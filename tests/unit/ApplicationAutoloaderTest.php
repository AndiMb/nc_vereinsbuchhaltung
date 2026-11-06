<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die App lädt den Composer-Autoloader ihrer Abhängigkeiten selbst (Issue #120),
 * aber erst dort, wo sie eine Bibliothek braucht (#265).
 *
 * Nextcloud lädt von einer App nur `composer/autoload.php`, nie
 * `vendor/autoload.php`. Fehlte das `require_once`, war
 * `chillerlan/php-qrcode` in der laufenden Instanz nicht ladbar, und der
 * GiroCode-Anhang der Mahnmails fiel lautlos weg – während jeder PHPUnit-Test
 * grün blieb, weil `tests/bootstrap.php` die Bibliothek von sich aus lädt.
 *
 * Umgekehrt darf `register()` den Autoloader nicht mehr laden: das liefe bei
 * jedem Request jeder Nextcloud-Seite, und die Bibliotheken der App (TCPDF,
 * php-qrcode, invoicesuite) stünden dann vor gleichnamigen Kopien anderer Apps.
 *
 * Darum laufen beide Prüfungen in einem Kindprozess, der nur die App-Klassen
 * und die OCP-Stubs kennt, aber keinen Composer-Autoloader.
 */
class ApplicationAutoloaderTest extends TestCase {

	protected function setUp(): void {
		if (!is_file(dirname(__DIR__, 2) . '/vendor/autoload.php')) {
			$this->markTestSkipped('vendor/ fehlt - `composer install` ausführen.');
		}
	}

	public function testLoadComposerAutoloaderMachtDieBibliothekenLadbar(): void {
		$result = $this->runChild('\\OCA\\Vereinsbuchhaltung\\AppInfo\\Application::loadComposerAutoloader();');

		$this->assertFalse($result['before'], 'Vorbedingung: ohne die App kennt der Kindprozess die Bibliothek nicht');
		$this->assertTrue($result['after'], 'loadComposerAutoloader() muss vendor/autoload.php laden, sonst fehlt der GiroCode in jeder echten Nextcloud');
	}

	public function testRegisterLaedtVendorNichtMehr(): void {
		// Ein IRegistrationContext, der alles annimmt: alle Methoden der
		// Schnittstelle liefern void, ein unbenannter variadischer Parameter
		// passt zu jeder Signatur.
		$code = '$methods = "";'
			. 'foreach ((new ReflectionClass(\\OCP\\AppFramework\\Bootstrap\\IRegistrationContext::class))->getMethods() as $m) {'
			. '$methods .= "public function " . $m->getName() . "(...\\$args): void {}\\n";'
			. '}'
			. 'eval("class VbhContext implements \\\\OCP\\\\AppFramework\\\\Bootstrap\\\\IRegistrationContext {" . $methods . "}");'
			. '$app = (new ReflectionClass(\\OCA\\Vereinsbuchhaltung\\AppInfo\\Application::class))->newInstanceWithoutConstructor();'
			. '$app->register(new VbhContext());';
		$result = $this->runChild($code);

		$this->assertFalse($result['before'], 'Vorbedingung: ohne die App kennt der Kindprozess die Bibliothek nicht');
		$this->assertFalse($result['after'], 'register() darf vendor/autoload.php nicht laden; das tun die Klassen, die eine Bibliothek brauchen');
	}

	/**
	 * Die Dienste laden den Autoloader in der Methode, nicht im Konstruktor:
	 * Nextcloud 34 baut sie unter PHP 8.4 als Lazy Objects, deren Konstruktor
	 * nicht vor dem Methodenaufruf laufen muss. Der Kindprozess baut die Objekte
	 * deshalb ganz ohne Konstruktor. Ob der Aufruf danach gelingt, ist hier
	 * egal (dem Kindprozess fehlt etwa gd); es zählt, dass er vendor/ lädt.
	 *
	 * @return array<string, array{string}>
	 */
	public static function libraryCalls(): array {
		$build = static fn (string $class): string => '$o = (new ReflectionClass(\\' . $class . '::class))->newInstanceWithoutConstructor();';
		return [
			'GiroCode (php-qrcode)' => [$build('OCA\\Vereinsbuchhaltung\\Service\\EpcQrCodeGenerator')
				. 'try { $o->generatePng("Verein", "DE89370400440532013000", null, 100, "Test"); } catch (\\Throwable $e) {}'],
			'E-Rechnung (invoicesuite)' => [$build('OCA\\Vereinsbuchhaltung\\Service\\EInvoice\\InvoiceSuiteEInvoiceWriter')
				. 'try { $o->extractXml("%PDF-1.4"); } catch (\\Throwable $e) {}'],
		];
	}

	#[DataProvider('libraryCalls')]
	public function testDienstLaedtVendorAuchOhneKonstruktor(string $action): void {
		$result = $this->runChild($action);

		$this->assertFalse($result['before'], 'Vorbedingung: ohne die App kennt der Kindprozess die Bibliothek nicht');
		$this->assertTrue($result['after'], 'Die Methode muss vendor/autoload.php laden, bevor sie die Bibliothek nutzt');
	}

	/**
	 * Führt $action in einem PHP-Kindprozess aus, der nur App-Klassen und
	 * OCP-Stubs laden kann, und meldet, ob php-qrcode davor und danach ladbar war.
	 *
	 * @return array{before: bool, after: bool}
	 */
	private function runChild(string $action): array {
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
			. $action
			. 'echo "VBH-RESULT " . json_encode(["before" => $before, "after" => class_exists("chillerlan\\\\QRCode\\\\QRCode")]);';

		$process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$this->assertIsResource($process, 'Kindprozess konnte nicht gestartet werden');
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		proc_close($process);

		$this->assertSame(1, preg_match('/VBH-RESULT (.*)$/m', $output, $match), 'Kindprozess lieferte kein Ergebnis: ' . $output);
		/** @var array{before: bool, after: bool} $result */
		$result = json_decode($match[1], true);
		return $result;
	}
}
