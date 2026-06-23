<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fortlaufende Buchungsnummer (entry_no) je Nutzer für Buchungssätze.
 */
class Version000102Date20260623000000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('vbh_journal');
		if (!$table->hasColumn('entry_no')) {
			$table->addColumn('entry_no', Types::INTEGER, ['notnull' => false]);
		}
		if (!$table->hasIndex('vbh_jrn_user_entry')) {
			$table->addIndex(['user_id', 'entry_no'], 'vbh_jrn_user_entry');
		}

		return $schema;
	}

	/**
	 * Vergibt für bestehende Buchungssätze nachträglich fortlaufende Nummern
	 * (je Nutzer, nach Datum/ID geordnet).
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->connection->getQueryBuilder();
		$select->select('id', 'user_id')
			->from('vbh_journal')
			->where($select->expr()->isNull('entry_no'))
			->orderBy('user_id', 'ASC')
			->addOrderBy('date', 'ASC')
			->addOrderBy('id', 'ASC');
		$result = $select->executeQuery();

		// Startwerte je Nutzer = aktuelles Maximum (für bereits nummerierte Zeilen)
		$counters = [];
		while (($row = $result->fetch()) !== false) {
			$user = (string)$row['user_id'];
			if (!isset($counters[$user])) {
				$counters[$user] = $this->currentMax($user);
			}
			$counters[$user]++;

			$update = $this->connection->getQueryBuilder();
			$update->update('vbh_journal')
				->set('entry_no', $update->createNamedParameter($counters[$user], IQueryBuilder::PARAM_INT))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
			$update->executeStatement();
		}
		$result->closeCursor();
	}

	private function currentMax(string $userId): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->selectAlias($qb->func()->max('entry_no'), 'm')
			->from('vbh_journal')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();
		$max = $res->fetchOne();
		$res->closeCursor();
		return (int)$max;
	}
}
