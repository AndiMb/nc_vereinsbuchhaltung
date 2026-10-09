<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Dev;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCA\Vereinsbuchhaltung\Db\DebitBatchMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\SepaDebtorAccountService;

/**
 * Erzeugt aus einem eingereichten Lastschriftlauf den passenden camt.053-
 * Kontoauszug der Bank: die Sammelgutschrift der erfolgreich eingezogenen
 * Posten, die Rückgaben des Szenarios ({@see DevScenario::returns()}) und ein
 * paar unabhängige Kontobewegungen ({@see DevScenario::plainBankEntries()}).
 *
 * End-to-End-IDs, Mandatsreferenzen und Beträge kommen aus den Posten des
 * Laufs in der Datenbank – deshalb passt die Datei immer zu dem Lauf, aus dem
 * sie erzeugt wurde. Der Seeder vergibt die End-to-End-IDs deterministisch, die
 * Datei ist darum nach jedem `--wipe` und erneuten Seeden byte-identisch.
 *
 * Buchungstage, Kopfzeile und Aufbau hängen nur vom Lauf und vom Szenario ab,
 * nie von der Uhrzeit.
 *
 * @phpstan-import-type Item from CamtBuilder
 * @phpstan-import-type Entry from CamtBuilder
 */
final class CamtGenerator {

	/** Länge, auf die der Seeder/die App die PmtInfId kürzt (pain.008: Max35Text). */
	private const MAX_ID = 35;

	public function __construct(
		private DebitBatchMapper $batches,
		private DebitItemMapper $items,
		private OpenItemMapper $openItems,
		private MemberMapper $members,
		private AccountMapper $accounts,
		private SepaDebtorAccountService $debtorAccount,
	) {
	}

	/**
	 * @param int|null $batchId der Lauf; ohne Angabe der eingereichte Lauf zum Oktobertermin des Szenarios
	 * @return array{xml:string, batchId:int, dueDate:string, collected:int, returned:int, plain:int, collectedCents:int}
	 */
	public function generate(?int $batchId = null): array {
		$batch = $this->findBatch($batchId);
		$iban = $this->ownIban();
		$returns = DevScenario::returns();

		/** @var list<Item> $collected */
		$collected = [];
		/** @var array<int, Item> $returned */
		$returned = [];
		foreach ($this->items->findByBatch((int)$batch->getId()) as $debitItem) {
			$claim = $this->openItems->find($debitItem->getOpenItemId());
			$member = $claim->getMemberId() !== null ? $this->members->findOrNull($claim->getMemberId()) : null;
			if ($member === null) {
				continue;
			}
			$number = (int)$member->getMemberNumber();
			$item = [
				'endToEndId' => $debitItem->getEndToEndId(),
				'mandateReference' => $debitItem->getMandateReference(),
				'amountCents' => $debitItem->getAmountCents(),
				'purpose' => $debitItem->getRemittanceInfo() . ' ' . $member->displayName(),
			];
			if (isset($returns[$number])) {
				$returned[$number] = $item;
				continue;
			}
			$collected[] = $item;
		}
		foreach (array_keys($returns) as $number) {
			if (!isset($returned[$number])) {
				throw new \RuntimeException('Mitglied ' . $number . ' hat keinen Posten im Lauf ' . $batch->getId() . ' – die Rückgabe lässt sich nicht erzeugen.');
			}
		}

		$dueDate = $batch->getDueDate();
		/** @var list<array{date: string, entry: Entry}> $dated */
		$dated = [];
		$dated[] = ['date' => $dueDate, 'entry' => CamtBuilder::collectionEntry($dueDate, $collected, mb_substr($batch->getMsgId() . '-RCUR', 0, self::MAX_ID))];
		foreach ($returns as $number => $case) {
			$date = self::plusDays($dueDate, $case['bookedAfterDays']);
			$dated[] = ['date' => $date, 'entry' => CamtBuilder::returnEntry($date, $returned[$number], $case['code'], $case['text'], $case['chargesCents'])];
		}
		foreach (DevScenario::plainBankEntries() as $plain) {
			$date = self::plusDays($dueDate, $plain['afterDays']);
			$dated[] = ['date' => $date, 'entry' => CamtBuilder::plainEntry(
				$date,
				$plain['direction'],
				$plain['amountCents'],
				$plain['counterparty'],
				DevScenario::iban($plain['bank']),
				$plain['purpose'],
				$plain['bookingText'],
			)];
		}
		// Chronologisch, bei gleichem Tag in der Reihenfolge der Erzeugung (stabil).
		usort($dated, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
		$entries = array_map(static fn (array $row): array => $row['entry'], $dated);

		$last = self::plusDays($dueDate, 4);
		$xml = CamtBuilder::statement(
			$iban,
			$entries,
			'TESTDATEN-BANK-' . str_replace('-', '', $dueDate),
			$last . 'T08:00:00',
			'AUSZUG-' . substr($dueDate, 0, 7) . '-TESTDATEN',
		);

		return [
			'xml' => $xml,
			'batchId' => (int)$batch->getId(),
			'dueDate' => $dueDate,
			'collected' => count($collected),
			'returned' => count($returned),
			'plain' => count(DevScenario::plainBankEntries()),
			'collectedCents' => array_sum(array_map(static fn (array $i): int => $i['amountCents'], $collected)),
		];
	}

	private function findBatch(?int $batchId): DebitBatch {
		if ($batchId !== null) {
			return $this->batches->find($batchId);
		}
		foreach ($this->batches->findAll() as $batch) {
			if ($batch->getDueDate() === DevScenario::RUN_DUE_DATE && $batch->getStatus() === DebitBatch::STATUS_SUBMITTED) {
				return $batch;
			}
		}
		throw new \RuntimeException('Es gibt keinen eingereichten Lauf zum ' . DevScenario::RUN_DUE_DATE . ' – bitte zuerst tests/dev/seed-beitraege.php ausführen oder --batch-id angeben.');
	}

	/** IBAN des einziehenden Kontos – die Bank liefert den Auszug für genau dieses Konto. */
	private function ownIban(): string {
		$accountId = $this->debtorAccount->getAccountId();
		if ($accountId === null) {
			throw new \RuntimeException('Es ist kein einziehendes Konto eingestellt.');
		}
		$iban = $this->accounts->find($accountId, Application::BOOK)->getIban();
		if ($iban === null || $iban === '') {
			throw new \RuntimeException('Das einziehende Konto hat keine IBAN.');
		}
		return $iban;
	}

	private static function plusDays(string $date, int $days): string {
		return (new \DateTimeImmutable($date))->modify('+' . $days . ' days')->format('Y-m-d');
	}
}
