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
 * Konto-Flag "durchlaufend" (transitory): technische Durchlauf-/Verrechnungs-/
 * Uebertragskonten tragen keinen Bestand ueber den Jahreswechsel. Sie werden
 * dadurch aus Vermoegen, Saldovortrag und Jahresuebergangs-Abgleich einheitlich
 * ausgenommen – statt bisher verstreut per Namensheuristik.
 *
 * Backfill setzt das Flag bei bestehenden Installationen anhand des Kontonamens
 * (durchlauf / verrechnung / uebertrag), damit kein Re-Import noetig ist.
 */
class Version000111Date20260709000000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_accounts')) {
			$table = $schema->getTable('vbh_accounts');
			if (!$table->hasColumn('transitory')) {
				$table->addColumn('transitory', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			}
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();
		// '%bertrag%' erfasst Uebertrag/Übertrag/uebertrag umlautunabhaengig.
		$qb->update('vbh_accounts')
			->set('transitory', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->orX(
				$qb->expr()->like($qb->func()->lower('name'), $qb->createNamedParameter('%durchlauf%')),
				$qb->expr()->like($qb->func()->lower('name'), $qb->createNamedParameter('%verrechnung%')),
				$qb->expr()->like($qb->func()->lower('name'), $qb->createNamedParameter('%bertrag%')),
			));
		$updated = $qb->executeStatement();
		$output->info(sprintf('vbh_accounts: %d Konto(en) als durchlaufend markiert.', $updated));
	}
}
