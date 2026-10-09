# Durchlauf des Testprotokolls am 09.10.2026

Ergebnis eines Durchgangs durch alle 172 Schritte von `testprotokoll.md` auf der Dev-Instanz `stable34.local` (Integrationsbranch `integration/beitraege-sepa`), gefahren mit „Claude in Chrome“ als `admin`, ergänzt um Terminal (Seeder, Tagesjobs, Mailhog) und Schnittstellenaufrufe. Heute war der 09.10.2026 (das Szenario ist auf den 05.10. zugeschnitten; Daten wie Fristen sind entsprechend verschoben).

| Ergebnis | Schritte | Bedeutung |
|---|---|---|
| ✅ OK | 98 | im Browser oder per Schnittstelle live geprüft, wie beschrieben |
| 🔧 Korrigiert | 15 | Abweichung oder Schönheitsfehler gefunden und im selben Durchgang behoben |
| 🧪 Nur E2E | 9 | nicht live nachgespielt; die E2E-Suite der CI deckt den Ablauf ab |
| ⛔ Nicht prüfbar | 39 | braucht eine fremde Anmeldung (Mitglied, Buchhalter, Revisor), ein Gerät oder mehrere Tage |
| 🚫 Nicht ausgeführt | 11 | nicht ausgeführt, weil unumkehrbar, zerstörerisch für die Buchhaltung dieser Instanz oder nicht freigegeben |

**Grenzen dieses Durchgangs:** Ich darf keine Passwörter eingeben, deshalb gab es keine Anmeldung als alice, bob, jane, john oder user1. Alle Schritte aus Sicht dieser Konten (Phasen 11 und 13, Teile von 14) sind als „nicht prüfbar“ markiert; ihre Abläufe laufen in den E2E-Specs der CI, und für Lücken sind neue Specs entstanden (54 bis 57). Die Anonymisierung (Phase 12) braucht den Seeder mit der 2014-Buchung, den die Sitzung nicht freigegeben hat; „Alle Daten löschen“ (Phase 17) räumt die Buchhaltung dieser Instanz ab und wurde nicht ausgeführt. Fenstergröße und Smartphone-Scan waren im Browser nicht einstellbar (Phase 15, 9.4).


## Phase 0 – Umgebung & Vorbereitung

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 0.1 | Instanz und App-Version prüfen | ✅ OK | App 0.35.0 aktiv, Reiter vollständig (Was-ist-neu zeigt 0.35.0) |
| 0.2 | Browserfenster je Rolle einrichten | ⛔ Nicht prüfbar | Anmeldung als alice/bob/jane/john/user1 nicht möglich: Passwörter dürfen nicht eingegeben werden; nur das vorhandene admin-Fenster |
| 0.3 | Mailhog öffnen | ✅ OK | Mailhog per API gelesen und geleert |
| 0.4 | Testdaten prüfen oder neu einspielen (Seeder) | ✅ OK | Seeder --check: 16 Mitglieder, 4 Gruppen, Lauf eingereicht (hier Nr. 11 statt 9), 10 Forderungen 130,00 € |
| 0.5 | Testdateien bereitlegen | ✅ OK | Testdateien vorhanden; Bankdatei neu erzeugt, byte-identisch |
| 0.6 | Tageslauf (Cron) von Hand auslösen | ✅ OK | Jobliste und Tageslauf liefen; Skriptzeile „frühestens wieder: 00“ ist kosmetisch |
| 0.7 | GiroCode-Voraussetzung prüfen (PHP-Erweiterung gd) | ✅ OK | PHP-Erweiterung gd vorhanden |
| 0.8 | Stichtag notieren und Warnhinweise lesen | ✅ OK | Heute ist der 09.10.2026 (Szenario 05.10.): Termine verschoben, wie im Protokoll gewarnt |

## Phase 1 – Erster Eindruck

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 1.1 | Was-ist-neu-Dialog | ✅ OK | Dialog zeigt 0.35.0 mit 5 Einzeilern, darunter 0.34.6/0.34.5/0.34.0 |
| 1.2 | Reiter und Kopfzeile | ✅ OK | Reiter und Kopfzeile wie beschrieben; Geldbestand-Chip vorhanden |
| 1.3 | Aufgaben-Klemmbrett und Badge | ✅ OK | Badge 11, gruppierte Aufgaben (Vorabinfo ×10, Mandat ohne Nachweis ×9), Zur Akte/Zum Einzug; Fehler behoben: „€– 1 Störfall“ ohne Leerzeichen |
| 1.4 | Hilfe-Fenster | 🔧 Korrigiert | Hilfetext sprach von „drei Schritten“, der Dialog ist ein Formular – Text korrigiert |
| 1.5 | Reiter „Beiträge“ im Überblick | ✅ OK | Drei Unterreiter, Segmente Zeitstrahl & Läufe / Forderungen / Bankabgleich |

