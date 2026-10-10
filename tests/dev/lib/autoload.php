<?php

declare(strict_types=1);

/**
 * Lädt die Klassen von tests/dev/lib (Namensraum OCA\Vereinsbuchhaltung\Tests\Dev).
 *
 * Bewusst ein eigener Autoloader: composer.json der App kennt nur lib/, und
 * die Werkzeuge hier gehören nicht ins Release-Paket. Die App selbst
 * (OCA\Vereinsbuchhaltung\…) lädt Nextcloud beziehungsweise tests/bootstrap.php.
 */
spl_autoload_register(static function (string $class): void {
	$prefix = 'OCA\\Vereinsbuchhaltung\\Tests\\Dev\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($file)) {
		require_once $file;
	}
});
