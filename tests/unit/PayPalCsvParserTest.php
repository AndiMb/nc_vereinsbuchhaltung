<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\ClubCurrency;
use OCA\Vereinsbuchhaltung\Service\Statement\PayPalCsvParser;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class PayPalCsvParserTest extends TestCase {
	private PayPalCsvParser $parser;
	private string $content;

	protected function setUp(): void {
		$this->parser = new PayPalCsvParser();
		$this->content = (string)file_get_contents(__DIR__ . '/../fixtures/beispiel-paypal.csv');
	}

	public function testErkenntPayPalKopfzeile(): void {
		$this->assertTrue($this->parser->supports($this->content));
	}

	public function testCamtCsvIstKeinPayPal(): void {
		$camt = (string)file_get_contents(__DIR__ . '/../fixtures/beispiel-camt.csv');
		$this->assertFalse($this->parser->supports($camt));
	}

	public function testUebernimmtSollUndHabenInVereinswaehrung(): void {
		$rows = $this->parser->parse($this->content);
		$this->assertCount(4, $rows);
		$this->assertSame([5000, -160, -125000, 125000], array_column($rows, 'amountCents'));
		$this->assertSame(2, $this->parser->skipped($this->content));
		$this->assertSame('paypal', $this->parser->sourceKey());
	}

	public function testGebuehrIstEigenerUmsatz(): void {
		$rows = $this->parser->parse($this->content);
		$this->assertSame(5000, $rows[0]['amountCents']);
		$this->assertSame('2026-03-02', $rows[0]['bookingDate']);
		$fee = $rows[1];
		$this->assertSame(-160, $fee['amountCents']);
		$this->assertSame('PayPal', $fee['counterparty']);
		$this->assertStringContainsString('PayPal-Gebühr', $fee['purpose']);
		$this->assertStringContainsString('1AB23456CD7890123', $fee['purpose']);
		$this->assertSame(hash('sha256', 'paypal|1AB23456CD7890123|fee'), $fee['hash']);
	}

	public function testTausenderpunkt(): void {
		$rows = $this->parser->parse($this->content);
		$this->assertSame(-125000, $rows[2]['amountCents']);
	}

	public function testVerwendungszweckEnthaeltCode(): void {
		$rows = $this->parser->parse($this->content);
		$this->assertSame('Spende Jugendarbeit · PayPal 1AB23456CD7890123', $rows[0]['purpose']);
		$this->assertSame('Erika Muster', $rows[0]['counterparty']);
	}

	public function testVerwendungszweckVerbindetFelder(): void {
		$lines = preg_split('/\r\n/', trim($this->content));
		$cols = str_getcsv($lines[1], ',', '"', '\\');
		$cols[37] = 'Sommerfest';
		$cols[15] = 'Eintrittskarte';
		$line = '"' . implode('","', $cols) . '"';
		$rows = $this->parser->parse($lines[0] . "\r\n" . $line . "\r\n");
		$this->assertSame('Sommerfest / Eintrittskarte · PayPal 1AB23456CD7890123', $rows[0]['purpose']);
	}

	public function testNameAusZugehoerigerTransaktion(): void {
		$rows = $this->parser->parse($this->content);
		$this->assertSame('Druckerei Beispiel', $rows[3]['counterparty']);
		$this->assertSame('Bankgutschrift auf PayPal-Konto', $rows[3]['bookingText']);
	}

	public function testEineKennungJeDatei(): void {
		foreach ($this->parser->parse($this->content) as $row) {
			$this->assertSame('PAYPALKASSEEXAMPLEORG', $row['ownAccount']);
			$this->assertSame('kasse@example.org', $row['ownAccountLabel']);
		}
	}

	public function testHashNurAusCode(): void {
		$rows = $this->parser->parse($this->content);
		$this->assertSame(hash('sha256', 'paypal|1AB23456CD7890123|gross'), $rows[0]['hash']);

		$changed = str_replace(['Ausstehend', 'Spende Jugendarbeit'], ['Abgeschlossen', 'Anderer Betreff'], $this->content);
		$this->assertNotSame($this->content, $changed);
		$this->assertSame(array_column($rows, 'hash'), array_column($this->parser->parse($changed), 'hash'));
	}

	public function testNettoPasstNicht(): void {
		$broken = str_replace('"-1,60","48,40"', '"-1,60","40,00"', $this->content);
		$this->assertNotSame($this->content, $broken);
		try {
			$this->parser->parse($broken);
			$this->fail('UserFacingException erwartet');
		} catch (UserFacingException $e) {
			$this->assertStringContainsString('Zeile 2', $e->getMessage());
			$this->assertStringNotContainsString('Erika', $e->getMessage());
			$this->assertStringNotContainsString('Muster', $e->getMessage());
		}
	}

	public function testOhneEigeneAdresseFehler(): void {
		$this->expectException(UserFacingException::class);
		$this->parser->parse(str_replace('kasse@example.org', '', $this->content));
	}

	/**
	 * Baut aus der Fixture eine Variante: Zeilen (0 = erste Datenzeile) werden
	 * in der angegebenen Reihenfolge ausgegeben, $edits ändert Spalten je Zeile.
	 *
	 * @param list<int> $order
	 * @param array<int, array<int, string>> $edits Zeile => [Spalte => Wert]
	 */
	private function variant(array $order, array $edits = []): string {
		$lines = preg_split('/\r\n/', trim($this->content));
		$out = $lines[0] . "\r\n";
		foreach ($order as $n) {
			$cols = str_getcsv($lines[$n + 1], ',', '"', '\\');
			foreach ($edits[$n] ?? [] as $col => $value) {
				$cols[$col] = $value;
			}
			$out .= '"' . implode('","', array_map(static fn ($c) => str_replace('"', '""', (string)$c), $cols)) . "\"\r\n";
		}
		return $out;
	}

	public function testFehlenderTransaktionscodeFehler(): void {
		try {
			$this->parser->parse($this->variant([0], [0 => [12 => '']]));
			$this->fail('UserFacingException erwartet');
		} catch (UserFacingException $e) {
			$this->assertSame('Zeile 2: Transaktionscode fehlt.', $e->getMessage());
		}
	}

	public function testDoppelterTransaktionscodeFehler(): void {
		try {
			$this->parser->parse($this->variant([0, 0]));
			$this->fail('UserFacingException erwartet');
		} catch (UserFacingException $e) {
			$this->assertSame('Zeile 3: Transaktionscode 1AB23456CD7890123 kommt mehrfach vor.', $e->getMessage());
		}
	}

	public function testMemoZeileOhneCodeStoertNicht(): void {
		// Nicht übernommene Zeilen (Memo) brauchen keinen Code.
		$rows = $this->parser->parse($this->variant([0, 1], [0 => [40 => 'Memo', 12 => '']]));
		$this->assertCount(1, $rows);
	}

	public function testFremdwaehrungVereinswaehrungChf(): void {
		$currency = $this->createMock(ClubCurrency::class);
		$currency->method('code')->willReturn('CHF');
		$parser = new PayPalCsvParser(currency: $currency);
		$content = $this->variant([0, 1], [0 => [6 => 'CHF']]);

		$rows = $parser->parse($content);

		$this->assertCount(2, $rows);
		$this->assertSame('CHF', $rows[0]['currency']);
		$this->assertSame(1, $parser->skipped($content));
	}

	public function testGebuehrtextWirdUebersetzt(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => 'EN:' . ($params === [] ? $text : vsprintf($text, $params)));
		$parser = new PayPalCsvParser(l10n: $l10n);

		$fee = $parser->parse($this->content)[1];

		$this->assertSame('EN:PayPal-Gebühr', $fee['bookingText']);
		$this->assertSame('EN:PayPal-Gebühr zu 1AB23456CD7890123', $fee['purpose']);
	}

	public function testSaldenAusGuthaben(): void {
		$balances = $this->parser->balances($this->content);
		$this->assertCount(1, $balances);
		$b = $balances[0];
		$this->assertSame('PAYPALKASSEEXAMPLEORG', $b->ownAccount);
		$this->assertSame(0, $b->openingCents);
		$this->assertSame('2026-03-02', $b->openingDate);
		$this->assertSame(4840, $b->closingCents);
		$this->assertSame('2026-03-06', $b->closingDate);
		$this->assertSame([], $b->gaps);
	}

	public function testGleicheSekundeUmgekehrt(): void {
		// Zeilen 2 und 3 am selben Tag zur selben Sekunde, in der Datei vertauscht.
		$same = [0 => [0 => '02.03.2026', 1 => '09:00:00'], 1 => [0 => '02.03.2026', 1 => '09:00:00']];
		$b = $this->parser->balances($this->variant([1, 0, 2, 3, 4], $same))[0];
		$this->assertSame([], $b->gaps);
		$this->assertSame(0, $b->openingCents);
		$this->assertSame(4840, $b->closingCents);
	}

	public function testLueckeWirdGemeldet(): void {
		$b = $this->parser->balances($this->variant([0, 1, 2, 3, 4], [2 => [29 => '50,00']]))[0];
		$this->assertSame([['date' => '2026-03-06', 'expectedCents' => 4840, 'actualCents' => 5000]], $b->gaps);
	}

	public function testKeineUebernommenenZeilenKeineSalden(): void {
		$this->assertSame([], $this->parser->balances($this->variant([3, 4])));
	}

	public function testSemikolonUndOhneBom(): void {
		$in = fopen('php://temp', 'r+');
		fwrite($in, substr($this->content, 3));
		rewind($in);
		$out = '';
		while (($cols = fgetcsv($in, 0, ',', '"', '\\')) !== false) {
			$out .= implode(';', array_map(static fn ($c) => '"' . $c . '"', $cols)) . "\n";
		}
		fclose($in);

		$this->assertStringStartsNotWith("\xEF\xBB\xBF", $out);
		$this->assertTrue($this->parser->supports($out));
		$this->assertCount(4, $this->parser->parse($out));
	}
}
