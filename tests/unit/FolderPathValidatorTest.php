<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\FolderPathValidator;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Die gemeinsamen Ordnerpfad-Regeln der Einstellungen (Belegablage,
 * Wachordner, Nachweis-Ordner, XML-Ablage), siehe {@see FolderPathValidator}.
 */
class FolderPathValidatorTest extends TestCase {

	private function validator(): FolderPathValidator {
		$l10n = $this->createMock(IL10N::class);
		// Die Meldung wird hier nur auf ihren Inhalt geprüft, nicht auf die
		// Übersetzung: t() gibt den Quelltext mit eingesetzten Platzhaltern zurück.
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));
		return new FolderPathValidator($l10n);
	}

	/** @dataProvider gueltigePfade */
	public function testGueltigePfadeWerdenAkzeptiert(string $path): void {
		$this->assertNull($this->validator()->validate($path, 'Ordner'));
	}

	/** @return array<string, array{string}> */
	public static function gueltigePfade(): array {
		return [
			'leer (Standardpfad)' => [''],
			'nur Schrägstriche' => ['///'],
			'einfach' => ['SEPA-Mandate'],
			'verschachtelt' => ['Verein/SEPA/Einreichungen'],
			'mit Umlauten und Leerzeichen' => ['Vereinsbuchhaltung/Kontoauszüge 2026'],
			'Windows-Schrägstriche' => ['Verein\\SEPA'],
			'führender Schrägstrich' => ['/Verein/SEPA/'],
		];
	}

	/** @dataProvider ungueltigePfade */
	public function testUngueltigePfadeWerdenMitMeldungAbgelehnt(string $path, string $erwarteterTeil): void {
		$error = $this->validator()->validate($path, 'Ablageordner');

		$this->assertNotNull($error);
		$this->assertStringContainsString('Ablageordner', $error);
		$this->assertStringContainsString($erwarteterTeil, $error);
	}

	/** @return array<string, array{string, string}> */
	public static function ungueltigePfade(): array {
		return [
			'Elternordner' => ['Verein/../geheim', 'nicht erlaubt'],
			'aktueller Ordner' => ['Verein/./SEPA', 'nicht erlaubt'],
			'doppelter Schrägstrich' => ['Verein//SEPA', 'nicht erlaubt'],
			'Doppelpunkt' => ['C:/Verein', 'unzulässige Zeichen'],
			'Fragezeichen' => ['Verein?', 'unzulässige Zeichen'],
			'Stern' => ['Verein*', 'unzulässige Zeichen'],
			'spitze Klammer' => ['<Verein>', 'unzulässige Zeichen'],
			'senkrechter Strich' => ['Ver|ein', 'unzulässige Zeichen'],
			'Anführungszeichen' => ['Ver"ein', 'unzulässige Zeichen'],
			'zu lang' => [str_repeat('a', FolderPathValidator::MAX_LENGTH + 1), 'zu lang'],
		];
	}

	public function testGenauMaximaleLaengeIstErlaubt(): void {
		$this->assertNull($this->validator()->validate(str_repeat('a', FolderPathValidator::MAX_LENGTH), 'Ordner'));
	}
}
