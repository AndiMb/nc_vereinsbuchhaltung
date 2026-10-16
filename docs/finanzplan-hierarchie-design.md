# Design: Hierarchischer Soll-Ist-Vergleich (Finanzplan)

Stand: 16.10.2026 · Status: freigegeben

## 1. Ausgangslage

| Baustein | Heute |
|---|---|
| Kontenhierarchie | `Account.parentId` (zyklenfrei, geprüft in `AccountService::resolveParent()`); Saldenliste rollt im Frontend schon „inkl. Unterkonten“ auf (`ReportsTab.vue` `balanceRows`). |
| Planwerte | Tabelle `budget` (Konto × Zeitraum, Cent + Notiz). Einziger Schreibpfad: `BudgetController::set()`. Zeiträume zusammenlegen (`PeriodService`) kann Pläne verschieben. |
| Soll-Ist | `LedgerAggregator::planActual()` – flach, nur **eigene** Buchungen je Konto. Konsumenten: `BudgetController::index`, `CsvExportService::budget`, `KassenberichtRenderer`, `KurzberichtRenderer`. |
| Plan-Stände | `BudgetSnapshotService` friert eigene Planbeträge ≠ 0 ein (Nr./Name/Typ kopiert); `BudgetSnapshotModal.vue` vergleicht Stand ↔ aktueller Plan, flach. |
| „PDF“ | Druckfertige HTML-Seiten über `PrintableReportPage` (Kassenbericht, Kurzbericht), Druck/PDF über den Browser. |

**Keine Datenbankänderung nötig.** Alles Neue ist Berechnung zur Laufzeit.

## 2. Begriffe

| Begriff | Bedeutung |
|---|---|
| **Planbaum** | Wald aus Einnahmen-/Ausgabenkonten entlang `parentId`. Baum-Elternteil = nächster Vorfahr, der **budgetierbar und vom selben Typ** ist; sonst ist das Konto Wurzel. Fremde Zwischenkonten werden übersprungen. |
| **Plan / Ist (eigen)** | Wert direkt auf dem Konto (wie heute). |
| **Plan Σ / Ist Σ** | Eigener Wert + Σ aller Nachfahren im Planbaum. |
| **Planposten** | Konto mit eigenem Plan ≠ 0. |
| **Planungsebene** | Menge der Planposten. 0 / leer zählt nicht als Plan. |
| **Plankonflikt** | Planposten, der einen Planposten als Vorfahr oder Nachfahr hat. |
| **Ist ohne Plan** | Konto mit eigenem Ist ≠ 0, ohne Plan auf sich selbst oder einem Vorfahren. |
| **Überschritten** | Planposten vom Typ Ausgabe mit Ist Σ > Plan Σ. |

## 3. Architektur

```
                  ┌────────────────────────────┐
  accounts ─────► │  BudgetTree (pure, static) │ ◄── plan (Cent je Konto)
  sumByAccount ─► │  build() / blockingPlan()  │ ◄── weitere Wertspalten
                  └─────────────┬──────────────┘
          ┌──────────────┬──────┴───────┬──────────────────┬───────────────────┐
          ▼              ▼              ▼                  ▼                   ▼
 BudgetController  BudgetController  CsvExportService  FinanzplanRenderer  BudgetSnapshotService
   ::index (Baum)   ::set (Sperre)    ::budget          (neu, Druckseite)    ::getDetail (Baum)
          │                                                                    │
          ▼                                                                    ▼
   ReportsTab.vue ──► BudgetTreeTable.vue (neu, gemeinsam) ◄── BudgetSnapshotModal.vue
```

### 3.1 `lib/Service/BudgetTree.php` (neu, reine Funktionen – wie `LedgerAggregator`)

