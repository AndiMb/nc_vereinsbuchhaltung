<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\CostCenter;

/**
 * Regeln für die Verteilung einer Buchungszeile auf Kostenstellen (Issue #7).
 *
 * Reine Funktionen ohne Nextcloud-Abhängigkeit, damit sie sich direkt testen
 * lassen; {@see LineAllocationWriter} wendet sie an.
 *
 * Zeilenform überall:
 *   ['accountId' => int, 'debitCents' => int, 'creditCents' => int,
 *    'allocations' => [['costCenterId' => int, 'amountCents' => int], …]]
 *
 * Der Schlüssel `allocations` ist optional, und der Unterschied trägt
 * Bedeutung: fehlt er, hat der Aufrufer zu den Kostenstellen nichts gesagt
 * (alter Client, Modul ausgeschaltet, automatische Buchung) – dann bleibt
 * eine vorhandene Verteilung erhalten, siehe {@see carryOver()}. Ist er
 * vorhanden, auch leer, ersetzt er die Verteilung der Zeile.
 *
 * @psalm-type Allocation = array{costCenterId:int, amountCents:int}
 * @psalm-type Line = array{accountId:int, debitCents:int, creditCents:int, allocations?: list<Allocation>}
 */
final class AllocationRules {

	/** Kontotypen, deren Zeilen eine Kostenstelle tragen dürfen (wie Account::canCarryCostCenter()). */
	private const CARRYING_TYPES = ['income', 'expense'];

	/**
	 * Prüft die Verteilungen aller Zeilen, die eine angeben.
	 *
	 * @param list<array<string, mixed>> $lines
	 * @param array<int, string> $accountTypes Konto-ID => Kontotyp
	 * @param list<int> $knownIds alle Kostenstellen des Bestands
	 * @param list<int> $activeIds die davon aktiven
	 * @param array<int, list<int>> $previous Konto-ID => bisher an dieser Zeile
	 *                                        zugeordnete Kostenstellen; eine inzwischen deaktivierte bleibt dort gültig
	 * @return array{code:string, line:int, params?:array<string, int>}|null
	 *                                                                       erster Fehler mit dem Index der Zeile, oder null
	 */
	public static function validate(array $lines, array $accountTypes, array $knownIds, array $activeIds, array $previous = []): ?array {
		foreach (array_values($lines) as $index => $line) {
			if (!array_key_exists('allocations', $line)) {
				continue;
			}
			$allocations = $line['allocations'];
			if ($allocations === []) {
				continue;
			}
			$accountId = (int)$line['accountId'];
			if (!in_array($accountTypes[$accountId] ?? '', self::CARRYING_TYPES, true)) {
				return ['code' => 'alloc_account_type', 'line' => $index];
			}
			$seen = [];
			$sum = 0;
			foreach ($allocations as $allocation) {
				$costCenterId = (int)$allocation['costCenterId'];
				$amount = (int)$allocation['amountCents'];
				if ($amount <= 0) {
					return ['code' => 'alloc_amount', 'line' => $index];
				}
				if (!in_array($costCenterId, $knownIds, true)) {
					return ['code' => 'alloc_unknown', 'line' => $index];
				}
				if (isset($seen[$costCenterId])) {
					return ['code' => 'alloc_duplicate', 'line' => $index];
				}
				$seen[$costCenterId] = true;
				if (!in_array($costCenterId, $activeIds, true)
					&& !in_array($costCenterId, $previous[$accountId] ?? [], true)) {
					return ['code' => 'alloc_inactive', 'line' => $index];
				}
				$sum += $amount;
			}
			$lineAmount = (int)$line['debitCents'] + (int)$line['creditCents'];
			if ($sum > $lineAmount) {
				return [
					'code' => 'alloc_exceeds_line',
					'line' => $index,
					'params' => ['allocated' => $sum, 'line' => $lineAmount],
				];
			}
		}
		return null;
	}

	/**
	 * Legt für jede neue Zeile die Verteilung fest, die geschrieben wird.
	 *
	 * Beim Ändern einer Buchung werden alle Zeilen gelöscht und neu
	 * geschrieben. Sagt der Aufrufer zu einer Zeile nichts (Schlüssel fehlt),
	 * übernimmt sie die Verteilung der alten Zeile mit demselben Konto,
	 * demselben Betrag und derselben Seite. Die Zuordnung ist eindeutig, weil
	 * ein Konto je Buchung nur einmal vorkommt. Hat sich Konto, Betrag oder
	 * Seite geändert, ist nicht mehr gesagt, dass die Aufteilung noch passt –
	 * dann beginnt die Zeile ohne Kostenstelle.
	 *
	 * @param list<array<string, mixed>> $oldLines bisherige Zeilen, `allocations` gesetzt
	 * @param list<array<string, mixed>> $newLines
	 * @return list<array<string, mixed>> $newLines, `allocations` in jeder Zeile gesetzt
	 */
	public static function carryOver(array $oldLines, array $newLines): array {
		$byAccount = [];
		foreach ($oldLines as $old) {
			$byAccount[(int)$old['accountId']] = $old;
		}
		$out = [];
		foreach (array_values($newLines) as $line) {
			if (!array_key_exists('allocations', $line)) {
				$old = $byAccount[(int)$line['accountId']] ?? null;
				$same = $old !== null
					&& (int)$old['debitCents'] === (int)$line['debitCents']
					&& (int)$old['creditCents'] === (int)$line['creditCents'];
				$line['allocations'] = $same ? array_values($old['allocations'] ?? []) : [];
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * Bisherige Zuordnung je Konto, für die Ausnahme bei deaktivierten
	 * Kostenstellen in {@see validate()}.
	 *
	 * @param list<array<string, mixed>> $oldLines
	 * @return array<int, list<int>>
	 */
	public static function previousOf(array $oldLines): array {
		$previous = [];
		foreach ($oldLines as $old) {
			$ids = array_map(static fn (array $a): int => (int)$a['costCenterId'], $old['allocations'] ?? []);
			if ($ids !== []) {
				$previous[(int)$old['accountId']] = array_values($ids);
			}
		}
		return $previous;
	}
}
