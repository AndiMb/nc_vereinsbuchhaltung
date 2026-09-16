<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\BackgroundJob;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\ClaimGenerationService;
use OCA\Vereinsbuchhaltung\Service\ContributionPreNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Der tägliche Einzugszyklus-Cron für das neue Assignment/Claim-Modell (Spec
 * §3.5/§7, Issue #70) – Nachfolger von {@see SepaPreNotificationJob} für den
 * alten `vbh_sepa_batches`-Zyklus, der unverändert weiterläuft (Spec §1.2).
 *
 * Zwei Schritte, in dieser Reihenfolge, weil eine gerade erst erzeugte
 * Periode noch am selben Tag ins Vorabinfo-Fenster fallen kann (kurzer
 * Turnus + knapp bemessene Vorlauffrist):
 *
 * 1. {@see ClaimGenerationService::generateDue()} – Forderungen anlegen,
 *    sobald ihr Einzugstermin ins Vorwarnfenster (D−21) fällt.
 * 2. {@see ContributionPreNotificationService::sendDue()} – Vorabinfo-Mails
 *    für alles, dessen Vorlauffrist (D−14) erreicht ist.
 *
 * Beide Schritte sind für sich idempotent (siehe dortige Klassendocs); ein
 * Fehler in Schritt 1 darf Schritt 2 nicht verhindern – bereits vorhandene
 * Forderungen sollen ihre Vorabinfo trotzdem bekommen.
 *
 * Die D−21-Vorwarn-Aufgabe und alle Störfälle sind bewusst NICHT Teil dieses
 * Jobs: das sind abgeleitete Abfragen ohne eigene Persistenz (Spec §7), siehe
 * {@see \OCA\Vereinsbuchhaltung\Service\ContributionCycleTaskService}.
 */
class ContributionDueCycleJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private ClaimGenerationService $claimGeneration,
		private ContributionPreNotificationService $preNotification,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		try {
			$generated = $this->claimGeneration->generateDue();
		} catch (\Throwable $e) {
			$generated = ['created' => 0, 'blocked' => 0];
			$this->logger->error('Einzugszyklus: Forderungserzeugung abgebrochen', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}

		try {
			$notified = $this->preNotification->sendDue();
		} catch (\Throwable $e) {
			$this->logger->error('Einzugszyklus: Vorabinfo-Versand abgebrochen', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return;
		}

		if ($generated['created'] > 0 || $generated['blocked'] > 0 || array_sum($notified) > 0) {
			$this->logger->info('Einzugszyklus: {created} Forderung(en) erzeugt, {blocked} durch fehlendes Mandat blockiert, Vorabinfo an {sent} Mitglied(er) verschickt ({skipped} ohne Empfänger, {failed} fehlgeschlagen)', [
				'app' => Application::APP_ID,
				'created' => $generated['created'],
				'blocked' => $generated['blocked'],
				'sent' => $notified['sent'],
				'skipped' => $notified['skipped'],
				'failed' => $notified['failed'],
			]);
		}
	}
}
