<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\DonationReceipt;

use OCA\Vereinsbuchhaltung\Db\DebtorMapper;
use OCA\Vereinsbuchhaltung\Db\Donation;
use OCA\Vereinsbuchhaltung\Db\DonationMapper;
use OCA\Vereinsbuchhaltung\Db\DonationReceipt;
use OCA\Vereinsbuchhaltung\Db\DonationReceiptMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\ClubClock;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Spenden und ihre Spender (Issue #10, Teil B): die Liste der Spendenbuchungen
 * eines Jahres, die Zuordnung zu einem Mitglied oder Debitor und die
 * Sammelbestätigungen je Spender.
 *
 * Eine Zuordnung, die schon in einer gültigen Bestätigung steht, lässt sich
 * nicht mehr ändern – sonst passte das Doppel nicht mehr zur Buchhaltung.
 */
class DonationService {

	public function __construct(
		private DonationMapper $donations,
		private DonationLedger $ledger,
		private DonationReceiptService $receipts,
		private DonationReceiptMapper $receiptMapper,
		private DonationReceiptSettings $settings,
		private MemberMapper $members,
		private DebtorMapper $debtors,
		private AuditService $audit,
		private ClubClock $clock,
		private IL10N $l10n,
	) {
	}

	/**
	 * Spenden eines Kalenderjahres mit Spender und Bestätigung, die Spender
	 * mit ihrer Sammelbestätigung und alle Bestätigungen des Jahres.
	 *
	 * @return array<string, mixed>
	 */
	public function overview(int $year): array {
		$today = $this->clock->today();
		if ($year < 1900 || $year > 9999) {
			$year = (int)substr($today, 0, 4);
		}
		$base = [
			'enabled' => $this->settings->isEnabled(),
			'year' => $year,
			'accountsConfigured' => $this->settings->donationAccountIds() !== [],
			'feesIncluded' => $this->settings->feesDeductible(),
			'problems' => [],
			'donations' => [],
			'donors' => [],
			'receipts' => [],
		];
		if (!$base['enabled']) {
			return $base;
		}
		$base['problems'] = $this->settings->problems($today);

		$rows = $this->ledger->inYear($year);
		$assigned = $this->donations->findByJournals(array_map(static fn (array $r): int => (int)$r['journal']->getId(), $rows));
		$receipts = $this->receiptMapper->findByYear($year);
		$confirmedBy = [];
		foreach ($receipts as $receipt) {
			if ($receipt->isVoided()) {
				continue;
			}
			foreach ($receipt->itemList() as $item) {
				$confirmedBy[DonationReceipt::itemKey($item)] = ['id' => (int)$receipt->getId(), 'number' => $receipt->getNumber(), 'type' => $receipt->getReceiptType()];
			}
		}

		$names = $this->names(array_merge(
			array_map(static fn (Donation $d): array => $d->donor(), array_values($assigned)),
			array_map(static fn (DonationReceipt $r): array => $r->donor(), $receipts),
		));

		$donors = [];
		foreach ($rows as $row) {
			$journal = $row['journal'];
			$journalId = (int)$journal->getId();
			$donation = $assigned[$journalId] ?? null;
			$donor = null;
			if ($donation !== null) {
				[$kind, $id] = $donation->donor();
				$donor = ['kind' => $kind, 'id' => $id, 'name' => $names[$kind . ':' . $id] ?? '?'];
				$donors[$kind . ':' . $id] = $donor;
			}
			$base['donations'][] = [
				'journalId' => $journalId,
				'entryNo' => $journal->getEntryNo(),
				'date' => substr($journal->getDate(), 0, 10),
				'description' => (string)($journal->getDescription() ?? ''),
				'amountCents' => $row['amountCents'],
				'donor' => $donor,
				'waiver' => $donation?->isWaiver() ?? false,
				'receipt' => $confirmedBy['j:' . $journalId] ?? null,
			];
		}

		// Sammelbestätigungen gibt es nur für abgeschlossene Jahre.
		if ($year < (int)substr($today, 0, 4)) {
			foreach ($donors as $donor) {
				foreach ($this->receipts->years($donor['kind'], $donor['id']) as $y) {
					if ($y['year'] === $year) {
						$base['donors'][] = $donor + ['amountCents' => $y['amountCents'], 'count' => $y['count'], 'receiptId' => $y['receiptId']];
					}
				}
			}
			usort($base['donors'], static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
		}

		foreach ($receipts as $receipt) {
			[$kind, $id] = $receipt->donor();
			$base['receipts'][] = $this->receipts->describe($receipt, true) + ['donorName' => $names[$kind . ':' . $id] ?? '?'];
		}
		return $base;
	}

	/**
	 * Ordnet einer Spendenbuchung den Spender zu (oder ändert ihn).
	 *
	 * @throws DoesNotExistException wenn es Buchung, Mitglied oder Debitor nicht gibt
	 * @throws \InvalidArgumentException
	 */
	public function assign(int $journalId, int $memberId, int $debtorId, bool $waiver, string $actor): Donation {
		if (($memberId > 0) === ($debtorId > 0)) {
			throw new \InvalidArgumentException($this->l10n->t('Bitte genau einen Spender wählen: ein Mitglied oder einen Debitor.'));
		}
		if ($this->ledger->amountOf($journalId) <= 0) {
			throw new \InvalidArgumentException($this->l10n->t('Die Buchung enthält keine Spende auf einem Spendenkonto.'));
		}
		if ($memberId > 0 && $this->members->find($memberId)->isRedacted()) {
			throw new \InvalidArgumentException($this->l10n->t('Dieses Mitglied ist anonymisiert.'));
		}
		if ($debtorId > 0 && $this->debtors->find($debtorId)->getRedactedAt() !== null) {
			throw new \InvalidArgumentException($this->l10n->t('Dieser Spender ist anonymisiert.'));
		}
		$now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
		$donation = $this->donations->findByJournal($journalId);
		if ($donation !== null) {
			$same = $donation->getMemberId() === $memberId && $donation->getDebtorId() === $debtorId && $donation->isWaiver() === $waiver;
			if ($same) {
				return $donation;
			}
			$this->assertNotConfirmed($donation);
			$donation->setMemberId($memberId);
			$donation->setDebtorId($debtorId);
			$donation->setWaiver($waiver ? 1 : 0);
			$donation->setUpdatedAt($now);
			$donation = $this->donations->update($donation);
		} else {
			$donation = new Donation();
			$donation->setJournalId($journalId);
			$donation->setMemberId($memberId);
			$donation->setDebtorId($debtorId);
			$donation->setWaiver($waiver ? 1 : 0);
			$donation->setCreatedAt($now);
			$donation->setCreatedBy($actor);
			$donation = $this->donations->insert($donation);
		}
		$this->audit->log('Spender zugeordnet', 'journal', $journalId, [
			'memberId' => $memberId,
			'debtorId' => $debtorId,
			'waiver' => $waiver,
		], $actor);
		return $donation;
	}

	/**
	 * Hebt die Zuordnung einer Spendenbuchung auf.
	 *
	 * @throws \InvalidArgumentException wenn eine gültige Bestätigung die Spende enthält
	 */
	public function unassign(int $journalId, string $actor): void {
		$donation = $this->donations->findByJournal($journalId);
		if ($donation === null) {
			return;
		}
		$this->assertNotConfirmed($donation);
		$this->donations->delete($donation);
		$this->audit->log('Spenderzuordnung entfernt', 'journal', $journalId, [
			'memberId' => $donation->getMemberId(),
			'debtorId' => $donation->getDebtorId(),
		], $actor);
	}

	/** Hat ein Debitor Spenden oder Bestätigungen? Dann darf er nicht gelöscht werden. */
	public function debtorInUse(int $debtorId): bool {
		return $this->donations->findByDonor(Donation::DONOR_DEBTOR, $debtorId) !== []
			|| $this->receiptMapper->findByDebtor($debtorId) !== [];
	}

	/** @throws \InvalidArgumentException */
	private function assertNotConfirmed(Donation $donation): void {
		[$kind, $id] = $donation->donor();
		$key = 'j:' . $donation->getJournalId();
		foreach ($this->receipts->receiptsOf($kind, $id) as $receipt) {
			if ($receipt->isVoided()) {
				continue;
			}
			foreach ($receipt->itemList() as $item) {
				if (DonationReceipt::itemKey($item) === $key) {
					throw new \InvalidArgumentException($this->l10n->t('Diese Spende ist bereits in der Zuwendungsbestätigung %s enthalten. Für eine Änderung zuerst diese stornieren.', [$receipt->getNumber()]));
				}
			}
		}
	}

	/**
	 * @param list<array{0: string, 1: int}> $donors
	 * @return array<string, string> "kind:id" => Name
	 */
	private function names(array $donors): array {
		$memberIds = [];
		$debtorIds = [];
		foreach ($donors as [$kind, $id]) {
			if ($kind === Donation::DONOR_MEMBER) {
				$memberIds[$id] = $id;
			} else {
				$debtorIds[$id] = $id;
			}
		}
		$out = [];
		foreach ($memberIds as $id) {
			$member = $this->members->findOrNull($id);
			$out[Donation::DONOR_MEMBER . ':' . $id] = $member?->displayName() ?? '?';
		}
		foreach ($this->debtors->findByIds(array_values($debtorIds)) as $id => $debtor) {
			$out[Donation::DONOR_DEBTOR . ':' . $id] = (string)$debtor->getName();
		}
		return $out;
	}
}
