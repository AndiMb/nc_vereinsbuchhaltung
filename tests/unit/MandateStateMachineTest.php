<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Service\MandateStateMachine;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Zustandsmaschine des Mandats (Spec §2.2, Issue #66) – jede assert*()-
 * Methode ist reine Entscheidungslogik ohne Datenbankzugriff, siehe
 * {@see MandateStateMachine}.
 */
class MandateStateMachineTest extends TestCase {

	private function machine(): MandateStateMachine {
		return new MandateStateMachine($this->createMock(IL10N::class));
	}

	private function mandate(string $status, string $signatureType = Mandate::SIGNATURE_PAPER, ?string $signedAt = null): Mandate {
		$m = new Mandate();
		$m->setStatus($status);
		$m->setSignatureType($signatureType);
		$m->setSignedAt($signedAt);
		return $m;
	}

	// --- Aktivierung (Papier) ---------------------------------------------------

	public function testEntwurfMitUnterschriftLaesstSichAktivieren(): void {
		$this->machine()->assertCanActivatePaper($this->mandate(Mandate::STATUS_DRAFT, signedAt: '2026-01-15'));
		$this->addToAssertionCount(1); // kein Throw = bestanden
	}

	public function testEntwurfOhneUnterschriftLaesstSichNichtAktivieren(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanActivatePaper($this->mandate(Mandate::STATUS_DRAFT, signedAt: null));
	}

	public function testBereitsAktivesMandatLaesstSichNichtErneutAktivieren(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanActivatePaper($this->mandate(Mandate::STATUS_ACTIVE, signedAt: '2026-01-15'));
	}

	public function testElektronischesMandatLaesstSichNichtManuellAktivieren(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanActivatePaper($this->mandate(Mandate::STATUS_DRAFT, Mandate::SIGNATURE_ELECTRONIC, '2026-01-15'));
	}

	// --- Aktivierung (elektronisch, Issue #67) ----------------------------------

	public function testElektronischerEntwurfLaesstSichOhneUnterschriftsdatumAktivieren(): void {
		// Kein signed_at erforderlich - die Zustimmung selbst IST die Unterschrift.
		$this->machine()->assertCanActivateElectronic($this->mandate(Mandate::STATUS_DRAFT, Mandate::SIGNATURE_ELECTRONIC, null));
		$this->addToAssertionCount(1);
	}

	public function testPapierMandatLaesstSichNichtElektronischAktivieren(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanActivateElectronic($this->mandate(Mandate::STATUS_DRAFT, Mandate::SIGNATURE_PAPER, '2026-01-15'));
	}

	public function testBereitsAktivesElektronischesMandatLaesstSichNichtErneutAktivieren(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanActivateElectronic($this->mandate(Mandate::STATUS_ACTIVE, Mandate::SIGNATURE_ELECTRONIC, '2026-01-15'));
	}

	// --- Sperren/Entsperren -----------------------------------------------------

	public function testAktivesMandatLaesstSichSperren(): void {
		$this->machine()->assertCanSuspend($this->mandate(Mandate::STATUS_ACTIVE));
		$this->addToAssertionCount(1);
	}

	public function testEntwurfLaesstSichNichtSperren(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanSuspend($this->mandate(Mandate::STATUS_DRAFT));
	}

	public function testBereitsAusgesetztesMandatLaesstSichNichtErneutSperren(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanSuspend($this->mandate(Mandate::STATUS_SUSPENDED));
	}

	public function testAusgesetztesMandatLaesstSichEntsperren(): void {
		$this->machine()->assertCanResume($this->mandate(Mandate::STATUS_SUSPENDED));
		$this->addToAssertionCount(1);
	}

	public function testAktivesMandatLaesstSichNichtEntsperren(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanResume($this->mandate(Mandate::STATUS_ACTIVE));
	}

	// --- Widerruf: terminal, nie reaktivierbar ----------------------------------

	/** @dataProvider widerrufbareZustaende */
	public function testAktivUndAusgesetztLassenSichWiderrufen(string $status): void {
		$this->machine()->assertCanRevoke($this->mandate($status));
		$this->addToAssertionCount(1);
	}

	public static function widerrufbareZustaende(): array {
		return [
			'aktiv' => [Mandate::STATUS_ACTIVE],
			'ausgesetzt' => [Mandate::STATUS_SUSPENDED],
		];
	}

	public function testEntwurfLaesstSichNichtWiderrufen(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanRevoke($this->mandate(Mandate::STATUS_DRAFT));
	}

	public function testErloschenesMandatLaesstSichNichtErneutWiderrufen(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanRevoke($this->mandate(Mandate::STATUS_ENDED));
	}

	// --- Entwurf verwerfen/korrigieren (Issue #118): nur im Zustand entwurf -----

	/** @dataProvider entwurfsSignaturen */
	public function testEntwurfLaesstSichVerwerfenUndKorrigieren(string $signatureType): void {
		$draft = $this->mandate(Mandate::STATUS_DRAFT, $signatureType);

		$this->machine()->assertCanDiscardDraft($draft);
		$this->machine()->assertCanCorrectDraft($draft);
		$this->addToAssertionCount(2);
	}

	public static function entwurfsSignaturen(): array {
		return [
			'Papier' => [Mandate::SIGNATURE_PAPER],
			'elektronisch' => [Mandate::SIGNATURE_ELECTRONIC],
		];
	}

	/** @dataProvider nichtEntwurfsZustaende */
	public function testNurEinEntwurfLaesstSichVerwerfen(string $status): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanDiscardDraft($this->mandate($status));
	}

	/** @dataProvider nichtEntwurfsZustaende */
	public function testNurEinEntwurfLaesstSichAnAmendmentVorbeiKorrigieren(string $status): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanCorrectDraft($this->mandate($status));
	}

	public static function nichtEntwurfsZustaende(): array {
		return [
			'aktiv' => [Mandate::STATUS_ACTIVE],
			'ausgesetzt' => [Mandate::STATUS_SUSPENDED],
			'erloschen' => [Mandate::STATUS_ENDED],
		];
	}

	/**
	 * Verwerfen und Widerruf schließen einander aus: was widerrufbar ist (aktiv,
	 * ausgesetzt), ist nicht verwerfbar - und umgekehrt der Entwurf.
	 */
	public function testVerwerfenUndWiderrufBedienenVerschiedeneZustaende(): void {
		foreach ([Mandate::STATUS_DRAFT, Mandate::STATUS_ACTIVE, Mandate::STATUS_SUSPENDED, Mandate::STATUS_ENDED] as $status) {
			$mandate = $this->mandate($status);
			$canDiscard = $this->passes(fn () => $this->machine()->assertCanDiscardDraft($mandate));
			$canRevoke = $this->passes(fn () => $this->machine()->assertCanRevoke($mandate));
			$this->assertFalse($canDiscard && $canRevoke, "Zustand $status darf nicht beides erlauben");
		}
		// Und jeder lebende Zustand hat einen Ausweg: verwerfen oder widerrufen.
		foreach ([Mandate::STATUS_DRAFT, Mandate::STATUS_ACTIVE, Mandate::STATUS_SUSPENDED] as $status) {
			$mandate = $this->mandate($status);
			$this->assertTrue(
				$this->passes(fn () => $this->machine()->assertCanDiscardDraft($mandate)) || $this->passes(fn () => $this->machine()->assertCanRevoke($mandate)),
				"Zustand $status braucht einen Ausweg, damit er kein neues Mandat blockiert",
			);
		}
	}

	private function passes(callable $assertion): bool {
		try {
			$assertion();
			return true;
		} catch (\InvalidArgumentException) {
			return false;
		}
	}

	// --- Höchstens ein lebendes Mandat je Mitglied ------------------------------

	public function testOhneLebendesMandatDarfEinNeuesAngelegtWerden(): void {
		$this->machine()->assertNoLiveMandate([]);
		$this->addToAssertionCount(1);
	}

	public function testMitBestehendemLebendenMandatSchlaegtDieAnlageFehl(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertNoLiveMandate([$this->mandate(Mandate::STATUS_ACTIVE)]);
	}

	// --- Amendment/Ersetzen setzen ein aktives Ausgangsmandat voraus ------------

	public function testAmendmentNurAufAktivemMandat(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanAmend($this->mandate(Mandate::STATUS_DRAFT));
	}

	public function testErsetzenNurVonAktivemMandat(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->machine()->assertCanReplace($this->mandate(Mandate::STATUS_SUSPENDED));
	}
}
