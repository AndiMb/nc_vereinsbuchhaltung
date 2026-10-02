<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCP\IL10N;

/**
 * Die Zustandsmaschine des Lastschriftlaufs (Spec §2.2/§3.5, Issue #71):
 * `freigegeben` → `eingereicht` | `verworfen`, beide terminal. Reine
 * Entscheidungslogik ohne Datenbankzugriff, nach demselben Muster wie
 * {@see MandateStateMachine} – jede assert*()-Methode wirft bei einem
 * unzulässigen Übergang, mutiert aber selbst nichts; das bleibt Sache von
 * {@see DebitBatchService}.
 */
class DebitBatchStateMachine {

	public function __construct(
		private IL10N $l10n,
	) {
	}

	/**
	 * Einreichung (Schritt 2, „Datei ist bei der Bank eingereicht") – nur aus
	 * `freigegeben` heraus, kein zweites Mal.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function assertCanSubmit(DebitBatch $batch): void {
		if ($batch->getStatus() !== DebitBatch::STATUS_RELEASED) {
			throw new \InvalidArgumentException($this->l10n->t('Nur ein freigegebener, noch nicht eingereichter Lauf lässt sich als eingereicht markieren.'));
		}
	}

	/**
	 * Verwerfen – „kein Storno nach Einreichung" (Spec §3.5): nur solange noch
	 * nicht eingereicht. Ein bereits verworfener Lauf ist bereits terminal.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function assertCanDiscard(DebitBatch $batch): void {
		if ($batch->getStatus() !== DebitBatch::STATUS_RELEASED) {
			throw new \InvalidArgumentException($this->l10n->t('Nur ein freigegebener, noch nicht eingereichter Lauf lässt sich verwerfen.'));
		}
	}

	/**
	 * Terminverschiebung – nur solange noch nicht eingereicht, und „nur nach
	 * hinten verschiebbar" (Spec §2.2 „Lastschriftlauf"): ein früheres Datum
	 * ließe die ohnehin schon knappe Vorlagefrist noch knapper werden, statt
	 * das eigentliche Problem (verspätete Einreichung) zu lösen.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function assertCanReschedule(DebitBatch $batch, string $newDueDate): void {
		if ($batch->getStatus() !== DebitBatch::STATUS_RELEASED) {
			throw new \InvalidArgumentException($this->l10n->t('Nur ein freigegebener, noch nicht eingereichter Lauf lässt sich terminlich verschieben.'));
		}
		if ($newDueDate <= $batch->getDueDate()) {
			throw new \InvalidArgumentException($this->l10n->t('Der Termin lässt sich nur nach hinten verschieben.'));
		}
	}
}
