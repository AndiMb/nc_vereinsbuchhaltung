# Changelog

Alle nennenswerten Änderungen an dieser App werden hier dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

Der App Store zeigt zu jedem Release den hier passenden Abschnitt an – zu jeder
veröffentlichten Version muss es daher eine Überschrift `## [x.y.z]` geben.

## [Unreleased]

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

[Unreleased]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.10.69...HEAD
[0.10.69]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.10.68...v0.10.69
[0.10.68]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.10.63...v0.10.68
[0.10.63]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.10.46...v0.10.63
[0.10.0]: https://github.com/AndiMb/nc_vereinsbuchhaltung/releases/tag/v0.10.17
