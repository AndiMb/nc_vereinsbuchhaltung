<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetail;
use OCA\Vereinsbuchhaltung\Db\DebitItem;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebit;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaMatchingService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Matching in drei Stufen (Spec §5, Issue #72) - siehe SepaMatchingService-
 * Klassendoc. Schwerpunkt: jede Stufe für sich, Mehrdeutigkeit (mehrere
 * Kandidaten) und der Posten-Guard gegen bereits zurückgebuchte Posten.
 */
class SepaMatchingServiceTest extends TestCase {

	private DebitItemMapper&MockObject $debitItems;
	private MandateMapper&MockObject $mandates;
	private ReturnedDebitMapper&MockObject $returnedDebits;

	/**
	 * @var array<int, ReturnedDebit> DebitItem-Id => bereits vorhandene
	 *                                Rücklastschrift. Callback statt mehrerer with()-Stubs auf derselben
	 *                                Methode - siehe DebitBatchServiceTest für dieselbe Begründung
	 *                                (Registrierungsreihenfolge verdeckt sonst spätere, spezifischere Stubs).
	 */
	private array $existingReturnedDebits = [];

	protected function setUp(): void {
		$this->debitItems = $this->createMock(DebitItemMapper::class);
		$this->mandates = $this->createMock(MandateMapper::class);
		$this->returnedDebits = $this->createMock(ReturnedDebitMapper::class);
		$this->returnedDebits->method('findByDebitItem')->willReturnCallback(
			fn (int $debitItemId) => $this->existingReturnedDebits[$debitItemId] ?? null,
		);
	}

	private function service(): SepaMatchingService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return new SepaMatchingService($this->debitItems, $this->mandates, $this->returnedDebits, $l10n);
	}

	private function detail(?string $endToEndId, ?string $mandateReference, int $amountCents, bool $isReturn = true): BankTxSepaDetail {
		$d = new BankTxSepaDetail();
		$d->setId(1);
		$d->setEndToEndId($endToEndId);
		$d->setMandateReference($mandateReference);
		$d->setAmountCents($amountCents);
		$d->setIsReturn($isReturn);
		return $d;
	}

	private function tx(int $amountCents, ?string $iban = null): BankTransaction {
		$tx = new BankTransaction();
		$tx->setId(5);
		$tx->setAmountCents($amountCents);
		$tx->setCounterpartyIban($iban);
		return $tx;
	}

	private function item(int $id, int $amountCents, string $iban = 'DE02120300000000202051'): DebitItem {
		$item = new DebitItem();
		$item->setId($id);
		$item->setAmountCents($amountCents);
		$item->setIban($iban);
		return $item;
	}

	private function mandate(int $id, string $reference): Mandate {
		$m = new Mandate();
		$m->setId($id);
		$m->setMandateReference($reference);
		return $m;
	}

	// --- Stufe 1: end_to_end_id -----------------------------------------------------

	public function testStufe1FindetExaktenEndToEndIdTreffer(): void {
		$item = $this->item(10, 4500);
		$this->debitItems->method('findByEndToEndId')->with('E2E-1')->willReturn($item);

		$candidates = $this->service()->candidatesFor($this->detail('E2E-1', null, -4500), $this->tx(-4500));

		$this->assertCount(1, $candidates);
		$this->assertSame(10, $candidates[0]['debitItemId']);
		$this->assertSame(SepaMatchingService::STAGE_END_TO_END_ID, $candidates[0]['stage']);
		$this->assertStringContainsString('E2E-1', $candidates[0]['reason']);
	}

	public function testStufe1WirdVomPostenGuardBlockiertUndFaelltAufNichtsZurueck(): void {
		$item = $this->item(10, 4500);
		$this->debitItems->method('findByEndToEndId')->with('E2E-1')->willReturn($item);
		$this->existingReturnedDebits[10] = new ReturnedDebit();
		// Keine weiteren Stufen liefern ohne Mandatsreferenz/Rückgabe-IBAN etwas.
		$this->mandates->method('findByReference')->willReturn(null);

		$candidates = $this->service()->candidatesFor($this->detail('E2E-1', null, -4500), $this->tx(-4500));

		$this->assertSame([], $candidates, 'Ein bereits zurückgebuchter Posten darf nicht erneut vorgeschlagen werden');
	}

	// --- Stufe 2: mandate_reference + Betrag ------------------------------------------

	public function testStufe2FindetTrefferUeberMandatsreferenzUndBetrag(): void {
		// Kein EndToEndId auf der Detail-Zeile - Stufe 1 greift nicht.
		$this->debitItems->method('findByEndToEndId')->willReturn(null);
		$mandate = $this->mandate(3, 'M-1');
		$this->mandates->method('findByReference')->with('M-1')->willReturn($mandate);
		$this->debitItems->method('findByMandate')->with(3)->willReturn([
			$this->item(20, 4500),
			$this->item(21, 9999), // anderer Betrag - kein Treffer
		]);

		$candidates = $this->service()->candidatesFor($this->detail(null, 'M-1', -4500), $this->tx(-4500));

		$this->assertCount(1, $candidates);
		$this->assertSame(20, $candidates[0]['debitItemId']);
		$this->assertSame(SepaMatchingService::STAGE_MANDATE_AND_AMOUNT, $candidates[0]['stage']);
	}

	/** Mehrdeutigkeit (Spec §5): mehrere gleich passende Kandidaten -> alle werden geliefert. */
	public function testStufe2LiefertAlleKandidatenBeiMehrdeutigkeit(): void {
		$this->debitItems->method('findByEndToEndId')->willReturn(null);
		$mandate = $this->mandate(3, 'M-1');
		$this->mandates->method('findByReference')->with('M-1')->willReturn($mandate);
		$this->debitItems->method('findByMandate')->with(3)->willReturn([
			$this->item(20, 4500),
			$this->item(22, 4500), // zufällig derselbe Betrag - beide sind Kandidaten
		]);

		$candidates = $this->service()->candidatesFor($this->detail(null, 'M-1', -4500), $this->tx(-4500));

		$this->assertCount(2, $candidates);
		$this->assertSame([20, 22], array_column($candidates, 'debitItemId'));
	}

	public function testStufe2OhneBekanntesMandatLiefertNichts(): void {
		$this->debitItems->method('findByEndToEndId')->willReturn(null);
		$this->mandates->method('findByReference')->with('M-UNBEKANNT')->willReturn(null);

		$candidates = $this->service()->candidatesFor($this->detail(null, 'M-UNBEKANNT', -4500), $this->tx(-4500, 'DE02120300000000202051'));

		$this->assertSame([], $candidates);
	}

	// --- Stufe 3: Betrag + IBAN, nur bei Rückgabe-Signal ------------------------------

	public function testStufe3FindetTrefferUeberBetragUndIbanBeiRueckgabeSignal(): void {
		$this->debitItems->method('findByEndToEndId')->willReturn(null);
		$this->mandates->method('findByReference')->willReturn(null);
		$this->debitItems->method('findByAmountAndIban')
			->with(4500, 'DE02120300000000202051')
			->willReturn([$this->item(30, 4500)]);

		$candidates = $this->service()->candidatesFor(
			$this->detail(null, null, -4500, isReturn: true),
			$this->tx(-4500, 'DE02120300000000202051'),
		);

		$this->assertCount(1, $candidates);
		$this->assertSame(30, $candidates[0]['debitItemId']);
		$this->assertSame(SepaMatchingService::STAGE_AMOUNT_AND_IBAN, $candidates[0]['stage']);
	}

	/** Stufe 3 greift NICHT ohne Rückgabe-Signal - sonst wäre jede normale Zahlung ein Zufallstreffer. */
	public function testStufe3GreiftNichtOhneRueckgabeSignal(): void {
		$this->debitItems->method('findByEndToEndId')->willReturn(null);
		$this->mandates->method('findByReference')->willReturn(null);
		$this->debitItems->expects($this->never())->method('findByAmountAndIban');

		$candidates = $this->service()->candidatesFor(
			$this->detail(null, null, -4500, isReturn: false),
			$this->tx(-4500, 'DE02120300000000202051'),
		);

		$this->assertSame([], $candidates);
	}

	public function testStufe3BlendetBereitsZurueckgebuchtePostenAus(): void {
		$this->debitItems->method('findByEndToEndId')->willReturn(null);
		$this->mandates->method('findByReference')->willReturn(null);
		$this->debitItems->method('findByAmountAndIban')->willReturn([$this->item(30, 4500)]);
		$this->existingReturnedDebits[30] = new ReturnedDebit();

		$candidates = $this->service()->candidatesFor(
			$this->detail(null, null, -4500, isReturn: true),
			$this->tx(-4500, 'DE02120300000000202051'),
		);

		$this->assertSame([], $candidates);
	}

	// --- Kein Treffer auf keiner Stufe --------------------------------------------------

	public function testKeinKandidatAufKeinerStufeLiefertLeereListe(): void {
		$this->debitItems->method('findByEndToEndId')->willReturn(null);
		$this->mandates->method('findByReference')->willReturn(null);
		$this->debitItems->method('findByAmountAndIban')->willReturn([]);

		$candidates = $this->service()->candidatesFor(
			$this->detail('E2E-UNBEKANNT', 'M-UNBEKANNT', -4500, isReturn: true),
			$this->tx(-4500, 'DE02120300000000202051'),
		);

		$this->assertSame([], $candidates);
	}
}
