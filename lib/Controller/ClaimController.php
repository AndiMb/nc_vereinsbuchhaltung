<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\ClaimService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Forderungen/manuelle Einzelforderungen (siehe {@see ClaimService}).
 * `index()` ist die „Offene-Posten-Sicht" (Spec §3.9): lesbar ab `revisor`
 * (Default-Heuristik, GET), maskiert – siehe ClaimService::listMasked().
 * Anlegen/Erledigen/Stornieren/Stunden sind laut Issue #68 ausdrücklich
 * `buchhalter`-only, deshalb überall ein explizites RequiresRole statt der
 * (hier ohnehin gleichlautenden) Verb-Heuristik.
 */
class ClaimController extends Controller {

	public function __construct(
		IRequest $request,
		private ClaimService $service,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function actorUid(): string {
		return $this->userSession->getUser()?->getUID() ?? 'unknown';
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->service->listMasked());
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function create(int $memberId, string $type, float $amount, string $label, string $dueDate, ?int $accountId = null): DataResponse {
		try {
			$item = $this->service->createManual($memberId, $type, (int)round($amount * 100), $label, $dueDate, $accountId);
			return new DataResponse($item->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function settle(int $id, string $settlementType, ?string $note = null): DataResponse {
		try {
			$item = $this->service->settle($id, $settlementType, $note);
			return new DataResponse($item->jsonSerialize());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Forderung nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function cancel(int $id, string $reason): DataResponse {
		try {
			$item = $this->service->cancel($id, $reason);
			return new DataResponse($item->jsonSerialize());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Forderung nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function defer(int $id, string $deferredUntil, string $reason): DataResponse {
		try {
			$item = $this->service->defer($id, $deferredUntil, $reason, $this->actorUid());
			return new DataResponse($item->jsonSerialize());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Forderung nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}
}
