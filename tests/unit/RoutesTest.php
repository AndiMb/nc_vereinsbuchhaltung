<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Jede Route in `appinfo/routes.php` zeigt auf einen Controller und eine
 * Methode, die es gibt. PHPStan sieht Routen nicht (sie sind Zeichenketten): wer
 * einen Controller löscht und eine Route stehen lässt, merkt es sonst erst als
 * 500 im Betrieb – so war es beim Cutover des Alt-Moduls (Issue #107) zu
 * befürchten. Außerdem darf keine Route zweimal vergeben sein (Verb + URL).
 */
class RoutesTest extends TestCase {

	/** @return list<array{name:string, url:string, verb:string}> */
	private static function routes(): array {
		$config = require dirname(__DIR__, 2) . '/appinfo/routes.php';
		return $config['routes'];
	}

	/** @return array<string, array{string}> */
	public static function routeNames(): array {
		$cases = [];
		foreach (self::routes() as $route) {
			$cases[$route['name']] = [$route['name']];
		}
		return $cases;
	}

	/** @dataProvider routeNames */
	public function testRouteZeigtAufEineVorhandeneControllerMethode(string $name): void {
		[$controller, $method] = explode('#', $name);
		$class = 'OCA\\Vereinsbuchhaltung\\Controller\\' . ucfirst($controller) . 'Controller';

		$this->assertTrue(class_exists($class), "Route $name: Controller $class fehlt.");
		$this->assertTrue(method_exists($class, $method), "Route $name: $class::$method() fehlt.");
	}

	public function testKeineRouteIstDoppeltVergeben(): void {
		$seen = [];
		foreach (self::routes() as $route) {
			$key = $route['verb'] . ' ' . $route['url'];
			$this->assertArrayNotHasKey($key, $seen, "Doppelte Route: $key");
			$seen[$key] = true;
		}
		$this->assertNotEmpty($seen);
	}

	public function testDieAltRoutenDesFlachenModulsGibtEsNichtMehr(): void {
		foreach (self::routes() as $route) {
			$this->assertStringStartsNotWith('/api/sepa/mandates', $route['url']);
			$this->assertStringStartsNotWith('/api/sepa/fees', $route['url']);
			$this->assertStringStartsNotWith('/api/sepa/export', $route['url']);
		}
	}
}