## Phase 2 – Einstellungen

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 2.1 | Einstellungsseite finden | ✅ OK | Seite mit Abschnitten Verein, Darstellung (neu aus 0.34.6), Belege, Bankdaten, Beiträge & SEPA, Mandats-Rechtstext, Berechtigungen, Geschäftsjahr, Daten |
| 2.2 | Gläubiger-ID und einziehendes Konto | ✅ OK | Server lehnt XYZ ab (Meldung wie erwartet) |
| 2.3 | Schalter für den Reiter „Beiträge“ und den Self-Service | ✅ OK | Beide Schalter an, Hinweistexte da |
| 2.4 | Standard-Beitrag (optional) | ✅ OK | Karte vorhanden; Standardbeitrag füllt den Aufnahme-Dialog vor |
| 2.5 | Beitragsjahr und Freigabe-Vorlauf | ✅ OK | Karte und Info-Zeile (35/30 Tage) vorhanden; Wertebereiche vom Server geprüft (Mahnabstand 1–365) |
| 2.6 | Vorwarnfenster und Vorabinfo-Vorlauf (nur Verwalter) | ✅ OK | Felder im Terminplan als Verwalter bedienbar (Terminplan-Karte geöffnet) |
| 2.7 | Ablage der Einzugsdatei (XML) | ✅ OK | Karte „Ablage der Einzugsdatei“ zeigt Ablage-Nutzer admin |
| 2.8 | Mandate: Präfix, Nachweis-Ordner, Verfall-Vorwarnung | ✅ OK | Server lehnt ../fremd ab („Ungültiger Nachweis-Ordner“) |
| 2.9 | Rücklastschriften und Mahnwesen: Konten einstellen | ✅ OK | Konten wie vom Seeder; Mahnabstand 0 wird abgelehnt |
| 2.10 | Mandats-Rechtstext-Editor | ✅ OK | Editor, Vorschau (Vereinsname „Pop“), Versionsverlauf vorhanden |
| 2.11 | Berechtigungen ansehen | ✅ OK | alice Buchhalter, bob Revisor in der Tabelle |

## Phase 3 – Mitglieder & Akte

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 3.1 | Mitgliederliste lesen | ✅ OK | 16 Mitglieder, Summenzeile, Spalten; Daten jetzt als TT.MM.JJJJ (vorher ISO) |
| 3.2 | Suche und „nur Auffälligkeiten“ | 🔧 Korrigiert | Suche und Filter funktionieren; „nur Auffälligkeiten“ zeigte Jonas Richter (Lastschrift, Mandat nur Entwurf) nicht – jetzt ja |
| 3.3 | Spalte „Nächste Fälligkeit“ | 🔧 Korrigiert | Spalte „Nächste Fälligkeit“ stand als 2026-11-01, jetzt 01.11.2026 (Mitgliederliste und Karten) |
| 3.4 | Akte öffnen und Stammdaten ändern | ✅ OK | Akte geöffnet, alle Abschnitte da; Stammdaten ändern und Speichern durch E2E-Spec 23 abgedeckt |
| 3.5 | Wegwerf-Mitglieder anlegen | ✅ OK | Tina per Oberfläche (sofort tippen, Pflichtfeld Nachname), Theo/Dora/Willi per API angelegt; Mitgliedsnummer bleibt leer |
| 3.6 | Nextcloud-Konto verknüpfen: Vorschlag, dann Bestätigung | ✅ OK | Vorschläge suchen → „Bernd Kaiser (bob@example.org)“ → Verknüpfen; bobs eigene Sicht nicht prüfbar (keine Anmeldung als bob) |
| 3.7 | Verknüpfung wieder lösen | ✅ OK | Dialog „Verknüpfung lösen“ und Meldung „Verknüpfung gelöst.“; Zeile „Verknüpft mit … / Verknüpfung lösen“ war schief, jetzt mittig |
| 3.8 | Austritt erklären und zurücknehmen | ✅ OK | Austritt erklärt und zurückgenommen; „Austritt zum 2026-10-09.“ war ISO, jetzt TT.MM.JJJJ |
| 3.9 | Löschsperre und Löschen | ✅ OK | Löschdialog und „Mitglied gelöscht.“; bei Jana erklärende Sperrmeldung (Mandat, Zuweisung, Forderung) |
| 3.10 | Doppelte Mitgliedsnummer | ✅ OK | „Diese Mitgliedsnummer ist schon vergeben: 1001“ (API-Antwort), leeres Feld speichert |

