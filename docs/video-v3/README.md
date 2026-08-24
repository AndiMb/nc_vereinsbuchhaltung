# Videopipeline v3 — Bedienung

Konzept und Szenenplan: [KONZEPT.md](KONZEPT.md). Diese Datei beschreibt, was
gebaut ist und wie man es startet.

**Stand: beide Videos sind fertig.** Ein Sprachlauf dauert rund sechs Minuten
und erzeugt alles, was zur Veröffentlichung gehört – außer dem Hochladen.

```bash
node lib/build.mjs --lang de      # Instanz, Daten, Aufnahme, Montage, Untertitel, Thumbnail
node lib/build.mjs --lang en
```

| | Deutsch | Englisch |
|---|---|---|
| Video | `build/de/vereinsbuchhaltung-de.mp4` · **3:26,7** | `build/en/vereinsbuchhaltung-en.mp4` · **3:02,4** |
| Bild/Ton | 1920×1080, 30 fps, H.264 · AAC Stereo, −14,3 LUFS | dieselben Werte, −15,2 LUFS |
| Untertitel | `…-de.srt` / `.vtt`, 45 Einblendungen | `…-en.srt` / `.vtt`, 49 Einblendungen |
| Thumbnail | `thumbnail-de.png` (1280×720) | `thumbnail-en.png` |
| Titel, Beschreibung, Kapitelmarken | `veroeffentlichung-de.md` | `veroeffentlichung-en.md` |

Die Lautheit weicht in der englischen Fassung um gut eine Einheit vom Ziel ab:
`loudnorm` misst in einem Durchgang und schätzt dabei. YouTube normalisiert
ohnehin selbst auf −14 LUFS; für einen genaueren Wert wäre ein Zwei-Pass-Lauf
nötig.

## Was schon läuft

| Baustein | Datei | Zweck |
|---|---|---|
| Datensatzbeschreibung | `content/seed.de.json`, `content/seed.en.json` | Alles Sprachliche am Datenbestand: Vereinsname, Kontenrahmen, Regeln, Planwerte, Namenslisten, Kontoauszugszeilen |
| Datensatzrechner | `lib/dataset.mjs` | Macht daraus Buchungen dreier Jahre, 75 Mitglieder mit gültigen Test-IBANs und die CSV-Dateien — deterministisch, ohne Netz |
| Instanzumstellung | `lib/instance.mjs` | Nextcloud-Sprache und Anzeigenamen der Demokonten (per `occ`) |
| Datenaufbau | `lib/seed.mjs` | Spielt den Bestand über die App-API in die laufende Nextcloud |
| Sprechtext und Bildschirmtexte | `content/de.json`, `content/en.json` | Sprechtext je Szene, Titelkarten, Lower Thirds, Callouts samt Wortankern |
| Vertonung | `lib/tts.py` | edge-tts → MP3 **plus Wortzeiten** je Szene und Hörproben mehrerer Stimmen |
| Zeitpunkte | `lib/cue.mjs` | `at('Wachordner')` / `after(…)` aus den Wortzeiten; prüft Anker auf Eindeutigkeit |
| Aufnahme-Kern | `lib/harness.mjs` | Cursor, Tippen, Scrollen, Zoom, Lower Thirds, Callouts, Titelkarten, Rahmen für Druckseite und Handy, Bildschirmaufnahme |
| Szenen | `scenes/00-intro.mjs` … `scenes/08-outro.mjs` | je Szene ein Skript; alles Sprachliche in einer Tabelle am Kopf |
| Aufnahme-Läufer | `lib/record.mjs` | Browser, Szenenreihenfolge, Bilder → Clip mit Sprachspur |
| Montage | `lib/assemble.mjs` | Kreuzblenden, Musikbett mit Ducking, Lautheit |
| Untertitel | `lib/subtitles.mjs` | SRT und VTT aus den Wortzeiten, mit Satzzeichen aus dem Sprechtext |
| Thumbnail und Texte | `lib/thumbnail.mjs` | Vorschaubild plus Titel, Beschreibung und Kapitelmarken |
| Sprachlauf | `lib/build.mjs` | ruft alle Schritte der Reihe nach auf |
| Browser | `lib/chrome.mjs` | headless Chromium aus dem Playwright-Cache, je Sprache eines |
| Sitzung | `lib/session.mjs` | Anmeldung und API-Aufrufe aus der Seite heraus |
| CDP-Client | `lib/cdp.cjs` | Kopie aus dem `browser-testing`-Skill, um Ereignis-Handler erweitert |

