# Gliederungsbericht: Vorlagen löschen und neu anlegen – Anforderungen

Stand: 18.10.2026 · Ergebnis eines Brainstormings, noch kein Design.
Baut auf `docs/gliederungsbericht-anforderungen.md` und
`docs/gliederungsbericht-design.md` auf (Version 0.38.0).

## Ausgangslage

Seit 0.38.0 gibt es im Reiter **Berichte → Gliederung** zwei mitgelieferte
Vorlagen („Haushaltsplan nach Titelgruppen (Zuwendungsgeber)“, „Ergebnis je
Bereich und Projekt“). Sie stehen im Code, sind schreibgeschützt und lassen
sich nur kopieren. Eigene Vorlagen entstehen ausschließlich als Kopie.

Wunsch: Die beiden Vorlagen sollen sich **löschen** lassen, und man soll eine
Vorlage **bei null** anlegen können, ohne zuerst eine Vorlage zu kopieren.

## Entscheidungen

| Frage | Entscheidung |
|---|---|
| Was heißt „löschen“ bei den mitgelieferten Vorlagen? | Sie werden **einmalig in eigene Vorlagen umgewandelt**: Sie liegen danach als normale, änderbare und löschbare Vorlagen in der Datenbank. Spätere Verbesserungen aus Updates kommen nicht automatisch an. |
| Womit startet eine neue Vorlage? | Mit einem **minimalen Gerüst**, das sofort speicherbar ist (F6). |
| Wer darf löschen und anlegen? | **Buchhalter und Verwalter**, wie bei den übrigen Vorlagen. |
| Lassen sich die Standardvorlagen zurückholen? | **Ja, per Knopf** – in der jeweils aktuellen Fassung (F8–F10). |
| In welcher Sprache werden die Texte festgeschrieben? | In der **Sprache der Person, die das Anlegen auslöst**; danach frei umbenennbar. |
| Was passiert bei „Alle Daten löschen“? | Die Vorlagen werden gelöscht, danach stehen **wieder die beiden Standardvorlagen** bereit. |

## Funktionale Anforderungen

### Standardvorlagen als eigene Vorlagen
- **F1** Beim ersten Öffnen des Reiters „Gliederung“ in einem Verein, in dem
  noch keine Standardvorlagen angelegt wurden, legt die App die beiden
  Standardvorlagen als eigene Vorlagen an. Name und Texte stehen in der
  Sprache der Person, die den Reiter öffnet.
- **F2** Das geschieht genau einmal je Verein. Löscht jemand später alle
  Vorlagen, werden sie beim nächsten Öffnen **nicht** neu angelegt.
- **F3** Bestehende Installationen (0.38.0) werden beim Update genauso
  behandelt: Die schon vorhandenen eigenen Kopien bleiben, die beiden
  Standardvorlagen kommen beim ersten Öffnen als eigene Vorlagen hinzu.
- **F4** Danach gibt es keine schreibgeschützten Vorlagen mehr. Jede Vorlage
  lässt sich bearbeiten, kopieren und löschen. Der Zusatz „(eigene Vorlage)“
  in der Auswahl entfällt.
- **F5** Ein gespeicherter oder verlinkter Verweis auf eine frühere
  mitgelieferte Vorlage (`builtin:…`, etwa in der zuletzt gewählten Vorlage
  im Browser) führt nicht zu einem Fehler. Die Ansicht wählt dann die erste
  vorhandene Vorlage.

### Neue Vorlage bei null
- **F6** Neben „Kopieren und anpassen“ gibt es **„Neue Vorlage“**. Der Dialog
  öffnet mit:
  - Name leer (Pflichtfeld)
  - einem Abschnitt „Konten“ mit der Gliederungsebene Konto (bis zur
    Planungsebene)
  - den Spalten Plan und Ist des gewählten Zeitraums
  - der Gesamtzeile „Saldo“
  - ohne Plannotizen und ohne Gruppen
- **F7** „Neue Vorlage“ und „Kopieren und anpassen“ stehen auch dann zur
  Verfügung, wenn noch keine oder keine Vorlage mehr existiert (Kopieren nur
  mit gewählter Vorlage).

