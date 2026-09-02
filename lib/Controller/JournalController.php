<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Exception\ConflictException;
use OCA\Vereinsbuchhaltung\Service\JournalService;
use OCA\Vereinsbuchhaltung\Service\LedgerAggregator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

class JournalController extends Controller {

	use BookContext;

	public function __construct(
		IRequest $request,
		private JournalMapper $journalMapper,
		private JournalLineMapper $lineMapper,
		private AccountMapper $accountMapper,
		private BankTransactionMapper $txMapper,
		private BudgetMapper $budgetMapper,
		private JournalService $journalService,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Geschäftsjahre mit Buchungen oder Planwerten (+ laufendes Jahr). */
	#[NoAdminRequired]
	public function years(): DataResponse {
		$userId = $this->userId();
		$all = array_merge(
			$this->journalMapper->distinctYears($userId),
			$this->budgetMapper->distinctYears($userId),
			[(int)date('Y')],
		);
		$all = array_values(array_unique($all));
		rsort($all);
		return new DataResponse($all);
	}

	#[NoAdminRequired]
	public function index(int $limit = 10000, int $offset = 0, ?int $year = null): DataResponse {
		$userId = $this->userId();
		[$from, $to] = $this->yearRange($year);
		$journals = $this->journalMapper->findAll($userId, $limit, $offset, $from, $to);
		// Zeilen aller Buchungen in einem Rutsch statt einer Abfrage je Buchung –
		// bei mehreren tausend Buchungen ist das der Unterschied zwischen zwei
		// und zweitausend Datenbankabfragen pro Aufruf.
		$linesByJournal = $this->lineMapper->findByJournals(array_map(
			static fn ($journal): int => $journal->getId(),
			$journals,
		));
		$out = [];
		foreach ($journals as $journal) {
			$out[] = [
				'journal' => $journal,
				'lines' => $linesByJournal[$journal->getId()] ?? [],
			];
		}
		return new DataResponse($out);
	}

	/**
	 * Saldenliste je Konto, gruppierbar nach Kategorie.
	 */
	#[NoAdminRequired]
	public function balances(?int $year = null): DataResponse {
		$userId = $this->userId();
		[$from, $to] = $this->yearRange($year);
		$accounts = $this->accountMapper->findAll($userId);

		// Bewegungssummen (Erfolgskonten = Jahr; ohne Jahr = alles).
		$moveSums = $this->lineMapper->sumByAccount($userId, $from, $to);
		// Bestandssummen (Bestandskonten = kumulativ bis Jahresende = Kontostand).
		$balSums = $from !== null ? $this->lineMapper->sumByAccount($userId, null, $to) : $moveSums;

		$rows = [];
		foreach ($accounts as $account) {
			// Spalten Soll/Haben zeigen immer die Bewegung des Zeitraums; der
			// Saldo dagegen bei Geldkonten den Kontostand (siehe
			// LedgerAggregator::listBalance()).
			$movement = LedgerAggregator::movement($account, $moveSums);
			$rows[] = [
				'accountId' => $account->getId(),
				'number' => $account->getNumber(),
				'name' => $account->getName(),
				'type' => $account->getType(),
				'category' => $account->getCategory(),
				'debit' => $movement['debit'] / 100,
				'credit' => $movement['credit'] / 100,
				'balance' => LedgerAggregator::listBalance($account, $moveSums, $balSums) / 100,
			];
		}

		// Ergebnis aus den Bewegungen des Zeitraums. Damit gilt: Änderung des
		// Vermögens (Bank/Kasse) = Ergebnis.
		$result = LedgerAggregator::incomeExpense($accounts, $moveSums);

		// --- Bank-Abstimmung ---------------------------------------------
		// Kontostand = kumulativer Saldo (inkl. Eröffnung) bis Jahresende.
		// "Offen" = Summe noch nicht zugeordneter CSV-Bankumsätze (nur Standard-Bankkonto).
		$openUnassigned = $this->txMapper->sumAmount($userId, 'unassigned');
		$defaultBankId = null;
		foreach ($accounts as $account) {
			if ($account->getIsBank()) {
				$defaultBankId = $account->getId();
				break;
			}
		}

		$bankReconciliation = [];
		foreach ($accounts as $account) {
			if (!$account->getIsBank()) {
				continue;
			}
			$id = $account->getId();
			$open = ($id === $defaultBankId) ? $openUnassigned : 0;
			$bankReconciliation[] = [
				'accountId' => $id,
				'number' => $account->getNumber(),
				'name' => $account->getName(),
				'balance' => LedgerAggregator::stock($account, $balSums) / 100,
				'open' => $open / 100,
				'countInTotal' => $account->countsInCashTotal(),
			];
		}

		// Geldbestand für die Kopfzeile: eine Zahl über alle dafür angehakten
		// Geldkonten. Sie stand dort bis 0.30.0 nur für das erste Geldkonto
		// nach Kontonummer – bei Kasse (1000) und Bankkonto (1200) also
		// ausgerechnet für die Barkasse (Issue #31). `open` ist hier die
		// gesamte noch nicht zugeordnete Summe, nicht der Anteil eines
		// einzelnen Kontos: zugeordnet wird sie ohnehin über alle Konten
		// hinweg.
		$cash = LedgerAggregator::cashTotal($accounts, $balSums);

		return new DataResponse([
			'year' => $year,
			'accounts' => $rows,
			'totals' => [
				'income' => $result['incomeCents'] / 100,
				'expense' => $result['expenseCents'] / 100,
				'result' => $result['resultCents'] / 100,
			],
			'bankReconciliation' => $bankReconciliation,
			'bankTotal' => [
				'balance' => $cash['cents'] / 100,
				'count' => $cash['count'],
				// Summe über alle Geldkonten – die Zeile unter der
				// Geldkonten-Tabelle. Weicht sie vom Geldbestand ab, ist
				// mindestens ein Konto abgewählt.
				'allBalance' => $cash['allCents'] / 100,
				'allCount' => $cash['allCount'],
				'open' => $openUnassigned / 100,
			],
		]);
	}

	/**
	 * Prüft die gemeinsamen Eingaben von {@see create()} und {@see update()}.
	 *
	 * Wichtig sind vor allem Datum und Kontoexistenz: ohne diese Prüfung landen
	 * Buchungszeilen mit einer account_id, zu der es kein Konto gibt (die Zeile
	 * taucht dann in keiner Auswertung mehr auf), bzw. Buchungen mit einem
	 * Datum, aus dem sich kein Geschäftsjahr ableiten lässt – dann greift auch
	 * die Festschreibungsprüfung nicht mehr.
	 *
	 * @return string|null Fehlermeldung oder null, wenn alles in Ordnung ist
	 */
	private function validateBooking(string $date, int $cents, int $debitAccountId, int $creditAccountId): ?string {
		$error = $this->validateDate($date);
		if ($error !== null) {
			return $error;
		}
		if ($cents <= 0) {
			return $this->l10n->t('Betrag muss größer als 0 sein');
		}
		if ($debitAccountId === $creditAccountId) {
			return $this->l10n->t('Soll- und Habenkonto müssen unterschiedlich sein');
		}
		$userId = $this->userId();
		foreach ([$this->l10n->t('Sollkonto') => $debitAccountId, $this->l10n->t('Habenkonto') => $creditAccountId] as $label => $accountId) {
			try {
				$this->accountMapper->find($accountId, $userId);
			} catch (DoesNotExistException) {
				return $this->l10n->t('%s nicht gefunden.', [$label]);
			}
		}
		return null;
	}

	/** @return string|null Fehlermeldung oder null */
	private function validateDate(string $date): ?string {
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			return $this->l10n->t('Ungültiges Datum (erwartet wird JJJJ-MM-TT).');
		}
		[$y, $m, $d] = array_map('intval', explode('-', $date));
		if (!checkdate($m, $d, $y)) {
			return $this->l10n->t('Dieses Datum gibt es nicht.');
		}
		if ($y < 2000 || $y > 2099) {
			return $this->l10n->t('Das Buchungsdatum muss zwischen 2000 und 2099 liegen.');
		}
		return null;
	}

