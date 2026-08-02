<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\ImportLogMapper;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\DemoDataService;
use OCA\Vereinsbuchhaltung\Service\ImportService;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\ResetService;
use OCA\Vereinsbuchhaltung\Service\XbucImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

class ImportController extends Controller {

	use BookContext;

	public function __construct(
		IRequest $request,
		private ImportService $importService,
		private XbucImportService $xbucService,
		private ResetService $resetService,
		private ImportLogMapper $importMapper,
		private PermissionService $permissionService,
		private AuditService $audit,
		private DemoDataService $demoService,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @return array{content:string, filename:string}|null
	 */
	private function readUpload(): ?array {
		$file = $this->request->getUploadedFile('file');
		if ($file === null || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
			// Fallback für Tests/JSON-Payload mit Base64/Plaintext
			$raw = $this->request->getParam('content');
			if (is_string($raw) && $raw !== '') {
				return ['content' => $raw, 'filename' => (string)($this->request->getParam('filename') ?? 'upload.csv')];
			}
			return null;
		}
		$content = file_get_contents($file['tmp_name']);
		if ($content === false) {
			return null;
		}
		return ['content' => $content, 'filename' => (string)($file['name'] ?? 'upload.csv')];
	}

	#[NoAdminRequired]
	public function preview(): DataResponse {
		$upload = $this->readUpload();
		if ($upload === null) {
			return new DataResponse(['message' => $this->l10n->t('Keine Datei empfangen')], Http::STATUS_BAD_REQUEST);
		}
		try {
			return new DataResponse($this->importService->preview($this->userId(), $upload['content']));
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function commit(): DataResponse {
		$upload = $this->readUpload();
		if ($upload === null) {
			return new DataResponse(['message' => $this->l10n->t('Keine Datei empfangen')], Http::STATUS_BAD_REQUEST);
		}
		$applyRules = filter_var($this->request->getParam('applyRules', true), FILTER_VALIDATE_BOOLEAN);
		try {
			$result = $this->importService->commit($this->userId(), $upload['filename'], $upload['content'], $applyRules);
			$this->audit->log('CSV-Import', 'import', null, [
				'filename' => $upload['filename'],
				'neu' => $result['new'] ?? null,
				'duplikate' => $result['duplicate'] ?? null,
			]);
			return new DataResponse($result, Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->importMapper->findAll($this->userId()));
	}

	/** Optionaler year-Parameter: manuell gewähltes Geschäftsjahr (2000–2099). */
	private function yearOverride(): ?int {
		$raw = $this->request->getParam('year');
		if (!is_numeric($raw)) {
			return null;
		}
		$year = (int)$raw;
		return ($year >= 2000 && $year <= 2099) ? $year : null;
	}

	#[NoAdminRequired]
	public function xbucPreview(): DataResponse {
		$upload = $this->readUpload();
		if ($upload === null) {
			return new DataResponse(['message' => $this->l10n->t('Keine Datei empfangen')], Http::STATUS_BAD_REQUEST);
		}
		try {
			return new DataResponse($this->xbucService->preview($this->userId(), $upload['content'], $this->yearOverride()));
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function xbucCommit(): DataResponse {
		$upload = $this->readUpload();
		if ($upload === null) {
			return new DataResponse(['message' => $this->l10n->t('Keine Datei empfangen')], Http::STATUS_BAD_REQUEST);
		}
		$reset = filter_var($this->request->getParam('reset', false), FILTER_VALIDATE_BOOLEAN);
		$clampDates = filter_var($this->request->getParam('clampDates', false), FILTER_VALIDATE_BOOLEAN);
		if ($reset && !$this->permissionService->isAdmin()) {
			return new DataResponse(['message' => $this->l10n->t('Nur Verwalter dürfen beim Import alle Daten löschen.')], Http::STATUS_FORBIDDEN);
		}
		try {
			$result = $this->xbucService->import($this->userId(), $upload['content'], $reset, $clampDates, $this->yearOverride());
			$this->audit->log('xbuc-Import', 'import', null, [
				'jahr' => $result['year'] ?? null,
				'buchungen' => $result['bookings'] ?? null,
				'reset' => $reset,
			]);
			return new DataResponse($result, Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function reset(): DataResponse {
		// Zweite Schicht neben der Middleware – hier hängt der gesamte
		// Datenbestand dran.
		if (!$this->permissionService->isAdmin()) {
			return new DataResponse(['message' => $this->l10n->t('Nur Verwalter dürfen alle Daten zurücksetzen.')], Http::STATUS_FORBIDDEN);
		}
		// Vor dem Löschen protokollieren – das Protokoll überlebt den Reset bewusst.
		$this->audit->log('Alle Daten zurückgesetzt');
		$this->resetService->resetAll($this->userId());
		$this->demoService->clearFlag();
		return new DataResponse([]);
	}
}
