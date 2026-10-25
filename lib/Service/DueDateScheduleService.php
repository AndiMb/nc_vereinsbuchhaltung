<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Der Terminplan (Spec §2.2 „Terminplan (Due Date Schedule)"/§3.5, Issue #70):
 * „Einstellung, keine Entity, kein Generator-Cron. Je Turnus ein
 * Standard-Einzugstag + eine überschreibbare Zeile je Periode (Zeilenidentität
 * = Periodenindex, nicht Datum — gilt jahresunabhängig)."
 *
 * Gespeichert als ein JSON-Blob in {@see IConfig} (dasselbe Muster wie
 * {@see PeriodService} für die Geschäftsjahr-Regel) statt einer eigenen
 * Tabelle: der Terminplan ist Konfiguration, keine Fachdatenhistorie.
 *
 * **Modellentscheidung „Einzugstag" = Tage-Versatz zum Periodenbeginn statt
 * Tag-im-Monat** (dokumentierte Auslegung, Issue #70 nennt nur „Standard-
 * Einzugstag" ohne Formel): ein reiner Tag-im-Monat, angewandt auf den
 * Startmonat der jeweiligen Periode, bliebe *immer* innerhalb der Periode –
 * und weil Perioden desselben Turnus lückenlos und überlappungsfrei
 * aufeinanderfolgen, kann ein Termin, der innerhalb seiner eigenen Periode
 * bleibt, den Termin der nächsten Periode gar nicht erst einholen. Die
 * Guard „kein Überholen" (Spec §3.5) wäre mit diesem Modell unerreichbar
 * (immer wahr) und damit weder sinnvoll prüfbar noch testbar. Ein
 * Tage-Versatz – 0 = Periodenbeginn, positiv = später, negativ =
 * Vorzieh-Einzug vor Periodenbeginn – bleibt dagegen ein „Tag" im Sinne der
 * Spec (ein einzelner, admin-verständlicher Zahlenwert je Turnus/Periode),
 * kann aber weit genug von der eigenen Periode wegzeigen, dass beide Guards
 * echte, ablehnbare Fälle haben: „kein Überholen" (Termin holt den der
 * nächsten Periode ein) und „Termin im Beitragsjahr" (Termin verlässt das
 * Beitragsjahr der Periode).
 */
class DueDateScheduleService {

	private const SETTING_KEY = 'due_date_schedule';
	public const DEFAULT_OFFSET_DAYS = 0;

	/** Plausibilitätsgrenzen gegen Tippfehler – weit genug für „Vorzieh-Einzug" und „Einzug erst Monate später". */
	public const MIN_OFFSET_DAYS = -90;
	public const MAX_OFFSET_DAYS = 270;

	/**
	 * Die Beitragsjahre, an denen die Guards den Terminplan durchspielen. Ein
	 * Versatz in Tagen trifft je nach Februar einen anderen Kalendertag, und ein
	 * simulierter Zyklus berührt bis zu drei Februare (der Wraparound reicht ins
	 * übernächste Jahr). 2000–2003 decken jede Lage der Schaltjahre dazu ab
	 * (Schaltjahr am Anfang, in der Mitte, am Ende, keins), damit kein reales
	 * Jahr einen Termin liefert, den die Prüfung nie gesehen hat.
	 */
	private const GUARD_ANCHOR_YEARS = [2000, 2001, 2002, 2003];

	public function __construct(
		private IConfig $config,
		private ContributionYearService $contributionYear,
		private IL10N $l10n,
	) {
	}

	/**
	 * Der komplette Terminplan über alle Turnuswerte (Spec-Feldkatalog), für
	 * die Einstellungs-UI.
	 *
	 * @return array<int, array{defaultOffsetDays:int, overrides: array<int,int>}>
	 */
	public function getFullSchedule(): array {
		$schedule = [];
		foreach (ContributionGroup::VALID_INTERVALS as $intervalMonths) {
			$schedule[$intervalMonths] = [
				'defaultOffsetDays' => $this->getDefaultOffsetDays($intervalMonths),
				'overrides' => $this->getOverrides($intervalMonths),
			];
		}
		return $schedule;
	}

