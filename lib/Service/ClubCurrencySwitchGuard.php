<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\DebitBatchMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCP\IL10N;

/**
 * Prüft eine Änderung der Vereinswährung (Issue #202).
 *
 * SEPA-Lastschrift gibt es nur in Euro. Weg von Euro geht es deshalb erst,
 * wenn kein Mandat mehr lebt (Entwurf, aktiv, ausgesetzt) und kein
 * freigegebener Sammeleinzug auf die Einreichung wartet – sonst blieben sie
 * halbfertig und unbedienbar zurück. Ebenso keine laufende Zuweisung mit
 * Zahlungsart Lastschrift: Sie erzeugte ohne Euro keine Forderungen mehr.
 * Zurück auf Euro geht immer.
 */
class ClubCurrencySwitchGuard {
	public function __construct(
		private ClubCurrency $currency,
		private MandateMapper $mandates,
		private DebitBatchMapper $batches,
		private AssignmentMapper $assignments,
		private ClubClock $clock,
		private IL10N $l10n,
	) {
	}

	/** @return string|null Fehlermeldung oder null, wenn die Änderung erlaubt ist */
	public function error(string $newCode): ?string {
		if (!ClubCurrency::isSupported($newCode)) {
			return $this->l10n->t('Unbekannte Vereinswährung.');
		}
		if ($newCode === 'EUR' || !$this->currency->isEuro()) {
			return null;
		}
		$liveMandates = $this->mandates->countNotEnded();
		if ($liveMandates > 0) {
			return $this->l10n->t('Die Vereinswährung lässt sich erst umstellen, wenn kein SEPA-Mandat mehr aktiv ist (%s offen). Mandate beenden und es erneut versuchen.', [(string)$liveMandates]);
		}
		if ($this->batches->findReleasedNotSubmitted() !== []) {
			return $this->l10n->t('Die Vereinswährung lässt sich erst umstellen, wenn kein freigegebener Sammeleinzug mehr offen ist. Ihn einreichen oder verwerfen und es erneut versuchen.');
		}
		$directDebit = $this->assignments->countDirectDebitNotEndedAsOf($this->clock->today());
		if ($directDebit > 0) {
			return $this->l10n->t('Die Vereinswährung lässt sich erst umstellen, wenn keine Beitragszuweisung mehr per Lastschrift läuft (%s offen). Diese Zuweisungen auf Überweisung umstellen und es erneut versuchen.', [(string)$directDebit]);
		}
		return null;
	}
}
