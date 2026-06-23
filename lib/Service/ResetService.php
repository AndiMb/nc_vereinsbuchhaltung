<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\CostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\ImportLogMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;

/**
 * Setzt alle Buchhaltungsdaten eines Nutzers zurück ("frisch starten").
 */
class ResetService {

	public function __construct(
		private JournalLineMapper $lineMapper,
		private JournalMapper $journalMapper,
		private BankTransactionMapper $txMapper,
		private ImportLogMapper $importMapper,
		private RuleMapper $ruleMapper,
		private AccountMapper $accountMapper,
		private CostCenterMapper $costCenterMapper,
	) {
	}

	public function resetAll(string $userId): void {
		$this->lineMapper->deleteAllForUser($userId);
		$this->journalMapper->deleteAllForUser($userId);
		$this->txMapper->deleteAllForUser($userId);
		$this->importMapper->deleteAllForUser($userId);
		$this->ruleMapper->deleteAllForUser($userId);
		$this->accountMapper->deleteAllForUser($userId);
		$this->costCenterMapper->deleteAllForUser($userId);
	}
}
