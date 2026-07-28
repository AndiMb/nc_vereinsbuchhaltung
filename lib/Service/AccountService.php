<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
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
		// Erträge – Vorbelegung „ideell", da der schlanke Standardrahmen keine
		// wirtschaftliche Tätigkeit vorsieht; bei Bedarf im Konto-Dialog anpassen.
		['number' => '4000', 'name' => 'Mitgliedsbeiträge', 'type' => 'income', 'category' => 'Einnahmen', 'sphere' => 'ideell'],
		['number' => '4100', 'name' => 'Spenden', 'type' => 'income', 'category' => 'Einnahmen', 'sphere' => 'ideell'],
		['number' => '4200', 'name' => 'Zuschüsse / Fördermittel', 'type' => 'income', 'category' => 'Einnahmen', 'sphere' => 'ideell'],
		['number' => '4300', 'name' => 'Veranstaltungseinnahmen', 'type' => 'income', 'category' => 'Einnahmen', 'sphere' => 'zweckbetrieb'],
		['number' => '4900', 'name' => 'Sonstige Einnahmen', 'type' => 'income', 'category' => 'Einnahmen'],
		// Aufwendungen
		['number' => '5000', 'name' => 'Raum- / Mietkosten', 'type' => 'expense', 'category' => 'Ausgaben', 'sphere' => 'ideell'],
		['number' => '5100', 'name' => 'Versicherungen / Beiträge', 'type' => 'expense', 'category' => 'Ausgaben', 'sphere' => 'ideell'],
		['number' => '5200', 'name' => 'Veranstaltungskosten', 'type' => 'expense', 'category' => 'Ausgaben', 'sphere' => 'zweckbetrieb'],
		['number' => '5300', 'name' => 'Büro- / Verwaltungskosten', 'type' => 'expense', 'category' => 'Ausgaben', 'sphere' => 'ideell'],
		['number' => '5400', 'name' => 'Bankgebühren', 'type' => 'expense', 'category' => 'Ausgaben', 'sphere' => 'ideell'],
		['number' => '5900', 'name' => 'Sonstige Ausgaben', 'type' => 'expense', 'category' => 'Ausgaben'],
	];

	public function __construct(
		private AccountMapper $mapper,
		private JournalLineMapper $lineMapper,
		private RuleMapper $ruleMapper,
		private BudgetMapper $budgetMapper,
		private TransactionRunner $transaction,
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

	public function create(string $userId, string $number, string $name, string $type, ?string $category, bool $isBank, ?int $parentId = null, ?string $sphere = null, ?string $reserveKind = null): Account {
		$account = new Account();
		$account->setUserId($userId);
		$account->setNumber(trim($number));
		$account->setName(trim($name));
		$account->setType($this->validateType($type));
		$account->setCategory($category !== null ? trim($category) : null);
		$account->setIsBank($isBank);
		$account->setActive(true);
		$account->setSphere($this->validateSphere($sphere));
		$account->setReserveKind($this->validateReserveKind($reserveKind));
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
		if (isset($data['active'])) {
			$account->setActive((bool)$data['active']);
		}
		if (array_key_exists('sphere', $data)) {
			$account->setSphere($this->validateSphere((string)$data['sphere']));
		}
		if (array_key_exists('reserveKind', $data)) {
			$account->setReserveKind($this->validateReserveKind((string)$data['reserveKind']));
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

	/**
	 * Löscht ein Konto – aber nur, wenn dadurch nichts kaputtgeht.
	 *
	 * Es gibt keine Fremdschlüssel im Schema, ein gelöschtes Konto würde also
	 * Buchungszeilen mit toter account_id zurücklassen. Deren Beträge
	 * verschwänden aus Saldenliste und Kassenbericht (die iterieren über die
	 * vorhandenen Konten), blieben in der Datenbank aber stehen – Soll und
	 * Haben stimmten in der Auswertung nicht mehr überein, ohne dass es
	 * jemandem auffällt. Ebenso hingen Unterkonten anschließend an einer
	 * nicht mehr existierenden parent_id.
	 *
	 * Deshalb: bebuchte Konten und Konten mit Unterkonten werden nicht
	 * gelöscht. Wer sie loswerden will, setzt sie inaktiv (active = false) –
	 * dann verschwinden sie aus den Auswahllisten, die Historie bleibt aber
	 * vollständig. Reine Verweise ohne Buchungswert (Regeln, Planwerte) werden
	 * mitgelöscht.
	 *
	 * @throws \InvalidArgumentException wenn das Konto noch in Verwendung ist
	 */
	public function delete(int $id, string $userId): void {
		$this->transaction->run(function () use ($id, $userId): void {
			$account = $this->mapper->find($id, $userId);

			$bookings = $this->lineMapper->countByAccount($userId, $id);
			if ($bookings > 0) {
				throw new \InvalidArgumentException(sprintf(
					'Das Konto "%s %s" ist in %d Buchungszeile%s verwendet und kann nicht gelöscht werden. '
					. 'Setze es stattdessen auf „inaktiv" – dann taucht es nicht mehr in den Auswahllisten auf, '
					. 'die bisherigen Buchungen bleiben aber erhalten.',
					$account->getNumber(),
					$account->getName(),
					$bookings,
					$bookings === 1 ? '' : 'n',
				));
			}

			$children = $this->mapper->countChildren($userId, $id);
			if ($children > 0) {
				throw new \InvalidArgumentException(sprintf(
					'Das Konto "%s %s" hat %d Unterkonto%s. Bitte diese zuerst löschen oder umhängen.',
					$account->getNumber(),
					$account->getName(),
					$children,
					$children === 1 ? '' : 'en',
				));
			}

			// Verweise ohne eigenen Buchungswert räumen wir mit ab.
			$this->ruleMapper->deleteByAccount($userId, $id);
			$this->budgetMapper->deleteByAccount($userId, $id);
			$this->mapper->delete($account);
		});
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
				null,
				$def['sphere'] ?? null,
			);
		}
		return $this->mapper->findAll($userId);
	}

	/**
	 * Ordnet mehrere Konten auf einmal einer Sphäre zu (Bulk-Zuordnung für
	 * Bestandsvereine mit vielen Konten).
	 *
	 * @param int[] $accountIds
	 * @return int Anzahl tatsächlich geänderter Konten (fremde/unbekannte IDs werden übersprungen)
	 */
	public function bulkSetSphere(string $userId, array $accountIds, string $sphere): int {
		$validated = $this->validateSphere($sphere);
		$count = 0;
		foreach ($accountIds as $id) {
			try {
				$account = $this->mapper->find((int)$id, $userId);
			} catch (DoesNotExistException) {
				continue;
			}
			$account->setSphere($validated);
			$this->mapper->update($account);
			$count++;
		}
		return $count;
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

	/** '' und null bedeuten beide „nicht zugeordnet" (gespeichert als NULL). */
	private function validateSphere(?string $sphere): ?string {
		if ($sphere === null || $sphere === '') {
			return null;
		}
		if (!in_array($sphere, Account::SPHERES, true)) {
			throw new \InvalidArgumentException('Ungültige Sphäre: ' . $sphere);
		}
		return $sphere;
	}

	/** '' und null bedeuten beide „keine Rücklage" (gespeichert als NULL). */
	private function validateReserveKind(?string $reserveKind): ?string {
		if ($reserveKind === null || $reserveKind === '') {
			return null;
		}
		if (!in_array($reserveKind, Account::RESERVE_KINDS, true)) {
			throw new \InvalidArgumentException('Ungültige Rücklagen-Art: ' . $reserveKind);
		}
		return $reserveKind;
	}
}
