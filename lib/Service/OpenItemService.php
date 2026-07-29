<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Offene-Posten-Verwaltung: schlanke Ad-hoc-Liste unbezahlter Forderungen
 * (siehe OpenItem.php). Zahlungsabgleich ist bewusst manuell (MVP) - der
 * Nutzer verknüpft einen Posten optional mit einer bereits gebuchten
 * Buchung (paidJournalId), kein Auto-Matching gegen Bankbuchungen.
 */
class OpenItemService {

	public function __construct(
		private OpenItemMapper $mapper,
		private JournalMapper $journalMapper,
	) {
	}

	/** @return OpenItem[] */
	public function findAll(): array {
		return $this->mapper->findAll();
	}

	public function find(int $id): OpenItem {
		return $this->mapper->find($id);
	}

	public function countOverdue(): int {
		return $this->mapper->countOverdue();
	}

	public function create(string $debtor, ?string $description, int $amountCents, ?string $dueDate, ?int $accountId): OpenItem {
		$debtor = trim($debtor);
		if ($debtor === '') {
			throw new \InvalidArgumentException('Debitor ist Pflicht.');
		}
		if ($amountCents <= 0) {
			throw new \InvalidArgumentException('Betrag muss größer als 0 sein.');
		}
		$item = new OpenItem();
		$item->setDebtor($debtor);
		$item->setDescription($description !== null && trim($description) !== '' ? trim($description) : null);
		$item->setAmountCents($amountCents);
		$item->setDueDate($dueDate !== null && $dueDate !== '' ? $dueDate : null);
		$item->setStatus('open');
		$item->setAccountId($accountId);
		$item->setCreatedAt((new \DateTime())->format(\DateTime::ATOM));
		return $this->mapper->insert($item);
	}

	/**
	 * @param int|null $journalId optionale Verknüpfung mit der Buchung, die den
	 *        Posten bezahlt hat
	 * @throws \InvalidArgumentException wenn es diese Buchung nicht gibt
	 */
	public function markPaid(int $id, ?int $journalId): OpenItem {
		$item = $this->mapper->find($id);
		if ($journalId !== null) {
			// Ohne Prüfung stünde im Posten eine Buchungsnummer, die ins Leere
			// zeigt - der Beleg für die Zahlung wäre nicht auffindbar.
			try {
				$this->journalMapper->find($journalId, Application::BOOK);
			} catch (DoesNotExistException) {
				throw new \InvalidArgumentException('Die angegebene Buchung existiert nicht.');
			}
		}
		$item->setStatus('paid');
		$item->setPaidJournalId($journalId);
		return $this->mapper->update($item);
	}

	public function cancel(int $id): OpenItem {
		$item = $this->mapper->find($id);
		$item->setStatus('cancelled');
		return $this->mapper->update($item);
	}

	public function reopen(int $id): OpenItem {
		$item = $this->mapper->find($id);
		$item->setStatus('open');
		$item->setPaidJournalId(null);
		return $this->mapper->update($item);
	}

	public function delete(int $id): void {
		$this->mapper->delete($this->mapper->find($id));
	}
}
