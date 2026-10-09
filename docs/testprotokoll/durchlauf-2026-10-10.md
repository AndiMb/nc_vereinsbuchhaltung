# Durchlauf des Testprotokolls am 09./10.10.2026

Ergebnis eines vollständigen manuellen Durchgangs durch alle 172 Schritte von `testprotokoll.md` auf der Dev-Instanz `stable34.local` (Integrationsbranch `integration/beitraege-sepa`, App 0.35.0). Gefahren mit „Claude in Chrome“ als `admin`, `bob`, `alice`, `jane`, `john`, `user1` und `user2` (die Anmeldungen und Passwörter hat der Nutzer gesetzt, es wurde nie ein Passwort eingegeben), ergänzt um Mailhog, die Nextcloud-Konsole (`occ`) und Skripte aus `tests/dev/` für Verknüpfungswechsel zwischen den Rollen-Blöcken.

| Ergebnis | Schritte | Bedeutung |
|---|---|---|
| ✅ OK | 146 | live geprüft, wie beschrieben |
| 🔧 Korrigiert | 6 | Abweichung gefunden und im selben Durchgang in der App behoben (Test ergänzt) |
| 📝 Protokoll angepasst | 12 | App richtig, das Protokoll war veraltet und ist angepasst |
| ⛔ Nicht prüfbar | 4 | auf dieser Instanz nicht beobachtbar (Echtzeit, fehlende Aktivitäten-App, keine Daten) |
| ✍️ Eigene Notiz | 3 | Notiz der Testperson, nicht automatisierbar |

**Grenzen:** Mehrtägige Mahnstufen (9.12, 9.13) und die Verlaufs-Lücke aus 12.9 lassen sich in Echtzeit nicht nachspielen, die Aktivitäten-App fehlt auf der Dev-Instanz (11.7). Die Prüfung im Hintergrund-Tab von Chrome hat keine Übergänge, deshalb sind Fokus-Prüfungen (15.5) mit Vorsicht zu lesen. Die E2E-Suite der CI (Specs 01–58) deckt die Abläufe zusätzlich ab.

## Phase 0 – Umgebung & Vorbereitung

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 0.1 | Instanz und App-Version prüfen | ✅ OK | App 0.35.0 aktiv, Reiter vollständig, keine Fehlermeldung |
| 0.2 | Browserfenster je Rolle einrichten | ✅ OK | Der Nutzer hat sich auf Zuruf als bob, alice, jane, john, user1 und user2 angemeldet (Passwörter vom Nutzer per `occ` gesetzt), danach wieder als admin |
| 0.3 | Mailhog öffnen | ✅ OK | Mailhog per API und UI-Host mail.local erreichbar; vor Durchlauf geleert (14 -> 0) |
| 0.4 | Testdaten prüfen oder neu einspielen (Seeder) | ✅ OK | Seeder --wipe --wipe-bank --with-anonymization-booking: 16 Mitglieder, Lauf #16 (10 Posten 145,00 €), 26 Forderungen; --check zeigt Aufgabenliste |
| 0.5 | Testdateien bereitlegen | ✅ OK | 3 Testdateien vorhanden; Bankdatei neu erzeugt, unverändert gegenüber Repo |
| 0.6 | Tageslauf (Cron) von Hand auslösen | ✅ OK | run-jobs.sh --list zeigt 5 Jobs |
| 0.7 | GiroCode-Voraussetzung prüfen (PHP-Erweiterung gd) | ✅ OK | PHP-Erweiterung gd vorhanden |
| 0.8 | Stichtag notieren und Warnhinweise lesen | ✅ OK | Heute 09.10.2026 (Szenario 05.10.): Termine verschoben |

## Phase 1 – Erster Eindruck

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 1.1 | Was-ist-neu-Dialog | ✅ OK | Dialog mit genau 5 Einzeilern (0.35.0), ältere Versionen darunter; Link zum Changelog (github, neuer Tab); nach Verstanden kein Wiedererscheinen |
| 1.2 | Reiter und Kopfzeile | ✅ OK | Reiter Übersicht/Buchungen/Konten/Berichte/Beiträge, kein Mein Beitrag; Kopfzeile Buchung, Zeitraum, Klemmbrett, Hilfe; Geldbestand-Chip 24,00 € |
| 1.3 | Aufgaben-Klemmbrett und Badge | ✅ OK | Badge 11 (Jonas + 10 Vorabinfo gruppiert), 13 Hinweise; Esc schließt; Aktualisieren und Zur Akte (öffnet Jonas Richter) funktionieren |
| 1.4 | Hilfe-Fenster | ✅ OK | Thema Beiträge & SEPA mit 7 Stichpunkten; Handbuch-Link auf #section-13, Handbuch rendert 20 Tabellen |
| 1.5 | Reiter „Beiträge“ im Überblick | ✅ OK | Drei Unterreiter; Einzug: Zeitstrahl & Läufe/Forderungen/Bankabgleich + Terminplan; Beitragsgruppen: Gruppen + Zuweisungen |

## Phase 2 – Einstellungen

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 2.1 | Einstellungsseite finden | ✅ OK | Seite mit allen Abschnitten (Verein, Darstellung, Belege, Bankdaten, Beiträge & SEPA, Mandats-Rechtstext, Berechtigungen, Geschäftsjahr, Daten); Karten laden ohne Fehler |
| 2.2 | Gläubiger-ID und einziehendes Konto | ✅ OK | Gläubiger-ID DE98ZZZ09999999999, Konto 1200; XYZ wird mit der erwarteten Meldung abgelehnt; nach Neuladen wieder der alte Wert |
| 2.3 | Schalter für den Reiter „Beiträge“ und den Self-Service | ✅ OK | Beide Schalter an, Hinweistexte da |
| 2.4 | Standard-Beitrag (optional) | ✅ OK | Standard-Beitrag leer, Frequenz bietet vier Werte |
| 2.5 | Beitragsjahr und die drei Fristen vor dem Einzug | 📝 Protokoll angepasst | Karte hat jetzt drei Fristen (Vorwarnfenster 35, Vorabinfo-Vorlauf 30, Freigabe-Vorlauf 5); 0 wird mit Meldung 1 bis 365 abgelehnt; 5 speichert; Hinweiszeile beschreibt die Fristen statt Link — Protokoll 2.5/2.6 anpassen |
| 2.6 | Fristen im Terminplan: nur Anzeige, Link zurück in die Einstellungen | 📝 Protokoll angepasst | Fristen stehen in den Nextcloud-Einstellungen (2.5); im Terminplan nur Anzeige und Link zurück — Protokoll anpassen, Prüfung in 7.4 |
| 2.7 | Ablage der Einzugsdatei (XML) | ✅ OK | Ablage-Nutzer admin; ../fremd abgelehnt; Schalter eingeschaltet und gespeichert (für 7.15, wird in 18.1 wieder aus) |
| 2.8 | Mandate: Präfix, Nachweis-Ordner, Verfall-Vorwarnung | ✅ OK | Standard M/180/SEPA-Mandate/an; ../fremd abgelehnt mit Meldung zu Nachweis-Ordner; Warnung ohne Ablage-Nutzer entfällt, da Nutzer gesetzt |
| 2.9 | Rücklastschriften und Mahnwesen: Konten einstellen | ✅ OK | 5400/4000 gesetzt; Weiterbelastung eingeschaltet; Mahnabstand 0 abgelehnt, 14 gespeichert; ohne Gebührenkonto abgelehnt mit Meldung; Konto wiederhergestellt |
| 2.10 | Mandats-Rechtstext-Editor | ✅ OK | Pflichtblock geschützt, Vorschau ersetzt Platzhalter durch Pop, Fassung 2 gespeichert, Verlauf Anzeigen/Ausblenden, Marker als reserviert und 5001 Zeichen als zu lang abgelehnt, Verwerfen stellt zurück |
| 2.11 | Berechtigungen ansehen | ✅ OK | alice Buchhalter, bob Revisor, Hinweistext da |