	/**
	 * Wandelt die Zeilen einer Splittbuchung aus der Anfrage in die interne
	 * Form um und prüft sie – das Gegenstück zu {@see validateBooking()} für
	 * den mehrzeiligen Fall.
	 *
	 * Die Kontoexistenz wird hier genauso geprüft wie dort und aus demselben
	 * Grund: eine Zeile mit unbekannter account_id taucht in keiner Auswertung
	 * mehr auf.
	 *
	 * @param array $raw Zeilen aus der Anfrage: [{accountId, debit, credit}, …], Beträge in Euro
	 * @param array<int, array{accountId:int, debitCents:int, creditCents:int}> $lines
	 *                                                                                 Ausgabe: die geprüften Zeilen in Cent (bei einem Fehler die bis
	 *                                                                                 dahin umgewandelten – der Aufrufer wertet sie dann nicht aus)
	 * @return string|null Fehlermeldung oder null, wenn alles in Ordnung ist
	 */
	private function parseLines(array $raw, array &$lines): ?string {
		$lines = [];
		foreach ($raw as $entry) {
			if (!is_array($entry)) {
				return $this->l10n->t('Ungültige Buchungszeile.');
			}
			// Erst je Zeile auf Cent runden, dann summieren lassen: prüfte man
			// die Summen in Euro, gingen sie bei krummen Beträgen (z.B. 33,33 +
			// 33,33 + 33,34) um einen Cent auseinander.
			$lines[] = [
				'accountId' => (int)($entry['accountId'] ?? 0),
				'debitCents' => (int)round(((float)($entry['debit'] ?? 0)) * 100),
				'creditCents' => (int)round(((float)($entry['credit'] ?? 0)) * 100),
			];
		}
		$error = JournalService::validateLines($lines);
		if ($error !== null) {
			return $this->translateLineError($error);
		}
		$userId = $this->userId();
		foreach ($lines as $line) {
			try {
				$this->accountMapper->find($line['accountId'], $userId);
			} catch (DoesNotExistException) {
				return $this->l10n->t('Ein Konto der Buchung wurde nicht gefunden.');
			}
		}
		return null;
	}

