<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetSnapshotMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Period;
use OCA\Vereinsbuchhaltung\Db\PeriodMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Exception\PeriodClosedException;
use OCA\Vereinsbuchhaltung\Exception\PeriodNotFoundException;
use OCP\IConfig;
use OCP\IL10N;

/**
 * Geschäftsjahre: anlegen, finden, festschreiben, umstellen.
 *
 * Die zentrale Stelle für die Frage „zu welchem Geschäftsjahr gehört dieses
 * Datum?". Bis 0.32.0 lautete die Antwort überall „zu dem Kalenderjahr, das
 * in den ersten vier Zeichen steht"; seit Issue #8 kann ein Geschäftsjahr
 * abweichen, kürzer als zwölf Monate sein (Semester) und eine eigene
 * Bezeichnung tragen. Diese Klasse löst {@see FiscalYear} und den
 * YearCloseService ab, die beide von der Gleichung Geschäftsjahr =
 * Kalenderjahr ausgingen.
 *
 * Perioden entstehen bei Bedarf, nie auf Vorrat: wer eine Buchung in einem
 * noch unbekannten Zeitraum anlegt, bekommt die passende Periode aus der
 * Regel ({@see PeriodRule}) angelegt. Angehängt wird dabei immer an die
 * vorhandene Kette – eine von Hand verschobene Grenze bleibt so wirksam, und
 * die nächste Periode findet über das Regelraster von selbst wieder in den
 * Takt zurück.
 *
 * Zwei Zusicherungen, auf die sich der Rest der App verlässt:
 *  - Die Perioden eines Buchs überlappen einander nicht.
 *  - Zwischen ihnen klafft keine Lücke; jedes Datum innerhalb der Kette
 *    gehört zu genau einer Periode.
 * Beide werden hier erzwungen, nicht von der Datenbank – ein
 * Bereichs-Constraint über zwei Spalten lässt sich nicht portabel ausdrücken.
 */
class PeriodService {

	/** Schlüssel der Geschäftsjahr-Regel in der App-Konfiguration. */
	public const SETTING_RULE = 'fiscal_period_rule';

	/**
	 * Obergrenze für automatisch erzeugte Perioden je Vorgang.
	 *
	 * Ein Zahlendreher im Buchungsdatum (1025 statt 2025) legte sonst
	 * tausend Perioden an, bevor jemand den Fehler bemerkt. Bei zwölf Monaten
	 * Länge sind 400 Perioden vier Jahrhunderte, bei Monatsperioden gut
	 * dreiunddreißig Jahre – für jede echte Vereinsbuchhaltung reichlich.
	 */
	private const MAX_MATERIALIZE = 400;

	/** @var array<string, Period[]> Request-Cache je Buch, absteigend nach Beginn */
	private array $cache = [];

	public function __construct(
		private PeriodMapper $mapper,
		private JournalMapper $journalMapper,
		private BudgetMapper $budgetMapper,
		private BudgetSnapshotMapper $snapshotMapper,
		private EntryNumberService $entryNumbers,
		private TransactionRunner $transaction,
		private AuditService $audit,
		private IConfig $config,
		private IL10N $l10n,
	) {
	}

	// -----------------------------------------------------------------
	// Regel
	// -----------------------------------------------------------------

	/**
	 * Die gespeicherte Geschäftsjahr-Regel.
	 *
	 * Ohne gespeicherte Regel gilt das Kalenderjahr. Bestehende
	 * Installationen verhalten sich nach dem Update deshalb unverändert,
	 * ohne dass jemand etwas einstellen müsste.
	 *
	 * @return array{preset:string, startDay:int, startMonth:int, lengthMonths:int}
	 */
	public function rule(): array {
		$raw = $this->config->getAppValue(Application::APP_ID, self::SETTING_RULE, '');
		if ($raw === '') {
			return PeriodRule::DEFAULT_RULE;
		}
		try {
			$decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
			return is_array($decoded) ? PeriodRule::validate($decoded) : PeriodRule::DEFAULT_RULE;
		} catch (\JsonException|\InvalidArgumentException) {
			// Eine unbrauchbar gewordene Regel darf die App nicht lahmlegen –
			// dann eben Kalenderjahr, das ist der Zustand vor Issue #8.
			return PeriodRule::DEFAULT_RULE;
		}
	}

	// -----------------------------------------------------------------
	// Lesen
	// -----------------------------------------------------------------

	/**
	 * Alle Perioden des Buchs, neueste zuerst.
	 *
	 * @return Period[]
	 */
	public function all(string $userId): array {
		if (!isset($this->cache[$userId])) {
			$this->cache[$userId] = $this->mapper->findAll($userId);
		}
		return $this->cache[$userId];
	}

