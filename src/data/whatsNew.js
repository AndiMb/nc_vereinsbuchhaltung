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
			version: '0.38.0',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Neu unter Berichte → Gliederung: Plan und Buchungen nach einer Vorlage gegliedert, etwa für einen Zuwendungsgeber oder als Ergebnis je Projekt – mit Druckseite und CSV.'),
				t('Die mitgelieferten Vorlagen lassen sich kopieren und anpassen: Abschnitte, Spalten und Gruppen frei wählbar, ohne den Kontenrahmen zu ändern.'),
				t('Der Finanzplan vergleicht mit einem anderen Zeitraum – vorbelegt das Ist des Vorjahres – und zeigt die Veränderung in Euro und Prozent, auch im Druck und im CSV.'),
			],
		},
		{
			version: '0.38.0',
			roles: ['revisor'],
			items: [
				t('Neu unter Berichte → Gliederung: Plan und Buchungen nach einer Vorlage gegliedert, etwa für einen Zuwendungsgeber oder als Ergebnis je Projekt – mit Druckseite und CSV.'),
				t('Der Finanzplan vergleicht mit einem anderen Zeitraum – vorbelegt das Ist des Vorjahres – und zeigt die Veränderung in Euro und Prozent, auch im Druck und im CSV.'),
			],
		},
		{
			version: '0.37.0',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Der Finanzplan rechnet Plan und Ist über die Unterkonten zusammen: Steht der Plan auf einem Sammelkonto, zählen die Buchungen der Unterkonten mit. Überschreitungen und Buchungen ohne Plan sind markiert.'),
				t('Geplant wird je Zweig auf genau einer Ebene. Der Finanzplan öffnet bis zu dieser Planungsebene und lässt sich auf Auffälligkeiten filtern.'),
				t('Den Soll-Ist-Vergleich gibt es als druckfertige Seite für den Vorstand; auch der Kassenbericht vergleicht jetzt bis zur Planungsebene.'),
				t('Achtung: Im CSV-Export des Finanzplans hat sich die Spaltenfolge geändert.'),
			],
		},
		{
			version: '0.37.0',
			roles: ['revisor'],
			items: [
				t('Der Finanzplan rechnet Plan und Ist über die Unterkonten zusammen: Steht der Plan auf einem Sammelkonto, zählen die Buchungen der Unterkonten mit. Überschreitungen und Buchungen ohne Plan sind markiert.'),
				t('Den Soll-Ist-Vergleich gibt es als druckfertige Seite für den Vorstand; auch der Kassenbericht vergleicht jetzt bis zur Planungsebene.'),
			],
		},
		{
			version: '0.36.2',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Buchungen können einen Kommentar tragen, etwa für die Kassenprüfung – auch im abgeschlossenen Geschäftsjahr noch änderbar.'),
				t('Das Kontenblatt eines Kontos gibt es jetzt als CSV und als druckfertige Seite (Reiter Konten, neben den Summen).'),
				t('Die Tabelle im Kontoauszug lässt sich per Klick auf die Spaltenüberschrift sortieren.'),
			],
		},
		{
			version: '0.36.2',
			roles: ['revisor'],
			items: [
				t('Kommentare an Buchungen (z. B. Hinweise der Kassenprüfung) erscheinen in den Listen als Symbol; beim Daraufzeigen steht der Text.'),
				t('Das Kontenblatt eines Kontos gibt es jetzt als CSV und als druckfertige Seite (Reiter Konten, neben den Summen).'),
			],
		},
		{
			version: '0.36.1',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Die Mitgliederliste zeigt in der neuen Spalte „Nextcloud-Konto?", wer verknüpft ist, und schlägt passende Konten zum Verknüpfen vor.'),
				t('Nextcloud-Administratoren können ein Mitglied beim Aufnehmen gleich mit einem Nextcloud-Konto anlegen – mit Einladung per Mail oder festem Passwort.'),
				t('Die neue Willkommens-Mail (Vorlage in den Einstellungen) geht beim Aufnehmen oder später aus der Akte an die Adresse des Mitglieds.'),
				t('Den Standard-Beitrag in den Einstellungen gibt es nicht mehr – Betrag und Turnus kommen von der Beitragsgruppe.'),
			],
		},
		{
			version: '0.36.0',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Die Suche unter „Alle Buchungen" und „Zuzuordnen" findet jetzt auch Beträge, z. B. 12,50 – und tippt sich bei vielen Buchungen flüssig.'),
				t('Belege aus dem Wächter-Ordner lassen sich schon unter „Zuzuordnen" per Büroklammer vormerken und hängen nach dem Zuordnen an der Buchung.'),
				t('Der Wächter-Ordner zeigt seine Ordner mit offenen und zugeordneten Belegen; „Ordner anzeigen" schaltet auf die einfache Liste zurück.'),
			],
		},
		{
			version: '0.35.0',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Beiträge und SEPA sind neu: Mitglieder, Mandate, Beitragsgruppen, Forderungen.'),
				t('Mandate und Beiträge des bisherigen Moduls sind übernommen – was sich nicht teilen ließ, steht in der Mitgliedernotiz.'),
				t('Einzug in zwei Schritten: freigeben, dann als eingereicht markieren.'),
				t('Bankabgleich schlägt Buchungen vor; Rücklastschriften mit Mahnstufen.'),
				t('„Mein Beitrag" für Mitglieder (ab Werk aus) und Aufgaben-Klemmbrett in der Kopfzeile.'),
			],
		},
		{
			version: '0.34.6',
			items: [
				t('Vorzeichen und Farbe an Beträgen sind jetzt eine Einstellung (Nextcloud-Einstellungen → Darstellung) und ab Werk aus.'),
			],
		},
		{
			version: '0.34.5',
			items: [
				t('Der Kontofilter im Journal kennt jetzt Kategorien wie „Einnahmen" oder „Ausgaben".'),
				t('Der Kassenbericht zeigt in der Sphärenübersicht nur noch Sphären mit Bewegung.'),
			],
		},
		{
			version: '0.34.0',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Neue Art der Belegablage: der Wächter-Ordner (Zahnrad → Belege). Ein Ordner in der Dateien-App, dessen Dateien samt Unterordnern beim Buchen über „Aus Ordner wählen" zur Auswahl stehen – die Dateien bleiben, wo sie sind; Umbenennen und Umsortieren innerhalb des Ordners sind unschädlich.'),
				t('Die Übersicht meldet „x Dokumente noch keiner Buchung zugewiesen", sobald im Wächter-Ordner etwas liegt, das an keiner Buchung hängt – damit keine Rechnung liegen bleibt. „Ansehen" öffnet den Eingangskorb mit „Buchung anlegen" je Datei.'),
			],
		},
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
