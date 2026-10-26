<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Db\InvoiceLine;
use OCA\Vereinsbuchhaltung\Service\EpcQrCodeGenerator;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoicePdfData;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoicePdfRenderer;
use PHPUnit\Framework\TestCase;

/**
 * PDF der Rechnung: Der Text liegt in einer eingebetteten Schrift als
 * Glyphennummern vor und lässt sich im Rohstrom nicht suchen. Geprüft werden
 * deshalb Aufbau und Archivkennzeichnung; das Layout selbst prüft ein Blick auf
 * das erzeugte PDF.
 */
class InvoicePdfRendererTest extends TestCase {

	protected function setUp(): void {
		if (!class_exists(\TCPDF::class)) {
			$this->markTestSkipped('TCPDF ist nicht installiert (composer install).');
		}
	}

	/** @return list<InvoiceLine> */
	private function lines(int $count): array {
		$lines = [];
		for ($i = 1; $i <= $count; $i++) {
			$l = new InvoiceLine();
			$l->setPosition($i);
			$l->setName('Hallenmiete ' . $i);
			$l->setDescription($i % 5 === 0 ? "Mit Reinigung\nund Aufbau" : null);
			$l->setQuantityE4(35000);
			$l->setUnitCode('HUR');
			$l->setUnitPriceCents(2550);
			$l->setLineNetCents(8925);
			$lines[] = $l;
		}
		return $lines;
	}

	private function invoice(int $lineCount, string $kind = Invoice::KIND_INVOICE): Invoice {
		$inv = new Invoice();
		$inv->setId(1);
		$inv->setKind($kind);
		$inv->setNumber('RE-2026-0042');
		$inv->setIssueDate('2026-03-05');
		$inv->setDeliveryDate('2026-03-01');
		$inv->setDueDate('2026-03-19');
		$inv->setExemptionText('Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.');
		$gross = 8925 * $lineCount * ($kind === Invoice::KIND_CREDIT ? -1 : 1);
		$inv->setNetCents($gross);
		$inv->setGrossCents($gross);
		return $inv;
	}

	/** @param array<string, string>|null $bank */
	private function render(Invoice $inv, int $lineCount, bool $preview = false, ?array $bank = ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001', 'holder' => 'TSV Beispiel e. V.'], ?string $preceding = null, ?string $logo = null): string {
		$data = new InvoicePdfData(
			$inv,
			$this->lines($lineCount),
			['name' => 'Muster GmbH', 'street' => 'Weg 2', 'postalCode' => '54321', 'city' => 'Ort', 'country' => 'DE'],
			['name' => 'TSV Beispiel e. V.', 'street' => 'Hauptstr. 1', 'postalCode' => '12345', 'city' => 'Beispielstadt', 'country' => 'DE', 'taxNumber' => '12/345/67890', 'vatId' => '', 'registerCourt' => '', 'registerNo' => '', 'representatives' => '', 'phone' => '', 'email' => ''],
			$bank,
			$preceding,
			$preceding !== null ? '2026-02-01' : null,
			$preview,
			null,
			$logo,
		);
		return (new InvoicePdfRenderer(new EpcQrCodeGenerator()))->render($data, false);
	}

	private function pages(string $pdf): int {
		return preg_match_all('#/Type /Page\b(?!s)#', $pdf);
	}

	public function testFestgeschriebeneRechnungIstPdfA3b(): void {
		$pdf = $this->render($this->invoice(3), 3);

		$this->assertStringStartsWith('%PDF-1.7', $pdf);
		$this->assertStringEndsWith("%%EOF\n", $pdf);
		$this->assertStringContainsString('<pdfaid:part>3</pdfaid:part>', $pdf);
		$this->assertStringContainsString('<pdfaid:conformance>B</pdfaid:conformance>', $pdf);
		$this->assertStringContainsString('/OutputIntent', $pdf);
		$this->assertStringContainsString('/FontFile2', $pdf, 'Die Schrift ist eingebettet (PDF/A).');
		$this->assertSame(1, $this->pages($pdf));
	}

	/** Ein PNG mit Transparenz, wie es ein Vereinslogo oft ist. */
	private function logoPng(): string {
		$image = imagecreatetruecolor(300, 120);
		imagesavealpha($image, true);
		imagealphablending($image, false);
		imagefill($image, 0, 0, (int)imagecolorallocatealpha($image, 0, 0, 0, 127));
		imagealphablending($image, true);
		imagefilledellipse($image, 150, 60, 200, 100, (int)imagecolorallocate($image, 200, 30, 30));
		ob_start();
		imagepng($image);
		return (string)ob_get_clean();
	}

	public function testVereinslogoWirdEingebettetUndBleibtPdfA(): void {
		if (!function_exists('imagecreatetruecolor')) {
			$this->markTestSkipped('GD fehlt');
		}
		$ohne = $this->render($this->invoice(3), 3);
		$mit = $this->render($this->invoice(3), 3, false, ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001', 'holder' => 'TSV Beispiel e. V.'], null, $this->logoPng());

		$this->assertGreaterThan(substr_count($ohne, '/Subtype /Image'), substr_count($mit, '/Subtype /Image'), 'Das Logo ist ein zusätzliches Bild.');
		$this->assertStringContainsString('<pdfaid:part>3</pdfaid:part>', $mit);
		$this->assertSame(1, $this->pages($mit));
	}

	public function testKaputtesLogoFaelltLeiseWeg(): void {
		$pdf = $this->render($this->invoice(3), 3, false, ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001', 'holder' => 'TSV Beispiel e. V.'], null, 'das ist kein bild');

		$this->assertStringStartsWith('%PDF-1.7', $pdf);
	}

	public function testEntwurfsvorschauIstKeinPdfA(): void {
		$pdf = $this->render($this->invoice(3), 3, true);

		$this->assertStringStartsWith('%PDF-', $pdf);
		$this->assertStringNotContainsString('pdfaid:part', $pdf);
	}

	public function testLangeRechnungLaeuftAufMehrereSeiten(): void {
		$pdf = $this->render($this->invoice(60), 60);

		$this->assertGreaterThan(1, $this->pages($pdf));
	}

	public function testStornorechnungMitVerweisAufDieUrsprungsrechnung(): void {
		$pdf = $this->render($this->invoice(2, Invoice::KIND_CREDIT), 2, false, null, 'RE-2026-0001');

		$this->assertStringContainsString('<pdfaid:part>3</pdfaid:part>', $pdf);
	}

	public function testOhneBankverbindungEntstehtTrotzdemEinPdf(): void {
		$pdf = $this->render($this->invoice(2), 2, false, null);

		$this->assertStringStartsWith('%PDF-1.7', $pdf);
	}
}
