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
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Db\YearCloseMapper;

class ResetService {

	public function __construct(
		private TransactionRunner $transaction,
		private JournalLineMapper $lineMapper,
		private JournalMapper $journalMapper,
		private BankTransactionMapper $txMapper,
		private ImportLogMapper $importMapper,
		private RuleMapper $ruleMapper,
		private AccountMapper $accountMapper,
		private CostCenterMapper $costCenterMapper,
		private BudgetMapper $budgetMapper,
		private BudgetSnapshotService $snapshotService,
		private AttachmentMapper $attachmentMapper,
		private AttachmentStorageService $storageService,
		private YearCloseMapper $yearCloseMapper,
		private OpenItemMapper $openItemMapper,
	) {
	}

	/**
	 * Löscht den gesamten Buchungsbestand.
	 *
	 * Die Datenbank-Anteile laufen in einer Transaktion – ein Abbruch mittendrin
	 * hinterließe sonst einen halb gelöschten Bestand (z.B. Journalzeilen ohne
	 * Konten), aus dem heraus sich weder sinnvoll weiterarbeiten noch sauber
	 * neu importieren lässt.
	 *
	 * Die Dateien der Belegablage liegen außerhalb der Datenbank und werden
	 * daher erst nach dem erfolgreichen Commit entfernt: bricht die
	 * Datenbank-Seite ab, sind die Dateien noch da und passen weiter zu den
	 * erhaltenen Datensätzen.
	 */
	public function resetAll(string $userId): void {
		// Vor dem Löschen der Datensätze merken, welche Dateien dazugehören –
		// danach ist die Zuordnung weg.
		$attachments = $this->attachmentMapper->findAllForUser($userId);

		$this->transaction->run(function () use ($userId): void {
			$this->attachmentMapper->deleteAllForUser($userId);

			$this->lineMapper->deleteAllForUser($userId);
			$this->journalMapper->deleteAllForUser($userId);
			$this->txMapper->deleteAllForUser($userId);
			$this->importMapper->deleteAllForUser($userId);
			$this->ruleMapper->deleteAllForUser($userId);
			$this->accountMapper->deleteAllForUser($userId);
			$this->costCenterMapper->deleteAllForUser($userId);
			$this->budgetMapper->deleteAllForUser($userId);
			$this->snapshotService->deleteAllForUser($userId);
			// Offene Posten enthalten Namen von Mitgliedern und Forderungsbeträge –
			// sie müssen beim Zurücksetzen mit verschwinden, sonst bleiben
			// personenbezogene Daten mit Verweisen auf gelöschte Konten zurück.
			$this->openItemMapper->deleteAll();
			// Abschluss-Marker gehören zum Datenbestand; das Änderungsprotokoll
			// bleibt bewusst erhalten (der Reset selbst wird protokolliert).
			$this->yearCloseMapper->deleteAll();
		});

		$this->storageService->deleteAllFiles($attachments);
	}
}
