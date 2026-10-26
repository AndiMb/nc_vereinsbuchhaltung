<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\BackgroundJob;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\InvoicePlanService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Stündlich: legt für fällige Pläne die wiederkehrenden Rechnungen an
 * (Rechnungsmodul, Stufe 3). Ein Termin wird nie zweimal bedient, denn der
 * Plan rückt vor, sobald er eine Rechnung angelegt hat.
 */
class InvoicePlanJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private InvoicePlanService $plans,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		try {
			$result = $this->plans->runDue();
			if ($result['created'] + $result['failed'] > 0) {
				$this->logger->info('Wiederkehrende Rechnungen: {created} angelegt, {issued} festgeschrieben, {sent} verschickt, {failed} mit Fehler', ['app' => Application::APP_ID] + $result);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Lauf der wiederkehrenden Rechnungen abgebrochen', ['app' => Application::APP_ID, 'exception' => $e]);
		}
	}
}
