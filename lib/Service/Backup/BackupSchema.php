<?php

declare(strict_types=1);

namespace OCA\Vereinsbuchhaltung\Service\Backup;

/**
 * Verzeichnis der Sicherung (Issue #111): welche Tabellen und Einstellungen
 * in eine Sicherung gehören und was beim Einspielen in einer anderen
 * Nextcloud-Instanz anders sein muss.
 *
 * **Wer eine Tabelle anlegt, trägt sie hier ein** – als gesichert
 * ({@see tables()}) oder als ausdrücklich ausgenommen
 * ({@see excludedTables()}). `BackupSchemaTest` verlangt das; ohne Eintrag
 * fehlte die Tabelle stillschweigend in jeder Sicherung.
 *
 * Gesichert wird mit unveränderten Primärschlüsseln. Das Schema kennt keine
 * Fremdschlüssel, die Verknüpfungen sind blanke ID-Spalten – auch in
 * JSON-Feldern, in Einstellungen, in Dateipfaden der app-internen Ablage und
 * in der Prüfkette der Auslagenanträge. Mit denselben IDs stimmt all das im
 * Ziel ohne Umrechnung; dafür muss das Ziel leer sein.
 */
final class BackupSchema {

	/** Primärschlüssel jeder gesicherten Tabelle. */
	public const PK = 'id';

	/** Arten gesicherter Dateien = Ordner unter `files/` in der Sicherung. */
	public const FILE_KINDS = ['attachments', 'reimbursements', 'invoices', 'mandates'];

	/** Datei-ID „liegt in der app-internen Ablage“ (Rechnungsdokumente, Mandatsnachweise). */
	public const APPDATA_FILE_ID = 0;

	/**
	 * Gesicherte Tabellen.
	 *
	 * - `encrypted`: Spalten, die {@see \OCA\Vereinsbuchhaltung\Service\FieldCipher}
	 *   mit dem Schlüssel der Instanz verschlüsselt. Die Sicherung trägt den
	 *   Klartext (sie ist als Ganzes verschlüsselt), das Ziel verschlüsselt neu.
	 * - `replace`: instanzgebundene Spalten (Nextcloud-Datei-IDs, deren
	 *   Besitzer) → Wert im Ziel. Die Dateien selbst liegen dort in der
	 *   app-internen Ablage unter der Datensatz-ID.
	 * - `replaceIfFile`: Spalte → Dateiart; im Ziel {@see APPDATA_FILE_ID},
	 *   wenn die Sicherung eine Datei dieser Art zum Datensatz enthält, sonst NULL.
	 *
	 * @return array<string, array{encrypted: list<string>, replace: array<string, int|null>, replaceIfFile?: array<string, string>}>
	 */
	public static function tables(): array {
		$plain = ['encrypted' => [], 'replace' => []];
		return [
			// Buchhaltung
			'vbh_periods' => $plain,
			'vbh_costcenters' => $plain,
			'vbh_booking_cc' => $plain,
			'vbh_accounts' => $plain,
			'vbh_bank_tx' => ['encrypted' => ['counterparty_iban'], 'replace' => []],
			'vbh_bank_tx_sepa_details' => $plain,
			'vbh_incoming_pay_rejects' => $plain,
			'vbh_journal' => $plain,
			'vbh_journal_line' => $plain,
			'vbh_line_alloc' => $plain,
			'vbh_rules' => ['encrypted' => ['match_value'], 'replace' => []],
			'vbh_rule_parts' => $plain,
			'vbh_attachments' => ['encrypted' => [], 'replace' => ['file_id' => null, 'file_owner' => null]],
			'vbh_budgets' => $plain,
			'vbh_cc_budgets' => $plain,
			'vbh_budget_snapshots' => $plain,
			'vbh_budget_snap_items' => $plain,
			'vbh_report_templates' => $plain,
			'vbh_open_items' => $plain,
			// Mitglieder, Beiträge, Einzug
			'vbh_members' => $plain,
			'vbh_mandates' => ['encrypted' => ['iban'], 'replace' => [], 'replaceIfFile' => ['document_file_id' => 'mandates']],
			'vbh_mandate_amendments' => ['encrypted' => ['old_iban'], 'replace' => []],
			'vbh_mandate_events' => $plain,
			'vbh_mandate_legal_text_versions' => $plain,
			'vbh_contribution_groups' => $plain,
			'vbh_assignments' => $plain,
			'vbh_assignment_events' => $plain,
			'vbh_debit_batches' => $plain,
			'vbh_debit_items' => ['encrypted' => ['iban'], 'replace' => []],
			'vbh_returned_debits' => $plain,
			'vbh_dunning_notices' => $plain,
			'vbh_tasks' => $plain,
			// Rechnungen
			'vbh_number_ranges' => $plain,
			'vbh_debtors' => $plain,
			'vbh_invoice_batches' => $plain,
			'vbh_invoices' => $plain,
			'vbh_invoice_lines' => $plain,
			'vbh_invoice_documents' => ['encrypted' => [], 'replace' => ['file_id' => self::APPDATA_FILE_ID]],
			'vbh_invoice_payments' => $plain,
			'vbh_invoice_templates' => $plain,
			'vbh_invoice_plans' => $plain,
			'vbh_invoice_dunnings' => $plain,
			// Auslagenerstattung
			'vbh_reimbursements' => ['encrypted' => ['iban'], 'replace' => []],
			'vbh_reimbursement_events' => $plain,
			'vbh_reimbursement_files' => ['encrypted' => [], 'replace' => ['file_id' => null, 'file_owner' => null]],
			// Rollen und Protokoll
			'vbh_permissions' => $plain,
			'vbh_audit_log' => $plain,
		];
	}