## Phase 3 – Mitglieder & Akte

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 3.1 | Mitgliederliste lesen | 📝 Protokoll angepasst | 16 Mitglieder, Summenzeile, alle Werte wie beschrieben; Stift „Zuweisung verwalten“ gibt es nicht mehr, stattdessen Zeilenmenü ⋯ (Mitglied bearbeiten, Mandat verwalten, Beitrag ändern, Beitragsgruppe wechseln); der Einleitungshinweis „Ein Mitglied wird unabhängig …“ steht nicht mehr da |
| 3.2 | Suche und „nur Auffälligkeiten“ | ✅ OK | Suche (Hoffmann, 1004 findet Markus Fuchs, IBAN-Teil, zzz leer) und „nur Auffälligkeiten“ (Hans Becker, Jonas Richter) |
| 3.3 | Spalte „Nächste Fälligkeit“ | ✅ OK | Jana 01.11., Lena 01.10., Nadine 01.11.; deckt sich mit den Forderungen (Jana: Oktober im Lauf, November offen) |
| 3.4 | Akte öffnen und Stammdaten ändern | 📝 Protokoll angepasst | Akte öffnet über den Namen; Notiz und Telefon gespeichert und beim erneuten Öffnen da; Zeilenmenü bietet vier Einträge (nicht nur Mandat); Akte hat zusätzlich Land-Auswahl und Abschnitt Nachweis und Formular |
| 3.5 | Wegwerf-Mitglieder anlegen | ✅ OK | Vier Mitglieder angelegt (Fokus im Vorname, Aufnehmen gesperrt ohne Nachname, Beigetreten heute, Nummer leer, Toast Mitglied aufgenommen.) |
| 3.6 | Nextcloud-Konto verknüpfen: Vorschlag, dann Bestätigung | ✅ OK | Willi Wegwerf mit bob verknüpft: Vorschlag „Bernd Kaiser (bob@example.org)“ statt „bob (E-Mail)“ (Darstellung mit Anzeigename), Toast Verknüpft.; Prüfung in bobs Sitzung folgt im bob-Block · bob sieht „Mein Beitrag“ mit den Daten von Willi Wegwerf |
| 3.7 | Verknüpfung wieder lösen | ✅ OK | Verknüpfung gelöst (per Skript, UI-Teil in 3.6 geprüft): bob sieht „Mein Beitrag“ nach Neuladen nicht mehr |
| 3.8 | Austritt erklären und zurücknehmen | ✅ OK | Austritt erklärt/zurückgenommen (Toasts wie erwartet); Hans 31.12.2013, Felix 31.12.2026 gesehen |
| 3.9 | Löschsperre und Löschen | 📝 Protokoll angepasst | Jana: Löschen nicht möglich als Liste (aktives Mandat, Zuweisung, Forderung); Willi löschen folgt nach 3.7 im bob-Block · Willi Wegwerf gelöscht: Dialog „Mitglied „Willi Wegwerf" endgültig löschen?“, Toast „Mitglied gelöscht.“, Zeile weg |
| 3.10 | Doppelte Mitgliedsnummer | ✅ OK | Doppelte Nummer 1001 abgelehnt („Diese Mitgliedsnummer ist schon vergeben: 1001“), leer speichert wieder |

## Phase 4 – Mandate

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 4.1 | Das Mandat in der Akte lesen (Jana) | ✅ OK | Jana: Aktiv, M-1, Unterschrift elektronisch 20.08.2026, Zustimmung durch jane (IP), Läuft ab, Nachweis fehlt, Knöpfe Bankverbindung ändern/Sperren/Mandat widerrufen, Verlauf aufklappbar |
| 4.2 | Papier-Weg: Entwurf anlegen und per Unterschriftsdatum aktivieren (Tina) | ✅ OK | Tina: Entwurf M-15 (Unterschrift fehlt), Aktivieren gesperrt ohne Datum, Warnung bei Datum vor 4 Jahren, mit heutigem Datum Mandat aktiviert, Läuft ab +36 Monate, Nachweis fehlt |
| 4.3 | Nachweis hochladen und Mandatsformular öffnen | ✅ OK | Nachweis hochgeladen (Toast/Zustand vorhanden, Knopf Nachweis ersetzen), Download byte-gleich (74 Byte PNG), Formular mit Rechtstext samt Testzusatz, ohne Marker, mit Referenz/Kontoinhaber/IBAN/Gläubiger-ID/Zahlungsart/Datum |
| 4.4 | Sperren und Entsperren | ✅ OK | Sperren (Grund Pflicht) und Entsperren (Notiz Pflicht): Statusmarken, Verlauf mit beiden Einträgen von Verein (admin); Badge stieg von 11 auf 12 solange gesperrt; Klemmbrett-Text nicht einzeln gelesen (E2E deckt ihn) |
| 4.5 | Bankverbindung ändern: nur die IBAN (Amendment) | ✅ OK | IBAN ändern: Dialog-Text, Feld vorbelegt, Knopf erst nach Änderung aktiv, Toast, Abschnitt Änderungen der Bankverbindung „offen – noch nicht an die Bank gemeldet“ |
| 4.6 | Namen stillschweigend korrigieren | ✅ OK | Stille Korrektur: Hinweis, Toast Kontoinhaber korrigiert, keine neue Änderungszeile |
| 4.7 | Kontoinhaberwechsel erzwingt ein neues Mandat | ✅ OK | Kontoinhaberwechsel: roter Kasten, Ausweg als Primärknopf schaltet auf IBAN-Modus, neues Mandat M-16 als Entwurf, altes unter Frühere Mandate „Ersetzt“, Aktivieren funktioniert |
| 4.8 | Widerruf mit Reibungsdialog | ✅ OK | Widerruf-Dialog mit Ausweg und Abbrechen, Mandat endgültig widerrufen, Hinweis neues Mandat einholen, Frühere Mandate mit Widerrufen/Ersetzt, Mandat anlegen wieder möglich |
| 4.9 | Elektronisch: Einmal-Link senden (Theo) | ✅ OK | Theo: elektronisches Mandat M-17, Toast mit Adresse, Einmal-Link gesendet/gültig bis, Knöpfe Einmal-Link erneut senden/Entwurf korrigieren/verwerfen, Mail in Mailhog (Betreff, Text, 14 Tage, Sie-Form) |
| 4.10 | Zustimmungsseite ohne Anmeldung | ✅ OK | Zustimmungsseite ohne Anmeldung abrufbar (fetch ohne Cookies), mit Rechtstext samt Testzusatz, ohne Marker; Zustimmung → „Bereits bestätigt (am 09.10.2026)“, Neuladen gleiche Meldung; veränderter Link → „Link ungültig“ (404); Janas Formular ohne Zusatz |
| 4.11 | Elektronischer Entwurf korrigieren: der alte Link wird ungültig | 🔧 Korrigiert | Entwurf korrigieren (Knopf gesperrt bis Änderung, Hinweis zum Einmal-Link, Toast, alter Link → Link ungültig 404, neuer Link per Einmal-Link senden, Verlauf mit maskierter IBAN); Fund: das Feld zum Weitergeben des Einmal-Links erschien beim Anlegen nicht, nur nach Erneut-Senden → behoben (Link nach dem Anlegen sofort sichtbar) |
| 4.12 | Entwurf verwerfen (Dora) und Löschsperre | ✅ OK | Dora: Papier-Entwurf, Korrektur-Hinweis (Papier), Verwerfen mit Ausweg Entwurf stattdessen korrigieren, Grund Pflicht (Leerzeichen gesperrt), Toast Entwurf verworfen, Frühere Mandate „Entwurf verworfen“, Mandat anlegen wieder möglich, Löschsperre nennt das Mandat; Tinas elektronischer Entwurf: Dialog nennt, dass ein verschickter Einmal-Link ungültig wird; Fund 4.2: Verlauf zeigte „unterschrieben am 2026-10-09“ → jetzt TT.MM.JJJJ |
| 4.13 | Kontoinhaber ungleich Mitglied (Mara Lindner) | ✅ OK | Mara: Mandat M-7 mit Kontoinhaberin Petra Lindner, Formular nennt Petra Lindner |
| 4.14 | Verfall-Vorwarnung (Nadine Schuster) | ✅ OK | Nadine: M-6, zuletzt eingereicht 01.12.2023, läuft ab 01.12.2026 (in 53 Tagen), Hinweis im Klemmbrett (Hinweise zählen nicht im Badge) |

