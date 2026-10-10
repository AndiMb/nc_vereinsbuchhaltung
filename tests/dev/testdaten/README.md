# Testdaten für den manuellen Komplett-Test

Diese Dateien gehören zum Seeder unter `tests/dev/` (Bankdatei: Import im Bankabgleich, Listen: CSV-Import der Mitglieder). Sie sind aus dem Seeder-Werkzeug unter `tests/dev/` und sind auf das Szenario vom 05.10.2026 zugeschnitten. Alle Namen, IBANs und Adressen sind erfunden.

| Datei | Wofür | Wo |
|---|---|---|
| `mitglieder-import.csv` | gültige Beispielliste, sechs neue Mitglieder | Mitglieder → Liste einlesen (Prüflauf, Übernehmen) |
| `mitglieder-import-fehler.csv` | absichtlich fehlerhafte Zeilen für den Prüflauf | Mitglieder → Liste einlesen (Fehlerdatei) |
| `bank-oktober-2026.camt053.xml` | Kontoauszug zum eingereichten Oktoberlauf | Buchungen → Kontoauszug importieren |

## Die Bankdatei neu erzeugen

Die Bankdatei passt nur zum Seeder-Stand. Nach einem erneuten Seeden bleibt sie gültig, weil die Kennungen deterministisch sind. Hast du die Datenbank verändert oder den Oktoberlauf neu erzeugt, baust du sie neu (der Seeder muss vorher gelaufen sein):

```
/Users/FlorianLudwig/Projekte/nextcloud-apps/vereinsbuchhaltung/tests/dev/make-camt053.sh
```

Die Befehle für den Seeder selbst (`--check`, `--wipe`, `--wipe-bank`, `--purge`) und den Tageslauf (`run-jobs.sh`) stehen in `tests/dev/README.md`.

## Hinweise

- Der Import prüft zuerst nur („Prüfen“) und legt erst nach „Zeilen übernehmen“ etwas an. Nach dem Import der Fehlerdatei sind ein paar Mitglieder angelegt: räume sie mit dem Seeder (`--wipe`) wieder weg, bevor du etwas wiederholst.
- Startdaten in den CSV-Dateien (zum Beispiel `01.12.2026`) dürfen nicht in der Vergangenheit liegen. Läuft dein Test deutlich nach dem 05.10.2026, passe die Daten vorher an.
- Die Bankdatei lässt sich nur einmal importieren: Beim zweiten Mal meldet die App, dass alle Buchungen schon importiert wurden (Dublettenprüfung). Zum Wiederholen räumt `--wipe --wipe-bank` auch die importierten Umsätze weg.
