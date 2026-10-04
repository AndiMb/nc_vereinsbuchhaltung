<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Account;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetail;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetailMapper;
use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCA\Vereinsbuchhaltung\Db\DebitBatchMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItem;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\IncomingPaymentRejection;
use OCA\Vereinsbuchhaltung\Db\IncomingPaymentRejectionMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Exception\PeriodClosedException;
use OCA\Vereinsbuchhaltung\Exception\SettlementBlockedException;
use OCA\Vereinsbuchhaltung\Service\AccountService;
use OCA\Vereinsbuchhaltung\Service\BookingService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Die Arbeitsliste des Bankabgleichs (Issue #105, Spec §3.6/§3.10/§5): „der
 * Bankauszug ist die Wahrheit" – alles, was aus dem Import an Vorschlägen
 * entstanden ist und auf ein menschliches Urteil wartet, an EINER Stelle, in
 * der Form, die die Oberfläche braucht. Hier wird nichts geschrieben (außer
 * {@see rejectIncomingPayment()}) und nichts gebucht: Urteile fällt
 * {@see SepaImportConfirmationService}, Vorschläge rechnen
 * {@see SepaMatchingService} und {@see IncomingPaymentMatchingService}.
 *
 * Drei Arten von Umsätzen warten auf ein Urteil:
 * - Einzugsgutschrift (Sammelgutschrift des eigenen Einzugs): Einzelurteil je
 *   Detail-Zeile, Verbuchen erst, wenn alle beurteilt sind (Spec §5 „Sammler");
 * - Rücklastschrift: dasselbe, mit Rückgabe-Klasse und ihren Folgen
 *   ({@see ReturnReasonClassifier});
 * - Zahlungseingang: eine Gutschrift ohne SEPA-Bezug, deren Betrag auf eine
 *   offene Forderung passt ({@see IncomingPaymentMatchingService}).
 *
 * **Warum nicht `SepaImportController::pending()`:** der liefert nur Zeilen
 * OHNE Urteil. Ist die letzte Zeile eines Sammlers beurteilt, fiele der Umsatz
 * aus dieser Liste – und es gäbe nichts mehr, woran man „Verbuchen" auslöst.
 * Die Arbeitsliste hier führt einen Umsatz, bis er gebucht ist.
 *
 * **Was als SEPA-Umsatz gilt:** eine Detail-Zeile ist nur dann „SEPA-relevant",
 * wenn sie eine Rückgabe ist, eine Mandatsreferenz oder Sammlerreferenz trägt,
 * schon zugeordnet wurde oder ein Einzugsposten zu ihr passt. Eine gewöhnliche
 * Überweisung trägt in camt oft nur eine `EndToEndId` („NOTPROVIDED"), die
 * ebenfalls eine Detail-Zeile ergibt – sie gehört nicht in die Liste der
 * Einzüge und Rückgaben (sie kann als Zahlungseingang erscheinen).
 *
 * **Rücklastschrift-Code nur für `buchhalter`/`verwalter`** (Spec §3.6 „Codes
 * bleiben admin-only"): ISO-Code und Bank-Freitext stehen nur in der Antwort,
 * wenn der Aufrufer `$withReturnCodes` setzt; die Klasse (Klartext in der
 * Oberfläche) bekommt jeder.
 */
class BankReconciliationService {

	public const KIND_COLLECTION = 'collection';
	public const KIND_RETURN = 'return';
	public const KIND_MIXED = 'mixed';

	/** Mindestens eine Detail-Zeile ohne Urteil. */
	public const STATE_OPEN = 'offen';
	/** Alles beurteilt, mindestens eine Zeile zugeordnet – bereit zum Verbuchen. */
	public const STATE_READY = 'bereit';
	/** Alles beurteilt, aber nichts zugeordnet: bleibt für die Zuordnung von Hand. */
	public const STATE_NO_MATCH = 'ohne_zuordnung';

	/** Die Periode des Bankumsatzes ist abgeschlossen. */
	public const BLOCKER_PERIOD_CLOSED = 'period_closed';
	/** Die zugeordneten Posten ergeben nicht den Betrag des Bankumsatzes. */
	public const BLOCKER_SUM_MISMATCH = 'sum_mismatch';
	/** Das Gebührenkonto ist zugleich eines der Erlöskonten dieser Buchung. */
	public const BLOCKER_FEE_ACCOUNT_CONFLICT = 'fee_account_conflict';
	/** Die Aufteilung ergibt aus anderem Grund keine gültige Buchung. */
	public const BLOCKER_INVALID_SPLIT = 'invalid_split';
	/** Das Geldkonto fehlt im Kontenrahmen. */
	public const BLOCKER_NO_BANK_ACCOUNT = 'no_bank_account';
	/** Alle zugeordneten Rückgaben sind schon verbucht (Posten-Guard) – nichts mehr zu tun. */
	public const BLOCKER_NOTHING_TO_BOOK = 'nothing_to_book';

	/** So viele unzugeordnete Umsätze prüft die Liste der Zahlungseingänge höchstens (neueste zuerst). */
	private const INCOMING_SCAN_LIMIT = 500;

	public function __construct(
		private BankTxSepaDetailMapper $details,
		private BankTransactionMapper $txMapper,
		private SepaMatchingService $matching,
		private IncomingPaymentMatchingService $incomingPayments,
		private IncomingPaymentRejectionMapper $rejections,
		private SepaImportConfirmationService $confirmation,
		private SepaImportSettingsService $settings,
		private DebitItemMapper $debitItems,
		private DebitBatchMapper $batches,
		private OpenItemMapper $openItems,
		private MandateMapper $mandates,
		private MemberMapper $members,
		private AccountMapper $accounts,
		private AccountService $accountService,
		private PeriodService $periods,
		private IUserSession $userSession,
		private ITimeFactory $time,
		private IL10N $l10n,
	) {
	}

	// --- Arbeitsliste ----------------------------------------------------------------

	/**
	 * @param bool $withReturnCodes ISO-Rückgabecode und Bank-Freitext mitliefern (nur `buchhalter`/`verwalter`)
	 * @return array{items:list<array<string,mixed>>, incoming:list<array<string,mixed>>}
	 */
	public function worklist(bool $withReturnCodes): array {
		$detailsByTx = [];
		foreach ($this->details->findOnUnassignedTransactions(Application::BOOK) as $detail) {
			$detailsByTx[$detail->getBankTxId()][] = $detail;
		}
		$txs = $detailsByTx === [] ? [] : $this->txMapper->findByIds(Application::BOOK, array_keys($detailsByTx));

		// Erst die Vorschläge je Zeile, dann die Posten dazu in EINER Abfrage je Tabelle.
		/** @var array<int,list<array{detail:BankTxSepaDetail, candidates:list<array{debitItemId:int, stage:int, reason:string}>}>> $entries */
		$entries = [];
		$itemIds = [];
		foreach ($detailsByTx as $txId => $txDetails) {
			$tx = $txs[$txId] ?? null;
			if ($tx === null) {
				continue;
			}
			$rows = [];
			$relevant = false;
			foreach ($txDetails as $detail) {
				$candidates = $this->matching->candidatesFor($detail, $tx);
				$relevant = $relevant || $this->isSepaRelevant($detail, $candidates);
				foreach ($candidates as $candidate) {
					$itemIds[] = $candidate['debitItemId'];
				}
				if ($detail->getDebitItemId() !== null) {
					$itemIds[] = $detail->getDebitItemId();
				}
				$rows[] = ['detail' => $detail, 'candidates' => $candidates];
			}
			if ($relevant) {
				$entries[$txId] = $rows;
			}
		}

		$items = $this->debitItems->findByIds($itemIds);
		$openItems = $this->openItems->findByIds(array_values(array_map(static fn (DebitItem $i): int => $i->getOpenItemId(), $items)));
		$batches = [];
		foreach ($this->batches->findAll() as $batch) {
			$batches[(int)$batch->getId()] = $batch;
		}
		$memberNames = $this->memberNames();
		$returnSettings = $this->returnSettings();

		$result = [];
		foreach ($entries as $txId => $rows) {
			$tx = $txs[$txId];
			$total = count($rows);
			$judged = 0;
			$assigned = 0;
			$returns = 0;
			$detailViews = [];
			foreach ($rows as $row) {
				$detail = $row['detail'];
				$judged += $detail->isDecided() ? 1 : 0;
				$assigned += $detail->getStatus() === BankTxSepaDetail::STATUS_ASSIGNED ? 1 : 0;
				$returns += $detail->getIsReturn() ? 1 : 0;
				$detailViews[] = $this->detailView($detail, $row['candidates'], $items, $openItems, $batches, $memberNames, $returnSettings, $withReturnCodes);
			}
			$result[] = [
				'bankTx' => $this->txView($tx),
				'kind' => $returns === 0 ? self::KIND_COLLECTION : ($returns === $total ? self::KIND_RETURN : self::KIND_MIXED),
				'state' => $judged < $total ? self::STATE_OPEN : ($assigned > 0 ? self::STATE_READY : self::STATE_NO_MATCH),
				'total' => $total,
				'judged' => $judged,
				'assigned' => $assigned,
				'details' => $detailViews,
			];
		}

		// Was am längsten wartet, zuerst; Umsätze ohne Zuordnung ans Ende (sie brauchen kein weiteres Urteil).
		$rank = [self::STATE_OPEN => 0, self::STATE_READY => 1, self::STATE_NO_MATCH => 2];
		usort($result, static fn (array $a, array $b): int => [$rank[$a['state']], $a['bankTx']['bookingDate'], $a['bankTx']['id']] <=> [$rank[$b['state']], $b['bankTx']['bookingDate'], $b['bankTx']['id']]);

		// Zahlungseingänge: unzugeordnete Gutschriften, die nicht schon als Einzug/Rückgabe anstehen.
		$pendingIds = [];
		foreach ($result as $item) {
			if ($item['state'] !== self::STATE_NO_MATCH) {
				$pendingIds[$item['bankTx']['id']] = true;
			}
		}
		$incoming = $this->incomingList($pendingIds, $openItems, $memberNames);

		return ['items' => $result, 'incoming' => $incoming];
	}

	/**
	 * @param list<array{debitItemId:int, stage:int, reason:string}> $candidates
	 */
	private function isSepaRelevant(BankTxSepaDetail $detail, array $candidates): bool {
		return $detail->getIsReturn()
			|| $detail->getMandateReference() !== null
			|| $detail->getBatchReference() !== null
			|| $detail->getStatus() === BankTxSepaDetail::STATUS_ASSIGNED
			|| $candidates !== [];
	}

	/**
	 * Zahlungseingänge mit Vorschlägen – Umsätze, die schon als Einzug oder
	 * Rückgabe anstehen, bleiben draußen ($excludeTxIds), damit keiner zweimal
	 * auftaucht.
	 *
	 * @param array<int,true> $excludeTxIds
	 * @param array<int,OpenItem> $knownOpenItems schon geladene Forderungen (werden nicht erneut gelesen)
	 * @param array<int,string> $memberNames
	 * @return list<array<string,mixed>>
	 */
	private function incomingList(array $excludeTxIds, array $knownOpenItems, array $memberNames): array {
		$txs = array_values(array_filter(
			$this->txMapper->findFiltered(Application::BOOK, 'unassigned', self::INCOMING_SCAN_LIMIT),
			static fn (BankTransaction $tx): bool => $tx->getAmountCents() > 0 && !isset($excludeTxIds[(int)$tx->getId()]),
		));
		$suggestionsByTx = $this->incomingPayments->suggestForMany($txs);
		if ($suggestionsByTx === []) {
			return [];
		}

		$wantedIds = [];
		foreach ($suggestionsByTx as $suggestions) {
			foreach ($suggestions as $suggestion) {
				$wantedIds[] = $suggestion['openItemId'];
			}
		}
		$claims = $knownOpenItems + $this->openItems->findByIds(array_values(array_diff(array_unique($wantedIds), array_keys($knownOpenItems))));
		$accountNames = $this->accountNames();

		$list = [];
		foreach ($txs as $tx) {
			$suggestions = $suggestionsByTx[(int)$tx->getId()] ?? [];
			if ($suggestions === []) {
				continue;
			}
			$views = [];
			foreach ($suggestions as $suggestion) {
				$claim = $claims[$suggestion['openItemId']] ?? null;
				if ($claim === null) {
					continue;
				}
				$accountId = $this->confirmation->revenueAccountIdOrNull($claim);
				$views[] = [
					'openItemId' => $suggestion['openItemId'],
					'memberId' => $suggestion['memberId'],
					'memberName' => $this->claimMemberName($claim, $memberNames),
					'description' => $claim->getDescription(),
					'amountCents' => $claim->getAmountCents(),
					'dueDate' => $claim->getDueDate(),
					'reason' => $suggestion['reason'],
					'revenueAccount' => $accountId === null ? null : $this->accountView($accountId, $accountNames),
				];
			}
			if ($views !== []) {
				$list[] = ['bankTx' => $this->txView($tx), 'suggestions' => $views];
			}
		}
		return $list;
	}

	/**
	 * Ein Vorschlag „diese Gutschrift passt nicht zu dieser Forderung" bleibt
	 * abgelehnt: {@see IncomingPaymentMatchingService} schlägt das Paar nicht
	 * wieder vor. Wiederholtes Ablehnen ist folgenlos.
	 *
	 * @throws DoesNotExistException wenn es den Umsatz oder die Forderung nicht (mehr) gibt
	 */
	public function rejectIncomingPayment(int $bankTxId, int $openItemId): void {
		$this->txMapper->find($bankTxId, Application::BOOK);
		$this->openItems->find($openItemId);
		if ($this->rejections->exists($bankTxId, $openItemId)) {
			return;
		}
		$rejection = new IncomingPaymentRejection();
		$rejection->setBankTxId($bankTxId);
		$rejection->setOpenItemId($openItemId);
		$rejection->setRejectedBy($this->userSession->getUser()?->getUID());
		$rejection->setRejectedAt($this->time->getDateTime()->format('Y-m-d H:i:s'));
		$this->rejections->insert($rejection);
	}

	// --- Vorschau der Buchung --------------------------------------------------------

	/**
	 * Was „Verbuchen" für diesen Bankumsatz täte – dieselbe Rechnung wie die
	 * Verbuchung selbst ({@see SepaImportConfirmationService::plan()}), nur ohne
	 * zu schreiben. Hindernisse (noch nicht alle Zeilen beurteilt, Periode
	 * geschlossen, Aufteilung geht nicht auf …) kommen als `blockers` mit
	 * Code und Text zurück statt als Fehler: die Oberfläche zeigt sie vor dem
	 * Klick, nicht danach.
	 *
	 * Buchungsdatum ist das Datum des Bankumsatzes (Spec §3.10): die Periode
	 * wird gegen dieses Datum geprüft, nicht gegen die des ursprünglichen
	 * Einzugs.
	 *
	 * @param bool $withReturnCodes ISO-Rückgabecode und Bank-Freitext mitliefern (nur `buchhalter`/`verwalter`)
	 * @return array<string,mixed>
	 * @throws DoesNotExistException wenn es den Bankumsatz nicht (mehr) gibt
	 */
	public function settlementPreview(int $bankTxId, bool $withReturnCodes): array {
		$tx = $this->txMapper->find($bankTxId, Application::BOOK);
		$preview = [
			'bankTx' => $this->txView($tx),
			'bookingDate' => $tx->getBookingDate(),
			'amountCents' => abs($tx->getAmountCents()),
			'direction' => null,
			'bank' => null,
			'lines' => [],
			'rows' => [],
			'blockers' => [],
			'warnings' => [],
		];

		try {
			$plan = $this->confirmation->plan($tx);
		} catch (SettlementBlockedException $e) {
			$preview['blockers'][] = ['code' => $e->reason, 'message' => $e->getMessage()];
			return $preview;
		}
		$preview['direction'] = $plan['direction'];

		$accountNames = $this->accountNames();
		$memberNames = $this->memberNames();
		$isReturn = $plan['direction'] === SepaImportConfirmationService::DIRECTION_RETURN;

		if ($plan['rows'] === []) {
			$preview['blockers'][] = [
				'code' => self::BLOCKER_NOTHING_TO_BOOK,
				'message' => $this->l10n->t('Alle zugeordneten Posten dieses Umsatzes sind bereits als Rücklastschrift verbucht.'),
			];
			return $preview;
		}

		// Geldkonto: dieselbe Auflösung wie bei der Buchung (BookingService::doAssign()).
		try {
			$bank = $this->accountService->resolveBankAccount(Application::BOOK, $tx->getOwnAccount());
			$preview['bank'] = $this->accountView((int)$bank->getId(), $accountNames);
		} catch (DoesNotExistException $e) {
			$preview['blockers'][] = ['code' => self::BLOCKER_NO_BANK_ACCOUNT, 'message' => $e->getMessage()];
		}

		// Zeilen: Gutschrift = Bank im Soll, Erlöskonten im Haben; Rücklastschrift umgekehrt.
		$partCount = count($plan['parts']);
		foreach ($plan['parts'] as $index => $part) {
			$isFee = $isReturn && $plan['chargesCents'] > 0 && $index === $partCount - 1;
			$preview['lines'][] = $this->accountView($part['accountId'], $accountNames) + [
				'side' => $isReturn ? 'soll' : 'haben',
				'role' => $isFee ? 'fee' : ($isReturn ? 'revenue_back' : 'revenue'),
				'amountCents' => $part['amountCents'],
			];
		}

		$split = BookingService::validateParts($plan['parts'], abs($tx->getAmountCents()));
		if ($split !== null) {
			$preview['blockers'][] = $this->splitBlocker($split, $isReturn);
		}

		try {
			$this->periods->assertOpen(Application::BOOK, (string)$tx->getBookingDate());
		} catch (PeriodClosedException $e) {
			$preview['blockers'][] = ['code' => self::BLOCKER_PERIOD_CLOSED, 'message' => $e->getMessage()];
		}

		$returnSettings = $this->returnSettings();
		foreach ($plan['rows'] as $row) {
			$view = [
				'detailId' => (int)$row['detail']->getId(),
				'debitItemId' => (int)$row['debitItem']->getId(),
				'openItemId' => (int)$row['openItem']->getId(),
				'memberName' => $this->claimMemberName($row['openItem'], $memberNames),
				'description' => $row['openItem']->getDescription(),
				'amountCents' => $row['debitItem']->getAmountCents(),
				'account' => $this->accountView($row['accountId'], $accountNames),
				'return' => null,
			];
			if ($isReturn) {
				$mandate = $this->mandates->findOrNull($row['debitItem']->getMandateId());
				$view['return'] = $this->returnInfo($row['detail'], $returnSettings, $withReturnCodes, $mandate?->getStatus() === Mandate::STATUS_ACTIVE);
			}
			$preview['rows'][] = $view;
		}

		if ($isReturn) {
			$skipped = $this->countAssignedReturns($tx) - count($plan['rows']);
			if ($skipped > 0) {
				$preview['warnings'][] = [
					'code' => 'already_returned',
					'count' => $skipped,
				];
			}
		}
		return $preview;
	}

	private function countAssignedReturns(BankTransaction $tx): int {
		return count(array_filter(
			$this->details->findByBankTx((int)$tx->getId()),
			static fn (BankTxSepaDetail $d): bool => $d->getIsReturn() && $d->getStatus() === BankTxSepaDetail::STATUS_ASSIGNED,
		));
	}

	/**
	 * @param array{code:string, params?:array<string,int>} $error aus {@see BookingService::validateParts()}
	 * @return array{code:string, message:string}
	 */
	private function splitBlocker(array $error, bool $isReturn): array {
		$params = $error['params'] ?? [];
		$euro = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.');
		return match ($error['code']) {
			'too_little' => [
				'code' => self::BLOCKER_SUM_MISMATCH,
				'message' => $this->l10n->t(
					'Die zugeordneten Posten ergeben %1$s €, der Bankumsatz beträgt %2$s €: %3$s € sind keinem Posten zugeordnet. Ordnen Sie die übrigen Zeilen zu, oder buchen Sie den Umsatz unter Buchungen von Hand.',
					[$euro($params['sum'] ?? 0), $euro($params['total'] ?? 0), $euro($params['diff'] ?? 0)],
				),
			],
			'too_much' => [
				'code' => self::BLOCKER_SUM_MISMATCH,
				'message' => $this->l10n->t(
					'Die zugeordneten Posten ergeben %1$s €, das sind %3$s € mehr als der Bankumsatz über %2$s €. Prüfen Sie die Zuordnung.',
					[$euro($params['sum'] ?? 0), $euro($params['total'] ?? 0), $euro($params['diff'] ?? 0)],
				),
			],
			'duplicate_account' => [
				'code' => self::BLOCKER_FEE_ACCOUNT_CONFLICT,
				'message' => $isReturn
					? $this->l10n->t('Das Rücklastschriftgebühren-Konto ist zugleich das Erlöskonto einer dieser Forderungen. Wählen Sie in den Einstellungen ein eigenes Konto für die Gebühren.')
					: $this->l10n->t('Die Aufteilung nennt dasselbe Konto mehrfach.'),
			],
			default => [
				'code' => self::BLOCKER_INVALID_SPLIT,
				'message' => $this->l10n->t('Aus den zugeordneten Posten lässt sich keine gültige Buchung bilden.'),
			],
		};
	}

	// --- Bausteine -------------------------------------------------------------------

	/**
	 * Nur, was die Oberfläche zeigt: Bankumsätze tragen die IBAN des Zahlers im
	 * Klartext, die der Bankabgleich nirgends braucht.
	 *
	 * @return array<string,mixed>
	 */
	private function txView(BankTransaction $tx): array {
		return [
			'id' => (int)$tx->getId(),
			'bookingDate' => $tx->getBookingDate(),
			'valueDate' => $tx->getValueDate(),
			'amountCents' => $tx->getAmountCents(),
			'amount' => $tx->getAmountCents() / 100,
			'currency' => $tx->getCurrency(),
			'counterparty' => $tx->getCounterparty(),
			'purpose' => $tx->getPurpose(),
			'bookingText' => $tx->getBookingText(),
		];
	}

	/**
	 * @param list<array{debitItemId:int, stage:int, reason:string}> $candidates
	 * @param array<int,DebitItem> $items
	 * @param array<int,OpenItem> $openItems
	 * @param array<int,DebitBatch> $batches
	 * @param array<int,string> $memberNames
	 * @param array{rechargeEnabled:bool, feeAccountId:?int} $returnSettings
	 * @return array<string,mixed>
	 */
	private function detailView(BankTxSepaDetail $detail, array $candidates, array $items, array $openItems, array $batches, array $memberNames, array $returnSettings, bool $withReturnCodes): array {
		$candidateViews = [];
		foreach ($candidates as $candidate) {
			$view = $this->itemView($candidate['debitItemId'], $items, $openItems, $batches, $memberNames);
			if ($view !== null) {
				// Die Begründung zeigt die Oberfläche in Klartext nach Stufe (kein Konfidenz-Wert, Spec §5).
				$candidateViews[] = $view + ['stage' => $candidate['stage']];
			}
		}
		$assignedItem = $detail->getDebitItemId() === null ? null : $this->itemView($detail->getDebitItemId(), $items, $openItems, $batches, $memberNames);

		return [
			'id' => (int)$detail->getId(),
			'detailIndex' => $detail->getDetailIndex(),
			'endToEndId' => $detail->getEndToEndId(),
			'mandateReference' => $detail->getMandateReference(),
			'amountCents' => $detail->getAmountCents(),
			'chargesCents' => $detail->getChargesCents(),
			'isReturn' => $detail->getIsReturn(),
			// `text_heuristik`: aus dem Buchungstext erkannt statt aus strukturierten Feldern – verdient einen genaueren Blick.
			'detectionSource' => $detail->getDetectionSource(),
			'status' => $detail->getStatus(),
			'debitItemId' => $detail->getDebitItemId(),
			'decidedAt' => $detail->getDecidedAt(),
			'return' => $detail->getIsReturn() ? $this->returnInfo($detail, $returnSettings, $withReturnCodes, null) : null,
			'candidates' => $candidateViews,
			'assignedItem' => $assignedItem,
		];
	}

	/**
	 * Der Einzugsposten hinter einem Vorschlag, so wie ihn ein Mensch erkennt:
	 * Mitglied, Forderung, Betrag, Einzugstermin. Null, wenn Posten oder
	 * Forderung inzwischen fehlen (z. B. nach einem Zurücksetzen des Bestands).
	 *
	 * @param array<int,DebitItem> $items
	 * @param array<int,OpenItem> $openItems
	 * @param array<int,DebitBatch> $batches
	 * @param array<int,string> $memberNames
	 * @return array<string,mixed>|null
	 */
	private function itemView(int $debitItemId, array $items, array $openItems, array $batches, array $memberNames): ?array {
		$item = $items[$debitItemId] ?? null;
		$claim = $item === null ? null : ($openItems[$item->getOpenItemId()] ?? null);
		if ($item === null || $claim === null) {
			return null;
		}
		$batch = $batches[$item->getBatchId()] ?? null;
		return [
			'debitItemId' => $debitItemId,
			'memberId' => $claim->getMemberId(),
			'memberName' => $this->claimMemberName($claim, $memberNames),
			'description' => $claim->getDescription() ?? $item->getRemittanceInfo(),
			'amountCents' => $item->getAmountCents(),
			'dueDate' => $batch?->getDueDate(),
			'batchStatus' => $batch?->getStatus(),
			'endToEndId' => $item->getEndToEndId(),
			'mandateReference' => $item->getMandateReference(),
		];
	}

	/**
	 * Rückgabe-Klasse und ihre automatischen Folgen (Spec §3.6): die Klasse
	 * als Schlüssel (den Klartext schreibt die Oberfläche), dazu je Folge ein
	 * Ja/Nein. Mit `$mandateActive` (nur in der Vorschau, wo das Mandat des
	 * Postens bekannt ist) sperrt die Verbuchung ein Mandat nur, wenn es noch
	 * aktiv ist – {@see \OCA\Vereinsbuchhaltung\Service\MandateService::suspendDueToReturnedDebit()}
	 * lässt ein bereits gesperrtes oder erloschenes unangetastet.
	 *
	 * @param array{rechargeEnabled:bool, feeAccountId:?int} $returnSettings
	 * @return array<string,mixed>
	 */
	private function returnInfo(BankTxSepaDetail $detail, array $returnSettings, bool $withReturnCodes, ?bool $mandateActive): array {
		$class = ReturnReasonClassifier::classify($detail->getReturnReasonCode());
		$charges = max(0, $detail->getChargesCents() ?? 0);
		$info = [
			'reasonClass' => $class,
			'suspendsMandate' => ReturnReasonClassifier::shouldSuspendMandate($class) && $mandateActive !== false,
			'paymentRequest' => ReturnReasonClassifier::shouldTriggerPaymentRequest($class),
			'feeClaimCents' => $returnSettings['rechargeEnabled']
				&& ReturnReasonClassifier::shouldRechargeFeeAutomatically($class)
				&& $charges > 0
				&& $returnSettings['feeAccountId'] !== null ? $charges : null,
		];
		if ($withReturnCodes) {
			// Code und Freitext der Bank: nur für die Buchhaltung (Spec §3.6 „Codes bleiben admin-only").
			$info['reasonCode'] = $detail->getReturnReasonCode();
			$info['reasonText'] = $detail->getReturnReasonText();
		}
		return $info;
	}

	/** @return array{rechargeEnabled:bool, feeAccountId:?int} */
	private function returnSettings(): array {
		return [
			'rechargeEnabled' => $this->settings->isReturnFeeRechargeEnabled(),
			'feeAccountId' => $this->settings->returnFeeAccountId(),
		];
	}

	/** @return array<int,string> Mitglieds-ID => Anzeigename, eine Abfrage statt einer je Zeile */
	private function memberNames(): array {
		$names = [];
		foreach ($this->members->findAll() as $member) {
			$names[(int)$member->getId()] = $member->displayName();
		}
		return $names;
	}

	/** @param array<int,string> $memberNames */
	private function claimMemberName(OpenItem $claim, array $memberNames): string {
		$name = $claim->getMemberId() === null ? '' : ($memberNames[$claim->getMemberId()] ?? '');
		return $name !== '' ? $name : $claim->getDebtor();
	}

	/** @return array<int,Account> */
	private function accountNames(): array {
		$byId = [];
		foreach ($this->accounts->findAll(Application::BOOK) as $account) {
			$byId[(int)$account->getId()] = $account;
		}
		return $byId;
	}

	/**
	 * @param array<int,Account> $accounts
	 * @return array{accountId:int, number:string, name:string}
	 */
	private function accountView(int $accountId, array $accounts): array {
		$account = $accounts[$accountId] ?? null;
		return [
			'accountId' => $accountId,
			'number' => $account === null ? '' : (string)$account->getNumber(),
			'name' => $account === null ? '' : (string)$account->getName(),
		];
	}
}
