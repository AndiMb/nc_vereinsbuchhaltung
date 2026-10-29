<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Welches Geldkonto Rechnungen als Bankverbindung nennen (Issue #215).
 *
 * Reihenfolge: das in den Einstellungen gewählte Konto (`invoice_account_id`),
 * sonst das einziehende Konto des SEPA-Einzugs, sonst das einzige aktive
 * Geldkonto mit IBAN. Damit braucht ein Verein mit einem Girokonto nur die
 * IBAN im Kontenplan – das darf jeder mit Schreibrecht, die Einstellungen nur
 * ein Verwalter. Eine eigene Wahl gilt auch dann, wenn ihr Konto die IBAN
 * verloren hat: dann fehlt die Bankverbindung, statt still auf ein anderes
 * Konto auszuweichen.
 */
class InvoiceBankAccountService {

	/** Behebbar im Kontenplan (IBAN fehlt) bzw. in den Einstellungen (Wahl nötig). */
	public const FIX_ACCOUNT = 'account';
	public const FIX_SETTINGS = 'settings';

	public function __construct(
		private InvoiceSettings $settings,
		private SepaDebtorAccountService $sepaDebtorAccount,
		private AccountMapper $accounts,
	) {
	}

	/** Geldkonto mit IBAN, das die Rechnung nennt; null = keine Bankverbindung. */
	public function resolve(): ?Account {
		return $this->lookup()['account'];
	}

	/**
	 * Wo sich eine fehlende Bankverbindung beheben lässt: `account` mit dem Konto,
	 * an dem die IBAN fehlt (null = mehrere Geldkonten, also der Kontenplan), oder
	 * `settings`, wenn mehrere Konten eine IBAN haben und keines gewählt ist.
	 * null, wenn die Bankverbindung steht.
	 *
	 * @return array{fix: string, accountId: int|null}|null
	 */
	public function fix(): ?array {
		$lookup = $this->lookup();
		return $lookup['account'] === null ? $lookup['fix'] : null;
	}

	/** @return array{account: Account|null, fix: array{fix: string, accountId: int|null}} */
	private function lookup(): array {
		$chosen = $this->bankAccount($this->settings->accountId());
		if ($chosen !== null) {
			return ['account' => $this->hasIban($chosen) ? $chosen : null, 'fix' => ['fix' => self::FIX_ACCOUNT, 'accountId' => (int)$chosen->getId()]];
		}
		$sepa = $this->bankAccount($this->sepaDebtorAccount->getAccountId());
		if ($sepa !== null && $this->hasIban($sepa)) {
			return ['account' => $sepa, 'fix' => ['fix' => self::FIX_ACCOUNT, 'accountId' => (int)$sepa->getId()]];
		}

		$active = array_values(array_filter($this->accounts->findAll(Application::BOOK), static fn (Account $a): bool => $a->getIsBank() && $a->getActive()));
		$withIban = array_values(array_filter($active, fn (Account $a): bool => $this->hasIban($a)));
		if (count($withIban) === 1) {
			return ['account' => $withIban[0], 'fix' => ['fix' => self::FIX_ACCOUNT, 'accountId' => (int)$withIban[0]->getId()]];
		}
		if ($withIban !== []) {
			return ['account' => null, 'fix' => ['fix' => self::FIX_SETTINGS, 'accountId' => null]];
		}
		// Keine IBAN irgendwo: am SEPA-Konto oder am einzigen Geldkonto nachtragen.
		$target = $sepa ?? (count($active) === 1 ? $active[0] : null);
		return ['account' => null, 'fix' => ['fix' => self::FIX_ACCOUNT, 'accountId' => $target !== null ? (int)$target->getId() : null]];
	}

	/** Das Konto, falls es existiert und ein Geldkonto ist. */
	private function bankAccount(?int $id): ?Account {
		if ($id === null) {
			return null;
		}
		try {
			$account = $this->accounts->find($id, Application::BOOK);
		} catch (DoesNotExistException) {
			return null;
		}
		return $account->getIsBank() ? $account : null;
	}

	private function hasIban(Account $account): bool {
		return trim((string)$account->getIban()) !== '';
	}
}
