<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\CamtCsvParser;
use OCA\Vereinsbuchhaltung\Service\Statement\Camt053Parser;
use OCA\Vereinsbuchhaltung\Service\Statement\Mt940Parser;
use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use OCA\Vereinsbuchhaltung\Service\Statement\StatementParserRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Zusammenspiel der Umsatzquellen: Formaterkennung und – der eigentliche Punkt –
 * dass derselbe Kontoauszug in verschiedenen Formaten auch als derselbe erkannt
 * wird.
 */
class StatementFormatTest extends TestCase {
	private RowNormalizer $normalizer;
	private StatementParserRegistry $registry;

	protected function setUp(): void {
		$this->normalizer = new RowNormalizer();
		$this->registry = new StatementParserRegistry(
			new Camt053Parser($this->normalizer),
			new Mt940Parser($this->normalizer),
			new CamtCsvParser($this->normalizer),
		);
	}

	private function fixture(string $name): string {
		return (string)file_get_contents(__DIR__ . '/../fixtures/' . $name);
	}

	public function testErkenntAlleDreiFormate(): void {
		$this->assertSame('csv', $this->registry->detect($this->fixture('beispiel-camt.csv'))->sourceKey());
		$this->assertSame('camt', $this->registry->detect($this->fixture('beispiel-camt053.xml'))->sourceKey());
		$this->assertSame('mt940', $this->registry->detect($this->fixture('beispiel-mt940.sta'))->sourceKey());
	}

	public function testUnbekanntesFormatWirdAbgelehnt(): void {
		$this->expectException(\RuntimeException::class);
		$this->registry->detect("Das ist einfach nur ein Fliesstext ohne jede Struktur.\n");
	}

	public function testLeereDateiWirdAbgelehnt(): void {
		$this->expectException(\RuntimeException::class);
		$this->registry->detect("   \n\n");
	}

	/**
	 * Der wichtigste Test dieser Datei.
	 *
	 * Dieselben fünf Umsätze liegen als CSV, CAMT.053 und MT940 vor. Wer das
	 * Exportformat wechselt, darf sie nicht ein zweites Mal importieren. Der
	 * Dedup-Hash allein kann das nicht leisten – er enthält das eigene Konto,
	 * und das schreiben die Formate verschieden ("0648489890" vs. die volle
	 * IBAN). Der weiche Schlüssel muss deshalb über alle Formate übereinstimmen.
	 */
	public function testGleicherAuszugInDreiFormatenErgibtGleicheSchluessel(): void {
		$keys = [];
		foreach (['beispiel-camt.csv', 'beispiel-camt053.xml', 'beispiel-mt940.sta'] as $file) {
			[$rows] = $this->registry->parse($this->fixture($file));
			$set = [];
			foreach ($rows as $row) {
				$set[] = $this->normalizer->softKey(
					(string)$row['bookingDate'],
					(int)$row['amountCents'],
					(string)$row['counterparty'] . (string)$row['purpose'],
				);
			}
			sort($set);
			$keys[$file] = $set;
		}

		$this->assertSame(
			$keys['beispiel-camt.csv'],
			$keys['beispiel-camt053.xml'],
			'CAMT.053 muss dieselben Umsätze liefern wie die CSV',
		);
		$this->assertSame(
			$keys['beispiel-camt.csv'],
			$keys['beispiel-mt940.sta'],
			'MT940 muss dieselben Umsätze liefern wie die CSV',
		);
	}

	/**
	 * Verschiedene Schreibweisen desselben Kontos dürfen nicht als verschiedene
	 * Konten gelten – sonst bekäme derselbe Umsatz je nach Export einen anderen
	 * Hash.
	 */
	public function testEigenesKontoWirdVereinheitlicht(): void {
		$erwartet = 'DE12345678901234567890';

		$this->assertSame($erwartet, $this->normalizer->normalizeOwnAccount('DE12 3456 7890 1234 5678 90'));
		$this->assertSame($erwartet, $this->normalizer->normalizeOwnAccount('de12345678901234567890'));
		$this->assertSame('3200015160', $this->normalizer->normalizeOwnAccount('32000151-60'));
		$this->assertNull($this->normalizer->normalizeOwnAccount(''));
		$this->assertNull($this->normalizer->normalizeOwnAccount(null));
	}

	/**
	 * Der Hash geht in die Datenbank ein: ändert sich seine Zusammensetzung,
	 * gilt jeder bereits importierte Umsatz wieder als neu und läge nach dem
	 * nächsten Import doppelt in „Zuzuordnen". Dieser Test friert ihn ein.
	 */
	public function testHashZusammensetzungIstEingefroren(): void {
		$row = [
			'ownAccount' => 'DE12500105170648489890',
			'bookingDate' => '2026-01-02',
			'amountCents' => 6000,
			'purpose' => 'Mitgliedsbeitrag 2026 Max Mustermann',
			'counterparty' => 'Max Mustermann',
			'counterpartyIban' => 'DE02120300000000202051',
		];

		$this->assertSame(
			hash('sha256', implode('|', [
				'DE12500105170648489890',
				'2026-01-02',
				'6000',
				'mitgliedsbeitrag 2026 max mustermann',
				'max mustermann',
				'DE02120300000000202051',
			])),
			$this->normalizer->computeHash($row),
		);
	}

	/** Der weiche Schlüssel ignoriert Trenn- und Sonderzeichen. */
	public function testWeicherSchluesselIgnoriertTrennzeichen(): void {
		$a = $this->normalizer->softKey('2026-01-02', 6000, 'Max Mustermann: Beitrag');
		$b = $this->normalizer->softKey('2026-01-02', -6000, 'max mustermann – beitrag');

		$this->assertNotNull($a);
		$this->assertSame($a, $b, 'Vorzeichen und Trennzeichen dürfen den Schlüssel nicht ändern');
		$this->assertNull($this->normalizer->softKey('2026-01-02', 6000, '   '));
	}
}
