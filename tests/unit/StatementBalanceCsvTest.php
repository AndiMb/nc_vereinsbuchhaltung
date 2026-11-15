<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\CamtCsvParser;
use OCA\Vereinsbuchhaltung\Service\Statement\Camt053Parser;
use OCA\Vereinsbuchhaltung\Service\Statement\Mt940Parser;
use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use OCA\Vereinsbuchhaltung\Service\Statement\StatementBalance;
use OCA\Vereinsbuchhaltung\Service\Statement\StatementParserRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Salden aus einem CSV-Auszug (Spalte „Saldo nach Buchung“) für die
 * Kontoabstimmung (Issue #159). Der Import selbst liest sie nicht.
 */
class StatementBalanceCsvTest extends TestCase {
	private const IBAN = 'DE12500105170648489890';
	private const HEAD = 'Auftragskonto;Buchungstag;Valutadatum;Buchungstext;Verwendungszweck;Beguenstigter/Zahlungspflichtiger;Betrag;Waehrung;Saldo nach Buchung;Info';

	private CamtCsvParser $parser;

	protected function setUp(): void {
		$this->parser = new CamtCsvParser();
	}

	/** @param list<array{0:string,1:string,2:string,3?:string}> $rows Datum, Betrag, Saldo, Konto */
	private function csv(array $rows): string {
		$lines = [self::HEAD];
		foreach ($rows as $r) {
			$lines[] = implode(';', [$r[3] ?? self::IBAN, $r[0], $r[0], 'GUTSCHRIFT', 'Zweck', 'Jemand', $r[1], 'EUR', $r[2], 'Umsatz gebucht']);
		}
		return implode("\n", $lines) . "\n";
	}

	private function one(string $content): StatementBalance {
		$balances = $this->parser->balances($content);
		$this->assertCount(1, $balances);
		return $balances[0];
	}

	public function testAufsteigendeDatei(): void {
		$b = $this->one($this->csv([
			['02.01.2026', '60,00', '1.060,00'],
			['05.01.2026', '-250,00', '810,00'],
		]));

		$this->assertSame(self::IBAN, $b->ownAccount);
		$this->assertSame(100000, $b->openingCents);
		$this->assertSame('2026-01-02', $b->openingDate);
		$this->assertSame(81000, $b->closingCents);
		$this->assertSame('2026-01-05', $b->closingDate);
		$this->assertSame([], $b->gaps);
	}

	/** Bankexporte stehen meist mit der neuesten Zeile oben – Anfang und Ende dürfen dabei nicht vertauscht werden. */
	public function testAbsteigendeDateiLiefertDieselbenSalden(): void {
		$b = $this->one($this->csv([
			['05.01.2026', '-250,00', '810,00'],
			['02.01.2026', '60,00', '1.060,00'],
		]));

		$this->assertSame(100000, $b->openingCents);
		$this->assertSame('2026-01-02', $b->openingDate);
		$this->assertSame(81000, $b->closingCents);
		$this->assertSame('2026-01-05', $b->closingDate);
		$this->assertSame([], $b->gaps);
	}

	public function testMehrereZeilenAmSelbenTagBehaltenDateireihenfolge(): void {
		$asc = $this->one($this->csv([
			['02.01.2026', '60,00', '1.060,00'],
			['02.01.2026', '-10,00', '1.050,00'],
			['03.01.2026', '1,00', '1.051,00'],
		]));
		$desc = $this->one($this->csv([
			['03.01.2026', '1,00', '1.051,00'],
			['02.01.2026', '-10,00', '1.050,00'],
			['02.01.2026', '60,00', '1.060,00'],
		]));

		foreach ([$asc, $desc] as $b) {
			$this->assertSame(100000, $b->openingCents);
			$this->assertSame(105100, $b->closingCents);
			$this->assertSame([], $b->gaps);
		}
	}

	public function testLueckeWirdGemeldet(): void {
		$b = $this->one($this->csv([
			['02.01.2026', '60,00', '1.060,00'],
			['05.01.2026', '-250,00', '810,00'],
			['07.01.2026', '5,00', '900,00'],
		]));

		$this->assertSame([['date' => '2026-01-07', 'expectedCents' => 81500, 'actualCents' => 90000]], $b->gaps);
		$this->assertSame(90000, $b->closingCents);
	}

	/** Eine 0,00-Zeile (Kontoabschluss ohne Zinsen) fällt beim Import weg, gehört aber zur Saldenkette. */
	public function testNullBetragsZeileUnterbrichtDieKetteNicht(): void {
		$b = $this->one($this->csv([
			['02.01.2026', '60,00', '1.060,00'],
			['03.01.2026', '0,00', '1.060,00'],
			['05.01.2026', '-250,00', '810,00'],
		]));

		$this->assertSame([], $b->gaps);
		$this->assertSame(81000, $b->closingCents);
	}

	public function testVorgemerkteUmsaetzeZaehlenNicht(): void {
		$content = $this->csv([['02.01.2026', '60,00', '1.060,00']])
			. implode(';', [self::IBAN, '06.01.2026', '06.01.2026', 'X', 'Zweck', 'Jemand', '-5,00', 'EUR', '', 'Umsatz vorgemerkt']) . "\n";

		$b = $this->one($content);

		$this->assertSame('2026-01-02', $b->closingDate);
		$this->assertSame(106000, $b->closingCents);
	}

	public function testOhneSaldoSpalteKeineSalden(): void {
		$this->assertSame([], $this->parser->balances((string)file_get_contents(__DIR__ . '/../fixtures/beispiel-camt.csv')));
	}

	public function testZweiKontenInEinerDatei(): void {
		$other = 'DE89370400440532013000';
		$balances = $this->parser->balances($this->csv([
			['02.01.2026', '60,00', '1.060,00'],
			['02.01.2026', '5,00', '25,00', $other],
			['05.01.2026', '-250,00', '810,00'],
		]));

		$this->assertCount(2, $balances);
		$byAccount = [];
		foreach ($balances as $b) {
			$byAccount[$b->ownAccount] = $b;
		}
		$this->assertSame(81000, $byAccount[self::IBAN]->closingCents);
		$this->assertSame(2000, $byAccount[$other]->openingCents);
		$this->assertSame(2500, $byAccount[$other]->closingCents);
		$this->assertSame([], $byAccount[self::IBAN]->gaps);
	}

	public function testRegistryReichtDurchUndLiefertLeerFuerDateiOhneSalden(): void {
		$n = new RowNormalizer();
		$registry = new StatementParserRegistry(new Camt053Parser($n), new Mt940Parser($n), new CamtCsvParser($n));

		$balances = $registry->balances($this->csv([['02.01.2026', '60,00', '1.060,00']]));
		$this->assertCount(1, $balances);
		$this->assertSame(106000, $balances[0]->closingCents);

		$this->assertSame([], $registry->balances((string)file_get_contents(__DIR__ . '/../fixtures/beispiel-camt.csv')));
	}
}
