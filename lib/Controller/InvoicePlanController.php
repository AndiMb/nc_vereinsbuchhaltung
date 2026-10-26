<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\InvoicePlanService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Pläne für wiederkehrende Rechnungen (Epic #168, Stufe 3). Lesen dürfen alle
 * Rollen, anlegen, ändern, pausieren und löschen nur der Verwalter, denn ein Plan
 * kann Rechnungen von selbst festschreiben und versenden.
 */
class InvoicePlanController extends Controller {

	private const FIELDS = [
		'name', 'debtorId', 'intervalMonths', 'startDate', 'endDate', 'periodMode', 'lines',
		'noteHead', 'noteFoot', 'paymentTerms', 'dueDays', 'buyerReference', 'orderReference',
		'autoIssue', 'autoSend',
	];

	public function __construct(
		IRequest $request,
		private InvoicePlanService $service,
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
			$plan = $this->service->create($this->payload());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$this->audit->log('Rechnungsplan angelegt', 'invoice_plan', $plan->getId(), ['name' => $plan->getName()]);
		return new DataResponse($plan, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function update(int $id): DataResponse {
		try {
			$plan = $this->service->update($id, $this->payload());
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$this->audit->log('Rechnungsplan geändert', 'invoice_plan', $plan->getId(), ['name' => $plan->getName()]);
		return new DataResponse($plan);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function setActive(int $id, bool $active): DataResponse {
		try {
			$plan = $this->service->setActive($id, $active);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
		$this->audit->log($active ? 'Rechnungsplan fortgesetzt' : 'Rechnungsplan pausiert', 'invoice_plan', $plan->getId(), ['name' => $plan->getName()]);
		return new DataResponse($plan);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->delete($id);
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
		$this->audit->log('Rechnungsplan gelöscht', 'invoice_plan', $id);
		return new DataResponse([]);
	}

	/** Führt alle fälligen Pläne jetzt aus, ohne auf den Hintergrundjob zu warten. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function runDue(): DataResponse {
		return new DataResponse($this->service->runDue());
	}

	/** @return array<string, mixed> */
	private function payload(): array {
		return array_intersect_key($this->request->getParams(), array_flip(self::FIELDS));
	}

	private function notFound(): DataResponse {
		return new DataResponse(['message' => $this->l10n->t('Plan nicht gefunden')], Http::STATUS_NOT_FOUND);
	}
}