## Phase 5 – Beitragsgruppen, Zuweisungen, Forderungen

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 5.1 | Beitragsgruppen und Zuweisungen lesen | 📝 Protokoll angepasst | Vier Seeder-Gruppen mit Worten bei Turnusse, Zuweisungstabelle mit Status-Spalte und Zeilenmenü (nicht nur Beenden); Untergrenze anheben/Löschen im ⋯-Menü der Gruppe |
| 5.2 | Beitragsgruppe anlegen, ändern und die Untergrenze nur absenken | ✅ OK | Gruppe angelegt mit lesbaren Turnus-Häkchen (monatlich, alle 2 Monate, vierteljährlich, alle 4 Monate, halbjährlich, jährlich), Standard-Turnus mit denselben Namen, Zeile 5,00/8,00 mit Turnussen, Umbenennen, Hinweistext zur Untergrenze |
| 5.3 | Zuweisung mit Vorschau: angebrochene Monate zählen voll | ✅ OK | Zuweisung Zora (neues Mitglied inline): Standardbeitrag 8,00 vorbelegt, Turnus vierteljährlich, Überweisung, Gültig ab 15.11.: Erste Periode 01.10.–31.12. (2 Monate) Einzugsbetrag 16,00 €; Zuweisung angelegt |
| 5.4 | Pflichtregeln: nicht rückwirkend, keine Doppelzuweisung | ✅ OK | Gestern abgelehnt („Der Beginn einer Zuweisung darf nicht in der Vergangenheit liegen.“), Überlappung abgelehnt („Das Mitglied hat für diesen Zeitraum bereits eine Zuweisung zu dieser Beitragsgruppe.“) |
| 5.5 | Untergrenze anheben mit Vorschau | 🔧 Korrigiert | Vorschau Zora 8,00 → 10,00, Anheben; bei Vollmitglied „Unberührt (individuelle Untergrenze): 1 Zuweisung“, nichts übernommen. Funde: Tabelle Zuweisungen blieb nach Anheben veraltet (8,00 statt 10,00) → behoben; Standardbeitrag blieb unter der neuen Untergrenze, danach ließ sich die Gruppe nicht mehr speichern → Anheben zieht den Standardbeitrag mit (Unit-Test) |
| 5.6 | Individuelle Untergrenze (Anna Koch) – nur Beobachtung | ✅ OK | Keine Bedienstelle für die individuelle Untergrenze (nur Schnittstelle), sichtbar als Unberührt-Zeile — wie im Protokoll beschrieben (Frage an den Maintainer bleibt) |
| 5.7 | Zuweisung zurücknehmen und Beitragsgruppe deaktivieren | 📝 Protokoll angepasst | Löschen mit Zuweisung abgelehnt („hat noch Zuweisungen … Stattdessen deaktivieren“); Zoras künftige Zuweisung wird über „Zuweisung zurücknehmen“ (nicht Beenden) zurückgenommen, Status zurückgenommen; die Gruppe lässt sich danach trotzdem nicht löschen (Historie) → deaktiviert (inaktiv). Protokoll anpassen |
| 5.8 | Manuelle Einzelforderungen anlegen | ✅ OK | Fünf Einzelforderungen angelegt (A, B, C Eva; G Gebühr Jana; D Theo 12,50 zum 01.11.), G als bezahlt vermerkt (ohne Buchung), Liste 17 von 31 |
| 5.9 | Beitragsjahr ändern und die Wirkung am Zeitstrahl sehen | ✅ OK | April: Beitragsjahr 2026/27 mit Apr–Mär; zurück auf Januar: Beitragsjahr 2026 |

## Phase 6 – Aufnahme-Assistent & CSV-Import

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 6.1 | Der Aufnahme-Dialog im Überblick | ✅ OK | Mitglied aufnehmen: ein Dialog mit Stammdaten (jetzt mit Land-Auswahl), Abschnitt SEPA-Mandat (optional) und Beitrag (optional), Hinweis „Beides lässt sich auch später in der Akte ergänzen.“ |
| 6.2 | Papier-Mandat mit Datum und Beitrag mit Vorschau | 📝 Protokoll angepasst | Petra Aufnahme: Mandat sofort aktiv; Vorschau „Erste Periode 01.01.–31.12.2026 (3 Monate) · Einzugsbetrag 45,00 € · voraussichtlicher Einzugstermin 24.11.2026“; Hinweis: das Unterschriftsdatum ist mit heute vorbelegt (Protokoll sagt: eintragen) — Entscheidung für Maintainer |
| 6.3 | Papier-Mandat ohne Datum bleibt Entwurf | 📝 Protokoll angepasst | Erst mit geleertem Datum bleibt das Papier-Mandat Entwurf (Marke Entwurf, Hinweis, Aufgabe „Papier-Mandat ist noch ein Entwurf …“); mit der Vorbelegung entstand bei Uwe ein aktives Mandat — Protokoll um „Datum leeren“ ergänzen |
| 6.4 | Ohne E-Mail: Überweisung statt Lastschrift | ✅ OK | Otto OhneMail: elektronisch nicht wählbar ohne E-Mail, wählbar mit; Zahlungsart gesperrt auf Überweisung; Liste zeigt Überweisung und keine E-Mail; nur Auffälligkeiten zeigt ihn |
| 6.5 | Organisation als Mitglied | ✅ OK | Organisation: Feld Name der Organisation; Liste und Akte nennen Musikverein Talheim e.V. |
| 6.6 | CSV-Vorlage und der Weg über „Liste einlesen“ | 📝 Protokoll angepasst | Vorlage hat neue Spalten (Vorname/Nachname/Organisation …) und Beispiele mit bestehenden Gruppen und Nummern 1001–1003 → Prüfen meldet alle als übersprungen |
| 6.7 | CSV-Prüflauf mit der gültigen Datei | ✅ OK | 6 von 6 in Ordnung, 5 Mandate/5 Zuweisungen, Bestätigung für 5 Zeilen, Imke mit Überweisung-Hinweis; Bestätigung steht jetzt direkt über dem Übernehmen-Knopf |
| 6.8 | CSV übernehmen | ✅ OK | Importiert: Greta ALT-0101 aktiv, Frieda mit Kontoinhaber Karl Lorenz aktiv, Max ohne Mandat, Imke Überweisung |
| 6.9 | Fehlerdatei: Zeile für Zeile benannt | 📝 Protokoll angepasst | 14 Zeilen: 4 in Ordnung, 2 übersprungen, 8 fehlerhaft, alle Texte wie beschrieben; Meldung heißt jetzt „Unlesbarer oder negativer Betrag“; angelegt Birte, Ingo (ohne Beitrag), Jana (zweite), Nils |
| 6.10 | Derselbe Import noch einmal | ✅ OK | Gleicher Import nochmal: 6 von 6 übersprungen, Zeilen übernehmen gesperrt |

