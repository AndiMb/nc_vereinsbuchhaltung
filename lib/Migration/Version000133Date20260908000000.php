<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Migration;

use Closure;
use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Geschäftsjahre als eigene Tabelle – Schritt 1 von 3 (Issue #8).
 *
 * Bis 0.32.0 war das Geschäftsjahr eine Jahreszahl: eine Spalte `year` in
 * vbh_journal, vbh_budgets und vbh_budget_snapshots, dazu eine Tabelle
 * vbh_year_close für die Festschreibung. Damit ließ sich ein Geschäftsjahr,
 * das vom Kalenderjahr abweicht, nicht ausdrücken – und ein Semester schon
 * gar nicht: zwei Perioden desselben Kalenderjahres hätten dieselbe Zahl
 * getragen, und die Buchungsnummern beider wären im selben Nummernkreis
 * gelandet.
 *
 * An die Stelle der Zahl tritt ein Verweis auf vbh_periods. Dort steht der
 * Zeitraum als Datumspaar, die Bezeichnung als freier Text und die
 * Festschreibung als closed_at/closed_by.
 *
 * Diese Migration legt die Tabelle an, hängt die Verweisspalten an und füllt
 * sie. Die Indizes folgen in Version000134, das Aufräumen der alten Spalten
 * in Version000135 – erst müssen die Daten stimmen (dasselbe Vorgehen wie
 * seinerzeit bei Version000119/000120).
 *
 * Der Bestand wird eins zu eins übernommen: jedes bisherige Kalenderjahr wird
 * eine Periode vom 01.01. bis 31.12. mit der Jahreszahl als Bezeichnung. Nach
 * dem Update sieht und rechnet die App deshalb genau wie vorher; wer nichts
 * umstellt, merkt nichts.
 */
class Version000133Date20260908000000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('vbh_periods')) {
			$table = $schema->createTable('vbh_periods');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 64]);
			// Datumsgrenzen als ISO-String (JJJJ-MM-TT), beide inklusive – wie
			// das Buchungsdatum, mit dem sie verglichen werden. Der
			// lexikografische Vergleich ist damit identisch mit dem
			// chronologischen, auf allen unterstützten Datenbanken gleich.
			$table->addColumn('start_date', Types::STRING, ['notnull' => true, 'length' => 10]);
			$table->addColumn('end_date', Types::STRING, ['notnull' => true, 'length' => 10]);
			$table->addColumn('closed_at', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('closed_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'start_date'], 'vbh_period_user_start');
			$table->addUniqueIndex(['user_id', 'label'], 'vbh_period_user_label');
		}

		foreach (['vbh_journal', 'vbh_budgets', 'vbh_budget_snapshots'] as $name) {
			if (!$schema->hasTable($name)) {
				continue;
			}
			$table = $schema->getTable($name);
			if (!$table->hasColumn('period_id')) {
				$table->addColumn('period_id', Types::BIGINT, ['notnull' => true, 'default' => 0, 'length' => 20]);
			}
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		foreach ($this->booksWithData() as $userId) {
			$this->migrateBook($output, $userId);
		}
		$this->assertComplete($output);
	}

	/**
	 * Jedes Buch, das überhaupt Daten trägt. In aller Regel genau eines
	 * (Application::BOOK); die Schleife kostet nichts und deckt Altbestände ab.
	 *
	 * @return string[]
	 */
	private function booksWithData(): array {
		$users = [];
		foreach (['vbh_journal', 'vbh_budgets', 'vbh_budget_snapshots'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('user_id')->from($table);
			$res = $qb->executeQuery();
			while (($row = $res->fetch()) !== false) {
				$users[(string)$row['user_id']] = true;
			}
			$res->closeCursor();
		}
		return array_keys($users);
	}

	private function migrateBook(IOutput $output, string $userId): void {
		[$firstYear, $lastYear] = $this->yearRange($userId);
		if ($firstYear === null || $lastYear === null) {
			return;
		}

		// Lückenlos anlegen, auch für Jahre ohne Buchungen: der PeriodService
		// setzt voraus, dass zwischen zwei Zeiträumen keine Lücke klafft.
		$closed = $this->closedYears();
		$periodIds = [];
		for ($year = $firstYear; $year <= $lastYear; $year++) {
			$periodIds[$year] = $this->insertPeriod($userId, $year, $closed[$year] ?? null);
		}

		$journalRows = 0;
		foreach ($periodIds as $year => $periodId) {
			$journalRows += $this->updateByDateRange('vbh_journal', $userId, $year, $periodId);
			$this->updateByYear('vbh_budgets', $userId, $year, $periodId);
			$this->updateByYear('vbh_budget_snapshots', $userId, $year, $periodId);
		}

		$output->info(sprintf(
			'Vereinsbuchhaltung: %d Geschäftsjahre (%d–%d) angelegt, %d Buchungen zugeordnet.',
			count($periodIds), $firstYear, $lastYear, $journalRows,
		));
	}

	/**
	 * Der Jahresbereich, den die Periodenkette abdecken muss: alles, wozu es
	 * Buchungen, Planwerte oder Plan-Stände gibt.
	 *
	 * Für die Buchungen zählt das Datum, nicht die alte Jahresspalte – sie
	 * konnte bei sehr alten Beständen noch auf 0 stehen (Version000119 hat sie
	 * nachgetragen, aber verlassen wollen wir uns darauf hier nicht).
	 *
	 * @return array{0:?int, 1:?int}
	 */
	private function yearRange(string $userId): array {
		$first = null;
		$last = null;

		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->min('date'), 'min_date')
			->selectAlias($qb->func()->max('date'), 'max_date')
			->from('vbh_journal')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		if ($row !== false && $row['min_date'] !== null) {
			$first = (int)substr((string)$row['min_date'], 0, 4);
			$last = (int)substr((string)$row['max_date'], 0, 4);
		}

		foreach (['vbh_budgets', 'vbh_budget_snapshots'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectAlias($qb->func()->min('year'), 'min_year')
				->selectAlias($qb->func()->max('year'), 'max_year')
				->from($table)
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			$res = $qb->executeQuery();
			$row = $res->fetch();
			$res->closeCursor();
			if ($row === false || $row['min_year'] === null) {
				continue;
			}
			$min = (int)$row['min_year'];
			$max = (int)$row['max_year'];
			if ($min > 0) {
				$first = $first === null ? $min : min($first, $min);
				$last = $last === null ? $max : max($last, $max);
			}
		}

		// Ein unbrauchbares Datum (Jahr 0 o. ä.) darf nicht tausende Perioden
		// erzeugen; dann lieber gar nichts anlegen und die App die Zeiträume
		// bei Bedarf selbst bilden lassen.
		if ($first === null || $last === null || $first < 1900 || $last > 2200 || $last < $first) {
			return [null, null];
		}
		return [$first, $last];
	}

	/**
	 * Die festgeschriebenen Jahre aus der alten Tabelle.
	 *
	 * vbh_year_close kannte kein user_id – der Abschluss galt global. Da es
	 * genau ein Buch gibt (Application::BOOK), ist die Übernahme eindeutig.
	 *
	 * @return array<int, array{closedAt:string, closedBy:string}>
	 */
	private function closedYears(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('year', 'closed_at', 'closed_by')->from('vbh_year_close');
		$res = $qb->executeQuery();
		$out = [];
		while (($row = $res->fetch()) !== false) {
			$out[(int)$row['year']] = [
				'closedAt' => (string)$row['closed_at'],
				'closedBy' => (string)$row['closed_by'],
			];
		}
		$res->closeCursor();
		return $out;
	}

	/** @param array{closedAt:string, closedBy:string}|null $closed */
	private function insertPeriod(string $userId, int $year, ?array $closed): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('vbh_periods')->values([
			'user_id' => $qb->createNamedParameter($userId),
			'label' => $qb->createNamedParameter((string)$year),
			'start_date' => $qb->createNamedParameter(sprintf('%04d-01-01', $year)),
			'end_date' => $qb->createNamedParameter(sprintf('%04d-12-31', $year)),
			'closed_at' => $qb->createNamedParameter($closed['closedAt'] ?? null),
			'closed_by' => $qb->createNamedParameter($closed['closedBy'] ?? null),
		]);
		$qb->executeStatement();
		return (int)$qb->getLastInsertId();
	}

	/** Buchungen anhand ihres Datums zuordnen – ein UPDATE je Jahr. */
	private function updateByDateRange(string $table, string $userId, int $year, int $periodId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table)
			->set('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gte('date', $qb->createNamedParameter(sprintf('%04d-01-01', $year))))
			->andWhere($qb->expr()->lte('date', $qb->createNamedParameter(sprintf('%04d-12-31', $year))));
		return $qb->executeStatement();
	}

	/** Planwerte und Plan-Stände anhand ihrer bisherigen Jahresspalte zuordnen. */
	private function updateByYear(string $table, string $userId, int $year, int $periodId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table)
			->set('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/**
	 * Bleibt irgendwo period_id = 0 stehen, ist etwas übersehen worden.
	 *
	 * Version000135 entfernt danach die alte Jahresspalte; was jetzt nicht
	 * zugeordnet ist, wäre dann nicht mehr zuzuordnen. Deshalb wird hier laut
	 * abgebrochen, statt still weiterzulaufen – eine Buchung ins falsche
	 * Geschäftsjahr zu schieben wäre für eine Buchhaltung das schlechtere
	 * Ergebnis als ein Update, das stehen bleibt.
	 *
	 * Damit es dabei nicht bleibt, nennt die Meldung die betroffenen Zeilen.
	 * In der Praxis kann das nur ein unmögliches Datum sein (Jahr 0 oder
	 * vierstellig daneben); mit ID und Wert ist es in Minuten zu berichtigen,
	 * danach läuft das Update durch.
	 */
	private function assertComplete(IOutput $output): void {
		foreach (['vbh_journal' => 'date', 'vbh_budgets' => 'year', 'vbh_budget_snapshots' => 'year'] as $table => $column) {
			$samples = $this->unassignedSamples($table, $column);
			if ($samples === []) {
				continue;
			}
			throw new \RuntimeException(sprintf(
				'Vereinsbuchhaltung: In %s ließen sich Zeilen keinem Geschäftsjahr zuordnen; '
				. 'das Update wurde abgebrochen. Betroffen sind (id: %s): %s. '
				. 'Bitte diese Zeilen berichtigen und das Update erneut ausführen.',
				$table,
				$column,
				implode(', ', $samples),
			));
		}
		$output->info('Vereinsbuchhaltung: alle Buchungen und Planwerte einem Geschäftsjahr zugeordnet.');
	}

	/**
	 * Bis zu zehn nicht zugeordnete Zeilen als "id: wert".
	 *
	 * @return string[]
	 */
	private function unassignedSamples(string $table, string $column): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', $column)
			->from($table)
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->setMaxResults(10);
		$res = $qb->executeQuery();
		$samples = [];
		while (($row = $res->fetch()) !== false) {
			$samples[] = $row['id'] . ': ' . (string)$row[$column];
		}
		$res->closeCursor();
		return $samples;
	}
}
