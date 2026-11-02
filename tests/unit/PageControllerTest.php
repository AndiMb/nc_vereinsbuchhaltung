<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\PageController;
use OCA\Vereinsbuchhaltung\Service\FrontendStateService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Die Seite bringt die Startwerte gleich mit (Issue #264): statt nach dem
 * Laden drei Anfragen hintereinander (`permissions/me`, `periods`,
 * `settings`) stehen sie als Initial State im HTML. Was ein Konto über die
 * API nicht lesen dürfte, darf auch hier nicht hinein.
 *
 * In eigenen Prozessen: Util::addScript() braucht den Server-Container
 * `\OC::$server`, den hier ein Stub (tests/stubs/OC.php) stellt. Einmal
 * geladen, bliebe die Klasse `\OC` für alle folgenden Tests bestehen – und
 * Code, der wie InvoiceSuiteEInvoiceWriter an `class_exists(\OC::class)`
 * erkennt, ob er in Nextcloud läuft, verhielte sich dort plötzlich anders.
 */
#[RunTestsInSeparateProcesses]
class PageControllerTest extends TestCase {

	private IInitialState&MockObject $initialState;
	private FrontendStateService&MockObject $frontendState;
	private PermissionService&MockObject $permissions;
	/** @var array<string, mixed> */
	private array $provided = [];

	protected function setUp(): void {
		require_once dirname(__DIR__) . '/stubs/OC.php';
		// Util::addScript()/addTranslations() fragen den Server nach der Sprache.
		$factory = $this->createMock(IFactory::class);
		$factory->method('findLanguage')->willReturn('de_DE');
		$server = $this->createMock(ContainerInterface::class);
		$server->method('get')->willReturnMap([[IFactory::class, $factory]]);
		\OC::$server = $server;

		$this->provided = [];
		$this->initialState = $this->createMock(IInitialState::class);
		$this->initialState->method('provideInitialState')->willReturnCallback(function (string $key, $data): void {
			$this->provided[$key] = $data;
		});
		$this->frontendState = $this->createMock(FrontendStateService::class);
		$this->frontendState->method('me')->willReturn(['role' => 'revisor', 'canRead' => true]);
		$this->frontendState->method('periods')->willReturn([['id' => 1, 'bookings' => 3]]);
		$this->frontendState->method('settings')->willReturn(['club_name' => 'TSV']);
		$this->permissions = $this->createMock(PermissionService::class);
	}

	private function controller(?LoggerInterface $logger = null): PageController {
		return new PageController(
			$this->createMock(IRequest::class),
			$this->initialState,
			$this->frontendState,
			$this->permissions,
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}

	public function testRevisorBekommtMePeriodsSettings(): void {
		$this->permissions->method('canRead')->willReturn(true);

		$this->controller()->index();

		$this->assertSame(['me', 'periods', 'settings'], array_keys($this->provided));
		$this->assertSame(['role' => 'revisor', 'canRead' => true], $this->provided['me']);
		$this->assertSame([['id' => 1, 'bookings' => 3]], $this->provided['periods']);
		$this->assertSame(['club_name' => 'TSV'], $this->provided['settings']);
	}

	public function testOhneLeserolleNurMe(): void {
		$this->permissions->method('canRead')->willReturn(false);
		$this->frontendState->expects($this->never())->method('periods');
		$this->frontendState->expects($this->never())->method('settings');

		$this->controller()->index();

		$this->assertSame(['me'], array_keys($this->provided));
	}

	/**
	 * Ein Fehler beim Zusammenstellen eines Startwerts darf die Seite nicht mit
	 * einem 500 abbrechen: die Oberfläche holt den fehlenden Wert dann wie
	 * bisher über die API und zeigt dort ihre eigene Fehlermeldung.
	 */
	public function testFehlerBeimStartwertLaesstDieSeiteStehen(): void {
		$this->permissions->method('canRead')->willReturn(true);
		$frontendState = $this->createMock(FrontendStateService::class);
		$frontendState->method('me')->willReturn(['role' => 'verwalter']);
		$frontendState->method('periods')->willThrowException(new \RuntimeException('DB weg'));
		$frontendState->method('settings')->willReturn(['club_name' => 'TSV']);
		$this->frontendState = $frontendState;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->controller($logger)->index();

		$this->assertSame(['me', 'settings'], array_keys($this->provided));
	}

	public function testUebersetzungenWerdenAlsSkriptAngemeldet(): void {
		$this->permissions->method('canRead')->willReturn(false);

		$this->controller()->index();

		$scripts = (new \ReflectionProperty(\OCP\Util::class, 'scripts'))->getValue();
		$this->assertContains('vereinsbuchhaltung/l10n/de_DE', $scripts['vereinsbuchhaltung'] ?? []);
	}
}
