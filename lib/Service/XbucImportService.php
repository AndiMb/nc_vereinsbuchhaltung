<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\CostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;

/**
 * Importiert eine „zero Buchhaltung"-Datei (.xbuc): Kontenbaum + Buchungen.
 *
 * Merge-Modus (reset=false): Konten werden nur angelegt wenn sie fehlen;
 * Buchungen werden per Fingerprint (Datum|Betrag|Soll|Haben|Beleg) dedupliziert.
 */
class XbucImportService {

	/** Feste Kostenstellen, die immer vorhanden sein sollen. */
	private const BUILTIN_COST_CENTERS = ['01' => 'Ideeller Bereich', '11' => 'Verbandszeitung'];

	public function __construct(
		private XbucParser $parser,
		private AccountMapper $accountMapper,
		private CostCenterMapper $costCenterMapper,
		private JournalMapper $journalMapper,
		private JournalLineMapper $lineMapper,
		private JournalService $journalService,
		private ResetService $resetService,
	) {
	}

	/**
	 * Analysiert die Datei ohne zu speichern.
	 *
	 * @param int|null $yearOverride manuell gewähltes Geschäftsjahr (hat Vorrang
	 *                               vor dem in der Datei hinterlegten Jahr)
	 * @return array{accounts:int, bookings:int, year:?int, fileYear:?int, outsideYear:int, outsideSamples:array<int,array<string,mixed>>, openings:array<int,array<string,mixed>>}
	 */
	public function preview(string $userId, string $content, ?int $yearOverride = null): array {
		$data = $this->parser->parse($content);
		$year = $yearOverride ?? $data['year'];
		$outside = $this->findOutsideYear($data['bookings'], $year);
		$openings = $this->analyzeOpenings($userId, $data['bookings'], $year);
		return [
			'accounts' => count($data['accounts']),
			'bookings' => count($data['bookings']),
			'year' => $year,
			'fileYear' => $data['year'],
			'outsideYear' => count($outside),
			'outsideSamples' => array_map(static fn ($b) => [
				'date' => $b['date'],
				'text' => mb_substr($b['text'], 0, 60),
				'amount' => $b['amountCents'] / 100,
				'docRef' => $b['docRef'],
			], array_slice($outside, 0, 5)),
			'openings' => array_map(static function ($o) {
				unset($o['index']);
				return $o;
			}, $openings),
		];
	}

	/**
	 * Analysiert Eröffnungsbuchungen (Buchungen gegen ein Eigenkapital-/EB-Konto).
	 *
	 * Bestandskonten werden in der App KUMULATIV über alle Jahre berechnet.
	 * Existieren bereits Buchungen aus Vorjahren, würde der Anfangsbestand einer
	 * weiteren Jahres-Datei doppelt zählen – solche Eröffnungsbuchungen werden
	 * übersprungen und stattdessen gegen den Vorjahres-Endstand abgeglichen.
	 *
	 * @param array<int, array<string,mixed>> $bookings
	 * @return array<int, array<string,mixed>> je Eröffnungsbuchung:
	 *         index, account, date, amount (EUR, erwarteter Anfangsbestand),
	 *         action ('import'|'skip'), priorBalance (EUR|null), matches (bool|null)
	 */
	private function analyzeOpenings(string $userId, array $bookings, ?int $year): array {
		$result = [];
		$priorSumsByTo = [];
		foreach ($bookings as $idx => $b) {
			if (($b['equitySide'] ?? null) === null) {
				continue;
			}
			// Konto = die Nicht-EB-Seite; Vorzeichen des Anfangsbestands je nach Seite
			$accountOnDebit = $b['equitySide'] === 'haben';
			$number = $accountOnDebit ? $b['sollNumber'] : $b['habenNumber'];
			$name = $accountOnDebit ? $b['sollName'] : $b['habenName'];
			$expectedCents = $accountOnDebit ? $b['amountCents'] : -$b['amountCents'];

			$bookingYear = $year ?? (int)substr((string)$b['date'], 0, 4);
			$priorTo = sprintf('%04d-12-31', $bookingYear - 1);

			$entry = [
				'index' => $idx,
				'account' => trim($number . ' ' . $name),
				'date' => $b['date'],
				'amount' => $expectedCents / 100,
				'action' => 'import',
				'priorBalance' => null,
				'matches' => null,
			];

			$account = $this->accountMapper->findByNumber($userId, $number);
			if ($account !== null) {
				if (!isset($priorSumsByTo[$priorTo])) {
					$priorSumsByTo[$priorTo] = $this->lineMapper->sumByAccount($userId, null, $priorTo);
				}
				$sums = $priorSumsByTo[$priorTo];
				if (isset($sums[$account->getId()])) {
					// Vorjahresbuchungen vorhanden → Anfangsbestand nicht erneut buchen
					$priorCents = ($sums[$account->getId()]['debit'] ?? 0) - ($sums[$account->getId()]['credit'] ?? 0);
					$entry['action'] = 'skip';
					$entry['priorBalance'] = $priorCents / 100;
					$entry['matches'] = ($priorCents === $expectedCents);
				}
			}
			$result[] = $entry;
		}
		return $result;
	}