```php
final class BudgetTree {
    /**
     * @param iterable<Account>                $accounts
     * @param array<string, array<int,int>>    $columns  z. B. ['plan'=>…, 'actual'=>…] Cent je Konto-ID
     * @return list<BudgetNode>  Vorordnung (Tiefensuche), Geschwister nach Kontonummer sortiert
     */
    public static function build(iterable $accounts, array $columns): array;

    /** Soll-Ist-Flags (Konflikt, ohne Plan, überschritten, Startzustand) ergänzen. */
    public static function annotatePlanActual(array $nodes, string $planCol = 'plan', string $actualCol = 'actual'): array;

    /** Planposten, der eine Planeingabe ≠ 0 auf $accountId blockiert – oder null. */
    public static function blockingPlan(iterable $accounts, array $plan, int $accountId): ?Account;

    /** Summen über die Wurzeln, je Typ (income/expense) und Spalte. */
    public static function totals(array $nodes): array;
}
```

`BudgetNode` (assoziatives Array, kein Entity):

| Feld | Typ | Inhalt |
|---|---|---|
| `account` | Account | Konto |
| `treeParentId` | ?int | Elternteil im Planbaum |
| `depth` | int | Tiefe im Planbaum (Wurzel = 0) |
| `hasChildren` | bool | |
| `own[col]` / `sum[col]` | int | eigener / aufsummierter Wert je Spalte |
| `isPlanItem` | bool | eigener Plan ≠ 0 |
| `coveredBy` | ?int | nächster Vorfahr, der Planposten ist |
| `planBelow` | bool | ein Nachfahr ist Planposten |
| `conflict` | bool | `isPlanItem && (coveredBy !== null \|\| planBelow)` |
| `unplanned` | bool | eigenes Ist ≠ 0, kein Plan auf sich/Vorfahren |
| `unplannedBelow` | bool | ein Nachfahr ist `unplanned` (Hinweis an eingeklappten Knoten) |
| `overrun` | bool | `isPlanItem && type=expense && sum.actual > sum.plan` |
| `expanded` | bool | Startzustand: `planBelow` (Zweig öffnet sich bis zur Planungsebene) |
| `planLockedBy` | ?int | `coveredBy` oder (falls `planBelow`) erster Planposten darunter; nur gesetzt, wenn **nicht** selbst Planposten |

**Algorithmus:** Eine Map baut `id → Kinder` auf. Danach läuft eine Tiefensuche von den Wurzeln, mit einer `seen`-Menge als Schutz gegen Zyklen in Altdaten. Die Summen werden im Post-Order-Durchlauf gebildet, `coveredBy` wird im Pre-Order-Durchlauf weitergereicht. Laufzeit O(n), bei rund 200 Konten unkritisch.

**Gesamtsummen:** Σ über die Wurzeln ergibt genau Σ aller eigenen Werte. Das sind dieselben Zahlen wie heute, also werden die bisherigen Gesamtsummen nicht verändert. Bei Altkonflikten wird additiv gerechnet und ein Hinweis angezeigt (Anforderung 4).

`LedgerAggregator::planActual()` bleibt unverändert, weil Kassen- und Kurzbericht es weiter nutzen (siehe §8).

### 3.2 API

**`GET /api/budget?period=`** – Antwort erweitert, die alten Felder bleiben:

```jsonc
{
  "periodId": 3, "label": "2026", "startDate": "…", "endDate": "…",
  "rows": [{
    "accountId": 42, "number": "544 01", "name": "…", "type": "expense", "category": "…",
    "plan": 50000.0, "note": "", "actual": 0.0,        // eigen (wie bisher)
    "planSum": 50000.0, "actualSum": 62070.09,          // neu
    "diff": 12070.09,                                   // GEÄNDERT: actualSum − planSum
    "treeParentId": 40, "depth": 2, "hasChildren": true,
    "expanded": false,
    "planLockedBy": null,                               // { "accountId", "number", "name" } | null
    "flags": ["overrun"]                                // ⊂ conflict | unplanned | unplannedBelow | overrun
  }],
  "totals": { …wie bisher… },
  "conflictCount": 0
}
```

Die Reihenfolge der Zeilen ist die Vorordnung des Planbaums. Sie ersetzt das bisherige `usort` nach Nummer, beide sind bei hierarchischer Nummerierung gleich.

