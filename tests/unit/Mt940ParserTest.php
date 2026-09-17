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

	/**
	 * SEPA-strukturierte Felder aus dem Verwendungszweck (EREF+/MREF+/KREF+,
	 * Spec §5) - MT940 liefert höchstens eine Detail-Zeile je Buchung.
	 */
	public function testSepaDetailsAusStrukturierterReferenz(): void {
		$sta = ":20:TEST\n:25:50010517/0648489890\n"
			. ":61:2610050105C4500,00NTRFNONREF\n"
			. ":86:171?00GUTSCHRIFT?20EREF+E2E-1 MREF+M-1?21 KREF+MSG-42-RCUR?32Max Mustermann\n";

		$rows = $this->parser->parse($sta);

		$details = $rows[0]['sepaDetails'];
		$this->assertCount(1, $details);
		$this->assertSame('E2E-1', $details[0]['endToEndId']);
		$this->assertSame('M-1', $details[0]['mandateReference']);
		$this->assertSame('MSG-42-RCUR', $details[0]['batchReference']);
		$this->assertSame('171', $details[0]['gvc']);
		$this->assertFalse($details[0]['isReturn']);
		$this->assertSame(450000, $details[0]['amountCents']);
	}

	/**
	 * "ist Rückgabe" (Spec §5): GVC 108 am :86:-Anfang - derselbe Kernwert wie
	 * bei camt. Der DK-Retourencode `?34` wird nur übersetzt, wenn er in
	 * {@see \OCA\Vereinsbuchhaltung\Service\Sepa\DkReturnReasonCodes} eindeutig
	 * bekannt ist ("nie raten").
	 */
	public function testSepaDetailsErkenntRuecklastschriftUeberGvc(): void {
		$sta = ":20:TEST\n:25:50010517/0648489890\n"
			. ":61:2610060106D50,00NTRFNONREF\n"
			. ":86:108?00RUECKLASTSCHRIFT?20EREF+E2E-2 MREF+M-2?34901\n";

		$rows = $this->parser->parse($sta);

		$details = $rows[0]['sepaDetails'];
		$this->assertCount(1, $details);
		$this->assertTrue($details[0]['isReturn']);
		$this->assertSame('AC01', $details[0]['returnReasonCode'], 'DK 901 -> ISO AC01');
		$this->assertSame(-5000, $details[0]['amountCents']);
	}

	/** Ein unbekannter/mehrdeutiger DK-Code wird NICHT geraten (Spec §5 "nie raten"). */
	public function testUnbekannterDkCodeWirdNichtGeraten(): void {
		$sta = ":20:TEST\n:25:50010517/0648489890\n"
			. ":61:2610070107D50,00NTRFNONREF\n"
			. ":86:108?00RUECKLASTSCHRIFT?20EREF+E2E-3?34999\n";

		$rows = $this->parser->parse($sta);

		$this->assertNull($rows[0]['sepaDetails'][0]['returnReasonCode']);
	}

	/** Ursprungsbetrag/Bankgebühr können statt über OAMT+/COAM+ auch als /OCMT/…/CHGS/… an :61: hängen. */
	public function testUrsprungsbetragUndGebuehrAusOcmtChgsAmZeilenende(): void {
		$sta = ":20:TEST\n:25:50010517/0648489890\n"
			. ":61:2610080108D50,00NTRFNONREF//OCMT/EUR45,00/CHGS/EUR5,00/\n"
			. ":86:108?00RUECKLASTSCHRIFT?20EREF+E2E-4\n";

		$rows = $this->parser->parse($sta);

		$details = $rows[0]['sepaDetails'];
		$this->assertSame(4500, $details[0]['originalAmountCents']);
		$this->assertSame(500, $details[0]['chargesCents']);
	}

	/** Ein Umsatz ohne jede SEPA-Referenz erzeugt keine Detail-Zeile. */
	public function testSepaDetailsLeerOhneReferenz(): void {
		$rows = $this->parser->parse($this->fixture());

		// Die Beispieldatei enthaelt keine EREF+/MREF+/GVC-108/109-Zeilen.
		foreach ($rows as $row) {
			$this->assertSame([], $row['sepaDetails']);
		}
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
