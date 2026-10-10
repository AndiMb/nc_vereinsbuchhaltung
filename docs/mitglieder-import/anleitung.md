# Mitgliederliste einlesen – Vorlage und Hinweise

Die Vorlage `vorlage-mitglieder-import.csv` ist die Datei für **Beiträge → Mitglieder → „Liste einlesen“**.
Sie ist UTF-8 mit Semikolon als Trennzeichen und lässt sich direkt in Excel/LibreOffice öffnen. Die vier
Beispielzeilen zeigen die häufigsten Fälle (Person mit Mandat und Beitrag, Kontoinhaber ≠ Mitglied,
Mitglied ohne Mandat, Organisation); ersetze sie durch eure Daten. Die App bringt unter „Liste einlesen“ →
„Vorlage herunterladen“ eine kürzere Fassung mit denselben Spalten mit, diese hier ist ausführlicher.

Die Vorlage nennt die Beitragsgruppen „Vollmitglied“, „Jugend“ und „Ermäßigt“ und als Beitragsbeginn den
01.01.2027: Namen und Datum auf die eigenen Gegebenheiten anpassen.

## Spalten

Reihenfolge, Groß-/Kleinschreibung, Umlaute und Leerzeichen der Überschriften sind egal („Straße“ = „Strasse“,
„Mandat am“ = „mandatam“); zusätzliche Spalten werden ignoriert. Englische Überschriften gehen auch
(`firstname`, `lastname`, `organization`, `street`, `zip`, `city`, `phone`, `joined`, …).

**Wer ist das Mitglied?** Eine Zeile braucht genau eine dieser Angaben (Regeln siehe unten):

| Spalte | Pflicht? | Was hineingehört |
|---|---|---|
| **Vorname**, **Nachname** | ja, wenn keine Organisation und kein „Name“ | Person mit genau diesen Feldern. **Der Nachname ist Pflicht**, der Vorname darf leer sein. |
| **Organisation** | (Alternative) | Name einer Organisation, auch „Firma“ oder „Verein“. Ist sie gefüllt, entsteht ein Mitglied vom Typ Organisation. |
| **Name** | (Alternative) | Der ganze Name in einer Zelle, so wie bisher (siehe „Wie aus einem Namen ein Mitglied wird“). |
| Konto | (Alternative) | Nextcloud-Benutzername; das Mitglied wird mit dem Konto verknüpft, Mail kommt notfalls aus dem Konto. |

**Stammdaten:**

| Spalte | Pflicht? | Was hineingehört |
|---|---|---|
| Mitgliedsnummer | empfohlen | Eure bisherige Nummer. Sie ist der Schlüssel gegen Dubletten: Eine Nummer, die es schon gibt (oder die weiter oben in der Datei steht), wird übersprungen, nie überschrieben. |
| Eintritt | nein | Eintrittsdatum, `TT.MM.JJJJ` oder `JJJJ-MM-TT`. **Darf in der Vergangenheit liegen** (Bestandsliste). Leer = Tag des Imports. Ein unlesbares Datum macht die Zeile fehlerhaft. |
| Straße, PLZ, Ort, Telefon | nein | Freitext, wird nur von Leerzeichen am Rand befreit. Leer = nicht hinterlegt. Zu lange Werte (Straße 255, PLZ 16, Ort 128, Telefon 64 Zeichen) sind ein Zeilenfehler, nichts wird still gekürzt. |
| E-Mail | für Lastschrift nötig | Die Vorankündigung geht per Mail. **Zeilen mit Beitrag und IBAN, aber ohne E-Mail, bekommen die Zahlungsart Überweisung** (die Vorschau warnt davor). |

**Lastschrift (Mandat):**