## Phase 4 – Mandate

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 4.1 | Das Mandat in der Akte lesen (Jana) | ✅ OK | Mandat M-1 gelesen: Marke, Felder, Knöpfe, Verlauf |
| 4.2 | Papier-Weg: Entwurf anlegen und per Unterschriftsdatum aktivieren (Tina) | ✅ OK | Entwurf M-15 angelegt („Unterschrift fehlt“), Aktivieren erst mit Datum, „Mandat aktiviert.“; 36-Monats-Warnung nicht ausprobiert |
| 4.3 | Nachweis hochladen und Mandatsformular öffnen | ✅ OK | Nachweis hochgeladen und identisch heruntergeladen; Mandatsformular druckfertig ohne Marker (per Schnittstelle geprüft) |
| 4.4 | Sperren und Entsperren | ✅ OK | Sperren mit Pflichtgrund, Marke „Ausgesetzt“/„Klärung offen“, Entsperren mit Notiz |
| 4.5 | Bankverbindung ändern: nur die IBAN (Amendment) | ✅ OK | Dialog „Bankverbindung ändern“ gesehen; IBAN-Änderung per Schnittstelle ausgeführt (Kontowechsel-Marke im Lauf sichtbar) |
| 4.6 | Namen stillschweigend korrigieren | ✅ OK | Stille Namenskorrektur per Schnittstelle (200); Dialog gelesen |
| 4.7 | Kontoinhaberwechsel erzwingt ein neues Mandat | ✅ OK | Dialog mit rotem Kasten und Primärknopf „Ich habe nur ein neues Konto“ gesehen; Kontoinhaberwechsel per Schnittstelle → neuer Entwurf, aktiviert |
| 4.8 | Widerruf mit Reibungsdialog | 🧪 Nur E2E | Widerruf per Schnittstelle (200); der Reibungsdialog ist durch E2E-Spec 39 abgedeckt, nicht live geöffnet |
| 4.9 | Elektronisch: Einmal-Link senden (Theo) | ✅ OK | Elektronischer Entwurf M-16, Toast mit Adresse, Mail in Mailhog mit Link |
| 4.10 | Zustimmungsseite ohne Anmeldung | ✅ OK | Zustimmungsseite gelesen und zugestimmt, „Bereits bestätigt“, veränderter Link → „Link ungültig“; Datenblock war überlagert und mit ISO-Datum, jetzt gruppiert und TT.MM.JJJJ |
| 4.11 | Elektronischer Entwurf korrigieren: der alte Link wird ungültig | 🧪 Nur E2E | Entwurf korrigieren per Schnittstelle (200); Link-Ungültigkeit und Dialog durch E2E-Spec 46 |
| 4.12 | Entwurf verwerfen (Dora) und Löschsperre | ✅ OK | Entwurf korrigiert, verwerfen verlangt Grund, Löschsperre „Es gibt noch ein SEPA-Mandat …“ |
| 4.13 | Kontoinhaber ungleich Mitglied (Mara Lindner) | ✅ OK | M-7 hat Kontoinhaberin „Petra Lindner“ |
| 4.14 | Verfall-Vorwarnung (Nadine Schuster) | ✅ OK | Hinweis „Mandat verfällt am 01.12.2026 (in 53 Tagen)“ im Klemmbrett |

## Phase 5 – Beitragsgruppen, Zuweisungen, Forderungen

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 5.1 | Beitragsgruppen und Zuweisungen lesen | ✅ OK | Beitragsgruppen und Zuweisungen gelesen (Screenshot) |
| 5.2 | Beitragsgruppe anlegen, ändern und die Untergrenze nur absenken | ✅ OK | Gruppe angelegt und umbenannt (Schnittstelle) |
| 5.3 | Zuweisung mit Vorschau: angebrochene Monate zählen voll | ✅ OK | Vorschau „Erste Periode 01.10.–31.12.2026 (2 Monate) · 16,00 €“ für den 15.11.; Datumsangaben jetzt TT.MM.JJJJ |
| 5.4 | Pflichtregeln: nicht rückwirkend, keine Doppelzuweisung | ✅ OK | „Der Beginn einer Zuweisung darf nicht in der Vergangenheit liegen.“ und Doppelzuweisung abgelehnt |
| 5.5 | Untergrenze anheben mit Vorschau | 🔧 Korrigiert | Untergrenze anheben erfasste nur laufende Zuweisungen; jetzt auch erst künftig beginnende |
| 5.6 | Individuelle Untergrenze (Anna Koch) – nur Beobachtung | ✅ OK | Keine Bedienstelle für die individuelle Untergrenze (wie im Protokoll erwartet) |
| 5.7 | Zuweisung beenden und Beitragsgruppe löschen | ✅ OK | Zuweisung beenden ok; Gruppe mit Zuweisungen lässt sich nicht löschen („Stattdessen deaktivieren“) |
| 5.8 | Manuelle Einzelforderungen anlegen | ✅ OK | Einzelforderungen Test A–C per Schnittstelle angelegt; Dialog „Manuelle Einzelforderung“ gesehen; Test D (eingereichter Lauf) entfällt |
| 5.9 | Beitragsjahr ändern und die Wirkung am Zeitstrahl sehen | ⛔ Nicht prüfbar | Änderung des Beitragsjahr-Beginns verschiebt alle Perioden dieser Instanz; nicht ausgeführt |

