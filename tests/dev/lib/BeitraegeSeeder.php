<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Dev;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\AssignmentEvent;
use OCA\Vereinsbuchhaltung\Db\AssignmentEventMapper;
use OCA\Vereinsbuchhaltung\Db\AssignmentMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCA\Vereinsbuchhaltung\Db\DebitBatchMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersionMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\PermissionMapper;
use OCA\Vereinsbuchhaltung\Service\AssignmentService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\ClaimService;
use OCA\Vereinsbuchhaltung\Service\ClaimStateResolver;
use OCA\Vereinsbuchhaltung\Service\ContributionCycleSettings;
use OCA\Vereinsbuchhaltung\Service\ContributionGroupService;
use OCA\Vereinsbuchhaltung\Service\DebitBatchService;
use OCA\Vereinsbuchhaltung\Service\JournalService;
use OCA\Vereinsbuchhaltung\Service\MandateLegalTextService;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCA\Vereinsbuchhaltung\Service\MemberService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\ProrataCalculator;
use OCA\Vereinsbuchhaltung\Service\Statement\Camt053Parser;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

/**
 * Legt das Testszenario des Beiträge/SEPA-Moduls in einer laufenden
 * Nextcloud an ({@see DevScenario}) – für den manuellen Komplett-Test auf einer
 * Entwicklungsinstanz. Aufgerufen von tests/dev/seed-beitraege.php.
 *
 * **Echte Dienste, wo es geht.** Mitglieder, Mandate, Beitragsgruppen, die
 * Erledigungsvermerke, der Lauf (Freigeben, Einreichen) und die Aufgabenliste
 * laufen über dieselben Dienste wie die Oberfläche – damit entstehen
 * Zustände, Ereignisse und Änderungsprotokoll wie im Betrieb. Direkt über die
 * Mapper geht nur, was die Dienste verweigern, weil es in der Vergangenheit
 * liegt: Zuweisungen mit Beginn vor heute, die Forderungen der Vergangenheit
 * (nach dem Muster von {@see \OCA\Vereinsbuchhaltung\Service\ClaimGenerationService})
 * und die Zeitstempel des Oktoberlaufs. Die Forderungen für November legt der
 * Seeder ebenfalls selbst an statt sie vom Tageslauf erzeugen zu lassen: der
 * Nachzügler-Regel nach würde der Tageslauf sie heute auf den 01.12. schieben,
 * weil die Vorabinfo-Frist zum 01.11. bei einem Vorlauf von 30 Tagen schon
 * angebrochen ist.
 *
 * **Was `--wipe` anfasst:** nur Modul-Daten. Konten, Buchungen, Belege und
 * Geschäftsjahre bleiben. Was der Seeder außerhalb der Modul-Tabellen verändert
 * (Einstellungen, Rollen, Sprachen, Rechtstext-Version, optional eine Buchung
 * für die Anonymisierung), steht samt Ausgangswerten im App-Wert
 * `dev_seed_state`; `--purge` stellt es wieder her.
 */
final class BeitraegeSeeder {

	public const STATE_KEY = 'dev_seed_state';

	/** Beschriftung der optionalen Buchung für die Anonymisierung (Erkennungsmerkmal beim Aufräumen). */
	private const ANONYMIZATION_BOOKING_DESCRIPTION = '[Testdaten] Mitgliedsbeitrag 2013 Hans Becker';
	private const ANONYMIZATION_BOOKING_DATE = '2014-02-10';

	/** Tabellen des Moduls in Löschreihenfolge (Verweise zuerst, Blätter vor Eltern). */
	private const MODULE_TABLES = [
		'vbh_returned_debits',
		'vbh_dunning_notices',
		'vbh_debit_items',
		'vbh_debit_batches',
		'vbh_mandate_activation_tokens',
		'vbh_mandate_amendments',
		'vbh_mandate_events',
		'vbh_mandates',
		'vbh_assignment_events',
		'vbh_assignments',
		'vbh_contribution_groups',
		'vbh_tasks',
		'vbh_members',
	];

	private IConfig $config;
	private IDBConnection $db;
	/** @var list<string> */
	private array $notes = [];

