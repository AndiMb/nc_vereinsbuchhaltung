<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\DunningNotice;
use OCA\Vereinsbuchhaltung\Db\OpenItem;
use OCA\Vereinsbuchhaltung\Service\DunningStatusResolver;
use PHPUnit\Framework\TestCase;

/**
 * Der lesende Mahnstand einer Forderung (Issue #104) spiegelt die Regeln des
 * täglichen Laufs ({@see \OCA\Vereinsbuchhaltung\Service\DunningLadderService})
 * und der Eskalations-Aufgabe: Mahnabstand ab der zuletzt erreichten Stufe,
 * aktive Stundung pausiert Stufe 1/2 und die Eskalation (nicht Stufe 0),
 * erledigte oder stornierte Forderungen bekommen keine nächste Stufe.
 */
class DunningStatusResolverTest extends TestCase {

	private function claim(?string $deferredUntil = null, string $status = 'open'): OpenItem {
		$item = new OpenItem();
		$item->setId(1);
		$item->setMemberId(1);
		$item->setType(OpenItem::TYPE_CONTRIBUTION);
		$item->setStatus($status);
		$item->setDeferredUntil($deferredUntil);
		return $item;
	}

	private function notice(int $stage, string $sentAt): DunningNotice {
		$n = new DunningNotice();
		$n->setOpenItemId(1);
		$n->setStage($stage);
		$n->setSentAt($sentAt);
		return $n;
	}

	public function testOhneMahnungUndOhneErwartetenTerminKommtNichts(): void {
		$status = DunningStatusResolver::resolve($this->claim(), [], 14, '2026-10-10');

		$this->assertNull($status['stage']);
		$this->assertFalse($status['escalated']);
		$this->assertSame([], $status['notices']);
		$this->assertNull($status['nextStage']);
		$this->assertNull($status['nextDueOn']);
	}

	public function testOhneMahnungMitErwartetemTerminIstDieZahlungsaufforderungDerNaechsteSchritt(): void {
		$status = DunningStatusResolver::resolve($this->claim(), [], 14, '2026-10-10', '2026-10-20');

		$this->assertNull($status['stage']);
		$this->assertSame(DunningNotice::STAGE_PAYMENT_REQUEST, $status['nextStage']);
		$this->assertSame('2026-10-20', $status['nextDueOn']);
	}

	public function testNachDerZahlungsaufforderungKommtDieErinnerungNachDemMahnabstand(): void {
		$status = DunningStatusResolver::resolve($this->claim(), [$this->notice(0, '2026-10-01T08:00:00+00:00')], 14, '2026-10-05');

		$this->assertSame(0, $status['stage']);
		$this->assertSame(DunningNotice::STAGE_REMINDER, $status['nextStage']);
		$this->assertSame('2026-10-15', $status['nextDueOn']);
		$this->assertSame([['stage' => 0, 'sentAt' => '2026-10-01T08:00:00+00:00']], $status['notices']);
	}

	public function testDieHoechsteErreichteStufeZaehltUndDieFristLaeuftAbIhrerVersandzeit(): void {
		$notices = [$this->notice(1, '2026-10-15T08:00:00+00:00'), $this->notice(0, '2026-10-01T08:00:00+00:00')];

		$status = DunningStatusResolver::resolve($this->claim(), $notices, 14, '2026-10-20');

		$this->assertSame(1, $status['stage']);
		$this->assertSame(DunningNotice::STAGE_DUNNING, $status['nextStage']);
		$this->assertSame('2026-10-29', $status['nextDueOn']);
		// Aufsteigend sortiert, egal in welcher Reihenfolge sie kamen.
		$this->assertSame([0, 1], array_column($status['notices'], 'stage'));
	}

	public function testNachDerMahnungKommtDieEskalationAnDenVorstand(): void {
		$status = DunningStatusResolver::resolve($this->claim(), [$this->notice(2, '2026-10-15T08:00:00+00:00')], 14, '2026-10-20');

		$this->assertSame(2, $status['stage']);
		$this->assertFalse($status['escalated']);
		$this->assertSame(DunningStatusResolver::NEXT_ESCALATION, $status['nextStage']);
		$this->assertSame('2026-10-29', $status['nextDueOn']);
	}

