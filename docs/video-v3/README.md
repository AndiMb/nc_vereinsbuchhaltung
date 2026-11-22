# Videopipeline v3 — Bedienung

Werbevideo für Vereinsvorstände („Eure Daten, eure Cloud“), Deutsch und
Englisch. Maßgeblich ist die Spec
[`docs/superpowers/specs/2026-11-15-werbevideo-vorstand-design.md`](../superpowers/specs/2026-11-15-werbevideo-vorstand-design.md);
[KONZEPT.md](KONZEPT.md) beschreibt die Aufnahmetechnik der Vorgängerfassung.

**Stand 22.11.2026: beide Videos sind fertig, hochgeladen wird erst nach dem
nächsten Release** – das Video zeigt Funktionen, die noch nicht veröffentlicht
sind.

```bash
node lib/build.mjs --lang de      # doctor, Instanz, Vertonung, Daten, Aufnahme,
node lib/build.mjs --lang en      # Film, Render, Lautheit, Untertitel, Thumbnail, Prüfung
node lib/build.mjs --lang de --skip-instance --only render   # ein Schritt
```

Das Demo-Passwort (`build/.demo-pass`) und der ElevenLabs-Schlüssel
(`build/.elevenlabs-key` oder `ELEVENLABS_API_KEY`) stehen bewusst nicht im
Repository.

| | Deutsch | Englisch |
|---|---|---|
| Video | `build/de/vereinsbuchhaltung-de.mp4` · **2:27,3** | `build/en/vereinsbuchhaltung-en.mp4` · **2:23,8** |
| Bild/Ton | 1920×1080, 30 fps, H.264 · AAC Stereo, −14,3 LUFS, True Peak −1,7 dBTP | dieselben Werte, −14,3 LUFS, True Peak −1,8 dBTP |
| Stimme | ElevenLabs „Brian“, `eleven_multilingual_v2`, Tempo 0,9 | dieselbe |
| Untertitel | `…-de.srt` / `.vtt` | `…-en.srt` / `.vtt` |
| Thumbnail | `thumbnail-de.png` (1280×720, Bild aus dem Film) | `thumbnail-en.png` |
| Titel, Beschreibung, Kapitelmarken | `veroeffentlichung-de.md` | `veroeffentlichung-en.md` |

## Aufbau

Acht Blöcke: `00-auftakt` (Bildfolge aus den Szenen, dann Titelkarte),
`01-souveraen`, `02-geld`, `03-beitraege`, `04-rechnungen`, `05-steuern`,
`06-pruefung`, `07-abspann`. Die Szenen 01–07 werden **ohne** Bildschirmtexte
aufgenommen (Clip + Manifest mit Shots, Zoomzielen und Schnittlücken);
Bauchbinden, Hinweise, Zooms, Mitgliederfenster, Blenden, Musik und Endkarte
legt HyperFrames beim Rendern darüber.

| Baustein | Datei | Zweck |
|---|---|---|
| Sprechtext und Bildschirmtexte | `content/de.json`, `content/en.json` | Sprechtext je Block, Bauchbinden, Hinweise, Zooms, Auftakt-Schnitte (`cards.intro`), KI-Hinweis der Endkarte (`cards.aiNote`), Stimme (`tts`), Veröffentlichungstexte (`publish`) |
| Datensatz | `content/seed.*.json`, `lib/dataset.mjs`, `lib/seed.mjs`, `lib/snapshot.mjs` | Demobestand rechnen, einspielen, sichern und vor Szenen wiederherstellen |
| Instanz | `lib/instance.mjs`, `lib/doctor.mjs` | Sprache und Anzeigenamen per `occ`; Werkzeuge prüfen |
| Vertonung | `lib/tts.py`, `lib/tts_words.py` | ElevenLabs mit Zeichen-Zeitmarken → MP3 + Wortzeiten je Block |
| Zeitpunkte | `lib/cue.mjs` | `cueAt('Wort')` aus den Wortzeiten |
| Aufnahme | `lib/harness.mjs`, `lib/record.mjs`, `scenes/*.mjs` | Cursor, Klicks, Tippen, `dragFile`, Handyrahmen, Schnitt hinter Seitenwechseln; zweiter Browser für das Mitglied |
| Film | `lib/timeline.mjs`, `lib/film.mjs`, `film-template/` | Zeitleiste und HyperFrames-Projekt `film/<lang>/` (generiert, nicht versioniert) |
| Endfassung | `lib/finish.mjs` | `hyperframes render` (4 Worker), Zwei-Pass-Lautheit −14 LUFS, Begrenzer |
| Beiwerk | `lib/subtitles.mjs`, `lib/thumbnail.mjs` | SRT/VTT, Thumbnail, Kapitelmarken aus `blocks[].start` |
| Prüfung | `lib/verify.mjs` | Länge gegen Zeitleiste, Format, Lautheit, True Peak ≤ −1 dBTP, Sprachspur je Block an ihrer Stelle |

