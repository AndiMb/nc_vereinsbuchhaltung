<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Controller;

use OCA\Vereinsbuchhaltung\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
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

	public function __construct(IRequest $request) {
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
