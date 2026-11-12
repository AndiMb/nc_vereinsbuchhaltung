<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Reconciliation;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Service\LedgerAggregator;
use OCA\Vereinsbuchhaltung\Service\Statement\BankAccountMatcher;
use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use OCA\Vereinsbuchhaltung\Service\Statement\StatementBalance;
use OCA\Vereinsbuchhaltung\Service\Statement\StatementParserRegistry;
use OCP\IL10N;

/**
 * Kontoabstimmung (Issue #159): vergleicht einen Kontoauszug mit dem, was die
 * App für ein Geldkonto führt, und zerlegt die Differenz der Salden.
 *
 * Liest nur. Der Auszug wird nicht importiert und nicht gespeichert.
 *
 * Nicht zu verwechseln mit dem „Bankabgleich“ des SEPA-Moduls
 * ({@see \OCA\Vereinsbuchhaltung\Service\Sepa}), der Rücklastschriften
 * zuordnet.
 *
 * Die App-Seite besteht aus drei Arten von Bewegungen
 * ({@see StatementMatcher}): Buchungen zu einem zugeordneten Bankumsatz,
 * Buchungen ohne Bankumsatz und noch nicht zugeordnete Bankumsätze. Der
 * App-Saldo ist entsprechend Kontostand laut Buchungen plus die noch nicht
 * zugeordneten Umsätze des Kontos – „Geldbestand + offen“. Damit gilt:
 *
 *   Differenz Ende = Differenz Anfang + zu viel − fehlt − verschoben + Rest
 *
 * (Differenz = App − Bank). Der Rest ist 0, solange der Auszug in sich stimmt.
 */
class ReconciliationService {

	public const DEFAULT_TOLERANCE = 3;
	public const MAX_TOLERANCE = 10;

	public function __construct(
		private AccountMapper $accounts,
		private JournalMapper $journals,
		private JournalLineMapper $lines,
		private BankTransactionMapper $transactions,
		private StatementParserRegistry $parsers,
		private StatementMatcher $matcher,
		private RowNormalizer $normalizer,
		private IL10N $l10n,
	) {
	}

