<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\IncomingPaymentRejectionMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\Sepa\IncomingPaymentMatchingService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Zuordnungs-Vorschlag für Zahlungseingänge (Spec §2.2/§3.6, Issue #72):
 * "importierte Gutschrift passt auf offene Forderung" statt Auto-Erledigen -
 * reine Vorschlags-Berechnung, siehe Klassendoc.
 */
class IncomingPaymentMatchingServiceTest extends TestCase {

	private OpenItemMapper&MockObject $openItems;
	private IncomingPaymentRejectionMapper&MockObject $rejections;

	protected function setUp(): void {
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->rejections = $this->createMock(IncomingPaymentRejectionMapper::class);
	}

	private function service(): IncomingPaymentMatchingService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return new IncomingPaymentMatchingService($this->openItems, $this->rejections, $l10n);
	}

	private function tx(int $amountCents, ?string $counterparty = null, ?string $purpose = null, ?int $id = null): BankTransaction {
		$tx = new BankTransaction();
		$tx->setId($id);
		$tx->setAmountCents($amountCents);
		$tx->setCounterparty($counterparty);
		$tx->setPurpose($purpose);
		return $tx;
	}

	private function claim(int $id, int $memberId, int $amountCents, string $debtor): OpenItem {
		$item = new OpenItem();
		$item->setId($id);
		$item->setMemberId($memberId);
		$item->setType(OpenItem::TYPE_CONTRIBUTION);
		$item->setAmountCents($amountCents);
		$item->setDebtor($debtor);
		$item->setStatus('open');
		return $item;
	}

	public function testSchlaegtOffeneForderungMitGleichemBetragVor(): void {
		$this->openItems->method('findClaims')->willReturn([
			$this->claim(1, 5, 4500, 'Max Mustermann'),
		]);

		$suggestions = $this->service()->suggestFor($this->tx(4500));

		$this->assertCount(1, $suggestions);
		$this->assertSame(1, $suggestions[0]['openItemId']);
		$this->assertSame(5, $suggestions[0]['memberId']);
	}

	public function testKeinVorschlagBeiAbweichendemBetrag(): void {
		$this->openItems->method('findClaims')->willReturn([
			$this->claim(1, 5, 4500, 'Max Mustermann'),
		]);

		$this->assertSame([], $this->service()->suggestFor($this->tx(5000)));
	}

	public function testBereitsErledigteForderungWirdNichtVorgeschlagen(): void {
		$claim = $this->claim(1, 5, 4500, 'Max Mustermann');
		$claim->setSettledAt('2026-10-01 00:00:00');
		$claim->setStatus('paid');
		$this->openItems->method('findClaims')->willReturn([$claim]);

		$this->assertSame([], $this->service()->suggestFor($this->tx(4500)));
	}

	public function testGeldausgangWirdNieVorgeschlagen(): void {
		$this->openItems->expects($this->never())->method('findClaims');

		$this->assertSame([], $this->service()->suggestFor($this->tx(-4500)));
	}

	public function testBegruendungNenntNamensuebereinstimmungImZahlungstext(): void {
		$this->openItems->method('findClaims')->willReturn([
			$this->claim(1, 5, 4500, 'Max Mustermann'),
		]);

		$suggestions = $this->service()->suggestFor($this->tx(4500, 'Max Mustermann', 'Beitrag 2026'));

		$this->assertStringContainsString('Zahlungstext', $suggestions[0]['reason']);
	}

	/** Die Forderungsnummer „F-<ID>" aus dem Verwendungszweck der Zahlungsaufforderung ordnet eindeutig zu. */
	public function testForderungsnummerImZahlungstextStehtVorGleichemBetrag(): void {
		$this->openItems->method('findClaims')->willReturn([
			$this->claim(1, 5, 1500, 'Max Mustermann'),
			$this->claim(2, 6, 1500, 'Erika Beispiel'),
		]);

		$suggestions = $this->service()->suggestFor($this->tx(1500, 'Frau E. Beispiel', 'Vollmitglied (01.11.2026 – 30.11.2026), Forderung F-2'));

		$this->assertSame([2, 1], array_column($suggestions, 'openItemId'), 'Die genannte Forderung kommt zuerst');
		$this->assertStringContainsString('F-2', $suggestions[0]['reason']);
		$this->assertStringNotContainsString('F-', $suggestions[1]['reason']);
	}

	public function testForderungsnummerOhneBetragsTrefferErzeugtKeinenVorschlag(): void {
		$this->openItems->method('findClaims')->willReturn([
			$this->claim(2, 6, 1500, 'Erika Beispiel'),
		]);

		$this->assertSame([], $this->service()->suggestFor($this->tx(1000, null, 'Forderung F-2')), 'Der Betrag muss weiter stimmen');
	}

	/** Das Gedächtnis des Bankabgleichs (Issue #105): ein abgelehntes Paar kommt nicht wieder. */
	public function testAbgelehntesPaarWirdNichtErneutVorgeschlagen(): void {
		$this->openItems->method('findClaims')->willReturn([
			$this->claim(1, 5, 4500, 'Max Mustermann'),
			$this->claim(2, 6, 4500, 'Erika Beispiel'),
		]);
		$this->rejections->method('findAllKeys')->willReturn([IncomingPaymentRejectionMapper::key(30, 1) => true]);

		$suggestions = $this->service()->suggestFor($this->tx(4500, id: 30));

		$this->assertCount(1, $suggestions);
		$this->assertSame(2, $suggestions[0]['openItemId']);
	}

	/** Die Ablehnung gilt für genau diesen Umsatz: dieselbe Forderung bleibt für einen anderen Umsatz vorschlagbar. */
	public function testAblehnungGiltNurFuerDenEinenUmsatz(): void {
		$this->openItems->method('findClaims')->willReturn([$this->claim(1, 5, 4500, 'Max Mustermann')]);
		$this->rejections->method('findAllKeys')->willReturn([IncomingPaymentRejectionMapper::key(30, 1) => true]);

		$this->assertCount(1, $this->service()->suggestFor($this->tx(4500, id: 31)));
	}

	/** Die Sammelabfrage liest die Forderungen nur einmal, egal wie viele Umsätze anstehen. */
	public function testSammelabfrageLiestDieForderungenNurEinmal(): void {
		$this->openItems->expects($this->once())->method('findClaims')->willReturn([
			$this->claim(1, 5, 4500, 'Max Mustermann'),
			$this->claim(2, 6, 6000, 'Erika Beispiel'),
		]);

		$result = $this->service()->suggestForMany([
			$this->tx(4500, id: 10),
			$this->tx(6000, id: 11),
			$this->tx(7000, id: 12),
			$this->tx(-4500, id: 13),
		]);

		$this->assertSame([10, 11], array_keys($result));
		$this->assertSame(1, $result[10][0]['openItemId']);
		$this->assertSame(2, $result[11][0]['openItemId']);
	}
}
