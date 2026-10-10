<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCP\IUserSession;

/**
 * Wer handelt gerade – der Kanal entscheidet, nicht die Identität
 * (Personalunion-Regel, Spec §3.4/§3.9): eine Änderung über „Mein Beitrag"
 * ist `actor_type: member`, dieselbe Änderung über die Admin-Akte (auch an
 * der eigenen Akte) ist `actor_type: staff`. Die vbh-Rolle (revisor/
 * buchhalter/verwalter) wird dafür nirgends herangezogen.
 *
 * Die {@see \OCA\Vereinsbuchhaltung\Middleware\PermissionMiddleware} befüllt
 * diesen Kontext einmal pro Request (Sonderfall SelfController → member,
 * jeder andere Endpunkt → staff). `actor_type: system` ist für Cron-Jobs
 * vorgesehen (§2.2 MandateEvent/AssignmentEvent), die nicht über die
 * Middleware laufen und den Kanal selbst setzen.
 *
 * Reines Grundgerüst für Issue #74: `actor_type` wird konkret erst in den
 * Events verwendet, die #66 (Mandats-Lifecycle) und #68 (Beitragsgruppen)
 * einführen. Dieser Dienst stellt nur das Kontext-Objekt bereit, das jene
 * Tickets injizieren können, statt den Kanal jeweils selbst neu herzuleiten.
 *
 * Als geteilter Dienst registriert (siehe Application::register(), Vorbild
 * TransactionRunner/PeriodService): ohne das sähe ein per Konstruktor
 * injizierter Verbraucher nicht den Stand, den die Middleware für denselben
 * Request gesetzt hat, sondern eine frische, unbefüllte Instanz.
 */
class ActorContextService {

	public const TYPE_MEMBER = 'member';
	public const TYPE_STAFF = 'staff';
	public const TYPE_SYSTEM = 'system';

	private string $actorType = self::TYPE_STAFF;

	/** Nur gesetzt bei actor_type=member – siehe memberId(). */
	private ?int $memberId = null;

	public function __construct(
		private IUserSession $userSession,
	) {
	}

	/**
	 * Kanal „Mein Beitrag": die member_id kommt ausschließlich von der
	 * PermissionMiddleware, die sie aus der Kontoverknüpfung aufgelöst hat –
	 * nie aus einem Request-Parameter (IDOR-Schutz, siehe SelfController).
	 */
	public function setMemberChannel(int $memberId): void {
		$this->actorType = self::TYPE_MEMBER;
		$this->memberId = $memberId;
	}

	/** Kanal Admin-Akte / reguläre Buchhaltungs-Endpunkte (Default). */
	public function setStaffChannel(): void {
		$this->actorType = self::TYPE_STAFF;
		$this->memberId = null;
	}

	/** Kanal Cron/Hintergrundjob – läuft nicht über die Middleware. */
	public function setSystemChannel(): void {
		$this->actorType = self::TYPE_SYSTEM;
		$this->memberId = null;
	}

	public function actorType(): string {
		return $this->actorType;
	}

	public function actorUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}

	/** Die member_id, auf die eine Self-Service-Query zwingend filtern muss. Nur bei actor_type=member gesetzt. */
	public function memberId(): ?int {
		return $this->memberId;
	}
}