## Aufnahme-Instanz

Eigener Container, damit der Entwicklungsstand in `nextcloud-test` unberührt
bleibt — die App hat **einen gemeinsamen Datenbestand je Instanz**, ein Seed
würde dort alles überschreiben.

```bash
docker run -d --name vbh-demo -p 8081:80 \
  -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD='VbhDemo2026!' \
  -e SQLITE_DATABASE=nextcloud -e NEXTCLOUD_TRUSTED_DOMAINS='localhost 127.0.0.1' \
  -v vbh-demo-html:/var/www/html \
  -v 'C:\Temp2\Claude\Nextcloud-Vereinsbuchhaltungsapp\vereinsbuchhaltung:/var/www/html/custom_apps/vereinsbuchhaltung' \
  nextcloud:34.0.0

docker exec -u www-data vbh-demo php occ app:enable vereinsbuchhaltung
docker exec -u www-data vbh-demo php occ app:disable firstrunwizard
```

Demokonten (Passwort für alle: `VbhDemo2026!`):

| Konto | Anzeigename (de/en) | Rolle in der App |
|---|---|---|
| `andrea` | Andrea Falk | Verwalterin (Gruppe `admin`) — das ist der Aufnahme-Nutzer |
| `jens` | Jens Wieland / James Wieland | Buchhalter |
| `karla` | Karla Riedel / Carla Reed | Revisorin (Kassenprüfung, Szene 06) |

Angelegt mit
`docker exec -u www-data -e OC_PASS='VbhDemo2026!' vbh-demo php occ user:add --password-from-env --display-name "<Name>" [--group admin] <uid>`.
Der Anzeigename wird später je Sprachlauf von `instance.mjs` überschrieben
(`occ user:setting <uid> settings displayname` — `user:modify` gibt es in
Nextcloud 34 nicht mehr).

## Ein Sprachlauf

```bash
cd docs/video-v3
node lib/instance.mjs --lang de          # Nextcloud-Sprache + Anzeigenamen
node lib/seed.mjs     --lang de          # Datenbestand (Standard-URL: http://localhost:8081)

node lib/instance.mjs --lang en
node lib/seed.mjs     --lang en
```

Nützliche Schalter: `--url`, `--user`, `--pass`, `--port` (CDP, Standard 9444),
`--today YYYY-MM-DD` (fester Stichtag statt heute), `--keep-tab`.
`node lib/dataset.mjs --lang de --dump` rechnet den Bestand nur durch und zeigt
die Eckwerte, ohne eine Nextcloud zu brauchen.

`seed.mjs` startet bei Bedarf selbst ein headless Chromium aus dem
`ms-playwright`-Cache und meldet sich an; die App-Endpunkte hängen an einer
Nextcloud-Sitzung samt CSRF-Token, deshalb läuft alles aus der Seite heraus.

## Was der Seed erzeugt (Stand 23.08.2026, Stichtag = heute)

```
23 Konten · 161 Buchungen (3 Geschäftsjahre) · 4 Kostenstellen · 6 Regeln
12 Planwerte · 75 Mitglieder, davon 68 mit SEPA-Mandat
7 offene Posten (6 fällige Beiträge + 1 Rechnung) · 0 offene Bankumsätze
```

Dazu in `build/<lang>/`:

| Datei | Verwendung |
|---|---|
| `kontoauszug.csv` | Szene 03: 17 Umsätze, davon 3 bereits gebucht → Import meldet „14 neu, 3 Dubletten" |
| `kontoauszug-vormonat.csv` | wurde vom Seed bereits importiert; liegt nur zur Nachvollziehbarkeit dort |
| `mitglieder-neu.csv` | Szene 04: 12 Neuzugänge, zehn davon ohne Betrag → greift auf den Standardbeitrag zu |
| `seed-dashboard.png` | Kontrollbild nach dem Lauf |

Geprüft nach dem letzten Lauf: Import-Vorschau meldet 14 neu / 3 Dubletten,
Mitglieder-Vorschau 12 ok / 0 Fehler / 12 Mandate / 12 Beiträge, der
SEPA-Einzug zeigt 6 fällige Beiträge über 390 €.

