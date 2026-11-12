<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\ReimbursementEvent;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupSchema;
use OCA\Vereinsbuchhaltung\Service\ReimbursementEventLog;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\AppConfigStub;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\BackupTestKit;
use PHPUnit\Framework\TestCase;

/**
 * Einspielen einer Sicherung (Issue #111). „Quelle“ und „Ziel“ sind zwei
 * Instanzen mit verschiedenem Instanzschlüssel – wie bei einem Umzug.
 */
class BackupImportServiceTest extends TestCase {
	use BackupTestKit;

	/** Zwei Ereignisse eines Auslagenantrags mit gültiger Prüfkette. */
	private function eventRows(): array {
		$rows = [];
		$prev = null;
		foreach ([[1, 'eingereicht'], [2, 'freigegeben']] as [$seq, $action]) {
			$row = ['id' => 30 + $seq, 'reimbursement_id' => 1, 'seq' => $seq, 'action' => $action, 'actor_uid' => 'alice', 'actor_role' => 'verwalter', 'created_at' => '2026-03-0' . $seq . ' 10:00:00', 'comment' => null, 'data' => '{}', 'prev_hash' => $prev];
			$row['hash'] = ReimbursementEventLog::hashOf(ReimbursementEvent::fromRow($row));
			$prev = $row['hash'];
			$rows[$row['id']] = $row;
		}
		return $rows;
	}

	/**
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string} Quelle, Ziel, Pfad der Sicherung
	 */
	private function exported(bool $withFiles = true, string $targetVersion = '0.53.0'): array {
		$source = $this->instance('quelle', true);
		$source['tables']->data['vbh_reimbursement_events'] = $this->eventRows();
		$target = $this->instance('ziel', false, $targetVersion);
		return [$source, $target, $source['export']->export(self::PASSWORD, $withFiles)['path']];
	}

	private function assertImportFails(array $target, string $path, string $message, string $password = self::PASSWORD): void {
		$before = $target['tables']->data;
		$config = AppConfigStub::values($target['config']);
		try {
			$target['import']->import($path, $password, 'importer');
			$this->fail('Das Einspielen hätte scheitern müssen.');
		} catch (UserFacingException $e) {
			$this->assertSame($message, $e->getMessage());
		}
		$this->assertSame($before, $target['tables']->data, 'Das Ziel muss unverändert bleiben.');
		$this->assertSame($config, AppConfigStub::values($target['config']));
		$this->assertSame([], $target['sink']->stored);
	}

	public function testHinUndZurueckErgibtDieselbenZeilen(): void {
		[$source, $target, $path] = $this->exported();

		$result = $target['import']->import($path, self::PASSWORD, 'alice');

		$changed = ['vbh_mandates', 'vbh_rules', 'vbh_attachments', 'vbh_invoice_documents', 'vbh_reimbursement_files'];
		$rows = 0;
		foreach (array_keys(BackupSchema::tables()) as $table) {
			$rows += count($source['tables']->all($table));
			if (!in_array($table, $changed, true)) {
				$this->assertSame($source['tables']->all($table), $target['tables']->all($table), $table);
			}
			$this->assertSame(array_column($source['tables']->all($table), 'id'), array_column($target['tables']->all($table), 'id'), $table);
		}
		$this->assertSame($rows, $result['rows']);
		$this->assertSame(count(BackupSchema::tables()), $result['tables']);
	}

	/** Review Focus 1: Die Zielinstanz hat einen anderen Schlüssel – IBANs müssen dort lesbar sein. */
	public function testIbanMitAnderemSchluesselLesbar(): void {
		[$source, $target, $path] = $this->exported();

		$target['import']->import($path, self::PASSWORD, 'alice');

		$stored = $target['tables']->all('vbh_mandates')[0]['iban'];
		$this->assertStringStartsWith('vbh1:', $stored);
		$this->assertNotSame($source['tables']->all('vbh_mandates')[0]['iban'], $stored);
		$this->assertSame(self::IBAN, $target['cipher']->decrypt($stored));
		$this->assertNull($target['tables']->all('vbh_mandates')[2]['iban']);
		$this->assertSame(self::IBAN, $target['cipher']->decrypt($target['tables']->all('vbh_rules')[0]['match_value']));
	}

