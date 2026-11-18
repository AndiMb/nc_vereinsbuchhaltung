<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\AsciiFileName;
use PHPUnit\Framework\TestCase;

class AsciiFileNameTest extends TestCase {

	public function testUmlauteWerdenAusgeschrieben(): void {
		$this->assertSame('Groesse-Uebersicht', AsciiFileName::of('Größe/Übersicht'));
		$this->assertSame('aeoeueAeOeUess', AsciiFileName::of('äöüÄÖÜß'));
	}

	public function testRestWirdZuStrichUndErlaubtesBleibt(): void {
		$this->assertSame('2025-26', AsciiFileName::of('2025/26'));
		$this->assertSame('a.b_c-d', AsciiFileName::of('a.b_c-d'));
		$this->assertSame('Verein-e.-V.', AsciiFileName::of('Verein e. V.'));
	}
}
