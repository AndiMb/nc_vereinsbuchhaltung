# Design: Soll-Ist-Vergleich im Kassenbericht bis zur Planungsebene (Issue #139)

Stand: 17.10.2026 · Status: umgesetzt (Entscheidungen § 8 nach Empfehlung) · Vorgänger: [finanzplan-hierarchie-design.md](finanzplan-hierarchie-design.md) (#138)

## 1. Ausgangslage

| Baustein | Heute |
|---|---|
| `KassenberichtRenderer::render()` | Lädt Plan über `BudgetMapper::findByPeriod()`; bei `$plan !== []` → `LedgerAggregator::planActual()` (flach, nur **eigene** Werte je Konto). |
| `planSection()` | Eine Tabelle, Spalten `Nr. │ Name │ Plan │ Ist │ Differenz`, Zeilen nach Kontonummer, Einnahmen und Ausgaben gemischt. Filter: Zeilen mit Plan = 0 **und** Ist = 0 entfallen. Darunter Summenzeilen Einnahmen / Ausgaben / Ergebnis. |
| `BudgetOverviewService::build()` | Planbaum mit `own`/`sum`, `depth`, `flags`, `comparable`, `expanded`; dazu `totals`, `conflictCount`, `accounts`. Gleicher Zeitraum (`startDate`–`endDate`) wie der Kassenbericht. |
| `FinanzplanRenderer` | Druckseite aus demselben Baum; Zeichen `▲ ○ ◌ ⚠` als private Konstante `MARKS`, Legende als private Methode. |

**Keine Datenbankänderung, keine neue Route, keine Frontend-Änderung.**

## 2. Zielbild

```
KassenberichtRenderer::render($userId, $period)
 ├─ wealthSection / resultSection / sphereSection        (unverändert)
 ├─ planSection( BudgetOverviewService::build($userId, $period->getId()) )
 │     ├─ visibleAtDepth($nodes, 'plan')
 │     ├─ Filter Σplan = 0 ∧ Σist = 0
 │     ├─ planRows(Einnahmen) · planRows(Ausgaben)     ← public static, testbar
 │     ├─ planTotalRow × 3                              (unverändert)
 │     └─ BudgetMarks::legend()                         ← mit FinanzplanRenderer geteilt
 └─ Vollständigkeit / Unterschriften                     (unverändert)
```

Beispiel aus dem Issue, neu:

| Konto | Plan | Ist | Differenz |
|---|---:|---:|---:|
| 5300 Bürokosten ▲ | 5.000,00 | 5.300,00 | 300,00 |

## 3. Komponenten

### 3.1 `BudgetMarks` (neu, `lib/Service/Export/BudgetMarks.php`)

Zieht Zeichen und Legende aus `FinanzplanRenderer` heraus, damit beide Berichte dieselben Symbole und Texte verwenden (keine neuen l10n-Strings).

```php
final class BudgetMarks {
    /** Zeichen der Auffälligkeiten, in der Reihenfolge der Legende. */
    public const MARKS = [FLAG_OVERRUN => '▲', FLAG_UNPLANNED => '○', FLAG_UNPLANNED_BELOW => '◌', FLAG_CONFLICT => '⚠'];

    /** ' ▲ ○' o. ä. – leer, wenn keine Flags; bereits HTML-sicher. */
    public static function marks(array $flags): string;

    /** <p class="meta">Legende<br>„Plan und Ist schließen alle Unterkonten ein."</p> */
    public static function legend(IL10N $l10n): string;

    /** <p><strong>⚠ n Plankonflikte …</strong></p> oder '' bei 0. */
    public static function conflictNote(IL10N $l10n, int $count): string;
}
```

`FinanzplanRenderer` ruft danach diese drei Methoden statt eigener Kopien auf – reine Verschiebung, Ausgabe bytegleich.

### 3.2 `KassenberichtRenderer`

**Abhängigkeiten:** `BudgetMapper` entfällt, `BudgetOverviewService` kommt hinzu (DI per Autowiring, `ExportController` unverändert).

**`render()`:**

```php
$budget = $this->overview->build($userId, (int)$period->getId());
$hasPlan = array_filter($budget['nodes'], fn ($n) => $n['isPlanItem']) !== [];
…
if ($hasPlan) { $h .= $this->planSection($budget); }
```

`build()` nutzt `selectedOrCurrent()`; mit gesetzter ID ist das genau die übergebene Periode, also derselbe Zeitraum `$from`–`$to`.

**`planSection(array $budget)`:**

1. `$nodes = BudgetOverviewService::visibleAtDepth($budget['nodes'], 'plan')`
2. Filter: Knoten mit `sum.plan = 0` und `sum.actual = 0` entfallen (Σ statt eigener Werte → leere Zweige verschwinden ganz).
3. Nichts übrig → `''` (wie heute).
4. Überschrift, `BudgetMarks::conflictNote()`, Tabelle:
   - Kopf wie heute: `Konto (colspan 2) │ Plan │ Ist │ Differenz`.
   - Zwischenkopf **Einnahmen**, `planRows(..., 'income')`, Zwischenkopf **Ausgaben**, `planRows(..., 'expense')` – gleiches Muster wie `resultSection()` im selben Bericht. (Heute gemischt nach Nummer; mit Einrückung wird ein gemischter Baum unlesbar.)
   - Die drei `planTotalRow()` aus `$budget['totals']` – Werte identisch zu heute (§ 5).
5. `BudgetMarks::legend()`.

**`planRows()` (public static, zustandslos wie `accountRows()`/`sphereRows()`):**

```php
/**
 * @param list<array<string, mixed>> $nodes  bereits gefiltert, Vorordnung
 * @param array<int, string> $names           Konto-ID → Name
 */
public static function planRows(array $nodes, array $names, string $type): string
```

Je Knoten des Typs:

| Zelle | Inhalt |
|---|---|
| `td.nr` | `number`, `padding-left: 4 + min(depth, 6)·14 px` bei `depth > 0` (wie `FinanzplanRenderer`) |
| `td` | Name + `BudgetMarks::marks(flags)` in `white-space:nowrap` |
| Plan / Ist | `sum.plan` / `sum.actual` |
| Differenz | `sum.actual − sum.plan`, leer wenn `!comparable` |

Keine Fettung der Wurzeln – der Kassenbericht setzt Kontozeilen sonst überall normal.

Die Namen kommen als ID → String herein statt als `Account`-Entity, damit der Test ohne Nextcloud-Klassen auskommt (`nodes` tragen `number` schon).

### 3.3 Unverändert

- `LedgerAggregator::planActual()` bleibt – `KurzberichtRenderer` nutzt es weiter (Out of scope laut Issue).
- `ReportFormat`, `PrintableReportPage`, CSS.

## 4. Randfälle

| Fall | Verhalten |
|---|---|
| Plan auf Sammelkonto, Buchungen auf Unterkonten | Sammelkonto nicht `expanded` → nur eine Zeile mit Σ. ✔ Akzeptanzkriterium 1 |
| Konto mit Ist, ohne Plan auf sich oder einem Vorfahren | Zeile mit `○`; dessen planloser Vorfahr (sichtbar, wenn darunter irgendwo geplant wird) trägt `◌`. ✔ AK 2 |
| Zweig ohne Plan und ohne Ist | Σ = 0 → fällt weg. |
| Zweig ganz ohne Plan, aber mit Ist | Wurzel nicht `expanded` → eine Zeile mit Σ und `◌` (bzw. `○`, wenn sie selbst bebucht ist). |
| **Plankonflikt** (Plan auf Eltern und Kind) | Eltern `expanded` → Kinder sichtbar. Ein ungeplantes Geschwister ist dann `!comparable` und erscheint **doch** – mit leerer Differenz. Die Annahme im Issue („erscheinen gar nicht") gilt nur ohne Konflikte; deshalb bleibt die `comparable`-Prüfung im Code. Zusätzlich Konflikthinweis über der Tabelle, weil die Plansummen dann doppelt zählen. |
| Σ = 0 durch Ausgleich (Erstattung hebt Ausgabe auf) bei Kind ≠ 0 | Eltern entfällt, Kind bleibt eingerückt ohne sichtbaren Elternteil. Sehr selten, Zahlen bleiben richtig; bewusst nicht behandelt. |
| Planwerte existieren nur als 0-Beträge | Heute: Abschnitt mit lauter Plan 0. Neu: kein Planposten → Abschnitt entfällt. Gewollt. |
| Ausgabenkonto unter Einnahmenkonto | Eigene Wurzel (BudgetTree-Regel), erscheint unter „Ausgaben". |

## 5. Gleichheit der Summen (AK 3)

Alt: Σ eigener Plan/Ist aller budgetierbaren Konten, getrennt nach `isCreditNature()` – bei budgetierbaren Konten heißt das genau `type = income` bzw. `expense`.
Neu: `BudgetTree::totals()` = Σ über die Wurzeln = Σ aller eigenen Werte je `type` (Docblock und `BudgetTreeTest`). Ist-Werte beide über `LedgerAggregator::net()` aus `sumByAccount($from, $to)`. → identisch, inkl. Ergebnis.

## 6. Tests

`KassenberichtRendererTest` (Knoten über `BudgetTree::build()`/`annotate()` mit Kontobeschreibungen wie in `BudgetTreeTest`, dann `visibleAtDepth('plan')` + Filter → `planRows()`):

1. **Sammelkonto mit Plan, Buchungen auf Unterkonten:** genau eine Zeile `5300`, Plan 5.000,00, Ist 5.300,00, Differenz 300,00, `▲`; `5300 01`/`5300 02` nicht im HTML.
2. **Ungeplantes Konto:** Zeile mit `○`.
3. **Leerer Zweig:** weder Plan noch Ist → Nummer nicht im HTML.
4. **Konflikt:** ungeplantes Geschwister unter geplantem Eltern erscheint mit leerer Differenz.

Damit Filter und Tiefe mitgetestet werden, gehen Schritt 1–2 von `planSection()` in eine zweite statische Funktion `planNodes(array $nodes): array` (visibleAtDepth + Σ-Filter), die der Test direkt aufruft.

Regression: `FinanzplanRenderer` nach der Verschiebung einmal gegen den Container rendern (Druckseiten-Prüfung, vgl. `%`-Falle in IL10N).

Vor dem Push: PHPStan, PHPUnit, php-cs-fixer auf den geänderten Dateien.

## 7. Doku & Auslieferung

- HANDBUCH.md 7.2, Punkt „Soll-Ist-Vergleich": *„… bis zur Planungsebene: Ein Konto mit Plan zeigt Plan und Ist einschließlich aller Unterkonten; Auffälligkeiten sind wie im Finanzplan-Druck markiert."*
- HANDBUCH.en.md 7.2 sinngemäß: *"… down to the budget level: an account with a budget shows plan and actual including all its sub-accounts; items needing attention are marked as in the financial-plan print."*
- CHANGELOG (DE/EN); Patch- oder Minor-Version nach Release-Checkliste. Keine neuen l10n-Strings (alle Texte existieren aus #138).

## 8. Offene Entscheidungen

1. **Gruppierung nach Einnahmen/Ausgaben** statt gemischter Nummernfolge – Empfehlung: ja (Begründung § 3.2).
2. **Legende immer drucken** oder nur, wenn eine Zeile ein Zeichen trägt – Empfehlung: immer, weil sie auch den Satz „Plan und Ist schließen alle Unterkonten ein" enthält, der die Tabelle erst erklärt.
3. **Konflikthinweis** im Kassenbericht – Empfehlung: ja, gleicher Text wie im Finanzplan-Druck.
