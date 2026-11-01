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
			version: '0.49.0',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Neu: Auslagenerstattung. Mitglieder reichen unter „Mein Bereich → Auslagen“ eine Ausgabe mit Beleg ein, mindestens N Verwalter geben sie unter „Buchungen → Auslagen“ frei. „Mein Beitrag“ heißt jetzt „Mein Bereich“.'),
				t('Neu: Mandatstext erneut bestätigen lassen. In den Einstellungen unter „Mandats-Rechtstext“ schreiben Sie alle an, die die aktuelle Fassung noch nicht bestätigt haben; ein Klick im Mail-Link genügt.'),
			],
		},
		{
			version: '0.48.0',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Neu: Belegvorschläge. Die App liest die Dateien im Wächter-Ordner und schlägt beim Buchen passende Belege vor („sicher“ oder „möglich“, mit Begründung). In der Buchungsliste zeigt ein Symbol, wo ein Vorschlag wartet; „Belege zuordnen“ verknüpft alle sicheren auf einmal – immer erst nach Bestätigung.'),
				t('Die Seitenleiste „Aus Ordner wählen“ bleibt am Desktop offen, wenn sie es zuletzt war.'),
			],
		},
		{
			version: '0.47.0',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Neu: Belegvorschau. Am Desktop zeigt ein Klick auf eine Datei im Wächter-Ordner sie direkt neben der Liste, im Buchungsdialog neben Formular und Liste – so lassen sich Rechnung und Buchung vergleichen. ↑/↓ blättern zur nächsten Datei.'),
			],
		},
		{
			version: '0.46.0',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Neu: Belegordner aufräumen. Im Eingangskorb des Wächter-Ordners lassen sich offene Belege auswählen und in einen Unterordner verschieben, z. B. „_Klären“.'),
				t('Neu: Die Ansicht „Dubletten“ findet Dateien mit gleichem Inhalt und zeigt, welche Kopie zugeordnet ist und welche weg kann. Gelöscht wird in der Dateien-App – die App selbst löscht nichts.'),
				t('Der Kontoauszugs-Wachordner verschiebt keine Belege mehr nach „fehler/“, auch wenn er mit dem Beleg-Ordner zusammenfällt.'),
			],
		},
		{
			version: '0.45.1',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Rechnungen nennen die Bankverbindung jetzt auch ohne Auswahl in den Einstellungen: das Konto des SEPA-Einzugs oder das einzige Geldkonto mit IBAN. Fehlt die IBAN, führt die Prüfliste direkt zum Konto.'),
				t('Die Rechnungsliste lässt sich per Klick auf eine Spaltenüberschrift sortieren.'),
			],
		},
		{
			version: '0.45.0',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Neu: E-Mails im Namen des Vereins. Rechnungen, Mahnungen und alle anderen Mails der App tragen den Vereinsnamen als Absender und das Vereinslogo im Kopf; mit hinterlegter E-Mail des Vereins gehen Antworten dorthin.'),
				t('Die Prüfliste im Rechnungseditor verlinkt offene Punkte: Ein Klick führt zum passenden Feld oder in die Einstellungen.'),
			],
		},
		{
			version: '0.44.0',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Neu: Mailkopie an den Vorstand. Mit der Adresse des Vorstands und dem Schalter „Mailkopie an Vorstand“ unter Einstellungen → Rechnungen gehen Mahnmails zusätzlich als Blindkopie an den Vorstand; beim Versand von Rechnungen und Mahnungen gibt es den Schalter „Kopie an Vorstand“.'),
				t('Das Rechnungs-PDF hat gleichmäßigere Abstände und eine aufgeräumte Fußzeile; ein Entwurf ist mit „ENTWURF“ gekennzeichnet.'),
				t('Datum und Uhrzeit erscheinen nach dem eigenen Gebietsschema und in der eigenen Zeitzone. Die Einstellungen der Rechnungen sind neu geordnet: Mahnwesen und E-Mail-Texte stehen getrennt.'),
			],
		},
		{
			version: '0.43.0',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Neu und optional: Rechnungen im Namen des Vereins. Einschalten unter Nextcloud-Einstellungen → Vereinsbuchhaltung → Rechnungen; danach gibt es den Reiter „Rechnungen“. Mehrere Positionen, Hinweis für Kleinunternehmer, Festschreiben mit fortlaufender Nummer, PDF mit GiroCode, Versand per E-Mail, Korrektur per Stornorechnung.'),
				t('Der Kontoauszug-Import ordnet Zahlungen ihrer Rechnung zu: bei passender Rechnungsnummer und genauem Betrag bucht er selbst, sonst schlägt „Zuzuordnen“ die Rechnung vor. Teilzahlungen und Barzahlungen sind möglich.'),
				t('Rechnungen mit Umsatzsteuer: Steuersatz je Position, Summen je Satz, Zahlungen verteilen sich auf Erlös- und Umsatzsteuerkonten.'),
				t('E-Rechnung: Zu festgeschriebenen Rechnungen erzeugt die App ZUGFeRD-PDF oder XRechnung (CII, UBL), schlägt das Format nach Empfänger vor und nennt fehlende Pflichtangaben.'),
				t('Wiederkehrende Rechnungen, Positionsvorlagen und Mahnwesen: ein Plan legt Rechnungen im Takt an, Zahlungserinnerung und Mahnungen gehen als PDF mit GiroCode per E-Mail raus. Die Rechnungen stehen samt Prüfsummen im Beleg-ZIP für die Kassenprüfung.'),
				t('Einstellungen und Tabellen aufgeräumt: Alle Ein/Aus-Einstellungen sind einheitliche Schalter mit Erklärung (der Schalter für den Reiter „Beiträge“ wirkt wieder), die Mahn- und Mailtexte haben einen Knopf „Standardtext übernehmen“, und große Tabellen erscheinen am Handy als Karten.'),
				t('Neue Mindestversion: Nextcloud 33 und PHP 8.2.'),
			],
		},
		{
			version: '0.42.0',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Der Terminplan öffnet sich als eigener Dialog und blättert durch die Beitragsjahre. Der Einzugstag steht als Satz da – „Einzug 14 Tage nach Beginn jeder Periode“ –, darunter jede Periode mit ihrem Termin samt Wochentag. Ein abweichender Termin wird als Datum gewählt; Termine, die eine andere Periode überholen, meldet der Dialog vor dem Speichern.'),
				t('Terminplan und Einstellungen zeigen die Fristen vor dem Einzug als Zeitstrahl mit echten Daten: „Forderungen entstehen“, „Vorabinfo an Mitglieder“, „Lauf freigeben bis“.'),
				t('Unter „Alle Buchungen“ lässt sich ein Beleg aus dem Wächter-Ordner an mehrere markierte Buchungen auf einmal hängen, etwa eine Sammelrechnung: Auswahlspalte vorne, „Beleg zuordnen…“ in der Leiste unter der Liste.'),
				t('IBANs liegen jetzt verschlüsselt in der Datenbank; das Update verschlüsselt den Bestand einmalig. Wichtig für Backups: Ohne die zugehörige config.php sind sie nach einer Wiederherstellung nicht mehr lesbar (Handbuch 12.3).'),
			],
		},
		{
			version: '0.41.1',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Kontoauszüge, die statt der IBAN nur Kontonummer und BLZ nennen (MT940, manche CSV), landen auf dem richtigen Geldkonto – vorausgesetzt, die IBAN steht am Geldkonto.'),
				t('„Nextcloud-Konto anlegen“ fragt bei Bedarf das Passwort ab und scheitert nicht mehr mit „Password confirmation is required“.'),
			],
		},
		{
			version: '0.41.0',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('„Beitrag ändern“ kann jetzt auch die Zahlungsart umstellen: von Lastschrift auf Überweisung und zurück, ohne die Zuweisung neu anzulegen. Die Umstellung wirkt sofort; das Mandat bleibt gültig und wird nur nicht mehr genutzt.'),
				t('Neuer Schalter in den Grundeinstellungen: Mitglieder dürfen unter „Mein Beitrag“ selbst von Lastschrift auf Überweisung umstellen – zurück auf Lastschrift stellt der Vorstand.'),
				t('Die Mitgliederliste rechnet das Beitragsaufkommen jetzt für den gewählten Zeitraum und nennt ihn in der Summenzeile.'),
			],
		},
		{
			version: '0.40.0',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Buchungsdialog: „Aus Ordner wählen“ zeigt den Belegordner als Seitenleiste neben dem Formular; Belege werden erst mit „Speichern“ angehängt, gelöst oder gelöscht – bis dahin lässt sich alles rückgängig machen.'),
				t('Wer den Buchungsdialog mit ungespeicherten Änderungen schließt, wird vorher gefragt.'),
			],
		},
		{
			version: '0.39.0',
			roles: ['verwalter', 'buchhalter'],
			items: [
				t('Gliederungsbericht: Die beiden Standardvorlagen lassen sich jetzt direkt bearbeiten und löschen; „Neue Vorlage“ startet bei null, „Standardvorlagen hinzufügen“ holt sie zurück.'),
			],
		},
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
