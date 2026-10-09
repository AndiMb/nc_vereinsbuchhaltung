# Mitgliederliste einlesen – Vorlage und Hinweise

Die Vorlage `vorlage-mitglieder-import.csv` ist die Datei für **Beiträge → Mitglieder → „Liste einlesen“**.
Sie ist UTF-8 mit Semikolon als Trennzeichen und lässt sich direkt in Excel/LibreOffice öffnen. Die vier
Beispielzeilen zeigen die häufigsten Fälle; ersetze sie durch eure Daten. Die App bringt unter
„Liste einlesen“ → „Vorlage herunterladen“ eine kürzere Fassung mit, diese hier ist ausführlicher.

## Spalten

| Spalte | Pflicht? | Was hineingehört |
|---|---|---|
| **Name** | ja | „Vorname Nachname“ in einer Zelle. Die App teilt am **ersten** Leerzeichen („Anna Maria Beispiel“ → Vorname „Anna“, Nachname „Maria Beispiel“); ein Name ohne Leerzeichen gilt als Organisation. |
| Mitgliedsnummer | empfohlen | Eure bisherige Nummer. Sie ist der Schlüssel gegen Dubletten: Eine Nummer, die es schon gibt, wird übersprungen, nie überschrieben. |
| E-Mail | für Lastschrift nötig | Die Vorabinfo geht per Mail. **Zeilen ohne E-Mail bekommen die Zahlungsart Überweisung, auch mit Mandat.** |
| IBAN | nein | Nur mit IBAN entsteht ein Mandat. Leerzeichen sind egal. **Mit IBAN ist „Mandat am“ Pflicht.** |
| BIC | nein | Fast nie nötig. |
| Kontoinhaber | nein | Nur wenn er vom Mitglied abweicht (z. B. Eltern zahlen für das Kind). |
| Mandat am | bei IBAN | Datum der Unterschrift auf dem Papiermandat, `TT.MM.JJJJ`. Das Mandat wird sofort **aktiv** angelegt. |
| Mandatsreferenz | nein | Eure alte Referenz, wenn ihr sie behalten wollt. Leer = die App vergibt `M-<Nr>`. |
| Beitragsgruppe | bei Beitrag | Name einer **vorhandenen** Beitragsgruppe (Reiter „Beitragsgruppen“), genau so geschrieben. |
| Betrag | bei Beitrag | **Der Monatsbeitrag**, unabhängig vom Turnus. Jahresbeitrag 60 € → `5,00` und Frequenz `jährlich`. Leer = Standardbeitrag aus den Einstellungen. |
| Frequenz | bei Beitrag | `monatlich`, `vierteljährlich`, `halbjährlich` oder `jährlich`. |
| Start | bei Beitrag | Erster Beitragsmonat, `TT.MM.JJJJ`. **Nicht in der Vergangenheit** (Zuweisungen gelten nie rückwirkend). |

Eine Zeile ohne IBAN und ohne Beitrag legt nur das Mitglied an (wie „Musikhaus Beispiel GmbH“ oben; Vorsicht: mit mehreren Wörtern im Namen gilt es als Person, siehe unten). Zusätzliche Spalten
werden ignoriert, Reihenfolge und Groß-/Kleinschreibung der Überschriften sind egal.

## Ablauf

1. **Beitragsgruppen zuerst anlegen** (Beiträge → Beitragsgruppen), mit denselben Namen wie in der Spalte.
2. Datei einlesen → **„Prüfen“**: Die Vorschau sagt, was entstehen würde (Mitglieder, Mandate, Zuweisungen,
   übersprungene und fehlerhafte Zeilen). Es wird noch nichts gespeichert.
3. Erst wenn das passt, bestätigen (bei Mandaten mit der ausdrücklichen Bestätigung, dass die Unterschriften
   vorliegen) und anlegen.
4. Die Mandate sind „Papier, ohne Nachweis“: Ab jetzt weist die Aufgabenliste darauf hin, den unterschriebenen
   Zettel noch hochzuladen. Das lässt sich später je Mitglied nachholen.

## Was der Import (noch) nicht kann

- **Kein Eintrittsdatum:** „Beigetreten am“ wird auf den Importtag gesetzt. Historische Eintritte müsst ihr
  in der Akte nachtragen.
- **Keine Adressdaten:** Straße, PLZ, Ort und Telefon gibt es in der Akte, aber nicht in der CSV.
- **Das Prüfen meldet nicht alles:** Eine ungültige IBAN-Form, ein Startdatum in der Vergangenheit und eine
  doppelte Mitgliedsnummer *innerhalb der Datei* fallen erst beim Anlegen auf, nicht in der Vorschau. Die
  IBAN-Prüfsumme wird nirgends geprüft (nur die Form).
- **Er legt nur an:** Bestehende Mitglieder werden nie geändert. Ein zweiter Lauf mit derselben Datei
  überspringt alles, was es schon gibt.

Tipp: Teste zuerst mit **zwei oder drei echten Zeilen**, schau dir die Akte an und lies dann den Rest ein.
