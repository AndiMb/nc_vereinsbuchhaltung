<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\Reimbursement;
use OCA\Vereinsbuchhaltung\Service\EpcQrCodeGenerator;
use OCA\Vereinsbuchhaltung\Service\ReimbursementService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementTransferService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Überweisungsdaten und GiroCode einer freigegebenen Auslage
 * (docs/reimbursement-design.md §7).
 */
class ReimbursementTransferServiceTest extends TestCase {

	private ReimbursementService&MockObject $service;
	private MemberMapper&MockObject $members;
	private EpcQrCodeGenerator&MockObject $qr;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->service = $this->createMock(ReimbursementService::class);
		$this->members = $this->createMock(MemberMapper::class);
		$this->qr = $this->createMock(EpcQrCodeGenerator::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function transfer(): ReimbursementTransferService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new ReimbursementTransferService($this->service, $this->members, $this->qr, $this->logger, $l10n);
	}

	private function reimbursement(string $status = Reimbursement::STATUS_APPROVED, string $holder = 'Erika Muster', string $iban = 'DE02120300000000202051'): Reimbursement {
		$r = new Reimbursement();
		$r->setId(12);
		$r->setMemberId(3);
		$r->setAmountCents(4599);
		$r->setPurpose('Material Sommerfest');
		$r->setStatus($status);
		$r->setIban($iban);
		$r->setAccountHolder($holder);
		return $r;
	}

	public function testVerwendungszweckHatNummerUndZweck(): void {
		$this->assertSame('Auslage Nr. 12 - Material Sommerfest', ReimbursementTransferService::remittance(12, 'Material Sommerfest'));
	}

	public function testVerwendungszweckOhneZweckHatNurDieNummer(): void {
		$this->assertSame('Auslage Nr. 7', ReimbursementTransferService::remittance(7, "  \n "));
	}

	public function testZeilenumbruecheImZweckWerdenZuLeerzeichen(): void {
		$this->assertSame('Auslage Nr. 1 - Zeile eins Zeile zwei', ReimbursementTransferService::remittance(1, "Zeile eins\r\n\n  Zeile zwei"));
	}

	public function testLangerZweckWirdAuf140ZeichenGekuerzt(): void {
		$text = ReimbursementTransferService::remittance(12, str_repeat('ä', 300));
		$this->assertSame(140, mb_strlen($text));
		$this->assertStringStartsWith('Auslage Nr. 12 - ää', $text);
	}

	public function testPrepareLiefertDatenUndGiroCode(): void {
		$this->service->method('find')->with(12)->willReturn($this->reimbursement());
		$this->qr->expects($this->once())->method('generatePng')
			->with('Erika Muster', 'DE02120300000000202051', null, 4599, 'Auslage Nr. 12 - Material Sommerfest')
			->willReturn('PNGDATA');

		$data = $this->transfer()->prepare(12);

		$this->assertSame('Erika Muster', $data['recipient']);
		$this->assertSame('DE02120300000000202051', $data['iban']);
		$this->assertSame(4599, $data['amountCents']);
		$this->assertSame('Auslage Nr. 12 - Material Sommerfest', $data['remittance']);
		$this->assertSame(base64_encode('PNGDATA'), $data['qrPng']);
	}

	public function testOhneKontoinhaberDientDerMitgliedsnameAlsEmpfaenger(): void {
		$this->service->method('find')->willReturn($this->reimbursement(holder: ''));
		$member = $this->createMock(Member::class);
		$member->method('displayName')->willReturn('Max Mitglied');
		$this->members->method('find')->with(3)->willReturn($member);
		$this->qr->method('generatePng')->willReturn('x');

		$this->assertSame('Max Mitglied', $this->transfer()->prepare(12)['recipient']);
	}

	public function testFehlerBeimGiroCodeLassenDieFelderTrotzdemDurch(): void {
		$this->service->method('find')->willReturn($this->reimbursement());
		$this->qr->method('generatePng')->willThrowException(new \RuntimeException('gd fehlt'));
		$this->logger->expects($this->once())->method('warning');

		$data = $this->transfer()->prepare(12);

		$this->assertNull($data['qrPng']);
		$this->assertSame('DE02120300000000202051', $data['iban']);
	}

	#[DataProvider('nichtFreigegeben')]
	public function testNurFreigegebeneAuslagenLassenSichUeberweisen(string $status): void {
		$this->service->method('find')->willReturn($this->reimbursement($status));
		$this->expectException(\InvalidArgumentException::class);
		$this->transfer()->prepare(12);
	}

	/** @return array<string, array{string}> */
	public static function nichtFreigegeben(): array {
		return [
			'eingereicht' => [Reimbursement::STATUS_SUBMITTED],
			'teilweise' => [Reimbursement::STATUS_PARTIALLY_APPROVED],
			'bezahlt' => [Reimbursement::STATUS_PAID],
			'abgelehnt' => [Reimbursement::STATUS_REJECTED],
			'zurückgezogen' => [Reimbursement::STATUS_WITHDRAWN],
		];
	}

	public function testAnonymisierteAuslageOhneIbanWirft(): void {
		$this->service->method('find')->willReturn($this->reimbursement(iban: ''));
		$this->expectException(\InvalidArgumentException::class);
		$this->transfer()->prepare(12);
	}

	public function testUnbekannterAntragWirftDoesNotExist(): void {
		$this->service->method('find')->willThrowException(new DoesNotExistException(''));
		$this->expectException(DoesNotExistException::class);
		$this->transfer()->prepare(99);
	}
}
