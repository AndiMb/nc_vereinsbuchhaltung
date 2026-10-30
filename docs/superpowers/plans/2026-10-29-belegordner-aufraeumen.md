# Belegordner aufräumen – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Offene Belege im Wächter-Ordner gesammelt in Unterordner verschieben und inhaltsgleiche Dateien (Dubletten) im Eingangskorb anzeigen.

**Architecture:** Zwei neue Methoden in `AttachmentWatchFolderService` (`move`, `duplicates`) plus eine reine Bewertungsfunktion; ein schmaler Hash-Cache (`ReceiptHashMapper`, Tabelle `vbh_receipt_hashes`). Zwei Endpunkte im `AttachmentController`. Im Frontend erweitert `AttachmentFolderPanel.vue` den Eingangskorb um Auswahl, Verschiebe-Dialog und eine Dubletten-Ansicht.

**Tech Stack:** PHP 8.2 / Nextcloud OCP (Files, IDBConnection), PHPUnit; Vue 3 + `@nextcloud/vue` (Einzelimporte), Vitest, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-29-belegordner-aufraeumen-design.md`

## Global Constraints

- Verschieben nur **innerhalb** des Wächter-Ordners; nie überschreiben, nie löschen.
- Nur offene Dateien (keine Buchung) werden verschoben; höchstens 500 Datei-IDs je Aufruf.
- Dubletten: verschiedene Datei-IDs, gleicher SHA-256; Größe 0 ignoriert; nur gleich große Dateien werden gehasht.
- Rechte: Verschieben = Schreiben (Buchhalter+), Dubletten = Lesen (Revisor+), über die bestehende `PermissionMiddleware`.
- `@nextcloud/vue` nur per Einzelimport; ESLint + Stylelint vor Commit.
- Oberflächentexte deutsch im Code, englische Übersetzung in `l10n/en.json`; Doku DE+EN.

## Review Focus

- Zielpfad mit `..`, führendem `/`, versteckten Segmenten oder verbotenen Zeichen → 400, nichts verschoben (Task 2 Test `testMoveLehntUngueltigesZielAb`).
- Datei wird zwischen Anzeige und Verschieben zugeordnet → übersprungen `assigned` (Task 2 Test `testMoveUeberspringtZugeordnete`).
- Namenskonflikt im Ziel, auch mehrfach → `Name (2).pdf`, `Name (3).pdf` (Task 2 Test `testMoveBenenntBeiKonfliktUm`).
- Gekappter Scan → Hash-Tabelle wird nicht bereinigt (Task 3 Test `testDuplicatesBereinigtNichtBeiKappung`).
- Dieselbe Datei an zwei Buchungen ohne zweite Kopie → keine Gruppe (Task 1 Test `testGleicheDateiAnZweiBuchungenIstKeineDublette`).

---

### Task 1: Bewertung der Dubletten-Gruppen (rein)

**Files:**
- Modify: `lib/Service/AttachmentWatchFolderService.php`
- Test: `tests/unit/AttachmentWatchFolderServiceTest.php`

**Interfaces:**
- Produces: `public static function classifyDuplicates(array $groups): array` – Eingabe: `list<list<file>>` (Dateien wie aus `inbox()['files']`, mit `fileId,name,folder,size,mtime,mime,journalIds`, je Gruppe gleicher Inhalt). Ausgabe: `list<array{size:int, doubleBooked:bool, files: list<file + verdict>}>`, `verdict` ∈ `assigned|keep|removable`; Gruppen mit < 2 verschiedenen fileIds fallen weg; Sortierung: Anzahl `removable` absteigend, dann `size` absteigend.

- [ ] Tests: eine Kopie zugeordnet (`assigned` + `removable`); keine zugeordnet (älteste `keep`, Gleichstand nach Name, dann Ordner); zwei Kopien an verschiedenen Buchungen → `doubleBooked`; gleiche Buchung an beiden Kopien → nicht `doubleBooked`; `testGleicheDateiAnZweiBuchungenIstKeineDublette`.
- [ ] Rot, implementieren, grün, commit.

### Task 2: Verschieben (Service + Endpunkt)

**Files:**
- Modify: `lib/Service/AttachmentWatchFolderService.php`, `lib/Controller/AttachmentController.php`, `appinfo/routes.php`
- Test: `tests/unit/AttachmentWatchFolderServiceTest.php`

**Interfaces:**
- Produces: `public const MAX_MOVE = 500;`
  `public function move(string $userId, array $fileIds, string $target): array{moved: list<int>, skipped: list<array{fileId:int, reason:string}>}` – wirft `\InvalidArgumentException` bei ungültigem Ziel/leerer oder zu großer Liste, `\RuntimeException` bei fehlendem Ordner. Gründe: `notFound|assigned|alreadyThere|failed`.
  `public static function normalizeTarget(string $target): ?string` – `''` für Wurzel, sonst bereinigter relativer Pfad oder `null` bei ungültig.
  Route `attachment#inboxMove` `POST /api/attachments/inbox/move`; Audit `'Belege verschoben'` mit `target`, `count`, `fileIds`.
