# Design: Gliederungsbericht mit Vorlagen

Stand: 17.10.2026 · Status: umgesetzt (Branch feature/gliederungsbericht), Abweichungen in §12
Anforderungen: `docs/gliederungsbericht-anforderungen.md` (F1–F23, N1–N4)

## 1. Ausgangslage

| Baustein | Heute | Nutzen für den Gliederungsbericht |
|---|---|---|
| Sphäre | `Account.sphere` (4 Werte oder NULL), `ReportService::sphereReport()` | Trennung ideell / Zweckbetrieb (F3) |
| Kostenstelle | `ReportService::costCenterReport()`, Modi `group`/`account`/`manual`. Die Zuordnung Konto → Gruppe steht **inline** in der Schleife (Z. 128–160). | Berichtsgruppen (F7) |
| Kontohierarchie + Summen | `BudgetTree::describe/build/annotate`, `BudgetOverviewService::visibleAtDepth()` (`plan\|n\|all`) | Kontoebene mit Tiefe (F4, F14) |
| Plan / Ist | `BudgetMapper::findByPeriod()`, `JournalLineMapper::sumByAccount()` + `LedgerAggregator::net()` | Wertspalten (F13) |
| Vorperiode | `PeriodService::previous()` | Spalten „Plan/Ist Vorjahr“ |
| Druck | `PrintableReportPage` (Logo, Akzentfarbe, CSP), Muster `FinanzplanRenderer` | F21 |
| CSV | `CsvExportService` + `CsvFile` | F22 |
| Rechte | `PermissionMiddleware`: GET = lesen (Revisor+), sonst schreiben (Buchhalter+) | N2 ohne Sonderregeln |

**Neu ist** nur die Vorlage als Datensatz und eine Rechenmaschine, die aus Vorlage und Zahlen eine Zeilenliste macht.

## 2. Begriffe

| Begriff | Bedeutung |
|---|---|
| **Vorlage** | Name + **Definition** (JSON). Mitgelieferte Vorlagen leben im Code, eigene in der Datenbank. |
| **Abschnitt** | Knoten der Definition mit Titel, Filter, Unterabschnitten **oder** Gliederungsebenen und optionaler Fußzeile. |
| **Filter** | Einschränkung auf Kontoart (`income`/`expense`), Sphäre (inkl. `none`) und/oder Berichtsgruppe. Er wird von oben nach unten geschnitten. |
| **Ebene** | Dynamische Gruppierung innerhalb eines Abschnitts nach `type`, `sphere`, `group` oder `account`. |
| **Berichtsgruppe** | Ergebnis von *Kostenstelle des Kontos* + *Umgruppierung der Vorlage* (F8, F9). |
| **Zeilenliste** | Ergebnis der Berechnung: flache Liste in Vorordnung mit `depth`. Bildschirm, Druck und CSV lesen dieselbe Liste (wie bei `BudgetOverviewService`). |
| **Zusammenfassung** | Abschnitt mit `summary: true`. Er zählt Konten erneut (z. B. nach Hauptgruppe) und ist von Doppelprüfung und Gesamtsumme ausgenommen. |

## 3. Datenmodell

### 3.1 Tabelle `vbh_report_templates` (Migration `Version000161Date20261017000000`)

| Spalte | Typ | Inhalt |
|---|---|---|
| `id` | bigint, PK | |
| `user_id` | string(64) | `Application::BOOK` wie überall |
| `name` | string(255) | Anzeigename |
| `definition` | text | JSON nach §3.2, immer normalisiert gespeichert |
| `position` | int | Reihenfolge in der Auswahl |
| `created_at`, `updated_at` | datetime | |

Index `vbh_rt_user` auf `user_id`. Entity `Db/ReportTemplate`, Mapper `Db/ReportTemplateMapper` (`findAll`, `find`, `insert`, `update`, `delete`).

