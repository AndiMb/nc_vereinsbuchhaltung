<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\ImportLog;
use OCA\Vereinsbuchhaltung\Db\ImportLogMapper;
use OCA\Vereinsbuchhaltung\Db\Rule;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;

class ImportService {

	public function __construct(
		private CamtCsvParser $parser,
		private BankTransactionMapper $txMapper,
		private ImportLogMapper $importMapper,
		private RuleMapper $ruleMapper,
		private BookingService $bookingService,
	) {
	}

	/**
	 * Analysiert die Datei, ohne etwas zu speichern.
	 *
	 * @return array{total:int, new:int, duplicate:int, sample:array<int, array<string,mixed>>}
	 */
	public function preview(string $userId, string $content): array {
		$rows = $this->parser->parse($content);
		[$new, $duplicate] = $this->splitNewAndDuplicate($userId, $rows);

		$sample = array_map(static function (array $r): array {
			return [
				'bookingDate' => $r['bookingDate'],
				'amount' => $r['amountCents'] / 100,
				'counterparty' => $r['counterparty'],
				'purpose' => $r['purpose'],
			];
		}, array_slice($new, 0, 20));

		return [
			'total' => count($rows),
			'new' => count($new),
			'duplicate' => count($duplicate),
			'sample' => $sample,
		];
	}

	/**
	 * Importiert die Datei: nur neue Buchungen werden gespeichert.
	 *
	 * @return array{import:ImportLog, total:int, new:int, duplicate:int, autoAssigned:int}
	 */
	public function commit(string $userId, string $filename, string $content, bool $applyRules = true): array {
		$rows = $this->parser->parse($content);
		[$new] = $this->splitNewAndDuplicate($userId, $rows);

		$log = new ImportLog();
		$log->setUserId($userId);
		$log->setFilename(mb_substr($filename, 0, 255));
		$log->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));
		$log->setRowsTotal(count($rows));
		$log->setRowsNew(count($new));
		$log->setRowsDuplicate(count($rows) - count($new));
		$log = $this->importMapper->insert($log);

		$rules = $applyRules ? $this->ruleMapper->findAll($userId) : [];
		$autoAssigned = 0;

		foreach ($new as $row) {
			$tx = $this->buildEntity($userId, $log->getId(), $row);
			$tx = $this->txMapper->insert($tx);

			if ($applyRules) {
				$accountId = $this->matchRule($tx, $rules);
				if ($accountId !== null) {
					try {
						$this->bookingService->assign($tx, $accountId);
						$autoAssigned++;
					} catch (\Throwable) {
						// Regel ins Leere – Buchung bleibt unzugeordnet
					}
				}
			}
		}

		return [
			'import' => $log,
			'total' => count($rows),
			'new' => count($new),
			'duplicate' => count($rows) - count($new),
			'autoAssigned' => $autoAssigned,
		];
	}

	/**
	 * Teilt geparste Zeilen in neue und bereits vorhandene (per Hash).
	 * Behandelt auch Dubletten innerhalb derselben Datei.
	 *
	 * @param array<int, array<string,mixed>> $rows
	 * @return array{0: array<int, array<string,mixed>>, 1: array<int, array<string,mixed>>}
	 */
	private function splitNewAndDuplicate(string $userId, array $rows): array {
		$hashes = array_column($rows, 'hash');
		$existing = array_flip($this->txMapper->findExistingHashes($userId, $hashes));

		$new = [];
		$duplicate = [];
		$seen = [];
		foreach ($rows as $row) {
			$h = $row['hash'];
			if (isset($existing[$h]) || isset($seen[$h])) {
				$duplicate[] = $row;
				continue;
			}
			$seen[$h] = true;
			$new[] = $row;
		}
		return [$new, $duplicate];
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function buildEntity(string $userId, int $importId, array $row): BankTransaction {
		$tx = new BankTransaction();
		$tx->setUserId($userId);
		$tx->setImportId($importId);
		$tx->setBookingDate($row['bookingDate']);
		$tx->setValueDate($row['valueDate']);
		$tx->setAmountCents($row['amountCents']);
		$tx->setCurrency($row['currency'] ?? 'EUR');
		$tx->setBookingText($row['bookingText']);
		$tx->setPurpose($row['purpose']);
		$tx->setCounterparty($row['counterparty']);
		$tx->setCounterpartyIban($row['counterpartyIban']);
		$tx->setCounterpartyBic($row['counterpartyBic']);
		$tx->setOwnAccount($row['ownAccount']);
		$tx->setHash($row['hash']);
		$tx->setStatus('unassigned');
		return $tx;
	}

	/**
	 * @param Rule[] $rules nach Priorität sortiert
	 */
	private function matchRule(BankTransaction $tx, array $rules): ?int {
		foreach ($rules as $rule) {
			$haystack = match ($rule->getMatchField()) {
				'counterparty' => $tx->getCounterparty(),
				'purpose' => $tx->getPurpose(),
				'iban' => $tx->getCounterpartyIban(),
				default => null,
			};
			if ($haystack === null || $haystack === '') {
				continue;
			}
			if (mb_stripos($haystack, $rule->getMatchValue()) !== false) {
				return $rule->getContraAccountId();
			}
		}
		return null;
	}
}
