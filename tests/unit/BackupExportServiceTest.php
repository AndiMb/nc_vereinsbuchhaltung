<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\Backup\BackupSchema;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\BackupTestKit;
use PHPUnit\Framework\TestCase;

/**
 * Export der Sicherung (Issue #111): was in der Datei steht – und was nicht.
 */
class BackupExportServiceTest extends TestCase {
	use BackupTestKit;

	public function testManifestNenntVersionZeilenUndPruefsummen(): void {
		$source = $this->instance('quelle', true);
		$result = $source['export']->export(self::PASSWORD, true);
		$zip = $this->openBackup($result['path']);

		$manifest = json_decode((string)$zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(1, $manifest['format']);
		$this->assertSame('vereinsbuchhaltung', $manifest['app']);
		$this->assertSame('0.53.0', $manifest['appVersion']);
		$this->assertSame('SC Muster e.V.', $manifest['clubName']);
		$this->assertTrue($manifest['withFiles']);
		$this->assertNotFalse(strtotime($manifest['createdAt']));

		$this->assertSame(array_keys(BackupSchema::tables()), array_keys($manifest['tables']));
		$total = 0;
		foreach ($manifest['tables'] as $table => $info) {
			$raw = (string)$zip->getFromName('tables/' . $table . '.jsonl');
			$this->assertSame($info['rows'], $raw === '' ? 0 : substr_count($raw, "\n"), $table);
			$this->assertSame($info['sha256'], hash('sha256', $raw), $table);
			$total += $info['rows'];
		}
		$this->assertSame(2, $manifest['tables']['vbh_accounts']['rows']);
		$this->assertSame($total, $result['rows']);
	}

	public function testZeilenStehenUnveraendertInDerSicherung(): void {
		$source = $this->instance('quelle', true);
		$zip = $this->openBackup($source['export']->export(self::PASSWORD, true)['path']);

		$this->assertSame($source['tables']->all('vbh_accounts'), $this->jsonl($zip, 'vbh_accounts'));
		$this->assertSame($source['tables']->all('vbh_journal'), $this->jsonl($zip, 'vbh_journal'));
		// Instanzgebundene Spalten ersetzt erst der Import – die Sicherung bleibt ein getreues Abbild.
		$this->assertSame(9001, $this->jsonl($zip, 'vbh_attachments')[0]['file_id']);
	}

	public function testVerschluesselteSpaltenStehenImKlartext(): void {
		$source = $this->instance('quelle', true);
		$zip = $this->openBackup($source['export']->export(self::PASSWORD, true)['path']);

		$mandates = $this->jsonl($zip, 'vbh_mandates');
		$this->assertSame(self::IBAN, $mandates[0]['iban']);
		$this->assertNull($mandates[2]['iban']);
		$this->assertSame(self::IBAN, $this->jsonl($zip, 'vbh_rules')[0]['match_value']);
		$this->assertStringStartsWith('vbh1:', $source['tables']->all('vbh_mandates')[0]['iban'], 'Die Quelle bleibt verschlüsselt.');
	}

	public function testAusgenommeneTabellenFehlen(): void {
		$source = $this->instance('quelle', true);
		$zip = $this->openBackup($source['export']->export(self::PASSWORD, true)['path']);

		foreach (BackupSchema::excludedTables() as $table) {
			$this->assertFalse($zip->locateName('tables/' . $table . '.jsonl'), $table);
		}
	}

	public function testTabelleDieEsNichtGibtGiltAlsLeer(): void {
		$source = $this->instance('quelle', true);
		unset($source['tables']->data['vbh_invoice_plans']);
		$zip = $this->openBackup($source['export']->export(self::PASSWORD, true)['path']);

		$this->assertSame([], $this->jsonl($zip, 'vbh_invoice_plans'));
	}

	public function testEinstellungenOhneInstanzgebundene(): void {
		$source = $this->instance('quelle', true);
		$zip = $this->openBackup($source['export']->export(self::PASSWORD, true)['path']);

		$config = json_decode((string)$zip->getFromName('config.json'), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame([
			'brand_logo_mime' => 'image/png',
			'club_name' => 'SC Muster e.V.',
			'fiscal_period_rule' => '{"preset":"calendar"}',
		], $config);
	}

	public function testDateienLiegenUnterIhrerDatensatzId(): void {
		$source = $this->instance('quelle', true);
		$result = $source['export']->export(self::PASSWORD, true);
		$zip = $this->openBackup($result['path']);

		$this->assertSame('%PDF beleg', $zip->getFromName('files/attachments/5'));
		$this->assertSame('JPEG quittung', $zip->getFromName('files/reimbursements/4'));
		$this->assertSame('%PDF rechnung', $zip->getFromName('files/invoices/9'));
		$this->assertSame('%PDF mandat', $zip->getFromName('files/mandates/21'));
		$this->assertSame('PNG logo', $zip->getFromName('files/logo'));
		$this->assertSame(4, $result['files']);

		$manifest = json_decode((string)$zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(['count' => 4, 'bytes' => 10 + 13 + 13 + 11], $manifest['files']);
	}

	/** Review Focus 2: Ein Beleg fehlt in der Quelle – die Sicherung entsteht trotzdem und nennt ihn. */
	public function testFehlendeDateiWirdGenannt(): void {
		$source = $this->instance('quelle', true);
		$result = $source['export']->export(self::PASSWORD, true);
		$zip = $this->openBackup($result['path']);

		$this->assertSame([['kind' => 'attachments', 'id' => 6]], $result['missingFiles']);
		$this->assertFalse($zip->locateName('files/attachments/6'));
		$manifest = json_decode((string)$zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame([['kind' => 'attachments', 'id' => 6]], $manifest['missingFiles']);
	}

	public function testOhneBelegeNurLogo(): void {
		$source = $this->instance('quelle', true);
		$result = $source['export']->export(self::PASSWORD, false);
		$zip = $this->openBackup($result['path']);

		$this->assertFalse($zip->locateName('files/attachments/5'));
		$this->assertFalse($zip->locateName('files/mandates/21'));
		$this->assertSame('PNG logo', $zip->getFromName('files/logo'));
		$this->assertSame(0, $result['files']);
		$this->assertSame([], $result['missingFiles']);
		$manifest = json_decode((string)$zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
		$this->assertFalse($manifest['withFiles']);
		$this->assertSame(1, $manifest['tables']['vbh_journal']['rows'], 'Die Tabellen sind trotzdem vollständig.');
	}

	public function testNichtEntschluesselbarerWertBrichtAb(): void {
		$source = $this->instance('quelle', true);
		$source['tables']->data['vbh_mandates'][22]['iban'] = $this->cipher('fremd')->encrypt(self::IBAN);

		try {
			$source['export']->export(self::PASSWORD, true);
			$this->fail('Der Export hätte abbrechen müssen.');
		} catch (UserFacingException $e) {
			$this->assertSame('Ein verschlüsselter Wert lässt sich nicht lesen (Tabelle vbh_mandates, Datensatz 22). Die Sicherung wurde nicht erstellt.', $e->getMessage());
		}
	}

	public function testKurzesPasswortWirdVorDerArbeitAbgelehnt(): void {
		$source = $this->instance('quelle', true);
		$source['tables']->data['vbh_mandates'][22]['iban'] = $this->cipher('fremd')->encrypt(self::IBAN);

		$this->expectException(UserFacingException::class);
		$this->expectExceptionMessage('Das Passwort braucht mindestens 10 Zeichen.');
		$source['export']->export('zu kurz', true);
	}

	public function testDateiname(): void {
		$source = $this->instance('quelle', true);
		$this->assertSame('vereinsbuchhaltung-SC-Muster-e.V-' . date('Y-m-d') . '.vbhbackup', $source['export']->export(self::PASSWORD, false)['fileName']);

		$source['config']->deleteKey('vereinsbuchhaltung', 'club_name');
		$this->assertSame('vereinsbuchhaltung-' . date('Y-m-d') . '.vbhbackup', $source['export']->export(self::PASSWORD, false)['fileName']);

		$source['config']->setValueString('vereinsbuchhaltung', 'club_name', '../Tür & "Tor" / e.V.');
		$this->assertMatchesRegularExpression('/^vereinsbuchhaltung-[A-Za-z0-9äöüÄÖÜß.-]+-\d{4}-\d{2}-\d{2}\.vbhbackup$/u', $source['export']->export(self::PASSWORD, false)['fileName']);
	}

	public function testSicherungIstVerschluesselt(): void {
		$source = $this->instance('quelle', true);
		$raw = (string)file_get_contents($source['export']->export(self::PASSWORD, true)['path']);

		$this->assertStringStartsWith("VBHBACKUP1\n", $raw);
		$this->assertStringNotContainsString(self::IBAN, $raw);
		$this->assertStringNotContainsString('SC Muster', $raw);
	}
}
