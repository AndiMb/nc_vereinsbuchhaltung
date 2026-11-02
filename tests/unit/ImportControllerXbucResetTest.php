<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\ImportController;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\DemoDataService;
use OCA\Vereinsbuchhaltung\Service\ImportService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\ResetService;
use OCA\Vereinsbuchhaltung\Service\XbucImportService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Issue #260/#261: Der xbuc-Import mit „vorher alles löschen“ ruft
 * ResetService::resetAll() auf und braucht deshalb dieselbe
 * Passwortbestätigung wie „Alle Daten löschen“. Das geht nur über eine eigene
 * Route; die normale Import-Route lehnt `reset=1` ab, statt still zu löschen.
 */
class ImportControllerXbucResetTest extends TestCase {

	public function testNormaleImportRouteLehntResetAbOhneEtwasZuLoeschen(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null) => ['reset' => '1', 'content' => 'x'][$key] ?? $default,
		);
		$xbuc = $this->createMock(XbucImportService::class);
		$xbuc->expects($this->never())->method('import');
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('isAdmin')->willReturn(true);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$controller = new ImportController(
			$request,
			$this->createMock(ImportService::class),
			$xbuc,
			$this->createMock(ResetService::class),
			$permissions,
			$this->createMock(AuditService::class),
			$this->createMock(DemoDataService::class),
			$l10n,
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->xbucCommit()->getStatus());
	}

	public function testResetVarianteVerlangtPasswortbestaetigungUndVerwalterrolle(): void {
		$method = new \ReflectionMethod(ImportController::class, 'xbucCommitWithReset');

		$this->assertNotEmpty($method->getAttributes(PasswordConfirmationRequired::class));
		$role = $method->getAttributes(RequiresRole::class);
		$this->assertCount(1, $role);
		$this->assertSame([PermissionService::ROLE_ADMIN], $role[0]->getArguments());
	}

	public function testResetVarianteHatEigeneRoute(): void {
		$config = require dirname(__DIR__, 2) . '/appinfo/routes.php';
		$routes = array_filter($config['routes'], fn (array $r) => $r['name'] === 'import#xbucCommitWithReset');

		$this->assertCount(1, $routes);
		$route = array_values($routes)[0];
		$this->assertSame('POST', $route['verb']);
		$this->assertSame('/api/import/xbuc/reset', $route['url']);
	}
}
