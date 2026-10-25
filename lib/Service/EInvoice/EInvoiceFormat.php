<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\EInvoice;

use OCA\Vereinsbuchhaltung\Db\InvoiceDocument;

/**
 * Die E-Rechnungs-Formate, die die App erzeugt (Epic #168, Stufe 2).
 *
 *  - `zugferd`: ZUGFeRD / Factur-X, Profil EN 16931 (COMFORT), als Hybrid-PDF
 *    mit eingebettetem XML. Für Unternehmen als Empfänger, die die Rechnung
 *    maschinell lesen; für Menschen bleibt es ein gewöhnliches PDF.
 *  - `xrechnung_cii`: XRechnung als CII-XML, optional als Hybrid-PDF.
 *  - `xrechnung_ubl`: XRechnung als UBL-XML (Rechnung oder Gutschrift), nur XML.
 *
 * Die Zuordnung zu den Providern der Bibliothek und zu den Dokumentarten der
 * Datenbank steht ausschließlich hier und im Writer.
 */
final class EInvoiceFormat {

	public const ZUGFERD = 'zugferd';
	public const XRECHNUNG_CII = 'xrechnung_cii';
	public const XRECHNUNG_UBL = 'xrechnung_ubl';

	public const ALL = [self::ZUGFERD, self::XRECHNUNG_CII, self::XRECHNUNG_UBL];

	private function __construct() {
	}

	public static function isValid(string $format): bool {
		return in_array($format, self::ALL, true);
	}

	/** Dokumentart (`vbh_invoice_documents.kind`) des erzeugten Dokuments. */
	public static function documentKind(string $format): string {
		return match ($format) {
			self::ZUGFERD => InvoiceDocument::KIND_ZUGFERD_PDF,
			self::XRECHNUNG_CII => InvoiceDocument::KIND_XRECHNUNG_CII,
			default => InvoiceDocument::KIND_XRECHNUNG_UBL,
		};
	}

	/** Profilbezeichnung, die am Dokument gespeichert und angezeigt wird. */
	public static function profile(string $format): string {
		return $format === self::ZUGFERD ? 'EN 16931' : 'XRechnung 3.0';
	}

	public static function fileExtension(string $format): string {
		return $format === self::ZUGFERD ? 'pdf' : 'xml';
	}

	/** XRechnung stellt strengere Pflichtangaben (Leitweg-ID/Käuferreferenz, Kontakt, Zahlungsangaben). */
	public static function isXRechnung(string $format): bool {
		return $format === self::XRECHNUNG_CII || $format === self::XRECHNUNG_UBL;
	}

	/** Ob das Format ein PDF mit eingebettetem XML ist. */
	public static function isHybridPdf(string $format): bool {
		return $format === self::ZUGFERD;
	}
}
