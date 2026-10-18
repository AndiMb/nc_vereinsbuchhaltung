<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Activity\SelfServiceProvider;
use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Beitrags-Aktionen im Self-Service (Spec §3.4 Aktionskatalog, Issue #76):
 * Betrag ändern (hoch frei, runter bis Untergrenze), Turnus wechseln (aus
 * `allowed_intervals`) und – wenn der Verein es freigeschaltet hat
 * ({@see SelfServiceService::canSwitchToTransfer()}) – die Zahlungsart von
 * Lastschrift auf Überweisung umstellen (nur in diese Richtung: zurück zur
 * Lastschrift braucht ein einzugsfähiges Mandat und bleibt beim Vorstand;
 * das Mandat selbst bleibt bei der Umstellung unberührt und wird nur nicht
 * mehr genutzt) – NICHT Beitragsgruppe wechseln, „diesen Monat mal
 * nicht abbuchen", Austritt, Erledigungsvermerk oder individuelle
 * Untergrenze (siehe Aktionskatalog „Darf nicht").
 *
 * Baut bewusst NICHT neu, was {@see AssignmentService} (Validierung,
 * Wirksamkeitsregel) und {@see EffectivityRuleService} (Sperrfenster) schon
 * können – diese Klasse ist nur die Self-Service-Hülle darum: IDOR-Schutz
 * (jede Methode löst `member_id` ausschließlich über
 * {@see ActorContextService} auf, NIE aus einem Parameter – siehe
 * {@see requireMemberId()}/{@see assertOwnership()}), Sperrfenster-Ablehnung
 * ({@see assertNotLocked()} – Issue #76 GitHub-Akzeptanzkriterium: „Änderung
 * wird abgelehnt mit Erklärung", nicht nur stillschweigend verschoben),
 * Quittungsmail ({@see SelfServiceReceiptMailService}) und
 * Activity-Feed-Eintrag ({@see SelfServiceActivityPublisher}) je tatsächlich
 * geänderten Feld.
 */
class SelfContributionService {

	public function __construct(
		private ActorContextService $actorContext,
		private AssignmentService $assignments,
		private SelfServiceService $selfService,
		private MemberMapper $members,
		private SelfServiceReceiptMailService $receiptMail,
		private SelfServiceActivityPublisher $activity,
		private IUserManager $userManager,
		private ITimeFactory $time,
		private IL10N $l10n,
	) {
	}

	/** @return Assignment[] */
	public function findOwn(): array {
		return $this->assignments->findByMember($this->requireMemberId());
	}

	/**
	 * Vorschau vor dem Speichern (Spec §3.4 Pflicht-UI) – validiert wie
	 * {@see apply()}, mutiert aber nichts. Lehnt wie apply() ab, wenn die
	 * Änderung gesperrt ist (siehe {@see assertNotLocked()}) - die Vorschau
	 * soll dieselbe Erklärung zeigen, die auch das Speichern verweigern würde,
	 * statt einen Erfolg vorzutäuschen, der beim Speichern dann doch scheitert.
	 *
	 * @throws \InvalidArgumentException bei ungültigen Werten oder gesperrter Änderung
	 * @throws DoesNotExistException wenn die Zuweisung nicht existiert oder nicht dem eigenen Mitglied gehört
	 */
	public function preview(int $assignmentId, ?int $monthlyAmountCents, ?int $intervalMonths, ?string $paymentMethod = null): array {
		$assignment = $this->assertOwnership($assignmentId);
		$this->assertPaymentMethodAllowed($assignment, $paymentMethod);
		$preview = $this->assignments->previewChange($assignmentId, $monthlyAmountCents, $intervalMonths, null, $paymentMethod);
		$this->assertNotLocked($preview);
		return $preview;
	}

	/**
	 * Betrag und/oder Turnus der eigenen Zuweisung ändern – sofort wirksam
	 * (Spec §3.4 Leitsatz „Vertrauensraum, kein Antragsmodell"), aber NICHT
	 * während des Sperrfensters (siehe {@see assertNotLocked()}). Löst danach
	 * Quittungsmail + Activity-Feed-Eintrag je tatsächlich geändertem Feld
	 * aus (Spec §3.4 „Quittungsmail an das Mitglied, immer" + „OCP\Activity-
	 * Feed-Eintrag je Änderung").
	 *
	 * @return array{assignment: Assignment, preview: array}
	 * @throws \InvalidArgumentException bei ungültigen Werten oder gesperrter Änderung
	 * @throws DoesNotExistException wenn die Zuweisung nicht existiert oder nicht dem eigenen Mitglied gehört
	 */
	public function apply(int $assignmentId, ?int $monthlyAmountCents, ?int $intervalMonths, ?string $paymentMethod = null): array {
		$assignment = $this->assertOwnership($assignmentId);
		$this->assertPaymentMethodAllowed($assignment, $paymentMethod);
		// Erst die Vorschau (validiert Untergrenze/erlaubten Turnus und
		// berechnet "wirkt ab" GENAU wie update() es gleich tun wird) - bei
		// einem Fehler wird gar nichts gespeichert.
		$preview = $this->assignments->previewChange($assignmentId, $monthlyAmountCents, $intervalMonths, null, $paymentMethod);
		$this->assertNotLocked($preview);

		$oldAmountCents = $assignment->getMonthlyAmountCents();
		$oldIntervalMonths = $assignment->getIntervalMonths();
		$oldPaymentMethod = $assignment->getPaymentMethod();

		$updated = $this->assignments->update(
			$assignmentId,
			$monthlyAmountCents,
			$intervalMonths,
			null, // Beitragsgruppe wechseln ist im Self-Service nicht erlaubt (Spec §3.4 "Darf nicht")
			ActorContextService::TYPE_MEMBER,
			$this->actorContext->actorUid(),
			null,
			$paymentMethod,
		);

		$this->notify($assignment->getMemberId(), $assignmentId, $oldAmountCents, $monthlyAmountCents, $oldIntervalMonths, $intervalMonths, $preview, $oldPaymentMethod, $paymentMethod);

		return ['assignment' => $updated, 'preview' => $preview];
	}

	private function notify(int $memberId, int $assignmentId, int $oldAmountCents, ?int $newAmountCents, int $oldIntervalMonths, ?int $newIntervalMonths, array $preview, ?string $oldPaymentMethod = null, ?string $newPaymentMethod = null): void {
		$member = $this->members->find($memberId);
		$ncUserId = $member->getNcUserId();

		$amountChanged = $newAmountCents !== null && $newAmountCents !== $oldAmountCents;
		$intervalChanged = $newIntervalMonths !== null && $newIntervalMonths !== $oldIntervalMonths;
		$paymentMethodChanged = $newPaymentMethod !== null && $newPaymentMethod !== $oldPaymentMethod;

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

		if ($paymentMethodChanged) {
			$whatParts[] = $this->l10n->t('Ihre Zahlungsart wurde auf Überweisung umgestellt: Ihr Beitrag wird nicht mehr per Lastschrift eingezogen, bitte überweisen Sie ihn künftig selbst. Ihr SEPA-Lastschriftmandat bleibt gültig, wird aber nicht mehr genutzt.');
			if ($ncUserId !== null) {
				$this->activity->publish($ncUserId, SelfServiceProvider::SUBJECT_CONTRIBUTION_PAYMENT_METHOD_CHANGED, [
					'from' => $oldPaymentMethod, 'to' => $newPaymentMethod,
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
				null,
				null,
				// Per Überweisung ist der Termin eine Fälligkeit, kein Einzug – die Mail sagt es so.
				($newPaymentMethod ?? $oldPaymentMethod) === Assignment::PAYMENT_METHOD_TRANSFER,
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

	/**
	 * Sperrfenster-Ablehnung (Spec §3.4 „Betrag/Turnus gesperrt sobald für die
	 * Periode `prenotified_at` gesetzt ist", GitHub-Akzeptanzkriterium Issue
	 * #76: „Änderung wird abgelehnt mit Erklärung"). Self-Service ist
	 * „sofort wirksam" (Spec-Leitsatz) - eine Änderung, die laut
	 * {@see EffectivityRuleService} erst SPÄTER als heute wirken dürfte, ist im
	 * Self-Service deshalb keine zulässige Aktion, sondern wird komplett
	 * abgelehnt, statt sie stillschweigend auf später zu verschieben (das
	 * bleibt der Admin-Akte über {@see AssignmentController::update()}
	 * vorbehalten, die dieselbe Wirksamkeitsregel unverändert nur protokolliert).
	 *
	 * @param array{effectiveFrom:string} $preview
	 * @throws \InvalidArgumentException wenn die Änderung gesperrt ist
	 */
	private function assertNotLocked(array $preview): void {
		if ($preview['effectiveFrom'] > $this->today()) {
			throw new \InvalidArgumentException($this->l10n->t(
				'Für die laufende Periode wurde bereits eine Vorabinfo verschickt – Betrag und Turnus stehen bis zum Einzug fest. Möglich wäre diese Änderung erst ab %s.',
				[GermanDate::format($preview['effectiveFrom'])],
			));
		}
	}

	/**
	 * Zahlungsart im Self-Service: nur von Lastschrift AUF Überweisung, und nur,
	 * wenn der Verein das freigeschaltet hat. Die Gegenrichtung (zurück zur
	 * Lastschrift) braucht ein einzugsfähiges Mandat und bleibt beim Vorstand
	 * (Spec §3.4 „Darf nicht"). Eine unveränderte Zahlungsart ist immer
	 * erlaubt – die SPA schickt den aktuellen Wert mit.
	 *
	 * @throws \InvalidArgumentException wenn die Umstellung nicht zulässig ist
	 */
	private function assertPaymentMethodAllowed(Assignment $assignment, ?string $paymentMethod): void {
		if ($paymentMethod === null || $paymentMethod === $assignment->getPaymentMethod()) {
			return;
		}
		if ($paymentMethod !== Assignment::PAYMENT_METHOD_TRANSFER) {
			throw new \InvalidArgumentException($this->l10n->t('Im Self-Service lässt sich die Zahlungsart nur auf Überweisung umstellen. Für die Rückkehr zur Lastschrift wenden Sie sich an Ihren Verein.'));
		}
		if (!$this->selfService->canSwitchToTransfer()) {
			throw new \InvalidArgumentException($this->l10n->t('Die Umstellung auf Überweisung ist im Self-Service nicht freigeschaltet. Wenden Sie sich an Ihren Verein.'));
		}
	}

	private function today(): string {
		return $this->time->getDateTime()->format('Y-m-d');
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