## Vertonung (Phase 2)

```bash
python lib/tts.py --lang de                 # alle Szenen vertonen
python lib/tts.py --lang de --scene 03-bank # nur eine Szene neu
python lib/tts.py --samples de              # Hörproben mehrerer Stimmen
node   lib/cue.mjs --lang de                # Anker prüfen und Zeiten zeigen
```

Ergebnis in `build/<lang>/vo/`: je Szene eine MP3, eine `*.words.json` mit
Start- und Endzeit **jedes gesprochenen Wortes** und eine `timing.json` mit den
Szenenlängen. Die Wortzeiten sind der Grund für den ganzen Aufwand: die
Szenenskripte in Phase 3 binden ihre Bildaktionen daran (`cue.at('Wachordner')`)
statt an geschätzte Sekunden.

Gemessene Längen (ohne Blenden):

| | Deutsch | Englisch |
|---|---|---|
| Stimme | `de-DE-SeraphinaMultilingualNeural` | `en-US-AvaMultilingualNeural` |
| Tempo | −8 % | −8 % |
| Gesamt | **3:17** | **2:53** |
| Längste Szene | 04 Beiträge, 36,4 s | 04 Beiträge, 29,8 s |

Die englische Fassung ist rund 24 Sekunden kürzer — englischer Text ist
kompakter, und Ava spricht etwas zügiger. Das stört nicht, weil jede Sprache
ihre eigene Zeitachse hat; wer sie angleichen will, setzt in `content/en.json`
`"rate": "-12%"`.

**Fallstrick, der Zeit gekostet hat:** `edge-tts` liefert seit Version 7 per
Vorgabe nur noch **einen `SentenceBoundary` je Satz**. Ohne
`boundary="WordBoundary"` im `Communicate`-Aufruf gibt es keine Wortzeiten –
und die halbe Pipeline hängt daran.

**Anker müssen eindeutig sein.** `cue.mjs` wirft, wenn eine Phrase im Sprechtext
mehrfach oder gar nicht vorkommt, und nennt die Fundstellen. In der
Vorgängerproduktion traf `after('ist')` im Satz „Soll und Ist gegenüber" statt
an der gemeinten Stelle – still, ohne Fehler.

## Aufnahme und Montage (Phase 3 bis 6)

```bash
node lib/record.mjs   --lang de                    # alle neun Szenen
node lib/record.mjs   --lang de --scene 04-beitraege --keep-frames
node lib/assemble.mjs --lang de [--no-music] [--music pfad.mp3]
node lib/subtitles.mjs --lang de
node lib/thumbnail.mjs --lang de
node lib/verify.mjs    --lang de     # Endfassung gegen die Clips prüfen
```

**Wie Bild und Ton zusammenfinden:** `startRecording()` setzt die Szenenuhr auf
0, die Sprachspur beginnt bei 0,55 s (Vorlauf), und jede Bildaktion hängt an
einer Wortzeit: `await ctx.until(ctx.cueAt('Wachordner'))`. Die Bilder kommen
über `Page.startScreencast` mit Zeitstempel; daraus baut der concat-Demuxer eine
Spur mit konstanten 30 fps. Eine Filmklappe wie in der Vorgängerproduktion
braucht es damit nicht.

**Alles Sichtbare außerhalb der App** – Cursor, Klickring, Lower Third,
Callouts, Titelkarten, Handyrahmen – ist ein Overlay im DOM, per Inline-Style
gesetzt. Bedient wird über echte `.click()`-Aufrufe im Seitenkontext, nicht über
Koordinaten.

### Fallstricke, die Zeit gekostet haben

Sie stehen alle im Code kommentiert, hier als Liste zum Nachschlagen:

- **`edge-tts` liefert seit Version 7 nur noch `SentenceBoundary`.** Ohne
  `boundary="WordBoundary"` gibt es keine Wortzeiten – und daran hängt die halbe
  Pipeline.
