# Belegvorschau Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eingebettete Vorschau der Wächter-Ordner-Dateien neben der Belegauswahl (Buchungsdialog dreispaltig, `AttachmentFolderDialog` zweispaltig mit Kontextstreifen), Markierung der angesehenen Datei, ↑/↓-Blättern (#231).

**Architecture:** Eine dumme Anzeige-Komponente `ReceiptPreview.vue` (iframe/img/Hinweis), reine Hilfsfunktionen in `src/lib/receiptPreview.js`, das bestehende `AttachmentFolderPanel` meldet `preview`-Ereignisse statt `window.open`, die beiden Rahmen halten Zustand und Layout. Backend: zwei CSP-Lockerungen (`frame-src 'self'` auf der App-Seite, `frame-ancestors 'self'` auf den Datei-Antworten), gebündelt in einer kleinen Policy-Klasse.

**Tech Stack:** Vue 3 (Options API), `@nextcloud/vue` (Einzelimporte!), Vitest, PHP 8.1 / PHPUnit mit OCP-Stubs, Playwright-E2E gegen Docker.

**Spec:** `docs/superpowers/specs/2026-10-31-belegvorschau-design.md`

## Global Constraints

- `@nextcloud/vue` nur per Einzelimport (`@nextcloud/vue/components/NcButton`), nie Sammelimport (ADR 0001).
- Mobil (`isMobile`) bleibt unverändert: Klick auf Dateiname → neuer Tab.
- Schwelle breites Fenster: `min-width: 1500px`.
- Buchungsdialog dreispaltig: Breite `min(1800px, 96vw)`, Spalten `minmax(0,1fr) minmax(0,1fr) minmax(0,1.6fr)`.
- `AttachmentFolderDialog` Desktop: Breite `min(1500px, 96vw)`, Spalten Liste 1 : Vorschau 1,4.
- Debounce beim Wechsel der Vorschau-URL: 250 ms.
- Datei-Antworten behalten `default-src 'none'`, `nosniff`, `Content-Disposition: inline`.
- Kein Enter zum Übernehmen. iframe **ohne** `sandbox`.
- NcModal teleportiert aus `.vbh` heraus: CSS-Regeln klassenbasiert schreiben (wie die bestehenden `.vbh-booking-dialog.is-split`-Regeln).
- Alle sichtbaren Texte über `t()`; kein nacktes `%` in Übersetzungen.
- Vor jedem Commit mit Vue/CSS: `npx eslint src/` und `npm run stylelint`; mit PHP: PHPStan + PHPUnit aus `.phpstan/vendor/bin/`.

## Review Focus

1. Datei ohne/mit unbekanntem `mime` (z. B. Dubletten-Daten) → Vorschau entscheidet nach Dateiendung, nie leere weiße Fläche ohne Hinweis. Test: `previewKind('', 'scan.JPG')` in Task 2.
2. Vorschau-Datei verschwindet aus der Liste (übernommen per „+“, Suche/Filter blendet sie aus) → ↑/↓ springt trotzdem sinnvoll (erste/letzte sichtbare Datei), kein Fehler. Test: `adjacentFile` mit `currentId` nicht in `rows` in Task 2.
3. Fenster wird während offener Vorschau schmaler/breiter → Layout wechselt, gewählte Datei bleibt. Prüfung im Browser-Test Task 7.
4. Pfeiltasten in Formularfeldern (Betrag, NcSelect) des Buchungsdialogs dürfen **nicht** die Vorschau wechseln. Prüfung in Task 4 (Handler nur auf Liste/Suchfeld) und Browser-Test Task 7.
5. Dialog geschlossen und wieder geöffnet → keine alte Vorschau, Dialog in Normalbreite. Prüfung im Browser-Test Task 7.

---

### Task 1: Backend – CSP für eingebettete Vorschau

**Files:**
- Create: `lib/Http/ReceiptPreviewPolicy.php`
- Modify: `lib/Controller/AttachmentController.php` (`inline()`), `lib/Controller/PageController.php` (`index()`)
- Test: `tests/unit/ReceiptPreviewPolicyTest.php`