**Warum JSON statt Tabellen:** Die Definition ist ein kleiner Baum (unter 5 KB), wird immer als Ganzes gelesen und geschrieben und nie per SQL durchsucht. Normalisierte Tabellen für Abschnitte, Ebenen und Gruppen brächten nur Joins und Migrationsaufwand. Die Validierung übernimmt `ReportDefinition` (§3.3).

### 3.2 Definition (Schema-Version 1)

```jsonc
{
  "version": 1,
  "columns": [                                    // F13, F15
    { "source": "plan",   "periodOffset": -1 },   // Plan Vorjahr
    { "source": "actual", "periodOffset": -1 },   // Ist Vorjahr
    { "source": "plan",   "periodOffset": 0 },    // Plan gewählte Periode
    { "source": "actual", "periodOffset": 0 },
    { "source": "diff",   "periodOffset": 0 }     // Ist − Plan, Färbung wie Finanzplan
  ],
  "showPlanNotes": false,                         // F23: Notizen der Periode mit Offset 0
  "groups": [                                     // F8–F11, optional
    { "key": "wettbewerbe", "label": "Wettbewerbe", "costCenters": ["54"], "accounts": [] },
    { "key": "ersatz", "label": "Ersatzprojekte Chorfest 2020",
      "costCenters": [], "accounts": [311, 312, 313, 314, 315] }   // Konto-IDs inkl. Unterkonten
  ],
  "sections": [ /* Abschnitt, rekursiv */ ],
  "grandTotal": { "label": "Saldo" }              // null = keine Gesamtzeile
}
```

**Abschnitt**

```jsonc
{
  "title": "Ideeller Bereich",                    // leer = keine Überschrift
  "filter": { "types": ["income"], "spheres": ["ideell"], "groups": null },
  "summary": false,
  "hideEmpty": true,                              // F12: alle Spalten 0 → ausblenden
  // genau eins von beiden:
  "sections": [ … ],
  "levels": [
    { "by": "group",   "footer": { "label": "Summe der Titelgruppe" }, "hideEmpty": true },
    { "by": "account", "depth": "plan", "ancestors": false }
  ],
  "footer": { "label": "Ergebnis ideeller Bereich" }   // null = keine Fußzeile
}
```

| Feld | Werte | Regel |
|---|---|---|
| `filter.types` | `income`, `expense` | null = beide |
| `filter.spheres` | `ideell`, `vermoegensverwaltung`, `zweckbetrieb`, `wirtschaftlich`, `none` | `none` = Konto ohne Sphäre |
| `filter.groups` | `t:<key>` (Gruppe der Vorlage) oder `cc:<Kostenstelle>` | selten gebraucht, z. B. ein Projekt einzeln herausstellen |
| `levels[].by` | `type`, `sphere`, `group`, `account` | `account` darf nur als **letzte** Ebene stehen. Jedes Merkmal höchstens einmal. |
| `levels[].depth` | `plan`, `0`–`9`, `all` | nur bei `account`. Gleiche Bedeutung wie im Finanzplan. |
| `levels[].ancestors` | bool | nur bei `account`: Oberkonten außerhalb des Filters als Zwischenüberschrift zeigen (§4.3) |
| `footer.label` | Text | Wert siehe §4.4 |

Ist die **letzte Ebene nicht** `account`, erscheint jede Gruppe dieser Ebene als **eine Wertzeile**. Ein Beispiel ist die Verbandsfassung: je Projekt die Zeilen „Einnahmen“ und „Ausgaben“, darunter die Fußzeile „Differenz“.

### 3.3 `lib/Service/Report/ReportDefinition.php` (rein, statisch)

- `normalize(array $raw): array`: setzt Standardwerte, entfernt unbekannte Felder und kürzt Texte (Titel ≤ 255 Zeichen).
- `validate(array $def): list<string>`: liefert Fehlermeldungen (übersetzt). Leer bedeutet gültig.
  - Schema-Version, erlaubte Enum-Werte, `account` nur als letzte Ebene, keine doppelten Merkmale in einer Kette
  - Gruppenschlüssel eindeutig, jede Kostenstelle höchstens in einer Gruppe, jedes Konto höchstens einmal in `groups[].accounts`
  - Tiefe der Abschnitte ≤ 4, höchstens 8 Spalten
