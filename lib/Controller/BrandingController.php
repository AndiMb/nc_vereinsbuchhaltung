<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\BrandingService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Vereins-Logo für den Kurzbericht (ExportController::kurzbericht()). Upload/
 * Löschen nur für Verwalter (wie SettingsController::update()), Ausliefern
 * für alle Leseberechtigten (auch anonym per <img>, siehe view()).
 */
class BrandingController extends Controller {

	public function __construct(
		IRequest $request,
		private BrandingService $branding,
		private PermissionService $permissionService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Logo ausliefern (<img src="...">, daher NoCSRFRequired wie andere Druckseiten-Assets). */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function view(): DataDisplayResponse|DataResponse {
		$logo = $this->branding->getLogo();
		if ($logo === null) {
			return new DataResponse(['message' => 'Kein Logo hinterlegt'], Http::STATUS_NOT_FOUND);
		}
		$response = new DataDisplayResponse($logo['content'], Http::STATUS_OK, ['Content-Type' => $logo['mimeType']]);
		$response->cacheFor(300);
		return $response;
	}

	#[NoAdminRequired]
	public function upload(): DataResponse {
		if (!$this->permissionService->isAdmin()) {
			return new DataResponse(['message' => 'Zugriff verweigert'], Http::STATUS_FORBIDDEN);
		}
		$upload = $this->request->getUploadedFile('file');
		if ($upload === null || !isset($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
			return new DataResponse(['message' => 'Keine Datei empfangen'], Http::STATUS_BAD_REQUEST);
		}
		if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return new DataResponse(['message' => 'Datei-Upload fehlgeschlagen (Fehlercode: ' . ($upload['error'] ?? -1) . ')'], Http::STATUS_BAD_REQUEST);
		}

		$finfo = new \finfo(FILEINFO_MIME_TYPE);
		$detectedMime = $finfo->file($upload['tmp_name']);
		// SVG wird von finfo oft als text/plain erkannt - anhand der Dateiendung nachhelfen.
		if ($detectedMime === 'text/plain' && str_ends_with(strtolower((string)$upload['name']), '.svg')) {
			$detectedMime = 'image/svg+xml';
		}
		$content = file_get_contents($upload['tmp_name']);
		if ($content === false) {
			return new DataResponse(['message' => 'Datei konnte nicht gelesen werden'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$this->branding->setLogo($content, (string)$detectedMime);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse(['uploaded' => true]);
	}

	#[NoAdminRequired]
	public function destroy(): DataResponse {
		if (!$this->permissionService->isAdmin()) {
			return new DataResponse(['message' => 'Zugriff verweigert'], Http::STATUS_FORBIDDEN);
		}
		$this->branding->deleteLogo();
		return new DataResponse([]);
	}
}
