<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Text\EntityDecoder;
use OCA\Vereinsbuchhaltung\Service\XbucParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kodierte Sonderzeichen aus dem xbuc-Import (Issue #112).
 *
 * Gemeldet wurde: Buchungstexte standen nach dem Import als "M&amp;#252;ller"
 * oder "M&üller" in der App. Der Parser dekodierte genau eine Stufe und
 * ergänzte "#252;" zu "&#252;", bevor er dekodierte – bei doppelter
 * Kodierung blieb ein "&" stehen.
 */
class EntityDecoderTest extends TestCase {

	/** @return array<string, array{string, string}> */
	public static function issueFaelle(): array {
		return [
			'Klartext' => ['Müller', 'Müller'],
			'Entität' => ['M&#252;ller', 'Müller'],
			'ohne &' => ['M#252;ller', 'Müller'],
			'doppelt kodiert' => ['M&amp;#252;ller', 'Müller'],
			'doppelt mit &' => ['M&amp;&#35;252;ller', 'Müller'],
			'benannt' => ['M&uuml;ller &amp; S&ouml;hne', 'Müller & Söhne'],
			'Euro' => ['10 &#8364;', '10 €'],
		];
	}

	#[DataProvider('issueFaelle')]
	public function testCleanLoestJedeKodierungstiefeAuf(string $raw, string $expected): void {
		$this->assertSame($expected, EntityDecoder::clean($raw));
	}

	#[DataProvider('issueFaelle')]
	public function testRepairLoestDieGemeldetenFormenEbensoAuf(string $raw, string $expected): void {
		$this->assertSame($expected, EntityDecoder::repair($raw));
	}

	public function testSteuerzeichenReferenzenWerdenLeerzeichen(): void {
		// "#20;" ist der Leerzeichen-Ersatz von zero Buchhaltung.
		$this->assertSame('Beitrag 2026', EntityDecoder::clean('Beitrag#20;2026'));
		$this->assertSame('Beitrag 2026', EntityDecoder::clean('Beitrag&#20;2026'));
		$this->assertSame('a b', EntityDecoder::clean('a&#12;b'));
		$this->assertSame('a b', EntityDecoder::clean('a&#9;b'));
		$this->assertSame('ab', EntityDecoder::clean("a\x07b"));
		$this->assertSame("a\tb", EntityDecoder::clean("a\tb"));
	}

	public function testNormalerTextBleibtUnveraendert(): void {
		foreach (['Müller & Söhne', 'A&B', 'Nr. 5; Halle', '', '100 % Beitrag'] as $text) {
			$this->assertSame($text, EntityDecoder::clean($text));
			$this->assertSame($text, EntityDecoder::repair($text));
			$this->assertFalse(EntityDecoder::needsRepair($text), $text);
		}
	}

	/** Im xbuc-Format ist "#12;" eine Entität, in einem gespeicherten Text womöglich nicht. */
	public function testRepairLaesstKleineZahlenOhneUndStehen(): void {
		$this->assertSame('Rechnung #12;', EntityDecoder::repair('Rechnung #12;'));
		$this->assertFalse(EntityDecoder::needsRepair('Rechnung #12;'));
		$this->assertSame('Rechnung #127; Tor', EntityDecoder::repair('Rechnung #127; Tor'));

		$this->assertTrue(EntityDecoder::needsRepair('M#252;ller'));
		$this->assertTrue(EntityDecoder::needsRepair('Beitrag#20;2026'));
		$this->assertSame('Beitrag 2026', EntityDecoder::repair('Beitrag#20;2026'));
	}

	public function testNeedsRepairErkenntEntitaetenMitUnd(): void {
		$this->assertTrue(EntityDecoder::needsRepair('M&amp;#252;ller'));
		$this->assertTrue(EntityDecoder::needsRepair('Kaffee &amp; Kuchen'));
		$this->assertTrue(EntityDecoder::needsRepair('&#x20AC; 5'));
	}

	public function testDecodeAendertNurEntitaeten(): void {
		$this->assertSame(' Müller ', EntityDecoder::decode(' M&amp;#252;ller '));
	}

	public function testTiefeKodierungEndetOhneEndlosschleife(): void {
		$this->assertSame('ü', EntityDecoder::clean('&amp;amp;amp;amp;#252;'));
		$this->assertSame('Mü', EntityDecoder::clean('M&amp;amp;#252;'));
		$this->assertSame('Mü', EntityDecoder::clean('M&amp;amp;&#35;252;'));
		// Nach acht Runden ist Schluss, der Rest bleibt stehen.
		$this->assertSame('&amp;amp;#252;', EntityDecoder::clean('&' . str_repeat('amp;', 10) . '#252;'));
	}

	/** Der Import selbst: Buchungstext und Kontoname aus einer xbuc-Datei. */
	public function testXbucImportLiestDoppeltKodierteTexte(): void {
		// Im XML steht "&amp;amp;#252;" – SimpleXML liefert daraus "&amp;#252;", die doppelte Kodierung aus dem Issue.
		$xml = <<<'XML'
			<?xml version="1.0" encoding="UTF-8"?>
			<zero><Projekt>
			  <Ordner_Konten>
			    <Konto><ID>1200</ID><Bezeichnung>Bank M&amp;amp;#252;nchen</Bezeichnung></Konto>
			    <Konto><ID>4000</ID><Bezeichnung>Beitr#228;ge</Bezeichnung></Konto>
			  </Ordner_Konten>
			  <Ordner_Buchungen><Buchungsgruppe>
			    <Buchung ID="1" Datum="15.01.2026"><Betrag_als_Zahl>12,00</Betrag_als_Zahl><Buchungstext>Beitrag M&amp;amp;#252;ller#20;2026</Buchungstext><Belegnummer>B&amp;#252;1</Belegnummer><Soll>1200</Soll><Haben>4000</Haben></Buchung>
			  </Buchungsgruppe></Ordner_Buchungen>
			</Projekt></zero>
			XML;
		$parsed = (new XbucParser())->parse($xml);

		$names = array_column($parsed['accounts'], 'name');
		$this->assertContains('Bank München', $names);
		$this->assertContains('Beiträge', $names);
		$this->assertSame('Beitrag Müller 2026', $parsed['bookings'][0]['text']);
		$this->assertSame('Bü1', $parsed['bookings'][0]['docRef']);
	}
}
