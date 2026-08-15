# Changelog

Alle nennenswerten Änderungen an dieser App werden hier dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

Der App Store zeigt zu jedem Release den hier passenden Abschnitt an – zu jeder
veröffentlichten Version muss es daher eine Überschrift `## [x.y.z]` geben.

## [Unreleased]

## [0.23.0] – 2026-08-15

### Geändert
- **Frontend auf Vue 3 migriert**: Vue 2.7 → 3.5, `@nextcloud/vue` 8 → 9,
  `@nextcloud/dialogs` 5 → 7, `vue-loader` 15 → 17. Build bleibt Webpack
  (`@nextcloud/webpack-vue-config` 6 → 7, unterstützt Vue 3 nativ); Options
  API und die bestehenden Composables (`reactive()`-Singletons) bleiben
  unverändert. Vue 2 ist seit 31.12.2023 End-of-Life, `@nextcloud/vue` 8.x
  bekommt zwar noch Patches, aber keine neuen Funktionen mehr.
- **Nextcloud-Mindestversion auf 31 angehoben** (vorher 28), passend zum
  harten Boden von `@nextcloud/vue` 9. Kostet real nichts – NC 28–31 sind
  sämtlich End-of-Life, gewartet sind nur noch 32–34. `max-version` auf 35
  angehoben, PHP-Obergrenze auf 8.5 (von NC 33/34 unterstützt).
- **ESLint 8 → 10** mit Flat Config (`eslint.config.mjs` statt
  `.eslintrc.cjs`), `@nextcloud/eslint-config` 8 → 9.
- Tote Frontend-Abhängigkeiten entfernt: `vue-router` und `vue-chartjs`
  standen in `package.json`, wurden aber nirgends importiert.

### Behoben
- **Button-Beschriftungen nicht mehr zentriert**: ein `:deep()`-Override aus
  der `@nextcloud/vue`-8-Zeit erzwang `.button-vue__icon { display: flex
  !important }` und verhinderte damit, dass `NcButton` v9 das Icon-Element
  bei textonly-Buttons per `:empty { display: none }` korrekt kollabiert –
  die leere Icon-Box schob den Text sichtbar nach rechts. Override ersatzlos
  entfernt (`App.vue`, `RulesPanel.vue`), v9 zentriert von sich aus richtig.
- **Berichte → Kostenstellen/Sphären nicht scrollbar, ohne Rand**: die
  Split-Ansicht (`.vbh-splitinner`) und das darunterliegende Pflegepanel
  (`CostCenterPanel`/`SphereAssignPanel`) lagen als Flex-Geschwister in
  einer Reihe statt untereinander und quetschten die Split-Ansicht auf
  ~1px Breite. `.vbh-sectionbody.is-split` bekommt `flex-direction: column`
  und `.vbh-splitinner` eine Mindesthöhe, damit beides untereinander steht
  und die Sektion als Ganzes scrollt (wie im Mobile-Breakpoint, der aus
  demselben Grund längst auf `display: block` umschaltet).
- Die beiden Notlösungen, die durch die Vue-2.7-Toolchain nötig waren, sind
  mit Vue 3 hinfällig geworden: `<teleport>` wird jetzt korrekt kompiliert
  (betraf `AccountPickerSheet.vue`, lief bisher über ein manuelles
  `mounted()`/`beforeUnmount()`-Portal-Pattern, siehe 0.22.3), und
  `NcAppSettingsDialog` könnte nun grundsätzlich wieder verwendet werden
  (bisher `NcModal` als Ersatz, siehe Kommentar in `App.vue`) – beides nicht
  in diesem Release umgesetzt, nur die Blockade entfernt.

## [0.22.4] – 2026-08-15

### Geändert
- **App-Store-Beschreibung aktualisiert**: Das seit 0.20.0 bestehende
  Beiträge-&-SEPA-Modul (Mitgliederverwaltung, Lastschriftmandate,
  Sammeleinzug, eigener Reiter) fehlte dort drei Releases lang komplett.
  Ergänzt, außerdem CAMT.053/MT940 und der Wachordner beim
  Kontoauszug-Import – bisher war dort nur CSV-CAMT genannt.

## [0.22.3] – 2026-08-15

### Behoben
- **Konto-/Kategorie-Auswahl auf dem Handy war unbedienbar**: Das
  Bottom-Sheet zur Kontoauswahl (`AccountPickerSheet.vue`) rendete zwar in
  den DOM, blieb aber unsichtbar und unklickbar hinter dem Buchungsdialog –
  Nextclouds eigenes Core-CSS (`#content { position: fixed }`) erzeugt einen
  Stacking-Context, in dem das Sheet trotz hohem `z-index` gefangen war,
  während `NcModal` (der Buchungsdialog) dem entkommt, weil `@nextcloud/vue`
  seine Modals an `document.body` teleportiert. Das Sheet tut das jetzt auch
  – per manuellem Portal-Pattern (`mounted()`/`beforeDestroy()` hängen das
  Element an `document.body`), da `<teleport>` von der hier verwendeten
  `vue-loader@15`-Toolchain nicht kompiliert wird. Gefunden im
  Gesamt-App-Review, siehe APP-REVIEW.md.

## [0.22.2] – 2026-08-15

> In `appinfo/info.xml` direkt von 0.21.0 auf 0.22.1 gesprungen und dann auf
> 0.22.2 weitergezählt, ohne dass 0.22.0/0.22.1 je getaggt oder veröffentlicht
> wurden – dieser Abschnitt fasst beide Zwischenstände zu einem einzigen,
> tatsächlich veröffentlichten Release zusammen.

### Neu
- **Standard-Beitrag** (Zahnrad → Beiträge & SEPA): ein einmal hinterlegter
  Betrag/Frequenz füllt „Mitglied aufnehmen" vor und greift auch im
  CSV-Import, wenn eine Zeile ein Start-Datum, aber keinen eigenen Betrag hat
  – bei 80–100 Mitgliedern mit demselben Satz sonst 80–100 Mal derselbe Wert
  von Hand.

