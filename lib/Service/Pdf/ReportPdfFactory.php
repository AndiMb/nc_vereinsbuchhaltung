<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Pdf;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Service\BrandingService;
use OCA\Vereinsbuchhaltung\Service\Export\PrintableReportPage;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Legt ein neues Berichts-PDF an (Issue #364): lädt TCPDF, setzt Ränder,
 * Schrift, Kopf- und Fußzeile und gibt den Baukasten {@see ReportPdf} zurück.
 *
 * Erzeugt wird PDF/A-3b mit eingebetteter Schrift wie bei Rechnungen: Ein
 * Kassenbericht wird unterschrieben und aufbewahrt, er soll sich auch in zehn
 * Jahren noch gleich öffnen lassen.
 */
class ReportPdfFactory {

	public function __construct(
		private IAppConfig $config,
		private IL10N $l10n,
		private ?BrandingService $branding = null,
	) {
	}

	/**
	 * @param string $title Titel des Dokuments (Metadaten und laufender Kopf ab Seite 2)
	 * @param string $footerNote Vermerk links in der Fußzeile jeder Seite
	 * @param bool $compress false erzeugt lesbare Streams (nur für Tests)
	 * @param bool $landscape A4 quer – für breite Tabellen mit vielen Wertspalten
	 * @throws UserFacingException wenn die PDF-Bibliothek fehlt
	 */
	public function create(string $title, string $footerNote, bool $compress = true, bool $landscape = false): ReportPdf {
		// ReportPdfDocument erweitert TCPDF aus vendor/: erst den Autoloader, dann die Klasse (#265).
		Application::loadComposerAutoloader();
		if (!class_exists(\TCPDF::class)) {
			throw new UserFacingException($this->l10n->t('Die PDF-Bibliothek (TCPDF) ist nicht installiert.'));
		}
		$pdf = $this->newDocument($landscape ? 'L' : 'P');
		$pdf->setCompression($compress);
		$pdf->runningTitle = $title;
		$pdf->footerNote = $footerNote;
		$pdf->pageLabel = $this->l10n->t('Seite {page} von {pages}');
		$pdf->accent = self::rgb(PrintableReportPage::accentColor($this->config->getValueString(Application::APP_ID, 'brand_color', ''), '#222222'));
		ReportPdf::setUp($pdf, $title, $this->clubName());
		return new ReportPdf($pdf);
	}

	/**
	 * Eigene Methode, damit Tests ein Dokument unterschieben können, das den
	 * gesetzten Text mitschreibt (im fertigen PDF liegt er nur als Glyphennummern).
	 *
	 * @param string $orientation 'P' Hochformat, 'L' Querformat
	 */
	protected function newDocument(string $orientation): ReportPdfDocument {
		return new ReportPdfDocument($orientation, 'mm', 'A4', true, 'UTF-8', false, 3);
	}

	public function clubName(): string {
		return $this->config->getValueString(Application::APP_ID, 'club_name', '');
	}

	/** Das Vereinslogo als Rohdaten, falls eines hinterlegt ist. */
	public function logo(): ?string {
		return $this->branding?->getLogo()['content'] ?? null;
	}

	/** @return array{0:int, 1:int, 2:int} */
	private static function rgb(string $hex): array {
		return [(int)hexdec(substr($hex, 1, 2)), (int)hexdec(substr($hex, 3, 2)), (int)hexdec(substr($hex, 5, 2))];
	}
}
