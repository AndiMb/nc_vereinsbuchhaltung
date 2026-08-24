---
name: video-produktion-pipeline
description: "Technische Pipeline für das KI-vertonte Werbevideo (Playwright-Aufnahme, TTS-Wortsync, ffmpeg-Montage) — reproduzierbar für Nachdrehs oder weitere Videos"
metadata: 
  node_type: memory
  type: project
  originSessionId: 256f4d1c-36c3-461e-b687-c483ee7887b3
  modified: 2026-08-20T14:29:48.216Z
---

Werbevideo "App zur Vereinsbuchhaltung für Nextcloud" (2:13, veröffentlicht auf
YouTube, verlinkt in README.md) wurde vollständig ohne Bildschirmaufnahme-Software
erzeugt: Playwright steuert einen Browser mit synthetischem Cursor, eine neuronale
TTS-Stimme liefert Wortzeiten, ffmpeg schneidet zusammen. Kein After Effects,
keine Cloud-Dienste, keine kostenpflichtige API.

**Warum diese Architektur:** Die Windows-Arbeits-VM hat keinen Bildschirm groß
genug für 1080p, kein Audiogerät zum Gegenhören, und Docker läuft dort nicht
(fehlendes SLAT/RVI in der Virtualisierung — nicht reparierbar im Gast). Deshalb
lief die Nextcloud-Sandbox auf einem separaten Debian-Host, angesteuert per
`docker context` über SSH.

## Umgebung (falls noch vorhanden)

- Debian-Host `172.26.121.215`, Nutzer `service`, passwortloses sudo.
- Docker-Kontext lokal: `docker context use vbh-debian` (Host `ssh://service@172.26.121.215`).
- Container `vbh-demo`: Nextcloud 31 + SQLite, App als Bind-Mount unter
  `~/vbh-video/apps/vereinsbuchhaltung` auf dem Debian-Host (App-Quellen dorthin
  per `tar | ssh` kopiert, nicht per git).
- Zugriff von der Windows-VM nur per SSH-Tunnel: `ssh -N -L 8080:127.0.0.1:8080 service@172.26.121.215`,
  danach `http://localhost:8080`, Login `admin` / `VbhDemo2026!`.
- Demodaten: erfundener Verein "TSV Waldbach e. V.", per `fetch()` aus einer
  eingeloggten Playwright-Seite gegen die App-API gesät (kein Session/CSRF-Handling
  nötig, da im Seitenkontext).

## Skripte (Stand: alle im session-gebundenen Scratchpad, siehe Warnung unten)

| Datei | Zweck |
|---|---|
| `seed-data.mjs` / `seed.mjs` | Erfundener Kontenrahmen, 3 Geschäftsjahre Buchungen, Finanzplan, Sphären, offene Posten |
| `prepare-recording.mjs` | Zuordnungsregeln anlegen, CSV-Kontoauszug vorab importieren (Dublettenprüfung sichtbar machen) |
| `vo-script.mjs` | Sprechtext, 7 Szenen |
| `tts.mjs` | `msedge-tts`, Stimme `de-DE-SeraphinaMultilingualNeural`, `-8%` Tempo, **`wordBoundaryEnabled: true`** → `vo/*.mp3` + `vo/*.words.json` + `timing.json` |
| `cue.mjs` | Liest `words.json`, liefert `at(phrase)` / `after(phrase)` — Zeitpunkt, zu dem ein Wort gesprochen wird |
| `harness.mjs` | Aufnahme-Kern: synthetischer Cursor, Tippsimulation, weiches Scrollen, Callout-Overlay, `zoomTo`/`zoomOut` (CSS-Transform, kein ffmpeg-Zoom), `clap()` (Sync-Marke), 1920×1080 bei `--force-device-scale-factor=1.25` |
| `scene1.html`/`outro.html` + `record-gfx.mjs` | Intro/Outro als HTML/CSS-Animation, ebenfalls per Playwright aufgezeichnet |
| `scene2–6.mjs` | App-Szenen, jede Bildaktion an `at()`/`after()` aus `cue.mjs` gebunden, nicht an geschätzte Sekunden |
| `detect-sync.mjs` | Sucht die Klappe (magenta, 4 Bilder) framegenau im Rohvideo, korrigiert `trimStart` in `sceneN.json` |
| `assemble.mjs --musik <mp3> --pegel 0.10` | Schneidet, überblendet (`xfade`), mischt Sprache + Musik (`sidechaincompress`-Ducking), `loudnorm=-16 LUFS`, H.264 CRF 18 |

