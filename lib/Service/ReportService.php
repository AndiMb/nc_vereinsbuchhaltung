<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\CostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;

/**
 * Auswertungen, insbesondere nach Kostenstellen.
 *
 * Die Kostenstelle steckt als zweite Zahlengruppe in der Kontonummer:
 *   "111 51 2021"  ->  Typ 111, Kostenstelle 51, Jahr 2021
 *   "546 01 01"    ->  Typ 546, Kostenstelle 01 (ideell)
 *   "111"          ->  nur Einnahmen-/Ausgabentyp, keine Kostenstelle
 */
class ReportService {

	/** Eingebaute Standardnamen für feste Kostenstellen. */
	private const BUILTIN = [
		'01' => 'Ideeller Bereich',
		'11' => 'Verbandszeitung',
	];

	public function __construct(
		private AccountMapper $accountMapper,
		private JournalLineMapper $lineMapper,
		private CostCenterMapper $costCenterMapper,
	) {
	}

	/**
	 * Liefert die Kostenstelle (2-stellige Zahl) aus einer Kontonummer oder null.
	 */
	public static function costCode(string $number): ?string {
		$parts = preg_split('/\s+/', trim($number)) ?: [];
		if (count($parts) >= 2 && preg_match('/^\d{2}$/', $parts[1])) {
			return $parts[1];
		}
		return null;
	}

	/**
	 * Auswertung Einnahmen/Ausgaben/Ergebnis je Kostenstelle.
	 *
	 * @return array{costCenters: array<int, array<string,mixed>>, totals: array<string,float>}
	 */
	public function costCenterReport(string $userId): array {
		$accounts = $this->accountMapper->findAll($userId);
		$sums = $this->lineMapper->sumByAccount($userId);

		$names = [];
		foreach ($this->costCenterMapper->findAll($userId) as $cc) {
			$names[$cc->getCode()] = $cc->getName();
		}

		$groups = [];
		foreach ($accounts as $a) {
			$type = $a->getType();
			if ($type !== 'income' && $type !== 'expense') {
				continue;
			}
			$code = self::costCode($a->getNumber());
			$key = $code ?? '';
			if (!isset($groups[$key])) {
				$groups[$key] = [
					'code' => $code,
					'name' => $this->resolveName($code, $names),
					'incomeCents' => 0,
					'expenseCents' => 0,
					'accounts' => [],
				];
			}
			$id = $a->getId();
			$debit = $sums[$id]['debit'] ?? 0;
			$credit = $sums[$id]['credit'] ?? 0;
			if ($type === 'income') {
				$balCents = $credit - $debit;
				$groups[$key]['incomeCents'] += $balCents;
			} else {
				$balCents = $debit - $credit;
				$groups[$key]['expenseCents'] += $balCents;
			}
			if ($balCents !== 0) {
				$groups[$key]['accounts'][] = [
					'accountId' => $id,
					'number' => $a->getNumber(),
					'name' => $a->getName(),
					'type' => $type,
					'balance' => $balCents / 100,
				];
			}
		}

		// Sortierung: nach Code aufsteigend, "(ohne Kostenstelle)" ans Ende
		uasort($groups, static function ($a, $b) {
			$ca = $a['code'] ?? 'zzz';
			$cb = $b['code'] ?? 'zzz';
			return strcmp($ca, $cb);
		});

		$result = [];
		$totalIncome = 0;
		$totalExpense = 0;
		foreach ($groups as $g) {
			$totalIncome += $g['incomeCents'];
			$totalExpense += $g['expenseCents'];
			usort($g['accounts'], static fn ($x, $y) => strcmp($x['number'], $y['number']));
			$result[] = [
				'code' => $g['code'],
				'name' => $g['name'],
				'income' => $g['incomeCents'] / 100,
				'expense' => $g['expenseCents'] / 100,
				'result' => ($g['incomeCents'] - $g['expenseCents']) / 100,
				'accounts' => $g['accounts'],
			];
		}

		return [
			'costCenters' => $result,
			'totals' => [
				'income' => $totalIncome / 100,
				'expense' => $totalExpense / 100,
				'result' => ($totalIncome - $totalExpense) / 100,
			],
		];
	}

	/**
	 * @param array<string,string> $names
	 */
	private function resolveName(?string $code, array $names): string {
		if ($code === null) {
			return '(ohne Kostenstelle)';
		}
		return $names[$code] ?? self::BUILTIN[$code] ?? ('Kostenstelle ' . $code);
	}
}
