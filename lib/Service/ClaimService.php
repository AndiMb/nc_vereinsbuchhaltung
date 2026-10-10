<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Forderungen (Spec §2.2 „Forderung (Claim)", auf `vbh_open_items`
 * abgebildet, siehe {@see OpenItem}) – manuelle Einzelforderungen sowie
 * Erledigungsvermerk/Storno/Stundung. Die periodische Forderungserzeugung aus
 * Zuweisungen (Cron D-21/D-14/D-5, Spec §3.5) ist bewusst NICHT Teil dieser
 * Klasse: das ist Ticket #70 (Einzugszyklus-Choreografie); Issue #68 verlangt
 * ausdrücklich nur die manuelle Einzelforderung.
 *
 * Getrennt von {@see \OCA\Vereinsbuchhaltung\Service\OpenItemService}: die
 * bestehenden Freitext-Posten (Rechnungen etc.) bleiben unangetastet, Claims
 * erkennt man an gesetztem `memberId`+`type` (siehe {@see OpenItem::isClaim()}).
 */
class ClaimService {

	public function __construct(
		private OpenItemMapper $mapper,
		private MemberMapper $memberMapper,
		private ITimeFactory $time,
		private IL10N $l10n,
		private DebitItemMapper $debitItems,
		private AuditService $audit,
	) {
	}

	/**
	 * „Offene-Posten-Sicht" (Spec §3.9): für `revisor` maskiert – hier
	 * schlicht ohne Personenakte/Kontaktdaten, nur Anzeigename. Details wie
	 * Erledigungsvermerk/Stundung/Storno bleiben sichtbar, die Spec nennt sie
	 * ausdrücklich als Teil des lesbaren Einzug-Unterreiters.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public function listMasked(): array {
		return array_map(fn (OpenItem $item) => $this->decorateMasked($item), $this->mapper->findClaims());
	}

	public function find(int $id): OpenItem {
		$item = $this->mapper->find($id);
		if (!$item->isClaim()) {
			throw new DoesNotExistException('Keine Forderung im Sinne von Issue #68.');
		}
		return $item;
	}

	/**
	 * Manuelle Einzelforderung (Spec §3.3 „schmale Tür"): freier Betrag,
	 * Pflicht-Bezeichnung, eigener Einzugstermin, auch ohne aktives Mandat
	 * anlegbar – deshalb keinerlei Mandatsprüfung hier (Mandate existieren in
	 * diesem Branch ohnehin noch nicht, siehe Ticket #66).
	 *
	 * @throws \InvalidArgumentException bei unvollständigen/ungültigen Angaben
	 * @throws DoesNotExistException wenn es das Mitglied nicht gibt
	 */
	public function createManual(int $memberId, string $type, int $amountCents, string $label, string $dueDate, ?int $accountId): OpenItem {
		$member = $this->memberMapper->find($memberId);
		if (!in_array($type, OpenItem::TYPES, true)) {
			throw new \InvalidArgumentException($this->l10n->t('Unbekannter Forderungstyp.'));
		}
		if ($amountCents <= 0) {
			throw new \InvalidArgumentException($this->l10n->t('Betrag muss größer als 0 sein.'));
		}
		$label = trim($label);
		if ($label === '') {
			throw new \InvalidArgumentException($this->l10n->t('Bezeichnung ist bei einer manuellen Einzelforderung Pflicht.'));
		}
		$this->assertDate($dueDate);

		$item = new OpenItem();
		$item->setDebtor($this->debtorLabel($member));
		$item->setDescription($label);
		$item->setAmountCents($amountCents);
		$item->setDueDate($dueDate);
		$item->setStatus('open');
		$item->setAccountId($accountId);
		$item->setMemberId($memberId);
		$item->setType($type);
		$item->setCreatedAt($this->now());
		return $this->mapper->insert($item);
	}

