<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\BackgroundJob;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\DunningLadderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Der tägliche Mahnstufen-Cron (Spec §3.6/§7 „Mahnstufen (1/2) | täglich",
 * Issue #73): {@see DunningLadderService::runDaily()} deckt sowohl die
 * Eskalation zu Stufe 1/2 als auch die überfälligkeits-basierte Stufe 0 für
 * nicht lastschriftfähige Forderungen ab (Überweiser + mandatlose manuelle
 * Forderungen, siehe dortige Klassendoc). Die ereignisgetriebene Stufe 0
 * (Rücklastschrift/Widerruf, Spec §7 „ereignisgetrieben") läuft NICHT über
 * diesen Job, sondern direkt aus
 * {@see \OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportConfirmationService}/
 * {@see \OCA\Vereinsbuchhaltung\Service\MandateService::revoke()}.
 */
class DunningLadderJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private DunningLadderService $dunningLadder,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		try {
			$result = $this->dunningLadder->runDaily();
		} catch (\Throwable $e) {
			$this->logger->error('Mahnwesen: täglicher Lauf abgebrochen', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return;
		}

		if (array_sum($result) > 0) {
			$this->logger->info('Mahnwesen: {sent} Mitglied(er) benachrichtigt ({skipped} ohne Empfänger/bereits erledigt, {failed} fehlgeschlagen)', [
				'app' => Application::APP_ID,
				'sent' => $result['sent'],
				'skipped' => $result['skipped'],
				'failed' => $result['failed'],
			]);
		}
	}
}
