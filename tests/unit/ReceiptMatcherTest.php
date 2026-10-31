<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\ReceiptIndex\ReceiptFacts;
use OCA\Vereinsbuchhaltung\Service\ReceiptIndex\ReceiptMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Abgleich Buchung gegen Belegindex: Stufen, Eindeutigkeit, Reihenfolge, Gründe.
 */
class ReceiptMatcherTest extends TestCase {

	private function booking(string $key, string $description = '', int $cents = -11900, string $date = '2026-10-20', string $documentRef = ''): array {
		return ['key' => $key, 'date' => $date, 'amountCents' => $cents, 'description' => $description, 'documentRef' => $documentRef];
	}

	/**
	 * @param list<string> $refs
	 * @param list<int> $amounts
	 * @param list<string> $dates
	 * @param list<string> $nameRefs
	 */
	private function file(int $id, array $refs = [], array $amounts = [], array $dates = [], int $mtime = 100, array $nameRefs = [], ?string $invoiceNo = null, ?int $total = null): array {
		return [
			'fileId' => $id,
			'mtime' => $mtime,
			'facts' => new ReceiptFacts('db', ReceiptFacts::STATUS_OK, $refs, $nameRefs, $amounts, $dates, $invoiceNo, $total),
		];
	}

	private function levels(array $result, string $key): array {
		$out = [];
		foreach ($result[$key] ?? [] as $s) {
			$out[$s['fileId']] = $s['level'];
		}
		ksort($out);
		return $out;
	}

	public function testRefUndBetragIstSicher(): void {
		$r = (new ReceiptMatcher())->match(
			[$this->booking('1', 'ReNr 28/26')],
			[$this->file(10, ['2826'], [11900])],
		);
		$this->assertSame([10 => 'sure'], $this->levels($r, '1'));
	}

	public function testNurRefIstMoeglich(): void {
		$r = (new ReceiptMatcher())->match(
			[$this->booking('1', 'ReNr 28/26')],
			[$this->file(10, ['2826'], [5000])],
		);
		$this->assertSame([10 => 'possible'], $this->levels($r, '1'));
	}

	public function testBetragUndDatumIstMoeglich(): void {
		$r = (new ReceiptMatcher())->match(
			[$this->booking('1', 'ohne Nummer')],
			[$this->file(10, [], [11900], ['2026-10-15'])],
		);
		$this->assertSame([10 => 'possible'], $this->levels($r, '1'));
	}

	public function testBetragOhneDatumErgibtNichts(): void {
		$r = (new ReceiptMatcher())->match(
			[$this->booking('1', 'ohne Nummer')],
			[$this->file(10, [], [11900], [])],
		);
		$this->assertSame([], $r['1'] ?? []);
	}

	public function testBetragOhneVorzeichen(): void {
		$m = new ReceiptMatcher();
		$f = [$this->file(10, [], [11900], ['2026-10-15'])];
		$this->assertSame([10 => 'possible'], $this->levels($m->match([$this->booking('1', 'x', -11900)], $f), '1'));
		$this->assertSame([10 => 'possible'], $this->levels($m->match([$this->booking('1', 'x', 11900)], $f), '1'));
	}

	public function testZweiSichereDateienFuerEineBuchungWerdenMoeglich(): void {
		$r = (new ReceiptMatcher())->match(
			[$this->booking('1', 'ReNr 28/26')],
			[$this->file(10, ['2826'], [11900]), $this->file(11, ['2826'], [11900])],
		);
		$this->assertSame([10 => 'possible', 11 => 'possible'], $this->levels($r, '1'));
	}

	public function testEineDateiSicherFuerZweiBuchungenWirdBeidesMoeglich(): void {
		$r = (new ReceiptMatcher())->match(
			[$this->booking('1', 'ReNr 28/26'), $this->booking('2', 'Rechnung 28-26')],
			[$this->file(10, ['2826'], [11900])],
		);
		$this->assertSame([10 => 'possible'], $this->levels($r, '1'));
		$this->assertSame([10 => 'possible'], $this->levels($r, '2'));
	}