### Geändert
- **Navigation aufgeräumt.** Das Zahnrad-Menü „Einstellungen & Import" hatte
  über die Entwicklungszeit 16 Abschnitte angesammelt – Einrichtung und
  laufende Arbeit ungetrennt nebeneinander. Neu:
  - **Reiter „Beiträge"** (Hauptnavigation, erscheint automatisch sobald das
    Modul genutzt wird): Mitgliederliste und SEPA-Sammeleinzug, vorher zwei
    Abschnitte im Einstellungen-Modal. Kennzahl im Reiter zeigt Beiträge im
    Rückstand.
  - **Unterreiter „Regeln"** (Tab Buchungen): Auto-Zuordnungsregeln, dort wo
    sie auch entstehen (Blitz-Button beim Zuordnen).
  - **Kostenstellen- und Sphären-Pflege** jetzt direkt in den gleichnamigen
    Berichten statt im Zahnrad – inklusive des Kostenstellen-Modus.
  - Das Zahnrad selbst fasst jetzt nur noch sieben statt 16 Abschnitte
    (Verein, Belege, Bankdaten, Beiträge & SEPA, Berechtigungen,
    Jahresabschluss, Daten). Der Wegweiser-Hinweis „Kontoumsätze
    importieren" darin entfällt ersatzlos – der Import steht seit 0.19 im
    Tab „Buchungen".
  - Keine Datenbank-Migration nötig; bestehende Installationen mit
    Mandaten/Beiträgen sehen den neuen Reiter sofort.
- **Import-Verlauf konsolidiert.** Die Liste „Bisherige CSV-Importe" unter
  Einstellungen → Daten zeigte dieselben Angaben (Dateiname, neu, Dubletten)
  wie das Änderungsprotokoll ein zweites Mal – beide wurden bei jedem Import
  parallel beschrieben. Die Liste entfällt; im Protokoll (Berichte →
  Protokoll) blendet der neue Schnellfilter „nur Importe" alles andere aus.
  Die dafür genutzte Tabelle `vbh_imports` wird per Migration entfernt.
- **Buchhalter darf Mitglieder, SEPA-Mandate und den Einzug jetzt vollständig
  verwalten** (vorher Verwalter-only) – die naheliegende Rollenzuweisung für
  eine Kassiererin ohne Rechtevergabe-Befugnis war sonst vom ganzen Reiter
  „Beiträge" ausgesperrt.
- **Mitgliederliste und Einzug zeigen auf dem Handy Karten statt einer
  siebenspaltigen Tabelle**, die dort zeilenweise Buchstabe für Buchstabe
  umbrach.
- **Aktionsspalte der Mitgliederliste verdichtet.** Seltene Aktionen
  (Bankverbindung wechseln, Mandat widerrufen, Löschen) stecken jetzt in
  einem Menü, „Bearbeiten" bleibt sichtbar – auch am Desktop ragte die Spalte
  vorher über den sichtbaren Bereich hinaus.