	/** @throws PeriodNotFoundException */
	public function find(string $userId, int $id): Period {
		foreach ($this->all($userId) as $period) {
			if ((int)$period->getId() === $id) {
				return $period;
			}
		}
		throw new PeriodNotFoundException($this->l10n->t('Der gewählte Zeitraum existiert nicht (mehr). Bitte die Seite neu laden.'));
	}

	/**
	 * Die Periode, in die $date fällt.
	 *
	 * @param bool $materialize legt die Periode an, wenn sie noch fehlt. Für
	 *                          Schreibpfade true (die Buchung braucht eine Periode), für reine
	 *                          Prüfungen und Anzeigen false – dort soll ein Blick nicht die
	 *                          Datenlage verändern.
	 */
	public function forDate(string $userId, string $date, bool $materialize = true): ?Period {
		foreach ($this->all($userId) as $period) {
			if ($period->contains($date)) {
				return $period;
			}
		}
		return $materialize ? $this->materialize($userId, $date) : null;
	}

	/**
	 * Wie {@see forDate()} mit Materialisierung, nur ohne Null im Rückgabetyp.
	 *
	 * Für die Schreibpfade: eine Buchung braucht ein Geschäftsjahr, und wenn
	 * keines passt, entsteht es. Dass dabei nie null herauskommt, soll am Typ
	 * ablesbar sein und nicht an jeder Aufrufstelle geprüft werden müssen.
	 */
	public function forDateOrCreate(string $userId, string $date): Period {
		$period = $this->forDate($userId, $date, true);
		if ($period === null) {
			throw new \LogicException('Für ' . $date . ' ließ sich kein Geschäftsjahr anlegen.');
		}
		return $period;
	}

	/** Die Periode, die den heutigen Tag enthält; wird bei Bedarf angelegt. */
	public function current(string $userId): Period {
		return $this->forDateOrCreate($userId, date('Y-m-d'));
	}

	/** Die Periode davor, oder null wenn $period die erste ist. */
	public function previous(string $userId, Period $period): ?Period {
		$best = null;
		foreach ($this->all($userId) as $candidate) {
			if ($candidate->getStartDate() < $period->getStartDate()
				&& ($best === null || $candidate->getStartDate() > $best->getStartDate())) {
				$best = $candidate;
			}
		}
		return $best;
	}

	/** Die Periode danach; mit $materialize wird sie bei Bedarf angelegt. */
	public function next(string $userId, Period $period, bool $materialize = false): ?Period {
		$best = null;
		foreach ($this->all($userId) as $candidate) {
			if ($candidate->getStartDate() > $period->getStartDate()
				&& ($best === null || $candidate->getStartDate() < $best->getStartDate())) {
				$best = $candidate;
			}
		}
		if ($best !== null || !$materialize) {
			return $best;
		}
		return $this->forDate($userId, PeriodRule::nextDay($period->getEndDate()), true);
	}

	/**
	 * Datumsgrenzen des gewählten Geschäftsjahres – oder [null, null] für
	 * „alle Zeiträume".
	 *
	 * Wie beim früheren FiscalYear::range() bedeuten „nichts gewählt" und
	 * „0" dasselbe: keine Eingrenzung. Die Fallunterscheidung gehört hierher
	 * und nicht in jeden einzelnen Aufrufer.
	 *
	 * @return array{0: ?string, 1: ?string} [von, bis], beide inklusive
	 * @throws PeriodNotFoundException bei unbekannter ID
	 */
	public function range(string $userId, ?int $periodId): array {
		if (!self::isSelected($periodId)) {
			return [null, null];
		}
		$period = $this->find($userId, (int)$periodId);
		return [$period->getStartDate(), $period->getEndDate()];
	}

	/** Ist überhaupt ein Zeitraum gewählt (nicht „alle Zeiträume")? */
	public static function isSelected(?int $periodId): bool {
		return $periodId !== null && $periodId > 0;
	}

	/**
	 * Die gewählte Periode, oder die laufende, wenn keine gewählt ist.
	 * Der Nachfolger von FiscalYear::orCurrent().
	 */
	public function selectedOrCurrent(string $userId, ?int $periodId): Period {
		return self::isSelected($periodId) ? $this->find($userId, (int)$periodId) : $this->current($userId);
	}

	/**
	 * IDs aller festgeschriebenen Perioden.
	 *
	 * @return int[]
	 */
	public function closedIds(string $userId): array {
		$ids = [];
		foreach ($this->all($userId) as $period) {
			if ($period->isClosed()) {
				$ids[] = (int)$period->getId();
			}
		}
		return $ids;
	}

	// -----------------------------------------------------------------
	// Festschreibung
	// -----------------------------------------------------------------

