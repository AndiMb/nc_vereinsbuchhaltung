<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\DebitBatchController;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleSettings;
use OCA\Vereinsbuchhaltung\Service\DebitBatchService;
use OCA\Vereinsbuchhaltung\Service\DebitBatchXmlStorageService;
use OCA\Vereinsbuchhaltung\Service\FolderPathValidator;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * DebitBatchController::updateSettings() (Issue #101): Freigabe-Vorlauf und
 * XML-Ablage werden zusammen geschickt, also erst prüfen, dann schreiben –
 * ein abgelehntes Feld hinterlässt keine halb gespeicherte Gruppe. Den
 * Ablage-Nutzer verlangt der Server nur beim EINSCHALTEN.
 */
class DebitBatchControllerSettingsTest extends TestCase {

	private DebitBatchXmlStorageService&MockObject $xmlStorage;
	/** @var array<string, string> */
	private array $store = [];

	protected function setUp(): void {
		$this->store = [];
		$this->xmlStorage = $this->createMock(DebitBatchXmlStorageService::class);
		$this->xmlStorage->method('isEnabled')->willReturn(false);
		$this->xmlStorage->method('isConfigured')->willReturn(true);
		$this->xmlStorage->method('folderPath')->willReturn('SEPA-Einreichungen');
	}

	private function controller(): DebitBatchController {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default);
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->store[$key] = $value;
		});
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));

		return new DebitBatchController(
			$this->createMock(IRequest::class),
			$this->createMock(DebitBatchService::class),
			$this->xmlStorage,
			new ContributionCycleSettings($config),
			new FolderPathValidator($l10n),
			$l10n,
		);
	}

	public function testGueltigeEinstellungenWerdenGeschrieben(): void {
		$this->xmlStorage->expects($this->once())->method('setEnabled')->with(true);
		$this->xmlStorage->expects($this->once())->method('setFolderPath')->with('E2E-Einreichungen');

		$response = $this->controller()->updateSettings(7, '1', 'E2E-Einreichungen');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('7', $this->store['release_lead_days']);
		$this->assertSame(7, $response->getData()['releaseLeadDays']);
	}

	public function testNurWasGesendetWurdeWirdGeschrieben(): void {
		$this->xmlStorage->expects($this->never())->method('setEnabled');
		$this->xmlStorage->expects($this->never())->method('setFolderPath');

		$response = $this->controller()->updateSettings(9, null, null);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('9', $this->store['release_lead_days']);
	}

	public function testUngueltigerOrdnerWirdAbgelehntUndNichtsWirdGeschrieben(): void {
		$this->xmlStorage->expects($this->never())->method('setEnabled');
		$this->xmlStorage->expects($this->never())->method('setFolderPath');

		$response = $this->controller()->updateSettings(7, '1', '../raus');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Ablageordner für die XML-Dateien', $response->getData()['message']);
		$this->assertStringContainsString('nicht erlaubt', $response->getData()['message']);
		$this->assertArrayNotHasKey('release_lead_days', $this->store, 'auch der gültige Vorlauf wird nicht halb gespeichert');
	}

	public function testEinschaltenOhneAblageNutzerWirdAbgelehnt(): void {
		$xmlStorage = $this->createMock(DebitBatchXmlStorageService::class);
		$xmlStorage->method('isEnabled')->willReturn(false);
		$xmlStorage->method('isConfigured')->willReturn(false);
		$xmlStorage->expects($this->never())->method('setEnabled');
		$this->xmlStorage = $xmlStorage;

		$response = $this->controller()->updateSettings(7, '1', 'SEPA-Einreichungen');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Nextcloud-Nutzer', $response->getData()['message']);
		$this->assertArrayNotHasKey('release_lead_days', $this->store);
	}

	/** Ausschalten und ein bereits laufendes Einschalten brauchen den Nutzer nicht (er kann inzwischen fehlen). */
	public function testAusschaltenUndUnveraendertEingeschaltetBlockierenNichtOhneAblageNutzer(): void {
		$disabled = $this->createMock(DebitBatchXmlStorageService::class);
		$disabled->method('isEnabled')->willReturn(true);
		$disabled->method('isConfigured')->willReturn(false);
		$disabled->method('folderPath')->willReturn('SEPA-Einreichungen');
		$disabled->expects($this->exactly(2))->method('setEnabled');
		$this->xmlStorage = $disabled;
		$controller = $this->controller();

		$this->assertSame(Http::STATUS_OK, $controller->updateSettings(7, '0', null)->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->updateSettings(7, '1', null)->getStatus(), 'schon eingeschaltet: unverändert mitgesendet blockiert nichts');
	}

	/** @dataProvider ungueltigeVorlaeufe */
	public function testUnplausiblerVorlaufWirdAbgelehntUndLaesstDieAblageUnangetastet(int $days): void {
		$this->xmlStorage->expects($this->never())->method('setEnabled');
		$this->xmlStorage->expects($this->never())->method('setFolderPath');

		$response = $this->controller()->updateSettings($days, '1', 'E2E-Einreichungen');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayNotHasKey('release_lead_days', $this->store);
	}

	/** @return array<string, array{int}> */
	public static function ungueltigeVorlaeufe(): array {
		return ['null' => [0], 'negativ' => [-3], 'ueber einem Jahr' => [366]];
	}
}
