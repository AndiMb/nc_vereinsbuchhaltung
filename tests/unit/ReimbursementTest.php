<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Reimbursement;
use PHPUnit\Framework\TestCase;

class ReimbursementTest extends TestCase {

	/**
	 * Eine Entity schreibt nur Felder, die sich geändert haben. Hätte `status` einen Vorgabewert
	 * `submitted`, wäre `setStatus('submitted')` beim Anlegen keine Änderung: das Feld fehlte im
	 * INSERT und die Datenbank lehnte die Zeile ab (NOT NULL). Mit Mocks fällt das in den
	 * Dienst-Tests nicht auf, die E2E-Suite hat es gefunden.
	 */
	public function testDerStatusWirdBeimAnlegenMitgeschrieben(): void {
		$reimbursement = new Reimbursement();
		$reimbursement->setStatus(Reimbursement::STATUS_SUBMITTED);

		$this->assertArrayHasKey('status', $reimbursement->getUpdatedFields());
	}

	public function testNurOffeneAntraegeStehenZurEntscheidung(): void {
		$reimbursement = new Reimbursement();
		foreach ([Reimbursement::STATUS_SUBMITTED, Reimbursement::STATUS_PARTIALLY_APPROVED] as $status) {
			$reimbursement->setStatus($status);
			$this->assertTrue($reimbursement->isOpenForDecision(), $status);
		}
		foreach ([Reimbursement::STATUS_APPROVED, Reimbursement::STATUS_PAID, Reimbursement::STATUS_REJECTED, Reimbursement::STATUS_WITHDRAWN] as $status) {
			$reimbursement->setStatus($status);
			$this->assertFalse($reimbursement->isOpenForDecision(), $status);
		}
	}
}