- Konfliktname: `Basis (n).ext`, n ab 2, geprüft über `$targetFolder->nodeExists()`.

- [ ] Tests: `testMoveVerschiebtInUnterordnerUndLegtIhnAn`, `testMoveBenenntBeiKonfliktUm`, `testMoveUeberspringtZugeordnete`, `testMoveUeberspringtDateiImZiel`, `testMoveUeberspringtFremdeDatei`, `testMoveLehntUngueltigesZielAb` (Datenprovider: `../x`, `a/../b`, `.versteckt`, `a:b`, `/abs` wird zu `abs`? → nein: führende/abschließende `/` werden getrimmt, nur Segmente geprüft).
- [ ] Rot, implementieren, grün, PHPStan, commit.

### Task 3: Dubletten suchen (Hash-Cache + Endpunkt)

**Files:**
- Create: `lib/Db/ReceiptHashMapper.php`, `lib/Migration/Version000167Date20261029000000.php`
- Modify: `lib/Service/AttachmentWatchFolderService.php` (Konstruktor + `duplicates`), `lib/Controller/AttachmentController.php`, `appinfo/routes.php`, `appinfo/info.xml` (Version 0.46.0 bei Release, Migration greift nach Versionssprung)
- Test: `tests/unit/AttachmentWatchFolderServiceTest.php`

**Interfaces:**
- Produces: `ReceiptHashMapper` (IDBConnection, Tabelle `vbh_receipt_hashes`: `file_id` PK bigint, `size` bigint, `mtime` bigint, `hash` string 64):
  `findAll(): array<int, array{size:int, mtime:int, hash:string}>`, `save(int $fileId, int $size, int $mtime, string $hash): void` (delete+insert), `deleteExcept(list<int> $keepFileIds): void`.
  `public function duplicates(string $userId): array{folderMissing:bool, capped:bool, limit:int, unreadable:int, groups: list<…>}` – Gruppen wie Task 1, Controller ergänzt `bookings` über `withBookings()`.
  Route `attachment#inboxDuplicates` `GET /api/attachments/inbox/duplicates`.

- [ ] Tests: `testDuplicatesHashtNurGleichGrosse` (Einzelgröße: `hash()` nie gerufen), `testDuplicatesNutztCache` (passender Eintrag → kein `hash()`), `testDuplicatesRechnetBeiAenderungNeu`, `testDuplicatesZaehltUnlesbare`, `testDuplicatesBereinigtNichtBeiKappung`.
- [ ] Rot, implementieren, grün, PHPStan, commit.

### Task 4: Oberfläche

**Files:**
- Create: `src/components/ReceiptMoveDialog.vue`, `src/components/ReceiptDuplicates.vue`
- Modify: `src/components/AttachmentFolderPanel.vue`, `src/lib/folderTree.js` (+ `folderTree.test.js`), `src/api.js`, `src/styles.css`

**Interfaces:**
- `api.attachmentInboxMove(fileIds, target)`, `api.attachmentInboxDuplicates()`.
- `folderTree.js`: `export function openFilesIn(files, { search, folder })` → Datei-IDs offener Dateien passend zur Suche (optional unterhalb `folder`); `export function folderPaths(files)` → sortierte Liste aller Ordnerpfade inkl. `''` und aller Elternpfade.
- `ReceiptMoveDialog` Props `fileIds: number[]`, `folders: string[]`; emits `close`, `moved({ moved, skipped })`. Teilt in Pakete à 500.
- `ReceiptDuplicates` emits `open-booking`, `select(fileIds)`; Prop `selected: Set`, `canWrite`.
- Panel: Umschaltung „Dateien | Dubletten“ (nur `!pick`), Kästchen an offenen Dateien, Kopf- und Ordnerkästchen, Leiste „n Dateien verschieben nach…“ (nur `canWrite`).

- [ ] Vitest für `openFilesIn`/`folderPaths`, rot → grün.
- [ ] Komponenten bauen, `npx eslint src/`, `npm run stylelint`, `npm run build`, commit.

### Task 5: E2E, Doku, Übersetzungen

**Files:**
- Modify: `tests/e2e/21-belegordner.spec.mjs`, `HANDBUCH.md`, `HANDBUCH.en.md`, `CHANGELOG.md`, `CHANGELOG.en.md`, `l10n/en.json` (+ `l10n/en.js` falls vorhanden)

- [ ] E2E: zwei inhaltsgleiche Dateien per WebDAV, eine verknüpfen, Dubletten-Endpunkt und -Ansicht prüfen, „kann weg“ nach `_Dubletten` verschieben, Datei liegt danach dort.
- [ ] Handbuch (Eingangskorb-Abschnitt) und CHANGELOG „Unreleased“ DE+EN; Übersetzungen aller neuen Strings.
- [ ] PHPUnit, PHPStan, Vitest, ESLint, Stylelint, E2E gegen Container; commit.
