<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebit;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCA\Vereinsbuchhaltung\Service\Sepa\ReturnReasonClassifier;
use OCP\IL10N;

/**
 * Die beiden aggregierten Folge-Aufgaben aus dem Aufgaben-Katalog (Spec §7,
 * Issue #117), die Forderungen betreffen, bei denen die Lastschrift als Weg
 * ausgefallen ist – je EINE Zeile mit Anzahl, wie
 * {@see ContributionCycleTaskService} es für überfällige Überweiser-Forderungen
 * tut (die dritte aggregierte Aufgabe des Katalogs):
 *
 * - **Rücklastschrift ohne Wiedereinzug**: offene Forderungen, deren
 *   Einzugsposten zurückgegeben wurde. Es gibt in v1 keinen Wiedereinzug (Spec
 *   §3.5), die Forderung bleibt also offen, bis gezahlt oder erledigt wird.
 *   Der Schweregrad folgt der Rückgabe-Klasse
 *   ({@see ReturnReasonClassifier::taskSeverity()}); steckt in der Zeile auch
 *   nur eine dringende Ursache (Konto nicht nutzbar, Widerspruch, verstorben,
 *   technisch, unbekannt), ist die ganze Zeile Handlungsbedarf, sonst
 *   Hinweis. Eine Zeile je Schweregrad wäre genauer, aber der Katalog will eine
 *   Zeile je Fall.
 * - **Forderungen nach Widerruf offen** (Hinweis): offene Forderungen von
 *   Mitgliedern, die ihr Mandat widerrufen haben und kein einzugsfähiges
 *   haben. Die Mahnstufen laufen von selbst, das Mandat ist weg – die Zeile
 *   sagt nur, dass hier nichts mehr eingezogen wird. Forderungen aus einer
 *   Überweiser-Zuweisung zählen nicht mit: sie sind ohnehin auf dem
 *   Überweisungsweg.
 *
 * Keine Persistenz, nichts zum Quittieren: beide Zeilen verschwinden, sobald
 * die Forderungen bezahlt, erlassen oder storniert sind (dieselbe Ableitung
 * wie {@see ClaimStateResolver}) bzw. das Mitglied ein neues, einzugsfähiges
 * Mandat hat. Sammelabfragen statt Abfragen je Forderung; die teuren (alle
 * Forderungen, alle Posten) erst, wenn es überhaupt eine Rücklastschrift oder
 * einen Widerruf gibt.
 */
class ClaimFollowUpTaskService {

	public function __construct(
		private OpenItemMapper $openItems,
		private ReturnedDebitMapper $returnedDebits,
		private DebitItemMapper $debitItems,
		private MandateMapper $mandates,
		private DirectDebitEligibilityResolver $eligibility,
		private IL10N $l10n,
	) {
	}

	/** @return list<array{severity:string,message:string,objectType:string,objectId:null,memberId:null}> */
	public function findTasks(): array {
		$returned = $this->returnedDebits->findAllByDebitItem();
		$revokedMembers = $this->membersWithRevokedMandate();
		if ($returned === [] && $revokedMembers === []) {
			return [];
		}

		$open = [];
		foreach ($this->openItems->findClaims() as $item) {
			if (ClaimStateResolver::resolveForItem($item) === ClaimStateResolver::STATE_OPEN) {
				$open[] = $item;
			}
		}
		if ($open === []) {
			return [];
		}

		return [
			...$this->findReturnedWithoutRecollection($open, $returned),
			...$this->findOpenAfterRevocation($open, $revokedMembers),
		];
	}

