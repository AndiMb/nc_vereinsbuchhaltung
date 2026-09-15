// Die Punkte der "Erste Schritte"-Karte (SetupChecklist.vue).
//
// Eigenes Modul, weil die Bedingungen die einzige Logik der Karte sind und
// getestet gehoeren: vitest.config.mjs bindet kein @vitejs/plugin-vue ein, ein
// Test koennte die SFC also gar nicht laden. Hier liegt reines JavaScript ohne
// Vue und ohne Uebersetzungs-Import - t wird hereingereicht (anders als in
// frequency.js), damit der Test nicht die @nextcloud/l10n-Kette mitziehen muss.

/**
 * Die Punkte der Karte samt ihrem Erledigt-Zustand, in Anzeigereihenfolge.
 *
 * @param {object} ctx alles, woran sich "erledigt" entscheidet
 * @param {string} ctx.clubName Name des Vereins ('' = noch nicht benannt)
 * @param {Array} ctx.accounts alle Konten
 * @param {Array} ctx.permissions vergebene Berechtigungen
 * @param {boolean} ctx.hasAnyBooking gibt es ueberhaupt eine Buchung - ueber ALLE
 *   Zeitraeume, nicht nur den gewaehlten (Issue #60)
 * @param {(key: string) => string} [ctx.t] Uebersetzer; ohne ihn bleiben die Quelltexte stehen
 * @return {Array<{id: string, label: string, action: string, done: boolean}>}
 */
export function buildSetupSteps({ clubName, accounts, permissions, hasAnyBooking, t = (s) => s }) {
	return [
		{ id: 'club', label: t('Verein benennen'), action: 'settings:verein', done: !!clubName },
		{ id: 'accounts', label: t('Kontenrahmen anlegen'), action: 'accounts', done: accounts.length > 0 },
		// xbuc-Importe setzen openingDate nicht (Anfangsbestand steckt in der
		// EB-Buchung selbst) – sobald überhaupt gebucht wurde, ist der Punkt
		// gegenstandslos, sonst würde er bei aktiven, importierten Vereinen nie erledigt sein.
		{ id: 'opening', label: t('Geldkonto mit Anfangsbestand eintragen'), action: 'accounts', done: hasAnyBooking || accounts.some((a) => a.isBank && a.openingDate) },
		{ id: 'permissions', label: t('Berechtigungen vergeben'), action: 'settings:berechtigungen', done: permissions.length > 0 },
		{ id: 'booking', label: t('Erste Buchung erfassen'), action: 'booking', done: hasAnyBooking },
		// Entspricht Account::isResultRelevant() im Backend (alles außer Geldkonten/Eigenkapital).
		// Zuordnung selbst steht seit NAVIGATION-KONZEPT.md Abschnitt 4 im
		// Bericht „Sphären", nicht mehr im Zahnrad.
		{ id: 'spheres', label: t('Sphären zuordnen (steuerlich)'), action: 'reports:spheres', done: accounts.filter((a) => a.type !== 'equity' && !a.isBank).every((a) => a.sphere) },
	]
}
