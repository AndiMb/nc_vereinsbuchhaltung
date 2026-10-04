<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

/**
 * Die Zahlungsfrequenz-Schlüssel, die der Standard-Beitrag in den
 * Einstellungen und der CSV-Import („monatlich", „monthly" …) verwenden,
 * samt ihrer Länge in Monaten. Mehr ist von der früheren Beitragsfortschreibung
 * des flachen Alt-Moduls nicht geblieben (Issue #107): die Fälligkeiten
 * ergeben sich seither aus dem Terminplan und dem Turnus der Zuweisung
 * ({@see DueDateScheduleService}).
 */
class BillingPeriod {

	/** Anzahl Monate je Zahlungsfrequenz – zugleich die Liste der erlaubten Werte. */
	public const FREQUENCY_MONTHS = [
		'monthly' => 1,
		'quarterly' => 3,
		'semiannual' => 6,
		'yearly' => 12,
	];
}
