<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\SettingsController;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetMapper;
use OCA\Vereinsbuchhaltung\Db\BudgetSnapshotMapper;
use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\AttachmentWatchFolderService;
use OCA\Vereinsbuchhaltung\Service\ClubClock;
use OCA\Vereinsbuchhaltung\Service\ContributionYearService;
use OCA\Vereinsbuchhaltung\Service\DemoDataService;
use OCA\Vereinsbuchhaltung\Service\DunningSettings;
use OCA\Vereinsbuchhaltung\Service\FolderPathValidator;
use OCA\Vereinsbuchhaltung\Service\FrontendStateService;
use OCA\Vereinsbuchhaltung\Service\InvoiceSettings;
use OCA\Vereinsbuchhaltung\Service\MandateDocumentService;
use OCA\Vereinsbuchhaltung\Service\MandateExpirySettings;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\ReimbursementAccess;
use OCA\Vereinsbuchhaltung\Service\ReimbursementSettings;
use OCA\Vereinsbuchhaltung\Service\SelfServiceService;
use OCA\Vereinsbuchhaltung\Service\SepaDebtorAccountService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die in Issue #101 ergänzten Teile des allgemeinen Einstellungssatzes
 * (`/settings`): Ablauf-Vorwarnung, Mahnabstand und der Nachweis-Ordner der
 * Mandate, der über die gemeinsamen Ordnerpfad-Regeln läuft.
 */
class SettingsControllerSepaTest extends TestCase {

	private IRequest&MockObject $request;
	/** @var array<string, string> */
	private array $store = [];
	private ?FrontendStateService $frontendState = null;

	protected function setUp(): void {
		$this->store = [];
		$this->request = $this->createMock(IRequest::class);
	}

	private function controller(?MemberMapper $members = null, ?AttachmentStorageService $attachmentStorage = null, ?AttachmentWatchFolderService $attachmentWatchFolder = null): SettingsController {
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

		if ($attachmentStorage === null) {
			$attachmentStorage = $this->createMock(AttachmentStorageService::class);
			$attachmentStorage->method('mode')->willReturn(AttachmentStorageService::MODE_APPDATA);
		}
		$mandateDocuments = $this->createMock(MandateDocumentService::class);
		$mandateDocuments->method('folderPath')->willReturnCallback(fn () => $this->store['mandate_document_folder'] ?? 'SEPA-Mandate');
		$mandateDocuments->method('showMissingDocumentWarning')->willReturn(true);
		$contributionYear = $this->createMock(ContributionYearService::class);
		$contributionYear->method('getStartMonth')->willReturn(1);

		$sepaDebtorAccount = $this->createMock(SepaDebtorAccountService::class);
		$dunningSettings = new DunningSettings($config);
		$mandateExpirySettings = new MandateExpirySettings($config);
		$invoiceSettings = new InvoiceSettings($config, new FolderPathValidator($l10n));
		$reimbursementSettings = $this->createMock(ReimbursementSettings::class);
		// Den Einstellungssatz der Antworten stellt seit Issue #264 der
		// FrontendStateService zusammen – echt, mit denselben Abhängigkeiten.
		$frontendState = $this->frontendState = new FrontendStateService(
			$permissions,
			$this->createMock(SelfServiceService::class),
			$this->createMock(ReimbursementAccess::class),
			$this->createMock(PeriodService::class),
			$this->createMock(JournalMapper::class),
			$this->createMock(BudgetMapper::class),
			$this->createMock(BudgetSnapshotMapper::class),
			$config,
			$this->createMock(DemoDataService::class),
			$members ?? $this->createMock(MemberMapper::class),
			$sepaDebtorAccount,
			$attachmentStorage,
			$mandateDocuments,
			$contributionYear,
			$dunningSettings,
			$mandateExpirySettings,
			$invoiceSettings,
			$reimbursementSettings,
		);

		return new SettingsController(
			$this->request,
			$config,
			$permissions,
			$this->createMock(AccountMapper::class),
			$this->createMock(IUserManager::class),
			$sepaDebtorAccount,
			$attachmentStorage,
			$attachmentWatchFolder ?? $this->createMock(AttachmentWatchFolderService::class),
			$contributionYear,
			new FolderPathValidator($l10n),
			$dunningSettings,
			$mandateExpirySettings,
			$invoiceSettings,
			$reimbursementSettings,
			$l10n,
			$frontendState,
		);
	}

