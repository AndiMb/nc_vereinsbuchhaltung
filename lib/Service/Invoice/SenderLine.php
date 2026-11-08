<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Invoice;

/**
 * Absenderzeile über dem Anschriftenfeld (DIN 5008), z. B.
 * „TSV Beispiel e. V. · Hauptstr. 1 · 12345 Beispielstadt“.
 *
 * Sie muss einzeilig bleiben, damit sie im Fenster des Umschlags steht (#238).
 * Passt sie nicht, wird zuerst die Schrift kleiner (bis MIN_SIZE), dann
 * entfällt die Straße. Was selbst so nicht passt, staucht der Renderer.
 */
final class SenderLine {
	public const SIZE = 6.5;
	public const MIN_SIZE = 5.0;

	/**
	 * @param array<string, string> $issuer siehe InvoiceSettings::issuer()
	 * @param callable(string, float): float $measure Breite eines Textes in mm bei einer Schriftgröße in pt
	 * @return array{0: string, 1: float} Text und Schriftgröße
	 */
	public static function fit(array $issuer, callable $measure, float $width): array {
		$name = $issuer['name'] ?? '';
		$place = trim(($issuer['postalCode'] ?? '') . ' ' . ($issuer['city'] ?? ''));
		$candidates = [
			self::join([$name, $issuer['street'] ?? '', $place]),
			self::join([$name, $place]),
		];
		foreach ($candidates as $text) {
			$natural = $measure($text, self::SIZE);
			if ($natural <= $width) {
				return [$text, self::SIZE];
			}
			// Die Breite wächst linear mit der Schriftgröße; abgerundet auf 0,1 pt.
			$size = floor(self::SIZE * $width / $natural * 10) / 10;
			if ($size >= self::MIN_SIZE) {
				return [$text, $size];
			}
		}
		return [$candidates[1], self::MIN_SIZE];
	}

	/** @param list<string> $parts */
	private static function join(array $parts): string {
		return implode(' · ', array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== '')));
	}
}
