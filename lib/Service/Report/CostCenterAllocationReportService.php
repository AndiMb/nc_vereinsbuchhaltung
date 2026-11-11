<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Report;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenter;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\LineAllocationMapper;
use OCA\Vereinsbuchhaltung\Service\LedgerAggregator;
use OCA\Vereinsbuchhaltung\Service\PeriodService;

/**
 * Bericht "Kostenstellen" (Issue #7): Einnahmen, Ausgaben und Ergebnis je
 * Kostenstelle, aufgeteilt nach Konten.
 *
 * Anders als die Auswertungsgruppen ({@see CostCenterResolver}) summiert
 * dieser Bericht nicht ganze Konten, sondern die Anteile einzelner
 * Buchungszeilen. Was keiner Kostenstelle zugeordnet ist, steht unter
 * "Ohne Kostenstelle" – berechnet als Rest zur Kontensumme, nicht als eigene
 * Zählung. Die Schlusssumme ist damit immer dieselbe wie in den übrigen
 * Berichten.
 *
 * Vorzeichen und die Einteilung in Einnahmen und Ausgaben kommen aus
 * {@see LedgerAggregator}; hier wird nichts davon nachgebaut.
 */
class CostCenterAllocationReportService {

	public function __construct(
		private AccountMapper $accounts,
		private JournalLineMapper $lines,
		private LineAllocationMapper $allocations,
		private BookingCostCenterMapper $costCenters,
		private PeriodService $periods,
	) {
	}

	/**
	 * @return array<string, mixed> siehe {@see compute()}
	 */
	public function report(string $userId, ?int $periodId): array {
		[$from, $to] = $this->periods->range($userId, $periodId);
		return self::compute(
			$this->accounts->findAll($userId),
			$this->lines->sumByAccount($userId, $from, $to),
			$this->allocations->sumByCostCenterAndAccount($userId, $from, $to),
			$this->costCenters->findAll($userId),
		);
	}

	/**
	 * Alle Beträge in Cent.
	 *
	 * @param iterable<Account> $accounts
	 * @param array<int, array{debit:int, credit:int}> $ledgerSums Soll/Haben je Konto im Zeitraum
	 * @param list<array{costCenterId:int, accountId:int, debit:int, credit:int}> $allocSums
	 *                                                                                       verteilte Beträge je Kostenstelle und Konto, nach Seite der Zeile
	 * @param iterable<BookingCostCenter> $costCenters
	 * @return array{
	 *     centers: list<array<string, mixed>>,
	 *     unassigned: array<string, mixed>,
	 *     total: array{incomeCents:int, expenseCents:int, resultCents:int},
	 *     ledgerResultCents:int,
	 *     reconciled:bool,
	 *     requiredOpen: list<array{accountId:int, number:string, name:string, amountCents:int, kind:string}>
	 * }
	 */
	public static function compute(iterable $accounts, array $ledgerSums, array $allocSums, iterable $costCenters): array {
		// Alles, was ins Ergebnis eingeht – auch Konten, die keine Kostenstelle
		// tragen dürfen (Durchlaufkonten): sie stehen ganz im Rest, sonst
		// stimmte die Schlusssumme nicht mit den Kontensalden überein.
		$relevant = [];
		foreach ($accounts as $account) {
			if ($account->isResultRelevant()) {
				$relevant[] = $account;
			}
		}
		usort($relevant, static fn (Account $a, Account $b): int => strnatcmp((string)$a->getNumber(), (string)$b->getNumber()));

		$byCenter = [];
		$unassigned = [];
		foreach ($relevant as $account) {
			$id = $account->getId();
			$unassigned[$id] = [
				'debit' => $ledgerSums[$id]['debit'] ?? 0,
				'credit' => $ledgerSums[$id]['credit'] ?? 0,
			];
		}
		foreach ($allocSums as $sum) {
			$accountId = (int)$sum['accountId'];
			if (!isset($unassigned[$accountId])) {
				// Konto geht nicht (mehr) ins Ergebnis ein – dann zählt auch
				// seine Verteilung nicht, sonst fehlte ihr das Gegenstück.
				continue;
			}
			$centerId = (int)$sum['costCenterId'];
			$byCenter[$centerId][$accountId] = ['debit' => (int)$sum['debit'], 'credit' => (int)$sum['credit']];
			$unassigned[$accountId]['debit'] -= (int)$sum['debit'];
			$unassigned[$accountId]['credit'] -= (int)$sum['credit'];
		}

		$known = [];
		foreach ($costCenters as $costCenter) {
			$known[$costCenter->getId()] = $costCenter;
		}
		$centers = [];
		foreach ($byCenter as $centerId => $sums) {
			$block = self::block($relevant, $sums);
			if ($block['income'] === [] && $block['expense'] === []) {
				continue;
			}
			$costCenter = $known[$centerId] ?? null;
			$centers[] = [
				'id' => $centerId,
				'code' => $costCenter !== null ? (string)$costCenter->getCode() : '#' . $centerId,
				'name' => $costCenter !== null ? (string)$costCenter->getName() : '',
				'active' => $costCenter !== null && $costCenter->isActive(),
			] + $block;
		}
		usort($centers, static fn (array $a, array $b): int => strnatcasecmp($a['code'], $b['code']));

		$rest = self::block($relevant, $unassigned);
		$ledger = LedgerAggregator::incomeExpense($relevant, $ledgerSums);
		$parts = array_merge($centers, [$rest]);
		$total = [
			'incomeCents' => array_sum(array_column($parts, 'incomeCents')),
			'expenseCents' => array_sum(array_column($parts, 'expenseCents')),
			'resultCents' => array_sum(array_column($parts, 'resultCents')),
		];

		return [
			'centers' => $centers,
			'unassigned' => $rest,
			'total' => $total,
			'ledgerResultCents' => $ledger['resultCents'],
			'reconciled' => $total['resultCents'] === $ledger['resultCents'],
			'requiredOpen' => self::requiredOpen($relevant, $rest),
		];
	}

