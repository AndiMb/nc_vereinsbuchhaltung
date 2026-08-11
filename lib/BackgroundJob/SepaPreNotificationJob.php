<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\BackgroundJob;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\SepaNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Verschickt täglich die SEPA-Vorankündigungen, die innerhalb der Vorlaufzeit
 * fällig werden (siehe {@see SepaNotificationService}). Läuft leer durch,
 * solange niemand SEPA-Mandate nutzt.
 *
 * Die Vorlaufzeit steht im Dienst, nicht hier: sie bestimmt zugleich das
 * Fälligkeitsdatum, das der Sammeleinzug vorschlägt, und beide müssen
 * zusammenpassen.
 */
class SepaPreNotificationJob extends TimedJob {

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
		try {
			$ergebnis = $this->notifications->sendDueNotifications();
		} catch (\Throwable $e) {
			$this->logger->error('SEPA-Vorankündigung-Lauf abgebrochen', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return;
		}
		if (array_sum($ergebnis) > 0) {
			$this->logger->info('SEPA-Vorankündigung: {sent} verschickt, {skipped} ohne Mailadresse, {failed} fehlgeschlagen', [
				'app' => Application::APP_ID,
				'sent' => $ergebnis['sent'],
				'skipped' => $ergebnis['skipped'],
				'failed' => $ergebnis['failed'],
			]);
		}
	}
}
