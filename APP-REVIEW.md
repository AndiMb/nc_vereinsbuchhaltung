# Review-Vorlage „Gesamte App": Personas und Testfälle

> **Durchgeführt am 15.08.2026** auf Version 0.22.2 gegen die lokale
> Docker-Testinstanz (headless Chromium per CDP, zwei unabhängige
> Chrome-Prozesse für echte Parallel-Sessions bei Petra – siehe
> Projekt-Memory „Docker-Testinstanz"). Alle acht Personas wurden
> durchgespielt; drei Befunde siehe [Abschnitt 5](#5-befunde). Der einzige
> hohe Befund (Uwe, mobile Kontoauswahl unsichtbar hinter dem Buchungsdialog)
> ist noch am selben Tag mit **Version 0.22.3** behoben und per
> Real-Klick-Koordinaten (nicht nur `.click()`) erneut verifiziert worden.
> Punkte, die aus Testdaten- oder Werkzeuggründen nicht erlaufen wurden, sind
> in Abschnitt 5 einzeln vermerkt.

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

Aufbau wie `BEITRAEGE-REVIEW.md` Abschnitt 3. Schweregrad wie dort: „kritisch"
blockiert eine Kernaufgabe vollständig, „hoch" einen häufigen Weg dorthin
(Umgehung existiert, ist aber unbequem/nicht offensichtlich), „niedrig"
Kosmetik/Randfall. Befund 1 ist mit **Version 0.22.3** behoben; Befunde 2–3
sind unten als Vorschlag festgehalten, aber (bewusst, geringe Priorität) noch
nicht umgesetzt.

| # | Schwere | Befund | Persona | Fix / Vorschlag | Berührte Dateien |
|---|---|---|---|---|---|
| 1 | hoch | Auf dem Handy öffnete sich das Konto-/Kategorie-Auswahl-Sheet (Betragsfeld → „Kategorie wählen…"/„Geldkonto") zwar korrekt im DOM (`.vbh-sheetwrap`, `z-index: 12000`), blieb aber **unsichtbar und unklickbar hinter dem „Neue Buchung"-Dialog**: Klicks an der Sheet-Position landeten nachweislich auf dem darunterliegenden Buchungsformular. Ursache: Nextclouds eigenes Core-CSS setzt `#content:not(.with-sidebar--full) { position: fixed }` – und `position: fixed` erzeugt einen neuen Stacking-Context, unabhängig vom eigenen `z-index`. Der App-Root (`#content` = `.app-vereinsbuchhaltung`) sitzt darin, `.vbh-sheetwrap` war ein Nachfahre und blieb damit in diesem Context gefangen. NcModal (der „Neue Buchung"-Dialog, `z-index: 9998`) entkommt dem, weil `@nextcloud/vue` seine Modals an `document.body` teleportiert. Praktisch: Auf dem Handy ließ sich beim Anlegen einer Buchung **weder Kategorie noch Geldkonto auswählen**, sobald der Buchungsdialog offen war – der zentrale Erfassungsweg (Uwe) war blockiert. | Uwe | **Behoben (0.22.3).** `<teleport to="body">` wäre die saubere Lösung, wird von der hier verwendeten `vue-loader@15`-Toolchain aber nur als wirkungsloses literales DOM-Element durchgereicht (siehe Projekt-Memory „Vue `<teleport>` wirkungslos") – stattdessen manuelles Portal-Pattern (`mounted()` hängt `this.$el` an `document.body`, `beforeDestroy()` entfernt es wieder; Root-Element von `v-if` auf `v-show` umgestellt, damit `$el` über die Komponenten-Lebensdauer stabil bleibt). Verifiziert per `elementFromPoint` an der realen Sheet-Item-Position **und** echtem `Input.dispatchMouseEvent` (nicht nur `.click()`) – Auswahl, Suche und vollständiger Buchungsvorgang laufen jetzt durch. | `src/components/AccountPickerSheet.vue` |
| 2 | niedrig | Auf einer wirklich leeren Instanz (0 Konten, direkt nach „Alle Daten löschen") zeigt die Einrichtungs-Checkliste „Sphären zuordnen (steuerlich)" bereits als **erledigt** an, obwohl noch gar keine Konten existieren – `this.accounts.filter(...).every(a => a.sphere)` ist auf einer leeren Liste vacuously `true`. Sobald der erste Kontenrahmen angelegt wird, korrigiert sich die Anzeige von selbst (verifiziert). Bis dahin ist der Haken irreführend für jemanden wie Jürgen, der die Checkliste von oben nach unten abarbeitet. | Jürgen | Vorschlag (nicht umgesetzt): In `steps` für `spheres` zusätzlich `this.accounts.length > 0` verlangen, analog zu den anderen Schritten. | `src/App.vue` (Checklisten-`steps`-Definition, Zeile ~64 laut Kommentarblock) |
| 3 | niedrig | Die Fehlerbegründungs-Datei im Wachordner (`<Datei>.fehler.txt`) mischt Deutsch und Englisch: „Diese Datei konnte … nicht eingelesen werden: The file format was not recognized. Supported are CSV-CAMT, CAMT.053 (XML), and MT940 …". Für die Ehrenamts-Zielgruppe (unübersetzte Ausnahme-Message eines Parsers, direkt in die für Menschen gedachte Begründungsdatei durchgereicht) unnötig verwirrend, funktional aber unproblematisch. | Frank | Vorschlag (nicht umgesetzt): Den Format-Hinweis-Satz in `WatchFolderService`/`StatementParserRegistry` auf Deutsch formulieren, statt die rohe Parser-Exception-Message durchzureichen. | `lib/Service/WatchFolderService.php`, `lib/Service/Statement/StatementParserRegistry.php` |

### Nicht erlaufene Punkte (Testdaten- bzw. Werkzeuggrenzen, kein Hinweis auf Bugs)

- **Frank, 4.2 Punkte 1–4** (xbuc-Mehrjahresimport, Geschäftsjahr-Prüfung,
  Anfangsbestände-Erkennung): Im Repo liegt keine `.xbuc`-Testdatei; eine
  synthetische Datei aus dem Parser-Code zurückzukonstruieren hätte nur
  meine eigene Format-Annahme getestet, nicht das echte Verhalten mit
  Dateien aus „zero Buchhaltung". Punkte 5–7 (Bankauszugsformate, Wachordner,
  formatübergreifende Dublettenerkennung) wurden mit echten Fixtures voll
  bestätigt. Für einen künftigen Durchlauf: eine kleine, reale `.xbuc`-Datei
  (ein bis zwei Jahre, wenige Konten/Buchungen) als Fixture ergänzen.
- **Renate, 4.4**: Alle Punkte inkl. der API-Gegenprobe (403 auf
  `POST /api/journal`, `/api/rules`, `/api/sepa/fees`, `/api/accounts`) sind
  vollständig bestätigt – keine Einschränkung.
- **Petra, 4.7 Punkt 1**: Die Zuordnung selbst (Gruppen-Rolle, höhere Rolle
  gewinnt in beide Richtungen, NC-Admin-Sonderfall) ist über die API voll
  verifiziert – dieselbe API, die `SettingsPermissions.vue::savePermission()`
  aufruft. Das reine Bedienen des `NcSelect`-Dropdowns zur Nutzer-/
  Gruppenauswahl ließ sich mit synthetischen DOM-Events nicht auslösen
  (vue-multiselect erwartet echte Tastatureingaben); ein Werkzeug-, kein
  App-Befund.

## 6. Was bereits gut funktionierte (nicht angefasst)

Für künftige Reviews zur Abgrenzung, was ausdrücklich geprüft und für gut
befunden wurde:

- **Setup-Assistent & Ersteinrichtung** (Jürgen): alle drei Wege, Beispielverein
  inkl. Zurücksetzen, Erste-Buchung-Tour (erscheint einmalig, bleibt nach
  Reload weg), Checkliste-Sprungmarken.
- **Statement-Import** (Frank/Thomas): CSV-CAMT/CAMT.053/MT940 werden korrekt
  erkannt, IBAN-basierte Geldkonto-Zuordnung über mehrere Bankkonten hinweg,
  formatübergreifende Dublettenerkennung, Wachordner-Verzeichnisse
  (`verarbeitet/`/`fehler/`) korrekt befüllt.
- **Zuordnung & Regeln** (Thomas): Verlaufs-basierter Vorschlag, Regel-Anlage
  per Blitz-Button, Splittbuchung (Geldkonto bleibt eine Zeile).
- **Kostenstellen & Sphären** (Thomas): freie Kostenstellen mit
  Mehrfachzuweisung, Freigrenzen-Warnung exakt bei 94 % ausgelöst.
- **Rollen & Rechte** (Renate/Markus/Petra): Revisor ist serverseitig
  vollständig schreibgeschützt (nicht nur UI-versteckt), „Kein Zugriff" für
  Nutzer ohne Rolle greift ebenso serverseitig, Gruppenrollen und deren
  Zusammenspiel mit individuellen Rollen (höhere gewinnt) funktionieren in
  beide Richtungen, NC-Admins sind ohne expliziten Eintrag Verwalter.
- **Kollaboration** (Petra): optimistisches Locking verhindert stilles
  Überschreiben mit der exakt dokumentierten Konfliktmeldung, Polling
  aktualisiert bei Fensterfokus mit der exakt dokumentierten Meldung.
- **Jahresabschluss** (Hannelore): Abschließen sperrt PUT/DELETE/POST im
  betroffenen Jahr mit HTTP 423 und verständlicher Meldung, offene Jahre
  bleiben unberührt buchbar, Wiedereröffnen protokolliert und mit rotem
  Bestätigungsdialog.
- **Berichte** (Hannelore): Kassenbericht und Kurzbericht mit allen
  dokumentierten Abschnitten (inkl. Vollständigkeitszeile, Freigrenzenhinweis,
  Corporate Design aus den Einstellungen), Finanzplan-Snapshot-Vergleich,
  Rücklagen-Bericht nach Art, Mehrjahresübersicht als CSV und Diagramm.
- **Mobile Bedienung** (Uwe): Bottom-Navigation ab 640 px, große Eingabefelder
  in der Schnellerfassung, Kamera-Direktzugriff fürs Belegfoto, Karten-Layout
  in Journal/Kostenstellen/Konten; die Auswahl-Sheets für Konto/Kategorie
  waren zunächst hinter dem Buchungsdialog unbedienbar (Befund 1), seit
  0.22.3 behoben und end-to-end nachverifiziert.
