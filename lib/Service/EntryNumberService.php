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
 * geschlossen: {@see renumberYear()}.
 *
 * Warum das zulässig ist: solange ein Geschäftsjahr nicht festgeschrieben ist,
 * sind seine Buchungsnummern vorläufig – es darf ohnehin frei gebucht,
 * geändert und gelöscht werden. Endgültig werden sie mit dem Jahresabschluss;
 * ab dann verhindert {@see YearCloseService::assertOpen()} jede Änderung, und
 * damit auch jede Nachnummerierung. Deshalb nummeriert
 * {@see YearCloseService::close()} unmittelbar vor dem Festschreiben ein
 * letztes Mal durch: was archiviert wird, ist garantiert lückenlos.
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
	 * schützt der Unique-Index (user_id, year, entry_no) zusammen mit dem
	 * Wiederholungsversuch in
	 * {@see \OCA\Vereinsbuchhaltung\Db\TransactionRunner::runWithRetry()}.
	 */
	public function next(string $userId, int $year): int {
		return $this->journalMapper->getNextEntryNoForYear($userId, $year);
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
	public function renumberYear(string $userId, int $year): int {
		if ($year <= 0) {
			return 0;
		}
		$plan = self::renumberPlan($this->journalMapper->findEntryNosForYear($userId, $year));
		foreach ($plan as $id => $newEntryNo) {
			$this->journalMapper->setEntryNo($id, $newEntryNo);
		}
		return count($plan);
	}

	/**
	 * Die reine Rechenvorschrift hinter {@see renumberYear()}: welche Buchung
	 * bekommt welche neue Nummer?
	 *
	 * Als eigenständige, seiteneffektfreie Funktion herausgezogen, damit sich
	 * genau diese Logik ohne Datenbank testen lässt (siehe
	 * tests/unit/EntryNumberServiceTest.php).
	 *
	 * @param array<int, array{id:int, entryNo:int}> $rows aufsteigend nach bisheriger Nummer
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
