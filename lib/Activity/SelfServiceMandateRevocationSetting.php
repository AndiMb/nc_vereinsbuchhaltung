<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Activity;

use OCP\Activity\ISetting;
use OCP\IL10N;

/**
 * Eigener Activity-Settings-Typ NUR für den Mandats-Widerruf im Self-Service
 * (Spec §3.4 „Mail-Voreinstellung: an bei Widerruf, aus sonst", Issue #75).
 *
 * `ISetting::isDefaultEnabledMail()` gilt für den gesamten Typ, nicht für ein
 * einzelnes Ereignis - ein einziger Self-Service-Settings-Typ könnte deshalb
 * nicht gleichzeitig "aus" für Erteilen/IBAN-Änderung/Kontoinhaberwechsel und
 * "an" für Widerruf sein. Der Widerruf bekommt deshalb {@see SelfServiceProvider::parse()}
 * denselben Provider, aber diesen zweiten, separat konfigurierbaren
 * Einstellungs-Anker (siehe {@see \OCA\Vereinsbuchhaltung\Service\SelfServiceMandateService::publishActivity()},
 * die per `IEvent::setType()` entscheidet, welcher der beiden Typen ein
 * konkretes Ereignis bekommt).
 */
class SelfServiceMandateRevocationSetting implements ISetting {

	public const TYPE = 'vereinsbuchhaltung_self_service_widerruf';

	public function __construct(
		private IL10N $l10n,
	) {
	}

	public function getIdentifier(): string {
		return self::TYPE;
	}

	public function getName(): string {
		return $this->l10n->t('Widerruf eines eigenen SEPA-Lastschriftmandats');
	}

	public function getPriority(): int {
		return 51;
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

	/** Einziger Unterschied zu {@see SelfServiceSetting}: hier "an" (Spec §3.4). */
	public function isDefaultEnabledMail(): bool {
		return true;
	}
}
