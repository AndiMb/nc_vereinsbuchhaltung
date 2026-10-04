<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Exception;

/**
 * Ein Bankumsatz lässt sich (noch) nicht verbuchen – mit einem Grund-Code
 * neben dem deutschen Text (Bankabgleich, Issue #105): die Verbuchung selbst
 * antwortet darauf mit 400 und der Meldung, die Vorschau des Bankabgleichs
 * zeigt sie vorab als Hindernis und wählt anhand des Codes ihre Darstellung.
 *
 * Bewusst eine {@see \InvalidArgumentException}: alle Aufrufer, die bisher
 * deren Meldung als 400 weitergaben, bleiben unverändert.
 */
class SettlementBlockedException extends \InvalidArgumentException {

	/** Der Umsatz hat keine SEPA-Detail-Zeilen. */
	public const REASON_NO_DETAILS = 'no_details';
	/** Nicht jede Detail-Zeile ist beurteilt (Spec §5). */
	public const REASON_UNDECIDED = 'undecided';
	/** Alle Zeilen sind abgelehnt oder „nicht zuordenbar" – nichts zu verbuchen. */
	public const REASON_NOTHING_ASSIGNED = 'nothing_assigned';
	/** Zugeordnete Gutschriften UND Rücklastschriften in einem Umsatz. */
	public const REASON_MIXED_DIRECTIONS = 'mixed_directions';
	/** Der Umsatz ist schon gebucht (eine zweite Verbuchung würde die erste ersetzen). */
	public const REASON_ALREADY_BOOKED = 'already_booked';
	/** Eine Bankgebühr ist bekannt, aber das Rücklastschriftgebühren-Konto fehlt. */
	public const REASON_FEE_ACCOUNT_MISSING = 'fee_account_missing';
	/** Weder die Forderung noch die Einstellungen nennen ein Erlöskonto. */
	public const REASON_REVENUE_ACCOUNT_MISSING = 'revenue_account_missing';
	/** Die Forderung ist inzwischen bezahlt, erlassen oder storniert (Zahlungseingangs-Vorschlag). */
	public const REASON_CLAIM_NOT_OPEN = 'claim_not_open';
	/** Der Einzugsposten ist schon einer anderen Detail-Zeile zugeordnet (Einzelurteil). */
	public const REASON_ITEM_TAKEN = 'item_taken';

	public function __construct(
		string $message,
		public readonly string $reason,
	) {
		parent::__construct($message);
	}
}
