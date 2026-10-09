<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Statement\Camt053Parser;
use OCA\Vereinsbuchhaltung\Tests\Dev\CamtBuilder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../dev/lib/autoload.php';

/**
 * Der camt.053-Generator der Testdaten (tests/dev) muss genau das erzeugen, was
 * der echte Parser des Bankimports liest – sonst kommt die Rückgabe nie als
 * Rückgabe im Bankabgleich an.
 */
class DevCamtBuilderTest extends TestCase {

	private const IBAN = 'DE12500105170648489890';

	/** @return array{endToEndId:string, mandateReference:string, amountCents:int, purpose:string} */
	private function item(string $suffix, string $mandate, int $cents, string $name): array {
		return [
			'endToEndId' => 'E2E-20260926-091000-5EED' . $suffix,
			'mandateReference' => $mandate,
			'amountCents' => $cents,
			'purpose' => 'Vollmitglied ' . $name,
		];
	}

	/** @return list<array<string, mixed>> */
	private function parse(string $xml): array {
		return (new Camt053Parser())->parse($xml);
	}

	private function statement(): string {
		$jana = $this->item('1001', 'M-1', 1500, 'Jana Hoffmann');
		$anna = $this->item('1011', 'M-9', 1500, 'Anna Koch');
		$markus = $this->item('1004', 'M-3', 1500, 'Markus Fuchs');
		return CamtBuilder::statement(self::IBAN, [
			CamtBuilder::collectionEntry('2026-10-01', [$jana, $anna], 'MSG-20260926-091000-5EED0001-RCUR'),
			CamtBuilder::returnEntry('2026-10-02', $markus, 'AM04', 'Insufficient funds', 350),
			CamtBuilder::plainEntry('2026-10-02', 'CRDT', 2250, 'Lena Bergmann & Söhne', 'DE44500105175407324931', 'Beitrag 4. Quartal <2026>', 'ÜBERWEISUNGSGUTSCHRIFT'),
			CamtBuilder::plainEntry('2026-10-05', 'DBIT', 790, null, null, 'Kontoführungsentgelt', 'ENTGELTABSCHLUSS'),
		], 'TESTDATEN-1', '2026-10-05T08:00:00', 'AUSZUG-1');
	}

	public function testErgibtWohlgeformtesXmlMitCamtNamensraum(): void {
		$doc = new \DOMDocument();
		$this->assertTrue($doc->loadXML($this->statement()));
		$this->assertSame('urn:iso:std:iso:20022:tech:xsd:camt.053.001.02', $doc->documentElement?->namespaceURI);
	}

	public function testParserErkenntAlleVierUmsaetze(): void {
		$rows = $this->parse($this->statement());

		$this->assertCount(4, $rows);
		$this->assertSame([3000, -1850, 2250, -790], array_column($rows, 'amountCents'));
		$this->assertSame(self::IBAN, $rows[0]['ownAccount']);
		$this->assertSame('2026-10-01', $rows[0]['bookingDate']);
	}

	public function testSammelgutschriftTraegtEineDetailZeileJePosten(): void {
		$details = $this->parse($this->statement())[0]['sepaDetails'];

		$this->assertCount(2, $details);
		$this->assertSame('E2E-20260926-091000-5EED1001', $details[0]['endToEndId']);
		$this->assertSame('M-1', $details[0]['mandateReference']);
		$this->assertSame(1500, $details[0]['amountCents']);
		$this->assertSame('MSG-20260926-091000-5EED0001-RCUR', $details[0]['batchReference']);
		$this->assertFalse($details[0]['isReturn']);
		$this->assertSame('M-9', $details[1]['mandateReference']);
	}

	public function testRueckgabeTraegtGrundUrsprungsbetragUndGebuehr(): void {
		$details = $this->parse($this->statement())[1]['sepaDetails'];

		$this->assertCount(1, $details);
		$detail = $details[0];
		$this->assertTrue($detail['isReturn']);
		$this->assertSame('AM04', $detail['returnReasonCode']);
		$this->assertSame('Insufficient funds', $detail['returnReasonText']);
		$this->assertSame('E2E-20260926-091000-5EED1004', $detail['endToEndId']);
		$this->assertSame('M-3', $detail['mandateReference']);
		$this->assertSame(1500, $detail['originalAmountCents']);
		$this->assertSame(350, $detail['chargesCents']);
	}

	public function testRueckgabeOhneGebuehrLaesstDieGebuehrWeg(): void {
		$xml = CamtBuilder::statement(self::IBAN, [
			CamtBuilder::returnEntry('2026-10-02', $this->item('1005', 'M-4', 4500, 'Sophie Krüger'), 'AC04', null, null),
		], 'M', '2026-10-05T08:00:00', 'S');

		$row = $this->parse($xml)[0];
		$this->assertSame(-4500, $row['amountCents']);
		$this->assertNull($row['sepaDetails'][0]['chargesCents']);
		$this->assertSame('AC04', $row['sepaDetails'][0]['returnReasonCode']);
	}

	public function testGewoehnlicherUmsatzHatKeineSepaReferenzen(): void {
		$rows = $this->parse($this->statement());

		$this->assertSame('Lena Bergmann & Söhne', $rows[2]['counterparty']);
		$this->assertSame('DE44500105175407324931', $rows[2]['counterpartyIban']);
		$this->assertSame('Beitrag 4. Quartal <2026>', $rows[2]['purpose']);
		// keine End-to-End-ID, keine Mandatsreferenz: keine Detail-Zeile im Bankabgleich
		$this->assertSame([], $rows[2]['sepaDetails']);
		$this->assertSame([], $rows[3]['sepaDetails']);
	}

	public function testBetraegeStehenImmerPositivMitRichtungskennzeichen(): void {
		$xml = $this->statement();

		$this->assertStringContainsString('<Amt Ccy="EUR">30.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>', $xml);
		$this->assertStringContainsString('<Amt Ccy="EUR">18.50</Amt><CdtDbtInd>DBIT</CdtDbtInd>', $xml);
		$this->assertStringNotContainsString('<Amt Ccy="EUR">-', $xml);
	}

	public function testIstBeiGleichenEingabenByteIdentisch(): void {
		$this->assertSame($this->statement(), $this->statement());
	}
}
