<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Repariert Buchungstexte, Belegnummern und Kontonamen mit kodierten
 * Sonderzeichen ("M&amp;#252;ller" → "Müller", Issue #112). Die Planung steht
 * in {@see EntityRepairPlanner}, hier wird nur gelesen und geschrieben.
 *
 * **Auch abgeschlossene Geschäftsjahre.** Geändert wird nur die Darstellung
 * der Zeichen, nie Betrag, Konto oder Datum. Bliebe ein abgeschlossenes Jahr
 * außen vor, stünden seine Berichte weiter mit "&amp;#252;" da. Nachvollziehbar
 * bleibt jede Änderung über das Protokoll: ein Eintrag je Feld mit dem Text
 * vorher und nachher, Akteur `system`.
 *
 * **Idempotent.** Ein reparierter Text enthält nichts mehr, was die Planung
 * auflösen würde; ein zweiter Lauf ändert und protokolliert nichts.
 *
 * Kein Schema-Eingriff. Bei einer Neuinstallation läuft nur der Schema-Teil
 * der Migrationen, `postSchemaChange()` entfällt – es gibt dann nichts zu
 * reparieren.
 */
class Version000162Date20261024000000 extends SimpleMigrationStep {

	public const AUDIT_ACTION = 'Sonderzeichen repariert';

	private const OBJECT_TYPES = [
		'vbh_journal' => 'journal',
		'vbh_accounts' => 'account',
	];

	/**
	 * Mitgelesene Spalten für das Protokoll, in dessen Schlüsseln
	 * (Protokoll-Ansicht: `#entryNo`, Datum, Konto).
	 */
	private const CONTEXT = [
		'vbh_journal' => ['entry_no' => 'entryNo', 'date' => 'date'],
		'vbh_accounts' => ['number' => 'konto'],
	];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		// Kein Schema-Eingriff, siehe Klassenkommentar.
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('vbh_audit_log')) {
			return;
		}

		$plans = [];
		$rows = [];
		foreach (EntityRepairPlanner::COLUMNS as $table => $columns) {
			if ($schema->hasTable($table)) {
				$candidates = $this->candidates($table, array_keys($columns));
				$plans[$table] = EntityRepairPlanner::plan($candidates, $columns);
				$rows[$table] = array_column($candidates, null, 'id');
			}
		}
		$count = array_sum(array_map('count', $plans));
		if ($count === 0) {
			return;
		}

		$now = new \DateTime();
		$this->db->beginTransaction();
		try {
			foreach ($plans as $table => $changes) {
				foreach ($changes as $change) {
					$this->update($table, $change, $now);
					$this->audit($table, $change, $rows[$table][$change['id']] ?? [], $now);
				}
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$output->info(sprintf('Vereinsbuchhaltung: %d Texte mit kodierten Sonderzeichen repariert.', $count));
	}

	/**
	 * Nur Zeilen, in denen eine Spalte nach Entität aussieht – der Rest bleibt
	 * in der Datenbank.
	 *
	 * @param list<string> $columns
	 * @return list<array<string, mixed>>
	 */
	private function candidates(string $table, array $columns): array {
		$qb = $this->db->getQueryBuilder();
		$conditions = [];
		foreach ($columns as $column) {
			$conditions[] = $qb->expr()->like($column, $qb->createNamedParameter('%&%;%'));
			$conditions[] = $qb->expr()->like($column, $qb->createNamedParameter('%#%;%'));
		}
		$qb->select('id', ...$columns, ...array_keys(self::CONTEXT[$table]))->from($table)
			->where($qb->expr()->orX(...$conditions))
			->orderBy('id');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	/** @param array{id: int, column: string, before: string, after: string} $change */
	private function update(string $table, array $change, \DateTime $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table)
			->set($change['column'], $qb->createNamedParameter($change['after']))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($change['id'], IQueryBuilder::PARAM_INT)));
		if ($table === 'vbh_journal') {
			$qb->set('updated_at', $qb->createNamedParameter($now->format('Y-m-d H:i:s.u')));
		}
		$qb->executeStatement();
	}

	/**
	 * @param array{id: int, column: string, before: string, after: string} $change
	 * @param array<string, mixed> $row
	 */
	private function audit(string $table, array $change, array $row, \DateTime $now): void {
		$details = [];
		foreach (self::CONTEXT[$table] as $column => $key) {
			if (isset($row[$column]) && $row[$column] !== '') {
				$details[$key] = match ($key) {
					'entryNo' => (int)$row[$column],
					// DATE kommt je nach Datenbank mit Uhrzeit zurück.
					'date' => substr((string)$row[$column], 0, 10),
					default => (string)$row[$column],
				};
			}
		}
		$details['feld'] = $change['column'];
		$details['vorher'] = $change['before'];
		$details['nachher'] = $change['after'];

		$qb = $this->db->getQueryBuilder();
		$qb->insert('vbh_audit_log')->values([
			'ts' => $qb->createNamedParameter($now->format('Y-m-d H:i:s')),
			'user_id' => $qb->createNamedParameter('system'),
			'action' => $qb->createNamedParameter(self::AUDIT_ACTION),
			'object_type' => $qb->createNamedParameter(self::OBJECT_TYPES[$table]),
			'object_id' => $qb->createNamedParameter($change['id'], IQueryBuilder::PARAM_INT),
			'details' => $qb->createNamedParameter(json_encode($details, JSON_UNESCAPED_UNICODE)),
		]);
		$qb->executeStatement();
	}
}
