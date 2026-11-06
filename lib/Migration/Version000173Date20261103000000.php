<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCA\Vereinsbuchhaltung\Db\NumberRange;
use OCA\Vereinsbuchhaltung\Service\NumberRangeService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Auslagennummer (`AUSL-2026-0012`) und Vermerk „Überweisung angewiesen“.
 *
 * Die Nummer steht im Verwendungszweck der Überweisung; über sie ordnet die App
 * den Kontoumsatz der Auslage zu. Bestehende Anträge bekommen ihre Nummer
 * nachträglich, in der Reihenfolge der Einreichung und je Jahr von 1 an; der
 * Nummernkreis `auslage` läuft danach dort weiter.
 */
class Version000173Date20261103000000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('vbh_reimbursements')) {
			return null;
		}
		$table = $schema->getTable('vbh_reimbursements');
		$changed = false;
		if (!$table->hasColumn('number')) {
			$table->addColumn('number', Types::STRING, ['notnull' => false, 'length' => 64]);
			$changed = true;
		}
		if (!$table->hasColumn('transferred_at')) {
			$table->addColumn('transferred_at', Types::STRING, ['notnull' => false, 'length' => 19]);
			$changed = true;
		}
		if (!$table->hasIndex('vbh_reimb_number')) {
			$table->addUniqueIndex(['number'], 'vbh_reimb_number');
			$changed = true;
		}
		return $changed ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('vbh_reimbursements') || !$schema->hasTable('vbh_number_ranges')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'submitted_at')->from('vbh_reimbursements')->where($qb->expr()->isNull('number'))->orderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		if ($rows === []) {
			return;
		}

		$pattern = NumberRangeService::DEFAULT_PATTERNS[NumberRange::KIND_REIMBURSEMENT];
		['numbers' => $numbers, 'next' => $next, 'counterKey' => $lastYear] = self::numberRows($rows, $pattern);
		foreach ($numbers as $id => $number) {
			$update = $this->db->getQueryBuilder();
			$update->update('vbh_reimbursements')
				->set('number', $update->createNamedParameter($number))
				->where($update->expr()->eq('id', $update->createNamedParameter($id)));
			$update->executeStatement();
		}

		// Den Nummernkreis hinter die letzte vergebene Nummer setzen.
		$check = $this->db->getQueryBuilder();
		$check->select('id')->from('vbh_number_ranges')->where($check->expr()->eq('kind', $check->createNamedParameter(NumberRange::KIND_REIMBURSEMENT)));
		$existing = $check->executeQuery();
		$id = $existing->fetchOne();
		$existing->closeCursor();

		$write = $this->db->getQueryBuilder();
		if ($id === false) {
			$write->insert('vbh_number_ranges')->values([
				'kind' => $write->createNamedParameter(NumberRange::KIND_REIMBURSEMENT),
				'prefix_pattern' => $write->createNamedParameter($pattern),
				'padding' => $write->createNamedParameter(4),
				'reset_mode' => $write->createNamedParameter(NumberRange::RESET_YEARLY),
				'next_no' => $write->createNamedParameter($next),
				'counter_key' => $write->createNamedParameter($lastYear),
			]);
		} else {
			$write->update('vbh_number_ranges')
				->set('next_no', $write->createNamedParameter($next))
				->set('counter_key', $write->createNamedParameter($lastYear))
				->where($write->expr()->eq('id', $write->createNamedParameter((int)$id)));
		}
		$write->executeStatement();
	}

	/**
	 * Vergibt die Nummern in der Reihenfolge der Zeilen, je Einreichungsjahr von 1 an.
	 *
	 * @param list<array{id: int|string, submitted_at: string}> $rows nach id aufsteigend
	 * @return array{numbers: array<int, string>, next: int, counterKey: string} `next` ist der Stand des Zählers
	 *                                                                           nach der letzten Zeile, `counterKey` deren Jahr
	 */
	public static function numberRows(array $rows, string $pattern): array {
		$counters = [];
		$numbers = [];
		$lastYear = '';
		foreach ($rows as $row) {
			$year = substr((string)$row['submitted_at'], 0, 4);
			$counters[$year] = ($counters[$year] ?? 0) + 1;
			$lastYear = $year;
			$numbers[(int)$row['id']] = NumberRangeService::format($pattern, new \DateTimeImmutable($year . '-01-01'), $counters[$year]);
		}
		return ['numbers' => $numbers, 'next' => ($counters[$lastYear] ?? 0) + 1, 'counterKey' => $lastYear];
	}
}
