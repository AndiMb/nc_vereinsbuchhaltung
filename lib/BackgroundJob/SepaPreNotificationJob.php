<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\BackgroundJob;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\SepaNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Verschickt täglich die SEPA-Vorankündigung für Sammeleinzüge, deren
 * Fälligkeit in genau 14 Tagen liegt (siehe {@see SepaNotificationService}).
 * Läuft leer durch, solange niemand SEPA-Mandate nutzt.
 */
class SepaPreNotificationJob extends TimedJob {

	private const LEAD_DAYS = 14;

	public function __construct(
		ITimeFactory $time,
		private SepaNotificationService $notifications,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$targetDate = (new \DateTime())->modify('+' . self::LEAD_DAYS . ' days')->format('Y-m-d');
		try {
			$count = $this->notifications->sendDueNotifications($targetDate);
		} catch (\Throwable $e) {
			$this->logger->error('SEPA-Vorankündigung-Lauf abgebrochen', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return;
		}
		if ($count > 0) {
			$this->logger->info('SEPA-Vorankündigung: {count} Mails verschickt', [
				'app' => Application::APP_ID,
				'count' => $count,
			]);
		}
	}
}