### Behoben
- **E-Mail-Adressen mit Umlaut im lokalen Teil** („m.müller@gmx.de") galten
  beim Anlegen eines Mandats und beim CSV-Import als ungültig – betraf reale,
  bei gmx.de/web.de/t-online.de zustellbare Adressen.
- **Bestätigungsdialoge für „Mitglieder übernehmen" und „Rückstand
  nachholen"** zeigten einen roten „Löschen"-Button statt einer zum Anlegen
  passenden Beschriftung.
- Der Reiter „Beiträge" blitzte beim Laden der App kurz auf, bevor er
  endgültig erschien.

Details und die zugrundeliegenden Personas/Testfälle siehe
`BEITRAEGE-REVIEW.md`.

## [0.21.0] – 2026-08-13

### Neu
- **Mitglieder und Beiträge in einer Ansicht** (Einstellungen → Mitglieder und
  Beiträge). Bankverbindung und Beitrag werden in einem Schritt angelegt statt
  wie bisher in zwei getrennten Formularen mit doppelter Auswahl des Zahlers.
  Dazu Suchfeld, Filter „nur Auffälligkeiten", Anzeige des Beitragsaufkommens
  im Jahr – gedacht für Vereine und Chöre mit dreistelliger Mitgliederzahl.
- **Mitgliederliste als CSV einlesen.** Erst ein Prüflauf, der je Zeile zeigt,
  was entstehen würde und was nicht stimmt, dann das Anlegen. Spaltennamen
  dürfen deutsch oder englisch sein, in beliebiger Reihenfolge; unbekannte
  Spalten werden übergangen. Eine Vorlage lässt sich herunterladen.
- **Einzug als ausgeführt verbuchen.** Ist das Geld eingegangen, werden alle
  zugehörigen offenen Posten in einem Schritt als bezahlt geschlossen. Bisher
  endete der Ablauf bei der heruntergeladenen XML-Datei, und jeder Posten
  musste einzeln von Hand geschlossen werden.
- **E-Mail-Adresse am Mandat.** Die gesetzlich vorgeschriebene Vorankündigung
  ging bisher nur an Zahler mit Nextcloud-Konto und dort hinterlegter Adresse –
  in den meisten Vereinen also an fast niemanden. Die Ankündigung nennt jetzt
  außerdem die Gläubiger-Identifikationsnummer.
- **Beitragsrückstand sichtbar**, mit Knopf zum Nachholen. Ein rückwirkend
  angelegter Monatsbeitrag brauchte bisher einen Tageslauf je Periode, ohne
  dass irgendwo stand, dass noch etwas aussteht.

### Behoben
- Nach einer **Rücklastschrift** galt das Mandat weiterhin als eingelöst: ein
  zurückgegebener Ersteinzug wurde beim nächsten Mal als Folgeeinzug (RCUR)
  eingereicht statt erneut als FRST. Manche Institute weisen das zurück.
- Eine **Rücklastschrift zu einem bereits abgeschlossenen Einzug** wird jetzt
  ebenfalls erkannt; die Rückgabe trifft regelmäßig erst nach dem Geldeingang
  ein. Der betroffene offene Posten wird dabei wieder geöffnet.
- Der **Mitglieder-Import** lehnt negative Beträge ab, statt sie stillschweigend
  als Forderung zu übernehmen.

### Intern
- Der Erzeuger der pain.008-Datei arbeitet nicht mehr auf der Datenbank-Entität,
  sondern auf einem Wertobjekt. Dadurch prüfen Unit-Tests die erzeugte Datei
  jetzt gegen das amtliche Schema der Deutschen Kreditwirtschaft – die bislang
  ungetestete, aber formatkritischste Stelle des Moduls.

## [0.20.0] – 2026-08-12

### Neu
- **SEPA-Lastschrift und Mitgliedsbeiträge** – ein optionales Zusatzmodul für
  Vereine, die Beiträge einziehen. Wer es nicht braucht, merkt nichts davon:
  ohne angelegtes Mandat bleibt alles wie bisher.
  - **Lastschriftmandate** (Einstellungen → SEPA-Lastschriftmandate): je Mandat
    ein Zahler, eine IBAN und ein Unterschriftsdatum. Zahler ist entweder ein
    Nextcloud-Konto oder ein frei eingetragener Name – letzteres für Verbände,
    die Beitragsanteile von Untergliederungen einziehen. Mandate werden
    widerrufen, nicht gelöscht, damit erzeugte Einreichungen nachvollziehbar
    bleiben.
  - **Mitgliedsbeiträge mit Zahlungsfrequenz** (monatlich bis jährlich): bei
    Fälligkeit legt die App automatisch einen offenen Posten an – mit oder ohne
    Mandat. Ohne Mandat ist der Posten schlicht eine Erinnerung an eine
    erwartete Überweisung.
  - **SEPA-Sammeleinzug** als pain.008-Datei zum Einreichen bei der Hausbank,
    mit Vorschau der fälligen Posten und der Möglichkeit, einen falsch
    erzeugten Einzug wieder zu verwerfen.
  - **Vorankündigung per E-Mail** an Mitglieder mit hinterlegter Adresse, wie
    vom SEPA-Regelwerk verlangt. Zahler ohne Adresse werden vermerkt, damit der
    Verein sie selbst informieren kann.
  - **Rücklastschriften** werden beim Kontoauszugs-Import erkannt und öffnen den
    zugehörigen offenen Posten wieder. Eine Fehlzuordnung lässt sich zurücknehmen.

  Vor dem ersten echten Einzug die erzeugte Datei mit dem Prüftool der Hausbank
  gegentesten – das genaue Format weicht je nach Bank leicht ab.

## [0.19.3] – 2026-08-06

### Geändert
- **Berichte-Seite: Reiter und Buttons wirkten überladen.** Im Auswertung-Tab
  brachen bis zu 7 Export-Buttons (Kassenbericht, Kurzbericht, Beleg-ZIP,
  Prüfleitfaden, Saldenliste, E/A-Übersicht, Mehrjahresübersicht) neben den 6
  Reitern mehrzeilig um und nahmen viel Platz ein. Kassenbericht und
  Kurzbericht – laut Handbuch die beiden meistgenutzten Berichte – bleiben
  direkt anklickbar, die übrigen fünf stecken jetzt in einem Menü „Weitere
  Exporte".

## [0.19.2] – 2026-08-06

### Behoben
- **Kassenbericht und Kurzbericht erschienen auf Englisch, obwohl die
  Nextcloud-Oberfläche auf Deutsch stand.** Nextclouds `L10NFactory` hält eine
  Sprache nur dann für „verfügbar", wenn im `l10n/`-Ordner der App eine Datei
  dazu liegt – für Deutsch gab es bisher keine, weil es ja die Quellsprache
  des Codes ist und `l10n/de.json` daher als überflüssig galt. Ohne diese
  Datei überspringt `findLanguage()` die deutsche Spracheinstellung des
  Nutzers und landet beim harten Fallback Englisch. Betroffen waren nur die
  serverseitig gerenderten Berichte (`IL10N::t()` direkt im PHP); die
  Vue-Oberfläche hat ihr eigenes, clientseitiges Übersetzungssystem und war
  nicht betroffen. Eine leere `l10n/de.json` reicht als Fix: sie macht
  Deutsch für Nextcloud „bekannt", ohne dass echte Übersetzungen nötig wären.

## [0.19.1] – 2026-08-05

### Geändert
- **Buchungsjournal: Zeilen brachen durch bis zu 4 Aktions-Icons zweizeilig
  um.** „Regel anlegen" und „Löschen" stecken jetzt in einem Menü-Button;
  Beleg (falls vorhanden) und „Bearbeiten" bleiben direkt anklickbar. Damit
  passt jede Zeile wieder auf eine Zeile.

## [0.19.0] – 2026-08-05

### Behoben
- **Vorgemerkte Umsätze im CSV-Import konnten zu Dubletten führen.** Die
  CSV-CAMT-Kontoauszüge führen anders als CAMT.053 keinen eigenen
  Statuscode, sondern schreiben den Buchungsstand als Klartext in die Spalte
  „Info" – „Umsatz vorgemerkt" wurde bislang nicht ausgewertet. Ändert sich
  ein solcher Umsatz beim endgültigen Buchen (typischerweise das Datum),
  erkannte ihn ein späterer, sich überschneidender Import nicht mehr als
  bereits vorhanden. `CamtCsvParser` überspringt vorgemerkte Zeilen jetzt wie
  schon `Camt053Parser` es für „PDNG" tut.

### Hinzugefügt
- **Nicht zugeordnete Bankumsätze lassen sich jetzt löschen.** Bisher gab es
  dafür keinen Weg – ein Umsatz, den die Dublettenerkennung bei einem sich
  überschneidenden Kontoauszugs-Import nicht als bereits vorhanden erkannt
  hat, ließ sich weder zuordnen (dann läge er doppelt im Journal) noch wieder
  loswerden. Der Papierkorb-Knopf in „Zuzuordnen" löscht einen einzelnen,
  noch nicht verbuchten Umsatz; bereits zugeordnete bleiben wie gehabt nur
  über den zugehörigen Buchungssatz löschbar.

## [0.18.0] – 2026-08-02

### Hinzugefügt
- **Weitere Mehrsprachigkeit im Backend.** Kassenbericht und Kurzbericht
  (beide als druckfertige HTML-Seite) sowie das Änderungsprotokoll
  (Berichte → Protokoll) erscheinen jetzt in der Sprache des
  Nextcloud-Profils statt fest auf Deutsch.
- Alle bisher noch deutschen Fehler- und Bestätigungsmeldungen der
  Controller sind übersetzt, ebenso die Prüfmeldungen beim Anlegen und
  Ändern von Buchungen (Soll-Haben-Kontrolle) und die Fehlermeldungen der
  CAMT-/MT940-/CSV-Kontoauszugs-Parser.

### Technisch
- Statische Prüfmethoden (`JournalService::validateLines()`,
  `JournalService::reassignPlan()`, `BookingService::validateParts()`), die
  auch direkt aus PHPUnit-Tests ohne laufende Nextcloud-Instanz aufgerufen
  werden, liefern jetzt stabile Fehlercodes statt fertigem Text; die
  Übersetzung passiert in einer begleitenden Instanzmethode mit Zugriff auf
  `IL10N`. Die Parser (`CamtCsvParser`, `Camt053Parser`, `Mt940Parser`,
  `XbucParser`) erhalten `IL10N` als optionalen, in Tests weglassbaren
  Konstruktorparameter mit deutschem Fallback-Text.

### Bekannte Lücken
- Der CSV-Export der Auswertungen ist noch vollständig Deutsch.

## [0.17.0] – 2026-08-02

Der erste sichtbare Schritt zur Mehrsprachigkeit: wer Nextcloud auf Englisch
eingestellt hat, sieht jetzt eine englische Oberfläche statt einer deutschen
mit englischen Brocken drumherum.

### Hinzugefügt
- **Englische Übersetzung.** Alle Vue-Komponenten der Oberfläche – jeder Tab,
  jeder Dialog, jede Fehler- und Bestätigungsmeldung – sowie die häufigsten
  Fehlermeldungen des Backends sind jetzt ins Englische übersetzt
  (`l10n/en.json`). Einen eigenen Sprachschalter braucht die App dafür nicht:
  wer sein Nextcloud-Profil auf Englisch stellt, bekommt automatisch die
  englische Fassung. Die Quelltexte im Code bleiben bewusst Deutsch – der
  Herkunftssprache der App –, Deutsch ist also unverändert die Fallback-Sprache.
- **Video in der App-Store-Beschreibung verlinkt.**

### Technisch
- Eigener Lademechanismus für Übersetzungen im Frontend (`src/lib/l10n.js`):
  die Bibliothek `@nextcloud/l10n` geht davon aus, dass Englisch die
  Quellsprache ist, und lädt dafür gar kein Übersetzungsbundle – bei einer
  deutschen Quellsprache wäre das genau die falsche Sprache.
- `IL10N` ist jetzt per Konstruktor in die Controller und Services injiziert,
  die nutzerseitige Fehlermeldungen werfen.

### Bekannte Lücken
- Kassenbericht, Kurzbericht, Prüfleitfaden und die CSV-Exporte sind noch
  vollständig Deutsch.
- Ein paar Backend-Prüfungen (u. a. die Soll-Haben-Kontrolle beim Buchen, die
  CAMT-/MT940-Parser) bleiben vorerst Deutsch – sie sind bewusst ohne
  Nextcloud-Abhängigkeit gehalten, damit sie sich ohne laufende Instanz testen
  lassen.
- Das Änderungsprotokoll (Berichte → Protokoll) zeigt seine Einträge
  weiterhin auf Deutsch.

## [0.16.0] – 2026-08-01

Diese Version bringt kaum sichtbare Neuerungen: sie räumt den Programmcode auf
und sichert ihn gegen Fehler ab, die bisher niemand bemerkt hätte. Ein solcher
Fehler kam dabei ans Licht – und er betraf ausgerechnet die Kassenprüfung.

### Behoben
- **Der Beleg-Export als ZIP brach ab, sobald ein Beleg im Zeitraum lag.** Die
  Funktion rief eine Methode auf, die es in PHP gar nicht gibt
  (`ZipArchive::addStream`); der Download endete mit einem Serverfehler statt
  mit einem Archiv. Betroffen war genau die Funktion, die eine Kassenprüfung
  braucht, um alle Belege eines Jahres auf einmal zu bekommen. Die Belege
  werden jetzt blockweise ins Archiv geschrieben – der Speicherbedarf bleibt
  wie vorgesehen niedrig, auch bei einem Jahr voller PDFs.
- **Der Kassenbericht ignorierte die dunkle Darstellung.** Wer ihn bei dunklem
  Systemdesign öffnete, bekam schwarze Schrift auf weißem Grund in einem sonst
  dunklen Fenster – der Kurzbericht konnte es längst. Beide Berichte teilen
  sich jetzt dieselbe Gestaltung. Am Ausdruck ändert sich nichts.
- **Beispieldaten konnten halb angelegt liegenbleiben.** Fehlte ein Konto des
  Standard-Kontenrahmens, brach das Anlegen mitten in den Buchungen ab. Jetzt
  meldet die App vorher, welches Konto fehlt.

### Geändert
- **Die Rechenregeln aller Auswertungen liegen jetzt an einer Stelle.** Die
  Frage, welches Konto mit welchem Vorzeichen ins Ergebnis eingeht, war an acht
  Stellen einzeln ausgeschrieben – in den Exporten, in der Saldenliste und in
  den Berichten. Eine Änderung daran musste bisher überall nachgezogen werden.
  An den Zahlen ändert sich nichts; sie sind jetzt nur an einer Stelle
  festgelegt und erstmals automatisiert geprüft.
- **Der Sortier-Vergleich der Tabellen war dreifach vorhanden** und ist es nun
  einmal. Sortierung und Sicherheitsabfragen sind damit in allen Ansichten
  garantiert gleich.

### Technisch
- Statische Analyse (PHPStan, Stufe 5) läuft jetzt bei jeder Änderung mit und
  fand die drei oben genannten Fehler.
- Der 1149 Zeilen lange `ExportController` ist in Dienste aufgeteilt: CSV,
  Beleg-Archiv und die druckfertigen Berichte je für sich, der Controller
  behält nur die Auslieferung (158 Zeilen).
- Frontend-Unit-Tests (Vitest) für die Rechen- und Anzeigehelfer; der
  Testbestand wächst von 118 auf 210 Tests.

## [0.15.0] – 2026-07-31

### Behoben
- **Gelöschte Buchung gab ihren Bankumsatz nicht wieder frei.** Wurde ein
  Buchungssatz gelöscht, der durch das Zuordnen eines Bankumsatzes entstanden
  war, blieb der Umsatz auf „zugeordnet" stehen – mit einem Verweis auf einen
  Buchungssatz, den es nicht mehr gab. Er tauchte danach nirgends mehr auf:
  nicht unter *Zuzuordnen* (dort stehen nur offene Umsätze) und nicht in den
  Salden (die kennen nur Buchungen). Auch die Bank-Abstimmung führte ihn weder
  als gebucht noch als offen, sodass der Kontostand still vom Bankauszug abwich.
  Der Umsatz steht jetzt nach dem Löschen wieder unter *Zuzuordnen* und lässt
  sich neu zuordnen – derselbe Stand wie beim Aufheben einer Zuordnung.
- **Belege eines Jahres konnten beim xbuc-Import verloren gehen.** Die
  Dublettenprüfung des Zusammenführ-Imports beschrieb eine Splittbuchung nur
  über eine ihrer Zeilen. Eine eingehende Buchung, die zufällig auf diesen
  Teilbetrag passte, galt dadurch als bereits vorhanden und wurde
  stillschweigend übersprungen. Der Vergleich betrachtet jetzt die ganze
  Buchung, und beide Seiten des Vergleichs bilden ihren Schlüssel an derselben
  Stelle.
- **Beleg löschen konnte einen Datensatz ohne Datei hinterlassen.** Die Datei
  wurde vor dem Datensatz entfernt; schlug das Löschen des Datensatzes fehl,
  blieb ein Beleg zurück, der sich nicht mehr öffnen ließ. Jetzt gilt auch hier
  die Reihenfolge, die beim Löschen einer ganzen Buchung längst galt: erst der
  Datensatz, dann – nach erfolgreichem Abschluss – die Datei.

### Geändert
- **Der Jahresabschluss schützt jetzt auch die Konten-Stammdaten.** Bisher war
  ein festgeschriebenes Geschäftsjahr nur gegen Änderungen an den Buchungen
  gesichert. Über das Konto ließ sich sein Bericht trotzdem nachträglich
  verschieben: Ein Wechsel der Kontoart dreht das Vorzeichen, ein Wechsel des
  Geldkonto-Kennzeichens verschiebt das Konto zwischen Vermögensübersicht und
  Einnahmen-/Ausgaben-Rechnung – das archivierte Jahresergebnis änderte sich,
  ohne dass eine einzige Buchung angefasst wurde. Kontoart, Geldkonto-
  Kennzeichen, Sphäre, Rücklagen-Art und Kostenstelle lassen sich deshalb nicht
  mehr ändern, solange das Konto in einem abgeschlossenen Jahr bebucht ist; wer
  die Änderung braucht, eröffnet das Jahr wieder und schließt es danach erneut
  ab. Nummer, Name, Kategorie, Aktiv-Schalter und Überkonto bleiben frei
  änderbar – sie ändern nur Beschriftungen, keine Beträge.
- **Konten stehen jetzt im Änderungsprotokoll.** Anlegen, Ändern und Löschen
  eines Kontos wurden bisher nicht protokolliert – ausgerechnet die Stammdaten,
  aus denen jede Auswertung gerechnet wird, waren für die Kassenprüfung
  unsichtbar. Bei einer Änderung vermerkt das Protokoll zusätzlich, ob ein
  auswertungsrelevantes Feld betroffen war.

## [0.14.0] – 2026-07-31

### Hinzugefügt
- **Splittbuchungen.** Ein Betrag lässt sich jetzt auf mehrere Gegenkonten
  verteilen – für die Überweisung, die mehreres zugleich enthält: der
  Jahresbeitrag zusammen mit einer Spende, eine Rechnung, die auf zwei
  Kostenstellen gehört. Bisher blieb dafür nur, den Umsatz einem einzigen Konto
  zuzuschlagen oder ihn von Hand zerlegt nachzubuchen.
  Zwei Wege führen dorthin: im **Buchungsdialog** über den Schalter *Betrag
  aufteilen*, und – der häufigere Fall – direkt beim **Zuordnen eines
  Bankumsatzes** über *Aufteilen…*. In beiden Fällen bleibt das Geldkonto eine
  Zeile über den vollen Betrag; aufgeteilt wird die Gegenseite. Eine laufende
  Restanzeige zeigt, was noch fehlt, gespeichert wird erst, wenn die Aufteilung
  aufgeht. Im Experten-Modus lässt sich wählen, welche Seite aufgeteilt wird.
  Bestehende Splittbuchungen lassen sich genauso bearbeiten wie andere
  Buchungen. Alle Auswertungen – Kassenbericht, Kostenstellen, Sphären,
  Saldenliste – rechnen zeilenweise und weisen die Teilbeträge damit
  getrennt aus.
- **Konten auf inaktiv setzen.** Ein Konto, das wegen vorhandener Buchungen
  nicht gelöscht werden kann, lässt sich im Konto-Dialog auf *inaktiv* stellen:
  es verschwindet aus allen Auswahllisten, Beträge und Historie bleiben
  unverändert. Genau dazu riet die App bisher beim Löschversuch, ohne dass es
  dafür ein Bedienelement gab. Im Kontenbaum sind inaktive Konten
  gekennzeichnet und lassen sich jederzeit wieder aktivieren.

### Behoben
- Der **Journal-Export als CSV** wies bei einer Buchung über mehr als zwei
  Konten einen falschen Betrag aus – er behielt nur je ein Soll- und Habenkonto.
  Jetzt belegt eine solche Buchung mehrere Zeilen mit derselben Buchungsnummer,
  wie in einem Journal üblich. Zweizeilige Buchungen exportiert die App
  unverändert.

### Sonstiges
- Eine Buchung ist intern nun eine Liste von Zeilen; „Soll an Haben" ist der
  zweizeilige Sonderfall davon. Die Prüfung (`JournalService::validateLines()`)
  ist wie das Umbuchen eine reine Funktion ohne Datenbank und damit prüfbar –
  neu sind 26 Unit-Tests dafür.
- Ein aufgeteilt zugeordneter Umsatz trägt kein einzelnes Gegenkonto mehr und
  geht deshalb nicht in die Vorschläge für künftige Zuordnungen ein.

## [0.13.0] – 2026-07-31

### Hinzugefügt
- **Frei definierbare Kostenstellen.** Bisher leitete die App die Kostenstelle
  aus dem Kontenrahmen ab – entweder aus der zweiten Zahlengruppe der
  Kontonummer oder „ein Konto = eine Kostenstelle". Beides setzt einen
  bestimmten Kontenaufbau voraus. Jetzt lassen sich Kostenstellen unter
  *Einstellungen → Kostenstellen* frei anlegen und Konten ihnen ausdrücklich
  zuordnen; beliebige Konten können so zu einem Projekt zusammengefasst werden.
  Der neue Modus heißt *„Frei definierte Kostenstellen"* (Einstellungen →
  Kostenstellen-Modus, nur Verwalter). Die beiden bisherigen Modi bleiben
  unverändert Standard – bestehende Auswertungen ändern sich nicht.
  Die Zuordnung geht einzeln im Konto-Dialog oder für viele Konten auf einmal.
  Angelegte Kostenstellen erscheinen im Bericht auch ohne zugeordnetes Konto,
  damit eine fehlende Zuordnung sichtbar ist.
