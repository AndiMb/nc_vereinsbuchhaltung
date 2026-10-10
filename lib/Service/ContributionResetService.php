<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service;

use OCA\Vereinsbuchhaltung\Db\DebitBatchMapper;
use OCA\Vereinsbuchhaltung\Db\DebitItemMapper;
use OCA\Vereinsbuchhaltung\Db\DunningNoticeMapper;
use OCA\Vereinsbuchhaltung\Db\MandateAmendmentMapper;
use OCA\Vereinsbuchhaltung\Db\MandateMapper;
use OCA\Vereinsbuchhaltung\Db\ReturnedDebitMapper;

/**
 * Der Beiträge/SEPA-Anteil von {@see ResetService} (Issue #123): räumt alles
 * weg, was an den Forderungen und Buchungen hängt, die der Reset löscht. Die
 * Forderungen selbst sind Zeilen von `vbh_open_items` und gehen mit den
 * offenen Posten – das macht {@see ResetService} direkt.
 *
 * Es gibt keine Fremdschlüssel im Schema: was hier fehlte, bliebe als Zeile
 * mit einem Verweis auf eine nicht mehr vorhandene ID stehen – und zeigte
 * nach einem Neustart des Datenbankservers (der Zähler der IDs beginnt je nach
 * System wieder von vorn) auf fremde, neu angelegte Forderungen. Die
 * Einzugsposten tragen außerdem IBAN und Kontoinhaber im Klartext, die
 * Mahnstufen und Rücklastschriften hängen an Mitgliedern: personenbezogene
 * Daten ohne Bezug dürfen nicht liegen bleiben, das galt schon für den
 * Offene-Posten-Teil des Resets.
 *
 * **Gelöscht** wird (alles in der Transaktion von {@see ResetService::resetAll()}):
 * Läufe, Einzugsposten, Rücklastschriften, Mahnstufen. Aufgaben und Störfälle
 * sind abgeleitete Abfragen ohne eigenen Zustand (Spec §7) und verschwinden
 * mit ihren Grundlagen von selbst; die wenigen gespeicherten Aufgaben
 * (`vbh_tasks`) hängen an Mitgliedern, nicht an Forderungen. Die
 * Abgleich-Daten am Kontoauszug (SEPA-Detailzeilen, abgelehnte Vorschläge)
 * räumt {@see ResetService} schon seit Issue #105.
 *
 * **Stammdaten bleiben** – Mitglieder, Mandate samt Historie, Beitragsgruppen,
 * Zuweisungen samt Historie, Rechtstext-Versionen und App-Einstellungen. Zwei
 * Zeiger der Mandate zeigen aber auf Gelöschtes und werden bereinigt:
 *
 * - `Mandate.returned_debit_id` (Verweis der automatischen Sperre auf die
 *   Rücklastschrift) wird NULL. Die Sperre selbst **bleibt**: Spec §2.2 kennt
 *   keine Auto-Entsperrung, und die Bank hat die Lastschrift tatsächlich
 *   zurückgegeben – das ändert kein Löschen von Buchhaltungsdaten. Der Grund
 *   steht weiter in `suspension_note`, die Aufgabenliste zeigt ohne Verweis
 *   einen neutralen Text ({@see MandateTaskService}).
 * - `MandateAmendment.status = transmitted` samt `debit_item_id` wird `open` und
 *   NULL. „Übermittelt" heißt „steckt in einem eingereichten Einzugsposten"
 *   (Spec §2.2); ohne Posten stimmt das nicht mehr. Die Gegenrichtung ist die
 *   billigere: ein erneut mitgeschicktes SMNDA beim nächsten Einzug ist
 *   unschädlich (Spec §8: die DK empfiehlt es für jeden Kontowechsel), eine
 *   fälschlich als gemeldet geltende Änderung unterbliebe dagegen unbemerkt –
 *   etwa wenn der „eingereichte" Lauf nur ein Probelauf war.
 *
 * **Bewusst unverändert:**
 *
 * - `Mandate.last_presented_due_date` ist ein Datum, kein Verweis. Es trägt die
 *   36-Monats-Frist ({@see MandateExpiryCalculator}), und die zählt ab der
 *   letzten tatsächlichen Vorlage bei der Bank – die macht kein Reset der
 *   Buchführung ungeschehen. Würde es zurückgesetzt, fiele die Frist auf
 *   `signed_at` zurück, und der tägliche Verfall beendete ein Mandat unter
 *   Umständen endgültig (terminal, nie reaktivierbar), das in Wahrheit noch
 *   gilt. Ein Mandat, das „wieder als nie genutzt" gilt, ist also nicht
 *   erwünscht; ein „erstmals genutzt"-Merker (FRST) existiert ohnehin nicht –
 *   der Sequenztyp ist immer RCUR ({@see \OCA\Vereinsbuchhaltung\Db\Mandate::SEQUENCE_TYPE}).
 * - Zuweisungen tragen weder Zeiger noch Zähler auf Forderungen: wie weit die
 *   Forderungserzeugung ist, liest {@see ClaimGenerationService} aus den
 *   gespeicherten Forderungen ab (`latestGeneratedPeriodEnd()`). Es gibt also
 *   nichts zurückzusetzen, und der Tageslauf legt die Forderungen nach dem
 *   Reset von selbst neu an. Er beginnt dabei bei dem Zeitraum, in dem die
 *   jeweilige Zuweisung beginnt (`valid_from`) – nicht erst am Tag des Resets –
 *   und holt alle Zeiträume bis zum Vorwarnfenster nach. Das ist Verhalten, kein
 *   Fehler, und im Handbuch (12.1) beschrieben; ein Stichtag, ab dem nach dem
 *   Reset erst gefordert wird, wäre eine eigene fachliche Entscheidung.
 * - Die XML-Kopien der Läufe in der optionalen XML-Ablage
 *   ({@see DebitBatchXmlStorageService}) bleiben liegen: die App merkt sich zu
 *   ihnen keine Datei-ID, sie liegen im Home eines echten Nutzers in einem frei
 *   wählbaren Ordner, und sie sind ausdrücklich die Compliance-Ablage dessen,
 *   was der Bank übergeben wurde. Nach Dateinamen zu raten und fremde Dateien
 *   zu löschen verbietet schon die Belegablage ({@see AttachmentStorageService::deleteAllFiles()}).
 *   Es gibt daher auch nichts „nach dem Commit" zu entfernen.
 */
