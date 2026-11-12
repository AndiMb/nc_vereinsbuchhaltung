<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\EncryptingMapper;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupSchema;
use PHPUnit\Framework\TestCase;

/**
 * Das Verzeichnis der Sicherung (Issue #111) muss vollständig sein: eine
 * Tabelle, die hier fehlt, fehlte stillschweigend in jeder Sicherung. Diese
 * Tests lassen die CI rot werden, sobald eine Migration eine Tabelle anlegt
 * oder ein Mapper eine Spalte verschlüsselt, ohne dass das Verzeichnis es weiß.
 */
class BackupSchemaTest extends TestCase {
	private const LIB = __DIR__ . '/../../lib';

	/** Von Migrationen ohne Bedingung wieder entfernt – es gibt sie auf keiner Instanz mehr. */
	private const DROPPED = ['vbh_imports', 'vbh_year_close'];

	/** @return list<string> */
	private function migrationTables(): array {
		$tables = [];
		foreach (glob(self::LIB . '/Migration/*.php') as $file) {
			preg_match_all("/createTable\\(\\s*'(vbh_[a-z0-9_]+)'/", (string)file_get_contents($file), $m);
			$tables = array_merge($tables, $m[1]);
		}
		$tables = array_values(array_unique(array_diff($tables, self::DROPPED)));
		sort($tables);
		return $tables;
	}

	public function testJedeTabelleDerMigrationenIstEingeordnet(): void {
		$migration = $this->migrationTables();
		$this->assertGreaterThan(40, count($migration), 'Die Suche in den Migrationen findet keine Tabellen mehr.');

		$saved = array_keys(BackupSchema::tables());
		$excluded = BackupSchema::excludedTables();
		$this->assertSame([], array_values(array_intersect($saved, $excluded)), 'Eine Tabelle ist zugleich gesichert und ausgenommen.');

		$known = array_merge($saved, $excluded);
		sort($known);
		$this->assertSame([], array_values(array_diff($migration, $known)), 'Tabellen ohne Eintrag in BackupSchema – sie fehlten in jeder Sicherung.');
		$this->assertSame([], array_values(array_diff($known, $migration)), 'BackupSchema nennt Tabellen, die keine Migration anlegt.');
	}

	public function testKeineTabelleDoppelt(): void {
		$excluded = BackupSchema::excludedTables();
		$this->assertSame($excluded, array_values(array_unique($excluded)));
	}

	/** Jede gesicherte Tabelle hat den einspaltigen Schlüssel `id` – darauf bauen Sortierung und Sequenz. */
	public function testGesicherteTabellenHabenDenSchluesselId(): void {
		$composite = [];
		foreach (glob(self::LIB . '/Migration/*.php') as $file) {
			$source = (string)file_get_contents($file);
			if (preg_match_all("/createTable\\(\\s*'(vbh_[a-z0-9_]+)'\\)(.*?)setPrimaryKey\\(\\[([^\\]]*)\\]\\)/s", $source, $m, PREG_SET_ORDER)) {
				foreach ($m as $hit) {
					if (trim($hit[3]) !== "'id'") {
						$composite[] = $hit[1];
					}
				}
			}
		}
		$this->assertSame([], array_values(array_intersect($composite, array_keys(BackupSchema::tables()))));
	}

