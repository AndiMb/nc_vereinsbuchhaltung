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
use OCA\Vereinsbuchhaltung\Service\RecipientL10n;
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
			$this->recipientL10n($l10n),
		);
	}

	/** Die Sprache des Empfängers ist hier immer die des Mocks: Du/Sie und Sprache prüft RecipientL10nTest. */
	private function recipientL10n(IL10N $l10n): RecipientL10n {
		$recipient = $this->createMock(RecipientL10n::class);
		$recipient->method('forMember')->willReturn($l10n);
		return $recipient;
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

		$result = $this->service()->triggerPaymentRequest($item, fn (): string => 'Die Lastschrift konnte nicht eingezogen werden.');

		$this->assertSame(['sent' => 1, 'skipped' => 0, 'failed' => 0], $result);
		$this->assertCount(1, $this->insertedNotices);
		$this->assertSame(1, $this->insertedNotices[0]->getOpenItemId());
		$this->assertSame(DunningNotice::STAGE_PAYMENT_REQUEST, $this->insertedNotices[0]->getStage());
	}

	public function testTriggerPaymentRequestIstIdempotentBeiBereitsVorhandenerNotiz(): void {
		$item = $this->claim(1, 7);
		$this->notices->method('findByOpenItemAndStage')->willReturn($this->notice(1, DunningNotice::STAGE_PAYMENT_REQUEST, '2026-10-01T00:00:00+00:00'));
		$this->mailer->expects($this->never())->method('send');

		$result = $this->service()->triggerPaymentRequest($item, fn (): string => 'Grund');

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

	/** @var list<array{data:string,filename:string,contentType:string}> von giroCodeService() mitgeschrieben: createAttachment()-Aufrufe */
	private array $createdAttachments = [];
	/** @var list<string> von giroCodeService() mitgeschrieben: addBodyText()-Aufrufe der Mail */
	private array $bodyTexts = [];
	private int $attachedCount = 0;
	private int $sentCount = 0;

	/**
	 * Dienst mit eingestelltem Zahlungskonto und einem Mailer, der Anhänge und
	 * Texte mitschreibt. Bewusst ein frischer Mailer-Mock statt $this->mailer
	 * wiederzuverwenden: PHPUnit lässt bei mehrfach konfigurierten method()-Stubs
	 * ohne unterscheidendes with() den zuerst konfigurierten (hier: aus setUp())
	 * gewinnen - ein Überschreiben von createMessage() hier griffe sonst nicht.
	 *
	 * @param string|null $iban IBAN des Zahlungskontos; null = Konto ohne IBAN
	 * @param bool $accountConfigured false = gar kein Zahlungskonto eingestellt
	 */
	private function giroCodeService(?EpcQrCodeGenerator $generator = null, ?LoggerInterface $logger = null, ?string $iban = 'DE02120300000000202051', bool $accountConfigured = true): DunningLadderService {
		$this->createdAttachments = [];
		$this->bodyTexts = [];
		$this->attachedCount = 0;
		$this->sentCount = 0;

		if ($accountConfigured) {
			$this->configStore['sepa_debtor_account_id'] = '9'; // SepaDebtorAccountService ist final, siehe $this->debtorAccount-Klassendoc
		}
		$account = new \OCA\Vereinsbuchhaltung\Db\Account();
		$account->setIban($iban);
		$account->setName('Girokonto Verein');
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('find')->willReturn($account);

		$template = $this->createMock(\OCP\Mail\IEMailTemplate::class);
		$template->method('addBodyText')->willReturnCallback(function (string $text) {
			$this->bodyTexts[] = $text;
		});
		$message = $this->createMock(\OCP\Mail\IMessage::class);
		$message->method('attach')->willReturnCallback(function () use ($message) {
			$this->attachedCount++;
			return $message;
		});
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createEMailTemplate')->willReturn($template);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('createAttachment')->willReturnCallback(function ($data, $filename, $contentType) {
			$this->createdAttachments[] = ['data' => $data, 'filename' => $filename, 'contentType' => $contentType];
			return $this->createMock(\OCP\Mail\IAttachment::class);
		});
		$mailer->method('send')->willReturnCallback(function (): array {
			$this->sentCount++;
			return [];
		});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-10 12:00:00'));
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
			$accounts,
			$generator ?? new EpcQrCodeGenerator(),
			$this->createMock(IUserManager::class),
			$mailer,
			$this->config,
			$time,
			$logger ?? $this->createMock(LoggerInterface::class),
			$this->recipientL10n($l10n),
		);
	}

	/** Ein Erzeuger, der wie bei fehlendem gd oder fehlender Bibliothek scheitert. */
	private function failingGenerator(\Throwable $failure): EpcQrCodeGenerator {
		return new class($failure) extends EpcQrCodeGenerator {
			public function __construct(
				private \Throwable $failure,
			) {
			}

			public function generatePng(string $creditorName, string $creditorIban, ?string $creditorBic, int $amountCents, string $remittanceText): string {
				throw $this->failure;
			}
		};
	}

	/** Bildinhalt eines mitgeschickten GiroCodes zurück in die EPC-Zeilen. */
	private function readGiroCode(string $png): array {
		$payload = (string)(new \chillerlan\QRCode\QRCode(new \chillerlan\QRCode\QROptions(['readerUseImagickIfAvailable' => false])))->readFromBlob($png);
		return explode("\n", $payload);
	}

	public function testGiroCodeWirdAlsAnhangJePositionAngehaengtWennKontoEingestelltIst(): void {
		$item = $this->claim(1, 7, amountCents: 1234);
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);

		$this->giroCodeService()->triggerPaymentRequest($item, fn (): string => 'Grund');

		$this->assertSame(1, $this->attachedCount);
	}

	public function testJedePositionBekommtEinenEigenenGiroCodeMitEigenemBetragUndDateinamen(): void {
		$this->openItems->method('findByMember')->with(7)->willReturn([
			$this->claim(11, 7, amountCents: 4500),
			$this->claim(12, 7, amountCents: 1234),
			$this->claim(13, 7, amountCents: 99900),
		]);
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);

		$result = $this->giroCodeService()->onMandateRevoked(7);

		$this->assertSame(['sent' => 1, 'skipped' => 0, 'failed' => 0], $result);
		$this->assertSame(3, $this->attachedCount, 'Eine Mail, ein Anhang je Position (kein Sammelbetrag)');
		$this->assertSame(['GiroCode-Position-1.png', 'GiroCode-Position-2.png', 'GiroCode-Position-3.png'], array_column($this->createdAttachments, 'filename'));
		$this->assertSame(['image/png', 'image/png', 'image/png'], array_column($this->createdAttachments, 'contentType'));
		$betraege = [];
		foreach ($this->createdAttachments as $attachment) {
			$this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $attachment['data'], 'Der Anhang ist ein echtes PNG');
			$betraege[] = $this->readGiroCode($attachment['data'])[7];
		}
		$this->assertSame(['EUR45.00', 'EUR12.34', 'EUR999.00'], $betraege, 'Jeder Code trägt den Betrag seiner Position');
	}

	/**
	 * Zahlungsaufforderung, Zahlungserinnerung und Mahnung hängen denselben
	 * GiroCode je Position an – die Stufe ändert nur den Text.
	 *
	 * @return array<string, array{0:int}>
	 */
	public static function stagesProvider(): array {
		return [
			'Stufe 0: Zahlungsaufforderung' => [DunningNotice::STAGE_PAYMENT_REQUEST],
			'Stufe 1: Zahlungserinnerung' => [DunningNotice::STAGE_REMINDER],
			'Stufe 2: Mahnung' => [DunningNotice::STAGE_DUNNING],
		];
	}

	/** @dataProvider stagesProvider */
	public function testAlleDreiStufenHaengenEinenGiroCodeJePositionAn(int $stage): void {
		$a = $this->claim(21, 7, amountCents: 4500);
		$b = $this->claim(22, 7, amountCents: 700);
		$this->openItems->method('findClaims')->willReturn([$a, $b]);
		$this->setEligible($stage !== DunningNotice::STAGE_PAYMENT_REQUEST); // Stufe 0 per Tageslauf nur für Überweiser
		// Die jeweils vorherige Stufe liegt weit über den Mahnabstand zurück.
		$this->notices->method('findByOpenItemAndStage')->willReturnCallback(
			fn (int $itemId, int $askedStage) => $askedStage === $stage - 1
				? $this->notice($itemId, $askedStage, '2026-09-01T00:00:00+00:00')
				: null,
		);

		$result = $this->giroCodeService()->runDaily('2026-10-10');

		$this->assertSame(1, $result['sent']);
		$this->assertCount(2, $this->insertedNotices);
		$this->assertSame($stage, $this->insertedNotices[0]->getStage());
		$this->assertSame(['GiroCode-Position-1.png', 'GiroCode-Position-2.png'], array_column($this->createdAttachments, 'filename'));
	}

	public function testDerMailtextVersprichtDenGiroCodeNurWennJedePositionEinenHat(): void {
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);

		$this->giroCodeService()->triggerPaymentRequest($this->claim(1, 7), fn (): string => 'Grund');

		$this->assertContains('Bitte überweisen Sie jede Position einzeln mit dem jeweils genannten Betrag – für jede Position liegt ein GiroCode zum Scannen mit Ihrer Banking-App als Bild bei (die Datei „GiroCode-Position-1.png“ gehört zu Position 1 und so weiter).', $this->bodyTexts);
	}

	public function testJedePositionNenntIhreZahlungsdatenZumAbschreibenImText(): void {
		$this->openItems->method('findByMember')->with(7)->willReturn([
			$this->claim(11, 7, amountCents: 4500),
			$this->claim(12, 7, amountCents: 1234),
		]);
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);

		$this->giroCodeService()->onMandateRevoked(7);

		$positionen = array_values(array_filter($this->bodyTexts, static fn (string $t): bool => str_starts_with($t, 'Position ')));
		$this->assertCount(2, $positionen);
		$this->assertStringStartsWith('Position 1: ', $positionen[0]);
		$this->assertStringStartsWith('Position 2: ', $positionen[1]);

		$bloecke = array_values(array_filter($this->bodyTexts, static fn (string $t): bool => str_starts_with($t, 'Empfänger: ')));
		$this->assertCount(2, $bloecke, 'Ein Zahlungsdaten-Block je Position');
		foreach ($bloecke as $block) {
			$this->assertStringContainsString('IBAN: DE02 1203 0000 0000 2020 51', $block, 'IBAN des Einziehenden Kontos, in Vierergruppen');
			$this->assertStringNotContainsString('Konto: ', $block);
			$this->assertStringContainsString('Verwendungszweck: ', $block);
		}
		$this->assertStringContainsString('Betrag: 45,00 €', $bloecke[0]);
		$this->assertStringContainsString('Betrag: 12,34 €', $bloecke[1]);
		$this->assertStringContainsString('Verwendungszweck: Beitrag, Forderung F-11', $bloecke[0], 'Der Verwendungszweck trägt die Forderungsnummer, nicht die Fälligkeit');
		$this->assertStringContainsString('Verwendungszweck: Beitrag, Forderung F-12', $bloecke[1]);
		$this->assertStringNotContainsString('fällig', $bloecke[0]);
	}

	public function testDieBeitragsperiodeStehtInMonatsnamenImText(): void {
		$item = $this->claim(5, 7, amountCents: 1500, dueDate: '2026-11-01');
		$item->setDescription('Vollmitglied');
		$item->setPeriodStart('2026-11-01');
		$item->setPeriodEnd('2026-11-30');
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);

		$this->giroCodeService()->triggerPaymentRequest($item, fn (): string => 'Grund');

		$position = array_values(array_filter($this->bodyTexts, static fn (string $t): bool => str_starts_with($t, 'Position ')));
		$this->assertSame('Position 1: Vollmitglied, November 2026: 15,00 €, fällig 01.11.2026', $position[0]);
		$block = array_values(array_filter($this->bodyTexts, static fn (string $t): bool => str_starts_with($t, 'Empfänger: ')));
		$this->assertStringContainsString('Verwendungszweck: Vollmitglied, November 2026, Forderung F-5', $block[0]);
	}

	public function testDieZahlungsdatenStehenAuchOhneGiroCodeImText(): void {
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);

		$this->giroCodeService($this->failingGenerator(new \Error('Class not found')))->triggerPaymentRequest($this->claim(1, 7), fn (): string => 'Grund');

		$bloecke = array_values(array_filter($this->bodyTexts, static fn (string $t): bool => str_starts_with($t, 'Empfänger: ')));
		$this->assertCount(1, $bloecke, 'Fällt der Code aus, bleiben die Zahlungsdaten im Text');
	}

	public function testOhneZahlungskontoStehenKeineZahlungsdatenImText(): void {
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);

		$this->giroCodeService(accountConfigured: false)->triggerPaymentRequest($this->claim(1, 7), fn (): string => 'Grund');

		foreach ($this->bodyTexts as $text) {
			$this->assertStringStartsNotWith('Empfänger: ', $text);
		}
	}

	// --- GiroCode-Fehlerfälle: der Mahnversand läuft weiter (Issue #120) ------------------

	/**
	 * Die beiden Gestalten des Ausfalls in einer echten Nextcloud:
	 * fehlt die PHP-Erweiterung `gd`, wirft die Bibliothek eine
	 * `QRCodeOutputException` („ext-gd not loaded"), fehlt die Bibliothek
	 * selbst, ist es ein `\Error` („Class … not found" – so sah das aus, bevor
	 * Application::register() den Composer-Autoloader lud).
	 *
	 * @return array<string, array{0:\Throwable}>
	 */
	public static function giroCodeFailuresProvider(): array {
		return [
			'gd fehlt' => [new \chillerlan\QRCode\Output\QRCodeOutputException('ext-gd not loaded')],
			'Bibliothek fehlt' => [new \Error('Class "chillerlan\\QRCode\\QROptions" not found')],
		];
	}

	/** @dataProvider giroCodeFailuresProvider */
	public function testFehlendesGdOderFehlendeBibliothekStoertDenMahnversandNicht(\Throwable $failure): void {
		$this->openItems->method('findByMember')->with(7)->willReturn([$this->claim(31, 7), $this->claim(32, 7)]);
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);
		$logger = $this->createMock(LoggerInterface::class);

		$result = $this->giroCodeService($this->failingGenerator($failure), $logger)->onMandateRevoked(7);

		$this->assertSame(['sent' => 1, 'skipped' => 0, 'failed' => 0], $result, 'Die Mail geht trotzdem raus');
		$this->assertSame(1, $this->sentCount);
		$this->assertCount(2, $this->insertedNotices, 'Die Stufe gilt als erreicht, sonst ginge die Mail beim nächsten Lauf doppelt raus');
		$this->assertSame(0, $this->attachedCount, 'Ohne Erzeugung gibt es keinen Anhang');
		$this->assertSame([], $this->createdAttachments);
	}

	/** @dataProvider giroCodeFailuresProvider */
	public function testDerAusfallDesGiroCodesStehtImLog(\Throwable $failure): void {
		$this->openItems->method('findByMember')->with(7)->willReturn([$this->claim(31, 7), $this->claim(32, 7)]);
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context) use (&$logged): void {
			$logged[] = ['message' => $message, 'context' => $context];
		});

		$this->giroCodeService($this->failingGenerator($failure), $logger)->onMandateRevoked(7);

		$this->assertCount(2, $logged, 'Eine Warnung je Position, damit sich der Ausfall im Log zuordnen lässt');
		$this->assertStringContainsString('GiroCode', $logged[0]['message']);
		$this->assertSame('vereinsbuchhaltung', $logged[0]['context']['app']);
		$this->assertSame([31, 32], array_column(array_column($logged, 'context'), 'id'));
		$this->assertSame($failure, $logged[0]['context']['exception'], 'Die Ursache hängt als Ausnahme am Eintrag (Meldung und Stacktrace im Log)');
	}

	/** @dataProvider giroCodeFailuresProvider */
	public function testOhneGiroCodeVersprichtDieMailKeinenAnhang(\Throwable $failure): void {
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);

		$this->giroCodeService($this->failingGenerator($failure))->triggerPaymentRequest($this->claim(1, 7), fn (): string => 'Grund');

		$this->assertContains('Bitte überweisen Sie jede Position einzeln mit dem jeweils genannten Betrag.', $this->bodyTexts);
		foreach ($this->bodyTexts as $text) {
			$this->assertStringNotContainsString('GiroCode', $text, 'Kein Hinweis auf einen Anhang, der nicht dran hängt');
		}
	}

	public function testScheitertNurEinePositionBekommenDieAnderenTrotzdemIhrenGiroCode(): void {
		// Ein Betrag von 0 € lässt sich nicht als GiroCode darstellen (EPC069-12: mindestens 0,01 €).
		$this->openItems->method('findByMember')->with(7)->willReturn([$this->claim(41, 7, amountCents: 4500), $this->claim(42, 7, amountCents: 0)]);
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$result = $this->giroCodeService(null, $logger)->onMandateRevoked(7);

		$this->assertSame(1, $result['sent']);
		$this->assertSame(['GiroCode-Position-1.png'], array_column($this->createdAttachments, 'filename'));
		$this->assertContains('Bitte überweisen Sie jede Position einzeln mit dem jeweils genannten Betrag.', $this->bodyTexts, 'Nicht jede Position hat einen Code: der Text verspricht keinen');
	}

	public function testOhneEingestelltesZahlungskontoGehtDieMailOhneGiroCodeUndOhneWarnungRaus(): void {
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$result = $this->giroCodeService(null, $logger, accountConfigured: false)->triggerPaymentRequest($this->claim(1, 7), fn (): string => 'Grund');

		$this->assertSame(1, $result['sent']);
		$this->assertSame(0, $this->attachedCount);
		$this->assertContains('Bitte überweisen Sie jede Position einzeln mit dem jeweils genannten Betrag.', $this->bodyTexts);
	}

	public function testZahlungskontoOhneIbanGehtDieMailOhneGiroCodeRaus(): void {
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$result = $this->giroCodeService(null, $logger, iban: null)->triggerPaymentRequest($this->claim(1, 7), fn (): string => 'Grund');

		$this->assertSame(1, $result['sent']);
		$this->assertSame(0, $this->attachedCount);
	}
}
