<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\AssignmentService;
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
 * Pflege der Zuweisungen (siehe {@see AssignmentService}). Da Self-Service
 * (Ticket #69/#74) noch nicht existiert, kommt jede Änderung ausschließlich
 * über die Admin-Akte – `actorType` ist deshalb hier immer `staff` (Spec
 * §3.9 „Personalunion": der **Kanal** entscheidet, nicht die Identität).
 */
class AssignmentController extends Controller {

	public function __construct(
		IRequest $request,
		private AssignmentService $service,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function actorUid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}

	#[NoAdminRequired]
	public function index(?int $memberId = null): DataResponse {
		$assignments = $memberId !== null ? $this->service->findByMember($memberId) : $this->service->findAll();
		return new DataResponse(array_map(fn ($a) => $a->jsonSerialize(), $assignments));
	}

	#[NoAdminRequired]
	public function create(
		int $memberId,
		int $groupId,
		int $intervalMonths,
		float $monthlyAmount,
		string $paymentMethod,
		string $validFrom,
		?string $validTo = null,
		?float $minMonthlyAmountOverride = null,
		?string $overrideReason = null,
	): DataResponse {
		try {
			$assignment = $this->service->create(
				$memberId,
				$groupId,
				$intervalMonths,
				(int)round($monthlyAmount * 100),
				$paymentMethod,
				$validFrom,
				$validTo,
				$minMonthlyAmountOverride !== null ? (int)round($minMonthlyAmountOverride * 100) : null,
				$overrideReason,
				'staff',
				$this->actorUid(),
			);
			return new DataResponse($assignment->jsonSerialize(), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Beitragsgruppe nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Vorschau der ersten (Prorata-)Periode, bevor überhaupt gespeichert wird (Spec §3.4 „Vorschau vor jedem Speichern"). */
	#[NoAdminRequired]
	public function previewNew(int $intervalMonths, float $monthlyAmount, string $validFrom): DataResponse {
		$transient = new Assignment();
		$transient->setIntervalMonths($intervalMonths);
		$transient->setMonthlyAmountCents((int)round($monthlyAmount * 100));
		$transient->setValidFrom($validFrom);
		try {
			return new DataResponse($this->service->previewFirstPeriod($transient));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	public function update(int $id, ?float $monthlyAmount = null, ?int $intervalMonths = null, ?int $groupId = null): DataResponse {
		try {
			$assignment = $this->service->update(
				$id,
				$monthlyAmount !== null ? (int)round($monthlyAmount * 100) : null,
				$intervalMonths,
				$groupId,
				'staff',
				$this->actorUid(),
			);
			return new DataResponse($assignment->jsonSerialize());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Zuweisung nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Individuelle Untergrenze setzen/ändern/entfernen – explizit `buchhalter`-only (Issue #68 AK „Rollen"). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function setMinAmountOverride(int $id, ?float $minMonthlyAmountOverride = null, ?string $overrideReason = null): DataResponse {
		try {
			$assignment = $this->service->setMinAmountOverride(
				$id,
				$minMonthlyAmountOverride !== null ? (int)round($minMonthlyAmountOverride * 100) : null,
				$overrideReason,
				'staff',
				$this->actorUid(),
			);
			return new DataResponse($assignment->jsonSerialize());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Zuweisung nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function end(int $id, string $validTo): DataResponse {
		try {
			$assignment = $this->service->end($id, $validTo, 'staff', $this->actorUid());
			return new DataResponse($assignment->jsonSerialize());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Zuweisung nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	public function events(int $id): DataResponse {
		return new DataResponse(array_map(fn ($e) => $e->jsonSerialize(), $this->service->findEvents($id)));
	}
}
