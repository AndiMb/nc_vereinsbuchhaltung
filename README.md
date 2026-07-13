# Vereinsbuchhaltung – Nextcloud-App

Eine schlanke Buchhaltungs-App für Vereine, direkt in Nextcloud integriert. Kontenrahmen und Buchungen können aus einer **„zero Buchhaltung"-.xbuc-Datei** importiert werden, Kontoumsätze kommen als **CSV-CAMT** von der Bank. Die App arbeitet nach den Regeln der **doppelten Buchführung** (Soll/Haben) mit frei definierbarem Kontenrahmen.

> 📖 **Einsteiger-Leitfaden:** Das beiliegende **[HANDBUCH.md](HANDBUCH.md)** führt Schatzmeisterinnen Schritt für Schritt durch die App – von der Ersteinrichtung über die laufende Buchung bis zum Jahresabschluss und der Kassenprüfung.

## Funktionsumfang

### Import
- **xbuc-Import** (zero Buchhaltung): übernimmt Kontenbaum und alle Buchungen aus einer `.xbuc`-Datei
  - **Merge-Modus** (Standard): nur fehlende Konten werden angelegt, bereits vorhandene Buchungen werden per Fingerprint übersprungen – mehrere Jahres-Dateien lassen sich nacheinander importieren
  - **Geschäftsjahr-Prüfung**: Jahr aus der Datei oder manuell wählbar; Buchungen außerhalb des Geschäftsjahres werden gemeldet und können automatisch auf den 01.01./31.12. datiert werden
  - **Anfangsbestände**: werden beim Mehrjahres-Import erkannt und übersprungen, wenn sie durch Vorjahresbuchungen abgedeckt sind (mit Abweichungswarnung)
  - Buchungen ohne Gegenkonto landen als offene Bankbuchungen im Tab „Zuzuordnen"
  - Reset-Modus (nur Verwalter): alle Daten werden vorher gelöscht
- **CSV-CAMT-Import** (Sparkasse, Volksbank/VR-NetWorld, …): automatische Erkennung von Trennzeichen und Zeichensatz, deutsches Zahlen- und Datumsformat
  - **Dublettenerkennung** per SHA-256-Hash – und zusätzlich gegen bereits per xbuc importierte Buchungen (auch bei abweichendem Valutadatum)
  - 0-€-Buchungen (z. B. ABSCHLUSS) werden übersprungen; bank-interne Buchungen ohne Zahlungsbeteiligten (ENTGELTABSCHLUSS …) bleiben buchbar
  - Import direkt im Tab „Buchungen" mit Drag-&-Drop, Vorschau und Erfolgsübersicht

### Buchhaltung
- **Doppelte Buchführung**: Buchungssätze mit Soll-/Haben-Konten und fortlaufender Buchungsnummer (je Kalenderjahr neu beginnend ab 1)
- **Kontenrahmen** frei pflegbar mit Hierarchie (Über-/Unterkonten), Kontotypen, Bankkonto-Flag und Eröffnungssaldo
- **Buchungsdialog mit Einfach-Modus** (Einnahme/Ausgabe + Kategorie + Geldkonto) und Experten-Modus (Soll/Haben direkt)
- **Bankbuchungen zuordnen**: jede importierte Bankbuchung wird einem Gegenkonto zugeordnet, woraus automatisch ein Buchungssatz entsteht
  - **Zuordnungs-Vorschläge** aus Regeln und der bisherigen Zuordnungshistorie, per Klick übernehmbar
  - **Auto-Zuordnungsregeln** (Zahlungspartner / Verwendungszweck / IBAN enthält Suchtext → Gegenkonto): verwaltbar unter Einstellungen, oder per Blitz-Button direkt aus einer gebuchten Bankbuchung
- **Belege** (PDF/Bilder, max. 20 MB) an Buchungssätze anhängen – Ablage intern (AppData) oder in einem konfigurierbaren Nextcloud-Ordner; auf Mobilgeräten direkt beim Anlegen fotografieren
- **Offene Posten** (Tab Buchungen → Offene Posten): schlanke Ad-hoc-Liste unbezahlter Forderungen (z. B. Mitgliedsbeiträge, Rechnungen) mit Debitor, Betrag, Fälligkeit und optionalem Konto; Status offen/bezahlt/storniert, Dashboard-Hinweis bei überfälligen Posten – bewusst keine vollständige Mitgliederverwaltung
- **Rücklagen** (§ 62 AO: freie / zweckgebundene / Wiederbeschaffungsrücklage): Eigenkapital-Konten entsprechend kennzeichenbar, eigener Bericht mit Saldo je Art; Zuweisungen sind normale Buchungen (Experten-Modus)
- **Jahresfilter**: alle Auswertungen beziehen sich auf das im Header gewählte Kalenderjahr; Bestandskonten kumulativ, Erfolgskonten jahresbezogen
- **Jahresabschluss (Festschreibung)**: Verwalter schließen ein Geschäftsjahr ab – Buchungen, Belege und Zuordnungen dieses Jahres sind danach unveränderlich (Schreibversuche liefern HTTP 423); Wiedereröffnen nur durch Verwalter, beides wird protokolliert