	/** @param array<string, mixed> $params */
	private function update(array $params): DataResponse {
		$this->request->method('getParams')->willReturn($params);
		return $this->controller()->update();
	}

	/**
	 * Belegordner bei „buchhaltung", derselbe Ordner als Freigabe bei „admin"
	 * als Kontoauszugsordner (#220): der Import räumte sonst die Belege nach
	 * „fehler/". Ob sich die Ordner überschneiden, entscheidet der Service
	 * über die Datei-IDs – der Controller muss ihn mit beiden Nutzern fragen.
	 */
	public function testKontoauszugsordnerDarfDenBelegordnerEinesAnderenNutzersNichtTreffen(): void {
		$this->store['statement_watch_user'] = 'admin';
		$storage = $this->createMock(AttachmentStorageService::class);
		$storage->method('isWatchMode')->willReturn(true);
		$storage->method('storageUser')->willReturn('buchhaltung');
		$storage->method('storagePath')->willReturn('Belege');
		$watch = $this->createMock(AttachmentWatchFolderService::class);
		$watch->expects($this->once())->method('foldersOverlap')
			->with('buchhaltung', 'Belege', 'admin', 'Freigaben/Belege')
			->willReturn(true);
		$this->request->method('getParams')->willReturn(['statement_watch_user' => 'admin', 'statement_watch_path' => 'Freigaben/Belege']);

		$response = $this->controller(null, $storage, $watch)->update();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('überschneiden', $response->getData()['message']);
		$this->assertArrayNotHasKey('statement_watch_path', $this->store);
	}

	public function testEinstellungssatzLiefertStandardwerteFuerAblaufVorwarnungUndMahnabstand(): void {
		$data = $this->controller()->index()->getData();

		$this->assertSame(180, $data['expiry_warning_days']);
		$this->assertSame(14, $data['dunning_interval_days']);
	}

	/** Betrag und Turnus kommen von der Beitragsgruppe: die frühere Einstellung „Standard-Beitrag“ gibt es nicht mehr. */
	public function testEinstellungssatzKenntKeinenStandardBeitragMehr(): void {
		$this->store['default_fee_amount_cents'] = '800';
		$this->store['default_fee_frequency'] = 'monthly';

		$data = $this->controller()->index()->getData();

		$this->assertArrayNotHasKey('default_fee_amount', $data);
		$this->assertArrayNotHasKey('default_fee_frequency', $data);
	}

