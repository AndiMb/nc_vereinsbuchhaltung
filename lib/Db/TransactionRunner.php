<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Db;

use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * Klammert mehrstufige Schreibvorgänge in eine Datenbank-Transaktion.
 *
 * Hintergrund: ein Buchungssatz besteht immer aus mindestens drei Statements
 * (Kopf + Soll-Zeile + Haben-Zeile). Ohne Transaktion hinterlässt ein Abbruch
 * dazwischen – Timeout, Verbindungsabriss, Exception – einen Kopf ohne Zeilen
 * oder eine einseitige Buchung. Damit wäre die zentrale Invariante der
 * doppelten Buchführung (Soll = Haben) dauerhaft verletzt, ohne dass es
 * jemandem auffällt.
 *
 * Verschachtelung: die Datenbanken hinter Nextcloud behandeln geschachtelte
 * BEGIN/COMMIT unterschiedlich (SQLite kennt sie gar nicht). Deshalb zählt
 * diese Klasse die Tiefe selbst mit und öffnet/schließt nur auf der äußersten
 * Ebene eine echte Transaktion. Ein Import darf dadurch gefahrlos
 * {@see \OCA\Vereinsbuchhaltung\Service\JournalService::createBooking()}
 * aufrufen, obwohl beide für sich transaktional sind.
 */
class TransactionRunner {

	private int $depth = 0;

	/** @var list<callable():void> Aufgaben, die erst nach dem Commit laufen dürfen */
	private array $afterCommit = [];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** Läuft gerade eine von dieser Klasse geöffnete Transaktion? */
	public function isActive(): bool {
		return $this->depth > 0;
	}

	/**
	 * Verschiebt eine Aufgabe hinter den Commit der äußersten Transaktion.
	 *
	 * Gedacht für Wirkungen außerhalb der Datenbank – konkret das Löschen von
	 * Beleg-Dateien. Ein Rollback macht Datenbankänderungen rückgängig, eine
	 * bereits gelöschte Datei aber nicht: der Buchungssatz wäre wieder da, sein
	 * Beleg jedoch unwiederbringlich weg. Deshalb wird erst gelöscht, wenn
	 * feststeht, dass die Datensätze tatsächlich verschwunden sind.
	 *
	 * Läuft gerade keine Transaktion, wird sofort ausgeführt.
	 *
	 * @param callable():void $fn
	 */
	public function afterCommit(callable $fn): void {
		if ($this->depth === 0) {
			$fn();
			return;
		}
		$this->afterCommit[] = $fn;
	}

	/**
	 * Arbeitet die aufgeschobenen Aufgaben ab. Fehler einzelner Aufgaben dürfen
	 * die bereits committete Transaktion nicht nachträglich als gescheitert
	 * erscheinen lassen – die Daten sind ja korrekt geschrieben.
	 */
	private function runAfterCommit(): void {
		$tasks = $this->afterCommit;
		$this->afterCommit = [];
		foreach ($tasks as $task) {
			try {
				$task();
			} catch (\Throwable) {
				// Verwaiste Datei ist ärgerlich, aber kein Grund, den
				// erfolgreichen Vorgang als Fehler zu melden.
			}
		}
	}

	/**
	 * Führt $fn in einer Transaktion aus und gibt dessen Rückgabewert weiter.
	 * Bei einer Exception wird zurückgerollt und die Exception weitergereicht.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public function run(callable $fn) {
		if ($this->depth > 0) {
			// Bereits in einer Transaktion: nur mitzählen, kein zweites BEGIN.
			$this->depth++;
			try {
				return $fn();
			} finally {
				$this->depth--;
			}
		}

		$this->db->beginTransaction();
		$this->depth = 1;
		try {
			$result = $fn();
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			$this->afterCommit = []; // aufgeschobene Aufgaben verwerfen
			throw $e;
		} finally {
			$this->depth = 0;
		}

		$this->runAfterCommit();
		return $result;
	}

	/**
	 * Wie {@see run()}, wiederholt den Vorgang aber, wenn er an einem
	 * Unique-Index scheitert. Genutzt für die Vergabe der Buchungsnummer: zwei
	 * gleichzeitige Buchungen können dieselbe freie Nummer ermitteln: eine
	 * gewinnt, die andere läuft mit neu ermittelter Nummer erneut.
	 *
	 * Bewusst nur auf der äußersten Ebene sinnvoll – innerhalb einer laufenden
	 * Transaktion ist nach einem Constraint-Fehler auf PostgreSQL ohnehin die
	 * ganze Transaktion verloren. Die Aufrufer prüfen das per {@see isActive()}.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public function runWithRetry(callable $fn, int $maxAttempts = 5) {
		for ($attempt = 1; ; $attempt++) {
			try {
				return $this->run($fn);
			} catch (DbException $e) {
				$isDuplicate = $e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
				if (!$isDuplicate || $attempt >= $maxAttempts) {
					throw $e;
				}
			}
		}
	}
}
