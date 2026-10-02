<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Mandate;

/**
 * 36-Monats-Verfall (Spec §2.2 „36-Monats-Verfall", Compliance-Anhang §8:
 * „36 Monate ab Due Date der letzten vorgelegten … Lastschrift, reine
 * Gläubigerpflicht"). Reine Rechenklasse ohne Datenbankzugriff: nimmt ein
 * bereits geladenes {@see Mandate} und ein Stichdatum entgegen, damit sich
 * sowohl die Formel als auch die Cron-Entscheidung ohne laufende Instanz per
 * PHPUnit prüfen lassen (siehe {@see MandateStateMachine} für dieselbe
 * Begründung).
 *
 * Vorwarnung: 180 Tage vor Ablauf (Spec §7 Aufgaben-Katalog). Bewusst als
 * Konstante statt Einstellung – die Spec sieht `expiry_warning_days` zwar im
 * vollständigen Einstellungskatalog (§4) vor, aber ohne dieses Ticket (#66)
 * als Quelle zu benennen; die zugehörige Aufgaben-Oberfläche ist laut Spec §7
 * ohnehin „abgeleitete Abfrage, kein Job – keine Entity" und Teil eines
 * eigenen, modulübergreifenden Aufgaben-Tickets.
 */
class MandateExpiryCalculator {

	public const WARNING_DAYS = 180;

	/**
	 * `expires_at = COALESCE(last_presented_due_date, signed_at) + 36 Monate`.
	 * Null, wenn keines der beiden Daten bekannt ist (Entwurf ohne
	 * Unterschriftsdatum) – ein solches Mandat hat noch keine laufende Frist.
	 */
	public function expiresAt(Mandate $mandate): ?\DateTimeImmutable {
		$base = $mandate->getLastPresentedDueDate() ?? $mandate->getSignedAt();
		if ($base === null || trim($base) === '') {
			return null;
		}
		try {
			return (new \DateTimeImmutable($base))->modify('+' . Mandate::EXPIRY_MONTHS . ' months');
		} catch (\Exception) {
			return null;
		}
	}

	/**
	 * Verfall betrifft nur noch lebende, tatsächlich vorgelegte Mandate
	 * (`aktiv`/`ausgesetzt`) – ein Entwurf wurde nie zum Einzug vorgelegt und
	 * ist ohnehin nicht einzugsfähig, ein bereits erloschenes Mandat ist schon
	 * terminal.
	 */
	public function isDueForExpiry(Mandate $mandate, \DateTimeImmutable $today): bool {
		if (!in_array($mandate->getStatus(), [Mandate::STATUS_ACTIVE, Mandate::STATUS_SUSPENDED], true)) {
			return false;
		}
		$expiresAt = $this->expiresAt($mandate);
		return $expiresAt !== null && $expiresAt <= $today;
	}

	/** Vorwarnung „Mandat läuft in 180 Tagen ab" (Spec §7) – noch nicht verfallen, aber innerhalb des Warnfensters. */
	public function needsExpiryWarning(Mandate $mandate, \DateTimeImmutable $today): bool {
		if ($this->isDueForExpiry($mandate, $today)) {
			return false;
		}
		if (!in_array($mandate->getStatus(), [Mandate::STATUS_ACTIVE, Mandate::STATUS_SUSPENDED], true)) {
			return false;
		}
		$expiresAt = $this->expiresAt($mandate);
		if ($expiresAt === null) {
			return false;
		}
		return $expiresAt <= $today->modify('+' . self::WARNING_DAYS . ' days');
	}
}
