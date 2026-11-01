<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\NumberRange;
use OCA\Vereinsbuchhaltung\Db\NumberRangeMapper;
use OCA\Vereinsbuchhaltung\Service\NumberRangeService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Rechnungsnummern: Muster, jährlicher Neubeginn, lückenlose Vergabe und
 * der Umgang mit einem gleichzeitig weitergesetzten Zähler.
 */
class NumberRangeServiceTest extends TestCase {

	private NumberRangeMapper&MockObject $mapper;
	/** @var array<string, NumberRange> */
	private array $rows = [];

	protected function setUp(): void {
		$this->rows = [];
		$this->mapper = $this->createMock(NumberRangeMapper::class);
		$this->mapper->method('findByKind')->willReturnCallback(function (string $kind): NumberRange {
			return $this->rows[$kind] ?? throw new DoesNotExistException('');
		});
		$this->mapper->method('insert')->willReturnCallback(function (NumberRange $r): NumberRange {
			$r->setId(count($this->rows) + 1);
			$this->rows[$r->getKind()] = $r;
			return $r;
		});
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mapper->method('advance')->willReturnCallback(function (int $id, int $expected, int $new, ?string $key, string $at): bool {
			foreach ($this->rows as $row) {
				if ((int)$row->getId() === $id && $row->getNextNo() === $expected) {
					$row->setNextNo($new);
					$row->setCounterKey($key);
					return true;
				}
			}
			return false;
		});
	}

	private function service(): NumberRangeService {
		return new NumberRangeService($this->mapper);
	}

	public function testStandardmusterUndFortlaufendeNummern(): void {
		$s = $this->service();
		$date = new \DateTimeImmutable('2026-03-05');

		$this->assertSame('RE-2026-0001', $s->issue('rechnung', $date)['number']);
		$this->assertSame('RE-2026-0002', $s->issue('rechnung', $date)['number']);
		$this->assertSame('ST-2026-0001', $s->issue('storno', $date)['number']);
	}

	public function testZaehlerBeginntImNeuenJahrNeu(): void {
		$s = $this->service();
		$s->issue('rechnung', new \DateTimeImmutable('2026-12-30'));
		$s->issue('rechnung', new \DateTimeImmutable('2026-12-31'));

		$this->assertSame('RE-2027-0001', $s->issue('rechnung', new \DateTimeImmutable('2027-01-02'))['number']);
	}

	public function testDatumVorDemJahrDesZaehlersWirdAbgelehnt(): void {
		$s = $this->service();
		$s->issue('rechnung', new \DateTimeImmutable('2027-01-02'));

		$this->expectException(\InvalidArgumentException::class);
		$s->issue('rechnung', new \DateTimeImmutable('2026-12-31'));
	}

	public function testZaehlerOhneJahresneubeginnLaeuftDurch(): void {
		$s = $this->service();
		$s->update('rechnung', 'R{NNNNN}', NumberRange::RESET_NEVER, null);

		$this->assertSame('R00001', $s->issue('rechnung', new \DateTimeImmutable('2026-12-31'))['number']);
		$this->assertSame('R00002', $s->issue('rechnung', new \DateTimeImmutable('2027-01-02'))['number']);
	}

	public function testStartwertDesVerwaltersGiltFuerDieErsteNummer(): void {
		$s = $this->service();
		$s->update('rechnung', 'RE-{JJJJ}-{NNNN}', NumberRange::RESET_YEARLY, 100);

		$this->assertSame('RE-2026-0100', $s->issue('rechnung', new \DateTimeImmutable('2026-02-01'))['number']);
		$this->assertSame('RE-2026-0101', $s->issue('rechnung', new \DateTimeImmutable('2026-02-02'))['number']);
	}

	public function testPeekVerbrauchtKeineNummer(): void {
		$s = $this->service();
		$date = new \DateTimeImmutable('2026-03-05');

		$preview = $s->peek('rechnung', $date);
		$s->peek('rechnung', $date);

		$this->assertSame($preview, $s->issue('rechnung', $date)['number']);
	}

