<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Migration\Version000159Date20261011000000;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Der Aufräum-Schritt zur entfallenen Einstellung „Standard-Beitrag": die beiden
 * gespeicherten App-Config-Werte werden ausgetragen, sonst nichts. Geprüft wird
 * gegen eine gemockte `IConfig` – ein Schema-Eingriff findet nicht statt.
 */
class Version000159MigrationTest extends TestCase {

	private IConfig&MockObject $config;
	private IOutput&MockObject $output;

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->output = $this->createMock(IOutput::class);
	}

	private function step(): Version000159Date20261011000000 {
		return new Version000159Date20261011000000($this->config);
	}

	/** Das Schema liest der Schritt nicht; ein leerer Wrapper genügt dem Typ. */
	private function untouchedSchema(): \Closure {
		$schema = $this->createMock(ISchemaWrapper::class);
		return static fn (): ISchemaWrapper => $schema;
	}

	public function testBeideWerteDerEntfallenenEinstellungWerdenAusgetragen(): void {
		$deleted = [];
		$this->config->expects($this->exactly(2))->method('deleteAppValue')->willReturnCallback(function (string $app, string $key) use (&$deleted): void {
			$this->assertSame(Application::APP_ID, $app);
			$deleted[] = $key;
		});

		$this->step()->postSchemaChange($this->output, $this->untouchedSchema(), []);

		$this->assertSame(['default_fee_amount_cents', 'default_fee_frequency'], $deleted);
	}

	/** Es bleibt ein reiner Konfigurationsschritt: das Schema wird nicht angefasst. */
	public function testKeinSchemaEingriff(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->expects($this->never())->method('dropTable');
		$schema->expects($this->never())->method('createTable');

		$this->assertNull($this->step()->changeSchema($this->output, static fn () => $schema, []));
	}

	/** Ohne vorhandene Einträge (zweiter Lauf, nie gesetzt) gibt es nichts zu tun – und keinen Fehler. */
	public function testAustragenIstWiederholbar(): void {
		$this->config->expects($this->exactly(4))->method('deleteAppValue');
		$this->config->expects($this->never())->method('setAppValue');

		$this->step()->postSchemaChange($this->output, $this->untouchedSchema(), []);
		$this->step()->postSchemaChange($this->output, $this->untouchedSchema(), []);
	}
}