- **Umbuchen direkt im Kontoauszug.** Wer beim Durchblättern eines Kontos
  (Tab *Konten*) eine falsch zugeordnete Buchung findet, bucht sie an Ort und
  Stelle auf ein anderes Konto um – der Umweg über das Journal entfällt.
  Umbuchen lässt sich jede Seite der Buchung, also auch das Gegenkonto.
  Geändert wird ausschließlich die Kontozuordnung; Betrag, Datum, Beschreibung
  und die Gegenseite bleiben, sodass Soll und Haben nicht auseinanderlaufen
  können. Abgeschlossene Geschäftsjahre bleiben gesperrt, jede Umbuchung steht
  im Änderungsprotokoll, und eine zwischenzeitliche Änderung durch jemand
  anderen wird erkannt.

## [0.12.0] – 2026-07-30

### Hinzugefügt
- **Kontoauszüge in zwei weiteren Formaten:** neben der bisherigen CSV liest
  die App jetzt auch **CAMT.053 (XML)** und **MT940**. Welches Format
  vorliegt, erkennt sie am Inhalt der Datei – die Endung spielt keine Rolle.
  CAMT.053 ist die bessere Wahl, wo die Bank es anbietet: Vorzeichen, Datum
  und Zahlungsbeteiligte stehen dort eindeutig drin, statt aus
  Spaltenüberschriften erraten zu werden.
