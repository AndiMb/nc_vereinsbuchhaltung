<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit\Support;

use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupConfigStore;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupContainer;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupExportService;
use OCA\Vereinsbuchhaltung\Service\FieldCipher;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\ITempManager;

/**
 * Gemeinsames Gerüst der Sicherungs-Tests: eine „Instanz“ aus Tabellen,
 * Einstellungen, Dateien und eigenem Instanzschlüssel, dazu die echten
 * Dienste darüber. Zwei Instanzen mit verschiedenem `secret` stellen einen
 * Umzug nach.
 */
trait BackupTestKit {

	public const PASSWORD = 'Sehr-Geheim-2026';
	public const IBAN = 'DE12500105170648489890';

	/** @var list<string> */
	private array $tempFiles = [];

	protected function tearDown(): void {
		foreach ($this->tempFiles as $file) {
			if (is_file($file)) {
				@unlink($file);
			}
		}
		$this->tempFiles = [];
	}

	protected function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $params === [] ? $text : vsprintf($text, $params));
		return $l10n;
	}

	protected function cipher(string $secret): FieldCipher {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default = ''): string => $key === 'secret' ? $secret : $default);
		return new FieldCipher($config);
	}

	protected function tempManager(): ITempManager {
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(function (string $postFix = ''): string {
			$path = tempnam(sys_get_temp_dir(), 'vbhtest') . $postFix;
			$this->tempFiles[] = $path;
			return $path;
		});
		return $temp;
	}

	protected function appManager(string $version): IAppManager {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn($version);
		return $apps;
	}

	protected function tempFile(?string $content = null): string {
		$path = tempnam(sys_get_temp_dir(), 'vbhtest');
		$this->tempFiles[] = $path;
		if ($content !== null) {
			file_put_contents($path, $content);
		}
		return $path;
	}

	/**
	 * Ein kleiner, in sich stimmiger Bestand. Verschlüsselte Spalten tragen
	 * das Chiffrat der übergebenen Instanz.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	protected function sampleRows(FieldCipher $cipher): array {
		return [
			'vbh_periods' => [['id' => 3, 'user_id' => '__verein__', 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'locked' => 0]],
			'vbh_accounts' => [
				['id' => 11, 'user_id' => '__verein__', 'number' => '1200', 'name' => 'Bank', 'is_bank' => 1, 'parent_id' => null],
				['id' => 12, 'user_id' => '__verein__', 'number' => '4000', 'name' => 'Beiträge „Ä“', 'is_bank' => 0, 'parent_id' => null],
			],
			'vbh_journal' => [['id' => 40, 'user_id' => '__verein__', 'booking_date' => '2026-03-01', 'description' => "Beitrag\nzweizeilig / mit Schrägstrich"]],
			'vbh_journal_line' => [
				['id' => 80, 'journal_id' => 40, 'account_id' => 11, 'debit_cents' => 5000, 'credit_cents' => 0],
				['id' => 81, 'journal_id' => 40, 'account_id' => 12, 'debit_cents' => 0, 'credit_cents' => 5000],
			],
			'vbh_attachments' => [
				['id' => 5, 'journal_id' => 40, 'file_name' => 'beleg.pdf', 'file_id' => 9001, 'file_owner' => 'alice'],
				['id' => 6, 'journal_id' => 40, 'file_name' => 'weg.pdf', 'file_id' => 9002, 'file_owner' => 'alice'],
			],
			'vbh_members' => [['id' => 7, 'first_name' => 'Erika', 'last_name' => 'Muster', 'nc_user_id' => 'erika']],
			'vbh_mandates' => [
				['id' => 21, 'member_id' => 7, 'mandate_reference' => 'M-21', 'iban' => $cipher->encrypt(self::IBAN), 'document_file_id' => 7001],
				['id' => 22, 'member_id' => 7, 'mandate_reference' => 'M-22', 'iban' => $cipher->encrypt('DE02120300000000202051'), 'document_file_id' => 7002],
				['id' => 23, 'member_id' => 7, 'mandate_reference' => 'M-23', 'iban' => null, 'document_file_id' => null],
			],
			'vbh_rules' => [['id' => 2, 'match_field' => 'iban', 'match_value' => $cipher->encrypt(self::IBAN)]],
			'vbh_invoice_documents' => [['id' => 9, 'invoice_id' => 1, 'kind' => 'pdf', 'file_id' => 9100]],
			'vbh_reimbursement_files' => [['id' => 4, 'reimbursement_id' => 1, 'file_name' => 'quittung.jpg', 'file_id' => 9200, 'file_owner' => 'alice']],
			'vbh_permissions' => [
				['id' => 1, 'principal_type' => 'user', 'principal_id' => 'alice', 'role' => 'verwalter'],
				['id' => 2, 'principal_type' => 'user', 'principal_id' => 'gone', 'role' => 'buchhalter'],
				['id' => 3, 'principal_type' => 'group', 'principal_id' => 'vorstand', 'role' => 'revisor'],
				['id' => 4, 'principal_type' => 'group', 'principal_id' => 'aufgeloest', 'role' => 'revisor'],
			],
			'vbh_audit_log' => [['id' => 1, 'user_id' => 'alice', 'action' => 'Konto angelegt', 'ts' => '2026-03-01 10:00:00']],
			'vbh_mandate_activation_tokens' => [['id' => 1, 'mandate_id' => 21, 'token_hash' => 'abc']],
		];
	}

	/** @return array<string, array<int, ?string>> */
	protected function sampleFiles(): array {
		return [
			'attachments' => [5 => '%PDF beleg', 6 => null],
			'reimbursements' => [4 => 'JPEG quittung'],
			'invoices' => [9 => '%PDF rechnung'],
			'mandates' => [21 => '%PDF mandat'],
		];
	}

	/** @return array<string, string> */
	protected function sampleConfig(): array {
		return [
			'club_name' => 'SC Muster e.V.',
			'fiscal_period_rule' => '{"preset":"calendar"}',
			'brand_logo_mime' => 'image/png',
			'storage_user' => 'alice',
			'storage_mode' => 'watch',
			'statement_watch_path' => 'Wachordner',
			'data_revision' => '1.2.3',
			'installed_version' => '0.53.0',
			'enabled' => 'yes',
		];
	}

	/**
	 * @return array{tables: InMemoryBackupTables, config: IAppConfig, files: FakeBackupFiles, cipher: FieldCipher, export: BackupExportService, container: BackupContainer, runner: TransactionRunner, configStore: BackupConfigStore}
	 */
	protected function instance(string $secret, bool $filled, string $version = '0.53.0'): array {
		$cipher = $this->cipher($secret);
		$tables = InMemoryBackupTables::create($this, $filled ? $this->sampleRows($cipher) : []);
		$config = AppConfigStub::create($this, $filled ? $this->sampleConfig() : ['installed_version' => $version, 'enabled' => 'yes', 'storage_user' => 'bob']);
		$files = new FakeBackupFiles($filled ? $this->sampleFiles() : [], $filled ? 'PNG logo' : null);
		$container = new BackupContainer($this->l10n());
		$runner = new TransactionRunner($tables->connection);
		$configStore = new BackupConfigStore($config);
		$export = new BackupExportService($container, $tables, $configStore, $files, $cipher, $runner, $this->appManager($version), $this->tempManager(), $this->l10n());
		return compact('tables', 'config', 'files', 'cipher', 'export', 'container', 'runner', 'configStore');
	}

	/** Entschlüsselt eine Sicherung und öffnet das ZIP darin. */
	protected function openBackup(string $path, ?BackupContainer $container = null): \ZipArchive {
		$plain = $this->tempFile();
		($container ?? new BackupContainer($this->l10n()))->decrypt($path, $plain, self::PASSWORD);
		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($plain, \ZipArchive::RDONLY));
		return $zip;
	}

	/**
	 * Baut aus einer Sicherung eine veränderte: `$change` bekommt das
	 * geöffnete ZIP zum Bearbeiten. Liefert den Pfad der neuen Sicherung.
	 *
	 * @param callable(\ZipArchive): void $change
	 */
	protected function tamper(string $path, callable $change): string {
		$container = new BackupContainer($this->l10n());
		$plain = $this->tempFile();
		$container->decrypt($path, $plain, self::PASSWORD);
		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($plain));
		$change($zip);
		$zip->close();
		$target = $this->tempFile();
		$container->encrypt($plain, $target, self::PASSWORD);
		return $target;
	}

	/** @return list<array<string, mixed>> */
	protected function jsonl(\ZipArchive $zip, string $table): array {
		$raw = $zip->getFromName('tables/' . $table . '.jsonl');
		$this->assertIsString($raw, $table . ' fehlt in der Sicherung');
		return array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $raw === '' ? [] : explode("\n", rtrim($raw, "\n")));
	}
}