	public function testInstanzgebundeneSpaltenWerdenErsetzt(): void {
		[, $target, $path] = $this->exported();

		$target['import']->import($path, self::PASSWORD, 'alice');

		foreach ($target['tables']->all('vbh_attachments') as $row) {
			$this->assertNull($row['file_id']);
			$this->assertNull($row['file_owner']);
			$this->assertSame(40, $row['journal_id']);
		}
		$this->assertNull($target['tables']->all('vbh_reimbursement_files')[0]['file_id']);
		$this->assertNull($target['tables']->all('vbh_reimbursement_files')[0]['file_owner']);
		$this->assertSame(0, $target['tables']->all('vbh_invoice_documents')[0]['file_id']);

		$mandates = $target['tables']->all('vbh_mandates');
		$this->assertSame(0, $mandates[0]['document_file_id'], 'Nachweis liegt in der Sicherung → app-interne Ablage.');
		$this->assertNull($mandates[1]['document_file_id'], 'Nachweis fehlt in der Sicherung → kein Verweis ins Leere.');
		$this->assertNull($mandates[2]['document_file_id']);
	}

	public function testDateienLandenInDerAblage(): void {
		[, $target, $path] = $this->exported();
		$target['sink']->store('attachments', 77, 'Altlast aus einem früheren Bestand');

		$result = $target['import']->import($path, self::PASSWORD, 'alice');

		$this->assertSame([
			'attachments' => [5 => '%PDF beleg'],
			'reimbursements' => [4 => 'JPEG quittung'],
			'invoices' => [9 => '%PDF rechnung'],
			'mandates' => [21 => '%PDF mandat'],
		], $target['sink']->stored);
		$this->assertSame('PNG logo', $target['sink']->logo);
		$this->assertSame(4, $result['files']);
	}

	/** Review Focus 2: Ein Beleg fehlte schon in der Quelle – das Einspielen läuft durch und nennt ihn. */
	public function testFehlendeDateiWirdGenannt(): void {
		[, $target, $path] = $this->exported();

		$result = $target['import']->import($path, self::PASSWORD, 'alice');

		$this->assertSame([['kind' => 'attachments', 'id' => 6]], $result['missingFiles']);
		$this->assertCount(2, $target['tables']->all('vbh_attachments'), 'Der Datensatz bleibt, nur die Datei fehlt.');
	}

	public function testSicherungOhneBelege(): void {
		[, $target, $path] = $this->exported(false);

		$result = $target['import']->import($path, self::PASSWORD, 'alice');

		$this->assertSame([], $target['sink']->stored);
		$this->assertSame('PNG logo', $target['sink']->logo);
		$this->assertSame(0, $result['files']);
		$this->assertNull($target['tables']->all('vbh_mandates')[0]['document_file_id']);
		$this->assertCount(1, $target['tables']->all('vbh_journal'));
	}

	public function testEinstellungenWerdenUebernommenAusserInstanzgebundenen(): void {
		[, $target, $path] = $this->exported();
		$target['config']->setValueString('vereinsbuchhaltung', 'club_city', 'Altstadt');

		$target['import']->import($path, self::PASSWORD, 'alice');

		$values = AppConfigStub::values($target['config']);
		$this->assertSame('SC Muster e.V.', $values['club_name']);
		$this->assertSame('{"preset":"calendar"}', $values['fiscal_period_rule']);
		$this->assertSame('bob', $values['storage_user'], 'Die Ablage des Ziels bleibt, wie sie ist.');
		$this->assertArrayNotHasKey('storage_mode', $values);
		$this->assertArrayNotHasKey('statement_watch_path', $values);
		$this->assertArrayNotHasKey('club_city', $values, 'Einstellungen des Ziels, die die Sicherung nicht kennt, gehen.');
		$this->assertNotSame('1.2.3', $values['data_revision'] ?? null);
		$this->assertNotSame('', $values['data_revision'] ?? '');
	}

