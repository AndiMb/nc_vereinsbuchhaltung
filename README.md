# Vereinsbuchhaltung – Nextcloud-App

Eine schlanke Buchhaltungs-App für Vereine, direkt in Nextcloud integriert. Kontenrahmen und Buchungen können aus einer **„zero Buchhaltung"-.xbuc-Datei** importiert werden, Kontoumsätze kommen als **CSV-CAMT** von der Bank. Die App arbeitet nach den Regeln der **doppelten Buchführung** (Soll/Haben) mit frei definierbarem Kontenrahmen.

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
- **Belege** (PDF/Bilder, max. 20 MB) an Buchungssätze anhängen – Ablage intern (AppData) oder in einem konfigurierbaren Nextcloud-Ordner
- **Jahresfilter**: alle Auswertungen beziehen sich auf das im Header gewählte Kalenderjahr; Bestandskonten kumulativ, Erfolgskonten jahresbezogen

### Auswertungen & Export
- **Übersicht (Dashboard)**: KPI-Kacheln mit Vorjahresvergleich, Hinweis auf nicht zugeordnete Buchungen, monatliches Einnahmen-/Ausgaben-Diagramm
- **Saldenliste**: alle Konten mit Soll/Haben/Saldo, hierarchische Darstellung, optional inkl. Unterkonten
- **Kontoauszug**: Buchungshistorie je Konto inkl. laufendem Saldo und Saldovortrag
- **Kostenstellen**: Einnahmen/Ausgaben/Ergebnis je Kostenstelle mit Buchungs-Drilldown; zwei Modi (2. Zahlengruppe der Kontonummer oder je Konto), Namen per UI änderbar
- **Finanzplan**: geplante Beträge je Konto und Jahr, Soll-Ist-Vergleich mit farbiger Abweichung
  - **Notizen je Planzahl** (z. B. Herleitung „40 Mitglieder × 25 €")
  - **Plan-Stände**: kompletten Plan als benannten, datierten Stand einfrieren (z. B. „Beschluss MV") und später mit dem aktuellen Plan vergleichen
- **CSV-Exporte** (für Kassenprüfung/Excel): Journal, Saldenliste, Einnahmen-/Ausgaben-Übersicht, Soll-Ist-Vergleich (inkl. Notizen)
- **Geldkonten-Abstimmung**: Kontostand (Journal) vs. offene (nicht zugeordnete) Bankbuchungen

### Organisation & Sicherheit
- **Berechtigungsrollen**: Verwalter – Buchhalter – Revisor (nur Lesen); NC-Admins sind immer Verwalter; Rollen für Nutzer und Gruppen
- **Gemeinsamer Datenbestand** (`user_id = '__verein__'`): alle berechtigten Nutzer arbeiten auf denselben Daten
- Destruktive Aktionen (Alles löschen, xbuc-Reset) nur für Verwalter, jeweils mit Bestätigungsdialog
- **Responsive Layout** für Desktop und Mobilgeräte

## Architektur

```
vereinsbuchhaltung/
├── appinfo/           info.xml, routes.php
├── lib/
│   ├── AppInfo/       Application.php (DI, Middleware-Registrierung)
│   ├── Controller/    Page, Account, Transaction, Import, Journal, Report,
│   │                  Budget, Permission, Rule, Export, Settings, Attachment
│   ├── Db/            Entities + QBMapper (accounts, bank_tx, journal, journal_line,
│   │                  imports, costcenters, budgets, budget_snapshots, permissions,
│   │                  rules, attachments)
│   ├── Migration/     Schema-Migrationen (vbh_* Tabellen)
│   └── Service/       CamtCsvParser, ImportService, XbucParser, XbucImportService,
│                      AccountService, BookingService, JournalService,
│                      OpeningBalanceService, ReportService, ResetService,
│                      PermissionService, AttachmentStorageService,
│                      BudgetSnapshotService
├── src/               Vue 2.7-Frontend
│   ├── App.vue        Hauptkomponente (Tabs, Dialoge)
│   ├── components/    ausgelagerte Kindkomponenten (z. B. SettingsRules.vue)
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
6. Tab **Berichte** → Auswertung, Kostenstellen, Finanzplan (inkl. Plan-Notizen, Plan-Ständen und CSV-Export).

## Roadmap

- Mehrere Bankkonten per IBAN automatisch zuordnen
- Splittbuchungen (eine Bankbuchung auf mehrere Gegenkonten)
- PDF-Export für Kassenprüfung und Jahresabschluss

## Lizenz

AGPL-3.0-or-later
