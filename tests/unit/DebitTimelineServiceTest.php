<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCA\Vereinsbuchhaltung\Db\DebitBatchMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItem;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleSettings;
use OCA\Vereinsbuchhaltung\Service\ContributionYearService;
use OCA\Vereinsbuchhaltung\Service\DebitRunQueryService;
use OCA\Vereinsbuchhaltung\Service\DebitTimelineService;
use OCA\Vereinsbuchhaltung\Service\DueDateScheduleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Zeitstrahl des Einzug-Unterreiters (Spec §3.5/§6, Issue #102): Termine
 * eines Beitragsjahres, Meilensteine, Vorschau-Zusammenfassung, Läufe und der
 * „nächste Termin" der Geisterkarte – siehe Klassendoc von
 * {@see DebitTimelineService}. Terminplan, Beitragsjahr und Abstände laufen
 * echt (gegen einen simulierten appconfig-Speicher), die Mapper sind gemockt.
 */
class DebitTimelineServiceTest extends TestCase {

	private AssignmentMapper&MockObject $assignments;
	private OpenItemMapper&MockObject $openItems;
	private DebitBatchMapper&MockObject $batches;
	private DebitItemMapper&MockObject $debitItems;
	private DebitRunQueryService&MockObject $runQuery;
	/** @var array<string,string> simulierter appconfig-Speicher */
	private array $store = [];

	protected function setUp(): void {
		$this->store = [];
		$this->assignments = $this->createMock(AssignmentMapper::class);
		$this->openItems = $this->createMock(OpenItemMapper::class);
		$this->batches = $this->createMock(DebitBatchMapper::class);
		$this->debitItems = $this->createMock(DebitItemMapper::class);
		$this->runQuery = $this->createMock(DebitRunQueryService::class);
		// Ohne Konfiguration liefern die Mocks fuer `array` automatisch [] - das
		// genuegt als "nichts vorhanden" (siehe ContributionCycleTaskServiceTest).
	}

	private function service(string $today): DebitTimelineService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => array_key_exists($key, $this->store) ? $this->store[$key] : $default,
		);
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->store[$key] = $value;
		});
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime($today));

		$year = new ContributionYearService($config);
		return new DebitTimelineService(
			$this->assignments,
			$this->openItems,
			$this->batches,
			$this->debitItems,
			new DueDateScheduleService($config, $year, $l10n),
			$year,
			new ContributionCycleSettings($config),
			$this->runQuery,
			$time,
		);
	}

	private function assignment(int $interval, string $validFrom = '2026-01-01', ?string $validTo = null): Assignment {
		$a = new Assignment();
		$a->setIntervalMonths($interval);
		$a->setValidFrom($validFrom);
		$a->setValidTo($validTo);
		return $a;
	}

	private function claim(int $id, string $dueDate): OpenItem {
		$c = new OpenItem();
		$c->setId($id);
		$c->setMemberId(1);
		$c->setType(OpenItem::TYPE_CONTRIBUTION);
		$c->setStatus('open');
		$c->setDueDate($dueDate);
		return $c;
	}

	private function batch(int $id, string $dueDate, string $status): DebitBatch {
		$b = new DebitBatch();
		$b->setId($id);
		$b->setDueDate($dueDate);
		$b->setStatus($status);
		return $b;
	}

	/** @return list<string> */
	private function dueDates(array $timeline): array {
		return array_column($timeline['dates'], 'dueDate');
	}

	public function testOhneZuweisungenForderungenUndLaeufeIstDerStrahlLeer(): void {
		$timeline = $this->service('2026-10-04')->build();

		$this->assertSame([], $timeline['dates']);
		$this->assertNull($timeline['next']);
		$this->assertSame('2026-10-04', $timeline['today']);
	}

	public function testBeitragsjahrUndAbstaendeKommenMit(): void {
		$timeline = $this->service('2026-10-04')->build();

		$this->assertSame(2026, $timeline['year']['anchorYear']);
		$this->assertSame('2026-01-01', $timeline['year']['start']);
		$this->assertSame('2026-12-31', $timeline['year']['end']);
		$this->assertTrue($timeline['year']['isCurrent']);
		$this->assertSame(['warning' => 21, 'prenotification' => 14, 'release' => 5], $timeline['leadDays']);
	}

	public function testMonatsTurnusErgibtZwoelfTermineImJahr(): void {
		$this->assignments->method('findAll')->willReturn([$this->assignment(1)]);

		$timeline = $this->service('2026-10-04')->build();

		$this->assertCount(12, $timeline['dates']);
		$this->assertSame('2026-01-01', $timeline['dates'][0]['dueDate']);
		$this->assertSame('2026-12-01', $timeline['dates'][11]['dueDate']);
		$this->assertSame([1], $timeline['dates'][0]['intervals']);
	}

	public function testTerminplanVersatzUndUeberschreibungVerschiebenDieTermine(): void {
		$this->assignments->method('findAll')->willReturn([$this->assignment(3)]);
		// Quartals-Turnus: Standard +10 Tage, Periodenindex 1 (zweites Quartal) +20 Tage.
		$this->store['due_date_schedule'] = json_encode(['3' => ['defaultOffsetDays' => 10, 'overrides' => [1 => 20]]]);

		$timeline = $this->service('2026-10-04')->build();

		$this->assertSame(['2026-01-11', '2026-04-21', '2026-07-11', '2026-10-11'], $this->dueDates($timeline));
	}

	public function testGemeinsamerTerminMehrererTurnusseWirdZusammengefasst(): void {
		$this->assignments->method('findAll')->willReturn([$this->assignment(1), $this->assignment(12)]);

		$timeline = $this->service('2026-10-04')->build();

		$this->assertCount(12, $timeline['dates']); // der Jahres-Termin faellt auf den ersten Monats-Termin
		$this->assertSame([1, 12], $timeline['dates'][0]['intervals']);
		$this->assertSame([1], $timeline['dates'][1]['intervals']);
	}

	public function testZuweisungDieVorDemJahrEndetErzeugtKeineTerminplanTermine(): void {
		$this->assignments->method('findAll')->willReturn([$this->assignment(1, '2024-01-01', '2025-12-31')]);

		$this->assertSame([], $this->service('2026-10-04')->build()['dates']);
	}

	public function testManuelleForderungUndVerschobenerLaufStehenAufIhremTermin(): void {
		$this->openItems->method('findClaims')->willReturn([
			$this->claim(1, '2026-10-17'),
			$this->claim(2, '2027-01-05'), // anderes Beitragsjahr - nicht dabei
		]);
		$this->batches->method('findAll')->willReturn([$this->batch(7, '2026-11-22', DebitBatch::STATUS_RELEASED)]);

		$timeline = $this->service('2026-10-04')->build();

		$this->assertSame(['2026-10-17', '2026-11-22'], $this->dueDates($timeline));
		$this->assertSame([], $timeline['dates'][0]['intervals']);
		$this->assertSame(7, $timeline['dates'][1]['batches'][0]['id']);
	}

	public function testStornierteForderungErzeugtKeinenTermin(): void {
		$cancelled = $this->claim(1, '2026-10-17');
		$cancelled->setCancelledAt('2026-10-01T00:00:00+00:00');
		$this->openItems->method('findClaims')->willReturn([$cancelled]);

		$this->assertSame([], $this->service('2026-10-04')->build()['dates']);
	}

	public function testMeilensteineRechnenVomTerminZurueckUndSindNachDatumSortiert(): void {
		$this->openItems->method('findClaims')->willReturn([$this->claim(1, '2026-11-01')]);

		$milestones = $this->service('2026-10-04')->build()['dates'][0]['milestones'];

		$this->assertSame([
			['key' => 'warning', 'date' => '2026-10-11'],
			['key' => 'prenotification', 'date' => '2026-10-18'],
			['key' => 'release', 'date' => '2026-10-27'],
			['key' => 'collection', 'date' => '2026-11-01'],
		], $milestones);
	}

	public function testMeilensteineFolgenGeaendertenAbstaenden(): void {
		$this->store['warning_lead_days'] = '10';
		$this->store['prenotification_lead_days'] = '30'; // groesser als das Vorwarnfenster
		$this->store['release_lead_days'] = '2';
		$this->openItems->method('findClaims')->willReturn([$this->claim(1, '2026-11-01')]);

		$milestones = $this->service('2026-10-04')->build()['dates'][0]['milestones'];

		// Nach Datum, nicht nach "ueblicher" Reihenfolge: die Vorabinfo kommt zuerst.
		$this->assertSame(['prenotification', 'warning', 'release', 'collection'], array_column($milestones, 'key'));
	}

	public function testVorschauSummeKommtAusDerLaufAbfrage(): void {
		$this->openItems->method('findClaims')->willReturn([$this->claim(1, '2026-11-01')]);
		$this->runQuery->method('summariesByDueDate')->willReturn(['2026-11-01' => ['count' => 3, 'sumCents' => 4500]]);

		$timeline = $this->service('2026-10-04')->build();

		$this->assertSame(['count' => 3, 'sumCents' => 4500], $timeline['dates'][0]['preview']);
	}

	public function testLaufSummeUndPostenzahlKommenAusDenEinzugsposten(): void {
		$batch = $this->batch(7, '2026-11-01', DebitBatch::STATUS_SUBMITTED);
		$this->batches->method('findAll')->willReturn([$batch]);
		$item1 = new DebitItem();
		$item1->setAmountCents(1500);
		$item2 = new DebitItem();
		$item2->setAmountCents(2500);
		$this->debitItems->method('findByBatch')->with(7)->willReturn([$item1, $item2]);

		$timeline = $this->service('2026-10-04')->build();

		$this->assertSame(
			[['id' => 7, 'status' => 'eingereicht', 'itemCount' => 2, 'sumCents' => 4000]],
			$timeline['dates'][0]['batches'],
		);
	}

	public function testNaechsterTerminIstDerFruehesteNichtVergangene(): void {
		$this->assignments->method('findAll')->willReturn([$this->assignment(1)]);

		$next = $this->service('2026-10-04')->build()['next'];

		$this->assertSame('2026-11-01', $next['dueDate']);
	}

	public function testNaechsterTerminSpringtUeberJahresgrenzen(): void {
		$this->assignments->method('findAll')->willReturn([$this->assignment(1)]);

		$timeline = $this->service('2026-12-15')->build();

		$this->assertSame('2027-01-01', $timeline['next']['dueDate']);
		// Der Strahl selbst zeigt weiter das laufende Jahr.
		$this->assertSame('2026-12-01', end($timeline['dates'])['dueDate']);
	}

	public function testNaechsterTerminUeberspringtTerminMitVollstaendigLebendemLauf(): void {
		$this->assignments->method('findAll')->willReturn([$this->assignment(1)]);
		$this->batches->method('findAll')->willReturn([$this->batch(7, '2026-11-01', DebitBatch::STATUS_RELEASED)]);

		$this->assertSame('2026-12-01', $this->service('2026-10-04')->build()['next']['dueDate']);
	}

	public function testVerworfenerLaufMachtDenTerminWiederZumNaechsten(): void {
		$this->assignments->method('findAll')->willReturn([$this->assignment(1)]);
		$this->batches->method('findAll')->willReturn([$this->batch(7, '2026-11-01', DebitBatch::STATUS_DISCARDED)]);

		$this->assertSame('2026-11-01', $this->service('2026-10-04')->build()['next']['dueDate']);
	}

	public function testVergangenerTerminMitFreizugebendenForderungenBleibtDerNaechste(): void {
		// Gerissene Vorlauffrist blockiert nichts (Spec §3.5): der verspaetete
		// Lauf bleibt freigebbar und wird zur Geisterkarte.
		$this->assignments->method('findAll')->willReturn([$this->assignment(1)]);
		$this->runQuery->method('summariesByDueDate')->willReturn(['2026-09-01' => ['count' => 2, 'sumCents' => 2000]]);

		$next = $this->service('2026-10-04')->build()['next'];

		$this->assertSame('2026-09-01', $next['dueDate']);
		$this->assertSame(2, $next['preview']['count']);
	}

	public function testUnplausiblesBeitragsjahrWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service('2026-10-04')->build(99999);
	}

	public function testAnderesBeitragsjahrUndVerschobenerStartmonat(): void {
		$this->store['fiscal_year_start_month'] = '10';
		$this->assignments->method('findAll')->willReturn([$this->assignment(12, '2025-01-01')]);

		$timeline = $this->service('2026-10-04')->build();

		// Beitragsjahr 2026/27 beginnt am 1.10.2026, der Jahres-Turnus hat einen Termin darin.
		$this->assertSame('2026-10-01', $timeline['year']['start']);
		$this->assertSame('2027-09-30', $timeline['year']['end']);
		$this->assertSame(['2026-10-01'], $this->dueDates($timeline));
	}
}