Tests: `node --test test/*.test.mjs` und
`python -m unittest discover -s test -p "test_*.py"`.

## Aufnahme-Instanz

Eigener Container, damit der Entwicklungsstand in `nextcloud-test` unberührt
bleibt — die App hat **einen gemeinsamen Datenbestand je Instanz**, ein Seed
würde dort alles überschreiben.

Das Passwort der Instanz steht **nicht** im Repository. Die Skripte lesen es
aus `--pass <wort>`, sonst aus `VBH_DEMO_PASS`, sonst aus der unversionierten
Datei `build/.demo-pass` (eine Zeile). Am einfachsten einmal anlegen:

```bash
python -c "import secrets;print('Vbh-'+secrets.token_urlsafe(12))" > build/.demo-pass
```

**Port 8082**, nicht 8081: 8081 ist auf diesem Rechner von einem anderen
Projekt belegt, 8080 gehört `nextcloud-test` bzw. der E2E-Suite.

Der Bind-Mount zeigt auf dieses Repository – `docker run` deshalb in dessen
Wurzelverzeichnis ausführen (unter Windows den Pfad in Docker-Schreibweise
angeben, etwa `//c/pfad/zum/repo`).

```bash
docker run -d --name vbh-demo -p 8082:80 \
  -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD="$VBH_DEMO_PASS" \
  -e SQLITE_DATABASE=nextcloud -e NEXTCLOUD_TRUSTED_DOMAINS='localhost 127.0.0.1' \
  -v vbh-demo-html:/var/www/html \
  -v "$PWD:/var/www/html/custom_apps/vereinsbuchhaltung" \
  nextcloud:34

docker exec -u www-data vbh-demo php occ app:enable vereinsbuchhaltung
docker exec -u www-data vbh-demo php occ app:disable firstrunwizard

# Mails laufen in einen Mailpit-Container, damit Mailaktionen (Mandatslink,
# Rechnungsversand) weder haengen noch Fehlermeldungen ins Bild bringen.
docker run -d --name vbh-demo-mail axllent/mailpit
docker network create vbh-demo-net
docker network connect vbh-demo-net vbh-demo
docker network connect vbh-demo-net vbh-demo-mail
for kv in "mail_smtpmode smtp" "mail_smtphost vbh-demo-mail" "mail_smtpport 1025"           "mail_from_address verein" "mail_domain example.org"; do
  set -- $kv; docker exec -u www-data vbh-demo php occ config:system:set $1 --value=$2
done
```

Danach prüft `node lib/doctor.mjs` alles, was ein Sprachlauf braucht
(edge-tts ≥ 7.3.1, ffmpeg ≥ 7, Chromium, Instanz, App, Konten, Passwort,
Musik, abgeschaltete HyperFrames-Telemetrie). **edge-tts 7.2.8 bekommt seit
November 2026 ein `403`** vom Dienst; 7.3.1 behebt das.

Demokonten (Passwort für alle: das der Instanz):

| Konto | Anzeigename (de/en) | Rolle in der App |
|---|---|---|
| `andrea` | Andrea Falk | Verwalterin (Gruppe `admin`) — das ist der Aufnahme-Nutzer |
| `jens` | Jens Wieland / James Wieland | Buchhalter |
| `karla` | Karla Riedel / Carla Reed | Revisorin (Kassenprüfung, Szene 06) |
| `mia` | Mia Brandt / Mia Brant | Mitglied mit verknüpftem Konto: „Mein Beitrag“ und Auslage einreichen (zweiter Browser) |

Angelegt mit
`docker exec -u www-data -e OC_PASS="$(cat docs/video-v3/build/.demo-pass)" vbh-demo php occ user:add --password-from-env --display-name "<Name>" [--group admin] <uid>`.
Der Anzeigename wird später je Sprachlauf von `instance.mjs` überschrieben
(`occ user:setting <uid> settings displayname` — `user:modify` gibt es in
Nextcloud 34 nicht mehr).

