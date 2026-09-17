<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AssignmentEventMapper;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateAmendmentMapper;
use OCA\Vereinsbuchhaltung\Db\MandateEventMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Der eigentliche Anonymisierungs-Vorgang (Spec §3.8, Issue #78, T30) – ein
 * Vorgang JE MITGLIED, nicht je Mandat: schwärzt in einer einzigen
 * Transaktion Name/Kontakt am Mitglied, Bankdaten an allen Mandaten
 * (inkl. ihrer Einzugsposten-Schnappschüsse, siehe {@see DebitItem}-
 * Klassendoc) sowie alle personenbezogenen Freitextfelder in der gesamten
 * verknüpften Historie. Irreversibel, deshalb:
 *
 * - {@see anonymize()} prüft die Anonymisierungsreife SELBST noch einmal
 *   (über {@see AnonymizationCandidateService}), nicht nur die aufrufende
 *   Oberfläche – „buchhalter bestätigt je Mitglied" ist eine Freigabe, keine
 *   Abschaltung der Fristprüfung.
 * - ein bereits anonymisiertes Mitglied (`redacted_at` gesetzt) lässt sich
 *   kein zweites Mal anonymisieren (wäre ohnehin wirkungslos, meldet aber
 *   einen klaren Fehler statt still nichts zu tun).
 *
 * **Strukturierte Felder bleiben stehen** (Reason-Codes, Beträge, Daten,
 * Status) – nur die in der Klassendoc-Liste unten genannten personenbezogenen
 * Felder werden geleert:
 *
 * - {@see Member}: Name/Kontakt (`firstName`/`lastName`/`organizationName`/
 *   `email`/`phone`/`street`/`postalCode`/`city`/`country`) sowie
 *   `internalNote`.
 * - {@see Mandate} (alle des Mitglieds): `iban`/`bic` (nullable seit Issue
 *   #66) auf null, `accountHolder` auf den Platzhalter
 *   {@see Mandate::REDACTED_ACCOUNT_HOLDER} (Pflichtfeld), `suspensionNote`
 *   (Issue #66), das Beweispaket-Trio `consentIp`/`consentUserAgent`/
 *   `consentActor` (Issue #67 – `consentAt`/`mandateTextVersion` bleiben als
 *   strukturierter Nachweis stehen, WANN unter welcher Textversion zugestimmt
 *   wurde) sowie – best effort – die verwiesene Nachweis-Datei
 *   ({@see MandateDocumentService}, enthält Unterschrift/IBAN/Name im Klartext).
 * - {@see \OCA\Vereinsbuchhaltung\Db\MandateAmendment} (je Mandat):
 *   `oldIban`/`oldBic`/`oldAccountHolder`.
 * - {@see \OCA\Vereinsbuchhaltung\Db\MandateEvent} (je Mandat): `onBehalfNote`
 *   (Issue #66).
 * - {@see \OCA\Vereinsbuchhaltung\Db\DebitItem} (je Mandat, Issue #71): der
 *   eingefrorene Bankdaten-Schnappschuss, siehe dortige Klassendoc.
 * - {@see \OCA\Vereinsbuchhaltung\Db\ReturnedDebit} (über die Einzugsposten
 *   der Mandate, Issue #72): `reasonText` bei „unbekanntem" Rücklastschrift-
 *   grund – `reasonCode` bleibt strukturiert stehen.
 * - {@see \OCA\Vereinsbuchhaltung\Db\OpenItem} (Forderungen des Mitglieds,
 *   Issue #68): `debtor` (friert sonst dauerhaft den alten Anzeigenamen ein,
 *   siehe {@see \OCA\Vereinsbuchhaltung\Service\ClaimService::debtorLabel()}/
 *   {@see \OCA\Vereinsbuchhaltung\Service\ClaimGenerationService}) auf
 *   {@see Member::REDACTED_DISPLAY_NAME}, sowie `settlementNote`/
 *   `deferredReason`/`cancelledReason`.
 * - {@see \OCA\Vereinsbuchhaltung\Db\Assignment} (Zuweisungen des Mitglieds,
 *   Issue #68): `overrideReason` (schon heute im Self-Service explizit
 *   ausgeblendet, siehe {@see \OCA\Vereinsbuchhaltung\Controller\SelfController::assignmentData()}).
 * - {@see \OCA\Vereinsbuchhaltung\Db\AssignmentEvent} (je Zuweisung): `onBehalfNote`.
 *
 * **Bekannte, bewusst nicht adressierte Lücke** (siehe PR-Beschreibung
 * Issue #78): generierte Ereignistexte
 * ({@see \OCA\Vereinsbuchhaltung\Db\MandateEvent::getMessage()}) können
 * einzelne personenbezogene Fragmente (z. B. eine Mailadresse, eine alte IBAN
 * oder den Wortlaut einer Sperr-Notiz) bereits zum Entstehungszeitpunkt fest
 * in den Satztext eingebaut haben (siehe MandateService::logEvent()-Aufrufe).
 * Eine zuverlässige nachträgliche Bereinigung dieser Freitexte ohne fragile
 * Heuristik ist eine eigene Aufgabe und in dieser Klasse bewusst nicht
 * versucht – die neu strukturierten Felder (`onBehalfNote`, `suspensionNote`,
 * …) sind dagegen vollständig erfasst.
 *
 * Die NC-Konto-Verknüpfung (`nc_user_id`) bleibt unangetastet: eine
 * NC-Konto-Löschung ist laut Spec §3.8 vollständig vom Anonymisierungs-
 * Zeitpunkt entkoppelt, in beide Richtungen.
 */
class MemberAnonymizationService {

	public function __construct(
		private MemberMapper $members,
		private MandateMapper $mandates,
		private MandateAmendmentMapper $amendments,
		private MandateEventMapper $mandateEvents,
		private AssignmentMapper $assignments,
		private AssignmentEventMapper $assignmentEvents,
		private OpenItemMapper $openItems,
		private DebitItemMapper $debitItems,
		private ReturnedDebitMapper $returnedDebits,
		private MandateDocumentService $mandateDocuments,
		private AnonymizationCandidateService $eligibility,
		private TransactionRunner $transaction,
		private AuditService $audit,
		private ITimeFactory $time,
		private IL10N $l10n,
	) {
	}

	/**
	 * @throws DoesNotExistException wenn es das Mitglied nicht gibt
	 * @throws \InvalidArgumentException wenn das Mitglied bereits anonymisiert
	 *                                   oder noch nicht anonymisierungsreif ist
	 */
	public function anonymize(int $memberId): Member {
		return $this->transaction->run(function () use ($memberId) {
			$member = $this->members->find($memberId);
			if ($member->isRedacted()) {
				throw new \InvalidArgumentException($this->l10n->t('Dieses Mitglied ist bereits anonymisiert.'));
			}
			if (!$this->eligibility->isEligible($memberId)) {
				throw new \InvalidArgumentException($this->l10n->t('Dieses Mitglied ist noch nicht anonymisierungsreif: die 10-Jahres-Frist seit der letzten zugehörigen Buchung ist noch nicht erreicht.'));
			}

			$now = $this->now();
			$this->redactMandates($memberId, $now);
			$this->redactClaims($memberId);
			$this->redactAssignments($memberId);
			$this->redactMember($member, $now);

			$this->audit->log('Mitglied anonymisiert (DSGVO)', 'member', $memberId);
			return $member;
		});
	}

	private function redactMember(Member $member, string $now): void {
		$member->setFirstName(null);
		$member->setLastName(null);
		$member->setOrganizationName(null);
		$member->setEmail(null);
		$member->setPhone(null);
		$member->setStreet(null);
		$member->setPostalCode(null);
		$member->setCity(null);
		$member->setCountry(null);
		$member->setInternalNote(null);
		$member->setRedactedAt($now);
		$this->members->update($member);
	}

	private function redactMandates(int $memberId, string $now): void {
		foreach ($this->mandates->findByMember($memberId) as $mandate) {
			$this->scheduleDocumentDeletion($mandate);

			$mandate->setIban(null);
			$mandate->setBic(null);
			$mandate->setAccountHolder(Mandate::REDACTED_ACCOUNT_HOLDER);
			$mandate->setSuspensionNote(null);
			$mandate->setDocumentFileId(null);
			$mandate->setConsentIp(null);
			$mandate->setConsentUserAgent(null);
			$mandate->setConsentActor(null);
			$mandate->setRedactedAt($now);
			$this->mandates->update($mandate);

			foreach ($this->amendments->findByMandate((int)$mandate->getId()) as $amendment) {
				if ($amendment->getOldIban() === null && $amendment->getOldBic() === null && $amendment->getOldAccountHolder() === null) {
					continue;
				}
				$amendment->setOldIban(null);
				$amendment->setOldBic(null);
				$amendment->setOldAccountHolder(null);
				$this->amendments->update($amendment);
			}

			foreach ($this->mandateEvents->findByMandate((int)$mandate->getId()) as $event) {
				if ($event->getOnBehalfNote() === null) {
					continue;
				}
				$event->setOnBehalfNote(null);
				$this->mandateEvents->update($event);
			}

			foreach ($this->debitItems->findByMandate((int)$mandate->getId()) as $debitItem) {
				if ($debitItem->getIban() !== null) {
					$debitItem->setIban(null);
				}
				if ($debitItem->getBic() !== null) {
					$debitItem->setBic(null);
				}
				if ($debitItem->getAccountHolder() !== Mandate::REDACTED_ACCOUNT_HOLDER) {
					$debitItem->setAccountHolder(Mandate::REDACTED_ACCOUNT_HOLDER);
				}
				$this->debitItems->update($debitItem);

				$returnedDebit = $this->returnedDebits->findByDebitItem((int)$debitItem->getId());
				if ($returnedDebit !== null && $returnedDebit->getReasonText() !== null) {
					$returnedDebit->setReasonText(null);
					$this->returnedDebits->update($returnedDebit);
				}
			}
		}
	}

	/**
	 * Löscht best effort die verwiesene Nachweis-Datei (Unterschrift/IBAN/Name
	 * im Klartext) NACH dem Commit – dasselbe Muster wie
	 * {@see AttachmentStorageService::deleteOne()}: ein Rollback macht die
	 * Datenbank rückgängig, eine bereits gelöschte Datei aber nicht. Der
	 * File-Node wird VOR dem Leeren von `document_file_id` aufgelöst, weil
	 * {@see MandateDocumentService::nodeOrNull()} genau dieses Feld braucht.
	 */
	private function scheduleDocumentDeletion(Mandate $mandate): void {
		if ($mandate->getDocumentFileId() === null) {
			return;
		}
		$node = $this->mandateDocuments->nodeOrNull($mandate);
		if ($node === null) {
			return;
		}
		$this->transaction->afterCommit(static function () use ($node): void {
			try {
				$node->delete();
			} catch (\Throwable) {
				// Verwaiste Datei ist aergerlich, aber kein Grund, den
				// abgeschlossenen Anonymisierungs-Vorgang scheitern zu lassen
				// (gleiches Prinzip wie TransactionRunner::runAfterCommit()).
			}
		});
	}

	private function redactClaims(int $memberId): void {
		foreach ($this->openItems->findByMember($memberId) as $item) {
			$changed = false;
			if ($item->getDebtor() !== Member::REDACTED_DISPLAY_NAME) {
				$item->setDebtor(Member::REDACTED_DISPLAY_NAME);
				$changed = true;
			}
			if ($item->getSettlementNote() !== null) {
				$item->setSettlementNote(null);
				$changed = true;
			}
			if ($item->getDeferredReason() !== null) {
				$item->setDeferredReason(null);
				$changed = true;
			}
			if ($item->getCancelledReason() !== null) {
				$item->setCancelledReason(null);
				$changed = true;
			}
			if ($changed) {
				$this->openItems->update($item);
			}
		}
	}

	private function redactAssignments(int $memberId): void {
		foreach ($this->assignments->findByMember($memberId) as $assignment) {
			if ($assignment->getOverrideReason() !== null) {
				$assignment->setOverrideReason(null);
				$this->assignments->update($assignment);
			}
			foreach ($this->assignmentEvents->findByAssignment((int)$assignment->getId()) as $event) {
				if ($event->getOnBehalfNote() === null) {
					continue;
				}
				$event->setOnBehalfNote(null);
				$this->assignmentEvents->update($event);
			}
		}
	}

	private function now(): string {
		return $this->time->getDateTime()->format('Y-m-d H:i:s');
	}
}
