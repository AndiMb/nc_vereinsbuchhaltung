<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\AccountNature;
use OCA\Vereinsbuchhaltung\Service\LedgerAggregator;
use PHPUnit\Framework\TestCase;

/**
 * Ein Konto, wie die Auswertung es sieht – ohne Datenbank und ohne Nextcloud.
 *
 * Die Kontoart wird hier genauso ausgewertet wie in Account, damit der Test
 * dieselbe Fallunterscheidung trifft wie der Produktivcode und nicht eine
 * bequemere.
 */
final class TestKonto implements AccountNature {

	public function __construct(
		private int $id,
		private string $number,
		private string $type,
		private bool $isBank = false,
		private bool $countInTotal = true,
	) {
	}

	public function getId(): int {
		return $this->id;
	}

	public function getNumber(): string {
		return $this->number;
	}

	public function isCreditNature(): bool {
		return in_array($this->type, ['income', 'liability', 'equity'], true);
	}

	public function isStockAccount(): bool {
		return $this->isBank;
	}

	public function isResultRelevant(): bool {
		return !$this->isStockAccount() && $this->type !== 'equity';
	}

	public function isBudgetable(): bool {
		return $this->type === 'income' || $this->type === 'expense';
	}

	public function countsInCashTotal(): bool {
		return $this->isStockAccount() && $this->countInTotal;
	}
}

/**
 * Die Rechenregeln hinter jeder Auswertung.
 *
 * Sie lagen vorher in den Controllern und waren dort nicht prüfbar – ein
 * Vorzeichenfehler wäre erst im gedruckten Kassenbericht aufgefallen, und
 * dann als falsche Summe, nicht als Fehlermeldung.
 */
class LedgerAggregatorTest extends TestCase {

	private static int $nextId = 1;

	private static function konto(string $number, string $type, bool $isBank = false, bool $countInTotal = true): TestKonto {
		return new TestKonto(self::$nextId++, $number, $type, $isBank, $countInTotal);
	}

	/**
	 * @param array<int, array{0:int, 1:int}> $perAccount [kontoId => [soll, haben]]
	 * @return array<int, array{debit:int, credit:int}>
	 */
	private static function summen(array $perAccount): array {
		$out = [];
		foreach ($perAccount as $id => [$debit, $credit]) {
			$out[$id] = ['debit' => $debit, 'credit' => $credit];
		}
		return $out;
	}

	public function testKontoOhneBewegungIstNull(): void {
		$konto = self::konto('4000', 'income');
		$this->assertSame(0, LedgerAggregator::net($konto, []));
		$this->assertSame(['debit' => 0, 'credit' => 0], LedgerAggregator::movement($konto, []));
	}

	/**
	 * Der Kern: das Vorzeichen folgt der Kontonatur, damit Einnahmen und
	 * Ausgaben beide positiv gezählt werden und das Ergebnis ihre Differenz ist.
	 */
	public function testVorzeichenFolgtDerKontonatur(): void {
		$einnahme = self::konto('4000', 'income');
		$ausgabe = self::konto('5000', 'expense');
		$sums = self::summen([
			$einnahme->getId() => [0, 85000],
			$ausgabe->getId() => [12000, 0],
		]);
		$this->assertSame(85000, LedgerAggregator::net($einnahme, $sums));
		$this->assertSame(12000, LedgerAggregator::net($ausgabe, $sums));
	}

	/**
	 * Eine Rückzahlung mindert die Einnahmen, sie wird nicht zur Ausgabe:
	 * sonst stimmte zwar das Ergebnis, aber der Bericht wiese eine
	 * Beitragsrückzahlung unter „Ausgaben" aus.
	 */
	public function testNegativeEinnahmeBleibtAufDerEinnahmenseite(): void {
		$einnahme = self::konto('4000', 'income');
		$sums = self::summen([$einnahme->getId() => [5000, 2000]]);

		$this->assertSame(-3000, LedgerAggregator::net($einnahme, $sums));

		$erg = LedgerAggregator::incomeExpense([$einnahme], $sums);
		$this->assertCount(1, $erg['income']);
		$this->assertCount(0, $erg['expense']);
		$this->assertSame(-3000, $erg['incomeCents']);
		$this->assertSame(-3000, $erg['resultCents']);
	}

