<?php

declare(strict_types=1);

/**
 * Manueller Schnelltest des CSV-CAMT-Parsers – ohne Nextcloud/Composer.
 * Aufruf:  php tests/manual_parser_check.php [pfad/zur/datei.csv]
 *
 * Praktisch, um eine echte Banking-Exportdatei zu prüfen, bevor sie in der
 * App importiert wird.
 */

require __DIR__ . '/../lib/Service/CamtCsvParser.php';

use OCA\Vereinsbuchhaltung\Service\CamtCsvParser;

$file = $argv[1] ?? __DIR__ . '/fixtures/beispiel-camt.csv';
if (!is_readable($file)) {
	fwrite(STDERR, "Datei nicht lesbar: $file\n");
	exit(1);
}

$parser = new CamtCsvParser();

try {
	$rows = $parser->parse((string)file_get_contents($file));
} catch (\Throwable $e) {
	fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
	exit(1);
}

printf("Datei: %s\n", $file);
printf("Geparste Buchungen: %d\n\n", count($rows));

$sum = 0;
foreach ($rows as $r) {
	$sum += $r['amountCents'];
	printf(
		"%s | %14s EUR | %-22s | %s\n",
		$r['bookingDate'],
		number_format($r['amountCents'] / 100, 2, ',', '.'),
		mb_substr((string)$r['counterparty'], 0, 22),
		mb_substr((string)$r['purpose'], 0, 40)
	);
}

$hashes = array_column($rows, 'hash');
printf("\nSaldo der Datei: %s EUR\n", number_format($sum / 100, 2, ',', '.'));
printf("Eindeutige Hashes: %d von %d\n", count(array_unique($hashes)), count($hashes));