	public function testManipulierteEinstellungenWerdenUebergangen(): void {
		[, $target, $path] = $this->exported();
		$path = $this->tamper($path, static function (\ZipArchive $zip): void {
			$zip->addFromString('config.json', json_encode(['club_name' => 'X', 'storage_user' => 'angreifer', 'enabled' => 'no', 'installed_version' => '9.9.9']));
		});

		$target['import']->import($path, self::PASSWORD, 'alice');

		$values = AppConfigStub::values($target['config']);
		$this->assertSame('X', $values['club_name']);
		$this->assertSame('bob', $values['storage_user']);
		$this->assertSame('yes', $values['enabled']);
		$this->assertSame('0.53.0', $values['installed_version']);
	}

	public function testImporteurWirdVerwalter(): void {
		[, $target, $path] = $this->exported();
		// Im Ziel war der Importeur Verwalter; die Sicherung kennt ihn gar nicht.
		$target['tables']->data['vbh_permissions'][1] = ['id' => 1, 'principal_type' => 'user', 'principal_id' => 'importer', 'role' => 'verwalter'];

		$target['import']->import($path, self::PASSWORD, 'importer');

		$permissions = $target['tables']->all('vbh_permissions');
		$own = array_values(array_filter($permissions, static fn (array $r): bool => $r['principal_type'] === 'user' && $r['principal_id'] === 'importer'));
		$this->assertCount(1, $own);
		$this->assertSame('verwalter', $own[0]['role']);
		$this->assertSame(5, $own[0]['id'], 'Neue Zeile hinter den eingespielten.');
		$this->assertCount(5, $permissions);
	}

	public function testImporteurMitGeringererRolleInDerSicherungWirdVerwalter(): void {
		$source = $this->instance('quelle', true);
		$source['tables']->data['vbh_permissions'][2]['principal_id'] = 'importer';
		$target = $this->instance('ziel', false);

		$target['import']->import($source['export']->export(self::PASSWORD, false)['path'], self::PASSWORD, 'importer');

		$own = array_values(array_filter($target['tables']->all('vbh_permissions'), static fn (array $r): bool => $r['principal_id'] === 'importer'));
		$this->assertCount(1, $own);
		$this->assertSame('verwalter', $own[0]['role']);
	}

	public function testUnbekannteBenutzerUndGruppenWerdenGenannt(): void {
		[$source, $target] = $this->exported();
		$source['tables']->data['vbh_members'][8] = ['id' => 8, 'first_name' => 'Max', 'last_name' => 'Weg', 'nc_user_id' => 'max'];
		$path = $source['export']->export(self::PASSWORD, false)['path'];

		$result = $target['import']->import($path, self::PASSWORD, 'alice');

		$this->assertSame(['gone', 'max'], $result['unknownUsers']);
		$this->assertSame(['aufgeloest'], $result['unknownGroups']);
	}

	public function testAusgenommeneTabellenSindDanachLeer(): void {
		[, $target, $path] = $this->exported();
		$target['tables']->data['vbh_mandate_activation_tokens'][9] = ['id' => 9, 'mandate_id' => 21, 'token_hash' => 'alt'];
		$target['tables']->data['vbh_receipt_index'][1] = ['id' => 1];
		$target['tables']->data['vbh_periods'][1] = ['id' => 1, 'name' => 'automatisch angelegt'];

		$target['import']->import($path, self::PASSWORD, 'alice');

		$this->assertSame([], $target['tables']->all('vbh_mandate_activation_tokens'));
		$this->assertSame([], $target['tables']->all('vbh_receipt_index'));
		$this->assertSame([3], array_column($target['tables']->all('vbh_periods'), 'id'), 'Automatisch Angelegtes des Ziels weicht dem Bestand der Sicherung.');
	}

	/** Review Focus 4: Nach dem Einfügen mit festen IDs muss der Zähler jeder Tabelle nachgezogen sein. */
	public function testSequenzenWerdenNachgezogen(): void {
		[, $target, $path] = $this->exported();

		$target['import']->import($path, self::PASSWORD, 'alice');

		$this->assertSame(array_keys(BackupSchema::tables()), $target['tables']->synced);
	}