- **Wachordner:** Auf Wunsch liest die App Kontoauszüge selbstständig aus
  einem Nextcloud-Ordner ein (Einstellungen → *Kontoauszüge automatisch
  einlesen*). Den heruntergeladenen Auszug dort ablegen genügt; stündlich
  sieht ein Hintergrundjob nach. Verarbeitete Dateien wandern nach
  `verarbeitet/`, nicht lesbare mit Begründung nach `fehler/` – gelöscht wird
  nichts. Setzt System-Cron voraus. Das ersetzt das Hochladen von Hand, nicht
  das Herunterladen bei der Bank.
- Vorgemerkte Umsätze aus CAMT.053 werden übersprungen. Sie ändern sich beim
  endgültigen Buchen häufig noch und kämen sonst ein zweites Mal herein.
- **Mehrere Bankkonten werden unterschieden.** Am Geldkonto lässt sich die
  **IBAN** hinterlegen (Tab Konten → Konto bearbeiten, nur bei Geldkonten);
  beim Zuordnen einer Bankbuchung wählt die App daraufhin das Geldkonto, auf
  dem der Umsatz tatsächlich gebucht wurde. Bisher landete alles auf dem
  ersten Bankkonto des Kontenrahmens. Ohne hinterlegte IBAN – oder wenn der
  Auszug keine mitbringt – bleibt es beim bisherigen Verhalten. Wer mehrere
  Konten führt, sollte CAMT.053 oder MT940 exportieren: dort steht die IBAN
  verlässlich, in der CSV mancher Bank nur eine Kontonummer.

