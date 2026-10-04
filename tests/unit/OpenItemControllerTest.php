<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\OpenItemController;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Exception\ClaimManagedException;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\OpenItemService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die vier schreibenden Endpunkte der generischen Offene-Posten-Verwaltung
 * (Issue #121): Forderungen des Beitragsmoduls kommen als 400 mit Hinweis
 * zurück und hinterlassen kein Protokoll (es hat sich nichts geändert), ein
 * fehlender Posten bleibt ein 404, ein freier Posten läuft wie bisher.
 * Gemeint ist der Controller; WAS als Forderung gilt, entscheidet der Dienst
 * (OpenItemServiceTest).
 */
class OpenItemControllerTest extends TestCase {

	private OpenItemService&MockObject $service;
	private AuditService&MockObject $audit;

	protected function setUp(): void {
		$this->service = $this->createMock(OpenItemService::class);
		$this->audit = $this->createMock(AuditService::class);
	}

	private function controller(): OpenItemController {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return new OpenItemController($this->createMock(IRequest::class), $this->service, $this->audit, $l10n);
	}

	private function declaredRole(string $method): ?string {
		$attributes = (new \ReflectionMethod(OpenItemController::class, $method))->getAttributes(RequiresRole::class);
		return $attributes === [] ? null : $attributes[0]->newInstance()->role;
	}

	private function freeItem(): OpenItem {
		$item = new OpenItem();
		$item->setId(4);
		$item->setDebtor('Schreinerei Holz');
		return $item;
	}

	/** @return array<string, array{string, list<mixed>}> */
	public static function writeEndpoints(): array {
		return [
			'bezahlt' => ['markPaid', [9]],
			'bezahlt mit Buchung' => ['markPaid', [9, 77]],
			'storniert' => ['cancel', [9]],
			'wieder geöffnet' => ['reopen', [9]],
			'gelöscht' => ['destroy', [9]],
		];
	}

	/**
	 * Der Dienst kennt die Methoden unter anderem Namen als der Endpunkt
	 * (`destroy` ruft `delete`); `destroy` liest vorher den Posten für das Protokoll.
	 */
	private function serviceMethodFor(string $endpoint): string {
		return $endpoint === 'destroy' ? 'delete' : $endpoint;
	}

	/**
	 * @dataProvider writeEndpoints
	 * @param list<mixed> $arguments
	 */
	public function testEineForderungKommtAls400MitHinweisZurueck(string $endpoint, array $arguments): void {
		$this->service->method('find')->willReturn($this->freeItem());
		$this->service->method($this->serviceMethodFor($endpoint))->willThrowException(new ClaimManagedException('Beitragsforderungen werden im Einzug-Reiter bearbeitet.'));
		$this->audit->expects($this->never())->method('log');

		$response = $this->controller()->$endpoint(...$arguments);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Einzug-Reiter', $response->getData()['message']);
	}

	/**
	 * @dataProvider writeEndpoints
	 * @param list<mixed> $arguments
	 */
	public function testEinFehlenderPostenBleibtEin404(string $endpoint, array $arguments): void {
		$this->service->method('find')->willThrowException(new DoesNotExistException('weg'));
		$this->service->method($this->serviceMethodFor($endpoint))->willThrowException(new DoesNotExistException('weg'));

		$response = $this->controller()->$endpoint(...$arguments);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testEinFreierPostenWirdBezahltUndProtokolliert(): void {
		$item = $this->freeItem();
		$this->service->expects($this->once())->method('markPaid')->with(4, null)->willReturn($item);
		$this->audit->expects($this->once())->method('log')->with('Offener Posten als bezahlt markiert', 'open_item', 4);

		$response = $this->controller()->markPaid(4);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($item, $response->getData());
	}

	public function testEinFreierPostenWirdStorniertUndProtokolliert(): void {
		$this->service->expects($this->once())->method('cancel')->with(4)->willReturn($this->freeItem());
		$this->audit->expects($this->once())->method('log')->with('Offener Posten storniert', 'open_item', 4);

		$this->assertSame(Http::STATUS_OK, $this->controller()->cancel(4)->getStatus());
	}

	public function testEinFreierPostenWirdWiedergeoeffnet(): void {
		$this->service->expects($this->once())->method('reopen')->with(4)->willReturn($this->freeItem());

		$this->assertSame(Http::STATUS_OK, $this->controller()->reopen(4)->getStatus());
	}

	public function testEinFreierPostenWirdGeloeschtUndProtokolliert(): void {
		$this->service->method('find')->with(4)->willReturn($this->freeItem());
		$this->service->expects($this->once())->method('delete')->with(4);
		$this->audit->expects($this->once())->method('log')->with('Offener Posten gelöscht', 'open_item', 4);

		$this->assertSame(Http::STATUS_OK, $this->controller()->destroy(4)->getStatus());
	}

	public function testDieSchreibendenEndpunkteTragenAusdruecklichDieSchreibrolle(): void {
		foreach (['markPaid', 'cancel', 'reopen', 'destroy'] as $method) {
			$this->assertSame(PermissionService::ROLE_WRITE, $this->declaredRole($method), $method);
		}
	}
}