- Gespeichert wird nur eine Definition, die `validate()` ohne Fehler übersteht. Sonst antwortet der Controller mit **422** und `{errors: [...]}`.

## 4. Rechenmaschine

### 4.1 Aufbau

```
                     ┌──────────────────────────────┐
 Vorlage ──────────► │  StructuredReportService     │  lädt Daten, löst Perioden auf
 (Code oder DB)      │  ::build(userId, tpl, period)│
                     └──────────────┬───────────────┘
   accounts, groupOf, values[col][accountId], notes
                                    ▼
                     ┌──────────────────────────────┐
                     │ StructuredReportBuilder      │  rein, statisch, ohne DB
                     │ ::rows(def, input) → rows    │  (Muster BudgetTree)
                     └──────────────┬───────────────┘
            ┌───────────────────────┼─────────────────────────┐
            ▼                       ▼                         ▼
   ReportTemplateController  GliederungsberichtRenderer   CsvExportService
   ::evaluate (JSON)         (Druckseite)                 ::gliederungsbericht
            ▼
   StructuredReportView.vue
```

**`lib/Service/Report/CostCenterResolver.php` (neu, aus `ReportService` herausgelöst):** `resolve(Account): {key, code, name}` für alle drei Modi. `costCenterReport()` wird darauf umgestellt, ohne sein Verhalten zu ändern (Regressionstest). Damit gibt es genau eine Stelle, die „Konto → Kostenstelle“ kennt.

**Berichtsgruppe eines Kontos** (`StructuredReportService::groupOf`):
1. Steht das Konto oder sein **nächster** Vorfahr in `groups[].accounts`, gilt diese Gruppe. Die tiefste Angabe gewinnt.
2. Sonst: Steht die Kostenstelle in `groups[].costCenters`, gilt diese Gruppe.
3. Sonst: Die Gruppe ist die Kostenstelle selbst (Schlüssel = Code, Name = Kostenstellenname).
4. Ohne Kostenstelle gilt die Gruppe `''` mit dem Namen „(ohne Auswertungsgruppe)“.

Jahreskonten wie `111 51 2021` landen schon über Regel 3 bei Kostenstelle 51, eine Umgruppierung ist dafür nicht nötig. Konto-IDs, die es nicht mehr gibt, werden ignoriert.

**Wertspalten:** Je verschiedener Periode einmal `findByPeriod` und `sumByAccount`. `periodOffset = -k` läuft k-mal über `PeriodService::previous()`. Fehlt die Periode, bleibt die Spalte im Kopf stehen („Plan –1: keine Periode“) und ihre Werte sind `null`. Beträge in Cent, `LedgerAggregator::net()` (Einnahmen und Ausgaben jeweils positiv).

### 4.2 Algorithmus `rows()`

1. **Blätter bestimmen:** alle budgetierbaren Konten (`isBudgetable()`) mit ihren eigenen Werten. Merkmale je Konto: `type`, `sphere ?? 'none'`, `group`.
2. **Abschnitte rekursiv:** Der wirksame Filter ist die Schnittmenge aller Vorfahren-Filter. Die Kontomenge eines Abschnitts sind die Blätter, die den Filter erfüllen.
3. **Ebenen:** Die Menge wird nach `by` partitioniert. Reihenfolge:
   - `type`: Einnahmen vor Ausgaben
   - `sphere`: Reihenfolge von `Account::SPHERES`, `none` zuletzt
   - `group`: zuerst die Reihenfolge in `groups`, dann nach Code, `''` zuletzt
   - `account`: siehe 4.3
