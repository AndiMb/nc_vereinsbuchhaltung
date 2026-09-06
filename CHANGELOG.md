# Changelog

**Deutsch** · [English (ab 0.27.0)](CHANGELOG.en.md)

Alle nennenswerten Änderungen an dieser App werden hier dokumentiert.

Das Format orientiert sich an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

Der App Store zeigt zu jedem Release den hier passenden Abschnitt an – zu jeder
veröffentlichten Version muss es daher eine Überschrift `## [x.y.z]` geben.

Innerhalb eines Versionsabschnitts **keine** `###`-Zwischenüberschriften
verwenden (auch nicht `## `) – Nextclouds eigenes „Was ist neu"-Popup
(`apps/updatenotification`) rendert Markdown-Überschriften seit `marked` v18
als „[object Object]" (falsche Renderer-Callback-Signatur, Nextcloud-
Core-Bug, reproduziert 23.08.2026). Stattdessen **Fettdruck als Zeilenanfang**
verwenden, z. B. `**Neu:**`.

## [Unreleased]

## [0.31.1] – 2026-09-06

**Behoben:**
- **Auf schmalen Bildschirmen sind die Unterreiter der Berichte wieder
  vollständig erreichbar.** Die Leiste mit *Auswertung*, *Auswertungsgruppen*,
  *Sphären*, *Rücklagen*, *Finanzplan* und *Protokoll* lief aus dem Bild und
  ließ sich nicht wischen – hinter *Sphären* war Schluss, und auch der Knopf
  *Weitere Exporte* war nicht mehr zu erreichen (Issue #38). Die Leiste lässt
  sich jetzt seitlich wischen und zeigt an den Rändern an, dass es dort
  weitergeht; springt man aus der Hilfe oder der unteren Leiste direkt auf
  einen hinteren Reiter, rückt dieser von selbst ins Bild. Das gilt genauso
  für die Unterreiter unter *Buchungen* und *Beiträge* und – bei geöffneter
  Nextcloud-Seitenleiste oder längeren Beschriftungen – auch am Desktop.
- **Betragsfelder zeigen den Betrag jetzt so, wie er daneben steht:
  „20.000,00 €" statt „20000".** Im Finanzplan stand die Spalte *Plan (Soll)*
  als nackte Zahl neben den formatierten Spalten *Ist* und *Differenz* – bei
  vier- und fünfstelligen Planwerten war schwer zu sehen, ob da 5.000 oder
  50.000 steht (Issue #34). Das gilt genauso für alle anderen Betragsfelder:
  Buchungsbetrag und Aufteilung, Eröffnungssaldo, offene Posten,
  Mitgliedsbeiträge und den Standard-Beitrag. Zum Bearbeiten zeigt das Feld
  weiterhin den nackten Wert, damit man nicht gegen eine mitlaufende
  Formatierung antippt; eingeben lässt sich beides – „20000", „20.000,00" oder
  „20000.5". Was sich nicht lesen lässt, setzt den alten Wert zurück, statt
  ihn stillschweigend auf 0 zu setzen.
- **Kassenbericht und Kurzbericht sind auf dem Handy in das Menü *Weitere
  Exporte* gewandert.** Als eigene Schaltflächen passten sie dort nicht mehr
  in die Zeile und schoben das Menü aus dem Bild. Das Datumsfeld des
  Kurzberichts steht jetzt mit im Menü; am Desktop bleibt alles wie bisher.

## [0.31.0] – 2026-09-04

**Neu:**
- **Die Kopfzeile zeigt jetzt den Geldbestand aller Geldkonten, nicht mehr
  nur den des ersten.** Rechts oben stand bisher ausschließlich das erste
  Geldkonto nach Kontonummer – wer Kasse (1000) und Bankkonto (1200) führt,
  sah dort also ausgerechnet die Barkasse, während das Bankkonto unsichtbar
  blieb (Issue #31). Die Zahl heißt jetzt *Geldbestand* und summiert alle
  Geldkonten; der Tooltip schlüsselt sie nach Konten auf und nennt die noch
  nicht zugeordneten Umsätze. Führt der Verein nur ein Geldkonto, steht dort
  weiterhin dessen Name – für diese Vereine ändert sich nichts.
- **Einzelne Geldkonten lassen sich aus dieser Zahl herausnehmen.** Im
  Konto-Dialog gibt es bei Geldkonten das neue Kennzeichen *Zählt in den
  Geldbestand oben in der Kopfzeile* (Vorgabe: an). Damit bleibt z. B. ein
  Festgeldkonto aus der Alltagszahl heraus, ohne dass es aus der Buchhaltung
  verschwindet: **Kassenbericht, Vermögensübersicht und Saldenliste rechnen
  unverändert mit allen Geldkonten.** Das Kennzeichen ist reine Anzeige und
  wird deshalb auch nicht von der Festschreibung eines Geschäftsjahres
  gesperrt.
- **Die Geldkonten-Tabelle in Dashboard und Auswertung hat eine
  Summenzeile** – sobald der Verein mehr als ein Geldkonto führt; bei einem
  einzigen wiederholte sie nur die Zeile darüber. Ist mindestens ein Konto
  vom Geldbestand abgewählt, steht darunter zusätzlich *davon Geldbestand
  (Kopfzeile)*, und die betroffenen Konten sind in der Liste als solche
  gekennzeichnet – so bleibt nachvollziehbar, wie die Zahl oben zustande
  kommt.

**Geändert:**
- **Ein Konto mit Unterkonten anzuklicken klappt sie jetzt mit auf.** Bisher
  ging das nur über den kleinen Pfeil davor, was leicht zu übersehen war.
  Zugeklappt wird weiterhin über den Pfeil – ein zweiter Klick auf die Zeile
  klappt bewusst nicht wieder zu, sonst ließe sich ein Sammelkonto nicht
  auswählen, ohne es zuzuklappen.
- **Der Buchungstext wächst jetzt mit seinem Inhalt.** Längere Texte brechen
  um und werden vollständig angezeigt, statt seitlich aus dem Feld zu laufen –
  beim Anlegen wie beim Bearbeiten einer Buchung. Der Text bleibt dabei
  einzeilig gespeichert, an Journal, Export und Schnittstelle ändert sich
  nichts.
- **Dialoge haben etwas mehr Luft zum Rand.** Inhalt und Schaltflächen klebten
  bisher recht dicht an der Kante.

**Behoben:**
- **Die Diagramme ignorierten das dunkle Design.** Achsenbeschriftungen und
  Gitterlinien waren dauerhaft schwarz und lagen im dunklen Design fast
  unsichtbar auf dunklem Grund – in der Übersicht wie in den Berichten.
  Ursache war eine Abfrage auf die Klasse `theme--dark`, die Nextcloud
  nirgends setzt; die Bedingung war damit immer unwahr. Die Diagramme lesen
  ihre Farben jetzt direkt aus Nextclouds Design-Variablen und folgen so jedem
  Design – hell, dunkel, hoher Kontrast und über die Theming-App gesetzte
  Vereinsfarben. Wird das Design bei geöffneter App umgestellt, zeichnen sie
  sich neu.
- **Erfolgs- und Fehlermeldungen (Toasts) erschienen unten links.** Nextcloud
  selbst zeigt seine Meldungen weiterhin oben rechts, sodass auf einem
  Bildschirm zwei Sorten in zwei Ecken standen. `@nextcloud/dialogs` 7.5.0 hat
  die Meldungen nach unten links verschoben und die Positionsoption ersatzlos
  entfernt; die App rückt sie jetzt selbst wieder an ihren gewohnten Platz.
- **Der aktive Knopf trug einen weißen Rand.** Sichtbar am Umschalter
  *Einnahme/Ausgabe* im Buchungsdialog, außerdem an aktiven Filter-Chips, am
  Unterstrich der aktiven Unterreiter und an der Seitenwahl beim Umbuchen.
  Nextcloud beansprucht `button.active` für seinen eigenen Gedrückt-Zustand
  und überschrieb dabei die Rahmenfarbe der App.
- **Der Fokusrahmen war im Buchungsdialog angeschnitten.** Wer sich mit der
  Tabulatortaste durch den Dialog bewegte, sah den Rahmen um *Einnahme* und
  *Ausgabe* nur als Splitter – der Umschalter schnitt ihn ab.
- **Der Buchungsdialog ließ sich waagerecht verschieben.** Das versteckte
  Feld zur Belegauswahl beanspruchte seine volle Eigenbreite und schob den
  Dialog über seinen Rand hinaus, was einen Rollbalken erzeugte.
- **Im Kontenbaum brachen Konten mit Unterkonten aus der Liste aus.** Sie
  standen fett und deutlich höher da als ihre Geschwister, und ihre Spalten
  saßen ein paar Pixel weiter rechts. Ursache war der Aufklapp-Pfeil: bei
  Elternkonten ist er ein Knopf, und Nextcloud gibt jedem Knopf eine
  Mindesthöhe von 34px und einen Außenabstand mit – die Zeile wuchs dadurch
  auf 46px, während Blattzeilen 29px hoch blieben. Alle Zeilen sind jetzt
  gleich hoch und gleich gesetzt; dass ein Konto Unterkonten hat, zeigt der
  Pfeil. Der blasse Platzhalterpunkt vor Konten ohne Unterkonten ist dabei
  entfallen – er sah aus wie ein Aufzählungszeichen; die Spalte hält der
  Platzhalter weiterhin frei.
- **Im Dialog „Was ist neu?" klebte der Text am Rand.** Er nutzt jetzt
  denselben Innenabstand wie alle anderen Dialoge.

## [0.30.0] – 2026-08-31

**Neu:**
- **Belege lassen sich jetzt auch am Desktop schon beim Anlegen anhängen.**
  Der Dialog *Neue Buchung* zeigt am Desktop denselben Bereich *Belege* wie
  der Bearbeiten-Dialog: ausgewählte Dateien stehen dort in einer Liste (mit
  Entfernen-Knopf) und werden hochgeladen, sobald die Buchung gespeichert
  ist. Bisher gab es das nur in der mobilen Ansicht (Fenster bis 640 px) – am
  Desktop musste man die Buchung erst speichern und dann noch einmal öffnen
  (Issue #29).
  Dateityp und Größe prüft die App dabei **schon bei der Auswahl**: Was der
  Server nicht annimmt (alles außer PDF, JPG, PNG, GIF, WebP; höchstens 20 MB),
  wird sofort mit Namen gemeldet, statt erst nach dem Speichern der Buchung.
  Scheitert ein Upload trotzdem – etwa weil die Verbindung abbricht –, bleiben
  die betroffenen Dateien im Dialog stehen und lassen sich über *Erneut
  hochladen* an die inzwischen angelegte Buchung schicken; die übrigen Belege
  hängen dann schon dran. Während gespeichert und hochgeladen wird, ist der
  Knopf gesperrt, damit aus einem zweiten Klick keine zweite Buchung wird.

**Behoben:**
- **Der Knopf *Anhängen* war nur mit der Maus erreichbar.** Das Dateifeld
  dahinter war per `hidden` ausgeblendet und fiel damit aus der
  Tab-Reihenfolge – wer die App mit der Tastatur bedient, kam an die
  Belegablage nicht heran. Das Feld ist jetzt fokussierbar und zeigt den
  Fokus am Knopf, mobil ebenso wie am Desktop.

## [0.29.1] – 2026-08-30

**Behoben:**
- **Erfolgs- und Fehlermeldungen (Toasts) erschienen als nackter Text oben
  links.** Seit 0.29.0 klebten Bestätigungen wie „Einstellungen gespeichert."
  unformatiert über der Kopfleiste, statt als Kasten unten links zu
  erscheinen. Ursache war die Aktualisierung von `@nextcloud/dialogs` auf
  7.5.0: Die Toasts der Bibliothek tragen seither gehashte CSS-Klassen, deren
  Gestaltung allein ihr mitgeliefertes Stylesheet kennt – bis 7.4.1 hatte
  Nextclouds Server-CSS die damals globalen Toastify-Klassen unbemerkt
  mitgestylt, sodass der fehlende Stylesheet-Import der App nie auffiel.
  Beide Skript-Einstiege laden das Stylesheet jetzt selbst; ein statischer
  Test und ein geschärfter E2E-Test wachen darüber (der bisherige prüfte
  nur, dass der Meldungstext erscheint, nicht dass er gestaltet ist).

## [0.29.0] – 2026-08-30

**Geändert:**
- **Aus „Kostenstelle" wird „Auswertungsgruppe".** Der Bericht, der Knopf
  *Auswertungsgruppen verwalten*, das Feld im Konto-Dialog, alle Meldungen,
  die Hilfe und das Handbuch heißen jetzt so; englisch *reporting group*.
  Anlass war die Rückfrage eines Buchhalters (Issue #7): Eine Kostenstelle ist
  in der Kosten- und Leistungsrechnung eine **zweite Dimension je
  Buchungszeile** – ein Betrag wird darauf verteilt, unabhängig davon, auf
  welches Konto gebucht wird. Diese App macht etwas anderes: Sie fasst
  **Konten** zu Gruppen zusammen, und ein Konto gehört zu höchstens einer
  Gruppe. Der alte Name versprach damit eine Funktion, die es nicht gibt –
  und ein Ergebnis je Kostenstelle gibt es fachlich ohnehin nicht, weil eine
  Kostenstelle nur Kosten trägt, keine Einnahmen.
  **An der Funktion selbst ändert sich nichts**: dieselben drei Gruppierungen,
  dieselben Zuordnungen, dieselben Zahlen. Angelegte Gruppen, ihre Kürzel und
  Namen bleiben unverändert erhalten, eine Umstellung ist nicht nötig. Wer
  einen Betrag auf zwei Gruppen aufteilen möchte, legt dafür weiterhin zwei
  Konten an (gern als Unterkonten eines gemeinsamen Kontos) und teilt die
  Buchung über *Aufteilen…* darauf auf; Handbuch-Kapitel 5.4 erklärt das jetzt
  ausdrücklich.
  Intern bleibt alles beim Alten – Tabelle `vbh_costcenters`, Spalte
  `cost_center_id`, Einstellung `cost_center_mode` und die Route
  `/api/costcenters` sind unverändert, es gibt keine Migration. Ältere
  Einträge im Änderungsprotokoll tragen weiterhin den alten Wortlaut.

**Behoben:**
- **Ein gelöschtes einziehendes Konto blockierte die gesamte
  Einstellungsseite.** Wurde unter „Beiträge & SEPA" ein einziehendes Konto
  gewählt und danach über „Alle Daten löschen" (oder einen Import mit
  Zurücksetzen) der Bestand geleert, zeigte die Einstellung auf ein Konto, das
  es nicht mehr gab. Ab da scheiterte **jedes** Speichern auf der
  Einstellungsseite mit „Das gewählte einziehende Konto wurde nicht gefunden."
  – auch das des Vereinsnamens oder der Belegablage, weil die Seite immer den
  vollständigen Feldsatz sendet. Das Zurücksetzen und das Löschen eines
  einzelnen Kontos räumen die Einstellung jetzt mit ab; geprüft wird sie
  außerdem nur noch, wenn sie tatsächlich geändert wird, damit ein
  nachträglich ungültig gewordenes Konto (etwa nach dem Entfernen seiner IBAN)
  nicht die übrigen Abschnitte lahmlegt.

- **Ein gelöschter Nextcloud-Nutzer blockierte die gesamte
  Einstellungsseite.** War unter „Belege" (Belegablage) oder „Bankdaten"
  (überwachter Ordner) ein Nextcloud-Nutzer eingetragen und wurde dieser Nutzer
  danach in Nextcloud gelöscht, scheiterte **jedes** Speichern auf der
  Einstellungsseite mit „Der angegebene Nextcloud-Nutzer für die … existiert
  nicht." – auch das des Vereinsnamens, weil die Seite immer den vollständigen
  Feldsatz sendet. Das Löschen eines Nextcloud-Nutzers räumt die beiden
  Einstellungen jetzt mit ab: die Belegablage fällt auf die app-interne Ablage
  zurück, der überwachte Ordner wird abgeschaltet. Geprüft wird der Nutzer
  außerdem nur noch, wenn er tatsächlich geändert wird – für die Löschungen,
  die die App nicht mitbekommt (fremdes Nutzer-Backend, eingespielter
  Datenbank-Dump, Löschung bei abgeschalteter App).

- **In den Einstellungen ließ sich kein Nextcloud-Nutzer mehr auswählen.**
  Betroffen waren „Belege" (Belegablage) und „Bankdaten" (überwachter Ordner):
  beide Auswahlfelder boten nur noch „— intern (AppData) —" bzw. „— aus —" an,
  die Liste der Nextcloud-Nutzer blieb leer. Beim Umzug der Einstellungen in
  die Nextcloud-Einstellungen (0.25.0) verlor die Seite die Anbindung an die
  Nutzerliste – sie wurde zwar weiterhin geladen, aber nicht mehr an die
  beiden Abschnitte durchgereicht. Belege konnten dadurch nicht mehr im Ordner
  eines Nutzers abgelegt und der Wachordner nicht mehr eingerichtet werden.

- **Unlesbare Beschriftungen und falsch eingefärbte Knöpfe.** Die App
  verwendete Nextclouds helle Status-Flächentöne als Schrift- und Akzentfarbe:
  „Ausgabe" im Buchungsdialog stand weiß auf Blassrosa, die Restanzeige beim
  Aufteilen war kaum lesbar, Randmarker und Warnstreifen verblassten zu
  Pastelltönen. Das dunkle Design hing außerdem an der
  Betriebssystem-Einstellung statt an der Wahl im Nextcloud-Profil – wer dort
  „Dunkles Design" wählte, während das System hell stand, bekam helle Farben
  auf dunklem Grund. Und mehrere Knöpfe der App (der
  Einnahme/Ausgabe-Umschalter, die Checklisten-Verweise, „Überspringen", die
  Vorschlags-Chips) wurden von Nextclouds allgemeiner Knopf-Regel blau
  eingefärbt. Ein automatisierter Kontrastdurchlauf über zwölf Ansichten in
  hellem und dunklem Design fand vorher neun Verstöße gegen WCAG AA, danach
  keinen; auch Nextclouds Kontrast-Designs werden jetzt korrekt übernommen.
  Zwei überflüssige Trennlinien im Kopfbereich sind nebenbei entfallen.

## [0.28.0] – 2026-08-24

**Behoben:**
- **Die Oberfläche blieb auf Deutsch, obwohl Nextcloud auf Englisch stand.**
  Die App lud ihr Übersetzungsbündel bisher direkt als Datei
  (`l10n/<sprache>.json`) aus dem App-Verzeichnis. Nextclouds mitgeliefertes
  `.htaccess` liefert von dort aber nur Dateien mit bestimmten Endungen aus –
  `.json` gehört nicht dazu, die Anfrage landet in `index.php` und kommt als
  404 zurück. Der Ladeversuch scheiterte deshalb in **jeder** normalen
  Installation still, und die App zeigte weiter die deutschen Quelltexte
  (übliche nginx-Konfigurationen verhalten sich genauso). Die Übersetzungen
  kommen jetzt über einen eigenen Endpunkt (`/api/l10n/<sprache>`), der von
  dieser Einschränkung nicht betroffen ist. Serverseitige Texte – Handbuch,
  Prüfleitfaden, Fehlermeldungen – waren nie betroffen.

- **Rückfrage beim Verbuchen eines SEPA-Einzugs zeigte einen roten Knopf
  „Löschen".** Betroffen waren die beiden Rückfragen „Einzug als ausgeführt
  verbuchen" und „Rückbuchung zurücknehmen": beide ließen die Beschriftung
  offen, und die Vorgabe des Rückfrage-Dialogs ist auf löschende Aktionen
  ausgelegt. Jetzt heißen die Knöpfe „Verbuchen" bzw. „Zurücknehmen" und sind
  nicht mehr rot. Die Vorgabe-Beschriftung ist außerdem übersetzbar – bisher
  stand dort auch in der englischen Oberfläche „Löschen".

- **Die Knöpfe in der Liste der Sammeleinzüge waren angeschnitten.** Die
  Aktionsspalte ist auf 160 Pixel festgelegt – eine Breite, die für drei
  Symbolknöpfe gedacht ist. Die vier Textknöpfe („Zeilen anzeigen“, „XML
  herunterladen“, „Als ausgeführt verbuchen“, „Verwerfen“) brachen dort
  untereinander um, der breiteste ragte aus der Spalte heraus, und die Tabelle
  wurde dadurch breiter als ihr Rahmen: rechts standen die Knöpfe halb
  außerhalb, links war die Spalte „Erzeugt“ angeschnitten. Ab 900 Pixel
  Fensterbreite stehen sie jetzt nebeneinander, darunter weiterhin
  untereinander – dann aber innerhalb der Spalte. Dasselbe galt für
  „Rückbuchung zurücknehmen“ in der aufgeklappten Einzugsliste.

- **„Die Buchhaltung wurde von einer anderen Person geändert“ – obwohl man
  selbst gebucht hatte.** Die App gleicht ihren Stand alle 20 Sekunden mit dem
  Server ab; eine dabei erkannte Änderung galt aber nur 15 Sekunden lang als
  eigene. Da der Abgleich seltener läuft als diese Frist – und während eines
  laufenden Imports zusätzlich aufgeschoben wird – meldete die App größere
  eigene Aktionen regelmäßig als fremde Änderung. Maßstab ist jetzt nicht mehr
  eine feste Frist, sondern der Zeitpunkt, zu dem der eigene Stand zuletzt
  nachweislich mit dem Server übereinstimmte: alles danach selbst Geschriebene
  erklärt die Abweichung. Echte Fremdänderungen werden weiterhin gemeldet.

- **Sphären-, Rücklagen- und Kostenstellennamen blieben auf Deutsch.** Sie
  kamen als feste Zeichenketten aus `ReportService` und liefen nie durch die
  Übersetzung – in der englischen Oberfläche stand deshalb „Ideeller Bereich"
  statt „Non-profit purpose". Betroffen waren die Sphärenübersicht, die
  Rücklagenübersicht und die eingebauten Kostenstellennamen.

- **22 fehlende englische Übersetzungen ergänzt.** Betroffen waren unter
  anderem der Hinweis zum Standard-Beitrag im Mitglieder-Import, die
  Beschriftungen der SEPA-Einzugskarten („Summe", „Fälligkeit", „erzeugt"),
  der „Was ist neu"-Dialog und die Ersteinrichtungs-Hinweise in der Hilfe –
  sie standen in der englischen Oberfläche auf Deutsch. Ein Abgleich der
  850 Textbausteine im Code gegen `l10n/en.json` zeigt jetzt keine Lücke
  mehr.

**Neu:**
- **Mitgliederlisten mit englischen Spaltenüberschriften einlesen.** Der
  CSV-Import erkennt jetzt neben den deutschen auch englische Spaltennamen
  (`Name`, `Email`, `IBAN`, `BIC`, `Mandate`, `Amount`, `Frequency`,
  `Start date` samt gängiger Varianten) sowie englische Frequenzangaben
  (`monthly`, `quarterly`, `semiannual`, `yearly`, `annually`). Das englische
  Handbuch hat diese Spaltennamen bereits beschrieben – jetzt funktionieren
  sie auch. Bestehende deutsche Listen bleiben unverändert lesbar.

## [0.27.2] – 2026-08-23

**Behoben:**
- **„Was ist neu"-Popup von Nextcloud zeigte „[object Object]" statt Text.**
  In 0.27.1 stand dafür noch eine `### Behoben`-Zwischenüberschrift im
  Changelog-Eintrag; Nextclouds Popup-Renderer (`marked` v18) kann
  Markdown-Überschriften nicht mehr korrekt verarbeiten (siehe Hinweis oben)
  und zeigt an deren Stelle „[object Object]", der Rest des Textes
  erscheint aber korrekt. Ab dieser Version verzichten Changelog-Einträge
  auf `###`-Überschriften.

## [0.27.1] – 2026-08-23

**Behoben:**
- **Kaputte „Was ist neu"-Meldung von Nextcloud selbst.** Nach dem Update auf
  0.27.0 zeigte Nextclouds eigenes (von unserer App unabhängiges) Popup zu
  App-Updates statt eines Textes „Neuigkeiten in {app} 0.27.0" gefolgt von
  „[object Object]". Ursache: `info.xml` trug den App-Namen seit 0.27.0 in
  zwei `<name>`-Elementen (Deutsch/Englisch) für einen lokalisierten
  App-Store-Titel – Nextclouds Update-Benachrichtigung liest den Namen aber
  an dieser Stelle ohne Sprachangabe aus und kann mit mehreren `<name>`-
  Elementen nicht umgehen (Nextcloud-Core-Bug). `<name>` ist deshalb wieder
  einsprachig; `<summary>`/`<description>` bleiben weiterhin zweisprachig.

## [0.27.0] – 2026-08-22

### Neu
- **README, Handbuch und Prüfleitfaden jetzt auch auf Englisch.** README.md,
  CHANGELOG.md (ab dieser Version) und HANDBUCH.md liegen zusätzlich als
  `*.en.md` vor, mit Sprach-Umschalter am Dateianfang. `info.xml` trägt
  `<name>`/`<summary>`/`<description>` jetzt in beiden Sprachen, sodass der
  App Store automatisch die passende zeigt. Das in der App ausgelieferte
  Handbuch (`/api/help/handbuch`) und der Prüfleitfaden für Kassenprüfer/innen
  (`/api/help/pruefleitfaden`) erkennen die Nextcloud-Spracheinstellung der
  Nutzerin oder des Nutzers und liefern Englisch statt Deutsch aus, sobald sie
  auf Englisch steht. Die Kapitel-Deep-Links aus der In-App-Hilfe (HelpModal)
  verlinken dafür jetzt auf sprachunabhängige Anker (`section-<Kapitel>`)
  statt auf die aus der (dann übersetzten) Überschrift abgeleiteten Slugs.

## [0.26.0] – 2026-08-22

### Neu
- **„Was ist neu"-Hinweis nach Updates.** Nach einem Update zeigt die App
  jeder Person einmalig einen kurzen, auf ihre Rolle zugeschnittenen Hinweis,
  was sich geändert hat – statt dass Neuerungen nur zufällig auffallen oder
  gar nicht bemerkt werden. Der zuletzt gesehene Stand wird pro
  Nextcloud-Konto serverseitig gemerkt (nicht nur im Browser), damit er auf
  allen Geräten derselben Person konsistent erscheint. Über Hilfe → „Was ist
  neu in Version …" lässt er sich jederzeit erneut aufrufen. Nicht jede
  Version bekommt einen Eintrag – reine Fehlerbehebungen bleiben unerwähnt.

## [0.25.0] – 2026-08-22

### Geändert
- **Einstellungen umgezogen in die Nextcloud-Einstellungen.** Das Zahnrad im
  App-Kopf und sein `NcModal` sind entfallen; die sieben Abschnitte (Verein,
  Belege, Bankdaten, Beiträge & SEPA, Berechtigungen, Jahresabschluss, Daten)
  stehen jetzt unter Einstellungen → Vereinsbuchhaltung – für
  Nextcloud-Administratoren unter „Verwaltung", für App-Verwalter ohne
  Nextcloud-Adminrechte unter „Persönlich". Erste-Schritte-Checkliste und
  Einrichtungsassistent verlinken direkt auf den passenden Abschnitt.
  `SettingsController::update()` schreibt seither nur noch die im Request
  enthaltenen Felder, damit die neue Einstellungsseite und der
  Kostenstellen-Modus (weiterhin in Berichte → Kostenstellen) sich beim
  Speichern nicht mehr gegenseitig überschreiben können.

## [0.24.3] – 2026-08-15

### Geändert
- **Beiträge → Mitglieder: Bearbeiten-Button zeigt jetzt das Stift-Symbol**
  statt des Texts „Bearbeiten“ – konsistent mit den übrigen
  Bearbeiten-Buttons in Tabellenzeilen (Buchungen → Regeln, Journal).

## [0.24.2] – 2026-08-15

### Behoben
- **Mehrere Tabellenzeilen mit zwei oder mehr Buttons wurden mehrzeilig**
  (Buchungen → Regeln, Beiträge → Mitglieder, Offene Posten, SEPA-Einzüge,
  Plan-Stände): NcButton rendert intern `display:flex` und damit blockartig –
  als einfache Geschwister in einer Zelle landete jeder Button in einer
  eigenen Zeile. Betroffene Aktionsspalten wrappen die Buttons jetzt in
  `<div class="vbh-actions">` (bestehendes Muster aus dem Buchungsjournal).
- **Buchungen → Zuzuordnen: Auswahlfeld, „Aufteilen…" und Löschen-Button
  standen dreizeilig untereinander** statt Aufteilen und Löschen in einer
  Zeile zu teilen – neue Klasse `.vbh-assign-btns` (flex-wrap) haelt beide
  Buttons zusammen.
- **Berichte → Protokoll: Zeitpunkt-Spalte schnitt die Uhrzeit ab** – reine
  Datumsspalten sind 96px breit, Datum+Uhrzeit („2026-08-15 14:23") braucht
  mehr Platz (neue Klasse `.vbh-col-datetime`, 140px), betrifft auch die
  Spalte „Gespeichert" bei den Finanzplan-Plan-Ständen.
- **Kostenstellen verwalten: Kürzel-Eingabefeld ragte rechts aus seiner
  Spalte heraus** – `.vbh-short` ist ausserhalb von Tabellen bewusst 110px
  breit, in der 96px schmalen Kürzel-Spalte überstand es die Zellengrenze.
- **Kostenstellen verwalten / Sphären zuordnen: Tabelle auf mobilen Geräten
  rechts abgeschnitten** – die leere Kopfzelle der Checkbox-Spalte erbte die
  für bis zu 3 Icon-Buttons gedachte 160px-Breite (`th:empty`) und quetschte
  Konto- und Zuordnungsspalte auf schmalen Bildschirmen bis zur
  Unlesbarkeit zusammen. Neue schmale Klasse `.vbh-col-check` (40px) für
  reine Checkbox-Kopfzellen; die Konten-Anzahl in der Kostenstellen-Liste
  blendet auf dem Handy zusätzlich aus (wie andere Nebenspalten).

## [0.24.1] – 2026-08-15

### Behoben
- **Modal-Überschriften schwebten am Browserfensterrand statt im Popup**:
  NcModals `:name`-Titel hängt CSS-seitig als Geschwister von
  `.modal-container`, nicht darin – bei zentrierten Dialogen (Einstellungen,
  Neue Buchung, neues Konto, Kostenstellen verwalten, Sphären zuordnen,
  Mitglied aufnehmen, Importe, Hilfe, Bankverbindung wechseln, Plan-Stand,
  Willkommens-Assistent) klaffte dadurch eine Lücke zwischen Titel und Box.
  Betroffene Dialoge rendern die Überschrift jetzt selbst im Inhalt; auf dem
  Handy (size="full") bleibt der eingebaute Titel-Balken, der dort korrekt
  über der Box sitzt.

## [0.24.0] – 2026-08-15

### Geändert
- **Berichte → Kostenstellen/Sphären: Bericht bekommt die volle Höhe, Pflege
  wandert ins Modal**: Seit 0.22.3 hing die Pflege (Kostenstellen anlegen/
  zuordnen, Sphären zuweisen) als zweiter, oft sehr hoher Block unter der
  Split-Ansicht (Baum + Detail) im selben Scrollcontainer – der eigentliche
  Bericht war dadurch auf `min-height: 360px` gedeckelt, während die Pflege
  den Großteil der Seite einnahm. Die Pflege öffnet jetzt als `NcModal` über
  die Buttons „Kostenstellen verwalten" bzw. „Sphären zuordnen" oben rechts
  im Bericht (gleiches Muster wie „＋ Mitglied"/„Liste einlesen" im Reiter
  Beiträge) sowie zusätzlich über „Konten zuordnen" direkt bei der
  Baumzeile „– nicht zugeordnet". Der Kostenstellen-Modus (Gruppierung)
  bleibt sichtbar, da er den Bericht selbst steuert – er ist jetzt ein
  Auswahlfeld in der Kopfzeile statt einer Karte im Pflege-Modal. Damit
  entfallen auch die CSS-Sonderfälle aus 0.23.0/0.23.1
  (`.vbh-sectionbody.is-split` hatte nur wegen des zweiten Blocks
  `flex-direction: column` und ein eigenes `.vbh-section-divider`-Padding
  gebraucht).

## [0.23.1] – 2026-08-15

### Behoben
- **Berichte → Kostenstellen/Sphären: kein Rand, weder oben in der
  Split-Ansicht noch im Pflegepanel darunter**: Nachbesserung zum
  0.23.0-Fix, der die Split-Ansicht (`.vbh-splitinner`) wieder auf volle
  Breite brachte. Zwei getrennte Stellen hatten trotzdem noch keinen Rand,
  weil beide bislang nur implizit vom umgebenden `.vbh-sectionbody`
  profitierten, das für Kostenstellen/Sphären aber bewusst auf `padding: 0`
  gesetzt ist (die Split-Ansicht managt Innenabstände sonst selbst) – bei
  der vorherigen ~1px-Quetschung war das nie sichtbar:
  - `.vbh-splitinner` selbst (Baum + Detail: kein oberer/linker Rand) –
    jetzt eigenes `padding-top`/`padding-left`.
  - das darunterliegende Pflegepanel (`CostCenterPanel`/`SphereAssignPanel`,
    umschlossen von `.vbh-section-divider`, das sonst nur als `<h3>`-Trenn-
    linie in schon gepolsterten Containern vorkommt) – jetzt eigenes
    horizontales Padding auf `.vbh-sectionbody.is-split > .vbh-section-divider`.

## [0.23.0] – 2026-08-15

### Geändert
- **Frontend auf Vue 3 migriert**: Vue 2.7 → 3.5, `@nextcloud/vue` 8 → 9,
  `@nextcloud/dialogs` 5 → 7, `vue-loader` 15 → 17. Build bleibt Webpack
  (`@nextcloud/webpack-vue-config` 6 → 7, unterstützt Vue 3 nativ); Options
  API und die bestehenden Composables (`reactive()`-Singletons) bleiben
  unverändert. Vue 2 ist seit 31.12.2023 End-of-Life, `@nextcloud/vue` 8.x
  bekommt zwar noch Patches, aber keine neuen Funktionen mehr.
- **Nextcloud-Mindestversion auf 31 angehoben** (vorher 28), passend zum
  harten Boden von `@nextcloud/vue` 9. Kostet real nichts – NC 28–31 sind
  sämtlich End-of-Life, gewartet sind nur noch 32–34. `max-version` auf 35
  angehoben, PHP-Obergrenze auf 8.5 (von NC 33/34 unterstützt).
- **ESLint 8 → 10** mit Flat Config (`eslint.config.mjs` statt
  `.eslintrc.cjs`), `@nextcloud/eslint-config` 8 → 9.
- Tote Frontend-Abhängigkeiten entfernt: `vue-router` und `vue-chartjs`
  standen in `package.json`, wurden aber nirgends importiert.

### Behoben
- **Button-Beschriftungen nicht mehr zentriert**: ein `:deep()`-Override aus
  der `@nextcloud/vue`-8-Zeit erzwang `.button-vue__icon { display: flex
  !important }` und verhinderte damit, dass `NcButton` v9 das Icon-Element
  bei textonly-Buttons per `:empty { display: none }` korrekt kollabiert –
  die leere Icon-Box schob den Text sichtbar nach rechts. Override ersatzlos
  entfernt (`App.vue`, `RulesPanel.vue`), v9 zentriert von sich aus richtig.
- **Berichte → Kostenstellen/Sphären nicht scrollbar, ohne Rand**: die
  Split-Ansicht (`.vbh-splitinner`) und das darunterliegende Pflegepanel
  (`CostCenterPanel`/`SphereAssignPanel`) lagen als Flex-Geschwister in
  einer Reihe statt untereinander und quetschten die Split-Ansicht auf
  ~1px Breite. `.vbh-sectionbody.is-split` bekommt `flex-direction: column`
  und `.vbh-splitinner` eine Mindesthöhe, damit beides untereinander steht
  und die Sektion als Ganzes scrollt (wie im Mobile-Breakpoint, der aus
  demselben Grund längst auf `display: block` umschaltet).
- Die beiden Notlösungen, die durch die Vue-2.7-Toolchain nötig waren, sind
  mit Vue 3 hinfällig geworden: `<teleport>` wird jetzt korrekt kompiliert
  (betraf `AccountPickerSheet.vue`, lief bisher über ein manuelles
  `mounted()`/`beforeUnmount()`-Portal-Pattern, siehe 0.22.3), und
  `NcAppSettingsDialog` könnte nun grundsätzlich wieder verwendet werden
  (bisher `NcModal` als Ersatz, siehe Kommentar in `App.vue`) – beides nicht
  in diesem Release umgesetzt, nur die Blockade entfernt.

## [0.22.4] – 2026-08-15

### Geändert
- **App-Store-Beschreibung aktualisiert**: Das seit 0.20.0 bestehende
  Beiträge-&-SEPA-Modul (Mitgliederverwaltung, Lastschriftmandate,
  Sammeleinzug, eigener Reiter) fehlte dort drei Releases lang komplett.
  Ergänzt, außerdem CAMT.053/MT940 und der Wachordner beim
  Kontoauszug-Import – bisher war dort nur CSV-CAMT genannt.

## [0.22.3] – 2026-08-15

### Behoben
- **Konto-/Kategorie-Auswahl auf dem Handy war unbedienbar**: Das
  Bottom-Sheet zur Kontoauswahl (`AccountPickerSheet.vue`) rendete zwar in
  den DOM, blieb aber unsichtbar und unklickbar hinter dem Buchungsdialog –
  Nextclouds eigenes Core-CSS (`#content { position: fixed }`) erzeugt einen
  Stacking-Context, in dem das Sheet trotz hohem `z-index` gefangen war,
  während `NcModal` (der Buchungsdialog) dem entkommt, weil `@nextcloud/vue`
  seine Modals an `document.body` teleportiert. Das Sheet tut das jetzt auch
  – per manuellem Portal-Pattern (`mounted()`/`beforeDestroy()` hängen das
  Element an `document.body`), da `<teleport>` von der hier verwendeten
  `vue-loader@15`-Toolchain nicht kompiliert wird. Gefunden im
  Gesamt-App-Review, siehe APP-REVIEW.md.

## [0.22.2] – 2026-08-15

> In `appinfo/info.xml` direkt von 0.21.0 auf 0.22.1 gesprungen und dann auf
> 0.22.2 weitergezählt, ohne dass 0.22.0/0.22.1 je getaggt oder veröffentlicht
> wurden – dieser Abschnitt fasst beide Zwischenstände zu einem einzigen,
> tatsächlich veröffentlichten Release zusammen.

### Neu
- **Standard-Beitrag** (Zahnrad → Beiträge & SEPA): ein einmal hinterlegter
  Betrag/Frequenz füllt „Mitglied aufnehmen" vor und greift auch im
  CSV-Import, wenn eine Zeile ein Start-Datum, aber keinen eigenen Betrag hat
  – bei 80–100 Mitgliedern mit demselben Satz sonst 80–100 Mal derselbe Wert
  von Hand.

### Geändert
- **Navigation aufgeräumt.** Das Zahnrad-Menü „Einstellungen & Import" hatte
  über die Entwicklungszeit 16 Abschnitte angesammelt – Einrichtung und
  laufende Arbeit ungetrennt nebeneinander. Neu:
  - **Reiter „Beiträge"** (Hauptnavigation, erscheint automatisch sobald das
    Modul genutzt wird): Mitgliederliste und SEPA-Sammeleinzug, vorher zwei
    Abschnitte im Einstellungen-Modal. Kennzahl im Reiter zeigt Beiträge im
    Rückstand.
  - **Unterreiter „Regeln"** (Tab Buchungen): Auto-Zuordnungsregeln, dort wo
    sie auch entstehen (Blitz-Button beim Zuordnen).
  - **Kostenstellen- und Sphären-Pflege** jetzt direkt in den gleichnamigen
    Berichten statt im Zahnrad – inklusive des Kostenstellen-Modus.
  - Das Zahnrad selbst fasst jetzt nur noch sieben statt 16 Abschnitte
    (Verein, Belege, Bankdaten, Beiträge & SEPA, Berechtigungen,
    Jahresabschluss, Daten). Der Wegweiser-Hinweis „Kontoumsätze
    importieren" darin entfällt ersatzlos – der Import steht seit 0.19 im
    Tab „Buchungen".
  - Keine Datenbank-Migration nötig; bestehende Installationen mit
    Mandaten/Beiträgen sehen den neuen Reiter sofort.
- **Import-Verlauf konsolidiert.** Die Liste „Bisherige CSV-Importe" unter
  Einstellungen → Daten zeigte dieselben Angaben (Dateiname, neu, Dubletten)
  wie das Änderungsprotokoll ein zweites Mal – beide wurden bei jedem Import
  parallel beschrieben. Die Liste entfällt; im Protokoll (Berichte →
  Protokoll) blendet der neue Schnellfilter „nur Importe" alles andere aus.
  Die dafür genutzte Tabelle `vbh_imports` wird per Migration entfernt.
- **Buchhalter darf Mitglieder, SEPA-Mandate und den Einzug jetzt vollständig
  verwalten** (vorher Verwalter-only) – die naheliegende Rollenzuweisung für
  eine Kassiererin ohne Rechtevergabe-Befugnis war sonst vom ganzen Reiter
  „Beiträge" ausgesperrt.
- **Mitgliederliste und Einzug zeigen auf dem Handy Karten statt einer
  siebenspaltigen Tabelle**, die dort zeilenweise Buchstabe für Buchstabe
  umbrach.
- **Aktionsspalte der Mitgliederliste verdichtet.** Seltene Aktionen
  (Bankverbindung wechseln, Mandat widerrufen, Löschen) stecken jetzt in
  einem Menü, „Bearbeiten" bleibt sichtbar – auch am Desktop ragte die Spalte
  vorher über den sichtbaren Bereich hinaus.

### Behoben
- **E-Mail-Adressen mit Umlaut im lokalen Teil** („m.müller@gmx.de") galten
  beim Anlegen eines Mandats und beim CSV-Import als ungültig – betraf reale,
  bei gmx.de/web.de/t-online.de zustellbare Adressen.
- **Bestätigungsdialoge für „Mitglieder übernehmen" und „Rückstand
  nachholen"** zeigten einen roten „Löschen"-Button statt einer zum Anlegen
  passenden Beschriftung.
- Der Reiter „Beiträge" blitzte beim Laden der App kurz auf, bevor er
  endgültig erschien.

Details und die zugrundeliegenden Personas/Testfälle siehe
`BEITRAEGE-REVIEW.md`.

## [0.21.0] – 2026-08-13

### Neu
- **Mitglieder und Beiträge in einer Ansicht** (Einstellungen → Mitglieder und
  Beiträge). Bankverbindung und Beitrag werden in einem Schritt angelegt statt
  wie bisher in zwei getrennten Formularen mit doppelter Auswahl des Zahlers.
  Dazu Suchfeld, Filter „nur Auffälligkeiten", Anzeige des Beitragsaufkommens
  im Jahr – gedacht für Vereine und Chöre mit dreistelliger Mitgliederzahl.
- **Mitgliederliste als CSV einlesen.** Erst ein Prüflauf, der je Zeile zeigt,
  was entstehen würde und was nicht stimmt, dann das Anlegen. Spaltennamen
  dürfen deutsch oder englisch sein, in beliebiger Reihenfolge; unbekannte
  Spalten werden übergangen. Eine Vorlage lässt sich herunterladen.
- **Einzug als ausgeführt verbuchen.** Ist das Geld eingegangen, werden alle
  zugehörigen offenen Posten in einem Schritt als bezahlt geschlossen. Bisher
  endete der Ablauf bei der heruntergeladenen XML-Datei, und jeder Posten
  musste einzeln von Hand geschlossen werden.
- **E-Mail-Adresse am Mandat.** Die gesetzlich vorgeschriebene Vorankündigung
  ging bisher nur an Zahler mit Nextcloud-Konto und dort hinterlegter Adresse –
  in den meisten Vereinen also an fast niemanden. Die Ankündigung nennt jetzt
  außerdem die Gläubiger-Identifikationsnummer.
- **Beitragsrückstand sichtbar**, mit Knopf zum Nachholen. Ein rückwirkend
  angelegter Monatsbeitrag brauchte bisher einen Tageslauf je Periode, ohne
  dass irgendwo stand, dass noch etwas aussteht.

### Behoben
- Nach einer **Rücklastschrift** galt das Mandat weiterhin als eingelöst: ein
  zurückgegebener Ersteinzug wurde beim nächsten Mal als Folgeeinzug (RCUR)
  eingereicht statt erneut als FRST. Manche Institute weisen das zurück.
- Eine **Rücklastschrift zu einem bereits abgeschlossenen Einzug** wird jetzt
  ebenfalls erkannt; die Rückgabe trifft regelmäßig erst nach dem Geldeingang
  ein. Der betroffene offene Posten wird dabei wieder geöffnet.
- Der **Mitglieder-Import** lehnt negative Beträge ab, statt sie stillschweigend
  als Forderung zu übernehmen.

### Intern
- Der Erzeuger der pain.008-Datei arbeitet nicht mehr auf der Datenbank-Entität,
  sondern auf einem Wertobjekt. Dadurch prüfen Unit-Tests die erzeugte Datei
  jetzt gegen das amtliche Schema der Deutschen Kreditwirtschaft – die bislang
  ungetestete, aber formatkritischste Stelle des Moduls.

## [0.20.0] – 2026-08-12

### Neu
- **SEPA-Lastschrift und Mitgliedsbeiträge** – ein optionales Zusatzmodul für
  Vereine, die Beiträge einziehen. Wer es nicht braucht, merkt nichts davon:
  ohne angelegtes Mandat bleibt alles wie bisher.
  - **Lastschriftmandate** (Einstellungen → SEPA-Lastschriftmandate): je Mandat
    ein Zahler, eine IBAN und ein Unterschriftsdatum. Zahler ist entweder ein
    Nextcloud-Konto oder ein frei eingetragener Name – letzteres für Verbände,
    die Beitragsanteile von Untergliederungen einziehen. Mandate werden
    widerrufen, nicht gelöscht, damit erzeugte Einreichungen nachvollziehbar
    bleiben.
  - **Mitgliedsbeiträge mit Zahlungsfrequenz** (monatlich bis jährlich): bei
    Fälligkeit legt die App automatisch einen offenen Posten an – mit oder ohne
    Mandat. Ohne Mandat ist der Posten schlicht eine Erinnerung an eine
    erwartete Überweisung.
  - **SEPA-Sammeleinzug** als pain.008-Datei zum Einreichen bei der Hausbank,
    mit Vorschau der fälligen Posten und der Möglichkeit, einen falsch
    erzeugten Einzug wieder zu verwerfen.
  - **Vorankündigung per E-Mail** an Mitglieder mit hinterlegter Adresse, wie
    vom SEPA-Regelwerk verlangt. Zahler ohne Adresse werden vermerkt, damit der
    Verein sie selbst informieren kann.
  - **Rücklastschriften** werden beim Kontoauszugs-Import erkannt und öffnen den
    zugehörigen offenen Posten wieder. Eine Fehlzuordnung lässt sich zurücknehmen.

  Vor dem ersten echten Einzug die erzeugte Datei mit dem Prüftool der Hausbank
  gegentesten – das genaue Format weicht je nach Bank leicht ab.

## [0.19.3] – 2026-08-06

### Geändert
- **Berichte-Seite: Reiter und Buttons wirkten überladen.** Im Auswertung-Tab
  brachen bis zu 7 Export-Buttons (Kassenbericht, Kurzbericht, Beleg-ZIP,
  Prüfleitfaden, Saldenliste, E/A-Übersicht, Mehrjahresübersicht) neben den 6
  Reitern mehrzeilig um und nahmen viel Platz ein. Kassenbericht und
  Kurzbericht – laut Handbuch die beiden meistgenutzten Berichte – bleiben
  direkt anklickbar, die übrigen fünf stecken jetzt in einem Menü „Weitere
  Exporte".

## [0.19.2] – 2026-08-06

### Behoben
- **Kassenbericht und Kurzbericht erschienen auf Englisch, obwohl die
  Nextcloud-Oberfläche auf Deutsch stand.** Nextclouds `L10NFactory` hält eine
  Sprache nur dann für „verfügbar", wenn im `l10n/`-Ordner der App eine Datei
  dazu liegt – für Deutsch gab es bisher keine, weil es ja die Quellsprache
  des Codes ist und `l10n/de.json` daher als überflüssig galt. Ohne diese
  Datei überspringt `findLanguage()` die deutsche Spracheinstellung des
  Nutzers und landet beim harten Fallback Englisch. Betroffen waren nur die
  serverseitig gerenderten Berichte (`IL10N::t()` direkt im PHP); die
  Vue-Oberfläche hat ihr eigenes, clientseitiges Übersetzungssystem und war
  nicht betroffen. Eine leere `l10n/de.json` reicht als Fix: sie macht
  Deutsch für Nextcloud „bekannt", ohne dass echte Übersetzungen nötig wären.

## [0.19.1] – 2026-08-05

### Geändert
- **Buchungsjournal: Zeilen brachen durch bis zu 4 Aktions-Icons zweizeilig
  um.** „Regel anlegen" und „Löschen" stecken jetzt in einem Menü-Button;
  Beleg (falls vorhanden) und „Bearbeiten" bleiben direkt anklickbar. Damit
  passt jede Zeile wieder auf eine Zeile.

## [0.19.0] – 2026-08-05

### Behoben
- **Vorgemerkte Umsätze im CSV-Import konnten zu Dubletten führen.** Die
  CSV-CAMT-Kontoauszüge führen anders als CAMT.053 keinen eigenen
  Statuscode, sondern schreiben den Buchungsstand als Klartext in die Spalte
  „Info" – „Umsatz vorgemerkt" wurde bislang nicht ausgewertet. Ändert sich
  ein solcher Umsatz beim endgültigen Buchen (typischerweise das Datum),
  erkannte ihn ein späterer, sich überschneidender Import nicht mehr als
  bereits vorhanden. `CamtCsvParser` überspringt vorgemerkte Zeilen jetzt wie
  schon `Camt053Parser` es für „PDNG" tut.

### Hinzugefügt
- **Nicht zugeordnete Bankumsätze lassen sich jetzt löschen.** Bisher gab es
  dafür keinen Weg – ein Umsatz, den die Dublettenerkennung bei einem sich
  überschneidenden Kontoauszugs-Import nicht als bereits vorhanden erkannt
  hat, ließ sich weder zuordnen (dann läge er doppelt im Journal) noch wieder
  loswerden. Der Papierkorb-Knopf in „Zuzuordnen" löscht einen einzelnen,
  noch nicht verbuchten Umsatz; bereits zugeordnete bleiben wie gehabt nur
  über den zugehörigen Buchungssatz löschbar.

## [0.18.0] – 2026-08-02

### Hinzugefügt
- **Weitere Mehrsprachigkeit im Backend.** Kassenbericht und Kurzbericht
  (beide als druckfertige HTML-Seite) sowie das Änderungsprotokoll
  (Berichte → Protokoll) erscheinen jetzt in der Sprache des
  Nextcloud-Profils statt fest auf Deutsch.
- Alle bisher noch deutschen Fehler- und Bestätigungsmeldungen der
  Controller sind übersetzt, ebenso die Prüfmeldungen beim Anlegen und
  Ändern von Buchungen (Soll-Haben-Kontrolle) und die Fehlermeldungen der
  CAMT-/MT940-/CSV-Kontoauszugs-Parser.

### Technisch
- Statische Prüfmethoden (`JournalService::validateLines()`,
  `JournalService::reassignPlan()`, `BookingService::validateParts()`), die
  auch direkt aus PHPUnit-Tests ohne laufende Nextcloud-Instanz aufgerufen
  werden, liefern jetzt stabile Fehlercodes statt fertigem Text; die
  Übersetzung passiert in einer begleitenden Instanzmethode mit Zugriff auf
  `IL10N`. Die Parser (`CamtCsvParser`, `Camt053Parser`, `Mt940Parser`,
  `XbucParser`) erhalten `IL10N` als optionalen, in Tests weglassbaren
  Konstruktorparameter mit deutschem Fallback-Text.

### Bekannte Lücken
- Der CSV-Export der Auswertungen ist noch vollständig Deutsch.

## [0.17.0] – 2026-08-02

Der erste sichtbare Schritt zur Mehrsprachigkeit: wer Nextcloud auf Englisch
eingestellt hat, sieht jetzt eine englische Oberfläche statt einer deutschen
mit englischen Brocken drumherum.

### Hinzugefügt
- **Englische Übersetzung.** Alle Vue-Komponenten der Oberfläche – jeder Tab,
  jeder Dialog, jede Fehler- und Bestätigungsmeldung – sowie die häufigsten
  Fehlermeldungen des Backends sind jetzt ins Englische übersetzt
  (`l10n/en.json`). Einen eigenen Sprachschalter braucht die App dafür nicht:
  wer sein Nextcloud-Profil auf Englisch stellt, bekommt automatisch die
  englische Fassung. Die Quelltexte im Code bleiben bewusst Deutsch – der
  Herkunftssprache der App –, Deutsch ist also unverändert die Fallback-Sprache.
- **Video in der App-Store-Beschreibung verlinkt.**

### Technisch
- Eigener Lademechanismus für Übersetzungen im Frontend (`src/lib/l10n.js`):
  die Bibliothek `@nextcloud/l10n` geht davon aus, dass Englisch die
  Quellsprache ist, und lädt dafür gar kein Übersetzungsbundle – bei einer
  deutschen Quellsprache wäre das genau die falsche Sprache.
- `IL10N` ist jetzt per Konstruktor in die Controller und Services injiziert,
  die nutzerseitige Fehlermeldungen werfen.

### Bekannte Lücken
- Kassenbericht, Kurzbericht, Prüfleitfaden und die CSV-Exporte sind noch
  vollständig Deutsch.
- Ein paar Backend-Prüfungen (u. a. die Soll-Haben-Kontrolle beim Buchen, die
  CAMT-/MT940-Parser) bleiben vorerst Deutsch – sie sind bewusst ohne
  Nextcloud-Abhängigkeit gehalten, damit sie sich ohne laufende Instanz testen
  lassen.
- Das Änderungsprotokoll (Berichte → Protokoll) zeigt seine Einträge
  weiterhin auf Deutsch.

## [0.16.0] – 2026-08-01

Diese Version bringt kaum sichtbare Neuerungen: sie räumt den Programmcode auf
und sichert ihn gegen Fehler ab, die bisher niemand bemerkt hätte. Ein solcher
Fehler kam dabei ans Licht – und er betraf ausgerechnet die Kassenprüfung.

### Behoben
- **Der Beleg-Export als ZIP brach ab, sobald ein Beleg im Zeitraum lag.** Die
  Funktion rief eine Methode auf, die es in PHP gar nicht gibt
  (`ZipArchive::addStream`); der Download endete mit einem Serverfehler statt
  mit einem Archiv. Betroffen war genau die Funktion, die eine Kassenprüfung
  braucht, um alle Belege eines Jahres auf einmal zu bekommen. Die Belege
  werden jetzt blockweise ins Archiv geschrieben – der Speicherbedarf bleibt
  wie vorgesehen niedrig, auch bei einem Jahr voller PDFs.
- **Der Kassenbericht ignorierte die dunkle Darstellung.** Wer ihn bei dunklem
  Systemdesign öffnete, bekam schwarze Schrift auf weißem Grund in einem sonst
  dunklen Fenster – der Kurzbericht konnte es längst. Beide Berichte teilen
  sich jetzt dieselbe Gestaltung. Am Ausdruck ändert sich nichts.
- **Beispieldaten konnten halb angelegt liegenbleiben.** Fehlte ein Konto des
  Standard-Kontenrahmens, brach das Anlegen mitten in den Buchungen ab. Jetzt
  meldet die App vorher, welches Konto fehlt.

### Geändert
- **Die Rechenregeln aller Auswertungen liegen jetzt an einer Stelle.** Die
  Frage, welches Konto mit welchem Vorzeichen ins Ergebnis eingeht, war an acht
  Stellen einzeln ausgeschrieben – in den Exporten, in der Saldenliste und in
  den Berichten. Eine Änderung daran musste bisher überall nachgezogen werden.
  An den Zahlen ändert sich nichts; sie sind jetzt nur an einer Stelle
  festgelegt und erstmals automatisiert geprüft.
- **Der Sortier-Vergleich der Tabellen war dreifach vorhanden** und ist es nun
  einmal. Sortierung und Sicherheitsabfragen sind damit in allen Ansichten
  garantiert gleich.

### Technisch
- Statische Analyse (PHPStan, Stufe 5) läuft jetzt bei jeder Änderung mit und
  fand die drei oben genannten Fehler.
- Der 1149 Zeilen lange `ExportController` ist in Dienste aufgeteilt: CSV,
  Beleg-Archiv und die druckfertigen Berichte je für sich, der Controller
  behält nur die Auslieferung (158 Zeilen).
- Frontend-Unit-Tests (Vitest) für die Rechen- und Anzeigehelfer; der
  Testbestand wächst von 118 auf 210 Tests.

## [0.15.0] – 2026-07-31

### Behoben
- **Gelöschte Buchung gab ihren Bankumsatz nicht wieder frei.** Wurde ein
  Buchungssatz gelöscht, der durch das Zuordnen eines Bankumsatzes entstanden
  war, blieb der Umsatz auf „zugeordnet" stehen – mit einem Verweis auf einen
  Buchungssatz, den es nicht mehr gab. Er tauchte danach nirgends mehr auf:
  nicht unter *Zuzuordnen* (dort stehen nur offene Umsätze) und nicht in den
  Salden (die kennen nur Buchungen). Auch die Bank-Abstimmung führte ihn weder
  als gebucht noch als offen, sodass der Kontostand still vom Bankauszug abwich.
  Der Umsatz steht jetzt nach dem Löschen wieder unter *Zuzuordnen* und lässt
  sich neu zuordnen – derselbe Stand wie beim Aufheben einer Zuordnung.
- **Belege eines Jahres konnten beim xbuc-Import verloren gehen.** Die
  Dublettenprüfung des Zusammenführ-Imports beschrieb eine Splittbuchung nur
  über eine ihrer Zeilen. Eine eingehende Buchung, die zufällig auf diesen
  Teilbetrag passte, galt dadurch als bereits vorhanden und wurde
  stillschweigend übersprungen. Der Vergleich betrachtet jetzt die ganze
  Buchung, und beide Seiten des Vergleichs bilden ihren Schlüssel an derselben
  Stelle.
- **Beleg löschen konnte einen Datensatz ohne Datei hinterlassen.** Die Datei
  wurde vor dem Datensatz entfernt; schlug das Löschen des Datensatzes fehl,
  blieb ein Beleg zurück, der sich nicht mehr öffnen ließ. Jetzt gilt auch hier
  die Reihenfolge, die beim Löschen einer ganzen Buchung längst galt: erst der
  Datensatz, dann – nach erfolgreichem Abschluss – die Datei.

### Geändert
- **Der Jahresabschluss schützt jetzt auch die Konten-Stammdaten.** Bisher war
  ein festgeschriebenes Geschäftsjahr nur gegen Änderungen an den Buchungen
  gesichert. Über das Konto ließ sich sein Bericht trotzdem nachträglich
  verschieben: Ein Wechsel der Kontoart dreht das Vorzeichen, ein Wechsel des
  Geldkonto-Kennzeichens verschiebt das Konto zwischen Vermögensübersicht und
  Einnahmen-/Ausgaben-Rechnung – das archivierte Jahresergebnis änderte sich,
  ohne dass eine einzige Buchung angefasst wurde. Kontoart, Geldkonto-
  Kennzeichen, Sphäre, Rücklagen-Art und Kostenstelle lassen sich deshalb nicht
  mehr ändern, solange das Konto in einem abgeschlossenen Jahr bebucht ist; wer
  die Änderung braucht, eröffnet das Jahr wieder und schließt es danach erneut
  ab. Nummer, Name, Kategorie, Aktiv-Schalter und Überkonto bleiben frei
  änderbar – sie ändern nur Beschriftungen, keine Beträge.
- **Konten stehen jetzt im Änderungsprotokoll.** Anlegen, Ändern und Löschen
  eines Kontos wurden bisher nicht protokolliert – ausgerechnet die Stammdaten,
  aus denen jede Auswertung gerechnet wird, waren für die Kassenprüfung
  unsichtbar. Bei einer Änderung vermerkt das Protokoll zusätzlich, ob ein
  auswertungsrelevantes Feld betroffen war.

## [0.14.0] – 2026-07-31

### Hinzugefügt
- **Splittbuchungen.** Ein Betrag lässt sich jetzt auf mehrere Gegenkonten
  verteilen – für die Überweisung, die mehreres zugleich enthält: der
  Jahresbeitrag zusammen mit einer Spende, eine Rechnung, die auf zwei
  Kostenstellen gehört. Bisher blieb dafür nur, den Umsatz einem einzigen Konto
  zuzuschlagen oder ihn von Hand zerlegt nachzubuchen.
  Zwei Wege führen dorthin: im **Buchungsdialog** über den Schalter *Betrag
  aufteilen*, und – der häufigere Fall – direkt beim **Zuordnen eines
  Bankumsatzes** über *Aufteilen…*. In beiden Fällen bleibt das Geldkonto eine
  Zeile über den vollen Betrag; aufgeteilt wird die Gegenseite. Eine laufende
  Restanzeige zeigt, was noch fehlt, gespeichert wird erst, wenn die Aufteilung
  aufgeht. Im Experten-Modus lässt sich wählen, welche Seite aufgeteilt wird.
  Bestehende Splittbuchungen lassen sich genauso bearbeiten wie andere
  Buchungen. Alle Auswertungen – Kassenbericht, Kostenstellen, Sphären,
  Saldenliste – rechnen zeilenweise und weisen die Teilbeträge damit
  getrennt aus.
- **Konten auf inaktiv setzen.** Ein Konto, das wegen vorhandener Buchungen
  nicht gelöscht werden kann, lässt sich im Konto-Dialog auf *inaktiv* stellen:
  es verschwindet aus allen Auswahllisten, Beträge und Historie bleiben
  unverändert. Genau dazu riet die App bisher beim Löschversuch, ohne dass es
  dafür ein Bedienelement gab. Im Kontenbaum sind inaktive Konten
  gekennzeichnet und lassen sich jederzeit wieder aktivieren.

### Behoben
- Der **Journal-Export als CSV** wies bei einer Buchung über mehr als zwei
  Konten einen falschen Betrag aus – er behielt nur je ein Soll- und Habenkonto.
  Jetzt belegt eine solche Buchung mehrere Zeilen mit derselben Buchungsnummer,
  wie in einem Journal üblich. Zweizeilige Buchungen exportiert die App
  unverändert.

### Sonstiges
- Eine Buchung ist intern nun eine Liste von Zeilen; „Soll an Haben" ist der
  zweizeilige Sonderfall davon. Die Prüfung (`JournalService::validateLines()`)
  ist wie das Umbuchen eine reine Funktion ohne Datenbank und damit prüfbar –
  neu sind 26 Unit-Tests dafür.
- Ein aufgeteilt zugeordneter Umsatz trägt kein einzelnes Gegenkonto mehr und
  geht deshalb nicht in die Vorschläge für künftige Zuordnungen ein.

## [0.13.0] – 2026-07-31

### Hinzugefügt
- **Frei definierbare Kostenstellen.** Bisher leitete die App die Kostenstelle
  aus dem Kontenrahmen ab – entweder aus der zweiten Zahlengruppe der
  Kontonummer oder „ein Konto = eine Kostenstelle". Beides setzt einen
  bestimmten Kontenaufbau voraus. Jetzt lassen sich Kostenstellen unter
  *Einstellungen → Kostenstellen* frei anlegen und Konten ihnen ausdrücklich
  zuordnen; beliebige Konten können so zu einem Projekt zusammengefasst werden.
  Der neue Modus heißt *„Frei definierte Kostenstellen"* (Einstellungen →
  Kostenstellen-Modus, nur Verwalter). Die beiden bisherigen Modi bleiben
  unverändert Standard – bestehende Auswertungen ändern sich nicht.
  Die Zuordnung geht einzeln im Konto-Dialog oder für viele Konten auf einmal.
  Angelegte Kostenstellen erscheinen im Bericht auch ohne zugeordnetes Konto,
  damit eine fehlende Zuordnung sichtbar ist.
- **Umbuchen direkt im Kontoauszug.** Wer beim Durchblättern eines Kontos
  (Tab *Konten*) eine falsch zugeordnete Buchung findet, bucht sie an Ort und
  Stelle auf ein anderes Konto um – der Umweg über das Journal entfällt.
  Umbuchen lässt sich jede Seite der Buchung, also auch das Gegenkonto.
  Geändert wird ausschließlich die Kontozuordnung; Betrag, Datum, Beschreibung
  und die Gegenseite bleiben, sodass Soll und Haben nicht auseinanderlaufen
  können. Abgeschlossene Geschäftsjahre bleiben gesperrt, jede Umbuchung steht
  im Änderungsprotokoll, und eine zwischenzeitliche Änderung durch jemand
  anderen wird erkannt.

## [0.12.0] – 2026-07-30

### Hinzugefügt
- **Kontoauszüge in zwei weiteren Formaten:** neben der bisherigen CSV liest
  die App jetzt auch **CAMT.053 (XML)** und **MT940**. Welches Format
  vorliegt, erkennt sie am Inhalt der Datei – die Endung spielt keine Rolle.
  CAMT.053 ist die bessere Wahl, wo die Bank es anbietet: Vorzeichen, Datum
  und Zahlungsbeteiligte stehen dort eindeutig drin, statt aus
  Spaltenüberschriften erraten zu werden.
- **Wachordner:** Auf Wunsch liest die App Kontoauszüge selbstständig aus
  einem Nextcloud-Ordner ein (Einstellungen → *Kontoauszüge automatisch
  einlesen*). Den heruntergeladenen Auszug dort ablegen genügt; stündlich
  sieht ein Hintergrundjob nach. Verarbeitete Dateien wandern nach
  `verarbeitet/`, nicht lesbare mit Begründung nach `fehler/` – gelöscht wird
  nichts. Setzt System-Cron voraus. Das ersetzt das Hochladen von Hand, nicht
  das Herunterladen bei der Bank.
- Vorgemerkte Umsätze aus CAMT.053 werden übersprungen. Sie ändern sich beim
  endgültigen Buchen häufig noch und kämen sonst ein zweites Mal herein.
- **Mehrere Bankkonten werden unterschieden.** Am Geldkonto lässt sich die
  **IBAN** hinterlegen (Tab Konten → Konto bearbeiten, nur bei Geldkonten);
  beim Zuordnen einer Bankbuchung wählt die App daraufhin das Geldkonto, auf
  dem der Umsatz tatsächlich gebucht wurde. Bisher landete alles auf dem
  ersten Bankkonto des Kontenrahmens. Ohne hinterlegte IBAN – oder wenn der
  Auszug keine mitbringt – bleibt es beim bisherigen Verhalten. Wer mehrere
  Konten führt, sollte CAMT.053 oder MT940 exportieren: dort steht die IBAN
  verlässlich, in der CSV mancher Bank nur eine Kontonummer.

### Behoben
- **Derselbe Umsatz landet nicht mehr doppelt, wenn das Exportformat wechselt.**
  Die Dublettenerkennung hing am eigenen Konto, und das schreiben die Formate
  verschieden: die CSV mancher Bank führt dort nur eine Kontonummer, CAMT.053
  immer die volle IBAN. Zusätzlich zum bisherigen Vergleich prüft die App
  Umsätze jetzt über Datum, Betrag und Text gegen die bereits vorhandenen
  Bankbuchungen.

### Sonstiges
- Die Umsatzquellen liegen hinter einer gemeinsamen Schnittstelle
  (`lib/Service/Statement/`); Dublettenerkennung, Regeln und Import-Protokoll
  sind für alle Formate dieselben. Der MT940-Parser ist zugleich die
  Vorarbeit für einen späteren FinTS-Abruf, der genau dieses Format liefert.
- Das Import-Protokoll hält fest, aus welchem Format ein Import stammt;
  Geldkonten können ihre IBAN tragen (Migration 122).
- Unit-Tests für beide neuen Parser sowie ein Kreuztest, der denselben
  Kontoauszug in allen drei Formaten vergleicht (61 Tests).

## [0.11.2] – 2026-07-29

### Behoben
- **Beträge werden nicht mehr abgeschnitten.** In Tabellen hatte die
  Betragsspalte eine feste Breite von 100 px; längere Werte wie
  „28.798,68 €" wurden mit „…" gekürzt und ließen sich falsch lesen. Die
  Spalte wächst jetzt mit.
- **Mobil: „Buchungen" in der unteren Leiste war gekürzt** („Buchun…"). Die
  Einträge teilen sich die Breite jetzt nach Inhalt statt zu gleichen Teilen.
- **Mobil: zu kleine Tippziele.** Schaltflächen, Auswahlfelder und
  Eingabefelder waren teils nur 24–36 px hoch. Sie sind jetzt mindestens
  44 px – auch auf Tablets, die per Finger bedient werden, aber das
  Desktop-Layout bekommen.
- **Mobil: lange Kontonamen** im Kontenbaum wurden gekürzt und waren damit
  nicht mehr unterscheidbar; sie brechen jetzt um.
- **Mobil: das Ende langer Listen** lag unter dem schwebenden
  Buchungs-Knopf und war nicht lesbar.

### Sonstiges
- Ausführliche Beschreibung und Screenshots für den Nextcloud App Store.

## [0.11.1] – 2026-07-29

Erste im Nextcloud App Store veröffentlichte Fassung. Am Programm selbst
ändert sich gegenüber 0.11.0 nichts.

### Sonstiges
- Die App wird jetzt mit dem von Nextcloud ausgestellten Zertifikat signiert
  (`appinfo/signature.json`) und im App Store veröffentlicht. Das Paket zu
  0.11.0 war noch unsigniert und ist deshalb nur über ein direktes
  Deployment aus dem GitHub-Release installierbar.

## [0.11.0] – 2026-07-28

Ergebnis eines Code-Reviews. Schwerpunkt: Datenintegrität der Buchführung.

### Behoben
- **Buchungsnummern bleiben lückenlos.** Bisher hinterließ jede gelöschte
  Buchung eine dauerhafte Lücke in der Nummerierung, die Journal und
  Kassenbericht anschließend als „fehlende Nummern" bemängelten. Solange ein
  Geschäftsjahr offen ist, rücken die nachfolgenden Nummern jetzt automatisch
  auf; mit dem Jahresabschluss werden sie endgültig festgeschrieben. Beim
  Update werden vorhandene Lücken einmalig geschlossen – bereits
  abgeschlossene Jahre bleiben dabei bewusst unangetastet (ihre Nummern
  stehen womöglich schon auf einem archivierten Kassenbericht).
- **Buchungsnummern können nicht mehr doppelt vergeben werden.** Zwei
  gleichzeitig gespeicherte Buchungen ermittelten dieselbe freie Nummer. Ein
  Unique-Index verhindert das jetzt; die zweite Buchung wird automatisch mit
  der nächsten Nummer wiederholt.
- **Buchungen entstehen und verschwinden nur noch vollständig.** Alle
  mehrstufigen Schreibvorgänge (Buchen, Ändern, Löschen, Bankzuordnung,
  Eröffnungssaldo, CSV- und xbuc-Import, Beispieldaten, Zurücksetzen) laufen
  in einer Datenbank-Transaktion. Ein Abbruch mittendrin – Zeitüberschreitung,
  Verbindungsabriss – konnte vorher eine einseitige, unausgeglichene Buchung
  hinterlassen.
- **Konten mit Buchungen lassen sich nicht mehr löschen.** Das Löschen
  hinterließ Buchungszeilen ohne Konto: deren Beträge verschwanden aus
  Saldenliste und Kassenbericht, blieben in der Datenbank aber stehen. Konten
  mit Buchungen oder Unterkonten werden jetzt mit einem erklärenden Hinweis
  abgelehnt (stattdessen: auf „inaktiv" setzen).
- **Buchungen prüfen ihre Eingaben.** Soll- und Habenkonto müssen existieren,
  das Datum muss ein gültiger Kalendertag zwischen 2000 und 2099 sein. Auch
  der CSV-Import verwirft nicht existierende Daten wie den 31. Februar.
- Beleg-Dateien werden erst gelöscht, wenn der zugehörige Datensatz
  tatsächlich verschwunden ist – ein Rollback hätte sonst die Buchung
  zurückgeholt, den Beleg aber nicht.
- **CSV-Exporte können keine Tabellenkalkulations-Formeln mehr einschleusen.**
  Ein Verwendungszweck stammt aus einer fremden Überweisung; beginnt er mit
  `=`, `+`, `-` oder `@`, führte Excel ihn beim Öffnen des Exports als Formel
  aus. Solche Felder werden jetzt als Text markiert – Beträge bleiben
  ausgenommen und damit rechenbar.
- **Mehrzeilige Verwendungszwecke im CSV-Import.** Ein Verwendungszweck darf
  Zeilenumbrüche enthalten, solange er in Anführungszeichen steht; solche
  Zeilen wurden bisher zerrissen und gingen verloren oder bekamen verschobene
  Spalten.
- Beim Markieren eines offenen Postens als bezahlt muss die verknüpfte Buchung
  existieren; bei der Rechtevergabe müssen Nutzer bzw. Gruppe existieren.
  Tippfehler erzeugten vorher stille Verweise ins Leere.

### Geändert
- **Das Änderungsprotokoll wird erst nach dem Commit geschrieben.** Ein
  abgebrochener Vorgang hinterlässt dadurch keinen Protokolleintrag mehr:
  festgehalten wird, was tatsächlich passiert ist.
- **SVG als Vereinslogo wird nicht mehr angenommen** (PNG, JPG und WebP
  bleiben). Ein SVG ist ein aktives Dokument und wäre unter der eigenen
  Nextcloud-Adresse ausgeliefert worden. Bereits hochgeladene SVG-Logos
  bleiben funktionsfähig, sollten aber ersetzt werden.
- **Belegablage:** der eingestellte Nextcloud-Nutzer muss existieren, der
  Ordnerpfad wird geprüft. Beim Zurücksetzen entfernt die App nur noch die
  ihr bekannten Beleg-Dateien statt des gesamten Ablageordners.
- Journal, Kontoauszug und die Exporte laden ihre Daten gebündelt statt je
  Buchung einzeln – bei größeren Beständen deutlich weniger Datenbankabfragen.
- **Der Beleg-Export als ZIP läuft nicht mehr über den Arbeitsspeicher.** Bei
  einem Jahr voller PDF-Belege konnte er vorher am `memory_limit` scheitern –
  ausgerechnet die Funktion, die für die Kassenprüfung gebraucht wird.

### Sonstiges
- CI-Workflow (`.github/workflows/ci.yml`): ESLint, Produktions-Build,
  PHP-Syntaxprüfung auf 8.1 und 8.4, PHPUnit und die info.xml-Schemaprüfung
  laufen jetzt bei jedem Push und Pull Request – nicht erst beim Release.
- `@mdi/js` als direkte Abhängigkeit eingetragen (war nur zufällig über
  `@nextcloud/vue` verfügbar).
- Unit-Tests für Nachnummerierung, Datumsprüfung und CSV-Formatierung ergänzt
  (33 Tests). Sie bringen einen eigenen Autoloader mit und laufen ohne
  `composer install`.
- Dialoge schreiben nicht mehr direkt in die Formularobjekte der Elternansicht,
  sondern melden Änderungen als Ereignis zurück – Voraussetzung für eine
  spätere Vue-3-Migration. Damit läuft ESLint jetzt vollständig ohne Ausnahmen.
- Rechteprüfungen können per Attribut an der Methode festgelegt werden, statt
  sie allein aus dem HTTP-Verb abzuleiten. Die besonders heiklen Endpunkte
  (Zurücksetzen, Jahresabschluss, Einstellungen, Logo, Beispieldaten) sind so
  gekennzeichnet.
- Der überflüssig gewordene Index `vbh_jrn_user_entry` entfällt (Migration 121).
- `composer.phar` ist nicht mehr Teil des Repositorys.

## [0.10.69] – 2026-07-28

### Behoben
- Drei Installations- und Anzeigefehler behoben

### Sonstiges
- Einführungsvideo in die README aufgenommen

## [0.10.68] – 2026-07-14

### Hinzugefügt
- **Mehrjahres-Trend** als Diagramm im Dashboard
- **Rücklagenverwaltung** nach § 62 AO: freie, zweckgebundene und
  Wiederbeschaffungsrücklage als kennzeichenbare Eigenkapital-Konten mit
  eigenem Bericht je Rücklagenart
- **Kurzbericht für Vorstandssitzungen** inklusive Corporate Design
- **Offene-Posten-Verwaltung**: Ad-hoc-Liste unbezahlter Forderungen mit
  Debitor, Betrag, Fälligkeit und Status (offen/bezahlt/storniert), inklusive
  Dashboard-Hinweis bei überfälligen Posten

### Behoben
- Race Condition beim Upsert von Berechtigungen
- Zusätzliche Diagnoseausgaben bei fehlgeschlagenen PATCH-Requests

### Dokumentation
- HANDBUCH.md um die vier neuen Präsidiums-Features ergänzt

## [0.10.63] – 2026-07-13

### Geändert
- Frontend modularisiert: die monolithische `App.vue` wurde in Composables
  (`useAuth`, `useYears`, `useAccounts`, `useBalances`, `useJournal`,
  `usePermissions`, `useSync`), Tab-Komponenten (Dashboard, Konten, Buchungen,
  Auswertungen), Dialoge (Konto, Buchung, Import) und Einstellungs-Panels
  zerlegt

### Behoben
- `toRefs`-Snapshot und Locale-Behandlung in `applySort` nach der
  Modularisierung korrigiert

## [0.10.0] – 2026-06-21

### Hinzugefügt
- Erste Veröffentlichung: CSV-CAMT-Import mit Dublettenerkennung,
  frei definierbarer Kontenrahmen, doppelte Buchführung (Soll/Haben, Journal),
  Zuordnung von Bankbuchungen und optionale Auto-Zuordnungsregeln

[Unreleased]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.16.0...HEAD
[0.16.0]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.15.0...v0.16.0
[0.15.0]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/87e535b...v0.15.0
[0.14.0]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.13.0...87e535b
[0.13.0]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.12.0...v0.13.0
[0.12.0]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.11.2...v0.12.0
[0.11.2]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.11.1...v0.11.2
[0.11.1]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.11.0...v0.11.1
[0.11.0]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.10.69...v0.11.0
[0.10.69]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.10.68...v0.10.69
[0.10.68]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.10.63...v0.10.68
[0.10.63]: https://github.com/AndiMb/nc_vereinsbuchhaltung/compare/v0.10.46...v0.10.63
[0.10.0]: https://github.com/AndiMb/nc_vereinsbuchhaltung/releases/tag/v0.10.17
