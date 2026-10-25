<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\BankTransactionMapper;
use OCA\Vereinsbuchhaltung\Exception\ConflictException;
use OCA\Vereinsbuchhaltung\Exception\InvoiceNotIssuableException;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\InvoiceMatchingService;
use OCA\Vereinsbuchhaltung\Service\InvoicePaymentService;
use OCA\Vereinsbuchhaltung\Service\InvoiceService;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCA\Vereinsbuchhaltung\Service\NumberRangeService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Rechnungen (Epic #168). Lesen alle Rollen, Entwürfe anlegen und ändern ab
 * `buchhalter`, festschreiben, versenden, stornieren und die Nummernkreise
 * ändern nur `verwalter`: eine festgeschriebene Rechnung ist ein
 * Steuerdokument und nicht mehr änderbar.
 */
class InvoiceController extends Controller {

	use BookContext;

	/** Kopffelder, die der Client senden darf. */
	private const FIELDS = [
		'debtorId', 'issueDate', 'deliveryDate', 'periodStart', 'periodEnd', 'dueDate',
		'taxMode', 'exemptionText', 'buyerReference', 'orderReference', 'paymentTerms',
		'noteHead', 'noteFoot', 'lines',
	];

	public function __construct(
		IRequest $request,
		private InvoiceService $service,
		private InvoiceSettings $settings,
		private NumberRangeService $numberRanges,
		private InvoicePaymentService $paymentService,
		private InvoiceMatchingService $matching,
		private BankTransactionMapper $txMapper,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function index(?string $status = null, ?int $year = null, ?int $debtorId = null): DataResponse {
		return new DataResponse($this->service->list($status, $year, $debtorId));
	}

	/** Auswahlwerte und Voreinstellungen für den Editor. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function meta(): DataResponse {
		return new DataResponse([
			'unitCodes' => InvoiceService::UNIT_CODES,
			'taxModes' => InvoiceSettings::AVAILABLE_TAX_MODES,
			'taxMode' => $this->settings->taxMode(),
			'smallBusinessNotice' => $this->l10n->t(InvoiceSettings::SMALL_BUSINESS_NOTICE),
			'defaultDueDays' => $this->settings->defaultDueDays(),
			'missingIssuerFields' => array_keys($this->settings->missingIssuerFields()),
			'active' => $this->settings->isActive(),
		]);
	}

	/**
	 * Zuordnungsvorschläge zu Rechnungen für alle unzugeordneten Zahlungseingänge,
	 * als Objekt Umsatz-ID => Vorschläge (stärkster zuerst).
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function suggestions(): DataResponse {
		$txs = $this->txMapper->findFiltered($this->userId(), 'unassigned', 1000, 0);
		return new DataResponse((object)$this->matching->suggestForMany($txs));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function show(int $id): DataResponse {
		try {
			return new DataResponse($this->service->detail($id));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function create(): DataResponse {
		try {
			$invoice = $this->service->createDraft($this->payload());
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
		return new DataResponse($this->service->detail((int)$invoice->getId()), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function update(int $id): DataResponse {
		try {
			$this->service->updateDraft($id, $this->payload());
			return new DataResponse($this->service->detail($id));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->deleteDraft($id);
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function duplicate(int $id): DataResponse {
		try {
			$copy = $this->service->duplicate($id);
			return new DataResponse($this->service->detail((int)$copy->getId()), Http::STATUS_CREATED);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	/** Festschreiben: vergibt die Nummer und legt den offenen Posten an. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function issue(int $id, ?string $issueDate = null): DataResponse {
		try {
			$this->service->issue($id, $issueDate);
			return new DataResponse($this->service->detail($id));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (ConflictException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (InvoiceNotIssuableException $e) {
			return new DataResponse(['message' => $e->getMessage(), 'problems' => $e->getProblems()], Http::STATUS_BAD_REQUEST);
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	/** Storniert eine festgeschriebene Rechnung mit einer Stornorechnung. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function cancel(int $id, string $reason = ''): DataResponse {
		try {
			$credit = $this->service->cancel($id, $reason);
			return new DataResponse([
				'invoice' => $this->service->detail($id),
				'credit' => $this->service->detail((int)$credit->getId()),
			]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	/** Ordnet einen Zahlungseingang der Rechnung zu und bucht ihn. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function pay(int $id, int $bankTxId): DataResponse {
		try {
			$tx = $this->txMapper->find($bankTxId, $this->userId());
			$this->paymentService->payFromBankTransaction($tx, $id);
			return new DataResponse($this->service->detail($id));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	/** Barzahlung: bucht Geldkonto an Erlöskonten, ohne Bankumsatz. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function payManually(int $id, string $date, int $accountId, ?int $amountCents = null): DataResponse {
		try {
			$this->paymentService->payManually($id, $date, $accountId, $amountCents);
			return new DataResponse($this->service->detail($id));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	/** Nimmt eine Zahlung zurück (löst die Zuordnung bzw. löscht die Barbuchung). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function revertPayment(int $id, int $paymentId): DataResponse {
		try {
			$this->paymentService->revertPayment($id, $paymentId);
			return new DataResponse($this->service->detail($id));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function numberRanges(): DataResponse {
		return new DataResponse($this->numberRanges->all());
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function updateNumberRange(string $kind, string $prefixPattern, string $resetMode, ?int $nextNo = null): DataResponse {
		try {
			return new DataResponse($this->numberRanges->update($kind, $prefixPattern, $resetMode, $nextNo));
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	/** @return array<string, mixed> */
	private function payload(): array {
		return array_intersect_key($this->request->getParams(), array_flip(self::FIELDS));
	}

	private function bad(\InvalidArgumentException $e): DataResponse {
		return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
	}

	private function notFound(): DataResponse {
		return new DataResponse(['message' => $this->l10n->t('Rechnung nicht gefunden')], Http::STATUS_NOT_FOUND);
	}
}
