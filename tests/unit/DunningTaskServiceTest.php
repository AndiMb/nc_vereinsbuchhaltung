<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\DunningNotice;
use OCA\Vereinsbuchhaltung\Db\DunningNoticeMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCA\Vereinsbuchhaltung\Service\DunningSettings;
use OCA\Vereinsbuchhaltung\Service\DunningTaskService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Vorstands-Eskalation nach Stufe 2 (Spec §3.6/§7 "danach Aufgabe 'Vorstand
 * entscheiden lassen'", Issue #73) – siehe Klassendoc von {@see DunningTaskService}.
 */
class DunningTaskServiceTest extends TestCase {

	private OpenItemMapper&MockObject $openItems;
	private DunningNoticeMapper&MockObject $notices;
	private MemberMapper&MockObject $members;
	private array $configStore = [];
	private IConfig&MockObject $config;

	protected function setUp(): void {
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->notices = $this->createMock(DunningNoticeMapper::class);
		$this->members = $this->createMock(MemberMapper::class);
		$this->members->method('displayNameOr')->willReturn('Katrin Brunner');

		$this->configStore = [];
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = '') => array_key_exists($key, $this->configStore) ? $this->configStore[$key] : $default);
	}

	private function service(string $today = '2026-10-10'): DunningTaskService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime($today . ' 12:00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new DunningTaskService(
			$this->openItems,
			$this->notices,
			$this->members,
			new DunningSettings($this->config), // final, siehe DunningLadderServiceTest-Klassendoc
			$time,
			$l10n,
		);
	}

	private function claim(int $id, int $memberId, ?string $deferredUntil = null): OpenItem {
		$item = new OpenItem();
		$item->setId($id);
		$item->setMemberId($memberId);
		$item->setType(OpenItem::TYPE_CONTRIBUTION);
		$item->setAmountCents(4500);
		$item->setDeferredUntil($deferredUntil);
		$item->setStatus('open');
		return $item;
	}

	private function stage2Notice(int $openItemId, string $sentAt): DunningNotice {
		$n = new DunningNotice();
		$n->setId(1);
		$n->setOpenItemId($openItemId);
		$n->setStage(DunningNotice::STAGE_DUNNING);
		$n->setSentAt($sentAt);
		$n->setMailBatchReference('batch-1');
		return $n;
	}

	public function testMeldetEskalationNachMahnabstandSeitStufe2(): void {
		$item = $this->claim(1, 7);
		$this->openItems->method('findClaims')->willReturn([$item]);
		$this->notices->method('findByOpenItemAndStage')->willReturn($this->stage2Notice(1, '2026-09-26T00:00:00+00:00')); // genau 14 Tage her

		$tasks = $this->service('2026-10-10')->findBoardEscalationTasks();

		$this->assertCount(1, $tasks);
		$this->assertSame(Task::SEVERITY_ACTION_REQUIRED, $tasks[0]['severity']);
		$this->assertSame('claim', $tasks[0]['objectType']);
		$this->assertSame(1, $tasks[0]['objectId']);
	}

	public function testMeldetNichtsVorAblaufDesMahnabstandsSeitStufe2(): void {
		$item = $this->claim(1, 7);
		$this->openItems->method('findClaims')->willReturn([$item]);
		$this->notices->method('findByOpenItemAndStage')->willReturn($this->stage2Notice(1, '2026-09-27T00:00:00+00:00')); // erst 13 Tage her

		$tasks = $this->service('2026-10-10')->findBoardEscalationTasks();

		$this->assertSame([], $tasks);
	}

	public function testMeldetNichtsOhneStufe2(): void {
		$item = $this->claim(1, 7);
		$this->openItems->method('findClaims')->willReturn([$item]);
		$this->notices->method('findByOpenItemAndStage')->willReturn(null);

		$tasks = $this->service('2026-10-10')->findBoardEscalationTasks();

		$this->assertSame([], $tasks);
	}

	public function testIgnoriertAktuellGestundeteForderung(): void {
		$item = $this->claim(1, 7, deferredUntil: '2026-10-20');
		$this->openItems->method('findClaims')->willReturn([$item]);
		$this->notices->method('findByOpenItemAndStage')->willReturn($this->stage2Notice(1, '2026-08-01T00:00:00+00:00'));

		$tasks = $this->service('2026-10-10')->findBoardEscalationTasks();

		$this->assertSame([], $tasks);
	}

	public function testIgnoriertErledigteForderung(): void {
		$item = $this->claim(1, 7);
		$item->setSettledAt('2026-10-05T00:00:00+00:00');
		$item->setStatus('paid');
		$this->openItems->method('findClaims')->willReturn([$item]);
		$this->notices->method('findByOpenItemAndStage')->willReturn($this->stage2Notice(1, '2026-08-01T00:00:00+00:00'));

		$tasks = $this->service('2026-10-10')->findBoardEscalationTasks();

		$this->assertSame([], $tasks);
	}
}