4. **Leere Gruppen:** Zweige, deren Summen in allen Spalten 0 sind, entfallen bei `hideEmpty`.
5. **Abdeckung (F10):** Jedes Blatt mit einem Wert ≠ 0 zählt in höchstens einem Nicht-Zusammenfassungs-Blattabschnitt.
   - Doppelt gezählte Konten bekommen das Flag `duplicate`, und die Antwort enthält eine Warnung.
   - Nicht erfasste Konten kommen in einen automatischen Abschnitt **„Nicht erfasste Konten“** am Ende, der in die Gesamtsumme eingeht. Die Antwort enthält eine Warnung.
   - So stimmt die Gesamtsumme **immer** mit Finanzplan und Saldenliste überein (N1).

### 4.3 Kontoebene

Die Konten des Abschnitts werden mit `BudgetTree::build()` aufgebaut, und zwar auf einer **eingeschränkten Beschreibung**:
- `ancestors: false`: Elternteil ist der nächste Vorfahr **innerhalb** der Menge, sonst wird das Konto Wurzel. Fremde Oberkonten werden übersprungen. Beispiel Ministerium, Titelgruppe 51: `427 51`, `511 51`, `518 51` … stehen auf einer Ebene.
- `ancestors: true`: Vorfahren außerhalb der Menge kommen als **Strukturknoten** mit eigenen Werten 0 dazu. Ihre Summen umfassen nur die gefilterten Nachfahren. Beispiel Ministerium, ideell: `1 Verwaltungseinnahmen` → `111 01 Mitgliedsbeiträge`.

Danach folgen `BudgetTree::annotate()` (für `expanded`) und `BudgetOverviewService::visibleAtDepth($nodes, $depth)`. Planbezogene Tiefe (`plan`) richtet sich nach der ersten Plan-Spalte mit Offset 0. Gibt es keine solche Spalte, wird `plan` wie `all` behandelt.

### 4.4 Fußzeilen und Summen

Der Wert einer Fußzeile hängt von den **Kontoarten ab, die der Filter zulässt** (deterministisch, unabhängig von den Daten):
- nur eine Art erlaubt: **Summe** (Ministerium „Summe der Titelgruppe“, „Gesamteinnahmen“)
- beide Arten erlaubt: **Ergebnis = Einnahmen − Ausgaben** (Verband „Differenz“, „Ergebnis ideeller Bereich“)

`grandTotal` ist immer ein Ergebnis über alle Nicht-Zusammenfassungs-Abschnitte einschließlich „Nicht erfasste Konten“. Die Spalte `diff` ist in jeder Zeile `actual − plan` derselben Periode. In Fußzeilen mit Ergebnis gilt dieselbe Differenz der Ergebnisse.

### 4.5 Zeilenliste (API-Antwort)

```jsonc
{
  "template": { "id": "builtin:zuwendungsgeber", "name": "…" },
  "period": { "id": 7, "label": "2026" },
  "columns": [ { "source": "plan", "periodId": 6, "label": "Plan 2025" }, … ],
  "rows": [
    { "kind": "heading", "depth": 0, "label": "Einnahmen" },
    { "kind": "heading", "depth": 1, "label": "Ideeller Bereich" },
    { "kind": "account", "depth": 2, "accountId": 12, "number": "111 01",
      "label": "Mitgliedsbeiträge", "type": "income",
      "values": [0, 0, 1200000, 845000, -355000], "flags": [] },
    { "kind": "note",    "depth": 3, "label": "7.300 Mitglieder …" },
    { "kind": "line",    "depth": 2, "label": "Einnahmen", "values": [ … ] },  // Wertzeile einer Nicht-Konto-Ebene
    { "kind": "footer",  "depth": 1, "label": "Summe der Titelgruppe", "values": [ … ] },
    { "kind": "total",   "depth": 0, "label": "Saldo", "values": [ … ] }
  ],
  "warnings": [ { "code": "uncovered", "accounts": ["999"] } ]
}
```

`values` sind Cent-Ganzzahlen oder `null` (Spalte ohne Periode). Flags: `duplicate`, `inactive` (wie Finanzplan F15) und `structural` (Strukturknoten aus 4.3).

## 5. Mitgelieferte Vorlagen

