# Design: Belegordner aufräumen (Verschieben offener Belege, Dubletten)

Stand: 29.10.2026 · Status: abgestimmt (Brainstorming), nicht umgesetzt

## 1. Problem

Wer Belege über den Wächter-Ordner führt, sammelt bei mehreren hundert
Buchungen schnell tausende Dateien – besonders, wenn lange nichts zugeordnet
wurde. Zwei Fragen sind dann mühsam zu beantworten:

1. **Was ist doppelt** und könnte in Nextcloud gelöscht werden?
2. **Was ist noch offen** und muss mit der Geschäftsstelle geklärt werden?

Der Eingangskorb zeigt offene Dateien schon (Ordnerbaum, Suche, „nur offene“),
bietet aber keine Möglichkeit, sie gesammelt wegzusortieren, und erkennt keine
inhaltsgleichen Dateien.

## 2. Entscheidungen aus dem Brainstorming

| Frage | Entscheidung |
|---|---|
| Ziel | exakte Dubletten finden; offene Belege gesammelt verschieben |
| Ähnliche (nicht byte-identische) Belege | nicht im Umfang |
| Geschäftsstelle | hat eigenen App-Zugang (Buchhalter oder höher), klärt direkt im Eingangskorb |
| Markierungen/Notizen je Datei | entfallen – sortiert wird über Unterordner |
| Export (PDF/CSV) der offenen Belege | entfällt |
| Verschiebeziel | **nur innerhalb** des Wächter-Ordners |
| Löschen | weiterhin nie durch die App, nur in der Dateien-App |
| Ort in der Oberfläche | bestehender Eingangskorb, keine eigene Seite |
| Rechte | bestehendes Modell: Verschieben ab Buchhalter, Dubletten ansehen ab Revisor |

## 3. Teil A: Offene Belege verschieben

### 3.1 Oberfläche

Gilt für den Eingangskorb (`AttachmentFolderPanel` ohne `pick`, geöffnet über
„Ansehen“ in der Übersicht). Der Auswahlmodus „Aus Ordner wählen“ bleibt
unverändert.

- Kästchen erscheinen **nur an offenen Dateien** (`journalIds` leer).
  Zugeordnete Dateien sind nicht auswählbar.
- Kästchen im Kopf: wählt alle offenen Dateien, die zur aktuellen Suche
  passen – auch die in zugeklappten Ordnern. Ein Kästchen an jeder
  Ordnerzeile wählt die offenen Dateien dieses Ordners samt Unterordnern.
  Umschalt-Klick wählt einen Bereich sichtbarer Zeilen, wie in
  *Alle Buchungen*.
- Leiste unter der Liste, sobald etwas gewählt ist:
  „**n Dateien verschieben nach…**“ (nur mit Schreibrecht sichtbar).
- Der Knopf öffnet einen Dialog mit
  - dem Ordnerbaum des Wächter-Ordners (aus den Ordnerpfaden des Scans plus
    der Wurzel; leere Ordner fehlen dort, siehe §3.4),
  - einem Feld „Neuer Unterordner“ relativ zum gewählten Ordner, z. B.
    `_Klären`,
  - „Verschieben“ / „Abbrechen“.
- Danach: Meldung „n verschoben“ bzw. „n verschoben, m übersprungen“ mit
  Grund je Fall (siehe §3.2), Auswahl leeren, Eingangskorb neu laden.
- Mehr als 500 gewählte Dateien teilt die Oberfläche in Aufrufe zu je 500 und
  zeigt den Fortschritt.

### 3.2 Server

Neuer Endpunkt `POST /api/attachments/inbox/move`, Body
`{ fileIds: int[], target: string }`. Schreibrecht (Buchhalter+) über die
`PermissionMiddleware` wie jeder Nicht-GET-Aufruf. Neue Methode
`AttachmentWatchFolderService::move(string $userId, array $fileIds, string $target)`.

Ablauf:

1. Wächter-Modus aus oder Ordner fehlt → Fehler (400) mit Text wie in
   `fileInFolder()`.
2. `fileIds` leer oder mehr als 500 → 400.
3. `target` ist ein Pfad **relativ zum Wächter-Ordner** (`''` = Wurzel). Jedes
   Segment wird geprüft: nicht leer, nicht `.` oder `..`, nicht versteckt
   (`isHidden()`), unverändert durch `SafeFileName::of($segment, 255)`. Sonst 400.
   Fehlende Ordner werden angelegt.