	/**
	 * Wirft, wenn das Geschäftsjahr zu diesem Datum festgeschrieben ist.
	 *
	 * Wird bewusst ohne Materialisierung geprüft: liegt das Datum in keiner
	 * bekannten Periode, ist dort nichts abgeschlossen – und ein bloßer
	 * Schreibversuch, der ohnehin gleich scheitern kann, soll keine Periode
	 * anlegen.
	 *
	 * @throws PeriodClosedException
	 */
	public function assertOpen(string $userId, string $date): void {
		$period = $this->forDate($userId, $date, false);
		if ($period !== null && $period->isClosed()) {
			throw new PeriodClosedException($this->l10n->t(
				'Das Geschäftsjahr %s ist abgeschlossen. Buchungen, Belege und Zuordnungen dieses Zeitraums können nicht mehr geändert werden.',
				[$period->getLabel()],
			));
		}
	}

	/**
	 * Schreibt ein Geschäftsjahr fest.
	 *
	 * Unmittelbar davor wird die Buchungsnummerierung ein letztes Mal
	 * lückenlos durchnummeriert. Bis hierher sind die Nummern vorläufig (es
	 * darf ja noch gebucht und gelöscht werden); ab der Festschreibung sind
	 * sie unveränderlich. So ist garantiert, dass genau der Stand archiviert
	 * wird, den der Kassenbericht als „Buchungsnummern lückenlos" ausweist.
	 *
	 * Beides zusammen in einer Transaktion: entweder ist der Zeitraum
	 * nummeriert UND festgeschrieben, oder nichts von beidem.
	 */
	public function close(string $userId, int $id, string $uid): Period {
		return $this->transaction->run(function () use ($userId, $id, $uid): Period {
			$period = $this->find($userId, $id);
			if ($period->isClosed()) {
				return $period;
			}

			$renumbered = $this->entryNumbers->renumberPeriod($userId, $id);

			$period->setClosedAt((new \DateTime())->format('Y-m-d H:i:s'));
			$period->setClosedBy($uid);
			$period = $this->mapper->update($period);
			$this->touched($userId);

			$this->audit->log('Geschäftsjahr abgeschlossen', 'period', $id, $renumbered > 0
				? ['zeitraum' => $period->getLabel(), 'nachnummeriert' => $renumbered]
				: ['zeitraum' => $period->getLabel()]);
			return $period;
		});
	}

	public function reopen(string $userId, int $id): void {
		$period = $this->find($userId, $id);
		if (!$period->isClosed()) {
			return;
		}
		$period->setClosedAt(null);
		$period->setClosedBy(null);
		$this->mapper->update($period);
		$this->touched($userId);
		$this->audit->log('Geschäftsjahr wiedereröffnet', 'period', $id, ['zeitraum' => $period->getLabel()]);
	}

	// -----------------------------------------------------------------
	// Pflege einzelner Perioden
	// -----------------------------------------------------------------

	/**
	 * Benennt einen Zeitraum um. Auch bei festgeschriebenen Perioden erlaubt:
	 * die Bezeichnung geht in keine Zahl ein, sie beschriftet nur.
	 */
	public function updateLabel(string $userId, int $id, string $label): Period {
		$period = $this->find($userId, $id);
		$label = mb_substr(trim($label), 0, 64);
		if ($label === '') {
			throw new \InvalidArgumentException($this->l10n->t('Die Bezeichnung darf nicht leer sein.'));
		}
		if ($this->mapper->labelExists($userId, $label, $id)) {
			throw new \InvalidArgumentException($this->l10n->t('Die Bezeichnung "%s" ist bereits vergeben.', [$label]));
		}
		$old = $period->getLabel();
		$period->setLabel($label);
		$period = $this->mapper->update($period);
		$this->touched($userId);
		$this->audit->log('Geschäftsjahr umbenannt', 'period', $id, ['vorher' => $old, 'nachher' => $label]);
		return $period;
	}

