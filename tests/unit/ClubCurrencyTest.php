<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit;

use OCA\Vereinsbuchhaltung\Service\ClubCurrency;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Issue #202: einstellbare Vereinswährung. Deutsches Zahlenformat bleibt,
 * nur das Zeichen wechselt; ohne Einstellung ist alles wie bisher Euro.
 */
class ClubCurrencyTest extends TestCase {

	private function currency(string $stored): ClubCurrency {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $key === ClubCurrency::SETTING ? $stored : $default
		);
		return new ClubCurrency($config);
	}

	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $p = []) => vsprintf($text, $p));
		return $l10n;
	}

	public function testDefaultsToEuro(): void {
		$c = $this->currency('');
		$this->assertSame('EUR', $c->code());
		$this->assertSame('€', $c->symbol());
		$this->assertTrue($c->isEuro());
		$this->assertSame('1.234,50 €', $c->format(123450));
	}

	public function testChf(): void {
		$c = $this->currency('CHF');
		$this->assertSame('-42,00 CHF', $c->format(-4200));
		$this->assertSame('0,05', $c->formatNumber(5));
		$this->assertSame('CHF', $c->symbol());
		$this->assertFalse($c->isEuro());
	}

	public function testLowercaseStoredCodeIsAccepted(): void {
		$this->assertSame('GBP', $this->currency(' gbp ')->code());
		$this->assertSame('£', $this->currency('gbp')->symbol());
	}

	public function testUnknownFallsBackToEuro(): void {
		$this->assertSame('EUR', $this->currency('usd')->code());
		$this->assertSame('EUR', $this->currency('XYZ')->code());
	}

	public function testWithSymbol(): void {
		$this->assertSame('12.50 CHF', $this->currency('CHF')->withSymbol('12.50'));
		$this->assertSame('12,50 €', $this->currency('EUR')->withSymbol('12,50'));
	}

	public function testIsSupported(): void {
		$this->assertTrue(ClubCurrency::isSupported('CZK'));
		$this->assertFalse(ClubCurrency::isSupported('USD'));
		$this->assertCount(8, ClubCurrency::SUPPORTED);
	}

	public function testAssertSepaAvailableThrowsForChf(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('CHF');
		$this->currency('CHF')->assertSepaAvailable($this->l10n());
	}

	public function testAssertSepaAvailablePassesForEur(): void {
		$this->currency('EUR')->assertSepaAvailable($this->l10n());
		$this->addToAssertionCount(1);
	}

	/** Eingabewerte dürfen jedes Zeichen und jeden Code der Liste tragen (Tabellenimport, #202). */
	public function testInputTokensListAllSymbolsAndCodes(): void {
		$tokens = ClubCurrency::inputTokens();
		foreach (['€', '£', 'EUR', 'CHF', 'GBP', 'DKK', 'SEK', 'NOK', 'PLN', 'CZK'] as $t) {
			$this->assertContains($t, $tokens);
		}
	}

	/** Belege mit eigener Währung (Rechnungen) zeigen ihre Währung, nicht die aktuelle Vereinswährung. */
	public function testFormatInNutztDieUebergebeneWaehrung(): void {
		$chf = $this->currency('CHF');
		$this->assertSame('1.234,50 €', $chf->formatIn('EUR', 123450));
		$this->assertSame('1.234,50 CHF', $chf->formatIn('chf', 123450));
		$this->assertSame('1.234,50 USD', $chf->formatIn('USD', 123450));
	}
}
