<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Backup\BackupSchema;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\AppConfigStub;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\BackupTestKit;
use PHPUnit\Framework\TestCase;

/**
 * „Bestand vollständig leeren“ (Issue #111): die Vorstufe zum Einspielen
 * einer Sicherung. Anders als „Alle Daten löschen“ gehen auch Mitglieder,
 * Mandate und die Einstellungen.
 */
class BackupWipeServiceTest extends TestCase {
	use BackupTestKit;

	public function testLeerenLaesstProtokollUndRollenStehen(): void {
		$inst = $this->instance('quelle', true);
		$audit = $inst['tables']->all('vbh_audit_log');
		$permissions = $inst['tables']->all('vbh_permissions');

		$inst['wipe']->wipe();

		$this->assertSame($audit, $inst['tables']->all('vbh_audit_log'));
		$this->assertSame($permissions, $inst['tables']->all('vbh_permissions'));
		foreach (array_merge(array_keys(BackupSchema::tables()), BackupSchema::excludedTables()) as $table) {
			if (!in_array($table, BackupSchema::keptOnWipe(), true)) {
				$this->assertSame([], $inst['tables']->all($table), $table);
			}
		}
		$this->assertTrue($inst['wipe']->isEmpty());
	}

	public function testLeerenEntferntEinstellungenAusserInstanzgebundenen(): void {
		$inst = $this->instance('quelle', true);
		$before = AppConfigStub::values($inst['config'])['data_revision'];

		$inst['wipe']->wipe();

		$values = AppConfigStub::values($inst['config']);
		$this->assertArrayNotHasKey('club_name', $values);
		$this->assertArrayNotHasKey('fiscal_period_rule', $values);
		$this->assertArrayNotHasKey('brand_logo_mime', $values);
		$this->assertSame('alice', $values['storage_user']);
		$this->assertSame('watch', $values['storage_mode']);
		$this->assertSame('Wachordner', $values['statement_watch_path']);
		$this->assertSame('0.53.0', $values['installed_version']);
		$this->assertSame('yes', $values['enabled']);
		$this->assertNotSame($before, $values['data_revision'], 'Offene Sitzungen müssen den neuen Stand bemerken.');
	}

	/** Welche Dateien zu löschen sind, steht nur in den Tabellen – also vorher merken, nach dem Commit löschen. */
	public function testDateienWerdenVorherGemerktUndDanachGeloescht(): void {
		$inst = $this->instance('quelle', true);
		$inst['sink']->store('attachments', 5, 'x');

		$inst['wipe']->wipe();

		$this->assertSame(['prepareWipe', 'wipeFiles'], $inst['sink']->calls);
		$this->assertSame([], $inst['sink']->stored);
	}

	public function testFehlendeAlttabelleStoertNicht(): void {
		$inst = $this->instance('quelle', true);
		unset($inst['tables']->data['vbh_sepa_batches'], $inst['tables']->data['vbh_membership_fees']);

		$inst['wipe']->wipe();

		$this->assertArrayNotHasKey('vbh_sepa_batches', $inst['tables']->data);
		$this->assertTrue($inst['wipe']->isEmpty());
	}

	/** Eine frisch geöffnete App hat schon ein Geschäftsjahr, Vorlagen und Rollen – sie ist trotzdem leer. */
	public function testIstLeerTrotzAutomatischAngelegtem(): void {
		$inst = $this->instance('ziel', false);
		$inst['tables']->data['vbh_periods'][1] = ['id' => 1, 'name' => '2026'];
		$inst['tables']->data['vbh_report_templates'][1] = ['id' => 1, 'name' => 'Standard'];
		$inst['tables']->data['vbh_number_ranges'][1] = ['id' => 1];
		$inst['tables']->data['vbh_permissions'][1] = ['id' => 1, 'principal_type' => 'user', 'principal_id' => 'bob', 'role' => 'verwalter'];
		$inst['tables']->data['vbh_audit_log'][1] = ['id' => 1, 'action' => 'x'];

		$this->assertTrue($inst['wipe']->isEmpty());
		$this->assertSame(0, array_sum($inst['wipe']->counts()));
	}

	public function testNichtLeerMitEinemEintrag(): void {
		foreach (BackupSchema::substantiveTables() as $table) {
			$inst = $this->instance('ziel', false);
			$inst['tables']->data[$table][1] = ['id' => 1];
			$this->assertFalse($inst['wipe']->isEmpty(), $table);
			$this->assertSame(1, $inst['wipe']->counts()[$table]);
		}
	}

	public function testZaehltDieInhaltstabellen(): void {
		$inst = $this->instance('quelle', true);
		$counts = $inst['wipe']->counts();

		$this->assertSame(BackupSchema::substantiveTables(), array_keys($counts));
		$this->assertSame(2, $counts['vbh_accounts']);
		$this->assertSame(3, $counts['vbh_mandates']);
		$this->assertSame(0, $counts['vbh_invoices']);
	}
}
