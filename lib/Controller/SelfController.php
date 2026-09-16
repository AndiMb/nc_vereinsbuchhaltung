<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Self-Service-Zugang für Mitglieder mit verknüpftem NC-Konto (Spec §3.4).
 *
 * Der Aktionskatalog (Mandat erfassen/ändern, Beitrag anpassen) ist NICHT
 * Teil dieses Tickets (#74) – er kommt mit #75/#76. Hier nur die eigenen
 * Stammdaten.
 *
 * Sicherheitsregel dieses Controllers, weil er die einzige Stelle im Modul
 * ist, die ohne Buchhaltungsrolle erreichbar ist: JEDE Methode liest die
 * maßgebliche member_id ausschließlich aus dem {@see ActorContextService} –
 * die PermissionMiddleware hat sie dort vor dem Aufruf aus der
 * Kontoverknüpfung aufgelöst (vierter instanceof-Sonderfall). Kein Parameter
 * aus Query oder Body darf je als member_id verwendet werden – sonst käme
 * ein Mitglied per ID-Manipulation an fremde Daten (IDOR). Absichtlich ohne
 * `#[PublicPage]`: die Middleware muss für jeden Aufruf laufen.
 */
class SelfController extends Controller {

	public function __construct(
		IRequest $request,
		private ActorContextService $actorContext,
		private MemberMapper $memberMapper,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Eigene Stammdaten – nur Kontaktdaten (Spec §2.2: „Kontaktdaten pflegt
	 * das Mitglied, Vereinsdaten pflegt der Verein"). Niemals `internalNote`
	 * (reine Vereinsinterna) oder `ncUserId`/`createdAt` (technische Felder).
	 */
	#[NoAdminRequired]
	public function me(): DataResponse {
		$memberId = $this->actorContext->memberId();
		if ($memberId === null) {
			// Kann die Middleware nicht passieren lassen (sie wirft vorher
			// eine ForbiddenException) – zweite Verteidigungslinie, falls
			// sich das je ändert.
			throw new ForbiddenException($this->l10n->t('Kein Self-Service-Zugang.'));
		}
		$member = $this->memberMapper->findOrNull($memberId);
		if ($member === null) {
			// Die Kontoverknüpfung zeigt auf einen inzwischen gelöschten
			// Datensatz – praktisch nur durch einen parallelen Admin-Eingriff
			// möglich, kein Nutzerfehler.
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		return new DataResponse($this->contactData($member));
	}

	/**
	 * Erlaubte Feldliste für den Self-Service – bewusst kein
	 * `jsonSerialize()` der Entity, das auch `internalNote` einschließt.
	 *
	 * @return array<string,mixed>
	 */
	private function contactData(Member $member): array {
		return [
			'id' => $member->getId(),
			'memberType' => $member->getMemberType(),
			'displayName' => $member->displayName(),
			'firstName' => $member->getFirstName(),
			'lastName' => $member->getLastName(),
			'organizationName' => $member->getOrganizationName(),
			'email' => $member->getEmail(),
			'phone' => $member->getPhone(),
			'street' => $member->getStreet(),
			'postalCode' => $member->getPostalCode(),
			'city' => $member->getCity(),
			'country' => $member->getCountry(),
			'memberNumber' => $member->getMemberNumber(),
			'joinedAt' => $member->getJoinedAt(),
			'leftAt' => $member->getLeftAt(),
			'active' => $member->isActive(),
		];
	}
}
