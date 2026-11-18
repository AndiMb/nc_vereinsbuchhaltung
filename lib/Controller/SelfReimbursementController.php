<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\ReimbursementMapper;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Http\ReceiptPreviewPolicy;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementDraftService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementFileService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementPresenter;
use OCA\Vereinsbuchhaltung\Service\ReimbursementService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementSettings;
use OCA\Vereinsbuchhaltung\Service\TravelDetails;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Auslagenerstattung aus Sicht des Mitglieds (docs/reimbursement-design.md):
 * eigene Anträge sehen, einreichen, zurückziehen, Belege nachreichen.
 *
 * Wie der {@see SelfController} ohne vbh-Rolle: den Zugang prüft die
 * PermissionMiddleware (Kontoverknüpfung und Auslagen-Einstellung, siehe
 * ReimbursementAccess) und legt die member_id im {@see ActorContextService}
 * ab. JEDE Methode liest die Mitglieds-Kennung ausschließlich von dort; kein
 * Parameter aus Query oder Body ist je eine member_id. Fremde Anträge
 * verhalten sich wie nicht vorhandene (404), damit niemand durch Raten von
 * IDs erfährt, dass es sie gibt.
 */
class SelfReimbursementController extends Controller {

	use InternalErrorResponder;

	public function __construct(
		IRequest $request,
		private ActorContextService $actor,
		private ReimbursementService $service,
		private ReimbursementPresenter $presenter,
		private ReimbursementMapper $mapper,
		private ReimbursementFileService $fileService,
		private ReimbursementDraftService $drafts,
		private ReimbursementSettings $settings,
		private MemberMapper $members,
		private MandateMapper $mandates,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function memberId(): int {
		return $this->actor->memberId() ?? throw new \LogicException('SelfReimbursementController ohne member_id im Kontext');
	}

	private function uid(): string {
		return $this->actor->actorUid() ?? '?';
	}

	/**
	 * Die eigenen Anträge plus das, was das Formular zum Einreichen braucht.
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		$own = $this->mapper->findByMember($this->memberId());
		$this->presenter->preload($own);
		return new DataResponse([
			'canSubmit' => $this->settings->isEnabled(),
			...$this->suggestedAccount(),
			'declarationText' => $this->service->declarationText(),
			'items' => array_map(fn ($r) => $this->presenter->forMember($r), $own),
			'drafts' => $this->drafts->listForMember($this->memberId()),
		]);
	}

	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		try {
			$reimbursement = $this->service->findForMember($id, $this->memberId());
		} catch (DoesNotExistException) {
			return $this->notFound();
		}
		return new DataResponse($this->presenter->forMember($reimbursement, true));
	}

	/**
	 * Reicht einen Antrag samt Belegen ein (multipart/form-data: amount,
	 * expenseDate (Rechnungsdatum), purpose, optional invoiceNumber und supplier,
	 * iban, optional accountHolder, `declaration` und die Dateien unter
	 * `files`). Mehrere Rechnungen sind mehrere Anträge.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 3600)]
	public function create(): DataResponse {
		return $this->handle(function (): DataResponse {
			$uploads = $this->readUploads('files');
			// Reisekostenabrechnung (#353): Betrag und Datum ergeben sich aus den Reiseangaben.
			$travel = $this->travelInput(true);
			$reimbursement = $this->service->submit(
				member: $this->members->find($this->memberId()),
				amountCents: $travel !== null ? $travel->totalCents() : $this->amountCents((string)$this->request->getParam('amount', '')),
				expenseDate: $travel !== null ? (string)$travel->startDate() : (string)$this->request->getParam('expenseDate', ''),
				purpose: (string)$this->request->getParam('purpose', ''),
				invoiceNumber: (string)$this->request->getParam('invoiceNumber', ''),
				supplier: (string)$this->request->getParam('supplier', ''),
				iban: (string)$this->request->getParam('iban', ''),
				accountHolder: (string)$this->request->getParam('accountHolder', ''),
				declarationAccepted: filter_var($this->request->getParam('declaration', false), FILTER_VALIDATE_BOOLEAN),
				uploads: $uploads,
				actorUid: $this->uid(),
				travel: $travel,
			);
			return new DataResponse($this->presenter->forMember($reimbursement, true), Http::STATUS_CREATED);
		});
	}

	#[NoAdminRequired]
	public function withdraw(int $id): DataResponse {
		return $this->handle(function () use ($id): DataResponse {
			$reimbursement = $this->service->withdraw($id, $this->memberId(), $this->uid());
			return new DataResponse($this->presenter->forMember($reimbursement, true));
		});
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 3600)]
	public function addReceipt(int $id): DataResponse {
		return $this->handle(function () use ($id): DataResponse {
			$uploads = $this->readUploads('file');
			if (count($uploads) !== 1) {
				throw new \InvalidArgumentException($this->l10n->t('Keine Datei empfangen'));
			}
			$file = $this->service->addReceipt($id, $this->memberId(), $this->uid(), $uploads[0]['name'], $uploads[0]['content']);
			return new DataResponse($file, Http::STATUS_CREATED);
		});
	}

	#[NoAdminRequired]
	public function removeReceipt(int $id, int $fileId): DataResponse {
		return $this->handle(function () use ($id, $fileId): DataResponse {
			$this->service->removeReceipt($id, $fileId, $this->memberId(), $this->uid());
			return new DataResponse(['status' => 'ok']);
		});
	}

	/** Eigenen Beleg inline ausliefern (Vorschau). */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function receipt(int $id, int $fileId): DataDownloadResponse|DataResponse {
		try {
			$this->service->findForMember($id, $this->memberId());
			$file = $this->fileService->findActiveFor($id, $fileId);
			$content = $this->fileService->content($file);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\RuntimeException) {
			return new DataResponse(['message' => $this->l10n->t('Datei nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		return ReceiptPreviewPolicy::inlineResponse($content, $file->getFileName(), $file->getMimeType());
	}

	// --- Entwürfe (Issue #356) --------------------------------------------------

	/**
	 * Speichert einen neuen Entwurf (multipart/form-data wie beim Einreichen,
	 * alle Felder optional, neue Belege unter `files`).
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 3600)]
	public function createDraft(): DataResponse {
		return $this->handle(function (): DataResponse {
			$draft = $this->drafts->save(null, $this->memberId(), $this->draftFields(), [], $this->readUploads('files'));
			return new DataResponse($draft, Http::STATUS_CREATED);
		});
	}

	/**
	 * Speichert einen Entwurf neu. `keepFileIds[]` nennt die gespeicherten
	 * Belege, die bleiben; die übrigen werden entfernt.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 3600)]
	public function updateDraft(int $id): DataResponse {
		return $this->handle(function () use ($id): DataResponse {
			$draft = $this->drafts->save($id, $this->memberId(), $this->draftFields(), $this->keepFileIds(), $this->readUploads('files'));
			return new DataResponse($draft);
		});
	}

	#[NoAdminRequired]
	public function deleteDraft(int $id): DataResponse {
		return $this->handle(function () use ($id): DataResponse {
			$this->drafts->delete($id, $this->memberId());
			return new DataResponse(['status' => 'ok']);
		});
	}

	/**
	 * Reicht einen Entwurf ein: dieselben Angaben und Prüfungen wie
	 * {@see create()}, dazu die behaltenen Belege des Entwurfs.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 3600)]
	public function submitDraft(int $id): DataResponse {
		return $this->handle(function () use ($id): DataResponse {
			$fields = $this->draftFields();
			if ($fields['kind'] !== 'travel' && $fields['amountCents'] === null) {
				throw new \InvalidArgumentException($this->l10n->t('Der Betrag muss größer als 0 sein.'));
			}
			$reimbursement = $this->drafts->submit(
				$id,
				$this->members->find($this->memberId()),
				$fields,
				filter_var($this->request->getParam('declaration', false), FILTER_VALIDATE_BOOLEAN),
				$this->keepFileIds(),
				$this->readUploads('files'),
				$this->uid(),
			);
			return new DataResponse($this->presenter->forMember($reimbursement, true), Http::STATUS_CREATED);
		});
	}

	/** Beleg eines eigenen Entwurfs inline ausliefern (Vorschau). */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function draftFile(int $id, int $fileId): DataDownloadResponse|DataResponse {
		try {
			$found = $this->drafts->fileForMember($id, $fileId, $this->memberId());
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\RuntimeException) {
			return new DataResponse(['message' => $this->l10n->t('Datei nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		return ReceiptPreviewPolicy::inlineResponse($found['content'], $found['file']->getFileName(), $found['file']->getMimeType());
	}

	/**
	 * Die Formularfelder eines Entwurfs; leer heißt „noch nicht ausgefüllt“.
	 *
	 * @return array{amountCents: ?int, expenseDate: ?string, purpose: ?string, invoiceNumber: ?string, supplier: ?string, iban: ?string, accountHolder: ?string, kind: string, travel: mixed}
	 */
	private function draftFields(): array {
		$travel = $this->request->getParam('kind', '') === 'travel';
		$amount = trim((string)$this->request->getParam('amount', ''));
		$text = fn (string $key): ?string => ($value = (string)$this->request->getParam($key, '')) !== '' ? $value : null;
		return [
			'amountCents' => $amount !== '' ? $this->amountCents($amount) : null,
			'expenseDate' => $text('expenseDate'),
			'purpose' => $text('purpose'),
			'invoiceNumber' => $text('invoiceNumber'),
			'supplier' => $text('supplier'),
			'iban' => $text('iban'),
			'accountHolder' => $text('accountHolder'),
			'kind' => $travel ? 'travel' : 'expense',
			'travel' => $travel ? $this->decodedTravel() : null,
		];
	}

	/**
	 * Die Reiseangaben aus dem Formular (`kind=travel`, `travel` als JSON), oder null für eine
	 * gewöhnliche Auslage.
	 *
	 * @throws \InvalidArgumentException bei unvollständigen oder ungültigen Angaben
	 */
	private function travelInput(bool $strict): ?TravelDetails {
		if ($this->request->getParam('kind', '') !== 'travel') {
			return null;
		}
		return TravelDetails::fromInput($this->decodedTravel(), $this->l10n, $strict);
	}

	private function decodedTravel(): mixed {
		$raw = $this->request->getParam('travel', '');
		if (is_array($raw)) {
			return $raw;
		}
		try {
			return json_decode((string)$raw, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new \InvalidArgumentException($this->l10n->t('Die Reiseangaben fehlen oder sind unlesbar.'));
		}
	}

	/** @return list<int> */
	private function keepFileIds(): array {
		$raw = $this->request->getParam('keepFileIds', []);
		if (!is_array($raw)) {
			return [];
		}
		return array_values(array_unique(array_map('intval', array_filter($raw, 'is_numeric'))));
	}

	/**
	 * Führt eine Aktion aus und übersetzt die Ausnahmen in Antworten: ein
	 * fremder oder fehlender Antrag ist 404, eine ungültige Angabe 400.
	 *
	 * @param callable():DataResponse $action
	 */
	private function handle(callable $action): DataResponse {
		try {
			return $action();
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (UserFacingException $e) {
			// Eine für Menschen geschriebene Ablehnung (etwa ein Kontoinhaber mit
			// Zeilenumbruch) ist ein Eingabefehler, kein Serverfehler.
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\RuntimeException $e) {
			return $this->internalError($e, Http::STATUS_INTERNAL_SERVER_ERROR, $this->l10n->t('Die Aktion ist fehlgeschlagen.'));
		}
	}

	private function notFound(): DataResponse {
		return new DataResponse(['message' => $this->l10n->t('Nicht gefunden')], Http::STATUS_NOT_FOUND);
	}

	/**
	 * Vorschläge fürs Formular aus dem lebenden SEPA-Mandat (IBAN und Kontoinhaber, beide vom selben
	 * Mandat), ohne Mandat der Name des Mitglieds als Kontoinhaber.
	 *
	 * @return array{suggestedIban: ?string, suggestedAccountHolder: string}
	 */
	private function suggestedAccount(): array {
		$memberId = $this->memberId();
		foreach ($this->mandates->findLiveByMember($memberId) as $mandate) {
			$iban = $mandate->getIban();
			if ($iban !== null && $iban !== '') {
				$holder = trim((string)$mandate->getAccountHolder());
				return [
					'suggestedIban' => $iban,
					'suggestedAccountHolder' => $holder !== '' ? $holder : $this->members->find($memberId)->displayName(),
				];
			}
		}
		return ['suggestedIban' => null, 'suggestedAccountHolder' => $this->members->find($memberId)->displayName()];
	}

	/**
	 * Betrag aus dem Formular ("12,50" oder "12.50") in Cent.
	 *
	 * @throws \InvalidArgumentException bei unlesbarem Betrag
	 */
	private function amountCents(string $amount): int {
		$normalized = str_replace(',', '.', trim($amount));
		if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $normalized)) {
			throw new \InvalidArgumentException($this->l10n->t('Bitte einen gültigen Betrag angeben, z. B. 12,50.'));
		}
		return (int)round(((float)$normalized) * 100);
	}

	/**
	 * Die hochgeladenen Dateien unter `$key`, einzeln oder als Liste (`files[]`).
	 *
	 * @return list<array{name: string, content: string}>
	 * @throws \InvalidArgumentException bei fehlgeschlagenem Upload
	 */
	private function readUploads(string $key): array {
		$raw = $this->request->getUploadedFile($key);
		if ($raw === null || $raw === []) {
			return [];
		}
		$entries = [];
		if (is_array($raw['name'] ?? null)) {
			foreach (array_keys($raw['name']) as $i) {
				$entries[] = [
					'name' => (string)$raw['name'][$i],
					'tmp_name' => (string)($raw['tmp_name'][$i] ?? ''),
					'error' => (int)($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE),
				];
			}
		} else {
			$entries[] = [
				'name' => (string)($raw['name'] ?? ''),
				'tmp_name' => (string)($raw['tmp_name'] ?? ''),
				'error' => (int)($raw['error'] ?? UPLOAD_ERR_NO_FILE),
			];
		}

		if (count($entries) > ReimbursementService::MAX_FILES) {
			throw new \InvalidArgumentException($this->l10n->t('Zu viele Dateien (höchstens %d je Antrag).', [ReimbursementService::MAX_FILES]));
		}

		$uploads = [];
		foreach ($entries as $entry) {
			if ($entry['error'] === UPLOAD_ERR_OK && is_file($entry['tmp_name']) && filesize($entry['tmp_name']) > ReimbursementFileService::MAX_SIZE) {
				// Vor dem Einlesen ablehnen: sonst läge die Summe aller Uploads im Speicher.
				throw new \InvalidArgumentException($this->l10n->t('Datei zu groß (max. 20 MB)'));
			}
			if ($entry['error'] !== UPLOAD_ERR_OK || $entry['tmp_name'] === '' || !is_uploaded_file($entry['tmp_name'])) {
				throw new \InvalidArgumentException($this->l10n->t('Datei-Upload fehlgeschlagen (Fehlercode: %s)', [(string)$entry['error']]));
			}
			$content = file_get_contents($entry['tmp_name']);
			if ($content === false) {
				throw new \InvalidArgumentException($this->l10n->t('Datei konnte nicht gelesen werden'));
			}
			$uploads[] = ['name' => $entry['name'], 'content' => $content];
		}
		return $uploads;
	}
}
