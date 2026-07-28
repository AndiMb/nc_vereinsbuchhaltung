<?php

declare(strict_types=1);

/**
 * Bootstrap der Unit-Tests.
 *
 * Bewusst ein eigener PSR-4-Autoloader statt vendor/autoload.php: die
 * composer.json dieser App setzt "classmap-authoritative": true (sinnvoll für
 * die Auslieferung, das spart im Betrieb Dateisystemzugriffe). Damit gibt es
 * aber keinen PSR-4-Rückfall – eine Klasse, die beim letzten `composer install`
 * noch nicht existierte, ist im eingefrorenen Classmap nicht enthalten und
 * lässt sich nicht laden. Wer nach dem Anlegen einer neuen Klasse die Tests
 * startet, bekam dann ein irreführendes "Class not found" statt eines
 * Testergebnisses.
 *
 * Dieser Autoloader liest immer den aktuellen Stand von lib/ und macht die
 * Unit-Tests unabhängig davon, ob und wann zuletzt ein Autoloader erzeugt
 * wurde.
 */

spl_autoload_register(static function (string $class): void {
	$prefix = 'OCA\\Vereinsbuchhaltung\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$relative = substr($class, strlen($prefix));
	$file = dirname(__DIR__) . '/lib/' . str_replace('\\', '/', $relative) . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});
