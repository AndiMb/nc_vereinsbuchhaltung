# Werbevideo v3 — Konzept

Stand: 23.08.2026 · App-Version 0.27.2 · Nachfolger von
[youtu.be/eaF-tAQ_OOM](https://youtu.be/eaF-tAQ_OOM) (v2, 2:13, nur Deutsch)

Dieses Dokument ist die Freigabe- und Umsetzungsgrundlage: Was gezeigt wird
(Kapitel 1–4), wie es aussieht (5), womit es gebaut wird (6–8), in welcher
Reihenfolge (9) und was noch zu entscheiden ist (11).

---

## 1. Ziel und Rahmen

| | |
|---|---|
| **Zweck** | Erstkontakt-Video für App Store, README und YouTube: in gut drei Minuten verstehen, was die App für einen Verein tut und warum sie besser ist als Excel oder eine fremde Cloud. |
| **Länge** | Vollfassung mit neun Szenen (entschieden am 23.08.2026; die Kurzfassung aus 3.3 ist damit vom Tisch). Nach der Vertonung gemessen: **3:17 deutsch, 2:53 englisch** – die Szenendauern in 3.1 waren Schätzungen, die tatsächlichen stehen in `build/<lang>/vo/timing.json` |
| **Sprachen** | Deutsch und Englisch als **zwei vollwertige Videos** — gleiche Bilder, eigene UI-Sprache, eigene Sprecherstimme, eigene Bildschirmtexte, eigene Demodaten |
| **Format** | 1920×1080, 30 fps, H.264; dazu Untertitel (SRT/VTT) und ein Thumbnail je Sprache |
| **Unterschied zu v2** | Nutzen- statt Funktionsdramaturgie (v2 war ein Reiter-für-Reiter-Rundgang), Mitgliedsbeiträge/SEPA als Hauptneuheit, von Anfang an zweisprachig gebaut, Pipeline im Repo statt im Scratchpad |

**Warum neu und nicht nachvertont:** v2 zeigt die App vor Beiträgen/SEPA, vor
dem Vue-3-Umbau und vor dem Einstellungs-Umzug (0.25.0). Die Oberfläche stimmt
an mehreren Stellen nicht mehr. Neu aufnehmen ist billiger als nachschneiden —
die Aufnahme ist ohnehin skriptgesteuert.

---

## 2. Zielgruppe und Kernbotschaften

**Wer schaut das:** Kassenwartin oder Kassenwart eines Vereins mit 50–300
Mitgliedern, meist ehrenamtlich, ohne Buchhaltungsausbildung, mit einer
Nextcloud im Verein (oder ein Vorstand, der über eine nachdenkt). Zweitpublikum:
Vorstände und Nextcloud-Admins.

**Fünf Botschaften, in dieser Reihenfolge:**

1. **Eure Daten bleiben bei euch.** Keine fremde Cloud, kein Abo.
2. **Man muss kein Buchhalter sein.** Einfach-Modus außen, saubere doppelte
   Buchführung innen.
3. **Die Tipparbeit macht die App.** Kontoauszug rein, Regeln ordnen zu,
   Dubletten fliegen raus.
4. **Neu: Mitgliedsbeiträge und SEPA-Lastschrift.** Von der CSV-Mitgliederliste
   bis zur pain.008-Datei für die Hausbank.
5. **Am Ende steht der Kassenbericht.** Mitgliederversammlung, Kassenprüfung und
   Jahresabschluss auf Knopfdruck.

Jede Szene trägt genau eine dieser Botschaften. Was in keine passt, kommt nicht
vor — bewusst draußen bleiben xbuc-Import, Änderungsprotokoll, Rücklagen nach
§ 62 AO und Splittbuchungen. Das steht im Handbuch, nicht im Werbevideo.

---

## 3. Szenenplan

### 3.1 Übersicht

| # | Szene | Dauer | Botschaft | Kernbild |
|---|---|---|---|---|
| 00 | Titel & Aufhänger | 0:12 | — | Titelkarte |
| 01 | In eurer Nextcloud | 0:18 | 1 | Nextcloud → App öffnen → Dashboard |
| 02 | Ohne Buchhaltungswissen | 0:22 | 2 | Einrichtungsassistent, Buchung im Einfach-Modus |
| 03 | Die Bank macht die Arbeit | 0:28 | 3 | Import, Dublettenerkennung, Zuordnen, Regeln |
| 04 | **Beiträge & SEPA (neu)** | 0:32 | 4 | Mitglieder-CSV, Mandat, Sammeleinzug, pain.008 |
| 05 | Auswerten | 0:24 | 5 | Berichte, Finanzplan, Sphären |
| 06 | Versammlung & Prüfung | 0:24 | 5 | Kassenbericht, Revisor-Zugang, Jahresabschluss |
| 07 | Unterwegs | 0:14 | 3 | Smartphone-Ansicht, Beleg fotografieren |
| 08 | Abspann & CTA | 0:14 | — | Beispielverein-Knopf, App-Store-Hinweis |
| | **Summe** | **~3:08** | | zzgl. Blenden ≈ 3:15 |

### 3.2 Szenen im Detail

Der Sprechtext ist ein **Entwurf (Fassung 1)**. Die deutschen Texte sind das
Original, die englischen sind übertragen, nicht wörtlich übersetzt. Vertont
wird erst nach Freigabe.

---

**00 · Titel & Aufhänger** (0:12)

> **DE:** „Kassenbuch führen, Beiträge einziehen, Kassenbericht schreiben — in
> jedem Verein jedes Jahr dieselbe Arbeit. Die App Vereinsbuchhaltung erledigt
> sie dort, wo eure Vereinsdaten ohnehin liegen: in eurer eigenen Nextcloud."

> **EN:** "Keeping the books, collecting membership fees, writing the annual
> treasurer's report — every club, every year, the same work. The
> Vereinsbuchhaltung app does it right where your club's data already lives: in
> your own Nextcloud."

*Bild:* Titelkarte als HTML/CSS-Animation — App-Logo, Titel, Untertitel,
leichter Zoom, Nextcloud-Blau (#0082c9) auf dunklem Verlauf.

---

**01 · In eurer Nextcloud** (0:18) — Botschaft 1

> **DE:** „Kein Abo, keine fremde Cloud, keine Tabelle, die nur eine Person
> versteht. Vorstand, Kassenwart und Kassenprüfung arbeiten auf demselben
> Stand — jeder mit den Rechten, die zur Rolle passen."

> **EN:** "No subscription, no third-party cloud, no spreadsheet only one person
> understands. Board, treasurer and auditor all work on the same data — each
> with the permissions their role calls for."

*Bild:* Nextcloud-Startseite, Klick auf das App-Icon, Dashboard baut sich auf
(Kennzahlen, Geldkonten, Monatsdiagramm). Callout-Overlay: „Verwalter ·
Buchhalter · Revisor".

---

**02 · Ohne Buchhaltungswissen** (0:22) — Botschaft 2

> **DE:** „Man muss kein Buchhalter sein. Beim ersten Start legt ein Assistent
> Verein, Geschäftsjahr und einen fertigen Kontenrahmen an. Eine Buchung
> erfassen heißt dann: Einnahme oder Ausgabe, Betrag, Kategorie — fertig. Soll
> und Haben bucht die App im Hintergrund. Wer es selbst setzen will, schaltet in
> den Experten-Modus."

> **EN:** "You don't need to be an accountant. On first launch a wizard sets up
> the club, the fiscal year and a ready-made chart of accounts. Recording an
> entry then means: income or expense, amount, category — done. Debit and credit
> happen in the background. If you'd rather set them yourself, switch to expert
> mode."

*Bild:* Einrichtungsassistent im Schnelldurchlauf (2 s), dann Buchungsdialog:
Betrag und Text werden getippt, Kategorie gewählt, gespeichert, Zeile erscheint
im Journal. Kurzer Umschalt auf den Experten-Modus mit Soll/Haben-Zeile.

---

**03 · Die Bank macht die Arbeit** (0:28) — Botschaft 3

> **DE:** „Der größte Teil der Arbeit kommt von der Bank. Kontoauszug
> herunterladen, hineinziehen — CSV, CAMT oder MT940, das Format erkennt die App
> am Inhalt. Was schon gebucht ist, überspringt sie. Der Rest landet unter
> ‚Zuzuordnen': ein Klick aufs Gegenkonto, und die Buchung steht. Miete,
> Beiträge, Gebühren — das übernehmen Regeln von allein. Und wer mag, lässt
> einen Nextcloud-Ordner überwachen; dann liest die App neue Auszüge ganz ohne
> Zutun ein."

> **EN:** "Most of the work comes from the bank. Download the statement, drop it
> in — CSV, CAMT or MT940; the app detects the format from the content. Anything
> already booked is skipped. The rest lands under 'To assign': one click on the
> counter-account and the entry is posted. Rent, fees, bank charges — rules
> handle those on their own. And if you like, point the app at a Nextcloud
> folder; then it picks up new statements without you lifting a finger."

*Bild:* Import-Dialog, Datei fällt hinein, Ergebnismeldung „14 neu, 3 bereits
vorhanden — übersprungen" (Zoom auf die Zahl), Liste „Zuzuordnen", ein Klick
ordnet zu, danach greift eine Regel und mehrere Zeilen verschwinden auf einmal.
Kurzer Schwenk auf die Wachordner-Einstellung.

---

**04 · Beiträge & SEPA** (0:32) — Botschaft 4, **die Neuheit**

> **DE:** „Neu sind die Mitgliedsbeiträge. Die Mitgliederliste liest du einmal
> als CSV ein — Name, IBAN, Beitrag, Zahlungsweise. Wer denselben Beitrag zahlt
> wie alle, bekommt ihn aus der Voreinstellung. Fällige Beiträge legt die App
> selbst als offene Posten an. Zum Stichtag: Sammeleinzug erzeugen, Mitglieder
> vorab informieren, pain.008-Datei herunterladen und im Onlinebanking
> einreichen. Ist das Geld da, schließt ein Klick alle Posten auf einmal. Und
> eine Rücklastschrift erkennt die App beim nächsten Kontoauszug von selbst."

> **EN:** "New in this version: membership fees. Import your member list once as
> CSV — name, IBAN, fee, payment frequency. Anyone paying the standard rate
> simply inherits it. Fees that fall due become open items automatically. On
> collection day: create the batch, notify your members, download the pain.008
> file and hand it to your bank. Once the money arrives, a single click closes
> every item at once. And a returned debit? The app spots it in your next bank
> statement by itself."

*Bild:* Reiter „Beiträge" → Mitgliederliste, CSV-Import-Dialog mit Vorschau
(„87 Mitglieder gelesen"), Mitglieds-Dialog mit SEPA-Mandat, Reiter „Einzug":
Vorschau der fälligen Posten mit Summe, „Sammeleinzug erzeugen", XML-Download,
„Als ausgeführt verbuchen" → die offenen Posten verschwinden.

---

**05 · Auswerten** (0:24) — Botschaft 5

> **DE:** „Auswerten geht auf Knopfdruck: Jahresüberblick mit Mehrjahres-Trend,
> Saldenliste, Auswertung nach Kostenstellen. Im Finanzplan stehen Budget und
> Ist nebeneinander, samt Abweichung. Und weil an der Gemeinnützigkeit einiges
> hängt, ordnet die App jeden Betrag den vier Sphären zu und meldet sich, bevor
> eine Freigrenze reißt."

> **EN:** "Evaluation is one click away: a yearly overview with a multi-year
> trend, a trial balance, results by cost centre. In the financial plan, budget
> and actuals sit side by side with the variance. And because nonprofit status
> depends on it, the app assigns every amount to one of the four spheres — and
> speaks up before an exemption limit is breached."

*Bild:* Berichte-Reiter, sanftes Scrollen über die Diagramme, Wechsel auf den
Finanzplan (Soll-Ist-Balken), dann Sphärenübersicht mit Freigrenzen-Hinweis
(Zoom auf die Warnzeile).

---

**06 · Versammlung & Prüfung** (0:24) — Botschaft 5

> **DE:** „Einmal im Jahr wird es ernst. Der Kassenbericht für die
> Mitgliederversammlung entsteht als druckfertige Seite — mit Vereinslogo,
> Vermögensübersicht, Einnahmen und Ausgaben und Unterschriftszeilen. Die
> Kassenprüfung bekommt einen eigenen Lesezugang, alle Belege des Jahres als ZIP
> und eine gedruckte Kurzanleitung dazu. Danach schreibt der Jahresabschluss das
> Jahr fest: lückenlos nummeriert, unveränderbar."

> **EN:** "Once a year it gets serious. The treasurer's report for the general
> assembly comes out as a print-ready page — club logo, asset overview, income
> and expenses, signature lines. Your auditors get read-only access of their
> own, every receipt of the year as a ZIP, and a printed quick guide to go with
> it. Then year-end closing locks the year down: gap-free numbering, no further
> changes."

*Bild:* Kassenbericht öffnet in neuem Tab, langsames Scrollen von der
Vermögensübersicht bis zu den Unterschriftszeilen; Schnitt auf die
Rollen-Einstellung (Revisor), Belege-ZIP-Download, Jahresabschluss-Dialog mit
Festschreibung.

---

**07 · Unterwegs** (0:14) — Botschaft 3

> **DE:** „Der Beleg gehört am besten sofort dazu: unterwegs abfotografieren und
> direkt an die Buchung hängen. Auf dem Smartphone hat die App eine eigene
> Ansicht mit großen Karten."

> **EN:** "A receipt is best filed on the spot: photograph it on the go and
> attach it to the entry right away. On a phone, the app switches to a card view
> built for thumbs."

*Bild:* Smartphone-Rahmen (Chromium-Geräteemulation 390×844 bei 2×),
Kartenliste, Beleg-Anhang mit Foto, untere Navigationsleiste.

---

**08 · Abspann & CTA** (0:14)

> **DE:** „Die App gibt es im Nextcloud App Store — kostenlos und quelloffen.
> Zum Ausprobieren legst du mit einem Klick einen Beispielverein an und
> entfernst ihn genauso schnell wieder. Handbuch und Hilfe stecken in der App.
> Viel Erfolg im Vereinsjahr!"

> **EN:** "The app is in the Nextcloud App Store — free and open source. To try
> it out, create a sample club with one click and remove it just as fast. The
> manual and help live inside the app. Enjoy your club's financial year!"

*Bild:* Beispielverein-Knopf wird geklickt, Daten erscheinen; Überblendung auf
die Outro-Karte mit App-Namen, App-Store-Zeile und Repo-URL.

### 3.3 Kurzfassung (verworfen, hier nur als Rückfallebene dokumentiert)

Am 23.08.2026 zugunsten der Vollfassung entschieden. Falls doch einmal ~2:15
gebraucht werden: 00 (0:10) · 01+02 zusammengezogen (0:25) · 03 (0:25)
· **04 unverändert (0:32)** · 05+06 zusammengezogen (0:28) · 08 (0:12). Es
entfallen Szene 07 (Mobil) und die Wachordner-Einblendung. Der Beitrags-Block
bleibt in jedem Fall vollständig — er ist der Anlass für das neue Video.

---

## 4. Mehrsprachigkeit — wie sie technisch entsteht

Grundsatz: **In der Pipeline steht kein einziger deutscher oder englischer
Satz.** Alles Sprachliche liegt in `content/<lang>.json` bzw.
`content/seed.<lang>.json`. Ein Lauf ist `node build.mjs --lang de`, der andere
`--lang en`; die Szenenskripte sind identisch.

Fünf Ebenen müssen umschalten:

| Ebene | Mechanismus |
|---|---|
| **App-Oberfläche** | `occ user:setting <demo-user> core lang <de\|en>` vor dem Lauf (macht `lib/instance.mjs`). Die Übersetzung ist vollständig (1150 Schlüssel in `l10n/en.json`) und greift seit **App-Version 0.28.0** auch wirklich: bis dahin lieferte Nextcloud `l10n/en.json` gar nicht aus, die Oberfläche blieb in jeder Sprache deutsch (Diagnose und Fix: [README.md](README.md)). Nachgemessen im englischen Lauf: „Overview / Entries / Accounts / Reports / Contributions". |
| **Demodaten** | Eigener Datensatz je Sprache. Nötig, weil Kontenrahmen und Buchungstexte **Nutzerdaten** sind: `AccountService::DEFAULTS` legt „Mitgliedsbeiträge", „Raum- / Mietkosten" usw. fest in Deutsch an. Für den englischen Lauf legt das Seed-Skript den Kontenrahmen selbst mit englischen Namen an, statt den Standardrahmen zu übernehmen. |
| **Sprecherstimme** | edge-tts, eigene Stimme je Sprache (Vorschlag: `de-DE-SeraphinaMultilingualNeural` und `en-US-AvaMultilingualNeural` — ähnlicher Stimmcharakter in beiden Sprachen), Tempo −8 %. |
| **Bildschirmtexte** | Titelkarten, Lower Thirds und Callouts sind HTML-Overlays im aufgenommenen Browser, gespeist aus `content/<lang>.json` — kein ffmpeg-`drawtext`, dadurch keine Font- oder Umlautprobleme und freie Typografie. |
| **Untertitel** | SRT und VTT je Sprache aus den WordBoundary-Zeiten des TTS-Laufs — fällt praktisch kostenlos ab und macht das Video auch stumm verständlich. |

**Nicht umgeschaltet wird die Währung.** SEPA-Lastschrift ist ein
Euroraum-Verfahren; das englische Video zeigt einen englischsprachigen Verein
mit Euro-Beträgen. Das passt zur Zielgruppe der englischen Fassung
(internationale Vereine in der EU, Nutzer mit englischer Oberfläche).

**Veröffentlichung:** zwei getrennte YouTube-Videos (eigener Titel, eigene
Beschreibung, eigene Untertitel, bessere Auffindbarkeit) statt einer Datei mit
zwei Tonspuren. README.md/README.en.md und die beiden `<description>`-Blöcke in
`info.xml` verlinken je die passende Fassung — die Struktur dafür steht schon
(„App in zwei Minuten"-Link).

---

## 5. Look & Feel

- **Bildaufbau:** echte Oberfläche, keine Mockups. 1920×1080 CSS-Pixel, ohne
  Geräteemulation und ohne Skalierungsfaktor: der Screencast liefert Bilder in
  *Fenster*-, nicht in Viewportgröße, deshalb wird das Fenster so lange
  nachjustiert, bis `innerWidth/innerHeight` genau 1920×1080 ergeben
  (`fitViewport()`). Die eingeblendeten Texte sind entsprechend groß gesetzt
  (Lower Third 42 px, Callout 34 px, Titelkarten 105 px), damit sie auch auf
  einem Handy-Display lesbar bleiben.
- **Kamera:** ruhig. Sanftes Scrollen, gelegentlich ein CSS-Zoom auf eine Zahl
  oder Meldung (nie ffmpeg-`zoompan`, siehe 10).
- **Cursor:** synthetischer Cursor als SVG-Overlay im DOM (headless Chromium
  zeichnet keinen echten), Bewegung als CSS-Transition mit Ease-out, Klicks mit
  kurzem Ring-Puls.
- **Tippen:** zeichenweise mit ~55 ms Verzögerung, nicht `fill()`.
- **Farben/Typo:** Nextcloud-Blau #0082c9 als Akzent, dunkles Deckblatt #0a1626,
  Overlay-Schrift in derselben Schriftfamilie wie die Nextcloud-Oberfläche.
- **Lower Third:** unten links, Akzentbalken plus Szenentitel, 0,3 s
  eingeblendet, bleibt 4 s stehen.
- **Blenden:** 0,4 s Kreuzblende zwischen Szenen (setzt neueres ffmpeg voraus,
  siehe 6.2) — sonst Blende über Schwarz.
- **Ton:** Sprache auf −16 LUFS normalisiert, Musikbett bei −22 LUFS mit Ducking
  unter der Sprache, Gesamtmix −14 LUFS (YouTube-Norm).
- **Musik:** derselbe Track wie beim letzten Video —
  `lvymusic-calm-background-for-video-121519.mp3` im Repo-Wurzelverzeichnis
  (2:40,9). Er ist ~27 s kürzer als das Video; die Montage loopt ihn deshalb
  mit 4 s Kreuzblende an der Nahtstelle und blendet zum Schluss aus. Die Datei
  liegt wegen der `*.mp3`-Regel in `.gitignore` **nicht** im Repository — bei
  einem Rechnerwechsel muss sie mitgenommen werden.
- **Keine echten Daten:** erfundener Verein, erfundene Namen, IBANs
  ausschließlich aus dokumentierten Testbereichen.

---

## 6. Werkzeuge

### 6.1 Vorhanden (heute auf dieser Maschine geprüft)

| Zweck | Werkzeug | Stand |
|---|---|---|
| Aufnahme-Instanz | Docker-Container `nextcloud-test`, Nextcloud 34.0.0.12, PHP 8.4, App 0.27.2, http://localhost:8080 | läuft |
| Browsersteuerung + Aufnahme | Playwright 1.56.0 (über `npx`, kein lokales Paket nötig) mit dem vorhandenen Chromium-Build 1228 unter `%LOCALAPPDATA%\ms-playwright` | verfügbar |
| Aufnahme-Fallback / CDP-Zugriff | abhängigkeitsfreier CDP-Client aus dem vorhandenen Browser-Testing-Werkzeug (Ende-zu-Ende getestet, liegt jetzt als `lib/cdp.cjs` im Repo) | vorhanden |
| Sprachausgabe | Python 3.14.3 + `edge-tts` 7.2.8 (neuronale Stimmen, WordBoundary-Zeiten) | vorhanden |
| Schnitt/Montage | ffmpeg 4.2.3 (das mit ImageMagick mitgelieferte) | vorhanden, **mit Einschränkung**, siehe 6.2 |
| Standbilder, Thumbnails, Geräterahmen | ImageMagick 7.1.1-47 | vorhanden |
| Orchestrierung | Node 22.23.0 / npm 10.9.8 | vorhanden |
| Demodaten-Zugriff | App-eigene REST-API inkl. `POST /api/reset` und `POST /api/demo/seed` | vorhanden |

### 6.2 Zu beschaffen (Stand nach den Entscheidungen vom 23.08.2026)

| Punkt | Warum | Vorgehen |
|---|---|---|
| **ffmpeg ≥ 6** *(freigegeben)* | Das gebündelte 4.2.3 (2020) kennt `xfade` nicht (ab 4.3) — weiche Kreuzblenden wären damit nicht möglich, nur Blenden über Schwarz; auch `loudnorm` ist dort älter. | Aktuelles Static-Build parallel installieren (`winget install Gyan.FFmpeg`, sonst BtbN-Zip entpacken); der Pfad bleibt in der Pipeline konfigurierbar, das alte Binary wird nicht angefasst. |
| **Musikbett** *(entschieden)* | — | `lvymusic-calm-background-for-video-121519.mp3` aus dem Repo-Wurzelverzeichnis, derselbe Track wie beim letzten Video. 2:40,9 lang, also ~27 s kürzer als das Video → Loop mit 4 s Kreuzblende, siehe Kapitel 5. |
| **Demo-Container** *(entschieden)* | Die App hat **einen gemeinsamen Datenbestand je Instanz** (`Application::BOOK`) — das Seeding würde die Testdaten in `nextcloud-test` überschreiben. | Zweiter Container `vbh-demo` auf Port 8081, gleicher Bind-Mount auf dieses Repo, eigener Demo-Nutzer. `nextcloud-test` bleibt unberührt. |
| **YouTube-Upload** | Kanalzugang liegt bei dir. | Ich liefere Videodateien, Untertitel, Thumbnails sowie Titel- und Beschreibungstexte je Sprache; du lädst hoch. |
| **Vereinslogo für die Demo** | Der Kassenbericht kann ein Logo tragen; ein echtes gibt es nicht. | Erfundenes Chor-Logo als SVG in `assets/`, je eine Fassung für „Liederkranz Waldbach" und „Riverside Community Choir". |

---

## 7. Pipeline-Architektur

**Aufnahmeverfahren:** Playwright startet Chromium und steuert die App; die
Bilder kommen aber nicht aus Playwrights `recordVideo`, sondern über
`context.newCDPSession(page)` und `Page.startScreencast` (JPEG, Qualität 90).
Jedes Bild trägt einen Zeitstempel; daraus baut ffmpeg über den concat-Demuxer
eine Spur mit konstanten 30 fps. Vorteil gegenüber der letzten Produktion: **die
Startzeit der Aufnahme ist exakt bekannt** — der Filmklappen-Trick
(magentafarbenes Bild im Rohvideo suchen, siehe 10) entfällt ersatzlos, und es
gibt keinen WebM→H.264-Zwischenschritt.

**Ton zuerst:** Das TTS läuft vor der Aufnahme. Die WordBoundary-Zeiten landen
in `words.json`; die Szenenskripte takten Bildaktionen an gesprochene Wörter
(`await cue.at('pain-Punkt-null-null-acht')`), nicht an geschätzte Sekunden.

```
docs/video-v3/
  KONZEPT.md              # dieses Dokument
  content/
    de.json               # Sprechtext + Bildschirmtexte je Szene
    en.json
    seed.de.json          # Verein, Kontenrahmen, Buchungen, Mitglieder
    seed.en.json
    kontoauszug.de.csv    # Demo-Kontoauszug für den Import in Szene 03
    kontoauszug.en.csv
  assets/                 # Logo, Titelhintergrund, Geräterahmen, Musik
  overlay/                # HTML/CSS: Titelkarte, Lower Third, Callouts, Cursor
  lib/
    seed.mjs              # Datenbestand über die App-API aufbauen (reset + anlegen)
    tts.py                # edge-tts → mp3 + words.json je Szene
    cue.mjs               # at('wort') / after('wort') aus words.json
    harness.mjs           # Cursor, Tippen, Scrollen, CSS-Zoom, Overlays, Screencast
    record.mjs            # Szenen-Runner (eine oder alle Szenen)
    subtitles.mjs         # SRT/VTT aus words.json
    assemble.mjs          # ffmpeg: Schnitt, Blenden, Musik-Ducking, Loudness
    build.mjs             # alle Schritte nacheinander, ein Sprachlauf
  scenes/
    00-intro.mjs … 08-outro.mjs
  build/                  # Rohbilder, mp3, Szenen-mp4, Endvideo  → .gitignore
```

Ein kompletter Lauf:

```bash
node lib/build.mjs --lang de --music assets/musik.mp3
node lib/build.mjs --lang en --music assets/musik.mp3

# oder Einzelschritte:
node lib/seed.mjs --lang de && python lib/tts.py --lang de \
  && node lib/record.mjs --lang de --scene 04 && node lib/assemble.mjs --lang de
```

**Versionierung:** `docs/video-v3/` kommt vollständig ins Repo, nur `build/`
wird ignoriert. Genau das war der Fehler der letzten Produktion — die Skripte
lagen im Session-Scratchpad und sind verloren.

---

## 8. Demodatenbestand

Ein Datensatz je Sprache, aufgebaut über die App-API (`POST /api/reset`, dann
Konten, Buchungen, Regeln, Mitglieder, Mandate). Alle Datumsangaben relativ zum
Aufnahmetag, damit das Video in einem Jahr nicht veraltet wirkt.

**Der Demo-Verein ist ein Chor** — die App ist mit dieser Erstintention
entstanden, und der mitgelieferte Beispielverein in `DemoDataService` ist
ebenfalls ein Chor. Das Video zeigt damit denselben Vereinstyp, den ein neuer
Nutzer beim Ausprobieren vor sich hat.

| | Deutsch | Englisch |
|---|---|---|
| Verein | Liederkranz Waldbach e. V. | Riverside Community Choir |
| Geschäftsjahre | drei (Vorjahr −2 bis laufend) | ebenso |
| Konten | 23: Standardrahmen + chorspezifische (Chorleitung, Notenmaterial und Lizenzen, Probenraummiete, Konzerteinnahmen je Konzert, Chorfahrt) | englische Entsprechungen, eigens angelegt (der Standardrahmen ist fest deutsch, siehe Kapitel 4) |
| Kostenstellen | Frühjahrskonzert, Weihnachtskonzert, Jugendchor, Chorfahrt | ebenso |
| Buchungen | 161 über drei Jahre, plausible Beträge | ebenso |
| Mitglieder | 75 vorab (68 mit SEPA-Mandat), dazu die 12, die Szene 04 per CSV einliest → 87 | ebenso |
| Beitragssätze | 60 € jährlich (Standard), 30 € Jugend, 90 € Familie, 25 € Fördermitglied | ebenso |
| Kontoauszug für Szene 03 | 17 Umsätze (Beiträge, GEMA, Notenverlag, Probenraum, Konzertkasse, Zuschuss des Chorverbands, Bankgebühren), davon 3 bereits gebucht → Import meldet „14 neu, 3 Dubletten" | ebenso |
| Offene Posten | 6 fällige Beiträge (390 €), 1 überfällige Rechnung des Notenverlags | ebenso |
| Rollen | `andrea` Verwalterin (Aufnahme-Nutzerin), `jens` Buchhalter, `karla` Revisorin | ebenso, mit englischen Anzeigenamen |

Zwei Abweichungen vom ersten Entwurf, die sich beim Bauen ergeben haben:
Der Reiter „Einzug" startet **ohne Einzugshistorie** — die App lässt kein
Fälligkeitsdatum in der Vergangenheit zu, ein rückdatierter Januar-Einzug ist
also nicht anlegbar. Szene 04 erzeugt damit den ersten Einzug überhaupt, was
die Szene eher klarer macht. Und die Mitgliederliste ist bewusst schon gefüllt:
Szene 04 liest **12 Neuzugänge** ein, nicht die ganze Liste — sonst wären alle
vorherigen Szenen ohne Beitragsdaten.

IBANs ausschließlich aus dokumentierten Testbereichen, Namen erfunden;
zusätzlich vor der Veröffentlichung prüfen, dass im Bild keine vollständige
IBAN groß im Zoom steht.

---

## 9. Produktionsablauf

| Phase | Inhalt | Ergebnis |
|---|---|---|
| **0** ✅ | Konzept freigeben, offene Punkte aus Kapitel 11 entscheiden | dieses Dokument, abgenickt |
| **1** ✅ | Demo-Container aufsetzen, `seed.mjs` + Demodaten DE/EN | erledigt am 23.08.2026 — Bedienung siehe [README.md](README.md) |
| **2** ✅ | Sprechtexte final (DE, dann EN), TTS-Lauf, Stimmenabnahme | erledigt am 24.08.2026 — Sprechtext in `content/<lang>.json`, Vertonung samt Wortzeiten in `build/<lang>/vo/`, Stimmen abgenommen (Seraphina / Ava) |
| **3** ✅ | `harness.mjs`, Overlays, Szenen 00–08 als Skripte, Rohaufnahme Deutsch | erledigt am 24.08.2026 |
| **4** ✅ | Montage Deutsch, Sichtung, Feinschliff (Timing, Zooms, Wortanker) | `build/de/vereinsbuchhaltung-de.mp4`, 3:26,7 |
| **5** ✅ | Englischer Lauf: Sprache umstellen, Seed EN, dieselben Szenenskripte | `build/en/vereinsbuchhaltung-en.mp4`, 3:02,4 — ein Befehl, rund sechs Minuten |
| **6** | Untertitel ✅, Thumbnails ✅, Titel-/Beschreibungstexte ✅ — **Upload und Linkpflege bleiben bei dir** | `build/<lang>/` enthält alles Nötige |

Phasen 3 und 4 waren der Aufwandsschwerpunkt, Phase 5 kostete danach wie
geplant nur noch Rechenzeit: derselbe Befehl mit `--lang en`, rund sechs
Minuten. Die Fallstricke aus der Aufnahme stehen gesammelt in
[README.md](README.md#fallstricke-die-zeit-gekostet-haben).

---

## 10. Übernommene Lehren aus der letzten Produktion

Aus der (verlorenen) Pipeline des YouTube-Videos, hier bereits eingeplant:

- **Ton zuerst, Bild danach** — Bildaktionen an Wortzeiten binden, nicht an
  geschätzte Sekunden.
- **Eindeutige Wortanker wählen** — `after('ist')` traf seinerzeit im Satz „Soll
  und Ist gegenüber" statt am Zielort; mehrdeutige Wörter brauchen Kontext oder
  einen Index.
- **Zoom im Browser per CSS-`transform`**, nicht per ffmpeg-`zoompan` (das
  ruckelt bei langsamen Fahrten, weil es auf Pixel rundet).
- **Nach dem Zoom die CSS-Transition explizit beenden**, sonst hält Playwright
  Elemente für „instabil" und verzögert Klicks.
- **`locator.filter({ visible: true })` immer vor `.first()`** — verlassene
  Reiter bleiben per `v-show` im DOM (mehrfach vorhandene Chart-Karten,
  Dateiknöpfe, versteckte `<option>`-Einträge).
- **Aufnahmestart nicht schätzen** — hier gelöst durch Screencast-Zeitstempel
  statt Filmklappe.
- **Skripte ins Repo**, nicht ins Scratchpad.

---

## 11. Entscheidungen

### Entschieden am 23.08.2026

| Punkt | Entscheidung |
|---|---|
| **Länge** | Vollfassung ~3:08 mit neun Szenen. Kurzfassung verworfen. |
| **Musik** | Der Track des letzten Videos: `lvymusic-calm-background-for-video-121519.mp3` (Repo-Wurzel, 2:40,9, wird geloopt). |
| **ffmpeg** | Upgrade auf 7.x freigegeben — weiche Kreuzblenden per `xfade`. |
| **Aufnahmeinstanz** | Eigener Container `vbh-demo` auf Port 8081; `nextcloud-test` bleibt unangetastet. |

### Entschieden am 24.08.2026 (nach den Hörproben)

| Punkt | Entscheidung |
|---|---|
| **Stimmen** | `de-DE-SeraphinaMultilingualNeural` und `en-US-AvaMultilingualNeural`, Tempo −8 % — beide mehrsprachig, sprechen Nextcloud, SEPA und pain.008 sauber aus. |
| **Länge** | 3:17 deutsch bleiben so. Weitere Kürzungen gingen an die Verständlichkeit, und die wiegt hier mehr als die geplante Minutenzahl. |
| **Englischer Chorname** | „Riverside Community Choir" (seit Phase 1 im Seed-Datensatz in Gebrauch). |

### Noch offen

Nichts, was Phase 3 blockiert.
