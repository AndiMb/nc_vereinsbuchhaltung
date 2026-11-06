<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\Statement\Camt053Parser;
use OCA\Vereinsbuchhaltung\Service\Text\SafeXml;
use OCA\Vereinsbuchhaltung\Service\XbucParser;
use PHPUnit\Framework\TestCase;

/**
 * XML aus fremder Hand: Dokumente mit DOCTYPE werden abgelehnt, bevor ein
 * Knoten gelesen wird. Interne Entitäten expandieren sonst beim String-Cast
 * außerhalb von PHPs memory_limit.
 */
class SafeXmlTest extends TestCase {

	/** Eine Entität, oft verwiesen: unterhalb von libxmls Schleifen-Heuristik, wächst beim Lesen trotzdem. */
	private static function bomb(string $root, string $body): string {
		return '<!DOCTYPE ' . $root . ' [<!ENTITY a "' . str_repeat('a', 10000) . '">]>'
			. str_replace('&c;', str_repeat('&a;', 100), $body);
	}

	public function testLiestGewoehnlichesXml(): void {
		$xml = SafeXml::load('<?xml version="1.0" encoding="UTF-8"?><r xmlns="urn:x"><v>Müller</v></r>', 0, $error);

		$this->assertNotNull($xml);
		$this->assertNull($error);
		$xml->registerXPathNamespace('x', 'urn:x');
		$this->assertSame('Müller', (string)$xml->xpath('//x:v')[0]);
	}

	public function testLehntDoctypeMitEntitaetenAb(): void {
		$this->assertNull(SafeXml::load('<?xml version="1.0"?>' . self::bomb('r', '<r>&c;</r>'), 0, $error));
		$this->assertSame(SafeXml::DOCTYPE_ERROR, $error);
	}

	public function testLehntAuchEinenLeerenDoctypeAb(): void {
		$this->assertNull(SafeXml::load('<!DOCTYPE r><r/>', 0, $error));
		$this->assertSame(SafeXml::DOCTYPE_ERROR, $error);
	}

	/** Eine Textsuche nach „<!DOCTYPE“ fände die Deklaration in UTF-16 nicht. */
	public function testLehntDoctypeInUtf16Ab(): void {
		$utf16 = "\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE r [<!ENTITY a "x">]><r>&a;</r>', 'UTF-16LE', 'UTF-8');

		$this->assertFalse(str_contains($utf16, '<!DOCTYPE'));
		$this->assertNull(SafeXml::load($utf16, 0, $error));
		$this->assertSame(SafeXml::DOCTYPE_ERROR, $error);
	}

	public function testLiestUtf16OhneDoctype(): void {
		$utf16 = "\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><r><v>Bon</v></r>', 'UTF-16LE', 'UTF-8');

		$xml = SafeXml::load($utf16);

		$this->assertNotNull($xml);
		$this->assertSame('Bon', (string)$xml->v);
	}

	/** libxml 2.9 braucht für einen Tag mit sehr vielen Attributen überlinear viel Zeit. */
	public function testLehntEinenTagMitAttributflutAb(): void {
		$attrs = static fn (string $value): string => implode(' ', array_map(static fn (int $i): string => 'a' . $i . '="' . $value . '"', range(1, SafeXml::MAX_ATTRIBUTES)));

		$this->assertNull(SafeXml::load('<r ' . $attrs('1') . '/>', 0, $error));
		$this->assertSame(SafeXml::ATTRIBUTES_ERROR, $error);
		// „>“ im Attributwert darf die Prüfung nicht täuschen.
		$this->assertNull(SafeXml::load('<r ' . $attrs('>') . '/>', 0, $error));
		$this->assertSame(SafeXml::ATTRIBUTES_ERROR, $error);
		// Auch in UTF-16, das für die Byte-Prüfung gewandelt wird.
		$this->assertNull(SafeXml::load("\xFF\xFE" . mb_convert_encoding('<r ' . $attrs('1') . '/>', 'UTF-16LE', 'UTF-8'), 0, $error));
		$this->assertSame(SafeXml::ATTRIBUTES_ERROR, $error);
		$this->assertNotNull(SafeXml::load('<r ' . implode(' ', array_map(static fn (int $i): string => 'a' . $i . '="1"', range(1, SafeXml::MAX_ATTRIBUTES - 1))) . '/>'));
	}

	/** Die Byte-Prüfung gilt nur für ASCII-kompatible Kodierungen; andere nimmt libxml sonst per Autoerkennung oder Deklaration. */
	public function testLehntFremdeKodierungenAb(): void {
		$this->assertNull(SafeXml::load('<?xml version="1.0"encoding="UTF-7"?><r/>', 0, $error));
		$this->assertSame(SafeXml::ENCODING_ERROR, $error);
		// „<r/>“ in EBCDIC (IBM037): libxml erkennt die Kodierung an den ersten Bytes.
		$this->assertNull(SafeXml::load("\x4C\x99\x61\x6E", 0, $error));
		$this->assertSame('Document does not start with <', $error);
		$this->assertNotNull(SafeXml::load("\xEF\xBB\xBF" . '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n" . '<r/>'));
		$this->assertNotNull(SafeXml::load('<?xml version="1.0" encoding="ISO-8859-1"?><r>' . "\xE4" . '</r>'));
	}

	public function testMeldetKaputtesUndLeeresXml(): void {
		$this->assertNull(SafeXml::load('<r>', 0, $error));
		$this->assertNotNull($error);
		$this->assertNull(SafeXml::load("  \n", 0, $error));
		$this->assertNotNull($error);
	}

	public function testXbucMitDoctypeWirdAbgelehnt(): void {
		$this->expectException(UserFacingException::class);
		(new XbucParser())->parse(self::bomb('Daten', '<Daten><Projekt><Bezeichnung>&c;</Bezeichnung></Projekt></Daten>'));
	}

	public function testCamtMitDoctypeWirdAbgelehnt(): void {
		$this->expectException(UserFacingException::class);
		$this->expectExceptionMessage(SafeXml::DOCTYPE_ERROR);
		(new Camt053Parser())->parse('<!-- camt.053 -->' . self::bomb('Document', '<Document><BkToCstmrStmt><Stmt><Ntry><AddtlNtryInf>&c;</AddtlNtryInf></Ntry></Stmt></BkToCstmrStmt></Document>'));
	}
}
