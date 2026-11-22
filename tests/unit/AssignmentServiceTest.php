<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentEvent;
use OCA\Vereinsbuchhaltung\Db\AssignmentEventMapper;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\DunningNoticeMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AssignmentService;
use OCA\Vereinsbuchhaltung\Service\ClaimGenerationService;
use OCA\Vereinsbuchhaltung\Service\ClaimService;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleSettings;
use OCA\Vereinsbuchhaltung\Service\ContributionYearService;
use OCA\Vereinsbuchhaltung\Service\DirectDebitEligibilityResolver;
use OCA\Vereinsbuchhaltung\Service\DueDateScheduleService;
use OCA\Vereinsbuchhaltung\Service\MonthlyAmount;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubClockStub;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\ClubCurrencyStub;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die fachlichen Regeln um eine Zuweisung herum (Spec §2.2/§3.1/§3.3):
 * Turnus muss erlaubt sein, Beginn nie in der Vergangenheit, Untergrenze
 * (Gruppe oder individueller Override), keine Überlappung zur selben Gruppe,
 * und der Austritts-Hook beendet offene Zuweisungen.
 */
class AssignmentServiceTest extends TestCase {
	/** Vereinswährung des Dienstes (#202). */
	private string $currencyCode = 'EUR';

	private AssignmentMapper&MockObject $mapper;
	private AssignmentEventMapper&MockObject $eventMapper;
	private ContributionGroupMapper&MockObject $groupMapper;
	private OpenItemMapper&MockObject $openItemMapper;
	private IDBConnection&MockObject $db;
	private TransactionRunner $runner;
	private ClaimService&MockObject $claims;
	/** @var array<int, list<OpenItem>> assignmentId => Perioden, siehe findByAssignment()-Stub */
	private array $openItemsByAssignment = [];

