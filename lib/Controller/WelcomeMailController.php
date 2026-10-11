<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\WelcomeMailService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Willkommens-Mail (siehe {@see WelcomeMailService}): die Vorlage lesen darf, wer
 * Mitglieder aufnimmt (Buchhalter), sie ändern nur der Verwalter – wie die übrigen
 * Einstellungen. Versenden darf, wer Mitglieder pflegt.
 */
class WelcomeMailController extends Controller {

	public function __construct(
		IRequest $request,
		private WelcomeMailService $service,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function template(): DataResponse {
		return new DataResponse($this->service->template());
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function saveTemplate(string $subject = '', string $body = ''): DataResponse {
		try {
			$this->service->saveTemplate($subject, $body);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->service->template());
	}

	/** Betreff und Text sind optional: ohne sie geht die Vorlage der Einstellungen raus. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function send(int $id, ?string $subject = null, ?string $body = null): DataResponse {
		try {
			$this->service->send($id, $subject, $body);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\RuntimeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
		return new DataResponse(['sent' => true]);
	}
}