- **Der Screencast nimmt das Fenster auf, nicht den emulierten Viewport.**
  `Emulation.setDeviceMetricsOverride` macht die *Seite* 1536×864 groß, die
  Bilder kommen aber weiter in Fenstergröße (1520×713) – der untere Teil der
  Seite fehlt darin schlicht, und ffmpeg zieht den Rest wieder auf 16:9 hoch.
  Im fertigen Video war das erst „unten abgeschnitten", nach der ersten
  Korrektur „vertikal gestreckt" (Faktor 1,21). Die Lösung ist, gar nicht zu
  emulieren: `fitViewport()` korrigiert die Fensterbreite und -höhe so lange
  (`Browser.setWindowBounds`), bis `innerWidth/innerHeight` wirklich 1920×1080
  sind. Alle Bildschirmtexte sind dafür um Faktor 1,25 gewachsen.
- **JPEG-Einzelbilder bringen eine Pixeldichte mit**, aus der ffmpeg ein
  Seitenverhältnis von 855:713 ableitet. Ohne `setsar=1` zeigt jeder Player
  2,13:1 statt 16:9 – schwarze Balken oben und unten. Steht deshalb sowohl im
  Clipbau als auch in der Montage, und `verify.mjs` prüft es am Ende.
- **`font: 700 84px/1.1 inherit` ist ungültiges CSS.** Die Kurzschreibweise
  verträgt kein `inherit` als Familie; die ganze Regel fällt weg, und die
  Titelkarte rendert in Fließtextgröße. Einzelwerte setzen.
- **`transform-origin` zählt im Dokument-, nicht im Fensterkoordinatensystem.**
  Ohne Scroll-Versatz zoomt die Seite an einer völlig anderen Stelle.
- **Verlassene Dialoge bleiben im DOM** (`v-show`). Wartebedingungen müssen auf
  *sichtbare* Elemente prüfen (`__vbhVisible`), sonst wird
  `querySelector('.vbh-modal-title') === null` nie wahr.
- **Der Import-Dialog bleibt nach dem Übernehmen mit seinem Ergebnis stehen** –
  er will geschlossen werden. Und sein Schließen-X muss im *sichtbaren* Dialog
  gesucht werden, sonst trifft man das unsichtbare eines anderen.
- **Szene 02 endet absichtlich mit offenem Dialog.** Deshalb räumt der Läufer
  vor jeder Szene auf (`closeAllModals`), statt sich auf die Vorgängerszene zu
  verlassen.
- **`DOM.setFileInputFiles` meldet Erfolg und tut nichts** – jedenfalls beim
  Datei-Feld der Mitgliederliste (gleicher Knoten, noch im DOM, `files.length`
  bleibt 0). Der Weg über `DataTransfer` in der Seite funktioniert; die
  Erfolgsprüfung muss **vor** dem `change`-Ereignis laufen, weil der Handler das
  Feld danach leert.
- **`input[type=text]` trifft ein Feld ohne `type`-Attribut nicht**, obwohl
  `input.type` „text" liefert. Der Buchungstext ist so ein Feld.
- **Chromes eigene Bedienelemente folgen der Browsersprache**, nicht der Seite:
  ohne `--lang=en-US` steht im englischen Video ein deutsches „Datei auswählen".
  Deshalb je Sprache ein eigener Browser auf eigenem Port – und das
  Thumbnail-Skript braucht einen dritten, sonst erbt es den falschen.
- **`record.mjs` darf beim Import nichts tun.** Ein `import { HEAD }` aus dem
  Untertitel-Skript hat sonst eine komplette Aufnahme gestartet.
- **Die Druckseiten senden `frame-ancestors 'none'`** und lassen sich nicht
  einbetten. Szene 06 holt den Kassenbericht deshalb per `fetch` und setzt ihn
  als `srcdoc` – dieselbe Optik, ohne zweiten Tab.
- **`-stream_loop -1` gilt für das *nächste* `-i`.** In der Eingabeliste vorn
  eingefügt, wiederholte es den ersten Clip endlos: im fertigen Video lief die
  Intro-Sprachspur immer wieder, während Bild, Laufzeit und die Clips selbst in
  Ordnung waren. Beim Durchsehen einzelner Bilder fällt so etwas nicht auf –
  deshalb prüft `lib/verify.mjs` seither die Tonspur der Endfassung gegen die
  Clips (Hüllkurven-Korrelation ≥ 0,75 und keine Wiederholung der ersten Szene).
  Bei der fehlerhaften Montage lag die Korrelation bei 0,11 und die
  Wiederholung bei 0,89; bei der korrigierten bei 0,91 bzw. −0,01.
