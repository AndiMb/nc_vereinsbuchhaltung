<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\BudgetStatusSettings;
use OCA\Vereinsbuchhaltung\Service\Report\BudgetStatus;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Die vereinsweit einstellbaren Ampel-Schwellen der visuellen
 * Gliederungsansicht: gespeichert als ein JSON-Wert, beim Lesen tolerant
 * (alles Ungültige gilt als „nicht eingestellt“), beim Schreiben streng.
 */
class BudgetStatusSettingsTest extends TestCase {

	private const VALID = ['expWarn' => 70, 'expCrit' => 110, 'incGood' => 95, 'incWarn' => 60, 'resTol' => 10];

	/** @var array<string, string> */
	private array $store = [];

	private function settings(?IL10N $l10n = null): BudgetStatusSettings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->store[$key] = $value;
			return true;
		});
		return new BudgetStatusSettings($config, $l10n);
	}

	public function testOhneEintragGeltenDieVorgaben(): void {
		$this->assertSame(BudgetStatus::DEFAULTS, $this->settings()->thresholds());
	}

	/** @return array<string, array{string}> */
	public static function ungueltigGespeichert(): array {
		return [
			'Reihenfolge verletzt' => [json_encode(['expWarn' => 130, 'expCrit' => 120, 'incGood' => 100, 'incWarn' => 80, 'resTol' => 20])],
			'kaputtes JSON' => ['{"expWarn":'],
			'kein Objekt' => ['42'],
			'Schlüssel fehlt' => [json_encode(['expWarn' => 80, 'expCrit' => 120, 'incGood' => 100, 'incWarn' => 80])],
			'falscher Typ' => [json_encode(['expWarn' => 'viel', 'expCrit' => 120, 'incGood' => 100, 'incWarn' => 80, 'resTol' => 20])],
			'Bruchzahl' => [json_encode(['expWarn' => 80.5, 'expCrit' => 120, 'incGood' => 100, 'incWarn' => 80, 'resTol' => 20])],
			'außerhalb des Bereichs' => [json_encode(['expWarn' => 80, 'expCrit' => 1200, 'incGood' => 100, 'incWarn' => 80, 'resTol' => 20])],
		];
	}

	/** @dataProvider ungueltigGespeichert */
	public function testUngueltigerGespeicherterWertGiltAlsVorgabe(string $stored): void {
		$this->store[BudgetStatusSettings::KEY] = $stored;

		$this->assertSame(BudgetStatus::DEFAULTS, $this->settings()->thresholds());
	}

	public function testSetSpeichertUndThresholdsLiefertDieWerte(): void {
		$this->settings()->set(self::VALID);

		$this->assertArrayHasKey(BudgetStatusSettings::KEY, $this->store);
		$this->assertSame(self::VALID, $this->settings()->thresholds());
	}

	public function testSetNimmtGanzzahligeZeichenkettenAn(): void {
		$this->settings()->set(['expWarn' => '70', 'expCrit' => '110', 'incGood' => '95', 'incWarn' => '60', 'resTol' => '0']);

		$this->assertSame(['expWarn' => 70, 'expCrit' => 110, 'incGood' => 95, 'incWarn' => 60, 'resTol' => 0], $this->settings()->thresholds());
	}

	public function testSetNimmtDieGrenzenAn(): void {
		$this->settings()->set(['expWarn' => 0, 'expCrit' => 999, 'incGood' => 999, 'incWarn' => 0, 'resTol' => 999]);

		$this->assertSame(999, $this->settings()->thresholds()['expCrit']);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function ungueltigeEingaben(): array {
		return [
			'Ausgaben gleich' => [['expWarn' => 120, 'expCrit' => 120] + self::VALID],
			'Ausgaben vertauscht' => [['expWarn' => 130, 'expCrit' => 120] + self::VALID],
			'Einnahmen gleich' => [['incWarn' => 100, 'incGood' => 100] + self::VALID],
			'Schlüssel fehlt' => [array_diff_key(self::VALID, ['resTol' => true])],
			'negativ' => [['resTol' => -1] + self::VALID],
			'zu groß' => [['expCrit' => 1000] + self::VALID],
			'keine Zahl' => [['expWarn' => 'abc'] + self::VALID],
			'Bruchzahl' => [['expWarn' => 80.5] + self::VALID],
			'Bruchzahl als Text' => [['expWarn' => '80.5'] + self::VALID],
			'leer' => [['resTol' => ''] + self::VALID],
			'Liste' => [['resTol' => [20]] + self::VALID],
		];
	}

	/**
	 * @dataProvider ungueltigeEingaben
	 * @param array<string, mixed> $values
	 */
	public function testSetLehntUngueltigeWerteAbUndSpeichertNichts(array $values): void {
		try {
			$this->settings()->set($values);
			$this->fail('Ausnahme erwartet');
		} catch (\InvalidArgumentException $e) {
			$this->assertNotSame('', $e->getMessage());
		}
		$this->assertArrayNotHasKey(BudgetStatusSettings::KEY, $this->store);
	}

	public function testUnbekannterSchluesselWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->settings()->set(self::VALID + ['extra' => 1]);
	}

	public function testMeldungenLaufenUeberDieUebersetzung(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params = []): string => 'EN: ' . vsprintf($text, $params));

		$this->expectExceptionMessageMatches('/^EN: /');
		$this->settings($l10n)->set(['expWarn' => 120, 'expCrit' => 120] + self::VALID);
	}
}
