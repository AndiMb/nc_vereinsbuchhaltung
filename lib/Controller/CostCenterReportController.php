<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\CostCenterSettings;
use OCA\Vereinsbuchhaltung\Service\Export\CostCenterAllocationExport;
use OCA\Vereinsbuchhaltung\Service\Export\PrintableReportPage;
use OCA\Vereinsbuchhaltung\Service\Report\CostCenterAllocationReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Bericht "Kostenstellen" (Issue #7) als Daten, Druckseite und CSV.
 *
 * Alle drei Wege sind lesend (Verb-Regel der PermissionMiddleware: ab
 * Revisor) und antworten bei ausgeschaltetem Modul mit 404 – der Bericht
 * existiert dann für die Oberfläche nicht.
 */
class CostCenterReportController extends Controller {

	use BookContext;

	public function __construct(
		IRequest $request,
		private CostCenterAllocationReportService $report,
		private CostCenterAllocationExport $export,
		private CostCenterSettings $settings,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(?int $period = null): DataResponse {
		if (!$this->settings->isEnabled()) {
			return $this->disabled();
		}
		return new DataResponse($this->report->report($this->userId(), $period));
	}

	/** Druckfertige HTML-Seite (Drucken/Als-PDF-speichern über den Browser). */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function print(?int $period = null): DataDisplayResponse|DataResponse {
		if (!$this->settings->isEnabled()) {
			return $this->disabled();
		}
		return PrintableReportPage::response($this->export->html($this->userId(), $period));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function csv(?int $period = null): DataDownloadResponse|DataResponse {
		if (!$this->settings->isEnabled()) {
			return $this->disabled();
		}
		$file = $this->export->csv($this->userId(), $period);
		return new DataDownloadResponse($file->content, $file->fileName, 'text/csv; charset=utf-8');
	}

	private function disabled(): DataResponse {
		return new DataResponse(
			['message' => $this->l10n->t('Kostenstellen sind in den Einstellungen ausgeschaltet.')],
			Http::STATUS_NOT_FOUND,
		);
	}
}
