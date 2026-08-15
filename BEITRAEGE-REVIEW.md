# Usability-Review „Beiträge & SEPA": Personas, Befunde, Testfälle

> **Durchgeführt am 15.08.2026** auf Branch `feature/navigation` gegen die
> lokale Docker-Testinstanz (headless Chromium per CDP, siehe Projekt-Memory).
> Alle sieben Befunde sind mit Version 0.22.2 behoben und live erneut
> verifiziert (siehe Abschnitt 3). Diese Datei bleibt als Nachschlagewerk und
> als Vorlage für künftige Reviews desselben Moduls stehen – Personas und
> Testfälle sind absichtlich so geschrieben, dass sie sich nach dem nächsten
> Umbau wiederverwenden und ergänzen lassen, nicht nur einmalig abhaken.
>
> Diese Datei betrifft **nur** das Modul „Beiträge & SEPA". Personas und
> Testfälle für die übrige App – Buchhaltung, Import, Kassenprüfung,
> Jahresabschluss, Rechteverwaltung, Mobil – stehen in
> **[APP-REVIEW.md](APP-REVIEW.md)** (seit 15.08.2026 durchgeführt, drei
> Befunde, einer davon hoch).

## 1. Anlass

Auftrag: prüfen, ob eine ehrenamtliche Kassiererin ohne Buchhaltungs- oder
IT-Hintergrund die Mitglieder-, Beitrags- und SEPA-Verwaltung allein bedienen
kann – für einen Chor mit 80–100 Mitgliedern, festem Beitragssatz und ein paar
Sonderfällen, Desktop **und** Handy (Chorprobe, Mitgliederversammlung,
Gremiensitzung).

## 2. Personas

Vier Rollen, wie sie in einem Chor dieser Größe tatsächlich vorkommen –
bewusst ohne Fachkenntnis, denn das ist der Regelfall im Ehrenamt. Bei
künftigen Reviews desselben Moduls: dieselben Personas wiederverwenden, bei
Bedarf um weitere ergänzen. Personas für die übrige App (Ersteinrichtung,
Migration, Kassenprüfung, Jahresabschluss, Rechte, Mobil) siehe
[APP-REVIEW.md](APP-REVIEW.md).

