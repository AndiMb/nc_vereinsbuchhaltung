<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\DemoDataService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class DemoController extends Controller {

	use BookContext;
	use InternalErrorResponder;

	public function __construct(
		IRequest $request,
		private DemoDataService $demoService,
		private PermissionService $permissionService,
		private AuditService $audit,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Legt den Beispielverein an – nur Verwalter, nur wenn noch keine Konten existieren. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function seed(): DataResponse {
		if (!$this->permissionService->isAdmin()) {
			return new DataResponse(['message' => $this->l10n->t('Nur Verwalter dürfen Beispieldaten anlegen.')], Http::STATUS_FORBIDDEN);
		}
		try {
			$result = $this->demoService->seed($this->userId());
			$this->audit->log('Beispieldaten angelegt', 'import', null, $result);
			return new DataResponse($result, Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			return $this->internalError($e, Http::STATUS_BAD_REQUEST, $this->l10n->t('Beispieldaten konnten nicht angelegt werden.'));
		}
	}
}
