# Vereinsbuchhaltung – Nextcloud-App

**Deutsch** · [English](README.en.md)

Eine schlanke Buchhaltungs-App für Vereine, direkt in Nextcloud integriert. Kontenrahmen und Buchungen können aus einer **„zero Buchhaltung"-.xbuc-Datei** importiert werden, Kontoumsätze kommen als **CSV-CAMT, CAMT.053 oder MT940** von der Bank – wahlweise per Upload oder vollautomatisch aus einem überwachten Nextcloud-Ordner. Die App arbeitet nach den Regeln der **doppelten Buchführung** (Soll/Haben) mit frei definierbarem Kontenrahmen. Optional zieht sie Mitgliedsbeiträge per **SEPA-Lastschrift** ein – vom CSV-Massenimport für 80–100 Mitglieder bis zum fertigen pain.008-XML für die Bank.

## 🎬 Die App in zwei Minuten

[![App zur Vereinsbuchhaltung für Nextcloud – Video ansehen](https://img.youtube.com/vi/eaF-tAQ_OOM/maxresdefault.jpg)](https://youtu.be/eaF-tAQ_OOM)

**[App zur Vereinsbuchhaltung für Nextcloud](https://youtu.be/eaF-tAQ_OOM)** – Kontoauszüge importieren, Umsätze zuordnen, auswerten und den Kassenbericht für die Mitgliederversammlung erzeugen.

> 📖 **Einsteiger-Leitfaden:** Das beiliegende **[HANDBUCH.md](HANDBUCH.md)** führt Schatzmeisterinnen Schritt für Schritt durch die App – von der Ersteinrichtung über die laufende Buchung bis zum Jahresabschluss und der Kassenprüfung.

## Funktionsumfang

### Einstieg & Hilfe
- **Setup-Assistent** beim ersten Start (noch keine Konten vorhanden): drei Wege zur Auswahl – xbuc-Datei übernehmen, Standard-Kontenrahmen anlegen oder erst mit Beispieldaten ausprobieren
- **Beispielverein** (Verwalter): vollständiger Datenbestand zum gefahrlosen Ausprobieren, mit Hinweisbanner und Zurücksetzen-Knopf; „Alle Daten löschen" macht daraus wieder eine leere Buchhaltung
- **Einrichtungs-Checkliste** auf dem Dashboard: offene Schritte (Verein benennen, Kontenrahmen, Anfangsbestand, Berechtigungen, erste Buchung, Sphären) mit Direktsprung, ausblendbar
- **Hilfe im Programm**: Hilfe-Knopf im Header öffnet das passende Kapitel zum aktiven Tab; von dort führt ein Link ins beiliegende Handbuch, das die App selbst als lesbare Seite ausliefert (`/api/help/handbuch`) – auf Englisch, sobald die Nextcloud-Sprache der Nutzerin oder des Nutzers auf Englisch steht, sonst auf Deutsch
- **Erste-Buchung-Tour**: einmalige Drei-Schritte-Hervorhebung der Felder im Buchungsdialog (Desktop, Einfach-Modus)
- **Willkommenshinweis für Revisoren** beim ersten Login mit der Rolle, inkl. Prüfleitfaden zum Ausdrucken

### Import
- **xbuc-Import** (zero Buchhaltung): übernimmt Kontenbaum und alle Buchungen aus einer `.xbuc`-Datei
  - **Merge-Modus** (Standard): nur fehlende Konten werden angelegt, bereits vorhandene Buchungen werden per Fingerprint übersprungen – mehrere Jahres-Dateien lassen sich nacheinander importieren
  - **Geschäftsjahr-Prüfung**: Jahr aus der Datei oder manuell wählbar; Buchungen außerhalb des Geschäftsjahres werden gemeldet und können automatisch auf den 01.01./31.12. datiert werden
  - **Anfangsbestände**: werden beim Mehrjahres-Import erkannt und übersprungen, wenn sie durch Vorjahresbuchungen abgedeckt sind (mit Abweichungswarnung)
  - Buchungen ohne Gegenkonto landen als offene Bankbuchungen im Tab „Zuzuordnen"
  - Reset-Modus (nur Verwalter): alle Daten werden vorher gelöscht
- **Kontoauszüge** in drei Formaten – das Format wird am **Inhalt** erkannt, die Dateiendung spielt keine Rolle:
  - **CSV-CAMT** (Sparkasse, Volksbank/VR-NetWorld, …): automatische Erkennung von Trennzeichen und Zeichensatz, deutsches Zahlen- und Datumsformat
  - **CAMT.053 (XML)**: das ISO-20022-Standardformat – Vorzeichen, Datum und Zahlungsbeteiligte sind eindeutig ausgezeichnet statt geraten; vorgemerkte Umsätze (`PDNG`) werden übersprungen, Sammelbuchungen bleiben eine Zeile mit Posten-Hinweis
  - **MT940** (SWIFT, oft als `.sta`): mehrteilige Verwendungszwecke und Namen werden zusammengesetzt, Storni (`RC`/`RD`) kehren die Richtung um
  - **Dublettenerkennung** per SHA-256-Hash, zusätzlich gegen bereits per xbuc importierte Buchungen und – formatübergreifend – gegen vorhandene Bankbuchungen über Datum/Betrag/Text (auch bei abweichendem Valutadatum). Derselbe Auszug lässt sich also gefahrlos in einem anderen Format erneut einlesen
  - 0-€-Buchungen (z. B. ABSCHLUSS) werden übersprungen; bank-interne Buchungen ohne Zahlungsbeteiligten (ENTGELTABSCHLUSS …) bleiben buchbar
  - Import direkt im Tab „Buchungen" mit Drag-&-Drop, Vorschau und Erfolgsübersicht
- **Wachordner** (Zahnrad → *Bankdaten*): den heruntergeladenen Auszug in einen Nextcloud-Ordner legen genügt – ein stündlicher Hintergrundjob liest ihn ein, verschiebt ihn nach `verarbeitet/` und fehlerhafte Dateien mitsamt Begründung nach `fehler/`. Gelöscht wird nichts. Setzt System-Cron voraus

### Buchhaltung
- **Doppelte Buchführung**: Buchungssätze mit Soll-/Haben-Konten und fortlaufender Buchungsnummer (je Kalenderjahr neu beginnend ab 1)
- **Kontenrahmen** frei pflegbar mit Hierarchie (Über-/Unterkonten), Kontotypen, Bankkonto-Flag und Eröffnungssaldo
- **Buchungsdialog mit Einfach-Modus** (Einnahme/Ausgabe + Kategorie + Geldkonto) und Experten-Modus (Soll/Haben direkt)
- **Splittbuchungen**: ein Betrag auf mehrere Gegenkonten – im Buchungsdialog über *Betrag aufteilen* und beim Zuordnen eines Bankumsatzes über *Aufteilen…*. Das Geldkonto bleibt eine Zeile über den vollen Betrag, aufgeteilt wird die Gegenseite; eine Restanzeige zeigt, was noch fehlt, gespeichert wird erst, wenn die Aufteilung aufgeht. Alle Auswertungen rechnen zeilenweise und weisen die Teilbeträge getrennt aus
- **Konten auf inaktiv setzen**: bebuchte Konten lassen sich nicht löschen, wohl aber stilllegen – sie verschwinden aus den Auswahllisten, Beträge und Historie bleiben; im Kontenbaum bleiben sie gekennzeichnet sichtbar
- **Mehrere Bankkonten**: am Geldkonto lässt sich die IBAN hinterlegen; beim Zuordnen wählt die App daraufhin das Geldkonto, auf dem der Umsatz gebucht wurde (ohne IBAN-Treffer das erste Bankkonto, wie bisher)
- **Bankbuchungen zuordnen**: jede importierte Bankbuchung wird einem Gegenkonto zugeordnet, woraus automatisch ein Buchungssatz entsteht
  - **Zuordnungs-Vorschläge** aus Regeln und der bisherigen Zuordnungshistorie, per Klick übernehmbar
  - **Auto-Zuordnungsregeln** (Zahlungspartner / Verwendungszweck / IBAN enthält Suchtext → Gegenkonto): verwaltbar im Unterreiter „Regeln" (Tab Buchungen), oder per Blitz-Button direkt aus einer gebuchten Bankbuchung
- **Belege** (PDF/Bilder, max. 20 MB) an Buchungssätze anhängen – Ablage intern (AppData) oder in einem konfigurierbaren Nextcloud-Ordner; auf Mobilgeräten direkt beim Anlegen fotografieren
- **Offene Posten** (Tab Buchungen → Offene Posten): schlanke Ad-hoc-Liste unbezahlter Forderungen (z. B. Mitgliedsbeiträge, Rechnungen) mit Debitor, Betrag, Fälligkeit und optionalem Konto; Status offen/bezahlt/storniert, Dashboard-Hinweis bei überfälligen Posten – bewusst keine vollständige Mitgliederverwaltung
- **Rücklagen** (§ 62 AO: freie / zweckgebundene / Wiederbeschaffungsrücklage): Eigenkapital-Konten entsprechend kennzeichenbar, eigener Bericht mit Saldo je Art; Zuweisungen sind normale Buchungen (Experten-Modus)
- **Jahresfilter**: alle Auswertungen beziehen sich auf das im Header gewählte Kalenderjahr; Bestandskonten kumulativ, Erfolgskonten jahresbezogen
- **Jahresabschluss (Festschreibung)**: Verwalter schließen ein Geschäftsjahr ab – Buchungen, Belege und Zuordnungen dieses Jahres sind danach unveränderlich (Schreibversuche liefern HTTP 423); Wiedereröffnen nur durch Verwalter, beides wird protokolliert

### Auswertungen & Export
- **Übersicht (Dashboard)**: KPI-Kacheln mit Vorjahresvergleich, Hinweis auf nicht zugeordnete Buchungen und überfällige offene Posten, monatliches Einnahmen-/Ausgaben-Diagramm
- **Saldenliste**: alle Konten mit Soll/Haben/Saldo, hierarchische Darstellung, optional inkl. Unterkonten
- **Kontoauszug**: Buchungshistorie je Konto inkl. laufendem Saldo und Saldovortrag; falsch zugeordnete Buchungen lassen sich direkt dort auf ein anderes Konto **umbuchen** (jede Seite der Buchung, nur die Kontozuordnung ändert sich, protokolliert, gesperrt in abgeschlossenen Jahren)
- **Auswertungsgruppen**: Einnahmen/Ausgaben/Ergebnis je Auswertungsgruppe mit Buchungs-Drilldown; drei Modi (2. Zahlengruppe der Kontonummer, je Konto oder **frei definierte Auswertungsgruppen** mit ausdrücklicher Konto-Zuordnung einzeln bzw. per Mehrfachauswahl), Namen per UI änderbar
- **Steuerliche Sphären** (ideeller Bereich, Vermögensverwaltung, Zweckbetrieb, wirtschaftlicher Geschäftsbetrieb): je Konto zuweisbar (einzeln oder per Mehrfachauswahl im Bericht „Sphären"), eigener Bericht mit Einnahmen/Ausgaben/Ergebnis je Sphäre; Dashboard-Warnleiste bei Annäherung an die Freigrenze für den wirtschaftlichen Geschäftsbetrieb (§ 64 Abs. 3 AO) – ersetzt keine steuerliche Beratung
- **Finanzplan**: geplante Beträge je Konto und Jahr, Soll-Ist-Vergleich mit farbiger Abweichung
  - **Notizen je Planzahl** (z. B. Herleitung „40 Mitglieder × 25 €")
  - **Plan-Stände**: kompletten Plan als benannten, datierten Stand einfrieren (z. B. „Beschluss MV") und später mit dem aktuellen Plan vergleichen
- **Kassenbericht (druckfertig)**: eigenständige Druckseite für die Mitgliederversammlung – Vereinsname, Vermögensübersicht der Geldkonten (Bestand 01.01./31.12.), Einnahmen-/Ausgaben-Rechnung, Soll-Ist-Vergleich, Sphärenübersicht (steuerlich) mit Freigrenzen-Hinweis, Vollständigkeitsvermerk, Abschlussvermerk und Unterschriftszeilen; Drucken/Als-PDF-speichern über den Browser
- **Kurzbericht für Vorstandssitzungen (druckfertig)**: kompakte Druckseite mit wählbarem Stichtag – Kontostände seither, Bewegungen, Finanzplan-Kurzfassung; optional im Corporate Design (Vereinslogo + Akzentfarbe, unter Zahnrad → Verein hinterlegbar)
- **CSV-Exporte** (für Kassenprüfung/Excel): Journal, Saldenliste, Einnahmen-/Ausgaben-Übersicht, Soll-Ist-Vergleich (inkl. Notizen)
- **Mehrjahresübersicht** (CSV-Matrix, Spalten = Jahre): Erfolgsrechnung nach Konten (Einnahmen/Ausgaben/Ergebnis) + Vermögen zum Jahresende sowie Ergebnis je Auswertungsgruppe/Projekt und nach steuerlichen Sphären über alle Jahre; zusätzlich als Liniendiagramm (Berichte → Auswertung) für Sitzungspräsentationen
- **Geldkonten-Abstimmung**: Kontostand (Journal) vs. offene (nicht zugeordnete) Bankbuchungen

### Kassenprüfung
- **Änderungsprotokoll** (Berichte → Protokoll, für alle Leseberechtigten): wer hat wann Buchungen angelegt/geändert/gelöscht, zugeordnet, importiert, Belege oder Berechtigungen geändert, Jahre abgeschlossen; übersteht bewusst auch „Alle Daten löschen"
- **Beleg-ZIP**: alle Belege eines Jahres als ZIP-Download, ein Ordner je Buchung (`NNNN_Datum_Beschreibung/`); fehlende Dateien werden aufgelistet statt den Export abzubrechen
- **Filter „nur ohne Beleg"** im Journal: zeigt Buchungen ohne angehängten Beleg
- **Lückenprüfung**: Warnhinweis über dem Journal bei fehlenden oder doppelten Buchungsnummern im gewählten Jahr (zusätzlich als Vollständigkeitszeile im Kassenbericht). In einem offenen Geschäftsjahr hält die App die Nummerierung selbst lückenlos – gelöschte Buchungen lassen die nachfolgenden Nummern aufrücken, mit dem Jahresabschluss werden sie festgeschrieben
- **Prüfleitfaden** (Berichte → Auswertung): druckfertige 1-Seiten-Kurzanleitung für Kassenprüfer/innen – Rolle, Prüfschritte, wo was zu finden ist; mit Vereinsname im Kopf

### Mitgliedsbeiträge & SEPA-Lastschrift
Optionales Zusatzmodul (Reiter „Beiträge", erscheint automatisch sobald genutzt oder über Zahnrad → Beiträge & SEPA einschaltbar), erreichbar für Verwalter **und Buchhalter** – nur die Grundeinstellungen (Gläubiger-ID, einziehendes Konto, Standardbeitrag) bleiben Verwaltern vorbehalten.
- **Mitglieder** bestehen aus zwei unabhängigen Angaben statt einer eigenen Mitgliederverwaltung: einem **SEPA-Mandat** (IBAN, BIC, E-Mail, Unterschriftsdatum) und/oder einem **Beitrag** (Betrag, Zahlungsfrequenz, erste Fälligkeit); Zahler ist ein Nextcloud-Konto oder ein freier Name
- **Standard-Beitrag**: einmal hinterlegter Betrag/Frequenz belegt „Mitglied aufnehmen" vor und greift auch im CSV-Import, wenn eine Zeile ein Startdatum, aber keinen eigenen Betrag hat – bei 80–100 Mitgliedern mit demselben Satz sonst 80–100 Mal derselbe Wert von Hand
- **CSV-Massenimport**: Prüflauf zeigt je Zeile, was entstehen würde, bevor etwas angelegt wird; Spaltennamen deutsch/englisch, beliebige Reihenfolge, unbekannte Spalten werden übergangen; E-Mail-Validierung akzeptiert Umlaute im lokalen Teil (z. B. `m.müller@gmx.de`)
- **Beitragsfälligkeit**: erzeugt automatisch offene Posten; Rückstand (rückwirkend angelegte Beiträge) lässt sich mit „Nachholen" auf einen Schlag erzeugen statt eine Periode pro Tag abzuwarten
- **SEPA-Sammeleinzug**: Vorschau aller fälligen offenen Posten mit aktivem Mandat, Erzeugen, XML-Export (**pain.008**), Vorankündigung per E-Mail (14-Tage-Frist des SEPA-Regelwerks, mit Warnung bei kürzerem Vorlauf), Verbuchen als ausgeführt (schließt alle enthaltenen offenen Posten in einem Schritt), Rücklastschriften werden beim nächsten Kontoauszugs-Import automatisch erkannt und der Posten wieder geöffnet
- **Mandat widerrufen statt löschen**: erzeugte Einreichungen bleiben nachvollziehbar; Bankverbindung wechseln hängt bestehende Beiträge und offene Posten korrekt auf das neue Mandat um

### Organisation & Sicherheit
- **Berechtigungsrollen**: Verwalter – Buchhalter – Revisor (nur Lesen); NC-Admins sind immer Verwalter; Rollen für Nutzer und Gruppen
- **Gemeinsamer Datenbestand** (`user_id = '__verein__'`): alle berechtigten Nutzer arbeiten auf denselben Daten
- **Kollaboration**: Änderungen anderer Personen werden per Polling (20 s + bei Fenster-Fokus) erkannt und die Ansicht automatisch aktualisiert; **optimistisches Locking** beim Bearbeiten von Buchungen verhindert stilles Überschreiben (Konfliktmeldung statt Datenverlust)
- Destruktive Aktionen (Alles löschen, xbuc-Reset) nur für Verwalter, jeweils mit Bestätigungsdialog

### Mobile Bedienung
- **Bottom-Navigation mit „+"-Knopf** (neue Buchung) auf Mobilgeräten (≤ 640 px); Desktop-Ansicht bleibt unverändert
- **Karten statt Tabellen**: Journal (nach Monat gruppiert), Bankbuchungen, Saldenliste, Auswertungsgruppen, Kontoauszug sowie Mitgliederliste und SEPA-Einzug als Karten mit Drilldown und Zurück-Leisten
- **Auswahl-Sheet** für Konten/Kategorien: durchsuchbar, mit Zuordnungs-Vorschlag, Gruppe „Zuletzt verwendet" (gerätelokal) und Wisch-nach-unten zum Schließen
- **Schnellerfassung**: großes Betragsfeld, native Datumswahl, Belegfoto per Kamera direkt beim Anlegen

## Architektur

```
vereinsbuchhaltung/
├── appinfo/           info.xml, routes.php
├── lib/
│   ├── AppInfo/       Application.php (DI, Middleware-Registrierung)
│   ├── Controller/    Page, Account, Transaction, Import, Journal, Report,
│   │                  Budget, Permission, Rule, Export, Settings, Attachment,
│   │                  OpenItem, Branding (Logo/Farbe), Help (Handbuch,
│   │                  Prüfleitfaden), Demo (Beispielverein),
│   │                  Sync (Kollaboration), Year (Jahresabschluss), Audit,
│   │                  CostCenter, SepaMandate, MembershipFee, SepaBatch,
│   │                  MemberImport (Beiträge & SEPA, ab Rolle Buchhalter)
│   ├── Db/            Entities + QBMapper (accounts, bank_tx, journal, journal_line,
│   │                  costcenters, budgets, budget_snapshots, open_items,
│   │                  permissions, rules, attachments, year_close, audit_log,
│   │                  sepa_mandates, membership_fees, sepa_batches,
│   │                  sepa_batch_items)
│   │                  + TransactionRunner (DB-Transaktionsklammer)
│   ├── Middleware/    PermissionMiddleware (Rechteprüfung, 403/423),
│   │                  RevisionMiddleware (Änderungsstand für das Polling),
│   │                  RequiresRole (Attribut zur Rechteprüfung je Methode)
│   ├── Migration/     Schema-Migrationen (vbh_* Tabellen)
│   ├── BackgroundJob/ ImportWatchFolderJob (stündlicher Blick in den Wachordner)
│   ├── Service/       CamtCsvParser, ImportService, WatchFolderService,
│   │                  XbucParser, XbucImportService, AccountService,
│   │                  BookingService, JournalService, EntryNumberService,
│   │                  OpeningBalanceService, ReportService, ResetService,
│   │                  PermissionService, AttachmentStorageService,
│   │                  BudgetSnapshotService, OpenItemService, RevisionService,
│   │                  YearCloseService, AuditService, BrandingService,
│   │                  CostCenterService, CsvFormatter, DemoDataService,
│   │                  EmailValidator, IbanValidator, BillingPeriod (Beiträge:
│   │                  Fälligkeits-/Rückstandsrechnung), SepaMandateService,
│   │                  MembershipFeeService, SepaBatchService,
│   │                  SepaNotificationService (Vorankündigung per Mail),
│   │                  SepaReturnDetectionService (Rücklastschriften),
│   │                  MemberImportService
│   ├── Service/Sepa/  MemberCsvParser, PainXmlBuilder (pain.008-XML),
│   │                  SepaCreditor, SepaReference (Mandatsreferenz),
│   │                  SepaText
│   └── Service/Statement/
│                      Umsatzquellen: StatementParser (Schnittstelle),
│                      Camt053Parser, Mt940Parser, StatementParserRegistry
│                      (Formaterkennung am Inhalt), RowNormalizer
│                      (kanonische Zeilenform + Dedup-Hash für alle Quellen)
├── src/               Vue 3-Frontend (Composition API via setup(), reactive() als
│   │                  Composable-Singletons statt Vuex/Pinia)
│   ├── App.vue        Shell: Header/Navigation/Jahresauswahl, Tab-Router,
│   │                  Top-Level-Modals, Composable-Bootstrap in mounted()
│   ├── composables/   geteilter Zustand als reactive()-Singletons je Fachbereich
│   │                  (useAuth, useYears, useAccounts, useBalances, useJournal,
│   │                  useOpenItems, usePermissions, useSync, useCostCenters,
│   │                  useRules, useSort, useConfirm, useMembershipFees,
│   │                  useSepaMandates, useSepaBatches)
│   ├── components/    Tabs (DashboardTab/BookingsTab/AccountsTab/ReportsTab/
│   │                  ContributionsTab), Dialoge (BookingDialog/
│   │                  SplitAssignDialog/AccountDialog/ImportDialog/
│   │                  BudgetSnapshotModal/HelpModal/SetupWizard),
│   │                  Beiträge & SEPA (MembersList/MemberDialog/
│   │                  MemberImportDialog/MemberCard/SepaBatchPanel/
│   │                  BankAccountChangeDialog), Berichte-Pflege
│   │                  (RulesPanel/CostCenterPanel/SphereAssignPanel),
│   │                  Settings-* (Club/Attachments/StatementWatch/
│   │                  SepaBasics/Permissions/XbucImport/YearClose),
│   │                  Mobil (MobileNav/BookingCard/AccountPickerSheet),
│   │                  SetupChecklist
│   ├── lib/           zustandslose Helfer (format.js, split.js – die Regeln der
│   │                  Splittbuchung, frequency.js – Beitragsfrequenzen,
│   │                  geteilt von App.vue und den Dialogen)
│   ├── styles.css     globale .vbh-* Utility-Styles
│   ├── api.js         API-Client (axios + @nextcloud/router)
│   └── main.js        Einstieg
├── templates/         main.php
├── tests/             Unit-Tests + Beispiel-Dateien
├── deploy/            vbh-deploy.sh (Server-Update aus dem GitHub-Release)
├── img/               app.svg + Screenshots für den App Store
└── .github/workflows/ ci.yml (Lint, Build, PHPUnit, Schemaprüfung bei jedem Push),
                       release.yml (Tag v* → signiertes Paket, GitHub-Release,
                       Meldung an den App Store)
```

### Datenmodell

| Tabelle | Zweck |
|---|---|
| `vbh_accounts` | Kontenrahmen (Nr., Name, Typ, Hierarchie, Eröffnungssaldo, IBAN bei Geldkonten, Auswertungsgruppe) |
| `vbh_bank_tx` | importierte Bankbuchungen inkl. Dedup-Hash und Zuordnungsstatus |
| `vbh_journal` | Buchungssätze (Datum, Beschreibung, Belegnr., Buchungsnr.) |
| `vbh_journal_line` | Soll-/Haben-Zeilen je Buchungssatz (Betrag in Cent) |
| `vbh_costcenters` | Auswertungsgruppen (Kürzel, Name); Konten verweisen über `vbh_accounts.cost_center_id` darauf. Tabellen- und Spaltenname stammen aus der Zeit, als die Auswertungsgruppe „Kostenstelle" hieß – sie bleiben, damit bestehende Installationen ohne Migration weiterlaufen |
| `vbh_budgets` | Finanzplan (Konto × Jahr × Betrag in Cent + Notiz) |
| `vbh_budget_snapshots` | eingefrorene Plan-Stände (Jahr, Label, Zeitpunkt) |
| `vbh_budget_snap_items` | Positionen eines Plan-Stands (inkl. eingefrorener Konto-Stammdaten) |
| `vbh_open_items` | offene Posten (Debitor, Betrag, Fälligkeit, Status, optional Konto/Buchung) |
| `vbh_rules` | Auto-Zuordnungsregeln (Feld, Suchtext, Gegenkonto, Priorität) |
| `vbh_attachments` | Belege je Buchungssatz (Dateiname, MIME, Größe) |
| `vbh_permissions` | Berechtigungen (principal_type, principal_id, Rolle) |
| `vbh_year_close` | abgeschlossene (festgeschriebene) Geschäftsjahre (Jahr, wann, von wem) |
| `vbh_audit_log` | Änderungsprotokoll (Zeitpunkt, Nutzer, Aktion, Objekt, Details) |
| `vbh_sepa_mandates` | SEPA-Lastschriftmandate (IBAN, BIC, E-Mail, Mandatsreferenz, Status: aktiv/widerrufen) |
| `vbh_membership_fees` | Mitgliedsbeiträge (Betrag in Cent, Frequenz, nächste Fälligkeit, optional verknüpftes Mandat/Konto) |
| `vbh_sepa_batches` | erzeugte SEPA-Sammeleinzüge (Fälligkeitstag, Gläubiger-Angaben zum Erzeugungszeitpunkt, Status) |
| `vbh_sepa_batch_items` | Zeilen eines Sammeleinzugs (Betrag, Mandat, offener Posten, Rücklastschrift-Status) – bleiben auch nach Bezahlung/Storno bestehen |

Beträge werden durchgängig als **Integer in Cent** gespeichert (keine Float-Rundungsfehler).

## Installation

Die App ist im **Nextcloud App Store** veröffentlicht (signiert): in Nextcloud unter *Apps → Deine Apps / Büro* nach „Vereinsbuchhaltung" suchen und installieren. Das ist der empfohlene Weg – Updates kommen dann über den üblichen App-Store-Mechanismus.

Alternativ lässt sich das Tarball eines [GitHub-Releases](https://github.com/AndiMb/nc_vereinsbuchhaltung/releases) nach `<nextcloud>/apps/` entpacken (siehe auch [`deploy/README.md`](deploy/README.md) für die Server-Automatisierung).

**Unterstützt:** Nextcloud 31–35, PHP 8.1–8.5, SQLite/MySQL/PostgreSQL.

## Entwicklung

**Voraussetzungen:** PHP ≥ 8.1, Node ≥ 22 / npm ≥ 10, eine Nextcloud-Instanz (≥ 31).

```bash
# 1. Frontend bauen
npm install
npm run build           # erzeugt js/vereinsbuchhaltung-main.js

# 2. App nach Nextcloud bringen
#    Ordner "vereinsbuchhaltung" nach <nextcloud>/apps/ kopieren oder verlinken,
#    dann in Nextcloud unter "Apps" aktivieren.

# 3. Datenbank-Migration ausführen (nach Updates)
php occ upgrade         # oder: php occ migrations:migrate vereinsbuchhaltung
```

> **Keine lokale Nextcloud?** Schnellster Weg zum Testen:
> ```bash
> docker run -d -p 8080:80 \
>   -v "$PWD":/var/www/html/custom_apps/vereinsbuchhaltung \
>   nextcloud
> ```
> Anschließend `http://localhost:8080` aufrufen, einrichten, App aktivieren.

### CI

`.github/workflows/ci.yml` läuft bei jedem Push und Pull Request: ESLint, Produktions-Build, PHP-Syntaxprüfung auf 8.1 und 8.4, `composer validate`, PHPUnit und die Validierung der `info.xml` gegen das App-Store-Schema.

### Release & Deployment

Ein Git-Tag `v<version>` (muss der `<version>` in `appinfo/info.xml` entsprechen und einen Abschnitt `## [x.y.z]` in der [CHANGELOG.md](CHANGELOG.md) haben) stößt `release.yml` an. Der Workflow baut das Frontend, signiert die App mit dem von Nextcloud ausgestellten Zertifikat (`appinfo/signature.json`), veröffentlicht `vereinsbuchhaltung-<version>.tar.gz` (+ SHA-256) als GitHub-Release und meldet es dem Nextcloud App Store.

Die im App Store gezeigten Screenshots werden per URL aus `img/screenshots/` auf `main` geladen – neue Bilder müssen also **vor** dem Release gepusht sein.

Auf eigenen Servern holt `deploy/vbh-deploy.sh` das neueste Release, prüft die Prüfsumme und führt `occ upgrade` aus. **Vor Releases mit Datenbank-Migration: Datenbank-Backup anlegen** – das Skript-Rollback stellt nur den App-Ordner wieder her, nicht das Schema.

## Erste Schritte

Beim allerersten Start begrüßt ein **Setup-Assistent** mit drei Wegen (xbuc übernehmen / neu anfangen / Beispieldaten). Wer ihn überspringt, arbeitet die folgenden Schritte ab – die **Einrichtungs-Checkliste** auf dem Dashboard zeigt dabei laufend, was noch offen ist.

1. **Berechtigungen** (Zahnrad → Einstellungen → Berechtigungen) → Nutzer oder Gruppen als Buchhalter oder Verwalter eintragen.
2. **Zahnrad → Daten → Aus „zero Buchhaltung" importieren** → `.xbuc`-Datei wählen → Vorschau prüfen → Importieren.
   - Mehrere Jahres-Dateien nacheinander importieren: der Merge-Modus (Standard) übernimmt nur fehlende Konten und neue Buchungen.
   - Alternativ: Tab **Konten** → *Standard-Kontenrahmen anlegen* und Konten manuell erstellen.
3. Tab **Buchungen** → *Kontoumsätze importieren* → Kontoauszug der Bank hochladen (CSV-CAMT, CAMT.053 oder MT940). Wer das regelmäßig tut: Zahnrad → *Bankdaten* erspart den Upload.
4. Tab **Buchungen → Zuzuordnen** → jede Bankbuchung einem Gegenkonto zuordnen (Vorschläge per Klick übernehmen; Regeln automatisieren wiederkehrende Buchungen).
5. Tab **Übersicht** → Dashboard mit KPI-Kacheln und Monatschart.
6. Tab **Berichte** → Auswertung (inkl. Kassenbericht, Kurzbericht, Beleg-ZIP und Prüfleitfaden), Auswertungsgruppen, Finanzplan (inkl. Plan-Notizen, Plan-Ständen und CSV-Export), Sphären, Rücklagen, Protokoll.
7. Werden Mitgliedsbeiträge per SEPA-Lastschrift eingezogen: Reiter **„Beiträge"** (erscheint nach Zahnrad → Beiträge & SEPA → Schalter, oder automatisch beim ersten Mandat).
8. Nach Kassenprüfung und Entlastung: **Zahnrad → Jahresabschluss** → Jahr abschließen (festschreiben).

## Roadmap

- **Umsätze direkt bei der Bank abrufen (FinTS/HBCI)**, statt sie herunterzuladen. Der MT940-Parser dafür steht schon, das Format liefert FinTS zurück. Offen sind vor allem die nicht-technischen Fragen: Produktregistrierung bei der Deutschen Kreditwirtschaft, Speicherung der Bankzugangsdaten und der TAN-Dialog. Ausgeschlossen bleibt der Weg über einen Aggregator – die Kontoumsätze würden dann über die Cloud eines Dritten laufen
- Budget-Ampel („Wie stehen wir zum Plan?") auf dem Dashboard
- Automatischer Zahlungsabgleich für offene Posten (Vorschläge per Zahlungspartner-Abgleich wie bei den Auto-Zuordnungsregeln)

## Lizenz

AGPL-3.0-or-later
