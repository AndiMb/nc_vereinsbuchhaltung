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
 * Übernahme der Mandate und Beiträge des bisherigen flachen Moduls in das
 * Mitglieder-Modell (Issue #107, Nachtrag zu Version000157).
 *
 * Version000157 räumte die Alt-Tabellen zunächst samt Inhalt ab. Wer vor dem
 * Update Mandate und Beiträge im alten Modul gepflegt hatte, hätte sie neu
 * erfassen müssen. Dieser Schritt übernimmt sie stattdessen, bevor jemand sie
 * vermisst: Mandate werden zu Mandaten, Beiträge zu Zuweisungen. Die
 * Planung steht in {@see LegacyContributionPlanner}, hier wird nur gelesen
 * und geschrieben.
 *
 * Übernommen heißt verschoben: Die Zeile verlässt die Alt-Tabelle, sobald sie
 * im neuen Modell steht. So gibt es die IBAN eines Mitglieds nur einmal –
 * dort, wo Reset und Anonymisierung sie kennen –, und was in den Alt-Tabellen
 * stehen bleibt, ist genau der Rest, der nicht übernommen werden konnte
 * (nicht teilbare Beträge, doppelte Referenzen, inaktive Beiträge). Die
 * Sammeleinzüge `vbh_sepa_batches`/`vbh_sepa_batch_items` lassen sich nicht
 * sinnvoll in das neue Einzugsmodell überführen und bleiben unangetastet.
 * Tabellen, die zu Beginn leer sind (Neuinstallation, Modul nie benutzt),
 * werden entfernt.
 *
 * Idempotent: Mandate mit schon vorhandener Referenz und Beiträge, aus denen
 * schon eine Zuweisung wurde, werden übersprungen; ein zweiter Lauf legt
 * nichts doppelt an und notiert nichts doppelt.
 */
class Version000158Date20261010000000 extends SimpleMigrationStep {

	/** Tabellen des neuen Modells, ohne die nichts übernommen wird. */
	private const REQUIRED = [
		'vbh_members', 'vbh_mandates', 'vbh_mandate_events',
		'vbh_assignments', 'vbh_assignment_events', 'vbh_contribution_groups', 'vbh_tasks',
	];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		foreach (Version000157Date20261004130000::LEGACY_TABLES as $table) {
			if ($schema->hasTable($table) && $this->isEmpty($table)) {
				$schema->dropTable($table);
				$changed = true;
			}
		}
		return $changed ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		foreach (self::REQUIRED as $table) {
			if (!$schema->hasTable($table)) {
				return;
			}
		}
		$hasMandates = $schema->hasTable('vbh_sepa_mandates') && $schema->getTable('vbh_sepa_mandates')->hasColumn('member_id');
		$hasFees = $schema->hasTable('vbh_membership_fees') && $schema->getTable('vbh_membership_fees')->hasColumn('member_id');
		if (!$hasMandates && !$hasFees) {
			return;
		}

		$members = $this->memberNames();
		$now = (new \DateTime())->format('Y-m-d H:i:s');
		$mandatesDone = 0;
		$mandatesSkipped = 0;
		$feesDone = 0;
		$notTransferred = 0;
		$inactive = 0;

		$activeMandateMembers = $this->membersWithActiveMandate();

		if ($hasMandates) {
			$mandateRows = $this->rows('vbh_sepa_mandates');
			$this->takeOverEmails($members, $mandateRows);
			$plan = LegacyContributionPlanner::planMandates($mandateRows, $members, $this->existingMandateReferences());
			foreach ($plan['inserts'] as $insert) {
				$mandateId = $this->insertMandate($insert['row']);
				$this->insertRow('vbh_mandate_events', [
					'mandate_id' => $mandateId,
					'actor_type' => 'system',
					'message' => $insert['message'],
					'created_at' => $now,
				]);
				$this->deleteRow('vbh_sepa_mandates', $insert['legacyId']);
				$mandatesDone++;
			}
			$mandatesSkipped = count($plan['skipped']);
			$activeMandateMembers += $plan['activeMembers'];
		}

		if ($hasFees) {
			$plan = LegacyContributionPlanner::planFees(
				$this->rows('vbh_membership_fees'),
				$members,
				$this->membersWithAssignment(),
				$activeMandateMembers,
				substr($now, 0, 10),
				$this->transferredFeeIds(),
			);
			$groups = [];
			foreach ($plan['assignments'] as $assignment) {
				$monthly = $assignment['monthlyCents'];
				$groups[$monthly] ??= $this->groupFor($monthly, $now);
				$assignmentId = $this->insertRow('vbh_assignments', [
					'member_id' => $assignment['memberId'],
					'group_id' => $groups[$monthly],
					'interval_months' => $assignment['intervalMonths'],
					'monthly_amount_cents' => $monthly,
					'payment_method' => $assignment['paymentMethod'],
					'valid_from' => $assignment['validFrom'],
					'created_at' => $now,
				]);
				$this->insertRow('vbh_assignment_events', [
					'assignment_id' => $assignmentId,
					'type' => 'assignment_started',
					'actor_type' => 'system',
					'details' => json_encode($assignment['details'], JSON_UNESCAPED_UNICODE),
					'created_at' => $now,
				]);
				$this->deleteRow('vbh_membership_fees', $assignment['legacyId']);
				$feesDone++;
			}
			foreach ($plan['notTransferred'] as $item) {
				if ($this->appendNote($item['memberId'], $item['note'])) {
					$notTransferred++;
				}
			}
			$inactive = $plan['inactive'];
		}

		if ($mandatesDone > 0 || $feesDone > 0) {
			$this->insertTask(sprintf(
				'Aus dem bisherigen Beitragsmodul übernommen – Mandate: %d (als Papier-Mandate mit dem alten Unterschriftsdatum), Beiträge: %d (in Beitragsgruppen „Beitrag … im Monat (übernommen)“). Bitte stichprobenartig prüfen.',
				$mandatesDone,
				$feesDone,
			));
		}
		if ($notTransferred > 0) {
			$this->insertTask(sprintf(
				'Nicht übernommene Beiträge aus dem bisherigen Modul: %d. Ihr Betrag lässt sich nicht in gleiche Monatsbeträge teilen; der alte Betrag steht in der Notiz der jeweiligen Mitgliederakte.',
				$notTransferred,
			), 'hinweis');
		}

		$output->info(sprintf(
			'Vereinsbuchhaltung: aus dem bisherigen Beitragsmodul übernommen – Mandate: %d (%d übersprungen), Beiträge: %d (%d nicht teilbar, %d inaktiv).',
			$mandatesDone,
			$mandatesSkipped,
			$feesDone,
			$notTransferred,
			$inactive,
		));
	}

	/** @return list<array<string,mixed>> */
	private function rows(string $table): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($table)->orderBy('id');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	/** @return array<int,string> Mitglieds-ID => Anzeigename */
	private function memberNames(): array {
		$names = [];
		foreach ($this->rows('vbh_members') as $row) {
			$name = (($row['member_type'] ?? 'person') === 'organisation')
				? trim((string)($row['organization_name'] ?? ''))
				: trim(((string)($row['first_name'] ?? '')) . ' ' . ((string)($row['last_name'] ?? '')));
			$names[(int)$row['id']] = $name;
		}
		return $names;
	}

	/** @return array<string,bool> */
	private function existingMandateReferences(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('mandate_reference')->from('vbh_mandates');
		$result = $qb->executeQuery();
		$references = [];
		while (($ref = $result->fetchOne()) !== false) {
			$references[(string)$ref] = true;
		}
		$result->closeCursor();
		return $references;
	}

	/** @return array<int,true> */
	private function membersWithActiveMandate(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('member_id')->from('vbh_mandates')
			->where($qb->expr()->eq('status', $qb->createNamedParameter('aktiv')));
		$result = $qb->executeQuery();
		$members = [];
		while (($id = $result->fetchOne()) !== false) {
			$members[(int)$id] = true;
		}
		$result->closeCursor();
		return $members;
	}

	/** @return array<int,true> */
	private function membersWithAssignment(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('member_id')->from('vbh_assignments');
		$result = $qb->executeQuery();
		$members = [];
		while (($id = $result->fetchOne()) !== false) {
			$members[(int)$id] = true;
		}
		$result->closeCursor();
		return $members;
	}

	/**
	 * Alt-Beiträge, die schon eine Zuweisung hervorgebracht haben: ihre Alt-ID steht im Startereignis der Zuweisung.
	 * Ohne diese Liste hielte ein zweiter Lauf sie für „Mitglied hat schon eine Zuweisung“ und notierte sie erneut.
	 *
	 * @return array<int,true>
	 */
	private function transferredFeeIds(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('details')->from('vbh_assignment_events')
			->where($qb->expr()->like('details', $qb->createNamedParameter('%beitragsmodul-alt%')));
		$result = $qb->executeQuery();
		$ids = [];
		while (($details = $result->fetchOne()) !== false) {
			$data = json_decode((string)$details, true);
			if (is_array($data) && ($data['source'] ?? null) === 'beitragsmodul-alt' && isset($data['legacyFeeId'])) {
				$ids[(int)$data['legacyFeeId']] = true;
			}
		}
		$result->closeCursor();
		return $ids;
	}

	/** @param array<string,mixed> $row */
	private function insertMandate(array $row): int {
		return $this->insertRow('vbh_mandates', $row);
	}

	/**
	 * @param array<string,mixed> $values Spalte => Wert (null bleibt NULL)
	 */
	private function insertRow(string $table, array $values): int {
		$qb = $this->db->getQueryBuilder();
		$params = [];
		foreach ($values as $column => $value) {
			$params[$column] = $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
		}
		$qb->insert($table)->values($params);
		$qb->executeStatement();
		return (int)$qb->getLastInsertId();
	}

	/** Beitragsgruppe zum Monatsbetrag: vorhandene mit gleichem Namen, sonst neu (Untergrenze = Betrag). */
	private function groupFor(int $monthlyCents, string $now): int {
		$name = LegacyContributionPlanner::groupName($monthlyCents);
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('vbh_contribution_groups')
			->where($qb->expr()->eq('name', $qb->createNamedParameter($name)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();
		if ($id !== false) {
			return (int)$id;
		}
		return $this->insertRow('vbh_contribution_groups', [
			'name' => $name,
			'min_monthly_amount_cents' => $monthlyCents,
			'default_monthly_amount_cents' => $monthlyCents,
			'allowed_intervals' => LegacyContributionPlanner::ALLOWED_INTERVALS,
			'default_interval' => 1,
			'created_at' => $now,
		]);
	}

	private function isEmpty(string $table): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($table)->setMaxResults(1);
		$result = $qb->executeQuery();
		$empty = $result->fetchOne() === false;
		$result->closeCursor();
		return $empty;
	}

	private function deleteRow(string $table, int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Hat das Mitglied keine eigene Adresse, aber das alte Mandat eine, übernimmt die Akte sie.
	 *
	 * @param list<array<string,mixed>> $mandateRows Zeilen aus vbh_sepa_mandates (vor dem Verschieben gelesen)
	 * @param array<int,string> $members
	 */
	private function takeOverEmails(array $members, array $mandateRows): void {
		foreach ($mandateRows as $row) {
			$email = trim((string)($row['email'] ?? ''));
			$memberId = (int)($row['member_id'] ?? 0);
			if ($email === '' || !isset($members[$memberId])) {
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update('vbh_members')
				->set('email', $qb->createNamedParameter($email))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->orX(
					$qb->expr()->isNull('email'),
					$qb->expr()->eq('email', $qb->createNamedParameter('')),
				));
			$qb->executeStatement();
		}
	}

	/** @return bool false, wenn die Zeile schon in der Notiz steht (ein früherer Lauf hat sie geschrieben) */
	private function appendNote(int $memberId, string $line): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('internal_note')->from('vbh_members')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$note = $result->fetchOne();
		$result->closeCursor();
		$note = $note === false ? '' : trim((string)$note);
		if (str_contains($note, $line)) {
			return false;
		}

		$update = $this->db->getQueryBuilder();
		$update->update('vbh_members')
			->set('internal_note', $update->createNamedParameter($note !== '' ? $note . "\n" . $line : $line))
			->where($update->expr()->eq('id', $update->createNamedParameter($memberId, IQueryBuilder::PARAM_INT)));
		$update->executeStatement();
		return true;
	}

	private function insertTask(string $message, string $severity = 'hinweis'): void {
		$this->insertRow('vbh_tasks', [
			'severity' => $severity,
			'message' => $message,
			'object_type' => 'member',
			'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
		]);
	}
}
