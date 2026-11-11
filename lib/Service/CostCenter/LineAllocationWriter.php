<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\CostCenter;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenter;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\LineAllocationMapper;
use OCA\Vereinsbuchhaltung\Service\ClubCurrency;
use OCA\Vereinsbuchhaltung\Service\CostCenterSettings;
use OCP\IL10N;

/**
 * Die einzige Stelle, die Verteilungen auf Kostenstellen prüft und schreibt
 * (Issue #7). Jeder Weg, auf dem Buchungszeilen entstehen oder ersetzt
 * werden, reicht seine Zeilen hier hinein.
 *
 * Die Zeilen selbst schreibt weiterhin der Aufrufer; die Verteilung hängt
 * über die Zeilen-ID daran. Weil eine Bearbeitung alle Zeilen löscht und neu
 * schreibt, nimmt der Aufrufer vorher einen {@see snapshot()} und gibt ihn
 * an {@see apply()} zurück – so bleibt eine Verteilung erhalten, zu der der
 * Aufrufer nichts gesagt hat ({@see AllocationRules::carryOver()}).
 *
 * Bei ausgeschaltetem Modul wird nichts Neues angenommen, aber weiter
 * erhalten: wer die Kostenstellen nicht sieht, soll sie beim Bearbeiten
 * einer Buchung auch nicht verlieren.
 */
class LineAllocationWriter {

	public function __construct(
		private LineAllocationMapper $allocations,
		private JournalLineMapper $lines,
		private AccountMapper $accounts,
		private BookingCostCenterMapper $costCenters,
		private CostCenterSettings $settings,
		private IL10N $l10n,
		private ClubCurrency $currency,
	) {
	}

	/**
	 * Die Zeilen eines Buchungssatzes samt Verteilung, bevor sie ersetzt werden.
	 *
	 * @return list<array{accountId:int, debitCents:int, creditCents:int, allocations: list<array{costCenterId:int, amountCents:int}>}>
	 */
	public function snapshot(int $journalId): array {
		$byLine = $this->allocations->findByJournal($journalId);
		$out = [];
		foreach ($this->lines->findByJournal($journalId) as $line) {
			$out[] = [
				'accountId' => (int)$line->getAccountId(),
				'debitCents' => (int)$line->getDebitCents(),
				'creditCents' => (int)$line->getCreditCents(),
				'allocations' => $byLine[(int)$line->getId()] ?? [],
			];
		}
		return $out;
	}

	/**
	 * Prüft die angegebenen Verteilungen, ohne etwas zu schreiben.
	 *
	 * @param list<array<string, mixed>> $lines
	 * @param list<array<string, mixed>> $snapshot Stand vor der Änderung, siehe {@see snapshot()}
	 * @return string|null Fehlermeldung für den Nutzer, oder null
	 */
	public function check(string $userId, array $lines, array $snapshot = []): ?string {
		if (!$this->requestsAllocations($lines)) {
			return null;
		}
		if (!$this->settings->isEnabled()) {
			return $this->translate(['code' => 'alloc_disabled', 'line' => 0]);
		}
		$types = [];
		foreach ($this->accounts->findAll($userId) as $account) {
			$types[(int)$account->getId()] = (string)$account->getType();
		}
		$known = [];
		$active = [];
		foreach ($this->costCenters->findAll($userId) as $costCenter) {
			$known[] = $costCenter->getId();
			if ($costCenter->isActive()) {
				$active[] = $costCenter->getId();
			}
		}
		$error = AllocationRules::validate($lines, $types, $known, $active, AllocationRules::previousOf($snapshot));
		return $error === null ? null : $this->translate($error);
	}

