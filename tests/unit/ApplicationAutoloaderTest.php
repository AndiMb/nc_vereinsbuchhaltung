<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Die App lädt den Composer-Autoloader ihrer Abhängigkeiten selbst (Issue #120).
 *
 * Nextcloud lädt von einer App nur `composer/autoload.php`, nie
 * `vendor/autoload.php`. Fehlte dieses `require_once`, war
 * `chillerlan/php-qrcode` in der laufenden Instanz nicht ladbar, und der
 * GiroCode-Anhang der Mahnmails fiel lautlos weg – während jeder PHPUnit-Test
 * grün blieb, weil `tests/bootstrap.php` die Bibliothek von sich aus lädt.
 * Darum läuft die Prüfung in einem Kindprozess, der nur die App-Klassen und die
 * OCP-Stubs kennt, aber keinen Composer-Autoloader.
 */
class ApplicationAutoloaderTest extends TestCase {

	public function testRegisterHoltDenAutoloaderDerAppAbhaengigkeitenNach(): void {
		if (!is_file(dirname(__DIR__, 2) . '/vendor/autoload.php')) {
			$this->markTestSkipped('vendor/ fehlt - `composer install` ausführen.');
		}

		$lib = var_export(dirname(__DIR__, 2) . '/lib/', true);
		$stubs = var_export(dirname(__DIR__, 2) . '/.phpstan/vendor/nextcloud/ocp/', true);
		$code = 'spl_autoload_register(static function (string $class): void {'
			. '$map = ["OCA\\\\Vereinsbuchhaltung\\\\" => ' . $lib . ', "OCP\\\\" => ' . $stubs . '];'
			. 'foreach ($map as $prefix => $dir) {'
			. 'if (str_starts_with($class, $prefix)) {'
			. '$file = ($prefix === "OCP\\\\" ? $dir . "OCP/" : $dir) . str_replace("\\\\", "/", substr($class, strlen($prefix))) . ".php";'
			. 'if (is_file($file)) { require_once $file; }'
			. '}'
			. '}'
			. '});'
			. '$before = class_exists("chillerlan\\\\QRCode\\\\QRCode");'
			. '$method = new ReflectionMethod(OCA\\Vereinsbuchhaltung\\AppInfo\\Application::class, "loadComposerAutoloader");'
			. '$method->invoke(null);'
			. 'echo "VBH-RESULT " . json_encode(["before" => $before, "after" => class_exists("chillerlan\\\\QRCode\\\\QRCode")]);';

		$process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$this->assertIsResource($process, 'Kindprozess konnte nicht gestartet werden');
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		proc_close($process);

		$this->assertSame(1, preg_match('/VBH-RESULT (.*)$/m', $output, $match), 'Kindprozess lieferte kein Ergebnis: ' . $output);
		$result = json_decode($match[1], true);
		$this->assertFalse($result['before'], 'Vorbedingung: ohne die App kennt der Kindprozess die Bibliothek nicht');
		$this->assertTrue($result['after'], 'Application::register() muss vendor/autoload.php laden, sonst fehlt der GiroCode in jeder echten Nextcloud');
	}
}