	/**
	 * @param string|null $from erster Tag des Zeitraums (YYYY-MM-DD); null = erster Buchungstag des Auszugs
	 * @param string|null $to letzter Tag des Zeitraums; null = letzter Buchungstag des Auszugs
	 * @return array<string, mixed> siehe Klassendoc und docs zur Kontoabstimmung
	 * @throws \InvalidArgumentException bei fachlich unbrauchbarer Eingabe (übersetzte Meldung)
	 * @throws \OCP\AppFramework\Db\DoesNotExistException wenn es das Konto nicht gibt
	 * @throws \RuntimeException wenn der Auszug nicht lesbar ist
	 */
	public function reconcile(string $userId, int $accountId, string $content, ?string $from, ?string $to, int $toleranceDays = self::DEFAULT_TOLERANCE): array {
		$account = $this->accounts->find($accountId, $userId);
		if (!$account->getIsBank()) {
			throw new \InvalidArgumentException($this->l10n->t('Die Kontoabstimmung gibt es nur für Geldkonten.'));
		}
		$tolerance = max(0, min(self::MAX_TOLERANCE, $toleranceDays));
		$banks = array_values(array_filter($this->accounts->findAll($userId), static fn (Account $a): bool => (bool)$a->getIsBank()));

		// --- Auszug ----------------------------------------------------------
		[$parsed] = $this->parsers->parse($content);
		$rows = [];
		$foreign = [];
		foreach ($parsed as $row) {
			$own = isset($row['ownAccount']) ? (string)$row['ownAccount'] : null;
			if ($this->owner($own, $banks)?->getId() === $account->getId()) {
				$rows[] = $row;
			} else {
				$foreign[(string)$own] = ($foreign[(string)$own] ?? 0) + 1;
			}
		}
		if ($rows === []) {
			throw new \InvalidArgumentException($this->l10n->t(
				'Der Auszug enthält keine Buchungen für dieses Konto. Im Auszug gefunden: %s. Prüfen Sie die Datei und die IBAN des Geldkontos.',
				[implode(', ', array_keys($foreign))],
			));
		}
		$dates = array_map(static fn (array $r): string => (string)$r['bookingDate'], $rows);
		$statementFrom = min($dates);
		$statementTo = max($dates);

		$from = $this->date($from) ?? $statementFrom;
		$to = $this->date($to) ?? $statementTo;
		if ($from > $to) {
			throw new \InvalidArgumentException($this->l10n->t('Der Zeitraum endet vor seinem Beginn.'));
		}
		if ($from > $statementTo || $to < $statementFrom) {
			throw new \InvalidArgumentException($this->l10n->t('Der Zeitraum liegt außerhalb des Auszugs (%1$s bis %2$s).', [$statementFrom, $statementTo]));
		}

		$statement = [];
		foreach ($rows as $row) {
			$date = (string)$row['bookingDate'];
			if ($date >= $from && $date <= $to) {
				$statement[] = ['date' => $date, 'amountCents' => (int)$row['amountCents'], 'texts' => $this->normalizer->matchTexts($row), 'row' => $row];
			}
		}

		// --- App-Seite -------------------------------------------------------
		$windowFrom = $this->shift($from, -$tolerance);
		$windowTo = $this->shift($to, $tolerance);
		$app = $this->bookingMovements($userId, $accountId, $windowFrom, $windowTo);

		// Alle offenen Umsätze bis zum Fensterende: die älteren zählen zum Anfangssaldo.
		$openBefore = 0;
		$openUntilEnd = 0;
		$skipped = 0;
		foreach ($this->transactions->findUnassignedInRange($userId, null, $windowTo) as $tx) {
			$owner = $this->owner($tx->getOwnAccount(), $banks);
			if ($owner === null) {
				$skipped++;
				continue;
			}
			if ($owner->getId() !== $account->getId()) {
				continue;
			}
			$date = substr($tx->getBookingDate(), 0, 10);
			if ($date < $from) {
				$openBefore += $tx->getAmountCents();
			}
			if ($date <= $to) {
				$openUntilEnd += $tx->getAmountCents();
			}
			if ($date >= $windowFrom) {
				$app[] = [
					'kind' => StatementMatcher::KIND_UNASSIGNED,
					'id' => (int)$tx->getId(),
					'date' => $date,
					'amountCents' => $tx->getAmountCents(),
					'texts' => $this->txTexts($tx),
					'ref' => ['journalIds' => [], 'bankTxId' => (int)$tx->getId(), 'text' => $this->txLabel($tx)],
				];
			}
		}

		$match = $this->matcher->match($statement, $app, $from, $to, $tolerance);

		$surplus = array_map(fn (int $a): array => $this->appItem($app[$a]), $match['surplus']);
		$missing = array_map(fn (int $s): array => $this->statementItem($statement[$s]), $match['missing']);
		$shifted = array_map(fn (array $p): array => [
			'statement' => $this->statementItem($statement[$p['statement']]),
			'app' => $this->appItem($app[$p['app']]),
		], $match['shifted']);

		// --- Salden ----------------------------------------------------------
		$balance = null;
		$statementBalance = $this->balanceFor($content, $account, $banks);
		if ($statementBalance !== null) {
			$bankOpening = $statementBalance->openingCents;
			$bankClosing = $statementBalance->closingCents;
			foreach ($rows as $row) {
				// Eingeengter Zeitraum: die Auszugssalden auf seine Ränder umrechnen.
				if ((string)$row['bookingDate'] < $from) {
					$bankOpening += (int)$row['amountCents'];
				} elseif ((string)$row['bookingDate'] > $to) {
					$bankClosing -= (int)$row['amountCents'];
				}
			}
			$appOpening = $this->stock($userId, $account, $this->shift($from, -1)) + $openBefore;
			$appClosing = $this->stock($userId, $account, $to) + $openUntilEnd;
			$surplusCents = array_sum(array_column($surplus, 'amountCents'));
			$missingCents = array_sum(array_column($missing, 'amountCents'));
			$shiftedCents = array_sum(array_map(static fn (array $p): int => $p['app']['amountCents'], $shifted));
			$diffOpening = $appOpening - $bankOpening;
			$diffClosing = $appClosing - $bankClosing;
			$balance = [
				'bankOpeningCents' => $bankOpening,
				'bankClosingCents' => $bankClosing,
				'appOpeningCents' => $appOpening,
				'appClosingCents' => $appClosing,
				'diffOpeningCents' => $diffOpening,
				'diffClosingCents' => $diffClosing,
				'surplusCents' => $surplusCents,
				'missingCents' => $missingCents,
				'shiftedCents' => $shiftedCents,
				'restCents' => $diffClosing - ($diffOpening + $surplusCents - $missingCents - $shiftedCents),
				'gaps' => $statementBalance->gaps,
			];
		}

		return [
			'account' => ['id' => $account->getId(), 'number' => $account->getNumber(), 'name' => $account->getName()],
			'period' => ['from' => $from, 'to' => $to, 'statementFrom' => $statementFrom, 'statementTo' => $statementTo],
			'tolerance' => $tolerance,
			'counts' => [
				'statementRows' => count($statement),
				'otherAccountRows' => array_sum($foreign),
				'appMovements' => count(array_filter($app, static fn (array $m): bool => $m['date'] >= $from && $m['date'] <= $to)),
				'matched' => count($match['pairs']),
				'skippedUnassigned' => $skipped,
			],
			'surplus' => $surplus,
			'missing' => $missing,
			'shifted' => $shifted,
			'balance' => $balance,
		];
	}

