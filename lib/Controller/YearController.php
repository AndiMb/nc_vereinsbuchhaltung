<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\YearCloseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Jahresabschluss: Abschließen/Wiedereröffnen ist Verwaltern vorbehalten,
 * die Liste der abgeschlossenen Jahre darf jeder Leseberechtigte sehen.
 */
class YearController extends Controller {

	public function __construct(
		IRequest $request,
		private YearCloseService $yearCloseService,
		private PermissionService $permissionService,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function closed(): DataResponse {
		return new DataResponse($this->yearCloseService->all());
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function close(int $year): DataResponse {
		// Die Middleware hat die Rolle bereits geprüft; die Prüfung hier bleibt
		// als zweite Schicht stehen, damit ein Fehler in der Verdrahtung nicht
		// gleich die Festschreibung öffnet.
		if (!$this->permissionService->isAdmin()) {
			return new DataResponse(['message' => $this->l10n->t('Nur Verwalter dürfen Jahre abschließen.')], Http::STATUS_FORBIDDEN);
		}
		if ($year < 2000 || $year > 2099) {
			return new DataResponse(['message' => $this->l10n->t('Ungültiges Jahr')], Http::STATUS_BAD_REQUEST);
		}
		$uid = $this->userSession->getUser()?->getUID() ?? '?';
		return new DataResponse($this->yearCloseService->close($year, $uid), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function reopen(int $year): DataResponse {
		if (!$this->permissionService->isAdmin()) {
			return new DataResponse(['message' => $this->l10n->t('Nur Verwalter dürfen Jahre wiedereröffnen.')], Http::STATUS_FORBIDDEN);
		}
		$this->yearCloseService->reopen($year);
		return new DataResponse([]);
	}
}
