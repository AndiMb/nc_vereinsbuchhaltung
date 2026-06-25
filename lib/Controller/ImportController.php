<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\ImportLogMapper;
use OCA\Vereinsbuchhaltung\Service\ImportService;
use OCA\Vereinsbuchhaltung\Service\ResetService;
use OCA\Vereinsbuchhaltung\Service\XbucImportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ImportController extends Controller {

	public function __construct(
		IRequest $request,
		private ImportService $importService,
		private XbucImportService $xbucService,
		private ResetService $resetService,
		private ImportLogMapper $importMapper,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function userId(): string {
		return Application::BOOK;
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
			return new DataResponse(['message' => 'Keine Datei empfangen'], Http::STATUS_BAD_REQUEST);
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
			return new DataResponse(['message' => 'Keine Datei empfangen'], Http::STATUS_BAD_REQUEST);
		}
		$applyRules = filter_var($this->request->getParam('applyRules', true), FILTER_VALIDATE_BOOLEAN);
		try {
			$result = $this->importService->commit($this->userId(), $upload['filename'], $upload['content'], $applyRules);
			return new DataResponse($result, Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse($this->importMapper->findAll($this->userId()));
	}

	#[NoAdminRequired]
	public function xbucPreview(): DataResponse {
		$upload = $this->readUpload();
		if ($upload === null) {
			return new DataResponse(['message' => 'Keine Datei empfangen'], Http::STATUS_BAD_REQUEST);
		}
		try {
			return new DataResponse($this->xbucService->preview($upload['content']));
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function xbucCommit(): DataResponse {
		$upload = $this->readUpload();
		if ($upload === null) {
			return new DataResponse(['message' => 'Keine Datei empfangen'], Http::STATUS_BAD_REQUEST);
		}
		$reset = filter_var($this->request->getParam('reset', true), FILTER_VALIDATE_BOOLEAN);
		try {
			$result = $this->xbucService->import($this->userId(), $upload['content'], $reset);
			return new DataResponse($result, Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function reset(): DataResponse {
		$this->resetService->resetAll($this->userId());
		return new DataResponse([]);
	}
}
