<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

/**
 * ISO-20022-Rückgabegründe für SEPA-Lastschriften, wie sie camt.053
 * (`RtrInf/Rsn/Cd`) direkt liefert und wie sie im Verwendungszweck/
 * Buchungstext referenzloser Formate (Spec §5 „referenzlose Formate: Text-
 * Heuristik als dokumentierter Fallback") als eigenständiges Wort auftauchen
 * können.
 *
 * Übernommen aus der bisherigen Liste in
 * {@see \OCA\Vereinsbuchhaltung\Service\SepaReturnDetectionService} (dort
 * gegen das alte `vbh_sepa_batches`-Modell verdrahtet) – hier zentral für die
 * neue, strukturierte Erkennung (Issue #72), damit beide Systeme nicht
 * unabhängig voneinander auseinanderlaufen können. Die alte Klasse bleibt
 * unverändert bestehen (bedient weiterhin das alte Mandatssystem, siehe
 * {@see \OCA\Vereinsbuchhaltung\Db\SepaMandate}).
 */
class SepaReturnReasonCodes {

	private const CODES = [
		'AC01', 'AC04', 'AC06', 'AC13', 'AG01', 'AG02', 'AM04', 'AM05',
		'BE01', 'BE04', 'BE05', 'FF01', 'FF05', 'MD01', 'MD02', 'MD06', 'MD07',
		'MS02', 'MS03', 'RC01', 'RR01', 'RR02', 'RR03', 'RR04', 'SL01', 'SL02',
		'SL11', 'SL12', 'SL13', 'SL14', 'TM01', 'DT01', 'FOCR', 'CNOR', 'DNOR',
	];

	/**
	 * Sucht einen ISO-Rückgabegrund als eigenständiges Wort im Freitext.
	 *
	 * Die Abgrenzung ist strenger als ein bloßes \b: das würde auch in
	 * „Rechnung AC01-2026" anschlagen, weil der Bindestrich als Wortgrenze
	 * zählt. Ein Rückgabegrund steht im Bankdeutsch für sich, nie als Teil
	 * einer längeren Kennung (identische Regel wie im Vorbild).
	 */
	public static function findInText(string $haystack): ?string {
		foreach (self::CODES as $code) {
			if (preg_match('/(?<![A-Z0-9-])' . $code . '(?![A-Z0-9-])/', $haystack)) {
				return $code;
			}
		}
		return null;
	}

	/** @return string[] */
	public static function all(): array {
		return self::CODES;
	}
}
