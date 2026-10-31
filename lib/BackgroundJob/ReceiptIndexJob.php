<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\BackgroundJob;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\AttachmentWatchFolderService;
use OCA\Vereinsbuchhaltung\Service\ReceiptIndex\ReceiptIndexService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hält den Belegindex des Wächter-Ordners im Hintergrund aktuell (alle
 * 10 Minuten, höchstens 300 Dateien oder 60 s je Lauf), damit die
 * Belegvorschläge nicht erst beim Öffnen der Ansicht lesen müssen.
 */
class ReceiptIndexJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private AttachmentStorageService $storage,
		private AttachmentWatchFolderService $watchFolder,
		private ReceiptIndexService $index,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(600);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		if (!$this->storage->isWatchMode()) {
			return;
		}

		try {
			$scan = $this->watchFolder->scan();
			if ($scan === null) {
				return;
			}
			$this->index->refresh($scan['files'], ReceiptIndexService::JOB_MAX_FILES, ReceiptIndexService::JOB_MAX_SECONDS, !$scan['capped']);
		} catch (\Throwable $e) {
			$this->logger->error('Belegindex-Lauf abgebrochen', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
		}
	}
}
