<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetailMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\CostCenterMapper;
use OCA\Vereinsbuchhaltung\Db\DebitBatchMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\DunningNoticeMapper;
use OCA\Vereinsbuchhaltung\Db\IncomingPaymentRejectionMapper;
use OCA\Vereinsbuchhaltung\Db\JournalLineMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\MandateAmendmentMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Db\RuleMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Migration\Version000157Date20261004130000;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\BudgetSnapshotService;
use OCA\Vereinsbuchhaltung\Service\ContributionResetService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\ResetService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportSettingsService;
use OCA\Vereinsbuchhaltung\Service\SepaDebtorAccountService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * „Alle Daten löschen" (Issue #123): der Reset räumt nicht nur Buchungen und
 * offene Posten, sondern alles, was an den Forderungen hängt – Läufe, Posten,
 * Rücklastschriften, Mahnstufen –, und löst die Zeiger der Mandate darauf.
 *
 * Die Mapper sind **echte** Klassen: ihr Query-Builder-Code läuft, nur die
 * Datenbank dahinter schreibt mit (`DELETE <Tabelle>`, `UPDATE <Tabelle> SET
 * …`) statt zu löschen. So prüft der Test, auf welche *Tabellen* der Reset
 * tatsächlich wirkt – ein vergessener Aufruf oder ein Mapper, der die falsche
 * Tabelle trifft, fiele auf. Was die Datenbank daraus macht, bleibt dem Lauf
 * gegen eine echte Instanz vorbehalten (siehe phpunit.xml und Spec 53 der
 * E2E-Suite).
 *
 * Der letzte Test ist das Gegenstück für die Zukunft: eine neue Tabelle in einer
 * Migration lässt ihn rot werden, bis jemand entschieden hat, ob der Reset sie
 * räumt oder sie als Stammdaten stehen bleibt. Genau diese Entscheidung ist bei
 * den SEPA-Detailzeilen (Issue #105) und den Forderungsdaten (dieses Ticket)
 * vergessen worden.
 */
class ResetServiceTest extends TestCase {

	/** Alles, was der Reset aus der Datenbank löscht – je Tabelle genau einmal. */
	private const WIPED_TABLES = [
		'vbh_attachments',
		'vbh_journal_line',
		'vbh_journal',
		'vbh_bank_tx',
		'vbh_bank_tx_sepa_details',
		'vbh_incoming_pay_rejects',
		'vbh_rules',
		'vbh_accounts',
		'vbh_costcenters',
		'vbh_budgets',
		'vbh_budget_snapshots',
		'vbh_budget_snap_items',
		'vbh_returned_debits',
		'vbh_dunning_notices',
		'vbh_debit_items',
		'vbh_debit_batches',
		'vbh_open_items',
		'vbh_periods',
	];

	/**
	 * Stammdaten und Protokolle, die der Reset bewusst stehen lässt (Handbuch
	 * 12.1): Mitglieder, Mandate samt Historie, Beitragsgruppen, Zuweisungen
	 * samt Historie, Rechtstexte, Rollen, Aufgaben (hängen an Mitgliedern) und
	 * das Änderungsprotokoll.
	 */
	private const KEPT_TABLES = [
		'vbh_members',
		'vbh_mandates',
		'vbh_mandate_amendments',
		'vbh_mandate_events',
		'vbh_mandate_legal_text_versions',
		'vbh_mandate_activation_tokens',
		'vbh_contribution_groups',
		'vbh_assignments',
		'vbh_assignment_events',
		'vbh_permissions',
		'vbh_tasks',
		'vbh_audit_log',
	];

	/** @var list<string> Reihenfolge aller Wirkungen des Resets, siehe record() */
	private array $log = [];

	/** @var list<callable():void> */
	private array $afterCommit = [];

	private IDBConnection&MockObject $db;

	/** Das Statement, das gerade aufgebaut wird: Art, Tabelle, gesetzte Spalten */
	private ?string $verb = null;
	private ?string $table = null;
	/** @var list<string> */
	private array $sets = [];

	protected function setUp(): void {
		$this->log = [];
		$this->afterCommit = [];
		$this->verb = null;
		$this->table = null;
		$this->sets = [];
		$this->db = $this->recordingConnection();
	}

	/**
	 * Eine Datenbank, die nichts speichert, sondern jedes ausgeführte
	 * DELETE/UPDATE mit Tabelle (und gesetzten Spalten) ins Log schreibt.
	 */
	private function recordingConnection(): IDBConnection&MockObject {
		$expr = $this->createMock(IExpressionBuilder::class);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		// Der Wert steckt im Platzhalter, damit `set()` ihn später ins Log schreiben kann.
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => 'param:' . ($value === null ? 'NULL' : (string)$value));
		$qb->method('delete')->willReturnCallback(function (string $table) use ($qb) {
			[$this->verb, $this->table, $this->sets] = ['DELETE', $table, []];
			return $qb;
		});
		$qb->method('update')->willReturnCallback(function (string $table) use ($qb) {
			[$this->verb, $this->table, $this->sets] = ['UPDATE', $table, []];
			return $qb;
		});
		$qb->method('set')->willReturnCallback(function (string $column, $value) use ($qb) {
			$this->sets[] = $column . '=' . substr((string)$value, strlen('param:'));
			return $qb;
		});
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('executeStatement')->willReturnCallback(function (): int {
			$this->log[] = $this->verb . ' ' . $this->table . ($this->sets === [] ? '' : ' SET ' . implode(', ', $this->sets));
			return 0;
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	/**
	 * Die Buchungszeilen löscht der Mapper erst, nachdem er die Buchungs-IDs
	 * gelesen hat – das braucht eine echte Datenbank. Hier schreibt deshalb die
	 * Löschmethode selbst mit, unter dem Tabellennamen, den der Mapper kennt.
	 */
	private function journalLines(): JournalLineMapper&MockObject {
		$mapper = $this->getMockBuilder(JournalLineMapper::class)
			->setConstructorArgs([$this->db])
			->onlyMethods(['deleteAllForUser'])
			->getMock();
		$mapper->method('deleteAllForUser')->willReturnCallback(function () use ($mapper): void {
			$this->log[] = 'DELETE ' . $mapper->getTableName();
		});
		return $mapper;
	}

	/**
	 * Ein Dienst, der statt seiner Mapper-Aufrufe die Tabellen ins Log schreibt, die er räumt.
	 *
	 * @template T of object
	 * @param class-string<T> $class
	 * @return T&MockObject
	 */
	private function recordingService(string $class, string $method, string ...$tables) {
		$service = $this->createMock($class);
		$service->method($method)->willReturnCallback(function () use ($tables): void {
			foreach ($tables as $table) {
				$this->log[] = 'DELETE ' . $table;
			}
		});
		return $service;
	}

	/** @param ContributionResetService|null $contribution anderer Forderungs-Anteil, etwa einer, der abbricht */
	private function service(?ContributionResetService $contribution = null): ResetService {
		$storage = $this->createMock(AttachmentStorageService::class);
		$storage->method('deleteAllFiles')->willReturnCallback(function (): void {
			$this->log[] = 'FILES';
		});

		$debtorAccount = $this->createMock(SepaDebtorAccountService::class);
		$debtorAccount->method('setAccountId')->willReturnCallback(function (): void {
			$this->log[] = 'AFTER_COMMIT debtor account';
		});
		$importSettings = $this->createMock(SepaImportSettingsService::class);
		$importSettings->method('forgetAccounts')->willReturnCallback(function (): void {
			$this->log[] = 'AFTER_COMMIT import settings';
		});

		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(function (callable $fn) {
			$this->log[] = 'BEGIN';
			try {
				$result = $fn();
			} catch (\Throwable $e) {
				$this->log[] = 'ROLLBACK';
				$this->afterCommit = [];
				throw $e;
			}
			$this->log[] = 'COMMIT';
			foreach ($this->afterCommit as $task) {
				$task();
			}
			$this->afterCommit = [];
			return $result;
		});
		$transaction->method('afterCommit')->willReturnCallback(function (callable $fn): void {
			$this->afterCommit[] = $fn;
		});

		// Der Dienst liest die Belege vor dem Löschen, um ihre Dateien zu kennen;
		// das Lesen braucht eine echte Datenbank und ist hier nicht der Punkt.
		$attachments = $this->getMockBuilder(AttachmentMapper::class)
			->setConstructorArgs([$this->db])
			->onlyMethods(['findAllForUser'])
			->getMock();
		$attachments->method('findAllForUser')->willReturn([]);

		return new ResetService(
			$transaction,
			$this->journalLines(),
			new JournalMapper($this->db),
			new BankTransactionMapper($this->db),
			new RuleMapper($this->db),
			new AccountMapper($this->db),
			new CostCenterMapper($this->db),
			new BudgetMapper($this->db),
			$this->recordingService(BudgetSnapshotService::class, 'deleteAllForUser', 'vbh_budget_snapshots', 'vbh_budget_snap_items'),
			$attachments,
			$storage,
			$this->recordingService(PeriodService::class, 'deleteAll', 'vbh_periods'),
			new OpenItemMapper($this->db),
			$debtorAccount,
			$importSettings,
			new BankTxSepaDetailMapper($this->db),
			new IncomingPaymentRejectionMapper($this->db),
			$contribution ?? new ContributionResetService(
				new MandateMapper($this->db),
				new MandateAmendmentMapper($this->db),
				new ReturnedDebitMapper($this->db),
				new DunningNoticeMapper($this->db),
				new DebitItemMapper($this->db),
				new DebitBatchMapper($this->db),
			),
		);
	}

	/** @return list<string> Tabellen, auf die ein DELETE lief */
	private function deleted(): array {
		$tables = [];
		foreach ($this->log as $entry) {
			if (str_starts_with($entry, 'DELETE ')) {
				$tables[] = substr($entry, strlen('DELETE '));
			}
		}
		return $tables;
	}

	private function position(string $entry): int {
		$position = array_search($entry, $this->log, true);
		$this->assertNotFalse($position, "Der Reset hat '$entry' nicht ausgeführt. Log: " . implode(', ', $this->log));
		return (int)$position;
	}

	/** Das Herzstück des Tickets: Läufe, Posten, Rücklastschriften und Mahnstufen sind nach dem Reset weg. */
	public function testResetLoeschtForderungsDatenZusammenMitDenBuchungen(): void {
		$this->service()->resetAll('admin');

		$deleted = $this->deleted();
		foreach (['vbh_open_items', 'vbh_debit_batches', 'vbh_debit_items', 'vbh_returned_debits', 'vbh_dunning_notices'] as $table) {
			$this->assertContains($table, $deleted, "Der Reset lässt '$table' stehen – die Zeilen zeigten danach auf gelöschte Forderungen.");
		}
		// Jede Tabelle genau einmal, und nichts, was nicht dazugehört: Stammdaten bleiben.
		$this->assertEqualsCanonicalizing(self::WIPED_TABLES, $deleted);
		$this->assertSame([], array_intersect(self::KEPT_TABLES, $deleted), 'Stammdaten dürfen nicht mitgelöscht werden.');
	}

	/** Mandate und ihre Meldungen bleiben, aber ihre Zeiger auf Rücklastschriften/Posten werden gelöst. */
	public function testZeigerDerMandateAufGeloeschtesWerdenGeloestOhneDieMandateZuLoeschen(): void {
		$this->service()->resetAll('admin');

		$updates = array_values(array_filter($this->log, static fn (string $entry): bool => str_starts_with($entry, 'UPDATE ')));
		$this->assertEqualsCanonicalizing([
			// Die Sperre selbst bleibt, nur der Verweis auf die gelöschte Rücklastschrift fällt weg.
			'UPDATE vbh_mandates SET returned_debit_id=NULL',
			// „übermittelt" gibt es ohne eingereichten Posten nicht mehr.
			'UPDATE vbh_mandate_amendments SET status=open, debit_item_id=NULL',
		], $updates);
		$this->assertNotContains('vbh_mandates', $this->deleted());
		$this->assertNotContains('vbh_mandate_amendments', $this->deleted());
	}

	/**
	 * Verweise zuerst, dann die Zeilen, auf die sie zeigen: Zeiger der Mandate →
	 * Rücklastschriften/Mahnstufen → Posten → Läufe → Forderungen. Ohne
	 * Fremdschlüssel erzwingt das keine Datenbank, die Reihenfolge hält aber
	 * jeden Zwischenstand lesbar, falls ein Statement mittendrin scheitert.
	 */
	public function testVerweiseWerdenVorDenZeilenGeloestAufDieSieZeigen(): void {
		$this->service()->resetAll('admin');

		$this->assertLessThan($this->position('DELETE vbh_returned_debits'), $this->position('UPDATE vbh_mandates SET returned_debit_id=NULL'));
		$this->assertLessThan($this->position('DELETE vbh_debit_items'), $this->position('UPDATE vbh_mandate_amendments SET status=open, debit_item_id=NULL'));
		$this->assertLessThan($this->position('DELETE vbh_debit_items'), $this->position('DELETE vbh_returned_debits'));
		$this->assertLessThan($this->position('DELETE vbh_debit_items'), $this->position('DELETE vbh_dunning_notices'));
		$this->assertLessThan($this->position('DELETE vbh_debit_batches'), $this->position('DELETE vbh_debit_items'));
		$this->assertLessThan($this->position('DELETE vbh_open_items'), $this->position('DELETE vbh_debit_batches'));
		$this->assertLessThan($this->position('DELETE vbh_open_items'), $this->position('DELETE vbh_dunning_notices'));
	}

	/** Alles läuft in einer Transaktion, Dateien und Einstellungen erst nach dem Commit. */
	public function testAllesInEinerTransaktionUndDateienErstNachDemCommit(): void {
		$this->service()->resetAll('admin');

		$begin = $this->position('BEGIN');
		$commit = $this->position('COMMIT');
		foreach ($this->log as $index => $entry) {
			if (str_starts_with($entry, 'DELETE ') || str_starts_with($entry, 'UPDATE ')) {
				$this->assertGreaterThan($begin, $index, "$entry läuft vor der Transaktion");
				$this->assertLessThan($commit, $index, "$entry läuft nach dem Commit");
			}
		}
		$this->assertGreaterThan($commit, $this->position('FILES'));
		$this->assertGreaterThan($commit, $this->position('AFTER_COMMIT debtor account'));
		$this->assertGreaterThan($commit, $this->position('AFTER_COMMIT import settings'));
	}

	/** Bricht ein Teil ab, ist die Transaktion zurückgerollt – und weder Dateien noch Einstellungen sind angefasst. */
	public function testAbbruchRolltZurueckUndLaesstDateienUndEinstellungenStehen(): void {
		// Der letzte Schritt des Forderungs-Anteils bricht ab.
		$broken = $this->getMockBuilder(DebitBatchMapper::class)
			->setConstructorArgs([$this->db])
			->onlyMethods(['deleteAll'])
			->getMock();
		$broken->method('deleteAll')->willThrowException(new \RuntimeException('Datenbank weg'));
		$service = $this->service(new ContributionResetService(
			new MandateMapper($this->db),
			new MandateAmendmentMapper($this->db),
			new ReturnedDebitMapper($this->db),
			new DunningNoticeMapper($this->db),
			new DebitItemMapper($this->db),
			$broken,
		));

		try {
			$service->resetAll('admin');
			$this->fail('Der Abbruch muss den Reset abbrechen.');
		} catch (\RuntimeException $e) {
			$this->assertSame('Datenbank weg', $e->getMessage());
		}

		$this->assertContains('ROLLBACK', $this->log);
		$this->assertNotContains('COMMIT', $this->log);
		$this->assertNotContains('FILES', $this->log);
		$this->assertNotContains('AFTER_COMMIT debtor account', $this->log);
		$this->assertNotContains('AFTER_COMMIT import settings', $this->log);
		$this->assertNotContains('DELETE vbh_open_items', $this->log, 'Die Forderungen fallen erst, wenn alles darunter weg ist.');
	}

	/**
	 * Jede Tabelle, die eine Migration anlegt (und keine spätere löscht), ist
	 * entweder in der Löschliste des Resets oder bewusst als Stammdaten
	 * aufgeführt. Eine neue Tabelle macht diesen Test rot – dann entscheiden:
	 * räumt der Reset sie (ResetService/ContributionResetService, WIPED_TABLES)
	 * oder bleibt sie stehen (KEPT_TABLES, und Handbuch 12.1 nennt sie).
	 */
	public function testJedeTabelleDerMigrationenIstEingeordnet(): void {
		$created = [];
		$dropped = Version000157Date20261004130000::LEGACY_TABLES;
		foreach (glob(__DIR__ . '/../../lib/Migration/Version*.php') ?: [] as $file) {
			$code = (string)file_get_contents($file);
			preg_match_all("/createTable\\('(vbh_[a-z_]+)'\\)/", $code, $c);
			preg_match_all("/dropTable\\('(vbh_[a-z_]+)'\\)/", $code, $d);
			$created = array_merge($created, $c[1]);
			$dropped = array_merge($dropped, $d[1]);
		}
		$live = array_values(array_diff(array_unique($created), $dropped));

		$known = array_merge(self::WIPED_TABLES, self::KEPT_TABLES);
		$this->assertSame([], array_values(array_diff($live, $known)), 'Neue Tabelle(n) ohne Entscheidung für den Reset – siehe Kommentar dieses Tests.');
		$this->assertSame([], array_values(array_diff($known, $live)), 'Die Listen nennen Tabellen, die es nicht mehr gibt.');
		$this->assertSame([], array_values(array_intersect(self::WIPED_TABLES, self::KEPT_TABLES)), 'Eine Tabelle kann nicht zugleich geräumt werden und stehen bleiben.');
	}
}
