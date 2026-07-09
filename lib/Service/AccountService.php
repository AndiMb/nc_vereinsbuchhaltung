<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class AccountService {

	/**
	 * Schlanker Standard-Kontenrahmen für Vereine (doppelte Buchführung).
	 * Bewusst kompakt – frei erweiterbar.
	 *
	 * @var array<int, array{number:string, name:string, type:string, category:string, isBank?:bool}>
	 */
	private const DEFAULTS = [
		// Bestandskonten
		['number' => '1200', 'name' => 'Bankkonto', 'type' => 'asset', 'category' => 'Geldkonten', 'isBank' => true],
		['number' => '1000', 'name' => 'Kasse', 'type' => 'asset', 'category' => 'Geldkonten', 'isBank' => true],
		['number' => '0800', 'name' => 'Vereinsvermögen', 'type' => 'equity', 'category' => 'Eigenkapital'],
		// Erträge
		['number' => '4000', 'name' => 'Mitgliedsbeiträge', 'type' => 'income', 'category' => 'Einnahmen'],
		['number' => '4100', 'name' => 'Spenden', 'type' => 'income', 'category' => 'Einnahmen'],
		['number' => '4200', 'name' => 'Zuschüsse / Fördermittel', 'type' => 'income', 'category' => 'Einnahmen'],
		['number' => '4300', 'name' => 'Veranstaltungseinnahmen', 'type' => 'income', 'category' => 'Einnahmen'],
		['number' => '4900', 'name' => 'Sonstige Einnahmen', 'type' => 'income', 'category' => 'Einnahmen'],
		// Aufwendungen
		['number' => '5000', 'name' => 'Raum- / Mietkosten', 'type' => 'expense', 'category' => 'Ausgaben'],
		['number' => '5100', 'name' => 'Versicherungen / Beiträge', 'type' => 'expense', 'category' => 'Ausgaben'],
		['number' => '5200', 'name' => 'Veranstaltungskosten', 'type' => 'expense', 'category' => 'Ausgaben'],
		['number' => '5300', 'name' => 'Büro- / Verwaltungskosten', 'type' => 'expense', 'category' => 'Ausgaben'],
		['number' => '5400', 'name' => 'Bankgebühren', 'type' => 'expense', 'category' => 'Ausgaben'],
		['number' => '5900', 'name' => 'Sonstige Ausgaben', 'type' => 'expense', 'category' => 'Ausgaben'],
	];

	public function __construct(
		private AccountMapper $mapper,
	) {
	}

	/**
	 * @return Account[]
	 */
	public function findAll(string $userId): array {
		return $this->mapper->findAll($userId);
	}

	public function find(int $id, string $userId): Account {
		return $this->mapper->find($id, $userId);
	}

	public function create(string $userId, string $number, string $name, string $type, ?string $category, bool $isBank, ?int $parentId = null, bool $transitory = false): Account {
		$account = new Account();
		$account->setUserId($userId);
		$account->setNumber(trim($number));
		$account->setName(trim($name));
		$account->setType($this->validateType($type));
		$account->setCategory($category !== null ? trim($category) : null);
		$account->setIsBank($isBank);
		$account->setTransitory($transitory);
		$account->setActive(true);
		if ($parentId !== null && $parentId > 0) {
			// Überkonto muss existieren und demselben Bestand gehören.
			$this->mapper->find($parentId, $userId);
			$account->setParentId($parentId);
		}
		return $this->mapper->insert($account);
	}

	public function update(int $id, string $userId, array $data): Account {
		$account = $this->mapper->find($id, $userId);
		if (isset($data['number'])) {
			$account->setNumber(trim((string)$data['number']));
		}
		if (isset($data['name'])) {
			$account->setName(trim((string)$data['name']));
		}
		if (isset($data['type'])) {
			$account->setType($this->validateType((string)$data['type']));
		}
		if (array_key_exists('category', $data)) {
			$account->setCategory($data['category'] !== null ? trim((string)$data['category']) : null);
		}
		if (isset($data['isBank'])) {
			$account->setIsBank((bool)$data['isBank']);
		}
		if (isset($data['transitory'])) {
			$account->setTransitory((bool)$data['transitory']);
		}
		if (isset($data['active'])) {
			$account->setActive((bool)$data['active']);
		}
		if (array_key_exists('parentId', $data)) {
			$account->setParentId($this->resolveParent($id, $userId, (int)$data['parentId']));
		}
		return $this->mapper->update($account);
	}

	/**
	 * Prüft ein gewünschtes Überkonto und liefert die zu setzende parent_id
	 * (oder null für Wurzel). Verhindert Selbst- und Zyklus-Zuordnungen.
	 */
	private function resolveParent(int $id, string $userId, int $parentId): ?int {
		if ($parentId <= 0) {
			return null;
		}
		if ($parentId === $id) {
			throw new \InvalidArgumentException('Ein Konto kann nicht sein eigenes Überkonto sein.');
		}
		$byId = [];
		foreach ($this->mapper->findAll($userId) as $acc) {
			$byId[$acc->getId()] = $acc;
		}
		if (!isset($byId[$parentId])) {
			throw new \InvalidArgumentException('Überkonto nicht gefunden.');
		}
		// Würde das gewählte Überkonto unter diesem Konto hängen? → Zyklus.
		$cursor = $byId[$parentId];
		$guard = 0;
		while ($cursor !== null && $guard++ < 1000) {
			if ($cursor->getId() === $id) {
				throw new \InvalidArgumentException('Ungültige Zuordnung: Das gewählte Überkonto ist ein Unterkonto dieses Kontos.');
			}
			$pid = $cursor->getParentId();
			$cursor = $pid && isset($byId[$pid]) ? $byId[$pid] : null;
		}
		return $parentId;
	}

	public function delete(int $id, string $userId): void {
		$account = $this->mapper->find($id, $userId);
		$this->mapper->delete($account);
	}

	/**
	 * Legt den Standard-Kontenrahmen an (nur wenn noch keine Konten existieren).
	 *
	 * @return Account[] alle Konten nach dem Seeding
	 */
	public function seedDefaults(string $userId): array {
		if ($this->mapper->countForUser($userId) > 0) {
			return $this->mapper->findAll($userId);
		}
		foreach (self::DEFAULTS as $def) {
			$this->create(
				$userId,
				$def['number'],
				$def['name'],
				$def['type'],
				$def['category'] ?? null,
				$def['isBank'] ?? false,
			);
		}
		return $this->mapper->findAll($userId);
	}

	/**
	 * Liefert das erste als Bankkonto markierte Konto.
	 */
	public function getDefaultBankAccount(string $userId): Account {
		foreach ($this->mapper->findAll($userId) as $account) {
			if ($account->getIsBank()) {
				return $account;
			}
		}
		throw new DoesNotExistException('Kein Bankkonto im Kontenrahmen definiert.');
	}

	/**
	 * Liefert das Eigenkapitalkonto für Eröffnungsbuchungen – legt es bei
	 * Bedarf an.
	 */
	public function getOpeningEquityAccount(string $userId): Account {
		foreach ($this->mapper->findAll($userId) as $account) {
			if ($account->getType() === 'equity') {
				return $account;
			}
		}
		foreach (['0800', '9000', '9001'] as $number) {
			try {
				return $this->create($userId, $number, 'Vereinsvermögen (Eröffnung)', 'equity', 'Eigenkapital', false);
			} catch (\Throwable) {
				continue;
			}
		}
		throw new \RuntimeException('Eigenkapitalkonto für Eröffnung konnte nicht angelegt werden.');
	}

	/**
	 * Setzt Eröffnungssaldo und -datum eines Kontos (Cent).
	 */
	public function setOpeningFields(int $id, string $userId, int $amountCents, ?string $date): Account {
		$account = $this->mapper->find($id, $userId);
		$account->setOpeningBalanceCents($amountCents);
		$account->setOpeningDate($date !== null && $date !== '' ? $date : null);
		return $this->mapper->update($account);
	}

	private function validateType(string $type): string {
		$allowed = ['asset', 'liability', 'equity', 'income', 'expense'];
		if (!in_array($type, $allowed, true)) {
			throw new \InvalidArgumentException('Ungültiger Kontotyp: ' . $type);
		}
		return $type;
	}
}
