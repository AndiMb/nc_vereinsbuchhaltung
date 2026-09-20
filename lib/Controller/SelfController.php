<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Assignment;
use OCA\Vereinsbuchhaltung\Db\ContributionGroupMapper;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Exception\ForbiddenException;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCA\Vereinsbuchhaltung\Service\Export\BeitragsbescheinigungRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\DatenuebersichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\PrintableReportPage;
use OCA\Vereinsbuchhaltung\Service\MandateActivationService;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCA\Vereinsbuchhaltung\Service\SelfContactService;
use OCA\Vereinsbuchhaltung\Service\SelfContributionService;
use OCA\Vereinsbuchhaltung\Service\SelfServiceMandateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Self-Service-Zugang für Mitglieder mit verknüpftem NC-Konto (Spec §3.4).
 *
 * Eigene Stammdaten (#74) plus zwei Aktionskataloge: Mandat (Issue #75 -
 * elektronisch erteilen/bestätigen, IBAN ändern, Kontoinhaber wechseln,
 * widerrufen) und Beitrag/Kontaktdaten (Issue #76 - Betrag/Turnus ändern,
 * Kontaktstammdaten pflegen). Für Letzteres ist dieser Controller nur die
 * dünne HTTP-Hülle - die eigentliche Logik (IDOR-Schutz, Validierung,
 * Wirksamkeitsregel, Benachrichtigungen) steckt in
 * {@see SelfContributionService}/{@see SelfContactService}. Dazu die
 * informelle Beitragsbestätigung (Issue #77) als druckfertige Live-Ansicht,
 * siehe {@see certificate()}.
 *
 * Sicherheitsregel dieses Controllers, weil er die einzige Stelle im Modul
 * ist, die ohne Buchhaltungsrolle erreichbar ist: JEDE Methode liest die
 * maßgebliche member_id ausschließlich aus dem {@see ActorContextService} –
 * die PermissionMiddleware hat sie dort vor dem Aufruf aus der
 * Kontoverknüpfung aufgelöst (vierter instanceof-Sonderfall). Kein Parameter
 * aus Query oder Body darf je als member_id ODER als Mandats-ID verwendet
 * werden – sonst käme ein Mitglied per ID-Manipulation an fremde Daten
 * (IDOR). Deshalb nehmen auch die neuen Mandats-Aktionen unten NIE eine
 * Mandats-ID entgegen: {@see SelfServiceMandateService} löst das eigene,
 * einzige lebende Mandat serverseitig über die member_id auf (Spec §2.2
 * „höchstens ein lebendes Mandat je Mitglied" - es gibt für den Self-Service
 * schlicht nichts zu identifizieren). Absichtlich ohne `#[PublicPage]`: die
 * Middleware muss für jeden Aufruf laufen.
 */
class SelfController extends Controller {

	public function __construct(
		IRequest $request,
		private ActorContextService $actorContext,
		private MemberMapper $memberMapper,
		private MandateService $mandateService,
		private MandateActivationService $activation,
		private SelfServiceMandateService $selfServiceMandate,
		private SelfContributionService $contributions,
		private SelfContactService $contact,
		private ContributionGroupMapper $groupMapper,
		private BeitragsbescheinigungRenderer $certificateRenderer,
		private DatenuebersichtRenderer $dataOverviewRenderer,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Eigene Stammdaten – nur Kontaktdaten (Spec §2.2: „Kontaktdaten pflegt
	 * das Mitglied, Vereinsdaten pflegt der Verein"). Niemals `internalNote`
	 * (reine Vereinsinterna) oder `ncUserId`/`createdAt` (technische Felder).
	 * Seit Issue #75 zusätzlich das eigene Mandat (maskiert, siehe
	 * {@see mandateData()}) und die offene Forderungssumme, damit die SPA
	 * beides ohne einen zweiten Roundtrip anzeigen kann.
	 */
	#[NoAdminRequired]
	public function me(): DataResponse {
		$memberId = $this->requireMemberId();
		$member = $this->memberMapper->findOrNull($memberId);
		if ($member === null) {
			// Die Kontoverknüpfung zeigt auf einen inzwischen gelöschten
			// Datensatz – praktisch nur durch einen parallelen Admin-Eingriff
			// möglich, kein Nutzerfehler.
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		$data = $this->contactData($member);
		$data['mandate'] = $this->mandateData($this->selfServiceMandate->currentMandate($memberId));
		$data['openClaimsTotalCents'] = $this->selfServiceMandate->openClaimsTotalCents($memberId);
		return new DataResponse($data);
	}

	/**
	 * Fordert für das EIGENE elektronische Mandat einen (neuen) Einmal-Link
	 * an (Issue #67, Spec: "teils über den Self-Service-Kanal"). Setzt einen
	 * bereits von der Verwaltung angelegten elektronischen Entwurf voraus.
	 * Seit Issue #75 gibt es mit {@see confirmMandate()} eine schnellere
	 * Alternative für dieselbe Situation (direkte Bestätigung in der
	 * angemeldeten Sitzung statt Mail-Umweg) - diese Methode bleibt als
	 * Rückfall bestehen (z.B. falls die Mail-Adresse des Kontos vom Verein
	 * abweicht und der Verein genau DIESE Adresse per Mail erreichen will).
	 * `memberId` kommt wie überall in diesem Controller ausschließlich aus
	 * dem ActorContextService (IDOR-Schutz).
	 */
	#[NoAdminRequired]
	public function requestMandateActivationLink(): DataResponse {
		$memberId = $this->requireMemberId();
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

	/** Textkörper für die Vorschau vor Erteilung/Kontoinhaberwechsel (Spec §3.4 Pflicht-UI). */
	#[NoAdminRequired]
	public function mandateLegalText(): DataResponse {
		$this->requireMemberId();
		return new DataResponse($this->selfServiceMandate->legalTextPreview());
	}

	/** Mandat erfassen + elektronisch erteilen in einem Schritt (Spec §3.4, Issue #75). */
	#[NoAdminRequired]
	public function grantMandate(string $iban, ?string $bic = null, ?string $accountHolder = null): DataResponse {
		$memberId = $this->requireMemberId();
		return $this->guarded(fn () => $this->selfServiceMandate->grant($memberId, $iban, $bic, $accountHolder), Http::STATUS_CREATED);
	}

	/** Einen von der Verwaltung angelegten elektronischen Entwurf direkt bestätigen (Issue #75, siehe requestMandateActivationLink()). */
	#[NoAdminRequired]
	public function confirmMandate(): DataResponse {
		$memberId = $this->requireMemberId();
		return $this->guarded(fn () => $this->selfServiceMandate->confirmDraft($memberId));
	}

	/** IBAN ändern (gleicher Kontoinhaber), kein Sperrfenster (Spec §3.4). */
	#[NoAdminRequired]
	public function changeMandateIban(string $iban, ?string $bic = null): DataResponse {
		$memberId = $this->requireMemberId();
		return $this->guarded(fn () => $this->selfServiceMandate->changeIban($memberId, $iban, $bic));
	}

	/** Kontoinhaberwechsel: erzwingt ein neues, elektronisch erteiltes Mandat (Spec §3.4). */
	#[NoAdminRequired]
	public function replaceMandate(string $iban, ?string $bic, string $accountHolder): DataResponse {
		$memberId = $this->requireMemberId();
		return $this->guarded(fn () => $this->selfServiceMandate->replaceForNewHolder($memberId, $iban, $bic, $accountHolder), Http::STATUS_CREATED);
	}

	/** Widerruf: terminal, „ein Recht" - keine Zweitfaktor-Bestätigung (Spec §3.4). */
	#[NoAdminRequired]
	public function revokeMandate(): DataResponse {
		$memberId = $this->requireMemberId();
		return $this->guarded(fn () => $this->selfServiceMandate->revoke($memberId));
	}

	/**
	 * Gemeinsame Fehlerbehandlung der Mandats-Aktionen (dasselbe Muster wie
	 * MandateController::guarded()): `DoesNotExistException` fängt den
	 * Extremfall ab, dass das eigene Mitglied zwischen Middleware-Prüfung und
	 * dieser Methode parallel gelöscht wurde - praktisch nur durch einen
	 * gleichzeitigen Admin-Eingriff möglich, kein Nutzerfehler.
	 *
	 * @param callable():\OCA\Vereinsbuchhaltung\Db\Mandate $action
	 * @param 200|201 $successStatus
	 */
	private function guarded(callable $action, int $successStatus = Http::STATUS_OK): DataResponse {
		try {
			return new DataResponse($this->mandateData($action()), $successStatus);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Die eigene, server-aufgelöste member_id - zweite Verteidigungslinie
	 * neben der Middleware (die einen fehlenden Self-Service-Zugang bereits
	 * vorher abweist), siehe Klassendoc.
	 */
	private function requireMemberId(): int {
		$memberId = $this->actorContext->memberId();
		if ($memberId === null) {
			throw new ForbiddenException($this->l10n->t('Kein Self-Service-Zugang.'));
		}
		return $memberId;
	}

	/**
	 * Eigene Kontaktstammdaten pflegen (Spec §3.4 Aktionskatalog „Darf":
	 * „Kontaktstammdaten pflegen"). NUR die Felder mit Hoheit „Mitglied"
	 * (Spec §2.2) - siehe {@see \OCA\Vereinsbuchhaltung\Service\MemberService::updateOwnContactData()}.
	 */
	#[NoAdminRequired]
	public function updateMe(
		?string $firstName = null,
		?string $lastName = null,
		?string $organizationName = null,
		?string $email = null,
		?string $phone = null,
		?string $street = null,
		?string $postalCode = null,
		?string $city = null,
		?string $country = null,
	): DataResponse {
		try {
			$member = $this->contact->update(array_filter([
				'firstName' => $firstName,
				'lastName' => $lastName,
				'organizationName' => $organizationName,
				'email' => $email,
				'phone' => $phone,
				'street' => $street,
				'postalCode' => $postalCode,
				'city' => $city,
				'country' => $country,
			], static fn ($v) => $v !== null));
			return new DataResponse($this->contactData($member));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Eigene Zuweisungen einsehen (Spec §3.4 Aktionskatalog „Darf": „eigene
	 * Daten einsehen") - Grundlage für den Beitrag-Bereich der SPA.
	 */
	#[NoAdminRequired]
	public function assignments(): DataResponse {
		$assignments = $this->contributions->findOwn();
		return new DataResponse(array_map(fn (Assignment $a) => $this->assignmentData($a), $assignments));
	}

	/**
	 * Vorschau vor dem Speichern (Spec §3.4 Pflicht-UI „Vorschau vor jedem
	 * Speichern - Wirkt ab … · erster Einzug am … · Betrag").
	 */
	#[NoAdminRequired]
	public function previewAssignment(int $id, ?float $monthlyAmount = null, ?int $intervalMonths = null): DataResponse {
		try {
			$preview = $this->contributions->preview(
				$id,
				$monthlyAmount !== null ? (int)round($monthlyAmount * 100) : null,
				$intervalMonths,
			);
			return new DataResponse($preview);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Zuweisung nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Monatsbeitrag und/oder Turnus der eigenen Zuweisung ändern (Spec §3.4
	 * Aktionskatalog „Darf": „Monatsbeitrag … Turnus wechseln"). Absichtlich
	 * OHNE `groupId`-Parameter - eine Beitragsgruppe zu wechseln ist im
	 * Self-Service nicht erlaubt ("Darf nicht"), diese Methode kann es also
	 * technisch gar nicht anfordern.
	 */
	#[NoAdminRequired]
	public function updateAssignment(int $id, ?float $monthlyAmount = null, ?int $intervalMonths = null): DataResponse {
		try {
			$result = $this->contributions->apply(
				$id,
				$monthlyAmount !== null ? (int)round($monthlyAmount * 100) : null,
				$intervalMonths,
			);
			return new DataResponse([
				'assignment' => $this->assignmentData($result['assignment']),
				'preview' => $result['preview'],
			]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Zuweisung nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Beitragsjahre mit mindestens einer eigenen bezahlten Beitrags-Forderung
	 * (plus das laufende Jahr) – Grundlage der Jahresauswahl der eigenen
	 * Beitragsbestätigung (Spec §3.7, Issue #77).
	 */
	#[NoAdminRequired]
	public function certificateYears(): DataResponse {
		return new DataResponse(['years' => $this->certificateRenderer->selectableYears($this->requireMemberId())]);
	}

	/**
	 * Informelle Beitragsbestätigung als druckfertige Live-Ansicht (Spec
	 * §3.7, Issue #77) – KEINE amtliche Zuwendungsbestätigung nach §10b EStG
	 * (separates Upstream-Issue #10). `memberId` kommt wie überall in diesem
	 * Controller ausschließlich aus dem ActorContextService (IDOR-Schutz) –
	 * ein Mitglied kann sich damit technisch NIE die Bestätigung eines
	 * anderen anzeigen lassen.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function certificate(?int $year = null): DataDisplayResponse|DataResponse {
		try {
			return PrintableReportPage::response($this->certificateRenderer->render($this->requireMemberId(), $year));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Eigene „Datenübersicht" als druckfertige Live-Ansicht (Spec §3.8, Issue
	 * #78) – deckt die Auskunftspflicht nach Art. 15 DSGVO ab, unter „Meine
	 * Daten". `memberId` kommt wie überall in diesem Controller ausschließlich
	 * aus dem ActorContextService (IDOR-Schutz) – ein Mitglied kann sich damit
	 * technisch NIE die Datenübersicht eines anderen anzeigen lassen.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dataOverview(): DataDisplayResponse|DataResponse {
		try {
			return PrintableReportPage::response($this->dataOverviewRenderer->render($this->requireMemberId()));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
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
			'redactedAt' => $member->getRedactedAt(),
		];
	}

	/**
	 * Erlaubte Feldliste für das eigene Mandat (Spec §3.4 Pflicht-UI: „IBAN
	 * immer maskiert", „keine Ereignislisten") – bewusst kein
	 * `jsonSerialize()` der Entity, das u.a. `consentIp`/`consentUserAgent`
	 * (technisches Beweispaket, keine Mitgliedsinfo) und die unmaskierte IBAN
	 * einschließen würde.
	 *
	 * @return array<string,mixed>|null
	 */
	private function mandateData(?Mandate $mandate): ?array {
		if ($mandate === null) {
			return null;
		}
		return [
			'id' => $mandate->getId(),
			'mandateReference' => $mandate->getMandateReference(),
			'ibanMasked' => $mandate->maskedIban(),
			'bic' => $mandate->getBic(),
			'accountHolder' => $mandate->getAccountHolder(),
			'signatureType' => $mandate->getSignatureType(),
			'status' => $mandate->getStatus(),
			'isCollectible' => $mandate->isCollectible(),
			'signedAt' => $mandate->getSignedAt(),
			'activatedAt' => $mandate->getActivatedAt(),
			'suspensionNote' => $mandate->getSuspensionNote(),
			'endReason' => $mandate->getEndReason(),
			'storyText' => $mandate->storyText($this->l10n),
			'createdAt' => $mandate->getCreatedAt(),
		];
	}

	/**
	 * Erlaubte Feldliste für eine Zuweisung im Self-Service (Spec §3.4
	 * Pflicht-UI: „individuelle Untergrenze sichtbar, ihre Begründung
	 * nicht") – Deny-Liste statt Allow-Liste wie bei {@see contactData()},
	 * weil hier (anders als bei Member) fast alle Felder unbedenklich sind
	 * und nur `overrideReason` (die vom Verein hinterlegte Begründung der
	 * individuellen Untergrenze) explizit ausgeblendet werden muss.
	 *
	 * @return array<string,mixed>
	 */
	private function assignmentData(Assignment $assignment): array {
		$data = $assignment->jsonSerialize();
		unset($data['overrideReason']);
		// Gruppenname + erlaubte Turnusse dazu (nicht in Assignment selbst,
		// aber fuer die Turnus-Auswahl der SPA noetig) - referenzielle
		// Sicherheit ist Vereinssache, ein bereits geloeschter Gruppen-Verweis
		// kommt praktisch nicht vor; die Anzeige zeigt dann einfach nichts.
		try {
			$group = $this->groupMapper->find($assignment->getGroupId());
			$data['groupName'] = $group->getName();
			$data['allowedIntervals'] = $group->getAllowedIntervalsArray();
			// Die tatsaechlich geltende Untergrenze (Override ODER Gruppen-
			// Untergrenze) - "individuelle Untergrenze sichtbar" (Spec §3.4)
			// ist ohne diese Ableitung nur die Haelfte der Information: ohne
			// Override kennt der Self-Service sonst gar keine Grenze.
			$data['effectiveMinMonthlyAmount'] = $assignment->effectiveMinMonthlyAmountCents($group) / 100;
		} catch (DoesNotExistException) {
			$data['groupName'] = null;
			$data['allowedIntervals'] = [];
			$data['effectiveMinMonthlyAmount'] = null;
		}
		return $data;
	}
}