### Behoben
- **Derselbe Umsatz landet nicht mehr doppelt, wenn das Exportformat wechselt.**
  Die Dublettenerkennung hing am eigenen Konto, und das schreiben die Formate
  verschieden: die CSV mancher Bank führt dort nur eine Kontonummer, CAMT.053
  immer die volle IBAN. Zusätzlich zum bisherigen Vergleich prüft die App
  Umsätze jetzt über Datum, Betrag und Text gegen die bereits vorhandenen
  Bankbuchungen.

### Sonstiges
- Die Umsatzquellen liegen hinter einer gemeinsamen Schnittstelle
  (`lib/Service/Statement/`); Dublettenerkennung, Regeln und Import-Protokoll
  sind für alle Formate dieselben. Der MT940-Parser ist zugleich die
  Vorarbeit für einen späteren FinTS-Abruf, der genau dieses Format liefert.
- Das Import-Protokoll hält fest, aus welchem Format ein Import stammt;
  Geldkonten können ihre IBAN tragen (Migration 122).
- Unit-Tests für beide neuen Parser sowie ein Kreuztest, der denselben
  Kontoauszug in allen drei Formaten vergleicht (61 Tests).

## [0.11.2] – 2026-07-29

### Behoben
- **Beträge werden nicht mehr abgeschnitten.** In Tabellen hatte die
  Betragsspalte eine feste Breite von 100 px; längere Werte wie
  „28.798,68 €" wurden mit „…" gekürzt und ließen sich falsch lesen. Die
  Spalte wächst jetzt mit.