	/** Review Focus 5 */
	public function testAndereVersionWirdAbgelehnt(): void {
		[, $target, $path] = $this->exported(true, '0.54.0');

		$this->assertImportFails($target, $path, 'Diese Sicherung stammt aus Version 0.53.0 der Vereinsbuchhaltung, installiert ist 0.54.0. Bringen Sie beide Instanzen auf denselben Stand.');
	}

	/** Review Focus 5 */
	public function testNichtLeeresZielWirdAbgelehnt(): void {
		[, $target, $path] = $this->exported();
		$target['tables']->data['vbh_members'][1] = ['id' => 1, 'last_name' => 'Schon da'];

		$this->assertImportFails($target, $path, 'Die Buchhaltung ist nicht leer. Eine Sicherung lässt sich nur in einen leeren Bestand einspielen.');
	}

	public function testFalschesPasswort(): void {
		[, $target, $path] = $this->exported();

		$this->assertImportFails($target, $path, 'Das Passwort passt nicht oder die Datei ist beschädigt.', 'Ein-anderes-Passwort');
	}

	/** Review Focus 3 */
	public function testAbgeschnitteneDateiLaesstZielLeer(): void {
		[, $target, $path] = $this->exported();
		$cut = $this->tempFile(substr((string)file_get_contents($path), 0, -200));

		$this->assertImportFails($target, $cut, 'Die Sicherungsdatei ist unvollständig.');
	}

	public function testFremdesZipWirdAbgelehnt(): void {
		[, $target, $path] = $this->exported();
		$noManifest = $this->tamper($path, static function (\ZipArchive $zip): void {
			$zip->deleteName('manifest.json');
		});
		$this->assertImportFails($target, $noManifest, 'Das ist keine Sicherungsdatei der Vereinsbuchhaltung.');

		$otherFormat = $this->tamper($path, static function (\ZipArchive $zip): void {
			$manifest = json_decode((string)$zip->getFromName('manifest.json'), true);
			$manifest['format'] = 2;
			$zip->addFromString('manifest.json', json_encode($manifest));
		});
		$this->assertImportFails($target, $otherFormat, 'Das ist keine Sicherungsdatei der Vereinsbuchhaltung.');
	}

	public function testFalschePruefsummeWirdAbgelehnt(): void {
		[, $target, $path] = $this->exported();
		$path = $this->tamper($path, static function (\ZipArchive $zip): void {
			$raw = (string)$zip->getFromName('tables/vbh_accounts.jsonl');
			$zip->addFromString('tables/vbh_accounts.jsonl', str_replace('Bank', 'Bunk', $raw));
		});

		$this->assertImportFails($target, $path, 'Die Sicherung ist beschädigt (Tabelle vbh_accounts).');
	}

	public function testFehlendeTabelleWirdAbgelehnt(): void {
		[, $target, $path] = $this->exported();
		$path = $this->tamper($path, static function (\ZipArchive $zip): void {
			$zip->deleteName('tables/vbh_journal_line.jsonl');
		});

		$this->assertImportFails($target, $path, 'Die Sicherung ist beschädigt (Tabelle vbh_journal_line).');
	}

	public function testAbweichendeZeilenzahlWirdAbgelehnt(): void {
		[, $target, $path] = $this->exported();
		$path = $this->tamper($path, static function (\ZipArchive $zip): void {
			$manifest = json_decode((string)$zip->getFromName('manifest.json'), true);
			$manifest['tables']['vbh_accounts']['rows'] = 3;
			$zip->addFromString('manifest.json', json_encode($manifest));
		});

		$this->assertImportFails($target, $path, 'Die Sicherung ist beschädigt (Tabelle vbh_accounts).');
	}

