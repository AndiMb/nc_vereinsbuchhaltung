<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\DonationReceipt\DonationReceiptService;
use OCA\Vereinsbuchhaltung\Service\DonationReceipt\DonationReceiptSettings;
use OCA\Vereinsbuchhaltung\Service\ForeignHomeGuard;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Zuwendungsbestätigungen über Mitgliedsbeiträge (Issue #10): Einstellungen,
 * Übersicht je Mitglied, Ausstellen, Stornieren, PDF. Rechte wie die
 * Mitgliederakte (Schreibrecht, enthält Personendaten); die Einstellungen nur
 * für Verwalter.
 */
class DonationReceiptController extends Controller {

	public function __construct(
		IRequest $request,
		private DonationReceiptService $service,
		private DonationReceiptSettings $settings,
		private ForeignHomeGuard $foreignHomeGuard,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function settings(): DataResponse {
		return new DataResponse($this->settings->all());
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function updateSettings(): DataResponse {
		$params = $this->request->getParams();
		if (array_key_exists(DonationReceiptSettings::SETTING_FOLDER, $params)) {
			// Derselbe Schutz wie bei den anderen Ablageordnern (#307).
			$requested = trim(str_replace('\\', '/', (string)$params[DonationReceiptSettings::SETTING_FOLDER]), '/');
			$error = $this->foreignHomeGuard->folderChangeError(
				$this->settings->folder(),
				$requested !== '' ? $requested : DonationReceiptSettings::DEFAULT_FOLDER,
			);
			if ($error !== null) {
				return new DataResponse(['message' => $error], Http::STATUS_FORBIDDEN);
			}
		}
		try {
			$this->settings->update($params);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->settings->all());
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function member(int $memberId): DataResponse {
		try {
			return new DataResponse($this->service->overview($memberId));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function issue(int $memberId, int $year): DataResponse {
		try {
			$receipt = $this->service->issue($memberId, $year, $this->actor());
			return new DataResponse($receipt->jsonSerialize(), Http::STATUS_CREATED);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException|UserFacingException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function void(int $id, string $reason = ''): DataResponse {
		try {
			return new DataResponse($this->service->void($id, $reason, $this->actor())->jsonSerialize());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Zuwendungsbestätigung nicht gefunden')], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function pdf(int $id): DataDownloadResponse|DataResponse {
		try {
			$pdf = $this->service->pdf($this->service->find($id));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Zuwendungsbestätigung nicht gefunden')], Http::STATUS_NOT_FOUND);
		} catch (UserFacingException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
		return new DataDownloadResponse($pdf['content'], $pdf['fileName'], 'application/pdf');
	}

	private function actor(): string {
		return $this->userSession->getUser()?->getUID() ?? 'unknown';
	}
}