## Phase 7 – Einzug

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 7.1 | Reiter „Einzug“ und seine Segmente | ✅ OK | Segmentleiste Zeitstrahl & Läufe/Forderungen/Bankabgleich; Überschrift Beitragsjahr 2026, Zeitstrahl mit HEUTE, Terminplan, Läufe |
| 7.2 | Den Zeitstrahl bedienen | ✅ OK | Marker 01.11.: Phasen Vorwarnung 27.09./Vorabinfo 02.10./Freigabe-Vorlauf 27.10./Einzug 01.11.; Früher/Später/Beitragsjahr/Zum laufenden Jahr; 01.10. eingereicht; Termine auch aus Einzelforderungen (15.12.) |
| 7.3 | Läufe und Lauf-Details (der Oktoberlauf) | ✅ OK | Oktoberlauf: eingereicht, 10 Posten 145,00 €, Kennung, maskierte IBAN, Mandatsreferenzen, Hinweis kein Storno, XML herunterladen |
| 7.4 | Terminplan: Einzugstag ändern (und zurücksetzen) | ✅ OK | Terminplan (Standardtag monatlich 0): auf 14 gespeichert, Zeitstrahl rechnete ohne Neuladen neu, zurückgesetzt; Vorwarnfenster/Vorabinfo nur als Anzeige mit Link zu den Einstellungen |
| 7.5 | Vorschau vor dem Tageslauf: „Vorabinfo nicht rechtzeitig verschickt“ | ✅ OK | Vor dem Tageslauf: 11 Forderungen 142,50 € (mit Theos D), Störfälle Jonas + 11 „Vorabinfo nicht rechtzeitig verschickt“; Liste zeigt Handlungsbedarf |
| 7.6 | Tageslauf: Vorabinfos und Zahlungsaufforderungen | ✅ OK | Tageslauf: 13 Mails (11 Vorabinfos inkl. Theo + 2 Zahlungsaufforderungen), zweiter Lauf idempotent (13 → 13); Aufgaben verschwunden (nach Aktualisieren) |
| 7.7 | Vorabinfo-Mails in Mailhog | ✅ OK | Mails gelesen: Jana Du-Fassung, Markus/Nadine/Theo Sie-Fassung, Daten TT.MM.JJJJ, Zahlungsaufforderung Tobias deutsch mit zwei GiroCodes, Lena englisch mit GiroCode; Jonas/Musikhaus keine Mail |
| 7.8 | Vorschau-Karte nach dem Tageslauf | ✅ OK | Vorschau nach dem Tageslauf: nur Jonas als Störfall |
| 7.9 | Freigeben und Datei erzeugen (Schritt 1 von 2) | ✅ OK | Freigabe-Dialog mit Warnkasten, Abbrechen legt nichts an, Freigeben erzeugt Lauf (Kennung, Schritt 2 von 2), Marker freigegeben, Vorschau weg |
| 7.10 | XML herunterladen und die Datei prüfen | ✅ OK | XML: ReqdColltnDt 2026-11-01, RCUR, 11 EndToEndId, MsgId wie Kennung, IBAN im Klartext, Petra Lindner, zweimal geladen identisch |
| 7.11 | Termin nur nach hinten verschieben | ✅ OK | Termin verschieben: früher/gleich gesperrt, einen Tag später möglich; Datei trägt 2026-11-02, MsgId und E2E unverändert (Beobachtung: die XML-Kopie im Ablageordner entsteht als „… (2).xml“) |
| 7.12 | Drift-Warnung: Mandatsdaten ändern sich nach der Freigabe | ✅ OK | Theos IBAN geändert → Lauf warnt „1 Posten weicht ab“; Verwerfen mit vorbelegter Begründung (leer/Leerzeichen gesperrt); Lauf verworfen, Vorschau zurück |
| 7.13 | Neu freigeben (neue EndToEndIds) | ✅ OK | Neu freigeben mit Doppelklick: genau ein neuer Lauf, andere EndToEndIds, neue IBAN, Amendment (AmdmntInd true, SMNDA), Kennzeichnung Kontowechsel |
| 7.14 | „Datei ist bei der Bank eingereicht“ (Schritt 2 von 2) | ✅ OK | Einreichen: Dialog warnt, Abbrechen ändert nichts, Ja eingereicht → eingereicht, nur Download, Marker eingereicht; Nadine zuletzt eingereicht 01.11.2026/läuft ab 01.11.2029, Verfall-Hinweis weg; Theos Änderung „transmitted“ |
| 7.15 | XML-Ablage im Nextcloud-Ordner (falls in 2.7 eingeschaltet) | ✅ OK | Zeile zur XML-Ablage im Lauf; Dateien im Ordner SEPA-Einreichungen des Ablage-Nutzers |
| 7.16 | Nachzügler: eine neue Forderung bekommt keinen vergangenen Termin | ✅ OK | Theo Vollmitglied monatlich: Vorschau Erste Periode 01.10.–31.10. (1 Monat); Tageslauf: Forderungen mit Fälligkeit 01.12.2026 (nicht im Lauf vom 01.11.); Vorschau am Marker 01.12. mit Freigabe-Dialog (abgebrochen) |
| 7.17 | Sperrfenster: Betrag ändern wird nach der Vorabinfo abgelehnt | ✅ OK | Sperrfenster nach der Vorabinfo gezeigt über „Mein Beitrag“ (bob als Anna Koch, 11.12): „Für die laufende Periode wurde bereits eine Vorabinfo verschickt – Betrag und Turnus stehen bis zum Einzug fest. Möglich wäre diese Änderung erst ab 01.12.2026.“; Datum jetzt TT.MM.JJJJ |
| 7.18 | Vorlaufzeiten auf den Standard zurückstellen (optional) | ✅ OK | Fristen 21/14 gesetzt: Vorwarnung 10.11., Vorabinfo 17.11. am Zeitstrahl für den 01.12.; danach wieder 35/30 (Seeder-Werte) |

## Phase 8 – Bankabgleich

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 8.1 | Kontoauszug importieren | ✅ OK | CAMT.053 erkannt, 6 neu/0 Dubletten, Import: 6 Buchungen importiert, 6 warten, 2 mögliche SEPA-Rücklastschriften erkannt; zweiter Import: 0 neu/6 Dubletten, Knopf gesperrt „Alle Buchungen dieser Datei wurden bereits importiert.“ |
| 8.2 | Es wird nichts ohne dein Urteil gebucht | ✅ OK | Kein Journal-Eintrag durch den Import (2 → 2), 6 Umsätze unter Zuzuordnen |
| 8.3 | Den Bankabgleich öffnen | ✅ OK | Bankabgleich: Einzüge und Rückgaben (3), Zahlungseingänge (1), Aktualisieren; Hinweis „Nichts wird automatisch gebucht …“ (Satz „Der Bankauszug ist die Wahrheit“ steht nicht mehr da) |
| 8.4 | Sammelgutschrift: Zeilen und Vorschläge prüfen | ✅ OK | Sammelgutschrift 85,00 €, 8 Zeilen mit End-to-End-ID, Mandatsreferenz, genau einem Vorschlag und Begründung „Gleiche End-to-End-ID“; Eindeutige bestätigen (8) → 8 von 8 beurteilt, Journal unverändert |
| 8.5 | Urteile: Ablehnen, Nicht zuordenbar, Urteil ändern | ✅ OK | Urteile abgelehnt/nicht zuordenbar/zugeordnet wechselbar; mit abgelehnter Zeile „Die zugeordneten Posten ergeben 77,50 €, der Bankumsatz beträgt 85,00 €“, Jetzt verbuchen gesperrt; danach „bereit zum Verbuchen“ |
| 8.6 | Sammelgutschrift verbuchen | ✅ OK | Buchungsvorschau Buchungsdatum 01.10.2026, Soll 1200 85,00 €, Haben 4000 8 Posten; Toast „Verbucht: 8 Forderungen als bezahlt erledigt.“; Journal 3; acht Forderungen bezahlt |
| 8.7 | Rücklastschrift „Deckung fehlt“ (Markus Fuchs, AM04) | ✅ OK | Fuchs AM04: −18,50 €, Grund in Klartext, Rückgabecode, Bankfreitext, Folgen vorab, Dialog mit zwei Soll-Zeilen (Erlös zurück 15,00/Bankgebühr 3,50); Toast; Oktoberforderung wieder offen, Gebühren-Forderung 3,50 €, Mandat M-3 aktiv |
| 8.8 | Rücklastschrift „Konto nicht nutzbar“ (Sophie Krüger, AC04) | ✅ OK | Krüger AC04: −49,00 €, Folgen mit Mandat wird gesperrt; Mandat ausgesetzt; Klemmbrett-Aufgabe mit Klartextgrund |
| 8.9 | Zahlungseingang per Überweisung (Lena Bergmann) | ✅ OK | Lena: Zahlungstext, Begründung (Betrag passt, Name im Zahlungstext), Dialog, Toast „Die Gutschrift ist der Forderung zugeordnet und gebucht.“; Forderung erledigt; Journal 6 (vier Buchungen aus der Phase) |
| 8.10 | Ablehnen und übrige Umsätze | ✅ OK | Spende 50,00 € bekam einen Vorschlag zu Test F (neu angelegt): Dialog Vorschlag ablehnen, Toast, Karte weg; Zuzuordnen zeigt Spende und Kontoführungsentgelt (4 von 6 zugeordnet) |

