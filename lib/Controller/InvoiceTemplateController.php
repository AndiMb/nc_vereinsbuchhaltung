<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\InvoiceItemTemplateService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Positionsvorlagen für Rechnungen (Epic #168, Stufe 3). Lesen dürfen alle
 * Rollen (der Editor braucht die Auswahl), pflegen darf der Verwalter.
 */
class InvoiceTemplateController extends Controller {

	private const FIELDS = ['name', 'description', 'unitCode', 'unitPriceCents', 'taxCategory', 'taxRateBp', 'accountId'];

	public function __construct(
		IRequest $request,
		private InvoiceItemTemplateService $service,
		private AuditService $audit,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function index(): DataResponse {
		return new DataResponse($this->service->list());
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function create(): DataResponse {
		try {
			$template = $this->service->create($this->payload());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$this->audit->log('Positionsvorlage angelegt', 'invoice_template', $template->getId(), ['name' => $template->getName()]);
		return new DataResponse($template, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function update(int $id): DataResponse {
		try {
			$template = $this->service->update($id, $this->payload());
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$this->audit->log('Positionsvorlage geändert', 'invoice_template', $template->getId(), ['name' => $template->getName()]);
		return new DataResponse($template);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->delete($id);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
		$this->audit->log('Positionsvorlage gelöscht', 'invoice_template', $id);
		return new DataResponse([]);
	}

	/** @return array<string, mixed> */
	private function payload(): array {
		return array_intersect_key($this->request->getParams(), array_flip(self::FIELDS));
	}

	private function notFound(): DataResponse {
		return new DataResponse(['message' => $this->l10n->t('Vorlage nicht gefunden')], Http::STATUS_NOT_FOUND);
	}
}