4. Je Datei:
   - über `fileInFolder()` holen; liegt sie nicht (mehr) im Ordner oder ist
     kein Belegtyp → übersprungen, Grund `notFound`;
   - hängt sie inzwischen an einer Buchung (`AttachmentMapper`) →
     übersprungen, Grund `assigned`;
   - liegt sie schon im Zielordner → übersprungen, Grund `alreadyThere`;
   - sonst verschieben. Gibt es den Namen im Ziel schon, wird
     `Name (2).pdf`, `Name (3).pdf` … gewählt; überschrieben wird nie.
   - Scheitert das Verschieben (Sperre, Rechte) → übersprungen, Grund
     `failed`; die übrigen Dateien laufen weiter.
5. Ein Protokolleintrag (`AuditService::log('Belege verschoben', …)` im
   Controller, wie die übrigen Beleg-Aktionen) mit Ziel, Anzahl und den
   verschobenen Datei-IDs.
6. Antwort: `{ moved: int[], skipped: [{ fileId, reason }] }`.

Da die App Belege über die Datei-ID kennt, berührt das Verschieben keine
bestehende Verknüpfung.

### 3.3 Bewusst nicht enthalten

- Verschieben aus dem Wächter-Ordner hinaus.
- Verschieben zugeordneter Dateien.
- Rückgängig. Zurückschieben geht über dieselbe Aktion oder in der Dateien-App.

### 3.4 Anmerkung Ordnerbaum

Der Scan kennt nur Ordner, die Belege enthalten. Ein leerer Ordner wie
`_Klären/` erscheint daher erst nach dem ersten Verschieben im Baum; bis dahin
legt man ihn über „Neuer Unterordner“ an. Ein eigener Ordner-Scan ist nicht
nötig.

## 4. Teil B: Dubletten finden

### 4.1 Begriff

Eine **Dublette** sind zwei oder mehr Dateien mit **verschiedener Datei-ID**
im Wächter-Ordner und byte-identischem Inhalt. Dieselbe Datei an mehreren
Buchungen (z. B. aufgeteilte Sammelrechnung) ist keine Dublette.

### 4.2 Suche

Neue Methode `AttachmentWatchFolderService::duplicates(string $userId)`, neuer
Endpunkt `GET /api/attachments/inbox/duplicates` (Leserecht). Getrennt vom
normalen Eingangskorb, damit dieser nicht langsamer wird.

1. `inbox()` liefert die Dateien samt `journalIds` (ein Scan, wie bisher).
2. Nach `size` gruppieren; Gruppen mit nur einer Datei fallen weg. Dateien mit
   Größe 0 werden ignoriert.
3. Für die übrigen Dateien den SHA-256 holen:
   - aus der Tabelle `vbh_receipt_hashes`, wenn `size` und `mtime` passen,
   - sonst über `File::hash('sha256')` berechnen und speichern.
   - Ist eine Datei nicht lesbar, wird sie gezählt (`unreadable`) und
     übergangen.
4. Nach Hash gruppieren; Gruppen mit nur einer Datei fallen weg.
5. Einträge der Tabelle zu Datei-IDs, die nicht im Scan vorkommen, löschen –
   aber nur, wenn der Scan nicht gekappt ist.

Tabelle `vbh_receipt_hashes` (neue Migration):

| Spalte | Typ | |
|---|---|---|
| `file_id` | bigint | Primärschlüssel |
| `size` | bigint | |
| `mtime` | bigint | |
| `hash` | string(64) | SHA-256 hex |

Die Tabelle ist reiner Cache; geht sie verloren, wird neu gerechnet. Sie
gehört zur Instanz, nicht zu einem Nutzer (der Wächter-Ordner ist einer pro
Instanz). `UserDeletedListener` braucht keine Ergänzung.

### 4.3 Bewertung einer Gruppe

Reine statische Funktion `AttachmentWatchFolderService::classifyDuplicates(array $groups)`
ohne Dateizugriff. Je Kopie ein `verdict`:

| Lage | Kopien |
|---|---|
| mindestens eine Kopie zugeordnet | zugeordnete: `assigned`; offene: `removable` |
| keine Kopie zugeordnet | älteste (kleinste `mtime`, bei Gleichstand Name, dann Ordner): `keep`; Rest: `removable` |

Zusätzlich je Gruppe `doubleBooked: true`, wenn die zugeordneten Kopien an
mindestens zwei **verschiedenen** Buchungen hängen (Vereinigung der
`journalIds` über verschiedene Datei-IDs).

Antwort:

```json
{
  "folderMissing": false,
  "capped": false,
  "limit": 5000,
  "unreadable": 0,
  "groups": [
    {
      "hash": "…",
      "size": 250880,
      "doubleBooked": false,
      "files": [
        { "fileId": 1, "name": "…", "folder": "…", "mtime": 0, "mime": "…",
          "bookings": [{ "journalId": 7, "entryNo": 12, "date": "2025-03-01" }],
          "verdict": "assigned" }
      ]
    }
  ]
}
```

`bookings` wie in `withBookings()`. Gruppen nach Anzahl `removable`
absteigend, dann Größe absteigend.

### 4.4 Oberfläche

- Im Eingangskorb oben eine Umschaltung **Dateien | Dubletten** (nicht im
  Auswahlmodus „Aus Ordner wählen“).
- Beim ersten Wechsel zu „Dubletten“: Ladeanzeige „Dubletten werden
  gesucht…“, dann die Gruppen. Das Ergebnis gilt bis zum Schließen des
  Dialogs oder bis zu einem Verschieben.
- Je Gruppe ein Block: Kopf „n gleiche Dateien · Größe“, bei `doubleBooked`
  der Hinweis **„evtl. doppelt gebucht“** mit den Buchungsnummern.
  Darunter die Kopien mit Name, Ordner, Status-Kennzeichen
  (*zugeordnet #Nr* / *behalten* / *kann weg*), Vorschau wie im Dateibaum,
  Buchungsnummer öffnet die Buchung.
- `removable`-Kopien sind offen und damit auswählbar (§3.1). Knopf
  „alle ‚kann weg‘ auswählen“; danach dieselbe Leiste „n Dateien verschieben
  nach…“, z. B. nach `_Dubletten`. Gelöscht wird anschließend in der
  Dateien-App.
- Leeres Ergebnis: „Keine inhaltsgleichen Dateien gefunden.“
- Hinweise: `unreadable > 0` → „n Dateien konnten nicht geprüft werden.“;
  `capped` → „Nur unter den ersten 5000 Dateien gesucht.“

## 5. Fehlerbehandlung (Übersicht)

| Fall | Verhalten |
|---|---|
| Wächter-Modus aus | Umschaltung und Verschieben nicht sichtbar; Endpunkte antworten 400 |
| Ordner fehlt | wie heute im Eingangskorb (`folderMissing`) |
| Zielpfad ungültig | 400 mit Meldung, nichts verschoben |
| einzelne Datei scheitert | übersprungen mit Grund, Rest läuft weiter |
| Datei unlesbar beim Hashen | gezählt, nicht in Gruppen |
| Scan gekappt | Hinweis; keine Bereinigung der Hash-Tabelle |

## 6. Tests

- **PHPUnit**
  - `classifyDuplicates`: eine Kopie zugeordnet; keine zugeordnet (älteste
    behalten, Gleichstand); zwei Kopien an verschiedenen Buchungen
    (`doubleBooked`); dieselbe Datei an zwei Buchungen ohne zweite Kopie
    (keine Gruppe).
  - `duplicates`: Größenfilter (keine Hash-Berechnung für Einzelgrößen),
    Cache-Treffer bei gleicher `size`/`mtime`, Neuberechnung bei Änderung,
    Bereinigung nur ohne Kappung, unlesbare Datei.
  - `move`: Namenskonflikt → `(2)`; zugeordnete Datei übersprungen; schon im
    Ziel; Datei außerhalb des Ordners; ungültiger Zielpfad (`..`, versteckt,
    verbotene Zeichen); Ordner wird angelegt; Protokolleintrag.
- **E2E** (`tests/e2e/17-watchfolder.spec.mjs` erweitern): zwei
  inhaltsgleiche Dateien anlegen, eine zuordnen, Dubletten-Ansicht prüfen,
  „kann weg“ auswählen, nach `_Dubletten` verschieben, Eingangskorb zeigt die
  Datei dort.
- Lokal vor Push: PHPStan, PHPUnit, ESLint, Stylelint.

## 7. Dokumentation

- HANDBUCH.md / HANDBUCH.en.md: Abschnitt zum Eingangskorb um „Offene Belege
  verschieben“ und „Dubletten finden“ ergänzen.
- CHANGELOG.md / CHANGELOG.en.md, `whatsNew.js`.
- Übersetzungen in `l10n`.

## 8. Nicht im Umfang

- Ähnliche, nicht byte-identische Belege (anders gescannt/fotografiert).
- Markierungen oder Notizen je Datei.
- Export der offenen Belege als PDF/CSV.
- Löschen durch die App; Verschieben aus dem Wächter-Ordner hinaus.
- Hintergrundjob zum Vorab-Hashen (erst, wenn die Suche in der Praxis zu
  langsam ist).