- **Mobil: „Buchungen" in der unteren Leiste war gekürzt** („Buchun…"). Die
  Einträge teilen sich die Breite jetzt nach Inhalt statt zu gleichen Teilen.
- **Mobil: zu kleine Tippziele.** Schaltflächen, Auswahlfelder und
  Eingabefelder waren teils nur 24–36 px hoch. Sie sind jetzt mindestens
  44 px – auch auf Tablets, die per Finger bedient werden, aber das
  Desktop-Layout bekommen.
- **Mobil: lange Kontonamen** im Kontenbaum wurden gekürzt und waren damit
  nicht mehr unterscheidbar; sie brechen jetzt um.
- **Mobil: das Ende langer Listen** lag unter dem schwebenden
  Buchungs-Knopf und war nicht lesbar.

### Sonstiges
- Ausführliche Beschreibung und Screenshots für den Nextcloud App Store.

## [0.11.1] – 2026-07-29

Erste im Nextcloud App Store veröffentlichte Fassung. Am Programm selbst
ändert sich gegenüber 0.11.0 nichts.

### Sonstiges
- Die App wird jetzt mit dem von Nextcloud ausgestellten Zertifikat signiert
  (`appinfo/signature.json`) und im App Store veröffentlicht. Das Paket zu
  0.11.0 war noch unsigniert und ist deshalb nur über ein direktes
  Deployment aus dem GitHub-Release installierbar.

## [0.11.0] – 2026-07-28

Ergebnis eines Code-Reviews. Schwerpunkt: Datenintegrität der Buchführung.

### Behoben
- **Buchungsnummern bleiben lückenlos.** Bisher hinterließ jede gelöschte
  Buchung eine dauerhafte Lücke in der Nummerierung, die Journal und
  Kassenbericht anschließend als „fehlende Nummern" bemängelten. Solange ein
  Geschäftsjahr offen ist, rücken die nachfolgenden Nummern jetzt automatisch
  auf; mit dem Jahresabschluss werden sie endgültig festgeschrieben. Beim
  Update werden vorhandene Lücken einmalig geschlossen – bereits
  abgeschlossene Jahre bleiben dabei bewusst unangetastet (ihre Nummern
  stehen womöglich schon auf einem archivierten Kassenbericht).
- **Buchungsnummern können nicht mehr doppelt vergeben werden.** Zwei
  gleichzeitig gespeicherte Buchungen ermittelten dieselbe freie Nummer. Ein
  Unique-Index verhindert das jetzt; die zweite Buchung wird automatisch mit
  der nächsten Nummer wiederholt.
- **Buchungen entstehen und verschwinden nur noch vollständig.** Alle
  mehrstufigen Schreibvorgänge (Buchen, Ändern, Löschen, Bankzuordnung,
  Eröffnungssaldo, CSV- und xbuc-Import, Beispieldaten, Zurücksetzen) laufen
  in einer Datenbank-Transaktion. Ein Abbruch mittendrin – Zeitüberschreitung,
  Verbindungsabriss – konnte vorher eine einseitige, unausgeglichene Buchung
  hinterlassen.
