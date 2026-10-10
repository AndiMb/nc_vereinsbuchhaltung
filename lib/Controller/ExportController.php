<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\Export\AttachmentArchive;
use OCA\Vereinsbuchhaltung\Service\Export\BeitragsbescheinigungRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\CsvExportService;
use OCA\Vereinsbuchhaltung\Service\Export\CsvFile;
use OCA\Vereinsbuchhaltung\Service\Export\DatenuebersichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\KassenberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\KurzberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\PrintableReportPage;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Die Download- und Druckansichten: CSV-Exporte, Beleg-Archiv und die
 * druckfertigen Berichte (Kassenbericht/Kurzbericht sowie – Issue #77 – die
 * Beitragsbestätigung eines einzelnen Mitglieds für die Stellvertretung
 * durch den Kassenwart).
 *
 * Hier fallen nur noch HTTP-Entscheidungen – Dateiname, Inhaltstyp,
 * Sicherheitsrichtlinie. Was in den Dateien steht, entsteht in
 * {@see \OCA\Vereinsbuchhaltung\Service\Export}; die Zahlen darin kommen aus
 * {@see \OCA\Vereinsbuchhaltung\Service\LedgerAggregator}.
 *
 * Die reinen Download-/Druck-Endpunkte sind #[NoCSRFRequired], damit der
 * Browser die Datei direkt per Link-Navigation abrufen kann (kein AJAX
 * nötig) – die Jahresauswahl der Beitragsbestätigung ist dagegen ein
 * gewöhnlicher AJAX-Aufruf und bleibt bewusst ohne dieses Attribut. Die
 * Session-Authentifizierung bleibt überall aktiv.
 */
class ExportController extends Controller {

	use BookContext;

	public function __construct(
		IRequest $request,
		private CsvExportService $csv,
		private AttachmentArchive $archive,
		private KassenberichtRenderer $kassenbericht,
		private KurzberichtRenderer $kurzbericht,
		private BeitragsbescheinigungRenderer $beitragsbescheinigung,
		private DatenuebersichtRenderer $datenuebersicht,
		private PeriodService $periods,
		private IL10N $l10n,
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
		// Kurzbericht: Vereinslogo aus der eigenen Instanz, daher $withImages.
		return PrintableReportPage::response($html, $withImages);
	}

	/** Journal aller Buchungssätze als CSV. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function journal(?int $period = null): DataDownloadResponse {
		return $this->download($this->csv->journal($this->userId(), $period));
	}

	/** Saldenliste aller Konten als CSV. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function balances(?int $period = null): DataDownloadResponse {
		return $this->download($this->csv->balances($this->userId(), $period));
	}

	/** Einnahmen-/Ausgaben-Übersicht als CSV. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function report(?int $period = null): DataDownloadResponse {
		return $this->download($this->csv->report($this->userId(), $period));
	}

	/** Finanzplan / Soll-Ist-Vergleich eines Geschäftsjahres als CSV. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function budget(?int $period = null): DataDownloadResponse {
		return $this->download($this->csv->budget($this->userId(), $period));
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
	public function attachments(?int $period = null): StreamResponse|DataDownloadResponse {
		$userId = $this->userId();
		$zipPath = $this->archive->build($userId, $period);

		$response = new StreamResponse($zipPath);
		$response->addHeader('Content-Type', 'application/zip');
		$response->addHeader('Content-Length', (string)(filesize($zipPath) ?: 0));
		$response->addHeader('Content-Disposition', 'attachment; filename="' . $this->archive->fileName($userId, $period) . '"');
		return $response;
	}

	/**
	 * Druckfertiger Kassenbericht für die Mitgliederversammlung als
	 * eigenständige HTML-Seite (Drucken/Als-PDF-speichern über den Browser).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function kassenbericht(?int $period = null): DataDisplayResponse {
		$userId = $this->userId();
		$html = $this->kassenbericht->render($userId, $this->periods->selectedOrCurrent($userId, $period));
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

	/**
	 * Beitragsjahre eines Mitglieds mit mindestens einer bezahlten
	 * Beitrags-Forderung (plus das laufende Jahr) – Grundlage der
	 * Jahresauswahl der Stellvertretung (Spec §3.7, Issue #77). Dieselbe
	 * Datenquelle wie {@see \OCA\Vereinsbuchhaltung\Controller\SelfController::certificateYears()},
	 * hier für den Kassenwart-Kanal über die Admin-Akte statt NC-Konto-Login.
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function beitragsbescheinigungYears(int $memberId): DataResponse {
		try {
			return new DataResponse(['years' => $this->beitragsbescheinigung->selectableYears($memberId)]);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Informelle Beitragsbestätigung als druckfertige Live-Ansicht (Spec
	 * §3.7, Issue #77) – KEINE amtliche Zuwendungsbestätigung nach §10b EStG
	 * (separates Upstream-Issue #10). Stellvertretung durch den Kassenwart
	 * über die Admin-Akte (kein login-loser Link, siehe T09/T10-Grenze) –
	 * deshalb `RequiresRole(WRITE)` wie {@see \OCA\Vereinsbuchhaltung\Controller\MemberController}
	 * (zeigt personenbezogene Zahlungsdaten eines Mitglieds, nicht
	 * Revisoren vorbehalten), statt der GET-Heuristik dieses Controllers.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function beitragsbescheinigung(int $memberId, ?int $year = null): DataDisplayResponse|DataResponse {
		try {
			return $this->printableResponse($this->beitragsbescheinigung->render($memberId, $year));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * „Datenübersicht" eines Mitglieds als druckfertige Live-Ansicht (Spec
	 * §3.8, Issue #78) – deckt die Auskunftspflicht nach Art. 15 DSGVO ab,
	 * kein strukturierter Export nach Art. 20. Stellvertretung durch den
	 * Kassenwart über die Admin-Akte, deshalb `RequiresRole(WRITE)` wie
	 * {@see beitragsbescheinigung()} (zeigt personenbezogene Daten eines
	 * Mitglieds, nicht Revisoren vorbehalten).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function datenuebersicht(int $memberId): DataDisplayResponse|DataResponse {
		try {
			return $this->printableResponse($this->datenuebersicht->render($memberId));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}
}
