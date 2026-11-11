<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Invoice\LineCostCenterRule;
use OCA\Vereinsbuchhaltung\Service\InvoicePaymentLedger;
use PHPUnit\Framework\TestCase;

/**
 * Kostenstellen an Rechnungen (Issue #332).
 *
 * Die Aufteilung einer Zahlung auf die Erlöskonten bleibt, wie sie ist. Neu
 * ist nur der zweite Schritt: der Betrag je Erlöskonto verteilt sich anteilig
 * auf die Kostenstellen der Rechnungszeilen dieses Kontos. Dabei darf kein
 * Cent verloren gehen und keiner zu viel verteilt werden – sonst lehnte der
 * Schreiber die Buchung ab, und die Zahlung scheiterte an einer Kostenstelle.
 */
class InvoiceCostCenterSharesTest extends TestCase {

	private const ERLOES = 40;
	private const ERLOES2 = 41;
	private const UST = 90;
	private const SEN = 3;
	private const AJU = 5;
	private const ALT = 9;

	private const USABLE = [self::SEN, self::AJU];

	/** @return array{accountId:?int, lineNetCents:int, bookingCostCenterId:?int} */
	private function line(?int $accountId, int $net, ?int $costCenterId): array {
		return ['accountId' => $accountId, 'lineNetCents' => $net, 'bookingCostCenterId' => $costCenterId];
	}

	public function testEineZeileEineKostenstelleBekommtDenGanzenKontobetrag(): void {
		$this->assertSame(
			[self::ERLOES => [['costCenterId' => self::SEN, 'amountCents' => 10000]]],
			InvoicePaymentLedger::sharesByCostCenter([$this->line(self::ERLOES, 10000, self::SEN)], [self::ERLOES => 10000], self::USABLE),
		);
	}

	public function testZweiZeilenGleichesKontoVerschiedeneKostenstellenAnteilig(): void {
		$shares = InvoicePaymentLedger::sharesByCostCenter(
			[$this->line(self::ERLOES, 60000, self::SEN), $this->line(self::ERLOES, 40000, self::AJU)],
			[self::ERLOES => 100000],
			self::USABLE,
		);
		$this->assertSame([
			['costCenterId' => self::SEN, 'amountCents' => 60000],
			['costCenterId' => self::AJU, 'amountCents' => 40000],
		], $shares[self::ERLOES]);
	}

	public function testTeilzahlungMitKrummemBetragGehtRestlosAuf(): void {
		$lines = [
			$this->line(self::ERLOES, 33333, self::SEN),
			$this->line(self::ERLOES, 33333, self::AJU),
			$this->line(self::ERLOES, 33334, null),
		];
		$shares = InvoicePaymentLedger::sharesByCostCenter($lines, [self::ERLOES => 50001], self::USABLE)[self::ERLOES];

		$allocated = array_sum(array_column($shares, 'amountCents'));
		// Zwei Drittel des Betrags liegen auf Kostenstellen, ein Drittel bleibt ohne.
		$this->assertLessThanOrEqual(50001, $allocated);
		$this->assertEqualsWithDelta(33334, $allocated, 1);
		foreach ($shares as $share) {
			$this->assertEqualsWithDelta(16667, $share['amountCents'], 1);
		}
	}

	public function testVollstaendigVerteilteZeilenErgebenExaktDenKontobetrag(): void {
		$lines = [$this->line(self::ERLOES, 33333, self::SEN), $this->line(self::ERLOES, 66667, self::AJU)];
		foreach ([1, 7, 50001, 99999, 100000] as $paid) {
			$shares = InvoicePaymentLedger::sharesByCostCenter($lines, [self::ERLOES => $paid], self::USABLE)[self::ERLOES] ?? [];
			$this->assertSame($paid, array_sum(array_column($shares, 'amountCents')), "Zahlung von $paid Cent");
		}
	}

	public function testZeileOhneKostenstelleBleibtOhne(): void {
		$shares = InvoicePaymentLedger::sharesByCostCenter(
			[$this->line(self::ERLOES, 60000, self::SEN), $this->line(self::ERLOES, 40000, null)],
			[self::ERLOES => 100000],
			self::USABLE,
		);
		$this->assertSame([['costCenterId' => self::SEN, 'amountCents' => 60000]], $shares[self::ERLOES]);
	}