	/** Geldkonten und Eigenkapital gehen nicht ins Jahresergebnis ein. */
	public function testGeldkontenUndEigenkapitalZaehlenNichtInsErgebnis(): void {
		$bank = self::konto('1200', 'asset', true);
		$eigenkapital = self::konto('0800', 'equity');
		$einnahme = self::konto('4000', 'income');
		$sums = self::summen([
			$bank->getId() => [85000, 12000],
			$eigenkapital->getId() => [0, 250000],
			$einnahme->getId() => [0, 85000],
		]);

		$erg = LedgerAggregator::incomeExpense([$bank, $eigenkapital, $einnahme], $sums);
		$this->assertCount(1, $erg['income']);
		$this->assertCount(0, $erg['expense']);
		$this->assertSame(85000, $erg['resultCents']);
	}

	/**
	 * Ein Aktivkonto ohne Geldkonto-Kennzeichen (Durchlauf-/Übertragskonto)
	 * ist erfolgswirksam und steht auf der Ausgabenseite – die App kennt
	 * ausdrücklich keine Sonderkonten außer Bank und Kasse.
	 */
	public function testAktivkontoOhneBankKennzeichenIstErfolgswirksam(): void {
		$durchlauf = self::konto('1590', 'asset');
		$sums = self::summen([$durchlauf->getId() => [4000, 1000]]);

		$erg = LedgerAggregator::incomeExpense([$durchlauf], $sums);
		$this->assertCount(1, $erg['expense']);
		$this->assertSame(3000, $erg['expenseCents']);
	}

	public function testErgebnisIstEinnahmenMinusAusgaben(): void {
		$einnahme = self::konto('4000', 'income');
		$ausgabe = self::konto('5000', 'expense');
		$sums = self::summen([
			$einnahme->getId() => [0, 100000],
			$ausgabe->getId() => [37500, 0],
		]);
		$erg = LedgerAggregator::incomeExpense([$einnahme, $ausgabe], $sums);
		$this->assertSame(100000, $erg['incomeCents']);
		$this->assertSame(37500, $erg['expenseCents']);
		$this->assertSame(62500, $erg['resultCents']);
	}

	// --- Bestände -------------------------------------------------------

	public function testBestandEinesGeldkontosIstSollMinusHaben(): void {
		$bank = self::konto('1200', 'asset', true);
		$sums = self::summen([$bank->getId() => [250000, 30000]]);
		$this->assertSame(220000, LedgerAggregator::stock($bank, $sums));
	}

	public function testVermoegenSummiertNurGeldkonten(): void {
		$bank = self::konto('1200', 'asset', true);
		$kasse = self::konto('1000', 'asset', true);
		$durchlauf = self::konto('1590', 'asset');
		$sums = self::summen([
			$bank->getId() => [250000, 30000],
			$kasse->getId() => [5000, 1000],
			$durchlauf->getId() => [99999, 0],
		]);
		$this->assertSame(224000, LedgerAggregator::wealth([$bank, $kasse, $durchlauf], $sums));
	}

	public function testVermoegensuebersichtLaesstUnbenutzteKontenWeg(): void {
		$bank = self::konto('1200', 'asset', true);
		$unbenutzt = self::konto('1201', 'asset', true);
		$start = self::summen([$bank->getId() => [250000, 0]]);
		$ende = self::summen([$bank->getId() => [300000, 20000]]);

		$erg = LedgerAggregator::wealthRows([$bank, $unbenutzt], $start, $ende);
		$this->assertCount(1, $erg['rows']);
		$this->assertSame(250000, $erg['startCents']);
		$this->assertSame(280000, $erg['endCents']);
		$this->assertSame(250000, $erg['rows'][0]['start']);
		$this->assertSame(280000, $erg['rows'][0]['end']);
	}

	// --- Geldbestand der Kopfzeile --------------------------------------