	/**
	 * Buchungen auf dem Konto als Bewegungen: je zugeordnetem Bankumsatz eine
	 * (auch wenn er auf mehrere Buchungen aufgeteilt wurde), je Buchung ohne
	 * Bankumsatz eine.
	 *
	 * @return list<array{kind:string, id:int, date:string, amountCents:int, texts:list<string>, ref:array<string,mixed>}>
	 */
	private function bookingMovements(string $userId, int $accountId, string $from, string $to): array {
		$movements = [];
		$byTx = [];
		foreach ($this->journals->findAccountMovements($userId, $accountId, $from, $to) as $m) {
			if ($m['bankTxId'] !== null) {
				$byTx[$m['bankTxId']][] = $m;
				continue;
			}
			$movements[] = [
				'kind' => StatementMatcher::KIND_BOOKING,
				'id' => $m['journalId'],
				'date' => $m['date'],
				'amountCents' => $m['netCents'],
				'texts' => [$m['description']],
				'ref' => ['journalIds' => [$m['journalId']], 'bankTxId' => null, 'text' => $m['description']],
			];
		}

		$txs = $byTx === [] ? [] : $this->transactions->findByIds($userId, array_keys($byTx));
		foreach ($byTx as $txId => $parts) {
			usort($parts, static fn (array $a, array $b): int => $a['journalId'] <=> $b['journalId']);
			$tx = $txs[$txId] ?? null;
			$descriptions = array_values(array_unique(array_column($parts, 'description')));
			$net = array_sum(array_column($parts, 'netCents'));
			if ($net === 0) {
				continue;
			}
			$movements[] = [
				'kind' => StatementMatcher::KIND_ASSIGNED,
				'id' => $parts[0]['journalId'],
				'date' => $parts[0]['date'],
				'amountCents' => $net,
				// Der Auszug trägt die Texte der Bank – die des Umsatzes passen am besten, der Buchungstext hilft zusätzlich.
				'texts' => array_values(array_merge($tx !== null ? $this->txTexts($tx) : [], $descriptions)),
				'ref' => [
					'journalIds' => array_column($parts, 'journalId'),
					'bankTxId' => (int)$txId,
					'text' => $tx !== null ? $this->txLabel($tx) : implode(' / ', $descriptions),
				],
			];
		}
		return $movements;
	}