| Persona | Rolle in der App | Situation | Ziel im Test |
|---|---|---|---|
| **Katrin**, 58 | Verwalter | Übernimmt die Kasse eines gemischten Chors mit 92 Sänger:innen von ihrer Vorgängerin. Excel-Erfahrung, keine Buchhaltungsausbildung. | Mitgliederliste per CSV einlesen, Beitragssatz für fast alle setzen, SEPA-Einzug fahren – am Desktop. |
| **Bernd**, 47 | Buchhalter (bis 0.22.1: kein Zugriff auf „Beiträge", siehe [Abschnitt 5](#5-bezug-zu-navigation-konzeptmd)) | Hilft Katrin bei der laufenden Buchführung, sitzt nicht im geschäftsführenden Vorstand, soll bewusst keine Rechtevergabe bekommen. | Prüfen, ob er mit „lesen und schreiben"-Rechten die Beitragsliste sieht und bearbeiten kann. |
| **Sofia**, 34 | Verwalter oder Buchhalter, unterwegs | Sitzt in der Chorprobe/Vorstandssitzung, wenn jemand fragt „Ist mein Beitrag abgebucht?" oder „Wer hat noch kein Mandat?". Nur Handy, drei Minuten Zeit. | Auf dem Handy (390 px) schnell eine Person finden und ihren Status lesen. |
| Die übrigen ~90 Sänger:innen | kein App-Zugang | Normale Chormitglieder sind i. d. R. keine Nextcloud-Nutzer:innen, tauchen nur als Zahlername/IBAN auf. | Nur indirekt berührt, über die SEPA-Vorankündigungsmail (nicht separat geprüft). |

## 3. Befunde und Status

Alle sieben Befunde sind mit **Version 0.22.2** behoben (Commit
„Version 0.22.2: Befunde des Beitraege-Usability-Reviews behoben"). Schweregrad
wie im ursprünglichen Review: „kritisch" blockierte eine Kernaufgabe
vollständig, nicht nur Komfort.

| # | Schwere | Befund | Persona | Fix | Berührte Dateien |
|---|---|---|---|---|---|
| 1 | kritisch | Rolle „Buchhalter" sah den Reiter „Beiträge" nicht | Bernd | `ROLE_WRITE` statt `ROLE_ADMIN` in vier Controllern, Tab-Gate `need: 'write'` | `SepaMandateController.php`, `MembershipFeeController.php`, `SepaBatchController.php`, `MemberImportController.php`, `App.vue` |
| 2 | kritisch | Mitgliederliste auf dem Handy unlesbar (Buchstaben-Stapel-Bug) | Sofia | Mobile Kartenansicht (`vbh-cardlist`), analog `BookingCard.vue` | neu: `MemberCard.vue`; `MembersList.vue`, `SepaBatchPanel.vue` |
| 3 | hoch | E-Mails mit Umlaut im lokalen Teil („m.müller@gmx.de") galten als ungültig | Katrin (Import) | Eigener `EmailValidator` statt reinem `FILTER_VALIDATE_EMAIL` | neu: `EmailValidator.php`; `MemberCsvParser.php`, `SepaMandateService.php` |
| 4 | hoch | Bestätigungsdialog „Mitglieder übernehmen" zeigte roten „Löschen"-Button | Katrin | `askConfirm()` mit `confirmLabel`/`confirmVariant` aufgerufen | `MemberImportDialog.vue`, `MembersList.vue` |
| 5 | mittel | Kein vereinsweiter Standard-Beitragssatz – Betrag musste pro Mitglied/CSV-Zeile eingetippt werden | Katrin | Neue Einstellung „Standardbeitrag", befüllt Dialog vor und greift im CSV-Import (nur bei Start-Datum ohne eigenen Betrag) | `SettingsSepaBasics.vue`, `MemberDialog.vue`, `MemberImportDialog.vue`, `SettingsController.php`, `MemberCsvParser.php`, `MemberImportService.php` |
| 6 | mittel | Aktionsspalte der Mitgliederliste auch am Desktop (1374 px) abgeschnitten | Katrin | Seltene Aktionen ins `NcActions`-Menü, Muster aus dem Buchungsjournal übernommen | `MembersList.vue`, `MemberCard.vue`, `styles.css` |
| 7 | niedrig | Reiter „Beiträge" blitzte beim Laden kurz auf | – | `loadStorageSettings()` in den Haupt-`Promise.all()` von `mounted()` verschoben | `App.vue` |

## 4. Was bereits gut funktionierte (nicht angefasst)

Für künftige Reviews zur Abgrenzung, was bewusst unverändert blieb:

- Grundmodell „Mandat und Beitrag getrennt, beides optional" – in einem Satz im
  Dialog erklärt.
- CSV-Import zeigt vor dem Anlegen immer erst eine vollständige
  Zeile-für-Zeile-Vorschau.
- Fehlertexte benennen das Problem konkret („Dieser Zahler steht schon in
  Zeile 5 dieser Datei").
- SEPA-Fachtexte (Vorlauffristen, Rücklastschrift, Erst-/Folgeeinzug) sind in
  Alltagssprache übersetzt.

## 5. Bezug zu NAVIGATION-KONZEPT.md

`NAVIGATION-KONZEPT.md` Abschnitt 7, Entscheidung **D3**, hatte am 14.08.2026
festgelegt: „Bleibt Verwalter-only. Wenn in einem Verein der Kassenwart
‚Buchhalter' ist, ist das eine eigene, bewusst zu treffende
Datenschutz-Entscheidung." Genau diese Entscheidung wurde einen Tag später
(15.08.2026) im Review aufgeworfen, dem Nutzer explizit zur Wahl gestellt
(volle Rechte / nur lesend / unverändert) und zugunsten **voller Rechte für
Buchhalter** getroffen (siehe Befund 1 oben und Projekt-Memory
„Buchhalter-Zugriff auf Beiträge"). `NAVIGATION-KONZEPT.md` selbst wurde nicht
mehr angepasst, da sie laut ihrer eigenen Kopfzeile „gelöscht werden darf,
wenn nicht mehr gebraucht" – D3 gilt dort als historischer Stand vor dieser
Änderung.

## 6. Testfälle (wiederverwendbar)

Als Checkliste für die nächste Regression gedacht – nach jeder größeren
Änderung an `ContributionsTab.vue`, `MembersList.vue`, `SepaBatchPanel.vue`
oder den zugehörigen Controllern einmal durchgehen. Umgebung: Docker-Testinstanz
`nextcloud-test`, headless Chromium per CDP (Vorgehen inkl. Stolperfallen beim
Nachbauen siehe Projekt-Memory „Docker-Testinstanz").

### 6.1 Testdaten-Rezept

Ein CSV mit **~90 synthetischen Mitgliedern** deckt die Chorgröße aus der
Aufgabenstellung ab und ist ergiebiger als 2–3 Beispielzeilen. Bewusst
enthalten sein sollten:
- mehrere Namen mit Umlaut (Müller, Krüger, Schröder …) samt Umlaut-E-Mail
  (`…@gmx.de`/`web.de`/`t-online.de`) – deckt Befund 3 ab.
- ein ermäßigter Einzelfall (abweichender Betrag).
- ein Zahler ohne IBAN, aber mit Betrag (Überweiser) – deckt „nur Beitrag"
  ab.
- eine Zeile mit ungültiger IBAN.
- eine Zeile mit Betrag `0` (muss übersprungen werden).
- zwei Zeilen mit identischem Zahlernamen (muss als Dublette erkannt werden).
- für Befund 5: einzelne Zeilen ganz ohne Betrag-Spalte, aber mit
  Start-Datum, sobald ein Standardbeitrag in den Einstellungen hinterlegt
  ist – erwartet wird automatische Übernahme des Standardsatzes.

### 6.2 Persona Katrin (Verwalter, Desktop)

1. Beiträge & SEPA-Einstellungen: Standardbeitrag setzen (z. B. 8,00 € /
   monatlich) → speichern → Erfolgstoast.
2. „Mitglied aufnehmen" öffnen → Betrag/Frequenz müssen mit dem
   Standardbeitrag vorbelegt sein, änderbar für Einzelfälle.
3. „Liste einlesen" → Testdaten-CSV (6.1) auswählen → „Prüfen" → Vorschau
   zeigt je Zeile ein nachvollziehbares Ergebnis, Umlaut-E-Mails werden
   **nicht** als „ungültig" abgelehnt, Zeilen ohne Betrag mit Start-Datum
   übernehmen den Standardbeitrag.
4. „N Zeilen übernehmen" klicken → Bestätigungsdialog zeigt einen **blauen**
   „Übernehmen"-Button, nicht rot/„Löschen".
5. Mitgliederliste nach Import: Suche/Filter funktionieren, Summenzeile
   stimmt, Aktionsspalte zeigt „Bearbeiten" + Menü-Button, keine Buttons
   ragen aus der Tabelle heraus (bei ~1374 px Fensterbreite).
6. Menü-Button einer Zeile öffnen → „Bankverbindung wechseln", „Mandat
   widerrufen", „… löschen" vorhanden und auslösbar.
7. Reiter „Einzug": Vorschau zeigt fällige Posten, Einzug erzeugen, XML
   herunterladen, als ausgeführt verbuchen.

### 6.3 Persona Bernd (Buchhalter)

1. Als Buchhalter-Nutzer einloggen (separater Browser-Kontext, damit die
   Verwalter-Session nicht verloren geht – siehe Projekt-Memory für das
   CDP-Vorgehen mit `Target.createBrowserContext`).
2. Reiter „Beiträge" muss in der Hauptnavigation erscheinen.
3. Mitgliederliste lädt vollständig (kein „Kein Zugriff"/403).
4. Ein Mitglied anlegen, einen Beitrag bearbeiten, einen Einzug erzeugen –
   alles muss ohne Berechtigungsfehler funktionieren.
5. Gegenprobe: Einstellungen → Rechtevergabe, Jahresabschluss bleiben für
   Bernd **nicht** erreichbar (weiterhin Verwalter-only).

### 6.4 Persona Sofia (mobil, 390×844)

1. Viewport auf 390×844 stellen (`Emulation.setDeviceMetricsOverride` bzw.
   Chrome-DevTools-Gerätesimulation).
2. Reiter „Beiträge" → Mitglieder: Liste erscheint als **Karten**, nicht als
   Tabelle; Name, Betrag, Bankverbindung, Fälligkeit lesbar ohne horizontales
   Scrollen oder Buchstaben-Umbruch.
3. Eine Karte antippen → „Bearbeiten" → Eingabefelder (Betrag, Frequenz,
   Fälligkeit, Aktiv) erscheinen inline in der Karte, „Speichern"/„Abbrechen"
   funktionieren.
4. Menü-Button („…") einer Karte öffnen → gleiche Aktionen wie am Desktop.
5. Reiter „Einzug": Vorschau- und Einzugsliste ebenfalls als Karten;
   aufgeklappte Einzugszeilen (Zahler je Einzug) erscheinen eingerückt
   darunter.
6. Suche im Suchfeld funktioniert wie am Desktop.

> Bei der letzten Durchführung (15.08.2026) wurde 6.4 Punkt 5 nur gegen eine
> **leere** Vorschau-/Einzugsliste geprüft (keine fälligen offenen Posten zum
> Testzeitpunkt) – Struktur und CSS sind identisch zur verifizierten
> Mitgliederkarte, aber ein Durchlauf mit **befüllten** Karten (echter Einzug
> mit mehreren Zeilen) steht noch aus. Guter erster Testfall für die nächste
> Session.

### 6.5 Allgemein (nach jeder Änderung)

- `php /tmp/phpstan.phar analyse --configuration phpstan.neon --memory-limit=1G`
  (oder das CI-Äquivalent) – Fehlerzahl mit `git stash`/Worktree-Vergleich
  gegen den Stand davor prüfen, nicht nur absolut lesen: dieses Projekt hat
  einen bekannten, umgebungsbedingten Bestand an „DataResponse expects T of
  DataResponseType"-Meldungen, der nichts mit dem eigenen Diff zu tun haben
  muss.
- `vendor`-loses PHPUnit (`php /tmp/phpunit.phar --configuration phpunit.xml`),
  `npx eslint --ext .js,.vue src/`, `npx vitest run`, `npm run build`.
- Mindestens einmal alle drei Rollen (Verwalter, Buchhalter, Revisor) mit und
  ohne aktives Beitragsmodul durchklicken.
