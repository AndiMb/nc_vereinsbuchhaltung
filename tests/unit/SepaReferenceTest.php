<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Sepa\SepaReference;
use OCA\Vereinsbuchhaltung\Service\Sepa\SepaText;
use PHPUnit\Framework\TestCase;

/**
 * Diese Prüfungen halten die beiden Enden zusammen: was erzeugt wird, muss die
 * Rücklastschrift-Erkennung im Verwendungszweck wiederfinden. Ändert jemand
 * ein Format, ohne das Muster mitzuziehen, bricht die Erkennung sonst *still* –
 * kein Fehler, nur ab sofort keine Treffer mehr.
 */
class SepaReferenceTest extends TestCase {

	public function testErzeugteMandatsreferenzWirdWiedergefunden(): void {
		$referenz = SepaReference::mandate();
		$this->assertSame($referenz, SepaReference::findMandate("RUECKLASTSCHRIFT MD01 $referenz Konto"));
	}

	public function testErzeugteEndToEndIdWirdWiedergefunden(): void {
		$id = SepaReference::endToEnd();
		$this->assertSame($id, SepaReference::findEndToEnd("Retoure $id"));
	}

	/**
	 * Alle Referenzen wandern in Felder vom Typ Max35Text.
	 */
	public function testAlleReferenzenPassenInDieFeldlaenge(): void {
		$this->assertLessThanOrEqual(SepaText::MAX_ID, strlen(SepaReference::mandate()));
		$this->assertLessThanOrEqual(SepaText::MAX_ID, strlen(SepaReference::message()));
		// Die PmtInfId hängt an die MsgId noch "-RCUR" an.
		$this->assertLessThanOrEqual(SepaText::MAX_ID - 5, strlen(SepaReference::message()));
		$this->assertLessThanOrEqual(SepaText::MAX_ID, strlen(SepaReference::endToEnd()));
	}

	/**
	 * Die Referenzen stehen in SEPA-Feldern und auf dem Kontoauszug des
	 * Zahlers – nur Zeichen aus dem erlaubten Satz.
	 */
	public function testReferenzenNutzenNurErlaubteZeichen(): void {
		foreach ([SepaReference::mandate(), SepaReference::message(), SepaReference::endToEnd()] as $referenz) {
			$this->assertSame($referenz, SepaText::convert($referenz, SepaText::MAX_ID));
		}
	}

	public function testReferenzenSindEindeutig(): void {
		$erzeugt = [];
		for ($i = 0; $i < 50; $i++) {
			$erzeugt[] = SepaReference::mandate();
			$erzeugt[] = SepaReference::endToEnd();
		}
		$this->assertCount(count($erzeugt), array_unique($erzeugt));
	}

	public function testOhneReferenzKeinTreffer(): void {
		$this->assertNull(SepaReference::findMandate('Miete September 2026'));
		$this->assertNull(SepaReference::findEndToEnd('Miete September 2026'));
	}

	/**
	 * Eine Mandatsreferenz ist keine End-to-End-ID und umgekehrt – sonst
	 * ordnete die Erkennung die Rückbuchung der falschen Stufe zu.
	 */
	public function testDieMusterVerwechselnSichNicht(): void {
		$this->assertNull(SepaReference::findEndToEnd(SepaReference::mandate()));
		$this->assertNull(SepaReference::findMandate(SepaReference::endToEnd()));
	}
}
