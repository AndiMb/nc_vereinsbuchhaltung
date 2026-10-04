<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCA\Vereinsbuchhaltung\Db\DebitBatchMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Datengrundlage des Zeitstrahls im Einzug-Unterreiter (Spec §3.5/§6 Variante
 * A „Zeitstrahl mit HEUTE-Marker, Geisterkarte für die Vorschau", Issue #102):
 * die Einzugstermine eines Beitragsjahres mit ihren Meilensteinen, der
 * Vorschau-Zusammenfassung (Anzahl, Summe) und den Läufen, die es zu einem
 * Termin schon gibt – alles abgeleitet, keine eigene Persistenz („der
 * Terminplan ist eine Einstellung, kein Generator-Cron", Spec §3.5).
 *
 * **Welche Termine auf den Zeitstrahl kommen** – die Vereinigung dreier
 * Quellen, weil die Spec den Termin an keiner einzelnen Stelle „besitzt":
 * - **Terminplan** ({@see DueDateScheduleService}): je Turnus, den eine im
 *   Beitragsjahr gültige Zuweisung tatsächlich nutzt, der Einzugstermin jeder
 *   Periode. Ein Turnus, den niemand nutzt, würde den Strahl nur mit Terminen
 *   füllen, an denen nie etwas eingezogen wird.
 * - **Forderungen**: manuelle Einzelforderungen und Prorata-Erstforderungen
 *   tragen einen eigenen Termin, der nicht im Terminplan steht.
 * - **Läufe**: ein bis zur Einreichung nach hinten verschobener Lauf steht auf
 *   seinem neuen Datum, auch wenn der Terminplan dort nichts vorsieht.
 *
 * **Meilensteine** je Termin D, aus den drei konfigurierbaren Abständen
 * ({@see ContributionCycleSettings}) – bewusst hier und nicht im Frontend
 * gerechnet, damit Zeitstrahl, Aufgabenliste ({@see ContributionCycleTaskService},
 * {@see DebitBatchTaskService}) und Cron dieselben Zahlen lesen: Vorwarnung
 * (D − Vorwarnfenster, Default 21), Vorabinfo (D − Vorabinfo-Vorlauf, Default
 * 14), Freigabe-Vorlauf (D − Vorlauf-Puffer, Default 5) und Einzug (D).
 *
 * **Nächster Termin** (die Geisterkarte): der früheste Termin, an dem noch
 * etwas freizugeben ist – also einer, der heute oder später liegt, oder ein
 * vergangener, an dem noch einzugsfähige, nicht gebündelte Forderungen stehen
 * (Spec §3.5 „gerissene Vorlauffrist blockiert nichts … nur eine eskalierende
 * Aufgabe": ein verspäteter Lauf bleibt freigebbar), und der nicht schon
 * vollständig in einem lebenden Lauf steckt. Gesucht wird über das Vorjahr,
 * das laufende und das Folgejahr, damit der Strahl am Jahreswechsel nicht
 * leer wirkt.
 */
class DebitTimelineService {

	public const MILESTONE_WARNING = 'warning';
	public const MILESTONE_PRENOTIFICATION = 'prenotification';
	public const MILESTONE_RELEASE = 'release';
	public const MILESTONE_COLLECTION = 'collection';

	public function __construct(
		private AssignmentMapper $assignments,
		private OpenItemMapper $openItems,
		private DebitBatchMapper $batches,
		private DebitItemMapper $debitItems,
		private DueDateScheduleService $schedule,
		private ContributionYearService $contributionYear,
		private ContributionCycleSettings $settings,
		private DebitRunQueryService $runQuery,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param int|null $anchorYear Anker-Kalenderjahr des Beitragsjahres ({@see ContributionYearService::anchorYearFor()}), sonst das laufende
	 * @param string|null $today Stichtag, sonst heute (für Tests)
	 * @throws \InvalidArgumentException bei einem unplausiblen Beitragsjahr
	 * @return array{
	 *     today:string,
	 *     year:array{anchorYear:int, label:string, start:string, end:string, isCurrent:bool},
	 *     leadDays:array{warning:int, prenotification:int, release:int},
	 *     dates:list<array<string,mixed>>,
	 *     next:?array<string,mixed>
	 * }
	 */
	public function build(?int $anchorYear = null, ?string $today = null): array {
		$today ??= $this->today();
		$currentAnchor = $this->contributionYear->anchorYearFor($today);
		$anchorYear ??= $currentAnchor;
		// Plausibilitätsgrenze gegen Tippfehler in der Adresse - und gegen
		// Jahreszahlen, mit denen die Datumsarithmetik nichts mehr anfangen kann.
		if ($anchorYear < 2000 || $anchorYear > 2200) {
			throw new \InvalidArgumentException('Das Beitragsjahr muss zwischen 2000 und 2200 liegen.');
		}

		$context = $this->loadContext();
		[$start, $end] = $this->contributionYear->yearBounds($anchorYear);

		return [
			'today' => $today,
			'year' => [
				'anchorYear' => $anchorYear,
				'label' => $this->contributionYear->yearLabel($anchorYear),
				'start' => $start,
				'end' => $end,
				'isCurrent' => $anchorYear === $currentAnchor,
			],
			'leadDays' => [
				'warning' => $this->settings->warningLeadDays(),
				'prenotification' => $this->settings->prenotificationLeadDays(),
				'release' => $this->settings->releaseLeadDays(),
			],
			'dates' => $this->datesBetween($context, $start, $end),
			'next' => $this->findNext($context, $today, $currentAnchor),
		];
	}

	/**
	 * @return array{
	 *     claims:OpenItem[],
	 *     batches:array<string, list<array{id:int, status:string, itemCount:int, sumCents:int}>>,
	 *     summaries:array<string, array{count:int, sumCents:int}>,
	 *     assignments:list<array{interval:int, from:string, to:?string}>
	 * }
	 */
	private function loadContext(): array {
		$batchesByDate = [];
		foreach ($this->batches->findAll() as $batch) {
			$items = $this->debitItems->findByBatch((int)$batch->getId());
			$batchesByDate[$batch->getDueDate()][] = [
				'id' => (int)$batch->getId(),
				'status' => $batch->getStatus(),
				'itemCount' => count($items),
				'sumCents' => array_sum(array_map(static fn ($i): int => $i->getAmountCents(), $items)),
			];
		}

		$assignments = [];
		foreach ($this->assignments->findAll() as $assignment) {
			$assignments[] = [
				'interval' => $assignment->getIntervalMonths(),
				'from' => $assignment->getValidFrom(),
				'to' => $assignment->getValidTo(),
			];
		}

		return [
			'claims' => $this->openItems->findClaims(),
			'batches' => $batchesByDate,
			'summaries' => $this->runQuery->summariesByDueDate(),
			'assignments' => $assignments,
		];
	}

	/**
	 * Die Turnusmonate, die eine zwischen `$start` und `$end` gültige
	 * Zuweisung nutzt – nur deren Terminplan-Termine kommen auf den Strahl.
	 *
	 * @param array<string,mixed> $context
	 * @return list<int>
	 */
	private function usedIntervals(array $context, string $start, string $end): array {
		$intervals = [];
		foreach ($context['assignments'] as $assignment) {
			if ($assignment['from'] <= $end && ($assignment['to'] === null || $assignment['to'] >= $start)) {
				$intervals[$assignment['interval']] = true;
			}
		}
		return array_keys($intervals);
	}

	/**
	 * Alle Einzugstermine zwischen `$start` und `$end` (beide inklusive),
	 * aufsteigend.
	 *
	 * @param array<string,mixed> $context siehe {@see loadContext()}
	 * @return list<array<string,mixed>>
	 */
	private function datesBetween(array $context, string $start, string $end): array {
		/** @var array<string, list<int>> $intervalsByDate */
		$intervalsByDate = [];

		foreach ($this->usedIntervals($context, $start, $end) as $interval) {
			$date = $start;
			// Die Obergrenze schützt nur vor einer Endlosschleife bei kaputten
			// Daten: drei Beitragsjahre à zwölf Monatsperioden sind 36 Schritte.
			for ($i = 0; $i < 400 && $date <= $end; $i++) {
				[$periodStart, $periodEnd] = $this->contributionYear->periodContaining($interval, $date);
				$due = $this->schedule->dueDateForPeriod($interval, $periodStart);
				if ($due >= $start && $due <= $end) {
					$intervalsByDate[$due][] = $interval;
				}
				$date = PeriodRule::nextDay($periodEnd);
			}
		}

		/** @var OpenItem $claim */
		foreach ($context['claims'] as $claim) {
			$due = $claim->getDueDate();
			if ($due === null || $due < $start || $due > $end || $claim->getCancelledAt() !== null) {
				continue;
			}
			$intervalsByDate[$due] ??= [];
		}
		foreach (array_keys($context['batches']) as $due) {
			if ($due >= $start && $due <= $end) {
				$intervalsByDate[$due] ??= [];
			}
		}

		ksort($intervalsByDate);
		$dates = [];
		foreach ($intervalsByDate as $due => $intervals) {
			$intervals = array_values(array_unique($intervals));
			sort($intervals);
			$dates[] = $this->entry((string)$due, $intervals, $context);
		}
		return $dates;
	}

	/**
	 * @param list<int> $intervals Turnusmonate, deren Terminplan auf diesen Tag zeigt (leer bei manuellem/verschobenem Termin)
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private function entry(string $dueDate, array $intervals, array $context): array {
		return [
			'dueDate' => $dueDate,
			'intervals' => $intervals,
			'milestones' => $this->milestones($dueDate),
			'preview' => $context['summaries'][$dueDate] ?? ['count' => 0, 'sumCents' => 0],
			'batches' => $context['batches'][$dueDate] ?? [],
		];
	}

	/**
	 * Die vier Meilensteine eines Termins in zeitlicher Reihenfolge. Bei einer
	 * ungewöhnlichen Einstellung (z. B. Vorabinfo-Vorlauf größer als das
	 * Vorwarnfenster) ordnet die Sortierung sie nach Datum, nicht nach der
	 * „üblichen" Reihenfolge – der Strahl zeigt, was tatsächlich zuerst kommt.
	 * Bei gleichem Datum bleibt die Reihenfolge Vorwarnung, Vorabinfo,
	 * Freigabe, Einzug (stabile Sortierung).
	 *
	 * @return list<array{key:string, date:string}>
	 */
	private function milestones(string $dueDate): array {
		$minus = static fn (int $days): string => (new \DateTimeImmutable($dueDate))->modify('-' . $days . ' days')->format('Y-m-d');
		$milestones = [
			['key' => self::MILESTONE_WARNING, 'date' => $minus($this->settings->warningLeadDays())],
			['key' => self::MILESTONE_PRENOTIFICATION, 'date' => $minus($this->settings->prenotificationLeadDays())],
			['key' => self::MILESTONE_RELEASE, 'date' => $minus($this->settings->releaseLeadDays())],
			['key' => self::MILESTONE_COLLECTION, 'date' => $dueDate],
		];
		usort($milestones, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
		return $milestones;
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>|null
	 */
	private function findNext(array $context, string $today, int $currentAnchor): ?array {
		[$from] = $this->contributionYear->yearBounds($currentAnchor - 1);
		[, $to] = $this->contributionYear->yearBounds($currentAnchor + 1);

		foreach ($this->datesBetween($context, $from, $to) as $entry) {
			$hasUnreleasedClaims = $entry['preview']['count'] > 0;
			if ($entry['dueDate'] < $today && !$hasUnreleasedClaims) {
				continue; // vergangen und nichts mehr freizugeben
			}
			if ($this->hasLiveBatch($entry) && !$hasUnreleasedClaims) {
				continue; // schon vollständig in einem lebenden Lauf
			}
			return $entry;
		}
		return null;
	}

	/** @param array<string,mixed> $entry */
	private function hasLiveBatch(array $entry): bool {
		foreach ($entry['batches'] as $batch) {
			if ($batch['status'] !== DebitBatch::STATUS_DISCARDED) {
				return true;
			}
		}
		return false;
	}

	private function today(): string {
		return $this->time->getDateTime()->format('Y-m-d');
	}
}
