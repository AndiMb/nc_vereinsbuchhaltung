<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\Reimbursement;
use OCA\Vereinsbuchhaltung\Db\ReimbursementEvent;
use OCA\Vereinsbuchhaltung\Db\ReimbursementFile;
use OCA\Vereinsbuchhaltung\Db\ReimbursementFileMapper;
use OCA\Vereinsbuchhaltung\Db\ReimbursementMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Auslagenerstattung (docs/reimbursement-design.md): ein Mitglied reicht eine
 * privat verauslagte Ausgabe mit Beleg ein, mindestens N verschiedene Verwalter
 * geben sie frei, erst dann entsteht ein offener Posten zur Überweisung.
 *
 * ```
 * submitted ─► partially_approved ─► approved ─► paid
 *     │               │
 *     ├─► rejected ◄──┘   (jeder Verwalter, mit Begründung, sofort endgültig)
 *     └─► withdrawn       (Mitglied, solange noch keine Freigabe vorliegt)
 * ```
 *
 * Jeder Schritt steht in der Historie ({@see ReimbursementEventLog}) und im
 * allgemeinen Änderungsprotokoll. Zustandswechsel laufen in einer Transaktion
 * mit Wiederholung bei Unique-Konflikt: zwei gleichzeitige Freigaben
 * ermitteln dieselbe Folgenummer, eine gewinnt, die andere liest den neuen
 * Stand und zählt korrekt weiter – eine Schwelle wird so nie überfahren und
 * kein Verwalter doppelt gezählt.
 */
class ReimbursementService {

	public const MAX_PURPOSE_LENGTH = 500;

	public function __construct(
		private ReimbursementMapper $mapper,
		private ReimbursementFileMapper $fileMapper,
		private OpenItemMapper $openItemMapper,
		private MemberMapper $memberMapper,
		private JournalMapper $journalMapper,
		private ReimbursementEventLog $log,
		private ReimbursementSettings $settings,
		private ReimbursementFileService $files,
		private ReimbursementMailService $mail,
		private IbanValidator $ibanValidator,
		private TransactionRunner $transaction,
		private AuditService $audit,
		private ITimeFactory $time,
		private IL10N $l10n,
	) {
	}

	// --- Lesen ------------------------------------------------------------

	/**
	 * @throws DoesNotExistException wenn es den Antrag nicht gibt
	 */
	public function find(int $id): Reimbursement {
		return $this->mapper->find($id);
	}

	/**
	 * Der Antrag eines bestimmten Mitglieds. Ein fremder Antrag verhält sich
	 * wie ein nicht vorhandener (IDOR-Schutz: keine Auskunft, dass es ihn gibt).
	 *
	 * @throws DoesNotExistException
	 */
	public function findForMember(int $id, int $memberId): Reimbursement {
		$reimbursement = $this->mapper->find($id);
		if ($reimbursement->getMemberId() !== $memberId) {
			throw new DoesNotExistException('Reimbursement not found');
		}
		return $reimbursement;
	}

	/**
	 * Freigeber für eine ganze Liste von Anträgen in einer Abfrage.
	 *
	 * @param list<int> $ids
	 * @return array<int, list<string>> Antrags-ID => Nutzerkennungen
	 */
	public function approverUidsFor(array $ids): array {
		return $this->log->approverUidsFor($ids);
	}

	/** @return ReimbursementEvent[] */
	public function history(int $id): array {
		return $this->log->history($id);
	}

	/** @return ReimbursementFile[] */
	public function files(int $id): array {
		return $this->fileMapper->findActive($id);
	}

	/**
	 * Fortschritt der Freigaben als Zahlenpaar, z. B. 1 von 2.
	 *
	 * @param list<string>|null $approvers die schon bekannten Freigeber (Listenansicht); ohne Angabe wird nachgeschlagen
	 * @return array{approved: int, required: int}
	 */
	public function progress(Reimbursement $reimbursement, ?array $approvers = null): array {
		$approved = count($approvers ?? $this->approverUids($reimbursement->getId()));
		return [
			'approved' => min($approved, $reimbursement->getRequiredApprovals()),
			'required' => $reimbursement->getRequiredApprovals(),
		];
	}

	// --- Einreichen -------------------------------------------------------

