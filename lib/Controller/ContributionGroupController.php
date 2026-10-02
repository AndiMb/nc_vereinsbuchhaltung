<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\ContributionGroupService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Pflege der Beitragsgruppen (siehe {@see ContributionGroupService}).
 * Lesen ab `revisor`, Schreiben ab `buchhalter` (Default-Heuristik der
 * PermissionMiddleware) – nur die Untergrenzen-Erhöhung ist laut Issue #68
 * ausdrücklich `buchhalter`-only und bekommt deshalb ein explizites
 * RequiresRole, obwohl die Vorschau ein GET ist.
 */
class ContributionGroupController extends Controller {

	public function __construct(
		IRequest $request,
		private ContributionGroupService $service,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		return new DataResponse(array_map(fn ($g) => $g->jsonSerialize(), $this->service->findAll()));
	}

	#[NoAdminRequired]
	public function create(
		string $name,
		float $minMonthlyAmount,
		float $defaultMonthlyAmount,
		array $allowedIntervals,
		int $defaultInterval,
		bool $isActive = true,
	): DataResponse {
		try {
			$group = $this->service->create($name, (int)round($minMonthlyAmount * 100), (int)round($defaultMonthlyAmount * 100), $allowedIntervals, $defaultInterval, $isActive);
			return new DataResponse($group->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function update(
		int $id,
		string $name,
		float $minMonthlyAmount,
		float $defaultMonthlyAmount,
		array $allowedIntervals,
		int $defaultInterval,
		bool $isActive,
	): DataResponse {
		try {
			$group = $this->service->update($id, $name, (int)round($minMonthlyAmount * 100), (int)round($defaultMonthlyAmount * 100), $allowedIntervals, $defaultInterval, $isActive);
			return new DataResponse($group->jsonSerialize());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Beitragsgruppe nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->delete($id);
			return new DataResponse([]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Beitragsgruppe nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Vorschau einer Untergrenzen-Erhöhung – explizit `buchhalter`-only trotz GET (Issue #68 AK „Rollen"). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function minAmountPreview(int $id, float $newMinMonthlyAmount): DataResponse {
		try {
			return new DataResponse($this->service->previewMinAmountIncrease($id, (int)round($newMinMonthlyAmount * 100)));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Beitragsgruppe nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function applyMinAmountIncrease(int $id, float $newMinMonthlyAmount): DataResponse {
		try {
			$actorUid = $this->userSession->getUser()?->getUID() ?? 'unknown';
			return new DataResponse($this->service->applyMinAmountIncrease($id, (int)round($newMinMonthlyAmount * 100), $actorUid));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Beitragsgruppe nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}
}