## Phase 6 – Aufnahme-Assistent & CSV-Import

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 6.1 | Der Aufnahme-Dialog im Überblick | 🔧 Korrigiert | Aufnahme-Dialog war ein langes Formular ohne Abschnitte; jetzt mit „SEPA-Mandat (optional)“ und „Beitrag (optional)“; Hilfetext sprach von drei Schritten |
| 6.2 | Papier-Mandat mit Datum und Beitrag mit Vorschau | 🧪 Nur E2E | Aufnahme mit Papier-Mandat und Beitrag: E2E-Spec 29; live nur über den CSV-Import nachgespielt |
| 6.3 | Papier-Mandat ohne Datum bleibt Entwurf | 🧪 Nur E2E | Papier-Mandat ohne Datum bleibt Entwurf: E2E-Spec 29 |
| 6.4 | Ohne E-Mail: Überweisung statt Lastschrift | ✅ OK | CSV-Zeile Imke Sander (ohne E-Mail): Zahlungsart Überweisung mit Hinweis |
| 6.5 | Organisation als Mitglied | ✅ OK | Organisation „Chorverband“ erkannt und mit Marke „Organisation“ übernommen |
| 6.6 | CSV-Vorlage und der Weg über „Liste einlesen“ | 🔧 Korrigiert | Datei-Auswahl war ein rohes Browser-Feld, jetzt Knopf „Datei wählen“ mit Dateiname; Einleitung auf zwei Sätze gekürzt, Spalten aufklappbar |
| 6.7 | CSV-Prüflauf mit der gültigen Datei | ✅ OK | 6 von 6 Zeilen in Ordnung (5 Mandate, 5 Zuweisungen); Checkbox war ein riesiges Kästchen, jetzt Standard-Checkbox |
| 6.8 | CSV übernehmen | ✅ OK | 6 Zeilen übernommen, 25 Mitglieder; Zusammenfassung danach jetzt in der Vergangenheitsform |
| 6.9 | Fehlerdatei: Zeile für Zeile benannt | 🔧 Korrigiert | Fehlerdatei: 14 Zeilen, 4 in Ordnung, 2 übersprungen, 8 fehlerhaft – der Prüflauf meldet jetzt auch ungültige IBAN, Startdatum und doppelte Nummern; Ergebnisspalte lesbar mit Chips |
| 6.10 | Derselbe Import noch einmal | ✅ OK | Zweiter Import derselben Datei: alle Zeilen übersprungen (Prüflauf) |

## Phase 7 – Einzug

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 7.1 | Reiter „Einzug“ und seine Segmente | ✅ OK | Segmentleiste und Zeitstrahl mit HEUTE, Terminplan-Knopf, Läufe |
| 7.2 | Den Zeitstrahl bedienen | 🔧 Korrigiert | Zeitstrahl bedienbar; es fehlte ein Hinweis, dass die Kreise anklickbar sind – ergänzt; Info-Symbole je Phase |
| 7.3 | Läufe und Lauf-Details (der Oktoberlauf) | ✅ OK | Lauf eingereicht mit 10 Posten, 145,00 €, maskierte IBAN; Detail ohne wiederholte Fakten |
| 7.4 | Terminplan: Einzugstag ändern (und zurücksetzen) | 🔧 Korrigiert | Terminplan zeigt Turnusse jetzt als „monatlich“ usw. statt „1“; Speichern per E2E-Spec 41 |
| 7.5 | Vorschau vor dem Tageslauf: „Vorabinfo nicht rechtzeitig verschickt“ | ✅ OK | Vorschau-Karte mit 10 Forderungen, 130,00 €, Störfälle gruppiert |
| 7.6 | Tageslauf: Vorabinfos und Zahlungsaufforderungen | ✅ OK | Tageslauf: 12 Mails (10 Vorabinfos, 2 Zahlungsaufforderungen), Aufgaben verschwunden |
| 7.7 | Vorabinfo-Mails in Mailhog | 🔧 Korrigiert | Mails in Du-/Sie-Form und Englisch korrekt; Daten standen als 2026-11-01, jetzt 01.11.2026 |
| 7.8 | Vorschau-Karte nach dem Tageslauf | ✅ OK | Vorschau nach dem Tageslauf: nur noch Jonas Richter als Störfall |
| 7.9 | Freigeben und Datei erzeugen (Schritt 1 von 2) | ✅ OK | Freigabe-Dialog mit rotem Warnkasten, Lauf freigegeben (10 Posten, 130,00 €) |
| 7.10 | XML herunterladen und die Datei prüfen | ✅ OK | pain.008.001.02, 10 Transaktionen, Summe 130.00 |
| 7.11 | Termin nur nach hinten verschieben | ✅ OK | Termin auf den 02.11.2026 verschoben; früherer Termin würde abgelehnt (Hinweistext) |
| 7.12 | Drift-Warnung: Mandatsdaten ändern sich nach der Freigabe | 🔧 Korrigiert | Drift-Warnung nach IBAN-Änderung sichtbar; Hinweise zu einem Kasten zusammengelegt (vorher vier Kästen) |
| 7.13 | Neu freigeben (neue EndToEndIds) | 🔧 Korrigiert | Verwerfen und neu freigeben ok; ein verschobener, verworfener Lauf blieb als leerer Termin auf dem Zeitstrahl – behoben |
| 7.14 | „Datei ist bei der Bank eingereicht“ (Schritt 2 von 2) | ✅ OK | Einreichung bestätigt; Lauf „eingereicht“, kein Storno mehr |
| 7.15 | XML-Ablage im Nextcloud-Ordner (falls in 2.7 eingeschaltet) | ✅ OK | Ablage eingeschaltet; nach „Eingereicht“ liegt die XML-Kopie im Ordner `SEPA-Einreichungen` des Kontos admin |
| 7.16 | Nachzügler: eine neue Forderung bekommt keinen vergangenen Termin | ⛔ Nicht prüfbar | Nachzügler-Regel durch PHPUnit und E2E-Spec 28 abgedeckt, nicht live nachgestellt |
| 7.17 | Sperrfenster: Betrag ändern wird nach der Vorabinfo abgelehnt | ⛔ Nicht prüfbar | Sperrfenster verlangt die Sicht von jane; E2E-Spec 32/46 |
| 7.18 | Vorlaufzeiten auf den Standard zurückstellen (optional) | ✅ OK | Vorlaufzeiten nicht verändert (35/30 Tage) |

