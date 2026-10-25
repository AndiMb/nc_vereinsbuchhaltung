<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\DebtorService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Rechnungsempfänger (Epic #168): suchen, anlegen, ändern, löschen und aus
 * einem Mitglied übernehmen. Lesen dürfen alle Rollen, schreiben ab
 * `buchhalter` (wie Entwürfe von Rechnungen).
 */
class DebtorController extends Controller {

	/** Felder, die der Client senden darf. */
	private const FIELDS = [
		'kind', 'name', 'name2', 'street', 'postalCode', 'city', 'country',
		'email', 'vatId', 'leitwegId', 'customerNo', 'memberId', 'note',
	];

	public function __construct(
		IRequest $request,
		private DebtorService $service,
		private AuditService $audit,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function index(?string $q = null): DataResponse {
		return new DataResponse($this->service->search($q));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function show(int $id): DataResponse {
		try {
			return new DataResponse($this->service->find($id));
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function create(): DataResponse {
		try {
			$debtor = $this->service->create($this->payload());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$this->audit->log('Rechnungsempfänger angelegt', 'debtor', $debtor->getId(), ['name' => $debtor->getName()]);
		return new DataResponse($debtor, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function update(int $id): DataResponse {
		try {
			$debtor = $this->service->update($id, $this->payload());
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$this->audit->log('Rechnungsempfänger geändert', 'debtor', $debtor->getId(), ['name' => $debtor->getName()]);
		return new DataResponse($debtor);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function destroy(int $id): DataResponse {
		try {
			$debtor = $this->service->find($id);
			$this->service->delete($id);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$this->audit->log('Rechnungsempfänger gelöscht', 'debtor', $id, ['name' => $debtor->getName()]);
		return new DataResponse([]);
	}

	/** Empfänger zu einem Mitglied: vorhandener Datensatz oder neu mit der Adresse aus der Akte. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function fromMember(int $memberId): DataResponse {
		try {
			return new DataResponse($this->service->forMember($memberId));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** @return array<string, mixed> */
	private function payload(): array {
		$params = $this->request->getParams();
		return array_intersect_key($params, array_flip(self::FIELDS));
	}

	private function notFound(): DataResponse {
		return new DataResponse(['message' => $this->l10n->t('Empfänger nicht gefunden')], Http::STATUS_NOT_FOUND);
	}
}