	public function getDefaultOffsetDays(int $intervalMonths): int {
		$this->assertValidInterval($intervalMonths);
		return $this->readEntry($intervalMonths)['defaultOffsetDays'] ?? self::DEFAULT_OFFSET_DAYS;
	}

	/** @return array<int,int> periodIndex => Tage-Versatz */
	public function getOverrides(int $intervalMonths): array {
		$this->assertValidInterval($intervalMonths);
		return $this->readEntry($intervalMonths)['overrides'] ?? [];
	}

	/**
	 * @throws \InvalidArgumentException bei ungültigem Turnus/Versatz oder wenn
	 *                                   die Änderung Perioden desselben Turnus überholen ließe oder aus
	 *                                   deren Beitragsjahr herausführte
	 */
	public function setDefaultOffsetDays(int $intervalMonths, int $offsetDays): void {
		$this->assertValidInterval($intervalMonths);
		$this->setSchedule($intervalMonths, $offsetDays, $this->getOverrides($intervalMonths));
	}

	/**
	 * @param int|null $offsetDays null entfernt die Überschreibung (zurück zum Standard-Einzugstag)
	 * @throws \InvalidArgumentException bei ungültigem Turnus/Periodenindex/Versatz
	 *                                   oder wenn die Änderung Perioden desselben Turnus überholen ließe
	 *                                   oder aus deren Beitragsjahr herausführte
	 */
	public function setOverride(int $intervalMonths, int $periodIndex, ?int $offsetDays): void {
		$this->assertValidInterval($intervalMonths);
		$this->assertValidPeriodIndex($intervalMonths, $periodIndex);

		$overrides = $this->getOverrides($intervalMonths);
		if ($offsetDays === null) {
			unset($overrides[$periodIndex]);
		} else {
			$overrides[$periodIndex] = $offsetDays;
		}
		$this->setSchedule($intervalMonths, $this->getDefaultOffsetDays($intervalMonths), $overrides);
	}

	/**
	 * Standard-Einzugstag und alle Überschreibungen eines Turnus in einem
	 * Schritt – so speichert die Terminplan-Ansicht ihren Entwurf. Einzeln
	 * nacheinander gespeichert könnte ein Zwischenstand an den Guards
	 * scheitern, obwohl das Ergebnis gültig ist (etwa Standard und eine
	 * Überschreibung gemeinsam verschieben).
	 *
	 * @param array<int|string,int> $overrides Periodenindex => Tage-Versatz; fehlende Perioden folgen dem Standard
	 * @throws \InvalidArgumentException bei ungültigem Turnus/Periodenindex/Versatz
	 *                                   oder wenn der Terminplan eine der Guards verletzte
	 */
	public function setSchedule(int $intervalMonths, int $defaultOffsetDays, array $overrides): void {
		$this->assertValidInterval($intervalMonths);
		$this->assertValidOffset($defaultOffsetDays);
		$overrides = $this->normalizeOverrides($intervalMonths, $overrides);
		$this->assertGuards($intervalMonths, $defaultOffsetDays, $overrides);

		$schedule = $this->readRaw();
		$schedule[(string)$intervalMonths] = ['defaultOffsetDays' => $defaultOffsetDays, 'overrides' => $overrides];
		$this->writeRaw($schedule);
	}

