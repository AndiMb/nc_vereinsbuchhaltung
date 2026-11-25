import { getContainer, runExec } from '@nextcloud/e2e-test-server'

// PHP in der laufenden Nextcloud des Testcontainers ausführen – für Proben,
// die keine HTTP-Schnittstelle haben (GiroCode-Laufzeit in 47, Bestätigung
// einer Kontoverknüpfung in member-link.mjs, Nextclouds Benachrichtigungen
// in 19). `container`/`env` sind nur für Proben gegen
// einen anderen Container gedacht; die Specs nehmen den Testserver und die
// Standardumgebung.

/**
 * Führt PHP im Container aus – mit gebooteter Nextcloud und regulär registrierten
 * Apps (wie ein occ-Befehl oder Cron-Lauf), als www-data. `$argv[1…]` sind die
 * übergebenen Argumente; das Skript meldet sein Ergebnis über eine Zeile
 * „VBH-RESULT <json>“ (Warnungen des Starts stören so nicht).
 *
 * @returns {Promise<any>} das JSON-Ergebnis des Skripts
 */
export async function inNextcloud(php, args = [], { container = getContainer(), env = [] } = {}) {
	const preamble = String.raw`
chdir('/var/www/html');
require_once '/var/www/html/lib/base.php';
\OCP\Server::get(\OC\AppFramework\Bootstrap\Coordinator::class)->runInitialRegistration();
`
	const { stdout, stderr } = await runExec(['php', '-r', preamble + php, '--', ...args], { container, env })
	const match = /VBH-RESULT (.*)$/m.exec(stdout)
	if (!match) {
		throw new Error(`PHP im Container lieferte kein Ergebnis.\nstdout: ${stdout}\nstderr: ${stderr}`)
	}
	return JSON.parse(match[1])
}
