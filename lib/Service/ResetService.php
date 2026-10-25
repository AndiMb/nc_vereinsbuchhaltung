<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetailMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\CostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\IncomingPaymentRejectionMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceDocumentMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceLineMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceMapper;
use OCA\Vereinsbuchhaltung\Db\InvoicePaymentMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\Report\ReportTemplateService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportSettingsService;

class ResetService {

	public function __construct(
		private TransactionRunner $transaction,
		private JournalLineMapper $lineMapper,
		private JournalMapper $journalMapper,
		private BankTransactionMapper $txMapper,
		private RuleMapper $ruleMapper,
		private AccountMapper $accountMapper,
		private CostCenterMapper $costCenterMapper,
		private BudgetMapper $budgetMapper,
		private BudgetSnapshotService $snapshotService,
		private AttachmentMapper $attachmentMapper,
		private AttachmentStorageService $storageService,
		private PeriodService $periods,
		private OpenItemMapper $openItemMapper,
		private SepaDebtorAccountService $sepaDebtorAccount,
		private SepaImportSettingsService $sepaImportSettings,
		private BankTxSepaDetailMapper $sepaDetails,
		private IncomingPaymentRejectionMapper $incomingRejections,
		private ContributionResetService $contribution,
		private ReportTemplateService $reportTemplates,
		private InvoiceMapper $invoiceMapper,
		private InvoiceLineMapper $invoiceLineMapper,
		private InvoiceDocumentMapper $invoiceDocumentMapper,
		private InvoiceSettings $invoiceSettings,
		private InvoicePaymentMapper $invoicePaymentMapper,
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
	 * erhaltenen Datensätzen. Für das einziehende Konto in den Einstellungen
	 * gilt dasselbe – ebenso für das Rücklastschriftgebühren-Konto und das
	 * Standard-Erlöskonto (Issue #101).
	 *
	 * Zum Buchungsbestand gehört auch alles, was an den Forderungen hängt:
	 * Lastschrift-Läufe samt Posten, Rücklastschriften und Mahnstufen (Issue
	 * #123, siehe {@see ContributionResetService}). Mitglieder, Mandate,
	 * Beitragsgruppen, Zuweisungen und die Einstellungen bleiben.
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
			// Die SEPA-Detail-Zeilen und abgelehnten Zahlungseingangs-Vorschläge hängen an den
			// Bankumsätzen (Bankabgleich, Issue #105). Blieben sie liegen, zeigten sie nach
			// einem Neustart des Datenbankservers – der Zähler der Umsatz-IDs beginnt je nach
			// System wieder von vorn – auf fremde, neu importierte Umsätze.
			$this->sepaDetails->deleteAll();
			$this->incomingRejections->deleteAll();
			$this->ruleMapper->deleteAllForUser($userId);
			$this->accountMapper->deleteAllForUser($userId);
			$this->costCenterMapper->deleteAllForUser($userId);
			$this->budgetMapper->deleteAllForUser($userId);
			$this->snapshotService->deleteAllForUser($userId);
			// Vorlagen des Gliederungsberichts verweisen auf Konten und
			// Kostenstellen; ohne sie hätten sie keinen Bezug mehr.
			$this->reportTemplates->deleteAllForUser($userId);
			// Läufe, Posten, Rücklastschriften und Mahnstufen verweisen auf die
			// Forderungen darunter (Issue #123) – sie gehen vor ihnen, und die
			// Zeiger der Mandate auf sie werden im selben Zug gelöst.
			$this->contribution->deleteClaimDependents();
			// Rechnungen verweisen auf Konten, Kostenstellen und offene Posten,
			// die hier verschwinden. Empfänger und Nummernkreise bleiben wie die
			// Mitglieder stehen: Adressen sind Stammdaten, und der Zähler läuft
			// weiter, damit eine bereits verschickte Nummer nicht noch einmal
			// vergeben wird.
			$this->invoicePaymentMapper->deleteAll();
			$this->invoiceDocumentMapper->deleteAll();
			$this->invoiceLineMapper->deleteAll();
			$this->invoiceMapper->deleteAll();
			// Offene Posten enthalten Namen von Mitgliedern und Forderungsbeträge –
			// sie müssen beim Zurücksetzen mit verschwinden, sonst bleiben
			// personenbezogene Daten mit Verweisen auf gelöschte Konten zurück.
			// Die Forderungen des Beitragsmoduls sind Zeilen derselben Tabelle.
			$this->openItemMapper->deleteAll();
			// Die Geschäftsjahre gehören zum Datenbestand und gehen mit; das
			// Änderungsprotokoll bleibt bewusst erhalten (der Reset selbst wird
			// protokolliert). Bewusst als Letztes: bricht der Vorgang vorher ab,
			// zeigt keine Buchung und kein Planwert auf einen Zeitraum, den es
			// nicht mehr gibt.
			$this->periods->deleteAll($userId);

			$this->transaction->afterCommit(function (): void {
				$this->sepaDebtorAccount->setAccountId(null);
				$this->invoiceSettings->setAccountId(null);
				$this->sepaImportSettings->forgetAccounts();
				// Wie in einem frischen Verein: der nächste Abruf legt die
				// Standardvorlagen des Gliederungsberichts wieder an.
				$this->reportTemplates->forgetSeeded();
			});
		});

		$this->storageService->deleteAllFiles($attachments);
	}
}
