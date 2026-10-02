<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

use OCA\Vereinsbuchhaltung\Db\Task;
use OCP\IL10N;

/**
 * Rückgabe-Klassen (Spec §3.6 Tabelle, Issue #73): hartkodiert, deterministisch
 * aus dem ISO-20022-Rückgabegrund abgeleitet, NICHT gespeichert – anders als
 * {@see \OCA\Vereinsbuchhaltung\Db\ReturnedDebit::getReasonCode()} (roh, admin-only)
 * ist die Klasse hier immer nur eine Ableitung zur Laufzeit, damit eine
 * spätere Korrektur der Tabelle rückwirkend auf alle bestehenden
 * Rücklastschriften wirkt statt an einem gespeicherten Snapshot vorbeizulaufen.
 *
 * „Kein Code beendet je automatisch ein Mandat – härteste Auto-Wirkung ist die
 * Sperre" (Spec §3.6): {@see shouldSuspendMandate()} entscheidet nur
 * ja/nein zur Sperre über {@see \OCA\Vereinsbuchhaltung\Service\MandateService::suspendDueToReturnedDebit()},
 * niemals zu `revoke()`.
 *
 * Die synchrone Verbuchung (Mensch bestätigt den Bankumsatz,
 * {@see SepaImportConfirmationService::finalizeReturn()} reagiert im selben
 * Zug) macht die in der Spec für `disputed` gesondert genannte Eilbedürftigkeit
 * von MD06 („auto-sperren (MD06 sofort)") gegenstandslos: JEDE Auto-Sperre
 * dieser Klasse passiert ohnehin sofort, nicht erst in einem späteren
 * Batch-Lauf – ein eigener Zeit-Pfad nur für MD06 wäre ohne erkennbaren
 * Unterschied.
 */
final class ReturnReasonClassifier {

	/** Deckung fehlt – keine Mandats-Wirkung, sofortige Zahlungsaufforderung, Aufgabe nur als Hinweis (Spec §3.6). */
	public const CLASS_INSUFFICIENT_FUNDS = 'insufficient_funds';

	/** Konto nicht mehr erreichbar/nutzbar – Mandat wird automatisch gesperrt, sofortige Zahlungsaufforderung. */
	public const CLASS_ACCOUNT_UNUSABLE = 'account_unusable';

	/** Widerspruch/kein gültiges Mandat aus Sicht des Zahlungspflichtigen – Mandat wird automatisch gesperrt. */
	public const CLASS_DISPUTED = 'disputed';

	/** Zahlungspflichtiger verstorben – Mandat wird automatisch gesperrt, KEINE Zahlungsaufforderung (Spec §3.6). */
	public const CLASS_DECEASED = 'deceased';

	/** Technischer/organisatorischer Fehler auf Bankseite – keine Mandats-Wirkung, keine Zahlungsaufforderung. */
	public const CLASS_TECHNICAL = 'technical';

	/** Kein bekannter oder kein übermittelter Code – konservativ wie technical behandelt (keine Automatik außer der Aufgabe). */
	public const CLASS_UNKNOWN = 'unknown';

	public const CLASSES = [
		self::CLASS_INSUFFICIENT_FUNDS,
		self::CLASS_ACCOUNT_UNUSABLE,
		self::CLASS_DISPUTED,
		self::CLASS_DECEASED,
		self::CLASS_TECHNICAL,
		self::CLASS_UNKNOWN,
	];

	/** ISO-Rückgabegrund => Klasse, exakt die Beispiel-Codes aus Spec §3.6. */
	private const MAP = [
		'AM04' => self::CLASS_INSUFFICIENT_FUNDS,
		'MS03' => self::CLASS_INSUFFICIENT_FUNDS,

		'AC01' => self::CLASS_ACCOUNT_UNUSABLE,
		'AC04' => self::CLASS_ACCOUNT_UNUSABLE,
		'AC06' => self::CLASS_ACCOUNT_UNUSABLE,
		'AC13' => self::CLASS_ACCOUNT_UNUSABLE,
		'AG01' => self::CLASS_ACCOUNT_UNUSABLE,
		'RC01' => self::CLASS_ACCOUNT_UNUSABLE,
		'BE05' => self::CLASS_ACCOUNT_UNUSABLE,

		'MD01' => self::CLASS_DISPUTED,
		'MD06' => self::CLASS_DISPUTED,
		'MS02' => self::CLASS_DISPUTED,
		'SL01' => self::CLASS_DISPUTED,

		'MD07' => self::CLASS_DECEASED,

		'AM05' => self::CLASS_TECHNICAL,
		'AG02' => self::CLASS_TECHNICAL,
		'FF01' => self::CLASS_TECHNICAL,
		'FF05' => self::CLASS_TECHNICAL,
		'TM01' => self::CLASS_TECHNICAL,
		'DT01' => self::CLASS_TECHNICAL,
		'RR01' => self::CLASS_TECHNICAL,
		'RR02' => self::CLASS_TECHNICAL,
		'RR03' => self::CLASS_TECHNICAL,
		'RR04' => self::CLASS_TECHNICAL,
		'FOCR' => self::CLASS_TECHNICAL,
		'CNOR' => self::CLASS_TECHNICAL,
		'DNOR' => self::CLASS_TECHNICAL,
	];

