<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\EInvoice;

/**
 * Die schmale Schnittstelle zur Bibliothek, die E-Rechnungs-XML erzeugt.
 *
 * Alles, was eine konkrete Bibliothek (heute horstoeko/invoicesuite) wissen muss,
 * steckt hinter diesen drei Methoden. Wechselt die Bibliothek, etwa zurück auf
 * horstoeko/zugferd, bleibt die Rechnungslogik unberührt: nur die
 * Implementierung wird ersetzt.
 */
interface EInvoiceWriter {

	/**
	 * Das Rechnungs-XML im gewünschten Format.
	 *
	 * @param string $format eines von {@see EInvoiceFormat::ALL}
	 * @throws EInvoiceException wenn Angaben für das Format fehlen
	 */
	public function buildXml(EInvoiceData $data, string $format): string;

	/**
	 * Bettet das XML in ein PDF ein (Hybrid-PDF). Nur für Formate, für die
	 * {@see EInvoiceFormat::isHybridPdf()} gilt.
	 *
	 * @param string $pdf PDF-Bytes der Rechnung (PDF/A-3b)
	 * @throws EInvoiceException wenn das PDF nicht eingebettet werden kann
	 */
	public function embedIntoPdf(string $xml, string $pdf, string $format): string;

	/**
	 * Prüft das XML gegen das Schema des Formats.
	 *
	 * @return list<string> Fehlermeldungen; leer, wenn das XML gültig ist
	 */
	public function validate(string $xml, string $format): array;

	/**
	 * Liest das eingebettete XML aus einem Hybrid-PDF zurück.
	 *
	 * @throws EInvoiceException wenn kein XML eingebettet ist
	 */
	public function extractXml(string $pdf): string;
}
