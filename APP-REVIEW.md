# Review-Vorlage „Gesamte App": Personas und Testfälle

> **Status: noch nicht durchgeführt.** Personas und Testfälle sind am
> 15.08.2026 aus dem Code hergeleitet (Version 0.22.2), nicht erlaufen. Die
> zitierten Beschriftungen stammen aus den jeweiligen Komponenten und sind
> damit als **Soll-Zustand** belastbar – das tatsächliche Verhalten ist es
> nicht. Wer den Review fährt, trägt die Ergebnisse in
> [Abschnitt 5](#5-befunde) nach.

## 1. Abgrenzung zu BEITRAEGE-REVIEW.md

`BEITRAEGE-REVIEW.md` dokumentiert den durchgeführten Usability-Review des
Moduls **„Beiträge & SEPA"** (Personas Katrin, Bernd, Sofia; sieben Befunde,
alle in 0.22.2 behoben). Für Nachtests an genau diesem Modul ist weiterhin
jene Datei die richtige.

Diese Datei hier ist das Gegenstück für die **übrige App**: Buchhaltung,
Import, Kassenprüfung, Jahresabschluss, Rechteverwaltung und mobile Bedienung.
Die Beiträge-Personas werden hier nicht wiederholt.

## 2. Personas

| Persona | Rolle in der App | Situation | Ziel im Test | Deckt ab |
|---|---|---|---|---|
| **Jürgen**, 63 | Verwalter, Ersteinrichtung | Neu gewählter Kassenwart eines kleinen Kegelclubs (25 Mitglieder), keine Vorgänger-Übergabe, keine xbuc-Datei, noch nie Buchhaltungssoftware benutzt. | Setup-Assistent von Null durchlaufen (Standard-Kontenrahmen), Einrichtungs-Checkliste abarbeiten, vorher mit dem Beispielverein ausprobieren und wieder auf „leer" zurücksetzen. Desktop. | Setup-Assistent, Einrichtungs-Checkliste, Beispielverein, Erste-Buchung-Tour |
| **Frank**, 50 | Verwalter, Altsystem-Wechsel | Übernimmt die Kasse eines Vereins, der bisher mit „zero Buchhaltung" gearbeitet hat – drei Jahres-`.xbuc`-Dateien liegen vor, dazu ein Ordner mit Kontoauszügen im CAMT.053-Format der Sparkasse. | xbuc-Mehrjahresimport (Merge-Modus, Geschäftsjahr-Prüfung, Anfangsbestände-Erkennung), danach Wachordner für künftige Auszüge einrichten. | xbuc-Import, Wachordner |
| **Thomas**, 39 | Verwalter, laufende Buchführung | Kassenwart eines Sportvereins mit Vereinsheim (Vermietung + kleiner Kiosk), bucht wöchentlich Kontoauszüge für drei Abteilungen, nähert sich mit dem Kiosk der Zweckbetriebs-Freigrenze. | CAMT-/MT940-Import, Auto-Zuordnungsregeln anlegen, Splittbuchung bei einer Sammelüberweisung, Kostenstellen je Abteilung, Sphären-Zuordnung Kiosk inkl. Dashboard-Warnleiste zur Freigrenze (§ 64 Abs. 3 AO). | Bankimport, Regeln, Splittbuchungen, Kostenstellen, Sphären |
| **Renate**, 71 | Revisor | Von der Mitgliederversammlung gewählte Kassenprüferin, prüft einmal im Jahr vor Ort, bislang kein eigener Nextcloud-Zugang, wenig IT-Erfahrung. | Erstlogin mit Rolle Revisor (Willkommenshinweis + Prüfleitfaden), Änderungsprotokoll, Lückenprüfung, Filter „nur ohne Beleg", Beleg-ZIP – und Gegenprobe, dass nirgends eine Schreibaktion (Buchen, Löschen, Zuordnen) angeboten oder möglich ist. | Kassenprüfung, Rolle Revisor (nur Lesen) |
| **Hannelore**, 60 | Verwalter, Jahreswechsel | Langjährige Schatzmeisterin eines Fördervereins. Die Mitgliederversammlung steht in zwei Wochen an: Jahresabschluss muss noch gemacht werden, danach Kassenbericht und Kurzbericht für die Sitzung drucken, Rückfragen zu Rücklagen und Finanzplan beantworten. | Jahr abschließen (danach Schreibversuch → HTTP 423 quittiert), Kassenbericht + Kurzbericht (mit Vereinslogo/Akzentfarbe) drucken/als PDF, Finanzplan-Soll-Ist inkl. gespeichertem Plan-Stand vergleichen, Mehrjahresübersicht als CSV und Diagramm. | Jahresabschluss, Kassenbericht/Kurzbericht, Finanzplan/Plan-Stände, Mehrjahresübersicht |
| **Petra**, 55 | Verwalter, Rechte & Kollaboration | Nicht selbst Kassenwartin, aber IT-Verantwortliche des Vereins; richtet nach einem Vorstandswechsel die Rollen neu ein und ist mit dem neuen Kassenwart gleichzeitig eingeloggt. | Zahnrad → Rechtevergabe: Rollen für einzelne Nutzer *und* eine Gruppe setzen/entziehen; dieselbe Buchung in zwei Browser-Kontexten gleichzeitig öffnen → optimistisches Locking/Konfliktmeldung statt stillem Überschreiben, Polling-Update (20 s / bei Fokus) beobachten. | Berechtigungsrollen (Nutzer & Gruppen), Kollaboration/Locking |
| **Uwe**, 44 | Verwalter, mobil beim Vereinsfest | Steht am Getränkestand, kassiert Bareinnahmen, hat nur das Handy dabei, mehrere Buchungen pro Minute in Stoßzeiten. | Bottom-Navigation „+" → Schnellerfassung (großes Betragsfeld, native Datumswahl), Beleg direkt per Kamera fotografieren, Auswahl-Sheet für Konten mit Gruppe „Zuletzt verwendet". | Mobile Bedienung (Buchungserfassung), Belege |
| **Markus**, 29 | *keine* Rolle | Ganz normales Vereinsmitglied mit Nextcloud-Konto (nutzt Kalender und Dateien), stolpert über die App-Kachel und klickt sie an. Soll die Vereinsfinanzen nicht sehen. | Muss die „Kein Zugriff"-Seite bekommen – und zwar nicht nur optisch: auch direkte API-Aufrufe müssen 403 liefern. | Negativfall Rechtesystem (Datenschutz) |

