// Kuratierte Kurzfassungen fuer den "Was ist neu"-Splash-Screen (WhatsNewDialog.vue).
//
// Bewusst eine eigene, handgepflegte Liste statt Parsing von CHANGELOG.md:
// die Changelog-Eintraege sind unstrukturierter, entwicklerorientierter
// Fliesstext ohne Rollenbezug. Hier steht nur, was fuer Nutzende der App
// wirklich wissenswert ist - nicht jede Version bekommt einen Eintrag (siehe
// z. B. 0.24.0-0.24.3: reine CSS-/Layout-Fixes am selben Tag, bewusst ohne
// Eintrag).
//
// roles: 'verwalter' | 'buchhalter' | 'revisor'. Fehlt das Feld, gilt der
// Eintrag fuer alle Rollen. Neueste Version zuerst, wird aber unabhaengig
// von der Reihenfolge per Versionsvergleich gefiltert (siehe version.js).
//
// Als Funktion statt Modul-Konstante, weil t() sonst beim Import ausgewertet
// wuerde - noch bevor main.js die Uebersetzungen geladen hat (gleiches Muster
// wie buildTopics() in HelpModal.vue).
import { t } from '../lib/l10n.js'
import { isNewerVersion } from '../lib/version.js'

export function buildWhatsNewEntries() {
	return [
		{
			version: '0.25.0',
			roles: ['verwalter'],
			items: [
				t('Die Einstellungen sind umgezogen: statt über das Zahnrad in der App gibt es sie jetzt unter Nextcloud-Einstellungen → Vereinsbuchhaltung.'),
			],
		},
		{
			version: '0.21.0',
			roles: ['buchhalter', 'verwalter'],
			items: [
				t('Mitglieder und Beiträge lassen sich jetzt in einem Formular anlegen, inklusive Mitgliederliste per CSV-Import.'),
				t('Beitragsrückstände sind jetzt sichtbar, mit einem Knopf zum Nachholen auf einen Schlag.'),
			],
		},
		{
			version: '0.20.0',
			items: [
				t('Neu: Mitgliedsbeiträge und SEPA-Lastschrift als optionales Zusatzmodul (Reiter „Beiträge").'),
			],
		},
	]
}

/**
 * Einträge, die für die gegebene Rolle sichtbar sind und (sofern sinceVersion
 * gesetzt ist) neuer sind als der zuletzt gesehene Stand. Von App.vue (Gate:
 * überhaupt etwas Neues?) und WhatsNewDialog.vue (Anzeige) gemeinsam genutzt,
 * damit beide Stellen exakt dieselbe Filterlogik anwenden.
 *
 * @param {Array} entries
 * @param {string} role
 * @param {string} sinceVersion leerer String = ungefiltert (alle Einträge der Rolle)
 */
export function filterWhatsNewEntries(entries, role, sinceVersion) {
	return entries
		.filter((entry) => !entry.roles || entry.roles.includes(role))
		.filter((entry) => !sinceVersion || isNewerVersion(entry.version, sinceVersion))
}
