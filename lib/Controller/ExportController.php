<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\Export\AttachmentArchive;
use OCA\Vereinsbuchhaltung\Service\Export\CsvExportService;
use OCA\Vereinsbuchhaltung\Service\Export\CsvFile;
use OCA\Vereinsbuchhaltung\Service\Export\KassenberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\KurzberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\FiscalYear;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;

/**
 * Die Download- und Druckansichten: CSV-Exporte, Beleg-Archiv und die
 * druckfertigen Berichte.
 *
 * Hier fallen nur noch HTTP-Entscheidungen – Dateiname, Inhaltstyp,
 * Sicherheitsrichtlinie. Was in den Dateien steht, entsteht in
 * {@see \OCA\Vereinsbuchhaltung\Service\Export}; die Zahlen darin kommen aus
 * {@see \OCA\Vereinsbuchhaltung\Service\LedgerAggregator}.
 *
 * Alle Endpunkte sind #[NoCSRFRequired], damit der Browser die Datei direkt
 * per Link-Navigation herunterladen kann (kein AJAX nötig).
 * Die Session-Authentifizierung bleibt aktiv.
 */
class ExportController extends Controller {

	use BookContext;

	public function __construct(
		IRequest $request,
		private CsvExportService $csv,
		private AttachmentArchive $archive,
		private KassenberichtRenderer $kassenbericht,
		private KurzberichtRenderer $kurzbericht,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function download(CsvFile $file): DataDownloadResponse {
		return new DataDownloadResponse($file->content, $file->fileName, 'text/csv; charset=utf-8');
	}

	/**
	 * Antwort für die druckfertigen HTML-Ansichten.
	 *
	 * Diese Seiten bringen ihr Stylesheet inline mit. Ohne eigene Richtlinie
	 * gilt Nextclouds Vorgabe `default-src 'none'`; der Browser verwirft das
	 * <style>-Element dann stillschweigend und der Bericht erscheint völlig
	 * unformatiert – ohne A4-Breite, Tabellenlinien und Unterschriftszeilen.
	 *
	 * Bewusst von EmptyContentSecurityPolicy aus aufgebaut: erlaubt wird nur
	 * das Nötigste, Skripte und fremde Quellen bleiben gesperrt.
	 */
	private function printableResponse(string $html, bool $withImages = false): DataDisplayResponse {
		$response = new DataDisplayResponse(
			$html,
			Http::STATUS_OK,
			['Content-Type' => 'text/html; charset=utf-8'],
		);
		$policy = new EmptyContentSecurityPolicy();
		$policy->allowInlineStyle(true);
		if ($withImages) {
			// Kurzbericht: Vereinslogo aus der eigenen Instanz.
			$policy->addAllowedImageDomain("'self'");
		}
		$response->setContentSecurityPolicy($policy);
		return $response;
	}

	/** Journal aller Buchungssätze als CSV. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function journal(?int $year = null): DataDownloadResponse {
		return $this->download($this->csv->journal($this->userId(), $year));
	}

	/** Saldenliste aller Konten als CSV. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function balances(?int $year = null): DataDownloadResponse {
		return $this->download($this->csv->balances($this->userId(), $year));
	}

	/** Einnahmen-/Ausgaben-Übersicht als CSV. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function report(?int $year = null): DataDownloadResponse {
		return $this->download($this->csv->report($this->userId(), $year));
	}

	/** Finanzplan / Soll-Ist-Vergleich eines Jahres als CSV. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function budget(?int $year = null): DataDownloadResponse {
		return $this->download($this->csv->budget($this->userId(), $year));
	}

	/** Mehrjahresübersicht als CSV-Matrix. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function multiyear(): DataDownloadResponse {
		return $this->download($this->csv->multiyear($this->userId()));
	}

	/**
	 * Alle Belege eines Geschäftsjahres als ZIP – für die Kassenprüfung.
	 *
	 * Ausgeliefert als Datenstrom, damit das fertige Archiv nicht noch einmal
	 * komplett in den Speicher gelesen wird.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function attachments(?int $year = null): StreamResponse|DataDownloadResponse {
		$zipPath = $this->archive->build($this->userId(), $year);

		$response = new StreamResponse($zipPath);
		$response->addHeader('Content-Type', 'application/zip');
		$response->addHeader('Content-Length', (string)(filesize($zipPath) ?: 0));
		$response->addHeader('Content-Disposition', 'attachment; filename="' . AttachmentArchive::fileName($year) . '"');
		return $response;
	}

	/**
	 * Druckfertiger Kassenbericht für die Mitgliederversammlung als
	 * eigenständige HTML-Seite (Drucken/Als-PDF-speichern über den Browser).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function kassenbericht(?int $year = null): DataDisplayResponse {
		$html = $this->kassenbericht->render($this->userId(), FiscalYear::orCurrent($year));
		return $this->printableResponse($html);
	}

	/**
	 * Kurzbericht für die nächste Vorstandssitzung: Kontostände und Bewegungen
	 * seit einem wählbaren Stichtag, optional im Corporate Design.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function kurzbericht(?string $since = null): DataDisplayResponse {
		$html = $this->kurzbericht->render($this->userId(), $since);
		// Das Vereinslogo kommt aus der eigenen Instanz und braucht eine
		// Ausnahme in der ansonsten leeren Richtlinie.
		return $this->printableResponse($html, true);
	}
}
