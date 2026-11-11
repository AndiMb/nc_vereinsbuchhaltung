<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\CostCenter\BookingCostCenterService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Pflege der Kostenstellen an der Buchungszeile (Issue #7), siehe
 * {@see BookingCostCenterService}.
 *
 * Rechte wie bei den Auswertungsgruppen ({@see CostCenterController}):
 * lesen ab Revisor, schreiben ab Buchhalter – die Verb-Regel der
 * PermissionMiddleware, deshalb ohne eigene Rollenangabe.
 */
class BookingCostCenterController extends Controller {

	use BookContext;

	public function __construct(
		IRequest $request,
		private BookingCostCenterService $service,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->service->all($this->userId()));
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
	public function update(int $id, string $code, string $name, bool $active = true): DataResponse {
		try {
			return new DataResponse($this->service->update($id, $this->userId(), $code, $name, $active));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Kostenstelle nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->delete($id, $this->userId());
			return new DataResponse([]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Kostenstelle nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}
}
