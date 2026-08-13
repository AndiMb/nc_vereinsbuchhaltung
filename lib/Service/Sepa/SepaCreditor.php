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
 * {@see \OCA\Vereinsbuchhaltung\Service\SepaBatchService::creditorOf()}.
 */
final class SepaCreditor {

	public function __construct(
		public readonly string $messageId,
		public readonly string $executionDate,
		public readonly string $creditorId,
		public readonly string $name,
		public readonly string $iban,
		public readonly ?string $bic = null,
	) {
	}
}
