<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;

/**
 * Übersetzt einen unerwarteten Fehler in eine Antwort, ohne interne Details
 * preiszugeben (Issue #261).
 *
 * Bis dahin gaben mehrere Controller `$e->getMessage()` jeder Ausnahme an den
 * Browser – auch Dateipfade aus TCPDF, SQL-Fehler der Datenbank oder Texte des
 * Mailservers. Jetzt gilt: Nur eine UserFacingException trägt einen Text, der
 * für Menschen geschrieben ist; alles andere landet mit Stacktrace im
 * Nextcloud-Log, und der Browser sieht eine allgemeine Meldung.
 *
 * Die benutzende Klasse muss `private LoggerInterface $logger` haben.
 */
trait InternalErrorResponder {

	/**
	 * @param Http::STATUS_* $status
	 * @param string $fallback bereits übersetzte allgemeine Meldung
	 * @param string $field Schlüssel der Meldung in der Antwort
	 */
	protected function internalError(\Throwable $e, int $status, string $fallback, string $field = 'message'): DataResponse {
		if ($e instanceof UserFacingException) {
			return new DataResponse([$field => $e->getMessage()], $status);
		}
		$this->logger->error($e->getMessage(), ['exception' => $e, 'app' => Application::APP_ID]);
		return new DataResponse([$field => $fallback], $status);
	}
}
