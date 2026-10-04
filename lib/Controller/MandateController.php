<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\Export\PrintableReportPage;
use OCA\Vereinsbuchhaltung\Service\MandateActivationService;
use OCA\Vereinsbuchhaltung\Service\MandateDocumentService;
use OCA\Vereinsbuchhaltung\Service\MandateExpiryCalculator;
use OCA\Vereinsbuchhaltung\Service\MandateFormRenderer;
use OCA\Vereinsbuchhaltung\Service\MandateLegalTextService;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCA\Vereinsbuchhaltung\Service\OpenItemService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Pflege der SEPA-Mandate im neuen Lifecycle-Modell (Spec §2.2/§3.2, Issue
 * #66) – Papier-Weg – plus die elektronische Erteilung (Issue #67):
 * elektronische Entwürfe anlegen und ihren Einmal-Link verschicken. Die
 * Zustimmung selbst läuft NICHT über diesen Controller, sondern über den
 * öffentlichen {@see MandateConsentController} (kein Login, auch für
 * Mitglieder ohne NC-Konto). Rollen laut Spec §3.9: Aktivierung/Sperren/
 * Entsperren/Ändern/Versenden nur `buchhalter`; `revisor` sieht nur lesend
 * mit maskierter IBAN. Einen Entwurf korrigieren oder verwerfen (Issue #118)
 * darf ebenfalls nur `buchhalter`; das Mitglied verwirft seinen eigenen
 * Entwurf über den {@see SelfController}.
 */
class MandateController extends Controller {

	private const MAX_DOCUMENT_SIZE = 20 * 1024 * 1024; // 20 MB

	public function __construct(
		IRequest $request,
		private MandateService $service,
		private MandateDocumentService $documents,
		private MandateActivationService $activation,
		private MandateLegalTextService $legalText,
		private MandateFormRenderer $formRenderer,
		private MandateExpiryCalculator $expiryCalculator,
		private OpenItemService $openItems,
		private PermissionService $permissions,
		private IUserSession $userSession,
		private IConfig $config,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * `revisor` sieht die IBAN nur maskiert (Spec §3.9) – die
	 * RequiresRole(ROLE_READ)-Torwache lässt Revisor UND Buchhalter/Verwalter
	 * durch, die Maskierung selbst hängt deshalb an der tatsächlichen Rolle,
	 * nicht am Türsteher.
	 */
	private function decorate(Mandate $mandate): array {
		$data = $mandate->jsonSerialize();
		if (!$this->permissions->canWrite()) {
			$data['iban'] = $mandate->maskedIban();
		}
		$data['storyText'] = $mandate->storyText($this->l10n);
		$data['hasDocument'] = $this->documents->hasDocument($mandate);
		$data['showMissingDocumentWarning'] = $this->documents->showMissingDocumentWarning() && !$data['hasDocument'] && $mandate->isLive();
		// 36-Monats-Verfall (Issue #100, Mitglieder-Akte): nur ein tatsächlich
		// vorgelegtes Mandat (aktiv/ausgesetzt) hat eine laufende Frist – bei
		// einem Entwurf stünde sonst ein Ablaufdatum da, das erst mit der
		// Aktivierung zu laufen beginnt. Das Warnfenster entscheidet der
		// Rechner (nicht die Oberfläche), damit die Zahl nur an einer Stelle
		// steht.
		$running = in_array($mandate->getStatus(), [Mandate::STATUS_ACTIVE, Mandate::STATUS_SUSPENDED], true);
		$data['expiresAt'] = $running ? $this->expiryCalculator->expiresAt($mandate)?->format('Y-m-d') : null;
		$data['expiryWarning'] = $this->expiryCalculator->needsExpiryWarning($mandate, new \DateTimeImmutable('today'));
		return $data;
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function index(): DataResponse {
		return new DataResponse(array_map($this->decorate(...), $this->service->findAll()));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function byMember(int $memberId): DataResponse {
		return new DataResponse(array_map($this->decorate(...), $this->service->findByMember($memberId)));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function show(int $id): DataResponse {
		try {
			$mandate = $this->service->find($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mandat nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		$data = $this->decorate($mandate);
		$data['history'] = array_map(fn ($e) => $e->jsonSerialize(), $this->service->history($id));
		$data['amendments'] = array_map(fn ($a) => $a->jsonSerialize(), $this->service->amendments($id));
		// Nur für die Akte (Issue #100), bewusst nicht in decorate(): das
		// laufen index()/byMember() für jede Zeile der Mitgliederliste durch.
		$data['activationLink'] = $mandate->isElectronic() && $mandate->getStatus() === Mandate::STATUS_DRAFT
			? $this->activation->linkStatus($id)
			: null;
		// „Offene Summe“ der Reibungsdialoge (Widerruf, Kontoinhaberwechsel;
		// Spec §3.4) – dieselbe Rechnung wie im Self-Service.
		$data['openClaimsTotalCents'] = $this->openItems->openClaimsTotalCents($mandate->getMemberId());
		return new DataResponse($data);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function create(
		int $memberId,
		string $iban,
		?string $bic = null,
		?string $accountHolder = null,
		?string $signedAt = null,
		?string $mandateReference = null,
	): DataResponse {
		try {
			$mandate = $this->service->createPaper($memberId, $iban, $bic, $accountHolder, $signedAt, $mandateReference);
			return new DataResponse($this->decorate($mandate), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Elektronischer Entwurf (Issue #67) – Aktivierung folgt nicht hier, sondern über {@see sendActivationLink()} + den öffentlichen Einmal-Link. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function createElectronic(
		int $memberId,
		string $iban,
		?string $bic = null,
		?string $accountHolder = null,
		?string $mandateReference = null,
	): DataResponse {
		try {
			$mandate = $this->service->createElectronic($memberId, $iban, $bic, $accountHolder, $mandateReference);
			return new DataResponse($this->decorate($mandate), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Verschickt (oder erneuert) den elektronischen Einmal-Link (Issue #67).
	 * Liefert die Aktivierungs-URL im Response mit zurück: dieselbe
	 * berechtigte Person, die den Versand auslösen darf, darf den Link auch
	 * sehen (z.B. um ihn mündlich weiterzugeben, wenn die Mail nicht
	 * ankommt) – keine zusätzliche Preisgabe gegenüber Dritten.
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function sendActivationLink(int $id): DataResponse {
		try {
			$result = $this->activation->issueLink($id, $this->userSession->getUser()?->getUID());
			return new DataResponse([
				'mandate' => $this->decorate($this->service->find($id)),
				'activationUrl' => $result['url'],
				'sentTo' => $result['email'],
			]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mandat nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Druckfertiges Mandatsformular (Spec §2.2 „Mandatsformular-PDF wird aus
	 * demselben Textkörper wie die elektronische Zustimmung erzeugt") –
	 * dasselbe „Strg+P“-Muster wie Kassen-/Kurzbericht
	 * ({@see \OCA\Vereinsbuchhaltung\Service\Export\KurzberichtRenderer}),
	 * kein PDF-erzeugendes Fremdpaket nötig (Spec §1.4: kein eigenes
	 * Tooling). Zeigt bei einem bereits aktiven/beendeten Mandat dessen
	 * fixierte Version und Zustimmungs-/Unterschriftsangaben, bei einem noch
	 * unbestätigten Entwurf die aktuelle Version mit leerer
	 * Unterschriftszeile.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function form(int $id): DataResponse|DataDisplayResponse {
		try {
			$mandate = $this->service->find($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mandat nicht gefunden')], Http::STATUS_NOT_FOUND);
		}

		$legalText = $mandate->getMandateTextVersion() !== null
			? ($this->legalText->find((int)$mandate->getMandateTextVersion()) ?? $this->legalText->current())
			: $this->legalText->current();

		$clubName = $this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$creditorId = $this->config->getAppValue(Application::APP_ID, 'sepa_creditor_id', '');

		$body = PrintableReportPage::header(null, $clubName, $this->l10n->t('SEPA-Lastschriftmandat'), PrintableReportPage::escape($mandate->getMandateReference()));
		$body .= '<section>' . $this->formRenderer->renderLegalText($legalText, $clubName) . '</section>';
		$body .= '<section>' . $this->formRenderer->renderDataBlock($mandate, $creditorId, $mandate->getSignedAt() ?? date('Y-m-d')) . '</section>';
		$body .= '<section class="signatures">' . $this->signatureSection($mandate) . '</section>';

		$html = PrintableReportPage::document($this->l10n->t('SEPA-Lastschriftmandat %s', [$mandate->getMandateReference()]), PrintableReportPage::printHint($this->l10n->t('Zum Drucken oder Als-PDF-Speichern: <strong>Strg+P</strong> (Mac: ⌘P) im Browser.')) . $body);

		$response = new DataDisplayResponse($html, Http::STATUS_OK, ['Content-Type' => 'text/html; charset=utf-8']);
		$policy = new EmptyContentSecurityPolicy();
		$policy->allowInlineStyle(true);
		$response->setContentSecurityPolicy($policy);
		return $response;
	}

	/** Unterschriftsbereich des Formulars – Papier: leere Zeile, elektronisch: Zustimmungsvermerk, wenn schon erteilt. */
	private function signatureSection(Mandate $mandate): string {
		if ($mandate->getConsentAt() !== null) {
			return '<div>' . $this->l10n->t('Elektronisch bestätigt am %1$s (IP %2$s)', [
				PrintableReportPage::escape($mandate->getConsentAt()),
				PrintableReportPage::escape((string)$mandate->getConsentIp()),
			]) . '</div>';
		}
		if ($mandate->isElectronic()) {
			return '<div>' . $this->l10n->t('Noch keine elektronische Zustimmung erteilt.') . '</div>';
		}
		return '<div><div class="line"></div>' . $this->l10n->t('Ort, Datum, Unterschrift') . '</div>';
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function activate(int $id, ?string $signedAt = null): DataResponse {
		return $this->guarded(fn () => $this->service->activatePaper($id, $signedAt));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function suspend(int $id, string $note, string $origin = Mandate::SUSPENSION_MANUAL): DataResponse {
		return $this->guarded(fn () => $this->service->suspend($id, $note, $origin));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function resume(int $id, string $note): DataResponse {
		return $this->guarded(fn () => $this->service->resume($id, $note));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function revoke(int $id): DataResponse {
		return $this->guarded(fn () => $this->service->revoke($id));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function correctAccountHolderName(int $id, string $accountHolder): DataResponse {
		return $this->guarded(fn () => $this->service->correctAccountHolderName($id, $accountHolder));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function amendBankDetails(int $id, string $iban, ?string $bic = null): DataResponse {
		return $this->guarded(fn () => $this->service->amendBankDetails($id, $iban, $bic));
	}

	/**
	 * Entwurf korrigieren (Issue #118): IBAN, BIC und Kontoinhaber eines noch
	 * nicht aktiven Mandats ändern, ohne Amendment; macht einen ausgesendeten
	 * Einmal-Link ungültig. Bei einem aktiven Mandat bleibt es beim Amendment
	 * ({@see amendBankDetails()}).
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function correctDraft(int $id, string $iban, ?string $bic = null, string $accountHolder = ''): DataResponse {
		return $this->guarded(fn () => $this->service->correctDraft($id, $iban, $bic, $accountHolder));
	}

	/** Entwurf verwerfen (Issue #118): endet mit `end_reason: verworfen`, Pflicht-Notiz; danach ist ein neues Mandat möglich. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function discardDraft(int $id, string $note = ''): DataResponse {
		return $this->guarded(fn () => $this->service->discardDraft($id, $note));
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function replace(int $id, string $iban, ?string $bic, string $accountHolder, ?string $signedAt = null): DataResponse {
		try {
			$mandate = $this->service->replaceMandate($id, $iban, $bic, $accountHolder, $signedAt);
			return new DataResponse($this->decorate($mandate), Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mandat nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function reopenAmendment(int $amendmentId): DataResponse {
		try {
			return new DataResponse($this->service->reopenAmendment($amendmentId)->jsonSerialize());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Amendment nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** @param callable():Mandate $action */
	private function guarded(callable $action): DataResponse {
		try {
			return new DataResponse($this->decorate($action()));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mandat nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	// --- Nachweis-Dokument -------------------------------------------------------

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function uploadDocument(int $id): DataResponse {
		$upload = $this->request->getUploadedFile('file');
		if ($upload === null || !isset($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
			return new DataResponse(['message' => $this->l10n->t('Keine Datei empfangen')], Http::STATUS_BAD_REQUEST);
		}
		if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return new DataResponse(['message' => $this->l10n->t('Datei-Upload fehlgeschlagen (Fehlercode: %s)', [(string)($upload['error'] ?? -1)])], Http::STATUS_BAD_REQUEST);
		}
		if (($upload['size'] ?? 0) > self::MAX_DOCUMENT_SIZE) {
			return new DataResponse(['message' => $this->l10n->t('Datei zu groß (max. 20 MB)')], Http::STATUS_BAD_REQUEST);
		}
		$content = file_get_contents($upload['tmp_name']);
		if ($content === false) {
			return new DataResponse(['message' => $this->l10n->t('Datei konnte nicht gelesen werden')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		try {
			$mandate = $this->service->uploadDocument($id, basename((string)$upload['name']), $content);
			return new DataResponse($this->decorate($mandate));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mandat nicht gefunden')], Http::STATUS_NOT_FOUND);
		} catch (\RuntimeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function downloadDocument(int $id): DataResponse|DataDownloadResponse {
		try {
			$mandate = $this->service->find($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mandat nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		$node = $this->documents->nodeOrNull($mandate);
		if ($node === null) {
			return new DataResponse(['message' => $this->l10n->t('Kein Nachweis-Dokument hinterlegt')], Http::STATUS_NOT_FOUND);
		}
		$response = new DataDownloadResponse($node->getContent(), $node->getName(), $node->getMimeType());
		// PDFs/Bilder koennen aktiven Inhalt enthalten - dieselbe Vorsichtsmassnahme
		// wie AttachmentController::inline().
		$response->setContentSecurityPolicy(new EmptyContentSecurityPolicy());
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
	}
}