	/**
	 * Erledigungsvermerk: `paid` (Datum/Urheber/optionale Notiz) oder
	 * `waived` (Pflicht-Begründung – Spec §3.6 „Erlass: war berechtigt, wir
	 * verzichten").
	 *
	 * Der Urheber (`settledBy`) wird mitgeschrieben – Spec §3.6 „Erledigungs-
	 * vermerk `paid` (Datum, Urheber, Notiz)"; die Oberfläche (Issue #104)
	 * zeigt ihn im Forderungs-Detail.
	 *
	 * @param 'paid'|'waived' $settlementType
	 * @throws \InvalidArgumentException bei ungültigem Zustand/fehlender Begründung
	 * @throws DoesNotExistException wenn es die Forderung nicht gibt
	 */
	public function settle(int $id, string $settlementType, ?string $note, string $actorUid): OpenItem {
		if (!in_array($settlementType, ['paid', 'waived'], true)) {
			throw new \InvalidArgumentException($this->l10n->t('Unbekannte Erledigungsart.'));
		}
		$item = $this->find($id);
		$this->assertOpen($item, $this->l10n->t('Nur offene Forderungen können erledigt werden.'));
		if ($settlementType === 'waived' && ($note === null || trim($note) === '')) {
			throw new \InvalidArgumentException($this->l10n->t('Ein Erlass braucht eine Begründung.'));
		}
		$item->setSettledAt($this->now());
		$item->setSettledBy($actorUid);
		$item->setSettlementNote($note !== null && trim($note) !== '' ? trim($note) : null);
		$item->setStatus($settlementType);
		return $this->mapper->update($item);
	}

	/**
	 * Storno – „hätte nie existieren dürfen", nur vor Einreichung (Spec §3.6).
	 * Seit dem Lastschriftlauf (Issue #71) ist das prüfbar: eine Forderung in
	 * einem eingereichten Lauf ist storno-gesperrt („kein Storno nach
	 * Einreichung", Spec §3.5) – für einen Verzicht gibt es den Erlass. Eine
	 * Forderung in einem freigegebenen, noch nicht eingereichten Lauf steckt
	 * als Zeile in der bereits erzeugten Datei; ein Storno ließe sie dort
	 * stehen, die Bank belastete eine stornierte Forderung. Dieser Lauf wird
	 * deshalb zuerst verworfen (Spec §3.5: „darf verworfen + neu freigegeben
	 * werden") – dabei werden die Forderungen frei und lassen sich stornieren.
	 *
	 * @throws \InvalidArgumentException bei ungültigem Zustand/fehlender Begründung
	 * @throws DoesNotExistException wenn es die Forderung nicht gibt
	 */
	public function cancel(int $id, string $reason): OpenItem {
		$item = $this->find($id);
		$this->assertOpen($item, $this->l10n->t('Nur offene Forderungen können storniert werden.'));
		$liveBatchStatus = $this->debitItems->findLiveBatchStatusByOpenItem($id);
		if ($liveBatchStatus === DebitBatch::STATUS_SUBMITTED) {
			throw new \InvalidArgumentException($this->l10n->t('Diese Forderung steckt in einem eingereichten Lauf und lässt sich nicht mehr stornieren. Ist sie berechtigt, aber nicht mehr zu erwarten, vermerken Sie einen Erlass.'));
		}
		if ($liveBatchStatus === DebitBatch::STATUS_RELEASED) {
			throw new \InvalidArgumentException($this->l10n->t('Diese Forderung steckt in einem freigegebenen Lauf, dessen Datei sie bereits enthält. Verwerfen Sie zuerst den Lauf; danach lässt sich die Forderung stornieren.'));
		}
		$reason = trim($reason);
		if ($reason === '') {
			throw new \InvalidArgumentException($this->l10n->t('Ein Storno braucht eine Begründung.'));
		}
		$item->setCancelledAt($this->now());
		$item->setCancelledReason($reason);
		$item->setStatus('cancelled');
		return $this->mapper->update($item);
	}

