import { currencySettingsPayload } from './clubCurrency.js'

/**
 * Was eine Karte der Einstellungsseite speichert (SettingsApp.vue).
 *
 * Früher schickte jede dieser Karten den vollständigen Satz aller Felder:
 * Wer Vereinsname und Belegablage änderte und nur unter „Verein" auf
 * Speichern drückte, speicherte unbemerkt auch die Belegablage – und ein
 * Fehler in der einen Karte (etwa ein fehlender Wächter-Ordner) ließ das
 * Speichern der anderen scheitern. Jetzt schickt jede Karte nur ihre eigenen
 * Felder; SettingsController::update() speichert seit 0.25.0 teilweise.
 *
 * Die Schlüssel und ihr Format sind dieselben wie zuvor.
 */
export const SAVE_PARTS = ['club', 'currency', 'brand', 'display', 'storage', 'watch', 'sepa', 'sepaModule']

/**
 * @param {string[]} parts Teile aus SAVE_PARTS; leer = alle (früheres Verhalten)
 * @param {object} s Formularstand der Seite (Felder wie in SettingsApp.data())
 * @return {object} Nutzlast für api.saveSettings()
 */
export function settingsPayload(parts, s) {
	const want = new Set(parts.length ? parts : SAVE_PARTS)
	const body = {}
	if (want.has('club')) {
		body.club_name = s.clubName
	}
	if (want.has('currency')) {
		Object.assign(body, currencySettingsPayload(s.clubCurrency, s.savedCurrency))
	}
	if (want.has('brand')) {
		body.brand_color = s.brandColor
	}
	if (want.has('display')) {
		body.amount_display = s.amountDisplay
	}
	if (want.has('storage')) {
		body.storage_mode = s.storageMode
		body.storage_user = s.storageUser
		body.storage_path = s.storagePath || 'Vereinsbuchhaltung/Belege'
	}
	if (want.has('watch')) {
		body.statement_watch_user = s.statementWatchUser
		body.statement_watch_path = s.statementWatchPath
	}
	if (want.has('sepa')) {
		body.sepa_creditor_id = s.sepaCreditorId
		body.sepa_debtor_account_id = s.sepaDebtorAccountId || ''
	}
	// Die Schalter des Beitragsmoduls wirken sofort und getrennt von der Karte
	// „Gläubiger-ID und Konto“: sonst speicherte ein Schalter eine halb
	// eingetippte Gläubiger-ID mit, und deren Fehler ließe den Schalter scheitern.
	if (want.has('sepaModule')) {
		body.membership_enabled = s.membershipEnabled ? '1' : '0'
		body.self_service_enabled = s.selfServiceEnabled ? '1' : '0'
		body.self_service_transfer_switch_enabled = s.selfServiceTransferSwitchEnabled ? '1' : '0'
	}
	return body
}