	/**
	 * Was ein (noch nicht gespeicherter) Terminplan eines Turnus ergäbe: die
	 * Perioden des Beitragsjahrs, das in `$year` beginnt, mit ihrem
	 * Einzugstermin, und alle Verstöße gegen die Guards – dieselbe Prüfung wie
	 * beim Speichern, damit die Ansicht schon vorher sagen kann, woran es
	 * scheitert. Verstöße aus dem gezeigten Jahr stehen vorn, mit dessen Daten;
	 * einer, der nur in einer anderen Schaltjahr-Lage auftritt, folgt mit den
	 * Daten des Jahres, in dem er auftritt.
	 *
	 * @param array<int|string,int> $overrides Periodenindex => Tage-Versatz
	 * @param int $year Anker-Kalenderjahr des gezeigten Beitragsjahrs, siehe {@see ContributionYearService::anchorYearFor()}
	 * @return array{
	 *     periods:list<array{periodIndex:int, start:string, end:string, offsetDays:int, overridden:bool, dueDate:string}>,
	 *     violations:list<array<string,int|string>>
	 * }
	 * @throws \InvalidArgumentException bei ungültigem Turnus/Periodenindex/Versatz/Beitragsjahr
	 */
	public function preview(int $intervalMonths, int $defaultOffsetDays, array $overrides, int $year): array {
		$this->assertValidInterval($intervalMonths);
		$this->assertValidOffset($defaultOffsetDays);
		$this->contributionYear->assertPlausibleAnchorYear($year);
		$overrides = $this->normalizeOverrides($intervalMonths, $overrides);

		return [
			// Ohne den Wraparound-Eintrag: der gehört schon zum nächsten Beitragsjahr.
			'periods' => array_slice($this->simulateCycle($intervalMonths, $defaultOffsetDays, $overrides, $year)['periods'], 0, intdiv(12, $intervalMonths)),
			'violations' => $this->guardViolations($intervalMonths, $defaultOffsetDays, $overrides, $year),
		];
	}

	/**
	 * Der tatsächliche Einzugstermin für eine Periode: Überschreibung für
	 * ihren Periodenindex, sonst der Standard-Einzugstag des Turnus, ausgehend
	 * von `$anchorDate`.
	 *
	 * `$anchorDate` ist im Regelfall der Periodenbeginn selbst – bei der
	 * Prorata-Erstforderung einer neuen Zuweisung (Spec §3.5: „trägt einen
	 * eigenen, vorgeschlagenen Einzugstermin") reicht
	 * {@see ClaimGenerationService} hier stattdessen `validFrom` durch: die
	 * erste (oft mehrmonatige) Periode eines Turnus beginnt am 1. Januar
	 * (bzw. dem konfigurierten Beitragsjahr-Start), auch wenn ein Mitglied
	 * erst im März beitritt – ein Standard-Einzugstag „+3 Tage" soll dann drei
	 * Tage nach dem Beitritt greifen, nicht drei Tage nach einem Jahresanfang,
	 * den das Mitglied nie erlebt hat. Der Periodenindex wird trotzdem korrekt
	 * ermittelt: {@see PeriodRule::gridIndex()} funktioniert für jedes Datum
	 * innerhalb der Periode, nicht nur für ihren exakten Anfang.
	 *
	 * @throws \InvalidArgumentException bei unmöglichem Datum
	 */
	public function dueDateForPeriod(int $intervalMonths, string $anchorDate): string {
		$rule = $this->contributionYear->periodRuleFor($intervalMonths);
		[, $periodIndex] = PeriodRule::gridIndex($rule, $anchorDate);
		$offsetDays = $this->getOverrides($intervalMonths)[$periodIndex] ?? $this->getDefaultOffsetDays($intervalMonths);
		return $this->computeDueDate($anchorDate, $offsetDays);
	}

	/**
	 * Die auf `$periodEnd` folgende Periode desselben Turnus – Grundlage für
	 * die Nachzügler-Suche in {@see ClaimGenerationService}.
	 *
	 * @return array{0:string,1:string} [periodStart, periodEnd]
	 */
	public function nextPeriod(int $intervalMonths, string $periodEnd): array {
		return $this->contributionYear->periodContaining($intervalMonths, PeriodRule::nextDay($periodEnd));
	}

	// --- Guards --------------------------------------------------------------

	/** @throws \InvalidArgumentException */
	private function assertValidInterval(int $intervalMonths): void {
		if (!in_array($intervalMonths, ContributionGroup::VALID_INTERVALS, true)) {
			throw new \InvalidArgumentException($this->l10n->t('Turnus muss ein Teiler von 12 sein (1, 2, 3, 4, 6 oder 12).'));
		}
	}

