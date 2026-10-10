<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Prüft die beiden Konten-Einstellungen aus {@see SepaImportSettingsService},
 * bevor sie gespeichert werden (Issue #101).
 *
 * Die Einstellungen zeigen auf Datensätze der Buchhaltung und werden erst bei
 * der Verbuchung einer Rücklastschrift bzw. eines Beitragseingangs gebraucht
 * ({@see SepaImportConfirmationService}) – ein falsch gewähltes Konto fiele
 * sonst erst auf, wenn eine Buchung daran scheitert oder auf dem falschen
 * Konto landet. Die Kontoart folgt Spec §3.10: die Rücklastschrift-Gebühr
 * (COAM) geht auf ein Aufwandskonto, die Beitragsforderung auf ein
 * Erlöskonto (Ertrag). Ein Geldkonto ist nie die richtige Wahl.
 */
class SepaSettingsAccountValidator {

	private const TYPE_EXPENSE = 'expense';
	private const TYPE_INCOME = 'income';

	public function __construct(
		private AccountMapper $accounts,
		private IL10N $l10n,
	) {
	}

	/** @return string|null Fehlermeldung oder null, wenn das Konto als Rücklastschriftgebühren-Konto taugt */
	public function returnFeeAccountError(int $accountId): ?string {
		return $this->accountError($accountId, self::TYPE_EXPENSE, $this->l10n->t('Konto für Rücklastschriftgebühren'));
	}

	/** @return string|null Fehlermeldung oder null, wenn das Konto als Standard-Erlöskonto taugt */
	public function contributionRevenueAccountError(int $accountId): ?string {
		return $this->accountError($accountId, self::TYPE_INCOME, $this->l10n->t('Standard-Erlöskonto für Beitragsforderungen'));
	}

	/** @param string $label Bezeichnung der Einstellung im Neutrum („Das …"), für die Meldung */
	private function accountError(int $accountId, string $requiredType, string $label): ?string {
		try {
			$account = $this->accounts->find($accountId, Application::BOOK);
		} catch (DoesNotExistException) {
			return $this->l10n->t('Das gewählte %s wurde nicht gefunden.', [$label]);
		}
		$name = $account->getNumber() . ' ' . $account->getName();
		if ($account->getIsBank()) {
			return $this->l10n->t('Das %1$s darf kein Geldkonto (Bank/Kasse) sein. Konto %2$s ist eines.', [$label, $name]);
		}
		if (!$account->getActive()) {
			return $this->l10n->t('Das %1$s ist inaktiv: Konto %2$s kann nicht verwendet werden.', [$label, $name]);
		}
		if ($account->getType() !== $requiredType) {
			return $this->l10n->t('Das %1$s muss ein %2$s sein. Konto %3$s ist ein %4$s.', [
				$label,
				$this->typeName($requiredType),
				$name,
				$this->typeName($account->getType()),
			]);
		}
		return null;
	}

	private function typeName(string $type): string {
		return match ($type) {
			'income' => $this->l10n->t('Ertragskonto'),
			'expense' => $this->l10n->t('Aufwandskonto'),
			'asset' => $this->l10n->t('Aktivkonto'),
			'liability' => $this->l10n->t('Passivkonto'),
			'equity' => $this->l10n->t('Eigenkapitalkonto'),
			default => $type,
		};
	}
}