**Interfaces:**
- Produces: `ReceiptPreviewPolicy::forPage(): ContentSecurityPolicy` (Standard-CSP + `addAllowedFrameDomain("'self'")`), `ReceiptPreviewPolicy::forInlineFile(): EmptyContentSecurityPolicy` (leer + `addAllowedFrameAncestorDomain("'self'")`).

- [ ] **Step 1: Failing test** `ReceiptPreviewPolicyTest`:
  - `testInlineFileMayBeFramedBySelfOnly`: `buildPolicy()` enthält `"frame-ancestors 'self'"` und `"default-src 'none'"`, enthält nicht `script-src`.
  - `testPageAllowsSameOriginFrames`: `forPage()->buildPolicy()` enthält `"frame-src 'self'"` und weiterhin `"frame-ancestors 'self'"`.
- [ ] **Step 2:** `.phpstan/vendor/bin/phpunit tests/unit/ReceiptPreviewPolicyTest.php` → FAIL (Klasse fehlt).
- [ ] **Step 3:** Klasse anlegen (final, zwei statische Methoden, Docblock mit Begründung/Spec-Verweis). `AttachmentController::inline()` nutzt `ReceiptPreviewPolicy::forInlineFile()`; `PageController::index()` setzt `$response->setContentSecurityPolicy(ReceiptPreviewPolicy::forPage())`.
- [ ] **Step 4:** PHPUnit (Datei + gesamte Suite) grün, `.phpstan/vendor/bin/phpstan analyse` ohne neue Fehler, php-cs-fixer nur auf geänderte Dateien.
- [ ] **Step 5: Commit** `Belegvorschau: CSP erlaubt Einbetten vom eigenen Ursprung (#231)`

### Task 2: Hilfsfunktionen `previewKind`, `adjacentFile`, Kontext

**Files:**
- Create: `src/lib/receiptPreview.js`, `src/lib/receiptPreview.test.js`

**Interfaces:**
- Produces:
  - `previewKind(mime: string, name?: string): 'pdf'|'image'|'none'` – `application/pdf` → pdf; `image/png|jpeg|gif|webp` → image; ohne/`application/octet-stream`-MIME Rückfall auf Endung (`.pdf`, `.png`, `.jpg`, `.jpeg`, `.gif`, `.webp`, case-insensitive); SVG/HEIC/sonst → none.
  - `adjacentFile(rows: Array<{kind:'folder'|'file', file?:{fileId:number}}>, currentId: number|null, dir: 1|-1): number|null`.
  - `txContext(tx): {kind:'tx', date, amount, purpose, counterparty}` aus `{bookingDate, amount, purpose, counterparty}`.
  - `bookingsContext(journalRows, journalIds): {kind:'bookings', items:[{entryNo,date,amount,description}]}` – Reihenfolge wie `journalIds`, unbekannte IDs übergangen.

- [ ] **Step 1: Failing tests:**
  - `previewKind('application/pdf')==='pdf'`, `('image/jpeg')==='image'`, `('image/webp')==='image'`, `('image/svg+xml')==='none'`, `('image/heic')==='none'`, `('text/xml')==='none'`, `('', 'scan.JPG')==='image'`, `('application/octet-stream','R1.pdf')==='pdf'`, `(undefined, 'x.docx')==='none'`.
  - `adjacentFile`: rows `[folder, file1, folder, file2, file3]` → `(rows,1,1)===2`, `(rows,2,-1)===1`, `(rows,3,1)===3` (Rand), `(rows,1,-1)===1`, `(rows,99,1)===1`, `(rows,99,-1)===3`, `(rows,null,1)===1`, `([],null,1)===null`.
  - `txContext({bookingDate:'2026-09-12', amount:-49.95, purpose:'Rechnung 4711', counterparty:'Telekom'})` liefert die vier Felder mit `kind:'tx'`.
  - `bookingsContext` mit drei Zeilen und `journalIds [3, 99, 1]` → items für 3 und 1 in dieser Reihenfolge.
- [ ] **Step 2:** `npx vitest run src/lib/receiptPreview.test.js` → FAIL.
- [ ] **Step 3:** Implementieren.
- [ ] **Step 4:** Test grün, `npx eslint src/lib/receiptPreview*.js`.
- [ ] **Step 5: Commit** `Belegvorschau: Hilfsfunktionen für Dateityp, Blättern und Kontext`