`lib/Service/Report/BuiltinReportTemplates.php` liefert Definitionen mit **übersetzten** Texten (`IL10N` zur Laufzeit, N3). IDs `builtin:zuwendungsgeber` und `builtin:bereiche`. Sie lassen sich nicht ändern und nicht löschen. „Kopieren“ schreibt eine DB-Vorlage mit den Texten in der aktuellen Sprache.

**„Haushaltsplan nach Titelgruppen (Zuwendungsgeber)“**: Spalten Plan −1, Plan 0, `showPlanNotes: true`

```
Einnahmen                         filter types=[income]
  Ideeller Bereich                filter spheres=[ideell]        levels: account(plan, ancestors)
  Titelgruppen / Projekte         filter spheres=[zweckbetrieb]  levels: group(footer „Summe der Titelgruppe“), account(plan)
  Vermögensverwaltung             filter …                       levels: account(plan, ancestors)   hideEmpty
  Wirtschaftlicher Geschäftsbetr. filter …                       …                                  hideEmpty
  Ohne Sphäre                     filter spheres=[none]          …                                  hideEmpty
  footer „Gesamteinnahmen“
Ausgaben                          (gleich, footer „Gesamtausgaben“)
grandTotal „Differenz“
Zusammenfassung (summary)         levels: type(footer „Gesamt…“), account(depth 0)  → Hauptgruppen
```

**„Ergebnis je Bereich und Projekt“**: Spalten Plan −1, Ist −1, Plan 0, Ist 0, `showPlanNotes: false`

```
Ideeller Bereich        filter spheres=[ideell]        levels: type(footer „Einnahmen/Ausgaben ideeller Bereich“), account(0)
                        footer „Ergebnis ideeller Bereich“
Zweckbetrieb            filter spheres=[zweckbetrieb]  levels: group(footer „Differenz“), type
                        footer „Ergebnis Zweckbetrieb“
(übrige Sphären / ohne Sphäre wie oben, hideEmpty)
grandTotal „Saldo“
```

Mit dem Kontenrahmen aus `example/` ergeben beide Vorlagen ohne jede Anpassung die Gliederung der Excel-Vorbilder (Akzeptanz US1/US2). Die Verbandsfassung braucht dafür nur eine **Kopie mit `groups`** (US3).

## 6. API

| Route | Verb | Rolle | Zweck |
|---|---|---|---|
| `/api/report-templates` | GET | Revisor+ | `{templates, groups}`: Vorlagen (mitgelieferte und eigene, je `id`, `name`, `builtin`, `definition`) und die Auswertungsgruppen des Kontenrahmens (`key`, `code`, `label`) für den Editor |
| `/api/report-templates` | POST | Buchhalter+ | Anlegen (`name`, `definition`). Kopieren = Definition holen und neu anlegen, im Frontend. |
| `/api/report-templates/{id}` | PUT | Buchhalter+ | Ändern. `builtin:*` → 403 |
| `/api/report-templates/{id}` | DELETE | Buchhalter+ | Löschen. `builtin:*` → 403 |
| `/api/report/structured?template=&period=` | GET | Revisor+ | Zeilenliste §4.5 |
| `/api/export/gliederungsbericht?template=&period=` | GET | Revisor+ | Druckseite |
| `/api/export/gliederungsbericht-csv?template=&period=` | GET | Revisor+ | CSV |

Die Rechte ergeben sich aus der Verb-Heuristik der `PermissionMiddleware`, es gibt kein `RequiresRole`. Schreibzugriffe protokolliert die `RevisionMiddleware` wie gewohnt. Unbekannte Vorlage → 404, ungültige Definition → 422.

**Controller:** `ReportTemplateController` (CRUD + `evaluate`), `ExportController::gliederungsbericht()` / `gliederungsberichtCsv()`.

## 7. Ausgabe