### Standardvorlagen zurückholen
- **F8** Ein Knopf **„Standardvorlagen hinzufügen“** legt die beiden
  Standardvorlagen in ihrer aktuellen Fassung erneut als eigene Vorlagen an.
- **F9** Vorhandene Vorlagen bleiben dabei unberührt. Gibt es schon eine
  Vorlage mit demselben Namen, entsteht trotzdem eine weitere; der Dialog
  bzw. die Rückmeldung weist darauf hin, dass dann zwei gleichnamige
  Vorlagen existieren.
- **F10** Der Knopf liegt nicht prominent in der Kopfzeile, sondern in einem
  Menü neben der Vorlagenauswahl (z. B. „Weitere Aktionen“) und im leeren
  Zustand (F11).

### Leerer Zustand
- **F11** Gibt es keine Vorlage, zeigt der Reiter statt der Tabelle einen
  Hinweis mit den Knöpfen „Neue Vorlage“ und „Standardvorlagen hinzufügen“.
  Druck- und CSV-Links sind dann ausgeblendet. Revisoren sehen nur den
  Hinweis, dass noch keine Vorlage angelegt ist.

### Löschen
- **F12** Löschen fragt wie bisher nach („Vorlage … wirklich löschen?“) und
  gilt für jede Vorlage, auch für die beiden aus F1.
- **F13** Nach dem Löschen wählt die Ansicht die erste verbleibende Vorlage
  oder zeigt den leeren Zustand.

### Zurücksetzen
- **F14** „Alle Daten löschen“ entfernt alle Vorlagen und setzt den Vermerk
  aus F2 zurück, sodass beim nächsten Öffnen wieder die beiden
  Standardvorlagen angelegt werden.

## Nicht-funktionale Anforderungen

- **N1** Das einmalige Anlegen (F1) darf nicht doppelt passieren, auch wenn
  zwei Personen den Reiter gleichzeitig öffnen.
- **N2** Rechte unverändert: Lesen ab Revisor, Anlegen, Ändern, Löschen und
  „Standardvorlagen hinzufügen“ ab Buchhalter. Öffnet ein Revisor den Reiter
  als Erster, werden die Standardvorlagen trotzdem angelegt (F1 ist eine
  Systemaktion, keine Bearbeitung durch den Revisor).
- **N3** Zweisprachig (DE/EN), Handbuch Kapitel 7.4 und Changelog anpassen.
- **N4** Die Berechnung der Berichte ändert sich nicht.

## User Stories / Akzeptanzkriterien

- **US1** Als Kassierer öffne ich nach dem Update den Reiter „Gliederung“ und
  finde die beiden Vorlagen wie gewohnt vor; jetzt kann ich sie direkt
  bearbeiten und brauche keine Kopie mehr.
- **US2** Als Kassierer lösche ich die Vorlage, die wir nicht brauchen. Sie
  kommt beim nächsten Öffnen nicht zurück.
- **US3** Als Kassierer lege ich mit „Neue Vorlage“ eine Vorlage an, gebe nur
  einen Namen ein, speichere und sehe sofort alle Konten mit Plan, Ist und
  Saldo.
- **US4** Als Kassierer habe ich alle Vorlagen gelöscht. Der Reiter zeigt
  einen Hinweis; mit „Standardvorlagen hinzufügen“ sind beide wieder da.
- **US5** Nach „Alle Daten löschen“ stehen wieder die beiden
  Standardvorlagen bereit.

## Offene Fragen

Keine. Bewusste Folgen der Entscheidungen:

- Verbesserungen an den Standardvorlagen in späteren Versionen erreichen
  bestehende Vereine nur über „Standardvorlagen hinzufügen“ (F8).
- Gespeicherte Druck- oder CSV-Links mit `template=builtin:…` funktionieren
  nach dem Update nicht mehr (404); die Oberfläche selbst fällt sauber auf
  eine vorhandene Vorlage zurück (F5).