	/** Klassen, deren härteste Auto-Wirkung die Mandats-Sperre ist (Spec §3.6 Tabellenspalte „Mandat-Wirkung"). */
	private const SUSPEND_CLASSES = [self::CLASS_ACCOUNT_UNUSABLE, self::CLASS_DISPUTED, self::CLASS_DECEASED];

	/** Klassen mit sofortiger Zahlungsaufforderung (Mahnstufe 0) nach Spec §3.6 Tabellenspalte „Zahlungsaufforderung". */
	private const PAYMENT_REQUEST_CLASSES = [self::CLASS_INSUFFICIENT_FUNDS, self::CLASS_ACCOUNT_UNUSABLE, self::CLASS_DISPUTED];

	/** Klassen mit Aufgabe „dringend" statt nur „Hinweis" (Spec §3.6 Tabellenspalte „Aufgabe"). */
	private const URGENT_TASK_CLASSES = [self::CLASS_ACCOUNT_UNUSABLE, self::CLASS_DISPUTED, self::CLASS_DECEASED, self::CLASS_TECHNICAL, self::CLASS_UNKNOWN];

	private function __construct() {
		// Reine Ableitungslogik, keine Instanz nötig - siehe Klassendoc und
		// dasselbe Muster bei DkReturnReasonCodes.
	}

	/**
	 * Leitet die Rückgabe-Klasse aus dem ISO-20022-Rückgabegrund ab. Ein
	 * fehlender/unbekannter Code landet bewusst konservativ bei `unknown`
	 * (keine Automatik außer der Aufgabe) statt eine Vermutung zu wagen.
	 */
	public static function classify(?string $isoReasonCode): string {
		if ($isoReasonCode === null || trim($isoReasonCode) === '') {
			return self::CLASS_UNKNOWN;
		}
		return self::MAP[strtoupper(trim($isoReasonCode))] ?? self::CLASS_UNKNOWN;
	}

	/** Härteste Auto-Wirkung ist die Sperre – nie ein automatisches Mandatsende (Spec §3.6). */
	public static function shouldSuspendMandate(string $class): bool {
		return in_array($class, self::SUSPEND_CLASSES, true);
	}

	/** Mahnstufe 0 „Zahlungsaufforderung" sofort bei Rücklastschrift, klassenabhängig (Spec §3.6). */
	public static function shouldTriggerPaymentRequest(string $class): bool {
		return in_array($class, self::PAYMENT_REQUEST_CLASSES, true);
	}

	/**
	 * Ob die (bereits opt-in aktivierte) Gebühren-Weiterbelastung für diese
	 * Klasse automatisch greift (Spec §3.6: „automatisch nur bei
	 * insufficient_funds/account_unusable" – Issue #73 verfeinert damit den
	 * einfachen Ja/Nein-Schalter aus #72).
	 */
	public static function shouldRechargeFeeAutomatically(string $class): bool {
		return in_array($class, [self::CLASS_INSUFFICIENT_FUNDS, self::CLASS_ACCOUNT_UNUSABLE], true);
	}

	/** Aufgaben-Schweregrad dieser Klasse (Spec §3.6 Tabellenspalte „Aufgabe"): „Hinweis" nur bei insufficient_funds, sonst „dringend". */
	public static function taskSeverity(string $class): string {
		return in_array($class, self::URGENT_TASK_CLASSES, true) ? Task::SEVERITY_ACTION_REQUIRED : Task::SEVERITY_HINT;
	}

	/**
	 * Wahrheitsfester Mitglieder-Klartext je Klasse (Spec §3.6/§3.11: „Codes
	 * bleiben admin-only") – wird nie mit dem rohen ISO-Code kombiniert
	 * ausgeliefert. Für `deceased`/`technical`/`unknown` löst diese Klasse
	 * ohnehin keine automatische Zahlungsaufforderung aus
	 * ({@see shouldTriggerPaymentRequest()}); der Text bleibt trotzdem
	 * vollständig, weil dieselbe Klassifikation auch für die admin-seitige
	 * Anzeige der Rücklastschrift gilt.
	 */
	public static function memberFacingReason(string $class, IL10N $l10n): string {
		return match ($class) {
			self::CLASS_INSUFFICIENT_FUNDS => $l10n->t('Die Lastschrift konnte mangels Kontodeckung nicht eingezogen werden.'),
			self::CLASS_ACCOUNT_UNUSABLE => $l10n->t('Die Lastschrift konnte nicht eingezogen werden, weil das angegebene Konto nicht erreichbar ist.'),
			self::CLASS_DISPUTED => $l10n->t('Die Lastschrift wurde auf Ihren Widerspruch hin von Ihrer Bank zurückgebucht.'),
			self::CLASS_DECEASED => $l10n->t('Die Lastschrift konnte nicht eingezogen werden.'),
			self::CLASS_TECHNICAL => $l10n->t('Die Lastschrift konnte aus technischen Gründen nicht eingezogen werden.'),
			default => $l10n->t('Die Lastschrift konnte nicht eingezogen werden.'),
		};
	}
}
