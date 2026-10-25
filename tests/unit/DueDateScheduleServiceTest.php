<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\ContributionYearService;
use OCA\Vereinsbuchhaltung\Service\DueDateScheduleService;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Der Terminplan (Spec §2.2/§3.5, Issue #70) – siehe Klassendoc von
 * {@see DueDateScheduleService} für die Modellentscheidung „Einzugstag" =
 * Tage-Versatz zum Periodenbeginn statt Tag-im-Monat, und warum das nötig
 * ist, damit die beiden Guards ("kein Überholen", "Termin im Beitragsjahr")
 * überhaupt einmal etwas ablehnen können.
 */
class DueDateScheduleServiceTest extends TestCase {

	private IConfig&MockObject $config;
	/** @var array<string,string> simulierter appconfig-Speicher */
	private array $store = [];

	protected function setUp(): void {
		$this->store = [];
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => array_key_exists($key, $this->store) ? $this->store[$key] : $default,
		);
		$this->config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->store[$key] = $value;
		});
	}

	private function service(int $fiscalYearStartMonth = 1): DueDateScheduleService {
		$this->store['fiscal_year_start_month'] = (string)$fiscalYearStartMonth;
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return new DueDateScheduleService($this->config, new ContributionYearService($this->config), $l10n);
	}

	public function testDefaultOffsetOhneEinstellungIstNull(): void {
		$this->assertSame(0, $this->service()->getDefaultOffsetDays(1));
	}

	public function testSetDefaultOffsetDaysRoundtrip(): void {
		$service = $this->service();
		$service->setDefaultOffsetDays(1, 3);
		$this->assertSame(3, $service->getDefaultOffsetDays(1));
	}

	public function testSetOverrideRoundtripUndEntfernen(): void {
		$service = $this->service();
		$service->setOverride(4, 1, 10);
		$this->assertSame([1 => 10], $service->getOverrides(4));

		$service->setOverride(4, 1, null);
		$this->assertSame([], $service->getOverrides(4));
	}

	public function testSetDefaultOffsetDaysLehntUngueltigenTurnusAb(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->setDefaultOffsetDays(5, 0);
	}

	public function testSetOverrideLehntPeriodenindexAusserhalbDesZyklusAb(): void {
		// Turnus 6 -> 2 Perioden im Jahr (Index 0 und 1), Index 2 gibt es nicht.
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->setOverride(6, 2, 0);
	}

	public function testSetDefaultOffsetDaysLehntVersatzAusserhalbDerPlausibilitaetsgrenzeAb(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->setDefaultOffsetDays(1, 1000);
	}

	public function testDueDateForPeriodWendetVersatzAufPeriodenbeginnAn(): void {
		$service = $this->service();
		$service->setDefaultOffsetDays(12, 5);
		// Beitragsjahr = Kalenderjahr, Turnus 12 -> genau eine Periode.
		$this->assertSame('2026-01-06', $service->dueDateForPeriod(12, '2026-01-01'));
	}

	public function testDueDateForPeriodMitNegativemVersatzZiehtDenEinzugVor(): void {
		// Ein negativer STANDARD-Versatz wuerde bereits die erste Periode des
		// Turnus (die immer am Jahresanfang beginnt) vor den Jahresbeginn
		// ziehen und am Beitragsjahr-Guard scheitern (siehe eigener Test) -
		// deshalb hier eine Ueberschreibung auf eine spaetere Periode, die
		// genug Puffer zum Jahresanfang hat.
		$service = $this->service();
		$service->setOverride(3, 1, -5);
		// Turnus 3 (vierteljaehrlich), zweite Periode (Index 1) beginnt am 1. April.
		$this->assertSame('2026-03-27', $service->dueDateForPeriod(3, '2026-04-01'));
	}

	public function testOverrideGiltNurFuerDenEigenenPeriodenindexJahresunabhaengig(): void {
		$service = $this->service();
		// Turnus 4 (drei Perioden im Jahr, Index 0/1/2): Override nur auf Index 1.
		$service->setOverride(4, 1, 20);

		// 2026: Index 0 = Jan-Apr, Index 1 = Mai-Aug, Index 2 = Sep-Dez.
		$this->assertSame('2026-01-01', $service->dueDateForPeriod(4, '2026-01-01'));
		$this->assertSame('2026-05-21', $service->dueDateForPeriod(4, '2026-05-01'));
		// Naechstes Jahr, derselbe Periodenindex (1) -> derselbe Versatz, "gilt jahresunabhaengig".
		$this->assertSame('2027-05-21', $service->dueDateForPeriod(4, '2027-05-01'));
	}

	public function testSetDefaultOffsetDaysLehntUeberholenVorhandenerUeberschreibungAb(): void {
		// Turnus 2 (zweimonatlich): Periodenindex 0 (Jan-Feb) faehrt dank
		// Ueberschreibung erst am 20. Februar. Ein neuer Standardversatz von
		// -15 Tagen liesse die naechste Periode (Maerz-April, kein eigener
		// Override) schon am 14. Februar faellig werden - vor der vorherigen.
		// Ein gleichfoermiger Standardversatz allein kann nie ueberholen (er
		// verschiebt alle Perioden parallel) - das braucht immer eine
		// abweichende Ueberschreibung als Gegenpart, wie hier.
		$service = $this->service();
		$service->setOverride(2, 0, 50);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('überholen');
		$service->setDefaultOffsetDays(2, -15);
	}

	public function testUeberholenWirdAuchUeberOverrideVerhindert(): void {
		// Turnus 2 (zweimonatlich, 6 Perioden/Jahr): Standard 0, Override auf
		// Periodenindex 2 (Mai-Jun) schiebt weit in Periodenindex 3 (Jul-Aug) hinein.
		$service = $this->service();
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('überholen');
		$service->setOverride(2, 2, 100);
	}

	public function testSetDefaultOffsetDaysLehntTerminAusserhalbDesBeitragsjahresAb(): void {
		// Turnus 12 (ein Jahrestermin): die einzige Periode beginnt immer am
		// 1. Januar - jeder negative Versatz zieht den Termin damit
		// zwangslaeufig ins VORJAHR, ausserhalb des Beitragsjahres der Periode.
		$service = $this->service();
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Beitragsjahres');
		$service->setDefaultOffsetDays(12, -10);
	}

	public function testGueltigerVersatzInnerhalbDesBeitragsjahresWirdAkzeptiert(): void {
		$service = $this->service();
		$service->setDefaultOffsetDays(12, 200);
		$this->assertSame(200, $service->getDefaultOffsetDays(12));
	}

	public function testNextPeriodLiefertDieFolgeperiodeDesselbenTurnus(): void {
		$service = $this->service();
		[$start, $end] = $service->nextPeriod(3, '2026-03-31');
		$this->assertSame('2026-04-01', $start);
		$this->assertSame('2026-06-30', $end);
	}

	public function testUeberholenNurImNichtSchaltjahrWirdAuchAbgelehnt(): void {
		// Monatlich, Februar (Index 1) 28 Tage nach Periodenbeginn: im Schaltjahr der
		// 29. Februar, sonst der 1. März – derselbe Tag wie der Einzug für März. Die
		// Prüfung spielte früher nur das Jahr 2000 (Schaltjahr) durch und ließ das zu.
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('überholen');
		$this->service()->setOverride(1, 1, 28);
	}

	public function testSetScheduleSpeichertStandardUndUeberschreibungenZusammen(): void {
		// Vierteljährlich: das 1. Quartal fährt dank Überschreibung erst am 22. März.
		// Ziel: Standard -30 Tage (2. Quartal also am 2. März), 1. Quartal zurück auf
		// Periodenbeginn. Einzeln, Standard zuerst, überholte das 2. Quartal das
		// 1. – zusammen ist der Terminplan gültig.
		$service = $this->service();
		$service->setOverride(3, 0, 80);
		try {
			$service->setDefaultOffsetDays(3, -30);
			$this->fail('Der Zwischenstand hätte überholen müssen.');
		} catch (\InvalidArgumentException) {
		}

		$service->setSchedule(3, -30, ['0' => 0]);
		$this->assertSame(-30, $service->getDefaultOffsetDays(3));
		$this->assertSame([0 => 0], $service->getOverrides(3));
		$this->assertSame('2026-03-02', $service->dueDateForPeriod(3, '2026-04-01'));
	}

	public function testSetScheduleLehntUngueltigenPeriodenindexAbUndSpeichertNichts(): void {
		$service = $this->service();
		try {
			$service->setSchedule(6, 5, [2 => 0]);
			$this->fail('Turnus 6 hat nur die Periodenindizes 0 und 1.');
		} catch (\InvalidArgumentException) {
		}
		$this->assertSame(0, $service->getDefaultOffsetDays(6));
	}

	public function testPreviewLiefertDiePeriodenDesBeitragsjahrsMitTermin(): void {
		$service = $this->service();
		$preview = $service->preview(3, 14, [1 => 31], 2027);

		$this->assertSame('2027', $preview['yearLabel']);
		$this->assertSame(['2027-01-01', '2027-12-31'], [$preview['yearStart'], $preview['yearEnd']]);
		$this->assertSame([
			['periodIndex' => 0, 'start' => '2027-01-01', 'end' => '2027-03-31', 'offsetDays' => 14, 'overridden' => false, 'dueDate' => '2027-01-15'],
			['periodIndex' => 1, 'start' => '2027-04-01', 'end' => '2027-06-30', 'offsetDays' => 31, 'overridden' => true, 'dueDate' => '2027-05-02'],
			['periodIndex' => 2, 'start' => '2027-07-01', 'end' => '2027-09-30', 'offsetDays' => 14, 'overridden' => false, 'dueDate' => '2027-07-15'],
			['periodIndex' => 3, 'start' => '2027-10-01', 'end' => '2027-12-31', 'offsetDays' => 14, 'overridden' => false, 'dueDate' => '2027-10-15'],
		], $preview['periods']);
		$this->assertSame([], $preview['violations']);
		// Eine Vorschau speichert nichts.
		$this->assertSame(0, $service->getDefaultOffsetDays(3));
		$this->assertSame([], $service->getOverrides(3));
	}

	public function testPreviewFolgtDemBeitragsjahrBeginn(): void {
		$preview = $this->service(10)->preview(6, 0, [], 2026);
		$this->assertSame('2026/27', $preview['yearLabel']);
		$this->assertSame(['2026-10-01', '2027-04-01'], array_column($preview['periods'], 'start'));
	}

	public function testPreviewNenntVerstoesseMitDenDatenDesGezeigtenJahres(): void {
		$preview = $this->service()->preview(3, 0, [1 => 100], 2027);
		// 2. Quartal am 10. Juli, 3. Quartal am 1. Juli – das 3. liegt nicht danach.
		$this->assertContains(
			['kind' => 'overtakes', 'periodIndex' => 2, 'dueDate' => '2027-07-01', 'previousPeriodIndex' => 1, 'previousDueDate' => '2027-07-10'],
			$preview['violations'],
		);
	}

	public function testPreviewNenntAuchVerstoesseAusAnderenSchaltjahrLagen(): void {
		// Im gezeigten Schaltjahr 2028 passt der 29. Februar; im Jahr danach wäre es
		// der 1. März, derselbe Tag wie der März-Einzug. Die Vorschau sagt das mit den
		// Daten des Jahres, in dem es passiert.
		$preview = $this->service()->preview(1, 0, [1 => 28], 2028);
		$this->assertSame('2028-02-29', $preview['periods'][1]['dueDate']);
		$this->assertCount(1, $preview['violations']);
		$this->assertSame('overtakes', $preview['violations'][0]['kind']);
		$this->assertSame(2, $preview['violations'][0]['periodIndex']);
		$this->assertSame('-03-01', substr((string)$preview['violations'][0]['dueDate'], 4));
	}

	public function testPreviewMeldetTerminAusserhalbDesBeitragsjahrs(): void {
		$preview = $this->service()->preview(12, -10, [], 2027);
		$this->assertSame(
			[['kind' => 'outsideYear', 'periodIndex' => 0, 'dueDate' => '2026-12-22', 'yearStart' => '2027-01-01', 'yearEnd' => '2027-12-31']],
			$preview['violations'],
		);
	}

	public function testPreviewLehntVersatzAusserhalbDerGrenzenAb(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->preview(3, 0, [0 => 400], 2027);
	}

	public function testFullScheduleEnthaeltAlleErlaubtenTurnusse(): void {
		$service = $this->service();
		$schedule = $service->getFullSchedule();
		$this->assertSame([1, 2, 3, 4, 6, 12], array_keys($schedule));
		$this->assertSame(0, $schedule[1]['defaultOffsetDays']);
		$this->assertSame([], $schedule[1]['overrides']);
	}
}
