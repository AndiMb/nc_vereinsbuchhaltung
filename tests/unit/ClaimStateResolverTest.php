<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Service\ClaimStateResolver;
use PHPUnit\Framework\TestCase;

/**
 * Spec §2.2 „Forderung (Claim)": Zustand ist vollständig abgeleitet. Issue
 * #68 deckt bewusst nur offen/storniert/erledigt ab (siehe Klassen-Docblock
 * von {@see ClaimStateResolver} für den Erweiterungspunkt Richtung
 * im-Einzug/eingezogen/zurückgegeben).
 */
class ClaimStateResolverTest extends TestCase {

	public function testOffenOhneWeitereFelder(): void {
		$this->assertSame(ClaimStateResolver::STATE_OPEN, ClaimStateResolver::resolve('open', null, null));
	}

	public function testStorniertGewinntUeberAlles(): void {
		$this->assertSame(ClaimStateResolver::STATE_CANCELLED, ClaimStateResolver::resolve('cancelled', '2026-09-01', null));
	}

	public function testErledigtBeiPaid(): void {
		$this->assertSame(ClaimStateResolver::STATE_SETTLED, ClaimStateResolver::resolve('paid', null, '2026-09-01'));
	}

	public function testErledigtBeiWaived(): void {
		$this->assertSame(ClaimStateResolver::STATE_SETTLED, ClaimStateResolver::resolve('waived', null, '2026-09-01'));
	}

	public function testResolveForItemLiestDieFelderDerEntitaet(): void {
		$item = new OpenItem();
		$item->setStatus('paid');
		$item->setSettledAt('2026-09-01T00:00:00+00:00');
		$this->assertSame(ClaimStateResolver::STATE_SETTLED, ClaimStateResolver::resolveForItem($item));
	}

	public function testResolveForItemOffenAlsStandard(): void {
		$item = new OpenItem();
		$this->assertSame(ClaimStateResolver::STATE_OPEN, ClaimStateResolver::resolveForItem($item));
	}
}
