<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\Reimbursement;
use OCA\Vereinsbuchhaltung\Db\ReimbursementMapper;
use OCA\Vereinsbuchhaltung\Service\ReimbursementMatchingService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Welcher Auslage ein Kontoumsatz gehört: Nummer im Text, sonst IBAN und Betrag.
 */
class ReimbursementMatchingServiceTest extends TestCase {

	private const IBAN = 'DE89370400440532013000';

	private ReimbursementMapper&MockObject $reimbursements;

	protected function setUp(): void {
		$this->reimbursements = $this->createMock(ReimbursementMapper::class);
	}

	private function service(Reimbursement ...$approved): ReimbursementMatchingService {
		$this->reimbursements->method('findApproved')->willReturn($approved);
		$member = new Member();
		$member->setFirstName('Anna');
		$member->setLastName('Musterfrau');
		$members = $this->createMock(MemberMapper::class);
		$members->method('find')->willReturn($member);
		return new ReimbursementMatchingService($this->reimbursements, $members);
	}

	private function reimbursement(int $id, string $number, int $cents = 1250): Reimbursement {
		$r = new Reimbursement();
		$r->setId($id);
		$r->setMemberId(1);
		$r->setNumber($number);
		$r->setAmountCents($cents);
		$r->setIban(self::IBAN);
		$r->setStatus(Reimbursement::STATUS_APPROVED);
		return $r;
	}

	private function tx(int $id, int $cents, string $purpose, string $iban = ''): BankTransaction {
		$tx = new BankTransaction();
		$tx->setId($id);
		$tx->setAmountCents($cents);
		$tx->setPurpose($purpose);
		$tx->setCounterpartyIban($iban);
		return $tx;
	}

	public function testNummerImVerwendungszweckFindetDieAuslage(): void {
		$service = $this->service($this->reimbursement(1, 'AUSL-2026-0012'));

		$found = $service->suggestForMany([$this->tx(7, -1250, 'Anna Musterfrau ausl 2026 0012 - Material')]);

		$this->assertSame(1, $found[7][0]['reimbursementId']);
		$this->assertSame(ReimbursementMatchingService::REASON_NUMBER, $found[7][0]['reason']);
		$this->assertTrue($found[7][0]['exact']);
		$this->assertSame('Anna Musterfrau', $found[7][0]['memberName']);
	}

	public function testNummerMitFalschemBetragBleibtEinHinweis(): void {
		$service = $this->service($this->reimbursement(1, 'AUSL-2026-0012'));

		$found = $service->suggestForMany([$this->tx(7, -1000, 'AUSL-2026-0012')]);

		$this->assertFalse($found[7][0]['exact']);
	}

	public function testOhneNummerFindetIbanMitGenauemBetrag(): void {
		$service = $this->service($this->reimbursement(1, 'AUSL-2026-0012'));

		$found = $service->suggestForMany([$this->tx(7, -1250, 'Erstattung', 'de89 3704 0044 0532 0130 00')]);

		$this->assertSame(ReimbursementMatchingService::REASON_IBAN_AMOUNT, $found[7][0]['reason']);
	}

	public function testIbanAlleinOhnePassendenBetragGenuegtNicht(): void {
		$service = $this->service($this->reimbursement(1, 'AUSL-2026-0012'));

		$this->assertSame([], $service->suggestForMany([$this->tx(7, -999, 'Erstattung', self::IBAN)]));
	}

	public function testNummerAlsAnfangEinerLaengerenNummerIstKeinTreffer(): void {
		$service = $this->service($this->reimbursement(1, 'AUSL-2026-0012'));

		$this->assertSame([], $service->suggestForMany([$this->tx(7, -1250, 'AUSL-2026-00123')]));
	}

	public function testNummerDirektVorTextIstEinTreffer(): void {
		$service = $this->service($this->reimbursement(1, 'AUSL-2026-0012'));

		$this->assertArrayHasKey(7, $service->suggestForMany([$this->tx(7, -1250, 'AUSL-2026-0012Material')]));
	}

	public function testEingaengeUndAndereAbgaengeBleibenOhneVorschlag(): void {
		$service = $this->service($this->reimbursement(1, 'AUSL-2026-0012'));

		$this->assertSame([], $service->suggestForMany([
			$this->tx(7, 1250, 'AUSL-2026-0012'),
			$this->tx(8, -1250, 'Miete'),
		]));
	}

	public function testOhneFreigegebeneAuslagenKeineVorschlaege(): void {
		$this->assertSame([], $this->service()->suggestForMany([$this->tx(7, -1250, 'AUSL-2026-0012')]));
	}
}
