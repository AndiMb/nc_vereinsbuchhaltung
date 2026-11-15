<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\BackupController;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Middleware\NoRevisionBump;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupDownloadStore;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupExportService;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupImportService;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupWipeService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\AppConfigStub;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Endpunkte der Sicherung (Issue #111): nur Verwalter, schreibende mit
 * Passwortbestätigung; das Passwort der Sicherung taucht in keinem Protokoll
 * und keiner Antwort auf.
 */
class BackupControllerTest extends TestCase {
	private const PASSWORD = 'Sehr-Geheim-2026';

	private BackupExportService&MockObject $export;
	private BackupImportService&MockObject $import;
	private BackupWipeService&MockObject $wipe;
	private BackupDownloadStore&MockObject $downloads;
	private AuditService&MockObject $audit;
	private LoggerInterface&MockObject $logger;
	private IAppConfig $config;
	private Folder&MockObject $userFolder;
	/** @var array<string, mixed> */
	private array $params = [];
	/** @var array<string, mixed>|null */
	private ?array $upload = null;
	/** @var list<array{0: string, 1: array<string, mixed>}> */
	private array $logged = [];
	/** @var list<string> */
	private array $tempFiles = [];

	protected function setUp(): void {
		$this->export = $this->createMock(BackupExportService::class);
		$this->import = $this->createMock(BackupImportService::class);
		$this->wipe = $this->createMock(BackupWipeService::class);
		$this->downloads = $this->createMock(BackupDownloadStore::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->audit->method('log')->willReturnCallback(function (string $action, ?string $type = null, ?int $id = null, array $details = []): void {
			$this->logged[] = [$action, $details];
		});
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->config = AppConfigStub::create($this, ['club_name' => ' SC Muster e.V. ']);
		$this->userFolder = $this->createMock(Folder::class);
	}

	protected function tearDown(): void {
		foreach ($this->tempFiles as $file) {
			if (is_file($file)) {
				@unlink($file);
			}
		}
	}

	private function temp(string $content = ''): string {
		$path = tempnam(sys_get_temp_dir(), 'vbhctl');
		file_put_contents($path, $content);
		$this->tempFiles[] = $path;
		return $path;
	}

	private function controller(): BackupController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
		$request->method('getUploadedFile')->willReturnCallback(fn (string $key) => $key === 'file' ? $this->upload : null);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willReturn($this->userFolder);

		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(fn (): string => $this->temp());

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('0.53.0');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $params === [] ? $text : vsprintf($text, $params));

		// is_uploaded_file() erkennt nur echte HTTP-Uploads; im Test gilt jede vorhandene Datei.
		return new class($request, $this->export, $this->import, $this->wipe, $this->downloads, $this->audit, $session, $root, $temp, $apps, $this->config, $l10n, $this->logger) extends BackupController {
			protected function isUploadedFile(string $path): bool {
				return is_file($path);
			}
		};
	}

	// --- Rechte ---------------------------------------------------------------

	public function testJederEndpunktVerlangtDenVerwalter(): void {
		$methods = ['status', 'export', 'download', 'inspect', 'import', 'wipe'];
		foreach ($methods as $name) {
			$method = new \ReflectionMethod(BackupController::class, $name);
			$roles = $method->getAttributes(RequiresRole::class);
			$this->assertCount(1, $roles, $name);
			$this->assertSame(PermissionService::ROLE_ADMIN, $roles[0]->newInstance()->role, $name);
			$this->assertCount(1, $method->getAttributes(NoAdminRequired::class), $name);
		}
		foreach (['export', 'inspect', 'import', 'wipe'] as $name) {
			$this->assertCount(1, (new \ReflectionMethod(BackupController::class, $name))->getAttributes(PasswordConfirmationRequired::class), $name);
		}
		// Nur der Download kommt ohne CSRF-Token aus (Navigation des Browsers) – er verändert nichts.
		foreach ($methods as $name) {
			$this->assertCount($name === 'download' ? 1 : 0, (new \ReflectionMethod(BackupController::class, $name))->getAttributes(NoCSRFRequired::class), $name);
		}
		// Erstellen und Ansehen ändern den Bestand nicht: andere Sitzungen sollen deshalb nicht neu laden.
		foreach (['export', 'inspect'] as $name) {
			$this->assertCount(1, (new \ReflectionMethod(BackupController::class, $name))->getAttributes(NoRevisionBump::class), $name);
		}
	}

	// --- Status ---------------------------------------------------------------

	public function testStatus(): void {
		$this->wipe->method('isEmpty')->willReturn(false);
		$this->wipe->method('counts')->willReturn(['vbh_accounts' => 14]);

		$data = $this->controller()->status()->getData();

		$this->assertFalse($data['empty']);
		$this->assertSame(['vbh_accounts' => 14], $data['counts']);
		$this->assertSame('0.53.0', $data['appVersion']);
		$this->assertSame('SC Muster e.V.', $data['clubName']);
		$this->assertSame(10, $data['minPasswordLength']);
		$this->assertTrue($data['zipAvailable']);
	}

	// --- Erstellen ------------------------------------------------------------

	public function testExportLegtDieDateiZumAbholenBereitUndProtokolliertOhnePasswort(): void {
		$path = $this->temp('verschlüsselt');
		$this->params = ['password' => self::PASSWORD, 'withFiles' => 'false'];
		$this->export->expects($this->once())->method('export')->with(self::PASSWORD, false)
			->willReturn(['path' => $path, 'fileName' => 'vereinsbuchhaltung-SC-2026-11-12.vbhbackup', 'rows' => 120, 'files' => 0, 'missingFiles' => [['kind' => 'attachments', 'id' => 6]]]);
		$this->downloads->expects($this->once())->method('put')->with($path, 'vereinsbuchhaltung-SC-2026-11-12.vbhbackup')->willReturn('abc123');

		$response = $this->controller()->export();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['token' => 'abc123', 'fileName' => 'vereinsbuchhaltung-SC-2026-11-12.vbhbackup', 'rows' => 120, 'files' => 0, 'missingFiles' => [['kind' => 'attachments', 'id' => 6]], 'size' => 14], $response->getData());
		$this->assertSame([['Sicherung erstellt', ['belege' => false, 'zeilen' => 120, 'dateien' => 0, 'fehlende_dateien' => 1]]], $this->logged);
		$this->assertStringNotContainsString(self::PASSWORD, json_encode([$this->logged, $response->getData()]));
		$this->assertFileDoesNotExist($path, 'Die temporäre Datei wird nach dem Bereitlegen entfernt.');
	}

	public function testExportMitBelegenIstDieVorgabe(): void {
		$this->params = ['password' => self::PASSWORD];
		$this->export->expects($this->once())->method('export')->with(self::PASSWORD, true)
			->willReturn(['path' => $this->temp('x'), 'fileName' => 'a.vbhbackup', 'rows' => 1, 'files' => 2, 'missingFiles' => []]);
		$this->downloads->method('put')->willReturn('t');

		$this->assertSame(Http::STATUS_OK, $this->controller()->export()->getStatus());
	}

	public function testExportFehlerFachlichUndIntern(): void {
		$this->params = ['password' => 'kurz'];
		$this->export->method('export')->willThrowException(new UserFacingException('Das Passwort braucht mindestens 10 Zeichen.'));
		$response = $this->controller()->export();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Das Passwort braucht mindestens 10 Zeichen.'], $response->getData());
		$this->assertSame([], $this->logged);

		$this->setUp();
		$this->params = ['password' => self::PASSWORD];
		$this->export->method('export')->willThrowException(new \RuntimeException('SQLSTATE[HY000] /var/www/html/data geheim ' . self::PASSWORD));
		$this->logger->expects($this->once())->method('error');
		$response = $this->controller()->export();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Die Sicherung ist fehlgeschlagen.'], $response->getData());
	}

	public function testDownloadLiefertDieBereitgelegteDatei(): void {
		$path = $this->temp('verschlüsselt');
		$this->downloads->expects($this->once())->method('take')->with('abc123')->willReturn(['path' => $path, 'fileName' => 'vereinsbuchhaltung-SC "x".vbhbackup']);

		$response = $this->controller()->download('abc123');

		$this->assertInstanceOf(StreamResponse::class, $response);
		// Response::getHeaders() greift auf den Server-Container zu; die gesetzten Header stehen im Objekt.
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		$this->assertSame('application/octet-stream', $headers['Content-Type']);
		$this->assertSame('14', $headers['Content-Length']);
		$this->assertSame('attachment; filename="vereinsbuchhaltung-SC _x_.vbhbackup"', $headers['Content-Disposition']);
	}

	public function testDownloadUnbekanntesToken(): void {
		$this->downloads->method('take')->willReturn(null);

		$response = $this->controller()->download('gibtsnicht');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// --- Ansehen und Einspielen ----------------------------------------------

	public function testInspectMitUpload(): void {
		$file = $this->temp('datei');
		$this->upload = ['tmp_name' => $file, 'name' => 'x.vbhbackup', 'error' => 0];
		$this->params = ['password' => self::PASSWORD];
		$this->import->expects($this->once())->method('inspect')->with($file, self::PASSWORD)->willReturn(['clubName' => 'SC', 'compatible' => true]);

		$response = $this->controller()->inspect();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['clubName' => 'SC', 'compatible' => true], $response->getData());
		$this->assertSame([], $this->logged);
	}

	public function testImportReichtDenAufruferDurchUndProtokolliert(): void {
		$file = $this->temp('datei');
		$this->upload = ['tmp_name' => $file, 'name' => 'x.vbhbackup', 'error' => 0];
		$this->params = ['password' => self::PASSWORD];
		$this->import->method('inspect')->willReturn(['clubName' => 'SC Muster', 'createdAt' => '2026-11-12T10:00:00+01:00']);
		$result = ['rows' => 120, 'tables' => 46, 'files' => 4, 'missingFiles' => [], 'unknownUsers' => ['gone'], 'unknownGroups' => []];
		$this->import->expects($this->once())->method('import')->with($file, self::PASSWORD, 'alice')->willReturn($result);

		$response = $this->controller()->import();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
		$this->assertSame([['Sicherung eingespielt', ['verein' => 'SC Muster', 'stand' => '2026-11-12T10:00:00+01:00', 'zeilen' => 120, 'dateien' => 4, 'unbekannte_benutzer' => ['gone'], 'unbekannte_gruppen' => []]]], $this->logged);
		$this->assertStringNotContainsString(self::PASSWORD, json_encode($this->logged));
	}

	public function testImportAusDenEigenenDateien(): void {
		$node = $this->createMock(File::class);
		$source = $this->temp('inhalt der sicherung');
		$node->method('fopen')->with('r')->willReturnCallback(static fn () => fopen($source, 'rb'));
		$this->userFolder->method('get')->with('Sicherungen/verein.vbhbackup')->willReturn($node);
		$this->params = ['password' => self::PASSWORD, 'path' => '/Sicherungen/verein.vbhbackup'];
		$this->import->method('inspect')->willReturn([]);
		$seen = null;
		$this->import->expects($this->once())->method('import')->willReturnCallback(function (string $path) use (&$seen): array {
			$seen = file_get_contents($path);
			return ['rows' => 1, 'tables' => 46, 'files' => 0, 'missingFiles' => [], 'unknownUsers' => [], 'unknownGroups' => []];
		});

		$this->assertSame(Http::STATUS_OK, $this->controller()->import()->getStatus());
		$this->assertSame('inhalt der sicherung', $seen);
	}

	public function testPfadAufOrdnerOderAusserhalbWirdAbgelehnt(): void {
		$this->import->expects($this->never())->method('import');
		$this->import->expects($this->never())->method('inspect');
		$this->userFolder->method('get')->willReturnCallback(function (string $path) {
			if ($path === 'Ordner') {
				return $this->createMock(Folder::class);
			}
			throw new NotFoundException();
		});

		foreach (['Ordner', 'gibtsnicht.vbhbackup', '../bob/files/x.vbhbackup', 'a/../../x'] as $path) {
			$this->params = ['password' => self::PASSWORD, 'path' => $path];
			foreach (['inspect', 'import'] as $action) {
				$response = $this->controller()->$action();
				$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $action . ' ' . $path);
				$this->assertSame(['message' => 'Die Sicherungsdatei wurde nicht gefunden.'], $response->getData(), $action . ' ' . $path);
			}
		}
	}

	public function testImportFehlerFachlichUndIntern(): void {
		$this->upload = ['tmp_name' => $this->temp('datei'), 'name' => 'x', 'error' => 0];
		$this->params = ['password' => self::PASSWORD];
		$this->import->method('inspect')->willReturn([]);
		$this->import->method('import')->willThrowException(new UserFacingException('Die Buchhaltung ist nicht leer. Eine Sicherung lässt sich nur in einen leeren Bestand einspielen.'));
		$response = $this->controller()->import();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Die Buchhaltung ist nicht leer. Eine Sicherung lässt sich nur in einen leeren Bestand einspielen.', $response->getData()['message']);
		$this->assertSame([], $this->logged);

		$this->setUp();
		$this->upload = ['tmp_name' => $this->temp('datei'), 'name' => 'x', 'error' => 0];
		$this->params = ['password' => self::PASSWORD];
		$this->import->method('inspect')->willReturn([]);
		$this->import->method('import')->willThrowException(new \RuntimeException('SQLSTATE duplicate key oc_vbh_journal'));
		$response = $this->controller()->import();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Das Einspielen ist fehlgeschlagen. Der Bestand wurde nicht verändert.'], $response->getData());
	}

	// --- Leeren ---------------------------------------------------------------

	public function testLeerenVerlangtDenVereinsnamen(): void {
		$this->wipe->expects($this->never())->method('wipe');

		foreach (['', 'LÖSCHEN', 'sc muster e.v.', 'SC Muster'] as $confirmation) {
			$this->params = ['confirmation' => $confirmation];
			$response = $this->controller()->wipe();
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $confirmation);
			$this->assertSame(['message' => 'Die Bestätigung stimmt nicht.'], $response->getData());
		}
		$this->assertSame([], $this->logged);
	}

	public function testLeerenMitVereinsnamenProtokolliertVorher(): void {
		$this->params = ['confirmation' => '  SC Muster e.V.'];
		$this->wipe->method('counts')->willReturn(['vbh_accounts' => 14, 'vbh_journal' => 900, 'vbh_members' => 0]);
		$this->wipe->expects($this->once())->method('wipe')->willReturnCallback(function (): void {
			$this->assertCount(1, $this->logged, 'Das Protokoll wird vor dem Leeren geschrieben.');
		});

		$response = $this->controller()->wipe();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['Bestand vollständig geleert', ['vbh_accounts' => 14, 'vbh_journal' => 900]]], $this->logged);
	}

	public function testLeerenOhneVereinsnamenVerlangtDasWort(): void {
		$this->config->deleteKey('vereinsbuchhaltung', 'club_name');
		$this->wipe->method('counts')->willReturn([]);
		$this->wipe->expects($this->once())->method('wipe');

		$this->params = ['confirmation' => 'löschen'];
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->wipe()->getStatus());

		$this->params = ['confirmation' => 'LÖSCHEN'];
		$this->assertSame(Http::STATUS_OK, $this->controller()->wipe()->getStatus());
	}

	public function testLeerenFehler(): void {
		$this->params = ['confirmation' => 'SC Muster e.V.'];
		$this->wipe->method('counts')->willReturn([]);
		$this->wipe->method('wipe')->willThrowException(new \RuntimeException('SQLSTATE'));

		$response = $this->controller()->wipe();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['message' => 'Das Leeren ist fehlgeschlagen.'], $response->getData());
	}

	/**
	 * Eine Sicherung über der Upload-Grenze kommt gar nicht erst an: PHP
	 * meldet einen Fehlercode oder – über post_max_size – eine völlig leere
	 * Anfrage. Die Meldung nennt dann den Ausweg statt „nicht gefunden“.
	 */
	public function testZuGrosserUploadNenntDenAusweg(): void {
		$this->import->expects($this->never())->method('inspect');
		$this->import->expects($this->never())->method('import');
		$expected = ['message' => 'Die Datei ist nicht angekommen – vermutlich ist sie größer, als dieser Server für einen Upload annimmt. Legen Sie sie in Ihre Nextcloud-Dateien und wählen Sie sie von dort.'];

		$cases = [
			'upload_max_filesize' => [['tmp_name' => '', 'name' => 'x.vbhbackup', 'error' => UPLOAD_ERR_INI_SIZE], ['password' => self::PASSWORD]],
			'Formulargrenze' => [['tmp_name' => '', 'name' => 'x.vbhbackup', 'error' => UPLOAD_ERR_FORM_SIZE], ['password' => self::PASSWORD]],
			'abgebrochen' => [['tmp_name' => '', 'name' => 'x.vbhbackup', 'error' => UPLOAD_ERR_PARTIAL], ['password' => self::PASSWORD]],
			'post_max_size: leere Anfrage' => [null, []],
		];
		foreach ($cases as $name => [$upload, $params]) {
			$this->upload = $upload;
			$this->params = $params;
			foreach (['inspect', 'import'] as $action) {
				$response = $this->controller()->$action();
				$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $action . ' ' . $name);
				$this->assertSame($expected, $response->getData(), $action . ' ' . $name);
			}
		}
	}
}
