<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\ReimbursementMapper;
use OCA\Vereinsbuchhaltung\Http\ReceiptPreviewPolicy;
use OCA\Vereinsbuchhaltung\Service\ActorContextService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementFileService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementPresenter;
use OCA\Vereinsbuchhaltung\Service\ReimbursementService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

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

	/** Mehr Belege je Antrag sind kein Anwendungsfall und würden nur den Speicher belasten. */
	private const MAX_FILES = 10;

	public function __construct(
		IRequest $request,
		private ActorContextService $actor,
		private ReimbursementService $service,
		private ReimbursementPresenter $presenter,
		private ReimbursementMapper $mapper,
		private ReimbursementFileService $fileService,
		private ReimbursementSettings $settings,
		private MemberMapper $members,
		private MandateMapper $mandates,
		private IL10N $l10n,
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
	public function create(): DataResponse {
		return $this->handle(function (): DataResponse {
			$uploads = $this->readUploads('files');
			$reimbursement = $this->service->submit(
				member: $this->members->find($this->memberId()),
				amountCents: $this->amountCents((string)$this->request->getParam('amount', '')),
				expenseDate: (string)$this->request->getParam('expenseDate', ''),
				purpose: (string)$this->request->getParam('purpose', ''),
				invoiceNumber: (string)$this->request->getParam('invoiceNumber', ''),
				supplier: (string)$this->request->getParam('supplier', ''),
				iban: (string)$this->request->getParam('iban', ''),
				accountHolder: (string)$this->request->getParam('accountHolder', ''),
				declarationAccepted: filter_var($this->request->getParam('declaration', false), FILTER_VALIDATE_BOOLEAN),
				uploads: $uploads,
				actorUid: $this->uid(),
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
		} catch (\RuntimeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
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

		if (count($entries) > self::MAX_FILES) {
			throw new \InvalidArgumentException($this->l10n->t('Zu viele Dateien (höchstens %d je Antrag).', [self::MAX_FILES]));
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
