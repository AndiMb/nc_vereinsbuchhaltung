<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\CostCenterService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Pflege der frei definierbaren Kostenstellen (siehe {@see CostCenterService}).
 *
 * Die Auswertung selbst liegt weiterhin im {@see ReportController}; hier geht
 * es nur um Anlegen, Umbenennen, Löschen und Zuordnen.
 */
class CostCenterController extends Controller {

	public function __construct(
		IRequest $request,
		private CostCenterService $service,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		return Application::BOOK;
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->service->findAll($this->userId()));
	}

	#[NoAdminRequired]
	public function create(string $code, string $name): DataResponse {
		try {
			return new DataResponse($this->service->create($this->userId(), $code, $name), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function update(int $id, string $code, string $name): DataResponse {
		try {
			return new DataResponse($this->service->update($id, $this->userId(), $code, $name));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Kostenstelle nicht gefunden'], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->delete($id, $this->userId());
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Kostenstelle nicht gefunden'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Mehrere Konten auf einmal einer Kostenstelle zuordnen.
	 *
	 * @param int[] $accountIds
	 * @param int|null $costCenterId null oder 0 = Zuordnung aufheben
	 */
	#[NoAdminRequired]
	public function assign(array $accountIds, ?int $costCenterId = null): DataResponse {
		try {
			$count = $this->service->assign($this->userId(), $accountIds, $costCenterId);
			return new DataResponse(['updated' => $count]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Kostenstelle nicht gefunden'], Http::STATUS_NOT_FOUND);
		}
	}
}