**Druck: `lib/Service/Export/GliederungsberichtRenderer.php`**, nach dem Muster `FinanzplanRenderer` auf `PrintableReportPage`:
- Kopf: Vorlagenname, Periode, „Stand: {Datum}“
- Tabelle: Nr. · Bezeichnung (eingerückt nach `depth`) · Wertspalten
- `heading` fett, `footer`/`total` mit Linie darüber, `note` kursiv und klein
- Warnungen als Kasten unter dem Kopf, damit sie auch im Druck sichtbar sind
- Kein nacktes `%` in `t()` (siehe IL10N-Falle)

**CSV:** `Ebene; Art; Nr.; Bezeichnung; <Spaltenköpfe…>; Notiz`. `Art` ist dabei Überschrift/Konto/Zeile/Summe/Gesamt, `Ebene` = `depth + 1` für die Excel-Gliederung (wie beim Finanzplan-CSV).

## 8. Frontend

**`ReportsTab.vue`:** neuer Untertab **„Gliederung“** zwischen „Finanzplan“ und „Protokoll“. Daneben stehen „Drucken / PDF“ und „CSV“ wie beim Finanzplan.

**`src/components/StructuredReportView.vue` (neu):**
- Kopfzeile: Auswahl der Vorlage (mitgelieferte zuerst, Gruppe „Eigene Vorlagen“), Periode kommt aus dem bestehenden Periodenwähler.
- Aktionen: „Kopieren“, und bei eigenen Vorlagen „Bearbeiten“ und „Löschen“ (nur `canWrite`).
- Tabelle aus `rows`, ohne Klappzustand: Die Tiefe legt die Vorlage fest, der Bildschirm zeigt dasselbe wie der Druck. Mindestbreite, mobil waagerecht scrollbar.
- Warnungsbanner bei `warnings` mit Link „Konten zuordnen“ zum Sphären- bzw. Kostenstellen-Panel (N4).
- Hinweis statt leerer Tabelle, wenn keine Konten eine Sphäre haben (N4).

**`src/components/ReportTemplateDialog.vue` (neu, NcModal `size="large"`, `v-if`, eigene Überschrift + `labelId`):** Der Dialog arbeitet auf einer tiefen Kopie der Definition. „Speichern“ schickt sie per PUT oder POST. Bei 422 erscheinen die Serverfehler oben im Dialog. Die Prüfregeln aus `ReportDefinition` sind in `src/lib/reportDefinition.js` gespiegelt, damit Fehler schon beim Bearbeiten sichtbar werden. Maßgeblich bleibt der Server.

| Reiter | Inhalt |
|---|---|
| Allgemein | Name, „Plannotizen als Erläuterung“, Bezeichnung der Gesamtzeile (leer = keine) |
| Struktur | siehe unten |
| Spalten | Liste mit Quelle (Plan/Ist/Differenz) + Periode (gewählte, Vorperiode, vor 2 Perioden), sortier- und löschbar, höchstens 8 Spalten |
| Gruppen | Je Berichtsgruppe: Name, zugeordnete Kostenstellen (Mehrfachauswahl) und Konten (`AccountPickerSheet`). „Gruppe hinzufügen“. Kostenstellen ohne Eintrag erscheinen grau als „automatisch“. |

**Reiter „Struktur“** (`src/components/ReportStructureEditor.vue`, rekursiv über `ReportSectionEditor.vue`):
- **Abschnittsbaum** als eingerückte Liste. Je Abschnitt gibt es Knöpfe für ↑ ↓ (innerhalb der Geschwister), „Unterabschnitt“, „Abschnitt danach“ und „Löschen“. Es wird nicht gezogen, damit Tastatur und Mobilgeräte ohne Sonderfall auskommen. Höchstens 4 Ebenen tief. Ab der vierten Ebene ist „Unterabschnitt“ deaktiviert und zeigt einen Tooltip.
- **Ein Abschnitt aufgeklappt** zeigt:
  - Titel
  - Filter: Kontoart als zwei Kontrollkästchen; Sphären und Gruppen als `NcSelect` mit Mehrfachauswahl, wobei „alle“ einer leeren Auswahl entspricht
  - „Ausblenden, wenn leer“, „Nur Zusammenfassung (zählt nicht zur Gesamtsumme)“
  - Fußzeile: Kontrollkästchen + Bezeichnung. Daneben steht als Hinweis, ob daraus eine **Summe** oder ein **Ergebnis** wird. Das ergibt sich aus dem wirksamen Filter (§4.4), so ist die Regel in der Oberfläche sichtbar.
