<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

/**
 * Die Gläubigerangaben einer Einreichung, losgelöst von der Datenbank.
 *
 * Existiert, damit {@see PainXmlBuilder} ohne Nextcloud auskommt: die Entität
 * {@see \OCA\Vereinsbuchhaltung\Db\SepaBatch} erbt von OCP, und der
 * Test-Bootstrap lädt nur `lib/`. Ausgerechnet die formatkritischste Klasse
 * des Moduls war dadurch nicht zu testen – ein Formatfehler wäre erst bei der
 * Bank aufgefallen. Die Umsetzung Entität → Wertobjekt macht
 * {@see \OCA\Vereinsbuchhaltung\Service\SepaBatchService::creditorOf()} für
 * den alten Einzugszyklus bzw.
 * {@see \OCA\Vereinsbuchhaltung\Service\DebitBatchService::creditorOf()} für
 * den neuen (Issue #71).
 */
final class SepaCreditor {

	/**
	 * @param string|null $creationDateTime Bei der Freigabe eingefrorener
	 *                                      GrpHdr/CreDtTm-Wert (Issue #71, Spec §3.5 „byte-identisch
	 *                                      nachrenderbar") – ohne Angabe nimmt {@see PainXmlBuilder} den
	 *                                      aktuellen Zeitpunkt (Verhalten des alten Einzugszyklus, dessen
	 *                                      Datei ohnehin nur einmal erzeugt und sofort heruntergeladen wird).
	 */
	public function __construct(
		public readonly string $messageId,
		public readonly string $executionDate,
		public readonly string $creditorId,
		public readonly string $name,
		public readonly string $iban,
		public readonly ?string $bic = null,
		public readonly ?string $creationDateTime = null,
	) {
	}
}
