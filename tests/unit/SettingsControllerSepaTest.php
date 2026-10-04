<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Controller\SettingsController;
use OCA\Vereinsbuchhaltung\Db\AccountMapper;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\MembershipFeeMapper;
use OCA\Vereinsbuchhaltung\Db\SepaMandateMapper;
use OCA\Vereinsbuchhaltung\Service\AttachmentStorageService;
use OCA\Vereinsbuchhaltung\Service\AttachmentWatchFolderService;
use OCA\Vereinsbuchhaltung\Service\ContributionYearService;
use OCA\Vereinsbuchhaltung\Service\DemoDataService;
use OCA\Vereinsbuchhaltung\Service\DunningSettings;
use OCA\Vereinsbuchhaltung\Service\FolderPathValidator;
use OCA\Vereinsbuchhaltung\Service\MandateDocumentService;
use OCA\Vereinsbuchhaltung\Service\MandateExpirySettings;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\SepaDebtorAccountService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
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

	protected function setUp(): void {
		$this->store = [];
		$this->request = $this->createMock(IRequest::class);
	}

	private function controller(): SettingsController {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default);
		$config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
			$this->store[$key] = $value;
		});
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));
		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('getRole')->willReturn(PermissionService::ROLE_ADMIN);

		$attachmentStorage = $this->createMock(AttachmentStorageService::class);
		$attachmentStorage->method('mode')->willReturn(AttachmentStorageService::MODE_APPDATA);
		$mandateDocuments = $this->createMock(MandateDocumentService::class);
		$mandateDocuments->method('folderPath')->willReturnCallback(fn () => $this->store['mandate_document_folder'] ?? 'SEPA-Mandate');
		$mandateDocuments->method('showMissingDocumentWarning')->willReturn(true);
		$contributionYear = $this->createMock(ContributionYearService::class);
		$contributionYear->method('getStartMonth')->willReturn(1);

		return new SettingsController(
			$this->request,
			$config,
			$permissions,
			$this->createMock(DemoDataService::class),
			$this->createMock(AccountMapper::class),
			$this->createMock(SepaMandateMapper::class),
			$this->createMock(MembershipFeeMapper::class),
			$this->createMock(MemberMapper::class),
			$this->createMock(IUserManager::class),
			$this->createMock(SepaDebtorAccountService::class),
			$attachmentStorage,
			$this->createMock(AttachmentWatchFolderService::class),
			$mandateDocuments,
			$contributionYear,
			new FolderPathValidator($l10n),
			new DunningSettings($config),
			new MandateExpirySettings($config),
			$l10n,
		);
	}

	/** @param array<string, mixed> $params */
	private function update(array $params): DataResponse {
		$this->request->method('getParams')->willReturn($params);
		return $this->controller()->update();
	}

	public function testEinstellungssatzLiefertStandardwerteFuerAblaufVorwarnungUndMahnabstand(): void {
		$data = $this->controller()->index()->getData();

		$this->assertSame(180, $data['expiry_warning_days']);
		$this->assertSame(14, $data['dunning_interval_days']);
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

	public function testNichtGeschickteFelderBleibenUnangetastet(): void {
		$this->store['expiry_warning_days'] = '45';

		$this->update(['dunning_interval_days' => 30]);

		$this->assertSame('45', $this->store['expiry_warning_days']);
	}
}
