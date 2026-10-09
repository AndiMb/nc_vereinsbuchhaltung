<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Service\SelfServiceReceiptMailService;
use OCA\Vereinsbuchhaltung\Service\StaffChangeReceiptService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Quittungsmail an das Mitglied, wenn der Vorstand seinen Beitrag ändert: wann sie rausgeht, was sie sagt
 * und dass ein gescheiterter Versand die (längst gespeicherte) Änderung nicht berührt.
 */
class StaffChangeReceiptServiceTest extends TestCase {

	private SelfServiceReceiptMailService&MockObject $receiptMail;
	private MemberMapper&MockObject $members;
	private ContributionGroupMapper&MockObject $groups;
	private IUserManager&MockObject $userManager;
	private IConfig&MockObject $config;
	private IFactory&MockObject $l10nFactory;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->receiptMail = $this->createMock(SelfServiceReceiptMailService::class);
		$this->members = $this->createMock(MemberMapper::class);
		$this->groups = $this->createMock(ContributionGroupMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->l10nFactory = $this->createMock(IFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/** Eine Übersetzung, die den Text unverändert lässt (nur die Platzhalter füllt), optional mit Kennzeichen davor. */
	private function l10n(string $prefix = ''): IL10N&MockObject {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => $prefix . vsprintf($text, $parameters));
		return $l10n;
	}

	private function service(): StaffChangeReceiptService {
		return new StaffChangeReceiptService($this->receiptMail, $this->members, $this->groups, $this->userManager, $this->config, $this->l10nFactory, $this->l10n(), $this->logger);
	}

	private function member(?string $email = 'katrin@example.org', ?string $ncUserId = null): Member {
		$member = new Member();
		$member->setId(5);
		$member->setMemberType(Member::TYPE_PERSON);
		$member->setFirstName('Katrin');
		$member->setLastName('Brunner');
		$member->setEmail($email);
		$member->setNcUserId($ncUserId);
		$this->members->method('find')->with(5)->willReturn($member);
		return $member;
	}

	private function assignment(int $amountCents, int $intervalMonths = 12, int $groupId = 1): Assignment {
		$a = new Assignment();
		$a->setId(42);
		$a->setMemberId(5);
		$a->setGroupId($groupId);
		$a->setMonthlyAmountCents($amountCents);
		$a->setIntervalMonths($intervalMonths);
		return $a;
	}

	/** @return array{amountCents:int, intervalMonths:int, groupId:int} */
	private function before(int $amountCents, int $intervalMonths = 12, int $groupId = 1): array {
		return ['amountCents' => $amountCents, 'intervalMonths' => $intervalMonths, 'groupId' => $groupId];
	}

	private function preview(): array {
		return ['effectiveFrom' => '2026-11-01', 'firstDueDate' => '2027-01-15'];
	}

	private function group(int $id, string $name): ContributionGroup {
		$group = new ContributionGroup();
		$group->setId($id);
		$group->setName($name);
		return $group;
	}

	public function testBetragsaenderungGehtAnDasMitgliedMitWirktAbUndErstemEinzug(): void {
		$member = $this->member();
		$this->receiptMail->expects($this->once())->method('sendReceipt')->with(
			$member,
			'katrin@example.org',
			'Ihr Beitrag wurde geändert',
			'Der Vorstand hat Ihren Monatsbeitrag von 10,00 € auf 15,50 € geändert.',
			'2026-11-01',
			'2027-01-15',
		);

		$this->assertSame(StaffChangeReceiptService::SENT, $this->service()->send($this->assignment(1550), $this->before(1000), $this->preview()));
	}

	public function testTurnusUndGruppeStehenMitNamenInDerMail(): void {
		$this->member();
		$this->groups->method('find')->willReturnCallback(fn (int $id) => $this->group($id, $id === 1 ? 'Vollmitglied' : 'Ermäßigt'));
		$this->receiptMail->expects($this->once())->method('sendReceipt')->with(
			$this->anything(),
			$this->anything(),
			$this->anything(),
			'Der Vorstand hat Ihre Beitragsgruppe von „Vollmitglied" auf „Ermäßigt" gewechselt. '
				. 'Der Vorstand hat Ihren Zahlungsturnus von jährlich auf vierteljährlich geändert.',
			$this->anything(),
			$this->anything(),
		);

		$this->service()->send($this->assignment(1000, 3, 2), $this->before(1000, 12, 1), $this->preview());
	}

	public function testBeitragsfreiHatKeinenErstenEinzug(): void {
		$this->member();
		$this->receiptMail->expects($this->once())->method('sendReceipt')->with(
			$this->anything(),
			$this->anything(),
			$this->anything(),
			'Der Vorstand hat Ihren Monatsbeitrag von 10,00 € auf 0,00 € geändert.',
			'2026-11-01',
			null,
		);

		$this->service()->send($this->assignment(0), $this->before(1000), $this->preview());
	}

	/** Die Mail folgt der Sprache des Mitglieds (seines Kontos), nicht der des Vorstands, der gerade speichert. */
	public function testMailFolgtDerSpracheDesKontos(): void {
		$member = $this->member(null, 'katrin.b');
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('konto@example.org');
		$this->userManager->method('get')->willReturn($user);
		$this->config->method('getUserValue')->with('katrin.b', 'core', 'lang', '')->willReturn('en');
		$memberL10n = $this->l10n('EN: ');
		$this->l10nFactory->expects($this->once())->method('get')->with('vereinsbuchhaltung', 'en')->willReturn($memberL10n);
		$this->receiptMail->expects($this->once())->method('sendReceipt')->with(
			$member,
			'konto@example.org',
			'EN: Ihr Beitrag wurde geändert',
			'EN: Der Vorstand hat Ihren Monatsbeitrag von 10,00 € auf 15,00 € geändert.',
			$this->anything(),
			$this->anything(),
			null,
			$memberL10n,
		);

		$this->service()->send($this->assignment(1500), $this->before(1000), $this->preview());
	}

	public function testOhneAenderungKeineMail(): void {
		$this->member();
		$this->receiptMail->expects($this->never())->method('sendReceipt');

		$this->assertSame(StaffChangeReceiptService::NOT_NEEDED, $this->service()->send($this->assignment(1000), $this->before(1000), $this->preview()));
	}

	public function testOhneAdresseKeineMailUndDerAusgangWirdGemeldet(): void {
		$this->member(null);
		$this->receiptMail->expects($this->never())->method('sendReceipt');

		$this->assertSame(StaffChangeReceiptService::NO_EMAIL, $this->service()->send($this->assignment(1500), $this->before(1000), $this->preview()));
	}

	public function testErsatzweiseDieAdresseDesVerknuepftenKontos(): void {
		$this->member(null, 'katrin.b');
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('konto@example.org');
		$this->userManager->method('get')->with('katrin.b')->willReturn($user);
		$this->receiptMail->expects($this->once())->method('sendReceipt')->with($this->anything(), 'konto@example.org', $this->anything(), $this->anything(), $this->anything(), $this->anything());

		$this->assertSame(StaffChangeReceiptService::SENT, $this->service()->send($this->assignment(1500), $this->before(1000), $this->preview()));
	}

	/** Der Versand darf scheitern, ohne die längst gespeicherte Änderung zu berühren: nur der Ausgang wird gemeldet und protokolliert. */
	public function testScheiterterVersandWirftNichtSondernMeldetFailed(): void {
		$this->member();
		$this->receiptMail->method('sendReceipt')->willThrowException(new \RuntimeException('SMTP nicht erreichbar'));
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame(StaffChangeReceiptService::FAILED, $this->service()->send($this->assignment(1500), $this->before(1000), $this->preview()));
	}
}
