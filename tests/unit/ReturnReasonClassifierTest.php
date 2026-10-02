<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Task;
use OCA\Vereinsbuchhaltung\Service\Sepa\ReturnReasonClassifier;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Rückgabe-Klassen-Ableitung (Spec §3.6 Tabelle, Issue #73) – deckt jeden in
 * der Spec genannten Beispiel-Code ab, damit eine künftige Umsortierung der
 * Tabelle hier sofort auffällt.
 */
class ReturnReasonClassifierTest extends TestCase {

	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return $l10n;
	}

	/** @return array<string, array{0:string,1:string}> Code => erwartete Klasse */
	public static function reasonCodeProvider(): array {
		return [
			// insufficient_funds
			'AM04' => ['AM04', ReturnReasonClassifier::CLASS_INSUFFICIENT_FUNDS],
			'MS03' => ['MS03', ReturnReasonClassifier::CLASS_INSUFFICIENT_FUNDS],
			// account_unusable
			'AC01' => ['AC01', ReturnReasonClassifier::CLASS_ACCOUNT_UNUSABLE],
			'AC04' => ['AC04', ReturnReasonClassifier::CLASS_ACCOUNT_UNUSABLE],
			'AC06' => ['AC06', ReturnReasonClassifier::CLASS_ACCOUNT_UNUSABLE],
			'AC13' => ['AC13', ReturnReasonClassifier::CLASS_ACCOUNT_UNUSABLE],
			'AG01' => ['AG01', ReturnReasonClassifier::CLASS_ACCOUNT_UNUSABLE],
			'RC01' => ['RC01', ReturnReasonClassifier::CLASS_ACCOUNT_UNUSABLE],
			'BE05' => ['BE05', ReturnReasonClassifier::CLASS_ACCOUNT_UNUSABLE],
			// disputed
			'MD01' => ['MD01', ReturnReasonClassifier::CLASS_DISPUTED],
			'MD06' => ['MD06', ReturnReasonClassifier::CLASS_DISPUTED],
			'MS02' => ['MS02', ReturnReasonClassifier::CLASS_DISPUTED],
			'SL01' => ['SL01', ReturnReasonClassifier::CLASS_DISPUTED],
			// deceased
			'MD07' => ['MD07', ReturnReasonClassifier::CLASS_DECEASED],
			// technical
			'AM05' => ['AM05', ReturnReasonClassifier::CLASS_TECHNICAL],
			'AG02' => ['AG02', ReturnReasonClassifier::CLASS_TECHNICAL],
			'FF01' => ['FF01', ReturnReasonClassifier::CLASS_TECHNICAL],
			'FF05' => ['FF05', ReturnReasonClassifier::CLASS_TECHNICAL],
			'TM01' => ['TM01', ReturnReasonClassifier::CLASS_TECHNICAL],
			'DT01' => ['DT01', ReturnReasonClassifier::CLASS_TECHNICAL],
			'RR01' => ['RR01', ReturnReasonClassifier::CLASS_TECHNICAL],
			'RR02' => ['RR02', ReturnReasonClassifier::CLASS_TECHNICAL],
			'RR03' => ['RR03', ReturnReasonClassifier::CLASS_TECHNICAL],
			'RR04' => ['RR04', ReturnReasonClassifier::CLASS_TECHNICAL],
			'FOCR' => ['FOCR', ReturnReasonClassifier::CLASS_TECHNICAL],
			'CNOR' => ['CNOR', ReturnReasonClassifier::CLASS_TECHNICAL],
			'DNOR' => ['DNOR', ReturnReasonClassifier::CLASS_TECHNICAL],
			// unknown - Rest, Beispiele fuer nicht in der Spec-Tabelle genannte Codes
			'AC02 (nicht in Spec-Tabelle -> unknown)' => ['AC02', ReturnReasonClassifier::CLASS_UNKNOWN],
			'MD02 (nicht in Spec-Tabelle -> unknown)' => ['MD02', ReturnReasonClassifier::CLASS_UNKNOWN],
			'kleinschreibung wird normalisiert' => ['am04', ReturnReasonClassifier::CLASS_INSUFFICIENT_FUNDS],
		];
	}

	/** @dataProvider reasonCodeProvider */
	public function testKlassifiziertJedenCodeAusDerSpecTabelle(string $code, string $expectedClass): void {
		$this->assertSame($expectedClass, ReturnReasonClassifier::classify($code));
	}

	public function testFehlenderCodeLandetBeiUnknown(): void {
		$this->assertSame(ReturnReasonClassifier::CLASS_UNKNOWN, ReturnReasonClassifier::classify(null));
		$this->assertSame(ReturnReasonClassifier::CLASS_UNKNOWN, ReturnReasonClassifier::classify(''));
		$this->assertSame(ReturnReasonClassifier::CLASS_UNKNOWN, ReturnReasonClassifier::classify('   '));
	}

	// --- Mandat-Wirkung: "kein Code beendet je automatisch ein Mandat" ------------------

	public function testAutoSperreNurBeiAccountUnusableDisputedDeceased(): void {
		$this->assertFalse(ReturnReasonClassifier::shouldSuspendMandate(ReturnReasonClassifier::CLASS_INSUFFICIENT_FUNDS));
		$this->assertTrue(ReturnReasonClassifier::shouldSuspendMandate(ReturnReasonClassifier::CLASS_ACCOUNT_UNUSABLE));
		$this->assertTrue(ReturnReasonClassifier::shouldSuspendMandate(ReturnReasonClassifier::CLASS_DISPUTED));
		$this->assertTrue(ReturnReasonClassifier::shouldSuspendMandate(ReturnReasonClassifier::CLASS_DECEASED));
		$this->assertFalse(ReturnReasonClassifier::shouldSuspendMandate(ReturnReasonClassifier::CLASS_TECHNICAL));
		$this->assertFalse(ReturnReasonClassifier::shouldSuspendMandate(ReturnReasonClassifier::CLASS_UNKNOWN));
	}

	// --- Zahlungsaufforderung (Mahnstufe 0) ------------------------------------------

	public function testZahlungsaufforderungSofortNurBeiDenDreiZahlungsbezogenenKlassen(): void {
		$this->assertTrue(ReturnReasonClassifier::shouldTriggerPaymentRequest(ReturnReasonClassifier::CLASS_INSUFFICIENT_FUNDS));
		$this->assertTrue(ReturnReasonClassifier::shouldTriggerPaymentRequest(ReturnReasonClassifier::CLASS_ACCOUNT_UNUSABLE));
		$this->assertTrue(ReturnReasonClassifier::shouldTriggerPaymentRequest(ReturnReasonClassifier::CLASS_DISPUTED));
		$this->assertFalse(ReturnReasonClassifier::shouldTriggerPaymentRequest(ReturnReasonClassifier::CLASS_DECEASED));
		$this->assertFalse(ReturnReasonClassifier::shouldTriggerPaymentRequest(ReturnReasonClassifier::CLASS_TECHNICAL));
		$this->assertFalse(ReturnReasonClassifier::shouldTriggerPaymentRequest(ReturnReasonClassifier::CLASS_UNKNOWN));
	}

	// --- Gebühren-Weiterbelastung (Verfeinerung von #72) -----------------------------

	public function testGebuehrenWeiterbelastungNurBeiInsufficientFundsUndAccountUnusable(): void {
		$this->assertTrue(ReturnReasonClassifier::shouldRechargeFeeAutomatically(ReturnReasonClassifier::CLASS_INSUFFICIENT_FUNDS));
		$this->assertTrue(ReturnReasonClassifier::shouldRechargeFeeAutomatically(ReturnReasonClassifier::CLASS_ACCOUNT_UNUSABLE));
		$this->assertFalse(ReturnReasonClassifier::shouldRechargeFeeAutomatically(ReturnReasonClassifier::CLASS_DISPUTED));
		$this->assertFalse(ReturnReasonClassifier::shouldRechargeFeeAutomatically(ReturnReasonClassifier::CLASS_DECEASED));
		$this->assertFalse(ReturnReasonClassifier::shouldRechargeFeeAutomatically(ReturnReasonClassifier::CLASS_TECHNICAL));
		$this->assertFalse(ReturnReasonClassifier::shouldRechargeFeeAutomatically(ReturnReasonClassifier::CLASS_UNKNOWN));
	}

	// --- Aufgaben-Schweregrad ---------------------------------------------------------

	public function testAufgabenSchweregradNurHinweisBeiInsufficientFunds(): void {
		$this->assertSame(Task::SEVERITY_HINT, ReturnReasonClassifier::taskSeverity(ReturnReasonClassifier::CLASS_INSUFFICIENT_FUNDS));
		foreach ([ReturnReasonClassifier::CLASS_ACCOUNT_UNUSABLE, ReturnReasonClassifier::CLASS_DISPUTED, ReturnReasonClassifier::CLASS_DECEASED, ReturnReasonClassifier::CLASS_TECHNICAL, ReturnReasonClassifier::CLASS_UNKNOWN] as $class) {
			$this->assertSame(Task::SEVERITY_ACTION_REQUIRED, ReturnReasonClassifier::taskSeverity($class), "Klasse $class sollte dringend sein");
		}
	}

	// --- Mitglieder-Klartexte: nie ein roher Code im Text -----------------------------

	public function testMitgliederKlartexteEnthaltenNieEinenRohenCode(): void {
		$l10n = $this->l10n();
		foreach (ReturnReasonClassifier::CLASSES as $class) {
			$text = ReturnReasonClassifier::memberFacingReason($class, $l10n);
			$this->assertNotSame('', trim($text));
			foreach (['AM04', 'AC01', 'MD01', 'MD07', 'AG02'] as $code) {
				$this->assertStringNotContainsString($code, $text, "Text für Klasse $class darf keinen rohen Code enthalten");
			}
		}
	}
}