	/**
	 * Verschiebt das Ende eines Zeitraums – und damit den Beginn des
	 * folgenden. Das ist der Weg zu einem Rumpfgeschäftsjahr beim Umstieg.
	 *
	 * Beide betroffenen Zeiträume müssen offen sein: sonst verschöbe sich
	 * nachträglich, was ein archivierter Kassenbericht ausweist.
	 *
	 * @return Period[] die geänderten Zeiträume
	 */
	public function moveEnd(string $userId, int $id, string $newEnd): array {
		return $this->transaction->run(function () use ($userId, $id, $newEnd): array {
			$period = $this->find($userId, $id);
			$following = $this->next($userId, $period);

			if ($period->isClosed()) {
				throw new PeriodClosedException($this->l10n->t(
					'Das Geschäftsjahr %s ist abgeschlossen; seine Grenzen lassen sich nicht mehr verschieben.',
					[$period->getLabel()],
				));
			}
			if ($following !== null && $following->isClosed()) {
				throw new PeriodClosedException($this->l10n->t(
					'Der folgende Zeitraum %s ist abgeschlossen; die gemeinsame Grenze lässt sich nicht mehr verschieben.',
					[$following->getLabel()],
				));
			}
			if ($newEnd < $period->getStartDate()) {
				throw new \InvalidArgumentException($this->l10n->t('Das Ende darf nicht vor dem Beginn des Zeitraums liegen.'));
			}
			if ($following !== null && $newEnd >= $following->getEndDate()) {
				throw new \InvalidArgumentException($this->l10n->t(
					'Das Ende muss vor dem Ende des folgenden Zeitraums (%s) liegen.',
					[$following->getEndDate()],
				));
			}

			$oldEnd = $period->getEndDate();
			$period->setEndDate($newEnd);
			$this->mapper->update($period);
			$changed = [$period];

			if ($following !== null) {
				$following->setStartDate(PeriodRule::nextDay($newEnd));
				$this->mapper->update($following);
				$changed[] = $following;
			}
			$this->touched($userId);

			$this->reassignAll($userId);
			$this->audit->log('Zeitraumgrenze verschoben', 'period', $id, [
				'zeitraum' => $period->getLabel(),
				'vorher' => $oldEnd,
				'nachher' => $newEnd,
			]);
			return $changed;
		});
	}

	/** Legt den Zeitraum nach dem bisher letzten an (Knopf „Nächsten Zeitraum anlegen"). */
	public function appendNext(string $userId): Period {
		$last = $this->mapper->findLast($userId);
		if ($last === null) {
			return $this->current($userId);
		}
		return $this->forDateOrCreate($userId, PeriodRule::nextDay($last->getEndDate()));
	}

	/**
	 * Entfernt einen leeren Zeitraum am Rand der Kette.
	 *
	 * Nur am Rand, weil eine Lücke in der Mitte die Zusicherung bräche, dass
	 * jedes Datum innerhalb der Kette zu genau einer Periode gehört.
	 */
	public function delete(string $userId, int $id): void {
		$period = $this->find($userId, $id);
		if ($period->isClosed()) {
			throw new PeriodClosedException($this->l10n->t('Ein abgeschlossener Zeitraum lässt sich nicht entfernen.'));
		}

		$isEdge = $this->previous($userId, $period) === null || $this->next($userId, $period) === null;
		if (!$isEdge) {
			throw new \InvalidArgumentException($this->l10n->t(
				'Nur der erste oder der letzte Zeitraum lässt sich entfernen – sonst entstünde eine Lücke.',
			));
		}
		if ($this->journalMapper->countByPeriod($userId, $id) > 0) {
			throw new \InvalidArgumentException($this->l10n->t('Der Zeitraum %s enthält Buchungen.', [$period->getLabel()]));
		}
		if ($this->budgetMapper->countByPeriod($userId, $id) > 0 || $this->snapshotMapper->countByPeriod($userId, $id) > 0) {
			throw new \InvalidArgumentException($this->l10n->t('Der Zeitraum %s enthält Planwerte.', [$period->getLabel()]));
		}

		$label = $period->getLabel();
		$this->mapper->delete($period);
		$this->touched($userId);
		$this->audit->log('Geschäftsjahr entfernt', 'period', $id, ['zeitraum' => $label]);
	}

	// -----------------------------------------------------------------
	// Regel umstellen
	// -----------------------------------------------------------------

	/**
	 * Was eine Umstellung bewirken würde – ohne etwas zu schreiben.
	 *
	 * Die Umstellung ordnet jede Buchung neu zu und kann Planwerte
	 * zusammenführen. Das ist nichts, was man jemandem ungefragt zumutet;
	 * deshalb gibt es diese Vorschau, und erst danach den Knopf.
	 *
	 * @param array<string,mixed> $rule
	 * @return array{rule:array{preset:string,startDay:int,startMonth:int,lengthMonths:int}, periods:list<array{label:string,startDate:string,endDate:string}>, bookingsMoved:int, budgetsDropped:int, closed:list<string>}
	 */
	public function previewRule(string $userId, array $rule): array {
		$rule = PeriodRule::validate($rule);
		$plan = $this->planRuleChange($userId, $rule);

		$closed = [];
		foreach ($this->all($userId) as $period) {
			if ($period->isClosed()) {
				$closed[] = $period->getLabel();
			}
		}

		return [
			'rule' => $rule,
			'periods' => $plan['periods'],
			'bookingsMoved' => $plan['bookingsMoved'],
			'budgetsDropped' => $plan['budgetsDropped'],
			'closed' => $closed,
		];
	}