	protected function setUp(): void {
		$this->mapper = $this->createMock(AssignmentMapper::class);
		$this->eventMapper = $this->createMock(AssignmentEventMapper::class);
		$this->groupMapper = $this->createMock(ContributionGroupMapper::class);
		$this->openItemMapper = $this->createMock(OpenItemMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->runner = new TransactionRunner($this->db);
		$this->claims = $this->createMock(ClaimService::class);
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
		// Ein "nacktes" createMock(IAppConfig::class) liefert fuer JEDEN Aufruf
		// null statt des dritten getValueString()-Arguments ($default) - anders
		// als die echte Implementierung. DueDateScheduleService/
		// ContributionYearService verlassen sich aber auf diesen Default
		// (leerer Terminplan/Kalenderjahr), deshalb hier wie in
		// ClaimGenerationServiceTest ein Fake mit Default-Rueckgabe statt des
		// bloßen Mocks.
		$config = $this->createMock(\OCP\IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $default,
		);
		$contributionYear = new ContributionYearService($config, ClubClockStub::system());
		$dueDateSchedule = new DueDateScheduleService($config, $contributionYear, $l10n);
		// Echt statt gemockt: Die Vorschau soll mit genau der Rechnung des Tageslaufs prüfbar sein.
		$claimGeneration = new ClaimGenerationService(
			$this->mapper,
			$this->openItemMapper,
			$this->groupMapper,
			$this->createMock(MemberMapper::class),
			new DirectDebitEligibilityResolver($this->mapper, $this->createMock(MandateMapper::class)),
			$contributionYear,
			$dueDateSchedule,
			new ContributionCycleSettings($config),
			$this->createMock(DebitItemMapper::class),
			$this->createMock(DunningNoticeMapper::class),
			$time,
		);
		return new AssignmentService($this->mapper, $this->eventMapper, $this->groupMapper, $this->openItemMapper, $contributionYear, $dueDateSchedule, $time, $l10n, ClubCurrencyStub::of($this, $this->currencyCode), $this->runner, $claimGeneration, $this->claims);
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

	/** Ein Betrag, der mal zwölf Monate nicht mehr in die Forderungsspalte passt, wird nie gespeichert. */
	public function testCreateLehntBetragUeberDerObergrenzeAb(): void {
		$this->groupMapper->method('find')->willReturn($this->group());
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('höchstens');
		$this->service()->create(7, 1, 12, MonthlyAmount::MAX_CENTS + 1, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, '2026-06-15', null, null, null, 'staff', 'kassenwart');
	}

	public function testCreateLehntNegativeUntergrenzeAb(): void {
		$this->groupMapper->method('find')->willReturn($this->group());
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create(7, 1, 12, -500, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, '2026-06-15', null, -500, 'x', 'staff', 'kassenwart');
	}

	/** @return array<string, array{string, ?string}> */
	public static function keinDatum(): array {
		return [
			'Markup als Beginn' => ['<img src=x>', null],
			'Markup als Ende' => ['2026-06-15', '<b>2027</b>'],
			'unmöglicher Tag' => ['2026-06-15', '2026-02-30'],
			'Zeitstempel' => ['2026-06-15 10:00:00', null],
		];
	}

	/** Beginn und Ende werden als Text verglichen und gespeichert, also muss es ein Kalenderdatum sein. */
	#[DataProvider('keinDatum')]
	public function testCreateLehntAllesAbWasKeinDatumIst(string $validFrom, ?string $validTo): void {
		$this->groupMapper->method('find')->willReturn($this->group());
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->create(7, 1, 12, 800, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, $validFrom, $validTo, null, null, 'staff', 'kassenwart');
	}

	public function testEndeLehntAllesAbWasKeinDatumIst(): void {
		$this->mapper->method('find')->willReturn($this->assignmentInGroup1());
		$this->mapper->expects($this->never())->method('update');
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->end(5, '9999-99-99<script>', AssignmentEvent::ACTOR_STAFF, 'buchhalter');
	}

	/** Die Vorschau lehnt ab, bevor sie rechnet: sonst liefe der Betrag dort über. */
	public function testPreviewChangeLehntBetragUeberDerObergrenzeAb(): void {
		$this->mapper->method('find')->willReturn($this->assignmentInGroup1());
		$this->groupMapper->method('find')->willReturn($this->group());
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('höchstens');
		$this->service()->previewChange(5, PHP_INT_MAX, null);
	}

	/**
	 * Eine Betragsänderung erreicht auch die Forderung, die der Tageslauf
	 * schon angelegt hat, solange sie nicht vorabinformiert ist – in derselben
	 * Transaktion wie die Änderung.
	 */
	public function testUpdateZiehtDieNochNichtVorabinformierteForderungNach(): void {
		$this->mapper->method('find')->willReturn($this->assignmentInGroup1());
		$this->mapper->method('update')->willReturnArgument(0);
		$this->groupMapper->method('find')->willReturn($this->group());
		$this->openItemMapper->method('update')->willReturnArgument(0);
		$claim = $this->claimOfAssignment5(11);
		$claim->setPeriodStart('2027-01-01');
		$claim->setPeriodEnd('2027-12-31');
		$claim->setDueDate('2027-01-01');
		$claim->setAmountCents(12000);
		$this->openItemsByAssignment[5] = [$claim];

		$this->service('2026-12-20')->update(5, 1500, null, null, AssignmentEvent::ACTOR_STAFF, 'kassenwart');

		$this->assertSame(18000, $claim->getAmountCents());
	}

	public function testUpdateLehntBetragUeberDerObergrenzeAb(): void {
		$this->mapper->method('find')->willReturn($this->assignmentInGroup1());
		$this->groupMapper->method('find')->willReturn($this->group());
		$this->mapper->expects($this->never())->method('update');
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->update(5, MonthlyAmount::MAX_CENTS + 1, null, null, AssignmentEvent::ACTOR_MEMBER, 'anna');
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

		$this->claims->expects($this->never())->method('cancel');

		$count = $this->service()->onMemberLeft(7, '2026-06-01');

		$this->assertSame(1, $count);
	}

	/** Eine Forderung der Zuweisung 5 im Zustand `$status`. */
	private function claimOfAssignment5(int $id, string $status = 'open'): OpenItem {
		$item = new OpenItem();
		$item->setId($id);
		$item->setAssignmentId(5);
		$item->setStatus($status);
		return $item;
	}

	/**
	 * Der Tageslauf legt die erste Forderung schon vor dem Beginn an, sobald
	 * ihr Termin im Vorwarnfenster liegt. Wird die Zuweisung vorher
	 * zurückgenommen, hätte die Forderung nie existieren dürfen: Storno.
	 * Bezahltes bleibt unberührt.
	 */
	public function testZuruecknehmenStorniertSchonErzeugteOffeneForderungen(): void {
		$assignment = $this->assignmentInGroup1();
		$assignment->setValidFrom('2026-07-01'); // heute (Standard im Test): 2026-06-15
		$this->mapper->method('find')->willReturn($assignment);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->openItemsByAssignment[5] = [$this->claimOfAssignment5(11), $this->claimOfAssignment5(12, 'paid')];
		$this->claims->expects($this->once())->method('cancel')->with(11, $this->isType('string'));

		$ended = $this->service()->end(5, '2026-06-15', AssignmentEvent::ACTOR_STAFF, 'buchhalter');

		$this->assertSame('2026-06-30', $ended->getValidTo());
	}

	public function testZuruecknehmenScheitertSolangeDieForderungInEinemFreigegebenenLaufSteckt(): void {
		$assignment = $this->assignmentInGroup1();
		$assignment->setValidFrom('2026-07-01');
		$this->mapper->method('find')->willReturn($assignment);
		$this->openItemsByAssignment[5] = [$this->claimOfAssignment5(11)];
		$this->claims->method('cancel')->willThrowException(new \InvalidArgumentException('Verwerfen Sie zuerst den Lauf'));
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->end(5, '2026-06-15', AssignmentEvent::ACTOR_STAFF, 'buchhalter');
	}

	/**
	 * Austritt vor dem Beginn: Die Zuweisung wird nie wirksam, ihre vorab
	 * erzeugte Forderung wird storniert. Lässt sie sich nicht stornieren
	 * (freigegebener Lauf), darf das den Austritts-Lauf nicht anhalten.
	 */
	public function testAustrittVorDemBeginnStorniertSchonErzeugteForderungen(): void {
		$assignment = $this->assignmentInGroup1();
		$assignment->setValidFrom('2026-07-01');
		$this->mapper->method('findOpenAsOf')->willReturn([$assignment]);
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);
		$this->openItemsByAssignment[5] = [$this->claimOfAssignment5(11), $this->claimOfAssignment5(12)];
		$this->claims->expects($this->exactly(2))->method('cancel')->willReturnCallback(
			static fn (int $id): OpenItem => $id === 11 ? throw new \InvalidArgumentException('freigegebener Lauf') : new OpenItem(),
		);

		$this->assertSame(1, $this->service()->onMemberLeft(7, '2026-06-20'));
		$this->assertSame('2026-06-20', $assignment->getValidTo());
	}

	public function testPreviewFirstPeriodRechnetProrataUeberDasBeitragsjahr(): void {
		$assignment = new Assignment();
		$assignment->setIntervalMonths(12);
		$assignment->setMonthlyAmountCents(1000);
		$assignment->setValidFrom('2026-03-15');
		$assignment->setPaymentMethod(Assignment::PAYMENT_METHOD_DIRECT_DEBIT);

		$preview = $this->service('2026-02-01')->previewFirstPeriod($assignment);

		$this->assertSame('2026-01-01', $preview['periodStart']);
		$this->assertSame('2026-12-31', $preview['periodEnd']);
		// Maerz bis Dezember = 10 Monate, voll gezaehlt trotz Start am 15.
		$this->assertSame(10, $preview['months']);
		$this->assertSame(10000, $preview['amountCents']);
		// Ohne eigenen Terminplan (Standard-Einzugstag 0 Tage Versatz) faellt der
		// vorgeschlagene Einzugstermin auf den tatsaechlichen Beginn der ersten
		// (angebrochenen) Periode - siehe DueDateScheduleService::dueDateForPeriod().
		// Am 1.2. erfasst reicht die Zeit fuer die Vorabinfo (Frist 1.3.).
		$this->assertSame('2026-03-15', $preview['dueDate']);
		$this->assertSame('2026-03-15', $preview['scheduledDueDate']);
	}

	/**
	 * Zu spät erfasst: Die Vorabinfo-Frist für den Termin laut Terminplan ist
	 * schon angebrochen, der Tageslauf gibt der Forderung den nächsten Termin
	 * des Turnus (Nachzügler-Regel) – die Vorschau muss das sagen, statt den
	 * nicht mehr erreichbaren Termin zu versprechen.
	 */
	public function testPreviewFirstPeriodNenntDenTerminDesTageslaufsWennDieVorabinfoFristNichtMehrReicht(): void {
		$assignment = new Assignment();
		$assignment->setIntervalMonths(12);
		$assignment->setMonthlyAmountCents(1000);
		$assignment->setValidFrom('2026-07-01');
		$assignment->setPaymentMethod(Assignment::PAYMENT_METHOD_DIRECT_DEBIT);

		$preview = $this->service('2026-06-25')->previewFirstPeriod($assignment);

		$this->assertSame('2026-07-01', $preview['scheduledDueDate']);
		$this->assertSame('2027-01-01', $preview['dueDate']);
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

		// Bis Jahresende berechnet: der Jahresturnus schließt ohne Lücke an.
		$locked = new OpenItem();
		$locked->setPeriodStart('2026-12-01');
		$locked->setPeriodEnd('2026-12-31');
		$locked->setPrenotifiedAt('2026-11-17T00:00:00+00:00');
		$this->openItemsByAssignment[5] = [$locked];

		$this->eventMapper->expects($this->once())->method('insert')
			->with($this->callback(function (AssignmentEvent $e): bool {
				return $e->getType() === AssignmentEvent::TYPE_INTERVAL_CHANGED
					&& $e->getDetailsArray()['effectiveFrom'] === '2027-01-01';
			}));

		$this->service('2026-12-10')->update(5, null, 12, null, AssignmentEvent::ACTOR_MEMBER, null);
	}

	private function assignmentInGroup1(): Assignment {
		$assignment = new Assignment();
		$assignment->setId(5);
		$assignment->setMemberId(7);
		$assignment->setGroupId(1);
		$assignment->setIntervalMonths(12);
		$assignment->setMonthlyAmountCents(1000);
		$assignment->setValidFrom('2026-01-01');
		return $assignment;
	}

	/** Eine zweite Gruppe (ID 2) mit eigener Untergrenze und eigenen Turnussen. */
	private function secondGroup(int $minCents, array $allowedIntervals): ContributionGroup {
		$group = $this->group($minCents, $allowedIntervals);
		$group->setId(2);
		return $group;
	}

	public function testEndeBeiNochNichtBegonnenerZuweisungNimmtSieZurueck(): void {
		$assignment = $this->assignmentInGroup1();
		$assignment->setValidFrom('2026-09-01'); // heute (Standard im Test): 2026-06-15
		$this->mapper->method('find')->willReturn($assignment);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->eventMapper->expects($this->once())->method('insert')
			->with($this->callback(static fn (AssignmentEvent $e): bool => $e->getType() === AssignmentEvent::TYPE_ASSIGNMENT_ENDED
				&& $e->getDetailsArray()['reason'] === 'withdrawn'));

		$ended = $this->service()->end(5, '2026-06-15', AssignmentEvent::ACTOR_STAFF, 'buchhalter');

		// Einen Tag vor dem Beginn: die Zuweisung wird nie wirksam.
		$this->assertSame('2026-08-31', $ended->getValidTo());
		$this->assertFalse($ended->isActive('2026-09-01'));
	}

	public function testEndeVorDemBeginnEinerSchonBegonnenenZuweisungBleibtEinFehler(): void {
		$assignment = $this->assignmentInGroup1(); // Beginn 2026-01-01, heute 2026-06-15
		$this->mapper->method('find')->willReturn($assignment);

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->end(5, '2025-12-31', AssignmentEvent::ACTOR_STAFF, 'buchhalter');
	}

	public function testPreviewChangeEinerKuenftigenZuweisungWirktErstAbIhremBeginn(): void {
		$assignment = $this->assignmentInGroup1();
		$assignment->setValidFrom('2027-01-01');
		$this->mapper->method('find')->willReturn($assignment);
		$this->groupMapper->method('find')->willReturn($this->group());

		$preview = $this->service('2026-06-15')->previewChange(5, 1500, null);

		$this->assertSame('2027-01-01', $preview['effectiveFrom']);
	}

	public function testUpdateGruppenwechselLogtEreignisUndBehaeltBetragUndTurnus(): void {
		$this->mapper->method('find')->willReturn($this->assignmentInGroup1());
		$this->mapper->method('update')->willReturnArgument(0);
		$this->groupMapper->method('find')->willReturn($this->secondGroup(500, [1, 12]));

		$this->eventMapper->expects($this->once())->method('insert')
			->with($this->callback(static fn (AssignmentEvent $e): bool => $e->getType() === AssignmentEvent::TYPE_GROUP_CHANGED
				&& $e->getDetailsArray()['from'] === 1 && $e->getDetailsArray()['to'] === 2));

		$updated = $this->service()->update(5, null, null, 2, AssignmentEvent::ACTOR_STAFF, 'buchhalter');

		$this->assertSame(2, $updated->getGroupId());
		$this->assertSame(1000, $updated->getMonthlyAmountCents());
		$this->assertSame(12, $updated->getIntervalMonths());
	}

	public function testUpdateZahlungsartLogtEreignisUndLaesstAllesAndereStehen(): void {
		$assignment = $this->assignmentInGroup1();
		$assignment->setPaymentMethod(Assignment::PAYMENT_METHOD_DIRECT_DEBIT);
		$this->mapper->method('find')->willReturn($assignment);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->groupMapper->method('find')->willReturn($this->group());

		$this->eventMapper->expects($this->once())->method('insert')
			->with($this->callback(static fn (AssignmentEvent $e): bool => $e->getType() === AssignmentEvent::TYPE_PAYMENT_METHOD_CHANGED
				&& $e->getActorType() === AssignmentEvent::ACTOR_STAFF
				&& $e->getDetailsArray() === ['from' => 'direct_debit', 'to' => 'ueberweisung']));

		$updated = $this->service()->update(5, null, null, null, AssignmentEvent::ACTOR_STAFF, 'buchhalter', null, Assignment::PAYMENT_METHOD_TRANSFER);

		$this->assertSame(Assignment::PAYMENT_METHOD_TRANSFER, $updated->getPaymentMethod());
		$this->assertSame(1000, $updated->getMonthlyAmountCents());
		$this->assertSame(12, $updated->getIntervalMonths());
		$this->assertSame(1, $updated->getGroupId());
	}

	public function testUpdateUnveraenderteZahlungsartLogtNichts(): void {
		$assignment = $this->assignmentInGroup1();
		$assignment->setPaymentMethod(Assignment::PAYMENT_METHOD_DIRECT_DEBIT);
		$this->mapper->method('find')->willReturn($assignment);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->groupMapper->method('find')->willReturn($this->group());
		$this->eventMapper->expects($this->never())->method('insert');

		$this->service()->update(5, null, null, null, AssignmentEvent::ACTOR_STAFF, 'buchhalter', null, Assignment::PAYMENT_METHOD_DIRECT_DEBIT);
	}

	public function testUpdateLehntUnbekannteZahlungsartAbUndSpeichertNichts(): void {
		$this->mapper->method('find')->willReturn($this->assignmentInGroup1());
		$this->groupMapper->method('find')->willReturn($this->group());
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->update(5, null, null, null, AssignmentEvent::ACTOR_STAFF, 'buchhalter', null, 'bar');
	}

	public function testPreviewChangeLehntUnbekannteZahlungsartAb(): void {
		$this->mapper->method('find')->willReturn($this->assignmentInGroup1());
		$this->groupMapper->method('find')->willReturn($this->group());

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->previewChange(5, null, null, null, 'bar');
	}

	public function testUpdateGruppenwechselLehntBetragUnterDerUntergrenzeDerNeuenGruppeAb(): void {
		$this->mapper->method('find')->willReturn($this->assignmentInGroup1());
		$this->groupMapper->method('find')->willReturn($this->secondGroup(1500, [1, 12])); // 10 € < 15 €

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->update(5, null, null, 2, AssignmentEvent::ACTOR_STAFF, 'buchhalter');
	}

	public function testUpdateGruppenwechselLehntEinenTurnusAbDenDieNeueGruppeNichtKennt(): void {
		$this->mapper->method('find')->willReturn($this->assignmentInGroup1()); // Turnus 12
		$this->groupMapper->method('find')->willReturn($this->secondGroup(500, [1, 3]));

		$this->expectException(\InvalidArgumentException::class);
		$this->service()->update(5, null, null, 2, AssignmentEvent::ACTOR_STAFF, 'buchhalter');
	}

	public function testPreviewChangeMitGruppenwechselPrueftDieNeueGruppe(): void {
		$this->mapper->method('find')->willReturn($this->assignmentInGroup1());
		$this->groupMapper->method('find')->willReturn($this->secondGroup(1500, [1, 12]));

		// Mit einem Betrag, der für die neue Gruppe reicht, geht es durch …
		$preview = $this->service()->previewChange(5, 1500, null, 2);
		$this->assertSame('2026-06-15', $preview['effectiveFrom']);

		// … mit dem bisherigen (zu niedrigen) nicht.
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->previewChange(5, null, null, 2);
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

	/** Die Zahlungsart kennt kein Sperrfenster: nur sie zu ändern wirkt auch in einer vorangekündigten Periode sofort. */
	public function testPreviewChangeNurZahlungsartIgnoriertDasSperrfenster(): void {
		$assignment = new Assignment();
		$assignment->setId(5);
		$assignment->setGroupId(1);
		$assignment->setIntervalMonths(1);
		$assignment->setMonthlyAmountCents(1000);
		$assignment->setPaymentMethod(Assignment::PAYMENT_METHOD_DIRECT_DEBIT);
		$assignment->setValidFrom('2026-01-01');
		$this->mapper->method('find')->willReturn($assignment);
		$this->groupMapper->method('find')->willReturn($this->group(allowedIntervals: [1, 12]));

		$locked = new OpenItem();
		$locked->setPeriodStart('2026-01-01');
		$locked->setPeriodEnd('2026-01-31');
		$locked->setPrenotifiedAt('2025-12-15T00:00:00+00:00');
		$this->openItemsByAssignment[5] = [$locked];

		// Betrag und Turnus kommen wie aus dem Dialog unverändert mit – das ist keine Änderung.
		$preview = $this->service('2026-01-10')->previewChange(5, 1000, 1, null, Assignment::PAYMENT_METHOD_TRANSFER);

		$this->assertSame('2026-01-10', $preview['effectiveFrom']);
		$this->assertSame('2026-01-01', $preview['periodStart']);
	}

	public function testPreviewChangeNurZahlungsartEinerKuenftigenZuweisungWirktAbIhremBeginn(): void {
		$assignment = $this->assignmentInGroup1();
		$assignment->setValidFrom('2027-01-01');
		$this->mapper->method('find')->willReturn($assignment);
		$this->groupMapper->method('find')->willReturn($this->group());

		$preview = $this->service('2026-06-15')->previewChange(5, null, null, null, Assignment::PAYMENT_METHOD_TRANSFER);

		$this->assertSame('2027-01-01', $preview['effectiveFrom']);
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

		// Bis Jahresende berechnet: der Jahresturnus schließt ohne Lücke an.
		$locked = new OpenItem();
		$locked->setPeriodStart('2026-12-01');
		$locked->setPeriodEnd('2026-12-31');
		$locked->setPrenotifiedAt('2026-11-17T00:00:00+00:00');
		$this->openItemsByAssignment[5] = [$locked];

		$preview = $this->service('2026-12-10')->previewChange(5, null, 12);

		$this->assertSame('2027-01-01', $preview['effectiveFrom']);
		$this->assertSame('2027-01-01', $preview['periodStart']);
		$this->assertSame('2027-12-31', $preview['periodEnd']);
		$this->assertSame(12000, $preview['amountCents']);
	}

	/** Eine monatliche Zuweisung mit berechneten Perioden bis zum Monatsende $billedUntil. */
	private function monthlyBilledUntil(string $billedUntil, ?string $prenotifiedAt = null): Assignment {
		$assignment = new Assignment();
		$assignment->setId(5);
		$assignment->setGroupId(1);
		$assignment->setIntervalMonths(1);
		$assignment->setMonthlyAmountCents(1000);
		$assignment->setValidFrom('2026-01-01');
		$this->mapper->method('find')->willReturn($assignment);
		$this->groupMapper->method('find')->willReturn($this->group(allowedIntervals: [1, 3, 12]));

		$item = new OpenItem();
		$item->setPeriodStart(substr($billedUntil, 0, 8) . '01');
		$item->setPeriodEnd($billedUntil);
		$item->setPrenotifiedAt($prenotifiedAt);
		$this->openItemsByAssignment[5] = [$item];
		return $assignment;
	}

	/**
	 * Wer im Februar von monatlich auf jährlich wechselte, bekam die nächste
	 * Forderung erst für das Folgejahr: März bis Dezember blieben ohne Beitrag.
	 */
	public function testTurnuswechselDerMonateOhneBeitragLiesseWirdAbgelehnt(): void {
		$this->monthlyBilledUntil('2026-02-28');
		$this->mapper->expects($this->never())->method('update');

		try {
			$this->service('2026-02-10')->previewChange(5, null, 12);
			$this->fail('Die Vorschau hätte ablehnen müssen.');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('bis 28.02.2026 berechnet', $e->getMessage());
			$this->assertStringContainsString('erst am 01.01.2027', $e->getMessage());
		}

		$this->expectException(\InvalidArgumentException::class);
		$this->service('2026-02-10')->update(5, null, 12, null, AssignmentEvent::ACTOR_MEMBER, 'anna');
	}

	/** Endet die Berechnung an einer Grenze des neuen Turnus, schließt er lückenlos an. */
	public function testTurnuswechselAnEinerPeriodengrenzeBleibtMoeglich(): void {
		$this->monthlyBilledUntil('2026-03-31');

		$preview = $this->service('2026-03-10')->previewChange(5, null, 3);

		$this->assertSame('2026-03-10', $preview['effectiveFrom']);
	}

	/** Ein kürzerer Turnus beginnt an jeder Grenze des längeren: von jährlich auf monatlich geht immer. */
	public function testWechselAufKuerzerenTurnusBleibtMoeglich(): void {
		$assignment = $this->monthlyBilledUntil('2026-12-31');
		$assignment->setIntervalMonths(12);
		$this->openItemsByAssignment[5][0]->setPeriodStart('2026-01-01');

		$preview = $this->service('2026-06-15')->previewChange(5, null, 1);

		$this->assertSame('2026-06-15', $preview['effectiveFrom']);
	}

	/** Solange noch nichts berechnet ist, rechnet die erste Periode anteilig: keine Lücke. */
	public function testTurnuswechselOhneBerechnetePeriodeBleibtMoeglich(): void {
		$this->monthlyBilledUntil('2026-02-28');
		$this->openItemsByAssignment[5] = [];

		$preview = $this->service('2026-02-10')->previewChange(5, null, 12);

		$this->assertSame('2026-02-10', $preview['effectiveFrom']);
	}

	/**
	 * Die Sperre richtet sich nach der spätesten Vorabinfo. Eine frühere Periode
	 * ohne Vorabinfo (bezahlt, beitragsfrei, Überweiser-Zeit) hob sie bisher auf.
	 */
	public function testFruehereForderungOhneVorabinfoHebtDieSperreNichtAuf(): void {
		$this->monthlyBilledUntil('2026-02-28', '2026-02-14T00:00:00+00:00');
		$unannounced = new OpenItem();
		$unannounced->setPeriodStart('2026-01-01');
		$unannounced->setPeriodEnd('2026-01-31');
		array_unshift($this->openItemsByAssignment[5], $unannounced);

		$preview = $this->service('2026-02-20')->previewChange(5, 1500, null);

		$this->assertSame('2026-03-01', $preview['effectiveFrom']);
	}

	/** Vereinswährung (#202): ohne Euro keine neue Lastschrift-Zuweisung. */
	public function testOhneEuroGibtEsKeineNeueLastschriftZuweisung(): void {
		$this->currencyCode = 'CHF';
		$this->groupMapper->method('find')->willReturn($this->group());
		$this->mapper->method('findByMemberAndGroup')->willReturn([]);
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('nur in Euro');
		$this->service()->create(7, 1, 12, 800, Assignment::PAYMENT_METHOD_DIRECT_DEBIT, '2026-06-15', null, null, null, 'staff', 'kassenwart');
	}

	public function testOhneEuroBleibtDieUeberweisungMoeglich(): void {
		$this->currencyCode = 'CHF';
		$this->groupMapper->method('find')->willReturn($this->group());
		$this->mapper->method('findByMemberAndGroup')->willReturn([]);
		$this->mapper->method('insert')->willReturnCallback($this->stubInsertAssignsId(101));

		$assignment = $this->service()->create(7, 1, 12, 800, Assignment::PAYMENT_METHOD_TRANSFER, '2026-06-15', null, null, null, 'staff', 'kassenwart');

		$this->assertSame(Assignment::PAYMENT_METHOD_TRANSFER, $assignment->getPaymentMethod());
	}

	public function testOhneEuroLaesstSichNichtAufLastschriftUmstellen(): void {
		$this->currencyCode = 'CHF';
		$assignment = $this->assignmentInGroup1();
		$assignment->setPaymentMethod(Assignment::PAYMENT_METHOD_TRANSFER);
		$this->mapper->method('find')->willReturn($assignment);
		$this->groupMapper->method('find')->willReturn($this->group());

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('nur in Euro');
		$this->service()->previewChange(5, null, null, null, Assignment::PAYMENT_METHOD_DIRECT_DEBIT);
	}

	/** Eine Altzuweisung auf Lastschrift bleibt änderbar (z. B. Betrag), solange die Zahlungsart gleich bleibt. */
	public function testOhneEuroBleibtEineAlteLastschriftZuweisungAenderbar(): void {
		$this->currencyCode = 'CHF';
		$assignment = $this->assignmentInGroup1();
		$assignment->setPaymentMethod(Assignment::PAYMENT_METHOD_DIRECT_DEBIT);
		$this->mapper->method('find')->willReturn($assignment);
		$this->groupMapper->method('find')->willReturn($this->group());

		$preview = $this->service()->previewChange(5, 1500, null, null, Assignment::PAYMENT_METHOD_DIRECT_DEBIT);

		$this->assertArrayHasKey('effectiveFrom', $preview);
	}

	// --- Jahresbeiträge (Issue #270) ---

	/** Gruppe mit Jahresbetrag: Untergrenze 50 €/Jahr, nur jährlicher Turnus. */
	private function annualGroup(bool $prorate = true, int $minAnnualCents = 5000): ContributionGroup {
		$group = $this->group(417, [12]);
		$group->setAmountBasis(ContributionGroup::BASIS_YEAR);
		$group->setProrate($prorate);
		$group->setMinAnnualAmountCents($minAnnualCents);
		return $group;
	}

	/** Bestehende Zuweisung über 50 €/Jahr in Gruppe 1. */
	private function annualAssignment(): Assignment {
		$assignment = $this->assignmentInGroup1();
		$assignment->setMonthlyAmountCents(417);
		$assignment->setAnnualAmountCents(5000);
		return $assignment;
	}

	public function testCreateSpeichertBeiJahresgruppenDenJahresbetragUndDenGerundetenMonatswert(): void {
		$this->groupMapper->method('find')->willReturn($this->annualGroup());
		$this->mapper->method('findByMemberAndGroup')->willReturn([]);
		$this->mapper->method('insert')->willReturnCallback($this->stubInsertAssignsId(101));

		$assignment = $this->service()->create(7, 1, 12, 0, Assignment::PAYMENT_METHOD_TRANSFER, '2026-06-15', null, null, null, 'staff', 'kassenwart', annualAmountCents: 7000);

		$this->assertSame(7000, $assignment->getAnnualAmountCents());
		$this->assertSame(583, $assignment->getMonthlyAmountCents());
	}

	public function testCreateLehntJahresbetragUnterDerJahresUntergrenzeAb(): void {
		$this->groupMapper->method('find')->willReturn($this->annualGroup());

		$this->expectExceptionMessage('Jahresbeitrag');
		$this->service()->create(7, 1, 12, 0, Assignment::PAYMENT_METHOD_TRANSFER, '2026-06-15', null, null, null, 'staff', 'kassenwart', annualAmountCents: 4999);
	}

	public function testCreateErlaubtJahresbetragUnterDerUntergrenzeMitIndividuellerJahresUntergrenze(): void {
		$this->groupMapper->method('find')->willReturn($this->annualGroup());
		$this->mapper->method('findByMemberAndGroup')->willReturn([]);
		$this->mapper->method('insert')->willReturnCallback($this->stubInsertAssignsId(102));

		$assignment = $this->service()->create(7, 1, 12, 0, Assignment::PAYMENT_METHOD_TRANSFER, '2026-06-15', null, null, 'Sozialermäßigung', 'staff', 'kassenwart', annualAmountCents: 2500, minAnnualOverrideCents: 2500);

		$this->assertSame(2500, $assignment->getAnnualAmountCents());
		$this->assertSame(2500, $assignment->getMinAnnualOverrideCents());
		$this->assertNull($assignment->getMinMonthlyAmountOverrideCents());
	}

	public function testCreateLehntFehlendenJahresbetragBeiJahresgruppeAb(): void {
		$this->groupMapper->method('find')->willReturn($this->annualGroup());

		$this->expectExceptionMessage('Jahresbetrag');
		$this->service()->create(7, 1, 12, 800, Assignment::PAYMENT_METHOD_TRANSFER, '2026-06-15', null, null, null, 'staff', 'kassenwart');
	}

	/** Ein Jahresbetrag an einer Monatsgruppe würde als Monatsbetrag gelesen und läge um den Faktor 12 daneben. */
	public function testCreateLehntJahresbetragBeiMonatsgruppeAb(): void {
		$this->groupMapper->method('find')->willReturn($this->group());

		$this->expectExceptionMessage('Monatsbetrag');
		$this->service()->create(7, 1, 12, 800, Assignment::PAYMENT_METHOD_TRANSFER, '2026-06-15', null, null, null, 'staff', 'kassenwart', annualAmountCents: 9600);
	}

	public function testUpdateAendertDenJahresbetragUndLogtIhnInJahreswerten(): void {
		$this->mapper->method('find')->willReturn($this->annualAssignment());
		$this->groupMapper->method('find')->willReturn($this->annualGroup());
		$this->mapper->method('update')->willReturnArgument(0);
		$this->eventMapper->expects($this->once())->method('insert')->with($this->callback(
			static function (AssignmentEvent $e): bool {
				$details = $e->getDetailsArray();
				return $e->getType() === AssignmentEvent::TYPE_AMOUNT_CHANGED
					&& $details['from'] === 5000 && $details['to'] === 7000 && $details['basis'] === 'year';
			},
		));

		$updated = $this->service()->update(5, null, null, null, 'staff', 'kassenwart', annualAmountCents: 7000);

		$this->assertSame(7000, $updated->getAnnualAmountCents());
		$this->assertSame(583, $updated->getMonthlyAmountCents());
	}

	public function testUpdateLehntMonatsbetragAnJahreszuweisungAb(): void {
		$this->mapper->method('find')->willReturn($this->annualAssignment());
		$this->groupMapper->method('find')->willReturn($this->annualGroup());

		$this->expectExceptionMessage('Jahresbetrag');
		$this->service()->update(5, 800, null, null, 'staff', 'kassenwart');
	}

	public function testUpdateLehntJahresbetragUnterDerUntergrenzeAb(): void {
		$this->mapper->method('find')->willReturn($this->annualAssignment());
		$this->groupMapper->method('find')->willReturn($this->annualGroup());

		$this->expectExceptionMessage('Jahresbeitrag');
		$this->service()->update(5, null, null, null, 'staff', 'kassenwart', annualAmountCents: 4000);
	}

	/** Der Betrag bliebe stehen, hätte in der neuen Gruppe aber eine andere Bedeutung. */
	public function testUpdateLehntWechselZwischenMonatsUndJahresgruppeAb(): void {
		$this->mapper->method('find')->willReturn($this->assignmentInGroup1());
		$annual = $this->annualGroup();
		$annual->setId(2);
		$this->groupMapper->method('find')->with(2)->willReturn($annual);

		$this->expectExceptionMessage('Betragsangabe');
		$this->service()->update(5, null, null, 2, 'staff', 'kassenwart');
	}

	public function testPreviewChangeLehntWechselZwischenMonatsUndJahresgruppeAb(): void {
		$this->mapper->method('find')->willReturn($this->assignmentInGroup1());
		$annual = $this->annualGroup();
		$annual->setId(2);
		$this->groupMapper->method('find')->with(2)->willReturn($annual);

		$this->expectExceptionMessage('Betragsangabe');
		$this->service()->previewChange(5, null, null, 2);
	}

	public function testPreviewChangeRechnetDenNeuenJahresbetragAnteilig(): void {
		$this->mapper->method('find')->willReturn($this->annualAssignment());
		$this->groupMapper->method('find')->willReturn($this->annualGroup());

		$preview = $this->service('2026-06-15')->previewChange(5, null, null, annualAmountCents: 6000);

		// Juni bis Dezember = 7 Monate: 60 € × 7 ÷ 12 = 35,00 €.
		$this->assertSame(3500, $preview['amountCents']);
	}

	public function testPreviewChangeOhneAnteiligeBerechnungZeigtDenVollenJahresbetrag(): void {
		$this->mapper->method('find')->willReturn($this->annualAssignment());
		$this->groupMapper->method('find')->willReturn($this->annualGroup(false));

		$preview = $this->service('2026-06-15')->previewChange(5, null, null, annualAmountCents: 6000);

		$this->assertSame(6000, $preview['amountCents']);
	}

	public function testPreviewFirstPeriodRechnetDenJahresbetragAnteiligGerundet(): void {
		$this->groupMapper->method('find')->with(1)->willReturn($this->annualGroup());
		$assignment = new Assignment();
		$assignment->setGroupId(1);
		$assignment->setIntervalMonths(12);
		$assignment->setMonthlyAmountCents(417);
		$assignment->setAnnualAmountCents(5000);
		$assignment->setValidFrom('2026-06-15');

		$preview = $this->service()->previewFirstPeriod($assignment);

		$this->assertSame(7, $preview['months']);
		$this->assertSame(2917, $preview['amountCents']);
	}

	public function testPreviewFirstPeriodOhneAnteiligeBerechnungZeigtDenVollenJahresbetrag(): void {
		$this->groupMapper->method('find')->with(1)->willReturn($this->annualGroup(false));
		$assignment = new Assignment();
		$assignment->setGroupId(1);
		$assignment->setIntervalMonths(12);
		$assignment->setMonthlyAmountCents(417);
		$assignment->setAnnualAmountCents(5000);
		$assignment->setValidFrom('2026-06-15');

		$this->assertSame(5000, $this->service()->previewFirstPeriod($assignment)['amountCents']);
	}

	public function testSetMinAmountOverrideSetztBeiJahreszuweisungenDieJahresUntergrenze(): void {
		$this->mapper->method('find')->with(5)->willReturn($this->annualAssignment());
		$this->mapper->method('update')->willReturnArgument(0);

		$updated = $this->service()->setMinAmountOverride(5, 6000, 'Fördermitglied', 'staff', 'kassenwart');

		$this->assertSame(6000, $updated->getMinAnnualOverrideCents());
		$this->assertNull($updated->getMinMonthlyAmountOverrideCents());
		// Der bisherige Beitrag von 50 € lag darunter und zieht mit.
		$this->assertSame(6000, $updated->getAnnualAmountCents());
		$this->assertSame(500, $updated->getMonthlyAmountCents());
	}

	public function testRaiseToMinimumHebtBeiJahreszuweisungenDenJahresbetrag(): void {
		$assignment = $this->annualAssignment();
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$this->service()->raiseToMinimum($assignment, 7000, 'kassenwart');

		$this->assertSame(7000, $assignment->getAnnualAmountCents());
		$this->assertSame(583, $assignment->getMonthlyAmountCents());
	}

	// ---- Zuweisung und Ereignis gemeinsam (Review 2026-11-18, Backend 1) ----

	/**
	 * Das Ereignis ist die Historie der Beitragsänderungen. Scheitert einer der
	 * beiden Schreibvorgänge, darf der andere nicht stehen bleiben: eine
	 * geänderte Zuweisung ohne Ereignis oder ein Ereignis ohne Änderung.
	 */
	private function expectRollback(): void {
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->expectException(\RuntimeException::class);
	}

	private function failingEventInsert(): void {
		$this->eventMapper->method('insert')->willThrowException(new \RuntimeException('Ereignis nicht gespeichert'));
	}

	public function testCreateRolltZurueckWennDasEreignisScheitert(): void {
		$this->groupMapper->method('find')->willReturn($this->group());
		$this->mapper->method('findByMemberAndGroup')->willReturn([]);
		$this->mapper->method('insert')->willReturnCallback($this->stubInsertAssignsId(101));
		$this->failingEventInsert();
		$this->expectRollback();

		$this->service()->create(7, 1, 12, 800, Assignment::PAYMENT_METHOD_TRANSFER, '2026-06-15', null, null, null, AssignmentEvent::ACTOR_STAFF, 'kassenwart');
	}

	public function testUpdateRolltDasEreignisZurueckWennDasSpeichernScheitert(): void {
		$this->mapper->method('find')->willReturn($this->assignmentInGroup1());
		$this->mapper->method('update')->willThrowException(new \RuntimeException('Zuweisung nicht gespeichert'));
		$this->groupMapper->method('find')->willReturn($this->group());
		$this->expectRollback();

		$this->service()->update(5, null, null, null, AssignmentEvent::ACTOR_STAFF, 'buchhalter', null, Assignment::PAYMENT_METHOD_TRANSFER);
	}

	public function testSetMinAmountOverrideRolltZurueckWennDasSpeichernScheitert(): void {
		$this->mapper->method('find')->willReturn($this->assignmentInGroup1());
		$this->mapper->method('update')->willThrowException(new \RuntimeException('Zuweisung nicht gespeichert'));
		$this->expectRollback();

		$this->service()->setMinAmountOverride(5, 1200, 'Förderer', AssignmentEvent::ACTOR_STAFF, 'buchhalter');
	}

	public function testEndRolltZurueckWennDasSpeichernScheitert(): void {
		$this->mapper->method('find')->willReturn($this->assignmentInGroup1());
		$this->mapper->method('update')->willThrowException(new \RuntimeException('Zuweisung nicht gespeichert'));
		$this->expectRollback();

		$this->service()->end(5, '2026-06-30', AssignmentEvent::ACTOR_STAFF, 'buchhalter');
	}

	public function testOnMemberLeftRolltAlleZuweisungenZurueckWennEinEreignisScheitert(): void {
		$first = $this->assignmentInGroup1();
		$second = $this->assignmentInGroup1();
		$second->setId(6);
		$this->mapper->method('findOpenAsOf')->willReturn([$first, $second]);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->failingEventInsert();
		$this->expectRollback();

		$this->service()->onMemberLeft(7, '2026-06-01');
	}

	public function testRaiseToMinimumRolltZurueckWennDasEreignisScheitert(): void {
		$this->mapper->method('update')->willReturnArgument(0);
		$this->failingEventInsert();
		$this->expectRollback();

		$this->service()->raiseToMinimum($this->assignmentInGroup1(), 1200, 'kassenwart');
	}
}
