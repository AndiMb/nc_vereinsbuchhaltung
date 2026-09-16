<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCA\Vereinsbuchhaltung\Service\MandateActivationService;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
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
		private MandateService $mandateService,
		private MandateActivationService $activation,
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
	 * Fordert für das EIGENE elektronische Mandat einen (neuen) Einmal-Link
	 * an (Issue #67, Spec: "teils über den Self-Service-Kanal"). Setzt einen
	 * bereits von der Verwaltung angelegten elektronischen Entwurf voraus -
	 * das Anlegen eines neuen Mandats mit eigenen Bankdaten gehört zum noch
	 * ausstehenden Self-Service-Aktionskatalog (#75/#76, siehe Klassendoc),
	 * nicht zu diesem Ticket. `memberId` kommt wie überall in diesem
	 * Controller ausschließlich aus dem ActorContextService (IDOR-Schutz).
	 */
	#[NoAdminRequired]
	public function requestMandateActivationLink(): DataResponse {
		$memberId = $this->actorContext->memberId();
		if ($memberId === null) {
			throw new ForbiddenException($this->l10n->t('Kein Self-Service-Zugang.'));
		}
		$mandate = $this->mandateService->findLiveByMember($memberId);
		if ($mandate === null || !$mandate->isElectronic() || $mandate->getStatus() !== Mandate::STATUS_DRAFT) {
			return new DataResponse(['message' => $this->l10n->t('Für Sie liegt aktuell kein elektronischer Mandats-Entwurf vor, der eine Bestätigung braucht.')], Http::STATUS_BAD_REQUEST);
		}
		try {
			// $requestedByUid bewusst null: das ist die Selbstbedienungs-Anfrage
			// selbst, kein Vorgang "im Namen von" durch Mitarbeitende.
			$result = $this->activation->issueLink((int)$mandate->getId(), null);
			return new DataResponse(['activationUrl' => $result['url'], 'sentTo' => $result['email']]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mandat nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
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