	/** Ein alter Client, der die Felder noch mitschickt, schreibt nichts mehr und bekommt keinen Fehler. */
	public function testStandardBeitragFelderEinesAltenClientsWerdenIgnoriert(): void {
		$response = $this->update(['default_fee_amount' => '8,00', 'default_fee_frequency' => 'monthly', 'sepa_creditor_id' => 'DE98ZZZ09999999999']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('DE98ZZZ09999999999', $this->store['sepa_creditor_id']);
		$this->assertArrayNotHasKey('default_fee_amount_cents', $this->store);
		$this->assertArrayNotHasKey('default_fee_frequency', $this->store);
	}

	/** Mandate und Zuweisungen hängen immer an einem Mitglied: seit dem Cutover (Issue #107) genügt es als Kriterium. */
	public function testReiterBeitraegeIstOhneSchalterUndOhneMitgliederVerborgen(): void {
		$members = $this->createMock(MemberMapper::class);
		$members->method('count')->willReturn(0);

		$this->assertFalse($this->controller($members)->index()->getData()['membership_active']);
	}

	public function testReiterBeitraegeErscheintSobaldEinMitgliedExistiert(): void {
		$members = $this->createMock(MemberMapper::class);
		$members->method('count')->willReturn(3);

		$this->assertTrue($this->controller($members)->index()->getData()['membership_active']);
	}

	public function testAusgeschalteterSchalterVerbirgtDenReiterAuchMitMitgliedern(): void {
		$this->store['membership_enabled'] = '0';
		$members = $this->createMock(MemberMapper::class);
		$members->method('count')->willReturn(3);

		$data = $this->controller($members)->index()->getData();
		$this->assertFalse($data['membership_active']);
		$this->assertFalse($data['membership_enabled']);
	}

	public function testReiterBeitraegeErscheintMitSchalterAuchOhneMitglieder(): void {
		$this->store['membership_enabled'] = '1';
		$members = $this->createMock(MemberMapper::class);
		$members->method('count')->willReturn(0);

		$this->assertTrue($this->controller($members)->index()->getData()['membership_active']);
	}

	public function testAblaufVorwarnungUndMahnabstandWerdenGeschriebenUndZurueckgegeben(): void {
		$response = $this->update(['expiry_warning_days' => '90', 'dunning_interval_days' => 21]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(90, $response->getData()['expiry_warning_days']);
		$this->assertSame(21, $response->getData()['dunning_interval_days']);
	}

	/** @dataProvider ungueltigeTage */
	public function testUnplausibleAblaufVorwarnungWirdAbgelehnt(mixed $value): void {
		$response = $this->update(['expiry_warning_days' => $value]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayNotHasKey('expiry_warning_days', $this->store);
	}

	/** @dataProvider ungueltigeTage */
	public function testUnplausiblerMahnabstandWirdAbgelehnt(mixed $value): void {
		$response = $this->update(['dunning_interval_days' => $value]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertArrayNotHasKey('dunning_interval_days', $this->store);
	}

	/** @return array<string, array{mixed}> */
	public static function ungueltigeTage(): array {
		return ['null' => [0], 'negativ' => [-1], 'ueber einem Jahr' => [366], 'leer' => [''], 'Text' => ['bald']];
	}

	public function testNachweisOrdnerMitElternverweisWirdMitVerstaendlicherMeldungAbgelehnt(): void {
		$response = $this->update(['mandate_document_folder' => '../fremd']);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Nachweis-Ordner', $response->getData()['message']);
		$this->assertStringContainsString('nicht erlaubt', $response->getData()['message']);
		$this->assertArrayNotHasKey('mandate_document_folder', $this->store);
	}

	public function testLeererNachweisOrdnerSetztDenStandardordner(): void {
		$this->update(['mandate_document_folder' => '']);

		$this->assertSame('SEPA-Mandate', $this->store['mandate_document_folder']);
	}

	public function testSelfServiceUmstellungAufUeberweisungIstStandardmaessigAusUndWirdGeschrieben(): void {
		$this->assertFalse($this->controller()->index()->getData()['self_service_transfer_switch_enabled']);

		$data = $this->update(['self_service_transfer_switch_enabled' => '1'])->getData();

		$this->assertTrue($data['self_service_transfer_switch_enabled']);
		$this->assertSame('1', $this->store['self_service_transfer_switch_enabled']);
	}

	public function testNichtGeschickteFelderBleibenUnangetastet(): void {
		$this->store['expiry_warning_days'] = '45';

		$this->update(['dunning_interval_days' => 30]);

		$this->assertSame('45', $this->store['expiry_warning_days']);
	}

	/**
	 * Issue #263: Die Oberfläche rechnet „heute“ in der Vereinszone. Den
	 * Zonennamen liefern Einstellungssatz und – für Konten ohne Leserolle,
	 * die keinen Einstellungssatz bekommen – auch `me`.
	 */
	public function testEinstellungssatzUndMeNennenDieVereinszone(): void {
		ClubClock::configureZone('America/New_York');
		try {
			$data = $this->controller()->index()->getData();
			$this->assertSame('America/New_York', $data['club_time_zone']);
			$this->assertSame('America/New_York', $this->frontendState?->me()['clubTimeZone']);
		} finally {
			ClubClock::configureZone('');
		}
	}
}
