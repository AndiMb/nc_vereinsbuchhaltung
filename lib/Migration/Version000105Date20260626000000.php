<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Berechtigungen + Umstellung auf gemeinsamen Datenbestand.
 *
 * - Legt die Tabelle vbh_permissions an.
 * - Macht bisherige Dateneigentümer zu Verwaltern (kein Aussperren).
 * - Setzt alle Datensätze auf den gemeinsamen Schlüssel "__verein__".
 */
class Version000105Date20260626000000 extends SimpleMigrationStep {

	private const BOOK = '__verein__';

	/** Tabellen mit user_id-Spalte, die auf den gemeinsamen Schlüssel umgestellt werden. */
	private const OWNED_TABLES = ['vbh_accounts', 'vbh_bank_tx', 'vbh_journal', 'vbh_imports', 'vbh_rules', 'vbh_costcenters'];

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_permissions')) {
			$table = $schema->createTable('vbh_permissions');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			// user | group
			$table->addColumn('principal_type', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('principal_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			// verwalter | buchhalter | revisor
			$table->addColumn('role', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['principal_type', 'principal_id'], 'vbh_perm_principal');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// bisherige Eigentümer einsammeln (bevor wir sie überschreiben)
		$owners = [];
		foreach (self::OWNED_TABLES as $table) {
			$qb = $this->connection->getQueryBuilder();
			$qb->selectDistinct('user_id')->from($table)
				->where($qb->expr()->neq('user_id', $qb->createNamedParameter(self::BOOK)));
			$res = $qb->executeQuery();
			while (($row = $res->fetch()) !== false) {
				$owners[(string)$row['user_id']] = true;
			}
			$res->closeCursor();
		}

		// Eigentümer zu Verwaltern machen (falls noch keine Rolle vergeben)
		foreach (array_keys($owners) as $uid) {
			if ($uid === '' || $uid === self::BOOK) {
				continue;
			}
			$check = $this->connection->getQueryBuilder();
			$check->select('id')->from('vbh_permissions')
				->where($check->expr()->eq('principal_type', $check->createNamedParameter('user')))
				->andWhere($check->expr()->eq('principal_id', $check->createNamedParameter($uid)));
			$r = $check->executeQuery();
			$exists = $r->fetchOne();
			$r->closeCursor();
			if ($exists === false) {
				$ins = $this->connection->getQueryBuilder();
				$ins->insert('vbh_permissions')->values([
					'principal_type' => $ins->createNamedParameter('user'),
					'principal_id' => $ins->createNamedParameter($uid),
					'role' => $ins->createNamedParameter('verwalter'),
				]);
				$ins->executeStatement();
			}
		}

		// Daten auf gemeinsamen Schlüssel umstellen
		foreach (self::OWNED_TABLES as $table) {
			$upd = $this->connection->getQueryBuilder();
			$upd->update($table)
				->set('user_id', $upd->createNamedParameter(self::BOOK))
				->where($upd->expr()->neq('user_id', $upd->createNamedParameter(self::BOOK)));
			$upd->executeStatement();
		}
	}
}