	/** @param \Closure(string): void $out Ausgabe einer Zeile */
	public function __construct(
		private \Closure $out,
	) {
		$this->config = Server::get(IConfig::class);
		$this->db = Server::get(IDBConnection::class);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T
	 */
	private function svc(string $class): object {
		return Server::get($class);
	}

	private function say(string $line = ''): void {
		($this->out)($line);
	}

	// =====================================================================
	// Einstieg
	// =====================================================================

	/**
	 * @param array{wipe:bool, wipeBank:bool, purge:bool, check:bool, anonymizationBooking:bool} $options
	 * @return int Exit-Code
	 */
	public function run(array $options): int {
		$this->actAsAdmin();

		if ($options['purge']) {
			$this->purge();
			return 0;
		}
		if ($options['check']) {
			$this->report();
			return 0;
		}

		$existing = $this->moduleCounts();
		if (array_sum($existing) > 0 && !$options['wipe']) {
			$this->say('Abbruch: Es gibt bereits Modul-Daten (' . $this->describeCounts($existing) . ').');
			$this->say('Mit --wipe werden nur diese Modul-Daten gelöscht (Konten, Buchungen, Belege, Geschäftsjahre bleiben) und das Szenario neu angelegt.');
			return 1;
		}
		if ($options['wipe']) {
			$this->wipe($options['wipeBank']);
		}

		$today = date('Y-m-d');
		if ($today < '2026-10-02' || $today > '2026-10-31') {
			$this->notes[] = 'Das Szenario ist auf Anfang Oktober 2026 zugeschnitten (heute ist ' . $today . '). Fälligkeiten, Vorabinfo und Fristen weichen dann vom Testprotokoll ab.';
		}

		$this->seed($options['anonymizationBooking']);
		$this->report();
		return 0;
	}

	/** Alle Schreibzugriffe laufen als Nextcloud-Administrator – Änderungsprotokoll und Ereignisse nennen ihn, die Dienste sprechen Deutsch. */
	private function actAsAdmin(): void {
		$admin = Server::get(IUserManager::class)->get('admin');
		if ($admin === null) {
			throw new \RuntimeException('Der Nextcloud-Nutzer „admin" existiert nicht.');
		}
		Server::get(IUserSession::class)->setUser($admin);
	}

	// =====================================================================
	// Zustand außerhalb der Modul-Tabellen
	// =====================================================================

	/**
	 * @return array{originals: array<string, ?string>, userLangs: array<string, ?string>, roles: array<string, ?string>, legalTextVersionId: ?int, accountIbanSetOn: ?int, anonymizationBooking: ?array{journalId:int, createdPeriodIds:list<int>}}
	 */
	private function loadState(): array {
		$empty = ['originals' => [], 'userLangs' => [], 'roles' => [], 'legalTextVersionId' => null, 'accountIbanSetOn' => null, 'anonymizationBooking' => null];
		$raw = $this->config->getAppValue(Application::APP_ID, self::STATE_KEY, '');
		if ($raw === '') {
			return $empty;
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? array_replace($empty, $decoded) : $empty;
	}

	/** @param array<string, mixed> $state */
	private function saveState(array $state): void {
		$this->config->setAppValue(Application::APP_ID, self::STATE_KEY, json_encode($state, JSON_THROW_ON_ERROR));
	}

	/**
	 * Setzt einen App-Wert und merkt sich beim ersten Mal den Ausgangswert
	 * (`null` = den Schlüssel gab es nicht).
	 *
	 * @param array<string, mixed> $state
	 */
	private function setSetting(array &$state, string $key, string $value): void {
		if (!array_key_exists($key, $state['originals'])) {
			$exists = in_array($key, $this->config->getAppKeys(Application::APP_ID), true);
			$state['originals'][$key] = $exists ? $this->config->getAppValue(Application::APP_ID, $key, '') : null;
		}
		$this->config->setAppValue(Application::APP_ID, $key, $value);
	}

	// =====================================================================
	// Wipe / Purge
	// =====================================================================

	/** @return array<string, int> */
	private function moduleCounts(): array {
		return [
			'Mitglieder' => count($this->svc(MemberMapper::class)->findAll()),
			'Mandate' => count($this->svc(MandateMapper::class)->findAll()),
			'Beitragsgruppen' => count($this->svc(ContributionGroupMapper::class)->findAll()),
			'Zuweisungen' => count($this->svc(AssignmentMapper::class)->findAll()),
			'Läufe' => count($this->svc(DebitBatchMapper::class)->findAll()),
			'Forderungen' => count($this->svc(OpenItemMapper::class)->findClaims()),
		];
	}

	/** @param array<string, int> $counts */
	private function describeCounts(array $counts): string {
		$parts = [];
		foreach ($counts as $label => $count) {
			if ($count > 0) {
				$parts[] = $count . ' ' . $label;
			}
		}
		return implode(', ', $parts);
	}

	/**
	 * Löscht die Modul-Daten (nicht Konten, Buchungen, Belege, Geschäftsjahre).
	 * Mit `$withBank` zusätzlich die Bankumsätze, die aus der Testdatei
	 * importiert wurden (am Hash erkannt), samt SEPA-Detailzeilen und den
	 * Buchungen, die der Nutzer daraus gemacht hat.
	 */
	public function wipe(bool $withBank): void {
		$this->say('Lösche Modul-Daten …');
		$before = $this->moduleCounts();

		if ($withBank) {
			$this->wipeBankImport();
		} elseif ($this->seedBankTransactionIds() !== []) {
			$this->notes[] = 'Bankumsätze aus der Testdatei sind noch vorhanden und bleiben liegen: sie lassen sich so nicht erneut importieren. Mit --wipe --wipe-bank räumt der Seeder sie samt daraus entstandener Buchungen weg.';
		}

		$this->db->beginTransaction();
		try {
			foreach (self::MODULE_TABLES as $table) {
				$this->db->getQueryBuilder()->delete($table)->executeStatement();
			}
			// Forderungen sind Zeilen der geteilten Tabelle der offenen Posten – freie Posten ohne Mitglied bleiben.
			$qb = $this->db->getQueryBuilder();
			$qb->delete('vbh_open_items')->where($qb->expr()->isNotNull('member_id'))->executeStatement();
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$state = $this->loadState();
		if ($state['legalTextVersionId'] !== null) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('vbh_mandate_legal_text_versions')->where($qb->expr()->eq('id', $qb->createNamedParameter($state['legalTextVersionId'])))->executeStatement();
			$state['legalTextVersionId'] = null;
			$this->saveState($state);
		}
		$this->say('  gelöscht: ' . ($this->describeCounts($before) !== '' ? $this->describeCounts($before) : 'nichts'));
	}

	/** @return list<string> Hashes der Umsätze der Testdatei (Quelle: die Datei selbst, falls lesbar) */
	private function seedBankHashes(): array {
		$file = dirname(__DIR__, 3) . '/docs/testprotokoll/testdaten/bank-oktober-2026.camt053.xml';
		if (!is_file($file)) {
			return [];
		}
		$hashes = [];
		foreach ((new Camt053Parser())->parse((string)file_get_contents($file)) as $row) {
			$hashes[] = (string)$row['hash'];
		}
		return $hashes;
	}

	/** @return list<int> */
	private function seedBankTransactionIds(): array {
		$hashes = $this->seedBankHashes();
		if ($hashes === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('vbh_bank_tx')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(Application::BOOK)))
			->andWhere($qb->expr()->in('hash', $qb->createNamedParameter($hashes, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$ids = [];
		while (($row = $result->fetch()) !== false) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();
		return $ids;
	}

	private function wipeBankImport(): void {
		$ids = $this->seedBankTransactionIds();
		if ($ids === []) {
			$this->say('  Bankumsätze aus der Testdatei: keine vorhanden');
			return;
		}
		$journals = $this->svc(JournalService::class);
		$mapper = $this->svc(BankTransactionMapper::class);
		$removedBookings = 0;
		foreach ($ids as $id) {
			$tx = $mapper->find($id, Application::BOOK);
			if ($tx->getJournalId() !== null) {
				// gibt den Umsatz wieder frei und räumt die Buchungsnummern auf
				$journals->deleteBooking((int)$tx->getJournalId(), Application::BOOK);
				$removedBookings++;
			}
		}
		foreach (['vbh_bank_tx_sepa_details', 'vbh_incoming_pay_rejects'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->in('bank_tx_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('vbh_bank_tx')->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))->executeStatement();
		$this->say('  Bankumsätze aus der Testdatei: ' . count($ids) . ' gelöscht, dazu ' . $removedBookings . ' daraus entstandene Buchung(en)');
	}

	/** Alles zurück in den Zustand vor dem ersten Seeden – soweit der Seeder ihn verändert hat. */
	private function purge(): void {
		$this->say('Räume auf (Modul-Daten, Bankimport der Testdatei, Einstellungen, Rollen, Sprachen) …');
		$this->wipe(true);
		$state = $this->loadState();

		$booking = $state['anonymizationBooking'];
		if ($booking !== null) {
			try {
				$this->svc(JournalService::class)->deleteBooking($booking['journalId'], Application::BOOK);
			} catch (\Throwable $e) {
				$this->say('  Buchung für die Anonymisierung nicht gelöscht: ' . $e->getMessage());
			}
			$periods = $this->svc(PeriodService::class);
			// Entfernen lässt sich nur der erste oder letzte Zeitraum der Kette: von außen nach innen, also vom ältesten an.
			$created = array_values(array_filter(
				$periods->all(Application::BOOK),
				static fn ($period): bool => in_array((int)$period->getId(), $booking['createdPeriodIds'], true),
			));
			usort($created, static fn ($a, $b): int => $a->getStartDate() <=> $b->getStartDate());
			$removed = 0;
			foreach ($created as $period) {
				try {
					$periods->delete(Application::BOOK, (int)$period->getId());
					$removed++;
				} catch (\Throwable $e) {
					$this->say('  Geschäftsjahr ' . $period->getLabel() . ' nicht entfernt: ' . $e->getMessage());
				}
			}
			$this->say('  Buchung und ' . $removed . ' angelegte(s) Geschäftsjahr(e) der Anonymisierungs-Buchung entfernt');
		}

		foreach ($state['originals'] as $key => $value) {
			if ($value === null) {
				$this->config->deleteAppValue(Application::APP_ID, $key);
			} else {
				$this->config->setAppValue(Application::APP_ID, $key, $value);
			}
		}
		$this->say('  Einstellungen zurückgesetzt: ' . implode(', ', array_keys($state['originals'])));

		$permissions = $this->svc(PermissionMapper::class);
		foreach ($state['roles'] as $uid => $previous) {
			foreach ($permissions->findAll() as $permission) {
				if ($permission->getPrincipalType() === 'user' && $permission->getPrincipalId() === $uid) {
					if ($previous === null) {
						$permissions->delete($permission);
					} else {
						$permissions->upsert('user', $uid, $previous);
					}
				}
			}
		}
		foreach ($state['userLangs'] as $uid => $previous) {
			if ($previous === null) {
				$this->config->deleteUserValue($uid, 'core', 'lang');
			} else {
				$this->config->setUserValue($uid, 'core', 'lang', $previous);
			}
		}
		if ($state['accountIbanSetOn'] !== null) {
			$accounts = $this->svc(AccountMapper::class);
			$account = $accounts->find($state['accountIbanSetOn'], Application::BOOK);
			$account->setIban(null);
			$accounts->update($account);
		}
		$this->config->deleteAppValue(Application::APP_ID, self::STATE_KEY);
		$this->say('Fertig. Rollen und Sprachen sind wieder wie vorher.');
	}

	// =====================================================================
	// Seeden
	// =====================================================================

	private function seed(bool $anonymizationBooking): void {
		$state = $this->loadState();
		$state['seededAt'] = date('c');

		$this->say('Lege das Szenario an …');
		[$bank, $income, $feeAccount] = $this->prepareAccounts($state);
		$this->configure($state, $bank, $income, $feeAccount);
		$this->assignRolesAndLanguages($state);
		$legalVersionId = $this->prepareLegalText($state);

		$groups = $this->createGroups();
		$members = $this->createMembers();
		$mandates = $this->createMandates($members, $legalVersionId);
		$assignments = $this->createAssignments($members, $groups);
		$claims = $this->createClaims($members, $assignments, $groups);
		$batch = $this->releaseOctoberRun();
		$this->createNovemberClaims($members, $assignments, $groups, $claims, (int)$income->getId());
		$this->finishMembers($members, $mandates, $assignments);

		if ($anonymizationBooking || $this->anonymizationBookingExists($state['anonymizationBooking'])) {
			$state['anonymizationBooking'] = $this->createAnonymizationBooking($members[1010], $claims, (int)$bank->getId(), (int)$income->getId(), $state['anonymizationBooking']);
		} else {
			$this->notes[] = 'Hans Becker (1010) ist noch KEIN Anonymisierungs-Kandidat: die 10-Jahres-Frist läuft ab der letzten verbuchten Zahlung, und ohne Buchung läuft keine Frist. Eine Buchung von 2014 würde die Geschäftsjahre 2014–2025 anlegen – mit --with-anonymization-booking tut der Seeder das (und --purge räumt es wieder weg).';
		}

		$this->saveState($state);
		$this->say('  fertig, Lauf #' . $batch->getId() . ' (Fälligkeit ' . $batch->getDueDate() . ', ' . $batch->getStatus() . ').');
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array{0: Account, 1: Account, 2: Account} Bankkonto, Erlöskonto, Gebührenkonto
	 */
	private function prepareAccounts(array &$state): array {
		$accounts = $this->svc(AccountMapper::class);
		$bank = $accounts->findByNumber(Application::BOOK, '1200');
		$income = $accounts->findByNumber(Application::BOOK, '4000');
		$fee = $accounts->findByNumber(Application::BOOK, '5400');
		if ($bank === null || $income === null || $fee === null) {
			throw new \RuntimeException('Die Konten 1200 (Bank), 4000 (Mitgliedsbeiträge) und 5400 (Bankgebühren) werden gebraucht – bitte zuerst den Kontenrahmen anlegen.');
		}
		if ($bank->getIban() === null || $bank->getIban() === '') {
			// Die IBAN des Vereinskontos steht am Konto (vbh_accounts.iban), nicht in den App-Einstellungen.
			$bank->setIban('DE12500105170648489890');
			$accounts->update($bank);
			$state['accountIbanSetOn'] = (int)$bank->getId();
			$this->notes[] = 'Dem Konto 1200 fehlte die IBAN – DE12 5001 0517 0648 4898 90 wurde eingetragen.';
		}
		return [$bank, $income, $fee];
	}

	/** @param array<string, mixed> $state */
	private function configure(array &$state, Account $bank, Account $income, Account $fee): void {
		$this->setSetting($state, 'sepa_creditor_id', DevScenario::CREDITOR_ID);
		$this->setSetting($state, 'sepa_debtor_account_id', (string)$bank->getId());
		$this->setSetting($state, 'sepa_return_fee_account_id', (string)$fee->getId());
		$this->setSetting($state, 'sepa_contribution_default_account_id', (string)$income->getId());
		$this->setSetting($state, 'self_service_enabled', '1');
		$this->setSetting($state, 'membership_enabled', '1');
		if (trim($this->config->getAppValue(Application::APP_ID, 'club_name', '')) === '') {
			$this->setSetting($state, 'club_name', 'Testverein e.V.');
		}
		// Heute muss die Vorabinfo zum 01.11. fällig sein (27 Tage vorher): Vorlauf 30 Tage, Vorwarnfenster 35.
		$this->setSetting($state, ContributionCycleSettings::SETTING_PRENOTIFICATION_LEAD_DAYS, (string)DevScenario::PRENOTIFICATION_LEAD_DAYS);
		$this->setSetting($state, ContributionCycleSettings::SETTING_WARNING_LEAD_DAYS, (string)DevScenario::WARNING_LEAD_DAYS);
		$this->say('  Einstellungen: Gläubiger-ID ' . DevScenario::CREDITOR_ID . ', einziehendes Konto 1200, Erlöskonto 4000, Gebührenkonto 5400, Self-Service an, Vorabinfo-Vorlauf ' . DevScenario::PRENOTIFICATION_LEAD_DAYS . ' Tage, Vorwarnfenster ' . DevScenario::WARNING_LEAD_DAYS . ' Tage');
	}

	/** @param array<string, mixed> $state */
	private function assignRolesAndLanguages(array &$state): void {
		$users = Server::get(IUserManager::class);
		$permissions = $this->svc(PermissionMapper::class);
		$audit = $this->svc(AuditService::class);
		foreach (DevScenario::ROLES as $uid => $role) {
			if (!$users->userExists($uid)) {
				$this->notes[] = 'Nutzer ' . $uid . ' existiert nicht – Rolle ' . $role . ' nicht vergeben.';
				continue;
			}
			if (!array_key_exists($uid, $state['roles'])) {
				$state['roles'][$uid] = null;
				foreach ($permissions->findAll() as $permission) {
					if ($permission->getPrincipalType() === 'user' && $permission->getPrincipalId() === $uid) {
						$state['roles'][$uid] = $permission->getRole();
					}
				}
			}
			$permissions->upsert('user', $uid, $role);
			$audit->log('Berechtigung gesetzt', 'permission', null, ['typ' => 'user', 'wer' => $uid, 'rolle' => $role]);
		}
		foreach (DevScenario::USER_LANGUAGES as $uid => $lang) {
			if (!$users->userExists($uid)) {
				$this->notes[] = 'Nutzer ' . $uid . ' existiert nicht – Sprache ' . $lang . ' nicht gesetzt.';
				continue;
			}
			if (!array_key_exists($uid, $state['userLangs'])) {
				$has = in_array('lang', $this->config->getUserKeys($uid, 'core'), true);
				$state['userLangs'][$uid] = $has ? $this->config->getUserValue($uid, 'core', 'lang', '') : null;
			}
			$this->config->setUserValue($uid, 'core', 'lang', $lang);
		}
		$this->say('  Rollen: alice = buchhalter, bob = revisor; Sprachen: jane = de (Du), john = de_DE (Sie), user1 = en');
	}

	/** @param array<string, mixed> $state */
	private function prepareLegalText(array &$state): int {
		$legal = $this->svc(MandateLegalTextService::class);
		$existed = $this->svc(MandateLegalTextVersionMapper::class)->findLatest() !== null;
		$version = $legal->current();
		if (!$existed) {
			// Vom Seeder angelegt – nur dann räumt --wipe sie wieder weg.
			$state['legalTextVersionId'] = (int)$version->getId();
		}
		return (int)$version->getId();
	}

	/** @return array<string, ContributionGroup> Name → Gruppe */
	private function createGroups(): array {
		$service = $this->svc(ContributionGroupService::class);
		$groups = [];
		foreach (DevScenario::groups() as $spec) {
			$groups[$spec['name']] = $service->create($spec['name'], $spec['minCents'], $spec['defaultCents'], $spec['intervals'], $spec['defaultInterval'], true);
		}
		$this->say('  ' . count($groups) . ' Beitragsgruppen');
		return $groups;
	}

	/** @return array<int, Member> Mitgliedsnummer → Mitglied */
	private function createMembers(): array {
		$service = $this->svc(MemberService::class);
		$users = Server::get(IUserManager::class);
		$members = [];
		foreach (DevScenario::members() as $spec) {
			$member = $service->create([
				'memberType' => $spec['type'],
				'firstName' => $spec['firstName'],
				'lastName' => $spec['lastName'],
				'organizationName' => $spec['organizationName'],
				'email' => $spec['email'],
				'phone' => $spec['phone'],
				'street' => $spec['street'],
				'postalCode' => $spec['postalCode'],
				'city' => $spec['city'],
				'country' => $spec['street'] !== null ? 'DE' : null,
				'memberNumber' => $spec['number'],
				'joinedAt' => $spec['joinedAt'],
				'internalNote' => $spec['internalNote'],
			]);
			$members[(int)$spec['number']] = $member;
			if ($spec['ncUserId'] !== null) {
				if ($users->userExists($spec['ncUserId'])) {
					$service->link((int)$member->getId(), $spec['ncUserId']);
				} else {
					$this->notes[] = 'Nextcloud-Konto ' . $spec['ncUserId'] . ' existiert nicht – ' . $member->displayName() . ' bleibt unverknüpft.';
				}
			}
		}
		$this->say('  ' . count($members) . ' Mitglieder (1001–' . (1000 + count($members)) . '), drei mit Nextcloud-Konto verknüpft');
		return $members;
	}

	/**
	 * @param array<int, Member> $members
	 * @return array<int, Mandate> Mitgliedsnummer → Mandat
	 */
	private function createMandates(array $members, int $legalVersionId): array {
		$service = $this->svc(MandateService::class);
		$mandates = [];
		foreach (DevScenario::members() as $spec) {
			$mandateSpec = $spec['mandate'];
			if ($mandateSpec === null || $spec['bank'] === null) {
				continue;
			}
			$member = $members[(int)$spec['number']];
			$memberId = (int)$member->getId();
			$iban = (string)DevScenario::iban($spec['bank']);
			$bic = $spec['bank']['bic'];

			if (in_array($mandateSpec['kind'], ['electronic', 'electronic_draft'], true)) {
				$mandate = $service->createElectronic($memberId, $iban, $bic, $mandateSpec['holder']);
				if ($mandateSpec['kind'] === 'electronic') {
					$consentAt = (string)$mandateSpec['signedAt'] . ' 18:42:11';
					$mandate = $service->activateElectronic(
						(int)$mandate->getId(),
						$legalVersionId,
						$consentAt,
						'203.0.113.17',
						'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0 (Testdaten)',
						$spec['ncUserId'] ?? (string)$spec['email'],
					);
				}
			} else {
				$mandate = $service->createPaper($memberId, $iban, $bic, $mandateSpec['holder'], $mandateSpec['signedAt']);
				$mandate = $service->activatePaper((int)$mandate->getId());
				if ($mandateSpec['kind'] === 'revoked') {
					// Vor allen Forderungen: sonst ginge dem Mitglied eine Zahlungsaufforderung nach Widerruf raus.
					$mandate = $service->revoke((int)$mandate->getId());
				}
			}
			if ($mandateSpec['lastPresented'] !== null) {
				$mandate = $service->markPresented((int)$mandate->getId(), $mandateSpec['lastPresented']);
			}
			$mandates[(int)$spec['number']] = $mandate;
		}
		$this->say('  ' . count($mandates) . ' Mandate (aktiv: Papier und elektronisch, 1 Entwurf, 1 widerrufen, 1 vor 34 Monaten zuletzt vorgelegt)');
		return $mandates;
	}

	/**
	 * @param array<int, Member> $members
	 * @param array<string, ContributionGroup> $groups
	 * @return array<int, Assignment> Mitgliedsnummer → Zuweisung
	 */
	private function createAssignments(array $members, array $groups): array {
		$mapper = $this->svc(AssignmentMapper::class);
		$events = $this->svc(AssignmentEventMapper::class);
		$service = $this->svc(AssignmentService::class);
		$assignments = [];
		foreach (DevScenario::members() as $spec) {
			$a = $spec['assignment'];
			if ($a === null) {
				continue;
			}
			$member = $members[(int)$spec['number']];
			$group = $groups[$a['group']];
			$today = date('Y-m-d');

			if ($a['validFrom'] >= $today) {
				// Beginn heute oder später: der Dienst darf, mit allen Prüfungen.
				$assignment = $service->create((int)$member->getId(), (int)$group->getId(), $a['interval'], $a['monthlyCents'], $a['method'], $a['validFrom'], $a['validTo'], $a['minOverrideCents'], $a['overrideReason'], AssignmentEvent::ACTOR_STAFF, 'admin');
			} else {
				// Beginn in der Vergangenheit verweigert der Dienst („nie rückwirkend") – hier geht es um historische Zustände.
				if (!in_array($a['interval'], $group->getAllowedIntervalsArray(), true)) {
					throw new \RuntimeException('Turnus ' . $a['interval'] . ' ist für ' . $group->getName() . ' nicht erlaubt.');
				}
				$assignment = new Assignment();
				$assignment->setMemberId((int)$member->getId());
				$assignment->setGroupId((int)$group->getId());
				$assignment->setIntervalMonths($a['interval']);
				$assignment->setMonthlyAmountCents($a['monthlyCents']);
				$assignment->setMinMonthlyAmountOverrideCents($a['minOverrideCents']);
				$assignment->setOverrideReason($a['overrideReason']);
				$assignment->setPaymentMethod($a['method']);
				$assignment->setValidFrom($a['validFrom']);
				$assignment->setValidTo($a['validTo']);
				$assignment->setCreatedAt(date(\DateTime::ATOM));
				$assignment = $mapper->insert($assignment);
				$this->historicEvent($events, (int)$assignment->getId(), AssignmentEvent::TYPE_ASSIGNMENT_STARTED, AssignmentEvent::ACTOR_STAFF, 'admin', [
					'groupId' => (int)$group->getId(), 'intervalMonths' => $a['interval'], 'monthlyAmountCents' => $a['monthlyCents'], 'validFrom' => $a['validFrom'],
				]);
				if ($a['minOverrideCents'] !== null) {
					$this->historicEvent($events, (int)$assignment->getId(), AssignmentEvent::TYPE_MIN_AMOUNT_OVERRIDE_SET, AssignmentEvent::ACTOR_STAFF, 'admin', [
						'from' => null, 'to' => $a['minOverrideCents'],
					]);
				}
				if ($a['validTo'] !== null) {
					$this->historicEvent($events, (int)$assignment->getId(), AssignmentEvent::TYPE_ASSIGNMENT_ENDED, AssignmentEvent::ACTOR_SYSTEM, null, [
						'validTo' => $a['validTo'], 'reason' => 'member_left',
					]);
				}
			}
			$assignments[(int)$spec['number']] = $assignment;
		}
		$this->say('  ' . count($assignments) . ' Zuweisungen (eine mit individueller Untergrenze: Anna Koch 10,00 €)');
		return $assignments;
	}

	/** @param array<string, mixed> $details */
	private function historicEvent(AssignmentEventMapper $events, int $assignmentId, string $type, string $actorType, ?string $actorUid, array $details): void {
		$event = new AssignmentEvent();
		$event->setAssignmentId($assignmentId);
		$event->setType($type);
		$event->setActorType($actorType);
		$event->setActorUid($actorUid);
		$event->setOnBehalfNote(null);
		$event->setDetailsArray($details);
		$event->setCreatedAt(date(\DateTime::ATOM));
		$events->insert($event);
	}

	/**
	 * Eine Forderung aus einer Zuweisung – das Muster von
	 * {@see \OCA\Vereinsbuchhaltung\Service\ClaimGenerationService::createClaim()}
	 * (Bezeichnung = Gruppenname, anteilig an beiden Enden), nur mit frei
	 * wählbarer Periode und Fälligkeit.
	 */
	private function insertClaim(Member $member, Assignment $assignment, ContributionGroup $group, string $periodStart, string $periodEnd, string $dueDate, ?string $prenotifiedAt): OpenItem {
		$from = max($periodStart, $assignment->getValidFrom());
		$to = ($assignment->getValidTo() !== null && $assignment->getValidTo() < $periodEnd) ? $assignment->getValidTo() : $periodEnd;

		$item = new OpenItem();
		$item->setDebtor($member->displayName());
		$item->setDescription($group->getName());
		$item->setAmountCents(ProrataCalculator::amountCents($assignment->getMonthlyAmountCents(), $from, $to));
		$item->setDueDate($dueDate);
		$item->setStatus('open');
		$item->setMemberId((int)$member->getId());
		$item->setType(OpenItem::TYPE_CONTRIBUTION);
		$item->setAssignmentId((int)$assignment->getId());
		$item->setPeriodStart($from);
		$item->setPeriodEnd($to);
		$item->setPrenotifiedAt($prenotifiedAt);
		$item->setCreatedAt(date(\DateTime::ATOM));
		return $this->svc(OpenItemMapper::class)->insert($item);
	}

	/** Erledigungsvermerk „bezahlt" über den Dienst, danach der historische Zeitpunkt. */
	private function settlePaid(OpenItem $claim, string $note, string $settledAt): OpenItem {
		$claim = $this->svc(ClaimService::class)->settle((int)$claim->getId(), 'paid', $note, 'admin');
		$claim->setSettledAt($settledAt);
		return $this->svc(OpenItemMapper::class)->update($claim);
	}

	/**
	 * Die Forderungen bis einschließlich Oktober.
	 *
	 * @param array<int, Member> $members
	 * @param array<int, Assignment> $assignments
	 * @param array<string, ContributionGroup> $groups
	 * @return array<int, list<OpenItem>> Mitgliedsnummer → Forderungen
	 */
	private function createClaims(array $members, array $assignments, array $groups): array {
		$groupOf = static fn (int $number): ContributionGroup => $groups[self::groupNameOf($number)];
		$claims = [];
		$add = function (int $number, OpenItem $claim) use (&$claims): void {
			$claims[$number][] = $claim;
		};
		$prenotified = DevScenario::RUN_PRENOTIFIED_AT;
		$due = DevScenario::RUN_DUE_DATE;

		// 1001 Jana: der September ist bezahlt (vor dem Oktoberlauf), der Oktober läuft im Lauf mit.
		$september = $this->insertClaim($members[1001], $assignments[1001], $groupOf(1001), '2026-09-01', '2026-09-30', '2026-09-01', '2026-08-18T06:00:00+00:00');
		$add(1001, $this->settlePaid($september, 'Lastschrift zum 01.09.2026 eingezogen (Testdaten)', '2026-09-02T09:00:00+00:00'));

		// Monatsbeiträge im Oktoberlauf (Lastschrift mit aktivem Mandat, Vorabinfo ist längst raus).
		foreach ([1001, 1004, 1008, 1011, 1012, 1013, 1014, 1015, 1016] as $number) {
			$add($number, $this->insertClaim($members[$number], $assignments[$number], $groupOf($number), '2026-10-01', '2026-10-31', $due, $prenotified));
		}
		// 1005 Sophie: Jahresturnus, Eintritt zum 01.10. – anteilig Oktober bis Dezember.
		$add(1005, $this->insertClaim($members[1005], $assignments[1005], $groupOf(1005), '2026-01-01', '2026-12-31', $due, $prenotified));

		// Überweiser: Forderung ohne Vorabinfo, nie in einem Lauf.
		$add(1003, $this->insertClaim($members[1003], $assignments[1003], $groupOf(1003), '2026-10-01', '2026-12-31', $due, null));
		$add(1006, $this->insertClaim($members[1006], $assignments[1006], $groupOf(1006), '2026-10-01', '2026-10-31', $due, null));

		// 1009 Musikhaus: Jahresbeitrag 2026 per Überweisung im Januar bezahlt.
		$jahr = $this->insertClaim($members[1009], $assignments[1009], $groupOf(1009), '2026-01-01', '2026-12-31', '2026-01-01', null);
		$add(1009, $this->settlePaid($jahr, 'Überweisung zum 15.01.2026 eingegangen (Testdaten)', '2026-01-15T09:00:00+00:00'));

		// 1010 Hans: Jahresbeitrag 2013, bezahlt (Erledigungsvermerk; die Buchung dazu nur mit --with-anonymization-booking).
		$alt = $this->insertClaim($members[1010], $assignments[1010], $groupOf(1010), '2013-01-01', '2013-12-31', '2013-01-01', null);
		$add(1010, $this->settlePaid($alt, 'Überweisung 2014 eingegangen (Testdaten)', '2014-02-10T09:00:00+00:00'));

		// Jonas (1002) hat keine Forderung: sein Mandat ist noch ein Entwurf, der Tageslauf meldet ihn als Störfall.

		$this->say('  Forderungen bis Oktober angelegt');
		return $claims;
	}

	/** Die Beitragsgruppe der Zuweisung eines Mitglieds laut Szenario. */
	private static function groupNameOf(int $number): string {
		foreach (DevScenario::members() as $spec) {
			if ((int)$spec['number'] === $number && $spec['assignment'] !== null) {
				return $spec['assignment']['group'];
			}
		}
		throw new \InvalidArgumentException('Mitglied ' . $number . ' hat keine Zuweisung.');
	}

	/**
	 * Oktoberlauf: Freigabe und Einreichung über den echten Dienst, danach
	 * deterministische Kennungen und die historischen Zeitstempel (Freigabe
	 * D−5). So ist die Bankdatei nach jedem erneuten Seeden byte-identisch.
	 */
	private function releaseOctoberRun(): DebitBatch {
		$service = $this->svc(DebitBatchService::class);
		$batch = $service->release(DevScenario::RUN_DUE_DATE);
		$batch = $service->submit((int)$batch->getId());

		$batches = $this->svc(DebitBatchMapper::class);
		$items = $this->svc(DebitItemMapper::class);
		$openItems = $this->svc(OpenItemMapper::class);
		$members = $this->svc(MemberMapper::class);

		$stamp = str_replace(['-', ' ', ':'], ['', '-', ''], DevScenario::RUN_RELEASED_AT);
		$batch->setMsgId('MSG-' . $stamp . '-5EED0001');
		$batch->setCreationDateTime(str_replace(' ', 'T', DevScenario::RUN_RELEASED_AT));
		$batch->setReleasedBy('admin');
		$batch->setReleasedAt(DevScenario::RUN_RELEASED_AT);
		$batch->setSubmittedBy('admin');
		$batch->setSubmittedAt(DevScenario::RUN_SUBMITTED_AT);
		$batch = $batches->update($batch);

		foreach ($items->findByBatch((int)$batch->getId()) as $item) {
			$claim = $openItems->find($item->getOpenItemId());
			$member = $claim->getMemberId() !== null ? $members->findOrNull($claim->getMemberId()) : null;
			$number = $member !== null ? (string)$member->getMemberNumber() : (string)$item->getId();
			// E2E-<Datum>-<Uhrzeit>-<8 Hexstellen>: dasselbe Muster wie SepaReference::endToEnd(), nur mit der Mitgliedsnummer als Kennung.
			$item->setEndToEndId('E2E-' . $stamp . '-5EED' . str_pad($number, 4, '0', STR_PAD_LEFT));
			$item->setCreatedAt(DevScenario::RUN_RELEASED_AT);
			$items->update($item);
		}
		$count = count($items->findByBatch((int)$batch->getId()));
		$this->say('  Oktoberlauf #' . $batch->getId() . ': ' . $count . ' Posten, eingereicht (Fälligkeit ' . $batch->getDueDate() . ')');
		return $batch;
	}

	/**
	 * Die Forderungen zum 01.11. – offen, noch ohne Vorabinfo, in keinem Lauf.
	 *
	 * @param array<int, Member> $members
	 * @param array<int, Assignment> $assignments
	 * @param array<string, ContributionGroup> $groups
	 * @param array<int, list<OpenItem>> $claims
	 */
	private function createNovemberClaims(array $members, array $assignments, array $groups, array &$claims, int $incomeAccountId): void {
		$due = DevScenario::NEXT_DUE_DATE;
		foreach ([1001, 1004, 1006, 1008, 1011, 1012, 1013, 1014, 1015, 1016] as $number) {
			$claims[$number][] = $this->insertClaim($members[$number], $assignments[$number], $groups[self::groupNameOf($number)], '2026-11-01', '2026-11-30', $due, null);
		}
		// 1007 Nadine hat keine Zuweisung, aber eine Einzelforderung: mit der Einreichung des Novemberlaufs beginnt ihre 36-Monats-Frist neu.
		$manual = $this->svc(ClaimService::class)->createManual((int)$members[1007]->getId(), OpenItem::TYPE_CONTRIBUTION, 3000, 'Mitgliedsbeitrag Nachzahlung 2025', $due, $incomeAccountId);
		$claims[1007][] = $manual;
		$this->say('  Forderungen zum ' . $due . ': 11 (zehn aus Zuweisungen, davon eine Überweiserin, plus Nadines Einzelforderung)');
	}

	/**
	 * Austritte: Hans (2013, Zuweisung und Mandat laufen mit aus) und Felix (künftig).
	 *
	 * @param array<int, Member> $members
	 * @param array<int, Mandate> $mandates
	 * @param array<int, Assignment> $assignments
	 */
	private function finishMembers(array $members, array $mandates, array $assignments): void {
		$service = $this->svc(MemberService::class);
		foreach (DevScenario::members() as $spec) {
			if ($spec['leftAt'] !== null) {
				$service->leave((int)$members[(int)$spec['number']]->getId(), $spec['leftAt']);
			}
			if ($spec['mandate'] !== null && $spec['mandate']['kind'] === 'departed') {
				// Was der Tageslauf bei einem ausgetretenen Mitglied ohne offene Forderung tut: das Mandat endet („beendet").
				$this->svc(MandateService::class)->endDueToDeparture((int)$mandates[(int)$spec['number']]->getId());
			}
		}
		unset($assignments);
		$this->say('  Austritte: Hans Becker (2013-12-31, Mandat beendet), Felix Maier (2026-12-31)');
	}

	/**
	 * Optional: eine verbuchte Zahlung von 2014, damit Hans Becker anonymisierungsreif ist.
	 *
	 * @param array<int, list<OpenItem>> $claims
	 * @param array{journalId:int, createdPeriodIds:list<int>}|null $previous
	 * @return array{journalId:int, createdPeriodIds:list<int>}
	 */
	private function createAnonymizationBooking(Member $hans, array $claims, int $bankId, int $incomeId, ?array $previous): array {
		$claim = $claims[1010][0];
		if ($previous !== null && $this->anonymizationBookingExists($previous)) {
			// Eine Buchung aus einem früheren Lauf gibt es noch (--wipe lässt Buchungen stehen): wiederverwenden, nicht verdoppeln.
			$claim->setPaidJournalId($previous['journalId']);
			$this->svc(OpenItemMapper::class)->update($claim);
			$this->say('  Anonymisierungs-Buchung aus dem früheren Lauf wiederverwendet');
			unset($hans);
			return $previous;
		}

		$periods = $this->svc(PeriodService::class);
		$before = array_map(static fn ($p): int => (int)$p->getId(), $periods->all(Application::BOOK));
		$journal = $this->svc(JournalService::class)->createBooking(Application::BOOK, self::ANONYMIZATION_BOOKING_DATE, self::ANONYMIZATION_BOOKING_DESCRIPTION, null, $bankId, $incomeId, 14400);
		$after = array_map(static fn ($p): int => (int)$p->getId(), $this->svc(PeriodService::class)->all(Application::BOOK));
		$created = array_values(array_diff($after, $before));

		$claim->setPaidJournalId((int)$journal->getId());
		$this->svc(OpenItemMapper::class)->update($claim);
		$this->say('  Anonymisierungs-Buchung ' . self::ANONYMIZATION_BOOKING_DATE . ' (144,00 €) angelegt, ' . count($created) . ' Geschäftsjahr(e) dafür materialisiert');
		unset($hans);
		return ['journalId' => (int)$journal->getId(), 'createdPeriodIds' => $created];
	}

	/** @param array{journalId:int, createdPeriodIds:list<int>}|null $booking */
	private function anonymizationBookingExists(?array $booking): bool {
		if ($booking === null) {
			return false;
		}
		try {
			$this->svc(JournalMapper::class)->find($booking['journalId'], Application::BOOK);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	// =====================================================================
	// Bericht
	// =====================================================================

	/** Zusammenfassung des Ist-Zustands samt Aufgabenliste – dieselben Dienste wie der Aufgaben-Flyout. */
	public function report(): void {
		$members = $this->svc(MemberMapper::class)->findAll();
		$mandates = $this->svc(MandateMapper::class)->findAll();
		$claims = $this->svc(OpenItemMapper::class)->findClaims();
		$batches = $this->svc(DebitBatchMapper::class)->findAll();

		$this->say();
		$this->say('== Stand ==');
		$byStatus = [];
		foreach ($mandates as $mandate) {
			$label = $mandate->getStatus() . ($mandate->getEndReason() !== null ? ' (' . $mandate->getEndReason() . ')' : '');
			$byStatus[$label] = ($byStatus[$label] ?? 0) + 1;
		}
		ksort($byStatus);
		$this->say('Mitglieder: ' . count($members) . '   Mandate: ' . count($mandates) . ' [' . implode(', ', array_map(static fn (string $k, int $v): string => $v . ' ' . $k, array_keys($byStatus), $byStatus)) . ']');
		$byState = [];
		foreach ($claims as $claim) {
			$state = ClaimStateResolver::resolveForItem($claim);
			$byState[$state] = ($byState[$state] ?? 0) + 1;
		}
		ksort($byState);
		$this->say('Forderungen: ' . count($claims) . ' [' . implode(', ', array_map(static fn (string $k, int $v): string => $v . ' ' . $k, array_keys($byState), $byState)) . ']');
		$items = $this->svc(DebitItemMapper::class);
		foreach ($batches as $batch) {
			$rows = $items->findByBatch((int)$batch->getId());
			$sum = array_sum(array_map(static fn ($i): int => $i->getAmountCents(), $rows));
			$this->say('Lauf #' . $batch->getId() . ': ' . $batch->getStatus() . ', Fälligkeit ' . $batch->getDueDate() . ', ' . count($rows) . ' Posten, ' . number_format($sum / 100, 2, ',', '.') . ' €');
		}

		$timeline = $this->svc(\OCA\Vereinsbuchhaltung\Service\DebitTimelineService::class)->build();
		$next = $timeline['next'];
		if (is_array($next)) {
			$preview = $next['preview'];
			$this->say('Nächster Lauf (Vorschau): ' . $next['dueDate'] . ', ' . $preview['count'] . ' Forderungen, ' . number_format($preview['sumCents'] / 100, 2, ',', '.') . ' €');
		} else {
			$this->say('Nächster Lauf: keiner');
		}

		$this->say();
		$this->say('== Aufgabenliste (wie im Flyout) ==');
		$tasks = $this->collectTasks();
		foreach ($tasks as $task) {
			$this->say(sprintf('[%s] %s', $task['severity'] === 'handlungsbedarf' ? 'Handlungsbedarf' : 'Hinweis', $task['message']));
		}
		if ($tasks === []) {
			$this->say('(leer)');
		}

		foreach ($this->notes as $note) {
			$this->say();
			$this->say('Hinweis: ' . $note);
		}
	}

	/**
	 * Dieselbe Zusammenstellung wie {@see \OCA\Vereinsbuchhaltung\Controller\TaskController::index()},
	 * ohne den Umweg über HTTP.
	 *
	 * @return list<array{severity:string, message:string}>
	 */
	public function collectTasks(): array {
		$tasks = [];
		foreach ($this->svc(\OCA\Vereinsbuchhaltung\Service\TaskService::class)->findCurrent() as $task) {
			$tasks[] = ['severity' => $task->getSeverity(), 'message' => $task->getMessage()];
		}
		$sources = [
			$this->svc(\OCA\Vereinsbuchhaltung\Service\MandateActivationService::class)->findStaleElectronicDraftTasks(),
			$this->svc(\OCA\Vereinsbuchhaltung\Service\ContributionCycleTaskService::class)->findTasks(null, true),
			$this->svc(\OCA\Vereinsbuchhaltung\Service\MandateTaskService::class)->findTasks(),
			$this->svc(\OCA\Vereinsbuchhaltung\Service\ClaimFollowUpTaskService::class)->findTasks(),
			$this->svc(\OCA\Vereinsbuchhaltung\Service\DebitBatchTaskService::class)->findTasks(),
			$this->svc(\OCA\Vereinsbuchhaltung\Service\DunningTaskService::class)->findBoardEscalationTasks(),
			$this->svc(\OCA\Vereinsbuchhaltung\Service\AnonymizationCandidateService::class)->findTasks(),
		];
		foreach ($sources as $source) {
			foreach ($source as $task) {
				$tasks[] = ['severity' => (string)$task['severity'], 'message' => (string)$task['message']];
			}
		}
		return $tasks;
	}
}
