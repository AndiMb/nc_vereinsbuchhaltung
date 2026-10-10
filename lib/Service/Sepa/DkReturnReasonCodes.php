<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Sepa;

/**
 * Übersetzt den dreistelligen DK-Retourencode aus MT940-Feld `:86:?34`
 * („Textschlüsselergänzung", Anlage 3 des DFÜ-Abkommens der Deutschen
 * Kreditwirtschaft) in den ISO-20022-Rückgabegrund, den camt.053 direkt in
 * `RtrInf/Rsn/Cd` liefert.
 *
 * Spec §5: „`?34`→ISO (nur DK-Kernwerte 901–918, **nie raten**)". Diese
 * Tabelle enthält bewusst NUR die Codes, für die mehrere unabhängige
 * öffentliche Quellen (Kreditinstituts-Dokumentationen zu „Geschäftsvorfall-
 * und Rückgabecodes") übereinstimmend einen einzigen, eindeutigen ISO-Code
 * nennen. Codes mit widersprüchlicher oder mehrdeutiger Zuordnung in den
 * verfügbaren Quellen (u. a. 905, 907, 911, 914, 916, 917, 918) fehlen
 * absichtlich – für sie {@see translate()} `null` statt eines geratenen
 * Werts. Alles außerhalb 901–918 ist keine "DK-Kernwert" mehr (spätere
 * Erweiterungen, z. B. für Echtzeitüberweisungen) und wird ebenfalls nicht
 * übersetzt.
 *
 * WICHTIG (siehe Spec §5 Implementierungs-Hinweis „Marker-/Synonymlisten vor
 * Scharfstellung gegen echte Vereinskonto-Exporte verifizieren"): diese
 * Tabelle stammt aus Sekundärquellen, nicht aus dem DK-Originaldokument
 * selbst (das nur eingeschränkt öffentlich zugänglich ist). Vor dem
 * produktiven Einsatz mit echten MT940-Exporten der Hausbank gegenprüfen.
 */
class DkReturnReasonCodes {

	/** DK-Nummer => eindeutiger ISO-20022-Rückgabegrund. */
	private const MAP = [
		'901' => 'AC01', // IBAN-Prüfziffer fehlerhaft
		'902' => 'AC04', // Konto erloschen
		'903' => 'AC06', // Konto gesperrt (Gesamt-Lastschriftsperre)
		'904' => 'AG01', // Zahlungsart für Konto unzulässig
		'906' => 'AM04', // Deckung fehlt
		'907' => 'AM05', // Doppelverarbeitung/Doppeleinreichung
		'908' => 'BE04', // Name/Kontoinhaber/Adresse fehlerhaft
		'909' => 'MD01', // Kein gültiges Mandat
		'910' => 'MD02', // Mandatsdaten fehlerhaft/unvollständig
		'912' => 'MD06', // Widerspruch des Zahlungspflichtigen (8-Wochen-Frist)
		'913' => 'MD07', // Zahlungspflichtiger verstorben
		'915' => 'RC01', // BIC/Bankleitzahl fehlerhaft
	];

	/** @param string $code dreistellige DK-Nummer, z. B. "901" */
	public static function translate(string $code): ?string {
		return self::MAP[$code] ?? null;
	}
}
