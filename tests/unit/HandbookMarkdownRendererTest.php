<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\HandbookMarkdownRenderer;
use PHPUnit\Framework\TestCase;

class HandbookMarkdownRendererTest extends TestCase {

	private HandbookMarkdownRenderer $renderer;

	protected function setUp(): void {
		$this->renderer = new HandbookMarkdownRenderer();
	}

	public function testTabelleMitKopfAusrichtungUndFormatierung(): void {
		$html = $this->renderer->render(implode("\n", [
			'| Rolle | Lesen | Betrag |',
			'|---|:---:|---:|',
			'| **Revisor** | ✓ | 25 € |',
			'| Buchhalter | `ja` |',
		]));

		$this->assertStringContainsString('<div class="table-wrap"><table><thead><tr><th>Rolle</th><th class="al-c">Lesen</th><th class="al-r">Betrag</th></tr></thead>', $html);
		$this->assertStringContainsString('<tr><td><strong>Revisor</strong></td><td class="al-c">✓</td><td class="al-r">25 €</td></tr>', $html);
		// fehlende Zelle wird aufgefüllt, Code-Span bleibt Code
		$this->assertStringContainsString('<tr><td>Buchhalter</td><td class="al-c"><code>ja</code></td><td class="al-r"></td></tr>', $html);
		$this->assertStringNotContainsString('|---', $html);
	}

	public function testMaskierterSenkrechterStrichBleibtInDerZelle(): void {
		$html = $this->renderer->render("| A | B |\n|---|---|\n| x \\| y | z |");

		$this->assertStringContainsString('<td>x | y</td><td>z</td>', $html);
	}

	public function testTabelleOhneTrennzeileBleibtAbsatz(): void {
		$html = $this->renderer->render('| nur eine Zeile |');

		$this->assertStringNotContainsString('<table', $html);
		$this->assertStringContainsString('<p>| nur eine Zeile |</p>', $html);
	}

	public function testNummerierteListeMitFortsetzungszeilen(): void {
		$html = $this->renderer->render(implode("\n", [
			'1. Erster Punkt',
			'  läuft auf der nächsten Zeile weiter',
			'2. Zweiter Punkt',
			'  1. Januar 2037 ist keine Unterliste',
		]));

		$this->assertSame(
			'<ol><li>Erster Punkt läuft auf der nächsten Zeile weiter</li><li>Zweiter Punkt 1. Januar 2037 ist keine Unterliste</li></ol>',
			$html,
		);
	}

	public function testNummerierteListeBehaeltStartnummer(): void {
		$this->assertSame('<ol start="3"><li>drei</li></ol>', $this->renderer->render('3. drei'));
	}

	public function testAufzaehlungMitLeerzeileZwischenPunktenBleibtEineListe(): void {
		$html = $this->renderer->render("- eins\n\n- zwei\n\nAbsatz");

		$this->assertSame('<ul><li>eins</li><li>zwei</li></ul><p>Absatz</p>', $html);
	}

	public function testListenartWechselBeendetDieListe(): void {
		$this->assertSame(
			'<ul><li>a</li></ul><ol><li>b</li></ol>',
			$this->renderer->render("- a\n1. b"),
		);
	}

	public function testZitatFasstZeilenZuEinemAbsatzZusammen(): void {
		$html = $this->renderer->render("> **Tipp:** erste Zeile\n> zweite Zeile\n>\n> Neuer Absatz");

		$this->assertSame(
			'<blockquote><p><strong>Tipp:</strong> erste Zeile zweite Zeile</p><p>Neuer Absatz</p></blockquote>',
			$html,
		);
	}

	public function testZitatKannListenEnthalten(): void {
		$html = $this->renderer->render("> Vorher:\n>\n> - eins\n> - zwei");

		$this->assertSame('<blockquote><p>Vorher:</p><ul><li>eins</li><li>zwei</li></ul></blockquote>', $html);
	}

