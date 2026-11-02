<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Die Uhr des Vereins (Issue #263): fachliche Tagesdaten – „heute“,
 * Fälligkeit, Überfälligkeit, Unterschriftsdatum, gedruckte „Stand:“-Angaben –
 * zählen in der Zeitzone des Servers (`default_timezone` in config.php), sonst
 * in Europe/Berlin. Nextcloud stellt PHP selbst auf UTC; ohne diese Uhr wäre
 * zwischen 00:00 und 02:00 Uhr deutscher Zeit noch „gestern“.
 *
 * Gespeicherte Zeitstempel (`created_at`, `updated_at`, `…_at` mit Uhrzeit)
 * bleiben UTC und laufen nicht über diese Klasse.
 *
 * Die Zone setzt {@see \OCA\Vereinsbuchhaltung\AppInfo\Application::boot()}
 * einmal je Request; sie ist statisch, damit auch Entitäten ohne
 * Dependency Injection denselben Tag sehen.
 */
final class ClubClock {
	private const FALLBACK_ZONE = 'Europe/Berlin';

	private static ?\DateTimeZone $zone = null;

	public function __construct(
		private ITimeFactory $time,
	) {
	}

	/** Die gesetzte Vereinszone, sonst Europe/Berlin. */
	public static function zone(): \DateTimeZone {
		return self::$zone ?? new \DateTimeZone(self::FALLBACK_ZONE);
	}

	/** Ein leerer oder unbekannter Name bedeutet Europe/Berlin. */
	public static function configureZone(string $name): void {
		$name = trim($name);
		if ($name === '') {
			self::$zone = null;
			return;
		}
		try {
			self::$zone = new \DateTimeZone($name);
		} catch (\Exception) {
			self::$zone = null;
		}
	}

	/** Jetzt, in der Vereinszone. */
	public function now(): \DateTimeImmutable {
		return \DateTimeImmutable::createFromMutable($this->time->getDateTime())->setTimezone(self::zone());
	}

	/** Der Kalendertag des Vereins, JJJJ-MM-TT. */
	public function today(): string {
		return $this->now()->format('Y-m-d');
	}

	/**
	 * Kalendertag des Vereins zu einem gespeicherten UTC-Zeitstempel
	 * (`Y-m-d H:i:s` ohne Zone gilt als UTC, ATOM trägt seine Zone selbst).
	 * Fristen, die ab einem Versand- oder Abschlusszeitpunkt laufen, und
	 * gedruckte Tagesangaben dazu rechnen mit diesem Tag statt mit dem
	 * UTC-Datumsteil – sonst läge ein Versand um 00:30 Uhr auf gestern.
	 *
	 * Reine Tagesdaten (JJJJ-MM-TT) kommen unverändert zurück, ebenso ''.
	 * Unlesbare Werte liefern wie bisher ihre ersten zehn Zeichen.
	 */
	public static function localDate(string $utcTimestamp): string {
		$value = trim($utcTimestamp);
		if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			return $value;
		}
		try {
			return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(self::zone())->format('Y-m-d');
		} catch (\Exception) {
			return substr($value, 0, 10);
		}
	}

	/**
	 * Gespeicherter UTC-Zeitstempel als Zeitpunkt in der Vereinszone – für
	 * gedruckte Uhrzeiten, die zum Vereinstag aus {@see localDate()} passen
	 * müssen. Leere oder unlesbare Werte liefern null.
	 */
	public static function localDateTime(string $utcTimestamp): ?\DateTimeImmutable {
		$value = trim($utcTimestamp);
		if ($value === '') {
			return null;
		}
		try {
			return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(self::zone());
		} catch (\Exception) {
			return null;
		}
	}

	/** Für Entitäten und statische Helfer ohne Dependency Injection. */
	public static function todayFromSystemClock(): string {
		return (new \DateTimeImmutable('now', self::zone()))->format('Y-m-d');
	}
}
