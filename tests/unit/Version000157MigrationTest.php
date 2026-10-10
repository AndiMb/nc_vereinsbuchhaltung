<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Migration\Version000157Date20261004130000;
use OCP\BackgroundJob\IJobList;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Der Cutover-Schritt (Issue #107): Alt-Jobs austragen, Alt-Tabellen stehen lassen.
 * Geprüft wird das Verhalten gegen einen gemockten Schema-Wrapper – die
 * Datenbank selbst (MySQL/PostgreSQL/SQLite) bleibt dem Lauf gegen eine echte
 * Instanz vorbehalten (`occ migrations:migrate vereinsbuchhaltung`).
 */
class Version000157MigrationTest extends TestCase {

	private IJobList&MockObject $jobList;
	private IOutput&MockObject $output;

	protected function setUp(): void {
		$this->jobList = $this->createMock(IJobList::class);
		$this->output = $this->createMock(IOutput::class);
	}

	private function step(): Version000157Date20261004130000 {
		return new Version000157Date20261004130000($this->jobList);
	}

	/**
	 * @param list<string> $existing welche Tabellen es im Schema gibt
	 * @param list<string> $dropped hier landen die gelöschten Tabellen
	 */
	private function schema(array $existing, array &$dropped): ISchemaWrapper&MockObject {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(static fn (string $table): bool => in_array($table, $existing, true));
		$schema->method('dropTable')->willReturnCallback(static function (string $table) use (&$dropped): void {
			$dropped[] = $table;
		});
		return $schema;
	}

	/** Das Schema liest `postSchemaChange()` nicht; ein leerer Wrapper genügt dem Typ. */
	private function untouchedSchema(): \Closure {
		$schema = $this->createMock(ISchemaWrapper::class);
		return static fn (): ISchemaWrapper => $schema;
	}

	/** @param list<string> $existing */
	private function changeSchema(array $existing, array &$dropped): ?ISchemaWrapper {
		$schema = $this->schema($existing, $dropped);
		return $this->step()->changeSchema($this->output, static fn () => $schema, []);
	}

	/**
	 * Ein Update darf weder Mandate noch Beiträge des alten Moduls vernichten: die Alt-Tabellen bleiben stehen
	 * (Version000158 übernimmt ihren Inhalt, die Sammeleinzüge liegen als Archiv weiter dort).
	 */
	public function testAltTabellenWerdenNichtGeloescht(): void {
		$dropped = [];
		$result = $this->changeSchema(['vbh_sepa_mandates', 'vbh_membership_fees', 'vbh_sepa_batches', 'vbh_sepa_batch_items', 'vbh_mandates', 'vbh_open_items'], $dropped);

		$this->assertNull($result, 'Kein Schema-Eingriff: Nextcloud migriert nichts.');
		$this->assertSame([], $dropped);
	}

	/** Die Tabellen des neuen Modells und der Kern-Buchhaltung sind nie Teil der Alt-Liste. */
	public function testNeueModellTabellenUndKernTabellenSindNichtTeilDerAltListe(): void {
		$neu = [
			'vbh_members', 'vbh_mandates', 'vbh_mandate_amendments', 'vbh_mandate_events', 'vbh_assignments',
			'vbh_contribution_groups', 'vbh_debit_batches', 'vbh_debit_items', 'vbh_returned_debits',
			'vbh_dunning_notices', 'vbh_bank_tx', 'vbh_bank_tx_sepa_details', 'vbh_open_items', 'vbh_journal',
		];

		$this->assertSame([], array_intersect($neu, Version000157Date20261004130000::LEGACY_TABLES));
	}

	/** Eine Neuinstallation (nichts davon existiert) ist ein leerer Schritt ohne Fehler. */
	public function testNeuinstallationOhneAltTabellenIstEinLeererSchritt(): void {
		$dropped = [];
		$result = $this->changeSchema(['vbh_mandates', 'vbh_open_items'], $dropped);

		$this->assertNull($result);
		$this->assertSame([], $dropped);
	}

	public function testAltJobsWerdenAusDerJobListeAusgetragen(): void {
		$removed = [];
		$this->jobList->expects($this->exactly(2))->method('remove')->willReturnCallback(function ($job, $argument = null) use (&$removed): void {
			$this->assertNull($argument, 'Ohne Argument austragen: auch Zeilen mit Argument gehören dazu.');
			$removed[] = $job;
		});

		$this->step()->postSchemaChange($this->output, $this->untouchedSchema(), []);

		$this->assertSame([
			'OCA\\Vereinsbuchhaltung\\BackgroundJob\\MembershipFeeDueJob',
			'OCA\\Vereinsbuchhaltung\\BackgroundJob\\SepaPreNotificationJob',
		], $removed);
	}

	/** Gegen die Job-Liste ohne die beiden Jobs (zweiter Lauf) gibt es nichts zu tun – und keinen Fehler. */
	public function testAustragenIstWiederholbar(): void {
		$this->jobList->expects($this->exactly(4))->method('remove');

		$this->step()->postSchemaChange($this->output, $this->untouchedSchema(), []);
		$this->step()->postSchemaChange($this->output, $this->untouchedSchema(), []);
	}

	/**
	 * Die beiden Alt-Jobs dürfen nirgends mehr registriert sein: stünden sie noch in
	 * `appinfo/info.xml`, trüge Nextcloud sie nach jeder Migration sofort wieder ein –
	 * auf eine Klasse, die es nicht mehr gibt.
	 */
	public function testAltJobsStehenNichtMehrInInfoXml(): void {
		$jobs = $this->infoXmlJobs();

		foreach (Version000157Date20261004130000::LEGACY_JOBS as $legacy) {
			$this->assertNotContains($legacy, $jobs);
			// Per Dateipfad statt class_exists(): der Composer-Autoloader der App warnt bei
			// jedem Ladeversuch einer fehlenden Datei, und failOnWarning ist gesetzt.
			$file = dirname(__DIR__, 2) . '/lib/BackgroundJob/' . substr($legacy, strrpos($legacy, '\\') + 1) . '.php';
			$this->assertFileDoesNotExist($file, "$legacy darf es nicht mehr geben.");
		}
	}

	/** Jeder in `appinfo/info.xml` eingetragene Job hat seine Klasse – sonst landet beim Cron eine Warnung im Log. */
	public function testJederJobAusInfoXmlHatEineKlasse(): void {
		$jobs = $this->infoXmlJobs();

		$this->assertNotEmpty($jobs);
		foreach ($jobs as $job) {
			$this->assertTrue(class_exists($job), "Job $job steht in appinfo/info.xml, die Klasse fehlt.");
		}
	}

	/** @return list<string> */
	private function infoXmlJobs(): array {
		$xml = simplexml_load_file(dirname(__DIR__, 2) . '/appinfo/info.xml');
		$this->assertNotFalse($xml);
		$jobs = [];
		foreach ($xml->{'background-jobs'}->job as $job) {
			$jobs[] = trim((string)$job);
		}
		return $jobs;
	}
}
