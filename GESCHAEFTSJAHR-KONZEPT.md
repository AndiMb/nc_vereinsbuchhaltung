# Abweichendes Geschäftsjahr (Issue #8): Konzept und Umsetzungsplan

> **Umgesetzt am 08.09.2026** auf Branch `feature/geschaeftsjahr`, ausgeliefert
> mit 0.33.0. Ergebnis der Anforderungsklärung zu
> [Issue #8](https://github.com/AndiMb/vereinsbuchhaltung/issues/8).
>
> Diese Datei bleibt als Begründung der Entscheidungen stehen und ist auf den
> Stand der Umsetzung nachgeführt; Abschnitt 11 hält fest, wo der Bauplan
> unterwegs korrigiert wurde. Sie darf gelöscht werden, wenn sie nicht mehr
> gebraucht wird.

## 1. Was gefordert ist

Drei Vereine haben sich im Issue gemeldet:

| Verein | Geschäftsjahr |
|---|---|
| Ersteller | 1. Oktober bis 30. September |
| Kindergarten | August bis Juli |
| Studentischer Verein | semesterweise, „komplett frei definierbar“ |

Getroffene Entscheidungen (08.09.2026):

1. **Frei definierbare Perioden mit Presets.** Eine Regel erzeugt die
   Perioden automatisch; Presets füllen die Regel vor. Einzelne Perioden
   lassen sich nachträglich anpassen, etwa für ein Rumpfjahr beim Umstieg.
2. **Presets:** Kalenderjahr (Vorgabe), Okt–Sep, Aug–Jul, Semester
   (1. Okt–31. Mär und 1. Apr–30. Sep).
3. **Beliebiger Starttag im Monat**, nicht nur der Monatserste.
4. **Perioden-ID als interner Schlüssel**, Bezeichnung als gespeichertes,
   vom Verwalter frei änderbares Textfeld. Die Regel schlägt eine Bezeichnung
   vor („2025“, „2025/26“, „2025/26-1“), gespeichert wird immer der Text.
5. **Umstellung mit Bestand:** Buchungen werden neu zugeordnet und offene
   Perioden neu nummeriert. Solange eine Periode festgeschrieben ist, wird
   die Umstellung abgelehnt. Der Vorgang steht im Änderungsprotokoll.
6. Kein Kommentar im Issue vor dem Release.

Nicht betroffen: Beiträge, offene Posten, SEPA (rechnen ab Startdatum des
Mitglieds), Rücklagenbericht (kumuliert), Protokoll, Wachordner,
Kontoauszugsparser, Berechtigungen.

## 2. Der Befund im Code

`lib/Service/FiscalYear.php` bündelt die Umrechnung Jahr → Datumsgrenzen
und hat rund 35 saubere Aufrufer. Daran vorbei lesen 21 Stellen das Jahr aus
den ersten vier Zeichen des Datums oder codieren `01-01`/`12-31` fest.
Vier davon sind tragend:

- `Journal::yearOf()` (`lib/Db/Journal.php:65`) definiert, zu welchem Jahr
  eine Buchung gehört, und füllt die redundante Spalte `vbh_journal.year`.
  Darauf liegt der Unique-Index `(user_id, year, entry_no)` für die lückenlose
  Buchungsnummer.
- `YearCloseService::assertOpen()` (`lib/Service/YearCloseService.php:58`)
  entscheidet für 15 Schreibpfade per Datums-Präfix über die Festschreibung.
  `vbh_year_close` ist nur über die nackte Jahreszahl geschlüsselt.
- `XbucImportService` leitet das Jahr an sechs Stellen selbst ab, rechnet mit
  `$year + 1` und klemmt auf `01.01.`/`31.12.`. `XbucParser::projectYear()`
  liefert nur dann ein Jahr, wenn Min- und Max-Datum im selben Kalenderjahr
  liegen.
- Das Frontend (`src/composables/useYears.js`, `src/App.vue:50-56`) führt eine
  einzelne Ganzzahl `selectedYear` als Request-Parameter, Schlüssel der
  Sperrliste, Beschriftung und Basis für „Vorjahr = Jahr − 1“.

Eine Ganzzahl je Jahr kann Semester nicht abbilden. Deshalb ist die Kernidee
dieses Entwurfs: **die Jahreszahl wird überall durch eine Perioden-ID
ersetzt**, und alles, was heute aus dem Datum „das Jahr“ ableitet, fragt
stattdessen einen Dienst nach „der Periode zu diesem Datum“.

## 3. Datenmodell

### 3.1 Neue Tabelle `vbh_periods`

| Spalte | Typ | Bemerkung |
|---|---|---|
| `id` | BIGINT, PK, autoincrement | der Schlüssel, auf den alles verweist |
| `user_id` | STRING(64), notnull | Buch-Kennung wie in `vbh_journal` (`Application::BOOK`) |
| `label` | STRING(64), notnull | Bezeichnung, manuell änderbar |
| `start_date` | STRING(10), notnull | ISO `YYYY-MM-DD`, inklusive |
| `end_date` | STRING(10), notnull | ISO `YYYY-MM-DD`, inklusive |
| `closed_at` | STRING(32), nullable | Festschreibung, ersetzt `vbh_year_close` |
| `closed_by` | STRING(64), nullable | dito |

Indizes: Unique `(user_id, start_date)` als `vbh_period_user_start`;
Unique `(user_id, label)` als `vbh_period_user_label`.

Datumsgrenzen bleiben ISO-Strings wie das Buchungsdatum, weil der
lexikografische Vergleich mit dem chronologischen identisch ist. Das ist
dieselbe Begründung wie heute in `FiscalYear.php`, und sie gilt für jede
Periodengrenze genauso. SQL-Datumsfunktionen bleiben damit weiterhin
außen vor.

**Invarianten**, vom `PeriodService` erzwungen, nicht von der Datenbank:

- Perioden eines Buchs überlappen nicht.
- Aufeinanderfolgende Perioden sind lückenlos: `end_date + 1 Tag = start_date`
  der nächsten. Jedes Buchungsdatum liegt in genau einer Periode.
- Jede Periode ist mindestens einen Tag lang.

`vbh_year_close` entfällt. Der Abschluss-Marker wandert als `closed_at` /
`closed_by` auf die Periode. Das spart eine Tabelle und einen Fremdschlüssel,
und es beseitigt die heutige Asymmetrie, dass der Abschluss ohne `user_id`
global gilt, die Buchungen aber je Buch geschlüsselt sind.

### 3.2 Regel in der App-Konfiguration

Ein Schlüssel `fiscal_period_rule` in `appconfig`, JSON:

```json
{ "preset": "oct-sep", "startDay": 1, "startMonth": 10, "lengthMonths": 12 }
```

| Preset | startDay | startMonth | lengthMonths |
|---|---|---|---|
| `calendar` (Vorgabe) | 1 | 1 | 12 |
| `oct-sep` | 1 | 10 | 12 |
| `aug-jul` | 1 | 8 | 12 |
| `semester` | 1 | 10 | 6 |
| `custom` | 1–31 | 1–12 | 1, 2, 3, 4, 6 oder 12 |

Die Periodenlänge muss ein Teiler von 12 sein. Eine Länge wie 5 liefe Jahr für
Jahr weiter gegen den Kalender: nach zwölf Perioden begänne das Geschäftsjahr
in einem anderen Monat als am Anfang. Für eine Vereinsbuchhaltung ist das kein
sinnvoller Zustand, weil Beitragsjahre, Mitgliederversammlungen und Fristen am
Kalender hängen.

Fehlt der Schlüssel, gilt `calendar`. Bestehende Installationen verhalten
sich damit nach dem Update exakt wie vorher.

### 3.3 Verweise statt Jahreszahlen

| Tabelle | heute | künftig |
|---|---|---|
| `vbh_journal` | `year` INTEGER, Unique `(user_id, year, entry_no)` | `period_id` BIGINT, Unique `(user_id, period_id, entry_no)` |
| `vbh_budgets` | `year`, Unique `(user_id, account_id, year)` | `period_id`, Unique `(user_id, account_id, period_id)` |
| `vbh_budget_snapshots` | `year`, Index `(user_id, year)` | `period_id`, Index `(user_id, period_id)` |
| `vbh_year_close` | eigene Tabelle | entfällt |

Die alten `year`-Spalten werden nach der Datenübernahme entfernt, damit
Spalte und Periode nie auseinanderlaufen können. Genau dieses Risiko war der
Grund für `Journal::setDateWithYear()`; künftig gibt es nur noch
`setDateWithPeriod(string $date, Period $period)`.

### 3.4 Migration

Drei Schritte nach dem Muster von Version000119/000120 (erst Daten, dann
Index), aufgeteilt, damit kein Schema-Diff gleichzeitig einen Index und die
Spalte darunter anfasst – das Ergebnis hinge sonst von der Datenbank ab:

**Version000133** (Schema + Daten):

1. Tabelle `vbh_periods` anlegen. Spalte `period_id` (BIGINT, notnull,
   default 0) an `vbh_journal`, `vbh_budgets`, `vbh_budget_snapshots`.
2. `postSchemaChange`: je `user_id` alle vorkommenden Jahre aus
   `vbh_journal.year`, `vbh_budgets.year`, `vbh_budget_snapshots.year` und
   `vbh_year_close.year` sammeln. Für jedes Jahr eine Periode
   `label = "2025"`, `start = 2025-01-01`, `end = 2025-12-31` anlegen.
   Lücken zwischen Jahren ebenfalls als Perioden anlegen, damit die Kette
   lückenlos ist. Für die Jahre aus `vbh_year_close` `closed_at`/`closed_by`
   übernehmen.
3. `UPDATE … SET period_id = ? WHERE user_id = ? AND year = ?` je Periode und
   Tabelle. Ein UPDATE je Jahr, nicht je Zeile.
4. Kontrolle: bleibt in einer der drei Tabellen `period_id = 0`, bricht die
   Migration mit Fehlermeldung ab, statt stumm weiterzulaufen.

**Version000134** (Indizes):

1. Unique-Index `vbh_jrn_user_period_no` auf `(user_id, period_id, entry_no)`;
   alten Index `vbh_jrn_user_year_no` entfernen.
2. Unique-Index `vbh_budget_period_uniq` auf
   `vbh_budgets (user_id, account_id, period_id)`, Index `vbh_snap_user_period`
   auf `vbh_budget_snapshots (user_id, period_id)`; alte Indizes entfernen.

**Version000135** (aufräumen):

1. Spalten `year` in den drei Tabellen entfernen.
2. Tabelle `vbh_year_close` entfernen.

Die Buchungsnummern bleiben unangetastet: jede Kalenderjahr-Periode enthält
genau die Buchungen des bisherigen Jahres, die Nummerierung 1..N ist
weiterhin gültig. Festgeschriebene Jahre werden also auch hier nicht
verändert.

## 4. Backend

### 4.1 `PeriodRule` (rein, ohne Nextcloud)

Statische Datumsarithmetik nach dem Muster von `BillingPeriod`, damit sie
sich in `tests/unit` ohne Container prüfen lässt:

```php
final class PeriodRule {
    public const PRESETS = ['calendar' => [1, 1, 12], 'oct-sep' => [1, 10, 12], 'aug-jul' => [1, 8, 12], 'semester' => [1, 10, 6]];

    /** @return array{0:string,1:string} [start, end] der Periode, die $date enthält */
    public static function containing(array $rule, string $date): array;

    /** Startdatum der Periode nach der mit $start beginnenden */
    public static function nextStart(array $rule, string $start): string;

    /** Vorschlag für die Bezeichnung: „2025“, „2025/26“, „2025/26-1“ */
    public static function proposeLabel(array $rule, string $start): string;

    /** Zyklusjahr und laufende Nummer der Rasterperiode um ein Datum */
    public static function gridIndex(array $rule, string $date): array;

    public static function previousDay(string $date): string;
    public static function nextDay(string $date): string;

    /** @throws \InvalidArgumentException bei ungültiger Regel */
    public static function validate(array $rule): array;
}
```

Regeln für den Starttag: liegt `startDay` über der Monatslänge, gilt der
Monatsletzte (Starttag 31 im Februar = 28./29.). Das Ende einer Periode ist
immer `nächster Start − 1 Tag`. Dadurch ist die Kette per Konstruktion
lückenlos, und der 29. Februar braucht keine Sonderbehandlung.

Bezeichnungsvorschlag:

| Regel | Vorschlag |
|---|---|
| 12 Monate, Start 1. Januar | `2025` |
| 12 Monate, sonst | `2025/26` |
| kürzer als 12 Monate | `2025/26-1`, `2025/26-2`, … (laufende Nummer innerhalb des Startjahrs des ersten Teils) |
| Rumpfperiode (manuell verkürzt) | Vorschlag bleibt, Verwalter passt an |

### 4.2 `PeriodService`

Ersetzt `FiscalYear` und `YearCloseService`. Die Klasse `FiscalYear` wird
gelöscht; wer sie heute aufruft, ruft künftig den Dienst.

```php
class PeriodService {
    /** Alle Perioden des Buchs, absteigend nach start_date. */
    public function all(string $userId): array;

    public function find(string $userId, int $id): Period;

    /**
     * Die Periode, in die $date fällt. Legt sie bei Bedarf aus der Regel an
     * ($materialize = true, für Schreibpfade); liefert sonst null.
     */
    public function forDate(string $userId, string $date, bool $materialize = true): ?Period;

    /** Periode zum heutigen Tag, wird angelegt, falls sie fehlt. */
    public function current(string $userId): Period;

    public function previous(string $userId, Period $p): ?Period;
    public function next(string $userId, Period $p, bool $materialize = false): ?Period;

    /** @return array{0:?string,1:?string} [von, bis], [null, null] für „alle“ */
    public function range(string $userId, ?int $periodId): array;

    /** @throws PeriodClosedException */
    public function assertOpen(string $userId, string $date): void;

    public function close(string $userId, int $id, string $uid): Period;
    public function reopen(string $userId, int $id): void;

    public function updateLabel(string $userId, int $id, string $label): Period;

    /** Grenze zwischen $id und Nachfolger verschieben; beide müssen offen sein. */
    public function moveEnd(string $userId, int $id, string $newEnd): array;

    /** Erste/letzte leere Periode entfernen. */
    public function delete(string $userId, int $id): void;

    public function rule(): array;

    /** Vorschau der Umstellung, ohne zu schreiben. */
    public function previewRule(string $userId, array $rule): array;

    /** Umstellung anwenden; wirft, wenn eine Periode festgeschrieben ist. */
    public function applyRule(string $userId, array $rule, string $uid): array;

    public function deleteAll(string $userId): void;
}
```

**Materialisierung.** Perioden entstehen bei Bedarf, nie auf Vorrat:

- Gibt es noch keine Periode, entsteht die aus der Regel berechnete Periode
  um das angefragte Datum.
- Liegt das Datum nach der letzten Periode, werden ab deren `end_date + 1`
  so viele Perioden aus der Regel erzeugt, bis das Datum abgedeckt ist.
  Anker ist die vorhandene Kette, nicht die Regel allein. Damit bleibt eine
  manuell verschobene Grenze auch für alle Folgeperioden wirksam.
- Liegt das Datum vor der ersten Periode, wird rückwärts von deren
  `start_date − 1` erzeugt.
- `GET /api/periods` ruft vorher `current()`. So steht die laufende Periode
  immer im Dropdown, was heute `(int)date('Y')` in
  `JournalController::years()` erledigt.

**Grenze verschieben (`moveEnd`).** Nur wenn Periode und Nachfolger offen
sind. Der Nachfolger beginnt danach bei `newEnd + 1`. Buchungen zwischen alter
und neuer Grenze wechseln die Periode; beide Perioden werden nach Datum neu
nummeriert (siehe 4.3). Das Ende der letzten Periode darf frei verschoben
werden; landen Buchungen dadurch außerhalb, werden Folgeperioden
materialisiert. Für den Anfang der ersten Periode gilt dasselbe rückwärts.

**Regel umstellen (`applyRule`).** In einer Transaktion:

1. Abbruch, wenn irgendeine Periode `closed_at` trägt. Die Fehlermeldung nennt
   die Perioden.
2. Regel validieren und speichern.
3. Frühestes und spätestes Datum aus Journal ermitteln, dazu heute und die
   Grenzen der bestehenden Perioden (an denen Planwerte hängen können).
4. **Unveränderte Perioden bleiben stehen, nur die übrigen werden ersetzt.**
   Eine Periode, deren Grenzen die neue Regel exakt trifft, behält ID,
   Bezeichnung, Buchungen, Buchungsnummern und Planwerte – beim Umstellen
   fallen alte und neue Grenzen regelmäßig zusammen, bei einer unveränderten
   Regel sogar alle. Für die übrigen gilt: erst die weichenden löschen, dann
   die neuen anlegen, weil Beginn und Bezeichnung je Buch eindeutig sind und
   ein neuer Zeitraum mit einem weichenden den Beginn teilen kann. Dazwischen
   zeigen Planwerte kurz auf Perioden, die es nicht mehr gibt; unbedenklich,
   weil die Transaktion nach außen erst den Endzustand zeigt. Die
   Bezeichnungen bleibender Perioden werden bei der Planung zuerst
   reserviert, damit kein Vorschlag für eine neue Periode sie verdrängt.
5. Planwerte und Plan-Stände: jede alte Periode wird auf die neue Periode mit
   der größten Überschneidung abgebildet. Treffen zwei alte Planwerte
   desselben Kontos auf dieselbe neue Periode, gewinnt der mit der größeren
   Überschneidung, der andere wird verworfen. Die Vorschau zeigt das vorher.
   Plan-Stände kollidieren nicht: mehrere je Zeitraum sind vorgesehen.
6. `reassignAll()`: ein UPDATE je Periode über den Datumsbereich (wie in
   Version000119), danach neu nummerieren – in zwei getrennten Durchgängen:
   erst wandern alle Buchungen, dann wird nummeriert. Nur Perioden, die
   Buchungen aufgenommen haben, werden nach Datum neu nummeriert; die übrigen
   behalten ihre Reihenfolge, und das Nachnummerieren schließt dort höchstens
   die Lücke, die abgegebene Buchungen hinterlassen haben.
7. Protokolleintrag „Geschäftsjahr-Regel geändert“ mit alter und neuer Regel,
   Zahl der neu zugeordneten Buchungen und verworfenen Planwerte.

`previewRule` führt Schritt 3 bis 5 nur im Speicher aus und liefert die neue
Periodenliste, die Zahl wechselnder Buchungen und die betroffenen Planwerte.

### 4.3 Buchungsnummern

`EntryNumberService` bleibt, `int $year` wird `int $periodId`.
`JournalMapper::getNextEntryNoForYear()` und `findEntryNosForYear()` werden
zu `…ForPeriod()`. Neu: `renumberPeriodByDate()`. Wenn Buchungen aus zwei
alten Perioden in einer neuen zusammenlaufen, ist die bisherige Nummer keine
sinnvolle Ordnung mehr. Sortiert wird dann nach `(date, entry_no, id)`, und
die Nummern werden 1..N vergeben. Die Rechenvorschrift steht wie bisher als
reine Funktion `renumberPlanByDate(array $rows)` neben `renumberPlan()` und
wird in `EntryNumberServiceTest` geprüft. Dieser Pfad läuft nur bei
`applyRule` und `moveEnd`, nie im Tagesbetrieb.

### 4.4 Wer was aufruft

| Stelle heute | künftig |
|---|---|
| `BookContext::yearRange(?int $year)` | `periodRange(?int $period)` über `PeriodService::range()` |
| `FiscalYear::orCurrent($year)` (Budget, Kassenbericht) | `$period ?? $periodService->current($userId)->getId()` |
| `Journal::setDateWithYear($date)` | `setDateWithPeriod($date, $periodService->forDate($userId, $date))` |
| `YearCloseService::assertOpen($date)` (15 Aufrufer) | `PeriodService::assertOpen($userId, $date)` |
| `YearCloseService::closedYears()` in `AccountService::assertEvaluationOpen()` | `JournalLineMapper::findPeriodIdsForAccount()` geschnitten mit geschlossenen Perioden-IDs |
| `JournalController::years()` + `YearController::closed()` | `PeriodController::index()` |
| `KassenberichtRenderer`: `$year - 1`, „Bestand 01.01.“ | `previous($period)`, Spalten „Bestand 01.10.2025“ / „Bestand 30.09.2026“ aus `start_date`/`end_date` |
| `KurzberichtRenderer`: `date('Y')` | `current()` |
| `ReportService::multiyearTrend()`, `CsvExportService::multiyear()` | iterieren über `all()` aufsteigend; Spaltenkopf = Bezeichnung |
| Dateinamen `journal_2025.csv`, `belege_2025.zip` | Bezeichnung, `/` wird `-`: `journal_2025-26.csv` |
| `DashboardTab` „ggü. 2024“ | Bezeichnung von `previous()` |
| `AccountsTab` Vortragszeile `selectedYear + '-01-01'` | `period.startDate` |
| `Version000119`-Muster Bereichs-UPDATE | wiederverwendet in `applyRule` |

`YearClosedException` wird zu `PeriodClosedException`; die Zuordnung auf
HTTP 423 in `PermissionMiddleware` bleibt.

### 4.5 xbuc-Import

- `XbucParser` liefert statt `year` die beiden Daten `minDate`/`maxDate`.
- `XbucImportService` bestimmt daraus die Periode: liegen beide im selben
  `forDate()`, ist das die Dateiperiode, sonst null. Der Parameter
  `yearOverride` wird `periodOverride` (Perioden-ID). Die Klemmoption klemmt
  auf `start_date`/`end_date` der Periode. Der Jahresübergang benutzt
  `next()` und `previous()` statt `$year ± 1`.
- `ImportController::yearOverride()` prüft künftig gegen `find()` statt
  gegen 2000–2099.

## 5. API

Neue Routen (`appinfo/routes.php`):

| Methode | Pfad | Rolle | Zweck |
|---|---|---|---|
| GET | `/api/periods` | lesend | Liste, absteigend; materialisiert vorher die laufende Periode |
| GET | `/api/periods/rule` | lesend | aktuelle Regel |
| PUT | `/api/periods/rule` | Verwalter | Regel umstellen; `dryRun=1` liefert nur die Vorschau |
| PUT | `/api/periods/{id}` | Verwalter | `label` und/oder `endDate` |
| DELETE | `/api/periods/{id}` | Verwalter | leere Rand-Periode entfernen |
| POST | `/api/periods/{id}/close` | Verwalter | festschreiben |
| DELETE | `/api/periods/{id}/close` | Verwalter | wiedereröffnen |

Entfallen: `journal#years`, `year#closed`, `year#close`, `year#reopen`.

Antwortform einer Periode:

```json
{ "id": 7, "label": "2025/26", "startDate": "2025-10-01", "endDate": "2026-09-30",
  "closedAt": null, "closedBy": null, "bookings": 143, "hasBudget": true }
```

Der Query-Parameter `year` heißt an allen 16 Stellen künftig `period` und
trägt die Perioden-ID; fehlend oder `0` bedeutet weiterhin „alle“. Das
betrifft `journal#index`, `journal#balances`, `account#journal`,
`report#costCenters`, `report#spheres`, `budget#index`, `budget#set`,
`budget#snapshots`, `budget#createSnapshot`, `export#journal`,
`export#balances`, `export#report`, `export#budget`, `export#kassenbericht`,
`export#attachments`, `import#*`.

Abwärtskompatibilität für gespeicherte Links auf den Kassenbericht: kommt
`year` ohne `period`, wird die Periode genommen, die `year-01-01` enthält.

## 6. Frontend

### 6.1 `usePeriods` ersetzt `useYears`

```js
const state = reactive({
    periods: [],            // vom Server, absteigend
    selectedPeriodId: null, // null = alle
})
const selectedPeriod = computed(...)        // Objekt oder null
const periodsById = computed(...)
const closedSet = computed(...)             // id → Periode, nur geschlossene
const periodClosed = computed(...)          // gewählte Periode ist geschlossen
function periodForDate(date)                // lexikografischer Vergleich über state.periods
function isDateClosed(date)                 // ersetzt isYearClosed
function previousOf(period)
async function loadPeriods()               // GET /api/periods; Vorgabe = laufende Periode
```

Die Vorgabe beim Laden ist die Periode, die heute enthält, nicht mehr
`data[0]`. Bei einer Semester-Regel ist das im Mai das Sommersemester, auch
wenn schon ein Planwert fürs Wintersemester existiert.

`src/api.js`: alle `year`-Parameter werden `period`; die Funktionsnamen
bleiben, wo sie noch passen (`balances(periodId)`), `journalYears` /
`closedYears` / `closeYear` / `reopenYear` werden `periods` / `closePeriod` /
`reopenPeriod` / `periodRule` / `savePeriodRule` / `updatePeriod` /
`deletePeriod`.

### 6.2 Kopfzeile

Beschriftung „Jahr“ wird „Zeitraum“, die Option „Alle Jahre“ wird „Alle
Zeiträume“. Die Optionen zeigen `label` und 🔒. Der Tooltip zeigt
`01.10.2025 – 30.09.2026`.

### 6.3 Verbraucher von `selectedYear`

| Datei | Änderung |
|---|---|
| `App.vue` | Watcher auf `selectedPeriodId`; alle Aufrufe geben die ID weiter |
| `BookingDialog.vue` | Banner „Zeitraum {label} ist abgeschlossen“ über `periodForDate(form.date)` |
| `BookingsTab.vue` | „Buchungsnummern {label} nicht lückenlos“; Monatsgruppen unverändert (Kalendermonate bleiben sinnvoll) |
| `AccountsTab.vue` | „Saldo {label}“; Vortragszeile datiert auf `selectedPeriod.startDate` |
| `DashboardTab.vue` | „ggü. {label des Vorgängers}“; Monatsdiagramm läuft vom Startmonat der Periode über ihre Länge, bei Semestern also sechs Balken |
| `ReportsTab.vue` | Überschriften mit `label`; Trend-Diagramm beschriftet mit `label`; `addBudgetYear()` (freie Jahreszahl) wird ersetzt durch „Plan für nächsten Zeitraum anlegen“, das serverseitig `next(materialize=true)` auslöst |
| `BudgetSnapshotModal.vue` | „Zeitraum {label}“ |
| `SettingsXbucImport.vue` | Dropdown der Perioden statt Jahreszahl-Eingabe; Klemmhinweis nennt die echten Grenzen |
| `useBalances.js` | Vorperiode über `previousOf()` statt `- 1` |
| `SettingsApp.vue` | lädt `usePeriods` |

Der 2000–2099-Filter in `ReportsTab.vue:1402` und `SettingsXbucImport.vue:154`
entfällt.

### 6.4 Einstellungen: Abschnitt „Geschäftsjahr“

Der Abschnitt „Jahresabschluss“ im Zahnrad wird zu **„Geschäftsjahr“** und
bekommt zwei Karten:

**Karte 1: Regel.** Radiogruppe mit den vier Presets und „Eigene Regel“
(Starttag, Startmonat, Länge in Monaten). Darunter der Text „Beispiel:
01.10.2025 – 30.09.2026“. Button „Vorschau“ öffnet einen NcModal-Dialog mit
der Tabelle der künftigen Perioden, der Zahl der Buchungen, die den Zeitraum
wechseln, und den Planwerten, die verworfen würden. Erst dort „Übernehmen“.
Ist eine Periode festgeschrieben, ist die Karte ausgegraut mit dem Hinweis,
welche Perioden erst wiedereröffnet werden müssten.

**Karte 2: Zeiträume.** Die heutige Tabelle aus `SettingsYearClose.vue`,
erweitert um Bezeichnung (inline editierbar), Von/Bis und ein Datumsfeld für
das Ende, das nur bei offener Periode und offenem Nachfolger aktiv ist.
Buttons „Abschließen“/„Wiedereröffnen“ wie heute, „Nächsten Zeitraum
anlegen“ am Kopf, „Entfernen“ nur bei leerer erster oder letzter Periode.

Die Rollenprüfung bleibt wie heute: lesen darf jeder Berechtigte, schreiben
nur Verwalter (`RequiresRole` plus zweite Schicht im Controller).

## 7. Protokoll, Texte, Doku

Protokolleinträge (`AuditService`): „Zeitraum abgeschlossen“, „Zeitraum
wiedereröffnet“, „Zeitraum umbenannt“, „Zeitraumgrenze verschoben“,
„Geschäftsjahr-Regel geändert“. Als Ziel jeweils `period` mit ID und
Bezeichnung.

Alle Texte, die heute „Jahr“ oder „Kalenderjahr“ sagen, werden auf
„Geschäftsjahr“ oder „Zeitraum“ umgestellt, in `l10n/de.json` und
`l10n/en.json`.

Dokumentation: HANDBUCH (Kapitel 2 neue Einstellung, Kapitel 8 wird
„Geschäftsjahr und Festschreibung“, Glossar), README (Zeilen zu
„Kalenderjahr“, Tabellenübersicht ohne `vbh_year_close`), beide englischen
Varianten, CHANGELOG, `info.xml`, Splash-Screen (`WhatsNewService`).
Versionsnummer 0.33.0.

## 8. Tests

Unit (`tests/unit`, ohne Nextcloud):

- `PeriodRuleTest`: alle vier Presets; Starttag 31 über Februar und April;
  29. Februar; Länge 1 und 12; Kette über zehn Perioden lückenlos und
  überlappungsfrei; Bezeichnungsvorschläge; ungültige Regeln.
- `EntryNumberServiceTest`: `renumberPlanByDate()` mit zwei verschmolzenen
  Perioden und gleichem Datum.
- `PeriodServiceTest` mit Mapper-Stub (`tests/stubs`): `forDate` vorwärts,
  rückwärts, ohne Bestand; `assertOpen` an beiden Grenztagen; `applyRule`
  lehnt bei Festschreibung ab; Budget-Abbildung nach Überschneidung.
- `FiscalYearTest` entfällt.

E2E (`tests/e2e`):

- `07-year-close.spec.mjs` auf Perioden umgestellt.
- Neu `21-fiscal-period.spec.mjs`: Preset Okt–Sep setzen, Buchungen am
  15.12.2025 und 15.01.2026 landen in „2025/26“ mit Nummern 1 und 2;
  Kassenbericht trägt „2025/26“ und die Stichtage 01.10./30.09.; Umstellung
  auf Semester verteilt beide auf „2025/26-1“; Umstellung wird bei
  festgeschriebener Periode abgelehnt; Kalenderjahr zurück stellt den
  Ausgangszustand her.
- `14-budget.spec.mjs`: Jahreszahl 2026 durch Perioden-ID aus der Liste
  ersetzen.

Vor dem Push: PHPStan und PHPUnit aus `.phpstan/vendor/bin/`, `npx eslint
src/`, Migration auf der Docker-Testinstanz mit Bestandsdaten und
Versionssprung.

## 9. Arbeitspakete

Ein Branch `feature/geschaeftsjahr`, vier Pakete, die nacheinander grün sein
müssen. Paket 1 und 2 sind erst gemeinsam auslieferbar, weil die API den
Parameter umbenennt.

1. **Backend-Modell.** `Period`, `PeriodMapper`, `PeriodRule`,
   `PeriodService`, Migrationen 133/134, Umstellung aller Aufrufer,
   `PeriodController`, Löschen von `FiscalYear`, `YearClose*`,
   `YearController`. PHPStan grün, Unit-Tests grün, Migration auf der
   Testinstanz mit Demo-Daten geprüft.
2. **Frontend-Modell.** `usePeriods`, `api.js`, Kopfzeile, alle Verbraucher
   aus 6.3. App verhält sich mit Kalenderjahr-Regel wie 0.32.0.
3. **Einstellungen.** Abschnitt „Geschäftsjahr“, Vorschau-Dialog, Regel
   umstellen, Grenzen verschieben, Protokoll.
4. **Ränder.** xbuc-Import, Kassenbericht-Stichtage, Dateinamen,
   Monatsdiagramm, Demo-Daten, Doku in beiden Sprachen, Splash-Screen,
   E2E-Tests, CHANGELOG, Version.

## 10. Bewusst nicht gemacht

- **Kein Parallelbetrieb von `year` und `period_id`.** Zwei Wahrheiten für
  dieselbe Frage waren schon einmal der Grund für `setDateWithYear()`.
- **Kein automatisches Umhängen festgeschriebener Perioden.** Wer die Regel
  ändern will, eröffnet vorher wieder. Das ist die Entscheidung aus Punkt 5.
- **Keine Perioden auf Vorrat.** Sie entstehen beim ersten Datum, das sie
  braucht, oder beim Laden der Liste für den heutigen Tag.
- **Kein eigener Schlüssel für Beiträge.** Zahlungsfrequenzen bleiben an das
  Startdatum des Mitglieds gebunden; „jährlich“ heißt weiterhin zwölf
  Monate ab Anker, nicht „je Geschäftsjahr“.
- **Kein Umbau der Monatsgruppen im Buchungsjournal.** Kalendermonate
  bleiben als Gliederung sinnvoll, unabhängig vom Geschäftsjahr.

## 11. Was der Bauplan nicht vorhergesehen hat

Sechs Stellen, an denen die Umsetzung vom Entwurf abweicht – die letzten
zwei fielen erst beim Durchstich gegen eine echte Instanz auf:

1. **Drei Migrationen statt zwei.** Ein Schema-Diff, der einen Index entfernt
   und im selben Schritt die Spalte darunter, führt je nach Datenbank zu
   unterschiedlichen Ergebnissen. Indizes (000134) und Spalten (000135) sind
   deshalb getrennt.
2. **Reihenfolge in `applyRule`.** Siehe Abschnitt 4.2, Punkt 4: der Entwurf
   wollte erst anlegen, dann löschen. Das kollidiert mit dem Unique-Index auf
   `(user_id, start_date)`, sobald eine Grenze unverändert bleibt. Die erste
   Umsetzung löschte deshalb alle Perioden und legte alle neu an – mit drei
   Nebenwirkungen: jede Buchung galt als „umgehängt“ (die Erfolgsmeldung
   nannte eine andere Zahl als die Vorschau), auch unberührte Zeiträume
   wurden nach Datum neu nummeriert, und jede Perioden-ID wechselte. Seit der
   Nachbesserung am 09.09.2026 bleiben Perioden mit unveränderten Grenzen
   stehen.
   **Zweiphasiges `reassignAll()`.** Die erste Fassung nummerierte jede
   Periode direkt nach dem Umhängen. Beim Verlängern eines Zeitraums
   (`moveEnd` nach hinten) wandern Buchungen aus dem *Folgezeitraum* in den
   verlängerten – der Folgezeitraum steht in der absteigenden Liste aber
   vorn, war also schon lückenlos nummeriert, als ihm die ersten Buchungen
   entzogen wurden, und behielt eine Lücke. Der E2E-Test „Grenze nach hinten
   verschieben“ deckt genau diesen Fall ab.
3. **Periodenlänge nur als Teiler von 12.** Der Entwurf ließ 1 bis 12 zu.
4. **`GET /api/periods` legt den laufenden Zeitraum an.** Eine schreibende
   GET-Anfrage, ausgelöst auch von Nutzern mit reinem Leserecht. Bewusst so:
   die Zeile trägt keine Daten und entsteht höchstens einmal je
   Geschäftsjahr. Die Alternative wäre ein Auswahleintrag ohne ID gewesen,
   den kein Endpunkt annehmen könnte – oder ein Auswahlfeld, in dem am
   1. Oktober das neue Geschäftsjahr fehlt, bis jemand die erste Buchung
   anlegt.

5. **Buchungsnummern müssen vor dem Umhängen geparkt werden.** Verschmelzen
   zwei Zeiträume, treffen zwei Buchungen mit der Nummer 1 aufeinander, und
   der Unique-Index `(user_id, period_id, entry_no)` lässt schon das UPDATE
   nicht zu – lange bevor die Neunummerierung dran wäre. Die wechselnden
   Zeilen bekommen deshalb vorher eine eindeutige negative Zwischennummer
   (`JournalMapper::parkEntryNosForMove()`).
   Stolperstein dabei: `IQueryBuilder::set($spalte, $wert)` quotet **auch den
   Wert** als Spaltennamen. Ein `set('entry_no', '-id')` wird dadurch zu
   einem doppelt gequoteten Namen, den SQLite klaglos als Zeichenkette
   `'-id'` liest – für eine Zahlenspalte also 0, und zwar für jede Zeile
   dieselbe. Richtig ist `createFunction('-' . $qb->getColumnName('id'))`.
6. **Die Periodenkette darf nicht alle bestehenden Zeiträume abdecken.** Der
   Entwurf ließ die neue Kette den Bereich aller alten Perioden umspannen.
   Da das Raster einer neuen Regel die alten Grenzen selten genau trifft,
   entsteht dabei vorn und hinten je eine neue Randperiode – und die zählte
   beim nächsten Wechsel wieder mit. Nach sieben Umstellungen standen im
   Auswahlfeld acht Geschäftsjahre, von denen sechs leer waren. Maßgeblich
   sind jetzt nur die Zeiträume der Buchungen, die mit Planwerten oder
   Plan-Ständen, und der heutige Tag.

Dazu kam ein Fund, der nicht im Entwurf stand: der `TransactionRunner` hatte
kein Gegenstück zu `afterCommit()`. Der `PeriodService` hält die Zeiträume des
Requests im Speicher; nach einem Rollback stand dort ein Zustand, den es nicht
mehr gab. Sichtbar geworden wäre das beim Wiederholungsversuch der
Buchungsnummer, der nach einem Rollback weiterarbeitet. `afterRollback()`
schließt die Lücke.

## 12. Offene Punkte

- Beim Entfernen der `year`-Spalten baut SQLite die Tabellen neu auf. Auf der
  Testinstanz mit MariaDB fällt das nicht auf; vor dem Release einmal gegen
  SQLite prüfen.
- Die Bezeichnung ist je Buch eindeutig. Ob Verwalter das als Hürde
  empfinden, zeigt sich erst im Gebrauch; die Vorschläge sind eindeutig.
- Semester-Preset: Start 1. Oktober ist die Konvention der meisten deutschen
  Hochschulen. Bayern beginnt teils am 1. Oktober, Fachhochschulen oft am
  1. September. Wer abweicht, nimmt „Eigene Regel“.
