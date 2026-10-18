# Design: Gliederungsbericht – Vorlagen löschen und neu anlegen

Stand: 18.10.2026 · Status: umgesetzt (Branch feature/gliederung-vorlagen-verwalten)
Anforderungen: `docs/gliederungsbericht-vorlagen-verwalten-anforderungen.md` (F1–F14, N1–N4)
Grundlage: `docs/gliederungsbericht-design.md` (0.38.0)

## 1. Ausgangslage

| Baustein | Heute (0.38.0) | Änderung |
|---|---|---|
| `BuiltinReportTemplates` | liefert zwei Vorlagen mit IDs `builtin:…`, Texte zur Laufzeit übersetzt | liefert nur noch **Definitionen zum Anlegen** (Name + Definition), keine IDs mehr nach außen |
| `ReportTemplateService` | mischt mitgelieferte (Code) und eigene (DB); `builtin:*` → 403 beim Ändern/Löschen | nur noch DB-Vorlagen; legt die Standardvorlagen **einmalig** an; `addStandard()` neu |
| `ReportTemplateController` | `index`, `create`, `update`, `destroy`, `evaluate` | neu: `addStandard` (POST) |
| `ResetService` | löscht `vbh_report_templates` | zusätzlich nach dem Commit: Vermerk „angelegt“ zurücksetzen |
| `StructuredReportView.vue` | Bearbeiten/Löschen nur für eigene, Suffix „(eigene Vorlage)“ | alle Vorlagen gleich; neu „Neue Vorlage“, Menü „Weitere Aktionen“, leerer Zustand |

**Keine Schemaänderung.** Der Vermerk ist ein App-Wert, die Sperre kommt aus Nextcloud.

## 2. Einmaliges Anlegen (F1–F3, N1, N2)

### 2.1 Vermerk

App-Wert `report_templates_seeded` (`IAppConfig::setValueString`, Wert `'1'`).
`IAppConfig` statt `IConfig`, weil Nextcloud App-Werte je Request im Speicher
hält: nach dem Sperren leert `clearCache(true)` diesen Speicher, sonst sähe die
zweite Prüfung einen Vermerk nicht, den ein anderer Request gerade gesetzt hat.

- **Nicht gesetzt:** Die Standardvorlagen wurden in diesem Verein noch nie angelegt.
- **Gesetzt:** Sie wurden angelegt. Löscht jemand alle Vorlagen, bleibt der
  Vermerk gesetzt, beim nächsten Öffnen kommt nichts zurück (F2).
- Wie alle Daten gilt er für den gemeinsamen Bestand (`Application::BOOK`);
  ein App-Wert genügt, weil es je Instanz genau einen Verein gibt.

Bestehende 0.38.0-Installationen haben den Wert nicht. Ihre Vorlagen werden
beim ersten Öffnen nach dem Update angelegt; vorhandene Kopien bleiben (F3).

### 2.2 Ablauf in `ReportTemplateService::all()`

```
all(userId)
 ├─ Vermerk gesetzt? ─ ja ─────────────────────────────► Vorlagen aus DB
 └─ nein
     ├─ Sperre „vereinsbuchhaltung/report-templates-seed“ (exklusiv) holen
     │    └─ LockedException ─► nicht anlegen, Vorlagen aus DB (anderer Request legt gerade an)
     ├─ App-Wert-Cache leeren, Vermerk erneut prüfen (Double-Checked)
     ├─ in einer Transaktion: Standardvorlagen einfügen
     ├─ Vermerk setzen
     ├─ Sperre freigeben (finally)
     └─ Vorlagen aus DB
```

- **Sperre:** `OCP\Lock\ILockingProvider::acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE)`.
  Nextcloud nutzt dafür Memcache oder die Tabelle `oc_file_locks`; Locking ist
  standardmäßig aktiv. Ist das Locking abgeschaltet (`filelocking.enabled = false`),
  liefert Nextcloud einen No-Op-Provider – dann bleibt nur der
  Double-Check. Das Restrisiko, dass zwei gleichzeitige allererste Aufrufe
  beide anlegen, ist in dieser Konfiguration hinnehmbar und behebbar (Löschen).
- **Wer anlegt:** jeder lesende Aufruf, auch ein Revisor (N2). Das Anlegen ist
  eine Systemaktion beim Lesen, keine Bearbeitung; die
  `PermissionMiddleware` prüft nur die Rolle für den Endpunkt (GET = lesen).
- **Sprache:** Die Texte kommen aus `BuiltinReportTemplates` mit dem `IL10N`
  des laufenden Requests, also in der Sprache der Person, die öffnet.
- **Reihenfolge:** Die Standardvorlagen werden **hinten angehängt**
  (`maxPosition + 1`, `+ 2`), vorhandene Vorlagen behalten ihre Plätze. Bei
  Neuinstallationen sind sie ohnehin die ersten.

### 2.3 Änderungsprotokoll

