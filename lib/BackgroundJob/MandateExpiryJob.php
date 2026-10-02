<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\BackgroundJob;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Täglicher 36-Monats-Verfall (Spec §2.2/§7/§8, Issue #66): setzt fällige
 * Mandate automatisch auf `ended`/`verfallen`. Dünner Job, dicke Logik in
 * {@see MandateService::expireDueMandates()} – gleiches Muster wie
 * {@see SepaPreNotificationJob}/{@see MembershipFeeDueJob}.
 */
class MandateExpiryJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private MandateService $mandates,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		try {
			$ergebnis = $this->mandates->expireDueMandates();
		} catch (\Throwable $e) {
			$this->logger->error('Mandats-Verfall-Lauf abgebrochen', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return;
		}
		if ($ergebnis['expired'] > 0) {
			$this->logger->info('Mandats-Verfall: {expired} Mandat(e) automatisch beendet', [
				'app' => Application::APP_ID,
				'expired' => $ergebnis['expired'],
			]);
		}
	}
}
