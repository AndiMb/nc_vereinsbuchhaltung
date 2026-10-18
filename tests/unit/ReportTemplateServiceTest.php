<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\ReportTemplate;
use OCA\Vereinsbuchhaltung\Db\ReportTemplateMapper;
use OCA\Vereinsbuchhaltung\Db\TransactionRunner;
use OCA\Vereinsbuchhaltung\Service\Report\BuiltinReportTemplates;
use OCA\Vereinsbuchhaltung\Service\Report\ReportTemplateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Vorlagen des Gliederungsberichts: die Standardvorlagen entstehen genau
 * einmal als gewöhnliche Vorlagen, lassen sich löschen und zurückholen.
 *
 * Der Mapper ist ein Speicher im Arbeitsspeicher, die Sperre und der
 * App-Wert sind steuerbar – so lassen sich auch zwei gleichzeitige erste
 * Abrufe nachstellen.
 */
class ReportTemplateServiceTest extends TestCase {

	/** @var array<int, ReportTemplate> */
	private array $rows = [];
	private int $nextId = 1;
	/** @var array<string, string> App-Werte */
	private array $values = [];
	/** @var list<string> */
	private array $log = [];
	private bool $lockBusy = false;
	private bool $insertFails = false;
	/** Wird beim Holen der Sperre ausgeführt – „ein anderer Request war schneller“ */
	private ?\Closure $whileLocking = null;

	private ReportTemplateMapper&MockObject $mapper;

	protected function setUp(): void {
		$this->rows = [];
		$this->nextId = 1;
		$this->values = [];
		$this->log = [];
		$this->lockBusy = false;
		$this->insertFails = false;
		$this->whileLocking = null;
	}

	private function service(): ReportTemplateService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$this->mapper = $this->createMock(ReportTemplateMapper::class);
		$this->mapper->method('findAll')->willReturnCallback(fn (): array => array_values($this->rows));
		$this->mapper->method('findForUser')->willReturnCallback(fn (string $u, int $id): ?ReportTemplate => $this->rows[$id] ?? null);
		$this->mapper->method('maxPosition')->willReturnCallback(fn (): int => count($this->rows));
		$this->mapper->method('insert')->willReturnCallback(function (ReportTemplate $t): ReportTemplate {
			if ($this->insertFails) {
				throw new \RuntimeException('DB weg');
			}
			$t->setId($this->nextId++);
			$this->rows[$t->getId()] = $t;
			$this->log[] = 'INSERT ' . $t->getName();
			return $t;
		});
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mapper->method('delete')->willReturnCallback(function (ReportTemplate $t): ReportTemplate {
			unset($this->rows[$t->getId()]);
			return $t;
		});