Kurz-Faustregel für künftige Zusammenstellung: Rolle (Verwalter/Buchhalter/
Revisor/keine) × Gerät (Desktop/Handy) × Vereinsgröße/-komplexität (klein-simpel
bis groß mit wirtschaftlichem Geschäftsbetrieb) × Zeitpunkt im Vereinsjahr
(Ersteinrichtung, laufender Betrieb, Jahresabschluss/Mitgliederversammlung)
deckt die meisten realistischen Testlücken ab.

## 3. Vorbereitung

- Drei Testnutzer in der Docker-Instanz mit den Rollen **verwalter**,
  **buchhalter**, **revisor** plus **einer ganz ohne Eintrag** (Markus).
  Rollen-Codes intern: `verwalter` / `buchhalter` / `revisor` / `none`
  (`PermissionService`); NC-Admins sind immer Verwalter, unabhängig von der
  Rechteliste.
- Für Rollenwechsel je einen eigenen Browser-Kontext verwenden
  (`Target.createBrowserContext`), sonst geht die Verwalter-Session verloren –
  siehe Projekt-Memory „Docker-Testinstanz".
- Jürgens Testfälle brauchen eine **leere** Buchhaltung (keine Konten), sonst
  startet der Setup-Assistent nicht. Am einfachsten: Zahnrad → „Alle Daten
  löschen" vorweg, oder eine frische Instanz.

## 4. Testfälle

### 4.1 Jürgen (Verwalter, Ersteinrichtung, Desktop)

