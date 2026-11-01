<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\InvoiceBatch\InvoiceBatchService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Sammelrechnungen aus einer Tabelle (#243). Lesen alle Rollen; Prüfen,
 * Übernehmen (legt nur Entwürfe an), Umbenennen und Löschen ab `buchhalter`.
 * Festschreiben und Versenden laufen je Rechnung über den
 * {@see InvoiceController} und dessen Rollenprüfung.
 */
class InvoiceBatchController extends Controller {

	/** Obergrenze der hochgeladenen Tabelle. */
	private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

	public function __construct(
		IRequest $request,
		private InvoiceBatchService $service,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function index(): DataResponse {
		return new DataResponse($this->service->list());
	}

	/** Prüflauf; legt nichts an. Datei als `file` (multipart). */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function preview(): DataResponse {
		$upload = $this->readUpload();
		if ($upload instanceof DataResponse) {
			return $upload;
		}
		try {
			return new DataResponse($this->service->preview($upload['content'], $this->options()));
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	/** Legt Stapel, fehlende Empfänger und Entwürfe an. Datei als `file`, dazu `name` und die Vorgaben. */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function create(): DataResponse {
		$upload = $this->readUpload();
		if ($upload instanceof DataResponse) {
			return $upload;
		}
		try {
			$result = $this->service->import($upload['content'], $upload['filename'], (string)$this->request->getParam('name', ''), $this->options());
			return new DataResponse($result, Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function update(int $id, string $name = ''): DataResponse {
		try {
			return new DataResponse($this->service->rename($id, $name));
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->delete($id);
			return new DataResponse([]);
		} catch (DoesNotExistException) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return $this->bad($e);
		}
	}

	/** @return array<string, mixed> */
	private function options(): array {
		$out = [];
		foreach (['deliveryDate', 'periodStart', 'periodEnd', 'accountId', 'noteHead', 'noteFoot'] as $key) {
			$value = $this->request->getParam($key);
			if ($value !== null) {
				$out[$key] = $value;
			}
		}
		return $out;
	}

	/** @return array{content: string, filename: string}|DataResponse */
	private function readUpload(): array|DataResponse {
		$file = $this->request->getUploadedFile('file');
		if ($file === null || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
			return new DataResponse(['message' => $this->l10n->t('Keine Datei empfangen')], Http::STATUS_BAD_REQUEST);
		}
		if ((int)($file['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
			return new DataResponse(['message' => $this->l10n->t('Die Datei ist zu groß (höchstens 10 MB).')], Http::STATUS_BAD_REQUEST);
		}
		$content = file_get_contents($file['tmp_name']);
		if ($content === false) {
			return new DataResponse(['message' => $this->l10n->t('Keine Datei empfangen')], Http::STATUS_BAD_REQUEST);
		}
		return ['content' => $content, 'filename' => (string)($file['name'] ?? '')];
	}

	private function bad(\InvalidArgumentException $e): DataResponse {
		return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
	}

	private function notFound(): DataResponse {
		return new DataResponse(['message' => $this->l10n->t('Projekt nicht gefunden')], Http::STATUS_NOT_FOUND);
	}
}
