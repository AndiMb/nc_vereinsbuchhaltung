<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\BankTransaction;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetail;
use OCA\Vereinsbuchhaltung\Db\BankTxSepaDetailMapper;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\Sepa\IncomingPaymentMatchingService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportConfirmationService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaImportSettingsService;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaMatchingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Bankimport-Härtung & Einzugs-/Rücklastschrift-Verbuchung (Spec §2.2/§5,
 * Issue #72): der Bestätigungsvorgang je Bankumsatz –
 * {@see SepaMatchingService} liefert Kandidaten, {@see SepaImportConfirmationService}
 * das Einzelurteil je Detail-Zeile und die Verbuchung.
 *
 * Rollen (Spec §3.9, analog {@see DebitBatchController}): lesen ab `revisor`,
 * Urteil/Verbuchung ab `buchhalter`, Einstellungen (Rücklastschriftgebühren-
 * Konto, Gebühren-Weiterbelastung, Standard-Erlöskonto) ab `verwalter`.
 */
class SepaImportController extends Controller {

	public function __construct(
		IRequest $request,
		private BankTxSepaDetailMapper $details,
		private BankTransactionMapper $txMapper,
		private SepaMatchingService $matching,
		private SepaImportConfirmationService $confirmation,
		private IncomingPaymentMatchingService $incomingPayments,
		private SepaImportSettingsService $settings,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** @return array<string,mixed> */
	private function decorate(BankTxSepaDetail $detail, BankTransaction $tx): array {
		$data = $detail->jsonSerialize();
		$data['candidates'] = $detail->isDecided() ? [] : $this->matching->candidatesFor($detail, $tx);
		return $data;
	}

	/**
	 * Alle noch nicht vollständig beurteilten Bankumsätze samt ihrer
	 * Detail-Zeilen und Kandidaten – Grundlage für den Sammler (Spec §5).
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function pending(): DataResponse {
		$open = $this->details->findOpen();
		$byTx = [];
		foreach ($open as $detail) {
			$byTx[$detail->getBankTxId()][] = $detail;
		}
		$result = [];
		foreach ($byTx as $bankTxId => $detailsOfTx) {
			try {
				$tx = $this->txMapper->find($bankTxId, Application::BOOK);
			} catch (DoesNotExistException) {
				continue;
			}
			$result[] = [
				'bankTx' => $tx->jsonSerialize(),
				'details' => array_map(fn (BankTxSepaDetail $d) => $this->decorate($d, $tx), $detailsOfTx),
			];
		}
		return new DataResponse($result);
	}

	/** Alle Detail-Zeilen eines einzelnen Bankumsatzes (auch bereits beurteilte). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function show(int $bankTxId): DataResponse {
		try {
			$tx = $this->txMapper->find($bankTxId, Application::BOOK);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Bankumsatz nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		$details = $this->confirmation->findByBankTx($bankTxId);
		return new DataResponse([
			'bankTx' => $tx->jsonSerialize(),
			'details' => array_map(fn (BankTxSepaDetail $d) => $this->decorate($d, $tx), $details),
		]);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function assign(int $id, int $debitItemId): DataResponse {
		try {
			return new DataResponse($this->confirmation->assign($id, $debitItemId)->jsonSerialize());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function reject(int $id): DataResponse {
		try {
			return new DataResponse($this->confirmation->reject($id)->jsonSerialize());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function markUnmatched(int $id): DataResponse {
		try {
			return new DataResponse($this->confirmation->markUnmatched($id)->jsonSerialize());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Verbuchung, sobald alle Detail-Vorschläge dieses Bankumsatzes beurteilt sind (Spec §5). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function settle(int $bankTxId): DataResponse {
		try {
			return new DataResponse($this->confirmation->settle($bankTxId));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Bankumsatz nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Zuordnungs-Vorschlag für Zahlungseingänge (Spec §2.2/§3.6). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function incomingPaymentSuggestions(int $bankTxId): DataResponse {
		try {
			$tx = $this->txMapper->find($bankTxId, Application::BOOK);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Bankumsatz nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($this->incomingPayments->suggestFor($tx));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function confirmIncomingPayment(int $bankTxId, int $openItemId): DataResponse {
		try {
			return new DataResponse($this->confirmation->confirmIncomingPayment($bankTxId, $openItemId)->jsonSerialize());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Rücklastschriftgebühren-Konto, Gebühren-Weiterbelastung, Standard-Erlöskonto (Spec §3.9: `verwalter`). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function settings(): DataResponse {
		return new DataResponse([
			'returnFeeAccountId' => $this->settings->returnFeeAccountId(),
			'returnFeeRechargeEnabled' => $this->settings->isReturnFeeRechargeEnabled(),
			'contributionDefaultAccountId' => $this->settings->contributionDefaultAccountId(),
		]);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function updateSettings(?int $returnFeeAccountId = null, ?string $returnFeeRechargeEnabled = null, ?int $contributionDefaultAccountId = null): DataResponse {
		if ($returnFeeAccountId !== null) {
			$this->settings->setReturnFeeAccountId($returnFeeAccountId);
		}
		if ($returnFeeRechargeEnabled !== null) {
			$this->settings->setReturnFeeRechargeEnabled($returnFeeRechargeEnabled === '1');
		}
		if ($contributionDefaultAccountId !== null) {
			$this->settings->setContributionDefaultAccountId($contributionDefaultAccountId);
		}
		return $this->settings();
	}
}