1. Leere Instanz öffnen → Setup-Assistent „Willkommen bei der
   Vereinsbuchhaltung!" erscheint von selbst, mit drei Wegen und
   „Überspringen, ich schaue mich selbst um".
2. „Erst mit Beispieldaten ausprobieren" → Banner „**Beispieldaten aktiv.**
   Das ist ein Beispielverein zum Ausprobieren, keine echten Daten." steht
   sichtbar über der Ansicht; Dashboard, Journal und Berichte sind befüllt.
3. Im Banner „Zurücksetzen" → Beispieldaten und Banner verschwinden, die
   Buchhaltung ist wieder leer.
4. Dashboard → „Setup-Assistenten öffnen" → „Ich fange neu an" →
   Standard-Kontenrahmen steht im Reiter „Konten".
5. Checkliste „Erste Schritte (x von y erledigt)": jeder offene Schritt
   springt an die richtige Stelle – „Verein benennen" → Zahnrad/Verein,
   „Berechtigungen vergeben" → Zahnrad/Berechtigungen, „Sphären zuordnen
   (steuerlich)" → Berichte → Sphären. Erledigte Schritte verschwinden aus
   der Liste, der Zähler stimmt.
6. Erste Buchung anlegen (Einfach-Modus, Desktop) → die einmalige
   Drei-Schritte-Tour erscheint; „Überspringen" beendet sie und sie kommt
   nach einem Reload **nicht** wieder.
7. Checkliste ausblenden → bleibt auch nach Reload ausgeblendet.

### 4.2 Frank (Verwalter, Umstieg von „zero Buchhaltung")

1. Zahnrad → xbuc-Import: drei Jahresdateien **nacheinander** im Merge-Modus
   (Standard) einlesen.
2. Bei Datei 2 und 3: vorhandene Konten werden nicht doppelt angelegt, bereits
   importierte Buchungen per Fingerprint übersprungen – die Ergebnisübersicht
   nennt die Zahl.
3. Datei mit Buchungen außerhalb des gewählten Geschäftsjahres → Meldung und
   Angebot, sie automatisch auf 01.01./31.12. zu datieren.
4. Anfangsbestände beim Mehrjahresimport: werden übersprungen, wenn durch die
   Vorjahresbuchungen gedeckt; bei Abweichung erscheint eine Warnung statt
   stiller Übernahme.
5. Buchungen ohne Gegenkonto landen im Unterreiter „Zuzuordnen" (Badge zählt
   sie).
6. Zahnrad → Bankdaten: Wachordner setzen, eine CAMT.053-Datei hineinlegen,
   Job auslösen → Datei liegt danach in `verarbeitet/`, die Umsätze stehen in
   der App. Eine absichtlich kaputte Datei landet in `fehler/` **mit**
   Begründung; gelöscht wird nichts.
7. Denselben Auszug zusätzlich als MT940 bzw. CSV-CAMT einlesen →
   formatübergreifende Dublettenerkennung greift, es entstehen keine
   Doppelbuchungen.

### 4.3 Thomas (Verwalter, laufender Betrieb, komplexer Verein)

1. „Umsätze importieren" (CSV-CAMT) → Vorschau → übernehmen.
2. Bankbuchung zuordnen: Vorschlag aus Regel/Historie wird angeboten und ist
   per Klick übernehmbar.
3. Blitz-Button an einer gebuchten Bankbuchung → Regel wird vorbefüllt
   angelegt und erscheint im Unterreiter „Regeln" (der nur ab Rolle
   Buchhalter überhaupt sichtbar ist).
4. Sammelüberweisung über „Aufteilen…" auf mehrere Gegenkonten: Restanzeige
   zeigt den fehlenden Betrag, Speichern gelingt erst bei aufgehender Summe,
   das Geldkonto bleibt **eine** Zeile über den vollen Betrag.
5. Berichte → Kostenstellen: Modus „frei definierte Kostenstellen", Konten je
   Abteilung per Mehrfachauswahl zuordnen, Drilldown auf die Buchungen.
