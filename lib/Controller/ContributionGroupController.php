<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\ContributionGroup;
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
 * Lesen ab `revisor` (das Regelwerk selbst enthält keine Personendaten),
 * Schreiben ab `buchhalter` – jede Methode ausdrücklich per #[RequiresRole]
 * (Spec §3.9, Issue #119), nicht über die Verb-Heuristik. Die Vorschau der
 * Untergrenzen-Erhöhung ist trotz GET `buchhalter`-only (Issue #68).
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
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function index(): DataResponse {
		return new DataResponse(array_map(fn ($g) => $g->jsonSerialize(), $this->service->findAll()));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function create(
		string $name,
		array $allowedIntervals,
		int $defaultInterval,
		float $minMonthlyAmount = 0,
		float $defaultMonthlyAmount = 0,
		bool $isActive = true,
		?int $bookingCostCenterId = null,
		// Betragsangabe (Issue #270): bei `year` zählen die Jahresbeträge, die Monatsbeträge bleiben unbeachtet.
		string $amountBasis = ContributionGroup::BASIS_MONTH,
		bool $prorate = true,
		float $minAnnualAmount = 0,
		float $defaultAnnualAmount = 0,
	): DataResponse {
		try {
			$annual = $amountBasis === ContributionGroup::BASIS_YEAR;
			$group = $this->service->create($name, self::cents($annual ? $minAnnualAmount : $minMonthlyAmount), self::cents($annual ? $defaultAnnualAmount : $defaultMonthlyAmount), $allowedIntervals, $defaultInterval, $isActive, $this->costCenterParam($bookingCostCenterId), $amountBasis, $prorate);
			return new DataResponse($group->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function update(
		int $id,
		string $name,
		array $allowedIntervals,
		int $defaultInterval,
		bool $isActive,
		float $minMonthlyAmount = 0,
		float $defaultMonthlyAmount = 0,
		?int $bookingCostCenterId = null,
		string $amountBasis = ContributionGroup::BASIS_MONTH,
		bool $prorate = true,
		float $minAnnualAmount = 0,
		float $defaultAnnualAmount = 0,
	): DataResponse {
		try {
			$annual = $amountBasis === ContributionGroup::BASIS_YEAR;
			$group = $this->service->update($id, $name, self::cents($annual ? $minAnnualAmount : $minMonthlyAmount), self::cents($annual ? $defaultAnnualAmount : $defaultMonthlyAmount), $allowedIntervals, $defaultInterval, $isActive, $this->costCenterParam($bookingCostCenterId), $amountBasis, $prorate);
			return new DataResponse($group->jsonSerialize());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Beitragsgruppe nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Vorgabe-Kostenstelle aus dem Request (Issue #333): fehlt der Parameter,
	 * ist nichts gesagt (`false`) und der gespeicherte Wert bleibt.
	 */
	private static function cents(float $amount): int {
		return (int)round($amount * 100);
	}

	private function costCenterParam(?int $bookingCostCenterId): int|false|null {
		return array_key_exists('bookingCostCenterId', $this->request->getParams()) ? $bookingCostCenterId : false;
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
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
	public function minAmountPreview(int $id, ?float $newMinMonthlyAmount = null, ?float $newMinAnnualAmount = null): DataResponse {
		try {
			// Der Aufrufer sagt mit dem Parameternamen, welche Betragsangabe er meint (Issue #270); der Dienst lehnt die falsche ab.
			return new DataResponse($this->service->previewMinAmountIncrease($id, self::cents($newMinAnnualAmount ?? $newMinMonthlyAmount ?? 0), $newMinAnnualAmount !== null));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Beitragsgruppe nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function applyMinAmountIncrease(int $id, ?float $newMinMonthlyAmount = null, ?float $newMinAnnualAmount = null): DataResponse {
		try {
			$actorUid = $this->userSession->getUser()?->getUID() ?? 'unknown';
			return new DataResponse($this->service->applyMinAmountIncrease($id, self::cents($newMinAnnualAmount ?? $newMinMonthlyAmount ?? 0), $actorUid, $newMinAnnualAmount !== null));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Beitragsgruppe nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}
}
