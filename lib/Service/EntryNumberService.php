<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\JournalMapper;

/**
 * Vergibt und pflegt die fortlaufenden Buchungsnummern (entry_no).
 *
 * Regel: innerhalb eines Geschäftsjahres sind die Nummern lückenlos 1..N.
 *
 * Das Problem, das diese Klasse löst: wird eine Buchung wieder gelöscht,
 * hinterließ ihre Nummer bisher eine dauerhafte Lücke, die der Kassenbericht
 * anschließend zu Recht als „fehlende Nummern" bemängelte – obwohl gar nichts
 * Verdächtiges passiert war. Statt die Prüfung aufzuweichen (sie ist für die
 * Kassenprüfung wertvoll), wird die Nummerierung nach jedem Löschen wieder
 * geschlossen: {@see renumberPeriod()}.
 *
 * Warum das zulässig ist: solange ein Geschäftsjahr nicht festgeschrieben ist,
 * sind seine Buchungsnummern vorläufig – es darf ohnehin frei gebucht,
 * geändert und gelöscht werden. Endgültig werden sie mit dem Jahresabschluss;
 * ab dann verhindert {@see PeriodService::assertOpen()} jede Änderung, und
 * damit auch jede Nachnummerierung. Deshalb nummeriert
 * {@see PeriodService::close()} unmittelbar vor dem Festschreiben ein
 * letztes Mal durch: was archiviert wird, ist garantiert lückenlos.
 *
 * Das Geschäftsjahr ist seit 0.33.0 eine Perioden-ID, keine Jahreszahl mehr
 * (Issue #8). Daher gibt es die Nachnummerierung in zwei Ausführungen: in
 * bisheriger Reihenfolge ({@see renumberPeriod()}) für den Normalfall, und
 * nach Datum ({@see renumberPeriodByDate()}) für den Fall, dass zwei bisher
 * getrennte Zeiträume zu einem verschmolzen sind.
 */
class EntryNumberService {

	public function __construct(
		private JournalMapper $journalMapper,
	) {
	}

	/**
	 * Nächste freie Buchungsnummer des Geschäftsjahres.
	 *
	 * Gegen zwei gleichzeitige Buchungen, die beide dieselbe Nummer ermitteln,
	 * schützt der Unique-Index (user_id, period_id, entry_no) zusammen mit dem
	 * Wiederholungsversuch in
	 * {@see \OCA\Vereinsbuchhaltung\Db\TransactionRunner::runWithRetry()}.
	 */
	public function next(string $userId, int $periodId): int {
		return $this->journalMapper->getNextEntryNoForPeriod($userId, $periodId);
	}

	/**
	 * Nummeriert ein Geschäftsjahr lückenlos auf 1..N durch und erhält dabei
	 * die bisherige Reihenfolge (bisherige Nummer, dann ID).
	 *
	 * Muss innerhalb einer Transaktion laufen (die Aufrufer sorgen dafür).
	 *
	 * Zur Kollisionsfreiheit: die Zeilen werden aufsteigend nach bisheriger
	 * Nummer abgearbeitet, und die neue Nummer ist nie größer als die alte
	 * (Lücken werden nur geschlossen, nie aufgerissen). Damit ist die
	 * Zielnummer beim Schreiben immer schon frei und der Unique-Index wird
	 * auch zwischendurch nie verletzt. Unveränderte Zeilen werden übersprungen,
	 * im Normalfall (keine Lücke) schreibt die Methode also gar nichts.
	 *
	 * @return int Anzahl tatsächlich umnummerierter Buchungen
	 */
	public function renumberPeriod(string $userId, int $periodId): int {
		if ($periodId <= 0) {
			return 0;
		}
		return $this->apply(self::renumberPlan($this->journalMapper->findEntryNosForPeriod($userId, $periodId)));
	}

	/**
	 * Wie {@see renumberPeriod()}, aber in Datumsreihenfolge.
	 *
	 * Nötig, wenn ein Zeitraum Buchungen aus einem anderen aufgenommen hat –
	 * etwa nach dem Umstellen der Geschäftsjahr-Regel. Dann treffen zwei
	 * Nummernkreise aufeinander, die beide bei 1 begannen; die bisherige
	 * Nummer ordnet nichts mehr sinnvoll, das Buchungsdatum schon.
	 *
	 * Anders als bei {@see renumberPeriod()} kann die neue Nummer hier größer
	 * werden als die alte. Deshalb bekommen die betroffenen Zeilen erst
	 * negative Zwischennummern: sonst liefe die Vergabe mitten im Durchlauf in
	 * den Unique-Index (user_id, period_id, entry_no).
	 *
	 * @return int Anzahl tatsächlich umnummerierter Buchungen
	 */
	public function renumberPeriodByDate(string $userId, int $periodId): int {
		if ($periodId <= 0) {
			return 0;
		}
		$plan = self::renumberPlan($this->journalMapper->findEntryNosForPeriodByDate($userId, $periodId));
		foreach (array_keys($plan) as $id) {
			$this->journalMapper->setEntryNo($id, -$id);
		}
		return $this->apply($plan);
	}

	/**
	 * @param array<int, int> $plan id => neue Nummer
	 * @return int Anzahl geschriebener Zeilen
	 */
	private function apply(array $plan): int {
		foreach ($plan as $id => $newEntryNo) {
			$this->journalMapper->setEntryNo($id, $newEntryNo);
		}
		return count($plan);
	}

	/**
	 * Die reine Rechenvorschrift hinter {@see renumberPeriod()}: welche Buchung
	 * bekommt welche neue Nummer?
	 *
	 * Als eigenständige, seiteneffektfreie Funktion herausgezogen, damit sich
	 * genau diese Logik ohne Datenbank testen lässt (siehe
	 * tests/unit/EntryNumberServiceTest.php).
	 *
	 * @param array<int, array{id:int, entryNo:int}> $rows in der gewünschten Zielreihenfolge
	 * @return array<int, int> id => neue Nummer, nur für tatsächliche Änderungen
	 */
	public static function renumberPlan(array $rows): array {
		$plan = [];
		$target = 1;
		foreach ($rows as $row) {
			if ($row['entryNo'] !== $target) {
				$plan[$row['id']] = $target;
			}
			$target++;
		}
		return $plan;
	}
}
