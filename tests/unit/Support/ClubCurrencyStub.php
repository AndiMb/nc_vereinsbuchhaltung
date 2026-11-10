<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit\Support;

use OCA\Vereinsbuchhaltung\Service\ClubCurrency;
use PHPUnit\Framework\TestCase;

/**
 * Vereinswährung für Tests (Issue #202): eine echte ClubCurrency über einer
 * In-Memory-Konfiguration. Ohne Angabe Euro – so bleiben die Erwartungen der
 * bestehenden Tests („… €“) unverändert gültig.
 */
final class ClubCurrencyStub {

	public static function of(TestCase $t, string $code = 'EUR'): ClubCurrency {
		return new ClubCurrency(AppConfigStub::create($t, [ClubCurrency::SETTING => $code]));
	}
}