	/**
	 * Stellt die Regel um und baut die Zeiträume neu auf.
	 *
	 * @param array<string,mixed> $rule
	 * @return array{periods:int, bookingsMoved:int, budgetsDropped:int}
	 * @throws PeriodClosedException wenn ein Zeitraum festgeschrieben ist
	 */
	public function applyRule(string $userId, array $rule, string $uid): array {
		$rule = PeriodRule::validate($rule);

		return $this->transaction->run(function () use ($userId, $rule, $uid): array {
			$closed = [];
			foreach ($this->all($userId) as $period) {
				if ($period->isClosed()) {
					$closed[] = $period->getLabel();
				}
			}
			if ($closed !== []) {
				throw new PeriodClosedException($this->l10n->t(
					'Die Umstellung ist nicht möglich: %s ist bzw. sind festgeschrieben. Wer das Geschäftsjahr ändern will, eröffnet diese Zeiträume vorher wieder.',
					[implode(', ', $closed)],
				));
			}

			$before = $this->rule();
			$plan = $this->planRuleChange($userId, $rule);
			$old = $this->all($userId);

			$this->config->setAppValue(Application::APP_ID, self::SETTING_RULE, json_encode($rule, JSON_THROW_ON_ERROR));

			// Erst die alte Kette entfernen, dann die neue anlegen, dann
			// umhängen.
			//
			// Die Reihenfolge ist nicht beliebig: Beginn und Bezeichnung sind
			// je Buch eindeutig, und beim Umstellen bleiben regelmäßig Grenzen
			// stehen, wo alte und neue Regel zusammenfallen – bei einer
			// unveränderten Regel sogar alle. Würde zuerst eingefügt, liefe das
			// in den Unique-Index.
			//
			// Dazwischen zeigen Planwerte kurzzeitig auf Zeiträume, die es
			// nicht mehr gibt. Das ist unbedenklich, weil alles in einer
			// Transaktion läuft: nach außen sichtbar wird erst der Zustand nach
			// dem Umhängen.
			foreach ($old as $period) {
				$this->mapper->delete($period);
			}

			$new = [];
			foreach ($plan['periods'] as $row) {
				$period = new Period();
				$period->setUserId($userId);
				$period->setStartDate($row['startDate']);
				$period->setEndDate($row['endDate']);
				$period->setLabel($row['label']);
				$new[] = $this->mapper->insert($period);
			}
			$this->touched($userId);

			$dropped = $this->remapPlanValues($userId, $old, $new);

			$moved = $this->reassignAll($userId);

			$this->audit->log('Geschäftsjahr-Regel geändert', 'period', null, [
				'vorher' => $this->ruleText($before),
				'nachher' => $this->ruleText($rule),
				'zeitraeume' => count($new),
				'umgehaengte_buchungen' => $moved,
				'verworfene_planwerte' => $dropped,
			], $uid);

			return ['periods' => count($new), 'bookingsMoved' => $moved, 'budgetsDropped' => $dropped];
		});
	}

	/** Beim vollständigen Zurücksetzen aller Daten: Zeiträume mit entfernen. */
	public function deleteAll(string $userId): void {
		$this->mapper->deleteAllForUser($userId);
		$this->touched($userId);
	}

	// -----------------------------------------------------------------
	// Innenleben
	// -----------------------------------------------------------------

	/**
	 * Legt die Perioden an, die nötig sind, damit $date abgedeckt ist.
	 *
	 * Angehängt wird an die vorhandene Kette (letztes Ende + 1 Tag bzw.
	 * erster Beginn − 1 Tag), das Ende bzw. der Beginn kommt aus dem
	 * Regelraster. Wurde eine Grenze von Hand verschoben, entsteht dadurch
	 * einmalig eine kürzere Periode, und danach läuft die Kette wieder im
	 * Takt der Regel.
	 */
	private function materialize(string $userId, string $date): ?Period {
		$rule = $this->rule();
		$created = 0;

		$last = $this->mapper->findLast($userId);
		if ($last === null) {
			[$from, $to] = PeriodRule::containing($rule, $date);
			$this->insertPeriod($userId, $rule, $from, $to);
			$this->touched($userId);
			return $this->forDate($userId, $date, false);
		}

		while ($date > $last->getEndDate()) {
			$this->guardMaterialize(++$created);
			$start = PeriodRule::nextDay($last->getEndDate());
			$end = max($start, PeriodRule::containing($rule, $start)[1]);
			$last = $this->insertPeriod($userId, $rule, $start, $end);
		}

		$first = $this->mapper->findFirst($userId);
		while ($first !== null && $date < $first->getStartDate()) {
			$this->guardMaterialize(++$created);
			$end = PeriodRule::previousDay($first->getStartDate());
			$start = min($end, PeriodRule::containing($rule, $end)[0]);
			$first = $this->insertPeriod($userId, $rule, $start, $end);
		}

		$this->touched($userId);
		return $this->forDate($userId, $date, false);
	}