**`POST /api/budget`** (set): Ist `amount ≠ 0` und liefert `BudgetTree::blockingPlan()` ein Konto, antwortet der Server mit **409 Conflict** und `{message: "Für diesen Zweig ist bereits auf 428 01 … geplant.", blockingAccountId}`. Ausnahmen:
- `amount = 0` ist immer erlaubt. So lassen sich Konflikte bereinigen.
- Eine reine Notizänderung an einem bestehenden Planposten mit Konflikt (`amount` unverändert) ist erlaubt. Sonst ließe sich ein Altkonflikt weder kommentieren noch bearbeiten.

**`GET /api/budget/snapshots/{id}`**: zusätzlich `tree` = `BudgetTree::build()` mit den Spalten `snapshot` (eingefrorener Betrag) und `current` (aktueller Plan). Die Hierarchie kommt aus dem **aktuellen** Kontenplan. Positionen gelöschter Konten werden als Wurzeln mit den eingefrorenen Nr./Name angehängt (Markierung „Konto gelöscht“). Das bekannte Verhalten bleibt: Snapshots frieren keine Hierarchie ein.

**`GET /api/export/budget?period=`** (CSV), neue Spalten:

`Typ; Ebene; Nr.; Konto; Kategorie; Plan (EUR); Plan Σ (EUR); Ist (EUR); Ist Σ (EUR); Differenz Σ (EUR); Status; Notiz`

- `Ebene` = `depth + 1`. Daran gruppiert man in Excel (Daten → Gliederung).
- `Status` = Klartext, mehrere Werte durch Komma getrennt: „Plankonflikt“, „ohne Plan“, „überschritten“.
- Die Summenzeilen bleiben unverändert.
- ⚠ Die Spaltenreihenfolge ändert sich. Das kommt als Hinweis ins CHANGELOG.

**`GET /api/export/finanzplan?period=&depth=plan|0|1|2|all`** (neu, Druckseite):
- `plan` (Standard) zeigt alle Knoten, deren Vorfahren alle `expanded` sind. Das entspricht der Startansicht.
- Eine Zahl *n* zeigt die Knoten mit `depth ≤ n`.
- `all` zeigt alles.

### 3.3 `lib/Service/Export/FinanzplanRenderer.php` (neu)

Aufgebaut nach dem Muster `KurzberichtRenderer` und nutzt `PrintableReportPage` (Kopf, Branding, CSP, Dunkelmodus):
- Abschnitte Einnahmen und Ausgaben, Spalten: Nr. · Konto (eingerückt nach `depth`) · Plan Σ · Ist Σ · Differenz · Ist in % vom Plan.
- Markierungen stehen als Text oder Symbol (⚠ Konflikt, ○ ohne Plan, ▲ überschritten) und nicht nur als Farbe, damit sie auch im Schwarzweißdruck lesbar sind.
- Ein eingeklappter Knoten mit `unplannedBelow` bekommt den Hinweis „enthält ungeplante Buchungen“.
- Fußbereich mit Gesamtsummen und Ergebnis Plan/Ist sowie einer Zeile, welche Ebene ausgewählt war.
- Controller: `ExportController::finanzplan()` → `printableResponse()`, `#[NoAdminRequired]`, dieselben Rechte wie der CSV-Export.

### 3.4 Frontend

**`src/components/BudgetTreeTable.vue` (neu)** – die Tabelle wird aus `ReportsTab.vue` (Z. 712–795) herausgelöst und von ReportsTab und BudgetSnapshotModal gemeinsam genutzt.

| Prop | Zweck |
|---|---|
| `rows` | Knoten in Vorordnung (von der API) |
| `columns` | `'planActual'` \| `'snapshot'` – wählt die Spalten |
| `editable` | `canWrite` |
| `resetKey` | Zeitraum-ID. Ändert sie sich, wird der Auf-/Zuklapp-Zustand aus `row.expanded` neu gesetzt |

Emits: `save(row)`, `toggle-note(row)`.

