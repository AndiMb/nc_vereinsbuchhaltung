<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateActivationTokenMapper;
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
use OCA\Vereinsbuchhaltung\Service\MandateExpirySettings;
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
	/** Einmal-Links (Issue #118): Korrigieren/Verwerfen eines Entwurfs muss ausgesendete Links löschen. */
	private MandateActivationTokenMapper&MockObject $activationTokens;
	/** Ereignistexte lassen sich nur prüfen, wenn t() den Text durchreicht - der Standard-Mock liefert ''. */
	private bool $realisticMessages = false;

	protected function setUp(): void {
		$this->activationTokens = $this->createMock(MandateActivationTokenMapper::class);
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

	/**
	 * @param array<string, string> $appValues gespeicherte App-Einstellungen; was fehlt, liefert seinen Standardwert
	 */
	private function service(array $appValues = []): MandateService {
		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(static fn (callable $fn) => $fn());

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default = '') => $appValues[$key] ?? $default);

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

		$serviceL10n = $this->createMock(IL10N::class);
		if ($this->realisticMessages) {
			$serviceL10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		}

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
			$serviceL10n,
			new MandateExpirySettings($config),
			$this->activationTokens,
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

	/**
	 * Issue #119: den Verlauf eines Mandats liest auch der Revisor, der die IBAN nur
	 * maskiert sehen darf (Spec §3.9). Der Ereignistext des Amendments nannte die
	 * alte IBAN bisher im Klartext – die volle steht im Amendment selbst.
	 */
	public function testAmendmentSchreibtDieAlteIbanNurMaskiertInDenVerlauf(): void {
		$this->realisticMessages = true;
		$this->mandateMapper->method('find')->willReturn($this->activeMandate());
		$events = [];
		$this->collectEvents($events);

		$this->service()->amendBankDetails(1, 'DE89370400440532013000', 'COBADEFFXXX');

		$this->assertCount(1, $events);
		$this->assertSame('Bankverbindung geändert (Amendment, alte IBAN DE12••••••••••••••9890)', $events[0]->getMessage());
		$this->assertStringNotContainsString('50010517', $events[0]->getMessage());
		$this->assertStringNotContainsString('DE89', $events[0]->getMessage(), 'auch die neue IBAN steht nicht im Verlauf');
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

	// --- Ablauf-Vorwarnung (Spec §4 expiry_warning_days) -------------------------

	/** Verfall am 2026-09-01 (Unterschrift 2023-09-01 + 36 Monate); „heute" ist 2026-06-01, also 92 Tage davor. */
	private function mandatMitVerfallAm20260901(): Mandate {
		$mandate = $this->activeMandate(3);
		$mandate->setSignedAt('2023-09-01');
		return $mandate;
	}

	public function testVorwarnungNutztStandardfristOhneEinstellung(): void {
		$mandate = $this->mandatMitVerfallAm20260901();
		$this->mandateMapper->method('findCandidatesForExpiry')->willReturn([$mandate]);

		// Standard 180 Tage: 92 Tage vor Verfall liegt mitten im Warnfenster.
		$this->assertSame([$mandate], $this->service()->findDueForExpiryWarning('2026-06-01'));
	}

	public function testVorwarnungFolgtDerEingestelltenFrist(): void {
		$mandate = $this->mandatMitVerfallAm20260901();
		$this->mandateMapper->method('findCandidatesForExpiry')->willReturn([$mandate]);

		// Bei 30 Tagen Vorwarnung ist es 92 Tage vor Verfall noch zu früh ...
		$this->assertSame([], $this->service(['expiry_warning_days' => '30'])->findDueForExpiryWarning('2026-06-01'));
		// ... und erst 30 Tage davor so weit.
		$this->assertSame([$mandate], $this->service(['expiry_warning_days' => '30'])->findDueForExpiryWarning('2026-08-02'));
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

	// --- Sperren/Entsperren mit Pflicht-Notiz (Spec §2.2, Issue #100) -----------

	public function testEntsperrenVerlangtEineNotiz(): void {
		$mandate = $this->activeMandate(1);
		$mandate->setStatus(Mandate::STATUS_SUSPENDED);
		$this->mandateMapper->method('find')->willReturn($mandate);
		$this->mandateMapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->resume(1, "  \t ");
	}

	public function testEntsperrenSetztMandatAktivZurueckUndProtokolliertDieNotiz(): void {
		$mandate = $this->activeMandate(1);
		$mandate->setStatus(Mandate::STATUS_SUSPENDED);
		$mandate->setSuspendedAt('2026-02-01 10:00:00');
		$mandate->setSuspendedBy('kassenwart');
		$mandate->setSuspensionOrigin(Mandate::SUSPENSION_MANUAL);
		$mandate->setSuspensionNote('Rückfrage beim Mitglied');
		$this->mandateMapper->method('find')->willReturn($mandate);
		$captured = null;
		$this->eventMapper->expects($this->once())->method('insert')
			->with($this->callback(function (MandateEvent $e) use (&$captured): bool {
				$captured = $e;
				return true;
			}));

		$result = $this->service()->resume(1, ' Konto bestätigt ');

		$this->assertSame(Mandate::STATUS_ACTIVE, $result->getStatus());
		$this->assertNull($result->getSuspendedAt());
		$this->assertNull($result->getSuspensionOrigin());
		$this->assertNull($result->getSuspensionNote());
		$this->assertSame(MandateEvent::ACTOR_STAFF, $captured->getActorType());
	}

	public function testEntsperrenEinesAktivenMandatsIstNichtErlaubt(): void {
		$this->mandateMapper->method('find')->willReturn($this->activeMandate(1));

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->resume(1, 'unnötig');
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

	// --- Entwurf korrigieren und verwerfen (Issue #118) -------------------------

	private function draftMandate(int $id = 1, string $signatureType = Mandate::SIGNATURE_PAPER): Mandate {
		$m = $this->activeMandate($id);
		$m->setStatus(Mandate::STATUS_DRAFT);
		$m->setSignatureType($signatureType);
		$m->setSignedAt(null);
		return $m;
	}

	/**
	 * Sammelt die in diesem Test geschriebenen Verlaufseinträge in der Reihenfolge ihres Entstehens.
	 *
	 * @param list<MandateEvent> $events wird per Referenz befüllt
	 */
	private function collectEvents(array &$events): void {
		$this->eventMapper->method('insert')->willReturnCallback(static function (MandateEvent $e) use (&$events): MandateEvent {
			$events[] = $e;
			return $e;
		});
	}

	public function testEntwurfVerwerfenBeendetMitVerworfenUndLaesstEinNeuesMandatZu(): void {
		$draft = $this->draftMandate();
		$this->mandateMapper->method('find')->willReturn($draft);

		$result = $this->service()->discardDraft(1, 'Tippfehler in der IBAN');

		$this->assertSame(Mandate::STATUS_ENDED, $result->getStatus());
		$this->assertSame(Mandate::END_REASON_DISCARDED, $result->getEndReason());
		$this->assertSame('verworfen', $result->getEndReason(), 'der Wert ist Teil der API und der Datenbank');
		$this->assertContains($result->getEndReason(), Mandate::END_REASONS);
		$this->assertNotNull($result->getEndedAt());
		// „höchstens ein lebendes Mandat“ zählt nur noch nicht erloschene - der verworfene Entwurf blockiert nichts mehr.
		$this->assertFalse($result->isLive());
		$this->assertNull($result->getActivatedAt(), 'ein Entwurf war nie aktiv');
	}

	public function testEntwurfVerwerfenProtokolliertNotizUndKanalImVerlauf(): void {
		$this->realisticMessages = true;
		$this->mandateMapper->method('find')->willReturn($this->draftMandate());
		$events = [];
		$this->collectEvents($events);

		$this->service()->discardDraft(1, '  Tippfehler in der IBAN  ');

		$this->assertCount(1, $events);
		$this->assertSame('Entwurf verworfen: Tippfehler in der IBAN', $events[0]->getMessage());
		$this->assertSame(MandateEvent::ACTOR_STAFF, $events[0]->getActorType());
		$this->assertSame('kassenwart', $events[0]->getActorUid());
		$this->assertSame(1, $events[0]->getMandateId());
	}

	public function testEntwurfVerwerfenImSelfServiceKanalProtokolliertDasMitgliedAlsAkteur(): void {
		$this->actorContext->setMemberChannel(42);
		$this->mandateMapper->method('find')->willReturn($this->draftMandate(1, Mandate::SIGNATURE_ELECTRONIC));
		$events = [];
		$this->collectEvents($events);

		$this->service()->discardDraft(1, 'Vom Mitglied selbst verworfen');

		$this->assertSame(MandateEvent::ACTOR_MEMBER, $events[0]->getActorType());
	}

	/** @dataProvider leereNotizen */
	public function testEntwurfVerwerfenVerlangtEinePflichtNotiz(string $note): void {
		$this->mandateMapper->expects($this->never())->method('find');
		$this->mandateMapper->expects($this->never())->method('update');
		$this->eventMapper->expects($this->never())->method('insert');
		$this->activationTokens->expects($this->never())->method('deleteOutstandingByMandate');

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->discardDraft(1, $note);
	}

	public static function leereNotizen(): array {
		return [
			'leer' => [''],
			'nur Leerzeichen' => ['   '],
			'nur Zeilenumbruch' => ["\n"],
		];
	}

	/** @dataProvider nichtEntwurfsStatus */
	public function testNurEinEntwurfLaesstSichVerwerfen(string $status): void {
		$mandate = $this->activeMandate();
		$mandate->setStatus($status);
		$this->mandateMapper->method('find')->willReturn($mandate);
		$this->mandateMapper->expects($this->never())->method('update');
		$this->eventMapper->expects($this->never())->method('insert');
		$this->activationTokens->expects($this->never())->method('deleteOutstandingByMandate');

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->discardDraft(1, 'Soll weg');
	}

	/** @dataProvider nichtEntwurfsStatus */
	public function testNurEinEntwurfLaesstSichOhneAmendmentKorrigieren(string $status): void {
		$mandate = $this->activeMandate();
		$mandate->setStatus($status);
		$this->mandateMapper->method('find')->willReturn($mandate);
		$this->mandateMapper->expects($this->never())->method('update');
		$this->activationTokens->expects($this->never())->method('deleteOutstandingByMandate');

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->correctDraft(1, 'DE89370400440532013000', null, 'Katrin Brunner');
	}

	public static function nichtEntwurfsStatus(): array {
		return [
			'aktiv' => [Mandate::STATUS_ACTIVE],
			'ausgesetzt' => [Mandate::STATUS_SUSPENDED],
			'erloschen' => [Mandate::STATUS_ENDED],
		];
	}

	public function testVerwerfenEinesElektronischenEntwurfsLoeschtDenAusgesendetenEinmalLink(): void {
		$this->realisticMessages = true;
		$this->mandateMapper->method('find')->willReturn($this->draftMandate(7, Mandate::SIGNATURE_ELECTRONIC));
		$this->activationTokens->expects($this->once())->method('deleteOutstandingByMandate')->with(7)->willReturn(1);
		$events = [];
		$this->collectEvents($events);

		$this->service()->discardDraft(7, 'Mitglied will kein Lastschriftmandat');

		$this->assertSame('Entwurf verworfen: Mitglied will kein Lastschriftmandat – der ausgesendete Einmal-Link ist damit ungültig', $events[0]->getMessage());
	}

	public function testKorrekturAendertIbanBicUndKontoinhaberOhneAmendment(): void {
		$this->mandateMapper->method('find')->willReturn($this->draftMandate());
		$this->amendmentMapper->expects($this->never())->method('insert');

		$result = $this->service()->correctDraft(1, 'de89 3704 0044 0532 0130 00', ' cobadeffxxx ', '  Katrin Meier  ');

		$this->assertSame(Mandate::STATUS_DRAFT, $result->getStatus(), 'die Korrektur ändert den Zustand nicht');
		$this->assertSame(1, $result->getId(), 'dasselbe Mandat, kein neues');
		$this->assertSame('DE89370400440532013000', $result->getIban(), 'IBAN normalisiert wie bei jedem anderen Weg');
		$this->assertSame('COBADEFFXXX', $result->getBic());
		$this->assertSame('Katrin Meier', $result->getAccountHolder());
		$this->assertNull($result->getEndReason());
	}

	public function testKorrekturDarfDieBicLeerenUndNenntNurGeaenderteFelder(): void {
		$this->realisticMessages = true;
		$this->mandateMapper->method('find')->willReturn($this->draftMandate());
		$events = [];
		$this->collectEvents($events);

		$result = $this->service()->correctDraft(1, 'DE12500105170648489890', null, 'Katrin Brunner');

		$this->assertNull($result->getBic());
		$this->assertSame('DE12500105170648489890', $result->getIban());
		$this->assertSame('Entwurf korrigiert: BIC INGDDEFFXXX → –', $events[0]->getMessage());
	}

	public function testKorrekturSchreibtJedeAenderungInDenVerlaufOhneVolleIbanOderNamen(): void {
		$this->realisticMessages = true;
		$this->mandateMapper->method('find')->willReturn($this->draftMandate());
		$events = [];
		$this->collectEvents($events);

		$this->service()->correctDraft(1, 'DE89370400440532013000', 'COBADEFFXXX', 'Katrin Meier');

		$this->assertCount(1, $events);
		$message = $events[0]->getMessage();
		$bullets = str_repeat('•', 14);
		$this->assertSame("Entwurf korrigiert: IBAN DE12{$bullets}9890 → DE89{$bullets}3000, BIC INGDDEFFXXX → COBADEFFXXX, Kontoinhaber", $message);
		// Der Verlauf ist auch für den Revisor lesbar und entgeht der Anonymisierung:
		// weder die volle Kontonummer noch ein Name gehören hinein.
		$this->assertStringNotContainsString('DE12500105170648489890', $message);
		$this->assertStringNotContainsString('DE89370400440532013000', $message);
		$this->assertStringNotContainsString('Katrin', $message);
		$this->assertSame(MandateEvent::ACTOR_STAFF, $events[0]->getActorType());
		$this->assertSame('kassenwart', $events[0]->getActorUid());
	}

	public function testKorrekturEinesElektronischenEntwurfsMachtDenAusgesendetenLinkUngueltig(): void {
		$this->realisticMessages = true;
		$this->mandateMapper->method('find')->willReturn($this->draftMandate(7, Mandate::SIGNATURE_ELECTRONIC));
		$this->activationTokens->expects($this->once())->method('deleteOutstandingByMandate')->with(7)->willReturn(1);
		$events = [];
		$this->collectEvents($events);

		$this->service()->correctDraft(7, 'DE89370400440532013000', null, 'Katrin Brunner');

		$this->assertStringContainsString('der ausgesendete Einmal-Link ist damit ungültig', $events[0]->getMessage());
	}

	public function testKorrekturOhneAusgesendetenLinkErwaehntKeinenLinkImVerlauf(): void {
		$this->realisticMessages = true;
		$this->mandateMapper->method('find')->willReturn($this->draftMandate(7, Mandate::SIGNATURE_ELECTRONIC));
		$this->activationTokens->expects($this->once())->method('deleteOutstandingByMandate')->with(7)->willReturn(0);
		$events = [];
		$this->collectEvents($events);

		$this->service()->correctDraft(7, 'DE89370400440532013000', null, 'Katrin Brunner');

		$this->assertStringNotContainsString('Einmal-Link', $events[0]->getMessage());
	}

	public function testUnveraenderteKorrekturSchreibtNichtsWegUndLoeschtKeinenLink(): void {
		$this->mandateMapper->method('find')->willReturn($this->draftMandate());
		$this->mandateMapper->expects($this->never())->method('update');
		$this->eventMapper->expects($this->never())->method('insert');
		$this->activationTokens->expects($this->never())->method('deleteOutstandingByMandate');

		$this->expectException(\InvalidArgumentException::class);
		// Schreibweise ohne Leerzeichen/Kleinbuchstaben ist derselbe Wert: nichts zu korrigieren.
		$this->service()->correctDraft(1, 'de12 5001 0517 0648 4898 90', 'ingddeffxxx', 'Katrin Brunner');
	}

	public function testKorrekturLehntUngueltigeIbanUndLeerenKontoinhaberAb(): void {
		$draft = $this->draftMandate();
		$this->mandateMapper->method('find')->willReturn($draft);
		$this->mandateMapper->expects($this->never())->method('update');
		$this->activationTokens->expects($this->never())->method('deleteOutstandingByMandate');
		$service = $this->service();

		$rejected = 0;
		foreach ([['kaputt', 'Katrin Brunner'], ['', 'Katrin Brunner'], ['DE89370400440532013000', '   ']] as [$iban, $holder]) {
			try {
				$service->correctDraft(1, $iban, null, $holder);
			} catch (\InvalidArgumentException) {
				$rejected++;
			}
		}

		$this->assertSame(3, $rejected);
		$this->assertSame('DE12500105170648489890', $draft->getIban(), 'der Entwurf bleibt unverändert');
	}

	public function testNachVerwerfenLaesstSichEinNeuesMandatAnlegen(): void {
		// Zusammenspiel Zustandsmaschine/Anlegen: nach dem Verwerfen ist der Entwurf nicht mehr
		// "lebend", die Liste lebender Mandate des Mitglieds ist leer - das Anlegen ist erlaubt.
		$draft = $this->draftMandate();
		$this->mandateMapper->method('find')->willReturn($draft);
		$member = new Member();
		$member->setId(42);
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Katrin');
		$member->setLastName('Brunner');
		$this->memberMapper->method('find')->willReturn($member);
		$this->mandateMapper->method('findLiveByMember')->willReturnCallback(static fn (): array => $draft->isLive() ? [$draft] : []);
		$service = $this->service();

		$service->discardDraft(1, 'Tippfehler');
		$new = $service->createPaper(42, 'DE89370400440532013000', null, null, '2026-01-01');

		$this->assertSame(Mandate::STATUS_DRAFT, $new->getStatus());
		$this->assertNotSame($draft->getId(), $new->getId());
	}
}
