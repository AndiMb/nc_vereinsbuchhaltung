# Vereinsbuchhaltung – Nextcloud-App

Eine schlanke Buchhaltungs-App für Vereine, direkt in Nextcloud. Umsätze aus dem
Onlinebanking werden als **CSV-CAMT** importiert, dublettenfrei übernommen, einem
**frei definierbaren Kontenrahmen** zugeordnet und nach den Regeln der **doppelten
Buchführung** (Soll/Haben) verbucht.

## Funktionsumfang (v0.1)

- **Import** von Kontoumsätzen im CSV-CAMT-Format (Sparkasse, Volksbank, …)
  - automatische Erkennung von Trennzeichen und Zeichensatz (UTF-8 / Windows-1252)
  - deutsches Zahlen- und Datumsformat (`1.234,56` / `TT.MM.JJJJ`)
- **Dublettenprüfung** per stabilem Hash – es werden nur neue Buchungen übernommen
- **Kontenrahmen** frei pflegbar; ein Standard-Rahmen für Vereine ist per Klick anlegbar
- **Zuordnung** jeder Bankbuchung zu einem Gegenkonto (= Kategorie)
- **Doppelte Buchführung**: aus jeder Zuordnung entsteht ein Buchungssatz (Soll/Haben)
- **Auto-Regeln** zur automatischen Zuordnung wiederkehrender Buchungen
- **Auswertung**: Saldenliste je Konto sowie Einnahmen/Ausgaben/Ergebnis

## Architektur

```
vereinsbuchhaltung/
├── appinfo/           info.xml, routes.php
├── lib/
│   ├── AppInfo/       Application.php
│   ├── Controller/    Page, Account, Transaction, Import, Journal, Rule
│   ├── Db/            Entities + QBMapper (accounts, bank_tx, journal, …)
│   ├── Migration/     Schema (Tabellen vbh_*)
│   └── Service/       CamtCsvParser, ImportService, AccountService, BookingService
├── src/               Vue-Frontend (App.vue, api.js, main.js)
├── templates/         main.php
└── tests/             Unit-Test + Beispiel-CSV
```

**Kernfluss:** CSV hochladen → `CamtCsvParser` parst → `ImportService` filtert per
Hash gegen die DB (nur Neues) → optional `BookingService` über Regeln auto-zuordnen
→ manuelle Zuordnung im Tab *Buchungen* → `BookingService` erzeugt den doppelten
Buchungssatz → `JournalController` liefert Salden/Auswertung.

### Datenmodell

| Tabelle | Zweck |
|---|---|
| `vbh_accounts` | Kontenrahmen (Nr., Name, Typ, Kategorie, Bankkonto-Flag) |
| `vbh_bank_tx` | importierte Bankbuchungen inkl. Dedup-`hash` und Status |
| `vbh_journal` / `vbh_journal_line` | Buchungssätze (Kopf) und Soll/Haben-Zeilen |
| `vbh_imports` | Import-Protokoll (neu/Dubletten je Datei) |
| `vbh_rules` | Auto-Zuordnungsregeln |

Beträge werden durchgängig als **Integer in Cent** gespeichert (keine Float-Rundungsfehler).

## Installation / Entwicklung

Voraussetzungen: PHP ≥ 8.1, Composer, Node ≥ 20 / npm ≥ 10, eine Nextcloud-Instanz
(≥ 28). Lokal am einfachsten über das offizielle Docker-Dev-Setup.

```bash
# 1. PHP-Autoloader erzeugen
composer install --no-dev

# 2. Frontend bauen
npm install
npm run build           # erzeugt js/vereinsbuchhaltung-main.js

# 3. App nach Nextcloud bringen
#    Ordner "vereinsbuchhaltung" nach <nextcloud>/apps/ kopieren oder verlinken
#    dann in Nextcloud unter "Apps" aktivieren – die DB-Migration läuft automatisch.
```

> **Composer fehlt?** `php -r "copy('https://getcomposer.org/installer','c.php');"`
> dann `php c.php` und `php composer.phar install`.

> **Keine lokale Nextcloud?** Schnellster Weg zum Testen:
> ```bash
> docker run -d -p 8080:80 -v "$PWD":/var/www/html/custom_apps/vereinsbuchhaltung nextcloud
> ```
> Anschließend `http://localhost:8080`, einrichten, App aktivieren.

## Erste Schritte in der App

1. Tab **Kontenrahmen** → *Standard-Kontenrahmen anlegen* (oder eigene Konten anlegen).
   Mindestens ein Konto muss als **Bankkonto** markiert sein.
2. Tab **Import** → CSV-CAMT-Datei wählen → Vorschau prüfen → *importieren*.
3. Tab **Buchungen** → offene Buchungen einem Gegenkonto/Kategorie zuordnen.
4. Tab **Auswertung** → Salden und Ergebnis einsehen.

## Tests

```bash
# Parser ohne Nextcloud (reines PHP)
php tests/manual_parser_check.php       # falls vorhanden

# PHPUnit (innerhalb einer Nextcloud-Dev-Umgebung)
phpunit tests/unit/CamtCsvParserTest.php
```

## Roadmap / nächste Schritte

- Mehrere Bankkonten anhand der eigenen IBAN automatisch zuordnen
- Splittbuchungen (eine Bankbuchung auf mehrere Gegenkonten)
- Eröffnungsbilanz / Anfangsbestände
- Export (CSV/PDF) für Kassenprüfung und Jahresabschluss
- Belege aus der Nextcloud-Dateiablage an Buchungen anhängen
- Mandantenfähigkeit pro Verein (aktuell pro Nextcloud-Nutzer getrennt)

## Lizenz

AGPL-3.0-or-later
