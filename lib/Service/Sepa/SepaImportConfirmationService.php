<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetail;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetailMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItem;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebit;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\BookingService;
use OCA\Vereinsbuchhaltung\Service\ClaimService;
use OCA\Vereinsbuchhaltung\Service\DunningLadderService;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Der Bestätigungsvorgang und die Verbuchung (Spec §2.2/§3.6/§3.10/§5, Issue
 * #72): „ein Bestätigungsvorgang je Bankumsatz, Einzelurteil je Detail-
 * Zeile; Verbuchung setzt voraus, dass alle Detail-Vorschläge beurteilt
 * sind." Kein Kandidat aus {@see SepaMatchingService} wirkt automatisch –
 * erst {@see assign()}/{@see reject()}/{@see markUnmatched()} (menschliche
 * Entscheidung je Zeile) setzen den Zustand, erst {@see settle()}
 * (menschlicher Auslöser für den ganzen Umsatz) bucht.
 *
 * Zwei grundverschiedene Buchungsrichtungen, je nach Vorzeichen des
 * Bankumsatzes (beide teilen sich denselben `BookingService::assignParts()`-
 * Pfad, Spec §3.10 „kein neuer SEPA-spezifischer Buchungsmechanismus"):
 * - **Geldeingang** (Sammelgutschrift eines erfolgreichen Einzugs, siehe
 *   {@see bookSettlements()}): jede zugeordnete, nicht-rückgabe Detail-Zeile
 *   schließt ihre Forderung ab, gruppiert nach Erlöskonto.
 * - **Geldausgang** (Rücklastschrift, siehe {@see bookReturns()}): jede
 *   zugeordnete Rückgabe-Detail-Zeile erzeugt eine {@see ReturnedDebit}
 *   (höchstens eine je Einzugsposten, siehe Posten-Guard) und öffnet die
 *   zugehörige Forderung wieder ("zurückgegeben → wieder offen", Spec §2.2).
 *
 * Seit Issue #73 löst {@see finalizeReturn()} zusätzlich die Rückgabe-Klassen-
 * Folgen aus (Spec §3.6, siehe {@see ReturnReasonClassifier}): automatische
 * Mandats-Sperre bei `account_unusable`/`disputed`/`deceased`
 * ({@see \OCA\Vereinsbuchhaltung\Service\MandateService::suspendDueToReturnedDebit()}),
 * sofortige Mahnstufe-0-Zahlungsaufforderung bei `insufficient_funds`/
 * `account_unusable`/`disputed`
 * ({@see \OCA\Vereinsbuchhaltung\Service\DunningLadderService::triggerPaymentRequest()})
 * und die klassenabhängige Gebühren-Weiterbelastung (verfeinert den in #72
 * gebauten einfachen Ja/Nein-Schalter).
 *
 * Ein Bankumsatz mischt beide Richtungen in der Praxis nie (eine Bank bündelt
 * Gutschrift und Rückgabe nie in derselben Buchung) – {@see settle()} bricht
 * trotzdem kontrolliert ab, sollte das doch vorkommen, statt eine der beiden
 * Seiten stillschweigend zu verwerfen.
 */
class SepaImportConfirmationService {

	public function __construct(
		private BankTxSepaDetailMapper $details,
		private BankTransactionMapper $txMapper,
		private DebitItemMapper $debitItems,
		private OpenItemMapper $openItems,
		private ReturnedDebitMapper $returnedDebits,
		private ClaimService $claims,
		private BookingService $bookingService,
		private SepaImportSettingsService $settings,
		private MandateService $mandates,
		private DunningLadderService $dunningLadder,
		private TransactionRunner $transaction,
		private AuditService $audit,
		private IUserSession $userSession,
		private ITimeFactory $time,
		private IL10N $l10n,
	) {
	}

	// --- Lesen -------------------------------------------------------------------

	/** @return BankTxSepaDetail[] */
	public function findByBankTx(int $bankTxId): array {
		return $this->details->findByBankTx($bankTxId);
	}

	// --- Einzelurteil je Detail-Zeile (Spec §5 "Sammler") ---------------------------

	/**
	 * Bestätigt einen Kandidaten aus {@see SepaMatchingService} für diese
	 * Detail-Zeile – erst hier wird die Vermutung zur Zuordnung.
	 *
	 * @throws DoesNotExistException wenn es die Detail-Zeile oder den Einzugsposten nicht (mehr) gibt
	 */
	public function assign(int $detailId, int $debitItemId): BankTxSepaDetail {
		$this->debitItems->find($debitItemId); // wirft, wenn er nicht (mehr) existiert
		return $this->decide($detailId, BankTxSepaDetail::STATUS_ASSIGNED, $debitItemId);
	}

	/** Vorschlag geprüft und verworfen – die Zeile bleibt unentschieden weiterreichbar an eine andere Zuordnung. */
	public function reject(int $detailId): BankTxSepaDetail {
		return $this->decide($detailId, BankTxSepaDetail::STATUS_REJECTED);
	}

	/** Kein Kandidat gefunden – Aufgabe „nicht zuordenbar" (Spec §5). */
	public function markUnmatched(int $detailId): BankTxSepaDetail {
		return $this->decide($detailId, BankTxSepaDetail::STATUS_UNMATCHED);
	}

	/** @throws DoesNotExistException wenn es die Detail-Zeile nicht (mehr) gibt */
	private function decide(int $detailId, string $status, ?int $debitItemId = null): BankTxSepaDetail {
		$detail = $this->details->find($detailId);
		$detail->setDebitItemId($debitItemId);
		$detail->setOpenItemId(null);
		$detail->setStatus($status);
		$detail->setDecidedAt($this->now());
		$detail->setDecidedBy($this->currentUid());
		return $this->details->update($detail);
	}

	// --- Verbuchung ----------------------------------------------------------------

	/**
	 * Bucht einen Bankumsatz, dessen SEPA-Detail-Zeilen vollständig beurteilt
	 * sind (Spec §5 „Verbuchung setzt voraus, dass alle Detail-Vorschläge
	 * beurteilt sind").
	 *
	 * @return array{settled:int, returned:int}
	 * @throws DoesNotExistException wenn es den Bankumsatz nicht (mehr) gibt
	 * @throws \InvalidArgumentException wenn noch nicht alle Detail-Zeilen
	 *                                   beurteilt sind, es keine zuzuordnenden Zeilen gibt oder Geldein-/
	 *                                   ausgang gemischt zugeordnet wurden
	 */
	public function settle(int $bankTxId): array {
		$tx = $this->txMapper->find($bankTxId, Application::BOOK);
		$details = $this->details->findByBankTx($bankTxId);
		if ($details === []) {
			throw new \InvalidArgumentException($this->l10n->t('Für diesen Bankumsatz gibt es keine SEPA-Detail-Zeilen.'));
		}
		foreach ($details as $detail) {
			if (!$detail->isDecided()) {
				throw new \InvalidArgumentException($this->l10n->t('Es sind noch nicht alle Detail-Vorschläge dieses Bankumsatzes beurteilt.'));
			}
		}

		$assigned = array_values(array_filter($details, static fn (BankTxSepaDetail $d): bool => $d->getStatus() === BankTxSepaDetail::STATUS_ASSIGNED && $d->getDebitItemId() !== null));
		if ($assigned === []) {
			throw new \InvalidArgumentException($this->l10n->t('Kein zugeordneter Einzugsposten – nichts zu verbuchen.'));
		}

		$returns = array_values(array_filter($assigned, static fn (BankTxSepaDetail $d): bool => $d->getIsReturn()));
		$settlements = array_values(array_filter($assigned, static fn (BankTxSepaDetail $d): bool => !$d->getIsReturn()));
		if ($returns !== [] && $settlements !== []) {
			// In der Praxis bündelt eine Bank Gutschrift und Rückgabe nie in
			// derselben Buchung (siehe Klassendoc) - lieber kontrolliert
			// abbrechen als eine Seite stillschweigend zu verwerfen.
			throw new \InvalidArgumentException($this->l10n->t('Dieser Bankumsatz enthält sowohl zugeordnete Gutschriften als auch Rücklastschriften – das kann nicht in einem Schritt verbucht werden.'));
		}

		return $this->transaction->run(function () use ($tx, $returns, $settlements): array {
			if ($returns !== []) {
				return ['settled' => 0, 'returned' => $this->bookReturns($tx, $returns)];
			}
			return ['settled' => $this->bookSettlements($tx, $settlements), 'returned' => 0];
		});
	}

	/**
	 * Sammelbuchung des Einzugs (Spec §3.10): der bestehende
	 * `BookingService::assignParts()`-Pfad wird wiederverwendet, Split
	 * gruppiert nach `account_id`. Schließt anschließend jede beteiligte
	 * Forderung ab, verknüpft mit der entstandenen Sammelbuchung.
	 *
	 * @param BankTxSepaDetail[] $settlements
	 */
	private function bookSettlements(BankTransaction $tx, array $settlements): int {
		/** @var list<array{debitItem:DebitItem, openItem:OpenItem}> $rows */
		$rows = [];
		$partsByAccount = [];
		foreach ($settlements as $detail) {
			$debitItem = $this->debitItems->find((int)$detail->getDebitItemId());
			$openItem = $this->openItems->find($debitItem->getOpenItemId());
			$accountId = $this->revenueAccountId($openItem);
			$partsByAccount[$accountId] = ($partsByAccount[$accountId] ?? 0) + $debitItem->getAmountCents();
			$rows[] = ['debitItem' => $debitItem, 'openItem' => $openItem];
		}

		$parts = [];
		foreach ($partsByAccount as $accountId => $amountCents) {
			$parts[] = ['accountId' => $accountId, 'amountCents' => $amountCents];
		}
		$tx = $this->bookingService->assignParts($tx, $parts);

		foreach ($rows as $row) {
			$openItem = $row['openItem'];
			$openItem->setStatus('paid');
			$openItem->setPaidJournalId($tx->getJournalId());
			$openItem->setSettledAt($this->now());
			$openItem->setSettledBy($this->currentUid());
			$this->openItems->update($openItem);
		}

		$this->audit->log('SEPA-Sammeleinzug per Bankumsatz verbucht', 'bank_tx', (int)$tx->getId(), [
			'anzahl' => count($settlements),
			'summe' => array_sum(array_map(static fn (BankTxSepaDetail $d) => $d->getAmountCents(), $settlements)) / 100,
			'journal' => $tx->getJournalId(),
		]);
		return count($settlements);
	}

	/**
	 * Rücklastschrift-Buchung (Spec §3.10): zwei Gegenkonto-Zeilen – OAMT
	 * zurück auf das ursprüngliche Erlöskonto der Forderung, COAM auf das
	 * konfigurierbare Rücklastschriftgebühren-Konto. Fehlt der Bank-Beleg zur
	 * Bankgebühr, entfällt die zweite Zeile (siehe splitReturnAmount()).
	 *
	 * @param BankTxSepaDetail[] $returns
	 * @throws \InvalidArgumentException wenn eine Bankgebühr bekannt ist, aber
	 *                                   kein Rücklastschriftgebühren-Konto eingestellt ist
	 */
	private function bookReturns(BankTransaction $tx, array $returns): int {
		$totalAbsCents = abs($tx->getAmountCents());
		$totalCharges = 0;
		$byAccount = [];
		/** @var array<int, array{debitItem:DebitItem, openItem:OpenItem, detail:BankTxSepaDetail}> $rows */
		$rows = [];
		foreach ($returns as $detail) {
			$debitItem = $this->debitItems->find((int)$detail->getDebitItemId());
			if ($this->returnedDebits->findByDebitItem((int)$debitItem->getId()) !== null) {
				// Posten-Guard (Spec §3.6/§5): Dubletten verpuffen still - diese
				// Zeile wurde (z. B. durch einen doppelten Import) bereits als
				// Rücklastschrift verbucht.
				continue;
			}
			$openItem = $this->openItems->find($debitItem->getOpenItemId());
			$rows[] = ['debitItem' => $debitItem, 'openItem' => $openItem, 'detail' => $detail];
			$totalCharges += max(0, $detail->getChargesCents() ?? 0);
		}
		if ($rows === []) {
			return 0;
		}

		$feeAccountId = $this->settings->returnFeeAccountId();
		if ($totalCharges > 0 && $feeAccountId === null) {
			throw new \InvalidArgumentException($this->l10n->t('Bitte zuerst das Rücklastschriftgebühren-Konto in den Einstellungen hinterlegen.'));
		}

		foreach ($rows as $row) {
			$openItem = $row['openItem'];
			$accountId = $this->revenueAccountId($openItem);
			// OAMT = eigener Anteil dieser Detail-Zeile abzüglich ihrer eigenen
			// Bankgebühr (nicht des Gesamt-Umsatzes) - bei mehreren gebündelten
			// Rückgaben in einer Buchung trägt jede Zeile ihre eigene Gebühr.
			$originalCents = abs($row['detail']->getAmountCents()) - max(0, $row['detail']->getChargesCents() ?? 0);
			$byAccount[$accountId] = ($byAccount[$accountId] ?? 0) + max(0, $originalCents);
		}
		$parts = [];
		foreach ($byAccount as $accountId => $amountCents) {
			if ($amountCents > 0) {
				$parts[] = ['accountId' => $accountId, 'amountCents' => $amountCents];
			}
		}
		if ($totalCharges > 0) {
			$parts[] = ['accountId' => $feeAccountId, 'amountCents' => $totalCharges];
		}

		$tx = $this->bookingService->assignParts($tx, $parts);

		$rechargeEnabled = $this->settings->isReturnFeeRechargeEnabled();
		foreach ($rows as $row) {
			$this->finalizeReturn($tx, $row['debitItem'], $row['openItem'], $row['detail'], $rechargeEnabled, $feeAccountId);
		}

		$this->audit->log('SEPA-Rücklastschrift per Bankumsatz verbucht', 'bank_tx', (int)$tx->getId(), [
			'anzahl' => count($rows),
			'summe' => $totalAbsCents / 100,
			'gebuehren' => $totalCharges / 100,
			'journal' => $tx->getJournalId(),
		]);
		return count($rows);
	}

	private function finalizeReturn(BankTransaction $tx, DebitItem $debitItem, OpenItem $openItem, BankTxSepaDetail $detail, bool $rechargeEnabled, ?int $feeAccountId): void {
		$returned = new ReturnedDebit();
		$returned->setDebitItemId((int)$debitItem->getId());
		$returned->setBankTxSepaDetailId((int)$detail->getId());
		$returned->setReasonCode($detail->getReturnReasonCode());
		$returned->setReasonText($detail->getReturnReasonText());
		$returned->setReceivedAt((string)$tx->getBookingDate());
		$returned->setSource(ReturnedDebit::SOURCE_IMPORT);
		$returned->setChargesCents($detail->getChargesCents());
		$returned->setJournalId($tx->getJournalId());
		$returned->setCreatedAt($this->now());
		$returned->setCreatedBy($this->currentUid());

		// Rückgabe-Klasse (Spec §3.6, Issue #73): deterministisch aus dem
		// ISO-Rückgabegrund, nicht gespeichert - entscheidet über Gebühren-
		// Weiterbelastung, Mandats-Sperre und Zahlungsaufforderung weiter unten.
		$class = ReturnReasonClassifier::classify($returned->getReasonCode());

		// Gebühren-Weiterbelastung (Spec §3.6/§5): Opt-in, Höhe = exakte
		// Bankgebühr, automatisch nur bei insufficient_funds/account_unusable
		// (Issue #73 verfeinert den einfachen Ja/Nein-Schalter aus #72).
		$chargesCents = $detail->getChargesCents() ?? 0;
		if ($rechargeEnabled && ReturnReasonClassifier::shouldRechargeFeeAutomatically($class) && $chargesCents > 0 && $feeAccountId !== null) {
			$fee = $this->claims->createManual(
				(int)$openItem->getMemberId(),
				OpenItem::TYPE_FEE,
				$chargesCents,
				$this->l10n->t('Gebühr für Rücklastschrift'),
				(string)$tx->getBookingDate(),
				$feeAccountId,
			);
			$returned->setFeeRechargeTriggered(true);
			$returned->setFeeOpenItemId((int)$fee->getId());
		}
		$returned = $this->returnedDebits->insert($returned);

		// "zurückgegeben -> wieder offen" (Spec §2.2): war die Forderung schon
		// als eingezogen/bezahlt vermerkt (Sammelgutschrift bereits vor der
		// Rücklastschrift bestätigt), macht das die Rücklastschrift rückgängig -
		// dieselbe Reopening-Logik wie im alten Mandatssystem
		// (SepaReturnDetectionService::detect()).
		$openItem->setStatus('open');
		$openItem->setPaidJournalId(null);
		$openItem->setSettledAt(null);
		$openItem->setSettledBy(null);
		$openItem->setSettlementNote(null);
		$this->openItems->update($openItem);

		$this->reactToReturn($debitItem, $openItem, $returned, $class);
	}

	/**
	 * Mandats-Sperre + Mahnwesen-Auslöser (Spec §3.6, Issue #73) – kommt NACH
	 * dem Wieder-Öffnen der Forderung, damit
	 * {@see DunningLadderService::triggerPaymentRequest()} eine bereits
	 * korrekt zurückgesetzte Forderung sieht (Erledigungsvermerk weg, Status
	 * `open`). Beides läuft best effort innerhalb derselben Buchungs-
	 * Transaktion wie der Rest von {@see finalizeReturn()} – ein Mailversand-
	 * oder Sperr-Fehler darf die bereits korrekt gebuchte Rücklastschrift
	 * nicht rückgängig machen (dieselbe Haltung wie
	 * {@see \OCA\Vereinsbuchhaltung\Service\MandateService::notifyRevocationDunning()}).
	 */
	private function reactToReturn(DebitItem $debitItem, OpenItem $openItem, ReturnedDebit $returned, string $class): void {
		if (ReturnReasonClassifier::shouldSuspendMandate($class)) {
			try {
				$this->mandates->suspendDueToReturnedDebit(
					$debitItem->getMandateId(),
					(int)$returned->getId(),
					$this->l10n->t('Automatisch nach Rücklastschrift: %s', [ReturnReasonClassifier::memberFacingReason($class, $this->l10n)]),
				);
			} catch (\Throwable $e) {
				$this->audit->log('Mahnwesen: automatische Mandats-Sperre nach Rücklastschrift fehlgeschlagen', 'bank_tx', (int)$returned->getBankTxSepaDetailId(), ['fehler' => $e->getMessage()]);
			}
		}
		if (ReturnReasonClassifier::shouldTriggerPaymentRequest($class)) {
			try {
				$this->dunningLadder->triggerPaymentRequest($openItem, ReturnReasonClassifier::memberFacingReason($class, $this->l10n));
			} catch (\Throwable $e) {
				$this->audit->log('Mahnwesen: Zahlungsaufforderung nach Rücklastschrift fehlgeschlagen', 'open_item', (int)$openItem->getId(), ['fehler' => $e->getMessage()]);
			}
		}
	}

	/**
	 * Bestätigt einen Zuordnungs-Vorschlag aus {@see IncomingPaymentMatchingService}
	 * (Spec §2.2 „Zuordnungs-Vorschlag ... Bestätigung setzt settlement_type:
	 * paid mit Buchungsreferenz"): bucht den Bankumsatz über den bestehenden,
	 * generischen `BookingService::assign()`-Pfad (ein einzelnes Konto, kein
	 * Split nötig) und schließt die Forderung mit der entstandenen Buchung als
	 * Zahlungsnachweis ab.
	 *
	 * @throws DoesNotExistException wenn es den Bankumsatz oder die Forderung nicht (mehr) gibt
	 * @throws \InvalidArgumentException wenn die Forderung kein Erlöskonto hat
	 *                                   und keines eingestellt ist
	 */
	public function confirmIncomingPayment(int $bankTxId, int $openItemId): OpenItem {
		$tx = $this->txMapper->find($bankTxId, Application::BOOK);
		$openItem = $this->openItems->find($openItemId);
		$accountId = $this->revenueAccountId($openItem);

		return $this->transaction->run(function () use ($tx, $openItem, $accountId): OpenItem {
			$tx = $this->bookingService->assign($tx, $accountId);
			$openItem->setStatus('paid');
			$openItem->setPaidJournalId($tx->getJournalId());
			$openItem->setSettledAt($this->now());
			$openItem->setSettledBy($this->currentUid());
			$openItem = $this->openItems->update($openItem);

			$this->audit->log('Zahlungseingang einer Forderung zugeordnet', 'bank_tx', (int)$tx->getId(), [
				'forderung' => $openItem->getId(),
				'betrag' => $openItem->getAmountCents() / 100,
				'journal' => $tx->getJournalId(),
			]);
			return $openItem;
		});
	}

	/**
	 * Erlöskonto der Forderung – die Forderung selbst gewinnt, sonst greift
	 * das Standard-Erlöskonto aus den Einstellungen (siehe
	 * {@see SepaImportSettingsService}-Klassendoc: automatisch erzeugte
	 * Beitragsforderungen tragen bislang kein eigenes `account_id`).
	 *
	 * @throws \InvalidArgumentException wenn keines von beiden gesetzt ist
	 */
	private function revenueAccountId(OpenItem $openItem): int {
		$accountId = $openItem->getAccountId() ?? $this->settings->contributionDefaultAccountId();
		if ($accountId === null) {
			throw new \InvalidArgumentException($this->l10n->t('Forderung #%d hat kein Erlöskonto, und es ist kein Standard-Erlöskonto für Beiträge eingestellt.', [(int)$openItem->getId()]));
		}
		return $accountId;
	}

	private function currentUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}

	private function now(): string {
		return $this->time->getDateTime()->format('Y-m-d H:i:s');
	}
}
