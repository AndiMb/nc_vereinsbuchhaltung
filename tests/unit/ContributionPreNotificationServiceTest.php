<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleSettings;
use OCA\Vereinsbuchhaltung\Service\ContributionPreNotificationService;
use OCA\Vereinsbuchhaltung\Service\DirectDebitEligibilityResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Die neue, gebündelte Vorabinfo (Spec §3.5/§3.11 T31, Issue #70) – siehe
 * Klassendoc von {@see ContributionPreNotificationService}: ein
 * Rendering-Pfad je Mitglied (auch bei nur einer Position), setzt
 * `prenotified_at` nur bei erfolgreichem Versand.
 */
class ContributionPreNotificationServiceTest extends TestCase {

	private OpenItemMapper&MockObject $openItems;
	private MandateMapper&MockObject $mandates;
	private MemberMapper&MockObject $members;
	private AssignmentMapper&MockObject $assignments;
	private IUserManager&MockObject $userManager;
	private IMailer&MockObject $mailer;
	/** @var array<string,string> */
	private array $configStore = [];
	/** @var OpenItem[] aktualisierte Forderungen (update()-Aufrufe) */
	private array $updated = [];

	protected function setUp(): void {
		$this->configStore = [];
		$this->updated = [];
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->mandates = $this->createMock(MandateMapper::class);
		$this->members = $this->createMock(MemberMapper::class);
		$this->assignments = $this->createMock(AssignmentMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->mailer = $this->createMock(IMailer::class);

		$this->openItems->method('update')->willReturnCallback(function (OpenItem $item): OpenItem {
			$this->updated[] = $item;
			return $item;
		});
	}

	/**
	 * Ob das Testmitglied ein einzugsfähiges Mandat hat - explizit pro Test
	 * aufzurufen (nicht in setUp()): mehrfach konfigurierte `method()`-Stubs
	 * ohne unterscheidendes `with()` lassen bei PHPUnit den ZUERST
	 * konfigurierten gewinnen, ein spaeteres Überschreiben in einem
	 * Einzeltest griffe also nicht.
	 */
	private function setMandateActive(bool $active): void {
		if (!$active) {
			$this->mandates->method('findLiveByMember')->willReturn([]);
			return;
		}
		$mandate = new Mandate();
		$mandate->setStatus(Mandate::STATUS_ACTIVE);
		$mandate->setMandateReference('M-1');
		$this->mandates->method('findLiveByMember')->willReturn([$mandate]);
	}

	private function member(int $id = 7, string $email = 'max@example.org'): Member {
		$m = new Member();
		$m->setId($id);
		$m->setFirstName('Max');
		$m->setLastName('Mustermann');
		$m->setEmail($email);
		return $m;
	}

	private function claim(int $id, int $memberId, string $dueDate, int $amountCents = 1000): OpenItem {
		$item = new OpenItem();
		$item->setId($id);
		$item->setMemberId($memberId);
		$item->setType(OpenItem::TYPE_CONTRIBUTION);
		$item->setStatus('open');
		$item->setDescription('Basisbeitrag');
		$item->setAmountCents($amountCents);
		$item->setDueDate($dueDate);
		$item->setPeriodStart('2026-01-01');
		$item->setPeriodEnd('2026-12-31');
		return $item;
	}

	/**
	 * @param array<string,string> $failedRecipients leer = erfolgreich zugestellt
	 * @return IMessage&MockObject
	 */
	private function stubMailer(array $failedRecipients = []): IMessage&MockObject {
		$template = $this->createMock(IEMailTemplate::class);
		$template->method('renderSubject')->willReturn('Bevorstehender Lastschrifteinzug von Verein');
		$this->mailer->method('createEMailTemplate')->willReturn($template);

		$message = $this->createMock(IMessage::class);
		$message->method('setTo')->willReturnSelf();
		$message->method('setSubject')->willReturnSelf();
		$message->method('useTemplate')->willReturnSelf();
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->method('send')->willReturn($failedRecipients);
		return $message;
	}

	private function service(string $today): ContributionPreNotificationService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => array_key_exists($key, $this->configStore) ? $this->configStore[$key] : $default,
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime($today));

		return new ContributionPreNotificationService(
			$this->openItems,
			$this->mandates,
			$this->members,
			new DirectDebitEligibilityResolver($this->assignments, $this->mandates),
			$this->userManager,
			$this->mailer,
			$config,
			new ContributionCycleSettings($config),
			$time,
			$this->createMock(LoggerInterface::class),
			$l10n,
		);
	}

	public function testBuendeltMehrerePositionenDesselbenMitgliedsInEinerMail(): void {
		$this->setMandateActive(true);
		$claim1 = $this->claim(1, 7, '2026-01-15');
		$claim2 = $this->claim(2, 7, '2026-01-05'); // frueher faellig - muss trotzdem als erste Position erscheinen
		$this->openItems->method('findClaimsAwaitingPrenotification')->willReturn([$claim1, $claim2]);
		$this->members->method('findOrNull')->with(7)->willReturn($this->member());

		$this->mailer->expects($this->once())->method('createMessage');
		$this->stubMailer();

		$result = $this->service('2025-12-25')->sendDue();

		$this->assertSame(['sent' => 1, 'skipped' => 0, 'failed' => 0], $result);
		$this->assertCount(2, $this->updated);
		foreach ($this->updated as $item) {
			$this->assertNotNull($item->getPrenotifiedAt());
		}
	}

	public function testOhneEmpfaengerAdresseWirdUebersprungenOhneMarkierung(): void {
		$this->setMandateActive(true);
		$claim = $this->claim(1, 7, '2026-01-05');
		$this->openItems->method('findClaimsAwaitingPrenotification')->willReturn([$claim]);
		$this->members->method('findOrNull')->with(7)->willReturn($this->member(7, ''));
		$this->userManager->method('get')->willReturn(null);
		$this->mailer->expects($this->never())->method('createMessage');

		$result = $this->service('2025-12-25')->sendDue();

		$this->assertSame(['sent' => 0, 'skipped' => 1, 'failed' => 0], $result);
		$this->assertCount(0, $this->updated);
	}

	public function testFehlgeschlagenerVersandSetztKeinPrenotifiedAt(): void {
		$this->setMandateActive(true);
		$claim = $this->claim(1, 7, '2026-01-05');
		$this->openItems->method('findClaimsAwaitingPrenotification')->willReturn([$claim]);
		$this->members->method('findOrNull')->with(7)->willReturn($this->member());

		$this->stubMailer(['max@example.org' => 'abgelehnt']);

		$result = $this->service('2025-12-25')->sendDue();

		$this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 1], $result);
		$this->assertCount(0, $this->updated);
	}

	public function testForderungOhneEinzugsfaehigesMandatBleibtAussenVor(): void {
		$this->setMandateActive(false);
		$claim = $this->claim(1, 7, '2026-01-05');
		$this->openItems->method('findClaimsAwaitingPrenotification')->willReturn([$claim]);
		$this->mailer->expects($this->never())->method('createMessage');

		$result = $this->service('2025-12-25')->sendDue();

		$this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $result);
	}
}
