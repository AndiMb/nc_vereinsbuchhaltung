<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Activity;

use OCP\Activity\ISetting;
use OCP\IL10N;

/**
 * Benachrichtigungsgerüst für Self-Service-Änderungen (Spec §3.4: „Aufgaben-
 * liste + OCP\Activity-Feed für jede Änderung, Mail-Voreinstellung: an bei
 * Widerruf, aus sonst"). Diese Klasse ist bewusst nur die Registrierung –
 * der Aktionskatalog, der tatsächlich Events erzeugt (Mandat, Beitrag),
 * kommt erst mit #75/#76. Ohne diese Registrierung gäbe es für jene Tickets
 * keinen Einstellungs-Anker in der Aktivitäten-Verwaltung, an den sie ihre
 * künftigen Events hängen könnten.
 */
class SelfServiceSetting implements ISetting {

	/** @see \OCP\Activity\IEvent::setType() – von künftigen Self-Service-Events verwendet. */
	public const TYPE = 'vereinsbuchhaltung_self_service';

	public function __construct(
		private IL10N $l10n,
	) {
	}

	public function getIdentifier(): string {
		return self::TYPE;
	}

	public function getName(): string {
		return $this->l10n->t('Änderungen an meinem Beitrag (Mandat, Beitrag, Stammdaten)');
	}

	public function getPriority(): int {
		return 50;
	}

	public function canChangeStream(): bool {
		return true;
	}

	public function isDefaultEnabledStream(): bool {
		return true;
	}

	public function canChangeMail(): bool {
		return true;
	}

	/**
	 * Konservativer Default aus – die Spec verlangt "an" nur für den Sonderfall
	 * Widerruf, den erst #76 auslösen kann. Ein pauschales "an" für alle
	 * Self-Service-Ereignisse widerspräche dieser Vorgabe.
	 */
	public function isDefaultEnabledMail(): bool {
		return false;
	}
}
