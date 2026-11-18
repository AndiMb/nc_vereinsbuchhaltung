<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\DonationReceipt;

/**
 * Ein Euro-Betrag in deutschen Worten, für das Feld „Gesamtbetrag der
 * Zuwendung – in Buchstaben –“ der Zuwendungsbestätigung (Issue #10).
 *
 * Das BMF-Schreiben vom 07.11.2013 (Nr. 5) verlangt den Betrag in Ziffern und
 * in Buchstaben; ein Wort wie „eintausenddreihundertzweiundzwanzig“ ist die
 * übliche Form. Die Bestätigung ist ein deutsches Steuerdokument, daher
 * ausdrücklich nicht übersetzt.
 */
final class AmountInWords {

	private const UNITS = ['null', 'eins', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht', 'neun',
		'zehn', 'elf', 'zwölf', 'dreizehn', 'vierzehn', 'fünfzehn', 'sechzehn', 'siebzehn', 'achtzehn', 'neunzehn'];
	private const TENS = [2 => 'zwanzig', 3 => 'dreißig', 4 => 'vierzig', 5 => 'fünfzig',
		6 => 'sechzig', 7 => 'siebzig', 8 => 'achtzig', 9 => 'neunzig'];

	/** z. B. 132250 → „eintausenddreihundertzweiundzwanzig Euro und fünfzig Cent“ */
	public static function euros(int $cents): string {
		if ($cents < 0) {
			throw new \InvalidArgumentException('Negative amounts cannot be spelled out.');
		}
		$euros = intdiv($cents, 100);
		$rest = $cents % 100;
		$text = $euros === 1 ? 'ein Euro' : self::number($euros) . ' Euro';
		if ($rest > 0) {
			$text .= ' und ' . ($rest === 1 ? 'ein Cent' : self::number($rest) . ' Cent');
		}
		return $text;
	}

	/** Eine nicht negative ganze Zahl in Worten, z. B. 1322 → „eintausenddreihundertzweiundzwanzig“. */
	public static function number(int $n): string {
		if ($n < 0) {
			throw new \InvalidArgumentException('Negative numbers cannot be spelled out.');
		}
		if ($n === 0) {
			return 'null';
		}
		$parts = [];
		$millions = intdiv($n, 1_000_000);
		$rest = $n % 1_000_000;
		if ($millions > 0) {
			$parts[] = $millions === 1 ? 'eine Million' : self::number($millions) . ' Millionen';
		}
		if ($rest > 0) {
			$parts[] = self::belowMillion($rest);
		}
		return implode(' ', $parts);
	}

	private static function belowMillion(int $n): string {
		$thousands = intdiv($n, 1000);
		$rest = $n % 1000;
		$text = $thousands > 0 ? self::prefix(self::belowThousand($thousands)) . 'tausend' : '';
		return $rest > 0 ? $text . self::belowThousand($rest) : $text;
	}

	private static function belowThousand(int $n): string {
		$hundreds = intdiv($n, 100);
		$rest = $n % 100;
		$text = $hundreds > 0 ? self::prefix(self::UNITS[$hundreds]) . 'hundert' : '';
		return $rest > 0 ? $text . self::belowHundred($rest) : $text;
	}

	private static function belowHundred(int $n): string {
		if ($n < 20) {
			return self::UNITS[$n];
		}
		$unit = $n % 10;
		$tens = self::TENS[intdiv($n, 10)];
		return $unit === 0 ? $tens : self::prefix(self::UNITS[$unit]) . 'und' . $tens;
	}

	/** „eins“ wird vor einem Zahlwort zu „ein“ (einhundert, einundzwanzig, einhunderteintausend). */
	private static function prefix(string $word): string {
		return str_ends_with($word, 'eins') ? substr($word, 0, -1) : $word;
	}
}
