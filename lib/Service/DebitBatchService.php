<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCA\Vereinsbuchhaltung\Db\DebitBatchMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItem;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateAmendment;
use OCA\Vereinsbuchhaltung\Db\MandateAmendmentMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\Sepa\PainXmlBuilder;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaCreditor;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaReference;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Das Freigabe-Gate des Einzugszyklus (Spec §2.2/§3.5, Issue #71): aus den
 * von {@see DebitRunQueryService} erkannten fälligen Forderungen entsteht bei
 * der Freigabe ein Lastschriftlauf-Snapshot ({@see DebitBatch}/{@see DebitItem})
 * **und** die pain.008-Datei in einem Schritt ({@see release()}); die
 * Einreichung ist ein zweiter, expliziter Schritt ({@see submit()}).
 *
 * „Ein Gate, eine Statusmaschine, keine Automatik, die zweimal Geld anfasst"
 * (Spec §3.5) – dieser Dienst ist bewusst der EINZIGE Schreibzugriff auf
 * `vbh_debit_batches`/`vbh_debit_items`, nach demselben Kapselungsmuster wie
 * {@see MandateService} für `vbh_mandates`.
 */
class DebitBatchService {

	public function __construct(
		private DebitBatchMapper $batchMapper,
		private DebitItemMapper $itemMapper,
		private OpenItemMapper $openItems,
		private DebitRunQueryService $runQuery,
		private MandateMapper $mandates,
		private MandateAmendmentMapper $amendments,
		private MandateService $mandateService,
		private MemberMapper $members,
		private DebitBatchStateMachine $stateMachine,
		private AccountMapper $accountMapper,
		private SepaDebtorAccountService $debtorAccount,
		private PainXmlBuilder $xmlBuilder,
		private DebitBatchXmlStorageService $xmlStorage,
		private TransactionRunner $transaction,
		private AuditService $audit,
		private IUserSession $userSession,
		private IAppConfig $config,
		private IL10N $l10n,
		private ReturnedDebitMapper $returnedDebits,
		private ClubClock $clock,
	) {
	}

	// --- Lesen -----------------------------------------------------------------

	/** @return DebitBatch[] */
	public function findAll(): array {
		return $this->batchMapper->findAll();
	}

	/** @throws DoesNotExistException wenn es den Lauf nicht (mehr) gibt */
	public function find(int $id): DebitBatch {
		return $this->batchMapper->find($id);
	}

	/**
	 * Vorschau auf einen möglichen Lauf – reine Abfrage, siehe
	 * {@see DebitRunQueryService}. Kein Lauf-Datensatz vor {@see release()}.
	 *
	 * @return OpenItem[]
	 */
	public function preview(string $dueDate): array {
		return $this->runQuery->preview($dueDate);
	}

	/** @return array{count:int, sumCents:int} */
	public function summary(string $dueDate): array {
		return $this->runQuery->summary($dueDate);
	}

	/**
	 * Die Zeilen eines Laufs, angereichert um Mitgliedsname/Forderungstext und
	 * den abgeleiteten Forderungszustand (`claimState`, Spec §2.2 – siehe
	 * {@see ClaimStateResolver::resolveForDebitItem()}; bei einem Erledigungs-
	 * vermerk zusätzlich `settlementType` = `paid`/`waived`, bei einer
	 * Rücklastschrift `returnedAt`). Den Rücklastschrift-Code liefert diese
	 * Sicht bewusst nicht aus: Codes bleiben admin-only (Spec §3.6), der
	 * Einzug-Unterreiter ist ab `revisor` lesbar.
	 *
	 * IBAN kommt bereits maskiert aus {@see DebitItem::jsonSerialize()} –
	 * unabhängig von der Rolle des Aufrufers (Spec §3.9 „Einzug-Unterreiter
	 * lesend … IBAN maskiert"), anders als bei {@see MandateController::decorate()}.
	 *
	 * @param string|null $today Stichtag für „im Einzug" vs. „eingezogen", sonst heute (für Tests)
	 * @return array<int,array<string,mixed>>
	 * @throws DoesNotExistException wenn es den Lauf nicht (mehr) gibt
	 */
	public function findItems(int $batchId, ?string $today = null): array {
		$batch = $this->batchMapper->find($batchId);
		$today ??= $this->clock->today();
		$returnedAt = $this->returnedDebits->findReceivedAtByBatch($batchId);
		$rows = [];
		foreach ($this->itemMapper->findByBatch($batchId) as $item) {
			$data = $item->jsonSerialize();
			$claim = null;
			try {
				$claim = $this->openItems->find($item->getOpenItemId());
				$data['memberId'] = $claim->getMemberId();
				$data['memberDisplayName'] = $claim->getMemberId() !== null
					? $this->members->displayNameOr($claim->getMemberId(), $this->l10n->t('(unbekanntes Mitglied)'))
					: null;
			} catch (DoesNotExistException) {
				// Forderung inzwischen geloescht - der Einzugsposten dokumentiert
				// trotzdem, was tatsaechlich eingereicht wurde (Historie).
				$data['memberId'] = null;
				$data['memberDisplayName'] = $this->l10n->t('(Forderung gelöscht)');
			}
			$itemId = (int)$item->getId();
			$data['claimState'] = ClaimStateResolver::resolveForDebitItem($claim, $batch, isset($returnedAt[$itemId]), $today);
			$data['settlementType'] = $data['claimState'] === ClaimStateResolver::STATE_SETTLED ? $claim?->getStatus() : null;
			$data['returnedAt'] = $returnedAt[$itemId] ?? null;
			$rows[] = $data;
		}
		return $rows;
	}

	/**
	 * Abweichungen zwischen dem bei der Freigabe eingefrorenen Schnappschuss
	 * und den *aktuellen* Mandatsdaten (Spec §3.5 „Freigabe→Einreichung-
	 * Fenster … warnt bei Abweichung zu aktuellen Mandatsdaten"). Nur für
	 * einen noch nicht eingereichten Lauf sinnvoll – ein eingereichter Lauf
	 * ist bereits bei der Bank, eine „Abweichung" wäre nachträglich folgenlos.
	 *
	 * Neben den Bankdaten zählt der Zustand (#302): bei der Freigabe war jedes
	 * Mandat einzugsfähig; ist es seitdem widerrufen, gesperrt oder beendet,
	 * zöge die Datei trotzdem ein (`field` = `status`).
	 *
	 * @return list<array{itemId:int, mandateId:int, field:string, snapshot:?string, current:?string}>
	 */
	public function findDrift(int $batchId): array {
		$drift = [];
		foreach ($this->itemMapper->findByBatch($batchId) as $item) {
			$mandate = $this->mandates->findOrNull($item->getMandateId());
			if ($mandate === null) {
				$drift[] = ['itemId' => $item->getId(), 'mandateId' => $item->getMandateId(), 'field' => 'mandate', 'snapshot' => $item->getMandateReference(), 'current' => null];
				continue;
			}
			if (!$mandate->isCollectible()) {
				$drift[] = ['itemId' => $item->getId(), 'mandateId' => (int)$mandate->getId(), 'field' => 'status', 'snapshot' => Mandate::STATUS_ACTIVE, 'current' => $mandate->getStatus()];
			}
			foreach ([
				'iban' => [$item->getIban(), $mandate->getIban()],
				'bic' => [$item->getBic(), $mandate->getBic()],
				'accountHolder' => [$item->getAccountHolder(), $mandate->getAccountHolder()],
			] as $field => [$snapshot, $current]) {
				if ($snapshot !== $current) {
					$drift[] = ['itemId' => $item->getId(), 'mandateId' => (int)$mandate->getId(), 'field' => $field, 'snapshot' => $snapshot, 'current' => $current];
				}
			}
		}
		return $drift;
	}

	/**
	 * Zusammenfassende Warnmeldung für {@see findDrift()}, oder null, wenn nichts
	 * abweicht. Posten ohne einzugsfähiges Mandat nennt sie zuerst und eigens.
	 */
	public function driftWarning(int $batchId): ?string {
		$drift = $this->findDrift($batchId);
		if ($drift === []) {
			return null;
		}
		$blocked = self::nonCollectibleItems($drift);
		$changed = array_diff(array_unique(array_column($drift, 'itemId')), $blocked);
		$parts = [];
		if ($blocked !== []) {
			$parts[] = $this->l10n->n(
				'%n Posten hat kein einzugsfähiges Mandat mehr (widerrufen, gesperrt oder beendet).',
				'%n Posten haben kein einzugsfähiges Mandat mehr (widerrufen, gesperrt oder beendet).',
				count($blocked),
			);
		}
		if ($changed !== []) {
			$parts[] = $this->l10n->n(
				'%n Posten weicht von den aktuellen Mandatsdaten ab.',
				'%n Posten weichen von den aktuellen Mandatsdaten ab.',
				count($changed),
			);
		}
		return implode(' ', $parts);
	}

	/** Anzahl der Posten, deren Mandat nicht mehr einzugsfähig ist oder fehlt (#302). */
	public function nonCollectibleCount(int $batchId): int {
		return count(self::nonCollectibleItems($this->findDrift($batchId)));
	}

	/**
	 * @param list<array{itemId:int, field:string}> $drift
	 * @return list<int>
	 */
	private static function nonCollectibleItems(array $drift): array {
		$ids = [];
		foreach ($drift as $entry) {
			if ($entry['field'] === 'status' || $entry['field'] === 'mandate') {
				$ids[$entry['itemId']] = $entry['itemId'];
			}
		}
		return array_values($ids);
	}

	/** Das pain.008.001.02-XML eines Laufs, byte-identisch nachrenderbar (Spec §3.5). */
	public function renderXml(int $batchId): string {
		$batch = $this->batchMapper->find($batchId);
		$rows = [];
		foreach ($this->itemMapper->findByBatch($batchId) as $item) {
			$rows[] = [
				'endToEndId' => $item->getEndToEndId(),
				'amountCents' => $item->getAmountCents(),
				'sequenceType' => $item->getSequenceType(),
				'mandateReference' => $item->getMandateReference(),
				'signedDate' => $item->getSignedDate(),
				'debtorIban' => $item->getIban(),
				'debtorBic' => $item->getBic(),
				'debtorName' => $item->getAccountHolder(),
				'remittanceInfo' => $item->getRemittanceInfo(),
				'amendmentIndicator' => $item->getAmendmentIndicator(),
				'originalDebtorAccount' => $item->getOriginalDebtorAccount(),
			];
		}
		return $this->xmlBuilder->build($this->creditorOf($batch), $rows);
	}

	// --- Schreiben ---------------------------------------------------------------

	/**
	 * Schritt 1 „Freigeben & Datei erzeugen" (Spec §3.5): Snapshot + pain.008
	 * in einem Zug. Ruft {@see DebitRunQueryService::preview()} selbst noch
	 * einmal frisch auf (statt einer vom Aufrufer mitgelieferten Liste von
	 * Forderungs-IDs) – das IST die geforderte Mandatsprüfung „erneut bei
	 * Freigabe" (die Vorschau prüft {@see DirectDebitEligibilityResolver} für
	 * jede Forderung), und zugleich der Schutz gegen einen doppelten Einzug
	 * (die Vorschau schließt bereits gebündelte Forderungen aus, siehe
	 * {@see DebitRunQueryService}-Klassendoc).
	 *
	 * Bewusst KEINE Prüfung „Termin darf nicht in der Vergangenheit liegen"
	 * (das flache Alt-Modul hatte sie): die Spec verlangt ausdrücklich, dass
	 * eine gerissene Vorlauffrist „nichts blockiert" (§3.5) – ein verspätet
	 * freigegebener Lauf muss möglich bleiben, nur die Aufgabenliste macht
	 * auf die Verspätung aufmerksam.
	 *
	 * @throws \InvalidArgumentException wenn die Grundeinstellungen fehlen, das
	 *                                   Datum ungültig ist oder nichts fällig ist
	 */
	public function release(string $dueDate): DebitBatch {
		$this->assertDate($dueDate);

		$creditorId = trim($this->config->getValueString(Application::APP_ID, 'sepa_creditor_id', ''));
		if ($creditorId === '') {
			throw new \InvalidArgumentException($this->l10n->t('Bitte zuerst die SEPA-Gläubiger-ID in den Einstellungen hinterlegen.'));
		}
		$creditorName = trim($this->config->getValueString(Application::APP_ID, 'club_name', ''));
		if ($creditorName === '') {
			throw new \InvalidArgumentException($this->l10n->t('Bitte zuerst den Vereinsnamen in den Einstellungen hinterlegen.'));
		}
		$debtorAccountId = $this->debtorAccount->getAccountId();
		if ($debtorAccountId === null) {
			throw new \InvalidArgumentException($this->l10n->t('Bitte zuerst das einziehende Konto in den Einstellungen hinterlegen.'));
		}
		try {
			$collectingAccount = $this->accountMapper->find($debtorAccountId, Application::BOOK);
		} catch (DoesNotExistException) {
			throw new \InvalidArgumentException($this->l10n->t('Das eingestellte einziehende Konto wurde nicht gefunden.'));
		}
		if ($collectingAccount->getIban() === null) {
			throw new \InvalidArgumentException($this->l10n->t('Das einziehende Konto hat keine IBAN hinterlegt.'));
		}

		$claims = $this->runQuery->preview($dueDate);
		if ($claims === []) {
			throw new \InvalidArgumentException($this->l10n->t('Keine bis zum %s fälligen offenen Posten mit einzugsfähigem Mandat gefunden.', [$dueDate]));
		}

		$batch = $this->transaction->run(function () use ($claims, $dueDate, $creditorId, $creditorName, $collectingAccount): DebitBatch {
			$batch = new DebitBatch();
			$batch->setDueDate($dueDate);
			$batch->setStatus(DebitBatch::STATUS_RELEASED);
			$batch->setReleasedBy($this->currentUid());
			$batch->setReleasedAt($this->now());
			// Eingefroren, nie mehr neu erzeugt (Spec §3.5 "byte-identisch
			// nachrenderbar") - siehe renderXml()/creditorOf().
			$batch->setMsgId(SepaReference::message());
			// CreDtTm ohne Zonenangabe: Ortszeit des Vereins (#263).
			$batch->setCreationDateTime($this->clock->now()->format('Y-m-d\TH:i:s'));
			$batch->setCreditorId($creditorId);
			$batch->setCreditorName($creditorName);
			$batch->setCreditorIban((string)$collectingAccount->getIban());
			// Bewusst leer: seit der IBAN-only-Umstellung 2016 ermittelt die Bank
			// die BIC selbst, der Builder schreibt dafuer "NOTPROVIDED". Das Konto
			// fuehrt deshalb gar keine BIC. Die Spalte bleibt trotzdem bestehen -
			// fuer Einreichungen ausserhalb des einheitlichen Zahlungsraums, wo
			// die BIC weiterhin verlangt wird.
			$batch->setCreditorBic(null);
			$batch = $this->batchMapper->insert($batch);

			foreach ($claims as $claim) {
				$this->createItemFor($batch, $claim);
			}

			$this->audit->log('Lastschriftlauf freigegeben', 'debit_batch', $batch->getId(), [
				'faelligkeit' => $dueDate,
				'anzahl' => count($claims),
				'summe' => array_sum(array_map(static fn (OpenItem $c) => $c->getAmountCents(), $claims)) / 100,
			]);
			return $batch;
		});

		$this->storeXmlSafely($batch);
		return $batch;
	}

	/**
	 * Baut den Einzugsposten-Schnappschuss einer einzelnen Forderung (Spec
	 * §2.2 „Einzugsposten (Debit Item)"). Das zugehörige Mandat MUSS
	 * einzugsfähig sein – das hat bereits {@see DebitRunQueryService::preview()}
	 * geprüft; die Absicherung hier wirft, statt still einen kaputten
	 * Einzugsposten anzulegen, falls diese Invariante je bricht.
	 */
	private function createItemFor(DebitBatch $batch, OpenItem $claim): DebitItem {
		$mandate = $this->mandates->findLiveByMember((int)$claim->getMemberId())[0] ?? null;
		if ($mandate === null || !$mandate->isCollectible() || $mandate->getIban() === null) {
			throw new \RuntimeException(sprintf(
				'Forderung #%d ohne einzugsfähiges Mandat in der Freigabe – das darf DebitRunQueryService::preview() nicht liefern.',
				(int)$claim->getId(),
			));
		}

		// Amendment-Kennzeichnung (Compliance-Anhang Spec §8): ein zum
		// Freigabezeitpunkt noch offenes Kontowechsel-Amendment dieses Mandats
		// wandert als AmdmntInd/OrgnlDbtrAcct=SMNDA in die pain.008-Zeile. Der
		// Amendment-Status selbst wechselt erst bei submit() auf `transmitted`
		// (Spec §2.2: "steckt in keinem EINGEREICHTEN Einzugsposten").
		$hasAccountAmendment = array_filter(
			$this->amendments->findOpenByMandate((int)$mandate->getId()),
			static fn (MandateAmendment $a): bool => $a->getType() === MandateAmendment::TYPE_ACCOUNT,
		) !== [];

		$item = new DebitItem();
		$item->setBatchId((int)$batch->getId());
		$item->setOpenItemId((int)$claim->getId());
		$item->setMandateId((int)$mandate->getId());
		$item->setAmountCents($claim->getAmountCents());
		$item->setIban((string)$mandate->getIban());
		$item->setBic($mandate->getBic());
		$item->setAccountHolder($mandate->getAccountHolder());
		$item->setMandateReference($mandate->getMandateReference());
		$item->setSignedDate((string)$mandate->getSignedAt());
		$item->setSequenceType(Mandate::SEQUENCE_TYPE);
		$item->setEndToEndId(SepaReference::endToEnd());
		$item->setRemittanceInfo(mb_substr($claim->getDescription() ?? $this->l10n->t('Mitgliedsbeitrag'), 0, 140));
		$item->setAmendmentIndicator($hasAccountAmendment);
		$item->setOriginalDebtorAccount($hasAccountAmendment ? DebitItem::ORIGINAL_DEBTOR_ACCOUNT_SMNDA : null);
		$item->setCreatedAt($this->now());
		return $this->itemMapper->insert($item);
	}

	/**
	 * Schritt 2 „Datei ist bei der Bank eingereicht" (Spec §3.5): terminal,
	 * kein Storno mehr danach. Setzt `last_presented_due_date` an jedem
	 * beteiligten Mandat und schaltet ein zum Freigabezeitpunkt offenes
	 * Kontowechsel-Amendment auf `transmitted` – erst jetzt gilt es der Bank
	 * tatsächlich als mitgeteilt.
	 *
	 * @throws DoesNotExistException wenn es den Lauf nicht (mehr) gibt
	 * @throws \InvalidArgumentException wenn der Lauf nicht (mehr) freigegeben ist
	 */
	public function submit(int $batchId, bool $acknowledgeNonCollectible = false): DebitBatch {
		$batch = $this->batchMapper->find($batchId);
		$this->stateMachine->assertCanSubmit($batch);
		// Ein seit der Freigabe widerrufenes oder gesperrtes Mandat steht noch in
		// der Datei (#302). Ablehnen kann der Server die Einreichung nicht – die
		// Datei liegt womöglich schon bei der Bank –, aber nur wer die Warnung
		// gesehen hat, bestätigt sie: eine vor dem Widerruf geladene Seite nicht.
		$nonCollectible = $this->nonCollectibleCount($batchId);
		if ($nonCollectible > 0 && !$acknowledgeNonCollectible) {
			throw new \InvalidArgumentException($this->l10n->n(
				'%n Posten des Laufs hat kein einzugsfähiges Mandat mehr. Laden Sie die Ansicht neu und prüfen Sie den Lauf: Verwerfen und neu freigeben nimmt den Posten heraus.',
				'%n Posten des Laufs haben kein einzugsfähiges Mandat mehr. Laden Sie die Ansicht neu und prüfen Sie den Lauf: Verwerfen und neu freigeben nimmt die Posten heraus.',
				$nonCollectible,
			));
		}

		return $this->transaction->run(function () use ($batch, $nonCollectible): DebitBatch {
			$items = $this->itemMapper->findByBatch((int)$batch->getId());

			$mandateIds = [];
			foreach ($items as $item) {
				$mandateIds[$item->getMandateId()] = true;
				if (!$item->getAmendmentIndicator()) {
					continue;
				}
				foreach ($this->amendments->findOpenByMandate($item->getMandateId()) as $amendment) {
					if ($amendment->getType() === MandateAmendment::TYPE_ACCOUNT) {
						$this->mandateService->markAmendmentTransmitted((int)$amendment->getId(), (int)$item->getId());
					}
				}
			}
			foreach (array_keys($mandateIds) as $mandateId) {
				$this->mandateService->markPresented($mandateId, $batch->getDueDate());
			}

			$batch->setStatus(DebitBatch::STATUS_SUBMITTED);
			$batch->setSubmittedBy($this->currentUid());
			$batch->setSubmittedAt($this->now());
			$batch = $this->batchMapper->update($batch);

			$details = [
				'faelligkeit' => $batch->getDueDate(),
				'anzahl' => count($items),
				'mandate' => count($mandateIds),
			];
			if ($nonCollectible > 0) {
				$details['ohne_einzugsfaehiges_mandat'] = $nonCollectible;
			}
			$this->audit->log('Lastschriftlauf eingereicht', 'debit_batch', $batch->getId(), $details);
			return $batch;
		});
	}

	/**
	 * Verwirft einen noch nicht eingereichten Lauf (Spec §3.5 „discarded
	 * behält die Historie, Forderungen werden wieder frei, EndToEndIds werden
	 * nie wiederverwendet"). Die Zeile und ihre Einzugsposten bleiben
	 * unverändert stehen – die Historie ist genau das, was hier „bleibt". Die
	 * zugehörigen Forderungen werden automatisch wieder frei, weil
	 * {@see DebitItemMapper::findOpenItemIdsInLiveBatches()} einen
	 * `verworfen`en Lauf nicht mehr mitzählt; `vbh_open_items` selbst wird
	 * nicht angefasst.
	 *
	 * @throws DoesNotExistException wenn es den Lauf nicht (mehr) gibt
	 * @throws \InvalidArgumentException wenn die Begründung fehlt oder der Lauf bereits eingereicht/verworfen ist
	 */
	public function discard(int $batchId, string $reason): DebitBatch {
		$reason = trim($reason);
		if ($reason === '') {
			throw new \InvalidArgumentException($this->l10n->t('Für das Verwerfen eines Laufs ist eine Begründung Pflicht.'));
		}
		$batch = $this->batchMapper->find($batchId);
		$this->stateMachine->assertCanDiscard($batch);

		return $this->transaction->run(function () use ($batch, $reason): DebitBatch {
			$itemCount = count($this->itemMapper->findByBatch((int)$batch->getId()));

			$batch->setStatus(DebitBatch::STATUS_DISCARDED);
			$batch->setDiscardedBy($this->currentUid());
			$batch->setDiscardedAt($this->now());
			$batch->setDiscardReason($reason);
			$batch = $this->batchMapper->update($batch);

			$this->audit->log('Lastschriftlauf verworfen', 'debit_batch', $batch->getId(), [
				'faelligkeit' => $batch->getDueDate(),
				'anzahl' => $itemCount,
				'begruendung' => $reason,
			]);
			return $batch;
		});
	}

	/**
	 * Terminverschiebung (Spec §2.2 „due_date nur nach hinten verschiebbar"):
	 * nur solange noch nicht eingereicht. Erzeugt eine neue Kopie der XML-
	 * Ablage (falls eingeschaltet), weil sich `ReqdColltnDt` ändert – `msg_id`/
	 * `creation_date_time` bleiben dabei unangetastet.
	 *
	 * @throws DoesNotExistException wenn es den Lauf nicht (mehr) gibt
	 * @throws \InvalidArgumentException bei ungültigem Datum oder unzulässigem Übergang
	 */
	public function rescheduleDueDate(int $batchId, string $newDueDate): DebitBatch {
		$this->assertDate($newDueDate);
		$batch = $this->batchMapper->find($batchId);
		$this->stateMachine->assertCanReschedule($batch, $newDueDate);

		$oldDueDate = $batch->getDueDate();
		$batch->setDueDate($newDueDate);
		$batch = $this->batchMapper->update($batch);

		$this->audit->log('Lastschriftlauf terminlich verschoben', 'debit_batch', $batch->getId(), [
			'alter_termin' => $oldDueDate,
			'neuer_termin' => $newDueDate,
		]);

		$this->storeXmlSafely($batch);
		return $batch;
	}

	// --- Hilfsmethoden -----------------------------------------------------------

	private function creditorOf(DebitBatch $batch): SepaCreditor {
		return new SepaCreditor(
			$batch->getMsgId(),
			$batch->getDueDate(),
			$batch->getCreditorId(),
			$batch->getCreditorName(),
			$batch->getCreditorIban(),
			$batch->getCreditorBic(),
			$batch->getCreationDateTime(),
		);
	}

	/**
	 * Optionale XML-Ablage (Spec §3.5, Default aus) – ein Fehlschlag (z. B.
	 * fehlender Ablage-Nutzer) darf eine bereits erfolgreiche Freigabe/
	 * Terminverschiebung nicht rückwirkend als gescheitert erscheinen lassen,
	 * siehe {@see DebitBatchXmlStorageService}-Klassendoc.
	 */
	private function storeXmlSafely(DebitBatch $batch): void {
		if (!$this->xmlStorage->isEnabled()) {
			return;
		}
		try {
			$this->xmlStorage->store($batch, $this->renderXml((int)$batch->getId()));
		} catch (\Throwable $e) {
			$this->audit->log('Lastschriftlauf: XML-Ablage im NC-Ordner fehlgeschlagen', 'debit_batch', $batch->getId(), ['fehler' => $e->getMessage()]);
		}
	}

	/** @throws \InvalidArgumentException bei einem ungültigen oder nicht existierenden Datum */
	private function assertDate(string $date): void {
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new \InvalidArgumentException($this->l10n->t('Ungültiges Datum (erwartet JJJJ-MM-TT).'));
		}
	}

	private function currentUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}

	private function now(): string {
		return (new \DateTime())->format('Y-m-d H:i:s'); // UTC-Zeitstempel (#263)
	}
}
