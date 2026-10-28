<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Invoice;
use OCA\Vereinsbuchhaltung\Service\EpcQrCodeGenerator;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoicePdfData;
use OCA\Vereinsbuchhaltung\Service\Invoice\InvoicePdfRenderer;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Issue #206: Hat eine andere App ihre TCPDF-Kopie zuerst geladen, zeigt die
 * prozessweite Konstante K_PATH_FONTS in deren Schriftordner, in dem DejaVu
 * Sans fehlen kann. Der Test stellt das nach, indem er die Konstante vor dem
 * Laden von TCPDF auf ein leeres Verzeichnis setzt – deshalb in einem eigenen
 * Prozess, sonst hat ein anderer Test TCPDF längst geladen.
 */
class InvoicePdfFontPathTest extends TestCase {

	#[RunInSeparateProcess]
	public function testSchriftenKommenAusDemEigenenVendorAuchWennKPathFontsWoandersHinzeigt(): void {
		$foreign = sys_get_temp_dir() . '/vbh-foreign-fonts-' . getmypid();
		@mkdir($foreign);
		define('K_PATH_FONTS', $foreign . '/');
		if (!class_exists(\TCPDF::class)) {
			$this->markTestSkipped('TCPDF ist nicht installiert (composer install).');
		}

		foreach ([true, false] as $preview) {
			$inv = new Invoice();
			$inv->setId(1);
			$inv->setKind(Invoice::KIND_INVOICE);
			$inv->setNumber('RE-2026-0042');
			$inv->setIssueDate('2026-03-05');
			$inv->setNetCents(1000);
			$inv->setGrossCents(1000);
			$data = new InvoicePdfData(
				$inv,
				[],
				['name' => 'Muster GmbH', 'street' => 'Weg 2', 'postalCode' => '54321', 'city' => 'Ort', 'country' => 'DE'],
				['name' => 'TSV Beispiel e. V.', 'street' => 'Hauptstr. 1', 'postalCode' => '12345', 'city' => 'Beispielstadt', 'country' => 'DE', 'taxNumber' => '', 'vatId' => '', 'registerCourt' => '', 'registerNo' => '', 'representatives' => '', 'phone' => '', 'email' => ''],
				null,
				null,
				null,
				$preview,
			);

			$pdf = (new InvoicePdfRenderer(new EpcQrCodeGenerator()))->render($data, false);

			$this->assertStringStartsWith('%PDF-', $pdf);
			$this->assertStringContainsString('DejaVuSans', $pdf);
		}
		@rmdir($foreign);
	}
}
