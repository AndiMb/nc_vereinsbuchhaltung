# Design: Belegvorschau neben der Belegauswahl (#231)

Stand: 31.10.2026 · Status: abgestimmt (Brainstorming), nicht umgesetzt

## 1. Problem

Wer einer Buchung einen Beleg aus dem Wächter-Ordner zuordnet und nicht
sicher ist, welche Rechnung passt, klickt heute auf den Dateinamen – die Datei
öffnet sich in einem neuen Browser-Tab (`AttachmentFolderPanel.vue`,
`preview()` → `window.open`). Zurück in der App ist nicht zu erkennen, welche
Datei zuletzt angesehen wurde (Issue #231), und Rechnung und Buchungsdaten
lassen sich nie nebeneinander vergleichen.

Ziel: Am Desktop steht die angeklickte Datei **direkt neben der Dateiliste**
und – im Buchungsdialog – zugleich neben dem Buchungsformular, damit sich
Datum, Betrag und Rechnungssteller ablesen und vergleichen lassen. Die
zuletzt angesehene Datei ist in der Liste markiert. Mobil bleibt es beim
neuen Tab.

## 2. Entscheidungen aus dem Brainstorming

| Frage | Entscheidung |
|---|---|
| Umfang | Belegauswahl im Buchungsdialog **und** `AttachmentFolderDialog` (Bankumsatz „Beleg vormerken…“, „Beleg für n Buchungen wählen“, Eingangskorb inkl. Dubletten) |
| Angehängte Belege bestehender Buchungen (Nextcloud-Viewer) | nicht im Umfang |
| Layout Buchungsdialog, breites Fenster | drei Spalten: Formular \| Liste \| Vorschau – alle gleichzeitig sichtbar, weil Rechnung und Buchung verglichen werden müssen |
| Layout bei schmalem Fenster | Rückfall: Vorschau ersetzt die Liste („← Liste“) |
| Formular zur Kurzinfo verkleinern | verworfen – dann ist kein Vergleich/keine Korrektur möglich |
| Kontext im `AttachmentFolderDialog` | Kontextstreifen mit den Daten des Bankumsatzes bzw. der gewählten Buchungen |
| Tastatur | ↑/↓ blättern durch die Liste und wechseln die Vorschau; **kein** Enter zum Übernehmen (Schutz vor versehentlichem Verknüpfen) |
| Technik | eingebauter Browser-Viewer per `<iframe>` (PDF) bzw. `<img>` (Bilder); kein pdf.js, keine Server-Thumbnails |
| Mobil | unverändert: neuer Tab |

## 3. Bausteine

### 3.1 Neu: `src/components/ReceiptPreview.vue`

Zeigt genau eine Datei. Weiß nichts von Listen, Buchungen oder Layout.

- Props: `url` (String), `name` (String), `mime` (String), `picked`
  (Boolean, optional – Kopfzeile zeigt dann „übernommen“).
- Kopfzeile: Dateiname, Knopf ↗ „Im neuen Tab öffnen“ (immer sichtbar),
  Ladeanzeige bis `load` des iframes/Bildes.
- Inhalt nach `previewKind(mime)`:
  - `pdf` → `<iframe :src="url">` ohne `sandbox` (Chromes PDF-Viewer läuft in
    einem sandboxed iframe nicht; die Datei-Antwort selbst sperrt Skripte per
    CSP, siehe 3.5), füllt die Spalte.
  - `image` → `<img>`, `max-width: 100%`, Höhe automatisch, Spalte scrollt.
    `@error` → Hinweis wie bei „keine Vorschau“.
  - `none` → Hinweis „Für diesen Dateityp ist keine Vorschau möglich.“ mit
    Knopf „Im neuen Tab öffnen“.
- Ohne Datei (`url` leer): Platzhalter „Datei anklicken für Vorschau“.
- Antwortet der Server mit 404 (Datei inzwischen weg), ist das beim iframe
  nicht als Ereignis erkennbar; die Datei-Antwort liefert dann die JSON-
  Fehlermeldung. Das wird hingenommen – beim nächsten Laden der Liste fehlt
  die Datei. Beim Bild greift `@error`.
- Wechselt `url` schnell hintereinander (↑/↓), wird `src` erst gesetzt, wenn
  250 ms kein neuer Wechsel kam – kein Download je Tastendruck.

Später austauschbar gegen eine pdf.js-Darstellung (z. B. für die
Belegerkennung), ohne dass sich die Rahmen ändern.

### 3.2 Neu: Hilfsfunktionen in `src/lib/receiptPreview.js`

Rein, ohne Vue, mit Vitest getestet:

- `previewKind(mime)` → `'pdf' | 'image' | 'none'`
  (`application/pdf` → pdf; `image/png|jpeg|gif|webp` → image; sonst none,
  auch `image/svg+xml` und `image/heic`, die Browser nicht sicher bzw. nicht
  überall darstellen).
- `adjacentFile(rows, currentId, dir)` → `fileId | null`: nächste (`dir = 1`)
  bzw. vorige (`-1`) **Dateizeile** in der sichtbaren Reihenfolge von `rows`
  (Format aus `lib/folderTree.js`); Ordnerzeilen werden übersprungen. An den
  Rändern bleibt es bei `currentId`. Ist `currentId` nicht (mehr) in `rows`,
  liefert `dir = 1` die erste, `dir = -1` die letzte Datei.

### 3.3 Neu: `src/composables/useWideLayout.js`

`useWideLayout(minWidth = 1500)` → reaktives `wide` (Boolean) per
`window.matchMedia('(min-width: 1500px)')`, mit Listener und Aufräumen beim
Aushängen. Entscheidet drei Spalten vs. Rückfall.

### 3.4 Geändert: `AttachmentFolderPanel.vue` und `ReceiptDuplicates.vue`

- Neue Props: `previewEnabled` (Boolean, Standard `false`),
  `previewFileId` (Number|null).
- Klick auf den Dateinamen: mit `previewEnabled` → `$emit('preview', file)`;
  ohne → wie bisher `window.open(...)` (mobil, und jeder Rahmen, der keine
  Vorschau anbietet).
- Die Zeile mit `fileId === previewFileId` bekommt die Klasse
  `vbh-folder-item--previewed` (Hintergrund `--color-primary-element-light`,
  Rand links in `--color-primary-element`) – die Markierung aus #231.
- Tastatur: Ist der Fokus in der Liste (Dateiname-Knopf) oder im Suchfeld
  und `previewEnabled` gesetzt, lösen ↑/↓ `adjacentFile()` aus und melden das
  Ergebnis per `preview`; der Fokus wandert auf den Dateinamen der neuen
  Zeile, die per `scrollIntoView({ block: 'nearest' })` sichtbar bleibt.
  Pfeiltasten in Eingabefeldern außer dem Suchfeld werden nicht abgefangen.
- `ReceiptDuplicates.vue` bekommt dieselben zwei Props und reicht `preview`
  über das Panel nach außen weiter (dort `rows` = die Kopien in Anzeige-
  reihenfolge).
- Das Panel gibt für ↑/↓ auch eine Methode `step(dir)` frei, damit der Rahmen
  im Rückfall-Layout (Liste ausgeblendet) weiterblättern kann.

### 3.5 Geändert: `BookingDialog.vue`

Zustand: `previewFile` (Dateiobjekt aus der Liste oder `null`),
`previewPicked` (Boolean). `wide` aus `useWideLayout()`.

- Panel bekommt `:previewEnabled="!isMobile"`, `:previewFileId`,
  `@preview="previewFile = $event; previewPicked = false"`.
- Breites Fenster (`wide`), Ordner offen, `previewFile` gesetzt:
  Klasse `is-split3`. Dialogbreite `min(1800px, 96vw)`, Grid
  `minmax(0,1fr) minmax(0,1fr) minmax(0,1.6fr)`, dritte Spalte
  `<aside class="vbh-booking-preview">` mit `ReceiptPreview`. Formular und
  Liste scrollen wie bisher je für sich und behalten ihren Scrollstand.
- Breites Fenster ohne `previewFile`: unverändert zweispaltig (1150 px).
- Schmales Fenster (`!wide`), Ordner offen, `previewFile` gesetzt: rechte
  Spalte zeigt statt der Liste die Vorschau mit Leiste
  „← Liste“ · „‹ ›“ (über `step(dir)` des Panels). Die Liste bleibt per
  `v-show` eingehängt (Suche, Auswahl und Scrollstand bleiben erhalten);
  „← Liste“ setzt nur die Ansicht zurück, die Datei bleibt markiert und wird
  in den sichtbaren Bereich gescrollt.
- Wechsel der Fensterbreite schaltet das Layout um, `previewFile` bleibt.
- Übernehmen per „+“ (`onFolderPick`): betrifft es `previewFile`, wird
  `previewPicked = true` – die Vorschau bleibt stehen.
- Schließen der Ordnerleiste („Aus Ordner wählen“) und Schließen des Dialogs
  setzen `previewFile = null`.
- Mobil (`isMobile`): keine Änderung.

### 3.6 Geändert: `AttachmentFolderDialog.vue` und `App.vue`

- Neue Prop `context` am Dialog:
  - `{ kind: 'tx', date, amount, purpose, counterparty }` bei
    `openFolderPickerForTx(tx)`,
  - `{ kind: 'bookings', items: [{ date, amount, description, entryNo }] }`
    bei `openFolderPickerForBookings(journalIds)` (`App.vue` sucht die
    Buchungen aus den geladenen Journalzeilen heraus),
  - `null` im Eingangskorb.
- Kontextstreifen über Liste und Vorschau: bei `tx` eine Zeile
  Datum · Betrag · Verwendungszweck · Gegenseite; bei `bookings` die ersten
  drei Buchungen (#Nr · Datum · Betrag · Text), darunter „+ n weitere“.
- Desktop: Dialog von Anfang an `min(1500px, 96vw)` breit, zweispaltig
  Liste \| Vorschau (1 : 1,4); die Vorschau zeigt bis zum ersten Klick den
  Platzhalter – die Breite springt hier nicht. Bei `!wide` dieselbe
  Rückfall-Logik wie im Buchungsdialog.
- Mobil: unverändert, `previewEnabled` false.

### 3.7 Backend

- `PageController::index()`: Antwort bekommt eine
  `ContentSecurityPolicy` mit `addAllowedFrameDomain("'self'")` – die
  App-Seite darf iframes vom eigenen Ursprung laden.
- `AttachmentController::inline()` (betrifft `inboxView` und `view`):
  `EmptyContentSecurityPolicy` zusätzlich mit
  `addAllowedFrameAncestorDomain("'self'")`. Alles andere bleibt
  (`default-src 'none'`, `nosniff`, `Content-Disposition: inline`) – Skripte
  in PDFs laufen weiterhin nicht.
- Keine Admin-Einstellung, keine Änderung an Nextcloud- oder Webserver-
  Konfiguration; kommt mit dem App-Update. Setzt ein Hoster per Proxy eine
  eigene, strengere CSP, blockiert der Browser das iframe – dann bleibt das
  immer sichtbare ↗ „Im neuen Tab öffnen“ (Hinweis im Handbuch).

## 4. Tests

- **Vitest** `src/lib/receiptPreview.test.js`: `previewKind` für PDF, gängige
  Bildtypen, SVG/HEIC, unbekannt/leer; `adjacentFile` vorwärts/rückwärts,
  Ordnerzeilen überspringen, Ränder, `currentId` nicht in `rows`, leere Liste.
- **PHPUnit**: CSP-Header von `inboxView`/`view` enthält
  `frame-ancestors 'self'` und weiterhin `default-src 'none'`;
  `PageController::index()` enthält `frame-src 'self'`.
- **Browser-Test** gegen die Docker-Instanz (`nextcloud-test`, Skill
  `browser-testing`), Chrome und Firefox:
  - PDF und Bild in der Vorschau, Datei ohne Vorschau (Hinweis);
  - Buchungsdialog bei 1920 px (drei Spalten) und 1366 px (Rückfall,
    „← Liste“, Markierung beim Zurückkehren);
  - ↑/↓ in der Liste, Übernehmen per „+“ mit stehenbleibender Vorschau;
  - Bankumsatz „Beleg vormerken…“ mit Kontextstreifen; Mehrfachauswahl im
    Journal mit „+ n weitere“; Dubletten-Ansicht mit Vorschau;
  - Mobilansicht unverändert (neuer Tab).
- ESLint, Stylelint, PHPStan, PHPUnit lokal vor dem Push.

## 5. Doku

- HANDBUCH (DE/EN): Abschnitt zur Belegvorschau in der Belegauswahl, Hinweis
  auf ↑/↓ und auf „Im neuen Tab öffnen“, falls die Vorschau leer bleibt.
- CHANGELOG (DE/EN), Verweis auf #231; Splash-Screen-Eintrag
  (`src/data/whatsNew.js`) beim Release.

## 6. Nicht im Umfang

- Vorschau angehängter Belege bestehender Buchungen (bleibt Nextcloud-Viewer).
- Eigene PDF-Darstellung (pdf.js), Hervorheben von Beträgen.
- Enter zum Übernehmen.
- Vorschau mobil.
