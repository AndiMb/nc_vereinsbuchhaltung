<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\BackgroundJob;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\WatchFolderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Sieht stündlich im überwachten Ordner nach neuen Kontoauszügen.
 *
 * Stündlich statt häufiger, weil ein Kontoauszug ohnehin nur wenige Male im
 * Monat abgelegt wird; jeder Lauf kostet sonst nur Datenbankabfragen.
 *
 * Wichtig für den Betrieb: das setzt echten System-Cron voraus. Läuft die
 * Instanz mit AJAX-Cron (Nextclouds Vorgabe für kleine Installationen), wird
 * der Job nur ausgeführt, während jemand die Oberfläche offen hat.
 */
class ImportWatchFolderJob extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private WatchFolderService $watchFolder,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		// Der Import kann bei einem Jahresauszug etwas dauern; parallele Läufe
		// desselben Ordners würden dieselbe Datei zweimal anfassen.
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		if (!$this->watchFolder->isConfigured()) {
			return;
		}

		try {
			$results = $this->watchFolder->run();
		} catch (\Throwable $e) {
			// Ein Fehler hier darf die übrigen Hintergrundaufgaben der Instanz
			// nicht anhalten.
			$this->logger->error('Wachordner-Lauf abgebrochen', [
				'app' => Application::APP_ID,
				'exception' => $e,
			]);
			return;
		}

		foreach ($results as $r) {
			if ($r['ok']) {
				$this->logger->info('Wachordner: {file} eingelesen ({new} neu, {duplicate} Dubletten)', [
					'app' => Application::APP_ID,
					'file' => $r['file'],
					'new' => $r['new'] ?? 0,
					'duplicate' => $r['duplicate'] ?? 0,
				]);
			}
		}
	}
}