### Auswertungen & Export
- **Übersicht (Dashboard)**: KPI-Kacheln mit Vorjahresvergleich, Hinweis auf nicht zugeordnete Buchungen und überfällige offene Posten, monatliches Einnahmen-/Ausgaben-Diagramm
- **Saldenliste**: alle Konten mit Soll/Haben/Saldo, hierarchische Darstellung, optional inkl. Unterkonten
- **Kontoauszug**: Buchungshistorie je Konto inkl. laufendem Saldo und Saldovortrag
- **Kostenstellen**: Einnahmen/Ausgaben/Ergebnis je Kostenstelle mit Buchungs-Drilldown; zwei Modi (2. Zahlengruppe der Kontonummer oder je Konto), Namen per UI änderbar
- **Steuerliche Sphären** (ideeller Bereich, Vermögensverwaltung, Zweckbetrieb, wirtschaftlicher Geschäftsbetrieb): je Konto zuweisbar (Einzeln oder per Mehrfachauswahl), eigener Bericht mit Einnahmen/Ausgaben/Ergebnis je Sphäre; Dashboard-Warnleiste bei Annäherung an die Freigrenze für den wirtschaftlichen Geschäftsbetrieb (§ 64 Abs. 3 AO) – ersetzt keine steuerliche Beratung
- **Finanzplan**: geplante Beträge je Konto und Jahr, Soll-Ist-Vergleich mit farbiger Abweichung
  - **Notizen je Planzahl** (z. B. Herleitung „40 Mitglieder × 25 €")
  - **Plan-Stände**: kompletten Plan als benannten, datierten Stand einfrieren (z. B. „Beschluss MV") und später mit dem aktuellen Plan vergleichen
- **Kassenbericht (druckfertig)**: eigenständige Druckseite für die Mitgliederversammlung – Vereinsname, Vermögensübersicht der Geldkonten (Bestand 01.01./31.12.), Einnahmen-/Ausgaben-Rechnung, Soll-Ist-Vergleich, Sphärenübersicht (steuerlich) mit Freigrenzen-Hinweis, Vollständigkeitsvermerk, Abschlussvermerk und Unterschriftszeilen; Drucken/Als-PDF-speichern über den Browser
- **Kurzbericht für Vorstandssitzungen (druckfertig)**: kompakte Druckseite mit wählbarem Stichtag – Kontostände seither, Bewegungen, Finanzplan-Kurzfassung; optional im Corporate Design (Vereinslogo + Akzentfarbe, unter Einstellungen hinterlegbar)
- **CSV-Exporte** (für Kassenprüfung/Excel): Journal, Saldenliste, Einnahmen-/Ausgaben-Übersicht, Soll-Ist-Vergleich (inkl. Notizen)
- **Mehrjahresübersicht** (CSV-Matrix, Spalten = Jahre): Erfolgsrechnung nach Konten (Einnahmen/Ausgaben/Ergebnis) + Vermögen zum Jahresende sowie Auswertung nach Kostenstellen/Projekten und nach steuerlichen Sphären über alle Jahre; zusätzlich als Liniendiagramm (Berichte → Auswertung) für Sitzungspräsentationen
- **Geldkonten-Abstimmung**: Kontostand (Journal) vs. offene (nicht zugeordnete) Bankbuchungen

### Kassenprüfung
- **Änderungsprotokoll** (Berichte → Protokoll, für alle Leseberechtigten): wer hat wann Buchungen angelegt/geändert/gelöscht, zugeordnet, importiert, Belege oder Berechtigungen geändert, Jahre abgeschlossen; übersteht bewusst auch „Alle Daten löschen"
- **Beleg-ZIP**: alle Belege eines Jahres als ZIP-Download, ein Ordner je Buchung (`NNNN_Datum_Beschreibung/`); fehlende Dateien werden aufgelistet statt den Export abzubrechen
- **Filter „nur ohne Beleg"** im Journal: zeigt Buchungen ohne angehängten Beleg
- **Lückenprüfung**: Warnhinweis über dem Journal bei fehlenden oder doppelten Buchungsnummern im gewählten Jahr (zusätzlich als Vollständigkeitszeile im Kassenbericht)

### Organisation & Sicherheit
- **Berechtigungsrollen**: Verwalter – Buchhalter – Revisor (nur Lesen); NC-Admins sind immer Verwalter; Rollen für Nutzer und Gruppen
- **Gemeinsamer Datenbestand** (`user_id = '__verein__'`): alle berechtigten Nutzer arbeiten auf denselben Daten
- **Kollaboration**: Änderungen anderer Personen werden per Polling (20 s + bei Fenster-Fokus) erkannt und die Ansicht automatisch aktualisiert; **optimistisches Locking** beim Bearbeiten von Buchungen verhindert stilles Überschreiben (Konfliktmeldung statt Datenverlust)
- Destruktive Aktionen (Alles löschen, xbuc-Reset) nur für Verwalter, jeweils mit Bestätigungsdialog

### Mobile Bedienung
- **Bottom-Navigation mit „+"-Knopf** (neue Buchung) auf Mobilgeräten (≤ 640 px); Desktop-Ansicht bleibt unverändert
- **Karten statt Tabellen**: Journal (nach Monat gruppiert), Bankbuchungen, Saldenliste, Kostenstellen und Kontoauszug als Karten mit Drilldown und Zurück-Leisten
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
│   │                  Sync (Kollaboration), Year (Jahresabschluss), Audit
│   ├── Db/            Entities + QBMapper (accounts, bank_tx, journal, journal_line,
│   │                  imports, costcenters, budgets, budget_snapshots, permissions,
│   │                  rules, attachments, year_close, audit_log)
│   ├── Middleware/    PermissionMiddleware (Rechteprüfung, 403/423),
│   │                  RevisionMiddleware (Änderungsstand für das Polling)
│   ├── Migration/     Schema-Migrationen (vbh_* Tabellen)
│   └── Service/       CamtCsvParser, ImportService, XbucParser, XbucImportService,
│                      AccountService, BookingService, JournalService,
│                      OpeningBalanceService, ReportService, ResetService,
│                      PermissionService, AttachmentStorageService,
│                      BudgetSnapshotService, RevisionService,
│                      YearCloseService, AuditService
├── src/               Vue 2.7-Frontend (Composition API via setup(), reactive() als
│   │                  Composable-Singletons statt Vuex/Pinia)
│   ├── App.vue        Shell: Header/Navigation/Jahresauswahl, Tab-Router,
│   │                  Top-Level-Modals, Composable-Bootstrap in mounted()
│   ├── composables/   geteilter Zustand als reactive()-Singletons je Fachbereich
│   │                  (useAuth, useYears, useAccounts, useBalances, useJournal,
│   │                  usePermissions, useSync)
│   ├── components/    Tabs (DashboardTab/BookingsTab/AccountsTab/ReportsTab),
│   │                  Dialoge (BookingDialog/AccountDialog/ImportDialog/
│   │                  BudgetSnapshotModal/HelpModal/SetupWizard), Settings-*
│   │                  (Rules/Spheres/XbucImport/Permissions/General/YearClose),
│   │                  Mobil (MobileNav/BookingCard/AccountPickerSheet),
│   │                  SetupChecklist
│   ├── lib/           zustandslose Helfer (format.js)
│   ├── styles.css     globale .vbh-* Utility-Styles
│   ├── api.js         API-Client (axios + @nextcloud/router)
│   └── main.js        Einstieg
├── templates/         main.php
├── tests/             Unit-Tests + Beispiel-Dateien
└── .github/workflows/ Release-Build (Tag v* → GitHub Release mit Tarball)
```

### Datenmodell

| Tabelle | Zweck |
|---|---|
| `vbh_accounts` | Kontenrahmen (Nr., Name, Typ, Hierarchie, Eröffnungssaldo) |
| `vbh_bank_tx` | importierte Bankbuchungen inkl. Dedup-Hash und Zuordnungsstatus |
| `vbh_journal` | Buchungssätze (Datum, Beschreibung, Belegnr., Buchungsnr.) |
| `vbh_journal_line` | Soll-/Haben-Zeilen je Buchungssatz (Betrag in Cent) |
| `vbh_imports` | Import-Protokoll (neu/Dubletten je Datei) |
| `vbh_costcenters` | Kostenstellen-Namen (code, name) |
| `vbh_budgets` | Finanzplan (Konto × Jahr × Betrag in Cent + Notiz) |
| `vbh_budget_snapshots` | eingefrorene Plan-Stände (Jahr, Label, Zeitpunkt) |
| `vbh_budget_snap_items` | Positionen eines Plan-Stands (inkl. eingefrorener Konto-Stammdaten) |
| `vbh_rules` | Auto-Zuordnungsregeln (Feld, Suchtext, Gegenkonto, Priorität) |
| `vbh_attachments` | Belege je Buchungssatz (Dateiname, MIME, Größe) |
| `vbh_permissions` | Berechtigungen (principal_type, principal_id, Rolle) |
| `vbh_year_close` | abgeschlossene (festgeschriebene) Geschäftsjahre (Jahr, wann, von wem) |
| `vbh_audit_log` | Änderungsprotokoll (Zeitpunkt, Nutzer, Aktion, Objekt, Details) |

Beträge werden durchgängig als **Integer in Cent** gespeichert (keine Float-Rundungsfehler).

## Installation / Entwicklung

**Voraussetzungen:** PHP ≥ 8.1, Node ≥ 20 / npm ≥ 10, eine Nextcloud-Instanz (≥ 28).

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

### Release & Deployment

Ein Git-Tag `v<version>` (muss der `<version>` in `appinfo/info.xml` entsprechen) stößt den GitHub-Actions-Workflow an, der das Frontend baut und ein fertiges `vereinsbuchhaltung-<version>.tar.gz` (+ SHA-256) als GitHub-Release veröffentlicht. Auf den Servern holt ein Deploy-Skript das neueste Release, prüft die Prüfsumme und führt `occ upgrade` aus. **Vor Releases mit Datenbank-Migration: Datenbank-Backup anlegen** – das Skript-Rollback stellt nur den App-Ordner wieder her, nicht das Schema.

### Build-Hinweis (vue-loader)

Das Projekt verwendet Vue 2.7 mit `@nextcloud/webpack-vue-config`. Damit der Build funktioniert, müssen `vue-loader@15` und `vue-template-compiler` explizit in `devDependencies` stehen – neuere `vue-loader`-Versionen erzeugen Vue-3-Render-Funktionen, die mit der Vue-2.7-Runtime inkompatibel sind.

## Erste Schritte

1. **Berechtigungen** (Gear-Icon → Einstellungen) → Nutzer oder Gruppen als Buchhalter oder Verwalter eintragen.
2. **Einstellungen → Aus „zero Buchhaltung" importieren** → `.xbuc`-Datei wählen → Vorschau prüfen → Importieren.
   - Mehrere Jahres-Dateien nacheinander importieren: der Merge-Modus (Standard) übernimmt nur fehlende Konten und neue Buchungen.
   - Alternativ: Tab **Konten** → *Standard-Kontenrahmen anlegen* und Konten manuell erstellen.
3. Tab **Buchungen** → *Kontoumsätze importieren* → CSV-CAMT-Datei der Bank hochladen.
4. Tab **Buchungen → Zuzuordnen** → jede Bankbuchung einem Gegenkonto zuordnen (Vorschläge per Klick übernehmen; Regeln automatisieren wiederkehrende Buchungen).
5. Tab **Übersicht** → Dashboard mit KPI-Kacheln und Monatschart.
6. Tab **Berichte** → Auswertung (inkl. Kassenbericht und Beleg-ZIP), Kostenstellen, Finanzplan (inkl. Plan-Notizen, Plan-Ständen und CSV-Export), Protokoll.
7. Nach Kassenprüfung und Entlastung: **Einstellungen → Jahresabschluss** → Jahr abschließen (festschreiben).

## Roadmap

- Splittbuchungen im UI anlegen und bearbeiten (eine Zahlung auf mehrere Gegenkonten; importierte Splittbuchungen werden bereits angezeigt)
- Mehrere Bankkonten per IBAN automatisch zuordnen (CSV-Import)
- Budget-Ampel („Wie stehen wir zum Plan?") auf dem Dashboard
- Automatischer Zahlungsabgleich für offene Posten (Vorschläge per Zahlungspartner-Abgleich wie bei den Auto-Zuordnungsregeln)

## Lizenz

AGPL-3.0-or-later
