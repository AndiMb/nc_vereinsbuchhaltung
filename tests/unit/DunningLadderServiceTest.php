<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\DunningNotice;
use OCA\Vereinsbuchhaltung\Db\DunningNoticeMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleSettings;
use OCA\Vereinsbuchhaltung\Service\DirectDebitEligibilityResolver;
use OCA\Vereinsbuchhaltung\Service\DunningLadderService;
use OCA\Vereinsbuchhaltung\Service\DunningSettings;
use OCA\Vereinsbuchhaltung\Service\EpcQrCodeGenerator;
use OCA\Vereinsbuchhaltung\Service\SepaDebtorAccountService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Mahntreppen-Zeitlogik (Spec §3.6 Mahnstufen-Tabelle, Issue #73) – Schwerpunkt:
 * die Ableitungsformel für Stufe 1/2 inkl. Stundung ("nicht aktuell gestundet",
 * "kein Reset, Uhr läuft von der zuletzt erreichten Stufe weiter") sowie die
 * beiden ereignisgetriebenen Stufe-0-Trigger.
 */
class DunningLadderServiceTest extends TestCase {

	private OpenItemMapper&MockObject $openItems;
	private MemberMapper&MockObject $members;
	/**
	 * DirectDebitEligibilityResolver ist final (nicht mockbar, siehe dortige
	 * Klassendoc) - hier eine echte Instanz über eine gemockte MandateMapper
	 * gesteuert: {@see setEligible()} kapselt "hat ein einzugsfähiges Mandat
	 * ja/nein", was bei einer Forderung ohne assignment_id (siehe claim())
	 * bereits über isEligible() entscheidet.
	 */
	private DirectDebitEligibilityResolver $eligibility;
	private MandateMapper&MockObject $eligibilityMandates;
	/**
	 * Von {@see setEligible()} umgeschaltet und von EINEM, in setUp() ein für
	 * allemal registrierten willReturnCallback() zur Laufzeit gelesen - ein
	 * zweites method()-Stub in einem Testkörper würde sonst am bereits in
	 * setUp() registrierten ersten Stub abprallen (siehe die ausführliche
	 * Begründung dazu in SepaImportConfirmationServiceTest).
	 */
	private bool $eligibleFlag = false;
	private DunningNoticeMapper&MockObject $notices;
	/**
	 * DunningSettings/ContributionCycleSettings/SepaDebtorAccountService sind
	 * alle `final` (nicht mockbar) – echte Instanzen über einen gemeinsamen,
	 * simplen In-Memory-IConfig-Fake (gleiches Muster wie
	 * ContributionCycleTaskServiceTest). Default-Werte entsprechen den
	 * eingebauten Defaults (Mahnabstand/Vorabinfo-Vorlauf je 14 Tage, kein
	 * Rücklastschriftgebühren-/Debitor-Konto eingestellt).
	 */
	private array $configStore = [];
	private IConfig&MockObject $config;
	private DunningSettings $settings;
	private ContributionCycleSettings $cycleSettings;
	private SepaDebtorAccountService $debtorAccount;
	private IMailer&MockObject $mailer;
	/** @var array<int, DunningNotice> nach openItemId+':'+stage */
	private array $insertedNotices = [];

	protected function setUp(): void {
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->members = $this->createMock(MemberMapper::class);
		$this->eligibilityMandates = $this->createMock(MandateMapper::class);
		$this->eligibleFlag = false; // sicherer Default - Tests, die "lastschriftfaehig" brauchen, rufen setEligible(true) explizit
		$this->eligibilityMandates->method('findLiveByMember')->willReturnCallback(function (): array {
			if (!$this->eligibleFlag) {
				return [];
			}
			$mandate = new Mandate();
			$mandate->setId(99);
			$mandate->setStatus(Mandate::STATUS_ACTIVE);
			return [$mandate];
		});
		$this->eligibility = new DirectDebitEligibilityResolver($this->createMock(AssignmentMapper::class), $this->eligibilityMandates);
		$this->notices = $this->createMock(DunningNoticeMapper::class);

		$this->configStore = [];
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = '') => array_key_exists($key, $this->configStore) ? $this->configStore[$key] : $default);
		$this->config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->configStore[$key] = $value;
		});
		$this->settings = new DunningSettings($this->config);
		$this->cycleSettings = new ContributionCycleSettings($this->config);
		$this->debtorAccount = new SepaDebtorAccountService($this->config); // GiroCode-Anhang bewusst ausgeklammert (kein Konto eingestellt), siehe eigener Test

		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('createEMailTemplate')->willReturn($this->createMock(\OCP\Mail\IEMailTemplate::class));
		$this->mailer->method('createMessage')->willReturn($this->createMock(\OCP\Mail\IMessage::class));

		$member = new Member();
		$member->setId(7);
		$member->setFirstName('Katrin');
		$member->setLastName('Brunner');
		$member->setEmail('katrin@example.org');
		$this->members->method('findOrNull')->willReturn($member);

		$this->insertedNotices = [];
		$this->notices->method('insert')->willReturnCallback(function (DunningNotice $n): DunningNotice {
			$n->setId(random_int(1000, 9999));
			$this->insertedNotices[] = $n;
			return $n;
		});
	}

	private function service(string $today = '2026-10-10'): DunningLadderService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime($today . ' 12:00:00'));

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new DunningLadderService(
			$this->openItems,
			$this->members,
			$this->eligibility,
			$this->notices,
			$this->settings,
			$this->cycleSettings,
			$this->debtorAccount,
			$this->createMock(AccountMapper::class),
			new EpcQrCodeGenerator(),
			$this->createMock(IUserManager::class),
			$this->mailer,
			$this->config,
			$time,
			$this->createMock(LoggerInterface::class),
			$l10n,
		);
	}

	private function claim(int $id, int $memberId, int $amountCents = 4500, ?string $dueDate = '2026-10-01', ?int $assignmentId = null, ?string $deferredUntil = null): OpenItem {
		$item = new OpenItem();
		$item->setId($id);
		$item->setMemberId($memberId);
		$item->setType(OpenItem::TYPE_CONTRIBUTION);
		$item->setAmountCents($amountCents);
		$item->setDueDate($dueDate);
		$item->setAssignmentId($assignmentId);
		$item->setDeferredUntil($deferredUntil);
		$item->setStatus('open');
		return $item;
	}

	private function setEligible(bool $eligible): void {
		$this->eligibleFlag = $eligible;
	}

	private function notice(int $openItemId, int $stage, string $sentAt): DunningNotice {
		$n = new DunningNotice();
		$n->setId(1);
		$n->setOpenItemId($openItemId);
		$n->setStage($stage);
		$n->setSentAt($sentAt);
		$n->setMailBatchReference('batch-1');
		return $n;
	}

	// --- Ereignisgetriebene Stufe 0 ---------------------------------------------------

	public function testTriggerPaymentRequestVersendetUndProtokolliertStufeNull(): void {
		$item = $this->claim(1, 7);
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);
		$this->mailer->method('send')->willReturn([]);

		$result = $this->service()->triggerPaymentRequest($item, 'Die Lastschrift konnte nicht eingezogen werden.');

		$this->assertSame(['sent' => 1, 'skipped' => 0, 'failed' => 0], $result);
		$this->assertCount(1, $this->insertedNotices);
		$this->assertSame(1, $this->insertedNotices[0]->getOpenItemId());
		$this->assertSame(DunningNotice::STAGE_PAYMENT_REQUEST, $this->insertedNotices[0]->getStage());
	}

	public function testTriggerPaymentRequestIstIdempotentBeiBereitsVorhandenerNotiz(): void {
		$item = $this->claim(1, 7);
		$this->notices->method('findByOpenItemAndStage')->willReturn($this->notice(1, DunningNotice::STAGE_PAYMENT_REQUEST, '2026-10-01T00:00:00+00:00'));
		$this->mailer->expects($this->never())->method('send');

		$result = $this->service()->triggerPaymentRequest($item, 'Grund');

		$this->assertSame(['sent' => 0, 'skipped' => 1, 'failed' => 0], $result);
	}

	public function testOnMandateRevokedBuendeltAlleOffenenForderungenInEinerMail(): void {
		$a = $this->claim(1, 7);
		$b = $this->claim(2, 7);
		$this->openItems->method('findByMember')->with(7)->willReturn([$a, $b]);
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);
		$this->mailer->expects($this->once())->method('send')->willReturn([]);

		$result = $this->service()->onMandateRevoked(7);

		$this->assertSame(['sent' => 1, 'skipped' => 0, 'failed' => 0], $result);
		$this->assertCount(2, $this->insertedNotices);
		$this->assertSame($this->insertedNotices[0]->getMailBatchReference(), $this->insertedNotices[1]->getMailBatchReference());
	}

	public function testOnMandateRevokedTutNichtsOhneOffeneForderungen(): void {
		$this->openItems->method('findByMember')->willReturn([]);
		$this->mailer->expects($this->never())->method('send');

		$result = $this->service()->onMandateRevoked(7);

		$this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $result);
	}

	// --- Täglicher Cron: Stufe 0 für nicht lastschriftfähige Forderungen ("Vorabinfo-Vorlauf") ---

	public function testRunDailyLoestStufeNullFuerUeberweiserForderungNachVorabinfoVorlaufAus(): void {
		$item = $this->claim(1, 7, dueDate: '2026-10-24'); // heute 2026-10-10, Vorlauf 14 Tage -> Deadline 2026-10-10
		$this->openItems->method('findClaims')->willReturn([$item]);
		$this->setEligible(false); // Ueberweiser/kein Mandat
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);
		$this->mailer->method('send')->willReturn([]);

		$result = $this->service('2026-10-10')->runDaily();

		$this->assertSame(1, $result['sent']);
		$this->assertCount(1, $this->insertedNotices);
	}

	public function testRunDailyLoestStufeNullNochNichtVorDemVorlaufAus(): void {
		$item = $this->claim(1, 7, dueDate: '2026-10-30'); // Deadline erst 2026-10-16
		$this->openItems->method('findClaims')->willReturn([$item]);
		$this->setEligible(false);

		$result = $this->service('2026-10-10')->runDaily();

		$this->assertSame(0, $result['sent']);
		$this->assertCount(0, $this->insertedNotices);
	}

	public function testRunDailyLoestKeineStufeNullFuerLastschriftfaehigeForderungAus(): void {
		// Lastschriftfaehige Forderungen bekommen Stufe 0 nur ereignisgetrieben
		// (Rücklastschrift/Widerruf), nie über den taeglichen Cron.
		$item = $this->claim(1, 7, dueDate: '2026-10-11');
		$this->openItems->method('findClaims')->willReturn([$item]);
		$this->setEligible(true);
		$this->mailer->expects($this->never())->method('send');

		$result = $this->service('2026-10-10')->runDaily();

		$this->assertSame(0, $result['sent']);
	}

	// --- Eskalation Stufe 0 -> 1 -> 2, inkl. Stundung -----------------------------------

	public function testRunDailyEskaliertZuStufe1NachMahnabstand(): void {
		$item = $this->claim(1, 7);
		$this->openItems->method('findClaims')->willReturn([$item]);
		$this->setEligible(true); // fuer Stufe0-Ueberweiser-Zweig irrelevant

		$this->notices->method('findByOpenItemAndStage')->willReturnCallback(
			fn (int $itemId, int $stage) => $stage === DunningNotice::STAGE_PAYMENT_REQUEST
				? $this->notice(1, DunningNotice::STAGE_PAYMENT_REQUEST, '2026-09-26T00:00:00+00:00') // genau 14 Tage vor "heute"
				: null,
		);
		$this->mailer->method('send')->willReturn([]);

		$result = $this->service('2026-10-10')->runDaily();

		$this->assertSame(1, $result['sent']);
		$this->assertCount(1, $this->insertedNotices);
		$this->assertSame(DunningNotice::STAGE_REMINDER, $this->insertedNotices[0]->getStage());
	}

	public function testRunDailyEskaliertNichtVorAblaufDesMahnabstands(): void {
		$item = $this->claim(1, 7);
		$this->openItems->method('findClaims')->willReturn([$item]);
		$this->setEligible(true);
		$this->notices->method('findByOpenItemAndStage')->willReturnCallback(
			fn (int $itemId, int $stage) => $stage === DunningNotice::STAGE_PAYMENT_REQUEST
				? $this->notice(1, DunningNotice::STAGE_PAYMENT_REQUEST, '2026-09-27T00:00:00+00:00') // erst 13 Tage her
				: null,
		);
		$this->mailer->expects($this->never())->method('send');

		$result = $this->service('2026-10-10')->runDaily();

		$this->assertSame(0, $result['sent']);
	}

	public function testGestundeteForderungFaelltWaehrendDerStundungKomplettAusDerPositionsliste(): void {
		// Stufe 0 liegt laengst ueber dem Mahnabstand zurueck - ohne Stundung
		// waere das laengst eskaliert.
		$item = $this->claim(1, 7, deferredUntil: '2026-10-15'); // "heute" 2026-10-10 -> noch aktiv gestundet
		$this->openItems->method('findClaims')->willReturn([$item]);
		$this->notices->method('findByOpenItemAndStage')->willReturnCallback(
			fn (int $itemId, int $stage) => $stage === DunningNotice::STAGE_PAYMENT_REQUEST
				? $this->notice(1, DunningNotice::STAGE_PAYMENT_REQUEST, '2026-09-01T00:00:00+00:00')
				: null,
		);
		$this->mailer->expects($this->never())->method('send');

		$result = $this->service('2026-10-10')->runDaily();

		$this->assertSame(0, $result['sent']);
	}

	/** "Kein Reset": nach Ablauf der Stundung läuft die Uhr vom ursprünglichen sent_at der Vorstufe weiter, nicht neu ab dem Stundungsende. */
	public function testNachAblaufDerStundungLaeuftDieUhrVonDerZuletztErreichtenStufeOhneResetWeiter(): void {
		$item = $this->claim(1, 7, deferredUntil: '2026-10-05'); // Stundung bereits abgelaufen ("heute" 2026-10-10)
		$this->openItems->method('findClaims')->willReturn([$item]);
		$this->setEligible(true);
		$this->notices->method('findByOpenItemAndStage')->willReturnCallback(
			fn (int $itemId, int $stage) => $stage === DunningNotice::STAGE_PAYMENT_REQUEST
				? $this->notice(1, DunningNotice::STAGE_PAYMENT_REQUEST, '2026-09-01T00:00:00+00:00') // weit ueber 14 Tage her
				: null,
		);
		$this->mailer->method('send')->willReturn([]);

		$result = $this->service('2026-10-10')->runDaily();

		$this->assertSame(1, $result['sent']);
		$this->assertSame(DunningNotice::STAGE_REMINDER, $this->insertedNotices[0]->getStage());
	}

	public function testEskaliertZuStufe2NachMahnabstandSeitStufe1(): void {
		$item = $this->claim(1, 7);
		$this->openItems->method('findClaims')->willReturn([$item]);
		$this->setEligible(true);
		$this->notices->method('findByOpenItemAndStage')->willReturnCallback(function (int $itemId, int $stage) {
			if ($stage === DunningNotice::STAGE_REMINDER) {
				return $this->notice(1, DunningNotice::STAGE_REMINDER, '2026-09-26T00:00:00+00:00');
			}
			return null; // weder Stufe 0 (fuer diesen Test irrelevant) noch Stufe 2 vorhanden
		});
		$this->mailer->method('send')->willReturn([]);

		$result = $this->service('2026-10-10')->runDaily();

		$this->assertSame(1, $result['sent']);
		$this->assertSame(DunningNotice::STAGE_DUNNING, $this->insertedNotices[0]->getStage());
	}

	public function testKeineDoppelteEskalationWennZielstufeBereitsVorhandenIst(): void {
		$item = $this->claim(1, 7);
		$this->openItems->method('findClaims')->willReturn([$item]);
		$this->setEligible(true);
		$this->notices->method('findByOpenItemAndStage')->willReturnCallback(function (int $itemId, int $stage) {
			return match ($stage) {
				DunningNotice::STAGE_PAYMENT_REQUEST => $this->notice(1, DunningNotice::STAGE_PAYMENT_REQUEST, '2026-09-01T00:00:00+00:00'),
				DunningNotice::STAGE_REMINDER => $this->notice(1, DunningNotice::STAGE_REMINDER, '2026-09-15T00:00:00+00:00'),
				default => null,
			};
		});
		$this->mailer->method('send')->willReturn([]);

		$result = $this->service('2026-10-10')->runDaily();

		// Stufe 1 existiert schon (nicht erneut ausgeloest), Stufe 2 wird ausgeloest.
		$this->assertSame(1, $result['sent']);
		$this->assertSame(DunningNotice::STAGE_DUNNING, $this->insertedNotices[0]->getStage());
	}

	// --- GiroCode-Anhang -----------------------------------------------------------

	public function testGiroCodeWirdAlsAnhangJePositionAngehaengtWennKontoEingestelltIst(): void {
		$item = $this->claim(1, 7, amountCents: 1234);
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);

		$this->configStore['sepa_debtor_account_id'] = '9'; // SepaDebtorAccountService ist final, siehe $this->debtorAccount-Klassendoc
		$account = new \OCA\Vereinsbuchhaltung\Db\Account();
		$account->setIban('DE02120300000000202051');
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('find')->willReturn($account);

		// Bewusst ein frischer Mailer-Mock statt $this->mailer wiederzuverwenden:
		// PHPUnit laesst bei mehrfach konfigurierten method()-Stubs ohne
		// unterscheidendes with() den zuerst konfigurierten (hier: aus setUp())
		// gewinnen - ein Ueberschreiben von createMessage() hier griffe sonst nicht.
		$attached = [];
		$message = $this->createMock(\OCP\Mail\IMessage::class);
		$message->method('attach')->willReturnCallback(function ($a) use (&$attached, $message) {
			$attached[] = $a;
			return $message;
		});
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createEMailTemplate')->willReturn($this->createMock(\OCP\Mail\IEMailTemplate::class));
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('createAttachment')->willReturn($this->createMock(\OCP\Mail\IAttachment::class));
		$mailer->method('send')->willReturn([]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-10 12:00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$service = new DunningLadderService(
			$this->openItems,
			$this->members,
			$this->eligibility,
			$this->notices,
			$this->settings,
			$this->cycleSettings,
			$this->debtorAccount,
			$accounts,
			new EpcQrCodeGenerator(),
			$this->createMock(IUserManager::class),
			$mailer,
			$this->config,
			$time,
			$this->createMock(LoggerInterface::class),
			$l10n,
		);

		$service->triggerPaymentRequest($item, 'Grund');

		$this->assertCount(1, $attached);
	}
}
