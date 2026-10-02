<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Wer ist anonymisierungsreif? (Spec §3.8, Issue #78, T30) – abgeleitete
 * Abfrage ohne eigene Persistenz, gleiches Muster wie
 * {@see DunningTaskService}/{@see ContributionCycleTaskService}: „Aufgabe
 * schlägt vor, kein Job, keine Entity" (Spec §7 Cron-Übersicht, Zeile
 * „Anonymisierungs-Vorschlag"). {@see findTasks()} wird von
 * {@see \OCA\Vereinsbuchhaltung\Controller\TaskController::index()} bei
 * jedem Laden der Aufgabenliste neu berechnet statt von einem eigenen Cron
 * geschrieben – „täglich (oder seltener)" aus der Spec ist damit implizit
 * erfüllt: die Aufgabe erscheint spätestens, sobald jemand die Aufgabenliste
 * öffnet.
 *
 * **„Letzte zugehörige Buchung":** die Spec (§3.8) meint damit ausdrücklich
 * eine tatsächliche Buchung (die 10-Jahres-Zahl stammt aus der
 * handelsrechtlichen Aufbewahrungspflicht für Buchungsbelege, §257 HGB), kein
 * bloßes Ereignis wie „Forderung angelegt". Diese Klasse sucht deshalb nur an
 * den beiden Stellen, an denen ein Mitglied strukturell mit einem
 * {@see \OCA\Vereinsbuchhaltung\Db\Journal}-Datensatz verbunden ist: einer
 * bezahlten Forderung ({@see \OCA\Vereinsbuchhaltung\Db\OpenItem::getPaidJournalId()})
 * und einer Rücklastschriftgebühr ({@see \OCA\Vereinsbuchhaltung\Db\ReturnedDebit::getJournalId()}
 * über die Einzugsposten der Mandate dieses Mitglieds). Das alte, an
 * `vbh_sepa_mandates`/`vbh_membership_fees` hängende Einzugssystem (siehe
 * {@see Member}-Klassendoc) bleibt dabei bewusst außen vor – dieselbe
 * Abgrenzung, die auch {@see MemberService::blockingReasonsForIds()} für den
 * neuen Mitglieds-Datensatz zieht. Ein Mitglied ganz ohne jede Buchung hat
 * damit auch keine laufende Frist (siehe {@see lastBookingDate()}) – die Uhr
 * tickt erst, sobald überhaupt Geld geflossen ist.
 *
 * **Zwei zusätzliche, über die Spec-Formel hinausgehende Voraussetzungen**
 * (siehe PR-Beschreibung Issue #78 für die Begründung): {@see isEligible()}
 * verlangt zusätzlich zur 10-Jahres-Grenze, dass das Mitglied nicht mehr
 * aktiv ist ({@see Member::isActive()} liefert false) und kein Mandat mehr
 * `lebt` ({@see Mandate::isLive()} liefert für jedes seiner Mandate false).
 * Ohne diese Schranken könnte ein rein rechnerisch "reifes", aber weiter
 * aktives Mitglied (z. B. jahrelang ohne Buchung, aber nie ausgetreten) seine
 * eigenen Stammdaten verlieren, oder ein noch laufendes/aktives Mandat seine
 * Bankverbindung – beides würde die laufende Vereinsverwaltung brechen, nicht
 * nur die Historie betreffen. Die Spec-Formel selbst bleibt davon unberührt:
 * die 10-Jahres-Grenze dominiert weiterhin die SEPA-14-Monats-Untergrenze
 * (siehe {@see AnonymizationEligibilityCalculator}), diese beiden Schranken
 * kommen als zusätzliches UND hinzu, nicht als Ersatz.
 */
class AnonymizationCandidateService {

	public function __construct(
		private MemberMapper $members,
		private OpenItemMapper $openItems,
		private MandateMapper $mandates,
		private DebitItemMapper $debitItems,
		private ReturnedDebitMapper $returnedDebits,
		private JournalMapper $journals,
		private ITimeFactory $time,
		private IL10N $l10n,
	) {
	}

	/**
	 * Datum der letzten zugehörigen Buchung, oder null, wenn es noch nie eine
	 * gab (siehe Klassendoc).
	 */
	public function lastBookingDate(int $memberId): ?string {
		$dates = [];
		foreach ($this->openItems->findByMember($memberId) as $item) {
			if ($item->getPaidJournalId() !== null) {
				$date = $this->journalDate((int)$item->getPaidJournalId());
				if ($date !== null) {
					$dates[] = $date;
				}
			}
		}
		foreach ($this->mandates->findByMember($memberId) as $mandate) {
			foreach ($this->debitItems->findByMandate((int)$mandate->getId()) as $debitItem) {
				$returnedDebit = $this->returnedDebits->findByDebitItem((int)$debitItem->getId());
				if ($returnedDebit !== null && $returnedDebit->getJournalId() !== null) {
					$date = $this->journalDate((int)$returnedDebit->getJournalId());
					if ($date !== null) {
						$dates[] = $date;
					}
				}
			}
		}
		return $dates === [] ? null : max($dates);
	}

	/**
	 * Anonymisierungsreif = 10-Jahres-Grenze erreicht, Mitglied nicht mehr
	 * aktiv, kein Mandat mehr lebend, UND noch nicht anonymisiert (siehe
	 * Klassendoc). Wird auch von {@see \OCA\Vereinsbuchhaltung\Service\MemberAnonymizationService::anonymize()}
	 * selbst als harte Voraussetzung geprüft (nicht nur hier für die
	 * Aufgabenliste) – „buchhalter bestätigt" heißt nicht „ohne jede Prüfung".
	 */
	public function isEligible(int $memberId): bool {
		return $this->statusFor($memberId)['eligible'];
	}

	/**
	 * @return array{lastBookingDate: ?string, cutoffDate: ?string, eligible: bool, redactedAt: ?string}
	 * @throws DoesNotExistException wenn es das Mitglied nicht gibt
	 */
	public function statusFor(int $memberId): array {
		$member = $this->members->find($memberId);
		$lastBooking = $this->lastBookingDate($memberId);

		$eligible = !$member->isRedacted()
			&& !$member->isActive()
			&& $lastBooking !== null
			&& AnonymizationEligibilityCalculator::isEligible($lastBooking, $this->today())
			&& $this->noLiveMandate($memberId);

		return [
			'lastBookingDate' => $lastBooking,
			'cutoffDate' => $lastBooking !== null ? AnonymizationEligibilityCalculator::tenYearCutoffDate($lastBooking) : null,
			'eligible' => $eligible,
			'redactedAt' => $member->getRedactedAt(),
		];
	}

	private function noLiveMandate(int $memberId): bool {
		foreach ($this->mandates->findByMember($memberId) as $mandate) {
			if ($mandate->isLive()) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Aufgabe „Mitglied X anonymisierungsreif" (Spec §7 Cron-Übersicht) – für
	 * {@see \OCA\Vereinsbuchhaltung\Controller\TaskController::index()}, siehe
	 * Klassendoc.
	 *
	 * @return list<array{severity:string,message:string,objectType:string,objectId:int}>
	 */
	public function findTasks(): array {
		$tasks = [];
		foreach ($this->members->findAll() as $member) {
			if ($member->isRedacted()) {
				continue;
			}
			if (!$this->isEligible((int)$member->getId())) {
				continue;
			}
			$tasks[] = [
				'severity' => Task::SEVERITY_HINT,
				'message' => $this->l10n->t('Mitglied %s ist anonymisierungsreif: die letzte zugehörige Buchung liegt mehr als 10 Jahre zurück (Spec §3.8). Bestätigung durch den Buchhalter erforderlich, kein Automatismus.', [$member->displayName()]),
				'objectType' => 'member',
				'objectId' => (int)$member->getId(),
			];
		}
		return $tasks;
	}

	private function journalDate(int $journalId): ?string {
		try {
			return $this->journals->find($journalId, Application::BOOK)->getDate();
		} catch (DoesNotExistException) {
			return null;
		}
	}

	private function today(): string {
		return $this->time->getDateTime()->format('Y-m-d');
	}
}
