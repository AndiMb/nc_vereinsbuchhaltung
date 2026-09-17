<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\IConfig;

/**
 * Verwalter-Einstellungen der Rücklastschrift-Verbuchung (Spec §3.9/§3.10,
 * Issue #72), nach demselben schlanken Muster wie
 * {@see \OCA\Vereinsbuchhaltung\Service\SepaDebtorAccountService}.
 *
 * `returnFeeAccountId` ist die von der Spec ausdrücklich verlangte
 * Einstellung ("konfigurierbares Rücklastschriftgebühren-Konto"). Dasselbe
 * Konto dient zugleich als Erlöskonto der optionalen Gebühren-
 * Weiterbelastung ans Mitglied (Spec §3.6: "bucht bei ihrem eigenen
 * Zahlungseingang") - ein Wash zwischen der Bankgebühr (Aufwand auf diesem
 * Konto) und ihrer Weiterbelastung (Ertrag auf demselben Konto), ohne eine
 * dritte Einstellung zu brauchen.
 *
 * `contributionDefaultAccountId` ist eine zusätzliche, in Issue #72 selbst
 * nicht explizit geforderte Einstellung: automatisch erzeugte Beitrags-
 * forderungen ({@see \OCA\Vereinsbuchhaltung\Service\ClaimGenerationService::createClaim()})
 * tragen bislang KEIN `account_id` (weder an der Forderung noch an der
 * Beitragsgruppe) - ohne einen Rückfall ließe sich weder die Sammelbuchung
 * des Einzugs ("gruppiert nach account_id") noch die Rücklastschrift-Buchung
 * ("zurück auf das ursprüngliche Erlöskonto") für den praktischen Regelfall
 * überhaupt durchführen. Diese Einstellung schließt genau diese Lücke,
 * bewusst nur als Rückfall - eine an der Forderung selbst gesetzte
 * `account_id` hat immer Vorrang.
 */
class SepaImportSettingsService {

	private const SETTING_RETURN_FEE_ACCOUNT = 'sepa_return_fee_account_id';
	private const SETTING_RETURN_FEE_RECHARGE_ENABLED = 'sepa_return_fee_recharge_enabled';
	private const SETTING_CONTRIBUTION_DEFAULT_ACCOUNT = 'sepa_contribution_default_account_id';

	public function __construct(
		private IConfig $config,
	) {
	}

	public function returnFeeAccountId(): ?int {
		return (int)$this->config->getAppValue(Application::APP_ID, self::SETTING_RETURN_FEE_ACCOUNT, '0') ?: null;
	}

	public function setReturnFeeAccountId(?int $accountId): void {
		if ($accountId === null) {
			$this->config->deleteAppValue(Application::APP_ID, self::SETTING_RETURN_FEE_ACCOUNT);
			return;
		}
		$this->config->setAppValue(Application::APP_ID, self::SETTING_RETURN_FEE_ACCOUNT, (string)$accountId);
	}

	/** Opt-in, Default aus (Spec §3.6/§5). */
	public function isReturnFeeRechargeEnabled(): bool {
		return $this->config->getAppValue(Application::APP_ID, self::SETTING_RETURN_FEE_RECHARGE_ENABLED, '0') === '1';
	}

	public function setReturnFeeRechargeEnabled(bool $enabled): void {
		$this->config->setAppValue(Application::APP_ID, self::SETTING_RETURN_FEE_RECHARGE_ENABLED, $enabled ? '1' : '0');
	}

	public function contributionDefaultAccountId(): ?int {
		return (int)$this->config->getAppValue(Application::APP_ID, self::SETTING_CONTRIBUTION_DEFAULT_ACCOUNT, '0') ?: null;
	}

	public function setContributionDefaultAccountId(?int $accountId): void {
		if ($accountId === null) {
			$this->config->deleteAppValue(Application::APP_ID, self::SETTING_CONTRIBUTION_DEFAULT_ACCOUNT);
			return;
		}
		$this->config->setAppValue(Application::APP_ID, self::SETTING_CONTRIBUTION_DEFAULT_ACCOUNT, (string)$accountId);
	}
}
