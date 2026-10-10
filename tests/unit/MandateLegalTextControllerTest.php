<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\MandateLegalTextController;
use OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersion;
use OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersionMapper;
use OCA\Vereinsbuchhaltung\Service\MandateLegalTextService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Die Antworten des Rechtstext-Controllers (Issue #101): Pflichtblock und
 * Rahmen getrennt, nie der Textkörper mit dem internen Rahmen-Marker (der
 * zuletzt in #67 als Text sichtbar wurde), Verlauf mit laufender Nummer.
 */
class MandateLegalTextControllerTest extends TestCase {

	/** @var array<int, MandateLegalTextVersion> */
	private array $stored = [];

	protected function setUp(): void {
		$this->stored = [];
	}

	private function controller(): MandateLegalTextController {
		$mapper = $this->createMock(MandateLegalTextVersionMapper::class);
		$mapper->method('insert')->willReturnCallback(function (MandateLegalTextVersion $v): MandateLegalTextVersion {
			$v->setId(count($this->stored) + 1);
			$this->stored[$v->getId()] = $v;
			return $v;
		});
		$mapper->method('findLatest')->willReturnCallback(fn () => $this->stored === [] ? null : $this->stored[array_key_last($this->stored)]);
		$mapper->method('findAll')->willReturnCallback(fn () => array_reverse(array_values($this->stored)));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));

		return new MandateLegalTextController($this->createMock(IRequest::class), new MandateLegalTextService($mapper, $l10n));
	}

	public function testAktuelleFassungLiefertPflichtblockUndRahmenGetrennt(): void {
		$data = $this->controller()->current()->getData();

		$this->assertStringContainsString('SEPA-Lastschriftmandat', $data['pflichtblock']);
		$this->assertSame('', $data['rahmen']);
		$this->assertSame($data['pflichtblock'], $data['version']['pflichtblock']);
		$this->assertSame(1, $data['version']['number']);
		$this->assertSame(MandateLegalTextVersion::CREATED_BY_SYSTEM, $data['version']['createdBy']);
	}

	public function testKeineAntwortEnthaeltDenTextkoerperOderDenInternenMarker(): void {
		$controller = $this->controller();
		$controller->update('Unser Rahmen');

		foreach ([$controller->current()->getData(), $controller->history()->getData()] as $payload) {
			$json = json_encode($payload, JSON_THROW_ON_ERROR);
			$this->assertStringNotContainsString('vbh:rahmen', $json);
			$this->assertStringNotContainsString('<!--', $json);
		}
		$this->assertArrayNotHasKey('body', $controller->current()->getData()['version']);
	}

	public function testUpdateLegtNeueFassungAnUndLiefertSieAlsAktuelle(): void {
		$controller = $this->controller();

		$response = $controller->update('Wir ziehen im März ein.');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('Wir ziehen im März ein.', $data['rahmen']);
		$this->assertSame('Wir ziehen im März ein.', $data['version']['rahmen']);
		$this->assertSame(MandateLegalTextVersion::CREATED_BY_VERWALTER, $data['version']['createdBy']);
		$this->assertSame(2, $data['version']['number'], 'die automatische Erstfassung ist Nummer 1');
	}

	public function testVerlaufZaehltVonDerAeltestenFassungAufUndListetNeuesteZuerst(): void {
		$controller = $this->controller();
		$controller->update('Zweite Fassung');
		$controller->update('Dritte Fassung');

		$history = $controller->history()->getData();

		$this->assertSame([3, 2, 1], array_column($history, 'number'));
		$this->assertSame(['Dritte Fassung', 'Zweite Fassung', ''], array_column($history, 'rahmen'));
		$this->assertSame(['verwalter', 'verwalter', 'system'], array_column($history, 'createdBy'));
		foreach ($history as $entry) {
			$this->assertStringContainsString('SEPA-Lastschriftmandat', $entry['pflichtblock'], 'jede Fassung trägt ihren eigenen Pflichtblock');
		}
	}

	public function testVerlaufIstAuchBeimAllerersteAufrufNichtLeer(): void {
		$history = $this->controller()->history()->getData();

		$this->assertCount(1, $history);
		$this->assertSame(1, $history[0]['number']);
	}

	public function testUnveraenderterRahmenWirdMit400AbgelehntUndLegtNichtsAn(): void {
		$controller = $this->controller();
		$controller->update('Gleicher Text');

		$response = $controller->update("Gleicher Text\r\n");

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('unverändert', $response->getData()['message']);
		$this->assertCount(2, $controller->history()->getData());
	}

	public function testZuLangerRahmenUndMarkerWerdenMit400AbgelehntUndLegenNichtsAn(): void {
		$controller = $this->controller();

		$tooLong = $controller->update(str_repeat('a', MandateLegalTextService::MAX_RAHMEN_LENGTH + 1));
		$marker = $controller->update('Text <!-- vbh:rahmen --> Text');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $tooLong->getStatus());
		$this->assertStringContainsString('zu lang', $tooLong->getData()['message']);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $marker->getStatus());
		$this->assertStringContainsString('reserviert', $marker->getData()['message']);
		$this->assertSame([], $this->stored);
	}
}
