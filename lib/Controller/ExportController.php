<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Exception\UserFacingException;
use OCA\Vereinsbuchhaltung\Middleware\RequiresRole;
use OCA\Vereinsbuchhaltung\Service\Export\AccountStatementRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\AttachmentArchive;
use OCA\Vereinsbuchhaltung\Service\Export\BeitragsbescheinigungPdfRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\BeitragsbescheinigungRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\CsvExportService;
use OCA\Vereinsbuchhaltung\Service\Export\CsvFile;
use OCA\Vereinsbuchhaltung\Service\Export\DatenuebersichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\FinanzplanRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\GliederungsberichtPdfRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\GliederungsberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\GliederungsberichtVisuellPdfRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\GliederungsberichtVisuellRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\KassenberichtPdfRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\KassenberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\KurzberichtRenderer;
use OCA\Vereinsbuchhaltung\Service\Export\PrintableReportPage;
use OCA\Vereinsbuchhaltung\Service\PeriodService;
use OCA\Vereinsbuchhaltung\Service\PermissionService;
use OCA\Vereinsbuchhaltung\Service\Report\ReportTemplateService;
use OCA\Vereinsbuchhaltung\Service\Report\StructuredReportService;
use OCA\Vereinsbuchhaltung\Service\Report\VisualReportService;
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
use Psr\Log\LoggerInterface;

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
		private AccountStatementRenderer $accountStatement,
		private FinanzplanRenderer $finanzplan,
		private GliederungsberichtRenderer $gliederungsbericht,
		private GliederungsberichtVisuellRenderer $gliederungsberichtVisuell,
		private ReportTemplateService $reportTemplates,
		private StructuredReportService $structuredReport,
		private VisualReportService $visualReport,
		private PeriodService $periods,
		private IL10N $l10n,
		private ?KassenberichtPdfRenderer $kassenberichtPdf = null,
		private ?GliederungsberichtPdfRenderer $gliederungsberichtPdf = null,
		private ?BeitragsbescheinigungPdfRenderer $beitragsbescheinigungPdf = null,
		private ?LoggerInterface $logger = null,
		private ?GliederungsberichtVisuellPdfRenderer $gliederungsberichtVisuellPdf = null,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Antwort für die PDF-Fassungen der formellen Berichte (Issue #364). Scheitert
	 * das Erzeugen (etwa ohne PDF-Bibliothek), gibt es eine Meldung statt einer
	 * kaputten Datei.
	 *
	 * @param callable(): array{content: string, fileName: string} $build
	 */
	private function pdfResponse(callable $build): DataDownloadResponse|DataResponse {
		try {
			$pdf = $build();
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Mitglied nicht gefunden')], Http::STATUS_NOT_FOUND);
		} catch (UserFacingException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		} catch (\RuntimeException $e) {
			$this->logger?->error($e->getMessage(), ['exception' => $e, 'app' => Application::APP_ID]);
			return new DataResponse(['message' => $this->l10n->t('Das PDF konnte nicht erzeugt werden.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new DataDownloadResponse($pdf['content'], $pdf['fileName'], 'application/pdf');
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

	/**
	 * Journal als CSV, beschränkt auf die Buchungen einer gefilterten Liste.
	 *
	 * Die Filter der Buchungsliste (Konto, Zeitraum, Suche, „nur ohne Beleg“)
	 * arbeiten im Browser; der schickt deshalb die Nummern der angezeigten
	 * Buchungen. Per POST, weil die Liste für eine URL zu lang werden kann.
	 *
	 * Trotz POST ein reiner Lesezugriff: ohne die ausdrückliche Rolle stufte
	 * die Verb-Heuristik ihn als Schreiben ein, und der Revisor könnte seine
	 * gefilterte Liste nicht exportieren.
	 *
	 * @param string $ids Buchungs-IDs, durch Komma getrennt
	 */
	#[NoAdminRequired]
	#[RequiresRole(PermissionService::ROLE_READ)]
	public function journalSelection(?int $period = null, string $ids = ''): DataDownloadResponse {
		$onlyIds = array_values(array_filter(
			array_map('intval', explode(',', $ids)),
			static fn (int $id): bool => $id > 0,
		));
		return $this->download($this->csv->journal($this->userId(), $period, $onlyIds));
	}

	/** Saldenliste aller Konten als CSV. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function balances(?int $period = null): DataDownloadResponse {
		return $this->download($this->csv->balances($this->userId(), $period));
	}

	/** Kontenblatt eines Kontos (optional mit Unterkonten) als CSV. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function accountStatement(int $id, int $includeChildren = 1, ?int $period = null): DataDownloadResponse|DataResponse {
		try {
			return $this->download($this->csv->accountStatement($this->userId(), $id, (bool)$includeChildren, $period));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Konto nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
	}

	/** Kontenblatt eines Kontos als druckfertige HTML-Seite. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function accountStatementPrint(int $id, int $includeChildren = 1, ?int $period = null): DataDisplayResponse|DataResponse {
		try {
			return $this->printableResponse($this->accountStatement->render($this->userId(), $id, (bool)$includeChildren, $period));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => $this->l10n->t('Konto nicht gefunden')], Http::STATUS_NOT_FOUND);
		}
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
	public function budget(?int $period = null, int $compare = 0, string $basis = 'actual'): DataDownloadResponse {
		return $this->download($this->csv->budget($this->userId(), $period, $compare, $basis));
	}

	/**
	 * Soll-Ist-Vergleich als druckfertige HTML-Seite für den Vorstand.
	 *
	 * @param string $depth `plan` (bis zur Planungsebene), Tiefe ab 0 oder `all`
	 * @param int $compare Vergleichsperiode, 0 = keine (die Oberfläche übergibt
	 *                     immer ihre aktuelle Wahl, siehe Issue #114)
	 * @param string $basis `actual` oder `plan`
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function finanzplan(?int $period = null, string $depth = 'plan', int $compare = 0, string $basis = 'actual'): DataDisplayResponse {
		return $this->printableResponse($this->finanzplan->render($this->userId(), $period, $depth, $compare, $basis));
	}

	/** Gliederungsbericht einer Vorlage als druckfertige HTML-Seite. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function gliederungsbericht(string $template, ?int $period = null): DataDisplayResponse|DataResponse {
		$report = $this->structuredReport($template, $period);
		return $report === null ? $this->templateNotFound() : $this->printableResponse($this->gliederungsbericht->render($report));
	}

	/** Gliederungsbericht einer Vorlage als CSV. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function gliederungsberichtCsv(string $template, ?int $period = null): DataDownloadResponse|DataResponse {
		$report = $this->structuredReport($template, $period);
		return $report === null ? $this->templateNotFound() : $this->download($this->csv->gliederungsbericht($report));
	}

	/**
	 * Gliederungsbericht einer Vorlage als PDF: derselbe Inhalt wie die Druckseite,
	 * als Datei mit Seitenzahlen. Unbekannte Vorlage: dieselbe Antwort wie dort.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function gliederungsberichtPdf(string $template, ?int $period = null): DataDownloadResponse|DataResponse {
		$report = $this->structuredReport($template, $period);
		if ($report === null) {
			return $this->templateNotFound();
		}
		return $this->pdfResponse(function () use ($report): array {
			$renderer = $this->gliederungsberichtPdf ?? throw new \RuntimeException('GliederungsberichtPdfRenderer fehlt');
			return ['content' => $renderer->render($report), 'fileName' => $renderer->fileName($report)];
		});
	}

	/**
	 * Visuelle Gliederungsansicht (Plan gegen Ist) als druckfertige HTML-Seite.
	 *
	 * @param string|null $asOf Stand `YYYY-MM` oder `current`; leer oder fehlend = Vorgabe
	 * @param string $basis `time` (zeitanteiliger Plan), sonst Jahresplan
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function gliederungsberichtVisuell(string $template, ?int $period = null, ?string $asOf = null, string $basis = 'year'): DataDisplayResponse|DataResponse {
		$visual = $this->visualReport($template, $period, $asOf);
		if ($visual instanceof DataResponse) {
			return $visual;
		}
		return $this->printableResponse($this->gliederungsberichtVisuell->render($visual, $basis === 'time' ? 'time' : 'year'));
	}

	/**
	 * Visuelle Gliederungsansicht als PDF: derselbe Inhalt wie die Druckseite, als
	 * Datei mit Seitenzahlen. Unbekannte Vorlage oder ungültiger Stand: dieselben
	 * Antworten wie dort.
	 *
	 * @param string|null $asOf Stand `YYYY-MM` oder `current`; leer oder fehlend = Vorgabe
	 * @param string $basis `time` (zeitanteiliger Plan), sonst Jahresplan
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function gliederungsberichtVisuellPdf(string $template, ?int $period = null, ?string $asOf = null, string $basis = 'year'): DataDownloadResponse|DataResponse {
		$visual = $this->visualReport($template, $period, $asOf);
		if ($visual instanceof DataResponse) {
			return $visual;
		}
		return $this->pdfResponse(function () use ($visual, $basis): array {
			$renderer = $this->gliederungsberichtVisuellPdf ?? throw new \RuntimeException('GliederungsberichtVisuellPdfRenderer fehlt');
			return ['content' => $renderer->render($visual, $basis === 'time' ? 'time' : 'year'), 'fileName' => $renderer->fileName($visual)];
		});
	}

	/** @return array<string, mixed>|DataResponse Daten der visuellen Ansicht oder die Fehlerantwort */
	private function visualReport(string $template, ?int $period, ?string $asOf): array|DataResponse {
		$userId = $this->userId();
		$found = $this->reportTemplates->find($userId, $template);
		if ($found === null) {
			return $this->templateNotFound();
		}
		try {
			return $this->visualReport->build($userId, $found, $period, $asOf === '' ? null : $asOf);
		} catch (\InvalidArgumentException) {
			return new DataResponse(['message' => $this->l10n->t('Ungültiger Stand')], Http::STATUS_UNPROCESSABLE_ENTITY);
		}
	}

	/** @return array<string, mixed>|null */
	private function structuredReport(string $template, ?int $period): ?array {
		$userId = $this->userId();
		$found = $this->reportTemplates->find($userId, $template);
		return $found === null ? null : $this->structuredReport->build($userId, $found, $period);
	}

	private function templateNotFound(): DataResponse {
		return new DataResponse(['message' => $this->l10n->t('Vorlage nicht gefunden')], Http::STATUS_NOT_FOUND);
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
	 * Der Kassenbericht als PDF (Issue #364): dasselbe wie die HTML-Seite, als
	 * festes Dokument zum Unterschreiben und Ablegen.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function kassenberichtPdf(?int $period = null): DataDownloadResponse|DataResponse {
		$userId = $this->userId();
		$selected = $this->periods->selectedOrCurrent($userId, $period);
		return $this->pdfResponse(function () use ($userId, $selected): array {
			$renderer = $this->kassenberichtPdf ?? throw new \RuntimeException('KassenberichtPdfRenderer fehlt');
			return ['content' => $renderer->render($userId, $selected), 'fileName' => $renderer->fileName($selected)];
		});
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

	/** Die Beitragsbestätigung eines Mitglieds als PDF (Issue #364), Rechte wie {@see beitragsbescheinigung()}. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[RequiresRole(PermissionService::ROLE_WRITE)]
	public function beitragsbescheinigungPdf(int $memberId, ?int $year = null): DataDownloadResponse|DataResponse {
		return $this->pdfResponse(fn (): array => $this->beitragsbescheinigungPdf?->render($memberId, $year)
			?? throw new \RuntimeException('BeitragsbescheinigungPdfRenderer fehlt'));
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