Das Anlegen läuft innerhalb eines GET und erzeugt daher keine Revision über
die `RevisionMiddleware`. Andere Sitzungen sehen die Vorlagen beim nächsten
eigenen Laden – unkritisch, weil sie beim Öffnen des Reiters ohnehin neu
geladen werden. „Standardvorlagen hinzufügen“ (POST) erzeugt wie jede
Änderung eine Revision.

## 3. Komponenten

### 3.1 `BuiltinReportTemplates` (umgebaut)

```php
class BuiltinReportTemplates {
    public const GRANTOR = 'zuwendungsgeber';
    public const AREAS = 'bereiche';

    /** @return list<array{key:string, name:string, definition:array<string,mixed>}> */
    public function all(): array;              // beide, übersetzt, normalisiert
    public function find(string $key): ?array; // für Tests
}
```

`PREFIX` und die IDs `builtin:…` entfallen. Der Klassenname bleibt; der
Docblock beschreibt sie als „Standardvorlagen zum Anlegen“.

### 3.2 `ReportTemplateService`

| Methode | Verhalten |
|---|---|
| `all(userId)` | wie §2.2, liefert `list<{id, name, definition}>` |
| `find(userId, id)` | nur numerische IDs; alles andere (`builtin:…`) → `null` (F5) |
| `create / update / delete` | unverändert, ohne die 403-Sonderregel |
| `addStandard(userId)` (neu) | legt beide Standardvorlagen erneut an, setzt den Vermerk; liefert die neuen Vorlagen und die Namen, die es danach doppelt gibt (F8, F9) |
| `forgetSeeded()` (neu) | löscht den Vermerk – für den Reset |
| `deleteAllForUser(userId)` (neu) | reicht an den Mapper durch – damit der Reset nur noch den Dienst kennt |

Das Feld `builtin` verschwindet aus allen Antworten. `StructuredReportService`
und `GliederungsberichtRenderer` verlieren es ebenfalls.

### 3.3 API

| Route | Verb | Rolle | Änderung |
|---|---|---|---|
| `/api/report-templates` | GET | Revisor+ | legt beim ersten Aufruf an; `templates[]` ohne `builtin` |
| `/api/report-templates/standard` | POST | Buchhalter+ | **neu**: `201 {templates: [...], duplicateNames: [...]}` |
| `/api/report-templates/{id}` | PUT/DELETE | Buchhalter+ | kein 403 mehr; `builtin:…` → 404 |
| `/api/report/structured`, Exporte | GET | Revisor+ | `template=builtin:…` → 404 |

Die Route `/standard` steht in `routes.php` **vor** `/{id}`, damit sie nicht
als ID gelesen wird. (Bei PUT/DELETE gäbe es keine Überschneidung, bei POST
gibt es keine `/{id}`-Route – die Reihenfolge ist trotzdem die sichere Wahl.)

### 3.4 Reset (F14)

`ResetService` bekommt statt `ReportTemplateMapper` den `ReportTemplateService`:

- in der Transaktion: `deleteAllForUser($userId)`
- in `afterCommit`: `forgetSeeded()` – wie die übrigen Einstellungen erst
  nach dem Commit, damit ein Abbruch keinen Vermerk ohne Vorlagen hinterlässt.

Beim nächsten Öffnen greift §2.2 und legt die Standardvorlagen neu an.

### 3.5 Frontend

**`src/lib/reportDefinition.js`**
- neu `starterDefinition()`: ein Abschnitt „Konten“ mit Ebene Konto
  (`depth: 'plan'`), Spalten Plan/Ist Offset 0, `grandTotal` „Saldo“,
  `showPlanNotes: false`, `groups: []` (F6). Besteht `validate()` ohne Fehler.

**`StructuredReportView.vue`**
- Werkzeugleiste (nur `canWrite`):
  - **„Neue Vorlage“** öffnet den Dialog mit `{ id: null, name: '', definition: starterDefinition() }` und `isNew`.
  - „Kopieren und anpassen“, „Bearbeiten“, „Löschen“ für jede gewählte Vorlage.
  - `NcActions` **„Weitere Aktionen“** mit „Standardvorlagen hinzufügen“ (F10).
- Auswahl: Namen ohne Zusatz „(eigene Vorlage)“.
- **Leerer Zustand** (F11): `templates.length === 0` → `NcEmptyContent`
  „Noch keine Vorlage“ mit Beschreibung; für `canWrite` die Knöpfe „Neue
  Vorlage“ und „Standardvorlagen hinzufügen“. Druck/CSV-Links und Tabelle
  ausgeblendet.
- Nach „Standardvorlagen hinzufügen“: Toast „Standardvorlagen hinzugefügt“;
  bei `duplicateNames` zusätzlich ein Hinweis „Es gibt jetzt mehrere Vorlagen
  mit dem Namen „…“ – zum Unterscheiden eine umbenennen.“ (F9). Danach ist
  die erste neu angelegte Vorlage gewählt.
- Nach dem Löschen: erste verbleibende Vorlage oder leerer Zustand (F13) –
  bestehendes Verhalten von `loadTemplates()`.