- **Angeschnittene Knöpfe waren ein Fehler der App, nicht der Aufnahme.** Die
  Aktionsspalte der Sammeleinzüge ist auf 160 Pixel festgelegt (gedacht für
  drei Symbolknöpfe), enthält aber vier Textknöpfe: sie brachen untereinander
  um, der breiteste ragte heraus, die Tabelle wurde breiter als ihr Rahmen.
  Erst hat `ctx.reveal()` den Rahmen nach rechts gescrollt – dann waren die
  Knöpfe zwar im Bild, dafür die Spalte „Erzeugt" angeschnitten. Behoben ist
  es jetzt in der App (eigene Spaltenbreite, siehe CHANGELOG 0.28.0);
  `reveal()` bleibt als Absicherung stehen.
- **Ein abgerechneter Sammeleinzug lässt sich nicht per API löschen** (die App
  lehnt das bewusst ab), und solange er existiert, bleiben auch seine Mandate
  stehen. Nach drei Aufnahmen zeigte die Liste 111 statt 87 Einträge samt
  Dubletten. `seed.mjs` leert die vier Beitragstabellen deshalb direkt in der
  SQLite-Datei (`hartReinigen()`); die Aufnahme-Instanz ist eine Wegwerfinstanz.
- **Der Finanzplan holt seine Zahlen erst beim Reiterwechsel**, und die Antwort
  kam in der Aufnahme gelegentlich nicht an – im Bild stand dann nur die
  Überschrift über einer leeren Fläche. Szene 05 wartet jetzt auf die
  Überschrift *mit Jahreszahl*: die Jahreszahl hängt an den geladenen Daten und
  ist damit genau das Zeichen dafür, dass sie da sind.
- **Wartebedingungen wandern als Zeichenkette durch mehrere Ebenen.** Ein `\d`
  in einem regulären Ausdruck überlebt das nicht; `[0-9]` schon.
- **Ein zweiter Klick auf denselben Reiter lädt nichts nach.** Die App hängt das
  Laden des Finanzplans an den *Wechsel* des Zustands; steht der schon auf
  „Finanzplan", passiert beim erneuten Klick nichts. Der Wiederholversuch geht
  deshalb über „Auswertung" zurück – und Szene 05 holt den Plan zur Sicherheit
  schon in `prepare()` einmal, also vor der Aufnahme.
- **Eine Szene darf einmal danebengehen.** Die Wartebedingungen hängen an echten
  Netzantworten; fällt eine aus, war früher der ganze Sprachlauf hin.
  `record.mjs` wiederholt eine gescheiterte Szene jetzt einmal – und
  `stopRecording()` meldet den Bildhandler dabei ab, sonst schriebe der alte
  Lauf während der Wiederholung in denselben Bildordner.

### Sechs App-Fehler, die dabei aufgefallen sind

Alle in 0.28.0 behoben, siehe CHANGELOG: die englische Oberfläche blieb deutsch,
weil Nextclouds `.htaccess` keine `.json`-Dateien ausliefert (jetzt über
`/api/l10n/<sprache>`); die Rückfrage beim Verbuchen eines SEPA-Einzugs zeigte
ein rotes „Löschen"; Sphären-, Rücklagen- und Kostenstellennamen kamen als feste
deutsche Zeichenketten aus dem Server; 22 Textbausteine hatten keine englische
Übersetzung; die Knöpfe in der Liste der Sammeleinzüge waren angeschnitten; und
die App meldete die eigene Buchung als „von einer anderen Person geändert", weil
die Frist dafür kürzer war als der Abstand zwischen zwei Abgleichen.
Dazu neu: der Mitglieder-CSV-Import versteht jetzt auch die englischen
Spaltenüberschriften, die das englische Handbuch längst beschrieb.

## Bewusste Entscheidungen

- **Kein rückdatierter Sammeleinzug.** Die App lässt kein Fälligkeitsdatum in
  der Vergangenheit zu. Die Januar-Beiträge werden deshalb direkt als bezahlt
  markiert; der Reiter „Einzug" ist leer, bis Szene 04 den ersten Einzug live
  erzeugt. Das ist für das Video sogar sauberer.
- **Sechs Wochen Lücke am Ende der Buchungen.** Die letzten Wochen deckt der
  Kontoauszug aus Szene 03 ab, sonst stünde jede Zeile doppelt.
