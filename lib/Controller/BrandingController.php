<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\BrandingService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\IL10N;
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
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Logo ausliefern (<img src="...">, daher NoCSRFRequired wie andere
	 * Druckseiten-Assets).
	 *
	 * Zusätzlich abgesichert, obwohl BrandingService nur Rastergrafiken
	 * annimmt: nosniff verhindert, dass der Browser den Inhalt gegen den
	 * deklarierten Typ umdeutet, und die leere CSP macht die Antwort selbst
	 * dann harmlos, wenn doch einmal aktiver Inhalt hier landet.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function view(): DataDisplayResponse|DataResponse {
		$logo = $this->branding->getLogo();
		if ($logo === null) {
			return new DataResponse(['message' => $this->l10n->t('Kein Logo hinterlegt')], Http::STATUS_NOT_FOUND);
		}
		$response = new DataDisplayResponse($logo['content'], Http::STATUS_OK, [
			'Content-Type' => $logo['mimeType'],
			'X-Content-Type-Options' => 'nosniff',
		]);
		$response->setContentSecurityPolicy(new EmptyContentSecurityPolicy());
		$response->cacheFor(300);
		return $response;
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function upload(): DataResponse {
		if (!$this->permissionService->isAdmin()) {
			return new DataResponse(['message' => $this->l10n->t('Zugriff verweigert')], Http::STATUS_FORBIDDEN);
		}
		$upload = $this->request->getUploadedFile('file');
		if ($upload === null || !isset($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
			return new DataResponse(['message' => $this->l10n->t('Keine Datei empfangen')], Http::STATUS_BAD_REQUEST);
		}
		if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return new DataResponse(['message' => $this->l10n->t('Datei-Upload fehlgeschlagen (Fehlercode: %s)', [(string)($upload['error'] ?? -1)])], Http::STATUS_BAD_REQUEST);
		}

		// Ausschließlich der erkannte Typ zählt. Früher wurde bei text/plain
		// anhand der Dateiendung auf image/svg+xml "nachgeholfen" - damit
		// bestimmte der vom Client gelieferte Dateiname den Content-Type, unter
		// dem die Datei später ausgeliefert wird. SVG ist inzwischen ohnehin
		// nicht mehr erlaubt (siehe BrandingService::ALLOWED_MIMES).
		$finfo = new \finfo(FILEINFO_MIME_TYPE);
		$detectedMime = $finfo->file($upload['tmp_name']);
		$content = file_get_contents($upload['tmp_name']);
		if ($content === false) {
			return new DataResponse(['message' => $this->l10n->t('Datei konnte nicht gelesen werden')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$this->branding->setLogo($content, (string)$detectedMime);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse(['uploaded' => true]);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function destroy(): DataResponse {
		if (!$this->permissionService->isAdmin()) {
			return new DataResponse(['message' => $this->l10n->t('Zugriff verweigert')], Http::STATUS_FORBIDDEN);
		}
		$this->branding->deleteLogo();
		return new DataResponse([]);
	}
}
