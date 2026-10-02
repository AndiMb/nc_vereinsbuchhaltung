<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
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

	protected function setUp(): void {
		$this->openItems = $this->createMock(OpenItemMapper::class);
	}

	private function service(): IncomingPaymentMatchingService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return new IncomingPaymentMatchingService($this->openItems, $l10n);
	}

	private function tx(int $amountCents, ?string $counterparty = null, ?string $purpose = null): BankTransaction {
		$tx = new BankTransaction();
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
}