## Ein Sprachlauf

```bash
cd docs/video-v3
node lib/instance.mjs --lang de          # Nextcloud-Sprache + Anzeigenamen
node lib/seed.mjs     --lang de          # Datenbestand (Standard-URL: http://localhost:8082)

node lib/instance.mjs --lang en
node lib/seed.mjs     --lang en
```

Nützliche Schalter: `--url`, `--user`, `--pass` (sonst `VBH_DEMO_PASS`),
`--port` (CDP, Standard 9444),
`--today YYYY-MM-DD` (fester Stichtag statt heute), `--keep-tab`.
`node lib/dataset.mjs --lang de --dump` rechnet den Bestand nur durch und zeigt
die Eckwerte, ohne eine Nextcloud zu brauchen.

`seed.mjs` startet bei Bedarf selbst ein headless Chromium aus dem
`ms-playwright`-Cache und meldet sich an; die App-Endpunkte hängen an einer
Nextcloud-Sitzung samt CSRF-Token, deshalb läuft alles aus der Seite heraus.

## Was der Seed erzeugt (Stand 23.08.2026; Zahlen der Vorgängerfassung)

```
23 Konten · 161 Buchungen (3 Geschäftsjahre) · 4 Kostenstellen · 6 Regeln
12 Planwerte · 75 Mitglieder, davon 68 mit SEPA-Mandat
7 offene Posten (6 fällige Beiträge + 1 Rechnung) · 0 offene Bankumsätze
```

Dazu in `build/<lang>/`:

| Datei | Verwendung |
|---|---|
| `kontoauszug.csv` | Block 02: wird per `dragFile` in den Import gezogen; die Vorstandsfassung meldet „15 neu, 3 Dubletten" |
| `kontoauszug-vormonat.csv` | wurde vom Seed bereits importiert; liegt nur zur Nachvollziehbarkeit dort |
| `mitglieder-neu.csv` | Szene 04: 12 Neuzugänge, zehn davon ohne Betrag → greift auf den Standardbeitrag zu |
| `seed-dashboard.png` | Kontrollbild nach dem Lauf |

Geprüft nach dem letzten Lauf: Import-Vorschau meldet 14 neu / 3 Dubletten,
Mitglieder-Vorschau 12 ok / 0 Fehler / 12 Mandate / 12 Beiträge, der
SEPA-Einzug zeigt 6 fällige Beiträge über 390 €.

## Vertonung

```bash
python lib/tts.py --lang de                 # alle Blöcke vertonen (ElevenLabs)
python lib/tts.py --lang de --scene 02-geld # nur einen Block neu
```

Ergebnis in `build/<lang>/vo/`: je Block eine MP3, eine `*.words.json` mit
Start und Ende jedes gesprochenen Wortes und `timing.json`. ElevenLabs liefert
Zeitmarken **je Zeichen**; `tts_words.py` fasst sie zu Wörtern zusammen und
lässt reine Satzzeichen (Gedankenstrich) weg, sonst verschieben sich die Anker.
Ein Durchlauf kostet rund 2 000 Zeichen je Sprache. In der Gratisstufe sind
über die API nur die Standardstimmen nutzbar; Bibliotheksstimmen antworten mit
402 „paid plan required“. Ohne `tts`-Eintrag in `content/<lang>.json` fällt
`tts.py` auf edge-tts zurück.

**Anker müssen eindeutig sein.** `cue.mjs` wirft, wenn ein Wort mehrfach oder
gar nicht vorkommt. Nach jedem Stimmwechsel verschieben sich alle Wortzeiten:
dann **alle** Szenen neu aufnehmen. `ctx.until()` meldet „verspaetet“, wenn eine
Bildaktion ihr Stichwort verpasst hat.

## Aufnahme, Film, Endfassung

```bash
node lib/record.mjs    --lang de [--scene 02-geld] [--keep-frames]
node lib/film.mjs      --lang de [--force]   # film/de/ aus Vorlage, Clips, Ton
node lib/finish.mjs    --lang de [--render-only | --skip-render] [--workers 4]
node lib/subtitles.mjs --lang de
node lib/thumbnail.mjs --lang de
node lib/verify.mjs    --lang de
```

`film.mjs` merkt sich die Prüfsummen der erzeugten Dateien und bricht ab, wenn
in `film/<lang>/` etwas von Hand (etwa in der HyperFrames-Desktop-App) geändert
wurde; `--force` sichert die alte Fassung vorher nach `film/<lang>.bak-…`.

