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
 * Bestätigung der Kontoverknüpfung durch das Mitglied (#295).
 *
 * Eine Verknüpfung Mitglied ↔ Nextcloud-Konto gilt erst, wenn das Konto einen
 * Einmal-Link bestätigt hat, der an die Mailadresse des Mitglieds ging. Bis
 * dahin ist sie „ausstehend“ und öffnet keinen Self-Service.
 *
 * Bestehende Verknüpfungen gelten als bestätigt: sie wurden unter der alten
 * Regel angelegt, und ohne diese Übernahme verlöre jedes verknüpfte Mitglied
 * mit dem Update seinen Zugang.
 */
class Version000175Date20261108120000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('vbh_members')) {
			return null;
		}
		$table = $schema->getTable('vbh_members');
		$changed = false;
		if (!$table->hasColumn('nc_link_confirmed_at')) {
			$table->addColumn('nc_link_confirmed_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$changed = true;
		}
		if (!$table->hasColumn('nc_link_token_hash')) {
			$table->addColumn('nc_link_token_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$changed = true;
		}
		if (!$table->hasColumn('nc_link_token_expires_at')) {
			$table->addColumn('nc_link_token_expires_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$changed = true;
		}
		if (!$table->hasIndex('vbh_members_link_token')) {
			$table->addIndex(['nc_link_token_hash'], 'vbh_members_link_token');
			$changed = true;
		}
		return $changed ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('vbh_members')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('vbh_members')
			->set('nc_link_confirmed_at', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s'))) // UTC-Zeitstempel (#263)
			->where($qb->expr()->isNotNull('nc_user_id'))
			->andWhere($qb->expr()->isNull('nc_link_confirmed_at'));
		$qb->executeStatement();
	}
}
