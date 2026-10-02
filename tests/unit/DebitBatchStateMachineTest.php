<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCA\Vereinsbuchhaltung\Service\DebitBatchStateMachine;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Zustandsmaschine des Lastschriftlaufs (Spec §2.2/§3.5, Issue #71):
 * `freigegeben` → `eingereicht` | `verworfen`, beide terminal, Termin nur
 * nach hinten verschiebbar. Reine Entscheidungslogik ohne Datenbankzugriff,
 * siehe {@see DebitBatchStateMachine}.
 */
class DebitBatchStateMachineTest extends TestCase {

	private function machine(): DebitBatchStateMachine {
		return new DebitBatchStateMachine($this->createMock(IL10N::class));
	}

	private function batch(string $status, string $dueDate = '2026-10-01'): DebitBatch {
		$b = new DebitBatch();
		$b->setStatus($status);
		$b->setDueDate($dueDate);
		return $b;
	}

	// --- Einreichung -------------------------------------------------------------

	public function testFreigegebenerLaufLaesstSichEinreichen(): void {
		$this->machine()->assertCanSubmit($this->batch(DebitBatch::STATUS_RELEASED));
		$this->addToAssertionCount(1);
	}

	public function testEingereichterLaufLaesstSichNichtNochEinmalEinreichen(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanSubmit($this->batch(DebitBatch::STATUS_SUBMITTED));
	}

	public function testVerworfenerLaufLaesstSichNichtEinreichen(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanSubmit($this->batch(DebitBatch::STATUS_DISCARDED));
	}

	// --- Verwerfen -----------------------------------------------------------------

	public function testFreigegebenerLaufLaesstSichVerwerfen(): void {
		$this->machine()->assertCanDiscard($this->batch(DebitBatch::STATUS_RELEASED));
		$this->addToAssertionCount(1);
	}

	/** „Kein Storno nach Einreichung" (Spec §3.5). */
	public function testEingereichterLaufLaesstSichNichtVerwerfen(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanDiscard($this->batch(DebitBatch::STATUS_SUBMITTED));
	}

	public function testVerworfenerLaufLaesstSichNichtNochEinmalVerwerfen(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanDiscard($this->batch(DebitBatch::STATUS_DISCARDED));
	}

	// --- Terminverschiebung ----------------------------------------------------------

	public function testTerminLaesstSichNachHintenVerschieben(): void {
		$this->machine()->assertCanReschedule($this->batch(DebitBatch::STATUS_RELEASED, '2026-10-01'), '2026-10-08');
		$this->addToAssertionCount(1);
	}

	public function testTerminLaesstSichNichtNachVornVerschieben(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanReschedule($this->batch(DebitBatch::STATUS_RELEASED, '2026-10-01'), '2026-09-24');
	}

	public function testUnveraenderterTerminIstKeineVerschiebung(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanReschedule($this->batch(DebitBatch::STATUS_RELEASED, '2026-10-01'), '2026-10-01');
	}

	public function testEingereichterLaufLaesstSichNichtTerminlichVerschieben(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanReschedule($this->batch(DebitBatch::STATUS_SUBMITTED, '2026-10-01'), '2026-10-08');
	}
}
