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
use OCP\IUserSession;

class ReportController extends Controller {

	public function __construct(
		IRequest $request,
		private ReportService $reportService,
		private CostCenterMapper $costCenterMapper,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		return Application::BOOK;
	}

	#[NoAdminRequired]
	public function costCenters(?int $year = null): DataResponse {
		return new DataResponse($this->reportService->costCenterReport($this->userId(), $year));
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