class ContributionResetService {

	public function __construct(
		private MandateMapper $mandates,
		private MandateAmendmentMapper $amendments,
		private ReturnedDebitMapper $returnedDebits,
		private DunningNoticeMapper $dunningNotices,
		private DebitItemMapper $debitItems,
		private DebitBatchMapper $debitBatches,
	) {
	}

	/**
	 * Räumt die Läufe, Posten, Rücklastschriften und Mahnstufen weg und
	 * bereinigt die Zeiger der Mandate darauf, siehe Klassendoc.
	 *
	 * Gehört in die Transaktion des Resets, und zwar **vor** das Löschen der
	 * Forderungen: erst die Zeiger lösen, dann die Zeilen, auf die sie zeigten
	 * (Verweise zuerst, Blätter vor Eltern: Rücklastschrift und Mahnstufe vor
	 * Posten, Posten vor Lauf). Kein eigener Transaktionsrahmen – bricht ein
	 * Teil ab, rollt der Aufrufer alles zurück.
	 */
	public function deleteClaimDependents(): void {
		$this->mandates->clearReturnedDebitReferences();
		$this->amendments->reopenAllTransmitted();

		$this->returnedDebits->deleteAll();
		$this->dunningNotices->deleteAll();
		$this->debitItems->deleteAll();
		$this->debitBatches->deleteAll();
	}
}
