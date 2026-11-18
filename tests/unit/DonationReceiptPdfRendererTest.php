<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\DonationReceipt\DonationReceiptPdfRenderer;
use OCA\Vereinsbuchhaltung\Service\Pdf\ReportPdfFactory;
use OCA\Vereinsbuchhaltung\Tests\Unit\Support\KeepPdf;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Die Sammelbestätigung als PDF (Issue #10): Bestätigung auf Seite 1, die Anlage
 * dahinter, und der Satz zur Steuerbegünstigung je nach Grundlage im Wortlaut des Musters.
 */
class DonationReceiptPdfRendererTest extends TestCase {

	protected function setUp(): void {
		if (!class_exists(\TCPDF::class)) {
			\OCA\Vereinsbuchhaltung\AppInfo\Application::loadComposerAutoloader();
		}
		if (!class_exists(\TCPDF::class)) {
			$this->markTestSkipped('TCPDF ist nicht installiert (composer install).');
		}
	}

	private function renderer(): DonationReceiptPdfRenderer {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $key === 'club_name' ? 'Förderverein Musterschule e. V.' : $default);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));
		return new DonationReceiptPdfRenderer(new ReportPdfFactory($config, $l10n));
	}

	/** @return array<string, mixed> */
	private function club(string $basis = 'freistellung', bool $machine = false): array {
		return [
			'basis' => $basis,
			'taxOffice' => 'Musterstadt',
			'taxNumber' => '12/345/67890',
			'noticeDate' => '2024-03-01',
			'assessmentPeriod' => '2022',
			'purposes' => 'der Bildung und Erziehung (§ 52 Abs. 2 Satz 1 Nr. 7 AO)',
			'signatory' => 'Max Muster, Kassenwart',
			'place' => '',
			'machineSigned' => $machine,
			'issuer' => ['name' => 'Förderverein Musterschule e. V.', 'street' => 'Schulweg 1', 'postalCode' => '12345', 'city' => 'Musterstadt'],
		];
	}

	public function testBestaetigungUndAnlageAufGetrenntenSeiten(): void {
		$items = [];
		for ($m = 1; $m <= 12; $m++) {
			$items[] = ['openItemId' => $m, 'date' => sprintf('2025-%02d-01', $m), 'amountCents' => 500, 'description' => 'Monatsbeitrag'];
		}
		$bytes = $this->renderer()->render([
			'number' => 'ZB-2026-0001',
			'issueDate' => '2026-01-15',
			'periodFrom' => '2025-01-01',
			'periodTo' => '2025-12-31',
			'amountCents' => 6000,
			'donorLines' => ['Erika Mustermann', 'Hauptstraße 5', '12345 Musterstadt'],
			'donorLabel' => 'Erika Mustermann (Mitgliedsnummer 42)',
			'items' => $items,
		], $this->club(), false);
		KeepPdf::to('zuwendungsbestaetigung.pdf', $bytes);

		$this->assertStringStartsWith('%PDF-', $bytes);
		$this->assertStringContainsString('<pdfaid:part>3</pdfaid:part>', $bytes);
		$this->assertSame(2, preg_match_all('/\/Type\s*\/Page\b/', $bytes), 'Seite 1 Bestätigung, Seite 2 Anlage');
	}

	public function testSatzZurFreistellungImWortlautDesMusters(): void {
		$sentence = $this->renderer()->basisSentence($this->club());
		$this->assertSame(
			'Wir sind wegen Förderung der Bildung und Erziehung (§ 52 Abs. 2 Satz 1 Nr. 7 AO) nach dem Freistellungsbescheid bzw. nach der Anlage zum Körperschaftsteuerbescheid des Finanzamtes Musterstadt, StNr. 12/345/67890, vom 01.03.2024 für den letzten Veranlagungszeitraum 2022 nach § 5 Abs. 1 Nr. 9 des Körperschaftsteuergesetzes von der Körperschaftsteuer und nach § 3 Nr. 6 des Gewerbesteuergesetzes von der Gewerbesteuer befreit.',
			$sentence,
		);
	}

	public function testSatzZurFeststellungNach60aAO(): void {
		$sentence = $this->renderer()->basisSentence($this->club('feststellung'));
		$this->assertStringStartsWith('Die Einhaltung der satzungsmäßigen Voraussetzungen nach den §§ 51, 59, 60 und 61 AO wurde vom Finanzamt Musterstadt, StNr. 12/345/67890, mit Bescheid vom 01.03.2024 nach § 60a AO gesondert festgestellt.', $sentence);
		$this->assertStringEndsWith('Wir fördern nach unserer Satzung der Bildung und Erziehung (§ 52 Abs. 2 Satz 1 Nr. 7 AO).', $sentence);
	}
}
