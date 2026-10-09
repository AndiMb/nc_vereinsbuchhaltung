<?php

declare(strict_types=1);

/**
 * Erzeugt aus einem eingereichten Lastschriftlauf den camt.053-Kontoauszug der
 * Bank zum Import im Bankabgleich (Sammelgutschrift, zwei Rückgaben,
 * unabhängige Kontobewegungen).
 *
 *   docker compose exec -T -u www-data stable34 php \
 *     /var/www/html/apps-shared/vereinsbuchhaltung/tests/dev/make-camt053.php [--batch-id=N] [--out=Pfad]
 *
 * Ohne --out geht das XML auf die Standardausgabe (die Zusammenfassung auf die
 * Fehlerausgabe) – der Container sieht den Worktree nur lesend, die Datei
 * schreibt deshalb der Aufruf auf dem Host:
 *
 *   … make-camt053.php > tests/dev/testdaten/bank-oktober-2026.camt053.xml
 *
 * Ohne --batch-id gilt der eingereichte Lauf zum 01.10.2026 (der Lauf des Seeders).
 */

require_once __DIR__ . '/lib/boot.php';

use OCA\Vereinsbuchhaltung\Tests\Dev\CamtGenerator;
use OCP\Server;

$batchId = null;
$out = null;
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
	if (preg_match('/^--batch-id=(\d+)$/', $arg, $m) === 1) {
		$batchId = (int)$m[1];
	} elseif (str_starts_with($arg, '--out=')) {
		$out = substr($arg, 6);
	} else {
		fwrite(STDERR, 'Unbekannte Option: ' . $arg . "\n");
		exit(2);
	}
}

try {
	$generator = Server::get(CamtGenerator::class);
	$result = $generator->generate($batchId);
} catch (\Throwable $e) {
	fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
	exit(2);
}

fwrite(STDERR, sprintf(
	"Lauf #%d (Fälligkeit %s): Sammelgutschrift über %d Posten (%s €), %d Rückgaben, %d weitere Umsätze\n",
	$result['batchId'],
	$result['dueDate'],
	$result['collected'],
	number_format($result['collectedCents'] / 100, 2, ',', '.'),
	$result['returned'],
	$result['plain'],
));
if ($out !== null) {
	file_put_contents($out, $result['xml']);
} else {
	echo $result['xml'];
}