	/**
	 * Schreibt die Verteilung zu den frisch geschriebenen Zeilen eines Buchungssatzes.
	 *
	 * @param list<array<string, mixed>> $lines die Zeilen, wie sie gerade geschrieben wurden
	 * @param list<array<string, mixed>> $snapshot Stand vor der Änderung; leer beim Anlegen
	 * @return string Verteilung in Kurzform für das Änderungsprotokoll, leer ohne Verteilung
	 * @throws \InvalidArgumentException wenn eine angegebene Verteilung ungültig ist
	 */
	public function apply(int $journalId, string $userId, array $lines, array $snapshot = []): string {
		$error = $this->check($userId, $lines, $snapshot);
		if ($error !== null) {
			throw new \InvalidArgumentException($error);
		}
		if (!$this->settings->isEnabled()) {
			// Ausgeschaltet zählt nur der Bestand: auch ein leer mitgeschickter
			// Schlüssel entfernt nichts.
			$lines = array_map(static function (array $line): array {
				unset($line['allocations']);
				return $line;
			}, $lines);
		}
		$resolved = AllocationRules::carryOver($snapshot, $lines);
		if (!$this->requestsAllocations($resolved)) {
			return '';
		}

		$lineIds = [];
		foreach ($this->lines->findByJournal($journalId) as $written) {
			$lineIds[(int)$written->getAccountId()] = (int)$written->getId();
		}
		$codes = [];
		foreach ($this->costCenters->findAll($userId) as $costCenter) {
			$codes[$costCenter->getId()] = (string)$costCenter->getCode();
		}
		$summary = [];
		foreach ($resolved as $line) {
			$lineId = $lineIds[(int)$line['accountId']] ?? null;
			if ($lineId === null) {
				continue;
			}
			foreach ($line['allocations'] as $allocation) {
				$costCenterId = (int)$allocation['costCenterId'];
				$amount = (int)$allocation['amountCents'];
				$this->allocations->insertFor($lineId, $costCenterId, $amount);
				$summary[] = ($codes[$costCenterId] ?? '#' . $costCenterId) . ' ' . $this->currency->formatNumber($amount);
			}
		}
		return implode(', ', $summary);
	}

	/** Muss laufen, solange die Zeilen des Buchungssatzes noch da sind. */
	public function deleteByJournal(int $journalId): void {
		$this->allocations->deleteByJournal($journalId);
	}

	/**
	 * Nach dem Umbuchen einer Zeile auf ein anderes Konto: die Zeile ist
	 * dieselbe geblieben, also bleibt auch ihre Verteilung – es sei denn,
	 * das neue Konto trägt keine Kostenstellen.
	 */
	public function afterReassign(int $lineId, Account $target): void {
		if (!$target->canCarryCostCenter()) {
			$this->allocations->deleteByLine($lineId);
		}
	}

	/**
	 * Verteilungen vieler Buchungssätze für die Ausgabe, Beträge in Euro.
	 *
	 * @param int[] $journalIds
	 * @return array<int, list<array{costCenterId:int, amount:float}>> Zeilen-ID => Anteile
	 */
	public function forJournals(array $journalIds): array {
		$out = [];
		foreach ($this->allocations->findByJournals($journalIds) as $lineId => $allocations) {
			$out[$lineId] = array_map(
				static fn (array $a): array => ['costCenterId' => $a['costCenterId'], 'amount' => $a['amountCents'] / 100],
				$allocations,
			);
		}
		return $out;
	}

	/**
	 * Kostenstellen des Bestands nach ID, für Ausgaben, die das Kürzel brauchen.
	 *
	 * @return array<int, BookingCostCenter>
	 */
	public function costCentersById(string $userId): array {
		$out = [];
		foreach ($this->costCenters->findAll($userId) as $costCenter) {
			$out[$costCenter->getId()] = $costCenter;
		}
		return $out;
	}

	/** @param list<array<string, mixed>> $lines */
	private function requestsAllocations(array $lines): bool {
		foreach ($lines as $line) {
			if (($line['allocations'] ?? []) !== []) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array{code:string, line:int, params?:array<string, int>} $error
	 */
	private function translate(array $error): string {
		$params = $error['params'] ?? [];
		return match ($error['code']) {
			'alloc_exceeds_line' => $this->l10n->t(
				'Den Kostenstellen ist mehr zugeordnet (%1$s), als die Buchungszeile hergibt (%2$s).',
				[
					$this->currency->format((int)($params['allocated'] ?? 0)),
					$this->currency->format((int)($params['line'] ?? 0)),
				],
			),
			'alloc_duplicate' => $this->l10n->t('Jede Kostenstelle darf in einer Buchungszeile nur einmal vorkommen.'),
			'alloc_unknown' => $this->l10n->t('Eine Kostenstelle der Buchung wurde nicht gefunden.'),
			'alloc_inactive' => $this->l10n->t('Eine inaktive Kostenstelle lässt sich nicht neu zuordnen.'),
			'alloc_account_type' => $this->l10n->t('Kostenstellen lassen sich nur Einnahmen und Ausgaben zuordnen.'),
			'alloc_amount' => $this->l10n->t('Jeder Anteil einer Kostenstelle braucht einen Betrag größer als 0.'),
			'alloc_disabled' => $this->l10n->t('Kostenstellen sind in den Einstellungen ausgeschaltet.'),
			default => $error['code'],
		};
	}
}
