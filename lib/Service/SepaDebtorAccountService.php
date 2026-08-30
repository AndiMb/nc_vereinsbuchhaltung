<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\IConfig;

/**
 * Das einziehende Konto des SEPA-Sammeleinzugs als App-Einstellung.
 *
 * Eigene Klasse, weil diese Einstellung als einzige auf einen Datensatz der
 * App zeigt und mit ihm gepflegt werden muss: verschwindet das Konto, bleibt
 * sonst eine ID stehen, die ins Leere zeigt, und der Sammeleinzug scheitert
 * erst in dem Moment, in dem er gebraucht wird. (storage_user und
 * statement_watch_user verweisen ebenfalls nach außen, dort aber auf
 * Nextcloud-Nutzer, deren Löschung die App nicht mitbekommt.)
 */
class SepaDebtorAccountService {

	private const SETTING = 'sepa_debtor_account_id';

	public function __construct(
		private IConfig $config,
	) {
	}

	public function getAccountId(): ?int {
		return (int)$this->config->getAppValue(Application::APP_ID, self::SETTING, '0') ?: null;
	}

	public function setAccountId(?int $accountId): void {
		if ($accountId === null) {
			$this->config->deleteAppValue(Application::APP_ID, self::SETTING);
			return;
		}
		$this->config->setAppValue(Application::APP_ID, self::SETTING, (string)$accountId);
	}

	public function forgetIfSetTo(int $accountId): void {
		if ($this->getAccountId() === $accountId) {
			$this->setAccountId(null);
		}
	}
}
