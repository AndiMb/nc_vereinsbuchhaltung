<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCA\Vereinsbuchhaltung\Db\DebitBatchMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItem;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\DunningNotice;
use OCA\Vereinsbuchhaltung\Db\DunningNoticeMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebit;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCA\Vereinsbuchhaltung\Service\ClaimOverviewService;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleSettings;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleTaskService;
use OCA\Vereinsbuchhaltung\Service\DirectDebitEligibilityResolver;
use OCA\Vereinsbuchhaltung\Service\DunningSettings;
use OCA\Vereinsbuchhaltung\Service\DunningTaskService;
use OCA\Vereinsbuchhaltung\Service\TaskTargetResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die lesende Forderungsübersicht des Einzug-Unterreiters (Issue #104) – siehe
 * Klassendoc von {@see ClaimOverviewService}: abgeleiteter Zustand inkl. Einzug,
 * Mahnstand, Rücklastschrift in Klartext (Code nur auf Wunsch) und Störfälle.
 */
class ClaimOverviewServiceTest extends TestCase {

	private const TODAY = '2026-10-10';

	private OpenItemMapper&MockObject $openItems;
	private MemberMapper&MockObject $members;
	private DebitBatchMapper&MockObject $batches;
	private DebitItemMapper&MockObject $debitItems;
	private ReturnedDebitMapper&MockObject $returnedDebits;
	private DunningNoticeMapper&MockObject $notices;
	private AssignmentMapper&MockObject $assignments;
	private MandateMapper&MockObject $mandates;
	private ContributionCycleTaskService&MockObject $cycleTasks;
	private IUserManager&MockObject $userManager;

	/** @var OpenItem[] */
	private array $claims = [];
	/** @var DebitBatch[] */
	private array $batchList = [];
	/** @var DebitItem[] */
	private array $liveItems = [];
	/** @var array<int,ReturnedDebit> */
	private array $returned = [];
	/** @var DunningNotice[] */
	private array $noticeList = [];
	/** @var list<array<string,mixed>> */
	private array $tasks = [];
	private bool $hasMandate = true;

	protected function setUp(): void {
		$this->claims = [];
		$this->batchList = [];
		$this->liveItems = [];
		$this->returned = [];
		$this->noticeList = [];
		$this->tasks = [];
		$this->hasMandate = true;

		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->openItems->method('findClaims')->willReturnCallback(fn () => $this->claims);
		$this->members = $this->createMock(MemberMapper::class);
		$this->members->method('findAll')->willReturn([$this->member(1, 'Anna', 'Muster')]);
		$this->batches = $this->createMock(DebitBatchMapper::class);
		$this->batches->method('findAll')->willReturnCallback(fn () => $this->batchList);
		$this->debitItems = $this->createMock(DebitItemMapper::class);
		$this->debitItems->method('findAllInLiveBatches')->willReturnCallback(fn () => $this->liveItems);
		$this->returnedDebits = $this->createMock(ReturnedDebitMapper::class);
		$this->returnedDebits->method('findAllByDebitItem')->willReturnCallback(fn () => $this->returned);
		$this->notices = $this->createMock(DunningNoticeMapper::class);
		$this->notices->method('findAll')->willReturnCallback(fn () => $this->noticeList);

		$this->assignments = $this->createMock(AssignmentMapper::class);
		$this->mandates = $this->createMock(MandateMapper::class);
		$this->mandates->method('findLiveByMember')->willReturnCallback(function (): array {
			if (!$this->hasMandate) {
				return [];
			}
			$mandate = new Mandate();
			$mandate->setStatus(Mandate::STATUS_ACTIVE);
			return [$mandate];
		});
		$this->cycleTasks = $this->createMock(ContributionCycleTaskService::class);
		$this->cycleTasks->method('findTasks')->willReturnCallback(fn () => $this->tasks);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => $uid === 'kassenwart' ? 'Katrin Kassenwart' : null);
	}

	private function service(): ClaimOverviewService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime(self::TODAY . ' 12:00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$dunningSettings = new DunningSettings($config);

		return new ClaimOverviewService(
			$this->openItems,
			$this->members,
			$this->batches,
			$this->debitItems,
			$this->returnedDebits,
			$this->notices,
			$dunningSettings,
			new ContributionCycleSettings($config),
			new DirectDebitEligibilityResolver($this->assignments, $this->mandates),
			$this->cycleTasks,
			new DunningTaskService($this->openItems, $this->notices, $this->members, $dunningSettings, $time, $l10n),
			new TaskTargetResolver($this->members, $this->mandates, $this->assignments, $this->openItems),
			$this->userManager,
			$time,
			$l10n,
		);
	}

	private function member(int $id, string $first, string $last): Member {
		$member = new Member();
		$member->setId($id);
		$member->setFirstName($first);
		$member->setLastName($last);
		return $member;
	}

	private function claim(int $id, string $dueDate = '2026-11-01', string $status = 'open'): OpenItem {
		$item = new OpenItem();
		$item->setId($id);
		$item->setMemberId(1);
		$item->setType(OpenItem::TYPE_CONTRIBUTION);
		$item->setStatus($status);
		$item->setDescription('Vereinsbeitrag');
		$item->setAmountCents(4500);
		$item->setDueDate($dueDate);
		return $this->claims[] = $item;
	}

	private function batch(int $id, string $status, string $dueDate): DebitBatch {
		$batch = new DebitBatch();
		$batch->setId($id);
		$batch->setStatus($status);
		$batch->setDueDate($dueDate);
		return $this->batchList[] = $batch;
	}

	private function debitItem(int $id, int $batchId, int $claimId): DebitItem {
		$item = new DebitItem();
		$item->setId($id);
		$item->setBatchId($batchId);
		$item->setOpenItemId($claimId);
		return $this->liveItems[] = $item;
	}

	private function returnedDebit(int $debitItemId, ?string $code, ?string $text = null): ReturnedDebit {
		$returned = new ReturnedDebit();
		$returned->setDebitItemId($debitItemId);
		$returned->setReasonCode($code);
		$returned->setReasonText($text);
		$returned->setReceivedAt('2026-10-05');
		return $this->returned[$debitItemId] = $returned;
	}

	private function notice(int $claimId, int $stage, string $sentAt): DunningNotice {
		$notice = new DunningNotice();
		$notice->setOpenItemId($claimId);
		$notice->setStage($stage);
		$notice->setSentAt($sentAt);
		return $this->noticeList[] = $notice;
	}

	/** @return array<string,mixed> */
	private function rowOf(int $claimId, bool $withCodes = false): array {
		foreach ($this->service()->build($withCodes)['claims'] as $row) {
			if ($row['id'] === $claimId) {
				return $row;
			}
		}
		$this->fail('Forderung ' . $claimId . ' fehlt in der Übersicht.');
	}

	public function testEineNochNichtEingezogeneForderungIstOffenMitMitgliedsname(): void {
		$this->claim(1);

		$result = $this->service()->build(false);

		$this->assertSame(self::TODAY, $result['today']);
		$this->assertSame(14, $result['dunningIntervalDays']);
		$this->assertCount(1, $result['claims']);
		$row = $result['claims'][0];
		$this->assertSame('offen', $row['state']);
		$this->assertSame('Anna Muster', $row['memberDisplayName']);
		$this->assertNull($row['debit']);
		$this->assertNull($row['returned']);
		$this->assertFalse($row['deferred']);
		$this->assertSame([], $row['issues']);
	}

	public function testEinPostenImFreigegebenenLaufIstImEinzug(): void {
		$this->claim(1);
		$this->batch(10, DebitBatch::STATUS_RELEASED, '2026-11-01');
		$this->debitItem(100, 10, 1);

		$row = $this->rowOf(1);

		$this->assertSame('im_einzug', $row['state']);
		$this->assertSame(['batchId' => 10, 'status' => 'freigegeben', 'dueDate' => '2026-11-01'], $row['debit']);
	}

	public function testEingereichterLaufMitVerstrichenemTerminIstEingezogen(): void {
		$this->claim(1, '2026-10-01');
		$this->batch(10, DebitBatch::STATUS_SUBMITTED, '2026-10-01');
		$this->debitItem(100, 10, 1);

		$this->assertSame('eingezogen', $this->rowOf(1)['state']);
	}

	public function testEineRuecklastschriftMachtDieForderungZurueckgegebenUndZeigtNurKlartext(): void {
		$this->claim(1, '2026-10-01');
		$this->batch(10, DebitBatch::STATUS_SUBMITTED, '2026-10-01');
		$this->debitItem(100, 10, 1);
		$this->returnedDebit(100, 'AM04', 'Bankfreitext');

		$row = $this->rowOf(1);

		$this->assertSame('zurueckgegeben', $row['state']);
		$this->assertSame('Die Lastschrift konnte mangels Kontodeckung nicht eingezogen werden.', $row['returned']['reason']);
		$this->assertSame('2026-10-05', $row['returned']['receivedAt']);
		// Der Code ist admin-only (Spec §3.6): für Revisoren fehlen die Schlüssel ganz.
		$this->assertArrayNotHasKey('reasonCode', $row['returned']);
		$this->assertArrayNotHasKey('reasonText', $row['returned']);
		$this->assertStringNotContainsString('AM04', (string)json_encode($row));
		$this->assertStringNotContainsString('Bankfreitext', (string)json_encode($row));
	}

	public function testBuchhalterBekommenZusaetzlichCodeUndFreitext(): void {
		$this->claim(1, '2026-10-01');
		$this->batch(10, DebitBatch::STATUS_SUBMITTED, '2026-10-01');
		$this->debitItem(100, 10, 1);
		$this->returnedDebit(100, 'AM04', 'Bankfreitext');

		$row = $this->rowOf(1, true);

		$this->assertSame('AM04', $row['returned']['reasonCode']);
		$this->assertSame('Bankfreitext', $row['returned']['reasonText']);
		$this->assertSame('Die Lastschrift konnte mangels Kontodeckung nicht eingezogen werden.', $row['returned']['reason']);
	}

	public function testDieRuecklastschriftIstEinStoerfallMitSchweregradNachKlasse(): void {
		$this->claim(1, '2026-10-01');
		$this->claim(2, '2026-10-01');
		$this->batch(10, DebitBatch::STATUS_SUBMITTED, '2026-10-01');
		$this->debitItem(100, 10, 1);
		$this->debitItem(101, 10, 2);
		$this->returnedDebit(100, 'AM04');
		$this->returnedDebit(101, 'AC01');

		$insufficient = $this->rowOf(1)['issues'];
		$unusable = $this->rowOf(2)['issues'];

		// Spec §3.6: „Hinweis" nur bei fehlender Deckung, sonst dringend.
		$this->assertSame(Task::SEVERITY_HINT, $insufficient[0]['severity']);
		$this->assertStringContainsString('mangels Kontodeckung', $insufficient[0]['message']);
		$this->assertSame(Task::SEVERITY_ACTION_REQUIRED, $unusable[0]['severity']);
		$this->assertStringNotContainsString('AC01', $unusable[0]['message']);
	}

	public function testEinErledigungsvermerkNenntArtUndUrheberMitAnzeigename(): void {
		$item = $this->claim(1, '2026-10-01', 'waived');
		$item->setSettledAt('2026-10-04T10:00:00+00:00');
		$item->setSettledBy('kassenwart');
		$item->setSettlementNote('Härtefall');

		$row = $this->rowOf(1);

		$this->assertSame('erledigt', $row['state']);
		$this->assertSame('waived', $row['settlementType']);
		$this->assertSame('Katrin Kassenwart', $row['settledByName']);
		$this->assertSame('Härtefall', $row['settlementNote']);
	}

	public function testEinUnbekanntesKontoBleibtBeiDerUid(): void {
		$item = $this->claim(1, '2026-10-01', 'paid');
		$item->setSettledAt('2026-10-04T10:00:00+00:00');
		$item->setSettledBy('geloeschter-nutzer');

		$this->assertSame('geloeschter-nutzer', $this->rowOf(1)['settledByName']);
	}

	public function testEineStornierteForderungImVerworfenenLaufIstStorniert(): void {
		$item = $this->claim(1, '2026-10-01', 'cancelled');
		$item->setCancelledAt('2026-10-02T10:00:00+00:00');
		$item->setCancelledReason('Doppelt angelegt');

		$row = $this->rowOf(1);

		$this->assertSame('storniert', $row['state']);
		$this->assertSame('Doppelt angelegt', $row['cancelledReason']);
	}

	public function testEineLaufendeStundungIstErkennbarEineAbgelaufeneNicht(): void {
		$running = $this->claim(1);
		$running->setDeferredUntil('2026-11-30');
		$expired = $this->claim(2);
		$expired->setDeferredUntil('2026-10-01');
		$endsToday = $this->claim(3);
		$endsToday->setDeferredUntil(self::TODAY);

		$this->assertTrue($this->rowOf(1)['deferred']);
		$this->assertFalse($this->rowOf(2)['deferred']);
		// „bis einschließlich": am letzten Tag ist sie noch aktiv (wie im Mahnlauf).
		$this->assertTrue($this->rowOf(3)['deferred']);
	}

	public function testDerMahnstandKommtMitVersandzeitpunktenUndNaechsterStufe(): void {
		$this->claim(1);
		$this->notice(1, 0, '2026-10-01T08:00:00+00:00');
		$this->notice(1, 1, '2026-10-08T08:00:00+00:00');

		$dunning = $this->rowOf(1)['dunning'];

		$this->assertSame(1, $dunning['stage']);
		$this->assertSame([0, 1], array_column($dunning['notices'], 'stage'));
		$this->assertSame(2, $dunning['nextStage']);
		$this->assertSame('2026-10-22', $dunning['nextDueOn']);
	}

	public function testEineGestundeteForderungVerschiebtDieNaechsteMahnstufe(): void {
		$claim = $this->claim(1);
		$claim->setDeferredUntil('2026-11-15');
		$this->notice(1, 0, '2026-10-01T08:00:00+00:00');

		$dunning = $this->rowOf(1)['dunning'];

		$this->assertSame(1, $dunning['nextStage']);
		$this->assertSame('2026-11-16', $dunning['nextDueOn']);
	}

	public function testEineUeberweiserForderungZeigtDenTerminDerZahlungsaufforderung(): void {
		// Ohne Mandat nie per Lastschrift - die Zahlungsaufforderung geht 14 Tage vor Fälligkeit raus.
		$this->hasMandate = false;
		$this->claim(1, '2026-11-01');

		$dunning = $this->rowOf(1)['dunning'];

		$this->assertNull($dunning['stage']);
		$this->assertSame(0, $dunning['nextStage']);
		$this->assertSame('2026-10-18', $dunning['nextDueOn']);
	}

	public function testEineLastschriftForderungHatKeinenTerminFuerDieZahlungsaufforderung(): void {
		$this->claim(1, '2026-11-01');

		$dunning = $this->rowOf(1)['dunning'];

		$this->assertNull($dunning['nextStage']);
		$this->assertNull($dunning['nextDueOn']);
	}

	public function testAufgabenZurForderungHaengenAnDieserForderung(): void {
		$this->claim(1);
		$this->claim(2);
		$this->tasks = [
			['severity' => Task::SEVERITY_ACTION_REQUIRED, 'message' => 'Vorabinfo für Anna Muster konnte nicht rechtzeitig verschickt werden.', 'objectType' => 'claim', 'objectId' => 1],
			// Aggregierte Aufgaben ohne Objekt gehören zu keiner Zeile.
			['severity' => Task::SEVERITY_HINT, 'message' => '2 überfällige Überweiser-Forderungen, zusammen 90,00 €.', 'objectType' => null, 'objectId' => null],
		];
		$this->openItems->method('find')->willReturnCallback(fn (int $id): OpenItem => $this->claims[$id - 1]);

		$first = $this->rowOf(1)['issues'];
		$second = $this->rowOf(2)['issues'];

		$this->assertCount(1, $first);
		$this->assertSame('claim', $first[0]['scope']);
		$this->assertSame(Task::SEVERITY_ACTION_REQUIRED, $first[0]['severity']);
		$this->assertSame([], $second);
	}

	public function testEineZuweisungOhneMandatBetrifftNurDieNochNichtEingezogenenForderungenDesMitglieds(): void {
		$this->claim(1, '2026-11-01');
		$settled = $this->claim(2, '2026-10-01', 'paid');
		$settled->setSettledAt('2026-10-02T10:00:00+00:00');
		$assignment = new Assignment();
		$assignment->setMemberId(1);
		$this->assignments->method('find')->with(7)->willReturn($assignment);
		$this->tasks = [
			['severity' => Task::SEVERITY_ACTION_REQUIRED, 'message' => 'Anna Muster hat eine Zuweisung mit Lastschrift, aber kein einzugsfähiges Mandat.', 'objectType' => 'assignment', 'objectId' => 7],
		];

		$open = $this->rowOf(1)['issues'];
		$done = $this->rowOf(2)['issues'];

		$this->assertCount(1, $open);
		$this->assertSame('member', $open[0]['scope']);
		$this->assertSame([], $done);
	}

	public function testHandlungsbedarfStehtVorDemHinweis(): void {
		$this->claim(1, '2026-10-01');
		$this->batch(10, DebitBatch::STATUS_SUBMITTED, '2026-10-01');
		$this->debitItem(100, 10, 1);
		$this->returnedDebit(100, 'AM04'); // Hinweis
		$this->tasks = [
			['severity' => Task::SEVERITY_ACTION_REQUIRED, 'message' => 'Dringend.', 'objectType' => 'claim', 'objectId' => 1],
		];
		$this->openItems->method('find')->willReturn($this->claims[0]);

		$issues = $this->rowOf(1)['issues'];

		$this->assertSame([Task::SEVERITY_ACTION_REQUIRED, Task::SEVERITY_HINT], array_column($issues, 'severity'));
	}

	public function testEinUnbekanntesMitgliedBekommtEinenErsatznamen(): void {
		$item = $this->claim(1);
		$item->setMemberId(99);

		$this->assertSame('(unbekanntes Mitglied)', $this->rowOf(1)['memberDisplayName']);
	}
}
