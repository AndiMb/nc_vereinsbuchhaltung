# Finanzplan mit Vergleichsperiode – Design (Issue #114)

Stand: 17.10.2026 · Grundlage: `finanzplan-vergleichsperiode-anforderungen.md`
(Kennungen F…/N… beziehen sich darauf).

## Leitgedanke

Bildschirm, Druck und CSV rechnen heute an einer Stelle,
`BudgetOverviewService::build()`. Der Vergleich kommt genau dort hinzu, und
zwar als **weitere Wertspalte im Planbaum**. `BudgetTree::build()` nimmt
bereits beliebig viele Spalten entgegen und summiert jede über die
Nachfahren. Daraus ergeben sich F6 (Σ) und die Summen (F14) ohne neue
Baumlogik. Δ und Δ % werden ebenfalls auf dem Server berechnet, damit alle
drei Ausgaben dieselben Zahlen zeigen.

```
             ┌─────────── GET /api/budget?period&compare&basis ──┐
ReportsTab ──┤                                                   ├─► BudgetController::index
 (Auswahl)   ├─ /export/budget?…     ─► CsvExportService::budget ┤
             └─ /export/finanzplan?… ─► FinanzplanRenderer       ┘
                                              │
                                              ▼
                       BudgetOverviewService::build(user, period, compare, basis)
                          ├─ PeriodService::previous()        (Vorbelegung F2)
                          ├─ BudgetMapper::findByPeriod(cmp)  (basis = plan)
                          ├─ JournalLineMapper::sumByAccount  (basis = actual)
                          ├─ BudgetTree::build(…, [plan, actual, compare])
                          └─ BudgetComparison::delta()         (F7/F8)
```

## 1. Rechenregeln: `BudgetComparison` (neu, statisch)

`lib/Service/BudgetComparison.php`, wie `BudgetTree` und `LedgerAggregator`
ohne Datenbank, alle Beträge in Cent.

```php
final class BudgetComparison {
    public const BASIS_ACTUAL = 'actual';
    public const BASIS_PLAN = 'plan';

    /** Δ = Plan − Vergleich (F7). */
    public static function delta(int $plan, int $compare): int;

    /** Δ % auf eine Nachkommastelle; null bei Vergleich 0 (F8). */
    public static function percent(int $plan, int $compare): ?float;
        // round(($plan - $compare) / abs($compare) * 100, 1)

    /** Erlaubte Basis, sonst BASIS_ACTUAL. */
    public static function basis(?string $basis): string;
}
```

Δ bezieht sich in jeder Zeile auf **Plan Σ**. Oberkonten, deren Plan
darunter liegt, haben damit einen aussagekräftigen Wert.

*Korrektur bei der Umsetzung:* Wie bei der Plan/Ist-Differenz gilt das
`comparable`-Kriterium. Ein Unterkonto unter einem Planposten hat keinen
eigenen Plan (Plan Σ = 0) und zeigte sonst je Zeile −100 %. Es zeigt nur
den Vergleichswert, Δ und Δ % sind `null` bzw. „—“. F10 ist entsprechend
eingegrenzt.

## 2. Service: `BudgetOverviewService::build()`

Die Signatur wird erweitert. Bestehende Aufrufer ändern sich nicht, weil die
neuen Parameter Vorgabewerte haben.

```php
public function build(
    string $userId,
    ?int $periodId,
    ?int $compareId = null,     // null = Vorbelegung, 0 = kein Vergleich, >0 = Periode
    string $basis = 'actual',
): array
```

Ablauf:

1. Vergleichsperiode auflösen:
   - `null`: `PeriodService::previous($period)`. Gibt es keine, ist der Vergleich aus.
   - `0`: Vergleich aus.
   - `>0`: `PeriodService::find()` (unbekannte ID → 404). Ist es die gewählte
     Periode selbst, ist der Vergleich aus.
2. Spalte `compare` je budgetierbarem Konto befüllen:
   - `actual`: `sumByAccount(start, end)` der Vergleichsperiode, dann
     `LedgerAggregator::net()`. Das ist derselbe Weg wie beim eigenen Ist.
   - `plan`: `BudgetMapper::findByPeriod(cmpId)[id]['amount']`.
3. `BudgetTree::build(…, ['plan' => …, 'actual' => …, 'compare' => …])`. Die
   Spalte wird nur übergeben, wenn ein Vergleich aktiv ist. `annotate()` bleibt
   unverändert, weil es nur `plan` und `actual` liest.