6. Berichte → Sphären: Kiosk-Konten dem wirtschaftlichen Geschäftsbetrieb
   zuordnen → Dashboard zeigt „… von 45.000 € Freigrenze"; bei Überschreitung
   den Text „– Freigrenze überschritten, bitte mit Steuerberatung klären.",
   knapp darunter „– nähert sich der Freigrenze."
7. Zwei Geldkonten mit hinterlegter IBAN → beim Zuordnen wählt die App das
   Geldkonto passend zur IBAN des Auszugs.

### 4.4 Renate (Revisorin, nur Lesen) — höchste Priorität

Die Rolle Revisor ist bisher in **keinem** Review durchgespielt worden; Punkt
6 und 7 sind die eigentlich wichtigen.

1. Erstlogin als Revisor → einmaliger Willkommenshinweis mit Prüfleitfaden;
   nach dem Wegklicken erscheint er nicht erneut.
2. Berichte → Auswertung: Prüfleitfaden und Kassenbericht öffnen und drucken
   (beide öffnen einen neuen Tab).
3. Berichte → Protokoll: Anlegen/Ändern/Löschen, Zuordnungen, Importe,
   Belege, Rechteänderungen und Jahresabschlüsse sind mit Wer und Wann
   nachvollziehbar.
4. Journal: Filter „nur ohne Beleg" greift; bei manipulierter Nummernfolge
   erscheint die Lückenwarnung über dem Journal und die Vollständigkeitszeile
   im Kassenbericht meldet sie.
5. Beleg-ZIP eines Jahres herunterladen; fehlt eine Datei, wird sie
   aufgelistet statt den Export abzubrechen.
6. **Gegenprobe UI**: Zahnrad-Knopf fehlt vollständig (Einstellungen sind ab
   Buchhalter), ebenso „Neue Buchung", „Umsätze importieren", der Unterreiter
   „Regeln", der Reiter „Beiträge" sowie sämtliche Bearbeiten-/Löschen-
   Aktionen in den Listen.
7. **Gegenprobe API** (wichtiger als Punkt 6, weil Verstecken keine
   Rechteprüfung ist): mit der Revisor-Session je einen direkten
   Schreib-Request absetzen (z. B. `POST /apps/vereinsbuchhaltung/api/journal`,
   `POST …/api/rules`, `POST …/api/members`) → jeweils **403**, nicht 200.

### 4.5 Markus (kein Zugriff)

1. Als Nutzer ohne jeden Rechte-Eintrag die App öffnen → Seite „Kein Zugriff"
   mit dem Hinweis „Du hast keine Berechtigung für die Vereinsbuchhaltung.
   Bitte wende dich an eine Verwalterin oder einen Verwalter." – keine Zahlen,
   keine Kontonamen, keine Jahresauswahl.
2. Direkte Lese-Requests (`GET …/api/accounts`, `…/api/journal`,
   `…/api/members`) → **403**, kein Datenleck über die API.
3. Gegenprobe: Rolle Revisor vergeben → nach Reload ist die App normal lesbar;
   Rolle wieder entziehen → wieder „Kein Zugriff".

### 4.6 Hannelore (Verwalter, Jahresabschluss & Mitgliederversammlung)

1. Zahnrad → Jahresabschluss: Jahr „Abschließen"; der Bestätigungsdialog
   benennt die Folgen (Buchungen, Belege, Zuordnungen unveränderlich).
2. Danach im abgeschlossenen Jahr eine Buchung ändern, löschen oder einen
   Beleg anhängen → wird abgelehnt (HTTP 423) mit verständlicher Meldung,
   nicht mit einem rohen Fehlercode.
3. Im Header auf ein offenes Jahr wechseln → dort ist wieder alles buchbar.
4. Kassenbericht drucken: Vereinsname im Kopf, Vermögensübersicht der
   Geldkonten (Bestand 01.01./31.12.), Einnahmen-/Ausgaben-Rechnung,
   Soll-Ist-Vergleich, Sphärenübersicht mit Freigrenzenhinweis,
   Vollständigkeitsvermerk, Abschlussvermerk, Unterschriftszeilen – in der
   Druckvorschau nichts abgeschnitten.
