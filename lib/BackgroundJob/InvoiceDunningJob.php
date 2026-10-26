<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\BackgroundJob;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoiceDunningService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Täglich: mahnt fällige Rechnungen, wenn der Verein das eingeschaltet hat
 * (Rechnungsmodul, Stufe 3). Ohne die Einstellung tut der Job nichts.
 */
class InvoiceDunningJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private InvoiceDunningService $dunning,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		try {
			$result = $this->dunning->runDaily();
			if ($result['sent'] + $result['failed'] > 0) {
				$this->logger->info('Rechnungen gemahnt: {sent} verschickt, {skipped} ohne Adresse, {failed} fehlgeschlagen', ['app' => Application::APP_ID] + $result);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Mahnlauf für Rechnungen abgebrochen', ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}
}
