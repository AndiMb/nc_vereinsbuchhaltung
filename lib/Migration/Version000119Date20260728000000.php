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
 * Geschäftsjahr als eigene Spalte in vbh_journal – plus einmalige Reparatur
 * der Buchungsnummern.
 *
 * Warum die Spalte: die Buchungsnummer startet je Geschäftsjahr bei 1. Um sie
 * gegen doppelte Vergabe abzusichern, braucht es einen Unique-Index über
 * (user_id, year, entry_no). Aus der DATE-Spalte lässt sich das Jahr nicht
 * datenbankübergreifend gleich indizieren (YEAR()/EXTRACT()/strftime()), also
 * wird es redundant mitgeführt – gepflegt ausschließlich über
 * Journal::setDateWithYear().
 *
 * Warum die Reparatur: bis hierher hinterließ jede gelöschte Buchung eine
 * dauerhafte Lücke in der Nummerierung, und zwei gleichzeitige Buchungen
 * konnten dieselbe Nummer bekommen. Beides bemängelt der Kassenbericht zu
 * Recht als „fehlende"/„doppelte Nummern". Diese Migration nummeriert die
 * betroffenen Jahre einmalig sauber durch; ab jetzt hält der
 * EntryNumberService die Nummerierung von selbst lückenlos.
 *
 * Bewusste Ausnahme: bereits abgeschlossene (festgeschriebene) Geschäftsjahre
 * werden NICHT angefasst, solange sie nur Lücken haben. Ihre Nummern stehen
 * womöglich schon auf einem ausgedruckten, archivierten Kassenbericht – die
 * dürfen nicht nachträglich abweichen. Nur wenn ein abgeschlossenes Jahr
 * doppelte Nummern enthält, wird es korrigiert: sonst ließe sich der
 * Unique-Index nicht anlegen, und doppelte Nummern sind ohnehin ein Fehler,
 * kein archivierungswürdiger Zustand.
 *
 * Der Index selbst folgt in Version000120 – erst müssen die Daten stimmen.
 */
class Version000119Date20260728000000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('vbh_journal')) {
			$table = $schema->getTable('vbh_journal');
			if (!$table->hasColumn('year')) {
				$table->addColumn('year', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			}
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->backfillYears($output);
		$this->repairEntryNumbers($output);
	}

	/**
	 * Füllt die neue Spalte aus dem vorhandenen Datum.
	 *
	 * Ein UPDATE je Jahr statt je Zeile: das Datum ist als ISO-String
	 * (YYYY-MM-DD) gespeichert, ein Bereichsvergleich ist damit identisch mit
	 * dem chronologischen und funktioniert auf allen drei Datenbanken gleich.
	 */
	private function backfillYears(IOutput $output): void {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->min('date'), 'min_date')
			->selectAlias($qb->func()->max('date'), 'max_date')
			->from('vbh_journal');
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();

		if ($row === false || $row['min_date'] === null) {
			return; // keine Buchungen
		}

		$firstYear = (int)substr((string)$row['min_date'], 0, 4);
		$lastYear = (int)substr((string)$row['max_date'], 0, 4);
		if ($firstYear <= 0 || $lastYear < $firstYear) {
			return;
		}

		$updated = 0;
		for ($year = $firstYear; $year <= $lastYear; $year++) {
			$upd = $this->db->getQueryBuilder();
			$upd->update('vbh_journal')
				->set('year', $upd->createNamedParameter($year, IQueryBuilder::PARAM_INT))
				->where($upd->expr()->gte('date', $upd->createNamedParameter(sprintf('%04d-01-01', $year))))
				->andWhere($upd->expr()->lte('date', $upd->createNamedParameter(sprintf('%04d-12-31', $year))));
			$updated += $upd->executeStatement();
		}
		$output->info(sprintf('Vereinsbuchhaltung: Geschäftsjahr für %d Buchungen gesetzt.', $updated));
	}

	/**
	 * Nummeriert Jahre mit Lücken oder Doppelungen einmalig sauber durch.
	 */
	private function repairEntryNumbers(IOutput $output): void {
		$closedYears = $this->closedYears();
		$repairedYears = 0;
		$repairedRows = 0;

		foreach ($this->yearsByUser() as $userId => $years) {
			foreach ($years as $year) {
				$rows = $this->entryNosFor($userId, $year);
				if ($rows === []) {
					continue;
				}
				$hasDuplicates = $this->hasDuplicates($rows);
				// Festgeschriebene Jahre nur anfassen, wenn es wirklich nötig ist.
				if (in_array($year, $closedYears, true) && !$hasDuplicates) {
					continue;
				}

				$changed = 0;
				$target = 1;
				foreach ($rows as $entry) {
					if ($entry['entryNo'] !== $target) {
						$upd = $this->db->getQueryBuilder();
						$upd->update('vbh_journal')
							->set('entry_no', $upd->createNamedParameter($target, IQueryBuilder::PARAM_INT))
							->where($upd->expr()->eq('id', $upd->createNamedParameter($entry['id'], IQueryBuilder::PARAM_INT)));
						$upd->executeStatement();
						$changed++;
					}
					$target++;
				}
				if ($changed > 0) {
					$repairedYears++;
					$repairedRows += $changed;
				}
			}
		}

		if ($repairedRows > 0) {
			$output->info(sprintf(
				'Vereinsbuchhaltung: Buchungsnummern in %d Geschäftsjahr(en) lückenlos nachnummeriert (%d Buchungen).',
				$repairedYears,
				$repairedRows,
			));
		}
	}

	/** @return int[] */
	private function closedYears(): array {
		$years = [];
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('year')->from('vbh_year_close');
			$res = $qb->executeQuery();
			while (($row = $res->fetch()) !== false) {
				$years[] = (int)$row['year'];
			}
			$res->closeCursor();
		} catch (\Throwable) {
			// Tabelle fehlt (sehr alte Installation) – dann ist nichts abgeschlossen.
		}
		return $years;
	}

	/**
	 * @return array<string, int[]> user_id => Jahre
	 */
	private function yearsByUser(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')
			->addSelect('year')
			->from('vbh_journal');
		$res = $qb->executeQuery();
		$out = [];
		while (($row = $res->fetch()) !== false) {
			$year = (int)$row['year'];
			if ($year > 0) {
				$out[(string)$row['user_id']][$year] = $year;
			}
		}
		$res->closeCursor();
		return array_map('array_values', $out);
	}

	/**
	 * @return array<int, array{id:int, entryNo:int}> aufsteigend nach bisheriger Nummer
	 */
	private function entryNosFor(string $userId, int $year): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'entry_no')
			->from('vbh_journal')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		$res = $qb->executeQuery();
		$rows = [];
		while (($row = $res->fetch()) !== false) {
			$rows[] = ['id' => (int)$row['id'], 'entryNo' => (int)($row['entry_no'] ?? 0)];
		}
		$res->closeCursor();

		usort($rows, static fn (array $a, array $b): int => [$a['entryNo'], $a['id']] <=> [$b['entryNo'], $b['id']]);
		return $rows;
	}

	/**
	 * @param array<int, array{id:int, entryNo:int}> $rows
	 */
	private function hasDuplicates(array $rows): bool {
		$seen = [];
		foreach ($rows as $row) {
			if (isset($seen[$row['entryNo']])) {
				return true;
			}
			$seen[$row['entryNo']] = true;
		}
		return false;
	}
}
