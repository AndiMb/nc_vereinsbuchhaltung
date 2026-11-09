<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\SettingsController;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\AttachmentWatchFolderService;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\ClubCurrency;
use OCA\Vereinsbuchhaltung\Service\ClubCurrencySwitchGuard;
use OCA\Vereinsbuchhaltung\Service\ContributionYearService;
use OCA\Vereinsbuchhaltung\Service\DunningSettings;
use OCA\Vereinsbuchhaltung\Service\FolderPathValidator;
use OCA\Vereinsbuchhaltung\Service\ForeignHomeGuard;
use OCA\Vereinsbuchhaltung\Service\FrontendStateService;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCA\Vereinsbuchhaltung\Service\MandateExpirySettings;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementSettings;
use OCA\Vereinsbuchhaltung\Service\SepaDebtorAccountService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\Folder;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Ein Verwalter der App muss kein Nextcloud-Administrator sein. Belegablage
 * und Wachordner lesen, verschieben und schreiben aber mit den Rechten der App
 * im Home des gewählten Nutzers. Einen fremden Ordner wählen oder umstellen
 * darf deshalb nur, wer ohnehin an fremde Homes herankommt.
 */
class SettingsControllerForeignHomeTest extends TestCase {

	private const SELF = 'vera';
	private const FOREIGN = 'opfer';
	private const MESSAGE = 'Nur Nextcloud-Administratoren dürfen einen Ordner im Home eines anderen Nutzers wählen.';

	private IRequest&MockObject $request;
	private AttachmentStorageService&MockObject $storage;
	private AttachmentWatchFolderService&MockObject $watch;
	private AuditService&MockObject $audit;
	/** @var array<string, string> */
	private array $store = [];
	/** @var list<array{0: string, 1: array<string, mixed>}> */
	private array $auditEntries = [];
	private bool $serverAdmin = false;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->storage = $this->createMock(AttachmentStorageService::class);
		$this->storage->method('mode')->willReturnCallback(fn () => $this->store['storage_mode'] ?? AttachmentStorageService::MODE_APPDATA);
		$this->storage->method('subfoldersAt')->willReturn([]);
		$this->storage->method('storageUser')->willReturnCallback(fn () => $this->store['storage_user'] ?? '');
		$this->storage->method('folderAt')->willReturn($this->createMock(Folder::class));
		$this->watch = $this->createMock(AttachmentWatchFolderService::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->audit->method('log')->willReturnCallback(function (string $action, ?string $type = null, ?int $id = null, array $details = []): void {
			$this->auditEntries[] = [$action, $details];
		});
	}

