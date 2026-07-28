<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Db\YearClose;
use OCA\Vereinsbuchhaltung\Db\YearCloseMapper;
use OCA\Vereinsbuchhaltung\Exception\YearClosedException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Jahresabschluss: abgeschlossene Geschäftsjahre sind festgeschrieben.
 * Jeder Schreibpfad, der Buchungssätze eines Jahres anlegt, ändert oder
 * löscht (inkl. Belege und Zuordnungen), muss vorher assertOpen() rufen.
 */
class YearCloseService {

	/** @var int[]|null Request-Cache der abgeschlossenen Jahre */
	private ?array $closedCache = null;

	public function __construct(
		private YearCloseMapper $mapper,
		private AuditService $audit,
		private EntryNumberService $entryNumbers,
		private TransactionRunner $transaction,
	) {
	}

	/** @return YearClose[] */
	public function all(): array {
		return $this->mapper->findAll();
	}

	/** @return int[] */
	public function closedYears(): array {
		if ($this->closedCache === null) {
			$this->closedCache = array_map(
				static fn (YearClose $c): int => $c->getYear(),
				$this->mapper->findAll(),
			);
		}
		return $this->closedCache;
	}

	public function isClosed(int $year): bool {
		return in_array($year, $this->closedYears(), true);
	}

	/**
	 * @throws YearClosedException wenn das Jahr des Datums abgeschlossen ist
	 */
	public function assertOpen(string $date): void {
		$year = (int)substr($date, 0, 4);
		if ($year > 0 && $this->isClosed($year)) {
			throw new YearClosedException(
				'Das Geschäftsjahr ' . $year . ' ist abgeschlossen. Buchungen, Belege und Zuordnungen dieses Jahres können nicht mehr geändert werden.'
			);
		}
	}

	/**
	 * Schreibt ein Geschäftsjahr fest.
	 *
	 * Unmittelbar davor wird die Buchungsnummerierung des Jahres ein letztes Mal
	 * lückenlos durchnummeriert. Bis hierher sind die Nummern vorläufig (es darf
	 * ja noch gebucht und gelöscht werden); ab der Festschreibung sind sie
	 * unveränderlich – assertOpen() lässt danach keine Änderung mehr zu. So ist
	 * garantiert, dass genau der Stand archiviert wird, den der Kassenbericht
	 * als „Buchungsnummern lückenlos" ausweist.
	 *
	 * Beides zusammen in einer Transaktion: entweder ist das Jahr nummeriert
	 * UND festgeschrieben, oder nichts von beidem.
	 */
	public function close(int $year, string $uid): YearClose {
		return $this->transaction->run(function () use ($year, $uid): YearClose {
			try {
				return $this->mapper->findByYear($year); // bereits abgeschlossen
			} catch (DoesNotExistException) {
			}

			$renumbered = $this->entryNumbers->renumberYear(Application::BOOK, $year);

			$close = new YearClose();
			$close->setYear($year);
			$close->setClosedAt((new \DateTime())->format('Y-m-d H:i:s'));
			$close->setClosedBy($uid);
			$close = $this->mapper->insert($close);
			$this->closedCache = null;
			$this->audit->log('Jahr abgeschlossen', 'year', $year, $renumbered > 0
				? ['nachnummeriert' => $renumbered]
				: []);
			return $close;
		});
	}

	public function reopen(int $year): void {
		try {
			$close = $this->mapper->findByYear($year);
		} catch (DoesNotExistException) {
			return;
		}
		$this->mapper->delete($close);
		$this->closedCache = null;
		$this->audit->log('Jahr wiedereröffnet', 'year', $year);
	}

	/** Beim vollständigen Zurücksetzen aller Daten: Abschluss-Marker mit entfernen. */
	public function deleteAll(): void {
		$this->mapper->deleteAll();
		$this->closedCache = null;
	}
}