	/**
	 * Stundung – „genau eine aktive Stundung je Forderung" (Spec §2.2): eine
	 * neue Stundung ist nur möglich, solange keine noch laufende existiert.
	 *
	 * @throws \InvalidArgumentException bei ungültigem Zustand/fehlender Begründung
	 * @throws DoesNotExistException wenn es die Forderung nicht gibt
	 */
	public function defer(int $id, string $deferredUntil, string $reason, string $actorUid): OpenItem {
		$item = $this->find($id);
		$this->assertOpen($item, $this->l10n->t('Nur offene Forderungen können gestundet werden.'));
		$this->assertDate($deferredUntil);
		if ($item->getDeferredUntil() !== null && $item->getDeferredUntil() >= $this->today()) {
			throw new \InvalidArgumentException($this->l10n->t('Es gibt bereits eine aktive Stundung für diese Forderung.'));
		}
		if ($deferredUntil < $this->today()) {
			throw new \InvalidArgumentException($this->l10n->t('Das Stundungsdatum darf nicht in der Vergangenheit liegen.'));
		}
		$reason = trim($reason);
		if ($reason === '') {
			throw new \InvalidArgumentException($this->l10n->t('Eine Stundung braucht eine Begründung.'));
		}
		$item->setDeferredUntil($deferredUntil);
		$item->setDeferredReason($reason);
		$item->setDeferredBy($actorUid);
		$item->setDeferredAt($this->now());
		return $this->mapper->update($item);
	}

	/**
	 * Hebt eine noch laufende Stundung vorzeitig auf. Das Mahnwesen läuft
	 * danach von der zuletzt erreichten Stufe weiter (Spec §3.6: „kein Reset");
	 * die Stundungsfelder werden geleert, damit eine neue Stundung möglich ist
	 * (genau eine aktive je Forderung). Dass es sie gab, hält das Änderungs-
	 * protokoll fest – Zeitpunkt, Urheber und Begründung der aufgehobenen
	 * Stundung stünden sonst nirgends mehr.
	 *
	 * @throws \InvalidArgumentException wenn die Forderung nicht (mehr) gestundet ist
	 * @throws DoesNotExistException wenn es die Forderung nicht gibt
	 */
	public function undefer(int $id): OpenItem {
		$item = $this->find($id);
		$this->assertOpen($item, $this->l10n->t('Nur offene Forderungen können gestundet sein.'));
		$until = $item->getDeferredUntil();
		if ($until === null || $until < $this->today()) {
			throw new \InvalidArgumentException($this->l10n->t('Diese Forderung ist nicht gestundet.'));
		}
		$this->audit->log('Stundung einer Forderung aufgehoben', 'open_item', $id, [
			'bis' => $until,
			'begruendung' => $item->getDeferredReason(),
			'gestundet_von' => $item->getDeferredBy(),
			'gestundet_am' => $item->getDeferredAt(),
		]);
		$item->setDeferredUntil(null);
		$item->setDeferredReason(null);
		$item->setDeferredBy(null);
		$item->setDeferredAt(null);
		return $this->mapper->update($item);
	}

	private function assertOpen(OpenItem $item, string $message): void {
		if (ClaimStateResolver::resolveForItem($item) !== ClaimStateResolver::STATE_OPEN) {
			throw new \InvalidArgumentException($message);
		}
	}

	private function debtorLabel(Member $member): string {
		$name = $member->displayName();
		return $name !== '' ? $name : $this->l10n->t('Mitglied #%s', [(string)$member->getId()]);
	}

	/** @return array<string,mixed> */
	private function decorateMasked(OpenItem $item): array {
		$data = $item->jsonSerialize();
		$data['memberDisplayName'] = $item->getMemberId() !== null
			? $this->memberMapper->displayNameOr($item->getMemberId(), $this->l10n->t('(unbekanntes Mitglied)'))
			: null;
		$data['state'] = ClaimStateResolver::resolveForItem($item);
		// Bewusst nicht mit ausgeliefert: Personenakte/Kontaktdaten des
		// Mitglieds (E-Mail, Telefon, Adresse, internal_note) - das ist Sache
		// des buchhalter-only Mitglieder-Unterreiters, nicht dieser
		// Offene-Posten-Sicht (Spec §3.9).
		return $data;
	}

	private function assertDate(string $date): void {
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new \InvalidArgumentException($this->l10n->t('Kein gültiges Datum: %s', [$date]));
		}
	}

	private function today(): string {
		return $this->time->getDateTime()->format('Y-m-d');
	}

	private function now(): string {
		return $this->time->getDateTime()->format(\DateTime::ATOM);
	}
}