- Gespeicherte Auswahl `builtin:…` im `localStorage` passt zu keiner Vorlage
  → erste Vorlage (F5) – bestehendes Verhalten.

**`ReportTemplateDialog.vue`** – unverändert; „Neue Vorlage“ nutzt ihn mit
leerem Namen, sodass „Die Vorlage braucht einen Namen.“ sofort sichtbar ist
und der Fokus im Namensfeld steht.

## 4. Abläufe

**Erstes Öffnen nach dem Update (0.38.0 → neu)**
```
GET /report-templates ─► Vermerk fehlt ─► Sperre ─► 2 Vorlagen anlegen ─► Vermerk ─► Liste (Kopien + 2 neue)
Browser: localStorage = "builtin:zuwendungsgeber" ─► nicht in Liste ─► erste Vorlage gewählt
```

**Alles löschen, dann zurückholen**
```
DELETE /report-templates/7, /8 ─► Liste leer ─► leerer Zustand
POST /report-templates/standard ─► 2 neue Vorlagen ─► Auswahl auf die erste
```

## 5. Zuordnung der Anforderungen

| Anforderung | Umsetzung |
|---|---|
| F1–F3 | §2.2 `all()` mit Vermerk und Sperre |
| F4 | `builtin` entfällt; Frontend ohne Sonderfall |
| F5 | `find()` nur numerisch; Frontend-Fallback |
| F6–F7 | `starterDefinition()`, Knopf „Neue Vorlage“ |
| F8–F10 | `addStandard()`, `POST /standard`, Menü „Weitere Aktionen“ |
| F11 | `NcEmptyContent` |
| F12–F13 | unverändert |
| F14 | §3.4 |
| N1 | `ILockingProvider` + Double-Check |
| N2 | GET-Rolle; `/standard` als POST ab Buchhalter |
| N3 | Handbuch 7.4, CHANGELOG, `l10n/en.json` |
| N4 | `StructuredReportBuilder` unverändert |

## 6. Tests

- **PHPUnit `ReportTemplateServiceTest`** (umgebaut):
  - erster Aufruf legt beide an und setzt den Vermerk
  - zweiter Aufruf legt nichts an
  - nach Löschen aller Vorlagen und gesetztem Vermerk wird nichts neu angelegt
  - belegte Sperre (`LockedException`) → nichts angelegt, keine Ausnahme
  - Vermerk zwischen Prüfung und Sperre gesetzt → nichts angelegt (Double-Check)
  - Sperre wird auch bei einem Fehler beim Einfügen freigegeben
  - `addStandard()` legt beide an, meldet doppelte Namen
  - `find('builtin:…')` → null; `update`/`delete` früherer IDs → nicht gefunden
- **PHPUnit `ResetServiceTest`:** Vorlagen werden gelöscht, `forgetSeeded()` läuft erst nach dem Commit.
- **PHPUnit Controller/Routen:** `ControllerRoleDeclarationTest`, `RoutesTest` für `addStandard`.
- **Bestehende Tests** auf `BuiltinReportTemplates::find($key)` ohne Präfix umstellen.
- **Vitest:** `starterDefinition()` besteht `validate()`.
- **E2E `42-gliederungsbericht`** umgebaut:
  - Standardvorlagen sind nach dem Reset als normale Vorlagen da und lassen sich bearbeiten
  - beide löschen → leerer Zustand → „Standardvorlagen hinzufügen“ → zwei Vorlagen
  - „Neue Vorlage“: nur Name eingeben, speichern, Tabelle mit „Saldo“ sichtbar
  - Revisor: keine Knöpfe, auch im leeren Zustand
- Druck- und CSV-Seite einmal gegen den Container prüfen.

## 7. Auslieferung & Doku

- Minor-Version (geändertes Verhalten, neue Funktion).
- CHANGELOG (DE/EN), Hinweis: Die mitgelieferten Vorlagen erscheinen nach dem
  Update als eigene Vorlagen; gespeicherte Druck-/CSV-Links mit
  `template=builtin:…` funktionieren nicht mehr.
- `whatsNew.js` für Buchhalter/Verwalter.
- HANDBUCH (DE/EN) 7.4: Absätze „Mitgeliefert“ und „Eigene Vorlagen“ neu
  fassen; 12.1 (Reset) um „danach wieder die Standardvorlagen“ ergänzen.
- `l10n/en.json`.

## 8. Offene Entscheidungen

Keine. Bewusst so entschieden:

1. **Sperre statt Schemaänderung:** `ILockingProvider` reicht für N1; eine
   eigene Marker-Tabelle mit Unique-Index wäre robuster bei abgeschaltetem
   Locking, kostet aber eine Migration für einen Fall, der nur beim allerersten
   Öffnen eintreten kann.
2. **Klassenname `BuiltinReportTemplates` bleibt**, um Umbenennungen in Code
   und Tests zu sparen; die Bedeutung steht im Docblock.
