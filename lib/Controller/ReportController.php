<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\CostCenterMapper;
use OCA\Vereinsbuchhaltung\Service\ReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ReportController extends Controller {

	use BookContext;

	public function __construct(
		IRequest $request,
		private ReportService $reportService,
		private CostCenterMapper $costCenterMapper,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function costCenters(?int $year = null): DataResponse {
		return new DataResponse($this->reportService->costCenterReport($this->userId(), $year));
	}

	#[NoAdminRequired]
	public function spheres(?int $year = null): DataResponse {
		return new DataResponse($this->reportService->sphereReport($this->userId(), $year));
	}

	#[NoAdminRequired]
	public function multiyearTrend(): DataResponse {
		return new DataResponse($this->reportService->multiyearTrend($this->userId()));
	}

	#[NoAdminRequired]
	public function reserves(): DataResponse {
		return new DataResponse($this->reportService->reserveReport($this->userId()));
	}

	/**
	 * Kostenstelle umbenennen (Code + neuer Name).
	 */
	#[NoAdminRequired]
	public function rename(string $code, string $name): DataResponse {
		$code = trim($code);
		$name = trim($name);
		if ($code === '' || $name === '') {
			return new DataResponse(['message' => 'Code und Name sind Pflicht'], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->costCenterMapper->upsert($this->userId(), $code, $name));
	}
}