	/**
	 * @param list<array{name: string, content: string}> $uploads die Belegdateien, mindestens eine
	 * @throws \InvalidArgumentException bei ungültigen Angaben oder wenn die Funktion aus ist
	 */
	public function submit(
		Member $member,
		int $amountCents,
		string $expenseDate,
		string $purpose,
		string $iban,
		array $uploads,
		string $actorUid,
	): Reimbursement {
		if (!$this->settings->isEnabled()) {
			throw new \InvalidArgumentException($this->l10n->t('Die Auslagenerstattung ist nicht aktiv.'));
		}
		if ($amountCents <= 0) {
			throw new \InvalidArgumentException($this->l10n->t('Der Betrag muss größer als 0 sein.'));
		}
		$expenseDate = $this->validDate($expenseDate);
		$purpose = trim($purpose);
		if ($purpose === '') {
			throw new \InvalidArgumentException($this->l10n->t('Bitte einen Verwendungszweck angeben.'));
		}
		if (mb_strlen($purpose) > self::MAX_PURPOSE_LENGTH) {
			throw new \InvalidArgumentException($this->l10n->t('Der Verwendungszweck ist zu lang (höchstens %d Zeichen).', [self::MAX_PURPOSE_LENGTH]));
		}
		$normalizedIban = $this->ibanValidator->validate($iban);
		if ($normalizedIban === null) {
			throw new \InvalidArgumentException($this->l10n->t('Bitte eine IBAN für die Erstattung angeben.'));
		}
		if ($uploads === []) {
			throw new \InvalidArgumentException($this->l10n->t('Bitte mindestens einen Beleg hochladen.'));
		}

		$required = $this->settings->requiredApprovals();
		if (count($this->settings->approversFor($actorUid)) < $required) {
			// Sonst bliebe der Antrag für immer hängen. Das ist ein Zustand der Vereinsführung,
			// nichts, was das Mitglied beheben kann - die Meldung sagt das.
			throw new \InvalidArgumentException($this->l10n->t('Der Antrag kann derzeit nicht eingereicht werden: Es gibt nicht genug Verwalter, die ihn freigeben könnten. Bitte wenden Sie sich an den Vorstand.'));
		}

		return $this->transaction->run(function () use ($member, $amountCents, $expenseDate, $purpose, $normalizedIban, $uploads, $actorUid, $required): Reimbursement {
			$now = $this->now();
			$reimbursement = new Reimbursement();
			$reimbursement->setMemberId($member->getId());
			$reimbursement->setAmountCents($amountCents);
			$reimbursement->setExpenseDate($expenseDate);
			$reimbursement->setPurpose($purpose);
			$reimbursement->setIban($normalizedIban);
			$reimbursement->setStatus(Reimbursement::STATUS_SUBMITTED);
			$reimbursement->setRequiredApprovals($required);
			$reimbursement->setSubmittedBy($actorUid);
			$reimbursement->setSubmittedAt($now);
			$reimbursement = $this->mapper->insert($reimbursement);

			$names = [];
			$duplicates = [];
			foreach ($uploads as $upload) {
				$file = $this->files->add($reimbursement, $upload['name'], $upload['content']);
				$names[] = $file->getFileName();
				foreach ($this->files->duplicatesOf($file) as $other) {
					$duplicates[] = ['file' => $file->getFileName(), 'reimbursementId' => $other->getReimbursementId()];
				}
			}

			$this->log->append($reimbursement->getId(), ReimbursementEvent::ACTION_SUBMITTED, $actorUid, ReimbursementEvent::ROLE_MEMBER, null, [
				'amountCents' => $amountCents,
				'requiredApprovals' => $required,
				'receipts' => $names,
			]);
			if ($duplicates !== []) {
				$this->log->append($reimbursement->getId(), ReimbursementEvent::ACTION_DUPLICATE_HINT, $actorUid, ReimbursementEvent::ROLE_MEMBER, null, ['matches' => $duplicates]);
			}
			$this->audit->log('Auslage eingereicht', 'reimbursement', $reimbursement->getId(), ['amountCents' => $amountCents]);
			// Mails erst, wenn der Antrag wirklich gespeichert ist.
			$this->transaction->afterCommit(fn () => $this->mail->submitted($reimbursement));
			return $reimbursement;
		});
	}

	// --- Entscheiden ------------------------------------------------------

