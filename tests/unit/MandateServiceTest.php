<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateAmendment;
use OCA\Vereinsbuchhaltung\Db\MandateAmendmentMapper;
use OCA\Vereinsbuchhaltung\Db\MandateEvent;
use OCA\Vereinsbuchhaltung\Db\MandateEventMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\DunningLadderService;
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
	/**
	 * Echte Instanz statt Mock, als Feld statt lokal in service() gebaut:
	 * Tests zum Issue-#75-Aktionskatalog (Widerruf/IBAN-Änderung über den
	 * Self-Service-Kanal) müssen VOR dem service()-Aufruf per
	 * setMemberChannel() umschalten können - der Default-Kanal "staff" ist
	 * das, was jeder ältere Test hier stillschweigend erwartet.
	 */
	private ActorContextService $actorContext;
	/** Dieselbe Instanz, mit der auch $actorContext gebaut wird - wie in der echten DI teilen sich beide dieselbe IUserSession. */
	private IUserSession&MockObject $userSession;
	/** Als Feld statt lokal in service() gebaut (Issue #73): Tests zur Widerruf-Zahlungsaufforderung muessen VOR dem service()-Aufruf expects() darauf setzen koennen. */
	private DunningLadderService&MockObject $dunningLadder;

	protected function setUp(): void {
		$this->mandateMapper = $this->createMock(MandateMapper::class);
		$this->amendmentMapper = $this->createMock(MandateAmendmentMapper::class);
		$this->eventMapper = $this->createMock(MandateEventMapper::class);
		$this->memberMapper = $this->createMock(MemberMapper::class);
		$this->dunningLadder = $this->createMock(DunningLadderService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('kassenwart');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);
		$this->actorContext = new ActorContextService($this->userSession);
	}

	private function service(): MandateService {
		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(static fn (callable $fn) => $fn());

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default = '') => $default);

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
			$this->actorContext,
			$this->userSession,
			$config,
			$this->dunningLadder,
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

	// --- Elektronische Erteilung (Issue #67) ------------------------------------

	public function testCreateElectronicLegtEntwurfOhneUnterschriftsdatumAn(): void {
		$member = new Member();
		$member->setId(42);
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Katrin');
		$member->setLastName('Brunner');
		$this->memberMapper->method('find')->willReturn($member);
		$this->mandateMapper->method('findLiveByMember')->willReturn([]);

		$mandate = $this->service()->createElectronic(42, 'DE12500105170648489890', null, null);

		$this->assertSame(Mandate::SIGNATURE_ELECTRONIC, $mandate->getSignatureType());
		$this->assertSame(Mandate::STATUS_DRAFT, $mandate->getStatus());
		$this->assertNull($mandate->getSignedAt(), 'keine Unterschrift vor der Zustimmung');
	}

	public function testActivateElectronicSetztDasVollstaendigeBeweispaket(): void {
		$mandate = $this->activeMandate(1);
		$mandate->setStatus(Mandate::STATUS_DRAFT);
		$mandate->setSignatureType(Mandate::SIGNATURE_ELECTRONIC);
		$mandate->setSignedAt(null);
		$this->mandateMapper->method('find')->willReturn($mandate);

		$result = $this->service()->activateElectronic(1, 7, '2026-03-10 12:00:00', '203.0.113.5', 'TestBrowser/1.0', 'katrin@example.org');

		$this->assertSame(Mandate::STATUS_ACTIVE, $result->getStatus());
		$this->assertTrue($result->isCollectible());
		$this->assertSame(7, $result->getMandateTextVersion());
		$this->assertSame('2026-03-10 12:00:00', $result->getConsentAt());
		$this->assertSame('203.0.113.5', $result->getConsentIp());
		$this->assertSame('TestBrowser/1.0', $result->getConsentUserAgent());
		$this->assertSame('katrin@example.org', $result->getConsentActor());
		$this->assertSame('2026-03-10', $result->getSignedAt(), 'die Zustimmung selbst wird zur Unterschrift (Datumsteil von consent_at)');
		$this->assertNotNull($result->getActivatedAt());
	}

	public function testActivateElectronicAufPapierMandatSchlaegtFehl(): void {
		$mandate = $this->activeMandate(1);
		$mandate->setStatus(Mandate::STATUS_DRAFT);
		$mandate->setSignatureType(Mandate::SIGNATURE_PAPER);
		$this->mandateMapper->method('find')->willReturn($mandate);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->activateElectronic(1, 7, '2026-03-10 12:00:00', '203.0.113.5', 'TestBrowser/1.0', 'katrin@example.org');
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

	// --- Austritts-Mandatsende (Issue #70) --------------------------------------

	public function testEndDueToDepartureBeendetEinAktivesMandat(): void {
		$mandate = $this->activeMandate(1);
		$this->mandateMapper->method('find')->with(1)->willReturn($mandate);
		$this->mandateMapper->expects($this->once())->method('update');

		$result = $this->service()->endDueToDeparture(1);

		$this->assertSame(Mandate::STATUS_ENDED, $result->getStatus());
		$this->assertSame(Mandate::END_REASON_TERMINATED, $result->getEndReason());
	}

	public function testEndDueToDepartureLehntNichtAktivesMandatAb(): void {
		$mandate = $this->activeMandate(1);
		$mandate->setStatus(Mandate::STATUS_SUSPENDED);
		$this->mandateMapper->method('find')->with(1)->willReturn($mandate);
		$this->mandateMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->endDueToDeparture(1);
	}

	// --- Freigabe & Einreichung (Issue #71) -------------------------------------

	/** {@see \OCA\Vereinsbuchhaltung\Service\DebitBatchService::submit()} ruft dies für jedes beteiligte Mandat auf. */
	public function testMarkPresentedSetztLastPresentedDueDate(): void {
		$mandate = $this->activeMandate(1);
		$this->mandateMapper->method('find')->with(1)->willReturn($mandate);

		$result = $this->service()->markPresented(1, '2026-10-01');

		$this->assertSame('2026-10-01', $result->getLastPresentedDueDate());
	}

	/**
	 * Ein Mandat kann über mehrere unabhängig fällige Forderungen in mehr als
	 * einem Lauf zugleich stecken – ein späterer Aufruf mit einem FRÜHEREN
	 * Termin (Läufe werden nicht zwingend in Terminreihenfolge eingereicht)
	 * darf den bereits gemerkten späteren Termin nicht zurückdrehen.
	 */
	public function testMarkPresentedDrehtEinenSpaeterenTerminNichtZurueck(): void {
		$mandate = $this->activeMandate(1);
		$mandate->setLastPresentedDueDate('2026-11-01');
		$this->mandateMapper->method('find')->with(1)->willReturn($mandate);
		$this->mandateMapper->expects($this->never())->method('update');

		$result = $this->service()->markPresented(1, '2026-10-01');

		$this->assertSame('2026-11-01', $result->getLastPresentedDueDate());
	}

	public function testMarkAmendmentTransmittedSetztStatusUndDebitItemId(): void {
		$amendment = new MandateAmendment();
		$amendment->setId(7);
		$amendment->setMandateId(1);
		$amendment->setType(MandateAmendment::TYPE_ACCOUNT);
		$amendment->setStatus(MandateAmendment::STATUS_OPEN);
		$this->amendmentMapper->method('find')->with(7)->willReturn($amendment);

		$result = $this->service()->markAmendmentTransmitted(7, 42);

		$this->assertSame(MandateAmendment::STATUS_TRANSMITTED, $result->getStatus());
		$this->assertSame(42, $result->getDebitItemId());
	}

	// --- Self-Service-Aktionskatalog (Issue #75) --------------------------------

	/**
	 * Kern der Personalunion-Regel (Spec §3.9): dieselbe Methode (revoke())
	 * protokolliert je nach Kanal - nicht je nach Identität - unterschiedliche
	 * actor_types. ActorContextService steht per Default auf "staff" (siehe
	 * setUp()), genau wie es die Admin-Akte für denselben Aufruf setzen würde.
	 */
	public function testWiderrufProtokolliertStaffAlsActorTypeOhneSelfServiceKanal(): void {
		$mandate = $this->activeMandate(1);
		$this->mandateMapper->method('find')->willReturn($mandate);
		$captured = null;
		$this->eventMapper->expects($this->once())->method('insert')
			->with($this->callback(function (MandateEvent $e) use (&$captured): bool {
				$captured = $e;
				return true;
			}));

		$this->service()->revoke(1);

		$this->assertSame(MandateEvent::ACTOR_STAFF, $captured->getActorType());
	}

	/** Gegenprobe: derselbe Aufruf über den Self-Service-Kanal protokolliert "member" (Spec §3.4 "Widerruf ist ein Recht"). */
	public function testWiderrufProtokolliertMemberAlsActorTypeUeberSelfServiceKanal(): void {
		$this->actorContext->setMemberChannel(42);
		$mandate = $this->activeMandate(1);
		$this->mandateMapper->method('find')->willReturn($mandate);
		$captured = null;
		$this->eventMapper->expects($this->once())->method('insert')
			->with($this->callback(function (MandateEvent $e) use (&$captured): bool {
				$captured = $e;
				return true;
			}));

		$this->service()->revoke(1);

		$this->assertSame(MandateEvent::ACTOR_MEMBER, $captured->getActorType());
	}

	// --- Automatische Rücklastschrift-Sperre (Issue #73) ------------------------

	public function testSuspendDueToReturnedDebitSperrtAktivesMandat(): void {
		$mandate = $this->activeMandate(1);
		$this->mandateMapper->method('find')->willReturn($mandate);

		$result = $this->service()->suspendDueToReturnedDebit(1, 77, 'Klasse: Konto nicht erreichbar');

		$this->assertSame(Mandate::STATUS_SUSPENDED, $result->getStatus());
		$this->assertSame(Mandate::SUSPENSION_RETURNED_DEBIT, $result->getSuspensionOrigin());
		$this->assertSame('Klasse: Konto nicht erreichbar', $result->getSuspensionNote());
		$this->assertSame(77, $result->getReturnedDebitId());
	}

	/** Idempotent statt werfend (Klassendoc): ein bereits nicht mehr aktives Mandat bleibt unangetastet. */
	public function testSuspendDueToReturnedDebitIstNoopBeiNichtAktivemMandat(): void {
		$mandate = $this->activeMandate(1);
		$mandate->setStatus(Mandate::STATUS_SUSPENDED);
		$this->mandateMapper->method('find')->willReturn($mandate);
		$this->mandateMapper->expects($this->never())->method('update');

		$result = $this->service()->suspendDueToReturnedDebit(1, 77, 'Klasse: Konto nicht erreichbar');

		$this->assertNull($result);
	}

	/** memberId der Mandats-Akte, nicht z. B. die Mandats-Id (Spec §3.6 "Widerruf mit offenen Forderungen"). */
	public function testWiderrufLoestZahlungsaufforderungFuerOffeneForderungenAus(): void {
		$mandate = $this->activeMandate(1); // activeMandate() setzt memberId auf 42
		$this->mandateMapper->method('find')->willReturn($mandate);
		$this->dunningLadder->expects($this->once())->method('onMandateRevoked')->with(42);

		$this->service()->revoke(1);
	}

	/** Best effort (Klassendoc notifyRevocationDunning()): ein Fehler bei der Zahlungsaufforderung darf den bereits vollzogenen Widerruf nicht scheitern lassen. */
	public function testWiderrufSchlaegtNichtFehlWennZahlungsaufforderungWirft(): void {
		$mandate = $this->activeMandate(1);
		$this->mandateMapper->method('find')->willReturn($mandate);
		$this->dunningLadder->method('onMandateRevoked')->willThrowException(new \RuntimeException('Mailserver nicht erreichbar'));

		$result = $this->service()->revoke(1);

		$this->assertSame(Mandate::STATUS_ENDED, $result->getStatus());
		$this->assertSame(Mandate::END_REASON_REVOKED, $result->getEndReason());
	}

	public function testGrantElectronicSelfServiceLegtMandatAnUndAktiviertEsSofort(): void {
		$this->actorContext->setMemberChannel(42);
		$member = new Member();
		$member->setId(42);
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Katrin');
		$member->setLastName('Brunner');
		$this->memberMapper->method('find')->willReturn($member);
		$this->mandateMapper->method('findLiveByMember')->willReturn([]);
		// activateElectronic() laedt das gerade angelegte Mandat per find() neu -
		// der geteilte insert()-Stub in service() liefert zwar dieselbe Instanz
		// zurueck, aber ohne eigenen find()-Stub kaeme hier eine PHPUnit-
		// Default-Instanz mit signatureType=papier zurueck (Entity-Standardwert)
		// und die Aktivierung schluege fehl.
		$this->mandateMapper->method('find')->willReturnCallback(static function (int $id): Mandate {
			$m = new Mandate();
			$m->setId($id);
			$m->setMemberId(42);
			$m->setMandateReference('M-' . $id);
			$m->setAccountHolder('Katrin Brunner');
			$m->setSignatureType(Mandate::SIGNATURE_ELECTRONIC);
			$m->setStatus(Mandate::STATUS_DRAFT);
			return $m;
		});

		$mandate = $this->service()->grantElectronicSelfService(
			42, 'DE12500105170648489890', null, null, 7,
			'203.0.113.5', 'TestBrowser/1.0', 'katrin.b',
		);

		$this->assertSame(Mandate::SIGNATURE_ELECTRONIC, $mandate->getSignatureType());
		$this->assertSame(Mandate::STATUS_ACTIVE, $mandate->getStatus(), 'sofort wirksam - keine separate Zustimmung nötig');
		$this->assertTrue($mandate->isCollectible());
		$this->assertSame(7, $mandate->getMandateTextVersion());
		$this->assertSame('katrin.b', $mandate->getConsentActor());
		$this->assertNotNull($mandate->getSignedAt(), 'die Zustimmung selbst wird zur Unterschrift');
	}

	public function testGrantElectronicSelfServiceLehntZweitesLebendesMandatAb(): void {
		$this->actorContext->setMemberChannel(42);
		$member = new Member();
		$member->setId(42);
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Katrin');
		$member->setLastName('Brunner');
		$this->memberMapper->method('find')->willReturn($member);
		$this->mandateMapper->method('findLiveByMember')->willReturn([$this->activeMandate()]);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->grantElectronicSelfService(42, 'DE12500105170648489890', null, null, 7, '203.0.113.5', 'TestBrowser/1.0', 'katrin.b');
	}

	public function testReplaceElectronicSelfServiceErzeugtNeuesAktivesMandatUndBeendetDasAlte(): void {
		$this->actorContext->setMemberChannel(42);
		$old = $this->activeMandate(1);
		// Zwei verschiedene find()-Aufrufe im selben Ablauf: erst das alte
		// Mandat (id=1, PAPIER/aktiv), dann - innerhalb von activateElectronic() -
		// das gerade neu angelegte (andere id, ELEKTRONISCH/entwurf). Ein
		// einzelnes willReturn($old) wuerde den zweiten Aufruf falsch bedienen.
		$this->mandateMapper->method('find')->willReturnCallback(static function (int $id) use ($old): Mandate {
			if ($id === $old->getId()) {
				return $old;
			}
			$m = new Mandate();
			$m->setId($id);
			$m->setMemberId($old->getMemberId());
			$m->setMandateReference('M-' . $id);
			$m->setAccountHolder('Neuer Kontoinhaber');
			$m->setSignatureType(Mandate::SIGNATURE_ELECTRONIC);
			$m->setStatus(Mandate::STATUS_DRAFT);
			return $m;
		});

		$new = $this->service()->replaceElectronicSelfService(
			1, 'DE89370400440532013000', 'COBADEFFXXX', 'Neuer Kontoinhaber', 9,
			'203.0.113.5', 'TestBrowser/1.0', 'katrin.b',
		);

		$this->assertNotSame($old->getId(), $new->getId());
		$this->assertSame(Mandate::SIGNATURE_ELECTRONIC, $new->getSignatureType());
		$this->assertSame(Mandate::STATUS_ACTIVE, $new->getStatus(), 'sofort wirksam, kein Papier-Entwurf');
		$this->assertSame('Neuer Kontoinhaber', $new->getAccountHolder());
		$this->assertSame(9, $new->getMandateTextVersion());
		$this->assertSame(Mandate::STATUS_ENDED, $old->getStatus());
		$this->assertSame(Mandate::END_REASON_REPLACED, $old->getEndReason());
	}

	public function testReplaceElectronicSelfServiceLehntEntwurfAlsAusgangsmandatAb(): void {
		$this->actorContext->setMemberChannel(42);
		$old = $this->activeMandate(1);
		$old->setStatus(Mandate::STATUS_DRAFT);
		$this->mandateMapper->method('find')->willReturn($old);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->replaceElectronicSelfService(1, 'DE89370400440532013000', null, 'Jemand anders', 9, '203.0.113.5', 'TestBrowser/1.0', 'katrin.b');
	}
}
