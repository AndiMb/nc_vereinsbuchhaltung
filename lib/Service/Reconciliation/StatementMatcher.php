<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Reconciliation;

use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;

/**
 * Paart die Zeilen eines Kontoauszugs mit den Bewegungen, die die App für
 * dasselbe Geldkonto führt (Kontoabstimmung, Issue #159).
 *
 * Reine Funktion ohne Datenbank. Beide Seiten sind Mengen mit Vielfachheit:
 * jede Zeile wird höchstens einmal gepaart, zwei gleiche Auszugszeilen
 * brauchen also zwei gleiche Bewegungen. Drei Stufen, jede arbeitet nur auf
 * dem Rest der vorigen:
 *
 *  1. gleiches Datum, gleicher Betrag samt Vorzeichen, gleicher normalisierter Text;
 *  2. gleiches Datum und gleicher Betrag, Texte ähnlich genug;
 *  3. gleicher Betrag, Datum höchstens `$toleranceDays` Tage auseinander.
 *
 * Konkurrieren mehr App-Bewegungen als Auszugszeilen, wird in der Reihenfolge
 * „zugeordneter Umsatz“ vor „Buchung ohne Umsatz“ vor „nicht zugeordneter
 * Umsatz“ bedient. Übrig – und damit Dublettenkandidat – bleibt also zuerst
 * das, was sich am gefahrlosesten löschen lässt.
 */
class StatementMatcher {

	public const KIND_ASSIGNED = 'assigned';
	public const KIND_BOOKING = 'booking';
	public const KIND_UNASSIGNED = 'unassigned';

	/** Mindestähnlichkeit der Texte in Stufe 2. */
	private const MIN_SIMILARITY = 0.5;

	private const PRIORITY = [self::KIND_ASSIGNED => 0, self::KIND_BOOKING => 1, self::KIND_UNASSIGNED => 2];

	public function __construct(
		private RowNormalizer $normalizer,
	) {
	}

	/**
	 * @param list<array{date:string, amountCents:int, texts:list<string>, row:array<string,mixed>}> $statement Auszugszeilen des Zeitraums
	 * @param list<array{kind:string, id:int, date:string, amountCents:int, texts:list<string>, ref:array<string,mixed>}> $app App-Bewegungen, auch die im Toleranzrand außerhalb des Zeitraums
	 * @return array{
	 *     pairs: list<array{statement:int, app:int, stage:int}>,
	 *     surplus: list<int>,
	 *     missing: list<int>,
	 *     shifted: list<array{statement:int, app:int}>
	 * } Indizes in die Eingabelisten; `surplus` nur Bewegungen im Zeitraum, `shifted` sind Paare mit Bewegung außerhalb
	 */
	public function match(array $statement, array $app, string $from, string $to, int $toleranceDays): array {
		$sNorm = array_map(fn (array $s): array => $this->normalized($s['texts']), $statement);
		$aNorm = array_map(fn (array $a): array => $this->normalized($a['texts']), $app);

		// App-Seite in Vorrangreihenfolge: so gewinnt bei Gleichstand immer die „bessere“ Bewegung.
		$appOrder = array_keys($app);
		usort($appOrder, static fn (int $x, int $y): int => [self::PRIORITY[$app[$x]['kind']] ?? 9, $app[$x]['id'], $x] <=> [self::PRIORITY[$app[$y]['kind']] ?? 9, $app[$y]['id'], $y]);
		$rank = array_flip($appOrder);

		$sFree = array_fill_keys(array_keys($statement), true);
		$aFree = array_fill_keys(array_keys($app), true);
		$pairs = [];
		$take = static function (int $s, int $a, int $stage) use (&$sFree, &$aFree, &$pairs): void {
			unset($sFree[$s], $aFree[$a]);
			$pairs[] = ['statement' => $s, 'app' => $a, 'stage' => $stage];
		};

		// Stufe 1: Schlüssel aus Datum, Betrag und Text.
		$byKey = [];
		foreach ($appOrder as $a) {
			foreach (array_unique($aNorm[$a]) as $text) {
				if ($text !== '') {
					$byKey[$app[$a]['date'] . '|' . $app[$a]['amountCents'] . '|' . $text][] = $a;
				}
			}
		}
		foreach (array_keys($statement) as $s) {
			foreach (array_unique($sNorm[$s]) as $text) {
				$key = $statement[$s]['date'] . '|' . $statement[$s]['amountCents'] . '|' . $text;
				foreach ($byKey[$key] ?? [] as $a) {
					if (isset($aFree[$a])) {
						$take($s, $a, 1);
						continue 3;
					}
				}
			}
		}

		// Stufe 2 und 3: alle zulässigen Paare bilden, die besten zuerst vergeben.
		foreach ([2, 3] as $stage) {
			$candidates = [];
			foreach (array_keys($sFree) as $s) {
				foreach (array_keys($aFree) as $a) {
					if ($statement[$s]['amountCents'] !== $app[$a]['amountCents']) {
						continue;
					}
					$distance = $this->dayDistance($statement[$s]['date'], $app[$a]['date']);
					$similarity = $this->similarity($sNorm[$s], $aNorm[$a]);
					if ($stage === 2 && ($distance !== 0 || $similarity < self::MIN_SIMILARITY)) {
						continue;
					}
					if ($stage === 3 && $distance > $toleranceDays) {
						continue;
					}
					$candidates[] = [$stage === 3 ? $distance : 0, -$similarity, $rank[$a], $s, $a];
				}
			}
			sort($candidates);
			foreach ($candidates as [, , , $s, $a]) {
				if (isset($sFree[$s], $aFree[$a])) {
					$take($s, $a, $stage);
				}
			}
		}

		$inWindow = static fn (string $date): bool => $date >= $from && $date <= $to;
		$shifted = [];
		foreach ($pairs as $pair) {
			if (!$inWindow($app[$pair['app']]['date'])) {
				$shifted[] = ['statement' => $pair['statement'], 'app' => $pair['app']];
			}
		}

		return [
			'pairs' => $pairs,
			'surplus' => array_values(array_filter(array_keys($aFree), static fn (int $a): bool => $inWindow($app[$a]['date']))),
			'missing' => array_keys($sFree),
			'shifted' => $shifted,
		];
	}

	/**
	 * @param list<string> $texts
	 * @return list<string>
	 */
	private function normalized(array $texts): array {
		return array_values(array_map(fn (string $t): string => $this->normalizer->normalizeText($t), $texts));
	}

	/**
	 * Ähnlichkeit zweier Zeilen, 0 bis 1: das Maximum über alle Textpaare.
	 * Ein fehlender Text widerspricht nichts und gilt als ähnlich genug –
	 * Buchungen aus Altimporten tragen oft keinen.
	 *
	 * @param list<string> $a
	 * @param list<string> $b
	 */
	private function similarity(array $a, array $b): float {
		$best = 0.0;
		foreach ($a as $x) {
			foreach ($b as $y) {
				if ($x === '' || $y === '' || str_contains($x, $y) || str_contains($y, $x)) {
					return 1.0;
				}
				similar_text($x, $y, $percent);
				$best = max($best, $percent / 100);
			}
		}
		return $best;
	}

	private function dayDistance(string $a, string $b): int {
		if ($a === $b) {
			return 0;
		}
		$x = \DateTimeImmutable::createFromFormat('!Y-m-d', $a);
		$y = \DateTimeImmutable::createFromFormat('!Y-m-d', $b);
		if ($x === false || $y === false) {
			return PHP_INT_MAX;
		}
		return (int)$x->diff($y)->days;
	}
}
