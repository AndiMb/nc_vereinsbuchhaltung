<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Middleware\NoRevisionBump;
use OCA\Vereinsbuchhaltung\Middleware\RevisionMiddleware;
use OCA\Vereinsbuchhaltung\Service\RevisionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Der Änderungsstand steigt nach Schreibzugriffen, aber nicht nach
 * Methoden, die per POST nur lesen (#[NoRevisionBump]).
 */
class RevisionMiddlewareTest extends TestCase {
	private RevisionService&MockObject $revision;
	private IRequest&MockObject $request;
	private Controller $controller;

	protected function setUp(): void {
		$this->revision = $this->createMock(RevisionService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->controller = new class('vereinsbuchhaltung', $this->createMock(IRequest::class)) extends Controller {
			public function plain(): void {
			}

			#[NoRevisionBump]
			public function readOnly(): void {
			}

			#[NoRevisionBump(unlessNonEmpty: 'linked')]
			public function bulk(): void {
			}
		};
	}

	private function dispatch(string $verb, string $method, mixed $data = []): void {
		$this->request->method('getMethod')->willReturn($verb);
		(new RevisionMiddleware($this->revision, $this->request))
			->afterController($this->controller, $method, new DataResponse($data));
	}

	public function testNormalerPostErhoehtDenStand(): void {
		$this->revision->expects($this->once())->method('bump')->willReturn(['previous' => 'r0', 'current' => 'r1']);
		$this->dispatch('POST', 'plain');
	}

	/** Der schreibende Browser erfährt Stand davor und danach (gegen das eigene Echo im Abgleich). */
	public function testNormalerPostMeldetDenStandImHeader(): void {
		$this->revision->method('bump')->willReturn(['previous' => 'r0', 'current' => 'r1']);
		$this->assertSame(
			['X-Vbh-Revision-Previous' => 'r0', 'X-Vbh-Revision' => 'r1'],
			$this->headersAfter('POST'),
		);
	}

	public function testGetMeldetKeinenStandImHeader(): void {
		$this->assertSame([], $this->headersAfter('GET'));
	}

	/**
	 * Gesetzte Header über ein Mock: Response::getHeaders() greift im
	 * OCP-Stub auf \OC zu, das es ohne laufende Nextcloud nicht gibt.
	 *
	 * @return array<string, string>
	 */
	private function headersAfter(string $verb): array {
		$this->request->method('getMethod')->willReturn($verb);
		$headers = [];
		$response = $this->createMock(DataResponse::class);
		$response->method('getStatus')->willReturn(200);
		$response->method('addHeader')->willReturnCallback(function (string $name, string $value) use (&$headers, $response) {
			$headers[$name] = $value;
			return $response;
		});
		(new RevisionMiddleware($this->revision, $this->request))
			->afterController($this->controller, 'plain', $response);
		return $headers;
	}

	public function testGetErhoehtDenStandNicht(): void {
		$this->revision->expects($this->never())->method('bump');
		$this->dispatch('GET', 'plain');
	}

	public function testPostMitAttributErhoehtDenStandNicht(): void {
		$this->revision->expects($this->never())->method('bump');
		$this->dispatch('POST', 'readOnly');
	}

	public function testSammelbestaetigungOhneVerknuepftesPaarErhoehtNicht(): void {
		$this->revision->expects($this->never())->method('bump');
		$this->dispatch('POST', 'bulk', ['linked' => [], 'skipped' => [['journalId' => 1]]]);
	}

	public function testSammelbestaetigungMitVerknuepftemPaarErhoeht(): void {
		$this->revision->expects($this->once())->method('bump')->willReturn(['previous' => 'r0', 'current' => 'r1']);
		$this->dispatch('POST', 'bulk', ['linked' => [['journalId' => 1, 'fileId' => 2]], 'skipped' => []]);
	}

	/**
	 * Jede Vorschau-Route, die per POST läuft (der Formularstand geht als Body
	 * mit), muss #[NoRevisionBump] tragen. Sonst meldeten alle anderen offenen
	 * Browser beim nächsten Abgleich eine Fremdänderung und lüden die gesamte
	 * Ansicht neu – bei der Fälligkeitsvorschau sogar bei jeder Eingabe.
	 */
	public function testPostVorschauRoutenErhoehenDenStandNicht(): void {
		$routes = require dirname(__DIR__, 2) . '/appinfo/routes.php';
		$checked = 0;
		foreach ($routes['routes'] as $route) {
			[$controller, $method] = explode('#', $route['name']);
			if (($route['verb'] ?? 'GET') !== 'POST' || stripos($method, 'preview') === false) {
				continue;
			}
			$class = 'OCA\\Vereinsbuchhaltung\\Controller\\' . ucfirst($controller) . 'Controller';
			$attributes = (new \ReflectionMethod($class, $method))->getAttributes(NoRevisionBump::class);
			$this->assertNotSame([], $attributes, $route['name'] . ' fehlt #[NoRevisionBump]');
			$checked++;
		}
		$this->assertGreaterThanOrEqual(8, $checked);
	}
}
