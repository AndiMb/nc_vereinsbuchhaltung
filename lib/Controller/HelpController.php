<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Liefert das beiliegende HANDBUCH.md als lesbare HTML-Seite aus. Bewusst
 * kein Markdown-Parser als Composer-Abhängigkeit, sondern ein schlanker
 * Eigenbau, der nur die im Handbuch tatsächlich genutzte Syntax abdeckt
 * (Überschriften, Listen, Zitate, Fett- und Kursivschrift, Trennlinien).
 * Überschriften bekommen ein id-Attribut (slugify), damit das HelpModal aus
 * dem Frontend gezielt in ein Kapitel verlinken kann.
 */
class HelpController extends Controller {

	public function __construct(
		IRequest $request,
		private IConfig $config,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function handbuch(): DataDisplayResponse {
		$path = dirname(__DIR__, 2) . '/HANDBUCH.md';
		$md = is_file($path) ? file_get_contents($path) : '# Handbuch nicht gefunden';

		$html = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>Handbuch Vereinsbuchhaltung</title>'
			. '<style>' . $this->css() . '</style></head><body>'
			. $this->render((string)$md)
			. '</body></html>';

		return new DataDisplayResponse($html, Http::STATUS_OK, ['Content-Type' => 'text/html; charset=utf-8']);
	}

	/**
	 * Druckfertige 1-Seiten-Kurzanleitung für Kassenprüfer/innen (Auszug aus
	 * HANDBUCH.md Kapitel 9) – zum Mitgeben vor der Prüfung, ohne dass die
	 * Prüfperson das ganze Handbuch lesen muss.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pruefleitfaden(): DataDisplayResponse {
		$clubName = (string)$this->config->getAppValue(Application::APP_ID, 'club_name', '');
		$title = ($clubName !== '' ? htmlspecialchars($clubName, ENT_QUOTES, 'UTF-8') . ' – ' : '') . 'Kurzanleitung für Kassenprüfer/innen';

		$h = '<div class="noprint">Zum Drucken oder Als-PDF-Speichern: <strong>Strg+P</strong> (Mac: ⌘P) im Browser.</div>';
		$h .= '<header>';
		if ($clubName !== '') {
			$h .= '<div class="club">' . htmlspecialchars($clubName, ENT_QUOTES, 'UTF-8') . '</div>';
		}
		$h .= '<h1>Kurzanleitung für Kassenprüfer/innen</h1>';
		$h .= '<div class="meta">Erstellt am ' . (new \DateTime())->format('d.m.Y') . '</div>';
		$h .= '</header>';

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
			. '<li><strong>Berichte</strong> – Saldenliste, Kostenstellen, Finanzplan, Kassenbericht, Protokoll.</li>'
			. '</ul></section>';

		$h .= '<section><h2>Nach der Prüfung</h2><p>Ergebnis mit dem Vorstand besprechen. Bei Beanstandungen bleibt das Jahr offen, bis korrigiert wurde. Nach Entlastung durch die Mitgliederversammlung schließt eine Verwalterin oder ein Verwalter das Geschäftsjahr ab (Festschreibung).</p></section>';

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

		$html = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
			. '<title>' . $title . '</title>'
			. '<style>' . $css . '</style></head><body>' . $h . '</body></html>';

		return new DataDisplayResponse($html, Http::STATUS_OK, ['Content-Type' => 'text/html; charset=utf-8']);
	}

	private function render(string $md): string {
		$lines = explode("\n", str_replace("\r\n", "\n", $md));
		$html = '';
		$inList = false;
		$inQuote = false;
		$para = [];

		$flushPara = function () use (&$para, &$html) {
			if ($para) {
				$html .= '<p>' . $this->inline(implode(' ', $para)) . '</p>';
				$para = [];
			}
		};
		$closeBlocks = function () use (&$inList, &$inQuote, &$html) {
			if ($inList) { $html .= '</ul>'; $inList = false; }
			if ($inQuote) { $html .= '</blockquote>'; $inQuote = false; }
		};

		foreach ($lines as $line) {
			$line = rtrim($line);

			if (preg_match('/^(#{1,4})\s+(.*)$/', $line, $m)) {
				$flushPara();
				$closeBlocks();
				$level = strlen($m[1]);
				$id = $this->slugify($m[2]);
				$html .= "<h{$level} id=\"{$id}\">" . $this->inline($m[2]) . "</h{$level}>";
				continue;
			}
			if (trim($line) === '---') {
				$flushPara();
				$closeBlocks();
				$html .= '<hr>';
				continue;
			}
			if (preg_match('/^[-*]\s+(.*)$/', $line, $m)) {
				$flushPara();
				if ($inQuote) { $html .= '</blockquote>'; $inQuote = false; }
				if (!$inList) { $html .= '<ul>'; $inList = true; }
				$html .= '<li>' . $this->inline($m[1]) . '</li>';
				continue;
			}
			if (preg_match('/^>\s?(.*)$/', $line, $m)) {
				$flushPara();
				if ($inList) { $html .= '</ul>'; $inList = false; }
				if (!$inQuote) { $html .= '<blockquote>'; $inQuote = true; }
				$html .= '<p>' . $this->inline($m[1]) . '</p>';
				continue;
			}
			if (trim($line) === '') {
				$flushPara();
				$closeBlocks();
				continue;
			}
			if (str_starts_with(trim($line), '|')) {
				// Tabellen (nur vereinzelt im Dokument) als lesbare Fallback-Zeile.
				$flushPara();
				$closeBlocks();
				$html .= '<p class="table-fallback">' . $this->inline($line) . '</p>';
				continue;
			}
			$para[] = $line;
		}
		$flushPara();
		$closeBlocks();
		return $html;
	}

	private function inline(string $s): string {
		$s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
		$s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
		$s = preg_replace('/(?<!\*)\*([^*\n]+?)\*(?!\*)/', '<em>$1</em>', $s);
		// Markdown-Links auf Text reduzieren statt eigene Slugs gegen GitHub-Anker zu raten.
		$s = preg_replace('/\[(.+?)\]\([^)]*\)/', '$1', $s);
		return $s;
	}

	private function slugify(string $s): string {
		$s = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], mb_strtolower($s));
		$s = preg_replace('/[^a-z0-9]+/', '-', $s);
		return trim((string)$s, '-');
	}

	private function css(): string {
		return '
			* { box-sizing: border-box; }
			body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #222; margin: 0 auto; padding: 24px; max-width: 760px; line-height: 1.55; }
			h1 { font-size: 22pt; margin: 4px 0 16px; }
			h2 { font-size: 15pt; margin: 32px 0 8px; border-bottom: 1px solid #ccc; padding-bottom: 4px; scroll-margin-top: 16px; }
			h3 { font-size: 12pt; margin: 20px 0 6px; scroll-margin-top: 16px; }
			blockquote { margin: 10px 0; padding: 4px 14px; border-left: 3px solid #999; color: #555; background: #f7f7f7; }
			ul { padding-left: 22px; }
			li { margin: 3px 0; }
			.table-fallback { font-family: monospace; font-size: 9pt; color: #555; white-space: pre-wrap; }
			hr { border: none; border-top: 1px solid #ddd; margin: 24px 0; }
			a { color: #0669d6; }
			@media (prefers-color-scheme: dark) {
				body { background: #1b1b1b; color: #ddd; }
				h2 { border-color: #444; }
				blockquote { background: #262626; border-color: #555; color: #bbb; }
				hr { border-color: #333; }
				a { color: #6cb6ff; }
			}
		';
	}
}
