<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Exception\YearClosedException;
use OCA\Vereinsbuchhaltung\Service\Statement\RowNormalizer;
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

	/**
	 * Konto-Felder, die in die Zahlen einer Auswertung eingehen – und damit den
	 * Bericht eines abgeschlossenen Geschäftsjahres nachträglich verändern
	 * würden. Siehe {@see assertEvaluationOpen()}.
	 *
	 *  - type    steuert über Account::isCreditNature() das Vorzeichen und über
	 *            isResultRelevant() die Aufnahme ins Jahresergebnis
	 *  - isBank  verschiebt das Konto über Account::isStockAccount() zwischen
	 *            Vermögensübersicht und Einnahmen-/Ausgaben-Rechnung
	 *  - sphere / reserveKind / costCenterId  bestimmen, in welcher Zeile der
	 *            Sphären-, Rücklagen- bzw. Kostenstellenübersicht das Konto steht
	 *
	 * Nummer, Name, Kategorie, Aktiv-Schalter und Überkonto sind bewusst nicht
	 * dabei: sie ändern nur Beschriftung und Sortierung, keine Beträge.
	 */
	private const EVALUATION_FIELDS = ['type', 'isBank', 'sphere', 'reserveKind', 'costCenterId'];

	public function __construct(
		private AccountMapper $mapper,
		private JournalLineMapper $lineMapper,
		private RuleMapper $ruleMapper,
		private BudgetMapper $budgetMapper,
		private TransactionRunner $transaction,
		private RowNormalizer $normalizer,
		private CostCenterService $costCenters,
		private YearCloseService $yearClose,
		private AuditService $audit,
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

	/**
	 * @param bool $audit Einzeleintrag im Änderungsprotokoll (beim Anlegen des
	 *        Standard-Kontenrahmens abschalten – der protokolliert sich als
	 *        Ganzes, sonst stünden dort 14 einzelne „Konto angelegt")
	 */
	public function create(string $userId, string $number, string $name, string $type, ?string $category, bool $isBank, ?int $parentId = null, ?string $sphere = null, ?string $reserveKind = null, ?string $iban = null, ?int $costCenterId = null, bool $audit = true): Account {
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
		// Eine IBAN ergibt nur an einem Geldkonto Sinn – nur dort werden
		// Bankumsätze zugeordnet.
		$account->setIban($isBank ? $this->validateIban($iban) : null);
		$account->setCostCenterId($this->costCenters->resolveId($userId, $costCenterId));
		if ($parentId !== null && $parentId > 0) {
			// Überkonto muss existieren und demselben Bestand gehören.
			$this->mapper->find($parentId, $userId);
			$account->setParentId($parentId);
		}
		$account = $this->mapper->insert($account);
		if ($audit) {
			$this->audit->log('Konto angelegt', 'account', $account->getId(), [
				'konto' => $account->getNumber() . ' ' . $account->getName(),
				'typ' => $account->getType(),
			]);
		}
		return $account;
	}

	public function update(int $id, string $userId, array $data): Account {
		$account = $this->mapper->find($id, $userId);
		$before = self::evaluationSnapshot($account);
		// Vollständiger Stand für die Protokollentscheidung: das Konto-Formular
		// schickt immer alle Felder, auch wenn niemand etwas geändert hat. Ohne
		// diesen Vergleich stünde nach jedem Öffnen-und-Speichern ein
		// "Konto geändert" im Protokoll – für die Kassenprüfung nur Rauschen.
		$stateBefore = $account->jsonSerialize();
		$labelBefore = $account->getNumber() . ' ' . $account->getName();
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
		if (array_key_exists('iban', $data)) {
			$account->setIban($this->validateIban($data['iban'] !== null ? (string)$data['iban'] : null));
		}
		// Wird das Geldkonto-Kennzeichen entfernt, muss die IBAN mitgehen: an
		// einem Aufwandskonto würde sie nie wieder ausgewertet und bliebe als
		// stiller, irreführender Rest stehen. Steht hinter der isBank-Auswertung,
		// damit der eben gesetzte Wert gilt und nicht der alte.
		if (!$account->getIsBank()) {
			$account->setIban(null);
		}
		if (array_key_exists('costCenterId', $data)) {
			// 0/null = keine Kostenstelle; das Konto-Formular sendet das Feld
			// immer mit, damit sich eine Zuordnung auch wieder lösen lässt.
			$account->setCostCenterId($this->costCenters->resolveId($userId, $data['costCenterId'] !== null ? (int)$data['costCenterId'] : null));
		}
		if (array_key_exists('parentId', $data)) {
			$account->setParentId($this->resolveParent($id, $userId, (int)$data['parentId']));
		}

		// Erst prüfen, dann speichern: die Änderungen stehen bis hierher nur am
		// Objekt, ein Abbruch lässt also nichts Halbes zurück.
		$changed = self::changedEvaluationFields($before, self::evaluationSnapshot($account));
		if ($changed !== []) {
			$this->assertEvaluationOpen($userId, $account->getId(), $labelBefore, $changed);
		}

		$saved = $this->mapper->update($account);
		if ($saved->jsonSerialize() !== $stateBefore) {
			$label = $saved->getNumber() . ' ' . $saved->getName();
			$this->audit->log('Konto geändert', 'account', $saved->getId(), [
				'konto' => $label,
				...($labelBefore !== $label ? ['vorher' => $labelBefore] : []),
				...($changed !== [] ? ['auswertungsrelevant' => implode(', ', array_map(self::fieldLabel(...), $changed))] : []),
			]);
		}
		return $saved;
	}

	/**
	 * Der Stand der auswertungsrelevanten Felder eines Kontos.
	 *
	 * @return array<string, mixed> Feldname => Wert, Schlüssel wie EVALUATION_FIELDS
	 */
	private static function evaluationSnapshot(Account $account): array {
		return [
			'type' => $account->getType(),
			'isBank' => $account->getIsBank(),
			'sphere' => $account->getSphere(),
			'reserveKind' => $account->getReserveKind(),
			'costCenterId' => $account->getCostCenterId(),
		];
	}

	/**
	 * Welche auswertungsrelevanten Felder haben sich geändert? Reine
	 * Rechenvorschrift ohne Datenbank, damit sich die Auswahl prüfen lässt.
	 *
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return string[] Feldnamen aus EVALUATION_FIELDS
	 */
	public static function changedEvaluationFields(array $before, array $after): array {
		$changed = [];
		foreach (self::EVALUATION_FIELDS as $field) {
			if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
				$changed[] = $field;
			}
		}
		return $changed;
	}

	/** Feldname → Bezeichnung für Meldungen und Protokoll. */
	private static function fieldLabel(string $field): string {
		return match ($field) {
			'type' => 'Kontoart',
			'isBank' => 'Geldkonto-Kennzeichen',
			'sphere' => 'Sphäre',
			'reserveKind' => 'Rücklagen-Art',
			'costCenterId' => 'Kostenstelle',
			default => $field,
		};
	}

	/**
	 * Festschreibung an den Konto-Stammdaten.
	 *
	 * Der Jahresabschluss schützt bisher die Buchungssätze eines Jahres – nicht
	 * aber die Konten, aus denen der Bericht gerechnet wird. Ein Wechsel der
	 * Kontoart dreht über Account::isCreditNature() das Vorzeichen, ein Wechsel
	 * des Geldkonto-Kennzeichens verschiebt das Konto über isStockAccount()
	 * zwischen Vermögensübersicht und Einnahmen-/Ausgaben-Rechnung. Beides geht
	 * rückwirkend in den Kassenbericht eines festgeschriebenen Jahres ein: das
	 * archivierte Jahresergebnis änderte sich nachträglich, ohne dass eine
	 * einzige Buchung angefasst wurde.
	 *
	 * Gesperrt wird deshalb nur, wenn das Konto in einem abgeschlossenen Jahr
	 * tatsächlich bebucht ist. Ein unbenutztes oder erst später bebuchtes Konto
	 * bleibt frei änderbar.
	 *
	 * @param string[] $changed Feldnamen aus EVALUATION_FIELDS
	 * @throws YearClosedException wenn das Konto ein abgeschlossenes Jahr berührt
	 */
	private function assertEvaluationOpen(string $userId, int $accountId, string $label, array $changed): void {
		$closed = array_values(array_intersect(
			$this->lineMapper->findYearsForAccount($userId, $accountId),
			$this->yearClose->closedYears(),
		));
		if ($closed === []) {
			return;
		}
		throw new YearClosedException(sprintf(
			'Das Konto "%s" ist in %s bebucht – %s dort abgeschlossen. %s lässt sich deshalb nicht mehr ändern: '
			. 'die Auswertungen dieses Jahres würden sich nachträglich verschieben. '
			. 'Wer die Änderung braucht, eröffnet das Jahr wieder und schließt es danach erneut ab.',
			$label,
			count($closed) === 1 ? $closed[0] : implode(', ', $closed),
			count($closed) === 1 ? 'dieses Geschäftsjahr ist' : 'diese Geschäftsjahre sind',
			count($changed) === 1
				? self::fieldLabel($changed[0])
				: implode(' und ', array_map(self::fieldLabel(...), $changed)),
		));
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

			$this->audit->log('Konto gelöscht', 'account', $id, [
				'konto' => $account->getNumber() . ' ' . $account->getName(),
				'typ' => $account->getType(),
			]);
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
				$def['category'],
				$def['isBank'] ?? false,
				null,
				$def['sphere'] ?? null,
				audit: false,
			);
		}
		$this->audit->log('Standard-Kontenrahmen angelegt', 'account', null, [
			'konten' => count(self::DEFAULTS),
		]);
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
		return $this->transaction->run(function () use ($userId, $accountIds, $validated): int {
			$count = 0;
			foreach ($accountIds as $id) {
				try {
					$account = $this->mapper->find((int)$id, $userId);
				} catch (DoesNotExistException) {
					continue;
				}
				if ($account->getSphere() === $validated) {
					continue; // nichts zu tun – und nichts zu sperren
				}
				// Dieselbe Festschreibungsprüfung wie beim einzelnen Konto: die
				// Sphärenübersicht eines abgeschlossenen Jahres darf sich nicht
				// nachträglich verschieben. Die Transaktion sorgt dafür, dass ein
				// gesperrtes Konto nicht die halbe Zuordnung stehen lässt.
				$this->assertEvaluationOpen($userId, $account->getId(), $account->getNumber() . ' ' . $account->getName(), ['sphere']);
				$account->setSphere($validated);
				$this->mapper->update($account);
				$count++;
			}
			return $count;
		});
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
	 * Ermittelt das Geldkonto, auf das ein importierter Umsatz gehört.
	 *
	 * Führt der Verein mehrere Bankkonten, entscheidet die IBAN: der Umsatz
	 * bringt aus dem Auszug das Konto mit, auf dem er gebucht wurde, und das
	 * wird gegen die am Geldkonto hinterlegte IBAN gehalten.
	 *
	 * Beide Seiten werden vor dem Vergleich normalisiert. Neu importierte
	 * Umsätze und neu gespeicherte IBANs liegen zwar bereits in Normalform vor,
	 * Umsätze aus der Zeit davor aber nicht – die trügen die Schreibweise der
	 * Bank ("DE12 3456 …") und würden sonst nie treffen.
	 *
	 * Ohne Treffer bleibt es beim ersten Bankkonto, wie bisher. Das ist bewusst
	 * kein Fehler: Die CSV mancher Bank führt im Feld „Auftragskonto" nur eine
	 * Kontonummer statt der IBAN, und daran darf die Zuordnung nicht scheitern.
	 * Wer mehrere Konten führt, sollte deshalb CAMT.053 oder MT940 exportieren –
	 * dort steht die IBAN verlässlich.
	 */
	public function resolveBankAccount(string $userId, ?string $ownAccount): Account {
		$wanted = $this->normalizer->normalizeOwnAccount($ownAccount);
		if ($wanted !== null) {
			foreach ($this->mapper->findAll($userId) as $account) {
				if (!$account->getIsBank()) {
					continue;
				}
				$iban = $this->normalizer->normalizeOwnAccount($account->getIban());
				if ($iban !== null && $iban === $wanted) {
					return $account;
				}
			}
		}
		return $this->getDefaultBankAccount($userId);
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
				// Ohne eigenen Protokolleintrag: das Konto entsteht als Nebenwirkung
				// der Eröffnungsbuchung, die sich selbst protokolliert.
				return $this->create($userId, $number, 'Vereinsvermögen (Eröffnung)', 'equity', 'Eigenkapital', false, audit: false);
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

	/**
	 * Prüft und vereinheitlicht die IBAN eines Geldkontos.
	 *
	 * Gespeichert wird in derselben Normalform, die auch der Import benutzt
	 * (Großbuchstaben, ohne Leerzeichen). Nur so trifft ein Vergleich mit dem
	 * Feld „eigenes Konto" einer importierten Bankbuchung – wer „DE12 3456 …"
	 * einträgt und die Bank „DE123456…" liefert, hätte sonst zwei Werte, die
	 * für den Menschen gleich aussehen und für die App nicht.
	 *
	 * Bewusst ohne Prüfsummenrechnung: eine formal gültige, aber fremde IBAN
	 * würde sie ebenso durchlassen, und eine zu strenge Prüfung sperrt am Ende
	 * jemanden mit einem ausländischen Vereinskonto aus.
	 */
	private function validateIban(?string $iban): ?string {
		if ($iban === null) {
			return null;
		}
		$normalized = strtoupper((string)preg_replace('/\s+/', '', $iban));
		if ($normalized === '') {
			return null;
		}
		if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{6,30}$/', $normalized)) {
			throw new \InvalidArgumentException(
				'Das sieht nicht nach einer IBAN aus: ' . $iban
				. ' (erwartet wird z. B. DE12 5001 0517 0648 4898 90).'
			);
		}
		return $normalized;
	}
}
