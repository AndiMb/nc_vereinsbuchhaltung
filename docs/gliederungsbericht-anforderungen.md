# Gliederungsbericht mit Vorlagen – Anforderungen

Stand: 17.10.2026 · Ergebnis eines Brainstormings, Fragen geklärt, noch kein Design.

## Ausgangslage

Ein Verband braucht seine Zahlen in zwei Darstellungen, die es so in der App
nicht gibt (Vorbilder: `example/Ministerium.xlsx`, `example/Verein.xlsx`,
Kontenrahmen `example/Kontendefinition.xlsx`):

- **Zuwendungsgeber (Ministerium):** Einnahmen, dann Ausgaben. Innerhalb davon
  zuerst der ideelle Bereich (nach Hauptgruppe und Titel), dann die
  Titelgruppen/Projekte, je Projekt mit Summe. Am Ende eine Zusammenfassung
  nach Hauptgruppen (Verwaltungseinnahmen, Personalausgaben …).
- **Verband (Vorstand/Mitgliederversammlung):** Einnahmen ideeller Bereich,
  Ausgaben ideeller Bereich, Ergebnis ideeller Bereich; danach je Projekt
  Einnahmen, Ausgaben, Differenz; Ergebnis Zweckbetrieb; Saldo. Ziel: auf
  einen Blick sehen, was der Verband für Projekte „zuschießt“.

Die Kontonummer (`HHH TT [SS]`) trägt bereits alle nötigen Merkmale: Typ
(Einnahme/Ausgabe), Hauptgruppe und Titel (Kontohierarchie), Titelgruppe
(Kostenstelle im Modus `group`). Die Sphäre steht am Konto. Beide
Darstellungen sind damit **dieselben Zahlen in anderer Gliederungsreihenfolge**.

**Entscheidung:** Kein fest einprogrammierter Sonderbericht, sondern ein
allgemeiner, konfigurierbarer *Gliederungsbericht*. Beide Darstellungen
werden als Vorlagen mitgeliefert.

## Begriffe

| Begriff | Bedeutung |
|---|---|
| **Gliederungsmerkmal** | Merkmal, nach dem gruppiert wird: Typ, Sphäre, Berichtsgruppe, Konto (mit Hierarchie). |
| **Berichtsgruppe** | Gruppe innerhalb eines Berichts. Standard: eine je Kostenstelle. Eine Vorlage darf Gruppen umbenennen, zusammenfassen oder aus einzelnen Konten bilden (siehe F7–F9). |
| **Vorlage** | Gespeicherte Berichtsdefinition (Gliederung, Spalten, Zwischensummen, Bezeichnungen). |

## Funktionale Anforderungen

### Gliederung
- **F1** Ein Bericht gliedert Einnahme- und Ausgabekonten über eine frei
  wählbare Reihenfolge von Gliederungsmerkmalen. Mindestens abbildbar:
  - Typ → Sphäre → Berichtsgruppe → Konto (Zuwendungsgeber)
  - Sphäre → Typ → Konto für ideell, Sphäre → Berichtsgruppe → Typ für
    Zweckbetrieb (Verband)
- **F2** Die Gliederung darf sich je Sphäre unterscheiden (Verband: ideeller
  Bereich nach Typ, Zweckbetrieb nach Projekt). Ob das über verschachtelte
  Abschnitte oder eine andere Form gelöst wird, entscheidet das Design.
- **F3** Ideell und Projekt/Zweckbetrieb werden über die **Sphäre am Konto**
  getrennt. Konten ohne Sphäre erscheinen in einem eigenen Abschnitt
  „ohne Sphäre“, damit die Summen vollständig bleiben.
- **F4** Auf Kontoebene folgt der Bericht der Kontohierarchie (Hauptgruppe →
  Titel → Unterkonto) mit wählbarer Tiefe, analog zum Finanzplan
  (`plan | 0 | 1 | 2 | alle`).
- **F5** Je Ebene einstellbar: Zwischensumme ja/nein, Differenz
  Einnahmen − Ausgaben ja/nein (Verband: je Projekt und je Sphäre), eigene
  Bezeichnung der Summenzeile („Summe der Titelgruppe“, „Ergebnis ideeller
  Bereich“).
- **F6** Am Ende optional: Gesamtsummen, Saldo, sowie eine Zusammenfassung
  nach einem weiteren Merkmal (Zuwendungsgeber: nach Hauptgruppe =
  oberste Kontoebene).

### Berichtsgruppen (Umgruppieren im Bericht)
- **F7** Standardmäßig ist jede Kostenstelle eine Berichtsgruppe, mit Code
  und Name der Kostenstelle.
- **F8** Eine Vorlage kann Berichtsgruppen umbenennen und mehrere
  Kostenstellen zu einer Gruppe zusammenfassen (z. B. „Wettbewerbe“).
- **F9** Eine Vorlage kann einzelne Konten (inkl. ihrer Unterkonten) einer
  anderen oder einer eigenen Berichtsgruppe zuordnen, z. B.
  `546 65 01–05` als „Ersatzprojekte für Deutsches Chorfest 2020“, oder
  Jahreskonten (`111 51 2021`) mit ihrem Projekt zusammen ausweisen.
- **F10** Kein Konto darf doppelt oder gar nicht gezählt werden. Nicht
  ausdrücklich zugeordnete Konten fallen in die Gruppe ihrer Kostenstelle.
- **F11** Reihenfolge der Berichtsgruppen: standardmäßig nach Code,
  in der Vorlage änderbar.