	public function testIstDerMahnabstandNachDerMahnungVerstrichenIstSieEskaliert(): void {
		$status = DunningStatusResolver::resolve($this->claim(), [$this->notice(2, '2026-10-15T08:00:00+00:00')], 14, '2026-10-29');

		$this->assertTrue($status['escalated']);
		$this->assertNull($status['nextStage']);
		$this->assertNull($status['nextDueOn']);
	}

	public function testEineAktiveStundungVerschiebtDieNaechsteStufeAufDenTagNachIhremEnde(): void {
		// Frist wäre der 15.10.; die Stundung läuft bis einschließlich 31.10. - der Lauf versendet frühestens am 1.11.
		$status = DunningStatusResolver::resolve($this->claim('2026-10-31'), [$this->notice(0, '2026-10-01T08:00:00+00:00')], 14, '2026-10-20');

		$this->assertSame(1, $status['nextStage']);
		$this->assertSame('2026-11-01', $status['nextDueOn']);
	}

	public function testEineStundungDieVorDerFristEndetVerschiebtNichts(): void {
		$status = DunningStatusResolver::resolve($this->claim('2026-10-10'), [$this->notice(0, '2026-10-01T08:00:00+00:00')], 14, '2026-10-05');

		$this->assertSame('2026-10-15', $status['nextDueOn']);
	}

	public function testEineAbgelaufeneStundungPausiertNichtsMehr(): void {
		$status = DunningStatusResolver::resolve($this->claim('2026-10-03'), [$this->notice(0, '2026-10-01T08:00:00+00:00')], 14, '2026-10-05');

		$this->assertSame('2026-10-15', $status['nextDueOn']);
	}

	public function testEineStundungHaeltDieEskalationAuf(): void {
		// Der Abstand nach der Mahnung wäre verstrichen, aber die Stundung läuft noch: keine Eskalation.
		$status = DunningStatusResolver::resolve($this->claim('2026-11-30'), [$this->notice(2, '2026-10-01T08:00:00+00:00')], 14, '2026-10-20');

		$this->assertFalse($status['escalated']);
		$this->assertSame(DunningStatusResolver::NEXT_ESCALATION, $status['nextStage']);
		$this->assertSame('2026-12-01', $status['nextDueOn']);
	}

	public function testDieZahlungsaufforderungKenntKeineStundungSperre(): void {
		// Spec §3.6: Stufe 0 ist „gatefrei" - der erwartete Termin kommt unverändert durch.
		$status = DunningStatusResolver::resolve($this->claim('2026-12-31'), [], 14, '2026-10-05', '2026-10-08');

		$this->assertSame('2026-10-08', $status['nextDueOn']);
	}

	public function testEineErledigteForderungBehaeltIhrenStandAlsHistorieOhneNaechsteStufe(): void {
		$settled = $this->claim(null, 'paid');
		$settled->setSettledAt('2026-10-04T10:00:00+00:00');

		$status = DunningStatusResolver::resolve($settled, [$this->notice(1, '2026-10-01T08:00:00+00:00')], 14, '2026-10-05', '2026-10-08');

		$this->assertSame(1, $status['stage']);
		$this->assertNull($status['nextStage']);
		$this->assertNull($status['nextDueOn']);
		$this->assertFalse($status['escalated']);
	}

	public function testEineStornierteForderungBekommtKeineNaechsteStufe(): void {
		$cancelled = $this->claim(null, 'cancelled');
		$cancelled->setCancelledAt('2026-10-04T10:00:00+00:00');

		$status = DunningStatusResolver::resolve($cancelled, [$this->notice(2, '2026-09-01T08:00:00+00:00')], 14, '2026-10-20');

		$this->assertNull($status['nextStage']);
		$this->assertFalse($status['escalated']);
	}
}
