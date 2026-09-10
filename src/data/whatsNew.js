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
//
// Ein Eintrag darf der laufenden App-Version vorauslaufen: er darf also schon
// hier stehen, bevor der Release-Commit appinfo/info.xml auf diese Version
// hebt. filterWhatsNewEntries() blendet ihn bis dahin aus - warum das noetig
// ist, steht beim Test "nach dem Wegklicken bleibt nichts uebrig".
import { t } from '../lib/l10n.js'
import { compareVersions, isNewerVersion } from '../lib/version.js'

export function buildWhatsNewEntries() {
	return [
		{
			version: '0.33.0',
			items: [
				t('Das Geschäftsjahr muss nicht mehr dem Kalenderjahr entsprechen. Unter Zahnrad → Geschäftsjahr lässt sich der Beginn frei wählen – etwa 1. Oktober bis 30. September oder das Schuljahr von August bis Juli. Auch halbjährliche Zeiträume (Semester) sind möglich.'),
				t('Aus dem „Jahr" oben in der Kopfzeile ist damit ein „Zeitraum" geworden. Jeder Zeitraum trägt eine frei änderbare Bezeichnung wie „2025/26"; Buchungsnummern, Berichte, Finanzplan und Festschreibung beziehen sich darauf.'),
			],
		},
		{
			version: '0.32.0',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Im Kontoauszug (Reiter „Konten") lassen sich Buchungen jetzt direkt bearbeiten – Knopf am Zeilenende, wie im Reiter „Buchungen". Kein Notieren der Buchungsnummer und kein Wechsel der Ansicht mehr, nur um einen Text oder ein falsches Konto zu korrigieren. Belege sind dort ebenfalls einsehbar; das Umbuchen ist ins Menü hinter den drei Punkten gewandert.'),
			],
		},
		{
			version: '0.31.0',
			items: [
				t('Oben rechts steht jetzt der Geldbestand aller Geldkonten zusammen, nicht mehr nur der des ersten Kontos. Die Aufschlüsselung nach Konten zeigt der Tooltip, wenn die Maus auf der Zahl steht.'),
				t('Ein einzelnes Geldkonto lässt sich aus dieser Zahl herausnehmen – im Konto-Dialog über „Zählt in den Geldbestand oben in der Kopfzeile". Kassenbericht, Vermögensübersicht und Saldenliste rechnen unverändert mit allen Geldkonten.'),
			],
		},
		{
			version: '0.30.0',
			roles: ['buchhalter', 'verwalter'],
			items: [
				t('Belege lassen sich jetzt auch am Desktop schon beim Anlegen einer Buchung anhängen – bisher ging das nur am Handy. Die Dateien wandern hoch, sobald die Buchung gespeichert ist.'),
			],
		},
		{
			version: '0.29.0',
			items: [
				t('Der Bericht „Kostenstellen" heißt jetzt „Auswertungsgruppen" – gleiche Funktion, treffenderer Name. Angelegte Gruppen und ihre Konto-Zuordnungen bleiben unverändert.'),
			],
		},
		{
			version: '0.28.0',
			items: [
				t('Steht Nextcloud auf Englisch, erscheint jetzt auch die Oberfläche der App auf Englisch – bisher blieb sie deutsch, weil das Übersetzungspaket nicht geladen werden konnte.'),
			],
		},
		{
			version: '0.28.0',
			roles: ['buchhalter', 'verwalter'],
			items: [
				t('Mitgliederlisten dürfen jetzt auch englische Spaltenüberschriften haben (Name, Email, IBAN, BIC, Mandate, Amount, Frequency, Start date).'),
			],
		},
		{
			version: '0.27.0',
			items: [
				t('Handbuch und Prüfleitfaden gibt es jetzt auch auf Englisch – die App liefert automatisch die passende Sprache aus, je nachdem, welche Sprache in den Nextcloud-Einstellungen eingestellt ist.'),
			],
		},
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
 * Einträge, die für die gegebene Rolle sichtbar sind, (sofern sinceVersion
 * gesetzt ist) neuer sind als der zuletzt gesehene Stand und nicht über die
 * laufende App-Version hinausgehen. Von App.vue (Gate: überhaupt etwas
 * Neues?) und WhatsNewDialog.vue (Anzeige) gemeinsam genutzt, damit beide
 * Stellen exakt dieselbe Filterlogik anwenden.
 *
 * @param {Array} entries
 * @param {string} role
 * @param {string} sinceVersion leerer String = ungefiltert (alle Einträge der Rolle)
 * @param {string} currentVersion laufende App-Version; leerer String = keine Obergrenze
 */
export function filterWhatsNewEntries(entries, role, sinceVersion, currentVersion = '') {
	return entries
		.filter((entry) => !entry.roles || entry.roles.includes(role))
		.filter((entry) => !sinceVersion || isNewerVersion(entry.version, sinceVersion))
		.filter((entry) => !currentVersion || compareVersions(entry.version, currentVersion) <= 0)
}