- **Inhalt:** Umschalter *Unterabschnitte* / *Gliederungsebenen*. Beim Wechsel muss bestätigt werden, wenn dadurch etwas verloren geht. Gliederungsebenen werden als geordnete Liste bearbeitet:
  - Je Ebene: Merkmal (`NcSelect`: Kontoart, Sphäre, Gruppe, Konto), ↑ ↓, Löschen, Fußzeile mit Bezeichnung, „Ausblenden, wenn leer“.
  - Ebene **Konto**: zusätzlich Tiefe (Planungsebene, 0–3, alle) und „Oberkonten als Zwischenüberschrift“.
  - „Konto“ ist nur als letzte Ebene wählbar, schon gewählte Merkmale sind ausgegraut.
- **Gliederungsvorschau** rechts (unter 1024 px darunter): Ein Skelett der Überschriften ohne Zahlen, rein clientseitig aus der Definition gezeichnet, z. B. „Einnahmen › Ideeller Bereich › Konten“. Es gibt keinen Vorschau-Endpunkt. Die Zahlen zeigt die Berichtsansicht nach dem Speichern, ein POST nur für die Vorschau würde jedes Mal eine Revision erzeugen.
- **Barrierefreiheit:** Jeder Abschnitt ist eine `fieldset` mit `legend` (Titel bzw. „Abschnitt ohne Titel“). Die Knöpfe ↑ ↓ haben `aria-label` „{Titel} nach oben/unten“. Nach dem Verschieben bleibt der Fokus auf dem verschobenen Abschnitt.

## 9. Tests

- **PHPUnit `ReportDefinitionTest`:** Normalisierung, jede Validierungsregel, Fehlermeldungen
- **PHPUnit `StructuredReportBuilderTest`** mit einer Fixture aus `example/Kontendefinition.xlsx` (Nummern, Hierarchie, Sphären, Gruppenmodus):
  - Ministeriumsvorlage: Reihenfolge der Überschriften, „Summe der Titelgruppe“ 51/52/55, Zusammenfassung nach Hauptgruppen
  - Verbandsvorlage: Ergebnis ideell + Ergebnis Zweckbetrieb = Saldo = Einnahmen − Ausgaben gesamt
  - Saldo = `BudgetTree::totals()` des Finanzplans (N1)
  - Umgruppierung: Konto vor Kostenstelle, tiefstes Konto gewinnt, gelöschte Konto-ID ignoriert
  - `ancestors` true/false, `depth` plan/0/all
  - Fußzeilenart nach Filter (Summe vs. Ergebnis)
  - `hideEmpty`; Spalte ohne Vorperiode → `null`
  - Abdeckung: nicht erfasstes Konto → automatischer Abschnitt + Warnung; Überschneidung → `duplicate`
- **PHPUnit `CostCenterResolverTest`** + Regression `costCenterReport()` für alle drei Modi
- **PHPUnit Controller:** 403 auf `builtin:*`, 422 bei ungültiger Definition, 404 bei unbekannter Vorlage
- **PHPUnit Export:** CSV-Kopf und Ebenen; Renderer gibt Warnungen und Notizen aus
- **Vitest `reportDefinition.js`:** gleiche Fälle wie `ReportDefinitionTest`, damit Client und Server dieselben Regeln haben
- **E2E `gliederungsbericht.spec.mjs`:**
  - Vorlage wählen, Kopie anlegen
  - Gruppe umbenennen und Kostenstellen zusammenfassen
  - im Reiter „Struktur“ einen Abschnitt verschieben und eine Ebene ändern, speichern, die geänderte Reihenfolge erscheint im Bericht
  - Druckseite öffnen
  - Revisor sieht keine Bearbeiten-Knöpfe
