<?php

declare(strict_types=1);

/**
 * Prueft ein zusammengestelltes App-Verzeichnis (Release-Staging) darauf, dass
 * jedes Verzeichnis und jede Datei, auf die der Composer-Autoloader verweist,
 * auch wirklich mitgeliefert wird.
 *
 * Anlass: Ein unverankertes rsync-`--exclude='src'` im Release-Workflow hatte
 * jedes `vendor/<paket>/src/` entfernt. Der Autoloader verwies weiter darauf,
 * das Tarball war trotzdem "gruen" - GiroCode, E-Rechnung und das Lesen von
 * Beleg-PDFs liefen im Betrieb ins Leere, und doctrine/lexer fuellte das Log
 * (Issue #269). Dieses Skript laesst so ein Release scheitern, bevor es
 * veroeffentlicht wird.
 *
 * Gelesen werden autoload_psr4.php, autoload_namespaces.php (PSR-0),
 * autoload_classmap.php, autoload_files.php sowie autoload_static.php, das
 * PHP tatsaechlich benutzt. Alle liefern absolute Pfade relativ zu ihrem
 * eigenen Speicherort, also zum gepruften Staging-Verzeichnis.
 *
 * Aufruf: php check-vendor-autoload.php <app-verzeichnis>
 * Exit-Code 0 = alles vorhanden, 1 = Verweise ins Leere, 2 = Aufruffehler.
 */

if ($argc !== 2) {
	fwrite(STDERR, "Aufruf: php {$argv[0]} <app-verzeichnis>\n");
	exit(2);
}

$appDir = rtrim($argv[1], '/\\');
$composerDir = $appDir . '/vendor/composer';
if (!is_dir($composerDir)) {
	fwrite(STDERR, "::error::$composerDir fehlt - wurde composer install vergessen?\n");
	exit(2);
}

/** @var list<string> $missing */
$missing = [];
$checked = 0;

$check = static function (string $source, string $path, bool $dir) use (&$missing, &$checked): void {
	$checked++;
	if ($dir ? !is_dir($path) : !is_file($path)) {
		$missing[] = sprintf('%s: %s fehlt (%s)', $source, $dir ? 'Verzeichnis' : 'Datei', $path);
	}
};

// Grundgeruest des Autoloaders selbst.
foreach (['vendor/autoload.php', 'vendor/composer/autoload_real.php', 'vendor/composer/ClassLoader.php'] as $file) {
	$check('Autoloader', $appDir . '/' . $file, false);
}

$load = static function (string $file) use ($composerDir): ?array {
	$path = $composerDir . '/' . $file;
	if (!is_file($path)) {
		return null;
	}
	$data = require $path;
	return is_array($data) ? $data : null;
};

// PSR-4 und PSR-0: Namensraum => Liste von Verzeichnissen.
foreach (['autoload_psr4.php', 'autoload_namespaces.php'] as $file) {
	$map = $load($file);
	if ($map === null) {
		if ($file === 'autoload_psr4.php') {
			$missing[] = "$file: fehlt oder ist unlesbar";
		}
		continue;
	}
	foreach ($map as $prefix => $dirs) {
		foreach ((array)$dirs as $dir) {
			$check("$file [$prefix]", $dir, true);
		}
	}
}

// Classmap: Klasse => Datei.
$classmap = $load('autoload_classmap.php');
if ($classmap === null) {
	$missing[] = 'autoload_classmap.php: fehlt oder ist unlesbar';
} else {
	foreach ($classmap as $class => $file) {
		$check("autoload_classmap.php [$class]", $file, false);
	}
}

// Dateien, die der Autoloader bei jedem Laden einbindet.
foreach ($load('autoload_files.php') ?? [] as $id => $file) {
	$check("autoload_files.php [$id]", $file, false);
}

// autoload_static.php: die Variante, die PHP ab 5.6 wirklich laedt. Sie wird
// separat erzeugt; geprueft wird sie, falls sie von den obigen abweicht.
$staticFile = $composerDir . '/autoload_static.php';
if (is_file($staticFile)) {
	$before = get_declared_classes();
	require $staticFile;
	$staticClasses = array_filter(
		array_diff(get_declared_classes(), $before),
		static fn (string $c): bool => str_starts_with($c, 'Composer\\Autoload\\ComposerStaticInit'),
	);
	foreach ($staticClasses as $class) {
		$ref = new ReflectionClass($class);
		$props = $ref->getStaticProperties();
		foreach ($props['files'] ?? [] as $id => $file) {
			$check("autoload_static.php files [$id]", $file, false);
		}
		foreach ($props['prefixDirsPsr4'] ?? [] as $prefix => $dirs) {
			foreach ($dirs as $dir) {
				$check("autoload_static.php psr4 [$prefix]", $dir, true);
			}
		}
		foreach ($props['fallbackDirsPsr4'] ?? [] as $dir) {
			$check('autoload_static.php psr4-fallback', $dir, true);
		}
		foreach ($props['prefixesPsr0'] ?? [] as $group) {
			foreach ($group as $prefix => $dirs) {
				foreach ($dirs as $dir) {
					$check("autoload_static.php psr0 [$prefix]", $dir, true);
				}
			}
		}
		foreach ($props['classMap'] ?? [] as $class2 => $file) {
			$check("autoload_static.php classmap [$class2]", $file, false);
		}
	}
	if ($staticClasses === []) {
		$missing[] = 'autoload_static.php: keine ComposerStaticInit-Klasse gefunden';
	}
}

if ($missing !== []) {
	$max = 50;
	foreach (array_slice($missing, 0, $max) as $line) {
		echo "::error::$line\n";
	}
	if (count($missing) > $max) {
		printf("::error::... und %d weitere fehlende Verweise\n", count($missing) - $max);
	}
	printf("FEHLER: %d von %d Autoloader-Verweisen zeigen ins Leere.\n", count($missing), $checked);
	exit(1);
}

printf("OK: alle %d Autoloader-Verweise vorhanden.\n", $checked);
exit(0);
