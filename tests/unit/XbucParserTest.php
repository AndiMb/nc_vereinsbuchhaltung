<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\XbucParser;
use PHPUnit\Framework\TestCase;

/**
 * Beträge im xbuc-Import (Review-Nachtrag I-1): ein leerer Betrag bleibt still
 * draußen, ein angegebener, aber unlesbarer Betrag bricht den Import mit der
 * Nummer der Buchung ab, statt einen Buchungssatz still zu verlieren.
 */
class XbucParserTest extends TestCase {

	private function xbuc(string ...$amounts): string {
		$bookings = '';
		foreach ($amounts as $i => $amount) {
			$id = $i + 1;
			$bookings .= '<Buchung ID="' . $id . '" Datum="15.01.2026"><Betrag_als_Zahl>' . $amount
				. '</Betrag_als_Zahl><Buchungstext>Beitrag ' . $id . '</Buchungstext><Soll>1200</Soll><Haben>4000</Haben></Buchung>';
		}
		return '<?xml version="1.0" encoding="UTF-8"?><zero><Projekt><Ordner_Konten>'
			. '<Konto><ID>1200</ID><Bezeichnung>Bank</Bezeichnung></Konto>'
			. '<Konto><ID>4000</ID><Bezeichnung>Beiträge</Bezeichnung></Konto>'
			. '</Ordner_Konten><Ordner_Buchungen><Buchungsgruppe>' . $bookings
			. '</Buchungsgruppe></Ordner_Buchungen></Projekt></zero>';
	}

	public function testLeererBetragBleibtStillDraussen(): void {
		$parsed = (new XbucParser())->parse($this->xbuc('12,00', '', '60,'));

		$this->assertSame([1200, 6000], array_column($parsed['bookings'], 'amountCents'));
	}

	public function testUnlesbarerBetragBrichtMitBuchungsnummerAb(): void {
		$this->expectException(UserFacingException::class);
		$this->expectExceptionMessage('Buchung 2 der XBUC-Datei: Der Betrag „ca. 12,50“ ist nicht lesbar.');

		(new XbucParser())->parse($this->xbuc('12,00', 'ca. 12,50'));
	}
}