	/**
	 * Der Punkt der ganzen Uebung (Issue #31): die Kopfzeile zeigte nur das
	 * erste Geldkonto nach Kontonummer – bei Kasse (1000) und Bankkonto (1200)
	 * also ausgerechnet die Barkasse. Jetzt ist es die Summe.
	 */
	public function testGeldbestandSummiertAlleGeldkonten(): void {
		$kasse = self::konto('1000', 'asset', true);
		$bank = self::konto('1200', 'asset', true);
		$sums = self::summen([
			$kasse->getId() => [15000, 3000],
			$bank->getId() => [250000, 30000],
		]);

		$erg = LedgerAggregator::cashTotal([$kasse, $bank], $sums);
		$this->assertSame(232000, $erg['cents']);
		$this->assertSame(2, $erg['count']);
	}

	/**
	 * Ein abgewaehltes Konto faellt aus dem Geldbestand, bleibt aber in der
	 * Summe aller Geldkonten – sonst waere die Zeile unter der
	 * Geldkonten-Tabelle falsch, und das Geld verschwaende spurlos.
	 */
	public function testAbgewaehltesKontoFehltNurImGeldbestand(): void {
		$bank = self::konto('1200', 'asset', true);
		$festgeld = self::konto('1300', 'asset', true, false);
		$sums = self::summen([
			$bank->getId() => [250000, 30000],
			$festgeld->getId() => [500000, 0],
		]);

		$erg = LedgerAggregator::cashTotal([$bank, $festgeld], $sums);
		$this->assertSame(220000, $erg['cents']);
		$this->assertSame(1, $erg['count']);
		$this->assertSame(720000, $erg['allCents']);
		$this->assertSame(2, $erg['allCount']);
	}

	/**
	 * Das Kennzeichen an einem Konto ohne Geldkonto-Kennzeichen bleibt ohne
	 * Wirkung: ein Aufwandskonto hat keinen Bestand, den man addieren koennte.
	 */
	public function testNurGeldkontenZaehlenInDenGeldbestand(): void {
		$bank = self::konto('1200', 'asset', true);
		$durchlauf = self::konto('1590', 'asset');
		$ausgabe = self::konto('5000', 'expense');
		$sums = self::summen([
			$bank->getId() => [250000, 30000],
			$durchlauf->getId() => [99999, 0],
			$ausgabe->getId() => [12000, 0],
		]);

		$erg = LedgerAggregator::cashTotal([$bank, $durchlauf, $ausgabe], $sums);
		$this->assertSame(220000, $erg['cents']);
		$this->assertSame(1, $erg['count']);
		$this->assertSame(220000, $erg['allCents']);
		$this->assertSame(1, $erg['allCount']);
	}

	/**
	 * Sind alle Geldkonten abgewaehlt, ist der Geldbestand null und zaehlt
	 * kein Konto – die Oberflaeche blendet den Chip dann aus, statt eine
	 * 0,00 € zu zeigen, die es so nicht gibt.
	 */
	public function testOhneAngehaktesKontoBleibtDerGeldbestandLeer(): void {
		$bank = self::konto('1200', 'asset', true, false);
		$sums = self::summen([$bank->getId() => [250000, 30000]]);

		$erg = LedgerAggregator::cashTotal([$bank], $sums);
		$this->assertSame(0, $erg['cents']);
		$this->assertSame(0, $erg['count']);
		$this->assertSame(220000, $erg['allCents']);
	}

	/**
	 * Der Geldbestand rechnet mit denselben Bestaenden wie die
	 * Vermoegensuebersicht – solange kein Konto abgewaehlt ist, muessen beide
	 * dieselbe Zahl liefern.
	 */
	public function testGeldbestandUndVermoegenStimmenOhneAbwahlUeberein(): void {
		$kasse = self::konto('1000', 'asset', true);
		$bank = self::konto('1200', 'asset', true);
		$durchlauf = self::konto('1590', 'asset');
		$sums = self::summen([
			$kasse->getId() => [5000, 1000],
			$bank->getId() => [250000, 30000],
			$durchlauf->getId() => [99999, 0],
		]);
		$konten = [$kasse, $bank, $durchlauf];

		$this->assertSame(
			LedgerAggregator::wealth($konten, $sums),
			LedgerAggregator::cashTotal($konten, $sums)['cents'],
		);
	}

	// --- Saldenliste ----------------------------------------------------