	/**
	 * Buchungen, deren Datum außerhalb des Geschäftsjahres der Datei liegt.
	 *
	 * @param array<int, array<string,mixed>> $bookings
	 * @return array<int, array<string,mixed>>
	 */
	private function findOutsideYear(array $bookings, ?int $year): array {
		if ($year === null) {
			return [];
		}
		$from = sprintf('%04d-01-01', $year);
		$to = sprintf('%04d-12-31', $year);
		return array_values(array_filter($bookings, static fn ($b) => $b['date'] < $from || $b['date'] > $to));
	}

	/**
	 * Importiert Konten und Buchungen.
	 *
	 * Im Merge-Modus (reset=false):
	 * – Konten werden nur angelegt, wenn die Nummer noch nicht existiert.
	 * – Buchungen werden per Fingerprint dedupliziert (Datum|Betrag|Soll-ID|Haben-ID|Belegnummer).
	 *
	 * @param bool $clampDates Buchungen außerhalb des Geschäftsjahres
	 *                         auf den 01.01. bzw. 31.12. dieses Jahres datieren
	 * @param int|null $yearOverride manuell gewähltes Geschäftsjahr (hat Vorrang
	 *                               vor dem in der Datei hinterlegten Jahr)
	 * @return array{accounts:int, accountsNew:int, bookings:int, skipped:int, reset:bool, year:?int, outsideYear:int, clamped:int, openingsSkipped:int, openingMismatches:array<int,array<string,mixed>>}
	 */
	public function import(string $userId, string $content, bool $reset = true, bool $clampDates = false, ?int $yearOverride = null): array {
		$data = $this->parser->parse($content);

		// Buchungen außerhalb des Geschäftsjahres erkennen und optional
		// auf die Jahresgrenzen datieren, damit sie in der App nicht in
		// einem anderen Jahr landen als in der xbuc-Datei.
		$year = $yearOverride ?? $data['year'];
		$outsideCount = count($this->findOutsideYear($data['bookings'], $year));
		$clamped = 0;
		if ($clampDates && $year !== null && $outsideCount > 0) {
			$from = sprintf('%04d-01-01', $year);
			$to = sprintf('%04d-12-31', $year);
			foreach ($data['bookings'] as &$booking) {
				if ($booking['date'] < $from) {
					$booking['date'] = $from;
					$clamped++;
				} elseif ($booking['date'] > $to) {
					$booking['date'] = $to;
					$clamped++;
				}
			}
			unset($booking);
		}

		// Eröffnungsbuchungen: im Merge-Modus überspringen, wenn das Konto
		// bereits Vorjahresbuchungen hat (der kumulative Saldo deckt den
		// Anfangsbestand dann schon ab); Abweichungen werden gemeldet.
		$openingSkipIdx = [];
		$openingMismatches = [];
		if (!$reset) {
			foreach ($this->analyzeOpenings($userId, $data['bookings'], $year) as $o) {
				if ($o['action'] === 'skip') {
					$openingSkipIdx[$o['index']] = true;
					if ($o['matches'] === false) {
						$openingMismatches[] = [
							'account' => $o['account'],
							'fileAmount' => $o['amount'],
							'priorBalance' => $o['priorBalance'],
						];
					}
				}
			}
		}

		if ($reset) {
			$this->resetService->resetAll($userId);
		}

		// --- Kostenstellen (feste + aus Klassifizierung) ---
		foreach (self::BUILTIN_COST_CENTERS as $code => $name) {
			$this->costCenterMapper->upsert($userId, (string)$code, $name);
		}
		foreach ($data['costCenters'] as $cc) {
			$this->costCenterMapper->upsert($userId, $cc['code'], $cc['name']);
		}

		// --- Konten (Eltern zuerst – Reihenfolge des Parsers) ---
		$byNumber = [];
		$accountsNew = 0;
		foreach ($data['accounts'] as $a) {
			$number = $a['number'];
			$existing = $this->accountMapper->findByNumber($userId, $number);
			if ($existing !== null) {
				$byNumber[$number] = $existing->getId();
				continue;
			}
			$account = new Account();
			$account->setUserId($userId);
			$account->setNumber($number);
			$account->setName($a['name']);
			$account->setType($a['type']);
			$account->setCategory($a['category']);
			$account->setIsBank((bool)$a['isBank']);
			$account->setActive(true);
			$parentNumber = $a['parentNumber'];
			if ($parentNumber !== null && isset($byNumber[$parentNumber])) {
				$account->setParentId($byNumber[$parentNumber]);
			}
			$account = $this->accountMapper->insert($account);
			$byNumber[$number] = $account->getId();
			$accountsNew++;
		}

		// --- Fingerprints vorhandener Buchungen (nur im Merge-Modus) ---
		$seen = $reset ? [] : $this->journalMapper->findFingerprintsForUser($userId);

		// Buchungsnummern je Kalenderjahr (starten bei 1; im Merge-Modus ab MAX+1 des jeweiligen Jahres)
		/** @var array<int,int> $nextEntryByYear  Jahr → nächste freie Nummer */
		$nextEntryByYear = [];

		// --- Buchungen ---
		$count = 0;
		$skipped = 0;
		$openingsSkipped = 0;
		foreach ($data['bookings'] as $idx => $b) {
			if (isset($openingSkipIdx[$idx])) {
				$openingsSkipped++;
				continue;
			}
			$debitId  = $this->ensureAccount($userId, $b['sollNumber'],  $b['sollName'],  $byNumber);
			$creditId = $this->ensureAccount($userId, $b['habenNumber'], $b['habenName'], $byNumber);

			$fp = $b['date'] . '|' . abs($b['amountCents']) . '|' . $debitId . '|' . $creditId . '|' . $b['docRef'];
			if (isset($seen[$fp])) {
				$skipped++;
				continue;
			}
			$seen[$fp] = true;

			$year = (int)substr((string)$b['date'], 0, 4);
			if (!isset($nextEntryByYear[$year])) {
				$nextEntryByYear[$year] = $reset ? 1 : $this->journalMapper->getNextEntryNoForYear($userId, $year);
			} else {
				$nextEntryByYear[$year]++;
			}

			$this->journalService->createBooking(
				$userId,
				$b['date'],
				$b['text'] !== '' ? $b['text'] : 'Buchung',
				$b['docRef'] !== '' ? $b['docRef'] : null,
				$debitId,
				$creditId,
				$b['amountCents'],
				$nextEntryByYear[$year],
			);
			$count++;
		}

		return [
			'accounts'          => count($byNumber),
			'accountsNew'       => $accountsNew,
			'bookings'          => $count,
			'skipped'           => $skipped,
			'reset'             => $reset,
			'year'              => $year,
			'outsideYear'       => $outsideCount,
			'clamped'           => $clamped,
			'openingsSkipped'   => $openingsSkipped,
			'openingMismatches' => $openingMismatches,
		];
	}

	/**
	 * Stellt sicher, dass ein Konto mit der Nummer existiert (legt es sonst an).
	 *
	 * @param array<string,int> $byNumber
	 */
	private function ensureAccount(string $userId, string $number, string $name, array &$byNumber): int {
		if (isset($byNumber[$number])) {
			return $byNumber[$number];
		}
		$account = new Account();
		$account->setUserId($userId);
		$account->setNumber(mb_substr($number, 0, 20));
		$account->setName(mb_substr($name !== '' ? $name : ('Konto ' . $number), 0, 255));
		$account->setType($this->guessType($number));
		$account->setCategory('Importiert');
		$account->setIsBank(in_array($number, ['001', '002'], true));
		$account->setActive(true);
		$account = $this->accountMapper->insert($account);
		$byNumber[$number] = $account->getId();
		return $account->getId();
	}

	private function guessType(string $number): string {
		$first = $number[0] ?? '';
		if ($number === '000') {
			return 'equity';
		}
		if ($first === '0' || $number === '999') {
			return 'asset';
		}
		if (in_array($first, ['1', '2', '3'], true)) {
			return 'income';
		}
		return 'expense';
	}
}