- **Beitragsstufen gemischt.** Blockweise Vergabe hätte dazu geführt, dass die
  sechs fälligen Beiträge alle 30 € betragen — der Einzug im Video sähe damit
  nach Jugendchor statt nach Verein aus.
- **`/api/reset` reicht nicht.** Mandate, Beiträge und Einzüge löscht es nicht;
  `seed.mjs` räumt sie einzeln ab, sonst wachsen sie mit jedem Lauf.
- **Alle IBANs sind rechnerisch gültig** (Prüfziffern nach ISO 13616) und liegen
  im Testbankleitzahlbereich `50010517`; alle Namen sind erfunden.

## Gelöst in 0.28.0: die App-Oberfläche blieb deutsch

Beim ersten englischen Lauf am 23.08.2026 kam heraus: Daten, Nextcloud-Rahmen
und Anzeigenamen waren englisch, **die App-Oberfläche selbst blieb deutsch**
(Reiter „Übersicht/Buchungen/Konten", „Geldkonten", „Letzte Buchungen" …).

Ursache, im Browser nachgemessen:

| Beobachtung | Wert |
|---|---|
| Sprache der Sitzung | `en` |
| Angeforderte Datei (`loadAppTranslations()` in `src/lib/l10n.js`) | `/custom_apps/vereinsbuchhaltung/l10n/en.json` |
| Antwort | **404**, `text/html` (Nextclouds Fehlerseite) |

Nextclouds mitgeliefertes `.htaccess` liefert statische Dateien nur für eine
feste Endungsliste aus (`css|js|mjs|svg|gif|ico|jpg|jpeg|png|webp|html|otf|ttf|woff2?|map|webm|mp4|mp3|ogg|wav|flac|wasm|tflite`).
**`.json` steht nicht darauf** – jede Anfrage darauf landet in `index.php` und
kommt als 404 zurück. `img/app.svg` wird ausgeliefert, `l10n/en.json` und
`package.json` nicht. Der Ladeversuch scheitert deshalb immer und fällt still
auf die deutschen Quelltexte zurück (`catch {}` in `loadAppTranslations()`).

Das betrifft nicht nur das Video: **die englische Oberfläche funktioniert in
keiner normalen Nextcloud-Installation.** Serverseitige Texte (IL10N, also z. B.
Fehlermeldungen, Handbuch, Prüfleitfaden) sind nicht betroffen – die liest PHP
direkt von der Platte.

**Behoben in 0.28.0** mit einem eigenen Endpunkt: `GET /api/l10n/{lang}`
(`L10nController` plus `TranslationBundle`) liefert das Bündel aus,
`loadAppTranslations()` holt es von dort statt per `generateFilePath()`. Zwei
Fallen dabei, beide im Code kommentiert: die `PermissionMiddleware` hätte den
Abruf für Nutzer ohne Rolle geblockt (Ausnahme eingebaut), und ohne
`#[NoCSRFRequired]` antwortet Nextcloud auf ein blankes `fetch()` mit **412** –
die CSRF-Prüfung gilt auch für GET.

Nachgemessen nach dem Fix: Endpunkt liefert 200 mit 1150 Schlüsseln, die Reiter
heißen „Overview / Entries / Accounts / Reports / Contributions".

## Ebenfalls gelöst in 0.28.0: englische CSV-Spaltenüberschriften

`MemberCsvParser::HEADERS` kannte nur deutsche Spaltennamen — `Mandat`,
`Betrag` und `Frequenz` hatten keine englische Entsprechung, und ohne erkanntes
Mandatsdatum lehnt der Import jede Zeile mit IBAN ab. Kurios dabei: das
englische Handbuch (13.3) beschrieb `Mandate on`, `Amount` und `Frequency`
bereits als erwartete Spalten.

Jetzt erkennt der Parser beide Sprachen (samt englischer Frequenzwörter). Die
englische Spaltenzeile steht in `content/seed.en.json` unter
`memberCsvHeader` — **noch auf Deutsch**, weil der Datensatz vor dem Fix
entstanden ist. Vor der englischen Aufnahme dort auf
`Name;Email;IBAN;BIC;Mandate;Amount;Frequency;Start date` umstellen und den
Seed neu laufen lassen.