4. Für die Summen kommt `BudgetTree::totals($nodes, 'compare')` hinzu.

Rückgabe zusätzlich:

```php
'comparison' => null | [
    'period' => Period,
    'basis'  => 'actual'|'plan',
],
'totals' => [ …, 'compareIncome' => int, 'compareExpense' => int ],  // nur bei aktivem Vergleich
```

Zwei bekannte Lücken nehmen wir bewusst in Kauf:
- Konten haben genau einen Typ und eine Hierarchie, und zwar die aktuelle.
  Wurde ein Konto seit der Vergleichsperiode umgehängt, zählt sein
  Vorjahreswert unter dem neuen Elternkonto.
- Ein Konto, das heute *nicht mehr budgetierbar* ist (Typ geändert), fehlt im
  Baum ganz. Der Teil „nicht mehr budgetierbar“ in F15 ist damit nicht
  umsetzbar, siehe Abschnitt 8.

## 3. API: `GET /api/budget`

Neue optionale Query-Parameter:

| Parameter | Werte | Bedeutung |
|---|---|---|
| `compare` | fehlt | Vorbelegung: Vorperiode (F2) |
| | `0` | kein Vergleich |
| | Perioden-ID | diese Periode |
| `basis` | `actual` (Vorgabe) \| `plan` | F3 |

Antwort, nur die Ergänzungen. Beträge wie bisher in Euro:

```jsonc
{
  "comparison": null | {
    "periodId": 7, "label": "2025", "basis": "actual"
  },
  "rows": [{
    // … bestehende Felder …
    "active": true,            // F15
    "compareSum": 1100.00,     // Σ der Vergleichsspalte; fehlt ohne Vergleich
    "compareDelta": 100.00,    // Plan Σ − compareSum
    "comparePercent": 9.1      // null bei compareSum = 0
  }],
  "totals": {
    // … bestehend …
    "compareIncome": 12300.00, "compareExpense": 11800.00, "compareResult": 500.00,
    "compareIncomePercent": 4.2, "compareExpensePercent": -1.3, "compareResultPercent": null
  }
}
```

Die Prozentwerte der Summen kommen über `BudgetComparison::percent()` vom
Server. Plan-Ergebnis gegen Vergleichs-Ergebnis ist dabei ein normaler Fall
der Formel.

Rechte bleiben unverändert, es ist dieselbe Route wie bisher (N1).

## 4. Export-Routen

`GET /api/export/budget` und `GET /api/export/finanzplan` bekommen dieselben
Parameter `compare` und `basis`. Die Oberfläche übergibt dabei **immer
explizit** die aktuelle Wahl, also die ID oder `0`. So bekommt der Druck nie
stillschweigend eine andere Vorbelegung als der Bildschirm (F12).

### CSV (F13)

Die bestehenden zwölf Spalten bleiben in Position und Benennung gleich. Bei
aktivem Vergleich werden drei Spalten **hinten angehängt**, damit vorhandene
Excel-Auswertungen, die über Spaltenpositionen arbeiten, nicht brechen:

`Ist 2025 Σ (EUR)` · `Δ zu 2025 (EUR)` · `Δ zu 2025 (%)`

Bei Basis Plan heißt die erste Spalte `Plan 2025 Σ (EUR)`. Δ % steht
**ohne** Pluszeichen in der CSV, weil der CSV-Schutz gegen Formel-Injection
sonst ein Hochkomma voranstellt (`'+9,1`). Die drei
Summenzeilen am Ende bekommen die passenden Werte in denselben Spalten. Die
Leerzeile `array_fill(0, 12, '')` wird auf 15 erweitert. Ohne Vergleich ist
das Format byte-gleich zu heute.

### Druck (F12)

- In der Kopfzeile kommt zu den Meta-Angaben `Vergleich: Ist 2025` hinzu, bzw.
  `Vergleich: Plan 2025`.
- Jede Abschnittstabelle bekommt drei zusätzliche Spalten nach `Ist/Plan`:
  Vergleichswert, Δ, Δ %. Dasselbe gilt für die Summen- und Ergebniszeilen.
- Breite: Mit dem Vergleich hat die Tabelle acht Zahlenspalten. Nur bei
  aktivem Vergleich setzt der Renderer deshalb einen eigenen `<style>` mit
  `@page { size: A4 landscape }` und `body { max-width: 297mm }`.
  `PrintableReportPage` bleibt unverändert. Ohne Vergleich ändert sich am
  Ausdruck nichts.
