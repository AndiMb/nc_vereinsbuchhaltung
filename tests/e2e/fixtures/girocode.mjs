import { getContainer, runExec } from '@nextcloud/e2e-test-server'

// Helfer für die GiroCode-Spec (47): PHP in der laufenden Nextcloud des
// Containers ausführen, die EPC-Zeilen hinter einem QR-Bild lesen und PNGs
// prüfen. `container`/`env` sind nur für Proben gegen einen anderen Container
// gedacht; die Spec nimmt den Testserver und die Standardumgebung.

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

/**
 * Selbstprüfung der Laufzeit: sind gd und die Bibliothek da, und erzeugt der
 * Dienst der App ein PNG? Liefert u. a. `gd`, `gdPng`, `library`, `generator`,
 * `png` (base64) oder `error`.
 */
export function giroCodeRuntimeCheck(name, iban, purpose, options) {
	return inNextcloud(String.raw`
$result = [
	'php' => PHP_VERSION,
	'gd' => extension_loaded('gd'),
	'gdPng' => function_exists('gd_info') && !empty(gd_info()['PNG Support']),
	'library' => class_exists(\chillerlan\QRCode\QRCode::class),
	'generator' => class_exists(\OCA\Vereinsbuchhaltung\Service\EpcQrCodeGenerator::class),
];
try {
	$png = \OCP\Server::get(\OCA\Vereinsbuchhaltung\Service\EpcQrCodeGenerator::class)->generatePng($argv[1], $argv[2], null, 4599, $argv[3]);
	$result['png'] = base64_encode($png);
} catch (\Throwable $e) {
	$result['error'] = get_class($e) . ': ' . $e->getMessage();
}
echo 'VBH-RESULT ' . json_encode($result);
`, [name, iban, purpose], options)
}

/**
 * Liest die EPC-Zeilen hinter PNG-Bildern mit der Bibliothek der App im Container
 * (Dekoder auf GD-Basis, nicht Imagick).
 *
 * @param {Buffer[]} pngs
 * @returns {Promise<string[][]>} je Bild die Zeilen der Nutzlast
 */
export async function decodeGiroCodes(pngs, options) {
	const decoded = await inNextcloud(String.raw`
$reader = new \chillerlan\QRCode\QRCode(new \chillerlan\QRCode\QROptions(['readerUseImagickIfAvailable' => false]));
$out = [];
foreach (array_slice($argv, 1) as $b64) {
	try {
		$out[] = (string)$reader->readFromBlob(base64_decode($b64));
	} catch (\Throwable $e) {
		$out[] = 'FEHLER ' . get_class($e) . ': ' . $e->getMessage();
	}
}
echo 'VBH-RESULT ' . json_encode($out);
`, pngs.map((png) => png.toString('base64')), options)
	return decoded.map((payload) => payload.split('\n'))
}

/** Prüft die PNG-Signatur und liest die Bildgröße aus dem IHDR-Block; `null`, wenn es kein PNG ist. */
export function pngInfo(data) {
	const signature = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])
	if (data.length < 33 || !data.subarray(0, 8).equals(signature) || data.subarray(12, 16).toString('ascii') !== 'IHDR') {
		return null
	}
	return { width: data.readUInt32BE(16), height: data.readUInt32BE(20), bytes: data.length }
}
