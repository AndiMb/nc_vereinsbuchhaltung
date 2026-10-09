<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCA\Vereinsbuchhaltung\Service\HandbookMarkdownRenderer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Liefert das beiliegende HANDBUCH.md (bzw. HANDBUCH.en.md) als lesbare
 * HTML-Seite aus. Das Markdown wandelt der schlanke Eigenbau
 * HandbookMarkdownRenderer um (dort auch die Anker-Regeln, auf die das
 * HelpModal im Frontend verlinkt); hier stehen nur Seitenrahmen,
 * Stylesheet und die Auslieferung.
 */
class HelpController extends Controller {

	public function __construct(
		IRequest $request,
		private IConfig $config,
		private IL10N $l10n,
		private HandbookMarkdownRenderer $renderer,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function isEnglish(): bool {
		return str_starts_with($this->l10n->getLanguageCode(), 'en');
	}

	/**
	 * Antwort für die HTML-Ansichten mit eingebettetem Stylesheet.
	 *
	 * Ohne eigene Richtlinie gilt Nextclouds Vorgabe `default-src 'none'`, und
	 * der Browser verwirft das <style>-Element – Handbuch und Prüfleitfaden
	 * erscheinen dann unformatiert.
	 */
	private function printableResponse(string $html): DataDisplayResponse {
		$response = new DataDisplayResponse(
			$html,
			Http::STATUS_OK,
			['Content-Type' => 'text/html; charset=utf-8'],
		);
		$policy = new EmptyContentSecurityPolicy();
		$policy->allowInlineStyle(true);
		$response->setContentSecurityPolicy($policy);
		return $response;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function handbuch(): DataDisplayResponse {
		$english = $this->isEnglish();
		$path = dirname(__DIR__, 2) . '/' . ($english ? 'HANDBUCH.en.md' : 'HANDBUCH.md');
		$notFound = $english ? '# Manual not found' : '# Handbuch nicht gefunden';
		$md = is_file($path) ? file_get_contents($path) : $notFound;

		$html = '<!DOCTYPE html><html lang="' . ($english ? 'en' : 'de') . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . ($english ? 'Vereinsbuchhaltung Manual' : 'Handbuch Vereinsbuchhaltung') . '</title>'
			. '<style>' . $this->css() . '</style></head><body>'
			. $this->renderer->render((string)$md)
			. '</body></html>';

		return $this->printableResponse($html);
	}

	/**
	 * Druckfertige 1-Seiten-Kurzanleitung für Kassenprüfer/innen (Auszug aus
	 * HANDBUCH.md Kapitel 9) – zum Mitgeben vor der Prüfung, ohne dass die
	 * Prüfperson das ganze Handbuch lesen muss.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pruefleitfaden(): DataDisplayResponse {
		$english = $this->isEnglish();
		$clubName = (string)$this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$heading = $english ? 'Quick Guide for Auditors' : 'Kurzanleitung für Kassenprüfer/innen';
		$title = ($clubName !== '' ? htmlspecialchars($clubName, ENT_QUOTES, 'UTF-8') . ' – ' : '') . $heading;

		$h = $english
			? '<div class="noprint">To print or save as PDF: <strong>Ctrl+P</strong> (Mac: ⌘P) in your browser.</div>'
			: '<div class="noprint">Zum Drucken oder Als-PDF-Speichern: <strong>Strg+P</strong> (Mac: ⌘P) im Browser.</div>';
		$h .= '<header>';
		if ($clubName !== '') {
			$h .= '<div class="club">' . htmlspecialchars($clubName, ENT_QUOTES, 'UTF-8') . '</div>';
		}
		$h .= '<h1>' . $heading . '</h1>';
		$h .= '<div class="meta">' . ($english ? 'Created on ' : 'Erstellt am ') . (new \DateTime())->format('d.m.Y') . '</div>';
		$h .= '</header>';

		if ($english) {
			$h .= '<section><h2>Your role</h2><p>You have the <strong>Auditor</strong> role: you can view everything but change, create or delete nothing. This lets you review freely without accidentally changing anything.</p></section>';

			$h .= '<section><h2>Before the audit</h2><ul>'
				. '<li>Request the <strong>treasurer\'s report</strong> (Reports tab → Evaluation → "Treasurer\'s report" button) – it is the basis of the audit.</li>'
				. '<li>Request the <strong>receipt ZIP</strong> (Reports → Evaluation → "Receipt ZIP") – all receipts for the year, sorted by posting.</li>'
				. '</ul></section>';

			$h .= '<section><h2>What to check</h2><ul>'
				. '<li><strong>Asset overview:</strong> Do the opening and closing balances of the cash accounts match the bank statements?</li>'
				. '<li><strong>Receipts complete?</strong> In the Bookings tab (journal), the "only without receipt" filter shows missing evidence.</li>'
				. '<li><strong>Posting numbers gap-free?</strong> A warning above the journal automatically reports missing or duplicate numbers.</li>'
				. '<li><strong>Plausibility:</strong> Compare the account statement per cash account (click it in the Accounts tab) against your own records.</li>'
				. '<li><strong>Traceability:</strong> The <strong>change log</strong> (Reports → Log) shows who changed what, and when.</li>'
				. '</ul></section>';

			$h .= '<section><h2>Where to find it</h2><ul>'
				. '<li><strong>Bookings</strong> – all postings, searchable and filterable.</li>'
				. '<li><strong>Accounts</strong> – chart of accounts; clicking an account shows its account statement.</li>'
				. '<li><strong>Reports</strong> – trial balance, cost centers, financial plan, treasurer\'s report, log.</li>'
				. '</ul></section>';

			$h .= '<section><h2>After the audit</h2><p>Discuss the result with the board. If there are objections, the year stays open until corrected. After formal discharge by the general assembly, an administrator closes the fiscal year (finalization).</p></section>';
		} else {
			$h .= '<section><h2>Deine Rolle</h2><p>Du hast die Rolle <strong>Revisor</strong>: du kannst alles einsehen, aber nichts ändern, anlegen oder löschen. So kannst du frei prüfen, ohne versehentlich etwas zu verändern.</p></section>';

			$h .= '<section><h2>Vor der Prüfung</h2><ul>'
				. '<li><strong>Kassenbericht</strong> anfordern (Tab Berichte → Auswertung → Button „Kassenbericht") – er ist die Grundlage der Prüfung.</li>'
				. '<li><strong>Beleg-ZIP</strong> anfordern (Berichte → Auswertung → „Beleg-ZIP") – alle Belege des Jahres, sortiert nach Buchung.</li>'
				. '</ul></section>';

			$h .= '<section><h2>Was du prüfen solltest</h2><ul>'
				. '<li><strong>Vermögensübersicht:</strong> Stimmen Anfangs- und Endbestand der Geldkonten mit den Bankauszügen überein?</li>'
				. '<li><strong>Belege vollständig?</strong> Im Tab Buchungen (Journal) zeigt der Filter „nur ohne Beleg" fehlende Nachweise.</li>'
				. '<li><strong>Buchungsnummern lückenlos?</strong> Ein Warnhinweis über dem Journal meldet fehlende oder doppelte Nummern automatisch.</li>'
				. '<li><strong>Plausibilität:</strong> Kontoauszug je Geldkonto (Tab Konten anklicken) gegen die eigenen Unterlagen abgleichen.</li>'
				. '<li><strong>Nachvollziehbarkeit:</strong> Das <strong>Änderungsprotokoll</strong> (Berichte → Protokoll) zeigt, wer wann was geändert hat.</li>'
				. '</ul></section>';

			$h .= '<section><h2>Wo du das findest</h2><ul>'
				. '<li><strong>Buchungen</strong> – alle Buchungssätze, durchsuchbar und filterbar.</li>'
				. '<li><strong>Konten</strong> – Kontenrahmen; ein Klick auf ein Konto zeigt den Kontoauszug.</li>'
				. '<li><strong>Berichte</strong> – Saldenliste, Auswertungsgruppen, Finanzplan, Kassenbericht, Protokoll.</li>'
				. '</ul></section>';

			$h .= '<section><h2>Nach der Prüfung</h2><p>Ergebnis mit dem Vorstand besprechen. Bei Beanstandungen bleibt das Jahr offen, bis korrigiert wurde. Nach Entlastung durch die Mitgliederversammlung schließt eine Verwalterin oder ein Verwalter das Geschäftsjahr ab (Festschreibung).</p></section>';
		}

		$css = '
			* { box-sizing: border-box; }
			body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #222; margin: 0 auto; padding: 24px; max-width: 210mm; font-size: 11pt; }
			header { border-bottom: 2px solid #222; margin-bottom: 18px; padding-bottom: 10px; }
			.club { font-size: 13pt; font-weight: 600; }
			h1 { font-size: 16pt; margin: 4px 0; }
			h2 { font-size: 12pt; margin: 0 0 6px; border-bottom: 1px solid #999; padding-bottom: 3px; }
			.meta { color: #555; font-size: 9pt; }
			section { margin-bottom: 18px; page-break-inside: avoid; }
			ul { margin: 0; padding-left: 20px; }
			li { margin: 4px 0; }
			.noprint { background: #fffbe6; border: 1px solid #e0d8a0; padding: 8px 12px; margin-bottom: 16px; font-size: 10pt; }
			@media print { .noprint { display: none; } body { padding: 0; } }
			@page { margin: 18mm 15mm; }
		';

		$html = '<!DOCTYPE html><html lang="' . ($english ? 'en' : 'de') . '"><head><meta charset="utf-8">'
			. '<title>' . $title . '</title>'
			. '<style>' . $css . '</style></head><body>' . $h . '</body></html>';

		return $this->printableResponse($html);
	}

	private function css(): string {
		return '
			* { box-sizing: border-box; }
			body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #222; margin: 0 auto; padding: 24px; max-width: 860px; line-height: 1.55; }
			h1 { font-size: 22pt; margin: 4px 0 16px; }
			h2 { font-size: 15pt; margin: 32px 0 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; scroll-margin-top: 16px; }
			h3 { font-size: 12pt; margin: 20px 0 6px; scroll-margin-top: 16px; }
			h4 { font-size: 11pt; margin: 16px 0 4px; scroll-margin-top: 16px; }
			blockquote { margin: 10px 0; padding: 4px 14px; border-left: 3px solid #999; color: #555; background: #f7f7f7; }
			blockquote p { margin: 6px 0; }
			ul, ol { padding-left: 24px; }
			li { margin: 3px 0; }
			code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.9em; background: #f0f0f0; border-radius: 3px; padding: 1px 4px; }
			.table-wrap { overflow-x: auto; margin: 12px 0; }
			table { border-collapse: collapse; width: 100%; font-size: 10pt; line-height: 1.4; }
			th, td { border: 1px solid #d0d0d0; padding: 6px 10px; text-align: left; vertical-align: top; }
			th { background: #f0f0f0; font-weight: 600; }
			tbody tr:nth-child(even) td { background: #fafafa; }
			.al-c { text-align: center; }
			.al-r { text-align: right; }
			hr { border: none; border-top: 1px solid #ddd; margin: 24px 0; }
			a { color: #0669d6; }
			@media (prefers-color-scheme: dark) {
				body { background: #1b1b1b; color: #ddd; }
				h2 { border-color: #444; }
				blockquote { background: #262626; border-color: #555; color: #bbb; }
				code { background: #2c2c2c; }
				th, td { border-color: #444; }
				th { background: #2c2c2c; }
				tbody tr:nth-child(even) td { background: #222; }
				hr { border-color: #333; }
				a { color: #6cb6ff; }
			}
			@media print {
				body { max-width: none; padding: 0; }
				tr, blockquote { break-inside: avoid; }
				h2, h3, h4 { break-after: avoid; }
			}
		';
	}
}