### Task 3: `useWideLayout` und `ReceiptPreview.vue`

**Files:**
- Create: `src/composables/useWideLayout.js`, `src/components/ReceiptPreview.vue`
- Modify: `src/styles.css` (Block `.vbh-receipt-preview…`)

**Interfaces:**
- Consumes: `previewKind` (Task 2), `api.attachmentInboxViewUrl(fileId)`.
- Produces:
  - `useWideLayout(minWidth = 1500): { wide: Ref<boolean> }` – `matchMedia`, Listener in `onBeforeUnmount` entfernt; ohne `window.matchMedia` → `wide = true`.
  - `<ReceiptPreview :file="{fileId,name,mime}|null" :picked="bool" :showNav="bool" @back @step(dir) />` – URL aus `api.attachmentInboxViewUrl(file.fileId)`. Kopfzeile: Name, bei `picked` Plakette „übernommen“, Knopf ↗ (`window.open(url,'_blank')`, `aria-label` „Im neuen Tab öffnen“). Mit `showNav` (Rückfall-Layout) zusätzlich „← Liste“ (`@back`) und ‹ › (`@step(-1|1)`), dort auch ↑/↓ auf dem Komponentenwurzelelement (`tabindex="-1"`) → `@step`. Ohne Datei: Platzhalter „Datei anklicken für Vorschau“. Debounce 250 ms auf die angezeigte URL; Ladeanzeige (`NcLoadingIcon`) bis `@load`; `<img @error>` → Hinweis „Die Datei lässt sich hier nicht anzeigen.“ mit Knopf „Im neuen Tab öffnen“; `none` → „Für diesen Dateityp ist keine Vorschau möglich.“ + Knopf.
  - CSS: Wurzel `.vbh-receipt-preview` als Flex-Spalte, `min-height:0`, iframe `flex:1; width:100%; border:0`, Bildbereich `overflow:auto`, Bild `max-width:100%`.

- [ ] **Step 1:** Beide Dateien anlegen (Docblocks wie im Projekt üblich, Verweis auf Spec §3.1/§3.3).
- [ ] **Step 2:** `npx eslint src/` und `npm run stylelint` grün; `npm run build` baut.
- [ ] **Step 3: Commit** `Belegvorschau: Anzeige-Komponente und Breiten-Erkennung`

### Task 4: Panel und Dubletten melden `preview`, markieren, ↑/↓

**Files:**
- Modify: `src/components/AttachmentFolderPanel.vue`, `src/components/ReceiptDuplicates.vue`, `src/styles.css`

**Interfaces:**
- Consumes: `adjacentFile` (Task 2).
- Produces (Panel): Props `previewEnabled: Boolean = false`, `previewFileId: Number|null = null`; Ereignis `preview(file)`; Methode `step(dir: 1|-1)` (öffentlich über `ref`). Gleiche zwei Props an `ReceiptDuplicates`, dessen `preview` vom Panel weitergemeldet wird.

- [ ] **Step 1:** Panel: `preview(f)` → mit `previewEnabled` `$emit('preview', f)`, sonst bisheriges `window.open`. Zeilenklasse `vbh-folder-item--previewed` bei `row.file.fileId === previewFileId`. `step(dir)`: Liste der aktuell sichtbaren Dateizeilen – in der Ansicht `files` `this.rows`, in `duplicates` die Dubletten-Dateien in Anzeigereihenfolge (Methode `previewRows()` an `ReceiptDuplicates`, liefert `[{kind:'file', file}]`) – `adjacentFile(...)`, Datei per `preview` melden, danach im nächsten Tick den Namensknopf der Zeile (`[data-file-id="…"] .vbh-attachment-name`) fokussieren und `scrollIntoView({block:'nearest'})`.
- [ ] **Step 2:** Tastatur: `@keydown.up.prevent` / `@keydown.down.prevent` am `<ul class="vbh-folder-list">` (Dubletten: an deren Liste) und am Suchfeld, nur wenn `previewEnabled` – ruft `step(-1|1)`. Keine globalen Listener. `data-file-id` an Dateizeilen.
- [ ] **Step 3:** CSS `.vbh-folder-item--previewed { background: var(--color-primary-element-light); box-shadow: inset 3px 0 0 var(--color-primary-element); }`.
- [ ] **Step 4:** `npx eslint src/`, `npm run stylelint`, `npx vitest run` grün.
- [ ] **Step 5: Commit** `Belegvorschau: Belegliste meldet Vorschau, markiert die angesehene Datei, Pfeiltasten`

