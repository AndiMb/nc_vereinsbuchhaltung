<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Exception\ClaimManagedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;

/**
 * Offene-Posten-Verwaltung: schlanke Ad-hoc-Liste unbezahlter Forderungen
 * (siehe OpenItem.php). Zahlungsabgleich ist bewusst manuell (MVP) - der
 * Nutzer verknüpft einen Posten optional mit einer bereits gebuchten
 * Buchung (paidJournalId), kein Auto-Matching gegen Bankbuchungen.
 *
 * Nur für freie Posten: Forderungen des Beitragsmoduls (`memberId`/`type`,
 * siehe {@see OpenItem::belongsToClaimModule()}) liegen in derselben Tabelle,
 * unterliegen aber den Regeln des {@see ClaimService}. Bezahlt, Stornieren,
 * Wieder öffnen und Löschen lehnen sie hier mit einer
 * {@see ClaimManagedException} ab (Issue #121) - sonst ließe sich jede dieser
 * Regeln über die generische Offene-Posten-Sicht umgehen.
 *
 * Warum ablehnen und nicht an den ClaimService weiterreichen: Dem generischen
 * Aufruf fehlt, was der ClaimService verlangt (beim Storno die Begründung,
 * beim Erledigen die Wahl zwischen bezahlt und erlassen samt Notiz), und für
 * Wieder öffnen und Löschen kennt der ClaimService absichtlich nichts - eine
 * erledigte Forderung wird nicht wieder geöffnet (das tut nur eine
 * Rücklastschrift), und eine Forderung wird nie gelöscht.
 */
class OpenItemService {

	public function __construct(
		private OpenItemMapper $mapper,
		private JournalMapper $journalMapper,
		private IL10N $l10n,
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

	/**
	 * Summe der noch OFFENEN Forderungen eines Mitglieds, in Cent (Spec §3.4
	 * Reibungsdialoge Widerruf/Kontoinhaberwechsel: „Endgültigkeit + offene
	 * Summe zeigen", Issue #75). „Offen" ist hier {@see ClaimStateResolver::STATE_OPEN}
	 * - storniert/erledigt zählen bewusst nicht mit, sonst würde die
	 * angezeigte Summe eine Reibung erzeugen, die es fachlich gar nicht mehr
	 * gibt.
	 */
	public function openClaimsTotalCents(int $memberId): int {
		$total = 0;
		foreach ($this->mapper->findByMember($memberId) as $item) {
			if (ClaimStateResolver::resolveForItem($item) === ClaimStateResolver::STATE_OPEN) {
				$total += $item->getAmountCents();
			}
		}
		return $total;
	}

	public function create(string $debtor, ?string $description, int $amountCents, ?string $dueDate, ?int $accountId, ?int $mandateId = null): OpenItem {
		$debtor = trim($debtor);
		if ($debtor === '') {
			throw new \InvalidArgumentException($this->l10n->t('Debitor ist Pflicht.'));
		}
		if ($amountCents <= 0) {
			throw new \InvalidArgumentException($this->l10n->t('Betrag muss größer als 0 sein.'));
		}
		$item = new OpenItem();
		$item->setDebtor($debtor);
		$item->setDescription($description !== null && trim($description) !== '' ? trim($description) : null);
		$item->setAmountCents($amountCents);
		$item->setDueDate($dueDate !== null && $dueDate !== '' ? $dueDate : null);
		$item->setStatus('open');
		$item->setAccountId($accountId);
		$item->setMandateId($mandateId);
		$item->setCreatedAt((new \DateTime())->format(\DateTime::ATOM));
		return $this->mapper->insert($item);
	}

	/**
	 * @param int|null $journalId optionale Verknüpfung mit der Buchung, die den
	 *                            Posten bezahlt hat
	 * @throws \InvalidArgumentException wenn es diese Buchung nicht gibt
	 * @throws ClaimManagedException wenn der Posten eine Forderung des Beitragsmoduls ist
	 * @throws DoesNotExistException wenn es den offenen Posten nicht gibt
	 */
	public function markPaid(int $id, ?int $journalId): OpenItem {
		$item = $this->mapper->find($id);
		$this->assertNotClaim($item, $this->l10n->t('Beitragsforderungen werden im Einzug-Reiter bearbeitet. Dort vermerken Sie die Erledigung mit Ihrem Namen und einer Notiz.'));
		if ($journalId !== null) {
			// Ohne Prüfung stünde im Posten eine Buchungsnummer, die ins Leere
			// zeigt - der Beleg für die Zahlung wäre nicht auffindbar.
			try {
				$this->journalMapper->find($journalId, Application::BOOK);
			} catch (DoesNotExistException) {
				throw new \InvalidArgumentException($this->l10n->t('Die angegebene Buchung existiert nicht.'));
			}
		}
		$item->setStatus('paid');
		$item->setPaidJournalId($journalId);
		return $this->mapper->update($item);
	}

	/**
	 * @throws ClaimManagedException wenn der Posten eine Forderung des Beitragsmoduls ist
	 * @throws DoesNotExistException wenn es den offenen Posten nicht gibt
	 */
	public function cancel(int $id): OpenItem {
		$item = $this->mapper->find($id);
		$this->assertNotClaim($item, $this->l10n->t('Beitragsforderungen werden im Einzug-Reiter bearbeitet. Dort braucht ein Storno eine Begründung und ist nur vor der Einreichung möglich.'));
		$item->setStatus('cancelled');
		return $this->mapper->update($item);
	}

	/**
	 * @throws ClaimManagedException wenn der Posten eine Forderung des Beitragsmoduls ist
	 * @throws DoesNotExistException wenn es den offenen Posten nicht gibt
	 */
	public function reopen(int $id): OpenItem {
		$item = $this->mapper->find($id);
		$this->assertNotClaim($item, $this->l10n->t('Beitragsforderungen werden im Einzug-Reiter bearbeitet. Eine erledigte oder stornierte Forderung lässt sich nicht wieder öffnen; nur eine Rücklastschrift öffnet sie wieder.'));
		$item->setStatus('open');
		$item->setPaidJournalId(null);
		return $this->mapper->update($item);
	}

	/**
	 * @throws ClaimManagedException wenn der Posten eine Forderung des Beitragsmoduls ist
	 * @throws DoesNotExistException wenn es den offenen Posten nicht gibt
	 */
	public function delete(int $id): void {
		$item = $this->mapper->find($id);
		$this->assertNotClaim($item, $this->l10n->t('Beitragsforderungen werden im Einzug-Reiter bearbeitet. Eine Forderung wird nicht gelöscht: Dort stornieren Sie sie (unberechtigt, nur vor der Einreichung) oder vermerken einen Erlass (berechtigt, jederzeit).'));
		$this->mapper->delete($item);
	}

	/**
	 * @param string $message Hinweis, was stattdessen zu tun ist - je Aktion ein eigener
	 * @throws ClaimManagedException wenn der Posten eine Forderung des Beitragsmoduls ist
	 */
	private function assertNotClaim(OpenItem $item, string $message): void {
		if ($item->belongsToClaimModule()) {
			throw new ClaimManagedException($message);
		}
	}
}