	public function testVerlorenerWettlaufWirdWiederholt(): void {
		$s = $this->service();
		$date = new \DateTimeImmutable('2026-03-05');
		$s->ensure('rechnung');
		$calls = 0;
		$mapper = $this->createMock(NumberRangeMapper::class);
		$mapper->method('findByKind')->willReturnCallback(fn () => $this->rows['rechnung']);
		$mapper->method('advance')->willReturnCallback(function (int $id, int $expected, int $new, ?string $key) use (&$calls): bool {
			$calls++;
			if ($calls === 1) {
				// Eine gleichzeitige Festschreibung zieht den Zähler vor.
				$this->rows['rechnung']->setNextNo(5);
				$this->rows['rechnung']->setCounterKey($key);
				return false;
			}
			$this->rows['rechnung']->setNextNo($new);
			return true;
		});

		$number = (new NumberRangeService($mapper))->issue('rechnung', $date)['number'];

		$this->assertSame('RE-2026-0005', $number);
		$this->assertSame(2, $calls);
	}

	/** @dataProvider muster */
	public function testMusterPruefung(string $pattern, bool $valid): void {
		$this->assertSame($valid, NumberRangeService::validatePattern($pattern) === null);
	}

	/** @return array<string, array{string, bool}> */
	public static function muster(): array {
		return [
			'Standard' => ['RE-{JJJJ}-{NNNN}', true],
			'zweistelliges Jahr' => ['{JJ}/{NNN}', true],
			'mit Monat' => ['RE-{JJJJ}{MM}-{NNNN}', true],
			'Monat einstellig' => ['RE-{JJJJ}{M}-{NNNN}', false],
			'nur Zähler' => ['{NNNNNN}', true],
			'ohne Zähler' => ['RE-{JJJJ}', false],
			'zwei Zähler' => ['{NN}-{NN}', false],
			'Leerzeichen' => ['RE {NNNN}', false],
			'Sonderzeichen' => ['RE#{NNNN}', false],
			'leer' => ['', false],
			'Zähler zu breit' => ['{NNNNNNNNN}', false],
		];
	}

	public function testJaehrlicherNeubeginnVerlangtDasJahrImMuster(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->update('rechnung', 'RE-{NNNN}', NumberRange::RESET_YEARLY, null);
	}

	public function testMonatsplatzhalterSetztDenMonatDesRechnungsdatumsEin(): void {
		$s = $this->service();
		$s->update('rechnung', 'RE-{JJJJ}{MM}-{NNNN}', NumberRange::RESET_YEARLY, null);

		$this->assertSame('RE-202603-0001', $s->issue('rechnung', new \DateTimeImmutable('2026-03-05'))['number']);
		// Jährlicher Neubeginn: der Monat ist nur Angabe, der Zähler läuft weiter.
		$this->assertSame('RE-202604-0002', $s->issue('rechnung', new \DateTimeImmutable('2026-04-01'))['number']);
	}

	public function testMonatlicherNeubeginn(): void {
		$s = $this->service();
		$s->update('rechnung', 'RE-{JJ}-{MM}-{NNN}', NumberRange::RESET_MONTHLY, null);

		$this->assertSame('RE-26-03-001', $s->issue('rechnung', new \DateTimeImmutable('2026-03-30'))['number']);
		$this->assertSame('RE-26-03-002', $s->issue('rechnung', new \DateTimeImmutable('2026-03-31'))['number']);
		$this->assertSame('RE-26-04-001', $s->issue('rechnung', new \DateTimeImmutable('2026-04-01'))['number']);
		$this->assertSame('RE-27-01-001', $s->issue('rechnung', new \DateTimeImmutable('2027-01-04'))['number']);
	}

	public function testMonatlicherNeubeginnLehntDatumVorDemMonatDesZaehlersAb(): void {
		$s = $this->service();
		$s->update('rechnung', 'RE-{JJJJ}{MM}-{NNNN}', NumberRange::RESET_MONTHLY, null);
		$s->issue('rechnung', new \DateTimeImmutable('2026-04-01'));

		$this->expectException(\InvalidArgumentException::class);
		$s->issue('rechnung', new \DateTimeImmutable('2026-03-31'));
	}

