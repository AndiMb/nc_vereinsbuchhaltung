# Handbuch Vereinsbuchhaltung

Ein Praxis-Handbuch für Schatzmeisterinnen und Schatzmeister – von der
Ersteinrichtung bis zum Jahresabschluss. Es beschreibt die App-Version
**0.22.2** und orientiert sich am tatsächlichen Jahresablauf, nicht an
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
13. [Mitgliedsbeiträge und SEPA-Lastschrift](#13-mitgliedsbeitr%C3%A4ge-und-sepa-lastschrift)
14. [Anhang: Rollen, Kontotypen, Tastenkürzel, Glossar](#14-anhang-rollen-kontotypen-tastenk%C3%BCrzel-glossar)

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
Ohne diese Rolle ist nur Lesen oder Buchen möglich (siehe Anhang 14.1).

### 2.0 Der Start-Assistent und die Checkliste

Beim allerersten Öffnen – solange noch kein einziges Konto existiert –
begrüßt Sie ein kleiner **Assistent** mit drei Wegen:

- **„Ich habe Daten aus ‚zero Buchhaltung'"** → öffnet direkt den
  xbuc-Import (Kapitel 3.1).
- **„Ich fange neu an"** → legt den erprobten Standard-Kontenrahmen an
  (Kapitel 2.2, Weg B).
- **„Erst mit Beispieldaten ausprobieren"** → legt einen kompletten
  **Beispielverein** an: Konten, Buchungen, Belege, Planwerte. Alles lässt
  sich gefahrlos durchklicken. Solange die Beispieldaten aktiv sind, steht
  oben ein Banner *„Beispieldaten aktiv"* mit dem Knopf **„Zurücksetzen &
  mit echten Daten starten"** – der räumt alles wieder ab (Kapitel 12.1).

Der Assistent erscheint nur einmal je Gerät; „Überspringen" ist jederzeit
möglich. Danach übernimmt die **Einrichtungs-Checkliste** auf dem Dashboard:
Sie listet die noch offenen Schritte (Verein benennen, Kontenrahmen,
Anfangsbestand, Berechtigungen, erste Buchung, Sphären zuordnen), hakt
Erledigtes automatisch ab und springt per Klick an die richtige Stelle. Wer
sie nicht braucht, blendet sie aus.

> **Tipp:** Die Beispieldaten sind der schnellste Weg, die App kennenzulernen –
> gerade vor der Übergabe an eine Nachfolgerin oder einen Nachfolger im
> Schatzmeisteramt.

### 2.1 Berechtigungen vergeben

Zahnrad-Symbol (Einstellungen) → Abschnitt **Berechtigungen**. Dort werden
Nextcloud-Nutzer oder -Gruppen mit einer Rolle ausgestattet:

- **Verwalter** – darf alles, inkl. Berechtigungen, Jahresabschluss, Alle-Daten-löschen.
- **Buchhalter** – liest und schreibt Buchungen, Belege, Zuordnungen, sowie
  Mitglieder, SEPA-Mandate und den Beitragseinzug (Kapitel 13.2–13.7).
- **Revisor** – darf nur lesen (für die Kassenprüfung).

> **Hinweis:** Nextcloud-Administratoren sind *immer* Verwalter, unabhängig
> von dieser Liste. In der Regel genügen zwei Verwalter und beliebig viele
> Buchhalter; die Kassenprüfer bekommen die Rolle „Revisor".

### 2.2 Kontenrahmen anlegen

Es gibt zwei Wege:

**Weg A – aus „zero Buchhaltung" importieren (empfohlen, wenn vorhanden):**
Zahnrad → *Daten* → *Aus „zero Buchhaltung" (.xbuc)*. Das übernimmt den
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
- bei Geldkonten optional die **IBAN**. Wer nur ein Bankkonto führt, braucht
  sie nicht. Bei mehreren Konten entscheidet sie darüber, auf welchem
  Geldkonto ein importierter Umsatz gebucht wird – ohne sie landet alles auf
  dem ersten Bankkonto des Kontenrahmens. Leerzeichen spielen keine Rolle,
  die App speichert sie einheitlich. Wird das Bankkonto-Kennzeichen wieder
  entfernt, verschwindet auch die IBAN,
- einen **Eröffnungssaldo** (Anfangsbestand, z. B. der Kontostand zum
  01.01.).

> **Konten löschen:** Nur solange auf ihnen **nichts gebucht** ist und sie
> keine Unterkonten haben. Sonst lehnt die App das Löschen mit einer
> Erklärung ab – sonst verschwänden die gebuchten Beträge aus Saldenliste
> und Kassenbericht, ohne dass es jemandem auffiele.
>
> **Konten stilllegen statt löschen:** Für genau diesen Fall gibt es im
> Konto-Dialog den Schalter **„Konto aktiv"**. Ein Konto, das Sie ausschalten,
> verschwindet aus allen Auswahllisten (Buchen, Zuordnen, Umbuchen) – die
> gebuchten Beträge, alle Berichte und die Historie bleiben unverändert. Im
> Kontenbaum steht es weiterhin, kursiv und mit dem Vermerk *inaktiv*, und
> lässt sich jederzeit wieder einschalten. So räumen Sie Konten aus dem Weg,
> die Sie nicht mehr brauchen, aber auch nicht löschen dürfen.

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

Zahnrad → *Belege*: Belege können entweder **intern** (nur über
die App sichtbar) oder in einem **Ordner eines Nextcloud-Nutzers** (z. B.
„Vereinsbuchhaltung/Belege") liegen. Die Ordner-Variante ist
empfehlenswert, weil die Belege dann auch direkt in Nextcloud durchsuchbar
sind. Speicherort später zu ändern ist möglich, wirkt aber nur auf neue
Belege.

### 2.5 Verein benennen (Verwalter)

Zahnrad → *Verein* → Vereinsname eintragen. Er erscheint im Kopf des
Kassenberichts (Kapitel 7). Kleine Sache, großer Effekt auf der
Mitgliederversammlung.

### 2.6 Corporate Design (optional)

Zahnrad → *Verein* (zweite Karte auf derselben Seite): ein **Vereinslogo**
(PNG, JPG oder WebP) hochladen und eine **Akzentfarbe** wählen. Beides erscheint
automatisch im **Kurzbericht für Vorstandssitzungen** (Kapitel 7.3) – der
Kassenbericht selbst bleibt bewusst schlicht/neutral. Ganz optional: ohne
Logo funktioniert der Kurzbericht genauso gut, nur ohne Wiedererkennung.

---

## 3. Daten ins System bringen

### 3.1 xbuc-Import aus „zero Buchhaltung"

Wer bisher mit *zero Buchhaltung* gearbeitet hat, kann Konten und Buchungen
komplett übernehmen: Zahnrad → *Daten* → *Aus „zero Buchhaltung" (.xbuc)* →
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

### 3.2 Kontoauszüge importieren (Bankumsätze)

Für die laufenden Umsätze: Tab **Buchungen** → *Umsätze importieren* →
Datei der Bank hierher ziehen oder wählen.

**Welches Format?** Die App erkennt drei und bestimmt das Format am Inhalt –
die Dateiendung ist ihr egal:

| Format | Im Onlinebanking meist so benannt | Empfehlung |
|---|---|---|
| **CSV-CAMT** | „CSV-CAMT-Format", „Umsätze als CSV" | funktioniert, aber jede Bank baut die Spalten anders |
| **CAMT.053** (XML) | „CAMT", „ISO 20022", „XML" | **beste Wahl**, wenn angeboten |
| **MT940** | „MT940", „SWIFT", Datei endet oft auf `.sta` | ebenfalls gut |

Warum CAMT.053 die beste Wahl ist: dort sind Vorzeichen, Datum und
Zahlungsbeteiligte eindeutig ausgezeichnet. Bei der CSV muss die App die
Spalten anhand ihrer Überschriften erraten – das klappt bei den gängigen
Instituten, aber eben nicht mit Sicherheit.

> **Mehrere Bankkonten?** Dann kommt es zusätzlich darauf an, dass der Auszug
> die **IBAN** des Kontos mitbringt – nur daran erkennt die App, auf welchem
> Geldkonto zu buchen ist (Kapitel 2.2). CAMT.053 führt sie immer, MT940
> häufig nur die Kontonummer, und die CSV je nach Institut. Im Zweifel
> CAMT.053 nehmen.

- **Dublettenprüfung:** bereits importierte Buchungen werden automatisch
  erkannt – auch gegen zuvor per xbuc importierte und **auch über
  Formatgrenzen hinweg**. Man kann dieselbe Datei also gefahrlos erneut laden,
  und ebenso denselben Auszug einmal als CSV und einmal als CAMT.
- **Vorgemerkte Umsätze** (in CAMT als „PDNG", in der CSV an „Umsatz
  vorgemerkt" in der Spalte *Info* erkennbar) werden übersprungen. Sie ändern
  sich beim endgültigen Buchen oft noch – sie jetzt zu übernehmen hieße, sie
  später ein zweites Mal zu bekommen.
- **0-€-Buchungen** (z. B. ABSCHLUSS) und bank-interne Buchungen werden
  sinnvoll behandelt (übersprungen bzw. buchbar gelassen).
- **Sammelbuchungen** (eine Lastschrifteinreichung mit vielen Einzelposten)
  bleiben *eine* Buchung – so, wie die Bank sie auch gebucht hat. Im
  Verwendungszweck steht ein Hinweis auf die Zahl der Posten.
- Nach dem Import erscheint eine Vorschau mit *neu* / *Dubletten* / *gesamt*.

### 3.3 Kontoauszüge automatisch einlesen (Wachordner)

Wer jeden Monat dasselbe tut, kann sich den Upload sparen: Zahnrad →
*Bankdaten* (nur Verwalter). Dort werden ein
Nextcloud-Nutzer und ein Ordner in dessen Dateien eingetragen, zum Beispiel
`Vereinsbuchhaltung/Kontoauszüge`.

Ab dann genügt es, den im Onlinebanking heruntergeladenen Auszug in diesen
Ordner zu legen – auch vom Handy oder direkt aus der Nextcloud-App heraus.
Stündlich sieht die App nach und liest neue Dateien ein. Anschließend
wandert die Datei nach `verarbeitet/`; ließ sie sich nicht lesen, nach
`fehler/`, mit einer Textdatei daneben, die den Grund nennt. **Gelöscht wird
nichts.**

> **Was das nicht ist:** ein Abruf bei der Bank. Den Auszug herunterladen
> muss weiterhin ein Mensch – die App holt ihn sich nicht selbst.

> **Voraussetzung:** Die Nextcloud-Instanz muss Hintergrundaufgaben per
> **System-Cron** ausführen (Verwaltung → Grundeinstellungen). Steht dort
> „AJAX", laufen sie nur, solange jemand Nextcloud geöffnet hat – der
> Wachordner reagiert dann unzuverlässig. Im Zweifel die verwaltende Person
> fragen.

Der Vorgang steht anschließend im Änderungsprotokoll (Kapitel 9.2), erkennbar
am Akteur „automatisch (Wachordner)".

Die importierten Umsätze landen im Tab **Buchungen → Zuzuordnen** und
warten dort auf ihre Zuordnung (Kapitel 4.1).

> **Hinweis:** Der Import eines Kontoauszugs erzeugt *noch keine*
> Buchungssätze, sondern nur die rohen Bankumsätze – gleich, aus welchem
> Format und ob von Hand oder über den Wachordner. Erst die **Zuordnung** zu
> einem Gegenkonto macht daraus eine Buchung. Das ist Absicht: So bleiben Sie
> Herr darüber, was tatsächlich gebucht wird.

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
  angelegt werden, oder gepflegt im Unterreiter *Regeln* (Tab Buchungen,
  nur Verwalter/Buchhalter). Beim Import
  können Regeln automatisch angewendet werden (Häkchen „Auto-Zuordnungsregeln
  anwenden"); über den Wachordner (Kapitel 3.3) geschieht das immer.

Wer eine Zuordnung versehentlich vorgenommen hat, kann sie jederzeit wieder
entfernen („– nicht zugeordnet –") – solange das Jahr noch offen ist.

**Ein Umsatz, der mehreres zugleich enthält: „Aufteilen…"**

Manchmal steckt in einer einzigen Überweisung mehr als eine Sache – jemand
zahlt seinen Jahresbeitrag und legt eine Spende obendrauf, oder eine Rechnung
gehört zur Hälfte auf zwei Projekte. So ein Umsatz gehört nicht auf *ein*
Gegenkonto.

Klicken Sie in der Zeile auf **„Aufteilen…"**. Es öffnet sich ein Fenster mit
dem Umsatz oben und einer Liste darunter: je Zeile ein Konto und ein
Teilbetrag, „+ Zeile hinzufügen" für weitere. Rechts oben steht immer, wie
viel noch offen ist („Rest: 70,00 €") bzw. „✓ geht auf". **Zuordnen** lässt
sich erst, wenn die Aufteilung aufgeht – so kann kein Betrag verloren gehen.
„Rest übernehmen" schreibt den offenen Betrag in die letzte Zeile.

Beispiel: 250,00 € Eingang von Frau Meier → 180,00 € auf *Mitgliedsbeiträge*,
70,00 € auf *Spenden*. Daraus entsteht **eine** Buchung mit drei Zeilen; im
Kassenbericht und in der Kostenstellenauswertung erscheinen beide Beträge
getrennt.

> Ein aufgeteilter Umsatz zeigt in der Liste „Aufgeteilt auf mehrere Konten"
> statt eines Kontonamens – ein einzelnes Konto gibt es dort ja nicht mehr.
> Für Vorschläge merkt sich die App eine solche Zuordnung bewusst **nicht**:
> ein Vorschlag „Konto X" wäre für einen geteilten Umsatz falsch. Aufheben und
> neu vergeben geht wie sonst auch.

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

**Betrag aufteilen (Splittbuchung):** Der Schalter *Betrag aufteilen* macht
aus der einen Kategorie eine Liste – dasselbe wie beim Zuordnen (Kapitel 4.1),
nur für eine Buchung, die Sie selbst erfassen. Oben steht der **Gesamtbetrag**,
darunter die Aufteilung mit laufender Restanzeige; das Geldkonto bleibt eine
einzige Zeile über den vollen Betrag. Im Experten-Modus können Sie zusätzlich
wählen, **welche Seite** aufgeteilt wird (Soll oder Haben).

> Beim allerersten Öffnen des Buchungsdialogs am Desktop führt eine kurze
> **Drei-Schritte-Tour** durch die wichtigsten Felder (Einnahme/Ausgabe,
> Kategorie, Geldkonto). Sie erscheint nur einmal und lässt sich
> überspringen.

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
stillen Überschreibung (Kapitel 10).

Auch **Splittbuchungen** lassen sich bearbeiten: Der Dialog öffnet sich mit
der bestehenden Aufteilung, Beträge lassen sich verschieben und Zeilen
entfernen oder ergänzen. Gespeichert wird auch hier erst, wenn die Aufteilung
aufgeht. Nur Buchungen, die auf **beiden** Seiten mehrere Konten haben, bleiben
außen vor – die App erzeugt solche nicht, sie könnten allenfalls aus
Fremddaten stammen; die App zeigt sie an und warnt beim Bearbeiten.

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

**Falsch zugeordnete Buchung korrigieren.** Fällt beim Durchsehen auf, dass
eine Buchung auf diesem Konto nicht richtig ist, korrigieren Sie das an Ort
und Stelle – ohne ins Journal zu wechseln. Der Knopf ⇄ am Zeilenende (mobil:
*„Falsch zugeordnet? Auf ein anderes Konto umbuchen…"*) öffnet die
Kontoauswahl. Sind mehrere Seiten beteiligt, wählen Sie zuerst, welche
umgebucht werden soll – voreingestellt ist das gerade geöffnete Konto, das
Gegenkonto steht ebenfalls zur Wahl.

Dabei ändert sich **nur die Kontozuordnung dieser einen Seite**: Betrag,
Datum, Beschreibung, Belege und die Gegenseite bleiben unverändert, Soll und
Haben können also nicht auseinanderlaufen. Buchungen aus einem
abgeschlossenen Geschäftsjahr lassen sich nicht umbuchen, und jede Umbuchung
steht mit Herkunfts- und Zielkonto im **Änderungsprotokoll**.

### 5.4 Kostenstellen

Tab **Berichte → Kostenstellen**. Einnahmen, Ausgaben und das Ergebnis je
**Kostenstelle** (z. B. Abteilungen, Projekte, Veranstaltungen) mit
Drilldown bis zu den einzelnen Buchungen. Namen lassen sich direkt hier
anpassen.

Wie die App die Konten zu Kostenstellen zusammenfasst, entscheidet der
**Kostenstellen-Modus** (im Bericht selbst, oberhalb der Kostenstellen-Liste
bzw. weiter unten in diesem Kapitel beschrieben, nur Verwalter):

| Modus | Kostenstelle ist … | Passt, wenn … |
|---|---|---|
| 2. Zahlengruppe der Kontonummer | die zweite Zahlengruppe, z. B. `111 51 2021` → `51` | der Kontenrahmen die Kostenstelle in der Nummer trägt |
| Jedes Konto eine eigene | das Konto selbst | jedes Einnahmen-/Ausgabenkonto für sich ausgewertet werden soll |
| **Frei definierte Kostenstellen** | die am Konto hinterlegte Kostenstelle | die Kostenstelle sich nicht aus der Kontonummer ergibt |

Der dritte Modus macht keine Annahme über den Kontenrahmen: Kostenstellen
werden direkt hier im Bericht **Berichte → Kostenstellen** angelegt (Kürzel
+ Name) und Konten ihnen ausdrücklich zugeordnet (nur Verwalter/Buchhalter,
Moduswechsel selbst nur Verwalter) – so lassen sich auch Konten mit ganz
unterschiedlichen Nummern zu einem Projekt bündeln. Zuordnen geht auf zwei
Wegen:

- einzeln im **Konto-Dialog** (Tab Konten → Konto bearbeiten → *Kostenstelle*);
  ein neues Unterkonto übernimmt die Kostenstelle seines Überkontos,
- für viele Konten auf einmal unterhalb der Kostenstellen-Liste in
  **Berichte → Kostenstellen** (ankreuzen, Kostenstelle wählen, *Zuweisen*).

Angelegte Kostenstellen erscheinen im Bericht auch dann, wenn ihnen noch kein
Konto zugeordnet ist – so fällt eine vergessene Zuordnung auf. Wird eine
Kostenstelle gelöscht, verlieren ihre Konten nur die Zuordnung; **Buchungen
bleiben unverändert**, eine Kostenstelle trägt selbst keine Beträge.

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
sind ausgenommen). Für viele Konten auf einmal: im Bericht selbst,
**Berichte → „Sphären"** unterhalb der Auswertung, bietet eine
Mehrfachauswahl mit Namensvorschlägen (nur Verwalter/Buchhalter).

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
  + steuerliche Sphären über alle Jahre)

Die CSV-Dateien eignen sich für die Weitergabe an Steuerberatung oder
Kassenprüfung oder für die eigene Analyse in Excel. Format: Semikolon-
getrennt, UTF-8 mit BOM (Excel-tauglich), deutsches Zahlenformat.

> **Splittbuchungen im Journal-Export:** Eine Buchung, deren Betrag auf
> mehrere Gegenkonten verteilt ist, belegt dort mehrere Zeilen – jede mit
> derselben Buchungsnummer und ihrem Teilbetrag. So weist ein Journal
> Splittbuchungen üblicherweise aus; die Summe der Zeilen ergibt den
> Buchungsbetrag.

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
unter Zahnrad → *Verein* (Kapitel 2.6) ein Logo und eine Akzentfarbe
hinterlegt, erscheinen beide automatisch im Kopf des Berichts.
Wie beim Kassenbericht: Drucken oder „Als PDF speichern" über den Browser.

---

## 8. Jahresabschluss und Festschreibung

Ein Kernstück für eine saubere Vereinsbuchhaltung: ein **abgeschlossenes**
Geschäftsjahr ist **festgeschrieben** – seine Buchungen, Belege und
Zuordnungen können danach nicht mehr geändert oder gelöscht werden. So
bleibt das, was die Mitgliederversammlung entlastet hat, unveränderlich.

### 8.1 Jahr abschließen

Zahnrad → *Jahresabschluss* (nur Verwalter). Liste aller Jahre mit
Status. Bei Bedarf „Abschließen" bestätigen. Das Jahr ist danach mit einem
🔒 im Jahres-Dropdown markiert.

### 8.2 Was gesperrt ist – und was nicht

Nach dem Abschluss sind im betreffenden Jahr **nicht mehr möglich**:
Buchungen anlegen/ändern/löschen, Bankbuchungen zuordnen oder Zuordnungen
entfernen, Belege anhängen oder löschen, Eröffnungssalden ändern, der
xbuc-Import (Merge). Die App zeigt abgeschlossene Buchungen nur noch
lesend an; Schreibversuche werden mit einer klaren Meldung abgewiesen.

**Möglich bleibt:** alles Lesen, alle Auswertungen, Exporte und der
Kassenbericht. Auch der Import *roher* Bankumsätze geht weiterhin – erst die
Zuordnung wäre gesperrt.

Gesperrt sind außerdem die **Eigenschaften eines Kontos, die in die Zahlen
eingehen**: Kontoart, Geldkonto-Kennzeichen, Sphäre, Rücklagen-Art und
Kostenstelle. Das betrifft nur Konten, die im abgeschlossenen Jahr auch
tatsächlich bebucht sind. Der Grund: Aus einem Einnahmekonto ein
Ausgabekonto zu machen, dreht das Vorzeichen in allen Auswertungen – der
Kassenbericht des abgeschlossenen Jahres sähe hinterher anders aus, ohne
dass jemand eine Buchung angefasst hätte. **Frei änderbar bleiben** Nummer,
Name, Kategorie, Überkonto und der Aktiv-Schalter; sie ändern nur
Beschriftung und Sortierung. Wer eine gesperrte Eigenschaft doch ändern
muss, eröffnet das Jahr wieder (Kapitel 8.3) und schließt es danach erneut
ab.

> **Für den Wachordner heißt das:** Legt jemand einen Auszug ab, der in ein
> abgeschlossenes Jahr fällt, werden die Umsätze eingelesen, bleiben aber
> unzugeordnet liegen – auch dann, wenn eine Regel greifen würde. Der Auszug
> landet trotzdem in `verarbeitet/`; die Zahl der nicht zugeordneten Umsätze
> vermerkt das Änderungsprotokoll (Kapitel 9.2) beim Eintrag
> „Wachordner-Import".

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
etwas zu verändern. Beim ersten Login mit dieser Rolle erscheint ein kurzer
Willkommenshinweis, der die wichtigsten drei Stellen nennt.

### 9.0 Der Prüfleitfaden zum Mitgeben

Berichte → Auswertung → Button **„Prüfleitfaden"**. Das ist eine
druckfertige **einseitige Kurzanleitung für Kassenprüfer/innen** – mit dem
Vereinsnamen im Kopf, der Erklärung der Revisor-Rolle, den empfohlenen
Prüfschritten und der Angabe, wo was zu finden ist. Ausdrucken oder als PDF
mitgeben (Strg+P bzw. ⌘P) erspart der Prüfperson, sich in dieses ganze
Handbuch einzulesen.

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
  Saldenliste, Kostenstellen, Kontoauszug sowie – wo genutzt – die
  Mitgliederliste und der Einzug (Reiter „Beiträge", Kapitel 13) erscheinen
  als Karten statt als breite Tabelle. Praktisch, um in der Chorprobe oder
  Vorstandssitzung kurz nachzusehen, ob ein Beitrag abgebucht ist. Konten und
  Kostenstellen haben eine Listen-/Detail-Ansicht mit „‹ Zurück"-Leiste.
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

### 12.0 Hilfe direkt in der App

Oben im Kopf sitzt ein **Hilfe-Knopf (?)**. Er öffnet ein kleines
Hilfe-Fenster mit Kurzinfos zum gerade geöffneten Tab (Ersteinrichtung,
Buchen & zuordnen, Konten, Berichte, Beiträge & SEPA, Sphären); in den
einzelnen Ansichten führen ebenfalls Hilfe-Symbole genau dorthin. Von jedem
Kapitel führt ein Link **direkt in dieses Handbuch** – die App liefert es
selbst als lesbare Seite aus, es muss also nichts auf GitHub gesucht werden.

### 12.1 „Alle Daten löschen" / Reset

Zahnrad → *Daten* → *Alle Daten löschen* (nur Verwalter, mit
Bestätigungsdialog) entfernt Konten, Buchungen, Importe, Belege und die
Jahresabschluss-Marker. **Das Änderungsprotokoll bleibt erhalten.**
Gleiches gilt für den Reset-Modus beim xbuc-Import. Beides ist
unwiderruflich – also nur nach Rücksprache und nie aus Versehen.

Derselbe Knopf ist der harmlose Weg aus den **Beispieldaten** heraus
(Kapitel 2.0): Solange das Banner „Beispieldaten aktiv" steht, gibt es
nichts zu verlieren.

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

## 13. Mitgliedsbeiträge und SEPA-Lastschrift

Ein **optionales Zusatzmodul**. Wer Beiträge per Überweisung bekommt oder gar
keine erhebt, kann dieses Kapitel überspringen – ohne angelegtes Mandat
verhält sich die App genau wie bisher.

Mitglieder, Mandate, Beiträge und den Einzug (13.2–13.7) dürfen **Verwalter
und Buchhalter** pflegen – ein Mandat verknüpft zwar eine Person mit ihrer
Bankverbindung, aber das ist keine schwerere Verantwortung als jede andere
Buchung. Die **Grundeinstellungen** (13.1: Gläubiger-ID, einziehendes Konto,
Standardbeitrag, der Schalter für den Reiter) bleiben Verwaltern vorbehalten
– das sind einmalige Weichenstellungen für den ganzen Verein, keine
laufende Arbeit.

### 13.1 Was Sie vorher brauchen

1. Eine **Gläubiger-Identifikationsnummer**. Die vergibt die Deutsche
   Bundesbank kostenlos auf Antrag; sie sieht aus wie `DE98ZZZ09999999999` und
   weist Ihren Verein bei jedem Einzug als Zahlungsempfänger aus.
2. Ein **schriftliches Mandat je Mitglied**. Die App verwaltet die Angaben,
   ersetzt aber nicht die unterschriebene Einzugsermächtigung – die gehört in
   Ihre Unterlagen.
3. Ein **Geldkonto mit hinterlegter IBAN** in der Kontenliste. Auf dieses Konto
   wird eingezogen.

Gläubiger-ID und einziehendes Konto tragen Sie unter *Zahnrad → Beiträge &
SEPA → Grundeinstellungen* ein. Dort steht auch der Schalter, der den
Reiter **„Beiträge"** in der Hauptnavigation einblendet (siehe 13.2) – ist
bereits ein Mandat oder ein Beitrag angelegt, erscheint er automatisch,
auch ohne den Schalter.

> **Zahlen fast alle Mitglieder denselben Beitrag** (z. B. 8 € monatlich, der
> Normalfall bei einem Chor oder Sportverein)? Dann lohnt sich auf derselben
> Seite die Karte **„Standard-Beitrag"**: Betrag und Frequenz einmal
> hinterlegen, und „Mitglied aufnehmen" (13.2) schlägt beides künftig vor,
> statt dass Sie es bei jedem einzelnen Mitglied neu eintippen. Auch beim
> CSV-Import (13.3) greift der Standardsatz, wenn eine Zeile ein Startdatum,
> aber keinen eigenen Betrag hat – abweichende Einzelfälle (Ermäßigung,
> Ehrenmitglied) tragen Sie einfach mit eigenem Betrag ein.

### 13.2 Wo die Bankdaten der Mitglieder stehen

**Es gibt in dieser App keine Mitgliederverwaltung.** Das ist Absicht: eine
Buchhaltung ist keine Mitgliederdatenbank, und die meisten Vereine führen ihre
Mitglieder ohnehin anderswo. Was die App braucht, ist nur das, was zum Geld
gehört.

Ein „Mitglied" besteht hier deshalb aus zwei Angaben, die beide im
**Reiter „Beiträge" → Mitglieder** stehen (Hauptnavigation, nicht das
Zahnrad – das ist laufende Arbeit, keine Einstellung):

| Angabe | Was dort hineingehört |
|---|---|
| **Mandat** | IBAN, optional BIC, E-Mail-Adresse und das Datum, an dem das Mandat unterschrieben wurde |
| **Beitrag** | Betrag, Zahlungsfrequenz und die erste Fälligkeit |

**Die IBAN steht am Mandat, nicht am Mitglied** – ein Mitglied ohne Mandat hat
in der App schlicht keine Bankverbindung. Beides legen Sie über den Knopf
**„＋ Mitglied"** (Reiter „Beiträge" → Mitglieder) in einem Schritt an;
jedes für sich ist ebenfalls möglich:

- **nur Beitrag, keine IBAN** – für Überweiser und Barzahler. Die App legt bei
  Fälligkeit trotzdem einen offenen Posten an, dann eben als Erinnerung.
- **nur Mandat, kein Betrag** – wenn Sie nur gelegentlich etwas einziehen.

Als Zahler wählen Sie entweder einen **Nextcloud-Nutzer** dieser Instanz oder
tragen einen **freien Namen** ein. Das zweite ist der Normalfall: kaum ein
Verein legt für jedes Mitglied ein Nextcloud-Konto an.

> **Tragen Sie die E-Mail-Adresse ein.** Ohne sie kann die App die gesetzlich
> vorgeschriebene Vorankündigung nicht verschicken, und Sie müssen jedes
> Mitglied selbst benachrichtigen. Die Liste weist Sie bei jeder Zeile ohne
> Adresse darauf hin; über *nur Auffälligkeiten* sehen Sie alle auf einmal.

Die **Mandatsreferenz** vergibt die App selbst (etwa `M20260813-2DE3C1`). Sie
erscheint auf dem Kontoauszug des Zahlers – teilen Sie sie ihm zusammen mit dem
Mandatsformular mit.

> Ein Mandat wird **widerrufen, nicht gelöscht**. Bereits erzeugte
> Einreichungen verweisen darauf, und dieser Nachweis muss erhalten bleiben.
> Deshalb verschwindet der Löschen-Knopf, sobald ein Mandat verwendet wurde.

### 13.3 Viele Mitglieder auf einmal aufnehmen

Für einen Chor mit 200 Stimmen ist das Formular der falsche Weg. Nutzen Sie
im Reiter „Beiträge" → Mitglieder den Knopf **„Liste einlesen"**: eine
**CSV-Datei**, eine Zeile je Mitglied.

Erwartet werden diese Spalten – **Reihenfolge und Schreibweise sind egal**, und
zusätzliche Spalten (Mitgliedsnummer, Eintrittsdatum, Stimmlage …) werden
einfach übergangen:

| Spalte | Beispiel | Pflicht? |
|---|---|---|
| Name *oder* Konto | `Katrin Brunner` bzw. `k.brunner` | ja |
| E-Mail | `k.brunner@example.org` | nein, aber dringend empfohlen |
| IBAN | `DE02 1203 0000 0000 2020 51` | nur wenn eingezogen werden soll |
| BIC | meist leer | nein |
| Mandat am | `15.01.2026` | ja, sobald eine IBAN dasteht |
| Betrag | `42,50` | nur wenn ein Beitrag entstehen soll |
| Frequenz | `monatlich` | nein – ohne Angabe gilt **jährlich** |
| Start | `01.02.2026` | ja, sobald ein Betrag dasteht |

Datumsangaben dürfen `15.01.2026` oder `2026-01-15` lauten, Beträge `42,50`
oder `42.50`. Eine **Vorlage** zum Ausfüllen können Sie direkt herunterladen.

> **Standardbeitrag hinterlegt (13.1)?** Dann darf die Betrag-Spalte für
> Zeilen mit demselben Satz leer bleiben – solange ein Startdatum dasteht,
> übernimmt die App automatisch Betrag und Frequenz aus den Einstellungen.
> Nur Sonderfälle brauchen dann noch einen eigenen Betrag.

Der Ablauf ist zweistufig: **„Prüfen"** ändert nichts und zeigt Ihnen für jede
Zeile, was entstehen würde und was nicht stimmt. Erst danach übernehmen Sie.
Fehlerhafte Zeilen werden übersprungen und einzeln benannt – ein Tippfehler in
Zeile 143 macht die 142 Zeilen davor nicht wertlos.

Beanstandet wird unter anderem: ein Zahler, der schon weiter oben in derselben
Datei steht (meist eine kopierte Zeile), eine IBAN, für die es bereits ein
aktives Mandat gibt, und ein Nextcloud-Konto, das es nicht gibt.

### 13.4 Beiträge, Fälligkeit und Rückstand

Bei Fälligkeit erzeugt die App automatisch einen **offenen Posten** – denselben,
den Sie unter *Berichte → Offene Posten* sehen. Nur Posten *mit* Mandat kommen
für den Lastschrifteinzug in Frage.

Liegt die nächste Fälligkeit in der Vergangenheit – etwa weil Sie einen Beitrag
rückwirkend angelegt haben –, holt der Tageslauf **eine Periode pro Tag** nach,
statt auf einen Schlag zwei Jahrgänge Forderungen zu erzeugen. Wie viel noch
aussteht, steht in der Spalte *Nächste Fälligkeit*; über **„Nachholen"**
erzeugen Sie den gesamten Rückstand sofort.

Haben Sie sich schlicht im Startdatum vertan, korrigieren Sie die nächste
Fälligkeit über *Bearbeiten*, statt nachzuholen.

### 13.5 Einzug erzeugen und einreichen

Im Reiter **„Beiträge" → Einzug** wählen Sie den **Fälligkeitstermin**. Die
Vorschau zeigt alle offenen Posten mit aktivem Mandat, die bis dahin fällig
sind und in keinem laufenden Einzug stecken.

> **Legen Sie den Termin mindestens 14 Tage in die Zukunft.** Das SEPA-Regelwerk
> verlangt, dass Sie den Zahler vorher über Betrag und Termin informieren
> („Vorankündigung"). Die App übernimmt das per E-Mail für alle Zahler mit
> hinterlegter Adresse und nennt darin Betrag, Termin, Mandatsreferenz und Ihre
> Gläubiger-ID. Bei kürzerem Vorlauf warnt sie. Zahler ohne Adresse müssen Sie
> selbst informieren – die Zeilenansicht des Einzugs weist Sie darauf hin.

„Einzug erzeugen" legt den Sammeleinzug an; über „XML herunterladen" bekommen
Sie die **pain.008-Datei**, die Sie im Online-Banking Ihrer Bank hochladen.

> **Vor dem ersten echten Einzug** die Datei mit dem Prüftool Ihrer Hausbank
> gegentesten. Das genaue Format weicht je nach Institut leicht ab.

Ein versehentlich erzeugter Einzug lässt sich über **„Verwerfen"** wieder
auflösen, solange keine Rücklastschrift eingegangen ist; die enthaltenen Posten
stehen dann wieder zur Verfügung. Wurde die Datei schon eingereicht, ändert das
Verwerfen daran natürlich nichts – dann müssen Sie den Einzug bei der Bank
zurückrufen.

### 13.6 Wenn das Geld da ist

Sobald die Sammelgutschrift auf Ihrem Vereinskonto steht, klicken Sie beim
Einzug auf **„Als ausgeführt verbuchen"**. Damit werden alle enthaltenen
offenen Posten in einem Schritt als bezahlt geschlossen – bei 80 Mitgliedern
also ein Klick statt achtzig.

Zurückgebuchte Zeilen bleiben dabei ausdrücklich offen: dieses Geld ist nicht
gekommen. Ein abgeschlossener Einzug lässt sich nicht mehr verwerfen.

Die Bankbuchung der Sammelgutschrift ordnen Sie wie jede andere Buchung einem
Konto zu.

### 13.7 Rücklastschriften

Kommt ein Einzug zurück (Konto nicht gedeckt, Mandat widersprochen), erkennt die
App das beim nächsten **Kontoauszugs-Import** und öffnet den zugehörigen offenen
Posten wieder. Die zurückgebuchte Bankbuchung selbst ordnen Sie wie jede andere
einem Konto zu – typischerweise Forderungsausfälle und Bankgebühren.

Die Erkennung arbeitet mit dem, was die Bank im Verwendungszweck mitliefert, und
liegt gelegentlich daneben. In der Zeilenansicht des Einzugs können Sie eine
falsch erkannte Rückbuchung über **„Rückbuchung zurücknehmen"** wieder aufheben.

Eine Rückgabe trifft oft erst ein, **nachdem** Sie den Einzug als ausgeführt
verbucht haben. Auch dann wird sie erkannt: der betroffene Posten wird wieder
geöffnet, und das Mandat gilt wieder als nicht eingelöst – der nächste Versuch
läuft dadurch erneut als Ersteinzug.

---

## 14. Anhang: Rollen, Kontotypen, Tastenkürzel, Glossar

### 14.1 Rollen und Rechte

| Rolle | Lesen | Buchen/Belege | Mitglieder/SEPA-Einzug (13.2–13.7) | Beiträge-Grundeinstellungen (13.1), Berechtigungen, Jahresabschluss, Reset |
|---|:---:|:---:|:---:|:---:|
| Revisor | ✓ | – | – | – |
| Buchhalter | ✓ | ✓ | ✓ | – |
| Verwalter | ✓ | ✓ | ✓ | ✓ |
| NC-Admin | ✓ | ✓ | ✓ | ✓ (immer) |

### 14.2 Kontotypen und ihre Bedeutung

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

### 14.3 Tastenkürzel (Desktop)

- **N** – neue Buchung anlegen
- **/** – Suche fokussieren (im Konten-Tab die Baumsuche, sonst die
  Buchungs-Suche)

### 14.4 Glossar

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
- **CSV-CAMT / CAMT.053 / MT940** – die drei Formate, in denen Banken einen
  Kontoauszug zum Herunterladen anbieten. CAMT.053 (eine XML-Datei) ist das
  eindeutigste und deshalb die beste Wahl; die CSV ist am verbreitetsten,
  ihre Spalten benennt aber jede Bank anders. Siehe Kapitel 3.2.
- **Wachordner** – ein Nextcloud-Ordner, aus dem die App abgelegte
  Kontoauszüge von allein einliest (Kapitel 3.3). Er holt nichts bei der Bank
  ab; das Herunterladen bleibt Handarbeit.
- **Vorgemerkter Umsatz** – eine von der Bank angezeigte, aber noch nicht
  endgültig gebuchte Zahlung. Die App überspringt solche Umsätze, weil sich
  Betrag oder Text bis zur endgültigen Buchung noch ändern können.
- **Splittbuchung** – eine Buchung, deren Betrag auf mehrere Gegenkonten
  verteilt ist: eine Überweisung über Beitrag *und* Spende, eine Rechnung auf
  zwei Projekte. Soll und Haben bleiben in der Summe gleich, nur eine Seite
  hat mehrere Zeilen (Kapitel 4.1 und 4.2).
- **Inaktives Konto** – ein Konto, das aus allen Auswahllisten genommen wurde,
  dessen gebuchte Beträge und Historie aber unverändert bleiben. Der Weg für
  Konten, die nicht mehr gebraucht werden, sich wegen vorhandener Buchungen
  aber nicht löschen lassen (Kapitel 2.2).

---

*Stand: App-Version 0.22.2. Bei Fragen an die verwaltende Person wenden.*
