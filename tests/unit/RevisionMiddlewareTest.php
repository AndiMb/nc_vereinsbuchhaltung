<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Middleware\NoRevisionBump;
use OCA\Vereinsbuchhaltung\Middleware\RevisionMiddleware;
use OCA\Vereinsbuchhaltung\Service\RevisionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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
		$this->revision->expects($this->once())->method('bump');
		$this->dispatch('POST', 'plain');
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
		$this->revision->expects($this->once())->method('bump');
		$this->dispatch('POST', 'bulk', ['linked' => [['journalId' => 1, 'fileId' => 2]], 'skipped' => []]);
	}
}
