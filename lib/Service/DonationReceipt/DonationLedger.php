<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\DonationReceipt;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Donation;
use OCA\Vereinsbuchhaltung\Db\DonationMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;

/**
 * Geldspenden aus der Buchhaltung (Issue #10, Teil B).
 *
 * Eine Spende ist eine Buchung mit Haben auf einem Spendenkonto
 * ({@see DonationReceiptSettings::donationAccountIds()}); ihr Betrag ist der
 * Saldo Haben minus Soll dieser Buchung auf den Spendenkonten, ihr Datum das
 * Buchungsdatum (Tag der Gutschrift). Die Zuordnung zum Spender steht in
 * `vbh_donations`. Betrag und Datum werden nie kopiert, sondern immer aus der
 * Buchung gelesen – eine geänderte Buchung zeigt sich damit an der
 * Bestätigung als „überholt“.
 */
class DonationLedger {

	public function __construct(
		private DonationReceiptSettings $settings,
		private DonationMapper $donations,
		private JournalMapper $journals,
		private JournalLineMapper $lines,
	) {
	}

	/**
	 * Buchungen eines Kalenderjahres mit Spendenbetrag > 0, ältere zuerst.
	 *
	 * @return list<array{journal: Journal, amountCents: int}>
	 */
	public function inYear(int $year): array {
		$accounts = $this->settings->donationAccountIds();
		if ($accounts === []) {
			return [];
		}
		$ids = $this->lines->findJournalIdsForAccounts(Application::BOOK, $accounts, $year . '-01-01', $year . '-12-31');
		return $this->withAmounts($ids);
	}

	/**
	 * Die zugeordneten Spenden eines Spenders, ältere zuerst.
	 *
	 * @return list<array{journalId: int, type: string, date: string, amountCents: int, description: string, waiver: bool}>
	 */
	public function forDonor(string $kind, int $id): array {
		$donations = [];
		foreach ($this->donations->findByDonor($kind, $id) as $donation) {
			$donations[$donation->getJournalId()] = $donation;
		}
		$out = [];
		foreach ($this->withAmounts(array_keys($donations)) as $row) {
			$journal = $row['journal'];
			$out[] = self::entry($journal, $row['amountCents'], $donations[(int)$journal->getId()]);
		}
		return $out;
	}

	/**
	 * Eine einzelne Spende, oder null, wenn die Buchung fehlt oder nichts auf
	 * einem Spendenkonto enthält.
	 *
	 * @return array{journalId: int, type: string, date: string, amountCents: int, description: string, waiver: bool}|null
	 */
	public function entryFor(Donation $donation): ?array {
		$rows = $this->withAmounts([$donation->getJournalId()]);
		return $rows === [] ? null : self::entry($rows[0]['journal'], $rows[0]['amountCents'], $donation);
	}

	/**
	 * Spendenbetrag einer Buchung (0, wenn sie kein Spendenkonto berührt).
	 */
	public function amountOf(int $journalId): int {
		$rows = $this->withAmounts([$journalId]);
		return $rows === [] ? 0 : $rows[0]['amountCents'];
	}

	/**
	 * @param int[] $journalIds
	 * @return list<array{journal: Journal, amountCents: int}>
	 */
	private function withAmounts(array $journalIds): array {
		$accounts = array_flip($this->settings->donationAccountIds());
		if ($journalIds === [] || $accounts === []) {
			return [];
		}
		$journals = $this->journals->findByIds(Application::BOOK, $journalIds);
		$lines = $this->lines->findByJournals(array_keys($journals));
		$out = [];
		foreach ($journals as $id => $journal) {
			$amount = 0;
			foreach ($lines[$id] ?? [] as $line) {
				if (isset($accounts[$line->getAccountId()])) {
					$amount += $line->getCreditCents() - $line->getDebitCents();
				}
			}
			if ($amount > 0) {
				$out[] = ['journal' => $journal, 'amountCents' => $amount];
			}
		}
		usort($out, static fn (array $a, array $b): int => [$a['journal']->getDate(), (int)$a['journal']->getId()] <=> [$b['journal']->getDate(), (int)$b['journal']->getId()]);
		return $out;
	}

	/**
	 * @return array{journalId: int, type: string, date: string, amountCents: int, description: string, waiver: bool}
	 */
	private static function entry(Journal $journal, int $amount, Donation $donation): array {
		return [
			'journalId' => (int)$journal->getId(),
			'type' => 'spende',
			'date' => substr($journal->getDate(), 0, 10),
			'amountCents' => $amount,
			'description' => (string)($journal->getDescription() ?? ''),
			'waiver' => $donation->isWaiver(),
		];
	}
}