	public function testMonatlicherNeubeginnVerlangtJahrUndMonatImMuster(): void {
		$s = $this->service();
		try {
			$s->update('rechnung', 'RE-{MM}-{NNNN}', NumberRange::RESET_MONTHLY, null);
			$this->fail('Muster ohne Jahr wurde angenommen.');
		} catch (\InvalidArgumentException) {
		}
		$this->expectException(\InvalidArgumentException::class);
		$s->update('rechnung', 'RE-{JJJJ}-{NNNN}', NumberRange::RESET_MONTHLY, null);
	}

	public function testWechselVonMonatlichAufJaehrlichImSelbenJahrVergibtKeineNummerDoppelt(): void {
		$s = $this->service();
		$s->update('rechnung', 'RE-{JJJJ}{MM}-{NNNN}', NumberRange::RESET_MONTHLY, null);
		$s->issue('rechnung', new \DateTimeImmutable('2026-11-02'));
		$s->update('rechnung', 'RE-{JJJJ}{MM}-{NNNN}', NumberRange::RESET_YEARLY, null);

		// Der Zähler gehört weiter zum laufenden Jahr: kein zweites RE-202611-0001.
		$this->assertSame('RE-202611-0002', $s->peek('rechnung', new \DateTimeImmutable('2026-11-03')));
		$this->assertSame('RE-202611-0002', $s->issue('rechnung', new \DateTimeImmutable('2026-11-03'))['number']);
		$this->assertSame('RE-202701-0001', $s->issue('rechnung', new \DateTimeImmutable('2027-01-04'))['number']);
	}

	public function testWechselVonJaehrlichAufMonatlichLaeuftImSelbenMonatWeiter(): void {
		$s = $this->service();
		$s->update('rechnung', 'RE-{JJJJ}{MM}-{NNNN}', NumberRange::RESET_YEARLY, null);
		$s->issue('rechnung', new \DateTimeImmutable('2026-11-02'));
		$s->update('rechnung', 'RE-{JJJJ}{MM}-{NNNN}', NumberRange::RESET_MONTHLY, null);

		$this->assertSame('RE-202611-0002', $s->issue('rechnung', new \DateTimeImmutable('2026-11-03'))['number']);
		$this->assertSame('RE-202612-0001', $s->issue('rechnung', new \DateTimeImmutable('2026-12-01'))['number']);
	}

	public function testWechselAufJaehrlichLehntDatumVorDemJahrDesMonatszaehlersAb(): void {
		$s = $this->service();
		$s->update('rechnung', 'RE-{JJJJ}{MM}-{NNNN}', NumberRange::RESET_MONTHLY, null);
		$s->issue('rechnung', new \DateTimeImmutable('2027-01-04'));
		$s->update('rechnung', 'RE-{JJJJ}{MM}-{NNNN}', NumberRange::RESET_YEARLY, null);

		$this->expectException(\InvalidArgumentException::class);
		$s->issue('rechnung', new \DateTimeImmutable('2026-12-31'));
	}

	public function testRegexMitMonat(): void {
		$regex = '/^' . NumberRangeService::toRegex('RE-{JJJJ}{MM}-{NNNN}') . '$/';

		$this->assertSame(1, preg_match($regex, 'RE-202611-0042'));
		$this->assertSame(0, preg_match($regex, 'RE-2026-0042'));
	}

	public function testRegexPasstAufErzeugteNummern(): void {
		$regex = '/^' . NumberRangeService::toRegex('RE-{JJJJ}-{NNNN}') . '$/';

		$this->assertSame(1, preg_match($regex, 'RE-2026-0042'));
		$this->assertSame(1, preg_match($regex, 'RE-2026-12345'));
		$this->assertSame(0, preg_match($regex, 'ST-2026-0042'));
	}

	public function testUnbekannteArtWirdAbgelehnt(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->ensure('angebot');
	}
}