| Spalte | Pflicht? | Was hineingehört |
|---|---|---|
| IBAN | nein | Nur mit IBAN entsteht ein Mandat. Leerzeichen sind egal; geprüft wird die **Form** (Länderkürzel, zwei Ziffern, 6–30 Zeichen), nicht die Prüfsumme. **Mit IBAN ist „Mandat am“ Pflicht.** |
| BIC | nein | Fast nie nötig. |
| Kontoinhaber | nein | Nur wenn er vom Mitglied abweicht (z. B. Eltern zahlen für das Kind). |
| Mandat am | bei IBAN | Datum der Unterschrift auf dem Papiermandat. Das Mandat wird sofort **aktiv** angelegt. |
| Mandatsreferenz | nein | Eure alte Referenz, wenn ihr sie behalten wollt. Leer = die App vergibt `M-<Nr>`. |

**Beitrag:**

| Spalte | Pflicht? | Was hineingehört |
|---|---|---|
| Beitragsgruppe | bei Beitrag | Name einer **vorhandenen** Beitragsgruppe (Reiter „Beitragsgruppen“), Groß-/Kleinschreibung egal. Gibt es nur eine Gruppe, darf die Spalte fehlen. Eine unbekannte Gruppe ist nur eine Warnung: das Mitglied entsteht, der Beitrag nicht. |
| Betrag | bei Beitrag | **Der Monatsbeitrag**, unabhängig vom Turnus. Jahresbeitrag 60 € → `5,00` und Frequenz `jährlich`. Leer = Standardbeitrag aus den Einstellungen (nur mit Start). |
| Frequenz | bei Beitrag | `monatlich`, `vierteljährlich`, `halbjährlich` oder `jährlich`; ohne Angabe `jährlich` (beim Standardbeitrag: dessen Frequenz). |
| Start | bei Beitrag | Erster Beitragsmonat, `TT.MM.JJJJ`. **Nicht in der Vergangenheit** (Zuweisungen gelten nie rückwirkend); heute ist erlaubt. |

Eine Zeile ohne IBAN und ohne Beitrag legt nur das Mitglied an.

## Wie aus einem Namen ein Mitglied wird

Je Zeile gilt die erste passende Regel:

1. **„Organisation“ ist gefüllt** → Mitglied vom Typ *Organisation* mit diesem Namen. Stehen daneben auch Vor- und
   Nachname, werden sie nicht übernommen (die Vorschau warnt).
2. **„Vorname“ und/oder „Nachname“ sind gefüllt** → Mitglied vom Typ *Person* mit genau diesen Feldern („Anna Maria“ /
   „Beispiel Müller“ bleibt so). Ohne Nachname ist die Zeile fehlerhaft.
3. **Sonst gilt „Name“ (oder das Konto):** Die App teilt am **ersten** Leerzeichen („Anna Maria Beispiel“ → Vorname
   „Anna“, Nachname „Maria Beispiel“). Als **Organisation** gilt ein Name,
   - der **kein Leerzeichen** enthält („Musikverein“), oder
   - der eine **Rechtsform** als eigenes Wort nennt: GmbH, gGmbH, mbH, UG, AG, KG, OHG, GbR, e. V./e.V./eV, eG,
     Stiftung, Genossenschaft (Groß-/Kleinschreibung egal; „Stiftung“ und „Genossenschaft“ auch als Wortende,
     z. B. „Bürgerstiftung“). „Musikhaus Beispiel GmbH“ wird so zur Organisation.

Das ist eine Heuristik. Die Vorschau zeigt bei Organisationen ein Kennzeichen hinter dem Namen – wer einen Namen
ohne Rechtsform hat, der trotzdem eine Organisation ist („Chor Beispielstadt“ bleibt eine Person!), nimmt die
Spalte „Organisation“. Mit Initialen wie „Karl E. V. Müller“ liegt die Heuristik falsch (wird als e. V. gelesen).

Zwei Stolpersteine:

- Steht **„Nachname“ allein** in der Datei (keine Spalte „Vorname“), wirkt es wie „Name“, damit ältere Dateien
  nicht kippen. Erst mit einer Spalte „Vorname“ sind beide Felder getrennt.
- **„Name; Vorname“** (in deutschen Listen steht „Name“ oft für den Nachnamen) liest die App **nicht** als
  Nachname + Vorname, sondern meldet pro Zeile den Fehler und nennt die Lösung: die Spalte in „Nachname“ umbenennen.

## Ablauf

