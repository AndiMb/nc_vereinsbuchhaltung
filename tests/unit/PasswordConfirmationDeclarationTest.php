<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\ImportController;
use OCA\Vereinsbuchhaltung\Controller\PermissionController;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #260: Alles-löschen und Rollenvergabe dürfen nicht mit einer geklauten
 * Sitzung allein gehen. Nextcloud prüft die Passwortbestätigung in einer eigenen
 * Middleware, aber nur, wenn die Methode das Attribut trägt – ohne fällt das
 * ersatzlos weg und kein Test würde es merken.
 */
class PasswordConfirmationDeclarationTest extends TestCase {

	/** @return array<string, array{class-string, string}> */
	public static function destructiveActions(): array {
		return [
			'Alle Daten zurücksetzen' => [ImportController::class, 'reset'],
			'xbuc-Import mit Löschen aller Daten' => [ImportController::class, 'xbucCommitWithReset'],
			'Rolle vergeben' => [PermissionController::class, 'setRole'],
			'Rolle entziehen' => [PermissionController::class, 'destroy'],
		];
	}

	#[DataProvider('destructiveActions')]
	public function testZerstoererischeAktionenVerlangenPasswortbestaetigung(string $class, string $method): void {
		$attributes = (new \ReflectionMethod($class, $method))->getAttributes(PasswordConfirmationRequired::class);
		$this->assertNotEmpty($attributes, "$class::$method braucht #[PasswordConfirmationRequired]");
	}
}
