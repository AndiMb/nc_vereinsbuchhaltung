<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersion;
use OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersionMapper;
use OCA\Vereinsbuchhaltung\Service\MandateLegalTextService;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Textversionierung des Mandats-Rechtstexts (Spec §2.2/§3.11, Issue #67):
 * Bootstrap der ersten Version, admin-getriebene neue Versionen mit
 * geänderten Rahmen, und die automatische system-getriebene Fortschreibung,
 * wenn der Code-Pflichtblock sich ändert (App-Update).
 */
class MandateLegalTextServiceTest extends TestCase {

	private MandateLegalTextVersionMapper&MockObject $mapper;
	/** @var array<int, MandateLegalTextVersion> */
	private array $stored = [];
	private int $nextId = 1;

	protected function setUp(): void {
		$this->mapper = $this->createMock(MandateLegalTextVersionMapper::class);
		$this->stored = [];
		$this->nextId = 1;

		$this->mapper->method('insert')->willReturnCallback(function (MandateLegalTextVersion $v): MandateLegalTextVersion {
			$v->setId($this->nextId++);
			$this->stored[$v->getId()] = $v;
			return $v;
		});
		$this->mapper->method('findLatest')->willReturnCallback(function (): ?MandateLegalTextVersion {
			if ($this->stored === []) {
				return null;
			}
			return $this->stored[array_key_last($this->stored)];
		});
		$this->mapper->method('findOrNull')->willReturnCallback(fn (int $id) => $this->stored[$id] ?? null);
	}

	private function service(): MandateLegalTextService {
		return new MandateLegalTextService($this->mapper, $this->createMock(IL10N::class));
	}

	// --- Bootstrap ---------------------------------------------------------------

	public function testErsterAufrufLegtSystemVersionMitLeeremRahmenAn(): void {
		$version = $this->service()->current();

		$this->assertSame(MandateLegalTextVersion::CREATED_BY_SYSTEM, $version->getCreatedBy());
		$this->assertStringContainsString('SEPA-Lastschriftmandat', $version->getBody());
		$this->assertSame('', $this->service()->extractRahmen($version->getBody()));
	}

	public function testCurrentIstStabilOhneAenderungen(): void {
		$service = $this->service();
		$first = $service->current();
		$second = $service->current();

		$this->assertSame($first->getId(), $second->getId(), 'ohne Aenderung keine neue Version bei jedem Aufruf');
	}

	// --- Admin-getriebene neue Version (Rahmen) -----------------------------------

	public function testCreateVersionLegtImmerEineNeueZeileAn(): void {
		$service = $this->service();
		$v1 = $service->createVersion('Alter Rahmentext', MandateLegalTextVersion::CREATED_BY_VERWALTER);
		$v2 = $service->createVersion('Neuer Rahmentext', MandateLegalTextVersion::CREATED_BY_VERWALTER);

		$this->assertNotSame($v1->getId(), $v2->getId());
		$this->assertSame('Alter Rahmentext', $service->extractRahmen($v1->getBody()), 'die alte Version bleibt unveraendert stehen (keine Rueckwirkung)');
		$this->assertSame('Neuer Rahmentext', $service->extractRahmen($v2->getBody()));
	}

	public function testCreateVersionMitUngueltigemErstellerSchlaegtFehl(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->createVersion('Rahmen', 'irgendwer');
	}

	public function testCurrentRahmenLiestDenRahmenDerAktuellenVersion(): void {
		$service = $this->service();
		$service->createVersion('Unser Verein foerdert Musik.', MandateLegalTextVersion::CREATED_BY_VERWALTER);

		$this->assertSame('Unser Verein foerdert Musik.', $service->currentRahmen());
	}

	// --- Keine Rueckwirkung: eine fixierte Version bleibt unabhaengig von current() ----

	public function testEinmalGeladeneVersionAendertSichNichtDurchSpaetereNeueVersion(): void {
		$service = $this->service();
		$fixed = $service->current();
		$fixedBody = $fixed->getBody();

		$service->createVersion('Ein ganz anderer Rahmen', MandateLegalTextVersion::CREATED_BY_VERWALTER);

		$this->assertSame($fixedBody, $fixed->getBody(), 'ein bereits gehaltenes Objekt mutiert nicht rueckwirkend');
	}

	// --- System-getriebene Fortschreibung bei geaendertem Pflichtblock ----------------

	/**
	 * Simuliert ein App-Update, das den Pflichtblock ändert: ein
	 * MandateLegalTextService mit einem ANDEREN Pflichtblock als beim
	 * Anlegen der gespeicherten Version muss bei current() automatisch eine
	 * neue system-Version mit demselben (alten) Rahmen nachziehen.
	 */
	public function testGeaenderterPflichtblockErzeugtAutomatischNeueSystemVersion(): void {
		$serviceV1 = new class($this->mapper, $this->createMock(IL10N::class)) extends MandateLegalTextService {
			public function defaultPflichtblock(): string {
				return 'PFLICHTBLOCK VERSION 1';
			}
		};
		$original = $serviceV1->createVersion('Individueller Rahmen', MandateLegalTextVersion::CREATED_BY_VERWALTER);

		$serviceV2 = new class($this->mapper, $this->createMock(IL10N::class)) extends MandateLegalTextService {
			public function defaultPflichtblock(): string {
				return 'PFLICHTBLOCK VERSION 2 (App-Update)';
			}
		};
		$current = $serviceV2->current();

		$this->assertNotSame($original->getId(), $current->getId(), 'ein geänderter Pflichtblock erzwingt eine neue Version');
		$this->assertSame(MandateLegalTextVersion::CREATED_BY_SYSTEM, $current->getCreatedBy());
		$this->assertStringContainsString('PFLICHTBLOCK VERSION 2', $current->getBody());
		$this->assertSame('Individueller Rahmen', $serviceV2->extractRahmen($current->getBody()), 'der bisherige Rahmen bleibt beim automatischen Fortschreiben erhalten');
	}

	// --- Rendering -----------------------------------------------------------------

	public function testRenderErsetztCreditorNamePlatzhalter(): void {
		$version = new MandateLegalTextVersion();
		$version->setBody('Ich ermächtige {{creditor_name}} zum Einzug.');

		$this->assertSame('Ich ermächtige Musikverein Beispiel e.V. zum Einzug.', $version->render('Musikverein Beispiel e.V.'));
	}
}
