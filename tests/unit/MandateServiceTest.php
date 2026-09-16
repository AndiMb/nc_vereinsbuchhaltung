<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateAmendment;
use OCA\Vereinsbuchhaltung\Db\MandateAmendmentMapper;
use OCA\Vereinsbuchhaltung\Db\MandateEventMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\IbanValidator;
use OCA\Vereinsbuchhaltung\Service\MandateDocumentService;
use OCA\Vereinsbuchhaltung\Service\MandateExpiryCalculator;
use OCA\Vereinsbuchhaltung\Service\MandateReferenceGenerator;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCA\Vereinsbuchhaltung\Service\MandateStateMachine;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Schwerpunkt: Amendment-vs-neues-Mandat-Entscheidung (Spec §2.2 „Amendment
 * vs. neues Mandat", Issue #66) – IBAN/BIC-Wechsel bei gleichem Kontoinhaber
 * bleibt dasselbe Mandat (Amendment), ein Kontoinhaberwechsel erzeugt ein
 * neues. Mapper/Transaktion/Audit sind gemockt (siehe
 * AttachmentStorageServiceTest für dasselbe Muster in diesem Repo);
 * {@see MandateStateMachine}/{@see MandateExpiryCalculator}/
 * {@see MandateReferenceGenerator} laufen echt mit, weil sie ohnehin ohne
 * Datenbankzugriff auskommen.
 */
class MandateServiceTest extends TestCase {

	private MandateMapper&MockObject $mandateMapper;
	private MandateAmendmentMapper&MockObject $amendmentMapper;
	private MandateEventMapper&MockObject $eventMapper;
	private MemberMapper&MockObject $memberMapper;

	protected function setUp(): void {
		$this->mandateMapper = $this->createMock(MandateMapper::class);
		$this->amendmentMapper = $this->createMock(MandateAmendmentMapper::class);
		$this->eventMapper = $this->createMock(MandateEventMapper::class);
		$this->memberMapper = $this->createMock(MemberMapper::class);
	}

	private function service(): MandateService {
		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(static fn (callable $fn) => $fn());

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default = '') => $default);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('kassenwart');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		// insert()/update() geben in der echten QBMapper-Implementierung das
		// (ggf. mit ID versehene) Entity zurück - hier reicht "gibt weiter,
		// was reinkam", die Fachlogik interessiert sich nur für die Aufrufe.
		$this->mandateMapper->method('insert')->willReturnCallback(static function (Mandate $m): Mandate {
			if ($m->getId() === null) {
				$m->setId(random_int(1000, 9999));
			}
			return $m;
		});
		$this->mandateMapper->method('update')->willReturnArgument(0);
		$this->amendmentMapper->method('insert')->willReturnArgument(0);
		$this->amendmentMapper->method('update')->willReturnArgument(0);
		$this->mandateMapper->method('findReferencesWithPrefix')->willReturn([]);
		$this->mandateMapper->method('findByReference')->willReturn(null);

		return new MandateService(
			$this->mandateMapper,
			$this->amendmentMapper,
			$this->eventMapper,
			$this->memberMapper,
			new MandateStateMachine($this->createMock(IL10N::class)),
			new MandateExpiryCalculator(),
			new MandateReferenceGenerator(),
			$this->createMock(MandateDocumentService::class),
			new IbanValidator($this->createMock(IL10N::class)),
			$transaction,
			$this->createMock(AuditService::class),
			$userSession,
			$config,
			$this->createMock(IL10N::class),
		);
	}

	private function activeMandate(int $id = 1, string $iban = 'DE12500105170648489890', ?string $bic = 'INGDDEFFXXX', string $holder = 'Katrin Brunner'): Mandate {
		$m = new Mandate();
		$m->setId($id);
		$m->setMemberId(42);
		$m->setMandateReference('M-' . $id);
		$m->setIban($iban);
		$m->setBic($bic);
		$m->setAccountHolder($holder);
		$m->setSignatureType(Mandate::SIGNATURE_PAPER);
		$m->setSignedAt('2024-01-01');
		$m->setStatus(Mandate::STATUS_ACTIVE);
		return $m;
	}

	// --- Anlegen: höchstens ein lebendes Mandat je Mitglied ---------------------

	public function testAnlegenSchlaegtFehlWennBereitsEinLebendesMandatExistiert(): void {
		$member = new Member();
		$member->setId(42);
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Katrin');
		$member->setLastName('Brunner');
		$this->memberMapper->method('find')->willReturn($member);
		$this->mandateMapper->method('findLiveByMember')->willReturn([$this->activeMandate()]);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->createPaper(42, 'DE12500105170648489890', null, null, '2026-01-01');
	}

	public function testAnlegenBefuelltKontoinhaberMitAnzeigenamenWennLeer(): void {
		$member = new Member();
		$member->setId(42);
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Katrin');
		$member->setLastName('Brunner');
		$this->memberMapper->method('find')->willReturn($member);
		$this->mandateMapper->method('findLiveByMember')->willReturn([]);

		$mandate = $this->service()->createPaper(42, 'DE12500105170648489890', null, null, '2026-01-01');
		$this->assertSame('Katrin Brunner', $mandate->getAccountHolder());
		$this->assertSame(Mandate::STATUS_DRAFT, $mandate->getStatus());
	}

	// --- IBAN/BIC-Wechsel bei gleichem Kontoinhaber: Amendment, kein neues Mandat ----

	public function testGleicherKontoinhaberErzeugtNurEinAmendmentKeinNeuesMandat(): void {
		$mandate = $this->activeMandate();
		$this->mandateMapper->method('find')->willReturn($mandate);

		$capturedAmendment = null;
		$this->amendmentMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (MandateAmendment $a) use (&$capturedAmendment): bool {
				$capturedAmendment = $a;
				return true;
			}))
			->willReturnCallback(static fn (MandateAmendment $a) => $a);

		$result = $this->service()->amendBankDetails(1, 'DE89370400440532013000', 'COBADEFFXXX');

		$this->assertSame(1, $result->getId(), 'dasselbe Mandat, keine neue ID');
		$this->assertSame('DE89370400440532013000', $result->getIban());
		$this->assertSame(MandateAmendment::TYPE_ACCOUNT, $capturedAmendment->getType());
		$this->assertSame('DE12500105170648489890', $capturedAmendment->getOldIban(), 'Amendment traegt die ALTEN Werte');
		$this->assertSame('INGDDEFFXXX', $capturedAmendment->getOldBic());
	}

	public function testUnveraenderteIbanUndBicErzeugenKeinAmendment(): void {
		$mandate = $this->activeMandate();
		$this->mandateMapper->method('find')->willReturn($mandate);
		$this->amendmentMapper->expects($this->never())->method('insert');

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->amendBankDetails(1, $mandate->getIban(), $mandate->getBic());
	}

	public function testAmendmentAufEntwurfIstNichtErlaubt(): void {
		$mandate = $this->activeMandate();
		$mandate->setStatus(Mandate::STATUS_DRAFT);
		$this->mandateMapper->method('find')->willReturn($mandate);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->amendBankDetails(1, 'DE89370400440532013000', null);
	}

	// --- Kontoinhaberwechsel: neues Mandat, altes ended/replaced ----------------

	public function testKontoinhaberwechselErzeugtNeuesMandatUndBeendetDasAlte(): void {
		$old = $this->activeMandate(1);
		$this->mandateMapper->method('find')->willReturn($old);

		$new = $this->service()->replaceMandate(1, 'DE89370400440532013000', 'COBADEFFXXX', 'Neuer Kontoinhaber', '2026-05-01');

		$this->assertNotSame($old->getId(), $new->getId(), 'ein NEUES Mandat, keine bloße Änderung des alten');
		$this->assertSame(Mandate::STATUS_DRAFT, $new->getStatus(), 'das neue Mandat startet als Entwurf und braucht eine eigene Aktivierung');
		$this->assertSame('Neuer Kontoinhaber', $new->getAccountHolder());
		$this->assertSame(Mandate::STATUS_ENDED, $old->getStatus());
		$this->assertSame(Mandate::END_REASON_REPLACED, $old->getEndReason());
	}

	public function testErsetzenEinesEntwurfsIstNichtErlaubt(): void {
		$old = $this->activeMandate(1);
		$old->setStatus(Mandate::STATUS_DRAFT);
		$this->mandateMapper->method('find')->willReturn($old);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->replaceMandate(1, 'DE89370400440532013000', null, 'Jemand anders', '2026-05-01');
	}

	// --- Reine Namenskorrektur: stille Korrektur, kein Amendment, kein neues Mandat --

	public function testReineNamenskorrekturErzeugtWederAmendmentNochNeuesMandat(): void {
		$mandate = $this->activeMandate(1, holder: 'Katrin Meier'); // Tippfehler im Nachnamen
		$this->mandateMapper->method('find')->willReturn($mandate);
		$this->amendmentMapper->expects($this->never())->method('insert');
		$this->mandateMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$result = $this->service()->correctAccountHolderName(1, 'Katrin Brunner');

		$this->assertSame(1, $result->getId());
		$this->assertSame('Katrin Brunner', $result->getAccountHolder());
		$this->assertSame(Mandate::STATUS_ACTIVE, $result->getStatus(), 'eine Namenskorrektur ändert den Zustand nicht');
	}

	public function testUnveraenderterNameSchreibtNichtsWeg(): void {
		$mandate = $this->activeMandate(1, holder: 'Katrin Brunner');
		$this->mandateMapper->method('find')->willReturn($mandate);
		$this->mandateMapper->expects($this->never())->method('update');

		$this->service()->correctAccountHolderName(1, 'Katrin Brunner');
	}

	// --- 36-Monats-Verfall-Cron (Spec §2.2/§7/§8) --------------------------------

	public function testFaelligeMandateVerfallenAutomatisch(): void {
		$faellig = $this->activeMandate(1);
		$faellig->setSignedAt('2023-01-01'); // + 36 Monate < "heute" 2026-06-01
		$this->mandateMapper->method('findCandidatesForExpiry')->willReturn([$faellig]);
		$this->mandateMapper->expects($this->once())->method('update');

		$result = $this->service()->expireDueMandates('2026-06-01');

		$this->assertSame(1, $result['expired']);
		$this->assertSame(Mandate::STATUS_ENDED, $faellig->getStatus());
		$this->assertSame(Mandate::END_REASON_EXPIRED, $faellig->getEndReason());
	}

	public function testNochNichtFaelligeMandateBleibenUnangetastet(): void {
		$nochGueltig = $this->activeMandate(2);
		$nochGueltig->setSignedAt('2026-01-01'); // + 36 Monate weit in der Zukunft
		$this->mandateMapper->method('findCandidatesForExpiry')->willReturn([$nochGueltig]);
		$this->mandateMapper->expects($this->never())->method('update');

		$result = $this->service()->expireDueMandates('2026-06-01');

		$this->assertSame(0, $result['expired']);
		$this->assertSame(Mandate::STATUS_ACTIVE, $nochGueltig->getStatus());
	}

	public function testGemischterBestandVerfaelltNurDieFaelligen(): void {
		$faellig = $this->activeMandate(1);
		$faellig->setSignedAt('2022-01-01');
		$nochGueltig = $this->activeMandate(2);
		$nochGueltig->setSignedAt('2026-01-01');
		$this->mandateMapper->method('findCandidatesForExpiry')->willReturn([$faellig, $nochGueltig]);
		$this->mandateMapper->expects($this->once())->method('update');

		$result = $this->service()->expireDueMandates('2026-06-01');

		$this->assertSame(1, $result['expired']);
		$this->assertSame(Mandate::STATUS_ENDED, $faellig->getStatus());
		$this->assertSame(Mandate::STATUS_ACTIVE, $nochGueltig->getStatus());
	}
}
