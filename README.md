# Vereinsbuchhaltung – Nextcloud-App

Eine schlanke Buchhaltungs-App für Vereine, direkt in Nextcloud integriert. Kontenrahmen und Buchungen können aus einer **„zero Buchhaltung"-.xbuc-Datei** importiert werden, Kontoumsätze kommen als **CSV-CAMT** von der Bank. Die App arbeitet nach den Regeln der **doppelten Buchführung** (Soll/Haben) mit frei definierbarem Kontenrahmen.

## Funktionsumfang

### Import
- **xbuc-Import** (zero Buchhaltung): übernimmt Kontenbaum und alle Buchungen aus einer `.xbuc-Datei`
  - **Merge-Modus** (Standard): nur fehlende Konten werden angelegt, bereits vorhandene Buchungen werden per Fingerprint (Datum|Betrag|Soll-ID|Haben-ID|Belegnummer) übersprungen – mehrere Jahres-Dateien lassen sich nacheinander importieren
  - Reset-Modus: alle Daten werden vorher gelöscht (optional)
- **CSV-CAMT-Import** (Sparkasse, Volksbank, …): automatische Erkennung von Trennzeichen und Zeichensatz (UTF-8 / Windows-1252), deutsches Zahlen- und Datumsformat; Dublettenerkennung per SHA-256-Hash

### Buchhaltung
- **Doppelte Buchführung**: Buchungssätze mit Soll-/Haben-Konten und fortlaufender Buchungsnummer (je Kalenderjahr neu beginnend ab 1)
- **Kontenrahmen** frei pflegbar mit Hierarchie (Über-/Unterkonten), Kontotypen (Anlage/Einnahme/Ausgabe/Eigenkapital), Bankkonto-Flag und Eröffnungssaldo
- **Manuelle Buchungssätze**: anlegen, bearbeiten, löschen
- **Bankbuchungen zuordnen**: jede importierte Bankbuchung wird einem Gegenkonto zugeordnet, woraus automatisch ein Buchungssatz entsteht
- **Jahresfilter**: alle Auswertungen beziehen sich auf das im Header gewählte Kalenderjahr; Bestandskonten werden kumulativ, Erfolgskonten jahresbezogen ausgewertet

### Auswertungen
- **Übersicht (Dashboard)**: KPI-Kacheln (Einnahmen/Ausgaben/Ergebnis), Hinweis auf nicht zugeordnete Buchungen, monatliches Einnahmen-/Ausgaben-Diagramm (Chart.js)
- **Saldenliste**: alle Konten mit Soll/Haben/Saldo, sortierbar, optional inkl. Unterkonten
- **Kontoauszug**: Buchungshistorie je Konto inkl. laufendem Saldo und Saldovortrag
- **Kostenstellen**: Einnahmen/Ausgaben/Ergebnis je Kostenstelle (aus 2. Gruppe der Kontonummer), per UI umbenennbar
- **Finanzplan**: geplante Beträge je Konto und Jahr, Ist-/Plan-Vergleich mit farbiger Abweichung
- **Geldkonten-Abstimmung**: Kontostand (Journal) vs. offene (nicht zugeordnete) Bankbuchungen

### Organisation & Sicherheit
- **Berechtigungsrollen**: Verwalter – Buchhalter – Revisor (nur Lesen); NC-Admins sind immer Verwalter; Rollen für einzelne Nutzer und Gruppen vergebar
- **Gemeinsamer Datenbestand** (`user_id = '__verein__'`): alle berechtigten Nutzer arbeiten auf denselben Daten
- **Responsive Layout**: funktioniert auf Desktop und Mobilgeräten (scrollbare Tabs, horizontaler Tabellen-Scroll, gestapeltes Split-Layout)

## Architektur

```
vereinsbuchhaltung/
├── appinfo/           info.xml, routes.php
├── lib/
│   ├── AppInfo/       Application.php (DI, Middleware-Registrierung)
│   ├── Controller/    Page, Account, Import, Journal, Report, Budget, Permission
│   ├── Db/            Entities + QBMapper (accounts, bank_tx, journal, journal_line,
│   │                  imports, costcenters, budgets, permissions)
│   ├── Migration/     Schema-Migrationen (vbh_* Tabellen)
│   └── Service/       CamtCsvParser, ImportService, XbucParser, XbucImportService,
│                      AccountService, BookingService, JournalService,
│                      OpeningBalanceService, ReportService, ResetService,
│                      PermissionService
├── src/               Vue 2.7-Frontend (App.vue, api.js, main.js)
├── templates/         main.php
└── tests/             Unit-Tests + Beispiel-Dateien
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
| `vbh_budgets` | Finanzplan (Konto × Jahr × Betrag in Cent) |
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
php occ migrations:migrate vereinsbuchhaltung
```

> **Keine lokale Nextcloud?** Schnellster Weg zum Testen:
> ```bash
> docker run -d -p 8080:80 \
>   -v "$PWD":/var/www/html/custom_apps/vereinsbuchhaltung \
>   nextcloud
> ```
> Anschließend `http://localhost:8080` aufrufen, einrichten, App aktivieren.

### Build-Hinweis (vue-loader)

Das Projekt verwendet Vue 2.7 mit `@nextcloud/webpack-vue-config`. Damit der Build funktioniert, müssen `vue-loader@15` und `vue-template-compiler` explizit in `devDependencies` stehen – neuere `vue-loader`-Versionen erzeugen Vue-3-Render-Funktionen, die mit der Vue-2.7-Runtime inkompatibel sind.

## Erste Schritte

1. **Berechtigungen** (Gear-Icon) → Nutzer oder Gruppen als Buchhalter oder Verwalter eintragen.
2. **Einstellungen → Aus „zero Buchhaltung" importieren** → `.xbuc`-Datei wählen → Vorschau prüfen → Importieren.
   - Mehrere Jahres-Dateien nacheinander importieren: der Merge-Modus (Standard) übernimmt nur fehlende Konten und neue Buchungen.
   - Alternativ: Tab **Konten** → *Standard-Kontenrahmen anlegen* und Konten manuell erstellen.
3. **Einstellungen → Kontoumsätze importieren** → CSV-CAMT-Datei der Bank hochladen.
4. Tab **Buchungen → Zuzuordnen** → jede Bankbuchung einem Gegenkonto zuordnen.
5. Tab **Übersicht** → Dashboard mit KPI-Kacheln und Monatschart.
6. Tab **Berichte** → Auswertung, Kostenstellen, Finanzplan.

## Roadmap

- Belege (PDF/Bild) an Buchungssätze anhängen
- Mehrere Bankkonten per IBAN automatisch zuordnen
- Splittbuchungen (eine Bankbuchung auf mehrere Gegenkonten)
- Export (CSV/PDF) für Kassenprüfung und Jahresabschluss

## Lizenz

AGPL-3.0-or-later