	/** Die Datei ist unversehrt, ihr Inhalt aber nicht stimmig: erst beim Einfügen bemerkt, also zurückrollen. */
	public function testSollUngleichHabenRolltZurueck(): void {
		$source = $this->instance('quelle', true);
		$source['tables']->data['vbh_journal_line'][81]['credit_cents'] = 4999;
		$target = $this->instance('ziel', false);
		$target['tables']->data['vbh_periods'][1] = ['id' => 1, 'name' => 'vorher da'];

		$this->assertImportFails($target, $source['export']->export(self::PASSWORD, true)['path'], 'Die Sicherung ist in sich nicht stimmig (Soll und Haben der Buchungen).');
		$this->assertSame([1], array_keys($target['tables']->data['vbh_periods']));
	}

	public function testGebrochenePruefketteRolltZurueck(): void {
		$source = $this->instance('quelle', true);
		$events = $this->eventRows();
		$events[32]['comment'] = 'nachträglich geändert';
		$source['tables']->data['vbh_reimbursement_events'] = $events;
		$target = $this->instance('ziel', false);

		$this->assertImportFails($target, $source['export']->export(self::PASSWORD, true)['path'], 'Die Sicherung ist in sich nicht stimmig (Prüfkette der Auslagenanträge).');
	}

	public function testGueltigePruefketteWirdUebernommen(): void {
		[$source, $target, $path] = $this->exported();

		$target['import']->import($path, self::PASSWORD, 'alice');

		$this->assertSame($source['tables']->all('vbh_reimbursement_events'), $target['tables']->all('vbh_reimbursement_events'));
	}

	public function testUnpassendeSpalteRolltZurueck(): void {
		[, $target, $path] = $this->exported();
		$path = $this->tamper($path, static function (\ZipArchive $zip): void {
			$line = json_encode(['id' => 1, 'x; DROP TABLE y' => 1]) . "\n";
			$zip->addFromString('tables/vbh_costcenters.jsonl', $line);
			$manifest = json_decode((string)$zip->getFromName('manifest.json'), true);
			$manifest['tables']['vbh_costcenters'] = ['rows' => 1, 'sha256' => hash('sha256', $line)];
			$zip->addFromString('manifest.json', json_encode($manifest));
		});

		$this->assertImportFails($target, $path, 'Die Sicherung passt nicht zu dieser App-Version (Tabelle vbh_costcenters, Spalte x; DROP TABLE y).');
	}

	public function testInspectNenntInhaltUndVeraendertNichts(): void {
		[, $target, $path] = $this->exported();
		$before = $target['tables']->data;

		$info = $target['import']->inspect($path, self::PASSWORD);

		$this->assertSame('SC Muster e.V.', $info['clubName']);
		$this->assertSame('0.53.0', $info['appVersion']);
		$this->assertSame('0.53.0', $info['installedVersion']);
		$this->assertTrue($info['compatible']);
		$this->assertTrue($info['targetEmpty']);
		$this->assertTrue($info['withFiles']);
		$this->assertSame(4, $info['files']);
		$this->assertSame(1, $info['missingFiles']);
		$this->assertSame(2, $info['counts']['vbh_accounts']);
		$this->assertSame(3, $info['counts']['vbh_mandates']);
		$this->assertGreaterThan(10, $info['rows']);
		$this->assertNotFalse(strtotime($info['createdAt']));
		$this->assertSame($before, $target['tables']->data);
		$this->assertSame([], $target['sink']->calls);
	}

	public function testInspectMeldetHindernisseOhneFehler(): void {
		[, $target, $path] = $this->exported(true, '0.54.0');
		$target['tables']->data['vbh_accounts'][1] = ['id' => 1];

		$info = $target['import']->inspect($path, self::PASSWORD);

		$this->assertFalse($info['compatible']);
		$this->assertFalse($info['targetEmpty']);
		$this->assertSame('0.54.0', $info['installedVersion']);
	}

	public function testInspectMitFalschemPasswort(): void {
		[, $target, $path] = $this->exported();

		$this->expectException(UserFacingException::class);
		$this->expectExceptionMessage('Das Passwort passt nicht oder die Datei ist beschädigt.');
		$target['import']->inspect($path, 'Ein-anderes-Passwort');
	}
}