	private function guardMaterialize(int $created): void {
		if ($created > self::MAX_MATERIALIZE) {
			throw new \InvalidArgumentException($this->l10n->t(
				'Das Datum liegt zu weit außerhalb der bestehenden Geschäftsjahre. Bitte das Datum prüfen.',
			));
		}
	}

	/**
	 * @param array{preset:string, startDay:int, startMonth:int, lengthMonths:int} $rule
	 */
	private function insertPeriod(string $userId, array $rule, string $start, string $end): Period {
		$period = new Period();
		$period->setUserId($userId);
		$period->setStartDate($start);
		$period->setEndDate($end);
		$period->setLabel($this->uniqueLabel($userId, PeriodRule::proposeLabel($rule, $start), $start));
		$inserted = $this->mapper->insert($period);
		$this->touched($userId);
		return $inserted;
	}

	/**
	 * Macht einen Bezeichnungsvorschlag eindeutig. Die Bezeichnung ist je
	 * Buch eindeutig, damit sie in Exporten und Berichten für sich steht.
	 */
	private function uniqueLabel(string $userId, string $base, string $fallback, ?int $exceptId = null): string {
		$label = mb_substr($base, 0, 64);
		if (!$this->mapper->labelExists($userId, $label, $exceptId)) {
			return $label;
		}
		for ($i = 2; $i <= 99; $i++) {
			$candidate = mb_substr($base, 0, 58) . ' (' . $i . ')';
			if (!$this->mapper->labelExists($userId, $candidate, $exceptId)) {
				return $candidate;
			}
		}
		// Das Startdatum ist je Buch eindeutig (Unique-Index), also ist es auch
		// diese Bezeichnung. Ein Notnagel, den in der Praxis niemand sieht.
		return mb_substr($base, 0, 52) . ' ' . $fallback;
	}

	/**
	 * Stellt die Zusicherung wieder her, dass jede Buchung in der Periode
	 * steht, die ihr Datum enthält – und nummeriert die betroffenen
	 * Zeiträume neu.
	 *
	 * Ein UPDATE je Periode über deren Datumsbereich statt eines UPDATE je
	 * Buchung; das ist dasselbe Vorgehen wie beim Befüllen der alten
	 * Jahresspalte in Version000119.
	 *
	 * @return int Anzahl umgehängter Buchungen
	 */
	private function reassignAll(string $userId): int {
		$bounds = $this->journalMapper->dateBounds($userId);
		if ($bounds !== null) {
			// Buchungen außerhalb der Kette (nach dem Verkürzen des letzten
			// Zeitraums) bekommen zuerst wieder eine Periode.
			$this->forDateOrCreate($userId, $bounds[0]);
			$this->forDateOrCreate($userId, $bounds[1]);
		}

		$moved = 0;
		foreach ($this->all($userId) as $period) {
			$id = (int)$period->getId();
			$mismatched = $this->journalMapper->countMismatchedInRange(
				$userId, $period->getStartDate(), $period->getEndDate(), $id,
			);
			if ($mismatched > 0) {
				// Erst die Nummern der wechselnden Buchungen aus dem Weg
				// räumen, dann umhängen: sonst stoßen im Zielzeitraum zwei
				// Buchungen mit derselben Nummer aufeinander, und der
				// Unique-Index lässt das UPDATE gar nicht erst zu.
				$this->journalMapper->parkEntryNosForMove($userId, $period->getStartDate(), $period->getEndDate(), $id);
				$this->journalMapper->setPeriodForRange($userId, $period->getStartDate(), $period->getEndDate(), $id);
				$moved += $mismatched;
			}
			// Nach dem Zusammenführen zweier Zeiträume ist die bisherige
			// Nummer keine sinnvolle Ordnung mehr – dann zählt das Datum.
			// Sonst bleibt es bei der bisherigen Reihenfolge, und das
			// Nachnummerieren schreibt im Normalfall gar nichts.
			if ($mismatched > 0) {
				$this->entryNumbers->renumberPeriodByDate($userId, $id);
			} else {
				$this->entryNumbers->renumberPeriod($userId, $id);
			}
		}
		return $moved;
	}

