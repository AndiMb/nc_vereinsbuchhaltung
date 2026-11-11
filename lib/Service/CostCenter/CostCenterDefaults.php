<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\CostCenter;

use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BookingCostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\InvoiceLineMapper;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
use OCA\Vereinsbuchhaltung\Service\CostCenterSettings;

/**
 * Vorgabe-Kostenstellen aus Stammdaten (Importregeln, später Rechnungszeilen
 * und Beitragsgruppen) in eine Verteilung übersetzen – oder eben nicht.
 *
 * Eine Vorgabe scheitert weich: ist das Modul aus, die Kostenstelle inaktiv
 * oder gelöscht, oder trägt das Konto der Zeile keine Kostenstelle, kommt
 * `null` heraus. Für den {@see LineAllocationWriter} heißt das "nichts
 * gesagt"; die Buchung entsteht ohne Kostenstelle. Kein Import, keine
 * Zahlung und kein Einzug soll an einer Kostenstelle scheitern.
 *
 * Außerdem die Gegenrichtung: wo eine Kostenstelle als Vorgabe eingetragen
 * ist ({@see usage()}), damit sie nicht gelöscht wird, solange etwas auf sie
 * zeigt.
 */
class CostCenterDefaults {

	/** @var array<string, list<int>> je Bestand die nutzbaren Kostenstellen, für die Dauer des Requests */
	private array $usable = [];

	public function __construct(
		private CostCenterSettings $settings,
		private BookingCostCenterMapper $costCenters,
		private AccountMapper $accounts,
		private RuleMapper $rules,
		private ?InvoiceLineMapper $invoiceLines = null,
	) {
	}

	/**
	 * Der ganze Betrag auf eine Vorgabe-Kostenstelle.
	 *
	 * @return list<array{costCenterId:int, amountCents:int}>|null null, wenn die Vorgabe fehlt oder nicht anwendbar ist
	 */
	public function fullAllocation(string $userId, ?int $costCenterId, int $accountId, int $amountCents): ?array {
		if ($costCenterId === null || $amountCents <= 0) {
			return null;
		}
		if (!in_array($costCenterId, $this->usableIds($userId), true)) {
			return null;
		}
		try {
			$account = $this->accounts->find($accountId, $userId);
		} catch (\Throwable) {
			return null;
		}
		if (!$account->canCarryCostCenter()) {
			return null;
		}
		return [['costCenterId' => $costCenterId, 'amountCents' => $amountCents]];
	}

	/**
	 * Kostenstellen, die sich neu zuordnen lassen: die aktiven, und nur bei
	 * eingeschaltetem Modul.
	 *
	 * @return list<int>
	 */
	public function usableIds(string $userId): array {
		if (!$this->settings->isEnabled()) {
			return [];
		}
		if (!isset($this->usable[$userId])) {
			$ids = [];
			foreach ($this->costCenters->findAll($userId) as $costCenter) {
				if ($costCenter->isActive()) {
					$ids[] = $costCenter->getId();
				}
			}
			$this->usable[$userId] = $ids;
		}
		return $this->usable[$userId];
	}

	/**
	 * Alle vorhandenen Kostenstellen, auch inaktive – für Stammdaten, an denen
	 * eine einmal gewählte Kostenstelle stehen bleiben darf.
	 *
	 * @return list<int>
	 */
	public function knownIds(string $userId): array {
		return array_map(static fn ($costCenter): int => $costCenter->getId(), $this->costCenters->findAll($userId));
	}

	/**
	 * Nachschlagetabelle für Importe: Kürzel oder Name (klein) => ID, nur
	 * nutzbare Kostenstellen. Das Kürzel geht dem Namen vor. null bei
	 * ausgeschaltetem Modul – der Aufrufer verhält sich dann wie zuvor.
	 *
	 * @return array<string, int>|null
	 */
	public function lookup(string $userId): ?array {
		if (!$this->settings->isEnabled()) {
			return null;
		}
		$active = array_filter($this->costCenters->findAll($userId), static fn ($costCenter): bool => $costCenter->isActive());
		$map = [];
		foreach ($active as $costCenter) {
			$map[mb_strtolower(trim((string)$costCenter->getName()))] = $costCenter->getId();
		}
		foreach ($active as $costCenter) {
			$map[mb_strtolower(trim((string)$costCenter->getCode()))] = $costCenter->getId();
		}
		return $map;
	}

	/**
	 * Wo die Kostenstelle als Vorgabe eingetragen ist.
	 *
	 * @return array<string, int> Art => Anzahl
	 */
	public function usage(int $costCenterId): array {
		return [
			'rules' => $this->rules->countByCostCenter($costCenterId),
			'invoiceLines' => $this->invoiceLines?->countByBookingCostCenter($costCenterId) ?? 0,
		];
	}
}