**Bild und Ton:** Die Szenenuhr startet mit der Aufnahme, die Sprachspur liegt
bei 0,55 s, jede Bildaktion wartet auf ihr Wort (`ctx.until(ctx.cueAt(…))`).
Seitenwechsel laufen hinter einem Schnitt (`ctx.goto`, `ctx.behindCut`): die
Ladebilder fallen weg, das letzte Bild bleibt stehen.

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
- **HyperFrames findet ffprobe nur im PATH.** Sonst meldet `check`
  `audio_source_unreadable`; `finish.mjs` stellt den ffmpeg-Ordner deshalb vorn
  in den PATH. Ohne `media.autoProxy=false` in `hyperframes.json` lehnt der
  Render die Clips mit 422 `browser_safe_codec` ab.
- **Ein Blockhintergrund deckt die Blende ab.** Mit deckendem Hintergrund im
  Block stand bei jedem Übergang ein Schwarzbild; der Wurzelknoten der Blöcke
  hat deshalb keinen.
- **`hyperframes render` mit „auto“ Workern** lief einmal in den Speichermangel
  (je Worker ein Chrome, der 4K-Clips dekodiert). Standard sind jetzt 4.
- **Die Sprachspur-Prüfung muss sample-genau messen.** Auf das 0,25-s-Raster
  gerundet lag das Fenster bis zu 0,125 s neben der Sprache, und drei Blöcke
  fielen mit 0,57–0,67 durch, obwohl der Ton exakt saß.
- **loudnorm hält den True Peak nicht**, wenn es stark anheben muss (DE +6 dB:
  −0,4 dBTP). Dahinter steht ein Begrenzer bei −2 dBFS.
- **Der Handyrahmen braucht `box-sizing: border-box`**, sonst steht die
  Polsterung rechts und unten 24 px über das App-Bild hinaus. Und er wird erst
  eingeblendet, wenn die App im Rahmen steht (`showFrame({ ready })`).
- **Die App scrollt nicht im Dokument**, sondern in einem Container;
  `scrollFrame` sucht deshalb den größten scrollbaren Bereich.
- **Ohne Schnitt zeigt das Öffnen der App gut eine Sekunde den leeren
  Nextcloud-Hintergrund.** Der Klick bleibt sichtbar, nur das Laden fällt weg.
- **Drag & Drop geht ohne echten Dateimanager:** `ctx.dragFile` zieht eine
  Dateikarte über das Bild und löst am Ziel echte `dragenter`/`dragover`/`drop`
  mit einem `File` aus – die App verarbeitet sie wie beim echten Ziehen.
- **YouTube verlangt je Kapitel mindestens 10 s.** Der englische Abspann kam
  mit 9,0 s Sprechtext nur auf 9,6 s, `thumbnail.mjs` bricht dann ab. Eine
  Szene kann deshalb mit `export const tail` länger ausklingen; der Abspann
  lässt die Endkarte 2 s stehen.
- **„verspaetet“ steht auf stderr.** Wer nur die Standardausgabe mitschreibt,
  sieht die Meldungen nicht. Block 04 läuft in beiden Sprachen rund 3 s hinter
  dem Sprechtext her, weil Mias Einreichen bis etwa 15,4 s dauert; ohne
  längeren Ausklang endete der Block mit dem Zeiger auf „Freigeben“. Szene 04
  hat deshalb `tail = 6.0`: Freigeben, Überweisung vorbereiten und abschließen
  laufen nach dem Sprechtext über der Musik zu Ende.
- **Die Bildfolge des Auftakts darf nicht in eine Aktion der Quellszene
  laufen.** Die Länge eines Ausschnitts kommt aus dem Sprechtext, nicht aus der
  Szene: im deutschen Film blitzte vor dem Schnitt ein Bild lang der aufgehende
  Freigabe-Dialog auf, im englischen gab es zwei Sprünge. `film.mjs` misst die
  Bildwechsel im Quellclip (`sceneChanges`) und verschiebt den Ausschnitt in
  einen ruhigen Abschnitt (`quietStart`); geht das nicht, warnt es.
- **Dialoge mit Stornoknopf:** `closeModal` vergleicht Beschriftungen exakt,
  sonst trifft „Cancel“ den Knopf „Cancel invoice …“.

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
