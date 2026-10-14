<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Kontoauszug (Kontenblatt) eines Kontos – optional inklusive aller
 * Unterkonten.
 *
 * Lag bis 0.36 im JournalController; seit es das Kontenblatt auch als CSV und
 * Druckansicht gibt, rechnen Bildschirm und Export hier an einer Stelle. Den
 * laufenden Saldo je Zeile liefert der Server deshalb gleich mit, statt dass
 * die Oberfläche ihn nachrechnet.
 */
class AccountStatementService {

	public function __construct(
		private JournalMapper $journalMapper,
		private JournalLineMapper $lineMapper,
		private AccountMapper $accountMapper,
		private PeriodService $periods,
	) {
	}

	/**
	 * @return array{account: Account, includeChildren: bool, periodId: ?int, carryDate: ?string, carry: float|int, rows: list<array<string, mixed>>, totals: array{debit: float|int, credit: float|int, balance: float|int, count: int}}
	 * @throws DoesNotExistException wenn das Konto nicht existiert
	 */
	public function build(string $userId, int $id, bool $includeChildren, ?int $period): array {
		// Unbekanntes Konto früh abweisen, bevor Buchungen geladen werden.
		$account = $this->accountMapper->find($id, $userId);
		[$from, $to] = $this->periods->range($userId, $period);
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
		// einen Bestand über die Grenze des Geschäftsjahres. So verfälscht bei "inkl. Unterkonten"
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
			// bietet das Umbuchen für beide Seiten an, siehe JournalController::reassign().
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
					'comment' => $journal->getComment(),
					'accountId' => $line->getAccountId(),
					'account' => $labels[$line->getAccountId()] ?? ('#' . $line->getAccountId()),
					'contra' => implode(', ', $contra),
					'contraAccounts' => $contraAccounts,
					// Für das optimistische Locking beim Umbuchen (siehe JournalController::reassign()).
					'updatedAt' => $journal->getUpdatedAt(),
					'debit' => $line->getDebitCents() / 100,
					'credit' => $line->getCreditCents() / 100,
				];
			}
		}

		usort($rows, static function ($a, $b) {
			return [$a['date'], $a['entryNo']] <=> [$b['date'], $b['entryNo']];
		});

		// Saldovortrag nur bei gewähltem Zeitraum; beigetragen haben oben ohnehin
		// nur Zeilen von Geldkonten (debit-Natur), daher Soll − Haben.
		$carryCents = 0;
		if ($from !== null) {
			$carryCents = $carryDebit - $carryCredit;
		}
		$periodNet = $account->isCreditNature() ? ($sumCredit - $sumDebit) : ($sumDebit - $sumCredit);
		$balanceCents = $carryCents + $periodNet;

		// Laufender Saldo je Zeile in chronologischer Reihenfolge, ausgehend vom
		// Vortrag, in der Natur des gewählten Kontos (Haben-Konten: Haben − Soll).
		$runCents = $carryCents;
		foreach ($rows as &$row) {
			$runCents += $account->isCreditNature()
				? (int)round(($row['credit'] - $row['debit']) * 100)
				: (int)round(($row['debit'] - $row['credit']) * 100);
			$row['saldo'] = $runCents / 100;
		}
		unset($row);

		return [
			'account' => $account,
			'includeChildren' => $includeChildren,
			'periodId' => $period,
			// Datum der Vortragszeile im Kontoauszug. Es ist der erste Tag des
			// gewählten Geschäftsjahres – seit Issue #8 nicht mehr zwingend
			// der 01.01., und deshalb schickt der Server ihn mit, statt ihn
			// die Oberfläche aus einer Jahreszahl basteln zu lassen.
			'carryDate' => $from,
			'carry' => $carryCents / 100,
			'rows' => $rows,
			'totals' => [
				'debit' => $sumDebit / 100,
				'credit' => $sumCredit / 100,
				'balance' => $balanceCents / 100,
				'count' => count($rows),
			],
		];
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
