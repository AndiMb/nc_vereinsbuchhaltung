<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Attachment;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\YearCloseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\Files\NotFoundException;
use OCP\IRequest;

class AttachmentController extends Controller {

	use BookContext;

	private const ALLOWED_MIMES = [
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'application/pdf',
	];

	private const MAX_SIZE = 20 * 1024 * 1024; // 20 MB

	public function __construct(
		IRequest $request,
		private AttachmentMapper $attachmentMapper,
		private AttachmentStorageService $storageService,
		private JournalMapper $journalMapper,
		private YearCloseService $yearClose,
		private AuditService $audit,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(int $journalId): DataResponse {
		$attachments = $this->attachmentMapper->findByJournal($journalId, $this->userId());
		$isNcMode = $this->storageService->isNcMode();
		$data = array_map(function (Attachment $a) use ($isNcMode): array {
			$row = $a->jsonSerialize();
			if ($isNcMode) {
				$row['ncPath'] = '/' . $this->storageService->getNcFilePath($a->getId(), $a->getJournalId(), $a->getFileName());
			}
			return $row;
		}, $attachments);
		return new DataResponse($data);
	}

	#[NoAdminRequired]
	public function counts(): DataResponse {
		return new DataResponse($this->attachmentMapper->countByUser($this->userId()));
	}

	#[NoAdminRequired]
	public function create(int $journalId): DataResponse {
		// Festschreibung: Belege eines abgeschlossenen Jahres sind Teil des Abschlusses.
		try {
			$journal = $this->journalMapper->find($journalId, $this->userId());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Buchung nicht gefunden'], Http::STATUS_NOT_FOUND);
		}
		$this->yearClose->assertOpen((string)$journal->getDate());

		$upload = $this->request->getUploadedFile('file');
		if ($upload === null || !isset($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
			return new DataResponse(['message' => 'Keine Datei empfangen'], Http::STATUS_BAD_REQUEST);
		}
		if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return new DataResponse(['message' => 'Datei-Upload fehlgeschlagen (Fehlercode: ' . ($upload['error'] ?? -1) . ')'], Http::STATUS_BAD_REQUEST);
		}
		if (($upload['size'] ?? 0) > self::MAX_SIZE) {
			return new DataResponse(['message' => 'Datei zu groß (max. 20 MB)'], Http::STATUS_BAD_REQUEST);
		}

		$finfo = new \finfo(FILEINFO_MIME_TYPE);
		$detectedMime = $finfo->file($upload['tmp_name']);
		if ($detectedMime === false || !in_array($detectedMime, self::ALLOWED_MIMES, true)) {
			return new DataResponse(['message' => 'Nur Bilder (JPG/PNG/GIF/WebP) und PDFs erlaubt'], Http::STATUS_BAD_REQUEST);
		}

		$content = file_get_contents($upload['tmp_name']);
		if ($content === false) {
			return new DataResponse(['message' => 'Datei konnte nicht gelesen werden'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$attachment = new Attachment();
		$attachment->setJournalId($journalId);
		$attachment->setUserId($this->userId());
		$attachment->setFileName(basename($upload['name']));
		$attachment->setMimeType($detectedMime);
		$attachment->setFileSize((int)$upload['size']);
		$attachment->setUploadedAt(new \DateTime());
		$attachment = $this->attachmentMapper->insert($attachment);

		try {
			$this->storageService->putFile($attachment->getId(), $journalId, $attachment->getFileName(), $content);
		} catch (\Throwable $e) {
			$this->attachmentMapper->delete($attachment);
			return new DataResponse(['message' => 'Datei konnte nicht gespeichert werden: ' . $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$this->audit->log('Beleg hinzugefügt', 'attachment', $attachment->getId(), [
			'journalId' => $journalId,
			'fileName' => $attachment->getFileName(),
		]);
		return new DataResponse($attachment, Http::STATUS_CREATED);
	}

	/** Beleg inline ausliefern (für In-App-Viewer-Modal). */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function view(int $id): DataDownloadResponse|DataResponse {
		try {
			$attachment = $this->attachmentMapper->findOne($id, $this->userId());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Nicht gefunden'], Http::STATUS_NOT_FOUND);
		}
		try {
			$content = $this->storageService->getFileContent($id, $attachment->getJournalId(), $attachment->getFileName());
		} catch (\Throwable) {
			return new DataResponse(['message' => 'Datei nicht gefunden'], Http::STATUS_NOT_FOUND);
		}
		$response = new DataDownloadResponse($content, $attachment->getFileName(), $attachment->getMimeType());
		$response->addHeader('Content-Disposition', 'inline; filename="' . addslashes($attachment->getFileName()) . '"');
		// Inline ausgelieferte Fremdinhalte (PDFs können Skripte enthalten)
		// dürfen im eigenen Ursprung nichts ausführen.
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->setContentSecurityPolicy(new EmptyContentSecurityPolicy());
		return $response;
	}

	/** Beleg herunterladen (Content-Disposition: attachment). */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function download(int $id): DataDownloadResponse|DataResponse {
		try {
			$attachment = $this->attachmentMapper->findOne($id, $this->userId());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Nicht gefunden'], Http::STATUS_NOT_FOUND);
		}

		try {
			$content = $this->storageService->getFileContent($id, $attachment->getJournalId(), $attachment->getFileName());
		} catch (\Throwable) {
			return new DataResponse(['message' => 'Datei nicht gefunden'], Http::STATUS_NOT_FOUND);
		}

		return new DataDownloadResponse($content, $attachment->getFileName(), $attachment->getMimeType());
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		try {
			$attachment = $this->attachmentMapper->findOne($id, $this->userId());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Nicht gefunden'], Http::STATUS_NOT_FOUND);
		}

		// Festschreibung: Belege eines abgeschlossenen Jahres bleiben unangetastet.
		try {
			$journal = $this->journalMapper->find($attachment->getJournalId(), $this->userId());
			$this->yearClose->assertOpen((string)$journal->getDate());
		} catch (DoesNotExistException) {
			// Buchung existiert nicht mehr → verwaister Beleg darf immer weg.
		}

		// Datensatz zuerst, Datei danach – siehe AttachmentStorageService::deleteOne().
		$this->storageService->deleteOne($attachment);
		$this->audit->log('Beleg gelöscht', 'attachment', $id, [
			'journalId' => $attachment->getJournalId(),
			'fileName' => $attachment->getFileName(),
		]);
		return new DataResponse([]);
	}
}
