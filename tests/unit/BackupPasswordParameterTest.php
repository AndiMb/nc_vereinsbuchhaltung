<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Backup\BackupContainer;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupExportService;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupImportService;
use PHPUnit\Framework\TestCase;

/**
 * Das Passwort einer Sicherung darf nicht ins Server-Log geraten
 * (Issue #111): Scheitert etwas, während eine dieser Methoden auf dem
 * Aufrufstapel liegt, schreibt Nextcloud die Ausnahme samt Argumenten ins
 * Log. `#[\SensitiveParameter]` lässt PHP den Wert dort schwärzen.
 */
class BackupPasswordParameterTest extends TestCase {

	/**
	 * @return iterable<string, array{class-string, string}>
	 */
	public static function methodsTakingThePassword(): iterable {
		yield 'Container: prüfen' => [BackupContainer::class, 'assertPassword'];
		yield 'Container: verschlüsseln' => [BackupContainer::class, 'encrypt'];
		yield 'Container: entschlüsseln' => [BackupContainer::class, 'decrypt'];
		yield 'Export' => [BackupExportService::class, 'export'];
		yield 'Import: ansehen' => [BackupImportService::class, 'inspect'];
		yield 'Import: einspielen' => [BackupImportService::class, 'import'];
		yield 'Import: öffnen' => [BackupImportService::class, 'withBackup'];
	}

	/**
	 * @dataProvider methodsTakingThePassword
	 * @param class-string $class
	 */
	public function testPasswortWirdInAufruflistenGeschwaerzt(string $class, string $method): void {
		$found = false;
		foreach ((new \ReflectionMethod($class, $method))->getParameters() as $parameter) {
			if ($parameter->getName() !== 'password') {
				continue;
			}
			$found = true;
			$this->assertNotSame([], $parameter->getAttributes(\SensitiveParameter::class), $class . '::' . $method . '() gibt das Passwort ungeschützt in die Aufrufliste.');
		}
		$this->assertTrue($found, $class . '::' . $method . '() hat keinen Parameter $password mehr – Liste anpassen.');
	}

	/** Kein weiterer Dienst der Sicherung nimmt ein Passwort entgegen, ohne hier zu stehen. */
	public function testListeIstVollstaendig(): void {
		$listed = [];
		foreach (self::methodsTakingThePassword() as [$class, $method]) {
			$listed[] = $class . '::' . $method;
		}
		$actual = [];
		foreach (glob(dirname(__DIR__, 2) . '/lib/Service/Backup/*.php') ?: [] as $file) {
			$class = 'OCA\\Vereinsbuchhaltung\\Service\\Backup\\' . basename($file, '.php');
			foreach ((new \ReflectionClass($class))->getMethods() as $method) {
				foreach ($method->getParameters() as $parameter) {
					if ($parameter->getName() === 'password') {
						$actual[] = $class . '::' . $method->getName();
					}
				}
			}
		}
		sort($listed);
		sort($actual);
		$this->assertSame($listed, $actual);
	}
}