	/**
	 * Tabellen, die nicht in die Sicherung gehören und im Ziel leer sind.
	 *
	 * @return list<string>
	 */
	public static function excludedTables(): array {
		return [
			// Zwischenspeicher zu Nextcloud-Datei-IDs der Quellinstanz.
			'vbh_receipt_hashes',
			'vbh_receipt_index',
			// Offene Aktivierungslinks zeigen auf die Quellinstanz.
			'vbh_mandate_activation_tokens',
			// Alttabellen, die die App nicht mehr liest (bleiben nur stehen, wo sie Daten hatten).
			'vbh_sepa_batch_items',
			'vbh_sepa_batches',
			'vbh_membership_fees',
			'vbh_sepa_mandates',
		];
	}

	/**
	 * Tabellen mit eigenem Inhalt: ist eine davon gefüllt, ist die Buchhaltung
	 * nicht leer. Automatisch Angelegtes (Geschäftsjahre, Standardvorlagen,
	 * Nummernkreise, Rechtstext-Versionen) zählt nicht – sonst wäre schon eine
	 * frisch geöffnete App „nicht leer“.
	 *
	 * @return list<string>
	 */
	public static function substantiveTables(): array {
		return [
			'vbh_accounts', 'vbh_journal', 'vbh_bank_tx', 'vbh_members', 'vbh_mandates',
			'vbh_contribution_groups', 'vbh_debtors', 'vbh_invoices', 'vbh_open_items',
			'vbh_reimbursements', 'vbh_budgets', 'vbh_rules', 'vbh_costcenters', 'vbh_booking_cc',
		];
	}

	/**
	 * Was „Bestand vollständig leeren“ stehen lässt: wer darf was, und was geschah.
	 *
	 * @return list<string>
	 */
	public static function keptOnWipe(): array {
		return ['vbh_audit_log', 'vbh_permissions'];
	}

	/**
	 * Einstellungen, die an der Instanz hängen und im Ziel neu einzustellen
	 * sind: Nextcloud-Benutzer, deren Ordner, und der Stand-Zähler der Sitzungen.
	 *
	 * @return list<string>
	 */
	public static function excludedConfigKeys(): array {
		return [
			'storage_user', 'storage_path', 'storage_mode',
			'statement_watch_user', 'statement_watch_path',
			'mandate_document_folder',
			'xml_folder_enabled', 'xml_folder_path',
			'invoice_folder',
			'data_revision',
		];
	}

	/**
	 * Werte, die Nextcloud selbst unter der App führt.
	 *
	 * @return list<string>
	 */
	public static function internalConfigKeys(): array {
		return ['installed_version', 'enabled', 'types'];
	}

	/**
	 * Gehört eine App-Einstellung in die Sicherung? Alles außer den
	 * instanzgebundenen und Nextclouds eigenen Werten – eine neue Einstellung
	 * ist damit von selbst dabei.
	 */
	public static function isBackedUpConfigKey(string $key): bool {
		return !in_array($key, self::excludedConfigKeys(), true) && !in_array($key, self::internalConfigKeys(), true);
	}
}
