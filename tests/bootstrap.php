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

/**
 * Vierter Autoloader (Fallback): Composer-Drittanbieter-Abhängigkeiten der
 * App selbst (seit Issue #73 z. B. `chillerlan/php-qrcode` für den
 * GiroCode-Anhang der Mahnwesen-Mails, siehe composer.json/EpcQrCodeGenerator) -
 * composer.json dient der App-Laufzeit seither nicht mehr nur dem
 * Autoloader für `OCA\Vereinsbuchhaltung\*` (dafür bleibt der erste
 * Autoloader oben zuständig, siehe dessen Klassendoc zur
 * `classmap-authoritative`-Falle), sondern liefert auch echten, im
 * Release-Tarball mitgelieferten Fremdcode. Nextcloud selbst lädt
 * vendor/autoload.php beim App-Start automatisch; für die Unit-Tests ohne
 * laufende Instanz braucht es diesen expliziten Require - bewusst NACH den
 * drei obigen Autoloadern registriert, damit deren gezielte, immer aktuelle
 * Ladewege unangetastet bleiben und nur echte Drittanbieter-Klassen hier
 * landen.
 */
if (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
	require_once dirname(__DIR__) . '/vendor/autoload.php';
}

/**
 * Fünfter Autoloader: die PSR-Schnittstellen, auf die die OCP-Stubs verweisen.
 *
 * `nextcloud/ocp` zieht psr/clock, psr/container, psr/event-dispatcher und
 * psr/log als Abhängigkeiten nach; sie liegen unter .phpstan/vendor/psr/. Etwa
 * `OCP\AppFramework\Utility\ITimeFactory` erweitert `Psr\Clock\ClockInterface` -
 * ohne diese Schnittstelle lässt sich ITimeFactory weder laden noch mocken.
 *
 * Der Composer-Autoloader aus .phpstan kennt die Psr\-Namensräume zwar, wird
 * aber nur vom PHPUnit unter .phpstan/vendor (`npm run phpunit`) geladen. In
 * CI läuft das globale PHPUnit-PHAR (setup-php `tools: phpunit`) und lädt
 * ausschließlich diese Bootstrap-Datei; ohne diesen Autoloader schlugen dort
 * alle Tests mit "Interface Psr\Clock\ClockInterface not found" fehl. Lokal
 * greift er nie doppelt: er springt nur an, wenn die Klasse noch fehlt.
 */
spl_autoload_register(static function (string $class): void {
	$packages = [
		'Psr\\Clock\\' => 'clock',
		'Psr\\Container\\' => 'container',
		'Psr\\EventDispatcher\\' => 'event-dispatcher',
		'Psr\\Log\\' => 'log',
	];
	foreach ($packages as $prefix => $package) {
		if (!str_starts_with($class, $prefix)) {
			continue;
		}
		$relative = substr($class, strlen($prefix));
		$file = dirname(__DIR__) . '/.phpstan/vendor/psr/' . $package . '/src/' . str_replace('\\', '/', $relative) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
		return;
	}
});