### Task 5: Buchungsdialog dreispaltig mit Rückfall

**Files:**
- Modify: `src/components/BookingDialog.vue`, `src/styles.css` (bei den `.is-split`-Regeln um Zeile 1050–1091)

**Interfaces:**
- Consumes: `useWideLayout` (Task 3), `ReceiptPreview` (Task 3), Panel-Props/`step` (Task 4).

- [ ] **Step 1:** `setup()` ergänzt `wide` aus `useWideLayout()`. `data`: `previewFile: null`, `previewPicked: false`, `previewOverList: false`. `show`-Watcher und `toggleFolderPanel()` beim Zuklappen setzen alle drei zurück.
- [ ] **Step 2:** Panel bekommt `ref="folderPanel"`, `:previewEnabled="!isMobile"`, `:previewFileId="previewFile?.fileId ?? null"`, `@preview="onPreview"`. `onPreview(file)`: `previewFile = file; previewPicked = false; previewOverList = !wide`. `onFolderPick(files)`: wenn `files` die `previewFile` enthält → `previewPicked = true`.
- [ ] **Step 3:** Layout: `isSplit3 = folderPanelOpen && !isMobile && wide && previewFile`. Bei `isSplit3` Klasse `is-split3` und dritte Spalte `<aside class="vbh-booking-preview"><ReceiptPreview :file="previewFile" :picked="previewPicked" /></aside>`. Bei `!wide && previewFile && previewOverList` in der Ordner-Spalte: Panel per `v-show` versteckt, darüber `ReceiptPreview` mit `showNav`, `@back="backToList"`, `@step="$refs.folderPanel.step($event)"`. `backToList()`: `previewOverList = false`, im nächsten Tick die markierte Zeile `scrollIntoView({block:'nearest'})`. Watch `wide`: wird es `false` mit `previewFile` → `previewOverList = true`.
- [ ] **Step 4:** CSS: `.modal-wrapper .modal-container:has(.vbh-booking-dialog.is-split3) { width: min(1800px, 96vw); }` und `.vbh-booking-dialog.is-split3 .vbh-booking-layout { grid-template-columns: minmax(0,1fr) minmax(0,1fr) minmax(0,1.6fr); }`, `.vbh-booking-preview` wie `.vbh-booking-folder` (Rand links, `min-height:0`, Flex-Spalte). Titel der Ordner-Spalte bekommt das Platz-für-X-Padding nur, wenn sie die letzte Spalte ist – bei `is-split3` trägt die Vorschau-Kopfzeile das Padding.
- [ ] **Step 5:** `npx eslint src/`, `npm run stylelint`, `npm run build`.
- [ ] **Step 6: Commit** `Belegvorschau im Buchungsdialog: drei Spalten am breiten Desktop, sonst anstelle der Liste (#231)`

### Task 6: `AttachmentFolderDialog` mit Vorschau und Kontextstreifen

**Files:**
- Modify: `src/components/AttachmentFolderDialog.vue`, `src/App.vue` (`openFolderPickerForTx`, `openFolderPickerForBookings`, `openInbox`, Dialog-Props), `src/styles.css`

**Interfaces:**
- Consumes: `txContext`, `bookingsContext` (Task 2), `useWideLayout`, `ReceiptPreview` (Task 3), Panel-Props (Task 4).
- Produces: Prop `context: Object|null` am Dialog.

