<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\Reimbursement;
use OCA\Vereinsbuchhaltung\Db\ReimbursementEvent;
use OCA\Vereinsbuchhaltung\Db\ReimbursementMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Nimmt die Auszahlung einer Auslage zurück, wenn ihr Buchungssatz verschwindet
 * (Zuordnung gelöst, Buchung gelöscht) - das Gegenstück zu
 * {@see InvoicePaymentLedger::revertForJournal()}. Ohne diesen Haken stünde der
 * Antrag weiter „Ausgezahlt“ und verwiese auf eine Buchung, die es nicht mehr gibt.
 *
 * Der Antrag ist danach wieder freigegeben (die Überweisung bleibt vorgemerkt), der
 * offene Posten wieder offen, und die Belege liegen wieder beim Antrag. Die Historie
 * hält fest, wer es war. Läuft in der Transaktion, die den Buchungssatz entfernt.
 */
class ReimbursementPaymentReverter {

	public function __construct(
		private ReimbursementMapper $reimbursements,
		private OpenItemMapper $openItems,
		private ReimbursementEventLog $log,
		private ReimbursementFileService $files,
		private ActorContextService $actor,
		private AuditService $audit,
	) {
	}

	public function revertForJournal(int $journalId): void {
		$reimbursement = $this->reimbursements->findPaidByJournal($journalId);
		if ($reimbursement === null) {
			return;
		}
		$this->files->restoreFromJournal($reimbursement, $journalId);
		$this->log->append($reimbursement->getId(), ReimbursementEvent::ACTION_PAYMENT_REVERTED, $this->actor->actorUid() ?? '?', ReimbursementEvent::ROLE_STAFF, null, [
			'journalId' => $journalId,
			'paidAt' => $reimbursement->getPaidAt(),
		]);

		$reimbursement->setStatus(Reimbursement::STATUS_APPROVED);
		$reimbursement->setPaidAt(null);
		$reimbursement->setJournalId(null);
		$this->reimbursements->update($reimbursement);

		if ($reimbursement->getOpenItemId() !== null) {
			try {
				$item = $this->openItems->find($reimbursement->getOpenItemId());
				$item->setStatus('open');
				$item->setPaidJournalId(null);
				$item->setSettledAt(null);
				$item->setSettledBy(null);
				$item->setSettledCents(0);
				$this->openItems->update($item);
			} catch (DoesNotExistException) {
			}
		}
		$this->audit->log('Auslage: Auszahlung zurückgenommen', 'reimbursement', $reimbursement->getId(), ['journalId' => $journalId]);
	}
}
