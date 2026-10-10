<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Service\MandateSituation;
use PHPUnit\Framework\TestCase;

/**
 * Mandatslage eines Mitglieds (Issue #117) – die Ableitung, auf die sich die
 * Aufgaben-Dienste einigen, damit dasselbe Problem nicht zweimal (oder gar
 * nicht) in der Aufgabenliste steht.
 */
class MandateSituationTest extends TestCase {

	private function mandate(int $memberId, string $status, string $signatureType = Mandate::SIGNATURE_PAPER): Mandate {
		$mandate = new Mandate();
		$mandate->setMemberId($memberId);
		$mandate->setStatus($status);
		$mandate->setSignatureType($signatureType);
		return $mandate;
	}

	public function testOhneMandatIstNone(): void {
		$this->assertSame(MandateSituation::NONE, MandateSituation::of([]));
	}

	/** @return array<string, array{0:string,1:string,2:string}> */
	public static function singleMandateProvider(): array {
		return [
			'aktiv' => [Mandate::STATUS_ACTIVE, Mandate::SIGNATURE_PAPER, MandateSituation::COLLECTIBLE],
			'ausgesetzt' => [Mandate::STATUS_SUSPENDED, Mandate::SIGNATURE_PAPER, MandateSituation::SUSPENDED],
			'Entwurf auf Papier' => [Mandate::STATUS_DRAFT, Mandate::SIGNATURE_PAPER, MandateSituation::DRAFT_PAPER],
			'elektronischer Entwurf' => [Mandate::STATUS_DRAFT, Mandate::SIGNATURE_ELECTRONIC, MandateSituation::DRAFT_ELECTRONIC],
			'erloschen' => [Mandate::STATUS_ENDED, Mandate::SIGNATURE_PAPER, MandateSituation::ENDED],
		];
	}

	/** @dataProvider singleMandateProvider */
	public function testLageEinesEinzelnenMandats(string $status, string $signatureType, string $expected): void {
		$this->assertSame($expected, MandateSituation::of([$this->mandate(1, $status, $signatureType)]));
	}

	public function testNeuesMandatNachErloschenemZaehltNachDemLebenden(): void {
		// Das erloschene Mandat bleibt in der Historie, bestimmt die Lage aber nicht mehr.
		$situation = MandateSituation::of([
			$this->mandate(1, Mandate::STATUS_ENDED),
			$this->mandate(1, Mandate::STATUS_DRAFT),
		]);

		$this->assertSame(MandateSituation::DRAFT_PAPER, $situation);
	}

	public function testBeiDatenfehlerMehrererLebenderMandateGewinntDasBeste(): void {
		// Zwei lebende Mandate darf es nicht geben; kommt es vor, soll das Mitglied
		// nicht als Störfall erscheinen, solange eines davon einzugsfähig ist.
		$situation = MandateSituation::of([
			$this->mandate(1, Mandate::STATUS_SUSPENDED),
			$this->mandate(1, Mandate::STATUS_ACTIVE),
			$this->mandate(1, Mandate::STATUS_DRAFT),
		]);

		$this->assertSame(MandateSituation::COLLECTIBLE, $situation);
	}

	public function testByMemberGruppiertJeMitglied(): void {
		$lagen = MandateSituation::byMember([
			$this->mandate(1, Mandate::STATUS_ACTIVE),
			$this->mandate(2, Mandate::STATUS_ENDED),
			$this->mandate(2, Mandate::STATUS_ENDED),
			$this->mandate(3, Mandate::STATUS_DRAFT),
		]);

		$this->assertSame([
			1 => MandateSituation::COLLECTIBLE,
			2 => MandateSituation::ENDED,
			3 => MandateSituation::DRAFT_PAPER,
		], $lagen);
	}

	public function testByMemberLaesstMitgliederOhneMandatAus(): void {
		$this->assertSame([], MandateSituation::byMember([]));
	}

	/** @return array<string, array{0:string,1:bool}> */
	public static function ownTaskProvider(): array {
		return [
			'gesperrt' => [MandateSituation::SUSPENDED, true],
			'Entwurf auf Papier' => [MandateSituation::DRAFT_PAPER, true],
			'erloschen' => [MandateSituation::ENDED, true],
			// Beim elektronischen Entwurf entscheidet erst der Link, ob eine Aufgabe existiert.
			'elektronischer Entwurf' => [MandateSituation::DRAFT_ELECTRONIC, false],
			'einzugsfähig' => [MandateSituation::COLLECTIBLE, false],
			'nie ein Mandat' => [MandateSituation::NONE, false],
		];
	}

	/** @dataProvider ownTaskProvider */
	public function testNurErklaerteLagenHabenEineEigeneAufgabe(string $situation, bool $expected): void {
		$this->assertSame($expected, MandateSituation::hasOwnTask($situation));
	}
}