5. Kurzbericht mit gewähltem Stichtag; zuvor unter Zahnrad → Verein Logo und
   Akzentfarbe hinterlegen → beides erscheint im Kurzbericht.
6. Berichte → Finanzplan: Plan-Stand „Beschluss MV" einfrieren, danach
   Planzahlen ändern → Vergleich weist die Differenz aus; Notizen je Planzahl
   („40 Mitglieder × 25 €") sind sichtbar.
7. Berichte → Rücklagen: Saldo je Rücklagenart (frei / zweckgebunden /
   Wiederbeschaffung) stimmt mit den Eigenkapital-Konten überein.
8. Mehrjahresübersicht als CSV **und** als Liniendiagramm (Berichte →
   Auswertung).
9. Jahr „Wiedereröffnen" → roter Bestätigungsdialog mit Ausnahmefall-Hinweis;
   der Vorgang steht anschließend im Protokoll.

### 4.7 Petra (Verwalter, Rechte & gleichzeitiges Arbeiten)

1. Zahnrad → Berechtigungen: je einen Eintrag für einen **Nutzer** und eine
   **Gruppe** anlegen; die Tabelle zeigt Typ, Nutzer/Gruppe und Rolle.
2. Ein Gruppenmitglied ohne eigenen Eintrag bekommt die Gruppenrolle; hat es
   zusätzlich einen eigenen Eintrag, gilt die **höhere** der beiden Rollen.
3. Rolle entziehen → die betroffene Person sieht nach dem nächsten Laden
   „Kein Zugriff".
4. Ein Nextcloud-Admin ohne jeden Eintrag ist trotzdem Verwalter.
5. **Locking**: dieselbe Buchung in zwei Sessions öffnen, in A speichern, dann
   in B speichern → „Diese Buchung wurde zwischenzeitlich von einer anderen
   Person geändert. Die Ansicht wurde aktualisiert – bitte erneut versuchen."
   Die Änderung von A bleibt erhalten.
6. **Polling**: in A eine Buchung anlegen → B aktualisiert sich binnen ~20 s
   bzw. sofort beim Fensterfokus, mit dem Hinweis „Die Buchhaltung wurde von
   einer anderen Person geändert – Ansicht aktualisiert."

### 4.8 Uwe (Verwalter, mobil, 390×844)

1. Viewport auf 390×844 → Bottom-Navigation mit „+"-Knopf erscheint
   (Breakpoint 640 px), die Desktop-Kopfzeile weicht.
2. „+" → Buchungsdialog im Einfach-Modus: großes Betragsfeld, native
   Datumswahl, mit Daumen bedienbar.
3. Kontoauswahl öffnet das Sheet: durchsuchbar, Gruppe „Zuletzt verwendet"
   oben, Wischen nach unten schließt es.
4. „Fotografieren" öffnet direkt die Kamera (`capture="environment"`), das
   Bild hängt anschließend an der Buchung.
5. Zehn Bareinnahmen zügig hintereinander erfassen – kein Zwischenschritt
   zwingt zurück aufs Dashboard.
6. Journal als Karten nach Monat gruppiert; Saldenliste, Kontoauszug und
   Kostenstellen ebenfalls als Karten mit funktionierender Zurück-Leiste.

### 4.9 Allgemein (nach jeder Änderung)

Gilt unverändert wie in `BEITRAEGE-REVIEW.md` Abschnitt 6.5: PHPStan (Vergleich
gegen den Stand davor, nicht absolut lesen), PHPUnit, ESLint, Vitest,
`npm run build` – und mindestens einmal alle drei Rollen durchklicken.

## 5. Befunde

*Noch keine – der Review ist noch nicht gelaufen. Tabellenkopf steht bereit,
Aufbau wie `BEITRAEGE-REVIEW.md` Abschnitt 3.*

| # | Schwere | Befund | Persona | Fix | Berührte Dateien |
|---|---|---|---|---|---|
| | | | | | |
