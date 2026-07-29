# Changelog

Alle nennenswerten Änderungen an dieser App werden hier dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

Der App Store zeigt zu jedem Release den hier passenden Abschnitt an – zu jeder
veröffentlichten Version muss es daher eine Überschrift `## [x.y.z]` geben.

## [Unreleased]

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

[Unreleased]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.10.69...HEAD
[0.10.69]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.10.68...v0.10.69
[0.10.68]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.10.63...v0.10.68
[0.10.63]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.10.46...v0.10.63
[0.10.0]: https://github.com/AndiMb/nc_vereinsbuchhaltung/releases/tag/v0.10.17