- Das Vorzeichen wird ausgeschrieben (`+100,00`). Die Kennzeichnung im
  Ausdruck hängt nicht an der Farbe, was zum Schwarzweiß-Anspruch des
  Renderers passt.

## 5. Frontend

### Zustand

Der Zustand liegt in `App.vue`, weil dort `loadBudget()` lebt (wie `reportView`):

```js
budgetCompare: null,          // null = Vorbelegung des Servers, 0 = aus, sonst ID
budgetCompareBasis: 'actual',
```

- `loadBudget()` gibt beide Werte an `api.budget(period, compare, basis)`
  weiter.
- Ein Watcher auf `selectedPeriodId` setzt beides auf `null` und `'actual'`
  zurück (F4). Danach liefert der Server die Vorperiode in
  `budgetData.comparison`.
- Nach dem Speichern eines Planwerts (`budget-changed` → `loadBudget()`)
  bleibt die Wahl erhalten. Dadurch ist AK 8 erfüllt.
- ReportsTab meldet Änderungen per `update:budget-compare` und
  `update:budget-compare-basis`, so wie schon `update:report-view`.

### Bedienelemente (ReportsTab, über den Summenkacheln)

```
Vergleich mit [ 2025            ▾ ]   ( Ist | Plan )
```

- Das Dropdown ist ein `NcSelect`. Optionen: „Kein Vergleich“ und alle
  Perioden aus `usePeriods().state.periods` außer der gewählten, neueste
  zuerst. Der angezeigte Wert stammt aus `budgetData.comparison?.periodId ?? 0`,
  also aus dem, was der Server tatsächlich verwendet hat.
- Den Umschalter Ist/Plan bilden zwei `NcCheckboxRadioSwitch type="radio"`
  (Knopfoptik). Bei „Kein Vergleich“ ist er deaktiviert.
- Die Druck- und CSV-URLs (`exportBudgetUrl`, `budgetPrintUrl`) hängen
  `compare` und `basis` an.

### Summenkacheln (F14)

Unter jedem `<strong>` steht bei aktivem Vergleich eine zweite, kleinere
Zeile:

```
Ausgaben (Plan / Ist)
11.500,00 € / 9.820,00 €
Ist 2025: 11.800,00 € · Δ −2,5 %       ← Klasse aus comparisonClass()
```

### Tabelle (`BudgetTreeTable.vue`)

Neue Props sind `comparison` (Objekt oder null). Die Spalten stehen nach
„Differenz“:

| Kopf | Inhalt | Klasse |
|---|---|---|
| `Ist 2025` / `Plan 2025` | `formatMoney(row.compareSum)` | `num vbh-col-hide-sm` |
| `Δ zu 2025` | `formatSigned(row.compareDelta)` | `num` + `comparisonClass` |
| `Δ %` | `formatPercent(row.comparePercent)` oder `—` | `num vbh-col-hide-sm` + `comparisonClass` |

Auf dem Handy bleibt nur Δ sichtbar (N3), über die vorhandene Klasse
`vbh-col-hide-sm`. Das `colspan` der Notizzeile wird von 7 auf 10 erhöht,
solange ein Vergleich aktiv ist. Der Kopf bekommt einen `title`, der die
Formel erklärt, z. B. „Plan minus Ist 2025“.

### Einfärbung (F9)

In `lib/format.js`:

```js
// Gut ist mehr Einnahme oder weniger Ausgabe – für jede Größe, die als
// „Plan minus Vergleich“ bzw. „Ist minus Plan“ gerechnet ist.
export function trendClass(type, value) { … }      // '' | 'good' | 'bad'
export function budgetDiffClass(row) { return trendClass(row.type, row.diff) }
```

`budgetDiffClass` wird so auf die neue Funktion zurückgeführt, ohne dass sich
sein Verhalten ändert. Die Vergleichsspalten verwenden
`trendClass(row.type, row.compareDelta)`. Für die Summenkacheln gilt
Einnahmen → `income`, Ausgaben → `expense`, Ergebnis → `income`.

### Inaktive Konten (F15)

- Die Zeile bekommt das Feld `active` vom Server (`Account::getActive()`).
  Inaktive Konten stehen heute schon im Baum, weil `AccountMapper::findAll`
  nicht filtert.
- Ist `!row.active` und gilt `row.plan === 0`, gibt es keine `AmountInput`.
  Angezeigt werden `—` und die Pille `nicht mehr aktiv` (`vbh-pill--quiet`).