	/**
	 * @param list<OpenItem> $open offene Forderungen
	 * @param array<int,ReturnedDebit> $returned Rücklastschriften je Einzugsposten
	 * @return list<array{severity:string,message:string,objectType:string,objectId:null,memberId:null}>
	 */
	private function findReturnedWithoutRecollection(array $open, array $returned): array {
		if ($returned === []) {
			return [];
		}
		/** @var array<int,ReturnedDebit> $returnedByClaim */
		$returnedByClaim = [];
		foreach ($this->debitItems->findAllInLiveBatches() as $debitItem) {
			$return = $returned[(int)$debitItem->getId()] ?? null;
			if ($return !== null) {
				$returnedByClaim[$debitItem->getOpenItemId()] = $return;
			}
		}

		$count = 0;
		$sumCents = 0;
		$severity = Task::SEVERITY_HINT;
		foreach ($open as $item) {
			$return = $returnedByClaim[(int)$item->getId()] ?? null;
			if ($return === null) {
				continue;
			}
			$count++;
			$sumCents += $item->getAmountCents();
			if (ReturnReasonClassifier::taskSeverity(ReturnReasonClassifier::classify($return->getReasonCode())) === Task::SEVERITY_ACTION_REQUIRED) {
				$severity = Task::SEVERITY_ACTION_REQUIRED;
			}
		}
		if ($count === 0) {
			return [];
		}
		return [$this->aggregated($severity, $this->l10n->n(
			'%n Forderung nach Rücklastschrift weiter offen, zusammen %s €. Sie wird nicht erneut eingezogen.',
			'%n Forderungen nach Rücklastschrift weiter offen, zusammen %s €. Sie werden nicht erneut eingezogen.',
			$count,
			[$this->formatAmount($sumCents)],
		))];
	}

	/**
	 * @param list<OpenItem> $open offene Forderungen
	 * @param array<int,true> $revokedMembers
	 * @return list<array{severity:string,message:string,objectType:string,objectId:null,memberId:null}>
	 */
	private function findOpenAfterRevocation(array $open, array $revokedMembers): array {
		if ($revokedMembers === []) {
			return [];
		}
		$transferAssignments = $this->eligibility->transferAssignmentIds();

		$count = 0;
		$sumCents = 0;
		foreach ($open as $item) {
			$memberId = $item->getMemberId();
			if ($memberId === null || !isset($revokedMembers[$memberId])) {
				continue;
			}
			if ($item->getAssignmentId() !== null && isset($transferAssignments[$item->getAssignmentId()])) {
				continue;
			}
			$count++;
			$sumCents += $item->getAmountCents();
		}
		if ($count === 0) {
			return [];
		}
		return [$this->aggregated(Task::SEVERITY_HINT, $this->l10n->n(
			'%n Forderung nach Widerruf des Mandats weiter offen, zusammen %s €. Sie kann nicht mehr eingezogen werden.',
			'%n Forderungen nach Widerruf des Mandats weiter offen, zusammen %s €. Sie können nicht mehr eingezogen werden.',
			$count,
			[$this->formatAmount($sumCents)],
		))];
	}

	/**
	 * Mitglieder, die ein Mandat widerrufen haben und aktuell kein
	 * einzugsfähiges besitzen.
	 *
	 * @return array<int,true>
	 */
	private function membersWithRevokedMandate(): array {
		$mandates = $this->mandates->findAll();
		$situations = MandateSituation::byMember($mandates);
		$revoked = [];
		foreach ($mandates as $mandate) {
			if ($mandate->getStatus() === Mandate::STATUS_ENDED
				&& $mandate->getEndReason() === Mandate::END_REASON_REVOKED
				&& ($situations[$mandate->getMemberId()] ?? null) !== MandateSituation::COLLECTIBLE) {
				$revoked[$mandate->getMemberId()] = true;
			}
		}
		return $revoked;
	}

	/** @return array{severity:string,message:string,objectType:string,objectId:null,memberId:null} */
	private function aggregated(string $severity, string $message): array {
		return [
			'severity' => $severity,
			'message' => $message,
			// Kein einzelnes Objekt, kein Mitglied: die Oberfläche führt in den Einzug.
			'objectType' => 'claims',
			'objectId' => null,
			'memberId' => null,
		];
	}

	private function formatAmount(int $cents): string {
		return number_format($cents / 100, 2, ',', '.');
	}
}
