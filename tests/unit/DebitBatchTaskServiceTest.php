<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCA\Vereinsbuchhaltung\Db\DebitBatchMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleSettings;
use OCA\Vereinsbuchhaltung\Service\DebitBatchTaskService;
use OCA\Vereinsbuchhaltung\Service\DirectDebitEligibilityResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * „Freigabe fällig"/„Einreichung überfällig" (Spec §7 Aufgaben-Katalog, Issue
 * #71) – siehe Klassendoc von {@see DebitBatchTaskService}: abgeleitete
 * Abfrage, blockiert nie, quittiert nie.
 */
class DebitBatchTaskServiceTest extends TestCase {

	private OpenItemMapper&MockObject $openItems;
	private DebitItemMapper&MockObject $debitItems;
	private DebitBatchMapper&MockObject $batches;
	private MandateMapper&MockObject $mandates;
	private AssignmentMapper&MockObject $assignments;
	/** @var array<string,string> */
	private array $configStore = [];
	/** @var list<int> */
	private array $alreadyBatchedIds = [];
	/** @var DebitBatch[] */
	private array $releasedNotSubmitted = [];

	protected function setUp(): void {
		$this->configStore = [];
		$this->alreadyBatchedIds = [];
		$this->releasedNotSubmitted = [];
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->debitItems = $this->createMock(DebitItemMapper::class);
		$this->batches = $this->createMock(DebitBatchMapper::class);
		$this->mandates = $this->createMock(MandateMapper::class);
		$this->assignments = $this->createMock(AssignmentMapper::class);
		// Default: einzugsfaehiges Mandat vorhanden. Die uebrigen beiden ueber
		// veraenderliche Properties statt eines zweiten method()->willReturn()
		// je Testfall: PHPUnit laesst bei mehreren Stubs ohne unterscheidendes
		// with() den ZUERST konfigurierten gewinnen (siehe
		// ContributionCycleTaskServiceTest fuer denselben Hinweis).
		$this->mandates->method('findLiveByMember')->willReturn([$this->activeMandate()]);
		$this->debitItems->method('findOpenItemIdsInLiveBatches')->willReturnCallback(fn () => $this->alreadyBatchedIds);
		$this->batches->method('findReleasedNotSubmitted')->willReturnCallback(fn () => $this->releasedNotSubmitted);
	}

	private function activeMandate(): Mandate {
		$m = new Mandate();
		$m->setStatus(Mandate::STATUS_ACTIVE);
		return $m;
	}

	private function claim(int $id, string $dueDate, int $amountCents = 1000): OpenItem {
		$item = new OpenItem();
		$item->setId($id);
		$item->setMemberId(1);
		$item->setType(OpenItem::TYPE_CONTRIBUTION);
		$item->setStatus('open');
		$item->setDescription('Vereinsbeitrag');
		$item->setAmountCents($amountCents);
		$item->setDueDate($dueDate);
		return $item;
	}

	private function batch(int $id, string $dueDate): DebitBatch {
		$b = new DebitBatch();
		$b->setId($id);
		$b->setDueDate($dueDate);
		$b->setStatus(DebitBatch::STATUS_RELEASED);
		return $b;
	}

	private function service(string $today): DebitBatchTaskService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => array_key_exists($key, $this->configStore) ? $this->configStore[$key] : $default,
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime($today));

		return new DebitBatchTaskService(
			$this->openItems,
			$this->debitItems,
			$this->batches,
			new DirectDebitEligibilityResolver($this->assignments, $this->mandates),
			new ContributionCycleSettings($config),
			$time,
			$l10n,
		);
	}

	// --- Freigabe fällig -------------------------------------------------------------

	public function testFreigabeFaelligBeiNichtGebuendelterEinzugsfaehigerForderungImPuffer(): void {
		// Default-Puffer 5 Tage (ContributionCycleSettings::DEFAULT_RELEASE_LEAD_DAYS).
		$this->openItems->method('findClaims')->willReturn([$this->claim(1, '2026-10-03')]);

		$tasks = $this->service('2026-10-01')->findTasks();

		$this->assertCount(1, $tasks);
		$this->assertStringStartsWith('Freigabe fällig', $tasks[0]['message']);
		$this->assertNull($tasks[0]['objectId']);
	}

	public function testFreigabeFaelligIgnoriertBereitsGebuendelteForderung(): void {
		$this->openItems->method('findClaims')->willReturn([$this->claim(1, '2026-10-03')]);
		$this->alreadyBatchedIds = [1];

		$this->assertSame([], $this->service('2026-10-01')->findTasks());
	}

	public function testFreigabeFaelligIgnoriertForderungAusserhalbDesPuffers(): void {
		$this->openItems->method('findClaims')->willReturn([$this->claim(1, '2026-12-01')]);

		$this->assertSame([], $this->service('2026-10-01')->findTasks());
	}

	public function testFreigabeFaelligIgnoriertForderungOhneEinzugsfaehigesMandat(): void {
		$this->mandates = $this->createMock(MandateMapper::class);
		$this->mandates->method('findLiveByMember')->willReturn([]);
		$this->openItems->method('findClaims')->willReturn([$this->claim(1, '2026-10-03')]);

		$this->assertSame([], $this->service('2026-10-01')->findTasks());
	}

	// --- Einreichung überfällig --------------------------------------------------------

	public function testEinreichungUeberfaelligBeiFreigegebenemLaufImPuffer(): void {
		$this->releasedNotSubmitted = [$this->batch(5, '2026-10-03')];

		$tasks = $this->service('2026-10-01')->findTasks();

		$this->assertCount(1, $tasks);
		$this->assertStringStartsWith('Einreichung überfällig', $tasks[0]['message']);
		$this->assertSame('debit_batch', $tasks[0]['objectType']);
		$this->assertSame(5, $tasks[0]['objectId']);
	}

	public function testEinreichungUeberfaelligIgnoriertLaufWeitInDerZukunft(): void {
		$this->releasedNotSubmitted = [$this->batch(5, '2026-12-01')];

		$this->assertSame([], $this->service('2026-10-01')->findTasks());
	}
}
