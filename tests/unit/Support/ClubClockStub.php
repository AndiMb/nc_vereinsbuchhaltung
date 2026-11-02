<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Tests\Unit\Support;

use OCA\Vereinsbuchhaltung\Service\ClubClock;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Vereinsuhr für Tests (Issue #263): entweder auf einen festen UTC-Zeitpunkt
 * gestellt oder mit der echten Systemzeit. Ein nacktes
 * createMock(ITimeFactory::class) liefert für getDateTime() eine Attrappe,
 * deren format() leer bleibt – „heute“ wäre dann ''.
 */
final class ClubClockStub {

	/** Fester Zeitpunkt, angegeben in UTC (z. B. '2026-06-30 22:30:00'). */
	public static function at(string $utc): ClubClock {
		return new ClubClock(self::timeFactory(new \DateTimeImmutable($utc, new \DateTimeZone('UTC'))));
	}

	/** Die echte Uhr – für Tests, die „heute“ selbst ausrechnen. */
	public static function system(): ClubClock {
		return new ClubClock(self::timeFactory(null));
	}

	private static function timeFactory(?\DateTimeImmutable $fixed): ITimeFactory {
		return new class($fixed) implements ITimeFactory {
			public function __construct(
				private ?\DateTimeImmutable $fixed,
			) {
			}

			public function now(): \DateTimeImmutable {
				return $this->fixed ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
			}

			public function getTime(): int {
				return $this->now()->getTimestamp();
			}

			public function getDateTime(string $time = 'now', ?\DateTimeZone $timezone = null): \DateTime {
				return \DateTime::createFromImmutable($this->now());
			}

			public function withTimeZone(\DateTimeZone $timezone): static {
				return $this;
			}

			public function getTimeZone(?string $timezone = null): \DateTimeZone {
				return new \DateTimeZone('UTC');
			}
		};
	}
}