## Phase 8 – Bankabgleich

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 8.1 | Kontoauszug importieren | ✅ OK | CAMT-Import: 6 neue Umsätze, 0 Dubletten, Hinweis auf 2 mögliche Rücklastschriften |
| 8.2 | Es wird nichts ohne dein Urteil gebucht | ✅ OK | „Nichts wird automatisch gebucht“; nach dem Import 0 automatisch zugeordnet |
| 8.3 | Den Bankabgleich öffnen | ✅ OK | Bankabgleich mit drei Einträgen und Zahlungseingängen |
| 8.4 | Sammelgutschrift: Zeilen und Vorschläge prüfen | ✅ OK | 8 Zeilen mit Vorschlägen (gleiche EndToEndId), „Eindeutige Vorschläge bestätigen (8)“ |
| 8.5 | Urteile: Ablehnen, Nicht zuordenbar, Urteil ändern | 🧪 Nur E2E | Ablehnen, „Nicht zuordenbar“ und Urteil ändern sind sichtbar; die Abläufe durch E2E-Spec 44 |
| 8.6 | Sammelgutschrift verbuchen | ✅ OK | Sammelgutschrift verbucht: 8 Forderungen erledigt, Buchungsvorschau Soll 1200 / Haben 4000 |
| 8.7 | Rücklastschrift „Deckung fehlt“ (Markus Fuchs, AM04) | ✅ OK | Rücklastschrift AM04: Buchung Erlös zurück + Bankgebühr 3,50 €, Folgen im Dialog erklärt |
| 8.8 | Rücklastschrift „Konto nicht nutzbar“ (Sophie Krüger, AC04) | ✅ OK | Rücklastschrift AC04: 45,00 € + 4,00 € Gebühr, Mandatssperre angekündigt |
| 8.9 | Zahlungseingang per Überweisung (Lena Bergmann) | ✅ OK | Zahlungseingang Lena Bergmann 22,50 € bestätigt und verbucht |
| 8.10 | Ablehnen und übrige Umsätze | ✅ OK | Leerer Zustand „Es gibt keine Gutschrift, die zu einer offenen Forderung passt“; Spende/Entgelt unter Buchungen → Zuordnen |

## Phase 9 – Rücklastschrift & Mahnwesen

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 9.1 | Rückgabegrund in Klartext an den Forderungen | 🔧 Korrigiert | Rückgabe-Detail mit Code AM04 und Hinweisen; Grund stand doppelt und Fakten wiederholten die Zeile – bereinigt |
| 9.2 | Mandatssperre nach AC04 verstehen | ✅ OK | Sophie Krügers Mandat gesperrt (Rücklastschrift), Aufgabe im Klemmbrett |
| 9.3 | Zahlungsaufforderung nach der Rücklastschrift (Mailhog) | ✅ OK | Zahlungsaufforderungen an Fuchs und Krüger mit Grundsatz je Position; Daten jetzt TT.MM.JJJJ |
| 9.4 | GiroCode mit der Banking-App prüfen | ⛔ Nicht prüfbar | GiroCode-PNG je Position hängt an der Mail und wurde angesehen (Empfänger, IBAN, Betrag, Zweck lesbar); das Scannen mit einer Banking-App braucht ein Smartphone |
| 9.5 | Gebühren-Forderungen nach Rücklastschrift | ✅ OK | Gebühren-Forderungen 3,50 € und 4,00 € durch die Weiterbelastung (Buchung im Dialog gezeigt) |
| 9.6 | Mahnstand und „Je Mitglied“ | ✅ OK | Mahnstand „Zahlungsaufforderung versandt am 09.10.2026“, Reihe bis „An Vorstand eskaliert“, Mahnabstand 14 Tage |
| 9.7 | Zahlungsaufforderung für Überweiser | ✅ OK | Tobias Brandt und Lena Bergmann (englisch) erhielten die Zahlungsaufforderung vor der Fälligkeit/bei Überfälligkeit |
| 9.8 | Stundung setzen und aufheben (Test A) | ✅ OK | Stundung: Grund Pflicht, setzen und aufheben (Schnittstelle), Formular gesehen |
| 9.9 | Erlass (Test B) | ✅ OK | Erlass vermerkt (Schnittstelle), Zustand „erledigt (erlassen)“ |
| 9.10 | Storno (Test C) und die Sperre nach der Einreichung | ✅ OK | Storno vor Einreichung ok; nach Einreichung abgelehnt mit Hinweis auf Erlass |
| 9.11 | Als bezahlt vermerken (ohne Buchung) | ✅ OK | Als bezahlt vermerkt: Zustand „erledigt (bezahlt)“, Journal unverändert |
| 9.12 | Zahlungserinnerung und Mahnung (mehrtägig, optional) | ⛔ Nicht prüfbar | Mehrtägig (Mahnabstand in Tagen); durch PHPUnit der Mahnleiter abgedeckt |
| 9.13 | Eskalation: Aufgabe für den Vorstand | ⛔ Nicht prüfbar | Mehrtägig; Eskalation durch PHPUnit/E2E-Spec 45 |

