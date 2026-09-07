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

/**
 * Zweiter Autoloader für die Nextcloud-Server-Schnittstellen (OCP\*).
 *
 * Sie liegen als reine Stub-Dateien unter .phpstan/vendor/nextcloud/ocp/ und
 * werden dort für die statische Analyse geholt. Das Paket bringt selbst keine
 * autoload-Angabe mit – composer legt für OCP\ deshalb keinen PSR-4-Eintrag an,
 * und `require .phpstan/vendor/autoload.php` allein macht die Interfaces nicht
 * ladbar. Die Zuordnung ist trivial (OCP\Foo\Bar -> OCP/Foo/Bar.php), also
 * übernimmt sie dieser Autoloader.
 *
 * Damit lassen sich Klassen testen, die OCP-Dienste nur als Schnittstelle
 * brauchen und per Mock zu befüllen sind – etwa die Belegablage, deren
 * ZIP-Export-Pfad in Issue #40 an genau so einer Schnittstelle scheiterte.
 * Wer echte Nextcloud-Dienste braucht (Mapper, Dateisystem), gehört weiterhin
 * in die E2E-Tests gegen eine laufende Instanz.
 */
spl_autoload_register(static function (string $class): void {
	if (!str_starts_with($class, 'OCP\\')) {
		return;
	}
	$file = dirname(__DIR__) . '/.phpstan/vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});

/**
 * Dritter Autoloader: die wenigen serverinternen Schnittstellen (OC\*), auf
 * die die OCP-Stubs verweisen, ohne dass nextcloud/ocp sie mitliefert.
 * Nachbauten unter tests/stubs/ – siehe den Kommentar in der jeweiligen Datei.
 */
spl_autoload_register(static function (string $class): void {
	if (!str_starts_with($class, 'OC\\')) {
		return;
	}
	$file = __DIR__ . '/stubs/' . str_replace('\\', '/', $class) . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});
