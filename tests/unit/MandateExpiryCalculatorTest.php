<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Service\MandateExpiryCalculator;
use PHPUnit\Framework\TestCase;

/**
 * 36-Monats-Verfall (Spec §2.2/§8, Issue #66): `expires_at = COALESCE(
 * last_presented_due_date, signed_at) + 36 Monate`, siehe
 * {@see MandateExpiryCalculator}.
 */
class MandateExpiryCalculatorTest extends TestCase {

	private function calculator(): MandateExpiryCalculator {
		return new MandateExpiryCalculator();
	}

	private function mandate(string $status, ?string $signedAt = null, ?string $lastPresentedDueDate = null): Mandate {
		$m = new Mandate();
		$m->setStatus($status);
		$m->setSignedAt($signedAt);
		$m->setLastPresentedDueDate($lastPresentedDueDate);
		return $m;
	}

	public function testFristBerechnetSichAbUnterschriftsdatumOhneVorlage(): void {
		$mandate = $this->mandate(Mandate::STATUS_ACTIVE, signedAt: '2026-01-15');
		$expiresAt = $this->calculator()->expiresAt($mandate);
		$this->assertSame('2029-01-15', $expiresAt?->format('Y-m-d'));
	}

	/** COALESCE: die letzte vorgelegte Fälligkeit hat Vorrang vor dem Unterschriftsdatum. */
	public function testLetzteVorlageHatVorrangVorDemUnterschriftsdatum(): void {
		$mandate = $this->mandate(Mandate::STATUS_ACTIVE, signedAt: '2020-01-01', lastPresentedDueDate: '2026-03-01');
		$expiresAt = $this->calculator()->expiresAt($mandate);
		$this->assertSame('2029-03-01', $expiresAt?->format('Y-m-d'));
	}

	public function testOhneJeglichesDatumGibtEsKeineFrist(): void {
		$mandate = $this->mandate(Mandate::STATUS_DRAFT);
		$this->assertNull($this->calculator()->expiresAt($mandate));
	}

	public function testAktivesMandatMitAbgelaufenerFristIstVerfallsfaellig(): void {
		$mandate = $this->mandate(Mandate::STATUS_ACTIVE, signedAt: '2023-01-01');
		$this->assertTrue($this->calculator()->isDueForExpiry($mandate, new \DateTimeImmutable('2026-06-01')));
	}

	public function testAktivesMandatVorAblaufDerFristIstNichtVerfallsfaellig(): void {
		$mandate = $this->mandate(Mandate::STATUS_ACTIVE, signedAt: '2025-01-01');
		$this->assertFalse($this->calculator()->isDueForExpiry($mandate, new \DateTimeImmutable('2026-06-01')));
	}

	/** Ausgesetzte Mandate laufen ebenfalls ab – die Sperre pausiert die 36-Monats-Uhr nicht (Spec §2.2). */
	public function testAusgesetztesMandatVerfaelltEbenfalls(): void {
		$mandate = $this->mandate(Mandate::STATUS_SUSPENDED, signedAt: '2023-01-01');
		$this->assertTrue($this->calculator()->isDueForExpiry($mandate, new \DateTimeImmutable('2026-06-01')));
	}

	/** Ein Entwurf wurde nie zum Einzug vorgelegt und ist ohnehin nicht einzugsfähig. */
	public function testEntwurfVerfaelltNicht(): void {
		$mandate = $this->mandate(Mandate::STATUS_DRAFT, signedAt: '2020-01-01');
		$this->assertFalse($this->calculator()->isDueForExpiry($mandate, new \DateTimeImmutable('2026-06-01')));
	}

	/** Ein bereits erloschenes Mandat ist schon terminal, kein zweiter Verfall. */
	public function testErloschenesMandatVerfaelltNichtEinZweitesMal(): void {
		$mandate = $this->mandate(Mandate::STATUS_ENDED, signedAt: '2020-01-01');
		$this->assertFalse($this->calculator()->isDueForExpiry($mandate, new \DateTimeImmutable('2026-06-01')));
	}

	public function testVorwarnungInnerhalbVon180TagenVorAblauf(): void {
		// signed_at + 36 Monate = 2026-08-01, "heute" = 2026-03-01 -> 153 Tage Vorlauf.
		$mandate = $this->mandate(Mandate::STATUS_ACTIVE, signedAt: '2023-08-01');
		$this->assertTrue($this->calculator()->needsExpiryWarning($mandate, new \DateTimeImmutable('2026-03-01')));
	}

	public function testKeineVorwarnungWeitVorAblauf(): void {
		$mandate = $this->mandate(Mandate::STATUS_ACTIVE, signedAt: '2026-01-01');
		$this->assertFalse($this->calculator()->needsExpiryWarning($mandate, new \DateTimeImmutable('2026-06-01')));
	}

	/** Bereits verfallen zählt nicht mehr als "Vorwarnung", sondern als fälliger Verfall selbst. */
	public function testBereitsVerfallenesMandatBrauchtKeineVorwarnungMehr(): void {
		$mandate = $this->mandate(Mandate::STATUS_ACTIVE, signedAt: '2023-01-01');
		$this->assertFalse($this->calculator()->needsExpiryWarning($mandate, new \DateTimeImmutable('2026-06-01')));
	}
}
