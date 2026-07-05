<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\CostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\ImportLogMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;

class ResetService {

	public function __construct(
		private JournalLineMapper $lineMapper,
		private JournalMapper $journalMapper,
		private BankTransactionMapper $txMapper,
		private ImportLogMapper $importMapper,
		private RuleMapper $ruleMapper,
		private AccountMapper $accountMapper,
		private CostCenterMapper $costCenterMapper,
		private BudgetMapper $budgetMapper,
		private AttachmentMapper $attachmentMapper,
		private AttachmentStorageService $storageService,
	) {
	}

	public function resetAll(string $userId): void {
		$this->storageService->deleteAllFiles();
		$this->attachmentMapper->deleteAllForUser($userId);

		$this->lineMapper->deleteAllForUser($userId);
		$this->journalMapper->deleteAllForUser($userId);
		$this->txMapper->deleteAllForUser($userId);
		$this->importMapper->deleteAllForUser($userId);
		$this->ruleMapper->deleteAllForUser($userId);
		$this->accountMapper->deleteAllForUser($userId);
		$this->costCenterMapper->deleteAllForUser($userId);
		$this->budgetMapper->deleteAllForUser($userId);
	}
}
