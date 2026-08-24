<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\TranslationBundle;
use PHPUnit\Framework\TestCase;

/**
 * Das Bündel wird über einen eigenen Endpunkt ausgeliefert, weil Nextclouds
 * .htaccess keine .json-Dateien aus dem App-Verzeichnis herausgibt – ohne ihn
 * blieb die Oberfläche in jeder Sprache deutsch. Geprüft wird hier vor allem,
 * dass der Sprachcode aus der Anfrage kein Schlupfloch ins Dateisystem öffnet.
 */
class TranslationBundleTest extends TestCase {

	private TranslationBundle $bundle;

	protected function setUp(): void {
		$this->bundle = new TranslationBundle(dirname(__DIR__, 2) . '/l10n');
	}

	public function testLiefertVorhandenesBuendel(): void {
		$json = json_decode($this->bundle->read('en'), true);

		$this->assertIsArray($json);
		$this->assertArrayHasKey('translations', $json);
		$this->assertNotEmpty($json['translations'], 'Das englische Bündel darf nicht leer sein.');
	}

	/** Für Sprachen ohne Übersetzung ist ein leeres Bündel die richtige Antwort. */
	public function testUnbekannteSpracheLiefertLeeresBuendel(): void {
		$this->assertSame(TranslationBundle::EMPTY_BUNDLE, $this->bundle->read('fi'));
	}

	/**
	 * @dataProvider sprachcodes
	 */
	public function testSprachcodePruefung(string $lang, bool $erlaubt): void {
		$this->assertSame($erlaubt, $this->bundle->isValidLanguage($lang));
	}

	/** @return array<string, array{string, bool}> */
	public static function sprachcodes(): array {
		return [
			'einfach' => ['en', true],
			'mit Region' => ['pt_BR', true],
			'dreibuchstabig' => ['ast', true],
			'Pfadwechsel' => ['../appinfo/info', false],
			'absoluter Pfad' => ['/etc/passwd', false],
			'mit Endung' => ['en.json', false],
			'leer' => ['', false],
			'zu lang' => ['deutschland', false],
			'Grossbuchstaben' => ['EN', false],
		];
	}

	/** Ein unerlaubter Code darf auch über read() nichts ausliefern. */
	public function testUnerlaubterCodeLiestNichts(): void {
		$this->assertSame(TranslationBundle::EMPTY_BUNDLE, $this->bundle->read('../composer'));
	}
}
