<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\Report\BuiltinReportTemplates;
use OCA\Vereinsbuchhaltung\Service\Report\ReportDefinition;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Form und Regeln einer Gliederungsbericht-Vorlage.
 */
class ReportDefinitionTest extends TestCase {

	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return $l10n;
	}

	/** @return array<string, mixed> */
	private function minimal(array $override = []): array {
		return array_replace([
			'columns' => [['source' => 'actual']],
			'sections' => [['title' => 'Alles', 'levels' => [['by' => 'account']]]],
		], $override);
	}

	/** @return list<string> */
	private function errors(array $raw): array {
		return ReportDefinition::validate(ReportDefinition::normalize($raw), $this->l10n());
	}

	public function testNormalisierenSetztStandardwerte(): void {
		$def = ReportDefinition::normalize($this->minimal());

		$this->assertSame(1, $def['version']);
		$this->assertSame([['source' => 'actual', 'periodOffset' => 0]], $def['columns']);
		$this->assertFalse($def['showPlanNotes']);
		$this->assertNull($def['grandTotal']);
		$section = $def['sections'][0];
		$this->assertSame(['types' => null, 'spheres' => null, 'groups' => null], $section['filter']);
		$this->assertNull($section['sections']);
		$this->assertSame(
			[['by' => 'account', 'footer' => null, 'hideEmpty' => true, 'depth' => 'plan', 'ancestors' => false]],
			$section['levels'],
		);
	}

	public function testNormalisierenVertraegtUnsinn(): void {
		$def = ReportDefinition::normalize('kein JSON-Objekt');
		$this->assertSame([], $def['sections']);
		$this->assertNotSame([], ReportDefinition::validate($def, $this->l10n()));

		$def = ReportDefinition::normalize(['sections' => [['filter' => ['types' => ['income', 'income']], 'evil' => 1]]]);
		$this->assertSame(['income'], $def['sections'][0]['filter']['types'], 'doppelte Werte fallen weg');
		$this->assertArrayNotHasKey('evil', $def['sections'][0]);
	}

	public function testLeererFilterHeisstKeineEinschraenkung(): void {
		$def = ReportDefinition::normalize($this->minimal(['sections' => [['filter' => ['spheres' => []], 'levels' => [['by' => 'type']]]]]));
		$this->assertNull($def['sections'][0]['filter']['spheres']);
	}

	public function testGueltigeMinimalvorlage(): void {
		$this->assertSame([], $this->errors($this->minimal()));
	}

	public function testMitgelieferteVorlagenSindGueltig(): void {
		foreach ((new BuiltinReportTemplates($this->l10n()))->all() as $template) {
			$this->assertSame([], ReportDefinition::validate($template['definition'], $this->l10n()), $template['id']);
		}
	}

	public function testSpaltenregeln(): void {
		$this->assertContains('Die Vorlage braucht mindestens eine Spalte.', $this->errors($this->minimal(['columns' => []])));
		$this->assertContains('Unbekannte Spaltenart „forecast“.', $this->errors($this->minimal(['columns' => [['source' => 'forecast']]])));
		$this->assertContains('Eine Spalte kann höchstens 3 Perioden zurückliegen.', $this->errors($this->minimal(['columns' => [['source' => 'plan', 'periodOffset' => -4]]])));
		$this->assertContains('Eine Spalte kann höchstens 3 Perioden zurückliegen.', $this->errors($this->minimal(['columns' => [['source' => 'plan', 'periodOffset' => 1]]])));
		$this->assertContains('Höchstens 8 Spalten sind möglich.', $this->errors($this->minimal(['columns' => array_fill(0, 9, ['source' => 'plan'])])));
	}

	public function testKontoNurAlsLetzteEbene(): void {
		$errors = $this->errors($this->minimal(['sections' => [['levels' => [['by' => 'account'], ['by' => 'type']]]]]));
		$this->assertContains('„Konto“ kann nur die letzte Gliederungsebene sein.', $errors);
	}

	public function testMerkmalNurEinmalJeAbschnitt(): void {
		$errors = $this->errors($this->minimal(['sections' => [['levels' => [['by' => 'group'], ['by' => 'group']]]]]));
		$this->assertContains('Jedes Merkmal darf in einem Abschnitt nur eine Gliederungsebene bilden.', $errors);
	}

	public function testUnbekannteWerte(): void {
		$errors = $this->errors($this->minimal(['sections' => [[
			'filter' => ['types' => ['asset'], 'spheres' => ['steuerfrei'], 'groups' => ['51']],
			'levels' => [['by' => 'color'], ['by' => 'account', 'depth' => 'tief']],
		]]]));
		$this->assertContains('Unbekannte Kontoart „asset“.', $errors);
		$this->assertContains('Unbekannte Sphäre „steuerfrei“.', $errors);
		$this->assertContains('Unbekannte Berichtsgruppe „51“ im Filter.', $errors);
		$this->assertContains('Unbekannte Gliederungsebene „color“.', $errors);
		$this->assertContains('Ungültige Kontotiefe „tief“.', $errors);
	}

	public function testOhneSphaereIstErlaubt(): void {
		$this->assertSame([], $this->errors($this->minimal(['sections' => [['filter' => ['spheres' => ['none', 'ideell']], 'levels' => [['by' => 'account']]]]])));
	}

	public function testAbschnittBrauchtGenauEinenInhalt(): void {
		$both = $this->errors($this->minimal(['sections' => [['sections' => [], 'levels' => []]]]));
		$neither = $this->errors($this->minimal(['sections' => [['title' => 'leer']]]));
		$this->assertContains('Ein Abschnitt enthält entweder Unterabschnitte oder Gliederungsebenen.', $both);
		$this->assertContains('Ein Abschnitt enthält entweder Unterabschnitte oder Gliederungsebenen.', $neither);
		$this->assertContains('Ein Abschnitt braucht mindestens eine Gliederungsebene.', $this->errors($this->minimal(['sections' => [['levels' => []]]])));
		$this->assertContains('Ein Abschnitt mit Unterabschnitten braucht mindestens einen davon.', $this->errors($this->minimal(['sections' => [['sections' => []]]])));
		$this->assertContains('Die Vorlage braucht mindestens einen Abschnitt.', $this->errors($this->minimal(['sections' => []])));
	}

	public function testHoechstensVierEbenenTief(): void {
		$leaf = ['levels' => [['by' => 'account']]];
		$nest = static fn (array $inner): array => ['sections' => [$inner]];
		$this->assertSame([], $this->errors($this->minimal(['sections' => [$nest($nest($nest($leaf)))]])));
		$this->assertContains(
			'Abschnitte lassen sich höchstens 4 Ebenen tief verschachteln.',
			$this->errors($this->minimal(['sections' => [$nest($nest($nest($nest($leaf))))]])),
		);
	}

	public function testGruppenregeln(): void {
		$errors = $this->errors($this->minimal(['groups' => [
			['key' => 'a', 'label' => 'Alpha', 'costCenters' => ['51'], 'accounts' => [7]],
			['key' => 'a', 'label' => 'Alpha 2', 'costCenters' => ['51'], 'accounts' => [7]],
			['key' => 'mit leerzeichen', 'label' => ''],
		]]));
		$this->assertContains('Die Berichtsgruppe „Alpha 2“ ist doppelt angelegt.', $errors);
		$this->assertContains('Die Auswertungsgruppe „51“ ist mehreren Berichtsgruppen zugeordnet.', $errors);
		$this->assertContains('Ein Konto ist mehreren Berichtsgruppen zugeordnet.', $errors);
		$this->assertContains('Ungültiger Schlüssel einer Berichtsgruppe.', $errors);
		$this->assertContains('Jede Berichtsgruppe braucht einen Namen.', $errors);
	}

	public function testErgebnisNurWennBeideKontoartenErlaubt(): void {
		$this->assertTrue(ReportDefinition::isResult(null));
		$this->assertTrue(ReportDefinition::isResult(['income', 'expense']));
		$this->assertFalse(ReportDefinition::isResult(['income']));
		$this->assertFalse(ReportDefinition::isResult([]));
	}
}
