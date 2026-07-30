<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\WatchFolderService;
use PHPUnit\Framework\TestCase;

/**
 * Der Wachordner selbst hängt an Nextclouds Dateizugriff und wird auf der
 * Testinstanz geprüft. Testbar ist hier die reine Namenslogik – sie entscheidet
 * darüber, ob eine bereits verarbeitete Datei überschrieben wird.
 */
class WatchFolderServiceTest extends TestCase {

	public function testZeitstempelKommtVorDieDateiendung(): void {
		$this->assertSame(
			'auszug_20260730-101500.csv',
			WatchFolderService::stampedName('auszug.csv', '20260730-101500'),
		);
	}

	public function testMehrfachePunkteNutzenDenLetzten(): void {
		$this->assertSame(
			'auszug.2026-01_20260730-101500.xml',
			WatchFolderService::stampedName('auszug.2026-01.xml', '20260730-101500'),
		);
	}

	public function testOhneEndungWirdAngehaengt(): void {
		$this->assertSame(
			'auszug_20260730-101500',
			WatchFolderService::stampedName('auszug', '20260730-101500'),
		);
	}

	/** Ein führender Punkt kennzeichnet eine versteckte Datei, keine Endung. */
	public function testVersteckteDateiBehaeltIhrenNamen(): void {
		$this->assertSame(
			'.auszug_20260730-101500',
			WatchFolderService::stampedName('.auszug', '20260730-101500'),
		);
	}
}