1. **Beitragsgruppen zuerst anlegen** (Beiträge → Beitragsgruppen), mit denselben Namen wie in der Spalte.
2. Datei einlesen → **„Prüfen“**: Die Vorschau sagt, was entstehen würde (Mitglieder, Mandate, Zuweisungen,
   übersprungene und fehlerhafte Zeilen). Es wird noch nichts gespeichert.
3. Erst wenn das passt, bestätigen (bei Mandaten mit der ausdrücklichen Bestätigung, dass die Unterschriften
   vorliegen) und anlegen.
4. Die Mandate sind „Papier, ohne Nachweis“: Ab jetzt weist die Aufgabenliste darauf hin, den unterschriebenen
   Zettel noch hochzuladen. Das lässt sich später je Mitglied nachholen.

## Was „Prüfen“ meldet

**Fehler** (die Zeile wird nicht angelegt, alle anderen schon):

- unlesbare oder fehlende Angaben: Datum (Eintritt, Mandat am, Start), Betrag, Frequenz, E-Mail-Adresse,
  fehlender Nachname, „IBAN ohne Mandat am“, „Betrag ohne Start“, zu lange Werte, ein Nextcloud-Konto, das es nicht gibt
- eine **ungültige IBAN-Form**
- ein **Start in der Vergangenheit**, wenn daraus eine Zuweisung entstünde (bei unbekannter Beitragsgruppe entsteht
  keine, dann bleibt es bei der Warnung)

**Übersprungen** (nicht angelegt, kein Fehler): die Mitgliedsnummer oder das Nextcloud-Konto gibt es schon – in der
App **oder in einer früheren Zeile derselben Datei**. Die zweite Zeile mit derselben Nummer wird also im Prüfen
schon als übersprungen gezeigt.

**Warnungen** (die Zeile wird trotzdem angelegt):

- ein Mitglied gleichen Namens gibt es schon (Namensgleichheit ist keine Dublette)
- die Beitragsgruppe fehlt oder ist unbekannt: das Mitglied entsteht, der Beitrag nicht
- **IBAN und Beitrag, aber keine E-Mail:** die Zuweisung bekommt Überweisung statt Lastschrift
- bei Person mit Rechtsform im Nachnamen („Nachname: Musikhaus GmbH“, kein Vorname): wird als Person angelegt –
  Organisationen gehören in die Spalte „Organisation“
- Organisation und Vor-/Nachname in derselben Zeile (die Namen werden nicht übernommen)

## Was der Import (noch) nicht kann

- **Prüfsumme der IBAN:** wird nirgends geprüft (nur die Form). Ein Zahlendreher in der IBAN fällt erst bei der
  ersten Einreichung bei der Bank auf.
- **Regeln der Beitragsgruppe:** Ein Turnus, den die Gruppe nicht erlaubt, und ein Monatsbeitrag unter ihrer
  Untergrenze fallen erst beim Anlegen auf, nicht in der Vorschau. Die Zeile wird dann einzeln als fehlerhaft gemeldet
  und nicht angelegt (auch das Mitglied nicht – es bleibt keine halbe Zeile).
- **Zahlungsart wählen:** Sie hängt allein an der E-Mail (mit E-Mail Lastschrift, ohne Überweisung). Eine Zeile **mit
  E-Mail, aber ohne IBAN und mit Beitrag** bekommt deshalb Lastschrift ohne Mandat; die Aufgabenliste meldet dann
  „hat eine Zuweisung mit Lastschrift, aber kein einzugsfähiges Mandat“, und Forderungen entstehen erst, wenn ein
  Mandat vorliegt. Das warnt die Vorschau (noch) nicht.
- **Er legt nur an:** Bestehende Mitglieder werden nie geändert. Ein zweiter Lauf mit derselben Datei
  überspringt alles, was es schon gibt – Zeilen ohne Mitgliedsnummer und Konto aber nicht (dort warnt nur die
  Namensgleichheit), sie würden doppelt angelegt.

Tipp: Teste zuerst mit **zwei oder drei echten Zeilen**, schau dir die Akte an und lies dann den Rest ein.