		$transaction = $this->createMock(TransactionRunner::class);
		$transaction->method('run')->willReturnCallback(function (callable $fn) {
			$this->log[] = 'BEGIN';
			$result = $fn();
			$this->log[] = 'COMMIT';
			return $result;
		});

		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')->willReturnCallback(function (string $path): void {
			if ($this->lockBusy) {
				throw new LockedException($path);
			}
			$this->log[] = 'LOCK';
			if ($this->whileLocking !== null) {
				($this->whileLocking)();
			}
		});
		$locking->method('releaseLock')->willReturnCallback(function (): void {
			$this->log[] = 'UNLOCK';
		});

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => $this->values[$key] ?? $default);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->values[$key] = $value;
			return true;
		});
		$appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->values[$key]);
		});
		$appConfig->method('clearCache')->willReturnCallback(function (): void {
			$this->log[] = 'CLEAR_CACHE';
		});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-10-18 12:00:00'));

		return new ReportTemplateService($this->mapper, new BuiltinReportTemplates($l10n), $transaction, $locking, $appConfig, $time, $l10n);
	}

	private function stored(string $name, string $json = '{}'): ReportTemplate {
		$t = new ReportTemplate();
		$t->setId($this->nextId++);
		$t->setUserId('u');
		$t->setName($name);
		$t->setDefinition($json);
		$t->setPosition(count($this->rows) + 1);
		$this->rows[$t->getId()] = $t;
		return $t;
	}

	/** @return array<string, mixed> */
	private function valid(): array {
		return ['columns' => [['source' => 'actual']], 'sections' => [['levels' => [['by' => 'type']]]]];
	}

	private const STANDARD = ['Haushaltsplan nach Titelgruppen (Zuwendungsgeber)', 'Ergebnis je Bereich und Projekt'];

	// --- Einmaliges Anlegen ------------------------------------------------

	public function testErsterAbrufLegtDieStandardvorlagenAn(): void {
		$all = $this->service()->all('u');

		$this->assertSame(self::STANDARD, array_column($all, 'name'));
		$this->assertSame(['1', '2'], array_column($all, 'id'));
		$this->assertArrayNotHasKey('builtin', $all[0]);
		$this->assertSame('1', $this->values[ReportTemplateService::SEEDED_KEY]);
		$this->assertSame(['LOCK', 'CLEAR_CACHE', 'BEGIN', 'INSERT ' . self::STANDARD[0], 'INSERT ' . self::STANDARD[1], 'COMMIT', 'UNLOCK'], $this->log);
	}

	public function testZweiterAbrufLegtNichtsMehrAn(): void {
		$service = $this->service();
		$service->all('u');
		$this->log = [];

		$this->assertCount(2, $service->all('u'));
		$this->assertSame([], $this->log, 'kein zweites Anlegen, nicht einmal eine Sperre');
	}

	public function testGeloeschteStandardvorlagenKommenNichtZurueck(): void {
		$service = $this->service();
		foreach ($service->all('u') as $t) {
			$service->delete('u', $t['id']);
		}

		$this->assertSame([], $service->all('u'));
	}

	public function testVorhandeneKopienBleibenUndStandardKommtHintenDazu(): void {
		// Bestand aus 0.38.0: eine Kopie, aber noch kein Vermerk
		$this->stored('Meine Kopie', '{"columns":[{"source":"plan"}]}');

		$all = $this->service()->all('u');

		$this->assertSame(['Meine Kopie', ...self::STANDARD], array_column($all, 'name'));
		$this->assertSame([2, 3], [$this->rows[2]->getPosition(), $this->rows[3]->getPosition()]);
	}

	public function testBelegteSperreLegtNichtsAn(): void {
		$this->lockBusy = true;

		$this->assertSame([], $this->service()->all('u'));
		$this->assertArrayNotHasKey(ReportTemplateService::SEEDED_KEY, $this->values);
	}

	public function testAndererRequestWarSchnellerAlsDieSperre(): void {
		$this->whileLocking = function (): void {
			$this->values[ReportTemplateService::SEEDED_KEY] = '1';
		};

		$this->assertSame([], $this->service()->all('u'));
		$this->assertSame(['LOCK', 'CLEAR_CACHE', 'UNLOCK'], $this->log, 'frisch gelesen, nichts angelegt, Sperre wieder frei');
	}

	public function testSperreWirdAuchBeiEinemFehlerFreigegeben(): void {
		$this->insertFails = true;

		try {
			$this->service()->all('u');
			$this->fail('Der Fehler muss weitergereicht werden.');
		} catch (\RuntimeException) {
		}
		$this->assertSame('UNLOCK', end($this->log));
		$this->assertArrayNotHasKey(ReportTemplateService::SEEDED_KEY, $this->values);
	}

	// --- Standardvorlagen zurückholen ------------------------------------

	public function testStandardvorlagenErneutHinzufuegenMeldetDoppelteNamen(): void {
		$service = $this->service();
		$service->all('u');
		$service->delete('u', '2');

		[$created, $duplicates] = $service->addStandard('u');

		$this->assertSame(self::STANDARD, array_column($created, 'name'));
		$this->assertSame([self::STANDARD[0]], $duplicates);
		$this->assertCount(3, $service->all('u'));
	}

	public function testHinzufuegenSetztDenVermerk(): void {
		$service = $this->service();
		$service->forgetSeeded();

		$service->addStandard('u');

		$this->assertSame('1', $this->values[ReportTemplateService::SEEDED_KEY]);
		$this->assertCount(2, $service->all('u'), 'kein zusätzliches Anlegen beim nächsten Abruf');
	}

	public function testNachDemZuruecksetzenWerdenSieWiederAngelegt(): void {
		$service = $this->service();
		$service->all('u');
		$this->rows = [];
		$service->forgetSeeded();

		$this->assertSame(self::STANDARD, array_column($service->all('u'), 'name'));
	}

	// --- Ändern und Löschen ----------------------------------------------

	public function testFruehereBuiltinKennungenGibtEsNichtMehr(): void {
		$service = $this->service();
		$service->all('u');

		$this->assertNull($service->find('u', 'builtin:bereiche'));
		$this->assertSame([null, []], $service->update('u', 'builtin:bereiche', 'X', $this->valid()));
		$this->assertFalse($service->delete('u', 'builtin:zuwendungsgeber'));
	}

	public function testStandardvorlageLaesstSichAendern(): void {
		$service = $this->service();
		$service->all('u');

		[$template, $errors] = $service->update('u', '1', 'Für das Ministerium', $this->valid());

		$this->assertSame([], $errors);
		$this->assertSame('Für das Ministerium', $template['name'] ?? null);
	}

	public function testAnlegenSpeichertNormalisiertUndHaengtHintenAn(): void {
		$service = $this->service();
		$this->values[ReportTemplateService::SEEDED_KEY] = '1';
		$this->stored('A');
		$this->stored('B');

		[$template, $errors] = $service->create('u', '  Verband ', $this->valid());

		$this->assertSame([], $errors);
		$this->assertSame('Verband', $template['name'] ?? null);
		$this->assertSame(3, $this->rows[3]->getPosition());
		$this->assertStringContainsString('"periodOffset":0', $this->rows[3]->getDefinition());
	}

	public function testUngueltigeVorlageWirdNichtGespeichert(): void {
		$service = $this->service();

		[$template, $errors] = $service->create('u', '', ['sections' => []]);

		$this->assertNull($template);
		$this->assertSame('Die Vorlage braucht einen Namen.', $errors[0]);
		$this->assertContains('Die Vorlage braucht mindestens einen Abschnitt.', $errors);
		$this->assertSame([], $this->rows);
	}

	public function testDefektesJsonErgibtLeereDefinition(): void {
		$service = $this->service();
		$this->stored('Kaputt', '{nicht json');

		$this->assertSame([], $service->find('u', '1')['definition']['sections'] ?? null);
	}
}