	/**
	 * Eine Freigabe. Die letzte nötige Freigabe stellt den Antrag auf
	 * `approved` und legt den offenen Posten zur Überweisung an.
	 *
	 * @throws ForbiddenException wenn der Handelnde kein Freigeber ist, den eigenen Antrag freigeben will oder schon freigegeben hat
	 * @throws \InvalidArgumentException wenn der Antrag nicht (mehr) zur Entscheidung ansteht
	 * @throws DoesNotExistException
	 */
	public function approve(int $id, string $actorUid): Reimbursement {
		return $this->atomically(function () use ($id, $actorUid): Reimbursement {
			$reimbursement = $this->mapper->find($id);
			$this->assertOpenForDecision($reimbursement);
			$this->assertMayDecide($reimbursement, $actorUid);

			$approvers = $this->approverUids($id);
			if (in_array($actorUid, $approvers, true)) {
				throw new ForbiddenException($this->l10n->t('Sie haben diesen Antrag bereits freigegeben.'));
			}

			$count = count($approvers) + 1;
			$required = $reimbursement->getRequiredApprovals();
			$final = $count >= $required;

			$this->log->append($id, ReimbursementEvent::ACTION_APPROVED, $actorUid, ReimbursementEvent::ROLE_STAFF, null, [
				'count' => min($count, $required),
				'required' => $required,
				'final' => $final,
			]);

			if ($final) {
				$reimbursement->setStatus(Reimbursement::STATUS_APPROVED);
				$reimbursement->setDecidedAt($this->now());
				$reimbursement->setOpenItemId($this->createOpenItem($reimbursement)->getId());
			} else {
				$reimbursement->setStatus(Reimbursement::STATUS_PARTIALLY_APPROVED);
			}
			$reimbursement = $this->mapper->update($reimbursement);

			$this->audit->log($final ? 'Auslage freigegeben' : 'Auslage teilweise freigegeben', 'reimbursement', $id, ['count' => min($count, $required), 'required' => $required]);
			// Teilfreigaben stehen nur in der Historie, eine Mail gibt es erst bei der letzten.
			if ($final) {
				$this->transaction->afterCommit(fn () => $this->mail->approved($reimbursement));
			}
			return $reimbursement;
		});
	}

	/**
	 * Ablehnung durch einen Verwalter: sofort endgültig, ohne zweite Stimme.
	 *
	 * @throws \InvalidArgumentException ohne Begründung oder wenn der Antrag nicht zur Entscheidung ansteht
	 * @throws ForbiddenException
	 */
	public function reject(int $id, string $actorUid, string $reason): Reimbursement {
		$reason = trim($reason);
		if ($reason === '') {
			throw new \InvalidArgumentException($this->l10n->t('Bitte eine Begründung für die Ablehnung angeben.'));
		}
		return $this->atomically(function () use ($id, $actorUid, $reason): Reimbursement {
			$reimbursement = $this->mapper->find($id);
			$this->assertOpenForDecision($reimbursement);
			$this->assertMayDecide($reimbursement, $actorUid);

			$this->log->append($id, ReimbursementEvent::ACTION_REJECTED, $actorUid, ReimbursementEvent::ROLE_STAFF, $reason);
			$reimbursement->setStatus(Reimbursement::STATUS_REJECTED);
			$reimbursement->setRejectionReason($reason);
			$reimbursement->setDecidedAt($this->now());
			$reimbursement = $this->mapper->update($reimbursement);

			$this->audit->log('Auslage abgelehnt', 'reimbursement', $id, ['reason' => $reason]);
			$this->transaction->afterCommit(fn () => $this->mail->rejected($reimbursement));
			return $reimbursement;
		});
	}

	/**
	 * Das Mitglied zieht seinen Antrag zurück - nur solange noch keine
	 * Freigabe vorliegt.
	 *
	 * @throws \InvalidArgumentException wenn schon eine Freigabe oder Entscheidung vorliegt
	 * @throws DoesNotExistException wenn der Antrag nicht dem Mitglied gehört
	 */
	public function withdraw(int $id, int $memberId, string $actorUid): Reimbursement {
		return $this->atomically(function () use ($id, $memberId, $actorUid): Reimbursement {
			$reimbursement = $this->findForMember($id, $memberId);
			if ($reimbursement->getStatus() !== Reimbursement::STATUS_SUBMITTED) {
				throw new \InvalidArgumentException($this->l10n->t('Dieser Antrag lässt sich nicht mehr zurückziehen.'));
			}
			$this->log->append($id, ReimbursementEvent::ACTION_WITHDRAWN, $actorUid, ReimbursementEvent::ROLE_MEMBER);
			$reimbursement->setStatus(Reimbursement::STATUS_WITHDRAWN);
			$reimbursement->setDecidedAt($this->now());
			$reimbursement = $this->mapper->update($reimbursement);

			$this->audit->log('Auslage zurückgezogen', 'reimbursement', $id);
			return $reimbursement;
		});
	}

