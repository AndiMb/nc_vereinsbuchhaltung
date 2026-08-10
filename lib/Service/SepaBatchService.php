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
	 * Offene Posten, die aktuell für einen SEPA-Export bereitstünden – zur
	 * Kontrolle, bevor tatsächlich ein Einzug erzeugt wird.
	 *
	 * @return array<int, array{openItem: OpenItem, mandate: SepaMandate, sequenceType: string}>
	 */
	public function previewEligible(): array {
		$pendingIds = $this->itemMapper->findPendingOpenItemIds();
		$rows = [];
		foreach ($this->openItemMapper->findOpenWithMandate() as $item) {
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
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $executionDate)) {
			throw new \InvalidArgumentException($this->l10n->t('Ungültiges Fälligkeitsdatum (erwartet JJJJ-MM-TT).'));
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

		$eligible = $this->previewEligible();
		if ($eligible === []) {
			throw new \InvalidArgumentException($this->l10n->t('Keine fälligen offenen Posten mit SEPA-Mandat gefunden.'));
		}

		return $this->transaction->run(function () use ($eligible, $executionDate, $creditorId, $creditorName, $collectingAccount): SepaBatch {
			$batch = new SepaBatch();
			$batch->setExecutionDate($executionDate);
			$batch->setMessageId($this->generateId('MSG'));
			$batch->setCreditorId($creditorId);
			$batch->setCreditorName($creditorName);
			$batch->setCreditorIban((string)$collectingAccount->getIban());
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
				$item->setEndToEndId($this->generateId('E2E'));
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

	private function sequenceTypeFor(SepaMandate $mandate): string {
		if ($mandate->getMandateType() === 'OOFF') {
			return 'OOFF';
		}
		return $mandate->isFirstUse() ? 'FRST' : 'RCUR';
	}

	private function generateId(string $prefix): string {
		return $prefix . '-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(4)));
	}
}
