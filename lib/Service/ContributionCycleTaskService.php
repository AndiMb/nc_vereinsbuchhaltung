<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Störfälle/Aufgaben des Einzugszyklus (Spec §3.5/§7, Issue #70) – „Störfälle
 * blockieren nie, niemand quittiert sie: abgeleitete Abfrage mit zwei
 * Schweregraden (Handlungsbedarf/Hinweis)". Nach demselben Muster wie
 * {@see \OCA\Vereinsbuchhaltung\Service\MandateActivationService::findStaleElectronicDraftTasks()}
 * (Issue #67) – keine eigene Persistenz, {@see \OCA\Vereinsbuchhaltung\Controller\TaskController}
 * mischt das Ergebnis mit den echten {@see Task}-Datensätzen.
 *
 * Deckt aus dem vollständigen Aufgaben-Katalog (Spec §7) die vier Fälle ab,
 * die unmittelbar zu diesem Ticket gehören:
 * - **Kein Mandat + Lastschrift gewollt** (Handlungsbedarf): aktive
 *   `direct_debit`-Zuweisung ohne einzugsfähiges Mandat – dieselbe Bedingung,
 *   die {@see ClaimGenerationService} von der Forderungserzeugung abhält.
 * - **Vorabinfo nicht rechtzeitig möglich** / „gerissene Vorlauffrist"
 *   (Handlungsbedarf): eine einzugsfähige Forderung, deren Vorabinfo-Frist
 *   verstrichen ist, ohne dass `prenotified_at` gesetzt wurde. Deckt sowohl
 *   einen echten Zustellfehler als auch einen fehlenden Empfänger ab (Spec
 *   §3.5 „blockiert nichts, verschiebt nichts automatisch, verfällt nicht –
 *   nur eine eskalierende Aufgabe").
 * - **Überweiser-Forderungen überfällig**, aggregiert (Hinweis).
 * - **Vorwarnfenster D−21**: „Nächster Lauf am … — N Forderungen, Summe, M
 *   Störfälle" (Hinweis). Die anderen Aufgaben-Katalog-Einträge (Mandat ohne
 *   Nachweis, Mandat läuft ab, Rücklastschrift, Freigabe fällig, …) gehören
 *   fachlich zu #66/#71/#72/#73 und werden dort eingehängt.
 *
 * „M Störfälle" der Vorwarn-Aufgabe zählt bewusst ALLE aktuell offenen
 * „Kein Mandat"-Fälle, nicht nur die, deren nächste Periode zufällig auf denselben
 * Termin fiele – das wäre eine deutlich aufwendigere Simulation für einen
 * Zähler, der ohnehin nur zum Nachschauen einlädt, nicht zum automatischen
 * Entscheiden.
 */
class ContributionCycleTaskService {

	public function __construct(
		private OpenItemMapper $openItems,
		private AssignmentMapper $assignments,
		private MemberMapper $members,
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
			...$this->findMissingMandateTasks($today),
			...$this->findBrokenLeadTimeTasks($today),
			...$this->findOverdueTransferTask($today),
			...$this->findUpcomingRunTask($today),
		];
	}

	/** @return list<array{severity:string,message:string,objectType:string,objectId:int}> */
	private function findMissingMandateTasks(string $today): array {
		$tasks = [];
		foreach ($this->assignments->findActiveAsOf($today) as $assignment) {
			if ($assignment->getPaymentMethod() !== Assignment::PAYMENT_METHOD_DIRECT_DEBIT) {
				continue;
			}
			if ($this->eligibility->hasCollectibleMandate($assignment->getMemberId())) {
				continue;
			}
			$name = $this->members->displayNameOr($assignment->getMemberId(), $this->l10n->t('unbekanntes Mitglied'));
			$tasks[] = [
				'severity' => Task::SEVERITY_ACTION_REQUIRED,
				'message' => $this->l10n->t('%s hat eine Zuweisung mit Lastschrift, aber kein einzugsfähiges Mandat.', [$name]),
				'objectType' => 'assignment',
				'objectId' => (int)$assignment->getId(),
			];
		}
		return $tasks;
	}

	/** @return list<array{severity:string,message:string,objectType:string,objectId:int}> */
	private function findBrokenLeadTimeTasks(string $today): array {
		$leadDays = $this->settings->prenotificationLeadDays();
		$tasks = [];
		// Grosszuegiges Fenster (weiter als "until" bei sendDue): wir wollen
		// auch Forderungen sehen, deren Faelligkeit schon lange verstrichen ist.
		foreach ($this->openItems->findClaimsAwaitingPrenotification('9999-12-31') as $item) {
			if (!$this->eligibility->isEligible($item)) {
				continue;
			}
			$deadline = (new \DateTimeImmutable((string)$item->getDueDate()))->modify('-' . $leadDays . ' days')->format('Y-m-d');
			if ($today < $deadline) {
				continue; // Frist noch nicht angebrochen - kein Problem
			}
			$name = $this->members->displayNameOr($item->getMemberId(), $this->l10n->t('unbekanntes Mitglied'));
			$tasks[] = [
				'severity' => Task::SEVERITY_ACTION_REQUIRED,
				'message' => $this->l10n->t('Vorabinfo für %1$s (%2$s, fällig %3$s) konnte nicht rechtzeitig verschickt werden.', [$name, (string)$item->getDescription(), (string)$item->getDueDate()]),
				'objectType' => 'claim',
				'objectId' => (int)$item->getId(),
			];
		}
		return $tasks;
	}

	/** @return list<array{severity:string,message:string,objectType:null,objectId:null}> */
	private function findOverdueTransferTask(string $today): array {
		$count = 0;
		$sumCents = 0;
		foreach ($this->openItems->findClaims() as $item) {
			if ($item->getAssignmentId() === null || $item->getDueDate() === null || $item->getDueDate() >= $today) {
				continue;
			}
			if (ClaimStateResolver::resolveForItem($item) !== ClaimStateResolver::STATE_OPEN) {
				continue;
			}
			$assignment = $this->assignments->find($item->getAssignmentId());
			if ($assignment->getPaymentMethod() !== Assignment::PAYMENT_METHOD_TRANSFER) {
				continue;
			}
			$count++;
			$sumCents += $item->getAmountCents();
		}
		if ($count === 0) {
			return [];
		}
		return [[
			'severity' => Task::SEVERITY_HINT,
			'message' => $this->l10n->n('%n überfällige Überweiser-Forderung, zusammen %s €.', '%n überfällige Überweiser-Forderungen, zusammen %s €.', $count, [number_format($sumCents / 100, 2, ',', '.')]),
			'objectType' => null,
			'objectId' => null,
		]];
	}

	/** @return list<array{severity:string,message:string,objectType:null,objectId:null}> */
	private function findUpcomingRunTask(string $today): array {
		$horizon = (new \DateTimeImmutable($today))->modify('+' . $this->settings->warningLeadDays() . ' days')->format('Y-m-d');

		/** @var OpenItem[] $candidates */
		$candidates = [];
		foreach ($this->openItems->findClaims() as $item) {
			if ($item->getDueDate() === null || $item->getDueDate() < $today || $item->getDueDate() > $horizon) {
				continue;
			}
			if (ClaimStateResolver::resolveForItem($item) !== ClaimStateResolver::STATE_OPEN) {
				continue;
			}
			if (!$this->eligibility->isEligible($item)) {
				continue;
			}
			$candidates[] = $item;
		}
		if ($candidates === []) {
			return [];
		}
		usort($candidates, static fn (OpenItem $a, OpenItem $b) => ((string)$a->getDueDate()) <=> ((string)$b->getDueDate()));
		$nextDue = (string)$candidates[0]->getDueDate();
		$sameRun = array_values(array_filter($candidates, static fn (OpenItem $i) => $i->getDueDate() === $nextDue));
		$sum = array_sum(array_map(static fn (OpenItem $i) => $i->getAmountCents(), $sameRun));
		$problemCount = count($this->findMissingMandateTasks($today));

		return [[
			'severity' => Task::SEVERITY_HINT,
			'message' => $this->l10n->t('Nächster Lauf am %1$s – %2$d Forderung(en), %3$s €, %4$d Störfall/Störfälle.', [$nextDue, count($sameRun), number_format($sum / 100, 2, ',', '.'), $problemCount]),
			'objectType' => null,
			'objectId' => null,
		]];
	}

	private function today(): string {
		return $this->time->getDateTime()->format('Y-m-d');
	}
}
