<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\DebitBatch;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\Member;
use OCA\Vereinsbuchhaltung\Db\MemberMapper;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Db\OpenItemMapper;
use OCA\Vereinsbuchhaltung\Service\AuditService;
use OCA\Vereinsbuchhaltung\Service\ClaimService;
use OCA\Vereinsbuchhaltung\Service\ClaimStateResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Manuelle Einzelforderung (Spec §3.3 „schmale Tür") sowie
 * Erledigungsvermerk/Storno/Stundung (Spec §2.2/§3.6).
 */
class ClaimServiceTest extends TestCase {

	private OpenItemMapper&MockObject $mapper;
	private MemberMapper&MockObject $memberMapper;
	private DebitItemMapper&MockObject $debitItems;
	private AuditService&MockObject $audit;

	protected function setUp(): void {
		$this->mapper = $this->createMock(OpenItemMapper::class);
		$this->memberMapper = $this->createMock(MemberMapper::class);
		$this->debitItems = $this->createMock(DebitItemMapper::class);
		$this->audit = $this->createMock(AuditService::class);
	}

	private function service(string $today = '2026-06-15'): ClaimService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime($today));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		return new ClaimService($this->mapper, $this->memberMapper, $time, $l10n, $this->debitItems, $this->audit);
	}

	private function member(int $id = 1): Member {
		$member = new Member();
		$member->setId($id);
		$member->setFirstName('Anna');
		$member->setLastName('Musterfrau');
		return $member;
	}

	public function testCreateManualLegtEineOffeneForderungAn(): void {
		$this->memberMapper->method('find')->with(1)->willReturn($this->member());
		$this->mapper->method('insert')->willReturnArgument(0);

		$item = $this->service()->createManual(1, OpenItem::TYPE_FEE, 1500, 'Startgebühr', '2026-07-01', null);

		$this->assertSame(1500, $item->getAmountCents());
		$this->assertSame('Startgebühr', $item->getDescription());
		$this->assertSame('open', $item->getStatus());
		$this->assertSame(1, $item->getMemberId());
		$this->assertSame(OpenItem::TYPE_FEE, $item->getType());
	}

	public function testCreateManualVerlangtEineBezeichnung(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->createManual(1, OpenItem::TYPE_FEE, 1500, '   ', '2026-07-01', null);
	}

	public function testCreateManualVerlangtPositivenBetrag(): void {
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->createManual(1, OpenItem::TYPE_FEE, 0, 'Startgebühr', '2026-07-01', null);
	}

	public function testCreateManualOhneMandatMoeglich(): void {
		// Kein Mandat noetig - die Methode fragt gar nicht danach (siehe
		// Klassen-Docblock: Mandate existieren in diesem Branch nicht).
		$this->memberMapper->method('find')->willReturn($this->member());
		$this->mapper->method('insert')->willReturnArgument(0);
		$item = $this->service()->createManual(1, OpenItem::TYPE_CONTRIBUTION, 500, 'Nachzahlung', '2026-07-01', null);
		$this->assertSame(OpenItem::TYPE_CONTRIBUTION, $item->getType());
	}

	private function openClaim(): OpenItem {
		$item = new OpenItem();
		$item->setId(1);
		$item->setMemberId(1);
		$item->setType(OpenItem::TYPE_FEE);
		$item->setStatus('open');
		return $item;
	}

	public function testSettlePaidOhneBegruendungMoeglich(): void {
		$this->mapper->method('find')->willReturn($this->openClaim());
		$this->mapper->method('update')->willReturnArgument(0);
		$item = $this->service()->settle(1, 'paid', null, 'kassenwart');
		$this->assertSame('paid', $item->getStatus());
		// Spec §3.6: Erledigungsvermerk mit Urheber - die Oberfläche zeigt ihn an.
		$this->assertSame('kassenwart', $item->getSettledBy());
		$this->assertSame(ClaimStateResolver::STATE_SETTLED, ClaimStateResolver::resolveForItem($item));
	}

	public function testSettleWaivedVerlangtBegruendung(): void {
		$this->mapper->method('find')->willReturn($this->openClaim());
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->settle(1, 'waived', null, 'kassenwart');
	}

	public function testSettleWaivedMitBegruendung(): void {
		$this->mapper->method('find')->willReturn($this->openClaim());
		$this->mapper->method('update')->willReturnArgument(0);
		$item = $this->service()->settle(1, 'waived', 'Härtefall laut Vorstandsbeschluss', 'kassenwart');
		$this->assertSame('waived', $item->getStatus());
	}

	public function testSettleBereitsErledigterForderungSchlaegtFehl(): void {
		$settled = $this->openClaim();
		$settled->setStatus('paid');
		$settled->setSettledAt('2026-01-01T00:00:00+00:00');
		$this->mapper->method('find')->willReturn($settled);
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->settle(1, 'paid', null, 'kassenwart');
	}

	public function testCancelVerlangtBegruendung(): void {
		$this->mapper->method('find')->willReturn($this->openClaim());
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->cancel(1, '');
	}

	public function testCancelSetztStatusUndBegruendung(): void {
		$this->mapper->method('find')->willReturn($this->openClaim());
		$this->mapper->method('update')->willReturnArgument(0);
		$item = $this->service()->cancel(1, 'Doppelt angelegt');
		$this->assertSame('cancelled', $item->getStatus());
		$this->assertSame('Doppelt angelegt', $item->getCancelledReason());
	}

	public function testCancelIstInEingereichtemLaufGesperrt(): void {
		// Spec §3.5 „kein Storno nach Einreichung" - für den Verzicht gibt es den Erlass.
		$this->mapper->method('find')->willReturn($this->openClaim());
		$this->debitItems->method('findLiveBatchStatusByOpenItem')->with(1)->willReturn(DebitBatch::STATUS_SUBMITTED);
		$this->mapper->expects($this->never())->method('update');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Erlass');
		$this->service()->cancel(1, 'Doppelt angelegt');
	}

	public function testCancelIstInFreigegebenemLaufGesperrtBisDerLaufVerworfenIst(): void {
		// Die Datei enthält die Forderung schon; ein Storno ließe die Zeile dort stehen.
		$this->mapper->method('find')->willReturn($this->openClaim());
		$this->debitItems->method('findLiveBatchStatusByOpenItem')->with(1)->willReturn(DebitBatch::STATUS_RELEASED);
		$this->mapper->expects($this->never())->method('update');
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Verwerfen Sie zuerst den Lauf');
		$this->service()->cancel(1, 'Doppelt angelegt');
	}

	public function testUndeferHebtLaufendeStundungAufUndProtokolliertSie(): void {
		$item = $this->openClaim();
		$item->setDeferredUntil('2026-09-01');
		$item->setDeferredReason('Kurzfristige Notlage');
		$item->setDeferredBy('kassenwart');
		$item->setDeferredAt('2026-06-01T10:00:00+00:00');
		$this->mapper->method('find')->willReturn($item);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->audit->expects($this->once())->method('log')->with(
			'Stundung einer Forderung aufgehoben',
			'open_item',
			1,
			$this->callback(static fn (array $details): bool => $details['bis'] === '2026-09-01' && $details['begruendung'] === 'Kurzfristige Notlage'),
		);

		$updated = $this->service('2026-06-15')->undefer(1);

		$this->assertNull($updated->getDeferredUntil());
		$this->assertNull($updated->getDeferredReason());
		$this->assertNull($updated->getDeferredBy());
		$this->assertNull($updated->getDeferredAt());
	}

	public function testUndeferErlaubtDanachEineNeueStundung(): void {
		$item = $this->openClaim();
		$item->setDeferredUntil('2026-09-01');
		$this->mapper->method('find')->willReturn($item);
		$this->mapper->method('update')->willReturnArgument(0);

		$service = $this->service('2026-06-15');
		$service->undefer(1);
		$again = $service->defer(1, '2026-12-01', 'Zweite Stundung', 'kassenwart');

		$this->assertSame('2026-12-01', $again->getDeferredUntil());
	}

	public function testUndeferOhneLaufendeStundungSchlaegtFehl(): void {
		$expired = $this->openClaim();
		$expired->setDeferredUntil('2026-01-01'); // bereits abgelaufen
		$this->mapper->method('find')->willReturn($expired);
		$this->expectException(\InvalidArgumentException::class);
		$this->service('2026-06-15')->undefer(1);
	}

	public function testUndeferNichtGestundeterForderungSchlaegtFehl(): void {
		$this->mapper->method('find')->willReturn($this->openClaim());
		$this->expectException(\InvalidArgumentException::class);
		$this->service('2026-06-15')->undefer(1);
	}

	public function testDeferVerlangtDatumInDerZukunft(): void {
		$this->mapper->method('find')->willReturn($this->openClaim());
		$this->expectException(\InvalidArgumentException::class);
		$this->service('2026-06-15')->defer(1, '2026-01-01', 'Kurzfristige Notlage', 'kassenwart');
	}

	public function testDeferSetztStundungsfelder(): void {
		$this->mapper->method('find')->willReturn($this->openClaim());
		$this->mapper->method('update')->willReturnArgument(0);
		$item = $this->service('2026-06-15')->defer(1, '2026-08-01', 'Kurzfristige Notlage', 'kassenwart');
		$this->assertSame('2026-08-01', $item->getDeferredUntil());
		$this->assertSame('kassenwart', $item->getDeferredBy());
	}

	public function testDeferLehntZweiteAktiveStundungAb(): void {
		$item = $this->openClaim();
		$item->setDeferredUntil('2026-09-01');
		$this->mapper->method('find')->willReturn($item);
		$this->expectException(\InvalidArgumentException::class);
		$this->service('2026-06-15')->defer(1, '2026-10-01', 'Noch eine Notlage', 'kassenwart');
	}

	public function testDeferErlaubtNeueStundungNachAblaufDerAlten(): void {
		$item = $this->openClaim();
		$item->setDeferredUntil('2026-01-01'); // bereits abgelaufen
		$this->mapper->method('find')->willReturn($item);
		$this->mapper->method('update')->willReturnArgument(0);
		$updated = $this->service('2026-06-15')->defer(1, '2026-08-01', 'Neue Notlage', 'kassenwart');
		$this->assertSame('2026-08-01', $updated->getDeferredUntil());
	}

	public function testFindLehntNichtClaimsAb(): void {
		$plain = new OpenItem();
		$plain->setId(5);
		$this->mapper->method('find')->willReturn($plain);
		$this->expectException(DoesNotExistException::class);
		$this->service()->find(5);
	}

	public function testListMaskedBlendetMitgliedsdatenAusUndZeigtNurAnzeigename(): void {
		$item = $this->openClaim();
		$this->mapper->method('findClaims')->willReturn([$item]);
		$this->memberMapper->method('displayNameOr')->willReturn('Anna Musterfrau');

		$rows = $this->service()->listMasked();

		$this->assertCount(1, $rows);
		$this->assertSame('Anna Musterfrau', $rows[0]['memberDisplayName']);
		$this->assertArrayNotHasKey('email', $rows[0]);
	}
}