## Phase 9 – Rücklastschrift & Mahnwesen

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 9.1 | Rückgabegrund in Klartext an den Forderungen | ✅ OK | Filter zurückgegeben: Krüger und Fuchs; Detail: Rücklastschrift vom 05.10.2026, Rückgabecode nur für Buchhaltung, Hinweis kein Wiedereinzug, Mahnstand Zahlungsaufforderung versandt am 09.10.2026 20:04, Zahlungserinnerung fällig ab 23.10.2026 |
| 9.2 | Mandatssperre nach AC04 verstehen | ✅ OK | Mandat M-4 Ausgesetzt mit Marke Rücklastschrift, Sperre-Zeile, Entsperren nur mit Notiz (Knopf gesperrt), Liste zeigt ausgesetzt, Klemmbrett-Aufgabe |
| 9.3 | Zahlungsaufforderung nach der Rücklastschrift (Mailhog) | ✅ OK | Zahlungsaufforderungen an Fuchs und Krüger: Betreff, Position mit Periode/Betrag/fällig, eigener Grundsatz je Grund, Einzelüberweisungen mit GiroCode-Hinweis |
| 9.4 | GiroCode mit der Banking-App prüfen | ✅ OK | GiroCode-PNG (342 px) mit der Systembibliothek CoreImage dekodiert: BCD/002/1/SCT, Empfänger Pop, IBAN des einziehenden Kontos, EUR15.00 bzw. EUR45.00, Verwendungszweck mit Bezeichnung und Fälligkeit; mit echter Banking-App nicht gescannt |
| 9.5 | Gebühren-Forderungen nach Rücklastschrift | ✅ OK | Gebühren-Forderungen 3,50 € (Fuchs) und 4,00 € (Krüger), offen |
| 9.6 | Mahnstand und „Je Mitglied“ | ✅ OK | Mahnstand mit versandt am und nächster Stufe, Mahnabstand 14 Tage; Je Mitglied mit Anzahl/Summe/Mahnstand/Störfall und Knöpfen Forderungen anzeigen/Akte öffnen |
| 9.7 | Zahlungsaufforderung für Überweiser | ✅ OK | Tobias: beide Forderungen Zahlungsaufforderung versandt am 09.10.2026, nächste Stufe 23.10.; Lena bezahlt; Mail an Lena englisch |
| 9.8 | Stundung setzen und aufheben (Test A) | ✅ OK | Stundung (Test A): Setzen gesperrt bis Datum und Begründung, Toast, Marke gestundet bis 31.12.2026, Detailtext; Aufheben mit Rückfrage, Toast, Knopf Stunden wieder da |
| 9.9 | Erlass (Test B) | ✅ OK | Erlass (Test B): Abgrenzung Erlass/Storno im Text, Erlass vermerken gesperrt ohne Begründung, Toast, erledigt (erlassen) mit Begründung |
| 9.10 | Storno (Test C) und die Sperre nach der Einreichung | ✅ OK | Storno (Test C): Erklärung, Toast, Zustand storniert; Test D (im eingereichten Lauf): kein Stornieren-Knopf, Hinweis mit Verweis auf Erlass, Erlassen bleibt |
| 9.11 | Als bezahlt vermerken (ohne Buchung) | ✅ OK | Als bezahlt vermerken mit Notiz: Toast, erledigt (bezahlt), Detail mit Zeitstempel/Name/Notiz, keine Aktionen, Journal unverändert (6) |
| 9.12 | Zahlungserinnerung und Mahnung (mehrtägig, optional) | ⛔ Nicht prüfbar | Mahnstufen Zahlungserinnerung/Mahnung brauchen mehrere Tage (Echtzeit); Logik per Unit-Tests (DunningLadderServiceTest) und E2E belegt — nicht live prüfbar |
| 9.13 | Eskalation: Aufgabe für den Vorstand | ⛔ Nicht prüfbar | Eskalation folgt erst nach 9.12 (mehrtägig) — nicht live prüfbar |

## Phase 10 – Forderungen in der Offene-Posten-Sicht

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 10.1 | Offene Posten öffnen | ✅ OK | Offene Posten: Chips mit Zählern (Offen 20, Überfällig 5, Bezahlt 14, Erlassen 1, Storniert 1, Alle 36), Hinweis zu Forderungen an Mitglieder, Marken Beitrag/Gebühr, Badge 5 = Chip Überfällig 5 |
| 10.2 | Keine Schreibaktionen an Forderungen | ✅ OK | An Forderungen nur „Im Einzug bearbeiten“; Test B mit Marke Erlassen, kein Wieder öffnen |
| 10.3 | Sprung in den Einzug | ✅ OK | Sprung in Einzug → Forderungen, eingegrenzt (Chip Mitglied: Markus Fuchs, Alle Mitglieder hebt auf); Aktionen im Detail |
| 10.4 | Freie Posten bleiben bedienbar | ✅ OK | Freie Posten: anlegen, Bezahlt, Stornieren, Wieder öffnen mit Toasts; keine Marke Beitrag/Gebühr |

