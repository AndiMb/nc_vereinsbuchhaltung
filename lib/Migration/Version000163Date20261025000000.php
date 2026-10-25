<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCA\Vereinsbuchhaltung\Service\FieldCipher;
use OCP\AppFramework\Db\TTransactional;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Verschlüsselt IBANs und Regel-Suchwerte in der Datenbank (Encryption at
 * rest, siehe {@see FieldCipher}).
 *
 * Betroffen sind die Bankverbindungen von Mitgliedern und Zahlern: am Mandat,
 * die alte IBAN eines Amendments, der Schnappschuss im Einzugsposten und die
 * Gegenkonten der Bankumsätze. Dazu die Suchwerte aller Zuordnungsregeln
 * (IBANs und Namen) und die Reste in `vbh_sepa_mandates`, die Version000158
 * nicht übernehmen konnte – die App liest sie nicht mehr, sie liegen aber
 * noch in der Datenbank. Die eigenen IBANs des Vereins (Geldkonten,
 * Gläubiger-IBAN der Läufe) bleiben im Klartext: Sie stehen ohnehin auf jedem
 * Mandatsformular und im Überweisungs-QR-Code.
 *
 * Schema: Das Chiffrat ist länger als der Klartext, die Spalten wachsen
 * deshalb auf 255 Zeichen, der Regel-Suchwert (bis 255 Zeichen Klartext) auf
 * 512.
 *
 * Bestand: Idempotent, verschlüsselte Werte erkennt {@see FieldCipher} am
 * Präfix; ein zweiter Lauf ändert nichts. Bei einer Neuinstallation läuft
 * nur der Schema-Teil, `postSchemaChange()` entfällt – es gibt dann nichts zu
 * verschlüsseln.
 */
class Version000163Date20261025000000 extends SimpleMigrationStep {
	use TTransactional;

	/** @var array<string, array{column: string, length: int}> */
	private const COLUMNS = [
		'vbh_mandates' => ['column' => 'iban', 'length' => 255],
		'vbh_mandate_amendments' => ['column' => 'old_iban', 'length' => 255],
		'vbh_debit_items' => ['column' => 'iban', 'length' => 255],
		'vbh_bank_tx' => ['column' => 'counterparty_iban', 'length' => 255],
		'vbh_rules' => ['column' => 'match_value', 'length' => 512],
		'vbh_sepa_mandates' => ['column' => 'iban', 'length' => 255],
	];

	public function __construct(
		private IDBConnection $db,
		private FieldCipher $cipher,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		foreach (self::COLUMNS as $tableName => ['column' => $column, 'length' => $length]) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}
			$table = $schema->getTable($tableName);
			if ($table->hasColumn($column) && (int)$table->getColumn($column)->getLength() < $length) {
				$table->changeColumn($column, ['length' => $length]);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$count = $this->atomic(function () use ($schema): int {
			$count = 0;
			foreach (self::COLUMNS as $table => ['column' => $column]) {
				if ($schema->hasTable($table) && $schema->getTable($table)->hasColumn($column)) {
					$count += $this->encryptColumn($table, $column);
				}
			}
			return $count;
		}, $this->db);
		if ($count > 0) {
			$output->info(sprintf('Vereinsbuchhaltung: %d Werte (IBANs, Regel-Suchwerte) verschlüsselt.', $count));
		}
	}

	private function encryptColumn(string $table, string $column): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', $column)->from($table)
			->where($qb->expr()->isNotNull($column))
			->orderBy('id');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$count = 0;
		foreach ($rows as $row) {
			$value = (string)$row[$column];
			if ($value === '' || FieldCipher::isEncrypted($value)) {
				continue;
			}
			$update = $this->db->getQueryBuilder();
			$update->update($table)
				->set($column, $update->createNamedParameter($this->cipher->encrypt($value)))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
			$update->executeStatement();
			$count++;
		}
		return $count;
	}
}