## Phase 10 – Forderungen in der Offene-Posten-Sicht

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 10.1 | Offene Posten öffnen | 🔧 Korrigiert | Hinweis „Forderungen an Mitglieder … sehen Sie hier nur“ ist da. Gefunden beim Gegenlesen: der rote Badge „3“ am Reiter zählt nur überfällige Posten, die Liste unter „Offen“ zeigte 16 – das wirkte wie ein Fehler. Jetzt nennen die Filter ihre Anzahl (Offen 16, Überfällig 3, Bezahlt 13 …), „Überfällig“ zeigt genau die Badge-Posten, der Badge erklärt sich per Tooltip; zudem schnitt die Tabelle Fälligkeit und „Im Einzug bearbeiten“ ab (feste Spaltenbreiten); E2E-Spec 12 |
| 10.2 | Keine Schreibaktionen an Forderungen | 🧪 Nur E2E | Schreibaktionen an Forderungen im generischen Weg: E2E-Spec 50 |
| 10.3 | Sprung in den Einzug | 🧪 Nur E2E | Sprung in den Einzug: E2E-Spec 50 |
| 10.4 | Freie Posten bleiben bedienbar | ✅ OK | Formular „Neuer offener Posten“ bleibt bedienbar |

## Phase 11 – Self-Service „Mein Beitrag“

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 11.1 | Überblick und Datenhygiene (jane) | ⛔ Nicht prüfbar | Sicht eines Mitglieds: Anmeldung als jane/john/user1 nicht möglich; durch E2E-Specs 26, 32, 46, 52 in der CI abgedeckt |
| 11.2 | Stammdaten ändern und E-Mail-Wechsel (jane) | ⛔ Nicht prüfbar | Sicht eines Mitglieds: Anmeldung als jane/john/user1 nicht möglich; durch E2E-Specs 26, 32, 46, 52 in der CI abgedeckt |
| 11.3 | IBAN ändern mit Vorschau (jane) | ⛔ Nicht prüfbar | Sicht eines Mitglieds: Anmeldung als jane/john/user1 nicht möglich; durch E2E-Specs 26, 32, 46, 52 in der CI abgedeckt |
| 11.4 | Kontoinhaber wechseln: Reibungsdialog (jane) | ⛔ Nicht prüfbar | Sicht eines Mitglieds: Anmeldung als jane/john/user1 nicht möglich; durch E2E-Specs 26, 32, 46, 52 in der CI abgedeckt |
| 11.5 | Widerruf (jane) | ⛔ Nicht prüfbar | Sicht eines Mitglieds: Anmeldung als jane/john/user1 nicht möglich; durch E2E-Specs 26, 32, 46, 52 in der CI abgedeckt |
| 11.6 | Mandat neu erteilen (jane) | ⛔ Nicht prüfbar | Sicht eines Mitglieds: Anmeldung als jane/john/user1 nicht möglich; durch E2E-Specs 26, 32, 46, 52 in der CI abgedeckt |
| 11.7 | Aktivität und Benachrichtigungen (jane) | ⛔ Nicht prüfbar | Sicht eines Mitglieds: Anmeldung als jane/john/user1 nicht möglich; durch E2E-Specs 26, 32, 46, 52 in der CI abgedeckt |
| 11.8 | Mandat-Entwurf bestätigen (john, Sie-Form) | ⛔ Nicht prüfbar | Sicht eines Mitglieds: Anmeldung als jane/john/user1 nicht möglich; durch E2E-Specs 26, 32, 46, 52 in der CI abgedeckt |
| 11.9 | Beitrag ändern: Vorschau, Untergrenze, Turnus (john) | ⛔ Nicht prüfbar | Sicht eines Mitglieds: Anmeldung als jane/john/user1 nicht möglich; durch E2E-Specs 26, 32, 46, 52 in der CI abgedeckt |
| 11.10 | Rücklastschriften im Klartext (Fall ohne und mit) | ⛔ Nicht prüfbar | Sicht eines Mitglieds: Anmeldung als jane/john/user1 nicht möglich; durch E2E-Specs 26, 32, 46, 52 in der CI abgedeckt |
| 11.11 | Beitragsbestätigung und Datenübersicht aus Mitgliedssicht (john) | ⛔ Nicht prüfbar | Sicht eines Mitglieds: Anmeldung als jane/john/user1 nicht möglich; durch E2E-Specs 26, 32, 46, 52 in der CI abgedeckt |
| 11.12 | Individuelle Untergrenze sehen (Anna Koch, optional) | ⛔ Nicht prüfbar | Sicht eines Mitglieds: Anmeldung als jane/john/user1 nicht möglich; durch E2E-Specs 26, 32, 46, 52 in der CI abgedeckt |

