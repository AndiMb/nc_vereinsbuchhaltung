<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Statement\Mt940Parser;
use PHPUnit\Framework\TestCase;

class Mt940ParserTest extends TestCase {
	private Mt940Parser $parser;

	protected function setUp(): void {
		$this->parser = new Mt940Parser();
	}

	private function fixture(): string {
		return (string)file_get_contents(__DIR__ . '/../fixtures/beispiel-mt940.sta');
	}

	public function testLiestDieBeispieldatei(): void {
		$rows = $this->parser->parse($this->fixture());

		$this->assertCount(5, $rows);
		$this->assertSame('2026-01-02', $rows[0]['bookingDate']);
		$this->assertSame('2026-01-02', $rows[0]['valueDate']);
		$this->assertSame(6000, $rows[0]['amountCents']);
		$this->assertSame('Max Mustermann', $rows[0]['counterparty']);
		$this->assertSame('DE02120300000000202051', $rows[0]['counterpartyIban']);
		$this->assertSame('BYLADEM1001', $rows[0]['counterpartyBic']);
	}

	/**
	 * Der Verwendungszweck ist in :86: auf ?20, ?21 … verteilt und mitten im
	 * Wort umbrochen. Werden die Teile nicht zusammengesetzt, steht in der
	 * Buchung nur ein Bruchstück.
	 */
	public function testSetztMehrteiligenVerwendungszweckZusammen(): void {
		$rows = $this->parser->parse($this->fixture());

		$this->assertSame('Mitgliedsbeitrag 2026 Max Mustermann', $rows[0]['purpose']);
	}

	/** Auch der Name des Zahlungsbeteiligten darf auf ?32/?33 verteilt sein. */
	public function testSetztMehrteiligenNamenZusammen(): void {
		$rows = $this->parser->parse($this->fixture());

		$this->assertSame('Stadtwerke Musterstadt', $rows[1]['counterparty']);
	}

	public function testRichtungCundD(): void {
		$rows = $this->parser->parse($this->fixture());

		$this->assertSame(-25000, $rows[1]['amountCents'], 'D muss negativ werden');
		$this->assertSame(125050, $rows[2]['amountCents'], 'C muss positiv bleiben');
	}

	/**
	 * RC storniert eine Gutschrift und ist deshalb eine Belastung, RD umgekehrt.
	 * Ohne diese Umkehr verdoppelt eine Rücklastschrift den Eingang, statt ihn
	 * auszugleichen.
	 */
	public function testStornoKehrtDieRichtungUm(): void {
		$sta = ":20:TEST\n:25:50010517/0648489890\n"
			. ":61:2602010201RC60,00NTRFNONREF\n"
			. ":86:166?00STORNO GUTSCHRIFT?20Ruecklastschrift?32Max Mustermann\n"
			. ":61:2602020202RD25,00NTRFNONREF\n"
			. ":86:166?00STORNO LASTSCHRIFT?20Rueckgabe?32Erika Beispiel\n";

		$rows = $this->parser->parse($sta);

		$this->assertSame(-6000, $rows[0]['amountCents'], 'RC storniert eine Gutschrift');
		$this->assertSame(2500, $rows[1]['amountCents'], 'RD storniert eine Belastung');
	}

	/**
	 * Das Buchungsdatum in :61: trägt nur Monat und Tag. Über den Jahreswechsel
	 * gehörte eine Buchung vom 30.12. sonst ins falsche Jahr – und damit in den
	 * falschen Kassenbericht.
	 */
	public function testJahreswechselBeimBuchungsdatum(): void {
		$sta = ":20:TEST\n:25:50010517/0648489890\n"
			. ":61:2601021230C60,00NTRFNONREF\n"
			. ":86:166?00GUTSCHRIFT?20Beitrag?32Max Mustermann\n";

		$rows = $this->parser->parse($sta);

		$this->assertSame('2025-12-30', $rows[0]['bookingDate'], 'Buchung liegt im Vorjahr');
		$this->assertSame('2026-01-02', $rows[0]['valueDate']);
	}

	/** :25: kann eine IBAN oder "BLZ/Kontonummer" enthalten. */
	public function testEigenesKontoAusFeld25(): void {
		$rows = $this->parser->parse($this->fixture());
		$this->assertSame('0648489890', $rows[0]['ownAccount']);

		$mitIban = ":20:TEST\n:25:DE12500105170648489890\n"
			. ":61:2601020102C60,00NTRFNONREF\n"
			. ":86:166?00GUTSCHRIFT?20Beitrag?32Max Mustermann\n";
		$rows = $this->parser->parse($mitIban);
		$this->assertSame('DE12500105170648489890', $rows[0]['ownAccount']);
	}

	public function testErkenntDasFormat(): void {
		$this->assertTrue($this->parser->supports($this->fixture()));
		$this->assertFalse($this->parser->supports('<?xml version="1.0"?><Document/>'));
	}

	public function testWirftOhneBuchungen(): void {
		$this->expectException(\RuntimeException::class);
		$this->parser->parse(":20:TEST\n:25:50010517/0648489890\n:62F:C260131EUR0,00\n");
	}
}