	/**
	 * Das Geldkonto, zu dem eine Kontoangabe aus Auszug oder Bankumsatz gehört
	 * – dieselbe Regel wie beim Zuordnen eines Umsatzes
	 * ({@see \OCA\Vereinsbuchhaltung\Service\AccountService::resolveBankAccount()}),
	 * hier ohne Datenbankzugriff je Zeile: ein eindeutiger Treffer über IBAN,
	 * Kontonummer oder BLZ; ohne verwertbare Angabe das erste Geldkonto.
	 *
	 * @param list<Account> $banks
	 * @return Account|null null, wenn die Angabe auf ein Konto zeigt, das zu keinem oder zu mehreren Geldkonten passt
	 */
	private function owner(?string $ownAccount, array $banks): ?Account {
		if ($banks === []) {
			return null;
		}
		$matcher = new BankAccountMatcher($this->normalizer);
		$hits = $matcher->candidates($ownAccount, $banks);
		if (count($hits) === 1) {
			return $hits[0];
		}
		$anyIban = array_filter($banks, fn (Account $a): bool => $this->normalizer->normalizeOwnAccount($a->getIban()) !== null) !== [];
		if (count($banks) === 1 || !$anyIban || !$matcher->identifiesAccount($ownAccount)) {
			return $banks[0];
		}
		return null;
	}

	/** @param list<Account> $banks */
	private function balanceFor(string $content, Account $account, array $banks): ?StatementBalance {
		foreach ($this->parsers->balances($content) as $balance) {
			if ($this->owner($balance->ownAccount, $banks)?->getId() === $account->getId()) {
				return $balance;
			}
		}
		return null;
	}

	/** Kontostand laut Buchungen bis einschließlich Stichtag. */
	private function stock(string $userId, Account $account, string $until): int {
		return LedgerAggregator::stock($account, $this->lines->sumByAccount($userId, null, $until));
	}

	/** @return list<string> */
	private function txTexts(BankTransaction $tx): array {
		return $this->normalizer->matchTexts([
			'counterparty' => $tx->getCounterparty(),
			'purpose' => $tx->getPurpose(),
			'bookingText' => $tx->getBookingText(),
		]);
	}

	private function txLabel(BankTransaction $tx): string {
		return $this->label((string)$tx->getCounterparty(), (string)$tx->getPurpose(), (string)$tx->getBookingText());
	}

	private function label(string $counterparty, string $purpose, string $bookingText): string {
		$parts = array_values(array_unique(array_filter([trim($counterparty), trim($purpose)], static fn (string $p): bool => $p !== '')));
		return $parts === [] ? trim($bookingText) : implode(' – ', $parts);
	}

	/**
	 * @param array{kind:string, date:string, amountCents:int, ref:array<string,mixed>} $movement
	 * @return array{kind:string, journalIds:list<int>, bankTxId:?int, date:string, amountCents:int, text:string}
	 */
	private function appItem(array $movement): array {
		return [
			'kind' => $movement['kind'],
			'journalIds' => $movement['ref']['journalIds'],
			'bankTxId' => $movement['ref']['bankTxId'],
			'date' => $movement['date'],
			'amountCents' => $movement['amountCents'],
			'text' => $movement['ref']['text'],
		];
	}

	/**
	 * @param array{date:string, amountCents:int, row:array<string,mixed>} $line
	 * @return array{date:string, valueDate:?string, amountCents:int, text:string, counterparty:string}
	 */
	private function statementItem(array $line): array {
		$row = $line['row'];
		$purpose = trim((string)($row['purpose'] ?? ''));
		return [
			'date' => $line['date'],
			'valueDate' => isset($row['valueDate']) ? (string)$row['valueDate'] : null,
			'amountCents' => $line['amountCents'],
			'text' => $purpose !== '' ? $purpose : trim((string)($row['bookingText'] ?? '')),
			'counterparty' => trim((string)($row['counterparty'] ?? '')),
		];
	}

	/** Prüft ein Datum aus der Anfrage; leer gilt als nicht angegeben. */
	private function date(?string $value): ?string {
		$value = $value !== null ? trim($value) : '';
		if ($value === '') {
			return null;
		}
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
			throw new \InvalidArgumentException($this->l10n->t('Ungültiges Datum: %s', [$value]));
		}
		return $value;
	}

	private function shift(string $date, int $days): string {
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		if ($parsed === false || $days === 0) {
			return $date;
		}
		return $parsed->modify(($days > 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
	}
}
