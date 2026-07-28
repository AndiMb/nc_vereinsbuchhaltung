<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\CostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;

/**
 * Importiert eine „zero Buchhaltung"-Datei (.xbuc): Kontenbaum + Buchungen.
 *
 * Merge-Modus (reset=false): Konten werden nur angelegt wenn sie fehlen;
 * Buchungen werden per Fingerprint (Datum|Betrag|Soll|Haben|Beleg) dedupliziert.
 *
 * Der eigentliche Import läuft komplett in einer Transaktion: er legt Konten,
 * Kostenstellen und hunderte Buchungen an, die nur gemeinsam einen stimmigen
 * Bestand ergeben. Insbesondere im reset-Modus wäre ein Abbruch nach dem
 * Löschen fatal – dann wären die Altdaten weg und die neuen nur zur Hälfte da.
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
		private BankTransactionMapper $txMapper,
		private AttachmentStorageService $attachmentStorage,
		private YearCloseService $yearCloseService,
		private DemoDataService $demoService,
		private EntryNumberService $entryNumbers,
		private TransactionRunner $transaction,
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
		$transition = $this->analyzeYearTransition($userId, $data, $year);
		return [
			'accounts' => count($data['accounts']),
			'bookings' => count($data['bookings']),
			// Buchungen ohne Gegenkonto → werden als offene Bankbuchungen übernommen
			'openBankTx' => count(array_filter($data['bookings'], static fn ($b) => !empty($b['openContra']))),
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
			'yearTransition' => $transition === null ? null : [
				'targetYear' => $transition['targetYear'],
				'removalCount' => count($transition['removeJournalIds']),
				'hasMismatch' => $transition['hasMismatch'],
				'comparisons' => $transition['comparisons'],
			],
		];
	}

	/**
	 * Rückwärts-Import (frühere Jahres-Datei als bereits vorhandene): prüft, ob
	 * der Endstand der Datei je Bestandskonto dem gespeicherten Anfangsbestand
	 * des bisher frühesten Jahres entspricht, und ermittelt dessen nun
	 * überflüssige Eröffnungsbuchungen (die sonst den kumulativen Saldo doppelt
	 * zählen würden). Nur relevant, wenn das Datei-Jahr UNTER dem kleinsten
	 * vorhandenen Buchungsjahr liegt.
	 *
	 * @param array<string,mixed> $data Parser-Ergebnis
	 * @return array{targetYear:int, removeJournalIds:int[], comparisons:array<int,array<string,mixed>>, hasMismatch:bool}|null
	 */
	private function analyzeYearTransition(string $userId, array $data, ?int $year): ?array {
		if ($year === null) {
			return null;
		}
		$existingYears = $this->journalMapper->distinctYears($userId);
		if (count($existingYears) === 0) {
			return null;
		}
		$targetYear = min($existingYears);
		if ($year >= $targetYear) {
			// Kein Rückwärts-Import → normale (Vorwärts-)Logik greift.
			return null;
		}

		// Kontostammdaten (Nummer → Typ/Name/Id) aus DB und Datei zusammenführen.
		$accById = [];
		$nameByNumber = [];
		$isBankByNumber = [];
		$equityIds = [];
		foreach ($this->accountMapper->findAll($userId) as $a) {
			$accById[$a->getId()] = $a;
			$nameByNumber[$a->getNumber()] = $a->getName();
			$isBankByNumber[$a->getNumber()] = $a->getIsBank();
			if ($a->getType() === 'equity') {
				$equityIds[] = $a->getId();
			}
		}
		foreach ($data['accounts'] as $a) {
			if (!isset($nameByNumber[$a['number']])) {
				$nameByNumber[$a['number']] = $a['name'];
			}
			// Datei-Wert nur ergänzend; ein vorhandenes DB-Konto ist maßgeblich.
			if (!isset($isBankByNumber[$a['number']])) {
				$isBankByNumber[$a['number']] = !empty($a['isBank']);
			}
		}

		// Endstand der Datei je Kontonummer (nur journalrelevante Buchungen; Soll +, Haben −).
		$closingByNumber = [];
		foreach ($data['bookings'] as $b) {
			if (!empty($b['openContra'])) {
				continue;
			}
			$closingByNumber[$b['sollNumber']]  = ($closingByNumber[$b['sollNumber']]  ?? 0) + $b['amountCents'];
			$closingByNumber[$b['habenNumber']] = ($closingByNumber[$b['habenNumber']] ?? 0) - $b['amountCents'];
		}

		// Gespeicherter Anfangsbestand des Zieljahres: Eröffnungsbuchungen (die ein
		// EK-Konto berühren) in diesem Jahr; je Bestandskonto Soll − Haben.
		$openingJournalIds = $this->journalMapper->findBookingIdsTouchingAccountsInYear($userId, $equityIds, $targetYear);
		$equityIdSet = array_flip($equityIds);
		$storedByNumber = [];
		$linesByJournal = $this->lineMapper->findByJournals($openingJournalIds);
		foreach ($openingJournalIds as $jid) {
			foreach ($linesByJournal[$jid] ?? [] as $line) {
				$aid = $line->getAccountId();
				if (isset($equityIdSet[$aid])) {
					continue; // Eigenkapital-Gegenseite ignorieren
				}
				$acc = $accById[$aid] ?? null;
				if ($acc === null) {
					continue;
				}
				$num = $acc->getNumber();
				$storedByNumber[$num] = ($storedByNumber[$num] ?? 0) + $line->getDebitCents() - $line->getCreditCents();
			}
		}

		// Vergleich ausschließlich über Geldkonten (Bank/Kasse): nur deren Bestand
		// kumuliert über Jahresgrenzen und wird von der Alt-Software am Jahresende
		// als Anfangsbestand fortgeschrieben (siehe Account::isStockAccount()).
		// Alle anderen Konten sind jahresbezogen und haben keinen Anfangsbestand.
		$numbers = array_unique(array_merge(array_keys($storedByNumber), array_keys($closingByNumber)));
		sort($numbers);
		$comparisons = [];
		$hasMismatch = false;
		foreach ($numbers as $num) {
			if (!($isBankByNumber[$num] ?? false)) {
				continue;
			}
			$closing = $closingByNumber[$num] ?? 0;
			$stored = $storedByNumber[$num] ?? 0;
			if ($closing === 0 && $stored === 0) {
				continue;
			}
			$matches = ($closing === $stored);
			if (!$matches) {
				$hasMismatch = true;
			}
			$comparisons[] = [
				'account' => trim($num . ' ' . ($nameByNumber[$num] ?? '')),
				'fileClosing' => $closing / 100,
				'storedOpening' => $stored / 100,
				'matches' => $matches,
			];
		}

		return [
			'targetYear' => $targetYear,
			'removeJournalIds' => $openingJournalIds,
			'comparisons' => $comparisons,
			'hasMismatch' => $hasMismatch,
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
					$entry['action'] = 'skip';
					// Der Abgleich gegen den kumulierten Vorjahresstand ist nur für
					// Geldkonten sinnvoll – alle anderen Konten sind jahresbezogen
					// und haben keinen fortgeschriebenen Anfangsbestand.
					if ($account->isStockAccount()) {
						$priorCents = ($sums[$account->getId()]['debit'] ?? 0) - ($sums[$account->getId()]['credit'] ?? 0);
						$entry['priorBalance'] = $priorCents / 100;
						$entry['matches'] = ($priorCents === $expectedCents);
					}
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
		return $this->transaction->run(fn (): array => $this->doImport($userId, $content, $reset, $clampDates, $yearOverride));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function doImport(string $userId, string $content, bool $reset, bool $clampDates, ?int $yearOverride): array {
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

		// Rückwärts-Import: Jahresübergang prüfen (VOR jeglichem Schreibvorgang).
		// Stimmt der Endstand der Datei nicht mit dem gespeicherten Anfangsbestand
		// des Folgejahres überein, wird der Import blockiert (nichts wird geändert).
		$transition = $reset ? null : $this->analyzeYearTransition($userId, $data, $year);
		if ($transition !== null && $transition['hasMismatch']) {
			throw new \RuntimeException($this->transitionErrorMessage($transition));
		}

		// Festschreibung: der Merge-Import darf kein abgeschlossenes Jahr
		// berühren – geprüft VOR jeglichem Schreibvorgang. Beim reset-Import
		// wird ohnehin alles inkl. der Abschluss-Marker gelöscht (Verwalter-only).
		if (!$reset) {
			$touchedYears = [];
			foreach ($data['bookings'] as $b) {
				$touchedYears[(int)substr((string)$b['date'], 0, 4)] = true;
			}
			if ($transition !== null && !empty($transition['removeJournalIds']) && $year !== null) {
				// Der Jahresübergang entfernt Eröffnungsbuchungen des Folgejahres.
				$touchedYears[$year + 1] = true;
			}
			foreach (array_keys($touchedYears) as $y) {
				$this->yearCloseService->assertOpen(sprintf('%04d-01-01', $y));
			}
		}

		if ($reset) {
			$this->resetService->resetAll($userId);
			$this->demoService->clearFlag();
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

		// --- Offene Bankbuchungen (Buchung ohne Gegenkonto): Hash-Dedup wie CSV ---
		$openHashes = [];
		foreach ($data['bookings'] as $b) {
			if (!empty($b['openContra'])) {
				$openHashes[] = $this->openTxHash($b);
			}
		}
		$existingTxHash = $reset ? [] : array_flip($this->txMapper->findExistingHashes($userId, $openHashes));
		$seenTxHash = [];
		$openBankTx = 0;

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
			// Buchung ohne Gegenkonto → als unzugeordnete Bankbuchung anlegen
			// (erscheint im Tab „Zuzuordnen"), Dedup per stabilem Hash.
			if (!empty($b['openContra'])) {
				$h = $this->openTxHash($b);
				if (isset($existingTxHash[$h]) || isset($seenTxHash[$h])) {
					$skipped++;
					continue;
				}
				$seenTxHash[$h] = true;
				$this->insertOpenBankTx($userId, $b, $h);
				$openBankTx++;
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
				false, // Import protokolliert sich als Ganzes, nicht je Buchung
			);
			$count++;
		}

		// Rückwärts-Import: nun überflüssige Eröffnungsbuchungen des Folgejahres
		// entfernen (der Anfangsbestand kommt jetzt aus der frisch importierten,
		// früheren Jahres-Datei). Der Übergang wurde oben bereits geprüft.
		$openingsRemoved = 0;
		if ($transition !== null) {
			foreach ($transition['removeJournalIds'] as $jid) {
				if ($this->deleteJournalById($userId, $jid)) {
					$openingsRemoved++;
				}
			}
		}

		return [
			'accounts'          => count($byNumber),
			'accountsNew'       => $accountsNew,
			'bookings'          => $count,
			'openBankTx'        => $openBankTx,
			'skipped'           => $skipped,
			'reset'             => $reset,
			'year'              => $year,
			'outsideYear'       => $outsideCount,
			'clamped'           => $clamped,
			'openingsSkipped'   => $openingsSkipped,
			'openingMismatches' => $openingMismatches,
			'openingsRemoved'   => $openingsRemoved,
			'transitionYear'    => $transition['targetYear'] ?? null,
		];
	}

	/**
	 * Baut die (blockierende) Fehlermeldung bei inkonsistentem Jahresübergang.
	 *
	 * @param array{targetYear:int, comparisons:array<int,array<string,mixed>>} $transition
	 */
	private function transitionErrorMessage(array $transition): string {
		$lines = [];
		foreach ($transition['comparisons'] as $c) {
			if (($c['matches'] ?? true) === false) {
				$lines[] = sprintf(
					'%s: Endstand Datei %s € ≠ gespeicherter Anfangsbestand %s €',
					$c['account'],
					number_format((float)$c['fileClosing'], 2, ',', '.'),
					number_format((float)$c['storedOpening'], 2, ',', '.'),
				);
			}
		}
		return 'Import blockiert: Der Jahresübergang zu ' . $transition['targetYear']
			. ' stimmt nicht überein. Bitte die Beträge in den Dateien prüfen.' . "\n" . implode("\n", $lines);
	}

	/**
	 * Löscht einen Buchungssatz samt Zeilen und Belegen. Gibt false zurück, wenn
	 * er (dem Nutzer) nicht gehört/nicht existiert.
	 */
	private function deleteJournalById(string $userId, int $journalId): bool {
		try {
			$journal = $this->journalMapper->find($journalId, $userId);
		} catch (\Throwable) {
			return false;
		}
		$year = $journal->getYear();
		$this->attachmentStorage->deleteForJournal($journalId);
		$this->lineMapper->deleteByJournal($journalId);
		$this->journalMapper->delete($journal);
		// Lücke in der Buchungsnummerierung schließen (siehe EntryNumberService).
		$this->entryNumbers->renumberYear($userId, $year);
		return true;
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

	/**
	 * Vorzeichenbehafteter Betrag einer offenen Buchung aus Sicht des Geldkontos:
	 * present-Seite Soll (Geld fließt zu) → positiv, Haben (Geld fließt ab) → negativ.
	 *
	 * @param array<string,mixed> $b
	 */
	private function openTxAmount(array $b): int {
		$abs = abs((int)$b['amountCents']);
		return $b['presentSide'] === 'soll' ? $abs : -$abs;
	}

	/**
	 * Stabiler Dedup-Hash für offene Bankbuchungen, damit ein erneuter xbuc-Import
	 * dieselbe Buchung nicht ein zweites Mal anlegt.
	 *
	 * @param array<string,mixed> $b
	 */
	private function openTxHash(array $b): string {
		$norm = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string)$b['text'])) ?? '';
		return hash('sha256', 'xbuc-open|' . $b['date'] . '|' . $this->openTxAmount($b) . '|' . $norm . '|' . $b['docRef']);
	}

	/**
	 * Legt eine offene Buchung als unzugeordnete Bankbuchung an. Der Buchungstext
	 * folgt i.d.R. dem Muster "Empfänger: Verwendungszweck" (aus der Alt-Software)
	 * und wird am ersten ": " in Empfänger + Zweck aufgeteilt.
	 *
	 * @param array<string,mixed> $b
	 */
	private function insertOpenBankTx(string $userId, array $b, string $hash): void {
		$text = (string)$b['text'];
		$counterparty = null;
		$purpose = $text;
		$pos = mb_strpos($text, ': ');
		if ($pos !== false) {
			$counterparty = trim(mb_substr($text, 0, $pos));
			$purpose = trim(mb_substr($text, $pos + 2));
		}

		$tx = new BankTransaction();
		$tx->setUserId($userId);
		$tx->setImportId(null);
		$tx->setBookingDate($b['date']);
		$tx->setValueDate(null);
		$tx->setAmountCents($this->openTxAmount($b));
		$tx->setCurrency('EUR');
		$tx->setBookingText(null);
		$tx->setPurpose($purpose !== '' ? mb_substr($purpose, 0, 255) : null);
		$tx->setCounterparty(($counterparty !== null && $counterparty !== '') ? mb_substr($counterparty, 0, 255) : null);
		$tx->setCounterpartyIban(null);
		$tx->setCounterpartyBic(null);
		$tx->setOwnAccount(null);
		$tx->setHash($hash);
		$tx->setStatus('unassigned');
		$tx->setContraAccountId(null);
		$tx->setJournalId(null);
		$this->txMapper->insert($tx);
	}
}