- Ist `!row.active`, aber ein Plan ≠ 0 vorhanden (Altdaten), bleibt die
  Eingabe offen, damit sich der Wert auf 0 zurücksetzen lässt. Das ist
  dieselbe Haltung wie bei Plankonflikten.
- Die Pille erscheint unabhängig davon, ob ein Vergleich aktiv ist. Der
  Server sperrt nichts zusätzlich, es ist eine reine UI-Regel.

`ATTENTION_FLAGS` bleibt unverändert (F16).

## 6. Zweisprachigkeit & Doku (N2)

- Neue Texte in `l10n/de.json`/`en.json`, u. a.: „Vergleich mit“, „Kein
  Vergleich“, „Ist {label}“, „Plan {label}“, „Δ zu {label}“, „nicht mehr
  aktiv“, „Vergleich: %s“ (PHP), CSV-Köpfe.
- Achtung bei Prozent in PHP-Texten: Laut Memory wirft `t('… %')` ohne
  Parameter einen ValueError. Prozentzeichen deshalb nur über `%%` oder
  außerhalb von `t()` ausgeben.
- In `HANDBUCH.md` (DE+EN) erweitert ein neuer Absatz den Finanzplan-Abschnitt,
  der stabile Anker bleibt. Außerdem kommen Einträge in `CHANGELOG` und
  `whatsNew.js` hinzu.

## 7. Tests (N5)

**Unit (`tests/unit/BudgetComparisonTest.php`, neu)**
- `delta()`/`percent()`: 1.200 gegen 1.100 → +100 / +9,1. Basis 0 → null.
  Negativer Vergleichswert → Bezug auf den Betrag. Gleichstand → 0 / 0,0.

**Unit (`BudgetTreeTest.php`, ergänzt)**
- Die dritte Spalte `compare` wird über Nachfahren summiert. `totals(…,
  'compare')` stimmt.

**Unit (Service, falls Mock-Infrastruktur vorhanden)**
- Auflösung von `compareId`: null → Vorperiode, 0 → aus, eigene ID → aus,
  erste Periode → aus.

**CSV**
- Ohne Vergleich byte-gleich zum heutigen Export. Mit Vergleich 15 Spalten,
  die Kopfzeile enthält das Label.

**E2E (`tests/e2e/14-budget.spec.mjs`, erweitert)**
- Werte und Vorbelegung, siehe AK 1–5.
- Planwert ändern → Δ aktualisiert sich (AK 8).
- Druckseite gegen den Container prüfen (Memory: IL10N/%), mit
  Kopfzeile „Vergleich: …“.
- Handy-Breite: nur Δ sichtbar.

## 8. Rückwirkung auf die Anforderungen

- **F15** wird eingegrenzt auf „inaktive Konten“. Konten, deren Typ heute
  nicht mehr Einnahme/Ausgabe ist, kann der Planbaum nicht darstellen (siehe
  Abschnitt 2). Ihre Vorjahreswerte fehlen dann auch in den Summen.
- **F12** wird um Querformat bei aktivem Vergleich ergänzt (Designentscheidung).
- **CSV-Spaltenlage**: Die Vergleichsspalten werden angehängt statt hinter der
  Differenz eingefügt (Kompatibilität).

## 9. Betroffene Dateien

| Datei | Änderung |
|---|---|
| `lib/Service/BudgetComparison.php` | neu |
| `lib/Service/BudgetOverviewService.php` | Parameter, Spalte `compare`, Summen |
| `lib/Controller/BudgetController.php` | Parameter, Zeilen-/Summenfelder, `active` |
| `lib/Controller/ExportController.php` | Parameter durchreichen |
| `lib/Service/Export/CsvExportService.php` | drei Spalten anhängen |
| `lib/Service/Export/FinanzplanRenderer.php` | Spalten, Kopfzeile, Querformat |
| `lib/Service/Export/PrintableReportPage.php` | optionales Querformat |
| `src/api.js` | `budget`, `exportBudgetUrl`, `exportFinanzplanUrl` |
| `src/App.vue` | Zustand, Watcher, `loadBudget` |
| `src/components/ReportsTab.vue` | Auswahl, Summenkacheln, Export-URLs |
| `src/components/BudgetTreeTable.vue` | Spalten, inaktive Konten |
| `src/lib/format.js` | `trendClass`, `formatSigned`, `formatPercent` |
| `l10n/*.json`, `HANDBUCH*.md`, `CHANGELOG*`, `whatsNew.js` | Texte & Doku |
| Tests | siehe Abschnitt 7 |

Keine Datenbankmigration, keine neue Route.
