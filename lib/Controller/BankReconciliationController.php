<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\Sepa\BankReconciliationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Bankabgleich im Einzug-Unterreiter (Issue #105, Spec §3.10/§5/§6): die
 * Arbeitsliste der Bankumsätze, die auf ein Urteil warten, und die Vorschau
 * der Buchung. Die Urteile selbst (zuordnen, ablehnen, nicht zuordenbar), das
 * Verbuchen und die Bestätigung eines Zahlungseingangs bleiben, wo sie
 * waren: im {@see SepaImportController}.
 *
 * Rollen (Spec §3.9): lesen ab `revisor`, ablehnen ab `buchhalter`. Den
 * Rückgabecode der Bank bekommen nur `buchhalter`/`verwalter` (Spec §3.6).
 */
class BankReconciliationController extends Controller {

	public function __construct(
		IRequest $request,
		private BankReconciliationService $service,
		private PermissionService $permissions,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Alle Bankumsätze, die auf ein Urteil warten: Einzugsgutschriften und
	 * Rücklastschriften mit ihren Detail-Zeilen und Vorschlägen (`items`),
	 * dazu Zahlungseingänge mit Vorschlägen (`incoming`).
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function index(): DataResponse {
		return new DataResponse($this->service->worklist($this->permissions->canWrite()));
	}

	/** Was die Verbuchung dieses Umsatzes buchen würde – rein lesend, mit Hindernissen als Daten. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function preview(int $bankTxId): DataResponse {
		try {
			return new DataResponse($this->service->settlementPreview($bankTxId, $this->permissions->canWrite()));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Bankumsatz nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Zahlungseingangs-Vorschlag ablehnen: dasselbe Paar wird nicht wieder vorgeschlagen. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function rejectIncomingPayment(int $bankTxId, int $openItemId): DataResponse {
		try {
			$this->service->rejectIncomingPayment($bankTxId, $openItemId);
			return new DataResponse(['rejected' => true]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}
}
