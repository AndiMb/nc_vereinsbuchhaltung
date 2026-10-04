<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\DebitBatch;
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

	// --- Zustand aus Sicht des Einzugspostens (Issue #102, Spec §2.2) ------------------

	private function batch(string $status, string $dueDate = '2026-10-01'): DebitBatch {
		$b = new DebitBatch();
		$b->setStatus($status);
		$b->setDueDate($dueDate);
		return $b;
	}

	private function claim(string $status = 'open'): OpenItem {
		$i = new OpenItem();
		$i->setStatus($status);
		if ($status === 'paid' || $status === 'waived') {
			$i->setSettledAt('2026-09-01T00:00:00+00:00');
		}
		if ($status === 'cancelled') {
			$i->setCancelledAt('2026-09-01T00:00:00+00:00');
		}
		return $i;
	}

	public function testFreigegebenerLaufHeisstImEinzug(): void {
		$state = ClaimStateResolver::resolveForDebitItem($this->claim(), $this->batch(DebitBatch::STATUS_RELEASED, '2026-09-01'), false, '2026-10-05');
		// Auch ein ueberschrittener Termin aendert nichts: erst die Einreichung zaehlt.
		$this->assertSame(ClaimStateResolver::STATE_IN_DEBIT, $state);
	}

	public function testEingereichterLaufIstBisZumTerminImEinzugAbDemTerminEingezogen(): void {
		// „Termin noch nicht erreicht" (Spec §2.2) heisst: heute liegt vor dem Termin.
		$batch = $this->batch(DebitBatch::STATUS_SUBMITTED, '2026-10-01');
		$this->assertSame(ClaimStateResolver::STATE_IN_DEBIT, ClaimStateResolver::resolveForDebitItem($this->claim(), $batch, false, '2026-09-30'));
		$this->assertSame(ClaimStateResolver::STATE_COLLECTED, ClaimStateResolver::resolveForDebitItem($this->claim(), $batch, false, '2026-10-01'));
		$this->assertSame(ClaimStateResolver::STATE_COLLECTED, ClaimStateResolver::resolveForDebitItem($this->claim(), $batch, false, '2026-10-02'));
	}

	public function testRuecklastschriftMachtDenPostenZurueckgegeben(): void {
		$batch = $this->batch(DebitBatch::STATUS_SUBMITTED, '2026-10-01');
		$this->assertSame(ClaimStateResolver::STATE_RETURNED, ClaimStateResolver::resolveForDebitItem($this->claim(), $batch, true, '2026-10-20'));
	}

	public function testVerworfenerLaufGibtDieForderungWiederFrei(): void {
		$batch = $this->batch(DebitBatch::STATUS_DISCARDED);
		$this->assertSame(ClaimStateResolver::STATE_OPEN, ClaimStateResolver::resolveForDebitItem($this->claim(), $batch, false, '2026-10-05'));
	}

	public function testStornoUndErledigungsvermerkGehenVorDemLauf(): void {
		$batch = $this->batch(DebitBatch::STATUS_SUBMITTED, '2026-10-01');
		$this->assertSame(ClaimStateResolver::STATE_CANCELLED, ClaimStateResolver::resolveForDebitItem($this->claim('cancelled'), $batch, true, '2026-10-20'));
		// Nach einer Ruecklastschrift per Ueberweisung beglichen: erledigt, nicht "zurueckgegeben".
		$this->assertSame(ClaimStateResolver::STATE_SETTLED, ClaimStateResolver::resolveForDebitItem($this->claim('paid'), $batch, true, '2026-10-20'));
	}

	public function testGeloeschteForderungFolgtAllemDemLauf(): void {
		$batch = $this->batch(DebitBatch::STATUS_SUBMITTED, '2026-10-01');
		$this->assertSame(ClaimStateResolver::STATE_COLLECTED, ClaimStateResolver::resolveForDebitItem(null, $batch, false, '2026-10-20'));
	}
}
