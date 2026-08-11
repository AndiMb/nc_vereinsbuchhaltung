<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Db\SepaBatch;
use OCA\Vereinsbuchhaltung\Db\SepaBatchItem;
use OCA\Vereinsbuchhaltung\Db\SepaBatchItemMapper;
use OCA\Vereinsbuchhaltung\Db\SepaBatchMapper;
use OCA\Vereinsbuchhaltung\Db\SepaMandate;
use OCA\Vereinsbuchhaltung\Db\SepaMandateMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\Sepa\PainXmlBuilder;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaReference;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserSession;

/**
 * Erzeugt SEPA-Sammeleinzüge aus offenen Posten mit verknüpftem Mandat
 * (siehe OpenItemMapper::findOpenWithMandate()). Ein offener Posten landet
 * nur einmal in einem noch nicht zurückgebuchten Einzug – siehe
 * {@see SepaBatchItemMapper::findPendingOpenItemIds()}.
 */
class SepaBatchService {

	public function __construct(
		private SepaBatchMapper $batchMapper,
		private SepaBatchItemMapper $itemMapper,
		private OpenItemMapper $openItemMapper,
		private SepaMandateMapper $mandateMapper,
		private AccountMapper $accountMapper,
		private MemberReferenceValidator $memberRef,
		private PainXmlBuilder $xmlBuilder,
		private IConfig $config,
		private IUserSession $userSession,
		private TransactionRunner $transaction,
		private AuditService $audit,
		private IL10N $l10n,
	) {
	}

	/** @return SepaBatch[] */
	public function findAllBatches(): array {
		return $this->batchMapper->findAll();
	}

	/**
	 * Vorschlag für das Fälligkeitsdatum eines neuen Einzugs: so weit in der
	 * Zukunft, dass die Vorankündigung ihre Frist einhält. Ein früheres Datum
	 * ist erlaubt (manchmal muss es schnell gehen), die Oberfläche warnt dann.
	 */
	public function defaultExecutionDate(): string {
		return (new \DateTime())->modify('+' . SepaNotificationService::LEAD_DAYS . ' days')->format('Y-m-d');
	}

	/**
	 * Offene Posten, die zum angegebenen Fälligkeitstag eingezogen würden –
	 * zur Kontrolle, bevor tatsächlich ein Einzug erzeugt wird.
	 *
	 * @param string|null $executionDate Fälligkeitstag des geplanten Einzugs;
	 *        nur bis dahin fällige Posten kommen mit. Ohne Angabe der
	 *        Vorschlagstermin aus {@see defaultExecutionDate()}.
	 * @return array<int, array{openItem: OpenItem, mandate: SepaMandate, sequenceType: string}>
	 */
	public function previewEligible(?string $executionDate = null): array {
		$executionDate ??= $this->defaultExecutionDate();
		$pendingIds = $this->itemMapper->findPendingOpenItemIds();
		$rows = [];
		foreach ($this->openItemMapper->findOpenWithMandate($executionDate) as $item) {
			if (in_array($item->getId(), $pendingIds, true)) {
				continue;
			}
			try {
				$mandate = $this->mandateMapper->find((int)$item->getMandateId());
			} catch (DoesNotExistException) {
				continue;
			}
			if ($mandate->getStatus() !== 'active') {
				continue;
			}
			$rows[] = ['openItem' => $item, 'mandate' => $mandate, 'sequenceType' => $this->sequenceTypeFor($mandate)];
		}
		return $rows;
	}