	// --- Belege nachreichen / entfernen -----------------------------------

	/**
	 * Eine weitere Belegdatei, solange keine Freigabe vorliegt.
	 *
	 * @throws \InvalidArgumentException wenn schon freigegeben wurde
	 * @throws DoesNotExistException wenn der Antrag nicht dem Mitglied gehört
	 */
	public function addReceipt(int $id, int $memberId, string $actorUid, string $name, string $content): ReimbursementFile {
		return $this->atomically(function () use ($id, $memberId, $actorUid, $name, $content): ReimbursementFile {
			$reimbursement = $this->findForMember($id, $memberId);
			$this->assertReceiptsEditable($reimbursement);
			$file = $this->files->add($reimbursement, $name, $content);
			$this->log->append($id, ReimbursementEvent::ACTION_RECEIPT_ADDED, $actorUid, ReimbursementEvent::ROLE_MEMBER, null, ['file' => $file->getFileName()]);
			foreach ($this->files->duplicatesOf($file) as $other) {
				$this->log->append($id, ReimbursementEvent::ACTION_DUPLICATE_HINT, $actorUid, ReimbursementEvent::ROLE_MEMBER, null, [
					'matches' => [['file' => $file->getFileName(), 'reimbursementId' => $other->getReimbursementId()]],
				]);
			}
			return $file;
		});
	}

	/**
	 * Entfernt eine Belegdatei; der letzte Beleg bleibt (ein Antrag ohne Beleg
	 * wäre nicht prüfbar).
	 *
	 * @throws \InvalidArgumentException wenn schon freigegeben wurde oder es der letzte Beleg ist
	 * @throws DoesNotExistException wenn Antrag oder Datei nicht zum Mitglied gehören
	 */
	public function removeReceipt(int $id, int $fileId, int $memberId, string $actorUid): void {
		$this->atomically(function () use ($id, $fileId, $memberId, $actorUid): void {
			$reimbursement = $this->findForMember($id, $memberId);
			$this->assertReceiptsEditable($reimbursement);
			$file = $this->files->findActiveFor($id, $fileId);
			if ($this->fileMapper->countActive($id) <= 1) {
				throw new \InvalidArgumentException($this->l10n->t('Der letzte Beleg lässt sich nicht entfernen. Ziehen Sie den Antrag zurück oder laden Sie zuerst einen anderen Beleg hoch.'));
			}
			$this->files->remove($file);
			$this->log->append($id, ReimbursementEvent::ACTION_RECEIPT_REMOVED, $actorUid, ReimbursementEvent::ROLE_MEMBER, null, ['file' => $file->getFileName()]);
		});
	}

	// --- Auszahlen ---------------------------------------------------------

	/**
	 * Vermerkt die Überweisung: Der Antrag wird `paid`, der offene Posten
	 * erledigt, die Belege hängen danach an der Buchung. Die Buchung selbst
	 * legt der Buchungsdialog an (Konto, Kostenstelle und Bank wählt der
	 * Buchende); hier kommt ihre Nummer an.
	 *
	 * @param string $paidDate Überweisungsdatum (JJJJ-MM-TT)
	 * @throws \InvalidArgumentException wenn der Antrag nicht freigegeben ist oder das Datum ungültig
	 */
	public function markPaid(int $id, int $journalId, string $paidDate, string $actorUid): Reimbursement {
		$paidDate = $this->validDate($paidDate);
		return $this->atomically(function () use ($id, $journalId, $paidDate, $actorUid): Reimbursement {
			$reimbursement = $this->mapper->find($id);
			if ($reimbursement->getStatus() !== Reimbursement::STATUS_APPROVED) {
				throw new \InvalidArgumentException($this->l10n->t('Nur ein freigegebener Antrag lässt sich als ausgezahlt vermerken.'));
			}
			// Ohne Prüfung stünde am Antrag eine Buchungsnummer, die ins Leere zeigt, und die Belege
			// hingen an einer Buchung, die es nicht gibt.
			try {
				$this->journalMapper->find($journalId, Application::BOOK);
			} catch (DoesNotExistException) {
				throw new \InvalidArgumentException($this->l10n->t('Die angegebene Buchung existiert nicht.'));
			}

			$this->log->append($id, ReimbursementEvent::ACTION_PAID, $actorUid, ReimbursementEvent::ROLE_STAFF, null, [
				'journalId' => $journalId,
				'paidDate' => $paidDate,
			]);

			$reimbursement->setStatus(Reimbursement::STATUS_PAID);
			$reimbursement->setPaidAt($paidDate);
			$reimbursement->setJournalId($journalId);
			$reimbursement = $this->mapper->update($reimbursement);

			if ($reimbursement->getOpenItemId() !== null) {
				$item = $this->openItemMapper->find($reimbursement->getOpenItemId());
				$item->setStatus('paid');
				$item->setPaidJournalId($journalId);
				$item->setSettledAt($this->now());
				$item->setSettledBy($actorUid);
				$item->setSettledCents($item->getAmountCents());
				$this->openItemMapper->update($item);
			}
			$this->files->linkToJournal($reimbursement, $journalId);

			$this->audit->log('Auslage ausgezahlt', 'reimbursement', $id, ['journalId' => $journalId]);
			$this->transaction->afterCommit(fn () => $this->mail->paid($reimbursement));
			return $reimbursement;
		});
	}

