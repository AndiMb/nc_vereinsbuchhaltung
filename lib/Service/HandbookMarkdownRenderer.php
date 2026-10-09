<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Schlanker Markdown-Renderer für HANDBUCH.md / HANDBUCH.en.md.
 *
 * Bewusst kein Markdown-Parser als Composer-Abhängigkeit, sondern ein
 * Eigenbau, der genau die im Handbuch genutzte Syntax abdeckt:
 *
 * - Überschriften (# bis ######), Trennlinien (---)
 * - Absätze (aufeinanderfolgende Zeilen werden zu einem Absatz)
 * - Aufzählungen (- / *) und nummerierte Listen (1.), jeweils mit
 *   eingerückten Fortsetzungszeilen; verschachtelt wird nicht, eine
 *   eingerückte Zeile wie „  1. Januar 2037 …" bleibt Fließtext
 * - Zitate (> …), darin wieder alle Blöcke (Absätze, Listen)
 * - Tabellen (| a | b | mit Trennzeile, Ausrichtung per :---: / ---:)
 * - Fett, Kursiv, Code-Spans und Links
 *
 * Überschriften bekommen zwei Anker: ein sprachabhängiges id-Attribut
 * (slugify des – ggf. übersetzten – Überschriftentexts) sowie zusätzlich
 * einen stabilen, sprachunabhängigen Anker "section-<Kapitelnummer>" direkt
 * davor. Das HelpModal im Frontend verlinkt auf Letzteren, damit ein
 * Kapitel-Deep-Link unabhängig davon funktioniert, ob die deutsche oder die
 * englische Fassung ausgeliefert wird (siehe sectionAnchor()).
 *
 * Die Links des Inhaltsverzeichnisses sind GitHub-Anker (#1-worum-es-geht…).
 * Sie werden auf die ids der Überschriften umgebogen (collectAnchors()),
 * sodass das Inhaltsverzeichnis auch in der ausgelieferten Seite trägt.
 */
class HandbookMarkdownRenderer {

	private const CODE_OPEN = "\u{E000}";
	private const CODE_CLOSE = "\u{E001}";

	/** @var array<string, string> GitHub-Anker => id der Überschrift in der ausgelieferten Seite */
	private array $anchors = [];

	public function render(string $md): string {
		$lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $md));
		$this->anchors = $this->collectAnchors($lines);
		return $this->blocks($lines);
	}

	/**
	 * @param list<string> $lines
	 */
	private function blocks(array $lines): string {
		$html = '';
		$para = [];

		$count = count($lines);
		$i = 0;
		while ($i < $count) {
			$line = rtrim($lines[$i]);

			if (trim($line) === '') {
				$html .= $this->flushParagraph($para);
				$i++;
				continue;
			}
			if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
				$html .= $this->flushParagraph($para);
				$level = strlen($m[1]);
				$sectionAnchor = $this->sectionAnchor($m[2]);
				if ($sectionAnchor !== null) {
					$html .= "<a id=\"{$sectionAnchor}\"></a>";
				}
				$html .= "<h{$level} id=\"" . $this->slugify($m[2]) . '">' . $this->inline($m[2]) . "</h{$level}>";
				$i++;
				continue;
			}
			if (preg_match('/^-{3,}$/', trim($line))) {
				$html .= $this->flushParagraph($para);
				$html .= '<hr>';
				$i++;
				continue;
			}
			if ($this->isTableStart($lines, $i)) {
				$html .= $this->flushParagraph($para);
				$html .= $this->tableBlock($lines, $i);
				continue;
			}
			if (str_starts_with($line, '>')) {
				$html .= $this->flushParagraph($para);
				$inner = [];
				while ($i < $count && str_starts_with(rtrim($lines[$i]), '>')) {
					$inner[] = (string)preg_replace('/^>\s?/', '', rtrim($lines[$i]));
					$i++;
				}
				$html .= '<blockquote>' . $this->blocks($inner) . '</blockquote>';
				continue;
			}
			if ($this->listItem($line) !== null) {
				$html .= $this->flushParagraph($para);
				$html .= $this->listBlock($lines, $i);
				continue;
			}
			$para[] = trim($line);
			$i++;
		}
		$html .= $this->flushParagraph($para);
		return $html;
	}

	/**
	 * Gibt den gesammelten Absatz als <p> zurück und leert die Sammlung (leer = nichts).
	 *
	 * @param list<string> $para
	 */
	private function flushParagraph(array &$para): string {
		if ($para === []) {
			return '';
		}
		$html = '<p>' . $this->inline(implode(' ', $para)) . '</p>';
		$para = [];
		return $html;
	}

	/**
	 * Listenpunkt am Zeilenanfang: ['ordered' => bool, 'number' => int, 'text' => string] oder null.
	 *
	 * @return array{ordered: bool, number: int, text: string}|null
	 */
	private function listItem(string $line): ?array {
		if (preg_match('/^[-*]\s+(.*)$/', $line, $m)) {
			return ['ordered' => false, 'number' => 0, 'text' => $m[1]];
		}
		if (preg_match('/^(\d+)\.\s+(.*)$/', $line, $m)) {
			return ['ordered' => true, 'number' => (int)$m[1], 'text' => $m[2]];
		}
		return null;
	}

	/**
	 * Eine zusammenhängende Liste: Punkte gleicher Art, eingerückte Zeilen gehören zum vorigen Punkt,
	 * eine Leerzeile trennt die Liste nur, wenn danach kein Punkt derselben Art folgt.
	 *
	 * @param list<string> $lines
	 */
	private function listBlock(array $lines, int &$i): string {
		$count = count($lines);
		$first = $this->listItem(rtrim($lines[$i]));
		if ($first === null) {
			return '';
		}
		$ordered = $first['ordered'];
		$items = [];

		while ($i < $count) {
			$line = rtrim($lines[$i]);
			$item = $this->listItem($line);
			if ($item !== null) {
				if ($item['ordered'] !== $ordered) {
					break;
				}
				$items[] = [$item['text']];
				$i++;
				continue;
			}
			if ($line === '') {
				$next = $i + 1;
				while ($next < $count && trim($lines[$next]) === '') {
					$next++;
				}
				$nextItem = $next < $count ? $this->listItem(rtrim($lines[$next])) : null;
				if ($nextItem === null || $nextItem['ordered'] !== $ordered) {
					break;
				}
				$i = $next;
				continue;
			}
			if ($line[0] === ' ' || $line[0] === "\t") {
				$items[count($items) - 1][] = trim($line);
				$i++;
				continue;
			}
			break;
		}

		$tag = $ordered ? 'ol' : 'ul';
		$start = $ordered && $first['number'] > 1 ? ' start="' . $first['number'] . '"' : '';
		$html = "<{$tag}{$start}>";
		foreach ($items as $parts) {
			$html .= '<li>' . $this->inline(implode(' ', $parts)) . '</li>';
		}
		return $html . "</{$tag}>";
	}

	/**
	 * @param list<string> $lines
	 */
	private function isTableStart(array $lines, int $i): bool {
		return str_starts_with(trim($lines[$i]), '|')
			&& isset($lines[$i + 1])
			&& preg_match('/^\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?$/', trim($lines[$i + 1])) === 1;
	}

	/**
	 * Kopfzeile, Trennzeile mit Ausrichtung, dann alle folgenden |-Zeilen als Körper.
	 *
	 * @param list<string> $lines
	 */
	private function tableBlock(array $lines, int &$i): string {
		$head = $this->cells($lines[$i]);
		$aligns = array_map(static function (string $spec): string {
			$left = str_starts_with($spec, ':');
			$right = str_ends_with($spec, ':');
			return $left && $right ? 'c' : ($right ? 'r' : '');
		}, $this->cells($lines[$i + 1]));
		$i += 2;

		$cell = function (string $tag, int $col, string $text) use ($aligns): string {
			$class = ($aligns[$col] ?? '') !== '' ? ' class="al-' . $aligns[$col] . '"' : '';
			return "<{$tag}{$class}>" . $this->inline($text) . "</{$tag}>";
		};

		$html = '<div class="table-wrap"><table><thead><tr>';
		foreach ($head as $col => $text) {
			$html .= $cell('th', $col, $text);
		}
		$html .= '</tr></thead><tbody>';

		$count = count($lines);
		while ($i < $count && str_starts_with(trim($lines[$i]), '|')) {
			$html .= '<tr>';
			$row = $this->cells($lines[$i]);
			for ($col = 0, $cols = count($head); $col < $cols; $col++) {
				$html .= $cell('td', $col, $row[$col] ?? '');
			}
			$html .= '</tr>';
			$i++;
		}
		return $html . '</tbody></table></div>';
	}

	/**
	 * Zellen einer |-Zeile; "\|" bleibt ein Pipe-Zeichen im Zellentext.
	 *
	 * @return list<string>
	 */
	private function cells(string $line): array {
		$line = trim($line);
		$line = (string)preg_replace('/^\|/', '', $line);
		$line = (string)preg_replace('/(?<!\\\\)\|$/', '', $line);
		$cells = preg_split('/(?<!\\\\)\|/', $line);
		return array_map(static fn (string $c): string => trim(str_replace('\|', '|', $c)), $cells === false ? [] : $cells);
	}

	/**
	 * Fließtext-Auszeichnung. Code-Spans zuerst herauslösen (ihr Inhalt bleibt unangetastet), dann
	 * escapen, dann Fett/Kursiv und Links.
	 */
	private function inline(string $s): string {
		$codes = [];
		$s = (string)preg_replace_callback('/`([^`\n]+)`/', function (array $m) use (&$codes): string {
			$codes[] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
			return self::CODE_OPEN . (count($codes) - 1) . self::CODE_CLOSE;
		}, $s);

		$s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
		$s = (string)preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
		$s = (string)preg_replace('/(?<!\*)\*([^*\n]+?)\*(?!\*)/', '<em>$1</em>', $s);
		$s = (string)preg_replace_callback('/\[([^\]]+)\]\(([^)\s]*)\)/', fn (array $m): string => $this->link($m[1], $m[2]), $s);

		return (string)preg_replace_callback(
			'/' . self::CODE_OPEN . '(\d+)' . self::CODE_CLOSE . '/u',
			static fn (array $m): string => $codes[(int)$m[1]],
			$s,
		);
	}

	/**
	 * Seiteninterne Anker (Inhaltsverzeichnis) werden auf die Überschriften-ids umgebogen, http(s)-Links
	 * öffnen in neuem Tab. Relative Verweise auf andere Dateien (HANDBUCH.en.md) gäbe es in der
	 * ausgelieferten Seite nicht – dort bleibt nur der Text.
	 *
	 * $text und $href sind bereits HTML-escaped.
	 */
	private function link(string $text, string $href): string {
		if (str_starts_with($href, '#')) {
			$fragment = rawurldecode(html_entity_decode(substr($href, 1), ENT_QUOTES, 'UTF-8'));
			$target = $this->anchors[$fragment] ?? $fragment;
			return '<a href="#' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '">' . $text . '</a>';
		}
		if (preg_match('#^https?://#i', $href) === 1) {
			return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $text . '</a>';
		}
		return $text;
	}

	/**
	 * GitHub-Anker aller Überschriften auf unsere ids abbilden.
	 *
	 * @param list<string> $lines
	 * @return array<string, string>
	 */
	private function collectAnchors(array $lines): array {
		$anchors = [];
		foreach ($lines as $line) {
			if (preg_match('/^#{1,6}\s+(.*?)\s*$/', $line, $m) !== 1) {
				continue;
			}
			$github = $this->githubSlug($m[1]);
			if (!isset($anchors[$github])) {
				$anchors[$github] = $this->slugify($m[1]);
			}
		}
		return $anchors;
	}

	/**
	 * Anker, wie GitHub sie aus einer Überschrift bildet: Kleinbuchstaben, Satzzeichen weg, Leerzeichen
	 * einzeln zu Bindestrichen ("1. Worum es geht – und mehr" -> "1-worum-es-geht--und-mehr").
	 */
	private function githubSlug(string $heading): string {
		$s = str_replace(['`', '*'], '', $heading);
		$s = mb_strtolower($s);
		$s = (string)preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $s);
		return str_replace(' ', '-', $s);
	}

	/**
	 * "2. Ersteinrichtung (einmalig)" -> "section-2", "2.2 Kontenrahmen anlegen"
	 * -> "section-2-2". Liefert null für Überschriften ohne Kapitelnummer
	 * (Haupttitel, Inhaltsverzeichnis) – die verlinkt niemand von außen an.
	 */
	private function sectionAnchor(string $heading): ?string {
		if (!preg_match('/^(\d+)(?:\.(\d+))?\.?\s/', trim($heading), $m)) {
			return null;
		}
		return isset($m[2]) ? "section-{$m[1]}-{$m[2]}" : "section-{$m[1]}";
	}

	private function slugify(string $s): string {
		$s = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], mb_strtolower($s));
		$s = preg_replace('/[^a-z0-9]+/', '-', $s);
		return trim((string)$s, '-');
	}
}
