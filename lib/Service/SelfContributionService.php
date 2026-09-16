<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Activity\SelfServiceProvider;
use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Beitrags-Aktionen im Self-Service (Spec §3.4 Aktionskatalog, Issue #76):
 * Betrag ändern (hoch frei, runter bis Untergrenze) und Turnus wechseln (aus
 * `allowed_intervals`) – NICHT Beitragsgruppe wechseln, „diesen Monat mal
 * nicht abbuchen", Austritt, Erledigungsvermerk oder individuelle
 * Untergrenze (siehe Aktionskatalog „Darf nicht").
 *
 * Baut bewusst NICHT neu, was {@see AssignmentService} (Validierung,
 * Wirksamkeitsregel) und {@see EffectivityRuleService} (Sperrfenster) schon
 * können – diese Klasse ist nur die Self-Service-Hülle darum: IDOR-Schutz
 * (jede Methode löst `member_id` ausschließlich über
 * {@see ActorContextService} auf, NIE aus einem Parameter – siehe
 * {@see requireMemberId()}/{@see assertOwnership()}), Quittungsmail
 * ({@see SelfServiceReceiptMailService}) und Activity-Feed-Eintrag
 * ({@see SelfServiceActivityPublisher}) je tatsächlich geänderten Feld.
 */
class SelfContributionService {

	public function __construct(
		private ActorContextService $actorContext,
		private AssignmentService $assignments,
		private MemberMapper $members,
		private SelfServiceReceiptMailService $receiptMail,
		private SelfServiceActivityPublisher $activity,
		private IUserManager $userManager,
		private IL10N $l10n,
	) {
	}

	/** @return Assignment[] */
	public function findOwn(): array {
		return $this->assignments->findByMember($this->requireMemberId());
	}

	/**
	 * Vorschau vor dem Speichern (Spec §3.4 Pflicht-UI) – validiert wie
	 * {@see apply()}, mutiert aber nichts.
	 *
	 * @throws \InvalidArgumentException bei ungültigen Werten
	 * @throws DoesNotExistException wenn die Zuweisung nicht existiert oder nicht dem eigenen Mitglied gehört
	 */
	public function preview(int $assignmentId, ?int $monthlyAmountCents, ?int $intervalMonths): array {
		$this->assertOwnership($assignmentId);
		return $this->assignments->previewChange($assignmentId, $monthlyAmountCents, $intervalMonths);
	}

	/**
	 * Betrag und/oder Turnus der eigenen Zuweisung ändern – sofort wirksam
	 * (Spec §3.4 Leitsatz „Vertrauensraum, kein Antragsmodell"). Löst danach
	 * Quittungsmail + Activity-Feed-Eintrag je tatsächlich geändertem Feld
	 * aus (Spec §3.4 „Quittungsmail an das Mitglied, immer" + „OCP\Activity-
	 * Feed-Eintrag je Änderung").
	 *
	 * @return array{assignment: Assignment, preview: array}
	 * @throws \InvalidArgumentException bei ungültigen Werten
	 * @throws DoesNotExistException wenn die Zuweisung nicht existiert oder nicht dem eigenen Mitglied gehört
	 */
	public function apply(int $assignmentId, ?int $monthlyAmountCents, ?int $intervalMonths): array {
		$assignment = $this->assertOwnership($assignmentId);
		// Erst die Vorschau (validiert Untergrenze/erlaubten Turnus und
		// berechnet "wirkt ab" GENAU wie update() es gleich tun wird) - bei
		// einem Fehler wird gar nichts gespeichert.
		$preview = $this->assignments->previewChange($assignmentId, $monthlyAmountCents, $intervalMonths);

		$oldAmountCents = $assignment->getMonthlyAmountCents();
		$oldIntervalMonths = $assignment->getIntervalMonths();

		$updated = $this->assignments->update(
			$assignmentId,
			$monthlyAmountCents,
			$intervalMonths,
			null, // Beitragsgruppe wechseln ist im Self-Service nicht erlaubt (Spec §3.4 "Darf nicht")
			ActorContextService::TYPE_MEMBER,
			$this->actorContext->actorUid(),
		);

		$this->notify($assignment->getMemberId(), $assignmentId, $oldAmountCents, $monthlyAmountCents, $oldIntervalMonths, $intervalMonths, $preview);

		return ['assignment' => $updated, 'preview' => $preview];
	}

	private function notify(int $memberId, int $assignmentId, int $oldAmountCents, ?int $newAmountCents, int $oldIntervalMonths, ?int $newIntervalMonths, array $preview): void {
		$member = $this->members->find($memberId);
		$ncUserId = $member->getNcUserId();

		$amountChanged = $newAmountCents !== null && $newAmountCents !== $oldAmountCents;
		$intervalChanged = $newIntervalMonths !== null && $newIntervalMonths !== $oldIntervalMonths;

		$whatParts = [];
		if ($amountChanged) {
			$whatParts[] = $this->l10n->t('Ihr Monatsbeitrag wurde von %1$s € auf %2$s € geändert.', [$this->euro($oldAmountCents), $this->euro($newAmountCents)]);
			if ($ncUserId !== null) {
				$this->activity->publish($ncUserId, SelfServiceProvider::SUBJECT_CONTRIBUTION_AMOUNT_CHANGED, [
					'from' => $oldAmountCents, 'to' => $newAmountCents, 'effectiveFrom' => $preview['effectiveFrom'],
				], 'assignment', $assignmentId);
			}
		}
		if ($intervalChanged) {
			$whatParts[] = $this->l10n->t('Ihr Zahlungsturnus wurde von alle %1$d Monate auf alle %2$d Monate geändert.', [$oldIntervalMonths, $newIntervalMonths]);
			if ($ncUserId !== null) {
				$this->activity->publish($ncUserId, SelfServiceProvider::SUBJECT_CONTRIBUTION_INTERVAL_CHANGED, [
					'from' => $oldIntervalMonths, 'to' => $newIntervalMonths, 'effectiveFrom' => $preview['effectiveFrom'],
				], 'assignment', $assignmentId);
			}
		}

		if ($whatParts === []) {
			return; // keine tatsaechliche Aenderung - keine Quittung noetig
		}

		$recipient = $this->resolveRecipient($member);
		if ($recipient !== null) {
			$this->receiptMail->sendReceipt(
				$member,
				$recipient,
				$this->l10n->t('Ihr Beitrag wurde geändert'),
				implode(' ', $whatParts),
				$preview['effectiveFrom'],
				$preview['firstDueDate'],
			);
		}
	}

	/**
	 * Empfänger der Quittungsmail: vorrangig die Mitglieds-Mailadresse,
	 * ersatzweise das verknüpfte NC-Konto (dieselbe Priorität wie
	 * {@see ContributionPreNotificationService::resolveRecipient()}).
	 */
	private function resolveRecipient(Member $member): ?string {
		if ($member->getEmail() !== null && $member->getEmail() !== '') {
			return $member->getEmail();
		}
		$user = $member->getNcUserId() !== null ? $this->userManager->get($member->getNcUserId()) : null;
		$accountEmail = $user?->getEMailAddress();
		return ($user !== null && $accountEmail !== null && $accountEmail !== '') ? $accountEmail : null;
	}

	private function euro(int $cents): string {
		return number_format($cents / 100, 2, ',', '.');
	}

	private function requireMemberId(): int {
		$memberId = $this->actorContext->memberId();
		if ($memberId === null) {
			// Die PermissionMiddleware laesst diesen Fall in der Praxis nie bis
			// hierher durch - zweite Verteidigungslinie wie SelfController::me().
			throw new ForbiddenException($this->l10n->t('Kein Self-Service-Zugang.'));
		}
		return $memberId;
	}

	/**
	 * IDOR-Schutz: liest die Zuweisung und prüft, dass sie dem AUFRUFENDEN
	 * Mitglied gehört (member_id ausschließlich aus ActorContextService, nie
	 * aus $assignmentId ableitbar). Eine fremde, aber existierende ID meldet
	 * bewusst denselben Fehler wie eine nicht existierende - sonst ließe sich
	 * per Fehlerunterschied erraten, welche IDs anderen Mitgliedern gehören.
	 *
	 * @throws DoesNotExistException wenn die Zuweisung nicht existiert oder nicht dem eigenen Mitglied gehört
	 */
	private function assertOwnership(int $assignmentId): Assignment {
		$memberId = $this->requireMemberId();
		$assignment = $this->assignments->find($assignmentId);
		if ($assignment->getMemberId() !== $memberId) {
			throw new DoesNotExistException('Zuweisung nicht gefunden');
		}
		return $assignment;
	}
}
