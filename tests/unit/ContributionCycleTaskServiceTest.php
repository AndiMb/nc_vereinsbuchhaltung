<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleSettings;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleTaskService;
use OCA\Vereinsbuchhaltung\Service\DirectDebitEligibilityResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Störfälle/Aufgaben des Einzugszyklus (Spec §3.5/§7, Issue #70) – siehe
 * Klassendoc von {@see ContributionCycleTaskService}: nie blockierend, nie
 * quittiert, zwei Schweregrade.
 */
class ContributionCycleTaskServiceTest extends TestCase {

	private OpenItemMapper&MockObject $openItems;
	private AssignmentMapper&MockObject $assignments;
	private MandateMapper&MockObject $mandates;
	private MemberMapper&MockObject $members;
	/** @var array<string,string> */
	private array $configStore = [];

	protected function setUp(): void {
		$this->configStore = [];
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->assignments = $this->createMock(AssignmentMapper::class);
		$this->mandates = $this->createMock(MandateMapper::class);
		$this->members = $this->createMock(MemberMapper::class);
		$this->members->method('displayNameOr')->willReturn('Max Mustermann');

		// Bewusst KEINE Default-Rueckgabe fuer findClaims()/
		// findClaimsAwaitingPrenotification()/findActiveAsOf() hier: PHPUnit
		// laesst bei mehrfach konfigurierten method()-Stubs ohne
		// unterscheidendes with() den ZUERST konfigurierten gewinnen - ein
		// Ueberschreiben in einem Einzeltest griffe also nicht. Ohne
		// Konfiguration generiert PHPUnit fuer den deklarierten Rueckgabetyp
		// `array` automatisch `[]`, das genuegt als impliziter Default.
	}

	private function setMandateActive(bool $active): void {
		$this->mandates->method('findLiveByMember')->willReturn($active ? [$this->activeMandate()] : []);
	}

	private function activeMandate(): Mandate {
		$m = new Mandate();
		$m->setStatus(Mandate::STATUS_ACTIVE);
		return $m;
	}

	private function assignment(int $id, int $memberId, string $paymentMethod = Assignment::PAYMENT_METHOD_DIRECT_DEBIT): Assignment {
		$a = new Assignment();
		$a->setId($id);
		$a->setMemberId($memberId);
		$a->setPaymentMethod($paymentMethod);
		$a->setValidFrom('2026-01-01');
		return $a;
	}

	private function claim(int $id, int $memberId, string $dueDate, ?int $assignmentId = null, int $amountCents = 1000): OpenItem {
		$item = new OpenItem();
		$item->setId($id);
		$item->setMemberId($memberId);
		$item->setType(OpenItem::TYPE_CONTRIBUTION);
		$item->setStatus('open');
		$item->setDescription('Basisbeitrag');
		$item->setAmountCents($amountCents);
		$item->setDueDate($dueDate);
		$item->setAssignmentId($assignmentId);
		return $item;
	}

	private function service(string $today): ContributionCycleTaskService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => array_key_exists($key, $this->configStore) ? $this->configStore[$key] : $default,
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l10n->method('n')->willReturnCallback(static function (string $singular, string $plural, int $count, array $parameters = []): string {
			$text = str_replace('%n', (string)$count, $count === 1 ? $singular : $plural);
			return vsprintf($text, $parameters);
		});
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime($today));

		return new ContributionCycleTaskService(
			$this->openItems,
			$this->assignments,
			$this->members,
			new DirectDebitEligibilityResolver($this->assignments, $this->mandates),
			new ContributionCycleSettings($config),
			$time,
			$l10n,
		);
	}

	public function testKeineAufgabenOhneAuffaelligkeiten(): void {
		$this->assertSame([], $this->service('2026-01-01')->findTasks());
	}

	public function testFehlendesMandatErzeugtHandlungsbedarf(): void {
		$this->setMandateActive(false);
		$this->assignments->method('findActiveAsOf')->willReturn([$this->assignment(1, 7)]);

		$tasks = $this->service('2026-01-01')->findTasks();

		$missingMandateTasks = array_values(array_filter($tasks, fn ($t) => $t['objectType'] === 'assignment'));
		$this->assertCount(1, $missingMandateTasks);
		$this->assertSame(Task::SEVERITY_ACTION_REQUIRED, $missingMandateTasks[0]['severity']);
		$this->assertSame(1, $missingMandateTasks[0]['objectId']);
	}

	public function testAktivesMandatErzeugtKeineAufgabe(): void {
		$this->setMandateActive(true);
		$this->assignments->method('findActiveAsOf')->willReturn([$this->assignment(1, 7)]);

		$this->assertSame([], $this->service('2026-01-01')->findTasks());
	}

	public function testGerisseneVorlauffristEskaliert(): void {
		$this->setMandateActive(true);
		// Faellig in 3 Tagen, Standard-Vorlauffrist 14 Tage laengst verstrichen.
		$claim = $this->claim(5, 7, '2026-01-04');
		$this->openItems->method('findClaimsAwaitingPrenotification')->willReturn([$claim]);

		$tasks = $this->service('2026-01-01')->findTasks();

		$brokenLeadTimeTasks = array_values(array_filter($tasks, fn ($t) => $t['objectType'] === 'claim'));
		$this->assertCount(1, $brokenLeadTimeTasks);
		$this->assertSame(Task::SEVERITY_ACTION_REQUIRED, $brokenLeadTimeTasks[0]['severity']);
		$this->assertSame(5, $brokenLeadTimeTasks[0]['objectId']);
	}

	public function testNochNichtGerisseneVorlauffristErzeugtNichts(): void {
		$this->setMandateActive(true);
		// Faellig in 30 Tagen - die 14-Tage-Frist ist noch laengst nicht angebrochen.
		$claim = $this->claim(5, 7, '2026-01-31');
		$this->openItems->method('findClaimsAwaitingPrenotification')->willReturn([$claim]);

		$this->assertSame([], $this->service('2026-01-01')->findTasks());
	}

	public function testUeberfaelligeUeberweiserForderungenWerdenAggregiert(): void {
		$assignment = $this->assignment(1, 7, Assignment::PAYMENT_METHOD_TRANSFER);
		$this->assignments->method('find')->with(1)->willReturn($assignment);
		$overdue1 = $this->claim(10, 7, '2025-12-01', 1, 1500);
		$overdue2 = $this->claim(11, 7, '2025-12-15', 1, 2500);
		$this->openItems->method('findClaims')->willReturn([$overdue1, $overdue2]);

		$tasks = $this->service('2026-01-01')->findTasks();

		$this->assertCount(1, $tasks);
		$this->assertSame(Task::SEVERITY_HINT, $tasks[0]['severity']);
		$this->assertStringContainsString('2', $tasks[0]['message']);
		$this->assertStringContainsString('40,00', $tasks[0]['message']);
	}

	public function testNaechsterLaufFasstForderungenDesselbenTerminsZusammen(): void {
		$this->setMandateActive(true);
		$dueSoon1 = $this->claim(20, 7, '2026-01-15', null, 1000);
		$dueSoon2 = $this->claim(21, 8, '2026-01-15', null, 2000);
		$dueLater = $this->claim(22, 9, '2026-02-15', null, 3000); // ausserhalb des 21-Tage-Vorwarnfensters
		$this->openItems->method('findClaims')->willReturn([$dueSoon1, $dueSoon2, $dueLater]);

		$tasks = $this->service('2026-01-01')->findTasks();

		$runTasks = array_values(array_filter($tasks, fn ($t) => str_contains($t['message'], 'Nächster Lauf')));
		$this->assertCount(1, $runTasks);
		$this->assertStringContainsString('2026-01-15', $runTasks[0]['message']);
		$this->assertStringContainsString('30,00', $runTasks[0]['message']);
	}
}
