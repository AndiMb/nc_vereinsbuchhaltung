<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\DebitBatchMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Die beiden Freigabe/Einreichung-Störfälle aus dem vollständigen
 * Aufgaben-Katalog (Spec §7 „Freigabe fällig / Einreichung überfällig",
 * Issue #71) – nach demselben Muster wie
 * {@see ContributionCycleTaskService} (Issue #70), das in seinem Klassendoc
 * ausdrücklich auf diese Fortsetzung verweist: „andere Aufgaben-Katalog-
 * Einträge … gehören fachlich zu #66/#71/#72/#73 und werden dort
 * eingehängt". Eigene Klasse statt Erweiterung von
 * {@see ContributionCycleTaskService}: die beiden Fälle hängen an
 * `DebitBatch`/`DebitItem`, die es zum Zeitpunkt von Issue #70 noch nicht gab.
 *
 * Beide Fälle nutzen denselben Schwellwert, den „Vorlauf-Puffer" D−5 (Spec
 * §8, {@see ContributionCycleSettings::releaseLeadDays()}): ab diesem Punkt
 * sollte ein Lauf für den betreffenden Termin bereits freigegeben UND
 * eingereicht sein.
 *
 * - **Freigabe fällig**: es gibt einzugsfähige, noch nicht gebündelte
 *   Forderungen mit Fälligkeit innerhalb des Vorlauf-Puffers, für die noch
 *   kein Lauf existiert.
 * - **Einreichung überfällig**: ein Lauf ist bereits freigegeben, aber
 *   innerhalb des Vorlauf-Puffers noch nicht eingereicht.
 *
 * Wie der gesamte Katalog „blockiert nie, niemand quittiert" (Spec §3.5) –
 * reine abgeleitete Abfrage, keine eigene Persistenz.
 */
class DebitBatchTaskService {

	public function __construct(
		private OpenItemMapper $openItems,
		private DebitItemMapper $debitItems,
		private DebitBatchMapper $batches,
		private DirectDebitEligibilityResolver $eligibility,
		private ContributionCycleSettings $settings,
		private ITimeFactory $time,
		private IL10N $l10n,
	) {
	}

	/** @return list<array{severity:string,message:string,objectType:?string,objectId:?int}> */
	public function findTasks(?string $today = null): array {
		$today ??= $this->today();
		return [
			...$this->findReleaseDueTasks($today),
			...$this->findSubmissionOverdueTasks($today),
		];
	}

	/** @return list<array{severity:string,message:string,objectType:null,objectId:null}> */
	private function findReleaseDueTasks(string $today): array {
		$horizon = $this->horizon($today);
		$alreadyBatched = $this->debitItems->findOpenItemIdsInLiveBatches();

		/** @var array<string, OpenItem[]> $byDueDate */
		$byDueDate = [];
		foreach ($this->openItems->findClaims() as $item) {
			if ($item->getDueDate() === null || $item->getDueDate() > $horizon) {
				continue;
			}
			if (ClaimStateResolver::resolveForItem($item) !== ClaimStateResolver::STATE_OPEN) {
				continue;
			}
			if (in_array($item->getId(), $alreadyBatched, true)) {
				continue; // bereits in einem lebenden Lauf - kein Problem mehr
			}
			if (!$this->eligibility->isEligible($item)) {
				continue; // Ueberweiser o.ae. - kein Lastschriftweg gewollt
			}
			$byDueDate[(string)$item->getDueDate()][] = $item;
		}

		$tasks = [];
		foreach ($byDueDate as $dueDate => $items) {
			$sumCents = array_sum(array_map(static fn (OpenItem $i): int => $i->getAmountCents(), $items));
			$tasks[] = [
				'severity' => Task::SEVERITY_ACTION_REQUIRED,
				'message' => $this->l10n->t(
					'Freigabe fällig: Lauf am %1$s (%2$d Forderung(en), %3$s €) ist noch nicht freigegeben.',
					[$dueDate, count($items), number_format($sumCents / 100, 2, ',', '.')],
				),
				'objectType' => null,
				'objectId' => null,
			];
		}
		return $tasks;
	}

	/** @return list<array{severity:string,message:string,objectType:string,objectId:int}> */
	private function findSubmissionOverdueTasks(string $today): array {
		$horizon = $this->horizon($today);
		$tasks = [];
		foreach ($this->batches->findReleasedNotSubmitted() as $batch) {
			if ($batch->getDueDate() > $horizon) {
				continue; // noch reichlich Zeit bis zum Termin
			}
			$tasks[] = [
				'severity' => Task::SEVERITY_ACTION_REQUIRED,
				'message' => $this->l10n->t('Einreichung überfällig: Lauf vom %s ist freigegeben, aber noch nicht bei der Bank eingereicht.', [$batch->getDueDate()]),
				'objectType' => 'debit_batch',
				'objectId' => (int)$batch->getId(),
			];
		}
		return $tasks;
	}

	private function horizon(string $today): string {
		return (new \DateTimeImmutable($today))->modify('+' . $this->settings->releaseLeadDays() . ' days')->format('Y-m-d');
	}

	private function today(): string {
		return $this->time->getDateTime()->format('Y-m-d');
	}
}
