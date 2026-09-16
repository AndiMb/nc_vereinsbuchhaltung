<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\MemberService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Mitglieder-Stammdaten (siehe {@see MemberService}). Anders als die
 * generische Verb-Heuristik der {@see \OCA\Vereinsbuchhaltung\Middleware\PermissionMiddleware}
 * verlangt jede Methode ausdrücklich mindestens Buchhalter – auch das Lesen:
 * die Personenakte zeigt unmaskierte Kontaktdaten, das ist laut Spec §3.9
 * *nicht* Revisoren vorbehalten (anders als der Einzug-Unterreiter).
 */
class MemberController extends Controller {

	public function __construct(
		IRequest $request,
		private MemberService $service,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** @param string[]|null $blockingReasons vorab berechnet (siehe index()), sonst wird einzeln nachgeschlagen */
	private function decorate(Member $member, ?array $blockingReasons = null): array {
		$data = $member->jsonSerialize();
		$data['blockingReasons'] = $blockingReasons ?? $this->service->blockingReasons((int)$member->getId());
		return $data;
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function index(): DataResponse {
		$members = $this->service->findAll();
		// Sperrgründe für alle Mitglieder auf einen Schlag statt je Zeile
		// einzeln nachzuschlagen (siehe MemberService::blockingReasonsForIds()).
		$reasons = $this->service->blockingReasonsForIds(array_map(
			static fn (Member $m): int => (int)$m->getId(),
			$members,
		));
		return new DataResponse(array_map(
			fn (Member $m): array => $this->decorate($m, $reasons[$m->getId()]),
			$members,
		));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function show(int $id): DataResponse {
		try {
			return new DataResponse($this->decorate($this->service->find($id)));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * @param string|null $memberType person|organisation, Default person
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function create(
		?string $memberType = null,
		?string $firstName = null,
		?string $lastName = null,
		?string $organizationName = null,
		?string $email = null,
		?string $phone = null,
		?string $street = null,
		?string $postalCode = null,
		?string $city = null,
		?string $country = null,
		?string $memberNumber = null,
		?string $joinedAt = null,
		?string $internalNote = null,
	): DataResponse {
		try {
			$member = $this->service->create(compact(
				'memberType', 'firstName', 'lastName', 'organizationName', 'email', 'phone',
				'street', 'postalCode', 'city', 'country', 'memberNumber', 'joinedAt', 'internalNote',
			));
			return new DataResponse($this->decorate($member), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function update(
		int $id,
		?string $memberType = null,
		?string $firstName = null,
		?string $lastName = null,
		?string $organizationName = null,
		?string $email = null,
		?string $phone = null,
		?string $street = null,
		?string $postalCode = null,
		?string $city = null,
		?string $country = null,
		?string $memberNumber = null,
		?string $joinedAt = null,
		?string $internalNote = null,
	): DataResponse {
		try {
			$member = $this->service->update($id, compact(
				'memberType', 'firstName', 'lastName', 'organizationName', 'email', 'phone',
				'street', 'postalCode', 'city', 'country', 'memberNumber', 'joinedAt', 'internalNote',
			));
			return new DataResponse($this->decorate($member));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
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
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Austritt: setzt left_at, auch auf ein Zukunftsdatum (Spec §3.1). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function leave(int $id, string $leftAt): DataResponse {
		try {
			return new DataResponse($this->decorate($this->service->leave($id, $leftAt)));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Nimmt einen erklärten Austritt zurück (left_at wird geleert). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function reactivate(int $id): DataResponse {
		try {
			return new DataResponse($this->decorate($this->service->reactivate($id)));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Vorschläge für eine NC-Kontoverknüpfung – zeigt nur, wählt nichts aus
	 * (Spec §3.1).
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function linkSuggestions(int $id): DataResponse {
		try {
			return new DataResponse($this->service->findLinkSuggestions($id));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Verknüpft erst nach dieser ausdrücklichen, menschlichen Bestätigung. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function link(int $id, string $ncUserId): DataResponse {
		try {
			return new DataResponse($this->decorate($this->service->link($id, $ncUserId)));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function unlink(int $id): DataResponse {
		try {
			return new DataResponse($this->decorate($this->service->unlink($id)));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}
}
