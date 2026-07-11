<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

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

	public function close(int $year, string $uid): YearClose {
		try {
			return $this->mapper->findByYear($year); // bereits abgeschlossen
		} catch (DoesNotExistException) {
		}
		$close = new YearClose();
		$close->setYear($year);
		$close->setClosedAt((new \DateTime())->format('Y-m-d H:i:s'));
		$close->setClosedBy($uid);
		$close = $this->mapper->insert($close);
		$this->closedCache = null;
		$this->audit->log('Jahr abgeschlossen', 'year', $year);
		return $close;
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
