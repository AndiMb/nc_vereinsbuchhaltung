<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Invoice;

/**
 * Rechenregeln für Rechnungspositionen und Summen, ausschließlich mit
 * Ganzzahlen: Beträge in Cent, Mengen in Zehntausendstel, Rabatt und
 * Steuersatz in Basispunkten (1 % = 100). So entstehen keine Rundungsfehler
 * aus Fließkommazahlen, und dieselbe Rechnung ergibt auf jedem System dieselben
 * Cent.
 *
 * Rundung: kaufmännisch (halbe Cent weg von null), und zwar
 *  - je Position einmal auf Cent (Menge × Einzelpreis, danach der Rabatt),
 *  - die Steuer je Steuersatz einmal aus der Summe der Nettobeträge dieses
 *    Satzes (nicht je Position), wie es EN 16931 für die Steueraufschlüsselung
 *    (BG-23) vorsieht.
 *
 * Reine Funktionen, keine Abhängigkeiten.
 */
final class InvoiceCalculator {

	/** Größte Menge: 10.000 Einheiten (in Zehntausendstel). */
	public const MAX_QUANTITY_E4 = 100_000_000;
	/** Größter Einzelpreis: 1.000.000,00 EUR. */
	public const MAX_UNIT_PRICE_CENTS = 100_000_000;
	/** Größter Rabatt: 100 % (in Basispunkten). */
	public const MAX_DISCOUNT_BP = 10_000;

	private function __construct() {
	}

	/** Ganzzahlige Division, halbe Einheiten weg von null gerundet. */
	public static function roundDiv(int $numerator, int $denominator): int {
		if ($denominator === 0) {
			throw new \DivisionByZeroError();
		}
		$negative = ($numerator < 0) !== ($denominator < 0);
		$n = abs($numerator);
		$d = abs($denominator);
		$q = intdiv($n, $d);
		if (($n % $d) * 2 >= $d) {
			$q++;
		}
		return $negative ? -$q : $q;
	}

	/**
	 * Netto einer Position in Cent: Menge × Einzelpreis, danach der Rabatt.
	 *
	 * @param int $quantityE4 Menge in Zehntausendstel (10000 = 1 Stück)
	 * @param int $discountBp Rabatt in Basispunkten (1000 = 10 %)
	 */
	public static function lineNet(int $quantityE4, int $unitPriceCents, int $discountBp = 0): int {
		$base = self::roundDiv($quantityE4 * $unitPriceCents, 10_000);
		$discount = self::roundDiv($base * $discountBp, 10_000);
		return $base - $discount;
	}

	/**
	 * Summen und Steueraufschlüsselung einer Rechnung.
	 *
	 * Bei Kleinunternehmer und Steuerbefreiung wird keine Steuer ausgewiesen:
	 * die Steuer ist 0, und es gibt eine einzige Gruppe ohne Satz.
	 *
	 * @param list<array{lineNetCents: int, taxRateBp: int, taxCategory: string}> $lines
	 * @return array{netCents: int, taxCents: int, grossCents: int, taxBreakdown: list<array{category: string, rateBp: int, baseCents: int, taxCents: int}>}
	 */
	public static function totals(array $lines, string $taxMode): array {
		$net = 0;
		/** @var array<string, array{category: string, rateBp: int, baseCents: int, taxCents: int}> $groups */
		$groups = [];
		foreach ($lines as $line) {
			$net += $line['lineNetCents'];
			$regular = $taxMode === Invoice::TAX_REGULAR;
			$category = $regular ? $line['taxCategory'] : 'E';
			$rate = $regular ? $line['taxRateBp'] : 0;
			$key = $category . ':' . $rate;
			$groups[$key] ??= ['category' => $category, 'rateBp' => $rate, 'baseCents' => 0, 'taxCents' => 0];
			$groups[$key]['baseCents'] += $line['lineNetCents'];
		}
		$tax = 0;
		foreach ($groups as &$group) {
			$group['taxCents'] = $group['category'] === 'S' ? self::roundDiv($group['baseCents'] * $group['rateBp'], 10_000) : 0;
			$tax += $group['taxCents'];
		}
		unset($group);
		ksort($groups);

		return [
			'netCents' => $net,
			'taxCents' => $tax,
			'grossCents' => $net + $tax,
			'taxBreakdown' => array_values($groups),
		];
	}

	/**
	 * Verteilt einen Betrag nach Gewichten auf Teile, ohne dass ein Cent
	 * verloren geht (Verfahren der größten Reste). Für Teilzahlungen auf
	 * mehrere Erlöskonten: die Summe der Teile ist immer genau $amount.
	 *
	 * @param list<int> $weights nicht negativ; sind alle 0, fällt alles auf das erste Element
	 * @return list<int>
	 */
	public static function allocate(int $amount, array $weights): array {
		$count = count($weights);
		if ($count === 0) {
			return [];
		}
		$total = array_sum($weights);
		if ($total <= 0) {
			$parts = array_fill(0, $count, 0);
			$parts[0] = $amount;
			return $parts;
		}
		$sign = $amount < 0 ? -1 : 1;
		$abs = abs($amount);
		$parts = [];
		$remainders = [];
		$assigned = 0;
		foreach ($weights as $i => $w) {
			$exact = $abs * $w;
			$parts[$i] = intdiv($exact, $total);
			$remainders[$i] = $exact % $total;
			$assigned += $parts[$i];
		}
		$left = $abs - $assigned;
		// Die größten Reste bekommen je einen Cent; bei Gleichstand der frühere Index.
		$order = array_keys($remainders);
		usort($order, static fn (int $a, int $b): int => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);
		for ($k = 0; $k < $left; $k++) {
			$parts[$order[$k]]++;
		}
		return array_map(static fn (int $p): int => $p * $sign, $parts);
	}
}