## Nicht-offensichtliche Lehren

- **Ton zuerst, Bild danach.** Erst TTS mit Wortzeiten erzeugen, dann Aufnahmeskripte auf `at('wort')`/`after('wort')` bauen. Geschätzte Sekunden laufen bei langen Szenen um mehrere Sekunden auseinander.
- **Mehrdeutige Anker vermeiden.** `after('ist')` traf in "Soll und Ist gegenüber" statt im Zielsatz — Wortphrasen mit mehreren Vorkommen brauchen mehr Kontext oder `nth`.
- **ffmpeg `zoompan` ruckelt bei langsamen Fahrten** (rundet Ausschnitt/Ursprung auf Pixel). Lösung: Zoom als CSS-`transform` im Browser während der Aufnahme, Chromium interpoliert mit Subpixel-Genauigkeit.
- **Playwright hält Elemente mit laufendem CSS-Transition für "instabil"** und verzögert Klicks darauf — nach `zoomOut()` die Transition explizit beenden (`transition: none` nach Ablauf setzen).
- **Playwright-Aufzeichnung startet ~1 s nach Kontext-Erstellung**, schwankt von Lauf zu Lauf. Lösung: magentafarbene "Filmklappe" für 4 Bilder einblenden, danach per `detect-sync.mjs` im Rohvideo suchen statt `Date.now()`-Differenz zu vertrauen.
- **`.first()` vor Sichtbarkeitsfilter ist ein Bug-Magnet.** Verlassene Tabs bleiben per `v-show` im DOM (zwei `.vbh-chart-card`, drei `.vbh-filebtn`, 24 versteckte `<option>` mit gleichem Text). Immer `locator.filter({ visible: true })` **vor** `.first()`.
- **Nextclouds `DataDisplayResponse` hat standardmäßig `default-src 'none'`** — inline `<style>` in eigenen Controller-Antworten wird von der CSP verworfen. Nur beim Rendern in einem echten Browser sichtbar, nicht in Unit-Tests oder beim bloßen Lesen des HTML. Fix: `EmptyContentSecurityPolicy` mit `allowInlineStyle(true)` setzen (betraf Kassenbericht, Kurzbericht, Handbuch, Prüfleitfaden — in `v0.10.69` behoben).
- **msedge-tts-Metadaten sind ein einzelnes JSON-Objekt** (`{Metadata: [...]}`), keine zeilenweisen Datensätze — naiver Zeilen-Parser findet nichts.

**Wie fortsetzen:** Tunnel neu aufbauen, `node seed.mjs && node prepare-recording.mjs` für sauberen Datenstand, nur die betroffene `sceneN.mjs` neu aufnehmen (Reihenfolge beachten: Szene 4 verändert Daten, deshalb zuletzt), `detect-sync.mjs`, dann `assemble.mjs` neu.

**⚠️ Persistenz-Risiko:** Alle obigen Skripte liegen im session-gebundenen Scratchpad-Verzeichnis der Claude-Code-Sitzung, nicht im Repository. Dieses Verzeichnis kann zwischen Sitzungen bereinigt werden. Vor dem nächsten Aufräumen unbedingt in einen dauerhaften Ordner kopieren, falls die Pipeline weiter gebraucht wird.
