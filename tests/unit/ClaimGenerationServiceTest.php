<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\ClaimGenerationService;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleSettings;
use OCA\Vereinsbuchhaltung\Service\ContributionYearService;
use OCA\Vereinsbuchhaltung\Service\DirectDebitEligibilityResolver;
use OCA\Vereinsbuchhaltung\Service\DueDateScheduleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Periodische Forderungserzeugung (Spec §2.2/§3.3/§3.5, Issue #70) – siehe
 * Klassendoc von {@see ClaimGenerationService}: Generierungshorizont
 * (Vorwarnfenster), Prorata an beiden Enden, Mandatsprüfung nur bei
 * `direct_debit`, Nachzügler-Regel, Idempotenz.
 *
 * {@see DirectDebitEligibilityResolver} ist `final` und deshalb nicht direkt
 * doppelbar - hier stattdessen echt instanziiert, gegen dieselbe gemockte
 * {@see AssignmentMapper} plus eine gemockte {@see MandateMapper}
 * ({@see setMandateActive()} steuert, ob das Mitglied ein einzugsfähiges
 * Mandat hat).
 */
class ClaimGenerationServiceTest extends TestCase {

	private AssignmentMapper&MockObject $assignments;
	private OpenItemMapper&MockObject $openItems;
	private ContributionGroupMapper&MockObject $groups;
	private MemberMapper&MockObject $members;
	private MandateMapper&MockObject $mandates;
	/** @var OpenItem[] */
	private array $store = [];
	/** @var array<string,string> simulierter appconfig-Speicher, fuer Terminplan/Lead-Days */
	private array $configStore = [];

	protected function setUp(): void {
		$this->store = [];
		$this->configStore = [];
		$this->assignments = $this->createMock(AssignmentMapper::class);
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->groups = $this->createMock(ContributionGroupMapper::class);
		$this->members = $this->createMock(MemberMapper::class);
		$this->mandates = $this->createMock(MandateMapper::class);
		$this->members->method('displayNameOr')->willReturn('Max Mustermann');

		$this->openItems->method('findByAssignment')->willReturnCallback(
			fn (int $id) => array_values(array_filter($this->store, static fn (OpenItem $i) => $i->getAssignmentId() === $id)),
		);
		$this->openItems->method('insert')->willReturnCallback(function (OpenItem $item): OpenItem {
			$item->setId(count($this->store) + 1);
			$this->store[] = $item;
			return $item;
		});
	}

	/** Ob das Testmitglied ein einzugsfähiges (aktives) Mandat hat. */
	private function setMandateActive(bool $active): void {
		if (!$active) {
			$this->mandates->method('findLiveByMember')->willReturn([]);
			return;
		}
		$mandate = new Mandate();
		$mandate->setStatus(Mandate::STATUS_ACTIVE);
		$this->mandates->method('findLiveByMember')->willReturn([$mandate]);
	}

	private function config(): IConfig&MockObject {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => array_key_exists($key, $this->configStore) ? $this->configStore[$key] : $default,
		);
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->configStore[$key] = $value;
		});
		return $config;
	}

	private function service(string $today): ClaimGenerationService {
		$config = $this->config();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime($today));

		return new ClaimGenerationService(
			$this->assignments,
			$this->openItems,
			$this->groups,
			$this->members,
			new DirectDebitEligibilityResolver($this->assignments, $this->mandates),
			new ContributionYearService($config),
			new DueDateScheduleService($config, new ContributionYearService($config), $l10n),
			new ContributionCycleSettings($config),
			$time,
		);
	}

	private function assignment(
		int $id = 1,
		int $memberId = 7,
		int $intervalMonths = 12,
		int $monthlyAmountCents = 1000,
		string $paymentMethod = Assignment::PAYMENT_METHOD_DIRECT_DEBIT,
		string $validFrom = '2026-01-01',
		?string $validTo = null,
	): Assignment {
		$a = new Assignment();
		$a->setId($id);
		$a->setMemberId($memberId);
		$a->setGroupId(1);
		$a->setIntervalMonths($intervalMonths);
		$a->setMonthlyAmountCents($monthlyAmountCents);
		$a->setPaymentMethod($paymentMethod);
		$a->setValidFrom($validFrom);
		$a->setValidTo($validTo);
		return $a;
	}

	private function group(): ContributionGroup {
		$g = new ContributionGroup();
		$g->setId(1);
		$g->setName('Basisbeitrag');
		return $g;
	}

	public function testErzeugtDieErstePeriodeInnerhalbDesVorwarnfensters(): void {
		$this->assignments->method('findActiveAsOf')->willReturn([$this->assignment()]);
		$this->groups->method('find')->with(1)->willReturn($this->group());
		$this->setMandateActive(true);

		// heute + 21 Tage (Standard-Vorwarnfenster) >= 2026-01-01, UND genug
		// Puffer vor der 14-Tage-Vorlauffrist (sonst griffe die
		// Nachzuegler-Regel schon hier, siehe eigener Test dafuer).
		$result = $this->service('2025-12-14')->generateDue();

		$this->assertSame(['created' => 1, 'blocked' => 0], $result);
		$this->assertCount(1, $this->store);
		$claim = $this->store[0];
		$this->assertSame(12000, $claim->getAmountCents());
		$this->assertSame('2026-01-01', $claim->getDueDate());
		$this->assertSame('2026-01-01', $claim->getPeriodStart());
		$this->assertSame('2026-12-31', $claim->getPeriodEnd());
		$this->assertSame(OpenItem::TYPE_CONTRIBUTION, $claim->getType());
	}

	public function testErzeugtNichtsAusserhalbDesVorwarnfensters(): void {
		$this->assignments->method('findActiveAsOf')->willReturn([$this->assignment()]);
		$this->groups->method('find')->willReturn($this->group());
		$this->setMandateActive(true);

		$result = $this->service('2025-11-01')->generateDue();

		$this->assertSame(['created' => 0, 'blocked' => 0], $result);
		$this->assertCount(0, $this->store);
	}

	public function testProrataAnBeidenEndenBeiBeitrittMittenInDerPeriode(): void {
		$this->assignments->method('findActiveAsOf')->willReturn([$this->assignment(validFrom: '2026-03-15')]);
		$this->groups->method('find')->willReturn($this->group());
		$this->setMandateActive(true);

		// Anker fuer den Termin ist jetzt der Beitritt (15.3.), nicht der 1.1.
		// Puffer wie im ersten Test: genug Abstand zur 14-Tage-Vorlauffrist.
		$result = $this->service('2026-02-25')->generateDue();

		$this->assertSame(['created' => 1, 'blocked' => 0], $result);
		$claim = $this->store[0];
		// Maerz bis Dezember = 10 volle Monate.
		$this->assertSame(10000, $claim->getAmountCents());
		$this->assertSame('2026-03-15', $claim->getPeriodStart());
		$this->assertSame('2026-12-31', $claim->getPeriodEnd());
		$this->assertSame('2026-03-15', $claim->getDueDate());
	}

	public function testProrataAmEndeWennDieZuweisungMittenInDerPeriodeEndet(): void {
		$this->assignments->method('findActiveAsOf')->willReturn([$this->assignment(intervalMonths: 12, validFrom: '2026-01-01', validTo: '2026-06-30')]);
		$this->groups->method('find')->willReturn($this->group());
		$this->setMandateActive(true);

		$result = $this->service('2025-12-14')->generateDue();

		$this->assertSame(['created' => 1, 'blocked' => 0], $result);
		$claim = $this->store[0];
		// Januar bis Juni = 6 volle Monate, nicht die vollen 12.
		$this->assertSame(6000, $claim->getAmountCents());
		$this->assertSame('2026-06-30', $claim->getPeriodEnd());
	}

	public function testKeineWeiterePeriodeNachEndeDerZuweisung(): void {
		// Turnus 1 (monatlich), Zuweisung endet nach der ersten Periode.
		$assignment = $this->assignment(intervalMonths: 1, validFrom: '2026-01-01', validTo: '2026-01-31');
		$this->assignments->method('findActiveAsOf')->willReturn([$assignment]);
		$this->groups->method('find')->willReturn($this->group());
		$this->setMandateActive(true);

		$service = $this->service('2026-01-05');
		$first = $service->generateDue();
		$this->assertSame(['created' => 1, 'blocked' => 0], $first);

		// Ein zweiter Lauf (z.B. naechster Tag) darf keine weitere Periode
		// erzeugen - die Zuweisung ist mit validTo=2026-01-31 vorbei.
		$second = $this->service('2026-01-06')->generateDue();
		$this->assertSame(['created' => 0, 'blocked' => 0], $second);
		$this->assertCount(1, $this->store);
	}

	public function testFehlendesMandatBlockiertOhneForderungZuErzeugen(): void {
		$this->assignments->method('findActiveAsOf')->willReturn([$this->assignment()]);
		$this->groups->method('find')->willReturn($this->group());
		$this->setMandateActive(false);

		$result = $this->service('2025-12-20')->generateDue();

		$this->assertSame(['created' => 0, 'blocked' => 1], $result);
		$this->assertCount(0, $this->store);
	}

	public function testUeberweiserZuweisungBrauchtKeinMandat(): void {
		$this->assignments->method('findActiveAsOf')->willReturn([$this->assignment(paymentMethod: Assignment::PAYMENT_METHOD_TRANSFER)]);
		$this->groups->method('find')->willReturn($this->group());
		$this->mandates->expects($this->never())->method('findLiveByMember');

		$result = $this->service('2025-12-20')->generateDue();

		$this->assertSame(['created' => 1, 'blocked' => 0], $result);
	}

	public function testNachzueglerFaehrtAmNaechstenTerminDesTurnusBeiGerissenerVorlauffrist(): void {
		// Turnus 1 (monatlich). Erster Lauf mit ausreichend Vorlauf (wie im
		// ersten Test): die Januar-Periode faehrt ganz normal am 1.1.
		$assignment = $this->assignment(intervalMonths: 1, validFrom: '2026-01-01');
		$this->assignments->method('findActiveAsOf')->willReturn([$assignment]);
		$this->groups->method('find')->willReturn($this->group());
		$this->setMandateActive(true);

		$this->service('2025-12-14')->generateDue();
		$this->assertCount(1, $this->store);
		$this->assertSame('2026-01-01', $this->store[0]->getDueDate());

		// Der Cron "verpasst" mehrere Tage (z.B. Wartung) und laeuft erst
		// wieder am 25.1.: die Februar-Periode liegt zwar noch im
		// Vorwarnfenster (1.2. <= 25.1.+21), aber ihre Vorlauffrist
		// (1.2. - 14 Tage = 18.1.) ist am 25.1. bereits angebrochen -
		// Nachzuegler-Regel greift, der Einzug faehrt stattdessen am naechsten
		// Termin des Turnus (1.3.). Die Periode selbst (Februar) bleibt
		// unveraendert - nur der Einzugstermin verschiebt sich.
		$this->service('2026-01-25')->generateDue();
		$this->assertCount(2, $this->store);
		$secondClaim = $this->store[1];
		$this->assertSame('2026-02-01', $secondClaim->getPeriodStart());
		$this->assertSame('2026-02-28', $secondClaim->getPeriodEnd());
		$this->assertSame('2026-03-01', $secondClaim->getDueDate());
	}

	/**
	 * Turnuswechsel-Sonderfall (Spec §3.4, Issue #76 "Self-Service
	 * Beitrag-Aktionen"): wechselt eine Zuweisung zwischen zwei Läufen den
	 * Turnus (per AssignmentService::update(), z.B. per Self-Service), kann
	 * das neue Perioden-Raster anders liegen als das alte. Ohne den Guard in
	 * nextPendingPeriod() würde das neue Raster hier "naiv" wieder ab dem
	 * 1.1.2026 beginnen - genau die schon erzeugte Januar-Periode
	 * überlappend. Der Guard muss stattdessen bis zur ersten Periode des
	 * neuen Turnus weiterspringen, die vollständig dahinter liegt (2027).
	 */
	public function testTurnuswechselUeberspringtUeberlappendePeriodeDesNeuenRasters(): void {
		$assignment = $this->assignment(intervalMonths: 1, validFrom: '2026-01-01');
		$this->assignments->method('findActiveAsOf')->willReturn([$assignment]);
		$this->groups->method('find')->willReturn($this->group());
		$this->setMandateActive(true);

		$this->service('2025-12-14')->generateDue();
		$this->assertCount(1, $this->store);
		$this->assertSame('2026-01-01', $this->store[0]->getPeriodStart());
		$this->assertSame('2026-01-31', $this->store[0]->getPeriodEnd());

		// Turnuswechsel auf Jahresturnus (Kalenderjahr-Raster) zwischen den
		// beiden Läufen.
		$assignment->setIntervalMonths(12);

		$this->service('2026-12-11')->generateDue();
		$this->assertCount(2, $this->store);
		$secondClaim = $this->store[1];
		$this->assertSame('2027-01-01', $secondClaim->getPeriodStart());
		$this->assertSame('2027-12-31', $secondClaim->getPeriodEnd());
		$this->assertSame('2027-01-01', $secondClaim->getDueDate());
	}

	public function testGenerateDueIstIdempotent(): void {
		$this->assignments->method('findActiveAsOf')->willReturn([$this->assignment()]);
		$this->groups->method('find')->willReturn($this->group());
		$this->setMandateActive(true);

		$service = $this->service('2025-12-14');
		$first = $service->generateDue();
		$second = $service->generateDue();

		$this->assertSame(['created' => 1, 'blocked' => 0], $first);
		$this->assertSame(['created' => 0, 'blocked' => 0], $second);
		$this->assertCount(1, $this->store);
	}
}