	public function testNichtNutzbareKostenstelleZaehltAlsOhne(): void {
		$shares = InvoicePaymentLedger::sharesByCostCenter(
			[$this->line(self::ERLOES, 60000, self::SEN), $this->line(self::ERLOES, 40000, self::ALT)],
			[self::ERLOES => 100000],
			self::USABLE,
		);
		$this->assertSame([['costCenterId' => self::SEN, 'amountCents' => 60000]], $shares[self::ERLOES]);
	}

	public function testUmsatzsteuerkontoBekommtNichts(): void {
		$shares = InvoicePaymentLedger::sharesByCostCenter(
			[$this->line(self::ERLOES, 10000, self::SEN)],
			[self::ERLOES => 10000, self::UST => 1900],
			self::USABLE,
		);
		$this->assertArrayNotHasKey(self::UST, $shares);
	}

	public function testZweiKontenWerdenGetrenntVerteilt(): void {
		$shares = InvoicePaymentLedger::sharesByCostCenter(
			[$this->line(self::ERLOES, 10000, self::SEN), $this->line(self::ERLOES2, 5000, self::AJU)],
			[self::ERLOES => 10000, self::ERLOES2 => 5000],
			self::USABLE,
		);
		$this->assertSame([['costCenterId' => self::SEN, 'amountCents' => 10000]], $shares[self::ERLOES]);
		$this->assertSame([['costCenterId' => self::AJU, 'amountCents' => 5000]], $shares[self::ERLOES2]);
	}

	public function testOhneJedeKostenstelleIstDasErgebnisLeer(): void {
		$this->assertSame([], InvoicePaymentLedger::sharesByCostCenter([$this->line(self::ERLOES, 10000, null)], [self::ERLOES => 10000], self::USABLE));
	}

	public function testOhneNutzbareKostenstellenIstDasErgebnisLeer(): void {
		// Modul ausgeschaltet: der Vorgabe-Helfer liefert keine nutzbaren Kostenstellen.
		$this->assertSame([], InvoicePaymentLedger::sharesByCostCenter([$this->line(self::ERLOES, 10000, self::SEN)], [self::ERLOES => 10000], []));
	}

	public function testZeilenOhneKontoUndOhneBetragWerdenUebergangen(): void {
		$shares = InvoicePaymentLedger::sharesByCostCenter(
			[$this->line(null, 5000, self::AJU), $this->line(self::ERLOES, 0, self::AJU), $this->line(self::ERLOES, 10000, self::SEN)],
			[self::ERLOES => 10000],
			self::USABLE,
		);
		$this->assertSame([self::ERLOES => [['costCenterId' => self::SEN, 'amountCents' => 10000]]], $shares);
	}

	// ---- Rechnungszeile: welche Kostenstelle wird gespeichert? ----

	private const KNOWN = [self::SEN, self::AJU, self::ALT];

	public function testEditorNutzlastSetztDieKostenstelle(): void {
		$this->assertSame([self::SEN, null], LineCostCenterRule::resolve(['bookingCostCenterId' => self::SEN], null, self::KNOWN));
		$this->assertSame([self::SEN, null], LineCostCenterRule::resolve(['bookingCostCenterId' => '3'], self::AJU, self::KNOWN));
	}

	/** Der Editor schickt das Feld bei ausgeschaltetem Modul nicht – genau so ging das alte Feld verloren. */
	public function testFehlenderSchluesselLaesstDenGespeichertenWertStehen(): void {
		$this->assertSame([self::SEN, null], LineCostCenterRule::resolve(['name' => 'Teilnahme'], self::SEN, self::KNOWN));
		$this->assertSame([null, null], LineCostCenterRule::resolve(['name' => 'Teilnahme'], null, self::KNOWN));
	}

	public function testLeererWertEntferntSie(): void {
		foreach ([null, '', 0, '0'] as $empty) {
			$this->assertSame([null, null], LineCostCenterRule::resolve(['bookingCostCenterId' => $empty], self::SEN, self::KNOWN));
		}
	}

	public function testInaktiveAberVorhandeneKostenstelleIstErlaubt(): void {
		// Ein gespeicherter Entwurf mit inzwischen deaktivierter Kostenstelle muss speicherbar bleiben.
		$this->assertSame([self::ALT, null], LineCostCenterRule::resolve(['bookingCostCenterId' => self::ALT], self::ALT, self::KNOWN));
	}

	public function testUnbekannteKostenstelleIstEinFehler(): void {
		$this->assertSame([null, 'unknown'], LineCostCenterRule::resolve(['bookingCostCenterId' => 77], self::SEN, self::KNOWN));
	}
}