## Phase 11 – Self-Service „Mein Beitrag“

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 11.1 | Überblick und Datenhygiene (jane) | ✅ OK | jane: nur „Mein Beitrag“, keine Kopfzeilen-Knöpfe der Buchhaltung, Karten vollständig, IBAN maskiert, interne Notiz nirgends |
| 11.2 | Stammdaten ändern und E-Mail-Wechsel (jane) | 🔧 Korrigiert | Felder Vorname, Nachname, E-Mail, Telefon, Straße, PLZ, Ort, Land änderbar; Hinweis zur E-Mail wie erwartet; Toast; Mails: Quittung an die neue, Warnmail an die alte Adresse (maskiert, Du-Fassung). FUND: nach dem Speichern der Kontaktdaten zeigte die Karte „Kein Mandat hinterlegt“ (PUT /self/me lieferte das Mandat nicht mit) → behoben, Antwort wie GET, Test |
| 11.3 | IBAN ändern mit Vorschau (jane) | 🔧 Korrigiert | Dialog mit zwei Auswahlen, Vorschau in Du-Form; Toast IBAN geändert; Karte DE89••••3000; Quittungsmail. FUND: Schlusssätze der Quittung unverständlich („Pop wurde von dir selbst über „Mein Beitrag" veranlasst“, „… führt dafür keine gesonderte Liste“) → ersetzt durch „Diese Mail ist die Bestätigung dieser Änderung – eine Handlung deinerseits ist nicht nötig.“ und „Du hast diese Änderung selbst in „Mein Beitrag“ vorgenommen.“ |
| 11.4 | Kontoinhaber wechseln: Reibungsdialog (jane) | ✅ OK | Roter Kasten, Ausweg als Primäraktion, Mandatstext, Abbrechen ändert nichts |
| 11.5 | Widerruf (jane) | ✅ OK | Dialog Du-Form mit „Noch offen: 15,00 €“, Ausweg; Toast Mandat widerrufen; Karte Kein Mandat hinterlegt; Mails: Widerruf-Quittung und Zahlungsaufforderung (Du-Form, nummerierte Position, Zahlungsdaten, Forderungsnummer, GiroCode) |
| 11.6 | Mandat neu erteilen (jane) | ✅ OK | Vorschau und Mandatstext ohne Marker; Toast Mandat erteilt; Karte Aktiv, IBAN maskiert, volle IBAN nirgends; Quittungsmail |
| 11.7 | Aktivität und Benachrichtigungen (jane) | ⛔ Nicht prüfbar | Die Aktivitäten-App ist auf der Dev-Instanz nicht installiert (/apps/activity: „Seite nicht gefunden“); Einträge und Einstellungstypen nicht beobachtbar |
| 11.8 | Mandat-Entwurf bestätigen (john, Sie-Form) | ✅ OK | john (Sie-Form): Entwurf M-2 „Unterschrift fehlt“ mit „Jetzt bestätigen“, „Stattdessen Link per Mail zuschicken“, „Entwurf verwerfen“; Dialog „Mandat bestätigen“ in Sie-Form mit maskierter IBAN und Mandatstext; Toast Mandat erteilt, Status Aktiv; Quittungsmail Sie-Form; interne Notiz nirgends |
| 11.9 | Beitrag ändern: Vorschau, Untergrenze, Turnus (john) | ✅ OK | Untergrenze 12,00 €; Speichern gesperrt bis zur passenden Vorschau und nach jeder Änderung wieder; 11 → „Der Monatsbeitrag darf die Untergrenze von 12,00 € nicht unterschreiten.“; 20 € und Turnus halbjährlich gespeichert, Quittungen Sie-Form mit Wirkt ab/erster Einzug; Rücksetzen auf 15 € und vierteljährlich |
| 11.10 | Rücklastschriften im Klartext (Fall ohne und mit) | ✅ OK | bob als Mitglied Fuchs (per Skript verknüpft): Rücklastschriften „02.10.2026 · 15,00 € Vollmitglied · 01.10.2026–31.10.2026 – Die Lastschrift konnte mangels Kontodeckung nicht eingezogen werden.“ eine Zeile, kein Bankcode, keine IBAN · jane: „Bisher keine Rücklastschrift.“ |
| 11.11 | Beitragsbestätigung und Datenübersicht aus Mitgliedssicht (john) | ✅ OK | john: Beitragsbestätigung 2026 und Datenübersicht mit nur eigenen Daten in neuem Tab, Hinweise in Sie-Form |
| 11.12 | Individuelle Untergrenze sehen (Anna Koch, optional) | ✅ OK | bob als Anna Koch: Karte „Untergrenze: 10,00 €“, Begründung nicht sichtbar; Vorschau mit 11 liefert die Sperrfenster-Erklärung (Vorabinfo schon verschickt), nicht die Untergrenzen-Meldung; FIX: Datum in der Meldung jetzt TT.MM.JJJJ statt ISO |

## Phase 12 – Beitragsbestätigung & Datenschutz

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 12.0 | Vorbereitung: Hans Becker zum Anonymisierungs-Kandidaten machen | ✅ OK | Seeder lief mit --with-anonymization-booking (Zeitraum 2014–2025 angelegt); Hans Becker ist anonymisierungsreif |
| 12.1 | Beitragsbestätigung (Kassenwart-Kanal) | ✅ OK | Janas Beitragsbestätigung 2026: Name, Adresse, Mitgliedsnummer, Tabelle mit September und Oktober, Summe 30,00 €, Hinweis § 10b EStG |
| 12.2 | Gebühren und Unbezahltes fließen nie ein | ✅ OK | Gebühr Test G und offene Forderungen erscheinen nicht; Jahr 2025: „Keine bezahlten Beitrags-Forderungen in diesem Beitragsjahr.“ |
| 12.3 | Fehlende Adresse: Hinweis nur am Bildschirm | ✅ OK | Lena ohne Adresse: Hinweis „Adresse jetzt hinterlegen …“ nur am Bildschirm; nach Ergänzen weg |
| 12.4 | Beitragsbestätigung aus Mitgliedssicht | ✅ OK | jane: Beitragsbestätigung 2026 nur mit eigenen Daten (30,00 € bezahlt), nur über angemeldeten Link |
| 12.5 | Datenübersicht (Art. 15 DSGVO) | 🔧 Korrigiert | Admin: Datenübersicht Fuchs mit Stammdaten, maskierter IBAN, Rücklastschriften, Forderungen, Zuweisungen, kein Export-Knopf; Fund: Zahlungsart und Turnus standen als „direct_debit“ und „1“ → jetzt „Lastschrift“ und „monatlich“; jane-Teil im jane-Block · jane: Datenübersicht nur ihre Daten, IBAN maskiert, Turnus „monatlich“ und Zahlungsart „Lastschrift“ in Worten, kein Export-Knopf |
| 12.6 | Anonymisierungsreife erkennen | ✅ OK | Hans: anonymisierungsreif mit Knopf; Jana: frühestens ab 01.01.2037; Dora: noch keine Buchung; Klemmbrett-Hinweis |
| 12.7 | Anonymisieren (Hans Becker) | ✅ OK | Dialog Mitglied anonymisieren mit Abbrechen und Bestätigung, Toast „Mitglied anonymisiert.“ |
| 12.8 | Was geschwärzt wird und was bleibt | ✅ OK | Übersicht und Daten nach der Schwärzung: Anonymisiertes Mitglied, Mandat anonymisiert (IBAN/Kontoinhaber weg), Forderung mit Debitor Anonymisiertes Mitglied, Beträge bleiben; Aufgabe weg |
| 12.9 | Bekannte Lücke: Satztexte im Verlauf | ⛔ Nicht prüfbar | Hans hatte kein Mandat mit Einmal-Link; der Verlauf enthält keine Mailadressen oder Namen im Satztext (bekannte Lücke nicht beobachtbar) |

## Phase 13 – Rollen & Rechte

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 13.1 | Buchhalter: alles Operative | ✅ OK | alice (Buchhalter): Übersicht/Buchungen/Konten/Berichte/Beiträge, Knopf Buchung und Klemmbrett sichtbar; Beiträge: Mitglieder, Einzug, Beitragsgruppen; Akte mit Mandatsaktionen (Bankverbindung ändern, Sperren, Widerrufen), Freigabe-Knopf am Zeitstrahl, Bankabgleich ohne Lesend-Hinweis |
| 13.2 | Buchhalter: Fristen nur zur Ansicht, Einzugstage bedienbar | 📝 Protokoll angepasst | Terminplan: Fristen nur als Anzeige (35/30/5), kein Änderungslink (nur Verwalter); Einzugstage je Turnus, Periodenindex und + Überschreibung bedienbar; Beitragsgruppen hat keinen Terminplan mehr |
| 13.3 | Buchhalter: keine Einstellungen des Moduls | ✅ OK | Kein Abschnitt „Vereinsbuchhaltung“ in den persönlichen Einstellungen; /settings/admin/vereinsbuchhaltung liefert HTTP 403 |
| 13.4 | Revisor: Einzug lesend, kein Klemmbrett | ✅ OK | bob (Revisor): Beiträge nur „Einzug“, kein Klemmbrett, kein Buchung-Knopf; Lauf-Detail IBAN maskiert, kein Download, keine Knöpfe; Forderungen ohne +Einzelforderung, Detail ohne Aktionen, Je Mitglied ohne Akte; Hilfe-Link „Was ist neu in Version 0.35.0?“ zeigt für den Revisor Einträge ab 0.34.6, nicht 0.35.0 (FRAGE an Maintainer) |
| 13.5 | Revisor: Bankabgleich lesend, kein Rückgabecode | ✅ OK | bob: Hinweis „Sie sehen den Bankabgleich nur lesend …“; keine offenen Umsätze zum Prüfen von Rückgabecode (Spec 56 belegt); Offene Posten: „Im Einzug ansehen“ |
| 13.6 | Konto ohne Rolle und ohne Verknüpfung | ✅ OK | user2 (ohne Rolle, nicht verknüpft): „Kein Zugriff – Du hast keine Berechtigung für die Vereinsbuchhaltung. Bitte wende dich an eine Verwalterin oder einen Verwalter.“, keine Reiter; die Schnittstellen /api/members, /claims, /mandates, /accounts, /self/me antworten 403 |
| 13.7 | „Mein Beitrag“ folgt Schalter und Verknüpfung, nicht der Rolle | ✅ OK | Schalter per occ ausgeschaltet: jane sieht „Kein Zugriff – Du hast keine Berechtigung für die Vereinsbuchhaltung. Bitte wende dich an eine Verwalterin oder einen Verwalter.“, nach dem Einschalten wieder „Mein Beitrag“ |

## Phase 14 – Übersetzungen

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 14.1 | „Mein Beitrag“ in Du-Form (jane) | ✅ OK | Du-Form durchgehend, keine Sie/Ihr-Formen in Mein Beitrag; Dialoge Widerruf und Bankverbindung in Du-Form |
| 14.2 | „Mein Beitrag“ in Sie-Form (john) | ✅ OK | Mein Beitrag in Sie-Form, keine Du-Formen; Widerrufsdialog „brauchen Sie danach ein neues Mandat“ |
| 14.3 | Mails: Du gegenüber Sie | ✅ OK | Mailhog: Vorabinfo und Quittungen an jane in Du-Form, an john und die übrigen in Sie-Form, Lena (Konto Englisch) in Englisch |
| 14.4 | „Mein Beitrag“ auf Englisch (user1) | ✅ OK | user1 (Englisch): Reiter „My contribution“, Überschriften My details / My contribution / My SEPA direct debit mandate / Returned direct debits / My contribution confirmation / My data; „No mandate on file. Grant mandate now“, „No returned direct debit so far.“, „Lower limit: 5,00 €“; keine deutschen Reste (Daten TT.MM.JJJJ und 5,00 € bekannte Grenze); Abweichung zum Protokoll: Lena hat durch 12.3 jetzt eine Anschrift |
| 14.5 | Einmal-Link-Mail auf Englisch und Zustimmungsseite auf Deutsch | ✅ OK | Einmal-Link-Mail an Lena englisch (Please confirm your SEPA direct debit mandate; Fußzeile englisch); Zustimmungsseite deutsch in Sie-Form (bekannte Grenze) |
| 14.6 | Einzug-Reiter auf Englisch (user1 mit Revisor-Rolle) | ✅ OK | user1 mit Revisor-Berechtigung (per Skript): Reiter „Contributions“ mit „Collection“, Segmente Timeline & runs / Claims / Bank reconciliation, Hinweis „You can only view the bank reconciliation. Judging and posting require the bookkeeper role.“, keine deutschen Reste; Berechtigung danach entfernt. Abweichung: der Satz „The bank statement is the truth“ steht nicht mehr da (siehe 8.3) |
| 14.7 | Zahlen und Daten in englischen Mails | ✅ OK | Englische Mails an Lena: Text englisch, Beträge („22,50 €“) und Daten (TT.MM.JJJJ) deutsch formatiert – bekannte Grenze |

## Phase 15 – Mobil & Barrierefreiheit (Stichproben)

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 15.1 | Schmalen Viewport einstellen | ✅ OK | Handy-Breite: untere Navigation, Plus-Knopf, kein seitliches Scrollen |
| 15.2 | Mitglieder als Karten | ✅ OK | Mitglieder als Karten, Aktionenmenü 4 Einträge, kein Überlauf |
| 15.3 | Einzug auf dem Handy | ✅ OK | Einzugstermine als Liste, Forderungen als Karten mit „Details anzeigen“, kein Überlauf |
| 15.4 | Dialoge und Klemmbrett auf dem Handy | ✅ OK | Klemmbrett passt in die Breite, „Zur Akte“ öffnet die Akte (Jonas Richter); Mitglied aufnehmen und Einzug freigeben ohne Überlauf, Knöpfe nach Scrollen erreichbar |
| 15.5 | Tastatur: Escape, Fokus, Reihenfolge | ✅ OK | Klemmbrett per Enter geöffnet, Esc schließt, Fokus zurück auf den Knopf; Mitglied aufnehmen: Cursor sofort in Vorname, Tippen landet im Feld, Tab bleibt im Dialog, Esc schließt, Fokus zurück auf „Mitglied“; Widerruf-Dialog und Akte schließen mit Esc (Fokus im Dialog nötig, im Hintergrund-Tab ohne Übergänge nicht belastbar) |
| 15.6 | Fokus sichtbar, Namen und Zoom | ✅ OK | Alle Symbol-Knöpfe benannt (Aufgaben – N mit Handlungsbedarf, Hilfe, Forderungen/Bankabgleich aktualisieren, Früherer/Späterer Termin …), Datumsfelder per umschließendem Label; Toast „Erfolg: …“ in aria-live polite; Fokusrahmen 2px hell + 4px dunkler Rand bei allen geprüften Elementen; 720 px Breite (≈200 %) ohne Überlauf auf allen Reitern |
| 15.7 | Dunkles Design | ✅ OK | Dunkel (Systemdesign) und Hell (per OCS umgeschaltet, danach auf Standard zurück): Kontrastprüfung 4,5:1 über Mitglieder, Zeitstrahl, Forderungen, Bankabgleich, Terminplan, Gruppen, Akte (Aktiv/Entwurf/Ausgesetzt), Widerruf-Dialog, Einstellungen ohne Treffer unter der Schwelle |

## Phase 16 – Randfälle & Fehlerfälle

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 16.1 | Leere Zustände | ✅ OK | Mitgliedersuche zzz: „Kein Eintrag passt zur Suche.“; Forderungen mit Fällig von 2040: „Keine Forderung passt zu den gewählten Filtern.“; Bankabgleich: „Es warten keine Bankumsätze …“; Zeitstrahl bis 2036 bleibt gefüllt (offene monatliche Zuweisungen erzeugen in jedem Jahr Termine, Leerhinweis nur ohne Zuweisungen → nicht erreichbar); Klemmbrett hat Aufgaben → Leerzustand übersprungen |
| 16.2 | Doppelklick | 🔧 Korrigiert | Doppelklick auf „Anlegen“ der Einzelforderung legte ZWEI Forderungen an (IDs 304/305): Sperre `saving` in ManualClaimDialog/ClaimsSegment, außerdem Gruppe und Zuweisung (ContributionGroupsPanel, MembersList); nach dem Fix genau eine Forderung (308); Mitglied aufnehmen mit Doppelklick: genau ein Mitglied (299) |
| 16.3 | Ungültige Eingaben und die IBAN-Prüfung | ✅ OK | ABC123: „Das sieht nicht nach einer IBAN aus: ABC123 (erwartet wird z. B. DE12 5001 0517 0648 4898 90).“ nichts gespeichert; falsche Prüfziffer wird angenommen (Entwurf angelegt); E-Mail abc: „Die E-Mail-Adresse ist ungültig: abc“; Betrag 0: „Betrag muss größer als 0 sein.“, ohne Bezeichnung ist Anlegen gesperrt; Einstellungen siehe 2.2/2.5/2.9 |
| 16.4 | Einstellungen: mehrere Fehler und der Server-Check | ✅ OK | Die Auswahllisten bieten nur geeignete Konten (kein „(nicht geeignet)“ in der Liste); der Server lehnt per API Geld-, Ertrags- und Aufwandskonto an falscher Stelle mit klarer Meldung ab (400), nichts gespeichert |
| 16.5 | Offline und Serverfehler ohne Rohtext | ✅ OK | Per XHR-Sperre simuliert: Klemmbrett „Die Liste konnte nicht aktualisiert werden. Angezeigt wird der zuletzt geladene Stand.“; Forderungen „Die Ansicht konnte nicht aktualisiert werden: Die Forderungen konnten nicht geladen werden.“; Bankabgleich „…Der Bankabgleich konnte nicht geladen werden.“ mit „Erneut versuchen“, lädt danach wieder; kein Rohtext; Schritt 5 (jane, returned-debits) offen |
| 16.6 | Zwei Personen zugleich | ✅ OK | Zwei Tabs als alice: Fenster A sperrt Theos Mandat, veraltetes Fenster B scheitert mit „Nur ein aktives Mandat lässt sich aussetzen.“ (Mandat bleibt gesperrt, kein zweiter Eintrag), Entsperren braucht Notiz, danach wieder Aktiv; BEOBACHTUNG: das veraltete Dialogfenster zeigt nach der Fehlermeldung weiter „Aktiv“ (lädt nicht neu) |
| 16.7 | Zurück-Taste und Deep-Links | ✅ OK | admin: Zurück/Vorwärts wechseln Reiter und Adresse folgt; Direktadressen /contributions/batch und /groups öffnen den richtigen Reiter, /self leitet ohne Verknüpfung auf die Übersicht; FRAGE: Zurück schließt einen offenen Dialog nicht, sondern wechselt dahinter den Reiter; bob-Teil offen · bob öffnet /contributions und landet auf Beiträge → Einzug (Zeitstrahl), keine leere Fläche |

## Phase 17 – Zurücksetzen („Alle Daten löschen“)

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 17.1 | Entscheidung und Ausgangslage festhalten | ✅ OK | Ausgangslage vor dem Reset: 37 Mitglieder, 31 Mandate (19 aktiv), 3 Läufe, 43 Forderungen, 14 Konten; Sophie Krügers Sperre notiert |
| 17.2 | „Alle Daten löschen“ ausführen | ✅ OK | Karte und Dialog wie beschrieben; Abbrechen ändert nichts (14 Konten); Bestätigen: „Alle Daten gelöscht.“, danach 0 Konten, 0 Forderungen, 0 Läufe, Mitglieder (37) und Mandate (31) bleiben |
| 17.3 | Der Einzug nach dem Reset | ✅ OK | Einzug nach dem Reset: „Noch kein Lauf …“, „Es gibt noch keine Forderung an ein Mitglied.“, Bankabgleich leer, keine Skriptfehler; Mitglieder, Mandate, Gruppen unverändert |
| 17.4 | Was am Mandat bleibt | ✅ OK | Sophie Krügers Sperre bleibt mit neutralem Aufgabentext („Mandat nach einer Rücklastschrift gesperrt, Klärung offen. Solange wird nichts eingezogen.“); „Zuletzt eingereicht“ und Theos Bankverbindungsänderung nicht einzeln angesehen |
| 17.5 | Der Tageslauf holt Forderungen nach (bekannte Entscheidung) | ✅ OK | Tageslauf und Mahnwesen legen Forderungen neu an und verschicken 3 Zahlungsaufforderungen (ohne Konto ohne GiroCode und ohne Zahlungsdaten, der Text verspricht keinen Code) – dokumentierte offene Entscheidung |
| 17.6 | XML-Kopien und Ausgangszustand wiederherstellen | ✅ OK | Kontenrahmen neu angelegt (1200 mit IBAN), Seeder --wipe --wipe-bank --with-anonymization-booking: 16 Mitglieder, 14 Mandate, Lauf #19 eingereicht; Mailhog geleert |

## Phase 18 – Abschluss

| Schritt | Titel | Ergebnis | Notiz |
|---|---|---|---|
| 18.1 | Einstellungen aufräumen | ✅ OK | Aufgeräumt: XML-Ablage aus, Rahmentext geleert (neue Fassung 2), Self-Service an, Berechtigung user1 entfernt; Fristen 35/30/5 und Mahnabstand 14 vom Seeder |
| 18.2 | Gesamteindruck festhalten | ✍️ Eigene Notiz | Eigene Notiz der Testperson, nicht Teil der automatisierten Prüfung |
| 18.3 | Prioritäten vergeben | ✍️ Eigene Notiz | Eigene Notiz der Testperson, nicht Teil der automatisierten Prüfung |
| 18.4 | Feedback exportieren | ✍️ Eigene Notiz | Eigene Notiz der Testperson, nicht Teil der automatisierten Prüfung |

## Im Durchgang gefundene und behobene Abweichungen

1. **4.11 Einmal-Link:** Das Feld zum Weitergeben des Links fehlte direkt nach dem Anlegen eines elektronischen Mandats. Der Link steht jetzt sofort da; im Mandatsverlauf steht das Unterschriftsdatum als TT.MM.JJJJ.
2. **5.5 Untergrenze anheben:** Die Zuweisungstabelle blieb nach dem Anheben veraltet, und der Standardbeitrag blieb unter der neuen Untergrenze (danach ließ sich die Gruppe nicht mehr speichern). Beides behoben, mit Unit-Tests.
3. **12.5 Datenübersicht:** Zahlungsart und Turnus standen als „direct_debit“ und „1“; jetzt „Lastschrift“ und „monatlich“.
4. **16.2 Doppelklick:** „Anlegen“ der Einzelforderung legte bei einem Doppelklick zwei Forderungen an. Gesperrt ist das Mehrfach-Absenden jetzt für Einzelforderung, Beitragsgruppe und Zuweisung; E2E-Test ergänzt.
5. **11.2 Mein Beitrag:** Nach dem Speichern der Kontaktdaten zeigte die Mandatskarte „Kein Mandat hinterlegt“, bis neu geladen wurde (die Antwort lieferte das Mandat nicht mit). Behoben, mit Test.
6. **11.3 Quittungsmail:** Die Schlusssätze „Pop wurde von dir selbst über „Mein Beitrag“ veranlasst“ und „… führt dafür keine gesonderte Liste“ waren unverständlich. Ersetzt durch „Diese Mail ist die Bestätigung dieser Änderung – eine Handlung deinerseits ist nicht nötig.“ und „Du hast diese Änderung selbst in „Mein Beitrag“ vorgenommen.“
7. **11.12 Sperrfenster-Meldung:** Das Datum stand als ISO „2026-12-01“; jetzt „01.12.2026“.
8. **Lint:** Ein Attribut-Umbruch im Einlese-Dialog ließ die CI-Stufe „Frontend (Lint + Build)“ rot werden; behoben.

**Auf Wunsch der Testperson im Durchgang umgesetzt:** Einzelforderung für mehrere Mitglieder auf einmal; Zahlungsaufforderung mit nummerierten Positionen, Zahlungsdaten zum Abschreiben (Empfänger, IBAN, Betrag, Verwendungszweck mit Forderungsnummer), GiroCode-Anhängen „GiroCode-Position-N.png“ und Zeitraum in Monatsnamen; der Bankabgleich schlägt die Forderung mit genannter Nummer zuerst vor; „Kassenführung“ heißt in Mails, Oberfläche und Dokumentation jetzt „Vorstand“.

## Offene Fragen und Beobachtungen für den Maintainer

- **6.2/6.3:** „Mandat unterschrieben am“ ist im Aufnahme-Dialog mit heute vorbelegt; ein Papier-Mandat bleibt nur dann Entwurf, wenn das Feld geleert wird. Soll die Vorbelegung bleiben?
- **5.6/11.12:** Es gibt keine Bedienstelle für die individuelle Untergrenze (nur die Schnittstelle); sichtbar ist sie in „Mein Beitrag“.
- **7.x:** Nach „Termin verschieben“ entsteht die Einzugsdatei-Kopie als „… (2).xml“.
- **13.4:** Der Hilfe-Link „Was ist neu in Version 0.35.0?“ öffnet für Revisoren ein Fenster, das erst bei 0.34.6 beginnt, weil der 0.35.0-Eintrag nur für Verwalter und Buchhalter gilt.
- **16.6:** Ein veralteter Mandats-Dialog zeigt nach der Konfliktmeldung („Nur ein aktives Mandat lässt sich aussetzen.“) weiter „Aktiv“ und lädt nicht neu.
- **16.7:** „Zurück“ bei geöffnetem Dialog wechselt den Reiter dahinter, der Dialog bleibt offen.
- **Zahlungsaufforderung:** BIC und Bankname gibt es am Einziehenden Konto nicht; eine BIC braucht eine SEPA-Überweisung im Euro-Raum nicht mehr. Wer sie in der Mail will, braucht ein Feld am Konto (Migration).
- **Zahlungsaufforderung:** Die Fußzeile des Nextcloud-Mailsystems stand in einer englischen Zahlungsaufforderung auf Deutsch (kosmetisch, Kern).
- **Einzelforderung ohne Zeitraum:** Eine manuelle Forderung hat keinen Zeitraum, die Mail kann deshalb keinen Monat nennen. Ein optionales Feld „Zeitraum“ wäre die Lösung.
- **Bekannte offene Entscheidungen (Spec §15.3):** Der Tageslauf holt nach „Alle Daten löschen“ Forderungen für längst abgerechnete Zeiträume nach (17.5); die Anonymisierung lässt Ereignis-Sätze im Verlauf stehen (12.9).