	/**
	 * Die neue Periodenkette zu einer Regel, samt Auswirkungen.
	 *
	 * @param array{preset:string, startDay:int, startMonth:int, lengthMonths:int} $rule
	 * @return array{periods:list<array{label:string,startDate:string,endDate:string}>, bookingsMoved:int, budgetsDropped:int}
	 */
	private function planRuleChange(string $userId, array $rule): array {
		$old = $this->all($userId);
		$today = date('Y-m-d');

		// Die neue Kette muss alles abdecken, was Daten trägt: den Zeitraum
		// der Buchungen, die Zeiträume mit Planwerten oder Plan-Ständen und
		// den heutigen Tag.
		//
		// Bewusst NICHT alle bestehenden Zeiträume: leere Perioden am Rand der
		// Kette entstehen ganz von selbst (das Raster einer neuen Regel deckt
		// die alten Grenzen selten genau ab). Würden sie mitgeschleppt, wüchse
		// die Kette bei jeder Umstellung um je einen leeren Zeitraum vorn und
		// hinten – nach ein paar Versuchen stünden im Auswahlfeld der
		// Kopfzeile mehr leere als benutzte Geschäftsjahre.
		$bounds = $this->journalMapper->dateBounds($userId);
		$from = $bounds[0] ?? $today;
		$to = $bounds[1] ?? $today;

		$withPlan = $this->budgetMapper->countsByPeriod($userId) + $this->snapshotMapper->countsByPeriod($userId);
		foreach ($old as $period) {
			if (($withPlan[(int)$period->getId()] ?? 0) === 0) {
				continue;
			}
			$from = min($from, $period->getStartDate());
			$to = max($to, $period->getEndDate());
		}

		$byRange = [];
		foreach ($old as $period) {
			$byRange[$period->getStartDate() . '|' . $period->getEndDate()] = $period->getLabel();
		}

		$periods = [];
		$used = [];
		$cursor = PeriodRule::containing($rule, $from)[0];
		while (true) {
			[$start, $end] = PeriodRule::containing($rule, $cursor);

			// Eine Periode mit unveränderten Grenzen behält ihre Bezeichnung –
			// wer sie umbenannt hat, soll das nicht durch eine Umstellung
			// verlieren, die diesen Zeitraum gar nicht berührt.
			$label = $byRange[$start . '|' . $end] ?? PeriodRule::proposeLabel($rule, $start);
			$base = $label;
			for ($i = 2; isset($used[$label]); $i++) {
				$label = mb_substr($base, 0, 58) . ' (' . $i . ')';
			}
			$used[$label] = true;

			$periods[] = ['label' => mb_substr($label, 0, 64), 'startDate' => $start, 'endDate' => $end];
			if ($end >= $to) {
				break;
			}
			if (count($periods) > self::MAX_MATERIALIZE) {
				throw new \InvalidArgumentException($this->l10n->t(
					'Diese Regel ergäbe zu viele Zeiträume. Bitte eine längere Periodendauer wählen.',
				));
			}
			$cursor = PeriodRule::nextDay($end);
		}

		return [
			'periods' => $periods,
			'bookingsMoved' => $this->countMoves($userId, $old, $periods),
			'budgetsDropped' => $this->countBudgetDrops($userId, $old, $periods),
		];
	}

	/**
	 * Wie viele Buchungen wechselten den Zeitraum? Gezählt wird je Paar aus
	 * altem und neuem Zeitraum, das sich überschneidet und nicht deckungsgleich ist.
	 *
	 * @param Period[] $old
	 * @param list<array{label:string,startDate:string,endDate:string}> $new
	 */
	private function countMoves(string $userId, array $old, array $new): int {
		$moved = 0;
		foreach ($old as $period) {
			foreach ($new as $row) {
				if ($period->getStartDate() === $row['startDate'] && $period->getEndDate() === $row['endDate']) {
					continue;
				}
				if (self::overlapDays($period->getStartDate(), $period->getEndDate(), $row['startDate'], $row['endDate']) === 0) {
					continue;
				}
				$moved += $this->journalMapper->countByPeriodInRange(
					$userId, (int)$period->getId(), $row['startDate'], $row['endDate'],
				);
			}
		}
		return $moved;
	}

	/**
	 * Wie viele Planwerte gingen verloren? Das passiert, wenn zwei bisherige
	 * Zeiträume zu einem verschmelzen und beide für dasselbe Konto einen
	 * Planwert tragen – ein Konto kann je Zeitraum nur einen haben.
	 *
	 * @param Period[] $old
	 * @param list<array{label:string,startDate:string,endDate:string}> $new
	 */
	private function countBudgetDrops(string $userId, array $old, array $new): int {
		$dropped = 0;
		foreach ($this->groupByTarget($old, $new) as $group) {
			$taken = [];
			foreach ($group as $periodId) {
				foreach ($this->budgetMapper->findAccountIdsForPeriod($userId, $periodId) as $accountId) {
					if (isset($taken[$accountId])) {
						$dropped++;
						continue;
					}
					$taken[$accountId] = true;
				}
			}
		}
		return $dropped;
	}

