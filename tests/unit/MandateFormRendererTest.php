<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Db\MandateLegalTextVersion;
use OCA\Vereinsbuchhaltung\Service\MandateFormRenderer;
use OCA\Vereinsbuchhaltung\Service\MandateLegalTextService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Der gerenderte Rechtstext von Mandatsformular-PDF, Zustimmungsseite und
 * Self-Service (Issue #67): der interne Rahmen-Marker aus dem Textkörper darf
 * nie als Text beim Mitglied ankommen.
 */
class MandateFormRendererTest extends TestCase {

	private function renderer(): MandateFormRenderer {
		return new MandateFormRenderer($this->createMock(IL10N::class));
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
}