	public function testVerschluesselteSpaltenEntsprechenDenMappern(): void {
		$expected = [];
		foreach (glob(self::LIB . '/Db/*Mapper.php') as $file) {
			$class = 'OCA\\Vereinsbuchhaltung\\Db\\' . basename($file, '.php');
			if (!is_subclass_of($class, EncryptingMapper::class)) {
				continue;
			}
			$this->assertSame(1, preg_match("/'(vbh_[a-z0-9_]+)'/", (string)file_get_contents($file), $m), $class . ': Tabellenname nicht gefunden');
			$reflection = new \ReflectionClass($class);
			$method = $reflection->getMethod('encryptedProperties');
			$method->setAccessible(true);
			$columns = array_map(
				static fn (string $property): string => strtolower((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $property)),
				$method->invoke($reflection->newInstanceWithoutConstructor()),
			);
			sort($columns);
			$expected[$m[1]] = $columns;
		}
		$this->assertNotSame([], $expected, 'Kein verschlüsselnder Mapper gefunden.');

		$actual = [];
		foreach (BackupSchema::tables() as $table => $def) {
			if ($def['encrypted'] !== []) {
				$columns = $def['encrypted'];
				sort($columns);
				$actual[$table] = $columns;
			}
		}
		ksort($expected);
		ksort($actual);
		$this->assertSame($expected, $actual);
	}

	public function testInhaltstabellenUndAusnahmenSindGesichert(): void {
		$saved = array_keys(BackupSchema::tables());
		$this->assertSame([], array_values(array_diff(BackupSchema::substantiveTables(), $saved)));
		$this->assertSame([], array_values(array_diff(BackupSchema::keptOnWipe(), $saved)));
		$this->assertSame(['vbh_audit_log', 'vbh_permissions'], BackupSchema::keptOnWipe());
		$this->assertCount(14, BackupSchema::substantiveTables());
	}

	public function testInstanzgebundeneSpalten(): void {
		$tables = BackupSchema::tables();
		$this->assertSame(['file_id' => null, 'file_owner' => null], $tables['vbh_attachments']['replace']);
		$this->assertSame(['file_id' => null, 'file_owner' => null], $tables['vbh_reimbursement_files']['replace']);
		$this->assertSame(['file_id' => 0], $tables['vbh_invoice_documents']['replace']);
		$this->assertSame(['document_file_id' => 'mandates'], $tables['vbh_mandates']['replaceIfFile']);
		$this->assertSame(['attachments', 'reimbursements', 'invoices', 'mandates'], BackupSchema::FILE_KINDS);
	}

	public function testEinstellungenWerdenGesichertAusserInstanzgebundenen(): void {
		foreach (['club_name', 'fiscal_period_rule', 'sepa_debtor_account_id', 'invoice_account_id', 'invoice_vat_account_19', 'demo_seeded', 'report_templates_seeded', 'brand_logo_mime'] as $key) {
			$this->assertTrue(BackupSchema::isBackedUpConfigKey($key), $key);
		}
		foreach (['storage_user', 'storage_path', 'storage_mode', 'statement_watch_user', 'statement_watch_path', 'mandate_document_folder', 'xml_folder_enabled', 'xml_folder_path', 'invoice_folder', 'data_revision', 'installed_version', 'enabled', 'types'] as $key) {
			$this->assertFalse(BackupSchema::isBackedUpConfigKey($key), $key);
		}
	}

	/**
	 * Neue Einstellungen kommen von selbst in die Sicherung. Gefährlich wäre
	 * nur eine neue instanzgebundene (Benutzer, Ordner, Pfad): sie zeigte im
	 * Ziel ins Leere. Deshalb muss jeder Schlüssel, der so heißt, ausdrücklich
	 * ausgenommen sein.
	 */
	public function testSchluesselMitBenutzerOderPfadSindAusgenommen(): void {
		$suspicious = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::LIB, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'Migration')) {
				continue;
			}
			preg_match_all("/const\\s+\\w+\\s*=\\s*'([a-z0-9_]*(?:_user|_path|_folder)(?:_enabled)?)'\\s*;/", (string)file_get_contents($file->getPathname()), $m);
			$suspicious = array_merge($suspicious, $m[1]);
		}
		$suspicious = array_values(array_unique($suspicious));
		$this->assertContains('storage_user', $suspicious, 'Die Suche findet die bekannten Schlüssel nicht mehr.');
		foreach ($suspicious as $key) {
			$this->assertFalse(BackupSchema::isBackedUpConfigKey($key), $key . ' sieht instanzgebunden aus, ist aber nicht ausgenommen.');
		}
	}
}
