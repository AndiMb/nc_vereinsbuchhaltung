<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Export\KassenberichtRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Die zustandslosen Zeilenbauer des Kassenberichts. Der Rest des Renderers
 * braucht Mapper und Nextcloud-Dienste und gehört in die E2E-Tests.
 */
class KassenberichtRendererTest extends TestCase {

	/**
	 * Eine Sphäre ohne Einnahmen und ohne Ausgaben verlängert den Bericht nur –
	 * wie ein Konto ohne Bewegung in der Erfolgsrechnung.
	 */
	public function testSphaerenOhneBewegungBleibenWeg(): void {
		$html = KassenberichtRenderer::sphereRows([
			['name' => 'Ideeller Bereich', 'income' => 1200.0, 'expense' => 300.5, 'result' => 899.5],
			['name' => 'Vermögensverwaltung', 'income' => 0.0, 'expense' => 0.0, 'result' => 0.0],
			['name' => 'Zweckbetrieb', 'income' => 0, 'expense' => 0, 'result' => 0],
			['name' => '(nicht zugeordnet)', 'income' => 0.0, 'expense' => 0.0, 'result' => 0.0],
		]);

		$this->assertStringContainsString('Ideeller Bereich', $html);
		$this->assertStringContainsString('1.200,00 €', $html);
		$this->assertStringContainsString('899,50 €', $html);
		$this->assertStringNotContainsString('Vermögensverwaltung', $html);
		$this->assertStringNotContainsString('Zweckbetrieb', $html);
		$this->assertStringNotContainsString('nicht zugeordnet', $html);
		$this->assertSame(1, substr_count($html, '<tr>'));
	}

	/**
	 * Nur eine Seite mit Bewegung reicht: eine Sphäre mit Ausgaben, aber ohne
	 * Einnahmen gehört in den Bericht – sonst fehlte ein Teil der Ausgaben.
	 */
	public function testEinseitigeBewegungBleibtSichtbar(): void {
		$html = KassenberichtRenderer::sphereRows([
			['name' => 'Wirtschaftlicher Geschäftsbetrieb', 'income' => 0.0, 'expense' => 80.0, 'result' => -80.0],
			['name' => 'Zweckbetrieb', 'income' => 50.0, 'expense' => 0.0, 'result' => 50.0],
		]);

		$this->assertSame(2, substr_count($html, '<tr>'));
		$this->assertStringContainsString('-80,00 €', $html);
		$this->assertStringContainsString('50,00 €', $html);
	}

	public function testAlleSphaerenOhneBewegungErgebenKeineZeile(): void {
		$this->assertSame('', KassenberichtRenderer::sphereRows([
			['name' => 'Ideeller Bereich', 'income' => 0.0, 'expense' => 0.0, 'result' => 0.0],
		]));
	}
}
