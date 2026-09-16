<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\MandateDocumentService;
use OCA\Vereinsbuchhaltung\Service\MandateService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Pflege der SEPA-Mandate im neuen Lifecycle-Modell (Spec §2.2/§3.2, Issue
 * #66) – Papier-Weg. Rollen laut Spec §3.9: Aktivierung/Sperren/Entsperren/
 * Ändern nur `buchhalter`; `revisor` sieht nur lesend mit maskierter IBAN.
 */
class MandateController extends Controller {

	private const MAX_DOCUMENT_SIZE = 20 * 1024 * 1024; // 20 MB

	public function __construct(
		IRequest $request,
		private MandateService $service,
		private MandateDocumentService $documents,
		private PermissionService $permissions,
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
	public function resume(int $id): DataResponse {
		return $this->guarded(fn () => $this->service->resume($id));
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
