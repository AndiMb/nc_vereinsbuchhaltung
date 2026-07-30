<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\ImportLog;
use OCA\Vereinsbuchhaltung\Db\ImportLogMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Rule;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
use OCA\Vereinsbuchhaltung\Service\Statement\StatementParserRegistry;

class ImportService {

	public function __construct(
		private StatementParserRegistry $parsers,
		private RowNormalizer $normalizer,
		private BankTransactionMapper $txMapper,
		private ImportLogMapper $importMapper,
		private RuleMapper $ruleMapper,
		private BookingService $bookingService,
		private JournalMapper $journalMapper,
		private TransactionRunner $transaction,
	) {
	}

	/**
	 * Analysiert die Datei, ohne etwas zu speichern.
	 *
	 * @return array{total:int, new:int, duplicate:int, format:string, sample:array<int, array<string,mixed>>}
	 */
	public function preview(string $userId, string $content): array {
		[$rows, $format] = $this->parsers->parse($content);
		[$new, $duplicate, $existingBookings] = $this->splitNewAndDuplicate($userId, $rows);

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
			// davon: bereits als vorhandene Buchung erkannt (z. B. aus XBUC-Import)
			'existingBookings' => $existingBookings,
			'format' => $format,
			'sample' => $sample,
		];
	}

	/**
	 * Importiert die Datei: nur neue Buchungen werden gespeichert.
	 *
	 * @return array{import:ImportLog, total:int, new:int, duplicate:int, autoAssigned:int}
	 */
	public function commit(string $userId, string $filename, string $content, bool $applyRules = true): array {
		[$rows, $format] = $this->parsers->parse($content);
		return $this->commitRows($userId, $filename, $rows, $format, $applyRules);
	}

	/**
	 * Importiert bereits gelesene Zeilen.
	 *
	 * Getrennt von {@see commit()}, damit auch Quellen ohne Datei diesen Weg
	 * nehmen können – der Wachordner reicht den Dateiinhalt durch, der geplante
	 * FinTS-Abruf später die Antwort der Bank. Dublettenerkennung, Regeln und
	 * Protokoll sind für alle Quellen dieselben.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array{import:ImportLog, total:int, new:int, duplicate:int, autoAssigned:int}
	 */
	public function commitRows(string $userId, string $label, array $rows, string $source, bool $applyRules = true): array {
		// Ganz oder gar nicht: bricht der Import in der Mitte ab (Timeout,
		// fehlerhafte Zeile), bleibt sonst ein halb eingelesener Kontoauszug
		// zurück, dessen Rest beim zweiten Versuch als Dublette gilt.
		return $this->transaction->run(fn (): array => $this->doCommit($userId, $label, $rows, $source, $applyRules));
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array{import:ImportLog, total:int, new:int, duplicate:int, autoAssigned:int}
	 */
	private function doCommit(string $userId, string $filename, array $rows, string $source, bool $applyRules): array {
		[$new, , $existingBookings] = $this->splitNewAndDuplicate($userId, $rows);

		$log = new ImportLog();
		$log->setUserId($userId);
		$log->setFilename(mb_substr($filename, 0, 255));
		$log->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));
		$log->setRowsTotal(count($rows));
		$log->setRowsNew(count($new));
		$log->setRowsDuplicate(count($rows) - count($new));
		$log->setSource($source);
		$log = $this->importMapper->insert($log);

		$rules = $applyRules ? $this->ruleMapper->findAll($userId) : [];
		$autoAssigned = 0;
		$ruleFailed = 0;

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
						// Regel ins Leere (gelöschtes Gegenkonto) oder Jahr bereits
						// abgeschlossen – die Buchung bleibt unzugeordnet. Beim
						// Import per Hand fällt das in der Liste auf; ein
						// unbeaufsichtigter Lauf (Wachordner) muss es dagegen
						// melden, sonst bleibt es unbemerkt liegen.
						$ruleFailed++;
					}
				}
			}
		}

		return [
			'import' => $log,
			'total' => count($rows),
			'new' => count($new),
			'duplicate' => count($rows) - count($new),
			'existingBookings' => $existingBookings,
			'autoAssigned' => $autoAssigned,
			'ruleFailed' => $ruleFailed,
			'format' => $source,
		];
	}

	/**
	 * Teilt geparste Zeilen in neue und bereits vorhandene.
	 *
	 * Drei Dublettenquellen:
	 *  1. Hash gegen bereits importierte Bankbuchungen (und Dubletten in derselben Datei).
	 *  2. Datum (Buchungs- ODER Valutadatum) + Betrag + normalisierter Text gegen
	 *     bestehende Buchungen OHNE Bankbezug (XBUC-/manuell erfasst). So werden
	 *     Umsätze, die schon über einen XBUC-Import gebucht sind, nicht ein
	 *     zweites Mal angelegt (die Alt-Software nutzte teils das Valutadatum). Die
	 *     Normalisierung entfernt Trennzeichen/Groß-Kleinschreibung, sodass
	 *     "Empfänger: Verwendungszweck" (XBUC) und "Empfänger – Verwendungszweck"
	 *     (Bankzuordnung) als gleich gelten.
	 *  3. Derselbe weiche Schlüssel gegen bereits vorhandene BANKbuchungen. Nötig,
	 *     weil der Hash das eigene Konto enthält und die Quellen es unterschiedlich
	 *     schreiben: die CSV der Sparkasse führt dort oft nur eine Kontonummer,
	 *     CAMT.053 und FinTS immer die IBAN. Ohne diesen Schritt läge derselbe
	 *     Umsatz beim Wechsel des Exportformats doppelt in „Zuzuordnen".
	 *
	 * @param array<int, array<string,mixed>> $rows
	 * @return array{0: array<int, array<string,mixed>>, 1: array<int, array<string,mixed>>, 2: int}
	 *         [neue Zeilen, Dubletten, davon bereits als Buchung vorhanden]
	 */
	private function splitNewAndDuplicate(string $userId, array $rows): array {
		$hashes = array_column($rows, 'hash');
		$existing = array_flip($this->txMapper->findExistingHashes($userId, $hashes));
		$bookingKeys = $this->existingBookingKeys($userId) + $this->existingBankKeys($userId);

		$new = [];
		$duplicate = [];
		$existingBookings = 0;
		$seen = [];
		foreach ($rows as $row) {
			$h = $row['hash'];
			if (isset($existing[$h]) || isset($seen[$h])) {
				$duplicate[] = $row;
				continue;
			}
			if ($this->matchesExistingBooking($row, $bookingKeys)) {
				$duplicate[] = $row;
				$existingBookings++;
				continue;
			}
			$seen[$h] = true;
			$new[] = $row;
		}
		return [$new, $duplicate, $existingBookings];
	}

	/**
	 * Dublettenschlüssel aller Buchungen ohne Bankbezug (XBUC/manuell):
	 * "datum|betragAbsCents|normalisierterText".
	 *
	 * @return array<string, true>
	 */
	private function existingBookingKeys(string $userId): array {
		$keys = [];
		foreach ($this->journalMapper->findManualBookingKeys($userId) as $k) {
			$key = $this->normalizer->softKey($k['date'], (int)$k['amount'], $k['description']);
			if ($key !== null) {
				$keys[$key] = true;
			}
		}
		return $keys;
	}

	/**
	 * Dieselben Schlüssel für bereits importierte Bankbuchungen – der
	 * quellenübergreifende Schutz aus Punkt 3 in {@see splitNewAndDuplicate()}.
	 *
	 * Buchungs- und Valutadatum werden beide eingetragen: welches von beiden ein
	 * Format als "Buchungstag" ausgibt, ist nicht einheitlich.
	 *
	 * @return array<string, true>
	 */
	private function existingBankKeys(string $userId): array {
		$keys = [];
		foreach ($this->txMapper->findDedupKeys($userId) as $k) {
			foreach ([$k['date'], $k['valueDate']] as $date) {
				if (!is_string($date) || $date === '') {
					continue;
				}
				$key = $this->normalizer->softKey($date, $k['amount'], $k['text']);
				if ($key !== null) {
					$keys[$key] = true;
				}
			}
		}
		return $keys;
	}

	/**
	 * Prüft, ob eine gelesene Zeile bereits als Buchung ohne Bankbezug oder als
	 * Bankbuchung existiert (Betrag + normalisierter Text gegen die Schlüssel
	 * aus {@see existingBookingKeys()} und {@see existingBankKeys()}).
	 *
	 * Datumsseitig wird sowohl das Buchungs- ALS AUCH das Valutadatum geprüft:
	 * die Alt-Software (xbuc-Export) hat teils das Valutadatum als Buchungsdatum
	 * gespeichert, sodass ein Abgleich nur über das Buchungsdatum fehlschlüge.
	 * Ohne aussagekräftigen Text findet kein Abgleich statt.
	 *
	 * @param array<string,mixed> $row
	 * @param array<string, true> $bookingKeys
	 */
	private function matchesExistingBooking(array $row, array $bookingKeys): bool {
		$text = (string)($row['counterparty'] ?? '') . (string)($row['purpose'] ?? '');
		$amount = (int)$row['amountCents'];
		foreach ([$row['bookingDate'] ?? null, $row['valueDate'] ?? null] as $date) {
			if (!is_string($date) || $date === '') {
				continue;
			}
			$key = $this->normalizer->softKey($date, $amount, $text);
			if ($key !== null && isset($bookingKeys[$key])) {
				return true;
			}
		}
		return false;
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
