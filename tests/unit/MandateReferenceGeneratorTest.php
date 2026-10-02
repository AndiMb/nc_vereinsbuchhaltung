<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\MandateReferenceGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Mandatsreferenz `<Präfix>-<lfd. Nr.>` (Spec §2.2, Issue #66), frei
 * überschreibbar – siehe {@see MandateReferenceGenerator}.
 */
class MandateReferenceGeneratorTest extends TestCase {

	private function generator(): MandateReferenceGenerator {
		return new MandateReferenceGenerator();
	}

	public function testErsteReferenzOhneBestand(): void {
		$this->assertSame('M-1', $this->generator()->next('M', []));
	}

	public function testFortlaufendeNummerierung(): void {
		$this->assertSame('M-4', $this->generator()->next('M', ['M-1', 'M-2', 'M-3']));
	}

	/** Lücken (z. B. durch gelöschte/verworfene Entwürfe) werden nicht wieder aufgefüllt. */
	public function testLueckenWerdenNichtAufgefuellt(): void {
		$this->assertSame('M-6', $this->generator()->next('M', ['M-1', 'M-5']));
	}

	public function testAndererPraefixWirdIgnoriert(): void {
		$this->assertSame('M-1', $this->generator()->next('M', ['X-1', 'X-2']));
	}

	public function testFreiUeberschriebeneReferenzenAusserhalbDesSchemasStoerenNicht(): void {
		$this->assertSame('M-1', $this->generator()->next('M', ['SONDERFALL-2026']));
	}

	public function testLeeresPraefixFaelltAufStandardZurueck(): void {
		$this->assertSame(MandateReferenceGenerator::DEFAULT_PREFIX . '-1', $this->generator()->next('', []));
	}

	public function testEigenesPraefixWirdVerwendet(): void {
		$this->assertSame('VEREIN-1', $this->generator()->next('VEREIN', []));
	}
}
