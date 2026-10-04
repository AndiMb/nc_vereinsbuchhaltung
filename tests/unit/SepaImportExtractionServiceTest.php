<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetail;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetailMapper;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportExtractionService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die Detail-Zeilen, die der Import je Bankbuchung anlegt (Issue #72), und die
 * Rückmeldung an den Import, wie viele davon als Rücklastschrift gekennzeichnet
 * sind (seit dem Cutover, Issue #107, die einzige Quelle für den Hinweis
 * „mögliche Rücklastschrift erkannt“ nach dem Import).
 */
class SepaImportExtractionServiceTest extends TestCase {

	private BankTxSepaDetailMapper&MockObject $mapper;
	/** @var list<BankTxSepaDetail> */
	private array $inserted = [];

	protected function setUp(): void {
		$this->inserted = [];
		$this->mapper = $this->createMock(BankTxSepaDetailMapper::class);
		$this->mapper->method('insert')->willReturnCallback(function (BankTxSepaDetail $detail): BankTxSepaDetail {
			$this->inserted[] = $detail;
			return $detail;
		});
	}

	private function service(): SepaImportExtractionService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-04 12:00:00'));
		return new SepaImportExtractionService($this->mapper, $time);
	}

	private function tx(int $amountCents, ?string $purpose = null): BankTransaction {
		$tx = new BankTransaction();
		$tx->setId(7);
		$tx->setAmountCents($amountCents);
		$tx->setPurpose($purpose);
		return $tx;
	}

	public function testZaehltNurDieAlsRuecklastschriftGekennzeichnetenZeilen(): void {
		$returns = $this->service()->extract($this->tx(-6000), [
			['endToEndId' => 'E2E-1', 'isReturn' => true, 'returnReasonCode' => 'AM04'],
			['endToEndId' => 'E2E-2', 'isReturn' => false],
			['endToEndId' => 'E2E-3', 'isReturn' => true],
		]);

		$this->assertSame(2, $returns);
		$this->assertCount(3, $this->inserted);
		$this->assertSame('AM04', $this->inserted[0]->getReturnReasonCode());
	}

	public function testBuchungOhneDetailsLegtNichtsAnUndMeldetNull(): void {
		$returns = $this->service()->extract($this->tx(-6000, 'Miete Oktober'), []);

		$this->assertSame(0, $returns);
		$this->assertSame([], $this->inserted);
	}

	public function testTextHeuristikFuerEineBelastungMitRueckgabegrundZaehltAlsRuecklastschrift(): void {
		$returns = $this->service()->extract($this->tx(-6000, 'RUECKLASTSCHRIFT AM04 Mandat verweigert'), []);

		$this->assertSame(1, $returns);
		$this->assertCount(1, $this->inserted);
		$this->assertSame(BankTxSepaDetail::DETECTION_TEXT_HEURISTIC, $this->inserted[0]->getDetectionSource());
		$this->assertSame('AM04', $this->inserted[0]->getReturnReasonCode());
	}

	public function testTextHeuristikGiltNurFuerGeldabgaenge(): void {
		$returns = $this->service()->extract($this->tx(6000, 'AM04 Beitrag Oktober'), []);

		$this->assertSame(0, $returns);
		$this->assertSame([], $this->inserted);
	}
}
