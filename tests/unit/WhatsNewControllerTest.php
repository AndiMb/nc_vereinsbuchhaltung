<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\WhatsNewController;
use OCA\Vereinsbuchhaltung\Service\UpdateHintSettings;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\AppConfigStub;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * GET /whatsnew meldet neben laufender und zuletzt gesehener Version den
 * vereinsweiten Schalter „Hinweise auf neue Versionen“ – danach entscheidet
 * die Oberfläche, ob der Splash von selbst aufgeht (src/data/whatsNew.js,
 * whatsNewBootAction()).
 */
class WhatsNewControllerTest extends TestCase {

	/** @param array<string, string> $settings */
	private function index(array $settings = []): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('kassierer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('getValueString')->willReturn('0.56.0');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('0.57.0');

		$controller = new WhatsNewController(
			$this->createMock(IRequest::class),
			$userConfig,
			$apps,
			$session,
			new UpdateHintSettings(AppConfigStub::create($this, $settings)),
		);
		return $controller->index()->getData();
	}

	public function testHinweiseSindAbWerkEingeschaltet(): void {
		$this->assertSame(['currentVersion' => '0.57.0', 'lastSeenVersion' => '0.56.0', 'enabled' => true], $this->index());
	}

	public function testAusgeschalteteHinweiseMeldetDerEndpunkt(): void {
		$this->assertFalse($this->index(['update_hints_enabled' => '0'])['enabled']);
	}
}
