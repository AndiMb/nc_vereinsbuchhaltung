<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Pdf;

/**
 * Bereitet das Vereinslogo für TCPDF auf: über GD auf weißen Grund und als PNG
 * ohne Transparenz. So nimmt es auch PDF/A an, und WebP, das TCPDF nicht kennt,
 * geht ebenfalls. Gelingt das nicht (kein GD, unlesbare Datei, SVG), kommt null
 * zurück; das Dokument entsteht dann ohne Logo, statt zu scheitern.
 *
 * Gemeinsam genutzt von Rechnungen ({@see \OCA\Vereinsbuchhaltung\Service\Invoice\InvoicePdfRenderer})
 * und Berichten ({@see ReportPdf}).
 */
final class PdfLogo {

	/**
	 * @return array{png: string, width: int, height: int}|null
	 */
	public static function png(?string $bytes): ?array {
		if ($bytes === null || $bytes === '' || !function_exists('imagecreatefromstring')) {
			return null;
		}
		$source = @imagecreatefromstring($bytes);
		if ($source === false) {
			return null;
		}
		$w = imagesx($source);
		$h = imagesy($source);
		if ($w > 900) {
			$scaled = imagescale($source, 900);
			if ($scaled !== false) {
				$source = $scaled;
				$w = imagesx($source);
				$h = imagesy($source);
			}
		}
		$canvas = imagecreatetruecolor($w, $h);
		imagefill($canvas, 0, 0, (int)imagecolorallocate($canvas, 255, 255, 255));
		imagecopy($canvas, $source, 0, 0, 0, 0, $w, $h);
		ob_start();
		imagepng($canvas);
		$png = (string)ob_get_clean();
		if ($png === '') {
			return null;
		}
		return ['png' => $png, 'width' => $w, 'height' => $h];
	}

	/**
	 * Setzt das Logo rechtsbündig an die obere Kante, eingepasst in den Rahmen.
	 *
	 * @return float Höhe des gesetzten Logos in mm, 0 ohne Logo
	 */
	public static function place(\TCPDF $pdf, ?string $bytes, float $right, float $top, float $maxWidth, float $maxHeight): float {
		$logo = self::png($bytes);
		if ($logo === null) {
			return 0.0;
		}
		$scale = min($maxWidth / $logo['width'], $maxHeight / $logo['height']);
		$dw = $logo['width'] * $scale;
		$dh = $logo['height'] * $scale;
		$pdf->Image('@' . $logo['png'], $pdf->getPageWidth() - $right - $dw, $top, $dw, $dh, 'PNG');
		return $dh;
	}
}