- **F12** Leere Gruppen (alle Spalten 0) können ausgeblendet werden.

### Spalten
- **F13** Wählbare Wertspalten: Plan und Ist der gewählten Periode, Plan und
  Ist beliebiger weiterer Perioden (z. B. Plan 2025 · Ist 2025 · Plan 2026 ·
  Ist 2026). Spaltenköpfe nennen Art und Periode.
- **F14** Plan- und Istwerte werden wie im Finanzplan über die
  Kontohierarchie summiert (gleiche Zahlen wie dort).
- **F15** Optional: Differenzspalte Plan ↔ Ist (Logik und Einfärbung wie im
  Finanzplan).
- **F23** Je Vorlage schaltbar: Plannotizen werden als Erläuterungszeile
  unter dem Konto ausgegeben. In der Vorlage „Zuwendungsgeber“ ist der
  Schalter an, in der Vorlage „Verband“ aus.

### Vorlagen
- **F16** Mitgeliefert werden zwei Vorlagen:
  „Haushaltsplan nach Titelgruppen (Zuwendungsgeber)“ und
  „Ergebnis je Bereich und Projekt“.
- **F17** Vorlagen lassen sich kopieren, anpassen, umbenennen und löschen.
  Mitgelieferte Vorlagen bleiben als Ausgangspunkt erhalten.
- **F18** Vorlagen werden für den Verein gespeichert (wie die übrigen Daten).
- **F19** Vorlagen enthalten keine fest verdrahteten Kontonummern, außer
  den in F9 bewusst gewählten Konten. Dadurch funktionieren die
  mitgelieferten Vorlagen mit jedem Kontenrahmen, der Sphären und
  Kostenstellen gepflegt hat.

### Ausgabe
- **F20** Anzeige als Tabelle im Bereich *Berichte* mit Auswahl von Vorlage
  und Periode(n).
- **F21** Druck-/PDF-Ansicht über die bestehende Druckseite (Logo,
  Akzentfarbe, Vereinsname) mit Titel der Vorlage und Stand-Datum.
- **F22** CSV-Export mit denselben Zeilen und Spalten.

## Nicht-funktionale Anforderungen

- **N1** Die Zahlen stimmen mit Saldenliste, Finanzplan und
  Kostenstellen-/Sphärenbericht überein (gleiche Summenlogik).
- **N2** Rechte wie bei den übrigen Berichten.
- **N3** Zweisprachig (DE/EN), auch die Namen der mitgelieferten Vorlagen.
- **N4** Vereine ohne Sphären/Kostenstellen sehen einen verständlichen
  Hinweis statt eines leeren Berichts.

## User Stories / Akzeptanzkriterien

- **US1** Als Kassierer drucke ich den Haushaltsplan für das Ministerium:
  Einnahmen und Ausgaben, darin zuerst ideell, dann je Titelgruppe mit
  Summe, am Ende Gesamtsummen und Zusammenfassung nach Hauptgruppen.
  *Akzeptanz:* Mit dem Kontenrahmen aus `example/` entspricht die Gliederung
  `example/Ministerium.xlsx`. Die Gesamtsummen entsprechen dem Finanzplan.
- **US2** Als Vorstand sehe ich in der Verbandsdarstellung das Ergebnis des
  ideellen Bereichs, die Differenz je Projekt und das Ergebnis des
  Zweckbetriebs. *Akzeptanz:* Saldo = Ergebnis ideell + Ergebnis
  Zweckbetrieb = Einnahmen − Ausgaben gesamt.
- **US3** Als Kassierer fasse ich in meiner Vorlage die Ersatzprojekte
  (`546 65 01–05`) zu einer Gruppe zusammen, ohne Kostenstellen oder
  Konten zu ändern.
- **US4** Als anderer Verein nutze ich die mitgelieferten Vorlagen mit
  meinem eigenen Kontenrahmen, sofern Sphären und Kostenstellen gepflegt sind.

## Geklärte Fragen

1. **Planstände:** Die erste Version verwendet nur den aktuellen Plan. Ein
   eingefrorener Planstand (`BudgetSnapshot`) als Spalte ist eine spätere
   Ausbaustufe (siehe *Nicht im Umfang*).
2. **Erläuterungszeilen:** Siehe F23. Einzelposten, die als Unterkonten
   existieren, erscheinen über die Kontotiefe (F4). Plannotizen lassen sich
   je Vorlage als Erläuterung einblenden.
3. **Bezeichnungen:** Im Bericht steht der Kontoname. Abweichende
   Bezeichnungen löst der Verein durch Umbenennen der Konten. Es gibt keine
   Überschreibung je Vorlage und kein eigenes Feld für einen Berichtsnamen.
4. **Rücklagen und Finanzierung:** `352 01` (Entnahme aus Rücklage) und
   `912` (Zuführung) sind gewöhnliche Einnahme- bzw. Ausgabekonten. Sie
   laufen nach Sphäre und Kostenstelle mit, ohne eigenen Abschnitt und ohne
   Sonderbehandlung im Ergebnis.
5. **Verwendungsnachweis:** Es gibt keinen eigenen Bericht dafür. Dieselbe
   Vorlage mit den Spalten Plan, Ist und Differenz (F13, F15) ergibt den
   Nachweis.
6. **Exportformat:** Druck/PDF und CSV (F21, F22). Es gibt keinen XLSX-Export.

## Nicht im Umfang

- Eingefrorener Planstand (`BudgetSnapshot`) als Spaltenquelle
- XLSX-Export
- Abweichende Zeilenbezeichnungen je Vorlage