	public function testCodeSpanSchuetztInhaltVorFormatierungUndHtml(): void {
		$html = $this->renderer->render('Ordner `<Jahr>/*Beleg*` und **fett mit `code`**');

		$this->assertSame(
			'<p>Ordner <code>&lt;Jahr&gt;/*Beleg*</code> und <strong>fett mit <code>code</code></strong></p>',
			$html,
		);
	}

	public function testHtmlImTextWirdMaskiert(): void {
		$this->assertSame('<p>a &lt;b&gt; &amp; c</p>', $this->renderer->render('a <b> & c'));
	}

	public function testInhaltsverzeichnisLinksZeigenAufDieUeberschriftenIds(): void {
		$html = $this->renderer->render(implode("\n", [
			'1. [Worum es geht – und ein bisschen Buchführung](#1-worum-es-geht--und-ein-bisschen-buchf%C3%BChrung)',
			'',
			'## 1. Worum es geht – und ein bisschen Buchführung',
		]));

		$this->assertStringContainsString('<a href="#1-worum-es-geht-und-ein-bisschen-buchfuehrung">', $html);
		$this->assertStringContainsString('<a id="section-1"></a><h2 id="1-worum-es-geht-und-ein-bisschen-buchfuehrung">', $html);
	}

	public function testLinks(): void {
		$html = $this->renderer->render('[extern](https://example.org/x?a=1&b=2) und [Datei](HANDBUCH.en.md)');

		$this->assertSame(
			'<p><a href="https://example.org/x?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">extern</a> und Datei</p>',
			$html,
		);
	}

	public function testUeberschriftenBekommenStabilenKapitelAnker(): void {
		$html = $this->renderer->render("### 13.4 Beitragsgruppen\n\n#### Ohne Nummer");

		$this->assertStringContainsString('<a id="section-13-4"></a><h3 id="13-4-beitragsgruppen">', $html);
		$this->assertStringContainsString('<h4 id="ohne-nummer">Ohne Nummer</h4>', $html);
		$this->assertStringNotContainsString('section-', substr($html, (int)strpos($html, '<h4')));
	}

	/** @return array<string, array{0:string}> */
	public static function handbuecher(): array {
		return ['deutsch' => ['HANDBUCH.md'], 'englisch' => ['HANDBUCH.en.md']];
	}

	/**
	 * Das ausgelieferte Handbuch darf keine rohe Markdown-Syntax mehr enthalten, und jede
	 * Sprungmarke (Inhaltsverzeichnis) muss auf eine vorhandene Überschrift zeigen.
	 *
	 * @dataProvider handbuecher
	 */
	public function testEchtesHandbuchWirdVollstaendigUmgesetzt(string $datei): void {
		$md = (string)file_get_contents(dirname(__DIR__, 2) . '/' . $datei);
		$html = $this->renderer->render($md);

		$tabellen = preg_match_all('/^\|.*\n\|[-:| ]+\|$/m', $md);
		$this->assertGreaterThan(10, $tabellen, 'Das Handbuch enthält Tabellen');
		$this->assertSame($tabellen, substr_count($html, '<table>'), 'jede Markdown-Tabelle wird zur Tabelle');

		$this->assertStringNotContainsString('|---', $html);
		$this->assertDoesNotMatchRegularExpression('/<p>\s*\|/', $html, 'keine Tabellenzeile als Fließtext');
		$this->assertDoesNotMatchRegularExpression('/<p>\d+\. /', $html, 'keine nummerierte Liste als Fließtext');
		$this->assertStringNotContainsString('**', $html);
		$this->assertStringNotContainsString('`', $html);

		preg_match_all('/<h[1-6] id="([^"]+)"/', $html, $ids);
		preg_match_all('/<a id="([^"]+)"><\/a>/', $html, $anker);
		preg_match_all('/<a href="#([^"]+)"/', $html, $verweise);
		$this->assertNotEmpty($verweise[1], 'Inhaltsverzeichnis ist verlinkt');
		foreach ($verweise[1] as $ziel) {
			$this->assertContains($ziel, array_merge($ids[1], $anker[1]), "Sprungmarke #$ziel hat kein Ziel");
		}
	}
}
