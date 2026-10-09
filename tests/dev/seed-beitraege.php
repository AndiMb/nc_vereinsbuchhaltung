<?php

declare(strict_types=1);

/**
 * Seeder mit Testdaten für das Beiträge/SEPA-Modul (docs/testprotokoll/).
 *
 *   docker compose exec -T -u www-data stable34 php \
 *     /var/www/html/apps-shared/vereinsbuchhaltung/tests/dev/seed-beitraege.php [Optionen]
 *
 * Optionen:
 *   (keine)                       Szenario anlegen; bricht ab, wenn schon Modul-Daten existieren
 *   --wipe                        Modul-Daten löschen und das Szenario neu anlegen
 *   --wipe-bank                   (zusammen mit --wipe) auch die Bankumsätze aus der Testdatei samt
 *                                 daraus entstandener Buchungen entfernen
 *   --with-anonymization-booking  eine Buchung von 2014 anlegen, damit Hans Becker anonymisierungsreif ist
 *                                 (legt die Geschäftsjahre 2014–2025 an)
 *   --purge                       alles zurück: Modul-Daten, Bankimport der Testdatei, Einstellungen,
 *                                 Rollen, Sprachen, Anonymisierungs-Buchung
 *   --check                       nichts ändern, nur Stand und Aufgabenliste ausgeben
 *
 * Details und Grenzen: tests/dev/README.md
 */

require_once __DIR__ . '/lib/boot.php';

use OCA\Vereinsbuchhaltung\Tests\Dev\BeitraegeSeeder;

$flags = array_slice($_SERVER['argv'] ?? [], 1);
$known = ['--wipe', '--wipe-bank', '--with-anonymization-booking', '--purge', '--check', '--help'];
foreach ($flags as $flag) {
	if (!in_array($flag, $known, true)) {
		fwrite(STDERR, 'Unbekannte Option: ' . $flag . "\n");
		exit(2);
	}
}
if (in_array('--help', $flags, true)) {
	echo "Optionen: --wipe, --wipe-bank, --with-anonymization-booking, --purge, --check (siehe tests/dev/README.md)\n";
	exit(0);
}

$seeder = new BeitraegeSeeder(static function (string $line): void {
	echo $line . "\n";
});
try {
	exit($seeder->run([
		'wipe' => in_array('--wipe', $flags, true) || in_array('--wipe-bank', $flags, true),
		'wipeBank' => in_array('--wipe-bank', $flags, true),
		'purge' => in_array('--purge', $flags, true),
		'check' => in_array('--check', $flags, true),
		'anonymizationBooking' => in_array('--with-anonymization-booking', $flags, true),
	]));
} catch (\Throwable $e) {
	fwrite(STDERR, 'Fehler: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
	exit(2);
}