	/**
	 * Hängt Planwerte und Plan-Stände von den alten auf die neuen Zeiträume um.
	 *
	 * Maßstab ist die größte Überschneidung: ein Planwert landet dort, wo der
	 * größte Teil seines bisherigen Zeitraums hingehört. Kollidieren zwei
	 * Planwerte desselben Kontos, gewinnt der mit der größeren Überschneidung.
	 *
	 * @param Period[] $old
	 * @param Period[] $new
	 * @return int Anzahl verworfener Planwerte
	 */
	private function remapPlanValues(string $userId, array $old, array $new): int {
		$rows = array_map(
			static fn (Period $p): array => [
				'label' => $p->getLabel(), 'startDate' => $p->getStartDate(), 'endDate' => $p->getEndDate(),
			],
			$new,
		);
		$targets = $this->groupByTarget($old, $rows);

		$dropped = 0;
		foreach ($targets as $index => $group) {
			$targetId = (int)$new[$index]->getId();
			$taken = [];
			foreach ($group as $periodId) {
				$collisions = [];
				foreach ($this->budgetMapper->findAccountIdsForPeriod($userId, $periodId) as $accountId) {
					if (isset($taken[$accountId])) {
						$collisions[] = $accountId;
						continue;
					}
					$taken[$accountId] = true;
				}
				if ($collisions !== []) {
					$this->budgetMapper->deleteByPeriodAndAccounts($userId, $periodId, $collisions);
					$dropped += count($collisions);
				}
				$this->budgetMapper->movePeriod($userId, $periodId, $targetId);
				// Plan-Stände tragen keinen Unique-Index je Konto; sie können
				// unverändert zusammen in den neuen Zeitraum wandern.
				$this->snapshotMapper->movePeriod($userId, $periodId, $targetId);
			}
		}
		return $dropped;
	}

	/**
	 * Ordnet jeden alten Zeitraum dem neuen mit der größten Überschneidung zu.
	 *
	 * @param Period[] $old
	 * @param list<array{label:string,startDate:string,endDate:string}> $new
	 * @return array<int, list<int>> Index im neuen Array => alte Perioden-IDs,
	 *                               absteigend nach Überschneidung
	 */
	private function groupByTarget(array $old, array $new): array {
		$groups = [];
		foreach ($old as $period) {
			$bestIndex = null;
			$bestOverlap = 0;
			foreach ($new as $index => $row) {
				$overlap = self::overlapDays(
					$period->getStartDate(), $period->getEndDate(), $row['startDate'], $row['endDate'],
				);
				if ($overlap > $bestOverlap) {
					$bestOverlap = $overlap;
					$bestIndex = $index;
				}
			}
			if ($bestIndex !== null) {
				$groups[$bestIndex][] = ['id' => (int)$period->getId(), 'overlap' => $bestOverlap];
			}
		}

		$result = [];
		foreach ($groups as $index => $entries) {
			usort($entries, static fn (array $a, array $b): int => $b['overlap'] <=> $a['overlap']);
			$result[$index] = array_map(static fn (array $e): int => $e['id'], $entries);
		}
		return $result;
	}

	/** Gemeinsame Tage zweier Zeiträume, beide Grenzen inklusive. */
	private static function overlapDays(string $aStart, string $aEnd, string $bStart, string $bEnd): int {
		$start = max($aStart, $bStart);
		$end = min($aEnd, $bEnd);
		if ($start > $end) {
			return 0;
		}
		return (int)(new \DateTimeImmutable($start))->diff(new \DateTimeImmutable($end))->days + 1;
	}

	/**
	 * Die Regel in einem Satz – für das Änderungsprotokoll, das ohne
	 * JSON-Brocken lesbar bleiben soll.
	 *
	 * @param array{preset:string, startDay:int, startMonth:int, lengthMonths:int} $rule
	 */
	private function ruleText(array $rule): string {
		return sprintf('%s (ab %02d.%02d., %d Monate)', $rule['preset'], $rule['startDay'], $rule['startMonth'], $rule['lengthMonths']);
	}

	/**
	 * Der Zwischenspeicher ist überholt – jetzt und auch dann, wenn die
	 * laufende Transaktion noch zurückgerollt wird.
	 *
	 * Ohne den zweiten Teil hielte der Cache nach einem Rollback Perioden, die
	 * es nicht gibt. Sichtbar würde das beim Wiederholungsversuch der
	 * Buchungsnummer ({@see TransactionRunner::runWithRetry()}), der nach einem
	 * Rollback weiterarbeitet.
	 */
	private function touched(string $userId): void {
		unset($this->cache[$userId]);
		$this->transaction->afterRollback(function () use ($userId): void {
			unset($this->cache[$userId]);
		});
	}
}
