<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Export\AttachmentArchive;
use OCA\Vereinsbuchhaltung\Service\Export\PrintableReportPage;
use OCA\Vereinsbuchhaltung\Service\Export\ReportFormat;
use PHPUnit\Framework\TestCase;

/**
 * Die zustandslosen Teile der Exporte: Zahlen- und Datumsformat, die
 * Namensbereinigung für ZIP-Einträge und die Prüfung der Akzentfarbe.
 */
class ExportFormatTest extends TestCase {

	public function testBetragInDeutscherSchreibweise(): void {
		$this->assertSame('1.234,56', ReportFormat::money(1234.56));
		$this->assertSame('0,00', ReportFormat::money(0));
		$this->assertSame('-42,00', ReportFormat::money(-42));
	}

	public function testCentBetragMitWaehrung(): void {
		$this->assertSame('1.234,56 €', ReportFormat::cents(123456));
		$this->assertSame('-3,00 €', ReportFormat::cents(-300));
	}

	public function testDatumWirdUmgedreht(): void {
		$this->assertSame('31.01.2026', ReportFormat::date('2026-01-31'));
		$this->assertSame('', ReportFormat::date(null));
		$this->assertSame('', ReportFormat::date(''));
	}

	/**
	 * Ein unbekanntes Format bleibt stehen. Ein roher Wert im Bericht ist
	 * erkennbar falsch; ein stillschweigend umgedeutetes Datum nicht.
	 */
	public function testUnbekanntesDatumsformatBleibtStehen(): void {
		$this->assertSame('irgendwas', ReportFormat::date('irgendwas'));
	}

	// --- ZIP-Namen ------------------------------------------------------

	/**
	 * Die Buchungsbeschreibung ist freier Text und geht in den Ordnernamen
	 * ein. Ohne Bereinigung ließe sich damit aus dem vorgesehenen Ordner
	 * ausbrechen – im Archiv, das ein Kassenprüfer entpackt.
	 */
	public function testPfadtrennerWerdenErsetzt(): void {
		$this->assertSame('a_b', AttachmentArchive::safeName('a/b'));
		$this->assertSame('a_b', AttachmentArchive::safeName('a\\b'));
		// Die Trenner werden zu Unterstrichen, das anschliessende trim() raeumt
		// fuehrende Punkte und Unterstriche ab - uebrig bleibt ein harmloser Name.
		$this->assertSame('etc', AttachmentArchive::safeName('../../etc'));
		$this->assertSame('a_b', AttachmentArchive::safeName("a\tb"));
	}

	public function testUmlauteBleibenErhalten(): void {
		$this->assertSame('Grüße für Müller', AttachmentArchive::safeName('Grüße für Müller'));
	}

	public function testNameWirdGekuerztUndNieLeer(): void {
		$this->assertSame('abcde', AttachmentArchive::safeName('abcdefghij', 5));
		$this->assertSame('_', AttachmentArchive::safeName(''));
		$this->assertSame('_', AttachmentArchive::safeName('   '));
	}

	public function testArchivnameTraegtDasJahr(): void {
		$this->assertSame('belege_2026.zip', AttachmentArchive::fileName(2026));
		$this->assertSame('belege_alle_jahre.zip', AttachmentArchive::fileName(null));
		$this->assertSame('belege_alle_jahre.zip', AttachmentArchive::fileName(0));
	}

	// --- Akzentfarbe ----------------------------------------------------

	/**
	 * Die Farbe wird unmaskiert in ein <style>-Element geschrieben. Ohne diese
	 * Schranke ließe sich über die Einstellung beliebiges CSS einschleusen.
	 */
	public function testNurEchteHexFarbenWerdenUebernommen(): void {
		$this->assertSame('#2d7d46', PrintableReportPage::accentColor('#2d7d46'));
		$this->assertSame('#ABCDEF', PrintableReportPage::accentColor('#ABCDEF'));
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function unbrauchbareFarben(): array {
		return [
			'leer' => [''],
			'ohne Raute' => ['2d7d46'],
			'zu kurz' => ['#abc'],
			'zu lang' => ['#2d7d46ff'],
			'keine Hexziffern' => ['#gggggg'],
			'CSS-Einschleusung' => ['#000; } body { display: none; } .x {'],
			'Ausbruch aus dem style-Element' => ['</style><script>alert(1)</script>'],
		];
	}

	/**
	 * @dataProvider unbrauchbareFarben
	 */
	public function testUnbrauchbareFarbeFaelltAufDenStandardZurueck(string $eingabe): void {
		$this->assertSame('#2d7d46', PrintableReportPage::accentColor($eingabe));
	}

	// --- Dokument -------------------------------------------------------

	public function testTitelWirdMaskiert(): void {
		$html = PrintableReportPage::document('Verein <script>', '<p>x</p>');
		$this->assertStringContainsString('<title>Verein &lt;script&gt;</title>', $html);
		$this->assertStringNotContainsString('<script>', $html);
	}

	/**
	 * Beide Berichte teilen sich das Stylesheet. Der Kassenbericht hatte vorher
	 * keine Regeln für die dunkle Darstellung, weil das Duplikat auseinander
	 * gelaufen war – dieser Test hält fest, dass es nur noch eine Quelle gibt.
	 */
	public function testDokumentBringtDunkleDarstellungMit(): void {
		$html = PrintableReportPage::document('Titel', '');
		$this->assertStringContainsString('prefers-color-scheme: dark', $html);
		$this->assertStringContainsString('@media print', $html);
		$this->assertStringContainsString('@page', $html);
	}

	public function testAkzentfarbeLandetImStylesheet(): void {
		$html = PrintableReportPage::document('Titel', '', '#123456');
		$this->assertStringContainsString('#123456', $html);
	}

	public function testKopfzeileOhneLogoUndOhneVereinsname(): void {
		$h = PrintableReportPage::header(null, '', 'Bericht', 'Zeile');
		$this->assertStringNotContainsString('<img', $h);
		$this->assertStringNotContainsString('class="club"', $h);
		$this->assertStringContainsString('<h1>Bericht</h1>', $h);
	}

	public function testKopfzeileMitLogoUndVereinsname(): void {
		$h = PrintableReportPage::header('/logo.png', 'Musterverein', 'Bericht', 'Zeile');
		$this->assertStringContainsString('<img class="logo" src="/logo.png"', $h);
		$this->assertStringContainsString('<div class="club">Musterverein</div>', $h);
	}
}