	private function assertValidOffset(int $offsetDays): void {
		if ($offsetDays < self::MIN_OFFSET_DAYS || $offsetDays > self::MAX_OFFSET_DAYS) {
			throw new \InvalidArgumentException($this->l10n->t('Der Einzugstag muss zwischen %1$d und %2$d Tagen Versatz liegen.', [self::MIN_OFFSET_DAYS, self::MAX_OFFSET_DAYS]));
		}
	}

	/** @throws \InvalidArgumentException */
	private function assertValidPeriodIndex(int $intervalMonths, int $periodIndex): void {
		$perCycle = intdiv(12, $intervalMonths);
		if ($periodIndex < 0 || $periodIndex >= $perCycle) {
			throw new \InvalidArgumentException($this->l10n->t('Der Periodenindex muss zwischen 0 und %d liegen.', [$perCycle - 1]));
		}
	}

	/**
	 * @param array<int|string,int> $overrides
	 * @return array<int,int>
	 * @throws \InvalidArgumentException
	 */
	private function normalizeOverrides(int $intervalMonths, array $overrides): array {
		$normalized = [];
		foreach ($overrides as $periodIndex => $offsetDays) {
			$this->assertValidPeriodIndex($intervalMonths, (int)$periodIndex);
			$this->assertValidOffset((int)$offsetDays);
			$normalized[(int)$periodIndex] = (int)$offsetDays;
		}
		ksort($normalized);
		return $normalized;
	}

	/**
	 * Lehnt beim ersten Verstoß aus {@see guardViolations()} ab:
	 *
	 * - **Termin im Beitragsjahr**: der berechnete Termin muss im selben
	 *   Beitragsjahres-Zyklus liegen wie die Periode selbst (Turnus-unabhängig,
	 *   deshalb immer gegen den 12-Monats-Jahresraster geprüft).
	 * - **Kein Überholen**: echt aufsteigende Termine über den ganzen Zyklus.
	 *
	 * @param array<int,int> $overrides
	 * @throws \InvalidArgumentException bei Verletzung einer der beiden Guards
	 */
	private function assertGuards(int $intervalMonths, int $defaultOffsetDays, array $overrides): void {
		$violations = $this->guardViolations($intervalMonths, $defaultOffsetDays, $overrides);
		if ($violations !== []) {
			throw new \InvalidArgumentException($violations[0]['kind'] === 'outsideYear'
				? $this->l10n->t('Dieser Einzugstermin läge außerhalb des Beitragsjahres der Periode.')
				: $this->l10n->t('Dieser Einzugstermin würde den Termin der vorherigen Periode überholen.'));
		}
	}

	/**
	 * Die Verstöße gegen die Guards in jeder Schaltjahr-Lage
	 * ({@see GUARD_ANCHOR_YEARS}), je Art und Periode nur der erste –
	 * `$firstYears` werden davor durchgespielt, damit ihre Daten gewinnen.
	 *
	 * @param array<int,int> $overrides
	 * @return list<array<string,int|string>>
	 */
	private function guardViolations(int $intervalMonths, int $defaultOffsetDays, array $overrides, int ...$firstYears): array {
		$violations = [];
		foreach ([...$firstYears, ...self::GUARD_ANCHOR_YEARS] as $anchorYear) {
			foreach ($this->simulateCycle($intervalMonths, $defaultOffsetDays, $overrides, $anchorYear)['violations'] as $violation) {
				$violations[$violation['kind'] . ':' . $violation['periodIndex']] ??= $violation;
			}
		}
		return array_values($violations);
	}