	/**
	 * Übersetzt einen Fehlercode aus {@see JournalService::validateLines()}
	 * in eine Nutzermeldung (Duplikat der gleichnamigen privaten Methode in
	 * JournalService - der Code ist dort ebenfalls static/testgebunden und
	 * kann nicht öffentlich gemacht werden, ohne die Kapselung aufzugeben).
	 *
	 * @param array{code:string, params?:array<string,int>} $error
	 */
	private function translateLineError(array $error): string {
		$params = $error['params'] ?? [];
		return match ($error['code']) {
			'too_few_lines' => $this->l10n->t('Eine Buchung braucht mindestens zwei Zeilen (Soll und Haben).'),
			'too_many_lines' => $this->l10n->t('Eine Buchung darf höchstens %d Zeilen haben.', [$params['max']]),
			'negative_amount' => $this->l10n->t('Beträge müssen positiv sein.'),
			'both_sides' => $this->l10n->t('Eine Buchungszeile steht entweder im Soll oder im Haben, nicht in beidem.'),
			'zero_amount' => $this->l10n->t('Jede Buchungszeile braucht einen Betrag größer als 0.'),
			'missing_account' => $this->l10n->t('Jede Buchungszeile braucht ein Konto.'),
			'duplicate_account' => $this->l10n->t('Jedes Konto darf in einer Buchung nur einmal vorkommen.'),
			'unbalanced' => $this->l10n->t(
				'Soll und Haben sind nicht ausgeglichen (%s € gegen %s €).',
				[
					number_format($params['debit'] / 100, 2, ',', '.'),
					number_format($params['credit'] / 100, 2, ',', '.'),
				],
			),
			'zero_total' => $this->l10n->t('Betrag muss größer als 0 sein'),
			default => $error['code'],
		};
	}

	/**
	 * Buchungssatz anlegen – "Soll an Haben" oder, mit $lines, als
	 * Splittbuchung über mehrere Konten.
	 *
	 * @param array $lines Zeilen einer Splittbuchung: [{accountId, debit, credit}, …]
	 *                     mit Beträgen in Euro. Ist der Parameter gesetzt, beschreiben die
	 *                     Zeilen die Buchung vollständig und $debitAccountId/$creditAccountId/
	 *                     $amount werden nicht ausgewertet.
	 */
	#[NoAdminRequired]
	public function create(string $date, string $description, int $debitAccountId = 0, int $creditAccountId = 0, float $amount = 0, ?string $documentRef = null, array $lines = []): DataResponse {
		[$error, $bookingLines] = $this->prepareLines($date, $debitAccountId, $creditAccountId, $amount, $lines);
		if ($error !== null) {
			return new DataResponse(['message' => $error], Http::STATUS_BAD_REQUEST);
		}
		$journal = $this->journalService->createBookingLines($this->userId(), $date, $description, $documentRef, $bookingLines);
		return new DataResponse($journal, Http::STATUS_CREATED);
	}