- **Konten mit Buchungen lassen sich nicht mehr löschen.** Das Löschen
  hinterließ Buchungszeilen ohne Konto: deren Beträge verschwanden aus
  Saldenliste und Kassenbericht, blieben in der Datenbank aber stehen. Konten
  mit Buchungen oder Unterkonten werden jetzt mit einem erklärenden Hinweis
  abgelehnt (stattdessen: auf „inaktiv" setzen).
- **Buchungen prüfen ihre Eingaben.** Soll- und Habenkonto müssen existieren,
  das Datum muss ein gültiger Kalendertag zwischen 2000 und 2099 sein. Auch
  der CSV-Import verwirft nicht existierende Daten wie den 31. Februar.
- Beleg-Dateien werden erst gelöscht, wenn der zugehörige Datensatz
  tatsächlich verschwunden ist – ein Rollback hätte sonst die Buchung
  zurückgeholt, den Beleg aber nicht.
- **CSV-Exporte können keine Tabellenkalkulations-Formeln mehr einschleusen.**
  Ein Verwendungszweck stammt aus einer fremden Überweisung; beginnt er mit
  `=`, `+`, `-` oder `@`, führte Excel ihn beim Öffnen des Exports als Formel
  aus. Solche Felder werden jetzt als Text markiert – Beträge bleiben
  ausgenommen und damit rechenbar.
- **Mehrzeilige Verwendungszwecke im CSV-Import.** Ein Verwendungszweck darf
  Zeilenumbrüche enthalten, solange er in Anführungszeichen steht; solche
  Zeilen wurden bisher zerrissen und gingen verloren oder bekamen verschobene
  Spalten.
- Beim Markieren eines offenen Postens als bezahlt muss die verknüpfte Buchung
  existieren; bei der Rechtevergabe müssen Nutzer bzw. Gruppe existieren.
  Tippfehler erzeugten vorher stille Verweise ins Leere.

### Geändert
- **Das Änderungsprotokoll wird erst nach dem Commit geschrieben.** Ein
  abgebrochener Vorgang hinterlässt dadurch keinen Protokolleintrag mehr:
  festgehalten wird, was tatsächlich passiert ist.
- **SVG als Vereinslogo wird nicht mehr angenommen** (PNG, JPG und WebP
  bleiben). Ein SVG ist ein aktives Dokument und wäre unter der eigenen
  Nextcloud-Adresse ausgeliefert worden. Bereits hochgeladene SVG-Logos
  bleiben funktionsfähig, sollten aber ersetzt werden.
- **Belegablage:** der eingestellte Nextcloud-Nutzer muss existieren, der
  Ordnerpfad wird geprüft. Beim Zurücksetzen entfernt die App nur noch die
  ihr bekannten Beleg-Dateien statt des gesamten Ablageordners.
- Journal, Kontoauszug und die Exporte laden ihre Daten gebündelt statt je
  Buchung einzeln – bei größeren Beständen deutlich weniger Datenbankabfragen.
- **Der Beleg-Export als ZIP läuft nicht mehr über den Arbeitsspeicher.** Bei
  einem Jahr voller PDF-Belege konnte er vorher am `memory_limit` scheitern –
  ausgerechnet die Funktion, die für die Kassenprüfung gebraucht wird.

### Sonstiges
- CI-Workflow (`.github/workflows/ci.yml`): ESLint, Produktions-Build,
  PHP-Syntaxprüfung auf 8.1 und 8.4, PHPUnit und die info.xml-Schemaprüfung
  laufen jetzt bei jedem Push und Pull Request – nicht erst beim Release.
- `@mdi/js` als direkte Abhängigkeit eingetragen (war nur zufällig über
  `@nextcloud/vue` verfügbar).
- Unit-Tests für Nachnummerierung, Datumsprüfung und CSV-Formatierung ergänzt
  (33 Tests). Sie bringen einen eigenen Autoloader mit und laufen ohne
  `composer install`.
- Dialoge schreiben nicht mehr direkt in die Formularobjekte der Elternansicht,
  sondern melden Änderungen als Ereignis zurück – Voraussetzung für eine
  spätere Vue-3-Migration. Damit läuft ESLint jetzt vollständig ohne Ausnahmen.
- Rechteprüfungen können per Attribut an der Methode festgelegt werden, statt
  sie allein aus dem HTTP-Verb abzuleiten. Die besonders heiklen Endpunkte
  (Zurücksetzen, Jahresabschluss, Einstellungen, Logo, Beispieldaten) sind so
  gekennzeichnet.
- Der überflüssig gewordene Index `vbh_jrn_user_entry` entfällt (Migration 121).
- `composer.phar` ist nicht mehr Teil des Repositorys.

## [0.10.69] – 2026-07-28

### Behoben
- Drei Installations- und Anzeigefehler behoben

### Sonstiges
- Einführungsvideo in die README aufgenommen

## [0.10.68] – 2026-07-14

### Hinzugefügt
- **Mehrjahres-Trend** als Diagramm im Dashboard
- **Rücklagenverwaltung** nach § 62 AO: freie, zweckgebundene und
  Wiederbeschaffungsrücklage als kennzeichenbare Eigenkapital-Konten mit
  eigenem Bericht je Rücklagenart
- **Kurzbericht für Vorstandssitzungen** inklusive Corporate Design
- **Offene-Posten-Verwaltung**: Ad-hoc-Liste unbezahlter Forderungen mit
  Debitor, Betrag, Fälligkeit und Status (offen/bezahlt/storniert), inklusive
  Dashboard-Hinweis bei überfälligen Posten

### Behoben
- Race Condition beim Upsert von Berechtigungen
- Zusätzliche Diagnoseausgaben bei fehlgeschlagenen PATCH-Requests

### Dokumentation
- HANDBUCH.md um die vier neuen Präsidiums-Features ergänzt

## [0.10.63] – 2026-07-13

### Geändert
- Frontend modularisiert: die monolithische `App.vue` wurde in Composables
  (`useAuth`, `useYears`, `useAccounts`, `useBalances`, `useJournal`,
  `usePermissions`, `useSync`), Tab-Komponenten (Dashboard, Konten, Buchungen,
  Auswertungen), Dialoge (Konto, Buchung, Import) und Einstellungs-Panels
  zerlegt

### Behoben
- `toRefs`-Snapshot und Locale-Behandlung in `applySort` nach der
  Modularisierung korrigiert

## [0.10.0] – 2026-06-21

### Hinzugefügt
- Erste Veröffentlichung: CSV-CAMT-Import mit Dublettenerkennung,
  frei definierbarer Kontenrahmen, doppelte Buchführung (Soll/Haben, Journal),
  Zuordnung von Bankbuchungen und optionale Auto-Zuordnungsregeln

[Unreleased]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.16.0...HEAD
[0.16.0]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.15.0...v0.16.0
[0.15.0]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/87e535b...v0.15.0
[0.14.0]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.13.0...87e535b
[0.13.0]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.12.0...v0.13.0
[0.12.0]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.11.2...v0.12.0
[0.11.2]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.11.1...v0.11.2
[0.11.1]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.11.0...v0.11.1
[0.11.0]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.10.69...v0.11.0
[0.10.69]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.10.68...v0.10.69
[0.10.68]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.10.63...v0.10.68
[0.10.63]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.10.46...v0.10.63
[0.10.0]: https://github.com/AndiMb/nc_vereinsbuchhaltung/releases/tag/v0.10.17
