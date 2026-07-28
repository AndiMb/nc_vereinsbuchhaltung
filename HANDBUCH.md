# Handbuch Vereinsbuchhaltung

Ein Praxis-Handbuch für Schatzmeisterinnen und Schatzmeister – von der
Ersteinrichtung bis zum Jahresabschluss. Es beschreibt die App-Version
**0.10.67** und orientiert sich am tatsächlichen Jahresablauf, nicht an
Menüstrukturen: Was muss ich wann tun, und worauf ist dabei zu achten?

---

## Inhaltsverzeichnis

1. [Worum es geht – und ein bisschen Buchführung](#1-worum-es-geht--und-ein-bisschen-buchf%C3%BChrung)
2. [Ersteinrichtung (einmalig)](#2-ersteinrichtung-einmalig)
3. [Daten ins System bringen](#3-daten-ins-system-bringen)
4. [Die laufende Arbeit: buchen und zuordnen](#4-die-laufende-arbeit-buchen-und-zuordnen)
5. [Auswertungen verstehen](#5-auswertungen-verstehen)
6. [Finanzplan (Budget)](#6-finanzplan-budget)
7. [Berichte, Exporte und der Kassenbericht](#7-berichte-exporte-und-der-kassenbericht)
8. [Jahresabschluss und Festschreibung](#8-jahresabschluss-und-festschreibung)
9. [Kassenprüfung vorbereiten und begleiten](#9-kassenpr%C3%BCfung-vorbereiten-und-begleiten)
10. [Mehrere Personen an der Buchhaltung (Kollaboration)](#10-mehrere-personen-an-der-buchhaltung-kollaboration)
11. [Unterwegs: die App auf dem Smartphone](#11-unterwegs-die-app-auf-dem-smartphone)
12. [Wenn etwas schiefgeht – Hilfe und Sicherheit](#12-wenn-etwas-schiefgeht--hilfe-und-sicherheit)
13. [Anhang: Rollen, Kontotypen, Tastenkürzel, Glossar](#13-anhang-rollen-kontotypen-tastenk%C3%BCrzel-glossar)

---

## 1. Worum es geht – und ein bisschen Buchführung

Die Vereinsbuchhaltung ist eine App **in Nextcloud**. Sie ersetzt die
Tabellenkalkulation für die Vereinsfinanzen und führt die Bücher nach den
Regeln der **doppelten Buchführung** – sauber, nachvollziehbar und prüffähig.

**Warum doppelte Buchführung?** Jede Buchung wird auf zwei Konten gebucht,
einmal im *Soll* (links, „wo kommt das Geld hin?") und einmal im *Haben*
(rechts, „wo kommt es her?"). Beispiel *Mitgliedsbeitrag 25 € auf dem
Girokonto*:

| Soll (Aufwand/Aktiv) | Haben (Ertrag/Passiv) | Betrag |
|---|---|---|
| 1200 Bank | 4000 Mitgliedsbeiträge | 25 € |

Dadurch bleibt die Buchhaltung immer in sich stimmig: Die Summe aller
Soll-Buchungen gleicht der Summe aller Haben-Buchungen, und der
Geldkontostand stimmt am Ende mit dem Bankauszug überein. Wer das Prinzip
noch nie bewusst angewandt hat, kann beruhigt sein: **Der Einfach-Modus der
App nimmt Ihnen die Soll-/Haben-Denke ab** – Sie sagen nur noch „Einnahme
Mitgliedsbeitrag auf Girokonto", und die App baut den korrekten Satz.

**Was die App nicht ist:** keine Lohn- oder Anlagenbuchhaltung, keine
Mitgliederverwaltung, kein Steuerprogramm. Sie ist das Herzstück der
Vereinsfinanzen – Konten, Buchungen, Belege, Auswertungen – und das
prüffähig dokumentiert.

---

## 2. Ersteinrichtung (einmalig)

Die Ersteinrichtung macht einmal eine **Verwalterin oder ein Verwalter**.
Ohne diese Rolle ist nur Lesen oder Buchen möglich (siehe Anhang 13.1).

### 2.1 Berechtigungen vergeben

Zahnrad-Symbol (Einstellungen) → Abschnitt **Berechtigungen**. Dort werden
Nextcloud-Nutzer oder -Gruppen mit einer Rolle ausgestattet:

- **Verwalter** – darf alles, inkl. Berechtigungen, Jahresabschluss, Alle-Daten-löschen.
- **Buchhalter** – liest und schreibt Buchungen, Belege, Zuordnungen.
- **Revisor** – darf nur lesen (für die Kassenprüfung).

> **Hinweis:** Nextcloud-Administratoren sind *immer* Verwalter, unabhängig
> von dieser Liste. In der Regel genügen zwei Verwalter und beliebig viele
> Buchhalter; die Kassenprüfer bekommen die Rolle „Revisor".

### 2.2 Kontenrahmen anlegen

Es gibt zwei Wege:

**Weg A – aus „zero Buchhaltung" importieren (empfohlen, wenn vorhanden):**
Einstellungen → *Aus „zero Buchhaltung" (.xbuc)*. Das übernimmt den
kompletten Kontenbaum inkl. Hierarchie und der bisherigen Buchungen.
Details siehe Kapitel 3.1.

**Weg B – Standard-Kontenrahmen oder manuell:**
Tab **Konten** → Button *Standard-Kontenrahmen anlegen* erzeugt einen
erprobten Rahmen (Bank, Kasse, Mitgliedsbeiträge, Spenden, Versicherungen
…). Einzelne Konten lassen sich anschließend anlegen, umbenennen oder
verschieben (Über-/Unterkonten über das Feld „Übergeordnet").

Jedes Konto hat:
- eine **Nummer** (frei, z. B. 1200),
- einen **Namen**,
- einen **Typ** (Einnahmen, Ausgaben, Anlage/Umlauf, Verbindlichkeit,
  Eigenkapital),
- ggf. das Flag **Bankkonto** (für Geldkonten – nur diese kumulieren über
  die Jahresgrenze),
- einen **Eröffnungssaldo** (Anfangsbestand, z. B. der Kontostand zum
  01.01.).

### 2.3 Eröffnungssalden eintragen

Wer nicht frisch bei 0 € beginnt, trägt die Anfangsbestände der Geldkonten
(Giro, Tagesgeld, Kasse) als Eröffnungssaldo ein – Tab *Konten* → Konto
anklicken → *Eröffnungssaldo*. Die App erzeugt daraus automatisch die
Eröffnungsbuchung gegen das Eigenkapitalkonto. Damit stimmt der Bestand
vom ersten Tag an.

> **Achtung:** Der Eröffnungssaldo wirkt sich auf den Bestand aus. Wer
> später von einer echten Buchhaltungsdatei (xbuc) importiert, sollte die
> Salden *nicht* doppelt anlegen – der Import bringt sie mit.

### 2.4 Belegablage einrichten (Verwalter)

Einstellungen → *Belegablage*: Belege können entweder **intern** (nur über
die App sichtbar) oder in einem **Ordner eines Nextcloud-Nutzers** (z. B.
„Vereinsbuchhaltung/Belege") liegen. Die Ordner-Variante ist
empfehlenswert, weil die Belege dann auch direkt in Nextcloud durchsuchbar
sind. Speicherort später zu ändern ist möglich, wirkt aber nur auf neue
Belege.

### 2.5 Verein benennen (Verwalter)

Einstellungen → *Verein* → Vereinsname eintragen. Er erscheint im Kopf des
Kassenberichts (Kapitel 7). Kleine Sache, großer Effekt auf der
Mitgliederversammlung.

### 2.6 Corporate Design (optional)

Einstellungen → *Corporate Design*: ein **Vereinslogo** (PNG, JPG oder
WebP) hochladen und eine **Akzentfarbe** wählen. Beides erscheint
automatisch im **Kurzbericht für Vorstandssitzungen** (Kapitel 7.3) – der
Kassenbericht selbst bleibt bewusst schlicht/neutral. Ganz optional: ohne
Logo funktioniert der Kurzbericht genauso gut, nur ohne Wiedererkennung.

---

## 3. Daten ins System bringen

### 3.1 xbuc-Import aus „zero Buchhaltung"

Wer bisher mit *zero Buchhaltung* gearbeitet hat, kann Konten und Buchungen
komplett übernehmen: Einstellungen → *Aus „zero Buchhaltung" (.xbuc)* →
Datei wählen.

- **Merge-Modus (Standard):** Nur fehlende Konten werden angelegt,
  bereits vorhandene Buchungen werden per Fingerprint erkannt und
  übersprungen. So lassen sich mehrere Jahres-Dateien **nacheinander**
  importieren, ohne Duplikate zu erzeugen.
- **Geschäftsjahr:** wird aus der Datei übernommen oder manuell gewählt.
  Buchungen außerhalb des Jahres werden gemeldet und können auf den
  01.01./31.12. datiert werden.
- **Anfangsbestände** beim Mehrjahres-Import: werden erkannt und
  übersprungen, wenn sie schon durch Vorjahresbuchungen abgedeckt sind –
  bei Abweichungen warnt die App.
- **Reset-Modus** („Vorher alle Daten löschen", nur Verwalter): ersetzt
  alle Daten komplett. **Vorsicht:** unwiderruflich (siehe 12.1).

> **Wichtig:** Der Merge-Import blockiert, wenn ein betroffenes Jahr
> bereits **abgeschlossen** ist (Kapitel 8). Ein abgeschlossenes Jahr ist
> festgeschrieben und darf nicht mehr verändert werden – auch nicht per
> Import.

### 3.2 CSV-CAMT-Import (Bankumsätze)

Für die laufenden Umsätze: Tab **Buchungen** → *Umsätze importieren* →
CSV-Datei der Bank hierher ziehen oder wählen. Unterstützt werden die
gängigen deutschen Bank-CSV-Formate (Sparkasse, Volksbank/VR-NetWorld …);
Trennzeichen und Zeichensatz werden automatisch erkannt.

- **Dublettenprüfung:** bereits importierte Buchungen werden automatisch
  erkannt (SHA-256-Hash) – auch gegen zuvor per xbuc importierte. Man kann
  dieselbe Datei also gefahrlos erneut laden.
- **0-€-Buchungen** (z. B. ABSCHLUSS) und bank-interne Buchungen werden
  sinnvoll behandelt (übersprungen bzw. buchbar gelassen).
- Nach dem Import erscheint eine Vorschau mit *neu* / *Dubletten* / *gesamt*.

Die importierten Umsätze landen im Tab **Buchungen → Zuzuordnen** und
warten dort auf ihre Zuordnung (Kapitel 4.1).

> **Hinweis:** Der CSV-Import erzeugt *noch keine* Buchungssätze, sondern
> nur die rohen Bankumsätze. Erst die **Zuordnung** zu einem Gegenkonto
> macht daraus eine Buchung. Das ist Absicht: So bleiben Sie Herr darüber,
> was tatsächlich gebucht wird.

---

## 4. Die laufende Arbeit: buchen und zuordnen

Die meiste Zeit verbringen Sie mit zwei Tätigkeiten: **Bankbuchungen
zuordnen** und **manuelle Buchungen** erfassen (Barausgaben, Überweisungen
ohne CSV, interne Umbuchungen).

### 4.1 Bankbuchungen zuordnen

Tab **Buchungen → Zuzuordnen**. Jede offene Bankbuchung wird einem
**Gegenkonto** zugeordnet – per Dropdown oder (mobil) Auswahl-Sheet. Aus
der Zuordnung entsteht automatisch der Buchungssatz:

- *Geldeingang* (Mitgliedsbeitrag rein): Soll Bank / Haben Mitgliedsbeiträge.
- *Geldausgang* (Versicherung raus): Soll Versicherungen / Haben Bank.

**Erleichterungen:**

- **Zuordnungs-Vorschläge:** Die App schlägt ein Gegenkonto vor, sobald es
  eine passende Regel oder eine bisherige Zuordnung an diesen
  Zahlungspartner gibt. Ein Klick auf „✓ Vorschlag übernehmen" genügt.
- **Auto-Zuordnungsregeln:** Wiederkehrende Buchungen (z. B. „Miete
  Vermieter Müller → 5100 Miete") lassen sich automatisieren. Eine Regel
  kann direkt aus einer bereits gebuchten Transaktion per **Blitz-Button**
  angelegt werden, oder gepflegt unter Einstellungen → *Regeln*. Beim
  CSV-Import können Regeln automatisch angewendet werden (Häkchen
  „Auto-Zuordnungsregeln anwenden").

Wer eine Zuordnung versehentlich vorgenommen hat, kann sie jederzeit wieder
entfernen („– nicht zugeordnet –") – solange das Jahr noch offen ist.

### 4.2 Manuelle Buchungen anlegen

Button **„+ Buchung"** (oben rechts, oder mobil der große „+"-Knopf).

**Einfach-Modus** (Standard): Sie wählen nur *Einnahme* oder *Ausgabe*,
eine **Kategorie** (Wofür? z. B. „Versicherungen") und ein **Geldkonto**
(Bank oder Kasse), dazu Datum, Betrag und Buchungstext. Die App baut den
Soll-/Haben-Satz korrekt zusammen.

**Experten-Modus** (Schalter „Experten-Modus"): Wer Soll und Haben direkt
wählen will – nötig für Buchungen, die nicht eindeutig Einnahme/Ausgabe
sind (z. B. interne Umbuchungen Bank → Tagesgeld, Rückstellungen).

Jeder Buchung lässt sich eine **Belegnummer** zuordnen (z. B. die
Rechnungsnummer) – optional, aber für die Kassenprüfung hilfreich.

### 4.3 Belege anhängen

An jede Buchung lassen sich **Belege** anhängen (PDF, JPG, PNG, GIF, WebP;
max. 20 MB pro Datei). Drei Wege:

- **Beim Anlegen** (mobil): direkt über „Fotografieren" mit der Kamera.
- **Nachträglich:** Buchung öffnen (Stift-Symbol) → Bereich *Belege* →
  „Anhängen".
- **Mehrere Dateien** gleichzeitig sind möglich.

Der **Büroklammer-Indikator** in der Buchungsliste zeigt sofort, ob und
wie viele Belege vorliegen – fehlende Belege sind so auf einen Blick
sichtbar (wichtig für Kapitel 9).

> **Tipp:** Gewöhnen Sie sich an, Belege *sofort* beim Buchen
> anzuhängen. Nachträgliches Zusammensuchen ist der häufigste
> Zeitfresser vor der Kassenprüfung.

### 4.4 Buchungen korrigieren und löschen

Solange das Jahr **offen** ist, lassen sich Buchungen jederzeit ändern
(Stift-Symbol) oder löschen (Papierkorb). Bei der Bearbeitung zeigt die App
jederzeit den aktuellen Stand – hat zwischenzeitlich eine andere Person
dieselbe Buchung geändert, erscheint eine Konfliktmeldung statt einer
stillen Überschreibung (Kapitel 10). Splittbuchungen (mehrere Soll-/Haben-
Zeilen, z. B. aus xbuc-Import) können derzeit **angezeigt, aber nicht
bearbeitet** werden – die App warnt dann.

### 4.5 Offene Posten (unbezahlte Forderungen)

Tab **Buchungen → Offene Posten**. Eine schlanke Liste für Forderungen, die
noch nicht bezahlt sind – z. B. ein ausstehender Mitgliedsbeitrag oder eine
gestellte Rechnung. **Wichtig: Das ist keine Mitgliederverwaltung** – der
Debitor (Name der zahlungspflichtigen Person oder Stelle) wird als
Freitext eingetragen, es gibt keine Mitglieder-Stammdaten dahinter.

Ein neuer Posten braucht: **Debitor**, **Betrag**, optional **Fälligkeit**
und ein **Konto** (für die spätere Buchung). Ist die Fälligkeit
überschritten, markiert die App den Posten als „überfällig" – das
Dashboard zeigt dann zusätzlich eine Kachel „Überfällige offene Posten"
mit Direktsprung zur Liste.

Sobald das Geld eingegangen ist, wird der Posten manuell **als bezahlt
markiert** (Button „Bezahlt"). Der eigentliche Zahlungseingang wird wie
gewohnt als Bankbuchung importiert und zugeordnet (Kapitel 4.1) oder
manuell gebucht (Kapitel 4.2) – die App gleicht offene Posten aktuell
**nicht automatisch** gegen Bankbuchungen ab. Ein Posten lässt sich auch
**stornieren** (erledigt sich anders, z. B. Beitragsbefreiung) oder bei
Bedarf **wieder öffnen**.

---

## 5. Auswertungen verstehen

Alle Auswertungen beziehen sich auf das **im Header gewählte
Geschäftsjahr** (Kalenderjahr; „Alle Jahre" ist möglich). Bestandskonten
(Bank, Kasse) zeigen den kumulierten Kontostand, Erfolgskonten
(Einnahmen/Ausgaben) nur die Bewegung des gewählten Jahres.

### 5.1 Übersicht (Dashboard)

KPI-Kacheln: **Einnahmen**, **Ausgaben**, **Ergebnis** des Jahres – jeweils
mit Vorjahresvergleich. Dazu ein Hinweis auf *nicht zugeordnete*
Bankbuchungen („Jetzt zuordnen" springt direkt dorthin) und ein monatliches
Einnahmen-/Ausgaben-Diagramm. Das Dashboard ist der erste Blick nach dem
Login: stimmt alles grob?

### 5.2 Saldenliste

Tab **Berichte → Auswertung**. Listet alle Konten mit Soll, Haben und
Saldo – hierarchisch, optional inklusive Unterkonten. Hier sehen Sie auf
einen Blick, was im Jahr auf jedem Konto passiert ist. Auch als CSV
exportierbar.

### 5.3 Kontoauszug

Auf jedes Konto klicken (in der Saldenliste oder im Konten-Tab) zeigt den
**Kontoauszug**: jede Buchung mit laufendem Saldo und Saldovortrag vom
Jahresanfang. Ideal, um einen einzelnen Bank- oder Kassenbestand gegen den
Bankauszug abzugleichen.

### 5.4 Kostenstellen

Tab **Berichte → Kostenstellen**. Einnahmen, Ausgaben und das Ergebnis je
**Kostenstelle** (z. B. Abteilungen, Projekte, Veranstaltungen) mit
Drilldown bis zu den einzelnen Buchungen. Zwei Gruppierungsmodi
(Einstellungen → *Kostenstellen*): 2. Zahlengruppe der Kontonummer oder
jedes Konto als eigene Kostenstelle. Namen lassen sich direkt hier
anpassen.

### 5.5 Geldkonten-Abstimmung

Im Dashboard und in der Auswertung: **Kontostand** (aus dem Journal) vs.
**offene** (noch nicht zugeordnete) Bankbuchungen. So erkennen Sie sofort:
„Mein Bankkontostand stimmt, aber es gibt noch X € unzugeordnete Umsätze,
die ich noch bearbeiten muss."

### 5.6 Steuerliche Sphären

Gemeinnützige Vereine müssen ihre Einnahmen und Ausgaben in bis zu vier
steuerliche Sphären trennen. Davon hängt ab, ob Steuern anfallen und ob die
Gemeinnützigkeit selbst gefährdet ist. Die App hilft, diese Trennung
sichtbar zu machen – **sie ersetzt keine steuerliche Beratung.**

| Sphäre | Beispiele | Steuerlich |
|---|---|---|
| **Ideeller Bereich** | Mitgliedsbeiträge, echte Spenden, Zuschüsse ohne Gegenleistung | nicht steuerbar |
| **Vermögensverwaltung** | Zinsen, Mieteinnahmen aus Vereinsräumen, Erträge aus Geldanlagen | grundsätzlich nicht steuerbar |
| **Zweckbetrieb** | Eintritt zu Konzerten/Sportveranstaltungen, Kursgebühren | steuerbegünstigt trotz „Geschäft" |
| **Wirtschaftlicher Geschäftsbetrieb** | Vereinsgaststätte, Werbung mit Gegenleistung, Warenverkauf | grundsätzlich steuerpflichtig oberhalb der Freigrenze |

**Zuordnen:** Im Konto-Dialog (Tab Konten) gibt es das Feld „Steuerliche
Sphäre" – für alle Einnahmen-/Ausgaben-Konten (Geldkonten und Eigenkapital
sind ausgenommen). Für viele Konten auf einmal: Einstellungen →
„Steuerliche Sphären" bietet eine Mehrfachauswahl mit Namensvorschlägen.

**Auswerten:** Tab Berichte → „Sphären" zeigt Einnahmen/Ausgaben/Ergebnis je
Sphäre inkl. eines Buckets „nicht zugeordnet". Der Kassenbericht enthält
denselben Abschnitt, die Mehrjahresübersicht eine zusätzliche Matrix.

> **Freigrenze wirtschaftlicher Geschäftsbetrieb:** aktuell 45.000 €
> Bruttoeinnahmen pro Jahr (§ 64 Abs. 3 AO, Stand seit 2020) – als Summe über
> alle wirtschaftlichen Aktivitäten zusammen. Wird sie überschritten, wird
> der **gesamte** wirtschaftliche Geschäftsbetrieb steuerpflichtig, nicht nur
> der übersteigende Teil. Das Dashboard zeigt eine Ampel (grün/gelb/rot),
> sobald es Einnahmen im wirtschaftlichen Geschäftsbetrieb gibt.

### 5.7 Rücklagen

Gemeinnützige Vereine dürfen (und sollen) einen Teil ihrer Mittel als
**Rücklage** zurücklegen, statt alles im selben Jahr auszugeben (§ 62 AO).
Die App unterscheidet drei Arten:

| Rücklagen-Art | Zweck |
|---|---|
| **Freie Rücklage** | allgemeine Reserve, gesetzlich begrenzt zulässig |
| **Zweckgebundene Rücklage** | für ein konkretes, noch nicht umgesetztes Vorhaben (z. B. „Rücklage Vereinsheim-Sanierung") |
| **Wiederbeschaffungsrücklage** | für den absehbaren Ersatz von Anlagegütern (z. B. Vereinsbus) |

**Einrichten:** Für die Rücklage wird ein eigenes **Eigenkapital-Konto**
angelegt (Tab Konten → Typ „Eigenkapital") und im Konto-Dialog die
gewünschte **Rücklagen-Art** ausgewählt.

**Zuweisen:** Es gibt dafür keinen eigenen Knopf – eine Rücklagenzuweisung
ist eine ganz normale Buchung im **Experten-Modus** (Kapitel 4.2): Soll das
Rücklage-Konto, Haben das Konto, von dem die Mittel kommen (meist das
allgemeine Eigenkapitalkonto).

**Auswerten:** Tab Berichte → „Rücklagen" zeigt den aktuellen Saldo je Art
sowie die beteiligten Konten – so ist auf einen Blick sichtbar, wie viel
bereits zurückgelegt wurde.

---

## 6. Finanzplan (Budget)

Tab **Berichte → Finanzplan**. Für jedes Einnahmen- und Ausgabenkonto
lässt sich ein **Planbetrag** je Jahr eintragen. Die App zeigt daneben den
**Ist-Wert** und die farbige **Abweichung** – so sehen Sie frühzeitig, ob
z. B. die Versicherungen über dem Plan liegen.

- **Notiz je Planzahl:** Herleitung festhalten, z. B. „40 Mitglieder ×
  25 €". Macht den Plan nachvollziehbar und bei der MV verteidigbar.
- **Plan-Stände (Snapshots):** Den kompletten Plan als benannten, datierten
  Stand einfrieren – typischerweise „Beschluss MV". Später lässt sich der
  eingefrorene Stand mit dem aktuellen Plan vergleichen, etwa wenn der
  Plan im Laufe des Jahres angepasst wurde.

---

## 7. Berichte, Exporte und der Kassenbericht

### 7.1 CSV-Exporte

In den Tabs **Buchungen** und **Berichte** gibt es jeweils
Download-Buttons (Pfeil-nach-unten-Symbol):

- **Journal** (alle Buchungssätze des Jahres)
- **Saldenliste**
- **Einnahmen-/Ausgaben-Übersicht**
- **Soll-Ist-Vergleich** (Finanzplan, inkl. Notizen)
- **Mehrjahresübersicht** (Matrix: Erfolgsrechnung + Vermögen + Kostenstellen
  über alle Jahre)

Die CSV-Dateien eignen sich für die Weitergabe an Steuerberatung oder
Kassenprüfung oder für die eigene Analyse in Excel. Format: Semikolon-
getrennt, UTF-8 mit BOM (Excel-tauglich), deutsches Zahlenformat.

> **Mehrjahres-Trend als Diagramm:** In Berichte → Auswertung zeigt ein
> Liniendiagramm Einnahmen, Ausgaben und Ergebnis über alle Jahre – auf
> einen Blick statt als Tabelle. Praktisch für die Präsentation vor dem
> Vorstand oder der Mitgliederversammlung.

### 7.2 Kassenbericht (druckfertig)

Tab **Berichte → Auswertung** → Button **„Kassenbericht"** (nur bei
gewähltem Jahr). Öffnet eine eigene, druckoptimierte Seite mit:

- Vereinsname, Jahr und Erstellungsdatum
- **Vermögensübersicht** der Geldkonten (Bestand 01.01. und 31.12. sowie
  Veränderung)
- **Einnahmen-/Ausgaben-Rechnung** nach Konten mit Summen und Jahresergebnis
- **Soll-Ist-Vergleich**, sofern Planwerte existieren
- **Vollständigkeitshinweis** (Buchungszahl, Nummernkreis,
  Lücken-/Dublettenprüfung)
- **Abschlussvermerk** („abgeschlossen am … von …" bzw. „noch nicht
  abgeschlossen")
- Unterschriftszeilen für Schatzmeister/in und Kassenprüfer/in

Drucken oder „Als PDF speichern" über den Browser (**Strg+P** bzw. **⌘+P**
auf dem Mac). Dieser Bericht ist das Dokument für die
Mitgliederversammlung.

### 7.3 Kurzbericht für Vorstandssitzungen (druckfertig)

Tab **Berichte → Auswertung** → Button **„Kurzbericht"**. Anders als der
Kassenbericht (Kapitel 7.2, immer ein volles Kalenderjahr) bezieht sich der
Kurzbericht auf einen frei wählbaren **Zeitraum „seit …"** – typischerweise
seit der letzten Vorstandssitzung. Die App merkt sich das zuletzt gewählte
Datum geräte-lokal als Vorschlag für das nächste Mal.

Inhalt: Kontostände der Geldkonten zum Stichtag und heute, Bewegungen seit
dem Stichtag (Einnahmen/Ausgaben/Ergebnis) sowie eine kurze
Finanzplan-Kurzfassung des laufenden Jahres (Plan vs. bisheriges Ist). Ist
unter Einstellungen → *Corporate Design* (Kapitel 2.6) ein Logo und eine
Akzentfarbe hinterlegt, erscheinen beide automatisch im Kopf des Berichts.
Wie beim Kassenbericht: Drucken oder „Als PDF speichern" über den Browser.

---

## 8. Jahresabschluss und Festschreibung

Ein Kernstück für eine saubere Vereinsbuchhaltung: ein **abgeschlossenes**
Geschäftsjahr ist **festgeschrieben** – seine Buchungen, Belege und
Zuordnungen können danach nicht mehr geändert oder gelöscht werden. So
bleibt das, was die Mitgliederversammlung entlastet hat, unveränderlich.

### 8.1 Jahr abschließen

Einstellungen → *Jahresabschluss* (nur Verwalter). Liste aller Jahre mit
Status. Bei Bedarf „Abschließen" bestätigen. Das Jahr ist danach mit einem
🔒 im Jahres-Dropdown markiert.

### 8.2 Was gesperrt ist – und was nicht

Nach dem Abschluss sind im betreffenden Jahr **nicht mehr möglich**:
Buchungen anlegen/ändern/löschen, Bankbuchungen zuordnen oder Zuordnungen
entfernen, Belege anhängen oder löschen, Eröffnungssalden ändern, der
xbuc-Import (Merge). Die App zeigt abgeschlossene Buchungen nur noch
lesend an; Schreibversuche werden mit einer klaren Meldung abgewiesen.

**Möglich bleibt:** alles Lesen, alle Auswertungen, Exporte und der
Kassenbericht. Auch der CSV-Import *roher* Bankumsätze geht weiterhin –
erst die Zuordnung wäre gesperrt.

### 8.3 Jahr wiedereröffnen (Ausnahmefall)

Nur Verwalter, nur in Ausnahmefällen (z. B. Korrektur vor der
Kassenprüfung). Der Vorgang wird im **Änderungsprotokoll** festgehalten.
Im Normalfall schließt man ein Jahr endgültig ab.

### 8.4 Wann abschließen?

Typischer Reihenfolge:

1. Alle Bankumsätze des Jahres importiert und zugeordnet.
2. Belege vollständig (Kapitel 9.1 prüfen).
3. Kassenprüfung durchgeführt.
4. **Erst dann** das Jahr abschließen – meist kurz nach der MV, in der
   Entlastung erteilt wurde.

> **Empfehlung:** Schließen Sie das *vorletzte* Jahr ab, sobald die
> Kassenprüfung vorliegt, und lassen Sie das laufende sowie das direkt
> zurückliegende Jahr offen, bis die MV entlastet hat.

---

## 9. Kassenprüfung vorbereiten und begleiten

Die App unterstützt die Kassenprüfung gezielt. Kassenprüfer bekommen die
Rolle **Revisor** (nur Lesen) und können alles einsehen, ohne versehentlich
etwas zu verändern.

### 9.1 Vor der Prüfung: Vollständigkeit herstellen

- **Filter „nur ohne Beleg"** im Tab Buchungen (Journal): zeigt alle
  Buchungen ohne angehängten Beleg. Diese vorab zu klären spart in der
  Prüfung Rückfragen.
- **Lückenprüfung:** Über dem Journal erscheint automatisch ein
  Warnhinweis, falls Buchungsnummern fehlen oder doppelt sind. Im
  Kassenbericht steht dasselbe als Vollständigkeitszeile. In einem offenen
  Geschäftsjahr hält die App die Nummerierung selbst lückenlos (gelöschte
  Buchungen lassen die nachfolgenden Nummern aufrücken); ein Hinweis hier
  bedeutet also, dass am Datenbestand vorbei etwas verändert wurde.
- **Offene Bankbuchungen:** Dashboard → „nicht zugeordnet" – sollte vor
  der Prüfung bei 0 stehen.

### 9.2 Während der Prüfung: alles griffbereit

- **Kassenbericht** drucken (Kapitel 7.2) – die Grundlage der Prüfung.
- **Beleg-ZIP** (Button „Beleg-ZIP" in Berichte → Auswertung): lädt alle
  Belege des Jahres als ZIP herunter, ein Ordner je Buchung
  (`NNNN_Datum_Beschreibung/`). So lassen sich Belege sortiert
  durchblättern, ohne die App. Fehlende Dateien werden in einer
  `fehlende_dateien.txt` vermerkt statt den Export abzubrechen.
- **Kontoauszüge** für die Geldkonten zum Abgleich mit den Bankauszügen.
- **Änderungsprotokoll** (Tab Berichte → **Protokoll**): wer hat wann was
  geändert – Buchungen, Zuordnungen, Belege, Berechtigungen,
  Jahresabschlüsse. Sichtbar für alle Leseberechtigten. Das Protokoll
  übersteht bewusst auch „Alle Daten löschen" – es ist die
  manipulationssichere Chronik.

### 9.3 Nach der Prüfung

Protokoll ggf. mit den Kassenprüfern gemeinsam durchgehen. Bei
Beanstandungen: Jahr noch offen lassen, korrigieren, dann abschließen
(Kapitel 8). Bei Entlastung: Jahr abschließen.

---

## 10. Mehrere Personen an der Buchhaltung (Kollaboration)

Mehrere Personen können **gleichzeitig** an derselben Buchhaltung arbeiten
– alle berechtigten Nutzer sehen denselben Datenbestand. Typisches
Szenario: Schatzmeisterin bucht, eine Stellvertreterin ordnet parallel zu.

**So funktioniert die Abstimmung:**

- Die App prüft alle 20 Sekunden (und bei jedem Aktivwerden des
  Browser-Fensters), ob sich etwas geändert hat. Ist das der Fall, wird die
  eigene Ansicht automatisch aktualisiert – mit einem Hinweis, falls eine
  *andere* Person geändert hat.
- Eigene Änderungen aktualisieren die Ansicht still, ohne Hinweis.
- **Optimistisches Locking:** Bearbeiten zwei Personen *dieselbe* Buchung
  gleichzeitig, gewinnt die erste Speicherung. Die zweite erhält eine
  Konfliktmeldung („zwischenzeitlich von einer anderen Person geändert")
  und kann die Buchung neu öffnen – es geht **keine** Änderung verloren,
  nichts wird still überschrieben.

> **Praxis-Tipp:** Große Aktionen (kompletter Jahres-Import, Alle-Daten-
> löschen) besser kurz mit den anderen abstimmen – die App synchronisiert
> zwar, aber Zwischenstände können verwirren.

---

## 11. Unterwegs: die App auf dem Smartphone

Auf Mobilgeräten (bis 640 px Breite) schaltet die App automatisch in eine
**touch-optimierte Ansicht**:

- **Untere Navigationsleiste** mit den Haupt-Tabs und einem zentralen
  **„+"-Knopf** für neue Buchungen.
- **Karten statt Tabellen:** Journal (nach Monaten gruppiert), Bankbuchungen,
  Saldenliste, Kostenstellen und Kontoauszug erscheinen als Karten.
  Konten und Kostenstellen haben eine Listen-/Detail-Ansicht mit
  „‹ Zurück"-Leiste.
- **Auswahl-Sheet für Konten/Kategorien:** statt Dropdown öffnet ein
  durchsuchbares Sheet von unten. Es merkt sich die **„zuletzt verwendeten"**
  Konten (max. 5, gerätelokal) und schlägt Zuordnungen vor. Nach unten
  wischen schließt es.
- **Schnellerfassung:** großes Betragsfeld, native Datumswahl, und Belege
  direkt über die **Kamera** fotografieren – ideal für die Quittung an der
  Tankstelle oder im Supermarkt.

Die Desktop-Ansicht bleibt davon unberührt; die Daten sind auf beiden
Wegen dieselben. Alles, was nicht primär mobil gebraucht wird
(Kontenrahmen pflegen, Finanzplan, Berechtigungen, Import), ist bewusst
nur am Desktop erreichbar – dort gehört es hin.

---

## 12. Wenn etwas schiefgeht – Hilfe und Sicherheit

### 12.1 „Alle Daten löschen" / Reset

Einstellungen → *Alle Daten löschen* (nur Verwalter, mit
Bestätigungsdialog) entfernt Konten, Buchungen, Importe, Belege und die
Jahresabschluss-Marker. **Das Änderungsprotokoll bleibt erhalten.**
Gleiches gilt für den Reset-Modus beim xbuc-Import. Beides ist
unwiderruflich – also nur nach Rücksprache und nie aus Versehen.

### 12.2 Falsche Buchung – was tun?

Solange das Jahr offen ist: Buchung öffnen (Stift) und korrigieren, oder
löschen und neu anlegen. Bei Konflikten mit einer anderen Person: neu
öffnen und erneut speichern. Ein abgeschlossenes Jahr lässt sich nur nach
Wiedereröffnung (Verwalter, Kapitel 8.3) korrigieren.

### 12.3 Datenbank-Backup vor Updates

Vor jedem Update, das eine Datenbank-Migration enthält, sollte ein
Datenbank-Backup (mysqldump) gemacht werden – das App-Deployment
stellt nur den Programmcode, nicht das Datenbankschema wieder her. Im
Zweifel die verwaltende Person fragen.

---

## 13. Anhang: Rollen, Kontotypen, Tastenkürzel, Glossar

### 13.1 Rollen und Rechte

| Rolle | Lesen | Buchen/Belege | Berechtigungen, Jahresabschluss, Reset |
|---|:---:|:---:|:---:|
| Revisor | ✓ | – | – |
| Buchhalter | ✓ | ✓ | – |
| Verwalter | ✓ | ✓ | ✓ |
| NC-Admin | ✓ | ✓ | ✓ (immer) |

### 13.2 Kontotypen und ihre Bedeutung

| Typ | Bedeutung | Natur | kumulativ? |
|---|---|---|---|
| Einnahmen | Erträge (Mitgliedsbeiträge, Spenden) | Haben | nein (jahresbezogen) |
| Ausgaben | Aufwendungen (Miete, Versicherungen) | Soll | nein (jahresbezogen) |
| Anlage/Umlauf | Vermögen (außer Bank/Kasse) | Soll | nein |
| Verbindlichkeit | Schulden | Haben | nein |
| Eigenkapital | Eigenkapital / Rücklagen | Haben | – |
| Bankkonto (Flag) | Geldkonto (Giro, Tagesgeld, Kasse) | Soll | **ja** (Kontostand) |

„Kumulativ" heißt: Das Konto trägt seinen Bestand über die Jahresgrenze
und zeigt den echten Kontostand, nicht nur die Jahresbewegung. Das betrifft
nur Geldkonten (Bank-Flag).

### 13.3 Tastenkürzel (Desktop)

- **N** – neue Buchung anlegen
- **/** – Suche fokussieren (im Konten-Tab die Baumsuche, sonst die
  Buchungs-Suche)

### 13.4 Glossar

- **Soll / Haben** – die zwei Seiten einer Buchung („wo hin" / „wo her").
- **Gegenkonto** – das Konto, dem eine Bankbuchung zugeordnet wird (die
  „andere Seite" neben dem Bankkonto).
- **Buchungsnummer** – fortlaufende Nummer je Buchung, beginnt jedes
  Kalenderjahr neu bei 1. Wichtig für die Lückenprüfung. Solange ein Jahr
  noch offen ist, sind die Nummern vorläufig: wird eine Buchung gelöscht,
  rücken die nachfolgenden Nummern automatisch auf, damit keine Lücke
  entsteht. Mit dem Jahresabschluss werden sie endgültig und ändern sich
  nicht mehr.
- **Eröffnungssaldo** – Anfangsbestand eines Kontos (z. B. Kontostand zum
  01.01.).
- **Kostenstelle** – eine Gruppierung (Abteilung, Projekt), getrennt
  ausgewiesen.
- **Festschreibung** – ein abgeschlossenes, unveränderliches Geschäftsjahr.
- **Snapshot (Plan-Stand)** – eingefrorener Stand des Finanzplans zu einem
  Zeitpunkt (z. B. „Beschluss MV").
- **Protokoll (Audit-Log)** – manipulationssichere Chronik aller Änderungen.
- **Offener Posten** – eine noch nicht bezahlte Forderung (z. B. Beitrag,
  Rechnung) mit Fälligkeit; keine Buchung, sondern eine Merkliste bis zur
  Zahlung.
- **Rücklage** – zurückgelegte Vereinsmittel (frei, zweckgebunden oder für
  Wiederbeschaffung), als gekennzeichnetes Eigenkapital-Konto geführt.

---

*Stand: App-Version 0.10.67. Bei Fragen an die verwaltende Person wenden.*
