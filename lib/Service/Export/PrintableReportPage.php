<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Export;

/**
 * Das Gerüst der druckfertigen HTML-Berichte: Dokument, Stylesheet, Kopfzeile.
 *
 * Kassenbericht und Kurzbericht trugen ihr Stylesheet bisher je einzeln im
 * Methodenrumpf. Die beiden Blöcke waren bis auf die Akzentfarbe gleich – bis
 * auf einen Unterschied, der genau zeigt, wie Duplikate altern: der Kurzbericht
 * bekam irgendwann Regeln für die dunkle Darstellung, der Kassenbericht nicht.
 * Wer ihn im dunklen Systemdesign öffnete, sah schwarze Schrift auf weißem
 * Grund in einem sonst dunklen Fenster. Jetzt gibt es das Stylesheet einmal,
 * und beide Berichte sehen gleich aus.
 *
 * Kein JavaScript und keine fremden Quellen: die Seiten werden unter einer
 * eigenen, sehr engen Content-Security-Policy ausgeliefert (siehe
 * ExportController::printableResponse()), damit Nextclouds Vorgabe
 * `default-src 'none'` das inline eingebettete Stylesheet nicht verwirft.
 */
final class PrintableReportPage {

	/** Akzentfarbe, wenn der Verein keine eigene gesetzt hat. */
	public const DEFAULT_ACCENT = '#222';

	public static function escape(string $s): string {
		return htmlspecialchars($s, ENT_QUOTES);
	}

	/**
	 * Prüft eine gespeicherte Akzentfarbe.
	 *
	 * Der Wert wird ungeprüft in ein <style>-Element geschrieben; ohne diese
	 * Schranke ließe sich über die Einstellung beliebiges CSS einschleusen.
	 */
	public static function accentColor(string $configured, string $fallback = '#2d7d46'): string {
		return preg_match('/^#[0-9a-fA-F]{6}$/', $configured) === 1 ? $configured : $fallback;
	}

	/**
	 * Baut das vollständige HTML-Dokument.
	 *
	 * @param string $title Fenstertitel (wird maskiert)
	 * @param string $body  fertiges HTML des Berichtsinhalts
	 * @param string $accent Farbe für Trennlinien und Überschrift
	 */
	public static function document(string $title, string $body, string $accent = self::DEFAULT_ACCENT): string {
		return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
			. '<title>' . self::escape($title) . '</title>'
			. '<style>' . self::stylesheet($accent) . '</style></head><body>'
			. $body
			. '</body></html>';
	}

	/** Der Hinweis zum Drucken – auf Papier blendet ihn @media print aus. */
	public static function printHint(): string {
		return '<div class="noprint">Zum Drucken oder Als-PDF-Speichern: <strong>Strg+P</strong> (Mac: ⌘P) im Browser.</div>';
	}

	/**
	 * Kopfzeile mit optionalem Logo, Vereinsname, Titel und Zusatzangabe.
	 *
	 * @param string|null $logoUrl URL des Vereinslogos, oder null
	 * @param string $clubName leer, wenn kein Vereinsname hinterlegt ist
	 * @param string $meta bereits maskierte Zusatzzeile (Zeitraum, Vermerk …)
	 */
	public static function header(?string $logoUrl, string $clubName, string $heading, string $meta): string {
		$h = '<header>';
		if ($logoUrl !== null) {
			$h .= '<img class="logo" src="' . self::escape($logoUrl) . '" alt="Logo">';
		}
		if ($clubName !== '') {
			$h .= '<div class="club">' . self::escape($clubName) . '</div>';
		}
		$h .= '<h1>' . self::escape($heading) . '</h1>';
		$h .= '<div class="meta">' . $meta . '</div>';
		return $h . '</header>';
	}

	/**
	 * Das gemeinsame Stylesheet. A4-Breite, druckbare Tabellen, dunkle
	 * Darstellung – Letztere wirkt nur am Bildschirm, gedruckt wird immer auf
	 * hellem Grund.
	 */
	private static function stylesheet(string $accent): string {
		return '
			* { box-sizing: border-box; }
			body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #222; margin: 0 auto; padding: 24px; max-width: 210mm; font-size: 11pt; }
			header { border-bottom: 2px solid ' . $accent . '; margin-bottom: 18px; padding-bottom: 10px; }
			.logo { max-height: 48px; max-width: 240px; display: block; margin-bottom: 8px; }
			.club { font-size: 13pt; font-weight: 600; }
			h1 { font-size: 16pt; margin: 4px 0; color: ' . $accent . '; }
			h2 { font-size: 12pt; margin: 0 0 6px; border-bottom: 1px solid #999; padding-bottom: 3px; }
			.meta { color: #555; font-size: 9pt; }
			section { margin-bottom: 20px; page-break-inside: avoid; }
			table { width: 100%; border-collapse: collapse; }
			th, td { text-align: left; padding: 3px 6px; border-bottom: 1px solid #ddd; vertical-align: top; }
			th { border-bottom: 1px solid #999; padding-top: 8px; }
			td.nr, th.nr { width: 60px; color: #555; }
			.num { text-align: right; white-space: nowrap; }
			tr.sum td { font-weight: 600; border-top: 1px solid #999; }
			tr.result td { font-weight: 700; border-top: 2px solid ' . $accent . '; border-bottom: 2px solid ' . $accent . '; }
			.signatures { display: flex; gap: 24px; margin-top: 60px; page-break-inside: avoid; }
			.signatures > div { flex: 1; font-size: 9pt; color: #555; }
			.signatures .line { border-bottom: 1px solid #222; height: 40px; margin-bottom: 4px; }
			.noprint { background: #fffbe6; border: 1px solid #e0d8a0; padding: 8px 12px; margin-bottom: 16px; font-size: 10pt; }
			@media print { .noprint { display: none; } body { padding: 0; } }
			@page { margin: 18mm 15mm; }
			@media (prefers-color-scheme: dark) {
				body { background: #1b1b1b; color: #ddd; }
				h2 { border-color: #444; }
				th, td { border-color: #3a3a3a; }
				th { border-color: #555; }
				tr.sum td { border-color: #555; }
				.meta { color: #aaa; }
				.signatures > div { color: #aaa; }
				.signatures .line { border-color: #ddd; }
				.noprint { background: #3a341a; border-color: #6b6130; color: #eee; }
			}
		';
	}
}
