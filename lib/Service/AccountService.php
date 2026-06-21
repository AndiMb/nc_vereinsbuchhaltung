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

	public function create(string $userId, string $number, string $name, string $type, ?string $category, bool $isBank): Account {
		$account = new Account();
		$account->setUserId($userId);
		$account->setNumber(trim($number));
		$account->setName(trim($name));
		$account->setType($this->validateType($type));
		$account->setCategory($category !== null ? trim($category) : null);
		$account->setIsBank($isBank);
		$account->setActive(true);
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
		if (isset($data['active'])) {
			$account->setActive((bool)$data['active']);
		}
		return $this->mapper->update($account);
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

	private function validateType(string $type): string {
		$allowed = ['asset', 'liability', 'equity', 'income', 'expense'];
		if (!in_array($type, $allowed, true)) {
			throw new \InvalidArgumentException('Ungültiger Kontotyp: ' . $type);
		}
		return $type;
	}
}