	/**
	 * @param array $lines siehe {@see create()}; aus einer zweizeiligen Buchung
	 *                     kann dabei eine Splittbuchung werden und umgekehrt.
	 */
	#[NoAdminRequired]
	public function update(int $id, string $date, string $description, int $debitAccountId = 0, int $creditAccountId = 0, float $amount = 0, ?string $documentRef = null, ?string $updatedAt = null, array $lines = []): DataResponse {
		[$error, $bookingLines] = $this->prepareLines($date, $debitAccountId, $creditAccountId, $amount, $lines);
		if ($error !== null) {
			return new DataResponse(['message' => $error], Http::STATUS_BAD_REQUEST);
		}
		try {
			$journal = $this->journalService->updateBookingLines($id, $this->userId(), $date, $description, $documentRef, $bookingLines, $updatedAt);
			return new DataResponse($journal);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Buchung nicht gefunden')], Http::STATUS_NOT_FOUND);
		} catch (ConflictException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}

	/**
	 * Gemeinsame Eingangsprüfung von {@see create()} und {@see update()}: der
	 * zweizeilige und der mehrzeilige Fall münden beide in eine geprüfte
	 * Zeilenliste.
	 *
	 * @return array{0: ?string, 1: array<int, array{accountId:int, debitCents:int, creditCents:int}>}
	 *                                                                                                 Fehlermeldung (oder null) und die Zeilen
	 */
	private function prepareLines(string $date, int $debitAccountId, int $creditAccountId, float $amount, array $rawLines): array {
		if ($rawLines !== []) {
			// Vorbelegt, weil parseLines() bei einem Datumsfehler gar nicht
			// erst aufgerufen wird (?? kürzt ab) und $lines dann ungesetzt bliebe.
			$lines = [];
			$error = $this->validateDate($date) ?? $this->parseLines($rawLines, $lines);
			return [$error, $lines];
		}
		$cents = (int)round($amount * 100);
		$error = $this->validateBooking($date, $cents, $debitAccountId, $creditAccountId);
		return [$error, JournalService::simpleLines($debitAccountId, $creditAccountId, $cents)];
	}

	/**
	 * Eine Seite einer Buchung auf ein anderes Konto umbuchen.
	 *
	 * Der Weg dorthin ist der Kontoauszug (Tab Konten): eine dort als falsch
	 * zugeordnet erkannte Buchung lässt sich an Ort und Stelle korrigieren,
	 * statt sie im Journal zu suchen und komplett neu zu erfassen.
	 */
	#[NoAdminRequired]
	public function reassign(int $id, int $fromAccountId, int $toAccountId, ?string $updatedAt = null): DataResponse {
		try {
			$journal = $this->journalService->reassignLine($id, $this->userId(), $fromAccountId, $toAccountId, $updatedAt);
			return new DataResponse($journal);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Buchung oder Konto nicht gefunden')], Http::STATUS_NOT_FOUND);
		} catch (ConflictException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		try {
			$this->journalService->deleteBooking($id, $this->userId());
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Buchung nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Kontoauszug eines Kontos – optional inklusive aller Unterkonten.
	 */
	#[NoAdminRequired]
	public function byAccount(int $id, int $includeChildren = 1, ?int $year = null): DataResponse {
		$userId = $this->userId();
		[$from, $to] = $this->yearRange($year);
		$accounts = $this->accountMapper->findAll($userId);
		$labels = [];
		$childrenByParent = [];
		foreach ($accounts as $a) {
			$labels[$a->getId()] = $a->getNumber() . ' ' . $a->getName();
			$pid = $a->getParentId();
			if ($pid !== null) {
				$childrenByParent[$pid][] = $a->getId();
			}
		}

		$ids = [$id];
		if ($includeChildren) {
			$ids = $this->collectDescendants($id, $childrenByParent);
		}
		$idSet = array_flip($ids);

		// Saldovortrag je Zeile anhand des Zeilen-Kontos: nur Geldkonten tragen
		// einen Bestand über die Jahresgrenze. So verfälscht bei "inkl. Unterkonten"
		// weder ein jahresbezogenes Unterkonto den Vortrag des Überkontos, noch
		// verliert ein Geldkonto seinen Vortrag unter einem jahresbezogenen Parent.
		$stockIdSet = [];
		foreach ($accounts as $a) {
			if ($a->isStockAccount()) {
				$stockIdSet[$a->getId()] = true;
			}
		}

		$journalIds = $this->lineMapper->findJournalIdsForAccounts($userId, $ids);
		// Buchungsköpfe und -zeilen gebündelt laden statt je Buchung einzeln.
		$journalsById = $this->journalMapper->findByIds($userId, $journalIds);
		$linesByJournal = $this->lineMapper->findByJournals($journalIds);

		$rows = [];
		$sumDebit = 0;
		$sumCredit = 0;
		// Saldovortrag: Bewegung auf diesen Konten vor Beginn des Geschäftsjahres.
		$carryDebit = 0;
		$carryCredit = 0;
		foreach ($journalIds as $jid) {
			$journal = $journalsById[$jid] ?? null;
			if ($journal === null) {
				continue;
			}
			$date = (string)$journal->getDate();
			$beforePeriod = $from !== null && $date < $from;
			$afterPeriod = $to !== null && $date > $to;
			if ($afterPeriod) {
				continue;
			}
			$lines = $linesByJournal[$jid] ?? [];
			$contra = [];
			// Die Gegenkonten einzeln (nicht nur als Text): der Kontoauszug
			// bietet das Umbuchen für beide Seiten an, siehe reassign().
			$contraAccounts = [];
			foreach ($lines as $line) {
				if (!isset($idSet[$line->getAccountId()])) {
					$label = $labels[$line->getAccountId()] ?? ('#' . $line->getAccountId());
					$contra[] = $label;
					$contraAccounts[] = ['accountId' => $line->getAccountId(), 'label' => $label];
				}
			}
			foreach ($lines as $line) {
				if (!isset($idSet[$line->getAccountId()])) {
					continue;
				}
				if ($beforePeriod) {
					if (isset($stockIdSet[$line->getAccountId()])) {
						$carryDebit += $line->getDebitCents();
						$carryCredit += $line->getCreditCents();
					}
					continue;
				}
				$sumDebit += $line->getDebitCents();
				$sumCredit += $line->getCreditCents();
				$rows[] = [
					'journalId' => $jid,
					'entryNo' => $journal->getEntryNo(),
					'date' => $journal->getDate(),
					'description' => $journal->getDescription(),
					'documentRef' => $journal->getDocumentRef(),
					'accountId' => $line->getAccountId(),
					'account' => $labels[$line->getAccountId()] ?? ('#' . $line->getAccountId()),
					'contra' => implode(', ', $contra),
					'contraAccounts' => $contraAccounts,
					// Für das optimistische Locking beim Umbuchen (siehe reassign()).
					'updatedAt' => $journal->getUpdatedAt(),
					'debit' => $line->getDebitCents() / 100,
					'credit' => $line->getCreditCents() / 100,
				];
			}
		}

		usort($rows, static function ($a, $b) {
			return [$a['date'], $a['entryNo']] <=> [$b['date'], $b['entryNo']];
		});

		$account = $this->accountMapper->find($id, $userId);

		// Saldovortrag nur bei aktivem Jahresfilter; beigetragen haben oben ohnehin
		// nur Zeilen von Geldkonten (debit-Natur), daher Soll − Haben.
		$carryCents = 0;
		if ($from !== null) {
			$carryCents = $carryDebit - $carryCredit;
		}
		$periodNet = $account->isCreditNature() ? ($sumCredit - $sumDebit) : ($sumDebit - $sumCredit);
		$balanceCents = $carryCents + $periodNet;

		return new DataResponse([
			'account' => $account,
			'includeChildren' => (bool)$includeChildren,
			'year' => $year,
			'carry' => $carryCents / 100,
			'rows' => $rows,
			'totals' => [
				'debit' => $sumDebit / 100,
				'credit' => $sumCredit / 100,
				'balance' => $balanceCents / 100,
				'count' => count($rows),
			],
		]);
	}

	/**
	 * @param array<int, int[]> $childrenByParent
	 * @return int[] das Konto selbst plus alle Nachfahren
	 */
	private function collectDescendants(int $id, array $childrenByParent): array {
		$result = [$id];
		$stack = [$id];
		while ($stack) {
			$current = array_pop($stack);
			foreach ($childrenByParent[$current] ?? [] as $child) {
				$result[] = $child;
				$stack[] = $child;
			}
		}
		return array_values(array_unique($result));
	}
}
