<?php

declare(strict_types=1);

/**
 * Startet Nextcloud für die Werkzeuge unter tests/dev (CLI, wie `occ`) und
 * lädt die Vereinsbuchhaltung samt der Hilfsklassen von tests/dev/lib.
 *
 * Läuft nur auf der Kommandozeile und muss als Nutzer des Webservers
 * (www-data) starten: nur der darf config/config.php lesen. Aufruf aus dem
 * Compose-Verzeichnis der Entwicklungsumgebung:
 *
 *   docker compose exec -T -u www-data stable34 php <Skript> [Optionen]
 *
 * Die Nextcloud-Wurzel liegt im Container unter /var/www/html; abweichende
 * Installationen setzen NEXTCLOUD_ROOT.
 */

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "Dieses Werkzeug läuft nur auf der Kommandozeile.\n");
	exit(1);
}

define('OC_CONSOLE', 1);
$ncRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
$ncBase = $ncRoot . '/lib/base.php';
if (!is_file($ncBase)) {
	fwrite(STDERR, 'Keine Nextcloud unter ' . $ncRoot . " gefunden (NEXTCLOUD_ROOT setzen).\n");
	exit(1);
}
require_once $ncBase;

\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('vereinsbuchhaltung');
require_once __DIR__ . '/autoload.php';
