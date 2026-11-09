<?php

declare(strict_types=1);

/**
 * Erzeugt im zusammengestellten App-Verzeichnis (Release-Staging) den
 * Klassen-Cache von horstoeko/invoicesuite, BEVOR die App signiert wird.
 *
 * Anlass: Die Bibliothek sucht beim ersten Erzeugen einer E-Rechnung ihre
 * Format-Anbieter und schreibt das Ergebnis fest nach
 * vendor/horstoeko/invoicesuite/src/cache/<md5>.cache - ein Pfad, den keine
 * Einstellung umlenkt. Im signierten Paket fehlte die Datei; die
 * Integritaetspruefung der Nextcloud meldete sie danach als EXTRA_FILE.
 * Liegt die Datei schon im Paket (und damit in appinfo/signature.json), liest
 * die Bibliothek sie nur noch und schreibt nichts mehr.
 *
 * Der Dateiname haengt von den Discovery-Namensraeumen ab; das Skript nimmt
 * deshalb dieselbe Konstante wie der Writer zur Laufzeit.
 *
 * Den Serializer-Cache (src/cache/jms) lenkt der Writer bereits ins
 * Temp-Verzeichnis um; die mit dem Paket gelieferten Dateien dort bleiben
 * unangetastet. InvoiceSuiteClassFinder::clearCache() wird bewusst NICHT
 * aufgerufen, weil es auch diese Dateien loescht.
 *
 * Aufruf: php warm-invoicesuite-cache.php <app-verzeichnis>
 * Exit-Code 0 = Cache erzeugt, 1 = Cache fehlt/leer, 2 = Aufruffehler.
 */

use horstoeko\invoicesuite\documents\abstracts\InvoiceSuiteAbstractDocumentFormatProvider;
use horstoeko\invoicesuite\InvoiceSuiteSettings;
use horstoeko\invoicesuite\utils\InvoiceSuiteClassFinder;
use OCA\Vereinsbuchhaltung\Service\EInvoice\InvoiceSuiteEInvoiceWriter;

if ($argc !== 2) {
	fwrite(STDERR, "Aufruf: php {$argv[0]} <app-verzeichnis>\n");
	exit(2);
}

$appDir = rtrim($argv[1], '/\\');
$autoload = $appDir . '/vendor/autoload.php';
if (!is_file($autoload)) {
	fwrite(STDERR, "::error::$autoload fehlt - wurde composer install vergessen?\n");
	exit(2);
}
require $autoload;

$cacheDir = dirname((string)(new ReflectionClass(InvoiceSuiteClassFinder::class))->getFileName(), 2) . '/cache';
if (!str_starts_with((string)realpath($cacheDir), (string)realpath($appDir) . DIRECTORY_SEPARATOR)) {
	fwrite(STDERR, "::error::Die Bibliothek wurde nicht aus $appDir geladen ($cacheDir).\n");
	exit(2);
}

// Nur Klassen-Caches entfernen, damit kein veralteter Eintrag mitsigniert wird.
foreach (glob($cacheDir . '/*.cache') ?: [] as $stale) {
	unlink($stale);
}

InvoiceSuiteSettings::setDiscoveryNamespaces(InvoiceSuiteEInvoiceWriter::DISCOVERY_NAMESPACES);
$providers = InvoiceSuiteClassFinder::factory()->getClassesWhenItsSubClassOf(InvoiceSuiteAbstractDocumentFormatProvider::class);

$files = glob($cacheDir . '/*.cache') ?: [];
if ($providers === [] || count($files) !== 1) {
	fwrite(STDERR, sprintf("::error::Klassen-Cache nicht erzeugt (%d Anbieter, %d Cache-Dateien in %s).\n", count($providers), count($files), $cacheDir));
	exit(1);
}

printf("Klassen-Cache %s erzeugt: %d Format-Anbieter.\n", basename($files[0]), count($providers));
exit(0);
