<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebit;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Db\Task;
use OCA\Vereinsbuchhaltung\Service\Sepa\ReturnReasonClassifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Aufgaben rund um den Zustand der Mandate (Spec §3.2/§7 „Vollständiger
 * Aufgaben-Katalog", Issue #117) – abgeleitete Abfrage nach demselben Muster
 * wie {@see ContributionCycleTaskService}: keine Persistenz, nichts zum
 * Quittieren, jede Aufgabe verschwindet von selbst, sobald ihre Ursache
 * behoben ist (Mandat aktiviert, entsperrt, neues Mandat da, Nachweis
 * hochgeladen, Forderungen beglichen ...).
 *
 * Handlungsbedarf:
 * - **Mandat-Entwurf auf Papier, Unterschrift fehlt** (der elektronische Weg
 *   steht in {@see MandateActivationService::findStaleElectronicDraftTasks()});
 *   ist das Unterschriftsdatum schon eingetragen und nur die Aktivierung
 *   offen, sagt der Text das.
 * - **Mandat gesperrt, Klärung offen** – bei einer Sperre aus einer
 *   Rücklastschrift mit der Ursache in Klartext
 *   ({@see ReturnReasonClassifier::staffFacingReason()}).
 * - **Mandat erloschen, Lastschrift weiter gewollt**: nur erloschene Mandate,
 *   aber eine laufende Zuweisung mit Lastschrift. Ersetzt für dieses
 *   Mitglied das allgemeine „kein einzugsfähiges Mandat"
 *   ({@see MandateSituation::hasOwnTask()}), das die Ursache nicht nennt.
 *
 * Hinweis:
 * - **Mandat ohne Nachweis**: nur solange `show_missing_document_warning` an
 *   ist, nur für aktive Papier-Mandate – bei einem elektronischen Mandat ist
 *   das Beweispaket der Zustimmung der Nachweis (Spec §2.2/§3.2: der
 *   Dauer-Mangel gehört zum Papier-Weg). Geprüft wird die hinterlegte
 *   Datei-ID, nicht das Dateisystem: eine Dateiprüfung je Mandat wäre bei
 *   jedem Öffnen des Flyouts ein Dateizugriff je Mandat; eine hinterher
 *   gelöschte Datei fällt dadurch hier nicht auf (die Akte zeigt sie als
 *   fehlend).
 * - **Mandat verfällt in N Tagen**: {@see MandateService::findDueForExpiryWarning()}
 *   mit der Einstellung `expiry_warning_days`.
 * - **Ausgetreten mit offenen Forderungen, Mandat noch aktiv**: das Mandat
 *   endet erst, wenn nichts mehr offen ist (Spec §2.2) – der Hinweis erklärt,
 *   warum ein Ausgetretener noch ein aktives Mandat hat.
 *
 * Die Namen der Mitglieder stehen nie als Platzhalter in `t()`, sondern werden
 * vor den übersetzten Text gesetzt: `t()` maskiert HTML-Zeichen in Variablen,
 * aus „Müller & Söhne“ würde „Müller &amp; Söhne“.
 *
 * Alles kommt aus Sammelabfragen (alle Mandate, alle Mitglieder, nur bei
 * Bedarf Zuweisungen/Forderungen/Rücklastschriften), nie je Mandat eine
 * eigene – die Liste wird bei jedem Öffnen des Flyouts und alle fünf Minuten
 * geladen.
 */
class MandateTaskService {

	/** Längere Sperr-Notizen werden in der Aufgabe gekürzt; die volle Notiz steht in der Akte. */
	private const NOTE_MAX_LENGTH = 120;

	public function __construct(
		private MandateMapper $mandates,
		private MandateService $mandateService,
		private MandateExpiryCalculator $expiryCalculator,
		private MandateDocumentService $documents,
		private MemberMapper $members,
		private AssignmentMapper $assignments,
		private OpenItemMapper $openItems,
		private ReturnedDebitMapper $returnedDebits,
		private ITimeFactory $time,
		private IL10N $l10n,
	) {
	}

	/**
	 * @return list<array{severity:string,message:string,objectType:string,objectId:int,memberId:?int}>
	 */
	public function findTasks(?string $today = null): array {
		$today ??= $this->time->getDateTime()->format('Y-m-d');
		$mandates = $this->mandates->findAll();
		if ($mandates === []) {
			return [];
		}
		$members = $this->members->findAllById();

		return [
			...$this->findDraftAndSuspendedTasks($mandates, $members),
			...$this->findEndedWithDirectDebitTasks($mandates, $members, $today),
			...$this->findMissingDocumentTasks($mandates, $members),
			...$this->findExpiryTasks($members, $today),
			...$this->findDepartedWithOpenClaimsTasks($mandates, $members, $today),
		];
	}

	/**
	 * @param Mandate[] $mandates
	 * @param array<int,Member> $members
	 * @return list<array{severity:string,message:string,objectType:string,objectId:int,memberId:?int}>
	 */
	private function findDraftAndSuspendedTasks(array $mandates, array $members): array {
		$returnedById = null;
		$tasks = [];
		foreach ($mandates as $mandate) {
			$member = $members[$mandate->getMemberId()] ?? null;
			if ($member?->isRedacted()) {
				continue;
			}
			if ($mandate->getStatus() === Mandate::STATUS_DRAFT && $mandate->getSignatureType() === Mandate::SIGNATURE_PAPER) {
				// Mit eingetragenem Unterschriftsdatum fehlt nur noch die Aktivierung - dieselbe
				// Unterscheidung wie in der Akte (mandateView.js::draftExplanation()).
				$tasks[] = $this->task(
					Task::SEVERITY_ACTION_REQUIRED,
					$member,
					$mandate->getSignedAt() !== null && trim($mandate->getSignedAt()) !== ''
						? $this->l10n->t('Papier-Mandat ist noch ein Entwurf: das Unterschriftsdatum ist eingetragen, aber das Mandat ist nicht aktiviert. Bis zur Aktivierung wird nichts eingezogen.')
						: $this->l10n->t('Papier-Mandat ist noch ein Entwurf, die Unterschrift fehlt. Bis zur Aktivierung wird nichts eingezogen.'),
					$mandate,
				);
			} elseif ($mandate->getStatus() === Mandate::STATUS_SUSPENDED) {
				if ($mandate->getSuspensionOrigin() === Mandate::SUSPENSION_RETURNED_DEBIT) {
					$returnedById ??= $this->returnedDebitsById();
					$text = $this->returnedDebitSuspensionText($returnedById[$mandate->getReturnedDebitId() ?? 0] ?? null);
				} else {
					$text = $this->l10n->t('Mandat gesperrt, Klärung offen. Solange wird nichts eingezogen.');
					$note = $this->shorten((string)$mandate->getSuspensionNote());
					if ($note !== '') {
						$text .= ' ' . $this->l10n->t('Notiz zur Sperre:') . ' ' . $note;
					}
				}
				$tasks[] = $this->task(Task::SEVERITY_ACTION_REQUIRED, $member, $text, $mandate);
			}
		}
		return $tasks;
	}

	private function returnedDebitSuspensionText(?ReturnedDebit $returned): string {
		if ($returned === null) {
			return $this->l10n->t('Mandat nach einer Rücklastschrift gesperrt, Klärung offen. Solange wird nichts eingezogen.');
		}
		$reason = ReturnReasonClassifier::staffFacingReason(ReturnReasonClassifier::classify($returned->getReasonCode()), $this->l10n);
		return $this->l10n->t('Mandat nach einer Rücklastschrift gesperrt (%s), Klärung offen. Solange wird nichts eingezogen.', [$reason]);
	}

	/**
	 * @param Mandate[] $mandates
	 * @param array<int,Member> $members
	 * @return list<array{severity:string,message:string,objectType:string,objectId:int,memberId:?int}>
	 */
	private function findEndedWithDirectDebitTasks(array $mandates, array $members, string $today): array {
		$endedOnly = array_filter(
			MandateSituation::byMember($mandates),
			static fn (string $situation): bool => $situation === MandateSituation::ENDED,
		);
		if ($endedOnly === []) {
			return [];
		}

		$wanted = [];
		foreach ($this->assignments->findActiveAsOf($today) as $assignment) {
			if ($assignment->getPaymentMethod() === Assignment::PAYMENT_METHOD_DIRECT_DEBIT && isset($endedOnly[$assignment->getMemberId()])) {
				$wanted[$assignment->getMemberId()] = true;
			}
		}
		if ($wanted === []) {
			return [];
		}

		// Das jüngste erloschene Mandat eines Mitglieds nennt den Grund und ist das Sprungziel.
		/** @var array<int,Mandate> $latest */
		$latest = [];
		foreach ($mandates as $mandate) {
			$memberId = $mandate->getMemberId();
			if (!isset($wanted[$memberId])) {
				continue;
			}
			if (!isset($latest[$memberId]) || (int)$mandate->getId() > (int)$latest[$memberId]->getId()) {
				$latest[$memberId] = $mandate;
			}
		}

		$tasks = [];
		foreach ($latest as $memberId => $mandate) {
			$member = $members[$memberId] ?? null;
			if ($member?->isRedacted()) {
				continue;
			}
			$tasks[] = $this->task(
				Task::SEVERITY_ACTION_REQUIRED,
				$member,
				$this->l10n->t('Das Mandat ist erloschen (%s), die Zuweisung verlangt aber weiter Lastschrift. Neues Mandat einholen oder auf Überweisung umstellen.', [$this->endReasonLabel($mandate->getEndReason())]),
				$mandate,
			);
		}
		return $tasks;
	}

	/**
	 * @param Mandate[] $mandates
	 * @param array<int,Member> $members
	 * @return list<array{severity:string,message:string,objectType:string,objectId:int,memberId:?int}>
	 */
	private function findMissingDocumentTasks(array $mandates, array $members): array {
		if (!$this->documents->showMissingDocumentWarning()) {
			return [];
		}
		$tasks = [];
		foreach ($mandates as $mandate) {
			if ($mandate->getStatus() !== Mandate::STATUS_ACTIVE
				|| $mandate->getSignatureType() !== Mandate::SIGNATURE_PAPER
				|| $mandate->getDocumentFileId() !== null) {
				continue;
			}
			$member = $members[$mandate->getMemberId()] ?? null;
			if ($member?->isRedacted()) {
				continue;
			}
			$tasks[] = $this->task(
				Task::SEVERITY_HINT,
				$member,
				$this->l10n->t('Zum Mandat ist kein Nachweis hinterlegt (unterschriebenes Dokument).'),
				$mandate,
			);
		}
		return $tasks;
	}

	/**
	 * @param array<int,Member> $members
	 * @return list<array{severity:string,message:string,objectType:string,objectId:int,memberId:?int}>
	 */
	private function findExpiryTasks(array $members, string $today): array {
		$todayDate = new \DateTimeImmutable($today);
		$tasks = [];
		foreach ($this->mandateService->findDueForExpiryWarning($today) as $mandate) {
			$expiresAt = $this->expiryCalculator->expiresAt($mandate);
			$member = $members[$mandate->getMemberId()] ?? null;
			if ($expiresAt === null || $member?->isRedacted()) {
				continue;
			}
			$days = (int)$todayDate->diff($expiresAt)->days;
			$tasks[] = $this->task(
				Task::SEVERITY_HINT,
				$member,
				$this->l10n->n(
					'Mandat verfällt am %1$s (in %n Tag), wenn bis dahin nichts eingezogen wird.',
					'Mandat verfällt am %1$s (in %n Tagen), wenn bis dahin nichts eingezogen wird.',
					$days,
					[$expiresAt->format('d.m.Y')],
				),
				$mandate,
			);
		}
		return $tasks;
	}

	/**
	 * @param Mandate[] $mandates
	 * @param array<int,Member> $members
	 * @return list<array{severity:string,message:string,objectType:string,objectId:int,memberId:?int}>
	 */
	private function findDepartedWithOpenClaimsTasks(array $mandates, array $members, string $today): array {
		/** @var array<int,Mandate> $candidates Mitglieds-ID => aktives Mandat eines ausgetretenen Mitglieds */
		$candidates = [];
		foreach ($mandates as $mandate) {
			$member = $members[$mandate->getMemberId()] ?? null;
			if ($mandate->getStatus() === Mandate::STATUS_ACTIVE && $member !== null && !$member->isRedacted() && !$member->isActive($today)) {
				$candidates[$mandate->getMemberId()] = $mandate;
			}
		}
		if ($candidates === []) {
			return [];
		}

		/** @var array<int,array{count:int,sumCents:int}> $open */
		$open = [];
		foreach ($this->openItems->findClaims() as $item) {
			$memberId = $item->getMemberId();
			if ($memberId === null || !isset($candidates[$memberId]) || ClaimStateResolver::resolveForItem($item) !== ClaimStateResolver::STATE_OPEN) {
				continue;
			}
			$open[$memberId] ??= ['count' => 0, 'sumCents' => 0];
			$open[$memberId]['count']++;
			$open[$memberId]['sumCents'] += $item->getAmountCents();
		}

		$tasks = [];
		foreach ($open as $memberId => $sum) {
			$tasks[] = $this->task(
				Task::SEVERITY_HINT,
				$members[$memberId],
				$this->l10n->n(
					'Ausgetreten, aber noch %n offene Forderung (%s €). Das Mandat bleibt aktiv, bis alles beglichen ist.',
					'Ausgetreten, aber noch %n offene Forderungen (zusammen %s €). Das Mandat bleibt aktiv, bis alles beglichen ist.',
					$sum['count'],
					[number_format($sum['sumCents'] / 100, 2, ',', '.')],
				),
				$candidates[$memberId],
			);
		}
		return $tasks;
	}

	/**
	 * @return array{severity:string,message:string,objectType:string,objectId:int,memberId:?int}
	 */
	private function task(string $severity, ?Member $member, string $text, Mandate $mandate): array {
		$name = $member?->displayName() ?? '';
		return [
			'severity' => $severity,
			// Name vor den Text setzen statt in t() einzusetzen (siehe Klassendoc).
			'message' => ($name !== '' ? $name : $this->l10n->t('unbekanntes Mitglied')) . ': ' . $text,
			'objectType' => 'mandate',
			'objectId' => (int)$mandate->getId(),
			// Gleich mitgeben statt vom TaskTargetResolver je Aufgabe nachschlagen zu lassen.
			'memberId' => $member !== null ? (int)$member->getId() : null,
		];
	}

	/** @return array<int,ReturnedDebit> */
	private function returnedDebitsById(): array {
		$byId = [];
		foreach ($this->returnedDebits->findAllByDebitItem() as $returned) {
			$byId[(int)$returned->getId()] = $returned;
		}
		return $byId;
	}

	private function endReasonLabel(?string $endReason): string {
		return match ($endReason) {
			Mandate::END_REASON_REVOKED => $this->l10n->t('widerrufen'),
			Mandate::END_REASON_EXPIRED => $this->l10n->t('nach 36 Monaten verfallen'),
			Mandate::END_REASON_REPLACED => $this->l10n->t('durch ein neues Mandat ersetzt'),
			default => $this->l10n->t('beendet'),
		};
	}

	private function shorten(string $note): string {
		$note = trim((string)preg_replace('/\s+/u', ' ', $note));
		return mb_strlen($note) > self::NOTE_MAX_LENGTH ? rtrim(mb_substr($note, 0, self::NOTE_MAX_LENGTH - 1)) . '…' : $note;
	}
}