	/**
	 * Vollständigkeitsprüfung zum Kennzeichen „Kostenstelle verpflichtend“
	 * (Issue #334): je Pflichtkonto der Betrag, der keiner Kostenstelle
	 * zugeordnet ist. Genau die Zeilen aus „Ohne Kostenstelle“, die auf einem
	 * solchen Konto liegen – also auch Beträge aus automatisch erzeugten
	 * Buchungen, an die im Dialog niemand erinnert wurde.
	 *
	 * @param list<Account> $accounts
	 * @param array<string, mixed> $rest der Block „Ohne Kostenstelle“
	 * @return list<array{accountId:int, number:string, name:string, amountCents:int, kind:string}>
	 */
	private static function requiredOpen(array $accounts, array $rest): array {
		$required = [];
		foreach ($accounts as $account) {
			if ($account->isCostCenterRequired()) {
				$required[$account->getId()] = true;
			}
		}
		if ($required === []) {
			return [];
		}
		$open = [];
		foreach (['income', 'expense'] as $kind) {
			foreach ($rest[$kind] as $row) {
				if (isset($required[$row['accountId']])) {
					$open[] = $row + ['kind' => $kind];
				}
			}
		}
		usort($open, static fn (array $a, array $b): int => strnatcmp($a['number'], $b['number']));
		return $open;
	}

	/**
	 * Einnahmen, Ausgaben und Ergebnis eines Blocks; Konten ohne Betrag bleiben weg.
	 *
	 * @param list<Account> $accounts
	 * @param array<int, array{debit:int, credit:int}> $sums
	 * @return array{
	 *     income: list<array{accountId:int, number:string, name:string, amountCents:int}>,
	 *     expense: list<array{accountId:int, number:string, name:string, amountCents:int}>,
	 *     incomeCents:int, expenseCents:int, resultCents:int
	 * }
	 */
	private static function block(array $accounts, array $sums): array {
		$result = LedgerAggregator::incomeExpense($accounts, $sums);
		$rows = static function (array $entries): array {
			$out = [];
			foreach ($entries as $entry) {
				if ($entry['cents'] === 0) {
					continue;
				}
				$out[] = [
					'accountId' => $entry['account']->getId(),
					'number' => (string)$entry['account']->getNumber(),
					'name' => (string)$entry['account']->getName(),
					'amountCents' => $entry['cents'],
				];
			}
			return $out;
		};
		return [
			'income' => $rows($result['income']),
			'expense' => $rows($result['expense']),
			'incomeCents' => $result['incomeCents'],
			'expenseCents' => $result['expenseCents'],
			'resultCents' => $result['resultCents'],
		];
	}
}