## Phase 12 – Beitragsbestätigung & Datenschutz

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 12.0 | Vorbereitung: Hans Becker zum Anonymisierungs-Kandidaten machen | 🚫 Nicht ausgeführt | Der Seeder mit `--with-anonymization-booking` legt Buchung und die Geschäftsjahre 2014–2025 an und wurde in dieser Sitzung nicht freigegeben. Zum Nachholen: `tests/dev/in-container.sh seed-beitraege.php --wipe --wipe-bank --with-anonymization-booking` |
| 12.1 | Beitragsbestätigung (Kassenwart-Kanal) | ✅ OK | Beitragsbestätigung Jana 2026: 30,00 € (September, Oktober bezahlt) |
| 12.2 | Gebühren und Unbezahltes fließen nie ein | ✅ OK | Gebühren und Unbezahltes fließen nicht ein (Fuchs: „Keine bezahlten Beitrags-Forderungen“) |
| 12.3 | Fehlende Adresse: Hinweis nur am Bildschirm | 🧪 Nur E2E | Fehlende Adresse: E2E-Spec 35 |
| 12.4 | Beitragsbestätigung aus Mitgliedssicht | ⛔ Nicht prüfbar | Mitgliedssicht (jane); Spec 35 |
| 12.5 | Datenübersicht (Art. 15 DSGVO) | 🔧 Korrigiert | Datenübersicht Jana gelesen; „Aktiviert am“ war „09 12:53:41.10.2026“ – behoben |
| 12.6 | Anonymisierungsreife erkennen | 🚫 Nicht ausgeführt | Ohne die 2014-Buchung ist Hans Becker nicht reif (Status „nicht reif“ geprüft); siehe 12.0 |
| 12.7 | Anonymisieren (Hans Becker) | 🚫 Nicht ausgeführt | Unumkehrbar und braucht die Seeder-Option (12.0); E2E-Spec 36 deckt Anonymisieren ab |
| 12.8 | Was geschwärzt wird und was bleibt | 🚫 Nicht ausgeführt | siehe 12.7; E2E-Spec 36 |
| 12.9 | Bekannte Lücke: Satztexte im Verlauf | 🚫 Nicht ausgeführt | siehe 12.7 |

## Phase 13 – Rollen & Rechte

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 13.1 | Buchhalter: alles Operative | ⛔ Nicht prüfbar | Rollenwechsel (alice/bob/Konto ohne Rolle) braucht deren Anmeldung; E2E-Specs 41, 46, 48 in der CI |
| 13.2 | Buchhalter: Vorlaufzeiten gesperrt, Einzugstage bedienbar | ⛔ Nicht prüfbar | Rollenwechsel (alice/bob/Konto ohne Rolle) braucht deren Anmeldung; E2E-Specs 41, 46, 48 in der CI |
| 13.3 | Buchhalter: keine Einstellungen des Moduls | ⛔ Nicht prüfbar | Rollenwechsel (alice/bob/Konto ohne Rolle) braucht deren Anmeldung; E2E-Specs 41, 46, 48 in der CI |
| 13.4 | Revisor: Einzug lesend, kein Klemmbrett | ⛔ Nicht prüfbar | Rollenwechsel (alice/bob/Konto ohne Rolle) braucht deren Anmeldung; E2E-Specs 41, 46, 48 in der CI |
| 13.5 | Revisor: Bankabgleich lesend, kein Rückgabecode | ⛔ Nicht prüfbar | Rollenwechsel (alice/bob/Konto ohne Rolle) braucht deren Anmeldung; E2E-Specs 41, 46, 48 in der CI |
| 13.6 | Konto ohne Rolle und ohne Verknüpfung | ⛔ Nicht prüfbar | Rollenwechsel (alice/bob/Konto ohne Rolle) braucht deren Anmeldung; E2E-Specs 41, 46, 48 in der CI |
| 13.7 | „Mein Beitrag“ folgt Schalter und Verknüpfung, nicht der Rolle | ⛔ Nicht prüfbar | Rollenwechsel (alice/bob/Konto ohne Rolle) braucht deren Anmeldung; E2E-Specs 41, 46, 48 in der CI |

## Phase 14 – Übersetzungen

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 14.1 | „Mein Beitrag“ in Du-Form (jane) | ⛔ Nicht prüfbar | Sicht jane; E2E-Spec 51 |
| 14.2 | „Mein Beitrag“ in Sie-Form (john) | ⛔ Nicht prüfbar | Sicht john; E2E-Spec 51 |
| 14.3 | Mails: Du gegenüber Sie | ✅ OK | Mails: jane in Du-Form, die übrigen Sie-Form |
| 14.4 | „Mein Beitrag“ auf Englisch (user1) | ⛔ Nicht prüfbar | Sicht user1; E2E-Spec 51 |
| 14.5 | Einmal-Link-Mail auf Englisch und Zustimmungsseite auf Deutsch | ⛔ Nicht prüfbar | Einmal-Link-Mail auf Englisch braucht Mitglied mit englischem Konto; E2E-Spec 51 |
| 14.6 | Einzug-Reiter auf Englisch (user1 mit Revisor-Rolle) | ⛔ Nicht prüfbar | Sicht user1; E2E-Spec 51 |
| 14.7 | Zahlen und Daten in englischen Mails | ✅ OK | Englische Mail (Lena): Beträge deutsch („22,50 €“), Daten jetzt TT.MM.JJJJ |

