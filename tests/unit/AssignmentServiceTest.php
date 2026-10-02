<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentEvent;
use OCA\Vereinsbuchhaltung\Db\AssignmentEventMapper;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\AssignmentService;
use OCA\Vereinsbuchhaltung\Service\ContributionYearService;
use OCA\Vereinsbuchhaltung\Service\DueDateScheduleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die fachlichen Regeln um eine Zuweisung herum (Spec §2.2/§3.1/§3.3):
 * Turnus muss erlaubt sein, Beginn nie in der Vergangenheit, Untergrenze
 * (Gruppe oder individueller Override), keine Überlappung zur selben Gruppe,
 * und der Austritts-Hook beendet offene Zuweisungen.
 */
class AssignmentServiceTest extends TestCase {

	private AssignmentMapper&MockObject $mapper;
	private AssignmentEventMapper&MockObject $eventMapper;
	private ContributionGroupMapper&MockObject $groupMapper;
	private OpenItemMapper&MockObject $openItemMapper;
	/** @var array<int, list<OpenItem>> assignmentId => Perioden, siehe findByAssignment()-Stub */
	private array $openItemsByAssignment = [];

	protected function setUp(): void {
		$this->mapper = $this->createMock(AssignmentMapper::class);
		$this->eventMapper = $this->createMock(AssignmentEventMapper::class);
		$this->groupMapper = $this->createMock(ContributionGroupMapper::class);
		$this->openItemMapper = $this->createMock(OpenItemMapper::class);
		// willReturnCallback statt willReturn([]), damit einzelne Tests per
		// $this->openItemsByAssignment[$id] eigene Perioden hinterlegen können -
		// ein zweites method('findByAssignment') im Test würde sonst nie
		// greifen (das zuerst registrierte, uneingeschränkte willReturn([])
		// gewinnt).
		$this->openItemMapper->method('findByAssignment')->willReturnCallback(
			fn (int $id) => $this->openItemsByAssignment[$id] ?? [],
		);
	}

	private function group(int $minCents = 500, array $allowedIntervals = [1, 12]): ContributionGroup {
		$group = new ContributionGroup();
		$group->setId(1);
		$group->setMinMonthlyAmountCents($minCents);
		$group->setAllowedIntervalsArray($allowedIntervals);
		return $group;
	}

	/**
	 * insert() bekommt in echt eine Entität ohne ID und liefert sie mit
	 * vergebener ID zurück (AUTO_INCREMENT) - AssignmentEventMapper::insert()
	 * (logEvent()) braucht danach eine echte assignmentId.
	 */
	private function stubInsertAssignsId(int $id): callable {
		return static function (Assignment $a) use ($id): Assignment {
			$a->setId($id);
			return $a;
		};
	}

