<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\BackgroundJob;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\MembershipFeeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Erzeugt täglich offene Posten für fällige Mitgliedsbeiträge (siehe
 * {@see MembershipFeeService::generateDueOpenItems()}). Läuft leer durch,
 * solange niemand einen Beitrag mit Zahlungsfrequenz angelegt hat – reines
 * Zusatzmodul, siehe Migration 000125.
 */
class MembershipFeeDueJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private MembershipFeeService $fees,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		try {
			$count = $this->fees->generateDueOpenItems();
		} catch (\Throwable $e) {
			$this->logger->error('Beitragsfälligkeiten-Lauf abgebrochen', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return;
		}
		if ($count > 0) {
			$this->logger->info('Beitragsfälligkeiten: {count} offene Posten erzeugt', [
				'app' => Application::APP_ID,
				'count' => $count,
			]);
		}
	}
}