- Vor dem Push: PHPStan, PHPUnit, `npx eslint src/`, `npm run stylelint`, Druckseite gegen den Container prüfen

## 10. Entscheidungen (17.10.2026)

1. **Struktur-Editor gleich mit:** Abschnitte, Filter und Gliederungsebenen sind in der ersten Version in der Oberfläche bearbeitbar (§8, Reiter „Struktur“). Damit ist F1 vollständig erfüllt.
2. **Name:** Untertab „Gliederung“, im Handbuch „Gliederungsbericht“.
3. **Kontobezug über die Konto-ID:** Er bleibt bei Umnummerierung erhalten. Vorlagen sind dafür an die Instanz gebunden, das ist unkritisch, solange es keinen Vorlagen-Export gibt.
4. **Nicht im Umfang:** Die Vorlage wird nicht in Kassen- oder Kurzbericht wiederverwendet. Möglich wäre das später, weil die Zeilenliste nicht an eine Ausgabeform gebunden ist.

## 10a. Umsetzungsreihenfolge

Ein Feature-Branch, aufgeteilt in Commits, die jeweils für sich prüfbar sind:

1. `CostCenterResolver` herauslösen + Regressionstests (kein sichtbarer Unterschied)
2. Migration, Entity, Mapper, `ReportDefinition` + Tests
3. `StructuredReportBuilder` + `StructuredReportService` + `BuiltinReportTemplates` + Tests mit der Fixture
4. Controller und Routen, Druck-Renderer, CSV
5. Frontend: Berichtsansicht + Vorlagenauswahl + Kopieren/Löschen
6. Frontend: Vorlagen-Dialog (Allgemein, Spalten, Gruppen)
7. Frontend: Reiter „Struktur“ + `reportDefinition.js`
8. E2E, Handbuch, CHANGELOG, Version

## 11. Auslieferung & Doku

- Minor-Version. Neue Tabelle, also Version-Bump und `occ upgrade` in der Testinstanz.
- CHANGELOG (DE/EN), `whatsNew.js`, HANDBUCH (DE/EN) mit neuem Kapitel „Gliederungsbericht“ und stabilem Anker im HelpController, `l10n/de.json` und `en.json`.
- `example/` wird **nicht** eingecheckt. Die Testfixture enthält nur Nummern, Namen und Sphären, keine Beträge.

## 12. Abweichungen bei der Umsetzung

- **Gruppenschlüssel mit Präfix:** Im Filter heißen Berichtsgruppen `t:<key>`
  (von der Vorlage festgelegt) bzw. `cc:<Schlüssel der Kostenstelle>`, damit
  sich ein Gruppenschlüssel der Vorlage nie mit einem Kostenstellen-Code
  überschneidet.
- **Vorlagenliste mit Gruppen:** `GET /api/report-templates` liefert zusätzlich
  die Auswertungsgruppen; der Reiter „Gruppen" und die Gruppenfilter im
  Struktur-Editor brauchen sie, und ein eigener Endpunkt hätte nur denselben
  Kontenabruf wiederholt.
- **Reset:** „Alle Daten löschen" entfernt auch die eigenen Vorlagen – sie
  verweisen auf Konto-IDs und Kostenstellen, die danach nicht mehr existieren.
- **Kontoauswahl im Gruppen-Reiter:** `NcSelect` mit Mehrfachauswahl statt
  `AccountPickerSheet` – das Blatt ist für die mobile Buchungserfassung auf
  App-Ebene gebaut und lässt sich nicht in einem Dialog öffnen.
- **Kein Klappzustand in der Berichtsansicht:** wie geplant legt die Vorlage
  die Tiefe fest; Bildschirm, Druck und CSV zeigen dieselben Zeilen.
- **Versionsnummer:** bleibt bis zum Release-Commit bei 0.37.0; die Migration
  `Version000161` läuft mit dem nächsten Versions-Bump.
