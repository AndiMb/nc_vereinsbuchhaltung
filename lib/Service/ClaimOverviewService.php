<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCA\Vereinsbuchhaltung\Db\DebitBatchMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItem;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\DunningNotice;
use OCA\Vereinsbuchhaltung\Db\DunningNoticeMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebit;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCA\Vereinsbuchhaltung\Service\Sepa\ReturnReasonClassifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Die lesende Forderungsübersicht des Einzug-Unterreiters (Issue #104, Spec
 * §6 „Aufgaben & Offene Posten: im Einzug, Segment neben dem Zeitstrahl"):
 * jede Forderung mit Mitglied – dazu der vollständig abgeleitete Zustand
 * (Spec §2.2, inkl. „im Einzug/eingezogen/zurückgegeben"), der Mahnstand
 * ({@see DunningStatusResolver}), der Einzugsposten im lebenden Lauf, eine
 * eventuelle Rücklastschrift und die Störfälle dieser Forderung.
 *
 * Bewusst getrennt von {@see ClaimService::listMasked()}: dessen `state` kennt
 * nur offen/storniert/erledigt, und Bestand (Beitragsgruppen-Reiter, E2E)
 * liest ihn so. Hier wird nichts verändert, nur gelesen – ab `revisor` (Spec
 * §3.9 „Einzug-Unterreiter lesend (Läufe, Forderungen, Erledigungsvermerke,
 * Störfall-/Rücklastschriftlisten)").
 *
 * **Rücklastschrift-Gründe** erscheinen immer in Klartext
 * ({@see ReturnReasonClassifier::memberFacingReason()}); der rohe ISO-Code und
 * der Freitext der Bank nur, wenn der Aufrufer `$withReturnCodes` setzt (Spec
 * §3.6 „Codes bleiben admin-only" – `buchhalter`/`verwalter`). Für alle
 * anderen fehlen die Schlüssel in der Antwort ganz.
 *
 * **Störfälle** kommen aus denselben abgeleiteten Abfragen wie die
 * Aufgabenliste ({@see ContributionCycleTaskService::findTasks()},
 * {@see DunningTaskService::findBoardEscalationTasks()}) – dieselben Texte,
 * keine zweite Auslegung. Aufgaben zu einer Forderung hängen an dieser, die zu
 * einer Zuweisung („kein einzugsfähiges Mandat") am Mitglied und damit an
 * seinen noch nicht eingezogenen Forderungen; aggregierte Aufgaben ohne
 * Objekt gehören zu keiner Zeile. Dazu kommt die Rücklastschrift selbst, die
 * es als Aufgabe noch nicht gibt (Schweregrad nach
 * {@see ReturnReasonClassifier::taskSeverity()}).
 */
class ClaimOverviewService {

	public function __construct(
		private OpenItemMapper $openItems,
		private MemberMapper $members,
		private DebitBatchMapper $batches,
		private DebitItemMapper $debitItems,
		private ReturnedDebitMapper $returnedDebits,
		private DunningNoticeMapper $notices,
		private DunningSettings $dunningSettings,
		private ContributionCycleSettings $cycleSettings,
		private DirectDebitEligibilityResolver $eligibility,
		private ContributionCycleTaskService $cycleTasks,
		private DunningTaskService $dunningTasks,
		private TaskTargetResolver $targets,
		private IUserManager $userManager,
		private ITimeFactory $time,
		private IL10N $l10n,
	) {
	}

	/**
	 * @param bool $withReturnCodes ISO-Rückgabecode und Bank-Freitext mitliefern (nur `buchhalter`/`verwalter`)
	 * @param string|null $today Stichtag (JJJJ-MM-TT), sonst heute (für Tests)
	 * @return array{today:string, dunningIntervalDays:int, claims:list<array<string,mixed>>} Forderungen nach Fälligkeit aufsteigend (wie {@see OpenItemMapper::findClaims()})
	 */
	public function build(bool $withReturnCodes, ?string $today = null): array {
		$today ??= $this->time->getDateTime()->format('Y-m-d');
		$intervalDays = $this->dunningSettings->intervalDays();
		$leadDays = $this->cycleSettings->prenotificationLeadDays();

		$memberNames = $this->memberNames();
		$noticesByItem = $this->noticesByItem();
		$liveDebits = $this->liveDebits();
		[$claimIssues, $memberIssues] = $this->taskIssues($today);
		/** @var array<string,string|null> $userNames */
		$userNames = [];

		$rows = [];
		foreach ($this->openItems->findClaims() as $claim) {
			$id = (int)$claim->getId();
			$memberId = $claim->getMemberId();
			$debit = $liveDebits[$id] ?? null;
			$returned = $debit['returned'] ?? null;
			$state = $debit !== null
				? ClaimStateResolver::resolveForDebitItem($claim, $debit['batch'], $returned !== null, $today)
				: ClaimStateResolver::resolveForItem($claim);
			$isOpen = ClaimStateResolver::resolveForItem($claim) === ClaimStateResolver::STATE_OPEN;

			$row = $claim->jsonSerialize();
			$row['memberDisplayName'] = $memberNames[$memberId] ?? $this->l10n->t('(unbekanntes Mitglied)');
			$row['state'] = $state;
			$row['settlementType'] = $state === ClaimStateResolver::STATE_SETTLED ? $claim->getStatus() : null;
			$row['settledByName'] = $this->userName($claim->getSettledBy(), $userNames);
			$row['deferredByName'] = $this->userName($claim->getDeferredBy(), $userNames);
			// „Gestundet" heißt: die Stundung läuft noch – eine abgelaufene bleibt Historie.
			$row['deferred'] = $isOpen && $claim->getDeferredUntil() !== null && $claim->getDeferredUntil() >= $today;
			$row['debit'] = $debit === null ? null : [
				'batchId' => (int)$debit['batch']->getId(),
				'status' => $debit['batch']->getStatus(),
				'dueDate' => $debit['batch']->getDueDate(),
			];
			$row['returned'] = $returned === null ? null : $this->returnedInfo($returned, $withReturnCodes);
			$row['dunning'] = DunningStatusResolver::resolve(
				$claim,
				$noticesByItem[$id] ?? [],
				$intervalDays,
				$today,
				$this->paymentRequestDueOn($claim, $debit !== null, $noticesByItem[$id] ?? [], $leadDays),
			);
			$row['issues'] = $this->issuesOf($state, $claimIssues[$id] ?? [], $memberIssues[$memberId] ?? [], $returned);
			$rows[] = $row;
		}

		return ['today' => $today, 'dunningIntervalDays' => $intervalDays, 'claims' => $rows];
	}

	/** @return array<int,string> Mitglieds-ID => Anzeigename, eine Abfrage statt einer je Forderung */
	private function memberNames(): array {
		$names = [];
		foreach ($this->members->findAll() as $member) {
			$name = $member->displayName();
			$names[(int)$member->getId()] = $name !== '' ? $name : $this->l10n->t('Mitglied #%s', [(string)$member->getId()]);
		}
		return $names;
	}

	/** @return array<int,list<DunningNotice>> Forderungs-ID => bisher versandte Stufen */
	private function noticesByItem(): array {
		$byItem = [];
		foreach ($this->notices->findAll() as $notice) {
			$byItem[$notice->getOpenItemId()][] = $notice;
		}
		return $byItem;
	}

	/**
	 * Der Einzugsposten je Forderung im lebenden Lauf samt Lauf und eventueller
	 * Rücklastschrift. Ein verworfener Lauf zählt nicht (Spec §3.5: die Posten
	 * bleiben als Historie, die Forderung ist wieder frei) – die Forderung hat
	 * damit höchstens einen Posten in einem lebenden Lauf (Spec §2.2).
	 *
	 * @return array<int,array{batch:DebitBatch, item:DebitItem, returned:ReturnedDebit|null}>
	 */
	private function liveDebits(): array {
		$batches = [];
		foreach ($this->batches->findAll() as $batch) {
			$batches[(int)$batch->getId()] = $batch;
		}
		$returned = $this->returnedDebits->findAllByDebitItem();
		$byItem = [];
		foreach ($this->debitItems->findAllInLiveBatches() as $item) {
			$batch = $batches[$item->getBatchId()] ?? null;
			if ($batch === null) {
				continue;
			}
			$byItem[$item->getOpenItemId()] = ['batch' => $batch, 'item' => $item, 'returned' => $returned[(int)$item->getId()] ?? null];
		}
		return $byItem;
	}

	/**
	 * Die Aufgaben der abgeleiteten Abfragen, nach Forderung bzw. Mitglied
	 * einsortiert. Aggregierte Aufgaben (ohne Objekt) und Läufe gehören zu
	 * keiner Zeile und bleiben außen vor.
	 *
	 * @return array{0:array<int,list<array{severity:string,message:string}>>, 1:array<int,list<array{severity:string,message:string}>>} [je Forderung, je Mitglied]
	 */
	private function taskIssues(string $today): array {
		$tasks = [...$this->cycleTasks->findTasks($today), ...$this->dunningTasks->findBoardEscalationTasks($today)];
		$byClaim = [];
		$byMember = [];
		foreach ($this->targets->withMemberIds($tasks) as $task) {
			$issue = ['severity' => (string)$task['severity'], 'message' => (string)$task['message']];
			if ($task['objectType'] === 'claim' && is_int($task['objectId'])) {
				$byClaim[$task['objectId']][] = $issue;
			} elseif ($task['objectType'] === 'assignment' && is_int($task['memberId'] ?? null)) {
				$byMember[$task['memberId']][] = $issue;
			}
		}
		return [$byClaim, $byMember];
	}

	/**
	 * @param list<array{severity:string,message:string}> $ofClaim Aufgaben zu genau dieser Forderung
	 * @param list<array{severity:string,message:string}> $ofMember Aufgaben zum Mitglied (Zuweisung ohne Mandat)
	 * @return list<array{severity:string,message:string,scope:string}> Handlungsbedarf zuerst
	 */
	private function issuesOf(string $state, array $ofClaim, array $ofMember, ?ReturnedDebit $returned): array {
		$issues = [];
		foreach ($ofClaim as $issue) {
			$issues[] = $issue + ['scope' => 'claim'];
		}
		// „Kein einzugsfähiges Mandat" betrifft, was noch nicht im Einzug ist; für eine
		// eingezogene, erledigte oder stornierte Forderung wäre der Hinweis irreführend.
		if ($state === ClaimStateResolver::STATE_OPEN) {
			foreach ($ofMember as $issue) {
				$issues[] = $issue + ['scope' => 'member'];
			}
		}
		if ($state === ClaimStateResolver::STATE_RETURNED && $returned !== null) {
			$class = ReturnReasonClassifier::classify($returned->getReasonCode());
			$issues[] = [
				'severity' => ReturnReasonClassifier::taskSeverity($class),
				'message' => $this->l10n->t('Rücklastschrift: %s', [ReturnReasonClassifier::memberFacingReason($class, $this->l10n)]),
				'scope' => 'claim',
			];
		}
		usort($issues, static fn (array $a, array $b): int => ($a['severity'] === Task::SEVERITY_ACTION_REQUIRED ? 0 : 1) <=> ($b['severity'] === Task::SEVERITY_ACTION_REQUIRED ? 0 : 1));
		return $issues;
	}

	/** @return array<string,string|int|null> */
	private function returnedInfo(ReturnedDebit $returned, bool $withReturnCodes): array {
		$class = ReturnReasonClassifier::classify($returned->getReasonCode());
		$info = [
			'receivedAt' => $returned->getReceivedAt(),
			'reason' => ReturnReasonClassifier::memberFacingReason($class, $this->l10n),
		];
		if ($withReturnCodes) {
			$info['reasonCode'] = $returned->getReasonCode();
			$info['reasonText'] = $returned->getReasonText();
		}
		return $info;
	}

	/**
	 * Wann die Zahlungsaufforderung (Stufe 0) versandt würde, solange noch keine
	 * raus ist: nur für Forderungen, die NIE per Lastschrift eingezogen werden
	 * (Überweiser, mandatlose manuelle Forderung) – der Versand erfolgt, sobald
	 * Fälligkeit minus Vorabinfo-Vorlauf erreicht ist
	 * ({@see DunningLadderService::duePaymentRequestsByMember()}). Lastschrift-
	 * Forderungen bekommen sie erst nach einer Rücklastschrift oder einem
	 * Widerruf, einen Termin dafür gibt es nicht.
	 *
	 * @param list<DunningNotice> $notices
	 */
	private function paymentRequestDueOn(OpenItem $claim, bool $inLiveBatch, array $notices, int $leadDays): ?string {
		if ($notices !== [] || $inLiveBatch || $claim->getDueDate() === null) {
			return null;
		}
		if (ClaimStateResolver::resolveForItem($claim) !== ClaimStateResolver::STATE_OPEN || $this->eligibility->isEligible($claim)) {
			return null;
		}
		return (new \DateTimeImmutable((string)$claim->getDueDate()))->modify('-' . $leadDays . ' days')->format('Y-m-d');
	}

	/**
	 * Anzeigename eines Nextcloud-Kontos, ersatzweise die uid selbst (z. B. nach
	 * dem Löschen des Kontos) – dasselbe wie im Lauf-Detail (DebitBatchController).
	 *
	 * @param array<string,string|null> $cache
	 */
	private function userName(?string $uid, array &$cache): ?string {
		if ($uid === null || $uid === '') {
			return null;
		}
		if (!array_key_exists($uid, $cache)) {
			$cache[$uid] = $this->userManager->getDisplayName($uid) ?? $uid;
		}
		return $cache[$uid];
	}
}