	public function testNurKundennummerUndAndererBetragIstHoechstensMoeglich(): void {
		$r = (new ReceiptMatcher())->match(
			[$this->booking('1', 'KD-Nr 12345')],
			[$this->file(10, ['12345'], [999]), $this->file(11, ['12345'], [888])],
		);
		$this->assertSame([10 => 'possible', 11 => 'possible'], $this->levels($r, '1'));
	}

	public function testReihenfolgeUndGrenzeFuenf(): void {
		$d = ['2026-10-15'];
		$files = [
			$this->file(1, [], [11900], $d, 500),            // amount+date
			$this->file(2, ['2826'], [1], [], 100),          // ref
			$this->file(3, ['2826'], [1], $d, 100),          // ref+date
			$this->file(4, ['2826'], [11900], [], 100),      // sure
			$this->file(5, [], [11900], $d, 900),            // amount+date, neuer
			$this->file(6, ['2826'], [1], [], 300),          // ref, neuer
			$this->file(7, ['2826'], [1], $d, 200),          // ref+date, neuer
		];
		$r = (new ReceiptMatcher())->match([$this->booking('1', 'ReNr 28/26')], $files);
		$ids = array_column($r['1'], 'fileId');
		$this->assertCount(ReceiptMatcher::MAX_SUGGESTIONS, $ids);
		$this->assertSame(4, $ids[0]);
		$this->assertSame('sure', $r['1'][0]['level']);
		$this->assertSame([7, 3], array_slice($ids, 1, 2));
		$this->assertSame([6, 2], array_slice($ids, 3, 2));
	}

	public function testDatumsfensterMinus90Plus10(): void {
		$m = new ReceiptMatcher();
		$b = [$this->booking('1', 'x', 11900, '2026-10-20')];
		$in = fn (string $d) => $m->match($b, [$this->file(10, [], [11900], [$d])])['1'] ?? [];
		$this->assertNotEmpty($in('2026-07-22')); // -90
		$this->assertEmpty($in('2026-07-21'));    // -91
		$this->assertNotEmpty($in('2026-10-30')); // +10
		$this->assertEmpty($in('2026-10-31'));    // +11
	}

	public function testGrundNennenOriginalausschnittUndQuelle(): void {
		$r = (new ReceiptMatcher())->match(
			[$this->booking('1', 'Zahlung ReNr 28/26 danke')],
			[$this->file(10, ['2826'], [11900], ['2026-10-15'], 100, ['2826'])],
		);
		$reasons = $r['1'][0]['reasons'];
		$this->assertContains(['kind' => 'ref', 'value' => '28/26', 'where' => 'name'], $reasons);
		$this->assertContains(['kind' => 'amount', 'cents' => 11900], $reasons);
		$this->assertContains(['kind' => 'date', 'date' => '2026-10-15'], $reasons);
	}

	public function testQuelleTextUndEinvoice(): void {
		$m = new ReceiptMatcher();
		$t = $m->match([$this->booking('1', 'ReNr 28/26')], [$this->file(10, ['2826'], [])]);
		$this->assertSame('text', $t['1'][0]['reasons'][0]['where']);
		$e = $m->match([$this->booking('1', 'ReNr 28/26')], [$this->file(10, ['2826'], [], [], 100, [], 'RE-28/26', 11900)]);
		$this->assertSame('einvoice', $e['1'][0]['reasons'][0]['where']);
		$this->assertSame('sure', $e['1'][0]['level']);
	}

	public function testEinvoiceBetragIstGleichGesamtbetrag(): void {
		$r = (new ReceiptMatcher())->match(
			[$this->booking('1', 'ReNr 28/26')],
			[$this->file(10, ['2826'], [11900], [], 100, [], 'RE-28/26', 5000)],
		);
		$this->assertSame([10 => 'possible'], $this->levels($r, '1'));
	}

	public function testBelegnummerLiefertReferenz(): void {
		$r = (new ReceiptMatcher())->match(
			[$this->booking('1', 'ohne', -11900, '2026-10-20', 'B-2826')],
			[$this->file(10, ['2826'], [11900])],
		);
		$this->assertSame([10 => 'sure'], $this->levels($r, '1'));
	}
}
