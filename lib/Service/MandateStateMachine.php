<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCP\IL10N;

/**
 * Die Zustandsmaschine des Mandats (Spec §2.2 „Zustandsmodell"), als eigene,
 * von {@see MandateMapper} unabhängige Klasse: reine Entscheidungslogik ohne
 * Datenbankzugriff, damit sie sich – wie in diesem Repo üblich (siehe
 * phpunit.xml/tests/bootstrap.php) – ohne laufende Nextcloud-Instanz per
 * PHPUnit prüfen lässt.
 *
 * Jede assert*()-Methode wirft bei einem unzulässigen Übergang; ein
 * erfolgreicher Aufruf sagt nur "der Übergang ist erlaubt", er mutiert das
 * Mandat nicht selbst – das bleibt Sache von
 * {@see \OCA\Vereinsbuchhaltung\Service\MandateService}, die auch die
 * DB-Schreibvorgänge und die Historie (MandateEvent) verantwortet.
 */
class MandateStateMachine {

	public function __construct(
		private IL10N $l10n,
	) {
	}

	/**
	 * Nur ein Entwurf mit `signature_type: papier` und gesetztem `signed_at`
	 * lässt sich aktivieren – „das Unterschriftsdatum *ist* das Gate" (Spec
	 * §2.2). Die elektronische Aktivierung (Selbst-Aktivierung bei Zustimmung)
	 * ist nicht Teil dieses Tickets (#67).
	 *
	 * @throws \InvalidArgumentException
	 */
	public function assertCanActivatePaper(Mandate $mandate): void {
		if ($mandate->getSignatureType() !== Mandate::SIGNATURE_PAPER) {
			throw new \InvalidArgumentException($this->l10n->t('Nur Papier-Mandate lassen sich manuell aktivieren.'));
		}
		if ($mandate->getStatus() !== Mandate::STATUS_DRAFT) {
			throw new \InvalidArgumentException($this->l10n->t('Nur ein Mandat im Entwurf lässt sich aktivieren.'));
		}
		if ($mandate->getSignedAt() === null || trim($mandate->getSignedAt()) === '') {
			throw new \InvalidArgumentException($this->l10n->t('Ohne Unterschriftsdatum lässt sich das Mandat nicht aktivieren.'));
		}
	}

	/**
	 * Der elektronische Aktivierungsweg (Issue #67): kein manuelles Gate, die
	 * Zustimmung selbst *ist* die Unterschrift - anders als beim Papier-Weg
	 * ({@see assertCanActivatePaper()}) wird deshalb KEIN `signed_at` verlangt,
	 * das setzt {@see \OCA\Vereinsbuchhaltung\Service\MandateService::activateElectronic()}
	 * erst mit dem Zustimmungszeitpunkt selbst.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function assertCanActivateElectronic(Mandate $mandate): void {
		if ($mandate->getSignatureType() !== Mandate::SIGNATURE_ELECTRONIC) {
			throw new \InvalidArgumentException($this->l10n->t('Nur elektronische Mandate aktivieren sich per Zustimmung selbst.'));
		}
		if ($mandate->getStatus() !== Mandate::STATUS_DRAFT) {
			throw new \InvalidArgumentException($this->l10n->t('Nur ein Mandat im Entwurf lässt sich aktivieren.'));
		}
	}

	/**
	 * Nur ein aktives Mandat lässt sich aussetzen (Spec §2.2 „Sperre"). Ein
	 * bereits ausgesetztes Mandat ein zweites Mal zu sperren wäre kein
	 * Zustandswechsel und hätte auch keine erkennbare Wirkung.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function assertCanSuspend(Mandate $mandate): void {
		if ($mandate->getStatus() !== Mandate::STATUS_ACTIVE) {
			throw new \InvalidArgumentException($this->l10n->t('Nur ein aktives Mandat lässt sich aussetzen.'));
		}
	}

	/**
	 * Entsperren („Aufheben" der Sperre, Spec §2.2): nur manuell, keine
	 * Auto-Entsperrung – dass die Methode überhaupt aufgerufen wird, ist
	 * bereits der manuelle Akt, sie prüft nur den Ausgangszustand.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function assertCanResume(Mandate $mandate): void {
		if ($mandate->getStatus() !== Mandate::STATUS_SUSPENDED) {
			throw new \InvalidArgumentException($this->l10n->t('Nur ein ausgesetztes Mandat lässt sich entsperren.'));
		}
	}

	/**
	 * Widerruf ist terminal (Spec §2.2 „Widerruf/Austritt") – ein Entwurf hat
	 * noch keine Einzugsermächtigung zu widerrufen (dafür einfach löschen/
	 * verwerfen), ein bereits erloschenes Mandat ist bereits terminal.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function assertCanRevoke(Mandate $mandate): void {
		if (!in_array($mandate->getStatus(), [Mandate::STATUS_ACTIVE, Mandate::STATUS_SUSPENDED], true)) {
			throw new \InvalidArgumentException($this->l10n->t('Nur ein aktives oder ausgesetztes Mandat lässt sich widerrufen.'));
		}
	}

	/**
	 * Ein Mandat, das gerade Kontowechsel-Ziel eines Amendments werden soll
	 * (Spec §2.2 „Amendment vs. neues Mandat"), muss selbst aktiv sein – ein
	 * Amendment auf einen Entwurf oder ein bereits beendetes Mandat ergibt
	 * fachlich keinen Sinn.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function assertCanAmend(Mandate $mandate): void {
		if ($mandate->getStatus() !== Mandate::STATUS_ACTIVE) {
			throw new \InvalidArgumentException($this->l10n->t('Nur ein aktives Mandat lässt sich per Amendment ändern.'));
		}
	}

	/**
	 * Ersetzt-werden (Kontoinhaberwechsel, Spec §2.2) setzt wie ein Amendment
	 * ein aktives Ausgangsmandat voraus.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function assertCanReplace(Mandate $mandate): void {
		if ($mandate->getStatus() !== Mandate::STATUS_ACTIVE) {
			throw new \InvalidArgumentException($this->l10n->t('Nur ein aktives Mandat lässt sich durch ein neues ersetzen.'));
		}
	}

	/**
	 * „höchstens ein lebendes Mandat je Mitglied" (Spec §2.2) – lebend heißt
	 * jeder Zustand außer `erloschen`.
	 *
	 * @param Mandate[] $liveMandates
	 * @throws \InvalidArgumentException
	 */
	public function assertNoLiveMandate(array $liveMandates): void {
		if ($liveMandates !== []) {
			throw new \InvalidArgumentException($this->l10n->t('Dieses Mitglied hat bereits ein lebendes Mandat – höchstens eines ist gleichzeitig erlaubt.'));
		}
	}
}
