<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\MembershipFee;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\MemberReferenceValidator;
use OCA\Vereinsbuchhaltung\Service\MembershipFeeService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Pflege der Mitgliedsbeiträge (siehe {@see MembershipFeeService}).
 * Dieselbe Verwalter-Einstufung wie SepaMandateController: ein Beitrag
 * verknüpft ggf. ein Nextcloud-Konto mit Betrag und Mandat.
 */
class MembershipFeeController extends Controller {

	public function __construct(
		IRequest $request,
		private MembershipFeeService $service,
		private MemberReferenceValidator $memberRef,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * `dueCount` ist die Zahl der Perioden, für die noch kein offener Posten
	 * existiert. Sie steht hier und nicht in der Entität, weil sie vom
	 * heutigen Datum abhängt – eine Entität, die je nach Tag etwas anderes
	 * ausgibt, wäre eine Falle.
	 */
	private function decorate(MembershipFee $fee): array {
		$data = $fee->jsonSerialize();
		$data['displayName'] = $this->memberRef->displayName($fee->getMemberUid(), $fee->getMemberLabel());
		$data['dueCount'] = $this->service->dueCount($fee);
		return $data;
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function index(): DataResponse {
		return new DataResponse(array_map($this->decorate(...), $this->service->findAll()));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function create(
		?string $memberUid,
		?string $memberLabel,
		float $amount,
		string $frequency,
		string $startDate,
		?int $accountId,
		?int $mandateId,
	): DataResponse {
		try {
			$fee = $this->service->create($memberUid, $memberLabel, (int)round($amount * 100), $frequency, $startDate, $accountId, $mandateId);
			return new DataResponse($this->decorate($fee), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function update(
		int $id,
		float $amount,
		string $frequency,
		?int $accountId,
		?int $mandateId,
		bool $active,
		?string $nextDueDate = null,
	): DataResponse {
		try {
			$fee = $this->service->update($id, (int)round($amount * 100), $frequency, $accountId, $mandateId, $active, $nextDueDate);
			return new DataResponse($this->decorate($fee));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Beitrag nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Erzeugt alle rückständigen offenen Posten dieses Beitrags auf einmal. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function catchUp(int $id): DataResponse {
		try {
			return new DataResponse(['created' => $this->service->catchUp($id)]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Beitrag nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->delete($id);
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Beitrag nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}
}