	private function service(string $today = '2026-06-15'): AssignmentService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime($today));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		// Ein "nacktes" createMock(IConfig::class) liefert fuer JEDEN Aufruf
		// null statt des dritten getAppValue()-Arguments ($default) - anders
		// als die echte Implementierung. DueDateScheduleService/
		// ContributionYearService verlassen sich aber auf diesen Default
		// (leerer Terminplan/Kalenderjahr), deshalb hier wie in
		// ClaimGenerationServiceTest ein Fake mit Default-Rueckgabe statt des
		// bloßen Mocks.
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $default,
		);
		$contributionYear = new ContributionYearService($config);
		$dueDateSchedule = new DueDateScheduleService($config, $contributionYear, $l10n);
		return new AssignmentService($this->mapper, $this->eventMapper, $this->groupMapper, $this->openItemMapper, $contributionYear, $dueDateSchedule, $time, $l10n);
	}

	public function testCreateLegtDieZuweisungAnUndLogtEinEreignis(): void {
		$this->groupMapper->method('find')->with(1)->willReturn($this->group());
		$this->mapper->method('findByMemberAndGroup')->willReturn([]);
		$this->mapper->method('insert')->willReturnCallback($this->stubInsertAssignsId(101));
		$this->eventMapper->expects($this->once())->method('insert')
			->with($this->callback(fn (AssignmentEvent $e) => $e->getType() === AssignmentEvent::TYPE_ASSIGNMENT_STARTED));

		$assignment = $this->service()->create(7, 1, 12, 800, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, '2026-06-15', null, null, null, AssignmentEvent::ACTOR_STAFF, 'kassenwart');

		$this->assertSame(7, $assignment->getMemberId());
		$this->assertSame(800, $assignment->getMonthlyAmountCents());
	}

	public function testCreateLehntNichtErlaubtenTurnusAb(): void {
		$this->groupMapper->method('find')->willReturn($this->group(500, [1, 12]));
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create(7, 1, 3, 800, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, '2026-06-15', null, null, null, 'staff', 'kassenwart');
	}

	public function testCreateLehntBetragUnterDerGruppenUntergrenzeAb(): void {
		$this->groupMapper->method('find')->willReturn($this->group(500));
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create(7, 1, 12, 400, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, '2026-06-15', null, null, null, 'staff', 'kassenwart');
	}

	public function testCreateErlaubtBetragUnterGruppenUntergrenzeMitPassendemOverride(): void {
		$this->groupMapper->method('find')->willReturn($this->group(500));
		$this->mapper->method('findByMemberAndGroup')->willReturn([]);
		$this->mapper->method('insert')->willReturnCallback($this->stubInsertAssignsId(102));
		$assignment = $this->service()->create(7, 1, 12, 300, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, '2026-06-15', null, 200, 'Sozialermäßigung', 'staff', 'kassenwart');
		$this->assertSame(300, $assignment->getMonthlyAmountCents());
	}

	public function testCreateLehntBeginnInDerVergangenheitAb(): void {
		$this->groupMapper->method('find')->willReturn($this->group());
		$this->expectException(\InvalidArgumentException::class);
		$this->service('2026-06-15')->create(7, 1, 12, 800, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, '2026-06-01', null, null, null, 'staff', 'kassenwart');
	}

	public function testCreateLehntUeberlappendeZuweisungZurSelbenGruppeAb(): void {
		$this->groupMapper->method('find')->willReturn($this->group());
		$existing = new Assignment();
		$existing->setId(99);
		$existing->setValidFrom('2026-01-01');
		$existing->setValidTo(null);
		$this->mapper->method('findByMemberAndGroup')->willReturn([$existing]);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create(7, 1, 12, 800, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, '2026-06-15', null, null, null, 'staff', 'kassenwart');
	}

	public function testCreateErlaubtNichtUeberlappendeZuweisungZurSelbenGruppe(): void {
		$this->groupMapper->method('find')->willReturn($this->group());
		$existing = new Assignment();
		$existing->setId(99);
		$existing->setValidFrom('2025-01-01');
		$existing->setValidTo('2026-05-31');
		$this->mapper->method('findByMemberAndGroup')->willReturn([$existing]);
		$this->mapper->method('insert')->willReturnCallback($this->stubInsertAssignsId(103));

		$assignment = $this->service()->create(7, 1, 12, 800, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, '2026-06-15', null, null, null, 'staff', 'kassenwart');
		$this->assertSame(7, $assignment->getMemberId());
	}

	public function testSetMinAmountOverrideHebtZuNiedrigenBetragAn(): void {
		$assignment = new Assignment();
		$assignment->setId(5);
		$assignment->setMonthlyAmountCents(300);
		$this->mapper->method('find')->with(5)->willReturn($assignment);
		$this->mapper->method('update')->willReturnArgument(0);

		$updated = $this->service()->setMinAmountOverride(5, 400, 'Härtefall', 'staff', 'kassenwart');

		$this->assertSame(400, $updated->getMonthlyAmountCents());
		$this->assertSame(400, $updated->getMinMonthlyAmountOverrideCents());
	}

	public function testSetMinAmountOverrideRuehrtHoehereBetraegeNichtAn(): void {
		$assignment = new Assignment();
		$assignment->setId(5);
		$assignment->setMonthlyAmountCents(1000);
		$this->mapper->method('find')->with(5)->willReturn($assignment);
		$this->mapper->method('update')->willReturnArgument(0);

		$updated = $this->service()->setMinAmountOverride(5, 400, null, 'staff', 'kassenwart');

		$this->assertSame(1000, $updated->getMonthlyAmountCents());
	}

	public function testOnMemberLeftBeendetOffeneZuweisungenZumAustrittsdatum(): void {
		$open = new Assignment();
		$open->setId(3);
		$open->setMemberId(7);
		$open->setValidFrom('2020-01-01');
		$open->setValidTo(null);
		$this->mapper->method('findOpenAsOf')->with(7, '2026-06-01')->willReturn([$open]);
		$this->mapper->expects($this->once())->method('update')->with($this->callback(
			fn (Assignment $a) => $a->getValidTo() === '2026-06-01',
		))->willReturnArgument(0);

		$count = $this->service()->onMemberLeft(7, '2026-06-01');

		$this->assertSame(1, $count);
	}

	public function testPreviewFirstPeriodRechnetProrataUeberDasBeitragsjahr(): void {
		$assignment = new Assignment();
		$assignment->setIntervalMonths(12);
		$assignment->setMonthlyAmountCents(1000);
		$assignment->setValidFrom('2026-03-15');

		$preview = $this->service()->previewFirstPeriod($assignment);

		$this->assertSame('2026-01-01', $preview['periodStart']);
		$this->assertSame('2026-12-31', $preview['periodEnd']);
		// Maerz bis Dezember = 10 Monate, voll gezaehlt trotz Start am 15.
		$this->assertSame(10, $preview['months']);
		$this->assertSame(10000, $preview['amountCents']);
		// Ohne eigenen Terminplan (Standard-Einzugstag 0 Tage Versatz) faellt der
		// vorgeschlagene Einzugstermin auf den tatsaechlichen Beginn der ersten
		// (angebrochenen) Periode - siehe DueDateScheduleService::dueDateForPeriod().
		$this->assertSame('2026-03-15', $preview['dueDate']);
	}

	/**
	 * update() muss beim Turnuswechsel denselben Sonderfall ins Event
	 * schreiben, den previewChange() vorher gezeigt hat - sonst liefe die
	 * Audit-Spur (AssignmentEvent.details.effectiveFrom) auseinander.
	 */
	public function testUpdateIntervalChangedLogtTurnuswechselSonderfall(): void {
		$assignment = new Assignment();
		$assignment->setId(5);
		$assignment->setGroupId(1);
		$assignment->setIntervalMonths(1);
		$assignment->setMonthlyAmountCents(1000);
		$assignment->setValidFrom('2026-01-01');
		$this->mapper->method('find')->willReturn($assignment);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->groupMapper->method('find')->willReturn($this->group(allowedIntervals: [1, 12]));

		$locked = new OpenItem();
		$locked->setPeriodStart('2026-01-01');
		$locked->setPeriodEnd('2026-01-31');
		$locked->setPrenotifiedAt('2025-12-15T00:00:00+00:00');
		$this->openItemsByAssignment[5] = [$locked];

		$this->eventMapper->expects($this->once())->method('insert')
			->with($this->callback(function (AssignmentEvent $e): bool {
				return $e->getType() === AssignmentEvent::TYPE_INTERVAL_CHANGED
					&& $e->getDetailsArray()['effectiveFrom'] === '2027-01-01';
			}));

		$this->service('2026-01-10')->update(5, null, 12, null, AssignmentEvent::ACTOR_MEMBER, null);
	}

	/**
	 * previewChange() ist die Grundlage der Pflicht-UI "Vorschau vor jedem
	 * Speichern" (Spec §3.4, Issue #76 Self-Service Beitrag-Aktionen). Ohne
	 * gesperrte Perioden wirkt eine Änderung sofort, also heute.
	 */
	public function testPreviewChangeOhneSperreWirktAbHeute(): void {
		$assignment = new Assignment();
		$assignment->setId(5);
		$assignment->setGroupId(1);
		$assignment->setIntervalMonths(12);
		$assignment->setMonthlyAmountCents(1000);
		$assignment->setValidFrom('2026-01-01');
		$this->mapper->method('find')->with(5)->willReturn($assignment);
		$this->groupMapper->method('find')->with(1)->willReturn($this->group());

		$preview = $this->service('2026-06-15')->previewChange(5, 1500, null);

		$this->assertSame('2026-06-15', $preview['effectiveFrom']);
		$this->assertSame('2026-01-01', $preview['periodStart']);
		$this->assertSame('2026-12-31', $preview['periodEnd']);
		$this->assertSame('2026-06-15', $preview['firstDueDate']);
		// Juni bis Dezember = 7 volle Monate zum neuen Betrag.
		$this->assertSame(10500, $preview['amountCents']);
	}

	public function testPreviewChangeLehntBetragUnterDerUntergrenzeAb(): void {
		$assignment = new Assignment();
		$assignment->setId(5);
		$assignment->setGroupId(1);
		$assignment->setIntervalMonths(12);
		$assignment->setMonthlyAmountCents(1000);
		$assignment->setValidFrom('2026-01-01');
		$this->mapper->method('find')->willReturn($assignment);
		$this->groupMapper->method('find')->willReturn($this->group(minCents: 500));

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->previewChange(5, 400, null);
	}

	public function testPreviewChangeLehntNichtErlaubtenTurnusAb(): void {
		$assignment = new Assignment();
		$assignment->setId(5);
		$assignment->setGroupId(1);
		$assignment->setIntervalMonths(12);
		$assignment->setMonthlyAmountCents(1000);
		$assignment->setValidFrom('2026-01-01');
		$this->mapper->method('find')->willReturn($assignment);
		$this->groupMapper->method('find')->willReturn($this->group(allowedIntervals: [1, 12]));

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->previewChange(5, null, 3);
	}

	/**
	 * Betrag/Turnus sind gesperrt, sobald für die Periode `prenotified_at`
	 * gesetzt ist (Spec §3.4 "Sperrfenster") - previewChange() muss dieselbe
	 * {@see EffectivityRuleService}-Regel anwenden wie update().
	 */
	public function testPreviewChangeMitGesperrterPeriodeVerschiebtWirktAb(): void {
		$assignment = new Assignment();
		$assignment->setId(5);
		$assignment->setGroupId(1);
		$assignment->setIntervalMonths(1);
		$assignment->setMonthlyAmountCents(1000);
		$assignment->setValidFrom('2026-01-01');
		$this->mapper->method('find')->willReturn($assignment);
		$this->groupMapper->method('find')->willReturn($this->group(allowedIntervals: [1, 12]));

		$locked = new OpenItem();
		$locked->setPeriodStart('2026-01-01');
		$locked->setPeriodEnd('2026-01-31');
		$locked->setPrenotifiedAt('2025-12-15T00:00:00+00:00');
		$this->openItemsByAssignment[5] = [$locked];

		$preview = $this->service('2026-01-10')->previewChange(5, 1500, null);

		$this->assertSame('2026-02-01', $preview['effectiveFrom']);
		$this->assertSame('2026-02-01', $preview['periodStart']);
		$this->assertSame('2026-02-28', $preview['periodEnd']);
		$this->assertSame(1500, $preview['amountCents']);
	}

	/**
	 * Turnuswechsel-Sonderfall (Spec §3.4): wirkt ab der ersten Periode des
	 * NEUEN Turnus, die vollständig hinter der letzten eingezogenen liegt -
	 * nicht einfach ab dem Tag nach der letzten gesperrten Periode im alten
	 * Raster (das wäre hier der 1.2.2026, mitten im Kalenderjahr 2026).
	 */
	public function testPreviewChangeTurnuswechselSonderfallSpringtHinterLetzteSperre(): void {
		$assignment = new Assignment();
		$assignment->setId(5);
		$assignment->setGroupId(1);
		$assignment->setIntervalMonths(1);
		$assignment->setMonthlyAmountCents(1000);
		$assignment->setValidFrom('2026-01-01');
		$this->mapper->method('find')->willReturn($assignment);
		$this->groupMapper->method('find')->willReturn($this->group(allowedIntervals: [1, 12]));

		$locked = new OpenItem();
		$locked->setPeriodStart('2026-01-01');
		$locked->setPeriodEnd('2026-01-31');
		$locked->setPrenotifiedAt('2025-12-15T00:00:00+00:00');
		$this->openItemsByAssignment[5] = [$locked];

		$preview = $this->service('2026-01-10')->previewChange(5, null, 12);

		$this->assertSame('2027-01-01', $preview['effectiveFrom']);
		$this->assertSame('2027-01-01', $preview['periodStart']);
		$this->assertSame('2027-12-31', $preview['periodEnd']);
		$this->assertSame(12000, $preview['amountCents']);
	}
}
