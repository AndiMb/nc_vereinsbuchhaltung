<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\JournalMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Exception\ClaimManagedException;
use OCA\Vereinsbuchhaltung\Service\OpenItemService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Die generischen Offene-Posten-Wege (Issue #121): freie Posten wie bisher,
 * Forderungen des Beitragsmoduls (Zeile mit `memberId` oder `type`) werden bei
 * Bezahlt, Stornieren, Wieder öffnen und Löschen abgelehnt, bevor irgendetwas
 * geschrieben wird. Die Regeln der Forderungen (Erledigungsvermerk, Storno nur
 * vor der Einreichung, Erlass statt Löschen) stehen im ClaimService und sind
 * über diesen Weg sonst nicht einzuhalten - siehe ClaimServiceTest.
 */
class OpenItemServiceTest extends TestCase {

	private OpenItemMapper&MockObject $mapper;
	private JournalMapper&MockObject $journalMapper;

	protected function setUp(): void {
		$this->mapper = $this->createMock(OpenItemMapper::class);
		$this->journalMapper = $this->createMock(JournalMapper::class);
	}

	private function service(): OpenItemService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return new OpenItemService($this->mapper, $this->journalMapper, $l10n);
	}

	private function freeItem(string $status = 'open'): OpenItem {
		$item = new OpenItem();
		$item->setId(4);
		$item->setDebtor('Schreinerei Holz');
		$item->setStatus($status);
		return $item;
	}

	private function claim(string $status = 'open'): OpenItem {
		$item = $this->freeItem($status);
		$item->setId(9);
		$item->setMemberId(1);
		$item->setType(OpenItem::TYPE_CONTRIBUTION);
		return $item;
	}

	/** @return array<string, array{string}> */
	public static function writePaths(): array {
		return [
			'bezahlt' => ['markPaid'],
			'storniert' => ['cancel'],
			'wieder geöffnet' => ['reopen'],
			'gelöscht' => ['delete'],
		];
	}

	private function call(string $method, int $id): mixed {
		return $method === 'markPaid' ? $this->service()->markPaid($id, null) : $this->service()->$method($id);
	}

	/**
	 * @dataProvider writePaths
	 */
	public function testEineForderungWirdAbgelehntUndNichtGeschrieben(string $method): void {
		$this->mapper->method('find')->with(9)->willReturn($this->claim());
		$this->mapper->expects($this->never())->method('update');
		$this->mapper->expects($this->never())->method('delete');

		try {
			$this->call($method, 9);
			$this->fail('Eine Forderung darf über den generischen Weg nicht geändert werden.');
		} catch (ClaimManagedException $e) {
			// Die Meldung nennt den Ort, an dem die Forderung zu bearbeiten ist.
			$this->assertStringContainsString('Einzug-Reiter', $e->getMessage());
		}
	}

	/**
	 * Der Zustand der Forderung ändert nichts: erledigt, erlassen, storniert oder
	 * in einem Lauf - der Weg ist für jede Forderung zu, nicht nur für „offene".
	 *
	 * @dataProvider writePaths
	 */
	public function testJederZustandEinerForderungIstGesperrt(string $method): void {
		$erledigt = $this->claim('paid');
		$erledigt->setSettledAt('2026-01-01T00:00:00+00:00');
		$erlassen = $this->claim('waived');
		$erlassen->setSettledAt('2026-01-01T00:00:00+00:00');
		$storniert = $this->claim('cancelled');
		$storniert->setCancelledAt('2026-01-01T00:00:00+00:00');

		foreach ([$erledigt, $erlassen, $storniert] as $item) {
			$mapper = $this->createMock(OpenItemMapper::class);
			$mapper->method('find')->willReturn($item);
			$mapper->expects($this->never())->method('update');
			$mapper->expects($this->never())->method('delete');
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnArgument(0);
			$service = new OpenItemService($mapper, $this->journalMapper, $l10n);

			try {
				$method === 'markPaid' ? $service->markPaid(9, null) : $service->$method(9);
				$this->fail('Auch eine erledigte oder stornierte Forderung bleibt gesperrt (' . $item->getStatus() . ').');
			} catch (ClaimManagedException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/**
	 * Die Zeile gehört auch dann dem Modul, wenn nur eines der beiden Felder
	 * gesetzt ist - das Modul legt sie immer zusammen an, ein halber Satz käme
	 * nur von außen und ist kein freier Posten.
	 *
	 * @dataProvider writePaths
	 */
	public function testSchonEinFeldDesModulsSperrtDenWeg(string $method): void {
		$onlyMember = $this->freeItem();
		$onlyMember->setMemberId(1);
		$onlyType = $this->freeItem();
		$onlyType->setType(OpenItem::TYPE_FEE);

		foreach ([$onlyMember, $onlyType] as $item) {
			$mapper = $this->createMock(OpenItemMapper::class);
			$mapper->method('find')->willReturn($item);
			$mapper->expects($this->never())->method('update');
			$mapper->expects($this->never())->method('delete');
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnArgument(0);
			$service = new OpenItemService($mapper, $this->journalMapper, $l10n);

			try {
				$method === 'markPaid' ? $service->markPaid(4, null) : $service->$method(4);
				$this->fail('Ein Feld des Moduls genügt, um den generischen Weg zu sperren.');
			} catch (ClaimManagedException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testEineForderungMitBuchungswahlWirdVorDerBuchungspruefungAbgelehnt(): void {
		$this->mapper->method('find')->willReturn($this->claim());
		// Die Ablehnung kommt zuerst: nicht erst eine Fehlermeldung zur Buchung, dann die zur Forderung.
		$this->journalMapper->expects($this->never())->method('find');

		$this->expectException(ClaimManagedException::class);
		$this->service()->markPaid(9, 77);
	}

	public function testEinFreierPostenWirdWieBisherBezahlt(): void {
		$this->mapper->method('find')->willReturn($this->freeItem());
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$item = $this->service()->markPaid(4, null);

		$this->assertSame('paid', $item->getStatus());
		$this->assertNull($item->getPaidJournalId());
	}

	public function testEinFreierPostenWirdMitBuchungBezahlt(): void {
		$this->mapper->method('find')->willReturn($this->freeItem());
		$this->journalMapper->expects($this->once())->method('find')->with(77)->willReturn($this->createMock(\OCA\Vereinsbuchhaltung\Db\Journal::class));
		$this->mapper->method('update')->willReturnArgument(0);

		$item = $this->service()->markPaid(4, 77);

		$this->assertSame('paid', $item->getStatus());
		$this->assertSame(77, $item->getPaidJournalId());
	}

	public function testEinFreierPostenWirdWieBisherStorniert(): void {
		$this->mapper->method('find')->willReturn($this->freeItem());
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$this->assertSame('cancelled', $this->service()->cancel(4)->getStatus());
	}

	public function testEinFreierPostenWirdWieBisherWiederGeoeffnet(): void {
		$paid = $this->freeItem('paid');
		$paid->setPaidJournalId(12);
		$this->mapper->method('find')->willReturn($paid);
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$item = $this->service()->reopen(4);

		$this->assertSame('open', $item->getStatus());
		$this->assertNull($item->getPaidJournalId());
	}

	public function testEinFreierPostenWirdWieBisherGeloescht(): void {
		$item = $this->freeItem();
		$this->mapper->method('find')->willReturn($item);
		$this->mapper->expects($this->once())->method('delete')->with($item);

		$this->service()->delete(4);
	}

	/**
	 * @dataProvider writePaths
	 */
	public function testEinUnbekannterPostenBleibtEinNichtGefunden(string $method): void {
		$this->mapper->method('find')->willThrowException(new DoesNotExistException('weg'));

		$this->expectException(DoesNotExistException::class);
		$this->call($method, 404);
	}
}