- [ ] **Step 1:** `App.vue`: `folderDialog` bekommt Feld `context`; `openFolderPickerForTx(tx)` → `txContext(tx)`, `openFolderPickerForBookings(journalIds)` → `bookingsContext(this.journalRows, journalIds)`, `openInbox()` → `null`. Weiterreichen als `:context="folderDialog.context"`.
- [ ] **Step 2:** Dialog: `size` am Desktop `'large'`, Klasse `vbh-folder-dialog--preview` am Inneren (nur `!isMobile`). Kontextstreifen `.vbh-folder-context` über dem Raster: `tx` → eine Zeile `formatDate(date) · formatMoney(amount) · purpose · counterparty` (leere Teile weglassen); `bookings` → bis drei Zeilen `#entryNo · Datum · Betrag · Text`, darunter `t('+ {n} weitere', {n})` wenn mehr. Raster `.vbh-folder-dialog-grid` Liste | `ReceiptPreview`, im Rückfall (`!wide`) dieselbe Logik wie Task 5 Step 3 (Vorschau ersetzt Liste mit `showNav`). Zustand `previewFile`, `previewOverList`; Panel mit `ref`, `previewEnabled = !isMobile`, `previewFileId`, `@preview`.
- [ ] **Step 3:** CSS: `.modal-wrapper .modal-container:has(.vbh-folder-dialog--preview) { width: min(1500px, 96vw); height: min(90vh, …wie is-split…); overflow:hidden }`, Inneres Flex-Spalte mit `height:100%`, Raster `grid-template-columns: minmax(0,1fr) minmax(0,1.4fr)`, Liste scrollt in sich (wie `.is-split .vbh-folder-list`). Kontextstreifen: `background: var(--color-background-dark); border-radius: var(--border-radius-large); padding: 6px 10px`.
- [ ] **Step 4:** `npx eslint src/`, `npm run stylelint`, `npx vitest run`, `npm run build`.
- [ ] **Step 5: Commit** `Belegvorschau im Ordner-Dialog mit Kontext des Umsatzes bzw. der Buchungen (#231)`

### Task 7: Verifikation im Browser, E2E, Doku

**Files:**
- Create/Modify: `tests/e2e/21-belegordner.spec.mjs` (neuer `test.describe('Belegvorschau')`) – falls die dortigen Fixtures keinen Wächter-Ordner anlegen, `17-watchfolder.spec.mjs`
- Modify: `HANDBUCH.md` + englische Fassung, `CHANGELOG.md` + englische Fassung (Abschnitt „Unveröffentlicht“)

- [ ] **Step 1:** Docker-Instanz starten, Version bumpen bzw. `occ upgrade` laut Memory-Verfahren, Build deployen.
- [ ] **Step 2:** Browser-Test (Skill `browser-testing`) Chrome bei 1920 px und 1366 px: PDF und Bild in der Vorschau sichtbar (iframe lädt, keine CSP-Konsolenfehler), Markierung, ↑/↓ in Liste, Pfeiltasten im Betragsfeld wechseln nichts, „+“ lässt Vorschau stehen („übernommen“), Fenster verkleinern → Rückfall, „← Liste“, Dialog schließen/öffnen → Normalbreite. Bankumsatz „Beleg vormerken…“ mit Kontextstreifen; Mehrfachauswahl im Journal; Dubletten-Ansicht. Mobil (Viewport 400 px): neuer Tab wie bisher. Firefox mindestens PDF-Vorschau.
- [ ] **Step 3:** E2E-Test: Buchungsdialog öffnen, „Aus Ordner wählen“, Dateiname klicken → `.vbh-receipt-preview iframe` bzw. `img` sichtbar, Zeile hat `vbh-folder-item--previewed`, `ArrowDown` → andere Zeile markiert. `npx playwright test tests/e2e/21-belegordner.spec.mjs` grün.
- [ ] **Step 4:** HANDBUCH DE/EN: Abschnitt „Belegvorschau“ beim Kapitel Belegauswahl (Klick zeigt Vorschau, ↑/↓, ↗ falls leer – z. B. wenn ein Hoster das Einbetten sperrt). CHANGELOG DE/EN mit Verweis auf #231.
- [ ] **Step 5:** Gesamtlauf: `npx eslint src/`, `npm run stylelint`, `npx vitest run`, PHPUnit, PHPStan.
- [ ] **Step 6: Commit** `Belegvorschau: E2E-Test, Handbuch und Changelog (#231)`