	/**
	 * Der Unterschied, den die Saldenliste macht: das Geldkonto zeigt seinen
	 * Kontostand (kumulativ, also inklusive Vorjahren), das Erfolgskonto nur
	 * die Bewegung des Zeitraums.
	 */
	public function testSaldenlisteZeigtGeldkontenKumulativUndErfolgskontenJahresbezogen(): void {
		$bank = self::konto('1200', 'asset', true);
		$einnahme = self::konto('4000', 'income');
		$bewegung = self::summen([
			$bank->getId() => [85000, 12000],
			$einnahme->getId() => [0, 85000],
		]);
		$kumuliert = self::summen([
			$bank->getId() => [335000, 42000],
			$einnahme->getId() => [0, 200000],
		]);

		$this->assertSame(293000, LedgerAggregator::listBalance($bank, $bewegung, $kumuliert));
		$this->assertSame(85000, LedgerAggregator::listBalance($einnahme, $bewegung, $kumuliert));
	}

	/**
	 * Ein Bestandskonto mit Haben-Natur dreht das Vorzeichen: es steht im
	 * Haben, sein Saldo ist Haben − Soll.
	 */
	public function testBestandskontoMitHabenNaturDrehtDasVorzeichen(): void {
		$kredit = self::konto('1800', 'liability', true);
		$sums = self::summen([$kredit->getId() => [1000, 51000]]);
		$this->assertSame(50000, LedgerAggregator::listBalance($kredit, $sums, $sums));
	}

	// --- Finanzplan -----------------------------------------------------

	public function testSollIstVergleich(): void {
		$einnahme = self::konto('4000', 'income');
		$ausgabe = self::konto('5000', 'expense');
		$bank = self::konto('1200', 'asset', true);
		$durchlauf = self::konto('1590', 'asset');
		$sums = self::summen([
			$einnahme->getId() => [0, 90000],
			$ausgabe->getId() => [40000, 0],
			$bank->getId() => [50000, 0],
			$durchlauf->getId() => [7000, 0],
		]);
		$plan = [
			$einnahme->getId() => ['amount' => 100000, 'note' => 'geschätzt'],
			$ausgabe->getId() => ['amount' => 35000],
		];

		$erg = LedgerAggregator::planActual([$einnahme, $ausgabe, $bank, $durchlauf], $sums, $plan);

		// Weder das Geldkonto noch das Durchlaufkonto gehören in den Finanzplan.
		$this->assertCount(2, $erg['rows']);
		$this->assertSame(100000, $erg['planIncomeCents']);
		$this->assertSame(90000, $erg['actualIncomeCents']);
		$this->assertSame(35000, $erg['planExpenseCents']);
		$this->assertSame(40000, $erg['actualExpenseCents']);
		$this->assertSame('geschätzt', $erg['rows'][0]['note']);
		$this->assertSame('', $erg['rows'][1]['note']);
	}

	public function testKontoOhnePlanwertZaehltMitNull(): void {
		$einnahme = self::konto('4100', 'income');
		$sums = self::summen([$einnahme->getId() => [0, 15000]]);

		$erg = LedgerAggregator::planActual([$einnahme], $sums, []);
		$this->assertSame(0, $erg['planIncomeCents']);
		$this->assertSame(15000, $erg['actualIncomeCents']);
	}

	/**
	 * Die Zusage, auf der die Mehrjahresübersicht beruht: Vermögensänderung
	 * eines Jahres = Jahresergebnis. Sie gilt, weil außer Geldkonten und
	 * Eigenkapital alle Konten erfolgswirksam sind (doppelte Buchführung).
	 */
	public function testVermoegensaenderungEntsprichtDemErgebnis(): void {
		$bank = self::konto('1200', 'asset', true);
		$einnahme = self::konto('4000', 'income');
		$ausgabe = self::konto('5000', 'expense');

		// Zwei Buchungen: 900,00 Einnahme auf die Bank, 400,00 Ausgabe von der Bank.
		$jahr = self::summen([
			$bank->getId() => [90000, 40000],
			$einnahme->getId() => [0, 90000],
			$ausgabe->getId() => [40000, 0],
		]);
		$konten = [$bank, $einnahme, $ausgabe];

		$ergebnis = LedgerAggregator::incomeExpense($konten, $jahr)['resultCents'];
		$vermoegensaenderung = LedgerAggregator::wealth($konten, $jahr);

		$this->assertSame(50000, $ergebnis);
		$this->assertSame($ergebnis, $vermoegensaenderung);
	}
}