- **Zustand:** `expandedIds: Set`. Eine Zeile ist sichtbar, wenn alle Vorfahren aufgeklappt sind. Der Zustand bleibt erhalten, wenn nach dem Speichern eines Plans neu geladen wird (der Schlüssel ist die `accountId`).
- **Werkzeugleiste:** „Planungsebene“ (Startzustand) · „Alle aufklappen“ · „Alle zuklappen“ · Umschalter **„Nur Auffälligkeiten“**. Bei aktivem Filter sind Zeilen mit einem Flag aus `conflict|unplanned|overrun` und ihre Vorfahren sichtbar, unabhängig vom Klappzustand. Dazu kommt ein Zähler, z. B. „3 überschritten · 10 ohne Plan“.
- **Zeile:** Chevron-Button (`aria-expanded`, `aria-label` „Unterkonten von {nr} ein-/ausklappen“) mit Einrückung wie in der Saldenliste.
  - **Plan:** `AmountInput`. Ist `planLockedBy` gesetzt, wird das Feld deaktiviert und als Tooltip und Screenreader-Text erscheint „Plan liegt auf {nr} {name}“. Ist eine Zeile **kein** Planposten, hat aber `planBelow`, zeigt sie statt des Eingabefelds Plan Σ grau und kursiv an.
  - **Ist Σ:** mit einer Unterzeile „davon direkt {own}“, wenn `hasChildren` und eigenes Ist ≠ 0.
  - **Differenz:** `budgetDiffClass` mit `diff` (jetzt Σ-basiert).
  - **Status:** Badges „Konflikt“, „ohne Plan“, „überschritten“, am eingeklappten Knoten „ungeplant darunter“.
- **Mobil:** Die Spalten Nr., Art, Notiz und Status werden ausgeblendet (`vbh-col-hide-sm`). Die Status-Badges stehen dann unter dem Kontonamen.
- **Fehler beim Speichern (409):** `errMsg()` zeigt die Servermeldung als Toast, der Wert wird zurückgesetzt und die Daten neu geladen. Im Normalfall verhindert schon das gesperrte Eingabefeld diesen Fall.

**`BudgetSnapshotModal.vue`:** nutzt `BudgetTreeTable` mit `columns='snapshot'` (Spalten Stand Σ · Aktuell Σ · Δ). `currentPlanForAccount()` entfällt.

**`ReportsTab.vue`:** Das Budget-Markup wird durch `<BudgetTreeTable>` ersetzt. `saveBudget`/`toggleBudgetNote` bleiben dort, der Link „Drucken / PDF“ neben dem CSV-Export kommt mit einer Auswahl der Tiefe hinzu.

## 4. Abläufe

**Plan eintragen, wenn Ober- oder Unterkonto bereits geplant ist**
```
Nutzer ─► gesperrtes Feld (Tooltip „Plan liegt auf 428 01“)  → keine Eingabe möglich
API direkt ─► POST /api/budget {428 01 03, 500}
            └► BudgetTree::blockingPlan() = 428 01 ─► 409 + Meldung
```

**Altkonflikt bereinigen**
```
Laden ─► flags=[conflict] an beiden Konten, conflictCount=1 ─► Banner „1 Plankonflikt – Summen enthalten Doppelplanung“
Nutzer setzt einen der Werte auf 0 ─► POST erlaubt (amount=0) ─► neu laden ─► Konflikt weg
```

## 5. Zuordnung der Anforderungen

| Anforderung | Umsetzung |
|---|---|
| 1 Σ-Werte | `BudgetTree::build` + Spalten Plan Σ/Ist Σ |
| 2 0 = kein Plan | `isPlanItem = own.plan ≠ 0` |
| 3 nur eine Planebene | `planLockedBy` (UI) + 409 in `set()` |
| 4 Altkonflikte | `conflict`-Flag, Banner, additive Summen, `amount=0` immer erlaubt |
| 5 Startansicht | `expanded = planBelow` |
| 6 direkt gebucht | Unterzeile aus `own.actual` |
| 7 Auffälligkeiten | Flags + Filter „Nur Auffälligkeiten“ |
| 8 CSV | Spalten Ebene/Σ/Status |
| 9 Vorstands-PDF | `FinanzplanRenderer` + `depth`-Parameter |
| 10 Snapshots | `getDetail().tree` + `BudgetTreeTable columns=snapshot` |
| 11 Gesamtsummen | `BudgetTree::totals` = bisherige Werte |

