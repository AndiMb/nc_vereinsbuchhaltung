<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Db\Attachment;
use OCA\Vereinsbuchhaltung\Db\AttachmentMapper;
use OCA\Vereinsbuchhaltung\Db\Journal;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\AttachmentWatchFolderService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

class AttachmentController extends Controller {

	use BookContext;

	private const MAX_SIZE = 20 * 1024 * 1024; // 20 MB

	public function __construct(
		IRequest $request,
		private AttachmentMapper $attachmentMapper,
		private AttachmentStorageService $storageService,
		private AttachmentWatchFolderService $watchFolder,
		private JournalMapper $journalMapper,
		private PeriodService $periods,
		private AuditService $audit,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(int $journalId): DataResponse {
		$attachments = $this->attachmentMapper->findByJournal($journalId, $this->userId());
		return new DataResponse(array_map(fn (Attachment $a): array => $this->serialize($a), $attachments));
	}

	/**
	 * Der Beleg samt allem, was die Oberfläche zum Öffnen und Löschen braucht.
	 *
	 * ncPath (für den Nextcloud-Viewer) nur, wenn die Datei im Home des
	 * angemeldeten Nutzers liegt – für alle anderen zeigt der Pfad ins Leere,
	 * sie bekommen den View-Endpunkt der App.
	 */
	private function serialize(Attachment $a): array {
		$node = $this->storageService->nodeOrNull($a);
		$row = $a->jsonSerialize();
		$row['missing'] = $a->isLinked() && $node === null;
		$row['unlinkOnly'] = $this->storageService->keepsFile($a);
		$owner = $this->storageService->ownerOf($a);
		if ($owner !== null && $owner === $this->userSession->getUser()?->getUID()) {
			$path = $this->storageService->ncPathOf($a, $node);
			if ($path !== null) {
				$row['ncPath'] = $path;
			}
		}
		return $row;
	}

	#[NoAdminRequired]
	public function counts(): DataResponse {
		return new DataResponse($this->attachmentMapper->countByUser($this->userId()));
	}

	private function openJournal(int $journalId): Journal|DataResponse {
		try {
			$journal = $this->journalMapper->find($journalId, $this->userId());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Buchung nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		// Festschreibung: Belege eines abgeschlossenen Geschäftsjahres sind Teil
		// des Abschlusses.
		$this->periods->assertOpen($this->userId(), (string)$journal->getDate());
		return $journal;
	}

	#[NoAdminRequired]
	public function create(int $journalId): DataResponse {
		$journal = $this->openJournal($journalId);
		if ($journal instanceof DataResponse) {
			return $journal;
		}

		$upload = $this->request->getUploadedFile('file');
		if ($upload === null || !isset($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
			return new DataResponse(['message' => $this->l10n->t('Keine Datei empfangen')], Http::STATUS_BAD_REQUEST);
		}
		if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return new DataResponse(['message' => $this->l10n->t('Datei-Upload fehlgeschlagen (Fehlercode: %s)', [(string)($upload['error'] ?? -1)])], Http::STATUS_BAD_REQUEST);
		}
		if (($upload['size'] ?? 0) > self::MAX_SIZE) {
			return new DataResponse(['message' => $this->l10n->t('Datei zu groß (max. 20 MB)')], Http::STATUS_BAD_REQUEST);
		}

		$finfo = new \finfo(FILEINFO_MIME_TYPE);
		$detectedMime = $finfo->file($upload['tmp_name']);
		if ($detectedMime === false || !AttachmentWatchFolderService::isReceipt($detectedMime)) {
			return new DataResponse(['message' => $this->l10n->t('Nur Bilder (JPG/PNG/GIF/WebP) und PDFs erlaubt')], Http::STATUS_BAD_REQUEST);
		}

		$content = file_get_contents($upload['tmp_name']);
		if ($content === false) {
			return new DataResponse(['message' => $this->l10n->t('Datei konnte nicht gelesen werden')], Http::STATUS_INTERNAL_SERVER_ERROR);
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
			$attachment = $this->storageService->store($attachment, (int)substr((string)$journal->getDate(), 0, 4), $content);
		} catch (\Throwable $e) {
			$this->attachmentMapper->delete($attachment);
			return new DataResponse(['message' => $this->l10n->t('Datei konnte nicht gespeichert werden: %s', [$e->getMessage()])], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$this->audit->log('Beleg hinzugefügt', 'attachment', $attachment->getId(), [
			'journalId' => $journalId,
			'fileName' => $attachment->getFileName(),
		]);
		return new DataResponse($this->serialize($attachment), Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function link(int $journalId, int $fileId): DataResponse {
		$journal = $this->openJournal($journalId);
		if ($journal instanceof DataResponse) {
			return $journal;
		}
		if (!$this->storageService->isWatchMode()) {
			return new DataResponse(['message' => $this->l10n->t('Der Wächter-Ordner für Belege ist nicht eingeschaltet.')], Http::STATUS_BAD_REQUEST);
		}
		try {
			$attachment = $this->watchFolder->link($this->userId(), $journalId, $fileId);
		} catch (\RuntimeException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$this->audit->log('Beleg verknüpft', 'attachment', $attachment->getId(), [
			'journalId' => $journalId,
			'fileName' => $attachment->getFileName(),
		]);
		return new DataResponse($this->serialize($attachment), Http::STATUS_CREATED);
	}

	/**
	 * Der Eingangskorb: alle Dateien des Wächter-Ordners mit ihren Buchungen,
	 * dazu die Belege ohne Datei samt Buchung – damit die Übersicht sagen
	 * kann, wo nachzusehen ist.
	 */
	#[NoAdminRequired]
	public function inbox(): DataResponse {
		$inbox = $this->watchFolder->inbox($this->userId());
		$journals = $this->journalMapper->findByIds($this->userId(), array_map(
			static fn (Attachment $a): int => $a->getJournalId(),
			$inbox['missing'],
		));
		$inbox['missing'] = array_map(function (Attachment $a) use ($journals): array {
			$row = $a->jsonSerialize();
			$journal = $journals[$a->getJournalId()] ?? null;
			if ($journal !== null) {
				$row['entryNo'] = $journal->getEntryNo();
				$row['date'] = (string)$journal->getDate();
				$row['description'] = (string)$journal->getDescription();
			}
			return $row;
		}, $inbox['missing']);
		return new DataResponse($inbox);
	}

	#[NoAdminRequired]
	public function inboxSummary(): DataResponse {
		return new DataResponse($this->watchFolder->summary($this->userId()));
	}

	/** Eine Datei des Wächter-Ordners inline ausliefern – die Vorschau im Eingangskorb und im Auswahldialog. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function inboxView(int $fileId): DataDownloadResponse|DataResponse {
		try {
			$file = $this->watchFolder->fileInFolder($fileId);
			$content = $file->getContent();
		} catch (\Throwable) {
			return new DataResponse(['message' => $this->l10n->t('Datei nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		return $this->inline($content, $file->getName(), $file->getMimetype());
	}

	/**
	 * Inline ausgelieferte Fremdinhalte (PDFs können Skripte enthalten)
	 * dürfen im eigenen Ursprung nichts ausführen.
	 */
	private function inline(string $content, string $name, string $mime): DataDownloadResponse {
		$response = new DataDownloadResponse($content, $name, $mime);
		$response->addHeader('Content-Disposition', 'inline; filename="' . addslashes($name) . '"');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->setContentSecurityPolicy(new EmptyContentSecurityPolicy());
		return $response;
	}

	/** Beleg inline ausliefern (für In-App-Viewer-Modal). */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function view(int $id): DataDownloadResponse|DataResponse {
		try {
			$attachment = $this->attachmentMapper->findOne($id, $this->userId());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		try {
			$content = $this->storageService->contentOf($attachment);
		} catch (\Throwable) {
			return new DataResponse(['message' => $this->l10n->t('Datei nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
		return $this->inline($content, $attachment->getFileName(), $attachment->getMimeType());
	}

	/** Beleg herunterladen (Content-Disposition: attachment). */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function download(int $id): DataDownloadResponse|DataResponse {
		try {
			$attachment = $this->attachmentMapper->findOne($id, $this->userId());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Nicht gefunden')], Http::STATUS_NOT_FOUND);
		}

		try {
			$content = $this->storageService->contentOf($attachment);
		} catch (\Throwable) {
			return new DataResponse(['message' => $this->l10n->t('Datei nicht gefunden')], Http::STATUS_NOT_FOUND);
		}

		return new DataDownloadResponse($content, $attachment->getFileName(), $attachment->getMimeType());
	}

	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		try {
			$attachment = $this->attachmentMapper->findOne($id, $this->userId());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Nicht gefunden')], Http::STATUS_NOT_FOUND);
		}

		// Festschreibung: Belege eines abgeschlossenen Geschäftsjahres bleiben
		// unangetastet.
		try {
			$journal = $this->journalMapper->find($attachment->getJournalId(), $this->userId());
			$this->periods->assertOpen($this->userId(), (string)$journal->getDate());
		} catch (DoesNotExistException) {
			// Buchung existiert nicht mehr → verwaister Beleg darf immer weg.
		}

		// Datensatz zuerst, Datei danach – siehe AttachmentStorageService::deleteOne().
		$kept = $this->storageService->deleteOne($attachment);
		$this->audit->log($kept ? 'Beleg-Verknüpfung gelöst' : 'Beleg gelöscht', 'attachment', $id, [
			'journalId' => $attachment->getJournalId(),
			'fileName' => $attachment->getFileName(),
		]);
		return new DataResponse([]);
	}
}
