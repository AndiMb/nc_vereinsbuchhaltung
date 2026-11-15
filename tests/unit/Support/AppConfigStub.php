<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit\Support;

use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeakMap;

/**
 * Schlichte In-Memory-Attrappe für {@see IAppConfig}.
 *
 * Ein "nacktes" createMock(IAppConfig::class) liefert für getValueString()
 * immer '' statt des übergebenen Standardwerts - die Dienste sähen dann nie
 * ihre Vorgabe. Diese Attrappe verhält sich wie die echte Konfiguration:
 * `getValueString($app, $key, $default)` liefert den gespeicherten Wert oder
 * den Standard, `setValueString` und `deleteKey` ändern den Speicher. Die
 * App-ID wird bewusst nicht unterschieden (die Tests fragen nur die Schlüssel
 * ihrer eigenen App ab).
 *
 * Eine Mock-Klasse kann keine eigenen Methoden tragen; den Speicherstand liest
 * deshalb {@see AppConfigStub::values()}.
 */
final class AppConfigStub {

	/** @var WeakMap<IAppConfig, array<string, string>>|null */
	private static ?WeakMap $stores = null;

	/**
	 * @param array<string, string> $values Anfangsstand
	 */
	public static function create(TestCase $t, array $values = []): IAppConfig&MockObject {
		/** @var IAppConfig&MockObject $config */
		$config = (new \ReflectionMethod($t, 'createMock'))->invoke($t, IAppConfig::class);

		self::$stores ??= new WeakMap();
		self::$stores[$config] = $values;

		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '', bool $lazy = false): string => self::$stores[$config][$key] ?? $default,
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false) use ($config): bool {
				self::$stores[$config] = [...self::$stores[$config], $key => $value];
				return true;
			},
		);
		$config->method('deleteKey')->willReturnCallback(
			static function (string $app, string $key) use ($config): void {
				$store = self::$stores[$config];
				unset($store[$key]);
				self::$stores[$config] = $store;
			},
		);
		$config->method('getKeys')->willReturnCallback(
			static fn (string $app): array => array_keys(self::$stores[$config]),
		);
		$config->method('hasKey')->willReturnCallback(
			static fn (string $app, string $key, ?bool $lazy = false): bool => array_key_exists($key, self::$stores[$config]),
		);

		return $config;
	}

	/**
	 * Aktueller Speicherstand einer mit {@see create()} gebauten Attrappe.
	 *
	 * @return array<string, string>
	 */
	public static function values(IAppConfig $config): array {
		return self::$stores[$config] ?? [];
	}
}