## 6. Tests

- **PHPUnit `BudgetTreeTest`** (neu):
  - Aufsummieren über drei Ebenen
  - Typwechsel in der Kette (Ausgabekonto unter einem Einnahmekonto → eigene Wurzel)
  - nicht budgetierbares Zwischenkonto wird übersprungen
  - Zyklus in Altdaten führt nicht zu einer Endlosschleife
  - 0 zählt nicht als Plan
  - Konflikt in beide Richtungen
  - `unplanned`/`unplannedBelow`, `overrun` nur für Ausgaben
  - `expanded`
  - `blockingPlan` (Vorfahr, Nachfahr, keiner)
  - Gesamtsummen gleich `planActual()`-Summen
  - Fixtures aus der CSV: 544 01, 428 01, 511 01, 111 54
- **PHPUnit Controller:** `set` gibt 409, wenn blockiert; 0 ist erlaubt; reine Notizänderung bei Konflikt ist erlaubt.
- **PHPUnit Export:** CSV-Kopfzeile und Ebene/Σ-Werte; Renderer zeigt bei `depth=plan` keine Unterkonten unter den Planposten.
- **E2E `14-budget.spec.mjs`:**
  - Startzustand aufgeklappt bis zur Planungsebene
  - Auf-/Zuklappen
  - gesperrtes Eingabefeld
  - Filter „Nur Auffälligkeiten“
  - Snapshot-Dialog als Baum
- Vor dem Push: PHPStan, PHPUnit, `npx eslint src/`.

## 7. Auslieferung & Doku

- Minor-Version (neue Funktion, CSV-Format geändert).
- CHANGELOG (DE/EN) mit Hinweis auf die geänderten CSV-Spalten, `whatsNew.js`.
- HANDBUCH (DE/EN): Kapitel Finanzplan um den Planbaum, die Sperrregel, den Filter und den Druckbericht ergänzen.
- `l10n/de.json` und `en.json` für alle neuen Texte.

## 8. Entscheidungen (bestätigt am 16.10.2026)

1. **Kassenbericht und Kurzbericht** bleiben in diesem Feature unverändert (flach über `planActual()`). Die Umstellung auf die Planungsebene über `BudgetTree` erfolgt in einem **Folge-Issue**.
2. **„Überschritten“ gilt nur für Ausgaben** (Ist Σ > Plan Σ am Planposten). Ein Rückstand bei den Einnahmen ist im laufenden Jahr normal und wird nicht als Auffälligkeit markiert. Die Differenzfarbe zeigt ihn weiterhin.
3. **Snapshots** verwenden die **aktuelle** Kontenstruktur, es gibt keine Datenbankänderung. Positionen gelöschter Konten erscheinen als Wurzeln mit der eingefrorenen Nr. und dem eingefrorenen Namen.

## 9. Abweichungen bei der Umsetzung

- **`comparable`:** Ein Unterkonto, dessen Plan auf einem Vorfahren liegt, hat keine eigene Differenz (`diff: null`, im Bildschirm „—", in CSV und Druck leer). Sonst stünde dort das volle Ist als scheinbare Überschreitung.
- **Snapshot-Dialog:** nutzt nicht `BudgetTreeTable.vue`, sondern dieselbe Klapplogik aus `src/lib/budgetTree.js` mit eigener, schlanker Tabelle (andere Spalten, keine Eingabe).
- **Gemeinsamer Dienst:** `BudgetOverviewService` liefert Bildschirm, CSV und Druck dieselben Knoten; `blockingPlan()` prüft die Sperre für `BudgetController::set()`.
- **Druckspalte „Ist/Plan"** statt „Ist in %": ein nacktes `%` bricht Nextclouds `vsprintf` in `IL10N::t()`.
- **Mobil:** Die Baumtabelle hat eine Mindestbreite und scrollt waagerecht, statt das Planfeld über den Kontonamen zu schieben.
