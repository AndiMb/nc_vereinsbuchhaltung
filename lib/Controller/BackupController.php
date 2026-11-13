<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Middleware\NoRevisionBump;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupContainer;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupDownloadStore;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupExportService;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupImportService;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupWipeService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\SafeFileName;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Sicherung und Umzug (Issue #111): den gesamten Bestand in eine
 * passwortverschlüsselte Datei sichern, eine solche Datei in einen leeren
 * Bestand einspielen, und den Bestand dafür vollständig leeren.
 *
 * Alles hier ist dem Verwalter vorbehalten; was den Bestand verlässt oder
 * verändert, verlangt zusätzlich die Bestätigung des Nextcloud-Passworts.
 * Das Passwort der Sicherung wird nur durchgereicht – es steht in keinem
 * Protokolleintrag und keiner Antwort.
 */
class BackupController extends Controller {

	use InternalErrorResponder;

	/** Bestätigungswort für das Leeren, solange kein Vereinsname eingetragen ist. */
	public const WIPE_WORD = 'LÖSCHEN';

	public function __construct(
		IRequest $request,
		private BackupExportService $exportService,
		private BackupImportService $importService,
		private BackupWipeService $wipeService,
		private BackupDownloadStore $downloads,
		private AuditService $audit,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private ITempManager $tempManager,
		private IAppManager $appManager,
		private IAppConfig $config,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function status(): DataResponse {
		return new DataResponse([
			'empty' => $this->wipeService->isEmpty(),
			'counts' => $this->wipeService->counts(),
			'appVersion' => $this->appManager->getAppVersion(Application::APP_ID),
			'clubName' => $this->clubName(),
			'minPasswordLength' => BackupContainer::MIN_PASSWORD_LENGTH,
			'zipAvailable' => class_exists(\ZipArchive::class),
		]);
	}

	/**
	 * Erstellt die Sicherung und legt sie zum Herunterladen bereit
	 * ({@see download()}).
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	#[PasswordConfirmationRequired]
	#[NoRevisionBump]
	public function export(): DataResponse {
		$this->noTimeLimit();
		$withFiles = filter_var($this->request->getParam('withFiles', true), FILTER_VALIDATE_BOOLEAN);
		try {
			$result = $this->exportService->export($this->password(), $withFiles);
			try {
				$size = (int)filesize($result['path']);
				$token = $this->downloads->put($result['path'], $result['fileName']);
			} finally {
				@unlink($result['path']);
			}
			$this->audit->log('Sicherung erstellt', 'backup', null, [
				'belege' => $withFiles,
				'zeilen' => $result['rows'],
				'dateien' => $result['files'],
				'fehlende_dateien' => count($result['missingFiles']),
			]);
			return new DataResponse([
				'token' => $token,
				'fileName' => $result['fileName'],
				'rows' => $result['rows'],
				'files' => $result['files'],
				'missingFiles' => $result['missingFiles'],
				'size' => $size,
			]);
		} catch (\Throwable $e) {
			return $this->failed($e, $this->l10n->t('Die Sicherung ist fehlgeschlagen.'));
		}
	}

	/**
	 * Liefert die bereitgelegte Sicherung als Datenstrom. Das Token gilt
	 * einmal; die Datei ist bereits verschlüsselt.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	public function download(string $token): StreamResponse|DataResponse {
		try {
			$file = $this->downloads->take($token);
		} catch (\Throwable $e) {
			return $this->failed($e, $this->l10n->t('Die Sicherung ist fehlgeschlagen.'));
		}
		if ($file === null) {
			return new DataResponse(['message' => $this->l10n->t('Diese Sicherung liegt nicht mehr bereit. Bitte erstellen Sie sie erneut.')], Http::STATUS_NOT_FOUND);
		}
		$response = new StreamResponse($file['path']);
		$response->addHeader('Content-Type', 'application/octet-stream');
		$response->addHeader('Content-Length', (string)(filesize($file['path']) ?: 0));
		$response->addHeader('Content-Disposition', 'attachment; filename="' . SafeFileName::of($file['fileName'], 150) . '"');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
	}

	/** Liest eine Sicherung, ohne etwas zu verändern: was steckt darin, ließe sie sich einspielen? */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	#[PasswordConfirmationRequired]
	#[NoRevisionBump]
	public function inspect(): DataResponse {
		$this->noTimeLimit();
		try {
			return new DataResponse($this->importService->inspect($this->backupFile(), $this->password()));
		} catch (\Throwable $e) {
			return $this->failed($e, $this->l10n->t('Die Sicherung konnte nicht gelesen werden.'));
		}
	}

	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	#[PasswordConfirmationRequired]
	public function import(): DataResponse {
		$this->noTimeLimit();
		try {
			$path = $this->backupFile();
			$password = $this->password();
			$info = $this->importService->inspect($path, $password);
			$result = $this->importService->import($path, $password, $this->uid());
			// Erst danach: das Änderungsprotokoll kommt selbst aus der Sicherung.
			$this->audit->log('Sicherung eingespielt', 'backup', null, [
				'verein' => $info['clubName'] ?? '',
				'stand' => $info['createdAt'] ?? '',
				'zeilen' => $result['rows'],
				'dateien' => $result['files'],
				'unbekannte_benutzer' => $result['unknownUsers'],
				'unbekannte_gruppen' => $result['unknownGroups'],
			]);
			return new DataResponse($result);
		} catch (\Throwable $e) {
			return $this->failed($e, $this->l10n->t('Das Einspielen ist fehlgeschlagen. Der Bestand wurde nicht verändert.'));
		}
	}

	/**
	 * „Bestand vollständig leeren“. Verlangt als Bestätigung den
	 * Vereinsnamen – oder, solange keiner eingetragen ist, das Wort
	 * {@see WIPE_WORD}.
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_ADMIN)]
	#[PasswordConfirmationRequired]
	public function wipe(): DataResponse {
		$confirmation = $this->request->getParam('confirmation');
		$expected = $this->clubName() !== '' ? $this->clubName() : self::WIPE_WORD;
		if (!is_string($confirmation) || trim($confirmation) !== $expected) {
			return new DataResponse(['message' => $this->l10n->t('Die Bestätigung stimmt nicht.')], Http::STATUS_BAD_REQUEST);
		}
		$this->noTimeLimit();
		try {
			// Vor dem Leeren protokollieren – das Protokoll bleibt stehen und nennt, was ging.
			$this->audit->log('Bestand vollständig geleert', 'backup', null, array_filter($this->wipeService->counts()));
			$this->wipeService->wipe();
			return new DataResponse([]);
		} catch (\Throwable $e) {
			return $this->failed($e, $this->l10n->t('Das Leeren ist fehlgeschlagen.'));
		}
	}

	/**
	 * Die Sicherungsdatei der Anfrage als lokaler Pfad: hochgeladen (`file`)
	 * oder aus den eigenen Nextcloud-Dateien des Aufrufers (`path`).
	 *
	 * @throws UserFacingException wenn keine Datei kam oder der Pfad auf keine Datei zeigt
	 */
	private function backupFile(): string {
		$upload = $this->request->getUploadedFile('file');
		if (is_array($upload) && is_string($upload['tmp_name'] ?? null) && $upload['tmp_name'] !== '' && $this->isUploadedFile($upload['tmp_name'])) {
			return $upload['tmp_name'];
		}

		$notFound = new UserFacingException($this->l10n->t('Die Sicherungsdatei wurde nicht gefunden.'));
		$path = $this->request->getParam('path');
		$path = is_string($path) ? trim(str_replace('\\', '/', $path), '/') : '';
		if ($path === '') {
			// Weder Upload noch Pfad: Über upload_max_filesize meldet PHP einen Fehlercode,
			// über post_max_size kommt die Anfrage völlig leer an.
			throw new UserFacingException($this->l10n->t('Die Datei ist nicht angekommen – vermutlich ist sie größer, als dieser Server für einen Upload annimmt. Legen Sie sie in Ihre Nextcloud-Dateien und wählen Sie sie von dort.'));
		}
		if (in_array('..', explode('/', $path), true)) {
			throw $notFound;
		}
		try {
			$node = $this->rootFolder->getUserFolder($this->uid())->get($path);
		} catch (\Throwable) {
			throw $notFound;
		}
		if (!$node instanceof File) {
			throw $notFound;
		}
		$source = $node->fopen('r');
		$local = $this->tempManager->getTemporaryFile('.vbhbackup');
		$target = fopen($local, 'wb');
		if (!is_resource($source) || $target === false) {
			throw $notFound;
		}
		stream_copy_to_stream($source, $target);
		fclose($source);
		fclose($target);
		return $local;
	}

	/** Eigene Methode, damit Tests ohne echten HTTP-Upload auskommen. */
	protected function isUploadedFile(string $path): bool {
		return is_uploaded_file($path);
	}

	private function password(): string {
		$password = $this->request->getParam('password');
		return is_string($password) ? $password : '';
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	private function clubName(): string {
		return trim($this->config->getValueString(Application::APP_ID, 'club_name', ''));
	}

	/** Fachliche Meldungen gehen als 400 durch, alles andere nur ins Log (Issue #261). */
	private function failed(\Throwable $e, string $fallback): DataResponse {
		return $this->internalError($e, $e instanceof UserFacingException ? Http::STATUS_BAD_REQUEST : Http::STATUS_INTERNAL_SERVER_ERROR, $fallback);
	}

	/** Ein großer Bestand braucht länger als die übliche Laufzeitgrenze einer Anfrage. */
	private function noTimeLimit(): void {
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}
	}
}