	/**
	 * Ein voller Zyklus ab dem Beitragsjahr, das in `$anchorYear` beginnt,
	 * plus ein Wraparound-Eintrag (letzte Periode eines Jahres → erste des
	 * nächsten), mit dem Termin jeder Periode und den Verstößen gegen die
	 * Guards (siehe {@see assertGuards()}). Ein Verstoß nennt die Periode, an
	 * der er auffällt, und die Daten, die ihn zeigen.
	 *
	 * @param array<int,int> $overrides
	 * @return array{
	 *     periods:list<array{periodIndex:int, start:string, end:string, offsetDays:int, overridden:bool, dueDate:string}>,
	 *     violations:list<array<string,int|string>>
	 * }
	 */
	private function simulateCycle(int $intervalMonths, int $defaultOffsetDays, array $overrides, int $anchorYear): array {
		$rule = $this->contributionYear->periodRuleFor($intervalMonths);
		$yearRule = $this->contributionYear->periodRuleFor(12);
		$perCycle = intdiv(12, $intervalMonths);

		$periods = [];
		$violations = [];
		[$date] = $this->contributionYear->yearBounds($anchorYear);
		$previous = null;
		for ($i = 0; $i <= $perCycle; $i++) {
			[$periodStart, $periodEnd] = PeriodRule::containing($rule, $date);
			[, $periodIndex] = PeriodRule::gridIndex($rule, $periodStart);
			$overridden = array_key_exists($periodIndex, $overrides);
			$offsetDays = $overridden ? $overrides[$periodIndex] : $defaultOffsetDays;
			$due = $this->computeDueDate($periodStart, $offsetDays);

			[$yearStart, $yearEnd] = PeriodRule::containing($yearRule, $periodStart);
			if ($due < $yearStart || $due > $yearEnd) {
				$violations[] = ['kind' => 'outsideYear', 'periodIndex' => $periodIndex, 'dueDate' => $due, 'yearStart' => $yearStart, 'yearEnd' => $yearEnd];
			}
			if ($previous !== null && $due <= $previous['dueDate']) {
				$violations[] = ['kind' => 'overtakes', 'periodIndex' => $periodIndex, 'dueDate' => $due, 'previousPeriodIndex' => $previous['periodIndex'], 'previousDueDate' => $previous['dueDate']];
			}

			$previous = [
				'periodIndex' => $periodIndex,
				'start' => $periodStart,
				'end' => $periodEnd,
				'offsetDays' => $offsetDays,
				'overridden' => $overridden,
				'dueDate' => $due,
			];
			$periods[] = $previous;
			$date = PeriodRule::nextDay($periodEnd);
		}
		return ['periods' => $periods, 'violations' => $violations];
	}

	private function computeDueDate(string $periodStart, int $offsetDays): string {
		$sign = $offsetDays >= 0 ? '+' : '-';
		return (new \DateTimeImmutable($periodStart))->modify($sign . abs($offsetDays) . ' days')->format('Y-m-d');
	}

	/** @return array{defaultOffsetDays?:int, overrides?: array<int,int>} */
	private function readEntry(int $intervalMonths): array {
		$raw = $this->readRaw();
		$entry = $raw[(string)$intervalMonths] ?? [];
		return [
			'defaultOffsetDays' => isset($entry['defaultOffsetDays']) ? (int)$entry['defaultOffsetDays'] : self::DEFAULT_OFFSET_DAYS,
			'overrides' => is_array($entry['overrides'] ?? null)
				? array_map('intval', array_combine(array_map('intval', array_keys($entry['overrides'])), array_values($entry['overrides'])))
				: [],
		];
	}

	/** @return array<string, array{defaultOffsetDays?:int, overrides?:array<int|string,int>}> */
	private function readRaw(): array {
		$raw = $this->config->getAppValue(Application::APP_ID, self::SETTING_KEY, '');
		if ($raw === '') {
			return [];
		}
		try {
			$decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
			return is_array($decoded) ? $decoded : [];
		} catch (\JsonException) {
			// Ein unbrauchbar gewordener Terminplan darf die App nicht
			// lahmlegen - dann eben ueberall der Standard-Einzugstag 0 (genau
			// der Periodenbeginn), das entspricht dem Zustand ohne jede
			// Einstellung.
			return [];
		}
	}

	/** @param array<string, array{defaultOffsetDays?:int, overrides?:array<int|string,int>}> $schedule */
	private function writeRaw(array $schedule): void {
		$this->config->setAppValue(Application::APP_ID, self::SETTING_KEY, json_encode($schedule, JSON_THROW_ON_ERROR));
	}
}
