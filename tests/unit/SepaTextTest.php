<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Sepa\SepaText;
use PHPUnit\Framework\TestCase;

/**
 * Was hier durchrutscht, weist die Bank zurück – und zwar die ganze Datei,
 * nicht die eine Zeile. Der Fehler fiele erst auf, wenn der Einzug schon
 * eingereicht werden sollte, also im ungünstigsten Moment.
 */
class SepaTextTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function umlaute(): array {
		return [
			'Vereinsname mit Umlauten' => ['Grün-Weiß Förderverein', 'Gruen-Weiss Foerderverein'],
			'grosse Umlaute' => ['ÄÖÜ', 'AeOeUe'],
			'scharfes S' => ['Straße', 'Strasse'],
			'franzoesische Akzente' => ['Café Frédéric', 'Cafe Frederic'],
			'skandinavisch' => ['Håkon Ørsted', 'Hakon Orsted'],
		];
	}

	/**
	 * @dataProvider umlaute
	 */
	public function testUmlauteWerdenAufgeloest(string $eingabe, string $erwartet): void {
		$this->assertSame($erwartet, SepaText::convert($eingabe, SepaText::MAX_NAME));
	}

	/**
	 * Die erlaubten Sonderzeichen müssen erhalten bleiben – sie kommen in
	 * echten Verwendungszwecken vor („Beitrag 1/2026 (Halbjahr)").
	 */
	public function testErlaubteSonderzeichenBleiben(): void {
		$this->assertSame(
			"Beitrag 1/2026 (Halbjahr), Nr. 42-7: o'k +/-",
			SepaText::convert("Beitrag 1/2026 (Halbjahr), Nr. 42-7: o'k +/-", SepaText::MAX_REMITTANCE)
		);
	}

	/**
	 * Das kaufmännische Und ist im SEPA-Zeichensatz nicht erlaubt. Es zu einem
	 * Leerzeichen zu machen zerrisse „Grün & Weiß" – „+" trifft die Bedeutung.
	 */
	public function testUndZeichenWirdZuPlus(): void {
		$this->assertSame('Gruen + Weiss', SepaText::convert('Grün & Weiß', SepaText::MAX_NAME));
	}

	/**
	 * Typografische Zeichen kommen regelmäßig aus Textverarbeitungen mit. Sie
	 * bekommen eine sinnvolle Entsprechung, statt als Leerzeichen zu enden.
	 */
	public function testTypografischeZeichen(): void {
		$this->assertSame('Beitrag Uebungsleiter', SepaText::convert("Beitrag \u{201E}Übungsleiter\u{201C}", SepaText::MAX_REMITTANCE));
		$this->assertSame("Nord-Sued o'clock", SepaText::convert("Nord\u{2013}Süd o\u{2019}clock", SepaText::MAX_NAME));
		$this->assertSame('EUR 12,50', SepaText::convert('€ 12,50', SepaText::MAX_REMITTANCE));
	}

	/**
	 * Unerlaubtes wird zu einem Leerzeichen, nicht ersatzlos gestrichen: sonst
	 * klebten Wörter aneinander. Die dabei entstehenden Doppel- und
	 * Randleerzeichen fallen anschließend weg.
	 */
	public function testUnerlaubtesWirdZuLeerzeichenOhneDoppelungen(): void {
		$this->assertSame('Verein Nord', SepaText::convert('Verein # * ! Nord', SepaText::MAX_NAME));
		$this->assertSame('A B', SepaText::convert('  A     B  ', SepaText::MAX_NAME));
		$this->assertSame('Nord Sued', SepaText::convert('Nord#Süd', SepaText::MAX_NAME));
	}

	/**
	 * Zu langer Text macht die Datei ungültig (Max70Text/Max140Text), deshalb
	 * wird gekürzt statt abgelehnt: ein gekürzter Name ist verschmerzbar, eine
	 * abgewiesene Einreichung nicht.
	 */
	public function testLaengenWerdenEingehalten(): void {
		$lang = str_repeat('A', 200);
		$this->assertSame(70, mb_strlen(SepaText::convert($lang, SepaText::MAX_NAME)));
		$this->assertSame(140, mb_strlen(SepaText::convert($lang, SepaText::MAX_REMITTANCE)));
	}

	/**
	 * Gekürzt wird nach der Umschrift, nicht davor: sonst wären aus 70 Zeichen
	 * mit Umlauten hinterher mehr als 70.
	 */
	public function testGekuerztWirdNachDerUmschrift(): void {
		$this->assertSame(70, mb_strlen(SepaText::convert(str_repeat('ü', 70), SepaText::MAX_NAME)));
	}

	public function testLeerUndNull(): void {
		$this->assertSame('', SepaText::convert(null, SepaText::MAX_NAME));
		$this->assertSame('', SepaText::convert('   ', SepaText::MAX_NAME));
	}

	/**
	 * Ein Name darf nicht leer in die Datei – lieber eine ehrliche Ersatzangabe
	 * als eine Einreichung, die die Bank ohne Begründung zurückweist.
	 */
	public function testNameFaelltNiemalsLeerAus(): void {
		$this->assertSame('UNBEKANNT', SepaText::name('###'));
		$this->assertSame('UNBEKANNT', SepaText::name(null));
		// Eine Schrift, für die es keine Umschrift gibt: hier bleibt wirklich
		// nichts übrig, und genau dafür ist die Ersatzangabe da.
		$this->assertSame('UNBEKANNT', SepaText::name('Мусоргский'));
	}

	/**
	 * Die eigentliche Zusage dieser Klasse, als eine Prüfung über alles:
	 * was herauskommt, passt in den SEPA-Zeichensatz.
	 */
	public function testErgebnisIstImmerSepaKonform(): void {
		$proben = [
			'Grün & Weiß e. V.', 'Café Frédéric', 'Beitrag „Übungsleiter"',
			'Mitglied #42 – 50 % Ermäßigung', 'Straße des 17. Juni', '€ 12,50',
		];
		foreach ($proben as $probe) {
			$this->assertMatchesRegularExpression(
				"/^[A-Za-z0-9\\/\\-?:().,'+ ]*$/",
				SepaText::convert($probe, SepaText::MAX_REMITTANCE),
				"Nicht SEPA-konform: $probe"
			);
		}
	}
}