	/**
	 * @throws \InvalidArgumentException wenn die Grundeinstellungen fehlen oder nichts fällig ist
	 */
	public function createBatch(string $executionDate): SepaBatch {
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $executionDate, $m)) {
			throw new \InvalidArgumentException($this->l10n->t('Ungültiges Fälligkeitsdatum (erwartet JJJJ-MM-TT).'));
		}
		if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			throw new \InvalidArgumentException($this->l10n->t('Diesen Tag gibt es nicht: %s', [$executionDate]));
		}
		if ($executionDate < (new \DateTime())->format('Y-m-d')) {
			throw new \InvalidArgumentException($this->l10n->t('Das Fälligkeitsdatum darf nicht in der Vergangenheit liegen.'));
		}

		$creditorId = $this->config->getAppValue(Application::APP_ID, 'sepa_creditor_id', '');
		if ($creditorId === '') {
			throw new \InvalidArgumentException($this->l10n->t('Bitte zuerst die SEPA-Gläubiger-ID in den Einstellungen hinterlegen.'));
		}
		$creditorName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		if (trim($creditorName) === '') {
			throw new \InvalidArgumentException($this->l10n->t('Bitte zuerst den Vereinsnamen in den Einstellungen hinterlegen.'));
		}
		$debtorAccountId = (int)$this->config->getAppValue(Application::APP_ID, 'sepa_debtor_account_id', '0');
		if ($debtorAccountId <= 0) {
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

		$eligible = $this->previewEligible($executionDate);
		if ($eligible === []) {
			throw new \InvalidArgumentException($this->l10n->t('Keine bis zum %s fälligen offenen Posten mit SEPA-Mandat gefunden.', [$executionDate]));
		}

		return $this->transaction->run(function () use ($eligible, $executionDate, $creditorId, $creditorName, $collectingAccount): SepaBatch {
			$batch = new SepaBatch();
			$batch->setExecutionDate($executionDate);
			$batch->setMessageId(SepaReference::message());
			$batch->setCreditorId($creditorId);
			$batch->setCreditorName($creditorName);
			$batch->setCreditorIban((string)$collectingAccount->getIban());
			// Bewusst leer: seit der IBAN-only-Umstellung 2016 ermittelt die Bank
			// die BIC selbst, der Builder schreibt dafür "NOTPROVIDED". Das Konto
			// führt deshalb gar keine BIC. Die Spalte bleibt trotzdem bestehen –
			// für Einreichungen außerhalb des einheitlichen Zahlungsraums, wo die
			// BIC weiterhin verlangt wird.
			$batch->setCreditorBic(null);
			$batch->setCreatedBy($this->userSession->getUser()?->getUID());
			$batch->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));
			$batch = $this->batchMapper->insert($batch);

			// Zwei offene Posten desselben Mandats in einem Lauf (z. B. Beitrag
			// + Veranstaltungsgebühr): row.sequenceType kommt aus previewEligible()
			// und weiß nichts von einem bereits verarbeiteten Geschwister-Posten
			// in dieser Schleife – ohne diese Liste bekämen beide FRST statt nur
			// der erste.
			$usedMandateIds = [];
			foreach ($eligible as $row) {
				$mandate = $row['mandate'];
				$sequenceType = in_array($mandate->getId(), $usedMandateIds, true)
					? ($mandate->getMandateType() === 'OOFF' ? 'OOFF' : 'RCUR')
					: $row['sequenceType'];

				$item = new SepaBatchItem();
				$item->setBatchId($batch->getId());
				$item->setOpenItemId($row['openItem']->getId());
				$item->setMandateId($mandate->getId());
				$item->setAmountCents($row['openItem']->getAmountCents());
				$item->setSequenceType($sequenceType);
				$item->setEndToEndId(SepaReference::endToEnd());
				$item->setStatus('pending');
				$item->setCreatedAt((new \DateTime())->format('Y-m-d H:i:s'));
				$this->itemMapper->insert($item);

				if (!in_array($mandate->getId(), $usedMandateIds, true)) {
					$mandate->setLastUsedDate($executionDate);
					$this->mandateMapper->update($mandate);
					$usedMandateIds[] = $mandate->getId();
				}
			}

			$this->audit->log('SEPA-Sammeleinzug erzeugt', 'sepa_batch', $batch->getId(), [
				'faelligkeit' => $executionDate,
				'anzahl' => count($eligible),
				'summe' => array_sum(array_map(static fn ($r) => $r['openItem']->getAmountCents(), $eligible)) / 100,
			]);

			return $batch;
		});
	}

	/**
	 * @throws DoesNotExistException wenn es den Einzug nicht (mehr) gibt
	 */
	public function generateXml(int $batchId): string {
		$batch = $this->batchMapper->find($batchId);
		$rows = [];
		foreach ($this->itemMapper->findByBatch($batchId) as $item) {
			$mandate = $this->mandateMapper->find($item->getMandateId());
			$openItem = $this->openItemMapper->find($item->getOpenItemId());
			$rows[] = [
				'endToEndId' => $item->getEndToEndId(),
				'amountCents' => $item->getAmountCents(),
				'sequenceType' => $item->getSequenceType(),
				'mandateReference' => $mandate->getMandateReference(),
				'signedDate' => $mandate->getSignedDate(),
				'debtorIban' => $mandate->getIban(),
				'debtorBic' => $mandate->getBic(),
				'debtorName' => $this->memberRef->displayName($mandate->getMemberUid(), $mandate->getMemberLabel()),
				'remittanceInfo' => $openItem->getDescription() ?? $this->l10n->t('Mitgliedsbeitrag'),
			];
		}
		return $this->xmlBuilder->build($batch, $rows);
	}

	/**
	 * Verwirft einen Einzug wieder – für den Fall, dass er versehentlich oder
	 * mit dem falschen Fälligkeitsdatum erzeugt wurde.
	 *
	 * Ohne diesen Weg wäre ein Vertipper endgültig: {@see
	 * SepaBatchItemMapper::findPendingOpenItemIds()} schließt jeden gebundenen
	 * offenen Posten vom Export aus, und er käme in der Vorschau nie wieder
	 * zum Vorschein – ohne Meldung, ohne Erklärung.
	 *
	 * Nur solange nichts zurückgebucht wurde: eine Rücklastschrift ist eine
	 * Tatsache aus der Außenwelt, die sich nicht durch Löschen der Zeile
	 * ungeschehen machen lässt.
	 *
	 * @throws DoesNotExistException wenn es den Einzug nicht (mehr) gibt
	 * @throws \InvalidArgumentException wenn bereits eine Zeile zurückgebucht wurde
	 */
	public function deleteBatch(int $batchId): void {
		$batch = $this->batchMapper->find($batchId);
		$items = $this->itemMapper->findByBatch($batchId);

		foreach ($items as $item) {
			if ($item->getStatus() === 'returned') {
				throw new \InvalidArgumentException($this->l10n->t('Dieser Einzug enthält bereits eine Rücklastschrift und kann nicht mehr verworfen werden.'));
			}
		}

		$mandateIds = array_unique(array_map(static fn (SepaBatchItem $i): int => $i->getMandateId(), $items));

		$this->transaction->run(function () use ($batch, $batchId, $items, $mandateIds): void {
			$this->itemMapper->deleteByBatch($batchId);
			$this->batchMapper->delete($batch);

			// last_used_date auf den Stand zurücknehmen, den die verbliebenen
			// Einzüge decken. Sonst gälte das Mandat weiter als benutzt und der
			// nächste Einzug liefe als RCUR, obwohl nie ein Ersteinzug stattfand.
			foreach ($mandateIds as $mandateId) {
				try {
					$mandate = $this->mandateMapper->find($mandateId);
				} catch (DoesNotExistException) {
					continue;
				}
				$mandate->setLastUsedDate($this->itemMapper->findLastExecutionDateByMandate($mandateId));
				$this->mandateMapper->update($mandate);
			}

			$this->audit->log('SEPA-Sammeleinzug verworfen', 'sepa_batch', $batchId, [
				'faelligkeit' => $batch->getExecutionDate(),
				'anzahl' => count($items),
				'nachricht' => $batch->getMessageId(),
			]);
		});
	}

	/**
	 * Die Zeilen eines Einzugs, angereichert um das, was zum Verstehen nötig
	 * ist: wer, welches Mandat, ob angekündigt wurde und ob zurückgebucht.
	 *
	 * @return array<int, array{item: SepaBatchItem, debtorName: string, mandateReference: string, description: ?string}>
	 * @throws DoesNotExistException wenn es den Einzug nicht (mehr) gibt
	 */
	public function findBatchItems(int $batchId): array {
		$this->batchMapper->find($batchId);
		$rows = [];
		foreach ($this->itemMapper->findByBatch($batchId) as $item) {
			try {
				$mandate = $this->mandateMapper->find($item->getMandateId());
				$debtorName = $this->memberRef->displayName($mandate->getMemberUid(), $mandate->getMemberLabel());
				$mandateReference = $mandate->getMandateReference();
			} catch (DoesNotExistException) {
				// Kann seit der Löschsperre in SepaMandateService::delete() nicht
				// mehr entstehen, nur noch in Altbeständen.
				$debtorName = $this->l10n->t('(Mandat gelöscht)');
				$mandateReference = '';
			}
			try {
				$description = $this->openItemMapper->find($item->getOpenItemId())->getDescription();
			} catch (DoesNotExistException) {
				$description = null;
			}
			$rows[] = [
				'item' => $item,
				'debtorName' => $debtorName,
				'mandateReference' => $mandateReference,
				'description' => $description,
			];
		}
		return $rows;
	}

	/**
	 * Nimmt eine erkannte Rücklastschrift zurück.
	 *
	 * Die Erkennung im Kontoauszugs-Import ist Best-Effort (siehe
	 * {@see SepaReturnDetectionService}); ohne diesen Weg wäre eine
	 * Fehlzuordnung endgültig, obwohl der Mensch sofort sieht, dass die
	 * Buchung etwas anderes war. Der offene Posten bleibt dabei unangetastet:
	 * welchen Status er vor der Erkennung hatte, weiß die App nicht mehr –
	 * das entscheidet der Nutzer in der Liste der offenen Posten selbst.
	 *
	 * @throws DoesNotExistException wenn es die Zeile nicht (mehr) gibt
	 * @throws \InvalidArgumentException wenn die Zeile gar nicht zurückgebucht war
	 */
	public function revertReturn(int $itemId): SepaBatchItem {
		$item = $this->itemMapper->find($itemId);
		if ($item->getStatus() !== 'returned') {
			throw new \InvalidArgumentException($this->l10n->t('Diese Zeile ist nicht als Rücklastschrift markiert.'));
		}
		$item->setStatus('pending');
		$item->setReturnReason(null);
		$item->setReturnDate(null);
		$item = $this->itemMapper->update($item);

		$this->audit->log('SEPA-Rücklastschrift zurückgenommen', 'sepa_batch_item', $item->getId(), [
			'endToEndId' => $item->getEndToEndId(),
			'betrag' => $item->getAmountCents() / 100,
		]);
		return $item;
	}

	private function sequenceTypeFor(SepaMandate $mandate): string {
		if ($mandate->getMandateType() === 'OOFF') {
			return 'OOFF';
		}
		return $mandate->isFirstUse() ? 'FRST' : 'RCUR';
	}
}