	private function controller(): SettingsController {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default);
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->store[$key] = $value;
			return true;
		});
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('getRole')->willReturn(PermissionService::ROLE_ADMIN);
		$permissions->method('isServerAdmin')->willReturnCallback(fn () => $this->serverAdmin);
		$permissions->method('describeCurrent')->willReturn(['uid' => self::SELF]);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid) => in_array($uid, [self::SELF, self::FOREIGN], true));
		$contributionYear = $this->createMock(ContributionYearService::class);
		$contributionYear->method('getStartMonth')->willReturn(1);
		// Den Einstellungssatz der Antwort prüft SettingsControllerSepaTest; hier
		// geht es nur um Status und gespeicherte Werte.
		$frontendState = $this->createMock(FrontendStateService::class);
		$frontendState->method('settings')->willReturn([]);

		return new SettingsController(
			$this->request,
			$config,
			$permissions,
			$this->createMock(AccountMapper::class),
			$users,
			$this->createMock(SepaDebtorAccountService::class),
			$this->storage,
			$this->watch,
			$contributionYear,
			new FolderPathValidator($l10n),
			new DunningSettings($config),
			new MandateExpirySettings($config),
			new InvoiceSettings($config, new FolderPathValidator($l10n)),
			$this->createMock(ReimbursementSettings::class),
			$l10n,
			$frontendState,
			$this->audit,
			new ForeignHomeGuard($permissions, $this->storage, $l10n),
			$this->createMock(ClubCurrency::class),
			$this->createMock(ClubCurrencySwitchGuard::class),
		);
	}

	/** @param array<string, mixed> $params */
	private function update(array $params): DataResponse {
		$this->request->method('getParams')->willReturn($params);
		return $this->controller()->update();
	}

	private function storeForeignStorage(string $mode = AttachmentStorageService::MODE_USER): void {
		$this->store['storage_user'] = self::FOREIGN;
		$this->store['storage_path'] = 'Belege';
		$this->store['storage_mode'] = $mode;
	}

	private function storeForeignWatch(): void {
		$this->store['statement_watch_user'] = self::FOREIGN;
		$this->store['statement_watch_path'] = 'Auszuege';
	}

	private function assertRejected(DataResponse $response): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(self::MESSAGE, $response->getData()['message']);
	}

	// Ordnerwahl

	public function testOrdnerwahlImFremdenHomeIstVerwalternOhneNextcloudAdminVerwehrt(): void {
		$this->storage->expects($this->never())->method('subfoldersAt');
		$this->assertRejected($this->controller()->folders(self::FOREIGN));
	}

	public function testOrdnerwahlVerraetNichtObEinNutzerExistiert(): void {
		$this->assertRejected($this->controller()->folders('gibt-es-nicht'));
	}

	public function testOrdnerwahlImEigenenHomeBleibtErlaubt(): void {
		$this->assertSame(Http::STATUS_OK, $this->controller()->folders(self::SELF)->getStatus());
	}

	public function testNextcloudAdminDarfDieOrdnerEinesFremdenHomesSehen(): void {
		$this->serverAdmin = true;
		$this->assertSame(Http::STATUS_OK, $this->controller()->folders(self::FOREIGN)->getStatus());
	}

	// Belegablage

	public function testFremdesHomeAlsBelegablageWirdAbgelehntUndNichtGespeichert(): void {
		$response = $this->update(['storage_mode' => 'user', 'storage_user' => self::FOREIGN, 'storage_path' => 'Privat']);

		$this->assertRejected($response);
		$this->assertArrayNotHasKey('storage_user', $this->store);
		$this->assertSame([], $this->auditEntries);
	}

	public function testBelegablageVerraetNichtObEinNutzerExistiert(): void {
		$this->assertRejected($this->update(['storage_mode' => 'user', 'storage_user' => 'gibt-es-nicht', 'storage_path' => 'Privat']));
	}

	public function testGrossschreibungUmgehtDieEigenePruefungNicht(): void {
		$this->assertRejected($this->update(['storage_mode' => 'user', 'storage_user' => strtoupper(self::SELF), 'storage_path' => 'Belege']));
	}

	public function testEigenesHomeAlsBelegablageBleibtErlaubt(): void {
		$response = $this->update(['storage_mode' => 'user', 'storage_user' => self::SELF, 'storage_path' => 'Belege']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::SELF, $this->store['storage_user']);
	}

	public function testNextcloudAdminDarfEinFremdesHomeWaehlen(): void {
		$this->serverAdmin = true;
		$response = $this->update(['storage_mode' => 'user', 'storage_user' => self::FOREIGN, 'storage_path' => 'Belege']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::FOREIGN, $this->store['storage_user']);
	}

	public function testUnveraendertGespeicherteFremdeBelegablageBleibtSpeicherbar(): void {
		$this->storeForeignStorage();

		$response = $this->update(['storage_mode' => 'user', 'storage_user' => self::FOREIGN, 'storage_path' => '/Belege/', 'club_name' => 'TSV']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('TSV', $this->store['club_name']);
		$this->assertSame([], $this->auditEntries);
	}

	public function testUnveraendertGespeicherterVerwaisterNutzerBleibtSpeicherbar(): void {
		$this->store['storage_user'] = 'geloescht';
		$this->store['storage_path'] = 'Belege';
		$this->store['storage_mode'] = AttachmentStorageService::MODE_USER;

		$this->assertSame(Http::STATUS_OK, $this->update(['storage_user' => 'geloescht', 'storage_path' => 'Belege', 'storage_mode' => 'user'])->getStatus());
	}

	public function testGespeicherteFremdeBelegablageLaesstSichNichtAufAndereOrdnerUmstellen(): void {
		$this->storeForeignStorage();

		$this->assertRejected($this->update(['storage_mode' => 'user', 'storage_user' => self::FOREIGN, 'storage_path' => 'Privat']));
		$this->assertSame('Belege', $this->store['storage_path']);
	}

	public function testGespeicherteFremdeBelegablageLaesstSichNichtZumWaechterOrdnerMachen(): void {
		$this->storeForeignStorage();
		// Die Ablehnung kommt vor dem Blick ins fremde Home: die Meldung „existiert
		// nicht" verriete sonst, welche Ordner es dort gibt. Auch nachtragen darf
		// die App dann nichts.
		$this->storage->expects($this->never())->method('folderAt');
		$this->watch->expects($this->never())->method('backfillFileIds');

		$this->assertRejected($this->update(['storage_mode' => 'watch', 'storage_user' => self::FOREIGN, 'storage_path' => 'Belege']));
		$this->assertSame(AttachmentStorageService::MODE_USER, $this->store['storage_mode']);
	}

	public function testVerwalterDarfEineFremdeBelegablageAufAppInternZurueckstellen(): void {
		$this->storeForeignStorage(AttachmentStorageService::MODE_WATCH);

		$response = $this->update(['storage_mode' => 'appdata', 'storage_user' => self::FOREIGN, 'storage_path' => 'Belege']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('', $this->store['storage_user']);
		$this->assertSame(AttachmentStorageService::MODE_APPDATA, $this->store['storage_mode']);
	}

	// Wachordner

	public function testFremdesHomeAlsWachordnerWirdAbgelehntUndNichtGespeichert(): void {
		$response = $this->update(['statement_watch_user' => self::FOREIGN, 'statement_watch_path' => 'Privat']);

		$this->assertRejected($response);
		$this->assertArrayNotHasKey('statement_watch_user', $this->store);
		$this->assertSame([], $this->auditEntries);
	}

	public function testWachordnerVerraetNichtObEinNutzerExistiert(): void {
		$this->assertRejected($this->update(['statement_watch_user' => 'gibt-es-nicht', 'statement_watch_path' => 'Privat']));
	}

	public function testEigenesHomeAlsWachordnerBleibtErlaubt(): void {
		$response = $this->update(['statement_watch_user' => self::SELF, 'statement_watch_path' => 'Auszuege']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(self::SELF, $this->store['statement_watch_user']);
	}

	public function testNextcloudAdminDarfEinenFremdenWachordnerWaehlen(): void {
		$this->serverAdmin = true;

		$this->assertSame(Http::STATUS_OK, $this->update(['statement_watch_user' => self::FOREIGN, 'statement_watch_path' => 'Auszuege'])->getStatus());
		$this->assertSame(self::FOREIGN, $this->store['statement_watch_user']);
	}

	public function testUnveraendertGespeicherterFremderWachordnerBleibtSpeicherbar(): void {
		$this->storeForeignWatch();

		$response = $this->update(['statement_watch_user' => self::FOREIGN, 'statement_watch_path' => '/Auszuege/']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $this->auditEntries);
	}

	public function testGespeicherterFremderWachordnerLaesstSichNichtUmstellen(): void {
		$this->storeForeignWatch();

		$this->assertRejected($this->update(['statement_watch_user' => self::FOREIGN, 'statement_watch_path' => 'Privat']));
		$this->assertSame('Auszuege', $this->store['statement_watch_path']);
	}

	public function testVerwalterDarfEinenFremdenWachordnerAbschalten(): void {
		$this->storeForeignWatch();

		$this->assertSame(Http::STATUS_OK, $this->update(['statement_watch_user' => '', 'statement_watch_path' => ''])->getStatus());
		$this->assertSame('', $this->store['statement_watch_user']);
	}

	// Änderungsprotokoll: bisher blieb nicht nachvollziehbar, wer die Ordner umgestellt hat.

	// Unterordner im Home des Belegablage-Nutzers (#307)

	public function testMandatsOrdnerImFremdenHomeLaesstSichNichtUmstellen(): void {
		$this->storeForeignStorage();
		$this->store['mandate_document_folder'] = 'SEPA-Mandate';

		$this->assertRejected($this->update(['mandate_document_folder' => 'Privat']));
		$this->assertSame('SEPA-Mandate', $this->store['mandate_document_folder']);
	}

	public function testRechnungsOrdnerImFremdenHomeLaesstSichNichtUmstellen(): void {
		$this->storeForeignStorage();

		$this->assertRejected($this->update(['invoice_folder' => 'Privat']));
		$this->assertArrayNotHasKey('invoice_folder', $this->store);
	}

	public function testAbgelehnterOrdnerHinterlaesstNichtsHalbGespeichertes(): void {
		$this->storeForeignStorage();

		$this->assertRejected($this->update(['club_name' => 'Neuer Name', 'invoice_folder' => 'Privat']));
		$this->assertArrayNotHasKey('club_name', $this->store);
	}

	public function testUnveraenderteOrdnerImFremdenHomeBleibenSpeicherbar(): void {
		$this->storeForeignStorage();
		$this->store['mandate_document_folder'] = 'SEPA-Mandate';

		// Die Einstellungsseite schickt immer den vollständigen Feldsatz – auch die Vorgabe als leeren Wert.
		$response = $this->update(['mandate_document_folder' => '/SEPA-Mandate/', 'invoice_folder' => '']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testOrdnerImEigenenHomeBleibenFrei(): void {
		$this->store['storage_user'] = self::SELF;
		$this->store['storage_mode'] = AttachmentStorageService::MODE_USER;
		$this->assertSame(Http::STATUS_OK, $this->update(['mandate_document_folder' => 'Eigene/Mandate'])->getStatus());
		$this->assertSame('Eigene/Mandate', $this->store['mandate_document_folder']);
	}

	public function testNextcloudAdminDarfOrdnerImFremdenHomeUmstellen(): void {
		$this->storeForeignStorage();
		$this->serverAdmin = true;
		$this->assertSame(Http::STATUS_OK, $this->update(['invoice_folder' => 'Rechnungen/Neu'])->getStatus());
		$this->assertSame('Rechnungen/Neu', $this->store['invoice_folder']);
	}

	public function testAenderungDerBelegablageStehtImProtokoll(): void {
		$this->store['storage_user'] = self::SELF;
		$this->store['storage_path'] = 'Alt';
		$this->store['storage_mode'] = AttachmentStorageService::MODE_USER;

		$this->update(['storage_mode' => 'user', 'storage_user' => self::SELF, 'storage_path' => 'Neu']);

		$this->assertSame([['Belegablage geändert', [
			'von' => 'user: vera/Alt',
			'nach' => 'user: vera/Neu',
		]]], $this->auditEntries);
	}

	public function testAenderungDesWachordnersStehtImProtokoll(): void {
		$this->update(['statement_watch_user' => self::SELF, 'statement_watch_path' => 'Auszuege']);

		$this->assertSame([['Wachordner geändert', [
			'von' => '',
			'nach' => 'vera/Auszuege',
		]]], $this->auditEntries);
	}
}