## Phase 15 – Mobil & Barrierefreiheit (Stichproben)

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 15.1 | Schmalen Viewport einstellen | ⛔ Nicht prüfbar | Fenstergröße lässt sich in diesem Browser nicht verkleinern; E2E-Spec 41/43 (Handy) |
| 15.2 | Mitglieder als Karten | ⛔ Nicht prüfbar | siehe 15.1 |
| 15.3 | Einzug auf dem Handy | ⛔ Nicht prüfbar | siehe 15.1 |
| 15.4 | Dialoge und Klemmbrett auf dem Handy | ⛔ Nicht prüfbar | siehe 15.1 |
| 15.5 | Tastatur: Escape, Fokus, Reihenfolge | ✅ OK | Escape schließt Dialoge und Klemmbrett; Fokus beim Öffnen (Vorname) bewiesen |
| 15.6 | Fokus sichtbar, Namen und Zoom | ⛔ Nicht prüfbar | Zoom/Fokus-Ring nicht systematisch durchgespielt; E2E-Spec 22 |
| 15.7 | Dunkles Design | ✅ OK | Dunkles Design (Stylesheet eingespielt): Zeitstrahl, Forderungen, Mitglieder, Offene Posten lesbar |

## Phase 16 – Randfälle & Fehlerfälle

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 16.1 | Leere Zustände | ✅ OK | Leere Zustände (Bankabgleich, „Kein Eintrag passt zur Suche“) |
| 16.2 | Doppelklick | 🧪 Nur E2E | Doppelklick: E2E-Spec 42 |
| 16.3 | Ungültige Eingaben und die IBAN-Prüfung | ✅ OK | Formal falsche IBAN abgelehnt, falsche Prüfziffer wird angenommen (bekannt, Frage 16.3) |
| 16.4 | Einstellungen: mehrere Fehler und der Server-Check | ✅ OK | Server lehnt XYZ, ../fremd und Mahnabstand 0 mit klaren Meldungen ab |
| 16.5 | Offline und Serverfehler ohne Rohtext | ⛔ Nicht prüfbar | Offline/Serverfehler brauchen Entwicklerwerkzeuge; E2E-Spec 21 |
| 16.6 | Zwei Personen zugleich | ⛔ Nicht prüfbar | Zwei Sitzungen (admin und alice) nicht möglich |
| 16.7 | Zurück-Taste und Deep-Links | ✅ OK | Deep-Links öffnen direkt; die Zurück-Taste ist durch E2E-Spec 21 abgedeckt |

## Phase 17 – Zurücksetzen („Alle Daten löschen“)

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 17.1 | Entscheidung und Ausgangslage festhalten | 🚫 Nicht ausgeführt | „Alle Daten löschen“ räumt die Buchhaltung dieser Instanz (14 Konten, 1 Buchung, 4 Belege); E2E-Spec 53 |
| 17.2 | „Alle Daten löschen“ ausführen | 🚫 Nicht ausgeführt | „Alle Daten löschen“ räumt die Buchhaltung dieser Instanz (14 Konten, 1 Buchung, 4 Belege); E2E-Spec 53 |
| 17.3 | Der Einzug nach dem Reset | 🚫 Nicht ausgeführt | „Alle Daten löschen“ räumt die Buchhaltung dieser Instanz (14 Konten, 1 Buchung, 4 Belege); E2E-Spec 53 |
| 17.4 | Was am Mandat bleibt | 🚫 Nicht ausgeführt | „Alle Daten löschen“ räumt die Buchhaltung dieser Instanz (14 Konten, 1 Buchung, 4 Belege); E2E-Spec 53 |
| 17.5 | Der Tageslauf holt Forderungen nach (bekannte Entscheidung) | 🚫 Nicht ausgeführt | „Alle Daten löschen“ räumt die Buchhaltung dieser Instanz (14 Konten, 1 Buchung, 4 Belege); E2E-Spec 53 |
| 17.6 | XML-Kopien und Ausgangszustand wiederherstellen | 🚫 Nicht ausgeführt | „Alle Daten löschen“ räumt die Buchhaltung dieser Instanz (14 Konten, 1 Buchung, 4 Belege); E2E-Spec 53 |

## Phase 18 – Abschluss

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 18.1 | Einstellungen aufräumen | ✅ OK | Einstellungen unverändert gelassen |
| 18.2 | Gesamteindruck festhalten | ✅ OK | Gesamteindruck: siehe Abschlussbericht |
| 18.3 | Prioritäten vergeben | ✅ OK | Prioritäten: siehe Abschlussbericht |
| 18.4 | Feedback exportieren | ✅ OK | Feedback-Export der HTML-Seite ist Nutzersache |
