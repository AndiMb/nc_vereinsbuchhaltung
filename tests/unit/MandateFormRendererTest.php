<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\Mandate;
use OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersion;
use OCA\Vereinsbuchhaltung\Service\MandateFormRenderer;
use OCA\Vereinsbuchhaltung\Service\MandateLegalTextService;
use PHPUnit\Framework\TestCase;

/**
 * Der gerenderte Rechtstext von Mandatsformular-PDF, Zustimmungsseite und
 * Self-Service (Issue #67): der interne Rahmen-Marker aus dem Textkörper darf
 * nie als Text beim Mitglied ankommen.
 */
class MandateFormRendererTest extends TestCase {

	private function renderer(): MandateFormRenderer {
		return new MandateFormRenderer();
	}

	private function version(string $body): MandateLegalTextVersion {
		$version = new MandateLegalTextVersion();
		$version->setBody($body);
		return $version;
	}

	public function testRahmenMarkerErscheintNieImGerendertenText(): void {
		$body = 'Pflichtblock für {{creditor_name}}.' . MandateLegalTextService::RAHMEN_MARKER;

		$html = $this->renderer()->renderLegalText($this->version($body), 'Testverein');

		$this->assertStringNotContainsString('vbh:rahmen', $html);
		$this->assertStringNotContainsString('&lt;!--', $html);
		$this->assertSame('<p>Pflichtblock für Testverein.</p>', $html);
	}

	public function testRahmenWirdAlsEigenerAbsatzAngehaengt(): void {
		$body = 'Pflichtblock.' . MandateLegalTextService::RAHMEN_MARKER . 'Rahmen des Vereins.';

		$html = $this->renderer()->renderLegalText($this->version($body), 'Testverein');

		$this->assertSame('<p>Pflichtblock.</p><p>Rahmen des Vereins.</p>', $html);
	}

	/**
	 * Issue #106: das Mandat ist ein deutsches Dokument. Weder der Platzhalter-
	 * Ersatz mitten im Rechtstext noch die Beschriftungen der Pflichtangaben
	 * hängen von einer Übersetzung ab.
	 */
	public function testOhneVereinsnamenStehtDenVereinMittenImDeutschenSatz(): void {
		$html = $this->renderer()->renderLegalText($this->version('Ich ermächtige {{creditor_name}}, zu buchen.'), '');

		$this->assertSame('<p>Ich ermächtige den Verein, zu buchen.</p>', $html);
	}

	public function testPflichtangabenSindDeutsch(): void {
		$mandate = new Mandate();
		$mandate->setMandateReference('M-1');
		$mandate->setAccountHolder('Echo & Söhne');
		$mandate->setIban('DE12500105170648489890');

		$html = $this->renderer()->renderDataBlock($mandate, 'DE98ZZZ09999999999', '2026-10-05');

		foreach (['Mandatsreferenz', 'Kontoinhaber', 'IBAN', 'Gläubiger-Identifikationsnummer', 'Zahlungsart', 'Datum'] as $label) {
			$this->assertStringContainsString('<dt>' . $label . '</dt>', $html);
		}
		$this->assertStringContainsString('<dd>wiederkehrende Zahlung (SEPA-Basislastschrift)</dd>', $html);
		$this->assertStringContainsString('<dd>Echo &amp; Söhne</dd>', $html);
		$this->assertStringContainsString('<dd>2026-10-05</dd>', $html);
	}
}