	// --- intern -----------------------------------------------------------

	/**
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	private function atomically(callable $fn) {
		// Innerhalb einer schon laufenden Transaktion wäre eine Wiederholung sinnlos (siehe
		// TransactionRunner::runWithRetry); dort läuft der Vorgang einfach mit.
		return $this->transaction->isActive()
			? $this->transaction->run($fn)
			: $this->transaction->runWithRetry($fn);
	}

	/**
	 * Wer den Antrag schon freigegeben hat.
	 *
	 * @return list<string> Nutzerkennungen
	 */
	public function approverUids(int $id): array {
		return $this->log->approverUids($id);
	}

	private function assertOpenForDecision(Reimbursement $reimbursement): void {
		if (!$reimbursement->isOpenForDecision()) {
			throw new \InvalidArgumentException($this->l10n->t('Dieser Antrag steht nicht mehr zur Entscheidung an.'));
		}
	}

	/**
	 * @throws ForbiddenException
	 */
	private function assertMayDecide(Reimbursement $reimbursement, string $actorUid): void {
		if ($actorUid === $reimbursement->getSubmittedBy()) {
			throw new ForbiddenException($this->l10n->t('Den eigenen Antrag können Sie nicht freigeben oder ablehnen.'));
		}
		if (!in_array($actorUid, $this->settings->approverUids(), true)) {
			throw new ForbiddenException($this->l10n->t('Nur Verwalter dürfen Auslagen freigeben oder ablehnen.'));
		}
	}

	private function assertReceiptsEditable(Reimbursement $reimbursement): void {
		if ($reimbursement->getStatus() !== Reimbursement::STATUS_SUBMITTED) {
			throw new \InvalidArgumentException($this->l10n->t('Belege lassen sich nur ändern, solange noch keine Freigabe vorliegt.'));
		}
	}

	private function createOpenItem(Reimbursement $reimbursement): OpenItem {
		$item = new OpenItem();
		$item->setDebtor($this->memberMapper->find($reimbursement->getMemberId())->displayName());
		$item->setDescription($this->l10n->t('Auslage: %s', [$reimbursement->getPurpose()]));
		$item->setReference('AUSL-' . $reimbursement->getId());
		$item->setAmountCents($reimbursement->getAmountCents());
		$item->setStatus('open');
		$item->setReimbursementId($reimbursement->getId());
		$item->setCreatedAt($this->now());
		return $this->openItemMapper->insert($item);
	}

	private function validDate(string $date): string {
		$date = trim($date);
		$parsed = \DateTime::createFromFormat('!Y-m-d', $date);
		if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
			throw new \InvalidArgumentException($this->l10n->t('Bitte ein gültiges Datum angeben (JJJJ-MM-TT).'));
		}
		$today = $this->time->getDateTime()->format('Y-m-d');
		if ($date > $today) {
			throw new \InvalidArgumentException($this->l10n->t('Das Datum darf nicht in der Zukunft liegen.'));
		}
		return $date;
	}

	private function now(): string {
		return $this->time->getDateTime()->format('Y-m-d H:i:s');
	}
}
