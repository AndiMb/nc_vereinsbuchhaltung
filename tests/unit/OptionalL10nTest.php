<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\ContributionYearService;
use OCA\Vereinsbuchhaltung\Service\DunningSettings;
use OCA\Vereinsbuchhaltung\Service\MandateExpirySettings;
use OCA\Vereinsbuchhaltung\Service\Sepa\MemberCsvParser;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Die Meldungen der kleinen Einstellungs- und Parser-Klassen laufen über den
 * optionalen `IL10N` ({@see \OCA\Vereinsbuchhaltung\Service\OptionalL10n},
 * Issue #106): ohne ihn der deutsche Quelltext, mit ihm die Übersetzung samt
 * Platzhaltern – diese Meldungen erreichen über die Controller die Oberfläche.
 */
class OptionalL10nTest extends TestCase {

	/** Ein „Übersetzer", der sichtbar macht, dass er gefragt wurde. */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params = []): string => 'EN: ' . vsprintf($text, $params));
		return $l10n;
	}

	public function testOhneL10nBleibtDerDeutscheQuelltextMitEingesetztenGrenzen(): void {
		$settings = new DunningSettings($this->createMock(IConfig::class));

		$this->expectExceptionMessage('Der Mahnabstand muss zwischen 1 und 365 Tagen liegen.');
		$settings->setIntervalDays(0);
	}

	public function testMitL10nWirdUebersetztUndDieGrenzenBleibenErhalten(): void {
		$settings = new DunningSettings($this->createMock(IConfig::class), $this->l10n());

		$this->expectExceptionMessage('EN: Der Mahnabstand muss zwischen 1 und 365 Tagen liegen.');
		$settings->setIntervalDays(0);
	}

	public function testAblaufVorwarnung(): void {
		$settings = new MandateExpirySettings($this->createMock(IConfig::class), $this->l10n());

		$this->expectExceptionMessageMatches('/^EN: Die Ablauf-Vorwarnung muss zwischen \d+ und \d+ Tagen liegen\.$/');
		$settings->setWarningDays(0);
	}

	public function testStartmonat(): void {
		$service = new ContributionYearService($this->createMock(IConfig::class), $this->l10n());

		$this->expectExceptionMessage('EN: Startmonat muss zwischen 1 und 12 liegen.');
		$service->setStartMonth(13);
	}

	public function testImportFehlerDerCsvZeilenWerdenUebersetzt(): void {
		$parser = new MemberCsvParser($this->l10n());

		$result = $parser->parse("Name;E-Mail\nAnna;kein-mail\n");

		$this->assertSame(['EN: Keine gültige E-Mail-Adresse: kein-mail'], $result['rows'][0]['errors']);
		$this->assertSame('EN: Die Datei ist leer.', $parser->parse('')['error']);
	}

	public function testImportFehlerOhneL10nSindDeutsch(): void {
		$result = (new MemberCsvParser())->parse("Name;E-Mail\nAnna;kein-mail\n");

		$this->assertSame(['Keine gültige E-Mail-Adresse: kein-mail'], $result['rows'][0]['errors']);
	}
}
