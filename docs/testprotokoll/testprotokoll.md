# Testprotokoll: Beiträge & SEPA in der Vereinsbuchhaltung

<!--
FORMAT DIESER DATEI (wird von build.mjs gelesen, bitte beibehalten):
- "# Titel" einmal ganz oben, danach freier Einleitungstext bis zur ersten "## ".
- "## Phase N – Titel" beginnt eine Phase. Danach optional die Phasen-Zeilen
  **Ziel:**, **Nutzer:**, **Vorbedingung:** (je Zeile oder Liste), dann die Schritte.
- "### N.M Schritt-Titel" beginnt einen Schritt (N = Phasennummer, M zählt ab 1 lückenlos).
  Darin die Abschnitte **Rolle:**, **Tun:**, **Erwartet:** und optional **Beachte:**.
  Ein Abschnitt reicht bis zum nächsten **Label:**, Aufzählungen und Codeblöcke sind erlaubt.
- Jede andere "## Überschrift" ist ein reines Textkapitel (ohne Schritte).
- Ein HTML-Kommentar, der nur aus dem Wort matrix besteht, erzeugt an dieser Stelle die Prioritäten-Matrix.
- Prüfen ohne Bauen: node docs/testprotokoll/build.mjs --check
-->

Dieses Protokoll führt dich einmal komplett durch die Customer Journey des neuen Moduls **Beiträge & SEPA** (App-Version 0.35.0, Stand: Integrationsbranch `integration/beitraege-sepa`): vom ersten Öffnen über Mitglieder, Mandate, Einzug und Bankabgleich bis zu Self-Service, Datenschutz und Zurücksetzen. Du gehst Schritt für Schritt vor, hältst je Schritt fest, ob alles passt, und gibst am Ende dein Feedback als Text weiter.

**So arbeitest du mit der Seite**

- Jeder Schritt hat **Tun** (was du klickst und eingibst) und **Erwartet** (woran du das Ergebnis prüfst). Setze danach das **Ergebnis**: *OK*, *Abweichung*, *Frage* oder *übersprungen*. Mit einer **Notiz** hältst du fest, was dir aufgefallen ist (auch bei OK).
- Deine Eingaben bleiben im Browser (`localStorage`) erhalten. Die Seite funktioniert auch ohne Speicher, dann gehen Eingaben beim Schließen verloren.
- Oben findest du Fortschritt, Filter („nur offene“, „Abweichungen“) und den Knopf **Feedback exportieren**: Er legt alle Abweichungen, Fragen und Notizen als Markdown in die Zwischenablage, damit du sie in den Chat einfügen kannst.
- Bei einer **Abweichung** oder **Frage** kannst du eine Priorität vergeben (Blocker, Wichtig, Schön zu haben). Die Matrix in Phase 18 fasst alles zusammen.

**Schreibweisen im Protokoll**

| Zeichen | Bedeutung |
|---|---|
| „Text“ | exakte Beschriftung aus der Oberfläche |
| `Text` | Eingabe oder Befehl, genau so tippen |
| (laut Seeder – ggf. abweichend) | Wert hängt von den Testdaten des Seeders ab; weicht der echte Stand ab, ist das kein Fehler, sondern eine Notiz wert |
| (Beschriftung nicht belegt) | die Oberfläche gehört nicht zur App (Nextcloud, Mailhog, Browser) oder der Text ließ sich nicht aus dem Code belegen; sinngemäß suchen |
| ⚠ Unumkehrbar | der Schritt ändert Daten endgültig; der Seeder (`--wipe`) stellt den Ausgangszustand wieder her |

**Wichtig vorab:** Es gibt keine Zeitreise. Die App rechnet mit dem echten Datum, und das Testszenario ist auf den **05.10.2026** zugeschnitten: ein eingereichter Lauf zum 01.10., der nächste zum 01.11. Der Seeder hat die Vorlaufzeiten deshalb so gesetzt (Vorabinfo-Vorlauf 30 Tage, Vorwarnfenster 35 Tage statt 14 und 21), dass heute die Vorabinfo zum 01.11. fällig ist. Mails und Zustände entstehen erst, wenn der Tageslauf läuft (Phase 0, Schritt 0.6).

## Bekannte Grenzen und Entscheidungen

Lies diese Liste vor dem Testen. Was hier steht, ist **kein Fehler**, sondern bewusst so gebaut oder bekannt offen (Quelle: Spec §15.2 und §15.3, Handbuch Kapitel 13). Trotzdem darfst du es kommentieren: Eine Notiz mit „Frage“ hilft, eine Entscheidung zu überdenken.

### Grenzen

- **Du/Sie nur für Mitgliedertexte:** Die Du-Fassung gibt es für Texte, die Mitglieder lesen (Mails an Mitglieder, „Mein Beitrag“). Sie gilt für Nutzerkonten mit Sprache „Deutsch (informell)“ (`de`). Förmliches Deutsch (`de_DE`) und Mitglieder ohne Konto bekommen Sie. Die gesamte Verwaltungsoberfläche bleibt in Sie-Form.
- **Zahlen und Daten in englischen Mails deutsch formatiert:** Beträge (`12,50 €`) und Datumsangaben in englischen Mails folgen dem deutschen Format.
- **Mandatstext und Zustimmungsseite nur auf Deutsch:** Der Rechtstext, das druckfertige Mandatsformular und die öffentliche Zustimmungsseite sind immer deutsch und in Sie-Form, auch wenn das Konto Englisch oder Du eingestellt hat.
- **Zurücksetzen holt Forderungen nach:** Nach „Alle Daten löschen“ bleiben die Zuweisungen bestehen. Der Tageslauf legt die gelöschten Forderungen auch für längst abgerechnete Zeiträume neu an und verschickt dazu Vorabinfos. Das gilt als offene Entscheidung (Spec §15.3), nicht als Fehlerbehebung.
- **Anonymisierung schwärzt Satztexte von Ereignissen nicht:** Strukturierte Freitexte (Sperrnotiz, Begründungen, Vertretungsnotizen, Rücklastschrift-Freitext) werden geschwärzt. Fragmente, die beim Entstehen eines Ereignisses schon im Satztext standen (etwa eine Mailadresse in „Aktivierungslink versendet an …“ oder Namen in „Kontoinhaber korrigiert …“), bleiben im Verlauf stehen.
- **XML-Kopien bleiben beim Zurücksetzen liegen:** Die optionale Kopie der Einzugsdatei im Nextcloud-Ordner löscht der Reset nicht (die App kennt keine Datei-ID, die Ablage dient der Compliance).
- **GiroCode braucht PHP-Erweiterung `gd`:** Die App nutzt `chillerlan/php-qrcode` in Version 5 (`^5.0`, PHP 8.1 genügt; Version 6 verlangt PHP 8.2). Fehlt `gd` oder die Bibliothek, geht die Mahnmail ohne GiroCode raus (der Mailtext erwähnt ihn dann nicht), und der Fehler steht im Nextcloud-Log. Prüfe das Docker-Image (Phase 0).
- **IBAN nur formal geprüft:** Länderkürzel, Prüfziffern-Stellen und Länge, aber keine Prüfsummenrechnung. Eine IBAN mit falscher Prüfziffer wird angenommen, eine formal falsche abgelehnt (bewusste Entscheidung, siehe Klassendoc `IbanValidator`). Die Test-IBANs des Seeders haben eine gültige Prüfsumme. Ob du eine Prüfsumme willst, ist eine Frage für dein Feedback (16.3).
- **Der Prüflauf des CSV-Imports legt die Latte hoch, prüft aber keine Bankprüfsumme:** Er meldet fehlende Namen, ungültige E-Mail- und IBAN-Formate, Startdaten in der Vergangenheit und doppelte Mitgliedsnummern (auch innerhalb derselben Datei) schon vor dem Übernehmen. Eine IBAN mit falscher Prüfziffer geht durch (siehe „IBAN nur formal geprüft“).
- **Zahlungseingangs-Vorschläge sind großzügig:** Sie berücksichtigen alle noch nicht erledigten Forderungen mit gleichem Betrag, auch solche, die schon in einem Lauf stecken. Du bestätigst jede Zuordnung selbst (8.9).
- **Anonymisierungs-Kandidat nur mit zusätzlicher Buchung:** Die 10-Jahres-Frist läuft ab der letzten **verbuchten** Zahlung. Hans Becker (1010) ist deshalb im Seeder-Szenario erst dann reif, wenn der Seeder eine Buchung von 2014 anlegt (`--with-anonymization-booking`, legt die Geschäftsjahre 2014 bis 2025 an, siehe 0.4 und 12.0).
- **Echter Cron:** Die Dev-Umgebung hat einen laufenden Cron, der die Tagesjobs frühestens am 06.10.2026 gegen 20:20 UTC selbst startet; Vorabinfos können dann ohne dein Zutun rausgehen (0.6).
- **Kein Wiedereinzug:** Nach einer Rücklastschrift wird eine Forderung nie wieder in einen Lauf gezogen. Sie bleibt offen, bis sie bezahlt, erlassen oder gestundet wird.
- **Einreichung bei der Bank ist Handarbeit:** Die App erzeugt die pain.008-Datei, du lädst sie im Online-Banking hoch. Vor dem ersten echten Einzug mit dem Prüftool der Hausbank testen.
- **Altmodul entfernt:** Mandate, Beiträge und Sammeleinzüge des alten flachen Moduls wurden nicht übernommen (Migration 157). Nur die daraus entstandenen Mitglieder bleiben.
- **E2E-Wackler seit #106 (nur Info):** In der CI laufen einzelne E2E-Specs seit der Übersetzungsumstellung gelegentlich instabil. Das betrifft die Testsuite, nicht die Bedienung; für dich genügt die Kenntnis.

### Entscheidungen

- **Löschsperre strenger als in der Spec:** Ein Mitglied lässt sich nur löschen, solange kein Mandat (auch kein Entwurf, kein beendetes und kein verworfenes), keine Zuweisung und keine Forderung an ihm hängt (Spec §3.1 nennt nur „keine Forderung und nie ein aktives Mandat“). Für Datenschutzfälle gibt es die Anonymisierung.
- **Einzugstage im Terminplan ab Buchhalter:** Standard-Einzugstag und Überschreibungen (`setDefaultDay`) darf der Buchhalter ändern, die Fristen (**Vorwarnfenster**, **Vorabinfo-Vorlauf** und **Freigabe-Vorlauf**) nur der Verwalter, und zwar in den Nextcloud-Einstellungen; der Terminplan zeigt sie nur an.
- **Sperrfenster lehnt ab, statt zu verschieben:** Eine Änderung von Betrag oder Turnus, die wegen einer verschickten Vorabinfo erst später wirken dürfte, weist „Mein Beitrag“ mit Erklärung zurück. Die IBAN kennt kein Sperrfenster.
- **Forderungen in „Offene Posten“ nur lesbar:** Bezahlt, Stornieren, Wieder öffnen und Löschen lehnt der generische Weg bei Forderungen an Mitglieder ab; bearbeitet wird im Reiter „Einzug“.
- **Revisor sieht nur „Einzug“:** Mitglieder, Mandate, Beitragsgruppen und Zuweisungen sind erst ab Buchhalter lesbar; die IBAN bleibt für Revisoren maskiert, den Rückgabecode der Bank sehen sie nicht.
- **Anonymisierung mit Zusatzbedingungen:** Reif ist ein Mitglied zehn Jahre nach Ende des Kalenderjahres der letzten Buchung, aber nur, wenn es ausgetreten ist und kein lebendes Mandat mehr hat. Die Aufgabe „anonymisierungsreif“ ist eine abgeleitete Abfrage, kein Cron-Job.
- **Bankabgleich ist ein Segment im Einzug:** Er steht neben „Zeitstrahl & Läufe“ und „Forderungen“. Ein abgelehnter Zahlungseingangs-Vorschlag bleibt abgelehnt.
- **„Als bezahlt vermerken“ bucht nichts:** Der Erledigungsvermerk ist kein Bankeingang. Gebucht wird nur über den Bankabgleich oder von Hand.
- **Noch offen vor dem Merge:** Der vollständige CI-Lauf einschließlich E2E ist das letzte offene Kriterium vor dem Merge nach `main` (Spec §15.3).

## Phase 0 – Umgebung & Vorbereitung

**Ziel:** Du kannst dich als alle benötigten Nutzer anmelden, Mails lesen, Testdaten einspielen und den Tageslauf von Hand anstoßen. Du kennst die Stellen, an denen der Test Daten unumkehrbar verändert.

**Nutzer:** admin (App-Verwalter), alice (Buchhalter), bob (Revisor), jane, john und user1 (Mitglieder mit verknüpftem Nextcloud-Konto).

**Vorbedingung:** Die Dev-Nextcloud läuft unter http://stable34.local/ (Nextcloud 34), die App „Vereinsbuchhaltung“ 0.35.0 ist gebaut, migriert und aktiviert. Nach jedem Neubau ein Hard-Reload (⇧⌘R).

### 0.1 Instanz und App-Version prüfen

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne http://stable34.local/ und melde dich als `admin` an (Passwort deiner Dev-Instanz, üblicherweise gleich dem Benutzernamen).
2. Öffne die Nextcloud-App-Verwaltung (Apps-Übersicht, Beschriftung nicht belegt) und suche „Vereinsbuchhaltung“.
3. Öffne die App über die Hauptnavigation: `http://stable34.local/index.php/apps/vereinsbuchhaltung/`.

**Erwartet:**
- Die App ist aktiv und trägt die Version 0.35.0.
- Die Kopfzeile der App zeigt den Titel „Vereinsbuchhaltung“ und die Reiter „Übersicht“, „Buchungen“, „Konten“, „Berichte“ und „Beiträge“.
- Es gibt keine Fehlermeldung beim Laden. Falls doch, notiere sie unter Abweichung und lade mit ⇧⌘R neu.

**Beachte:** Migration 157 hat die Alt-Tabellen des flachen Beitragsmoduls entfernt. Alte Mandate und Sammeleinzüge sind nicht mehr da (siehe „Bekannte Grenzen“).

### 0.2 Browserfenster je Rolle einrichten

**Rolle:** Du

**Tun:**
1. Öffne pro Nutzer ein eigenes privates Fenster oder Browserprofil, damit sich die Sitzungen nicht in die Quere kommen.
2. Melde dich an: `admin` (Fenster A), `alice` (B), `bob` (C), `jane` (D), `john` (E), `user1` (F). Das Passwort ist das deiner Dev-Instanz (üblicherweise gleich dem Benutzernamen).

**Erwartet:**
- Alle sechs Anmeldungen klappen.
- Fenster A (admin) bleibt dein Hauptfenster; die anderen Fenster brauchst du erst ab Phase 4 (jane, john ab Phase 7 und 11).

**Beachte:** Rollen laut Seeder: `admin` ist als Nextcloud-Administrator immer Verwalter, `alice` Buchhalter, `bob` Revisor. Die Mitglieder jane (Jana Hoffmann, Sprache „Deutsch“), john (Jonas Richter, „Deutsch (förmlich)“) und user1 (Lena Bergmann, „English“) haben keine App-Rolle.

### 0.3 Mailhog öffnen

**Rolle:** Du

**Tun:**
1. Öffne http://mail.local/ in einem eigenen Tab.
2. Nach einem Test-Durchlauf leerst du Mailhog mit diesem Befehl (die Oberfläche von Mailhog bietet ebenfalls einen Knopf dafür, Beschriftung nicht belegt):

```bash
curl -X DELETE -H "Host: mail.local" http://127.0.0.1/api/v1/messages
```

**Erwartet:**
- Mailhog zeigt beim Start eine leere Inbox (Stand nach dem Seeder).
- Mails, die die App verschickt (Einmal-Link, Vorabinfo, Zahlungsaufforderung, Quittung), erscheinen hier und werden nicht wirklich zugestellt.

### 0.4 Testdaten prüfen oder neu einspielen (Seeder)

**Rolle:** Du (Terminal)

**Tun:**
1. Der Seeder ist auf dieser Instanz schon gelaufen. Prüfe den Stand, ohne etwas zu ändern (Kennzahlen und Aufgabenliste wie im Klemmbrett):

```bash
/Users/FlorianLudwig/Projekte/nextcloud-apps/vereinsbuchhaltung/tests/dev/in-container.sh seed-beitraege.php --check
```

2. **Entscheide jetzt, ob du Phase 12 (Anonymisierung) mitmachen willst.** Dann musst du das Szenario einmal mit der zusätzlichen Buchung von 2014 neu anlegen (siehe Beachte), und zwar **bevor** du mit Phase 1 beginnst:

```bash
/Users/FlorianLudwig/Projekte/nextcloud-apps/vereinsbuchhaltung/tests/dev/in-container.sh seed-beitraege.php --wipe --with-anonymization-booking
```

3. Lade die App im Browser neu (⇧⌘R) und öffne **Beiträge**.

**Erwartet:**
- `--check` nennt: 16 Mitglieder (1001 bis 1016), 4 Beitragsgruppen, einen eingereichten Lauf (Fälligkeit 01.10.2026, 10 Posten, 145,00 €; die laufende Nummer hängt von der Instanz ab, in der Dev-Umgebung zuletzt Nr. 11) und 10 Forderungen für den 01.11.2026 (130,00 €), dazu die Aufgabenliste.
- Im Reiter „Beiträge“ → „Mitglieder“ stehen 16 Mitglieder mit den Nummern #1001 bis #1016. Die Beitragsgruppen heißen Vollmitglied (15,00 € je Monat), Ermäßigt (7,50 €), Jugend (5,00 €) und Fördermitglied (5,00 € je Monat, also 60,00 € im Jahr).
- Unter „Einzug“ steht ein eingereichter Lauf zum 01.10.2026 und eine Vorschau für den 01.11.2026.
- Einstellungen laut Seeder: Gläubiger-ID `DE98ZZZ09999999999`, einziehendes Konto 1200, Erlöskonto 4000, Gebührenkonto 5400, Self-Service an, **Vorabinfo-Vorlauf 30 Tage** und **Vorwarnfenster 35 Tage** (Standard wären 14 und 21). Rollen: alice Buchhalter, bob Revisor. Sprachen: jane „Deutsch“, john „Deutsch (förmlich)“, user1 „English“.

**Beachte:** Zweck des Seeders: ein festes Szenario aus 16 Mitgliedern mit unterschiedlichen Mandatszuständen, Beitragsgruppen, einem eingereichten Lauf und einem anstehenden Lauf, an dem du jeden Teil der Journey prüfst. Der Seeder schreibt als `admin`, das Änderungsprotokoll nennt ihn.

Die Optionen im Überblick (der Befehl lautet immer `…/tests/dev/in-container.sh seed-beitraege.php <Option>`):

| Option | Wirkung |
|---|---|
| (keine) | legt das Szenario an, bricht ab, wenn schon Modul-Daten da sind |
| `--wipe` | löscht **nur Modul-Daten** (Mitglieder, Mandate samt Verlauf, Gruppen, Zuweisungen, Läufe, Rücklastschriften, Mahnstufen, Forderungen) und legt das Szenario neu an; Konten, Buchungen, Belege und Geschäftsjahre bleiben |
| `--wipe --wipe-bank` | löscht zusätzlich die aus der Testdatei importierten Bankumsätze samt Buchungen (nötig, sonst meldet ein zweiter Import Dubletten) |
| `--with-anonymization-booking` | legt zusätzlich eine Buchung vom 10.02.2014 über 144,00 € an, damit Hans Becker anonymisierungsreif ist (nur beim Anlegen des Szenarios, also mit `--wipe`) |
| `--purge` | stellt den Zustand vor dem ersten Seeden her: Modul-Daten, Bankimport, Einstellungen, Rollen, Sprachen und die Anonymisierungs-Buchung |
| `--check` | ändert nichts, zeigt Stand und Aufgabenliste |

⚠ **Nebenwirkung von `--with-anonymization-booking`:** Die Buchung von 2014 legt die Geschäftsjahre **2014 bis 2025** (12 leere Jahre) in der Buchhaltung dieser Instanz an. Sie erscheinen in der Auswahl „Zeitraum“ in der Kopfzeile. `--purge` entfernt Buchung und Jahre wieder, setzt aber auch alles andere zurück (Einstellungen, Rollen, Sprachen); danach legst du das Szenario mit einem erneuten Seeden neu an.

Ohne das Hilfsskript geht derselbe Aufruf direkt (z. B. für `--check`):

```bash
cd /Users/FlorianLudwig/Projekte/nextcloud-docker-dev
docker compose exec -T -u www-data stable34 php /var/www/html/apps-shared/vereinsbuchhaltung/tests/dev/seed-beitraege.php --check
```

Die Ausgabe des Containers beginnt mit einer Zeile „Profiler output …“; sie gehört nicht zum Ergebnis.

### 0.5 Testdateien bereitlegen

**Rolle:** Du

**Tun:**
1. Öffne im Repo den Ordner `docs/testprotokoll/testdaten/`.
2. Prüfe, dass diese drei Dateien vorhanden sind.
3. Bei Bedarf erzeugst du die Bankdatei neu (sie ist nach jedem erneuten Seeden byte-identisch):

```bash
/Users/FlorianLudwig/Projekte/nextcloud-apps/vereinsbuchhaltung/tests/dev/make-camt053.sh
```

**Erwartet:**

| Datei | Wofür |
|---|---|
| `bank-oktober-2026.camt053.xml` | Kontoauszug für Phase 8: Sammelgutschrift des Oktoberlaufs (8 Posten, 85,00 €), Rückgabe Markus Fuchs (AM04, 15,00 € plus 3,50 € Gebühr), Rückgabe Sophie Krüger (AC04, 45,00 € plus 4,00 € Gebühr), Überweisung von Lena Bergmann (22,50 €), eine Spende (50,00 €) und ein Kontoführungsentgelt (7,90 €) |
| `mitglieder-import.csv` | gültige Mitgliederliste für Phase 6 (6 neue Mitglieder mit den Nummern 1101 bis 1106) |
| `mitglieder-import-fehler.csv` | Liste mit absichtlichen Fehlern für Phase 6 (14 Zeilen) |

**Beachte:** Fehlt eine Datei, notiere das als Abweichung und überspringe die zugehörigen Schritte.

### 0.6 Tageslauf (Cron) von Hand auslösen

**Rolle:** Du (Terminal)

**Tun:**
1. Zeige die Jobs und ihre IDs an (ändert nichts):

```bash
/Users/FlorianLudwig/Projekte/nextcloud-apps/vereinsbuchhaltung/tests/dev/run-jobs.sh --list
```

2. Merke dir den Befehl für den Tageslauf (kommt in Phase 7, 9 und 17 vor):

```bash
/Users/FlorianLudwig/Projekte/nextcloud-apps/vereinsbuchhaltung/tests/dev/run-jobs.sh tageslauf mahnwesen
```

3. Ohne Namen (`…/run-jobs.sh`) laufen alle fünf Jobs der Reihe nach. Ohne das Skript geht es direkt (die Job-ID zeigt `background-job:list`):

```bash
cd /Users/FlorianLudwig/Projekte/nextcloud-docker-dev
docker compose exec -T -u www-data stable34 php occ background-job:list | grep -i vereins
docker compose exec -T -u www-data stable34 php occ background-job:execute <ID> --force-execute
```

**Erwartet:**
- Der Befehl läuft ohne Fehlermeldung durch. Die Jobs der App heißen im Skript:

| Kurzname | Job | Wirkung |
|---|---|---|
| `tageslauf` | `ContributionDueCycleJob` | legt Forderungen an und verschickt Vorabinfos |
| `mahnwesen` | `DunningLadderJob` | Zahlungsaufforderung für Überweiser, Zahlungserinnerung, Mahnung, Eskalation |
| `verfall` | `MandateExpiryJob` | 36-Monats-Verfall der Mandate |
| `austritt-zuweisungen` | `MemberDepartureJob` | beendet Zuweisungen ausgetretener Mitglieder |
| `austritt-mandate` | `MandateDepartureJob` | beendet das Mandat Ausgetretener, sobald nichts mehr offen ist |

**Beachte:** Alle Jobs sind idempotent. ⚠ **Reihenfolge und Cron:** Mails und Zustände entstehen erst, wenn ein Job läuft. Die Umgebung hat einen echten Cron (alle fünf Minuten), der die Tagesjobs **frühestens am 2026-10-06 gegen 20:20 UTC selbst startet**. Dann gehen die Vorabinfos auch ohne dein Zutun raus, und der Zustand „Vorabinfo noch nicht versendet“ (Phase 7) lässt sich nur mit `--wipe` wiederherstellen. Auch `run-jobs.sh` setzt „zuletzt gelaufen“ auf jetzt: Der Cron startet einen Tagesjob dann frühestens 24 Stunden später.

### 0.7 GiroCode-Voraussetzung prüfen (PHP-Erweiterung gd)

**Rolle:** Du (Terminal)

**Tun:**
1. Prüfe im Container, ob PHP die Erweiterung `gd` hat:

```bash
cd /Users/FlorianLudwig/Projekte/nextcloud-docker-dev
docker compose exec -T stable34 php -m | grep -i '^gd$'
```

**Erwartet:**
- Die Ausgabe lautet `gd`. Dann hängen die Mahnmails in Phase 9 GiroCodes an.
- Fehlt die Zeile, ist das keine App-Abweichung: Die Mahnmails gehen dann ohne GiroCode raus, und Schritt 9.4 prüft genau dieses Verhalten.

### 0.8 Stichtag notieren und Warnhinweise lesen

**Rolle:** Du

**Tun:**
1. Notiere das heutige Datum: ________. Das Szenario ist auf den **05.10.2026** zugeschnitten; alle Fristen (Vorwarnung, Vorabinfo, Freigabe-Vorlauf, Verfall) rechnen von heute aus.
2. Lies die Warnungen unten und nimm sie zur Kenntnis.

**Erwartet:**
- Liegt heute zwischen dem 02. und 31.10.2026, passen Fälligkeiten, Vorabinfo und Fristen zu diesem Protokoll. An einem anderen Tag warnt der Seeder, und Termine sowie Aufgaben weichen ab (dann rechne die Daten in den Schritten um).
- Du weißt, welche Schritte nicht rückgängig zu machen sind: Sie tragen das Zeichen ⚠.

**Beachte:** ⚠ **Phase 17 („Alle Daten löschen“) ist für die Buchhaltung dieser Instanz destruktiv** (14 Konten, 1 Buchung, 4 Belege) und nur bewusst auszuführen. Die Migration hat die Alt-Tabellen des Beitragsmoduls entfernt. Einen Sicherungsdump der App-Tabellen vor dem Upgrade hat der Koordinator. Auch die Anonymisierung (12.7), der Mandatswiderruf und das Einreichen eines Laufs (7.14) lassen sich nicht zurücknehmen; `--wipe` stellt die Modul-Daten wieder her.

## Phase 1 – Erster Eindruck

**Ziel:** Du siehst, was ein Verwalter oder Buchhalter nach dem Update zuerst sieht: den Was-ist-neu-Dialog, die Reiter und das Aufgaben-Klemmbrett.

**Nutzer:** admin (Verwalter), danach alice (Buchhalter).

**Vorbedingung:** Phase 0 abgeschlossen, Seeder gelaufen.

### 1.1 Was-ist-neu-Dialog

**Rolle:** admin (Verwalter)

**Tun:**
1. Melde dich als `admin` an und öffne die App. Erscheint der Dialog „Was ist neu?“ nicht von selbst (er kommt nur einmal nach einem Update), öffne ihn über den Hilfe-Knopf (?) oben rechts und den Link „Was ist neu in Version 0.35.0?“.
2. Lies die Stichpunkte. Klicke auf den Link „Vollständige Änderungsliste öffnen ↗“ (öffnet das Changelog in einem neuen Tab).
3. Schließe den Dialog mit „Verstanden“.

**Erwartet:**
- Der Dialog trägt die Überschrift „Was ist neu?“ und einen Block „Version 0.35.0“ mit **genau 5 Einzeilern** zu: (1) Beiträge und SEPA sind neu, (2) das bisherige Modul ist ersetzt (seine Mandate und Beiträge wurden nicht übernommen), (3) Einzug in zwei Schritten, (4) Bankabgleich schlägt Buchungen vor und Rücklastschriften mit Mahnstufen, (5) „Mein Beitrag“ für Mitglieder und Aufgaben-Klemmbrett.
- Über den Hilfe-Link zeigt der Dialog zusätzlich ältere Versionen (z. B. 0.34.0).
- Nach „Verstanden“ kommt der Dialog beim Neuladen nicht wieder.
- Die Texte sind verständlich, in Sie-Form und nicht zu lang (deine Einschätzung).

**Beachte:** Der Eintrag zu 0.35.0 gilt für Verwalter und Buchhalter. Ein Revisor sieht ihn nicht (prüfe das in 13.4).

### 1.2 Reiter und Kopfzeile

**Rolle:** admin (Verwalter)

**Tun:**
1. Sieh dir die Kopfzeile an, von links nach rechts.
2. Klicke nacheinander auf jeden Reiter.

**Erwartet:**
- Reiter: „Übersicht“, „Buchungen“, „Konten“, „Berichte“, „Beiträge“. Der Reiter „Mein Beitrag“ erscheint für admin nicht (admin ist kein verknüpftes Mitglied).
- Rechts in der Kopfzeile: Knopf „Buchung“, Auswahl „Zeitraum“, der Klemmbrett-Knopf (Aufgaben) und der Hilfe-Knopf.
- Der Geldbestand steht als Chip in der Kopfzeile (Desktop, sobald Geldkonten vorhanden sind).

### 1.3 Aufgaben-Klemmbrett und Badge

**Rolle:** admin (Verwalter)

**Tun:**
1. Sieh dir den Klemmbrett-Knopf an: Steht eine Zahl daran?
2. Klicke ihn an und lies die Liste.
3. Klicke bei einer Aufgabe auf „Zur Akte“ bzw. „Zum Einzug“.
4. Öffne das Klemmbrett erneut und drücke **Esc**.
5. Klicke im Klemmbrett auf das Aktualisieren-Symbol („Aufgaben aktualisieren“).

**Erwartet:**
- Der Knopf trägt den zugänglichen Namen „Aufgaben – N mit Handlungsbedarf“. Die Zahl im Badge zählt **nur** Handlungsbedarf, nicht die Hinweise.
- Das Fenster heißt „Aufgaben“ und zeigt die Überschriften „Handlungsbedarf“ und „Hinweise“.
- Stand nach dem Seeder, **vor dem Tageslauf** (Einzelheiten laut Seeder – ggf. abweichend):
  - unter „Handlungsbedarf“ **eine aufklappbare Zeile** „Vorabinfo nicht rechtzeitig verschickt“ mit Zähler **10** (aufgeklappt zehnmal „Vorabinfo für {Name} ({Bezeichnung}, fällig 01.11.2026) konnte nicht rechtzeitig verschickt werden.“; ab drei gleichartigen Meldungen fasst das Klemmbrett sie so zusammen) (der 30-Tage-Vorlauf ist schon unterschritten, der Cron ist noch nicht gelaufen),
  - eine Aufgabe zu **Jonas Richter** (sein Mandat ist ein Entwurf: kein einzugsfähiges Mandat, bzw. der Einmal-Link wartet auf Zustimmung),
  - unter „Hinweise“ bei Nadine Schuster „Mandat verfällt am 01.12.2026 (in N Tagen)“ und ggf. „Zum Mandat ist kein Nachweis hinterlegt (unterschriebenes Dokument).“ bei Papier-Mandaten sowie „Nächster Lauf am 01.11.2026 – N Forderung(en), X €, M Störfall/Störfälle.“
- Jede Aufgabe führt mit „Zur Akte“ oder „Zum Einzug“ an die Stelle, an der du sie behebst; die Liste schließt sich dabei.
- Es gibt nichts zum Wegklicken (kein „erledigt“, kein Quittieren). Eine Aufgabe verschwindet von selbst, wenn die Ursache behoben ist.
- **Esc** schließt das Fenster.

**Beachte:** Das Klemmbrett erscheint ab Buchhalter. Revisoren sehen es nicht (13.4). Die zehn Vorabinfo-Aufgaben sind der echte Zustand „Cron noch nicht gelaufen“; sie verschwinden mit 7.6.

### 1.4 Hilfe-Fenster

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne den Reiter „Beiträge“ und klicke den Hilfe-Knopf (?).
2. Lies das Thema „Beiträge & SEPA“. Klicke auf „Vollständiges Handbuch öffnen ↗“.

**Erwartet:**
- Das Hilfe-Fenster öffnet beim passenden Thema „Beiträge & SEPA“ mit mehreren Stichpunkten (aktuell sieben).
- Das Handbuch öffnet im neuen Tab bei Kapitel 13 (Mitgliedsbeiträge und SEPA-Lastschrift).

**Beachte:** Der Hilfetext sagt, der Knopf „Mitglied“ nehme ein Mitglied „samt optionalem Mandat und Beitrag in einem Dialog“ auf (früher stand dort „in drei Schritten“). Prüfe in Phase 6, ob die Oberfläche das einlöst.

### 1.5 Reiter „Beiträge“ im Überblick

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne „Beiträge“ und sieh dir die drei Unterreiter an.
2. Klicke nacheinander auf „Mitglieder“, „Einzug“ und „Beitragsgruppen“.

**Erwartet:**
- Drei Unterreiter: „Mitglieder“, „Einzug“, „Beitragsgruppen“. Bei „Mitglieder“ stehen oben rechts die Knöpfe „Liste einlesen“ und „Mitglied“.
- „Einzug“ zeigt die Segmente „Zeitstrahl & Läufe“, „Forderungen“ und „Bankabgleich“.
- „Beitragsgruppen“ zeigt die Karten „Beitragsgruppen“ und „Zuweisungen“. Einzelforderungen legst du unter „Einzug“ → „Forderungen“ an, den Terminplan öffnest du über den Knopf „Terminplan“ am Zeitstrahl (beides gibt es nur dort, nicht doppelt).

## Phase 2 – Einstellungen

**Ziel:** Du prüfst alle Einstellungen des Beitragsmoduls, so wie ein Verwalter sie einmal für den ganzen Verein festlegt, und stellst das Konto für die Verbuchung ein.

**Nutzer:** admin (Verwalter).

**Vorbedingung:** Phase 1. Die Einstellungen liegen in den Nextcloud-Einstellungen und sind nur für Verwalter zugänglich.

### 2.1 Einstellungsseite finden

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne `http://stable34.local/index.php/settings/admin/vereinsbuchhaltung` (in Nextcloud: Verwaltungseinstellungen, Abschnitt „Vereinsbuchhaltung“, Beschriftung des Menüpunkts nicht belegt).
2. Scrolle die Seite einmal von oben nach unten durch.

**Erwartet:**
- Die Seite hat die Abschnitte „Verein“, „Darstellung“ (Vorzeichen und Farbe an Beträgen, seit 0.34.6; ab Werk neutral), „Belege“, „Bankdaten“, „Beiträge & SEPA“, „Mandats-Rechtstext“, „Berechtigungen“, „Geschäftsjahr“ und „Daten“.
- „Beiträge & SEPA“ beginnt mit dem Hinweis „Rein optionales Zusatzmodul für Vereine, die Mitgliedsbeiträge per Lastschrift einziehen …“ und enthält die Karten „Grundeinstellungen“, „Standard-Beitrag“, „Beitragsjahr und Einzugszyklus“, „Ablage der Einzugsdatei (XML)“, „Mandate“ und „Rücklastschriften und Mahnwesen“.
- Alle Karten laden ohne Fehlermeldung. Ein Ladefehler zeigt „Die weiteren Einstellungen des Beitragsmoduls konnten nicht geladen werden.“ mit dem Knopf „Erneut versuchen“.

### 2.2 Gläubiger-ID und einziehendes Konto

**Rolle:** admin (Verwalter)

**Tun:**
1. Lies in der Karte „Grundeinstellungen“ die Felder „SEPA-Gläubiger-ID“ und „Einziehendes Konto“.
2. Klappe die Auswahl „Einziehendes Konto“ auf.
3. Trage testweise bei „SEPA-Gläubiger-ID“ `XYZ` ein und klicke „Speichern“.
4. Lade die Seite neu (Wert zurücksetzen lassen).

**Erwartet:**
- Die Gläubiger-ID lautet `DE98ZZZ09999999999` und als einziehendes Konto ist 1200 gewählt (vom Seeder gesetzt).
- Die Auswahl listet Geldkonten als „Nummer · Name“ und „– Konto wählen –“.
- Für `XYZ` meldet die App: „Das sieht nicht nach einer SEPA-Gläubiger-ID aus (erwartet wird z. B. DE98ZZZ09999999999).“ Es wird nichts gespeichert.

**Beachte:** Das einziehende Konto braucht eine IBAN (Reiter „Konten“). Ohne sie gibt es keine GiroCodes (Phase 9) und keine Einzugsdatei. Lade nach dem Fehlversuch neu, sonst speicherst du `XYZ` beim nächsten „Speichern“ der Karte mit.

### 2.3 Schalter für den Reiter „Beiträge“ und den Self-Service

**Rolle:** admin (Verwalter)

**Tun:**
1. Sieh dir in „Grundeinstellungen“ die beiden Schalter und ihre Hinweistexte an.

**Erwartet:**
- Schalter 1: „Reiter „Beiträge“ in der Hauptnavigation zeigen (Mitgliederliste und Sammeleinzug)“. Der Hinweis „Ohne diesen Schalter bleibt der Reiter ausgeblendet, bis das erste Mandat oder der erste Beitrag angelegt wird.“ erscheint nur, solange der Schalter aus ist und noch kein Mitglied existiert.
- Schalter 2: „Self-Service „Mein Beitrag“ für verknüpfte Nextcloud-Konten freischalten“ ist eingeschaltet (vom Seeder; ab Werk ist er aus). Darunter: „Betrifft nur Mitglieder, deren Nextcloud-Konto in der Mitgliederakte verknüpft ist …“.

**Beachte:** Das Ausschalten des Self-Service-Schalters probierst du in 13.7.

### 2.4 Standard-Beitrag (optional)

**Rolle:** admin (Verwalter)

**Tun:**
1. Lies die Karte „Standard-Beitrag“ mit den Feldern „Betrag (€)“ und „Frequenz“.
2. Ist sie leer, lass sie leer. Sonst notiere die Werte.

**Erwartet:**
- Die Frequenz bietet „monatlich“, „vierteljährlich“, „halbjährlich“ und „jährlich“.
- Der Hinweis erklärt, dass „Mitglied aufnehmen“ und der CSV-Import Betrag und Frequenz vorschlagen. Nach dem neuen Beitragsmodell wirkt der Standard-Beitrag nur noch beim CSV-Import (Zeilen mit Startdatum, aber ohne eigenen Betrag, siehe 6.7). Notiere als Frage, falls du den Vorschlag im Aufnahme-Dialog erwartest.

### 2.5 Beitragsjahr und die drei Fristen vor dem Einzug

**Rolle:** admin (Verwalter)

**Tun:**
1. Lies die Karte „Beitragsjahr und Einzugszyklus“: „Beitragsjahr beginnt im“ und darunter, unter „Fristen vor dem Einzug“, die drei Felder „Vorwarnfenster (Tage vor Einzug)“, „Vorabinfo-Vorlauf (Tage vor Einzug)“ und „Freigabe-Vorlauf (Tage vor Einzug)“.
2. Trage bei „Freigabe-Vorlauf (Tage vor Einzug)“ `0` ein und klicke „Speichern“.
3. Stelle den Wert wieder auf `5` und speichere.
4. Lies die Hinweise unter den Feldern.

**Erwartet:**
- Beitragsjahr beginnt im Januar. Die Fristen stehen laut Seeder auf 35 Tage (Vorwarnfenster), 30 Tage (Vorabinfo-Vorlauf) und 5 Tage (Freigabe-Vorlauf), damit heute die Vorabinfo zum 01.11. fällig ist; die Standardwerte der App wären 21, 14 und 5.
- Für `0` erscheint eine Meldung, die „Freigabe-Vorlauf“ nennt und „zwischen 1 und 365“ verlangt; nichts wird gespeichert. Für die beiden anderen Felder gilt dieselbe Grenze.
- Nach dem Speichern von `5` erscheint „Einstellungen gespeichert.“.
- Die Hinweise beschreiben die Fristen statt auf einen Link zu verweisen: „Vorwarnfenster: ab dann entstehen die Forderungen (Standard 21 Tage).“, „Vorabinfo-Vorlauf: ab dann geht die Ankündigung per Mail an die Mitglieder (Standard 14 Tage; …)“ und „Freigabe-Vorlauf: ab dann meldet die Aufgabenliste „Freigabe fällig“ (Standard 5 Tage).“ Darunter steht ein Beispiel mit deinen Werten („Beispiel – Einzug am …“).

**Beachte:** Das Ändern des Beitragsjahr-Beginns probierst du in 5.9. Die Fristen lassen sich nur hier ändern; der Terminplan im Einzug zeigt sie bloß an (2.6).

### 2.6 Fristen im Terminplan: nur Anzeige, Link zurück in die Einstellungen

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne **Beiträge** → **Einzug** und klicke am Zeitstrahl auf „Terminplan“.
2. Suche am Ende der Karte „Terminplan“ die Zeile mit den Fristen.
3. Klicke den Link „Fristen in den Einstellungen ändern“.

**Erwartet:**
- Die Fristen stehen nur als Anzeige da: „Vorwarnfenster: 35 Tage · Vorabinfo-Vorlauf: 30 Tage · Freigabe-Vorlauf: 5 Tage vor dem Einzug.“ (Werte vom Seeder). Es gibt dort keine Eingabefelder für sie; bearbeitet wird im Terminplan nur der Einzugstag (7.4).
- Als Verwalter siehst du darunter den Link „Fristen in den Einstellungen ändern“. Er führt zurück in die Nextcloud-Einstellungen zum Abschnitt „Beiträge & SEPA“ (2.5).

**Beachte:** Geändert wird hier nichts. Die Sicht des Buchhalters prüfst du in 13.2, das Zurückstellen auf 21 und 14 Tage ist optional (7.18).

### 2.7 Ablage der Einzugsdatei (XML)

**Rolle:** admin (Verwalter)

**Tun:**
1. Lies die Karte „Ablage der Einzugsdatei (XML)“.
2. Steht dort „Ablage-Nutzer:“ mit einem Namen, schalte „Einzugsdatei zusätzlich im Nextcloud-Ordner ablegen“ ein, lass „Ablageordner“ auf `SEPA-Einreichungen` und klicke „Speichern“.
3. Steht dort stattdessen der Hinweis, dass noch kein Nutzer gewählt ist, versuche trotzdem den Schalter einzuschalten und zu speichern.

**Erwartet:**
- Mit Ablage-Nutzer: „Einstellungen gespeichert.“ Der Ordner entsteht im Home dieses Nutzers beim ersten Freigeben (Schritt 7.15).
- Ohne Ablage-Nutzer: Der Hinweis „Der Ordner liegt im Home des Nutzers, der unter „Belege“ … gewählt ist. Dort ist noch keiner gewählt …“ steht da, und das Einschalten wird mit einer Meldung abgelehnt, die „Nextcloud-Nutzer“ nennt.
- Ein Ablageordner mit `../` wird mit einer Meldung abgelehnt, die „Ablageordner“ und „nicht erlaubt“ nennt.

**Beachte:** ⚠ Die Datei enthält alle IBANs im Klartext. Schalte die Ablage nach dem Test wieder aus. Die Kopien löscht weder das Ausschalten noch der Reset.

### 2.8 Mandate: Präfix, Nachweis-Ordner, Verfall-Vorwarnung

**Rolle:** admin (Verwalter)

**Tun:**
1. Lies die Karte „Mandate“: „Mandatsreferenz-Präfix“, „Ablauf-Vorwarnung (Tage vor Verfall)“, „Nachweis-Ordner“, Schalter „Auf Mandate ohne Nachweis hinweisen“.
2. Trage bei „Nachweis-Ordner“ `../fremd` ein, klicke „Speichern“ und lade die Seite danach neu.

**Erwartet:**
- Standard: Präfix `M` (die Referenzen des Seeders heißen `M-1` bis `M-14`), Ablauf-Vorwarnung 180 Tage, Nachweis-Ordner `SEPA-Mandate`, Schalter an.
- Der Ordner `../fremd` wird mit einer Meldung abgelehnt, die „Nachweis-Ordner“ und „nicht erlaubt“ nennt.
- Ohne Ablage-Nutzer steht die Warnung „Nachweise werden im Home des Nutzers abgelegt …“; bis dahin lassen sich keine Nachweise hochladen (relevant für 4.3).

**Beachte:** Den Präfix lässt du auf dem Standard, damit die Referenzen der Testdaten einheitlich bleiben.

### 2.9 Rücklastschriften und Mahnwesen: Konten einstellen

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne die Karte „Rücklastschriften und Mahnwesen“.
2. Wähle bei „Konto für Rücklastschriftgebühren (Aufwand)“ ein Aufwandskonto (z. B. Bankgebühren, Nummer 5400, falls vorhanden) und bei „Standard-Erlöskonto für Beitragsforderungen (Ertrag)“ ein Ertragskonto (z. B. 4000 „Mitgliedsbeiträge“). Der Seeder hat bereits 5400 als Gebührenkonto und 4000 als Erlöskonto gewählt; lass es dabei.
3. Schalte „Rücklastschriftgebühren an das Mitglied weiterbelasten“ **ein** (der Seeder lässt ihn aus).
4. Lass „Mahnabstand (Tage)“ auf `14` und klicke „Speichern“.
5. Optional: Wähle beim Gebührenkonto „– Konto wählen –“, lass den Schalter an und speichere. Stelle danach das Konto wieder ein und speichere erneut.
6. Lade die Seite neu.

**Erwartet:**
- „Einstellungen gespeichert.“ und nach dem Neuladen stehen die Werte noch da.
- Unpassende Konten sind mit „(nicht geeignet)“ gekennzeichnet.
- Schritt 5: Die Weiterbelastung ohne Konto wird mit einer Meldung abgelehnt, die „Konto für Rücklastschriftgebühren“ und „gewählt“ nennt.
- Ohne Standard-Erlöskonto ließen sich Beitragsforderungen ohne eigenes Erlöskonto weder als Einzug noch als Rücklastschrift verbuchen (Phase 8).

**Beachte:** Die Weiterbelastung brauchst du in Phase 8 und 9, um die Gebühren-Forderung zu sehen. Der Mahnabstand 0 wird mit einer Meldung zu „Mahnabstand“ abgelehnt.

### 2.10 Mandats-Rechtstext-Editor

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne den Abschnitt „Mandats-Rechtstext“. Lies „Aktuell gültig: Fassung N vom … (…)“.
2. Sieh dir „Pflichtblock (geschützt)“, das Feld „Rahmentext (optional)“ und die „Vorschau“ an.
3. Tippe in „Rahmentext (optional)“: `Testzusatz: Rückfragen an {{creditor_name}} richten Sie bitte an die Kassenführung.`
4. Beobachte „Vorschau“, den Zähler und die Knöpfe „Neue Version speichern“ und „Änderungen verwerfen“.
5. Klicke „Neue Version speichern“.
6. Klicke im „Versionsverlauf“ bei der früheren Fassung auf „Anzeigen“, danach auf „Ausblenden“.
7. Optional: Tippe `Text <!-- vbh:rahmen --> mehr` und danach 5001 Zeichen (z. B. `x` wiederholt) und beobachte die Meldungen; verwirf mit „Änderungen verwerfen“.

**Erwartet:**
- Der Pflichtblock ist nur Anzeige (kein Eingabefeld) und enthält „SEPA-Lastschriftmandat“ und den Platzhalter `{{creditor_name}}`.
- Die Vorschau ersetzt `{{creditor_name}}` durch den Vereinsnamen (ein „&“ im Namen steht als „&“, nicht als „&amp;“). Ist noch kein Vereinsname eingetragen, steht dort „den Verein“ und eine Warnung verweist auf den Abschnitt „Verein“.
- „Neue Version speichern“ ist erst nach einer Änderung aktiv; „Änderungen verwerfen“ erscheint erst dann.
- Nach dem Speichern: „Neue Fassung N gespeichert.“, die neue Fassung trägt „aktuell“, die frühere bleibt unverändert und lesbar. Der Hinweis nennt, dass bestehende Mandate die Fassung behalten, die ihnen beim Erteilen angezeigt wurde.
- Der interne Marker `vbh:rahmen` taucht nirgends sichtbar auf. Ein Text mit dem Marker wird als „reserviert“ abgelehnt, mehr als 5000 Zeichen als „zu lang“ (mit der Zahl); Speichern bleibt gesperrt.

**Beachte:** ⚠ Eine gespeicherte Fassung bleibt für immer im Verlauf. Du kannst später den Rahmentext leeren und das als neue Fassung speichern. In 4.10 siehst du den Zusatz auf der Zustimmungsseite eines neuen Mandats, aber nicht im Formular eines älteren.

### 2.11 Berechtigungen ansehen

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne den Abschnitt „Berechtigungen“ und lies die Tabelle „Nutzer / Gruppe“ mit der Rolle.

**Erwartet:**
- Der Hinweistext erklärt: „Verwalter dürfen alles inkl. Rechtevergabe, Buchhalter lesen und schreiben, Revisor nur lesen. Nextcloud-Administratoren sind immer Verwalter.“
- `alice` hat die Rolle Buchhalter, `bob` die Rolle Revisor (vom Seeder gesetzt). jane, john und user1 stehen nicht in der Liste.

## Phase 3 – Mitglieder & Akte

**Ziel:** Du bedienst die Mitgliederliste und die Akte: Suche, Filter, Stammdaten, Kontoverknüpfung, Austritt und Löschen.

**Nutzer:** admin (Verwalter). Buchhalter dürfen dasselbe.

**Vorbedingung:** Phase 2. Du legst hier vier Wegwerf-Mitglieder an, die du in den Phasen 4 bis 6 weiterverwendest.

### 3.1 Mitgliederliste lesen

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne **Beiträge** → **Mitglieder**.
2. Lies Summenzeile und Tabelle und öffne in einer Zeile das Menü ⋯.

**Erwartet:**
- Der Einleitungshinweis „Ein Mitglied wird unabhängig …“ steht nicht mehr da.
- Summenzeile „16 von 16 Mitgliedern · K mit Mandat · Beitragsaufkommen X € im Jahr“ (16 Mitglieder, Nummern 1001 bis 1016).
- Tabellenspalten: „Mitglied“, „Bankverbindung“, „Betrag“, „Frequenz“, „Nächste Fälligkeit“, „Zuweisung“ (Zustand der Zuweisung mit Statuspunkt, z. B. „aktiv“ oder „beendet 31.12.2013“). Hinter dem Namen steht die Mitgliedsnummer (z. B. „#1001“).
- „Betrag“ ist der Betrag je Periode (Monatsbeitrag × Turnus): Jana Hoffmann 15,00 € monatlich, Jonas Richter 45,00 € vierteljährlich, Lena Bergmann 22,50 € vierteljährlich, Sophie Krüger 180,00 € jährlich, Musikhaus Schmidt GmbH 60,00 € jährlich. Nadine Schuster hat keine Zuweisung (Betrag und Frequenz „–“).
- Bei Mandaten steht die IBAN, bei Bedarf mit einer Marke: Jonas Richter trägt „Entwurf“. Wer per Überweisung zahlt, steht mit „Überweisung“ statt „kein Mandat“ da (Lena Bergmann, Tobias Brandt trotz widerrufenen Mandats, Musikhaus Schmidt GmbH).
- Hans Becker (Austritt 31.12.2013) trägt die Marke „ausgetreten“ und hat keine E-Mail-Adresse.
- Zeilen ohne E-Mail zeigen „keine E-Mail – keine Vorankündigung möglich“ (laut Seeder nur Hans Becker).
- Am Zeilenende steht das Menü ⋯ („Aktionen“). Es bietet „Mitglied bearbeiten“, „Mandat verwalten“, „Beitrag ändern“ und „Beitragsgruppe wechseln“. Bei Mitgliedern ohne Zuweisung (Nadine Schuster) sind es „Mitglied bearbeiten“, „Mandat verwalten“ und „Beitrag zuweisen“. Den Stift „Zuweisung verwalten“ gibt es nicht mehr.

### 3.2 Suche und „nur Auffälligkeiten“

**Rolle:** admin (Verwalter)

**Tun:**
1. Tippe ins Feld „Suchen“ (Platzhalter „Name, IBAN, Mitgliedsnummer oder E-Mail“) nacheinander `Hoffmann`, `1004`, einen Teil einer IBAN und `zzz`.
2. Leere das Feld. Setze das Häkchen „nur Auffälligkeiten“.
3. Nimm das Häkchen wieder heraus.

**Erwartet:**
- Die Liste und die Summenzeile („N von N Mitgliedern“) passen sich beim Tippen an; `1004` findet Markus Fuchs.
- Bei `zzz`: „Kein Eintrag passt zur Suche.“
- „nur Auffälligkeiten“ zeigt Mitglieder ohne E-Mail-Adresse (Hans Becker) und solche mit Lastschrift-Zuweisung ohne Mandat; Überweiser wie Lena Bergmann, Tobias Brandt und das Musikhaus erscheinen nicht. Auch Jonas Richter steht dort: Seine Lastschrift-Zuweisung hat nur ein Mandat im Entwurf, und ein Entwurf zieht nichts ein (das Klemmbrett meldet dieselbe Lage als Handlungsbedarf).

### 3.3 Spalte „Nächste Fälligkeit“

**Rolle:** admin (Verwalter)

**Tun:**
1. Merke dir in der Liste für Jana Hoffmann, Lena Bergmann und Nadine Schuster den Wert der Spalte „Nächste Fälligkeit“.
2. Öffne **Einzug** → **Forderungen** und vergleiche mit der frühesten nicht beglichenen Forderung dieser Mitglieder.

**Erwartet:**
- Die Spalte zeigt das früheste Datum unter den noch fälligen Forderungen (offen, im Einzug oder zurückgegeben) im Format `TT.MM.JJJJ` (z. B. 01.11.2026), eine laufende Stundung zählt mit ihrem Ende. Ohne solche Forderung steht „–“.
- Laut Seeder: Jana `01.11.2026` (September bezahlt, Oktober im Lauf bereits eingezogen, November offen), Nadine `01.11.2026` (Einzelforderung), Lena das Datum ihrer offenen Q4-Forderung.
- Erledigte, stornierte und erlassene Forderungen zählen nicht.

**Beachte:** Die Spalte verändert sich in den Phasen 7 bis 9, wenn Forderungen entstehen, eingezogen oder bezahlt werden. Schau danach noch einmal hin.

### 3.4 Akte öffnen und Stammdaten ändern

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne die Akte von Jana Hoffmann: Klicke in der Liste auf ihren Namen oder im Zeilenmenü ⋯ auf „Mitglied bearbeiten“.
2. Scrolle durch die Akte.
3. Trage bei „Interne Notiz“ `Testnotiz nur intern` ein und ändere „Telefon“ auf `+49 30 1234567`.
4. Klicke „Speichern“.
5. Öffne die Akte erneut.

**Erwartet:**
- Der Dialog heißt „Mitglied: Jana Hoffmann“. Felder: „Mitgliedstyp“, „Vorname“, „Nachname“, „E-Mail“, „Telefon“, „Straße“, „PLZ“, „Ort“, „Land“ (Auswahlliste), „Mitgliedsnummer“, „Beigetreten am“, „Interne Notiz“ (Platzhalter „nicht im Self-Service sichtbar“).
- Darunter die Abschnitte „SEPA-Mandat“ (darin „Nachweis und Formular“), „Nextcloud-Konto“ („Verknüpft mit „jane“.“), „Beitragsbestätigung“, „Datenübersicht (Art. 15 DSGVO)“, „Anonymisierung (Art. 17 DSGVO)“, „Austritt“ und „Löschen“.
- „Mitglied gespeichert.“ erscheint, die Änderungen stehen beim erneuten Öffnen noch da.

**Beachte:** Die interne Notiz darf in „Mein Beitrag“ nie erscheinen (Prüfung in 11.1). Lass sie bis dahin stehen.

### 3.5 Wegwerf-Mitglieder anlegen

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke oben rechts auf „Mitglied“ (mit Plus-Symbol).
2. Tippe sofort los, ohne vorher zu klicken.
3. Lege nacheinander diese Mitglieder an (nur die Stammdaten, Abschnitte zu Mandat und Beitrag leer lassen), jeweils „Aufnehmen“:

| Vorname Nachname | E-Mail |
|---|---|
| Tina Testmandat | `tina.testmandat@example.org` |
| Theo Testlink | `theo.testlink@example.org` |
| Dora Testentwurf | `dora.testentwurf@example.org` |
| Willi Wegwerf | die E-Mail-Adresse des Nextcloud-Kontos `bob` (siehe Beachte) |

4. Probiere beim ersten Mitglied vor dem Aufnehmen: Nachname leer lassen.

**Erwartet:**
- Der Dialog „Mitglied aufnehmen“ öffnet mit dem Cursor im Feld „Vorname“ (sofort tippen funktioniert).
- Ohne Nachname ist „Aufnehmen“ gesperrt.
- Nach dem Aufnehmen: „Mitglied aufgenommen.“ und die Zeile erscheint mit „kein Mandat“.
- Die **Mitgliedsnummer bleibt leer**: Die App vergibt keine automatisch und bietet auch keinen Vorschlag an. „Beigetreten am“ ist mit dem heutigen Tag vorbelegt.

**Beachte:** Hat `bob` in Nextcloud keine E-Mail-Adresse, trage in der Nextcloud-Benutzerverwaltung eine ein (z. B. `bob@example.org`) und verwende sie für Willi.

### 3.6 Nextcloud-Konto verknüpfen: Vorschlag, dann Bestätigung

**Rolle:** admin (Verwalter), danach bob

**Tun:**
1. Öffne die Akte von Willi Wegwerf. Sieh dir den Abschnitt „Nextcloud-Konto“ an, bevor du etwas klickst.
2. Klicke „Vorschläge suchen“.
3. Klicke beim Vorschlag mit `bob` auf „Verknüpfen“.
4. Wechsle in Fenster C (bob), lade neu (⇧⌘R) und öffne die Reiter.

**Erwartet:**
- Vor dem Klick steht dort noch nichts „Verknüpft mit …“. Die Mailadresse liefert nur den Vorschlag, kein Treffer ist vorausgewählt.
- Der Vorschlag zeigt „bob (E-Mail)“ mit einem Knopf „Verknüpfen“. Gibt es keinen Treffer: „Kein Nextcloud-Konto mit dieser Mailadresse gefunden.“ Fehlt die E-Mail am Mitglied: „Ohne Mailadresse gibt es keinen Vorschlag – erst speichern, dann verknüpfen.“
- Nach dem Klick: „Verknüpft.“ und „Verknüpft mit „bob“.“.
- bob sieht jetzt neben dem Reiter „Beiträge“ den Reiter „Mein Beitrag“ (Self-Service folgt der Verknüpfung, nicht der Rolle) mit den Daten von Willi Wegwerf. Im Reiter „Beiträge“ hat bob weiter nur „Einzug“.

### 3.7 Verknüpfung wieder lösen

**Rolle:** admin (Verwalter), danach bob

**Tun:**
1. Klicke in Willis Akte beim Nextcloud-Konto auf „Verknüpfung lösen“.
2. Bestätige im Dialog mit „Lösen“.
3. Lade in Fenster C (bob) neu.

**Erwartet:**
- Der Dialog „Verknüpfung lösen“ fragt: „Die Verknüpfung mit dem Nextcloud-Konto lösen? Das Mitglied und seine Historie bleiben bestehen.“
- Danach: „Verknüpfung gelöst.“ und wieder der Knopf „Vorschläge suchen“.
- bob sieht den Reiter „Mein Beitrag“ nicht mehr.

### 3.8 Austritt erklären und zurücknehmen

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne die Akte von Willi Wegwerf, Abschnitt „Austritt“.
2. Wähle bei „Austrittsdatum“ ein Datum in der Zukunft und klicke „Austritt erklären“.
3. Klicke „Austritt zurücknehmen“.
4. Sieh dir zum Vergleich die Akten von Hans Becker (Austritt 31.12.2013) und Felix Maier (Austritt 31.12.2026) an.

**Erwartet:**
- „Austritt erklärt.“ und „Austritt zum {Datum}.“ mit dem Knopf „Austritt zurücknehmen“. Danach „Austritt zurückgenommen.“ und wieder das Datumsfeld.
- Ein Austritt in der Zukunft ist erlaubt; das Mitglied bleibt bis dahin aktiv. Hans Becker trägt in der Liste „ausgetreten“.

**Beachte:** Die Spec (§3.1) nennt den Austritt einen „Vorgang mit Wirkungsvorschau“. In der Akte gibt es nur Datum und Knopf. Notiere es als Frage, wenn du eine Vorschau vermisst.

### 3.9 Löschsperre und Löschen

**Rolle:** admin (Verwalter)

**Tun:**
1. Scrolle in Janas Akte zum Abschnitt „Löschen“.
2. Öffne die Akte von Willi Wegwerf, Abschnitt „Löschen“, und klicke „Mitglied löschen“.
3. Bestätige den Dialog.

**Erwartet:**
- Bei Jana ist „Mitglied löschen“ nicht möglich: Statt des Knopfes steht eine Hinweiskarte „Löschen nicht möglich“ mit „Es hängt noch am Mitglied:“ und einer Liste der Hindernisse („Ein aktives SEPA-Mandat“, „Eine Zuweisung zu einer Beitragsgruppe“, „Eine Forderung“; nicht ausgegraut). Darunter: „Sie bleiben als Nachweis erhalten. Für Datenschutzfälle gibt es die Anonymisierung.“
- Bei Willi: Dialog „Mitglied löschen“ mit „Mitglied „Willi Wegwerf“ endgültig löschen?“. Nach dem Bestätigen: „Mitglied gelöscht.“ und die Zeile ist weg.

**Beachte:** ⚠ Das Löschen ist unumkehrbar. Die Sperre ist strenger als die Spec: Auch ein beendetes oder verworfenes Mandat verhindert das Löschen (prüfst du in 4.12); die Liste nennt es dann als „Ein SEPA-Mandat (Entwurf, ausgesetzt oder beendet)“.

### 3.10 Doppelte Mitgliedsnummer

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne die Akte von Dora Testentwurf.
2. Trage bei „Mitgliedsnummer“ `1001` ein und klicke „Speichern“.
3. Leere das Feld wieder und speichere.

**Erwartet:**
- Die App lehnt ab mit einer Meldung wie „Diese Mitgliedsnummer ist schon vergeben: 1001“ (kein Rohtext wie „Request failed“).
- Mit leerem Feld speichert sie wieder.

## Phase 4 – Mandate

**Ziel:** Du führst ein Mandat durch seinen ganzen Lebenszyklus: Papier-Weg, elektronische Zustimmung, Entwurf korrigieren und verwerfen, Bankverbindung ändern, Sperre und Widerruf.

**Nutzer:** admin (Verwalter); für die Zustimmungsseite ein privates Fenster ohne Anmeldung.

**Vorbedingung:** Die Wegwerf-Mitglieder aus 3.5 (Tina, Theo, Dora) existieren, haben aber kein Mandat. Das Mandat führst du immer über das Menü (⋯) der Zeile → „Mandat verwalten“.

### 4.1 Das Mandat in der Akte lesen (Jana)

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke in der Zeile von Jana Hoffmann auf das Menü (⋯) und wähle „Mandat verwalten“. Die Akte öffnet beim Abschnitt „SEPA-Mandat“.
2. Lies die Statusmarke, die Felder und die Knöpfe. Klappe „Verlauf“ auf.
3. Klicke noch nichts, was den Zustand ändert.

**Erwartet:**
- Überschrift „SEPA-Mandat“, Statusmarke „Aktiv“ und die Mandatsreferenz daneben.
- Felder: „Mandatsreferenz“, „Kontoinhaber“, „IBAN“ (in Vierergruppen, für dich unmaskiert), „BIC“, „Unterschrift“ („Elektronisch (Einmal-Link oder Konto)“ mit Datum), „Zustimmung“ (Zeitpunkt, Konto und IP, soweit der Seeder sie gesetzt hat), „Läuft ab (36-Monats-Regel)“ mit Datum und „in N Tagen“, „Nachweis“ („fehlt“ oder „vorhanden“), „Aktiviert“. Die Referenz lautet `M-1`, die Unterschrift trägt das Datum 20.08.2026 (vom Seeder).
- Knöpfe bei einem aktiven Mandat: „Bankverbindung ändern“, „Sperren“, „Mandat widerrufen“; darunter „Nachweis und Formular“ mit „Nachweis hochladen“ und „Mandatsformular öffnen“.
- „Verlauf (N Einträge)“ listet jede Änderung mit Zeitpunkt, Text und Urheber („Verein (admin)“, „Mitglied (jane)“ oder „System“).

### 4.2 Papier-Weg: Entwurf anlegen und per Unterschriftsdatum aktivieren (Tina)

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne bei Tina Testmandat „Mandat verwalten“.
2. Klicke „Mandat anlegen“. Lass „Art der Unterschrift“ auf „Papier“, trage bei „IBAN“ `DE02 1203 0000 0000 2020 51` ein, lass „Kontoinhaber“, „Mandatsreferenz“ und „Mandat unterschrieben am“ leer.
3. Klicke noch einmal „Mandat anlegen“ (der erste Klick öffnet das Formular).
4. Klicke „Aktivieren“. Beobachte, dass „Mandat aktivieren“ gesperrt ist, solange kein Datum steht.
5. Trage bei „Mandat unterschrieben am“ ein Datum von vor mehr als 36 Monaten ein (z. B. vor vier Jahren).
6. Trage stattdessen das heutige Datum ein und klicke „Mandat aktivieren“.

**Erwartet:**
- Hinweis unter dem Formular: „Ohne Unterschriftsdatum bleibt das Mandat ein Entwurf („Unterschrift fehlt“) – erst das Datum aktiviert es sofort.“
- Nach Schritt 3: „Mandat als Entwurf angelegt – die Unterschrift fehlt noch.“ Statusmarke „Entwurf“, Titel „Unterschrift fehlt“, „Kontoinhaber: Tina Testmandat“ (vorbefüllt mit dem Anzeigenamen), eine automatisch vergebene Mandatsreferenz (Präfix und laufende Nummer, nach den 14 Referenzen des Seeders also etwa „M-15“), „Läuft ab (36-Monats-Regel): beginnt mit der Aktivierung“.
- Schritt 5: Warnung „Das Unterschriftsdatum liegt mehr als 36 Monate zurück – das Mandat würde nach der Aktivierung sofort verfallen. Bitte prüfen Sie das Datum.“
- Schritt 6: „Mandat aktiviert.“ Statusmarke „Aktiv“, „Läuft ab (36-Monats-Regel)“ zeigt das heutige Datum plus 36 Monate, „Nachweis: fehlt“ und der Hinweis „Mandat ohne Nachweis“.
- Das Unterschriftsdatum ist Pflicht und das Gate: Ohne Datum kein aktives Mandat.

**Beachte:** Ab jetzt ist Tinas Mandat einzugsfähig, aber sie hat keine Zuweisung. Es entsteht also nichts, was eingezogen wird.

### 4.3 Nachweis hochladen und Mandatsformular öffnen

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke bei Tinas aktivem Mandat auf „Nachweis hochladen“ und wähle eine kleine PDF- oder PNG-Datei (irgendeine Testdatei).
2. Klicke „Nachweis herunterladen“.
3. Klicke „Mandatsformular öffnen“ (neuer Tab) und drucke mit Strg+P bzw. ⌘P in die Vorschau.
4. Sieh in der Nextcloud-Dateien-App im Home des Ablage-Nutzers (Einstellung „Belege“) nach dem Ordner „SEPA-Mandate“ (Name laut Einstellung „Nachweis-Ordner“).

**Erwartet:**
- „Nachweis hinterlegt.“, „Nachweis: vorhanden“, und der Hinweis „Mandat ohne Nachweis“ verschwindet. Aus „Nachweis hochladen“ wird „Nachweis ersetzen“.
- Der Download liefert dieselbe Datei.
- Das Formular ist druckfertig: Überschrift „SEPA-Lastschriftmandat“, Vereinsname, der Rechtstext (Pflichtblock plus Rahmen, ohne sichtbaren Marker `vbh:rahmen`), darunter die Pflichtangaben „Mandatsreferenz“, „Kontoinhaber“, „IBAN“, „Gläubiger-Identifikationsnummer“, „Zahlungsart“ („wiederkehrende Zahlung (SEPA-Basislastschrift)“) und „Datum“.
- Die Datei liegt als echte Nextcloud-Datei im Nachweis-Ordner (kein Datenbank-BLOB).
- Ohne Ablage-Nutzer scheitert der Upload mit einer verständlichen Meldung („Der Nachweis konnte nicht hochgeladen werden …“).

### 4.4 Sperren und Entsperren

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke bei Tinas aktivem Mandat auf „Sperren“. Beobachte, dass „Mandat sperren“ erst nach einer Eingabe aktiv wird.
2. Trage bei „Grund der Sperre (Pflicht)“ `Rückfrage beim Mitglied` ein und klicke „Mandat sperren“.
3. Öffne das Klemmbrett (Aufgaben).
4. Klicke bei Tina „Entsperren“, trage bei „Notiz zur Klärung (Pflicht)“ `Konto telefonisch bestätigt` ein und klicke „Mandat entsperren“.
5. Aktualisiere das Klemmbrett („Aufgaben aktualisieren“) und klappe den „Verlauf“ auf.

**Erwartet:**
- „Mandat gesperrt.“, Statusmarke „Ausgesetzt“, Titel „Klärung offen“. Zeilen „Sperre: manuell gesperrt seit … (von admin)“ und „Notiz zur Sperre: Rückfrage beim Mitglied“.
- Im Klemmbrett unter „Handlungsbedarf“: „Tina Testmandat: Mandat gesperrt, Klärung offen. Solange wird nichts eingezogen.“ mit der Notiz.
- Nach dem Entsperren: „Mandat entsperrt.“, Statusmarke „Aktiv“, und die Aufgabe verschwindet nach „Aufgaben aktualisieren“.
- Der Verlauf nennt „Mandat gesperrt: Rückfrage beim Mitglied“ und „Mandat entsperrt: Konto telefonisch bestätigt“, beide „Verein (admin)“.

**Beachte:** Es gibt keine Auto-Entsperrung. Eine Sperre beendet nichts, offene Forderungen bleiben offen.

### 4.5 Bankverbindung ändern: nur die IBAN (Amendment)

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke bei Tinas aktivem Mandat auf „Bankverbindung ändern“.
2. Lass „Gleicher Kontoinhaber, nur die IBAN hat sich geändert“ gewählt. Beobachte das Feld „Neue IBAN“ und den Knopf „IBAN ändern“.
3. Trage `DE89 3704 0044 0532 0130 00` ein und klicke „IBAN ändern“.

**Erwartet:**
- Der Dialog „Bankverbindung ändern“ nennt oben „Mandat {Referenz} von {Kontoinhaber}, bisherige IBAN …“ und erklärt „Dasselbe Mandat bleibt bestehen, eine neue Unterschrift ist nicht nötig. Die Änderung wird der Bank beim nächsten Einzug als Amendment gemeldet.“
- „Neue IBAN“ ist mit der bisherigen vorbelegt, „IBAN ändern“ bleibt gesperrt, solange nichts geändert ist.
- Toast „Bankverbindung geändert – die Bank erfährt es mit dem nächsten Einzug.“ Statusmarke bleibt „Aktiv“, die Mandatsreferenz bleibt gleich, kein neues Mandat.
- Neuer Abschnitt „Änderungen der Bankverbindung“: „Bisherige IBAN … · {Zeitpunkt}“ mit der Marke „offen – noch nicht an die Bank gemeldet“.

**Beachte:** Sobald ein eingereichter Einzug die Änderung trägt, wechselt die Marke auf „an die Bank gemeldet“ und der Knopf „Als nicht gemeldet zurücksetzen“ erscheint.

### 4.6 Namen stillschweigend korrigieren

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke „Bankverbindung ändern“ und wähle „Derselbe Kontoinhaber, nur der Name war falsch geschrieben“.
2. Trage bei „Kontoinhaber“ `Tina Testmandat-Meier` ein und klicke „Name korrigieren“.

**Erwartet:**
- Hinweis „Stille Korrektur (Tippfehler, Heirat): kein Amendment, kein neues Mandat. Nur wählen, wenn es dieselbe Person bleibt …“.
- „Kontoinhaber korrigiert.“ Der Kontoinhaber steht neu im Mandat; die Liste „Änderungen der Bankverbindung“ bekommt **keinen** neuen Eintrag.

### 4.7 Kontoinhaberwechsel erzwingt ein neues Mandat

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke „Bankverbindung ändern“ und wähle „Der Kontoinhaber wechselt (andere Person)“.
2. Lies den roten Kasten und den Hinweis darunter. Klicke den hervorgehobenen Knopf „Ich habe nur ein neues Konto → IBAN ändern“.
3. Wähle wieder „Der Kontoinhaber wechselt (andere Person)“. Trage „Neue IBAN“ `DE44 5001 0517 5407 3249 31`, „Neuer Kontoinhaber“ `Maria Neuinhaberin` ein, lass „Neues Mandat unterschrieben am“ leer und klicke „Kontoinhaber wechseln“.
4. Aktiviere den neuen Entwurf wie in 4.2 mit dem heutigen Datum („Aktivieren“, „Mandat aktivieren“).

**Erwartet:**
- Roter Kasten: „Das bisherige Mandat wird endgültig beendet. Für den neuen Kontoinhaber entsteht ein neues Mandat, das eine eigene Unterschrift braucht. Das lässt sich nicht rückgängig machen.“ (bei offenen Forderungen zusätzlich „Noch offen: …“).
- Der Ausweg „Ich habe nur ein neues Konto → IBAN ändern“ ist die **Primäraktion** (auffälliger Knopf) und schaltet zurück auf „Gleicher Kontoinhaber, nur die IBAN hat sich geändert“.
- Nach Schritt 3: „Kontoinhaber gewechselt – das neue Mandat ist ein Entwurf, bis die Unterschrift vorliegt.“ Das neue Mandat hat den Status „Entwurf“ und den Kontoinhaber „Maria Neuinhaberin“; unter „Frühere Mandate“ steht das alte mit „Ersetzt (Kontoinhaberwechsel)“.
- Nach Schritt 4: „Mandat aktiviert.“

**Beachte:** Eine Namensänderung derselben Person ist nach SEPA kein Wechsel. Das Mandat ist die Erlaubnis des Kontoinhabers, deshalb braucht eine andere Person ein neues Mandat.

### 4.8 Widerruf mit Reibungsdialog

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke bei Tinas aktivem Mandat auf „Mandat widerrufen“.
2. Lies den Dialog. Klicke „Ich habe nur ein neues Konto → IBAN ändern“ und danach im folgenden Dialog „Abbrechen“.
3. Klicke noch einmal „Mandat widerrufen“ und dann „Mandat endgültig widerrufen“.

**Erwartet:**
- Dialog „Mandat widerrufen“ mit rotem Kasten: „Der Widerruf ist endgültig – ein widerrufenes Mandat lässt sich nicht wieder aktivieren. Für künftige Einzüge braucht Tina Testmandat danach ein neues Mandat mit neuer Unterschrift.“ Bei offenen Forderungen zusätzlich die Summe und „– dafür geht dem Mitglied eine Zahlungsaufforderung zu.“
- Der Ausweg ist der Primärknopf; er öffnet „Bankverbindung ändern“ im IBAN-Modus und widerruft nichts. Es gibt keine Zweitfaktor-Abfrage.
- Nach dem Widerruf: „Mandat widerrufen.“ Hinweis „neues Mandat einholen“, unter „Frühere Mandate“ die Einträge „Widerrufen“ und „Ersetzt (Kontoinhaberwechsel)“. Es gibt keinen Knopf zum Reaktivieren; „Mandat anlegen“ ist wieder möglich.

**Beachte:** ⚠ Unumkehrbar für dieses Mandat. Tina bekommt in 4.11 ein weiteres.

### 4.9 Elektronisch: Einmal-Link senden (Theo)

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne bei Theo Testlink „Mandat verwalten“ und klicke „Mandat anlegen“.
2. Wähle bei „Art der Unterschrift“ „Elektronisch (Einmal-Link per Mail)“, trage bei „IBAN“ `DE12 5001 0517 0648 4898 90` ein und klicke „Mandat anlegen“.
3. Lies das Panel. Öffne Mailhog (http://mail.local/) und die neue Mail.

**Erwartet:**
- Die Option „Elektronisch (Einmal-Link per Mail)“ ist nur wählbar, wenn das Mitglied eine E-Mail-Adresse hat. Darunter: „Nach dem Anlegen geht sofort ein Einmal-Link an die Mailadresse des Mitglieds – die Zustimmung dort aktiviert das Mandat.“
- Toast: „Entwurf angelegt, Einmal-Link an theo.testlink@example.org verschickt.“ Statusmarke „Entwurf“, Zeile „Einmal-Link: gesendet am … an … · gültig bis …“, Hinweis „Der Einmal-Link ist unterwegs – das Mandat wird aktiv, sobald das Mitglied zustimmt.“
- Es gibt **keinen** Knopf „Aktivieren“ (elektronische Entwürfe aktivieren sich bei Zustimmung selbst), aber „Einmal-Link erneut senden“. Zusätzlich steht der Link zum Weitergeben in einem Feld „Einmal-Link“ („Einmal-Link an … verschickt. Kommt die Mail nicht an, können Sie den Link auch direkt weitergeben:“).
- Mailhog: Betreff „Bitte bestätigen Sie Ihr SEPA-Lastschriftmandat“, Überschrift „SEPA-Lastschriftmandat bestätigen“, Text „… bittet Sie, das SEPA-Lastschriftmandat mit der Referenz … elektronisch zu bestätigen.“, Satz „Mit einem Klick auf die Schaltfläche sehen Sie den vollständigen Mandatstext und können zustimmen.“, Knopf „Jetzt bestätigen“ und „Dieser Link ist 14 Tage gültig und nur einmal verwendbar.“ Die Mail steht in Sie-Form (Theo hat kein Nextcloud-Konto).

### 4.10 Zustimmungsseite ohne Anmeldung

**Rolle:** Du in einem privaten Fenster ohne Nextcloud-Anmeldung

**Tun:**
1. Öffne die Link-Adresse aus der Mail (Knopf „Jetzt bestätigen“ oder das Feld „Einmal-Link“ in Theos Akte) im privaten Fenster.
2. Lies die Seite. Klicke „Ich stimme zu und erteile das Mandat“.
3. Lade die Seite neu.
4. Verändere im Link einen Buchstaben und öffne ihn.
5. Lade in Fenster A Theos Akte neu.
6. Öffne zum Vergleich in Janas Akte „Mandatsformular öffnen“.

**Erwartet:**
- Die Seite heißt „SEPA-Lastschriftmandat“, zeigt den Vereinsnamen, „Bitte lesen Sie den folgenden Mandatstext und bestätigen Sie am Ende der Seite Ihre Zustimmung.“, den Rechtstext (jetzt mit dem Testzusatz aus 2.10), den Datenblock („Mandatsreferenz“, „Kontoinhaber“, „IBAN“, „Gläubiger-Identifikationsnummer“, „Zahlungsart“, „Datum“) und den Knopf „Ich stimme zu und erteile das Mandat“ mit dem Hinweis, dass das Mandat sofort aktiv wird. Kein Marker `vbh:rahmen` ist sichtbar.
- Nach der Zustimmung: Kasten „Bereits bestätigt – Sie haben diesem SEPA-Lastschriftmandat bereits zugestimmt (am …). Es ist aktiv, eine erneute Bestätigung ist nicht nötig.“ Beim Neuladen steht dieselbe Meldung (kein Fehler, keine zweite Aktivierung).
- Der veränderte Link zeigt „Link ungültig“ (HTTP 404) statt eines Serverfehlers.
- Theos Akte: Statusmarke „Aktiv“, Zeile „Zustimmung: {Zeit} durch theo.testlink@example.org (IP …)“, Nachweis zur Fassung des Mandatstextes im Verlauf.
- Das Formular von Jana (älteres Mandat) zeigt den neuen Testzusatz **nicht**, weil bestehende Mandate die Fassung behalten, die ihnen beim Erteilen angezeigt wurde (der Seeder legt Janas Mandat vor der Textänderung an; weicht es ab, notiere es).

### 4.11 Elektronischer Entwurf korrigieren: der alte Link wird ungültig

**Rolle:** admin (Verwalter), danach privates Fenster

**Tun:**
1. Lege bei Tina Testmandat mit „Mandat anlegen“ ein elektronisches Mandat an (wie in 4.9), diesmal mit der Tippfehler-IBAN `DE12 5001 0517 0648 4898 99`.
2. Kopiere die Adresse aus dem Feld „Einmal-Link“ und öffne sie im privaten Fenster (nur ansehen, nicht zustimmen).
3. Klicke in der Akte auf „Entwurf korrigieren“. Beobachte, dass der Knopf „Entwurf korrigieren“ gesperrt ist, solange nichts geändert ist. Ändere die IBAN auf `DE12 5001 0517 0648 4898 90` und bestätige.
4. Lade im privaten Fenster den alten Link neu.
5. Klicke „Einmal-Link senden“ und öffne den neuen Link.

**Erwartet:**
- Der Dialog „Entwurf korrigieren“ ist mit IBAN, BIC und Kontoinhaber vorbelegt und weist darauf hin: „Der bereits verschickte Einmal-Link wird mit der Korrektur ungültig …“.
- Toast: „Entwurf korrigiert. Der bisherige Einmal-Link ist ungültig – senden Sie dem Mitglied einen neuen.“ Die Zeile „Einmal-Link“ steht auf „noch nicht versendet“.
- Der alte Link zeigt „Link ungültig“, der neue lädt die Zustimmungsseite.
- Der Verlauf nennt „Entwurf korrigiert: IBAN DE12 …“ mit **maskierter** IBAN; es gibt kein Amendment und kein neues Mandat.

### 4.12 Entwurf verwerfen (Dora) und Löschsperre

**Rolle:** admin (Verwalter)

**Tun:**
1. Lege bei Dora Testentwurf ein Papier-Mandat ohne Unterschriftsdatum an (IBAN `DE12 5001 0517 0648 4898 99`).
2. Klicke „Entwurf korrigieren“, beobachte den Hinweistext und brich ab.
3. Klicke „Entwurf verwerfen“. Klicke im Dialog zuerst „Entwurf stattdessen korrigieren“ (Ausweg) und danach „Abbrechen“.
4. Klicke wieder „Entwurf verwerfen“. Gib bei „Grund des Verwerfens (Pflicht)“ nur Leerzeichen ein, danach `Tippfehler, Mitglied meldet sich neu`, und klicke „Entwurf endgültig verwerfen“.
5. Klappe unter „Frühere Mandate“ den Eintrag auf.
6. Beachte, dass „Mandat anlegen“ wieder angeboten wird, und scrolle in Doras Akte zum Abschnitt „Löschen“.
7. Optional: Verwirf Tinas elektronischen Entwurf aus 4.11 und beobachte den Dialogtext.

**Erwartet:**
- Papier-Korrektur: Der Dialog weist darauf hin, dass die Angaben „zum unterschriebenen Formular passen“ müssen (kein Satz zum Einmal-Link).
- Dialog „Mandats-Entwurf verwerfen“: „Eingezogen wurde über ihn nie etwas …“; „Entwurf endgültig verwerfen“ bleibt gesperrt, solange der Grund leer oder nur Leerzeichen ist.
- Toast „Entwurf verworfen.“, Hinweis „neues Mandat einholen“. Unter „Frühere Mandate“: „Entwurf verworfen“ (kein „Widerrufen“); der Verlauf nennt „Entwurf verworfen: Tippfehler, Mitglied meldet sich neu“ mit „Verein (admin)“.
- „Mandat anlegen“ ist wieder möglich.
- Beim Löschen steht statt des Knopfes die Hinweiskarte „Löschen nicht möglich“ mit „Es hängt noch am Mitglied:“ und der Liste „Ein SEPA-Mandat (Entwurf, ausgesetzt oder beendet)“ als einzigem Hindernis.
- Bei einem elektronischen Entwurf nennt der Dialog: „Ein bereits verschickter Einmal-Link wird ungültig …“.

**Beachte:** Das Verwerfen löst keine Zahlungsaufforderung aus, der Widerruf eines aktiven Mandats schon. Die Löschsperre gilt auch bei einem verworfenen Entwurf (bekannte Entscheidung, strenger als die Spec).

### 4.13 Kontoinhaber ungleich Mitglied (Mara Lindner)

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne bei Mara Lindner „Mandat verwalten“ und lies „Kontoinhaber“.
2. Klicke „Mandatsformular öffnen“ und lies den Datenblock.
3. Notiere die Mandatsreferenz für 7.10.

**Erwartet:**
- Das Mandat `M-7` hat als Kontoinhaberin „Petra Lindner“, nicht Mara. Das Mandat gehört dem Kontoinhaber, der Zahler im Sinn der App bleibt das Mitglied Mara.
- Das Formular nennt „Kontoinhaber: Petra Lindner“.
- In 7.10 steht Petra Lindner in der Einzugsdatei als Zahlungspflichtige; die Vorabinfo (7.7) geht an die Mailadresse des Mitglieds und nennt das Mitglied.

### 4.14 Verfall-Vorwarnung (Nadine Schuster)

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne bei Nadine Schuster „Mandat verwalten“.
2. Öffne das Klemmbrett und suche ihren Eintrag.

**Erwartet:**
- Das Mandat `M-6` wurde zuletzt am 01.12.2023 vorgelegt („Zuletzt eingereicht für: 01.12.2023“). „Läuft ab (36-Monats-Regel)“ zeigt deshalb den **01.12.2026** („in 57 Tagen“ am 05.10.2026), darunter der Hinweis „Mandat läuft in N Tagen ab“ mit „Die 36-Monats-Frist endet am … Ohne Einzug bis dahin erlischt das Mandat automatisch; jeder eingereichte Einzug setzt die Frist neu in Gang.“
- Im Klemmbrett unter „Hinweise“: „Nadine Schuster: Mandat verfällt am … (in N Tagen)“. Hinweise zählen nicht im Badge.

**Beachte:** Der Hinweis verschwindet, wenn ihr Mandat in einen eingereichten Lauf kommt (7.14): Der Einzug setzt die Frist neu. Merke dir den Stand hier, damit du ihn danach vergleichen kannst.

## Phase 5 – Beitragsgruppen, Zuweisungen, Forderungen

**Ziel:** Du prüfst das Regelwerk (Gruppen, Zuweisungen, Untergrenzen), die Prorata-Rechnung und die manuellen Einzelforderungen.

**Nutzer:** admin (Verwalter).

**Vorbedingung:** Phase 3 und 4. Du verwendest die Wegwerf-Mitglieder weiter und legst vier Einzelforderungen an, die in Phase 9 und 12 gebraucht werden.

### 5.1 Beitragsgruppen und Zuweisungen lesen

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne **Beiträge** → **Beitragsgruppen** und lies die Karten „Beitragsgruppen“ und „Zuweisungen“.

**Erwartet:**
- Karte „Beitragsgruppen“: Spalten „Name“, „Untergrenze“, „Standard“, „Turnusse“ (als Worte, z. B. „monatlich, vierteljährlich, jährlich“), „Status“ (aktiv/inaktiv); je Zeile der Knopf „Bearbeiten“ und das Menü ⋯ mit „Untergrenze anheben“ und „Löschen“; oben „+ Beitragsgruppe“. Vier Gruppen (Untergrenze / Standard je Monat): Vollmitglied 12,00 € / 15,00 € (monatlich, vierteljährlich, halbjährlich, jährlich), Ermäßigt 5,00 € / 7,50 € (monatlich, vierteljährlich, jährlich), Jugend 3,00 € / 5,00 € (monatlich, jährlich), Fördermitglied 4,00 € / 5,00 € (nur jährlich, also 60,00 € im Jahr). Eine Gruppe „50,00 € im Jahr“ gibt es nicht: Der Monatsbeitrag ist das Atom und lässt sich nicht in ganzen Cent aus 50,00 € ableiten.
- Karte „Zuweisungen“: Spalten „Mitglied“, „Beitragsgruppe“, „Monatsbeitrag“, „Turnus“ (als Wort), „Gültig ab“, „Gültig bis“ und „Status“ (z. B. „aktiv“, „beendet 31.12.2013“); bei laufenden und künftigen Zuweisungen ein Zeilenmenü ⋯ mit „Beitrag ändern“, „Beitragsgruppe wechseln“ und „Zuweisung beenden“ (bei einer künftigen Zuweisung „Zuweisung zurücknehmen“); oben „+ Zuweisung“.
- Mehr gibt es auf dieser Seite nicht: Einzelforderungen und Terminplan stehen im Reiter „Einzug“.

### 5.2 Beitragsgruppe anlegen, ändern und die Untergrenze nur absenken

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke „+ Beitragsgruppe“. Trage „Name“ `Testgruppe Probe`, „Untergrenze (€/Monat)“ `5`, „Standardbeitrag (€/Monat)“ `8` ein, kreuze bei „Erlaubte Turnusse“ „monatlich“, „vierteljährlich“ und „jährlich“ an, wähle „Standard-Turnus“ „vierteljährlich“ und lass „Aktiv“ an. Klicke „Anlegen“.
2. Klicke bei der neuen Gruppe „Bearbeiten“, ändere den Namen auf `Testgruppe Probe 2` und klicke „Speichern“.
3. Öffne „Bearbeiten“ noch einmal und lies den Hinweistext zur Untergrenze.

**Erwartet:**
- Dialog „Neue Beitragsgruppe“, danach „Beitragsgruppe gespeichert.“ und die Zeile mit 5,00 und 8,00 und den Turnussen „monatlich, vierteljährlich, jährlich“.
- Dialog „Beitragsgruppe bearbeiten“ mit dem Hinweis „Eine Erhöhung der Untergrenze läuft über die eigene Funktion „Untergrenze anheben“ in der Gruppenliste – hier lässt sie sich nur absenken.“

### 5.3 Zuweisung mit Vorschau: angebrochene Monate zählen voll

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke bei „Zuweisungen“ auf „+ Zuweisung“.
2. Klicke „+ neues Mitglied“, trage „Vorname“ `Zora` und „Nachname“ `Zuweisung` ein und klicke „Mitglied anlegen“.
3. Wähle bei „Beitragsgruppe“ `Testgruppe Probe 2`. Beobachte „Monatsbeitrag (€)“, „Turnus“ und „Zahlungsart“.
4. Wähle „Turnus“ „vierteljährlich“ und „Zahlungsart“ „Überweisung“.
5. Wähle bei „Gültig ab“ einen Tag **mitten im zweiten Monat eines Quartals** (bei Beitragsjahr ab Januar: der 15. Februar, Mai, August oder November, der nächste zukünftige) und klicke „Vorschau“.
6. Klicke „Anlegen“.

**Erwartet:**
- Beim Wechseln der Gruppe ist der Monatsbeitrag mit dem Standardbeitrag der Gruppe vorbelegt (8,00 €).
- Die Vorschau lautet „Erste Periode: {Beginn} bis {Ende} ({N Monate}) · Einzugsbetrag {Betrag}“, z. B. für den 15. November „Erste Periode: 01.10.2026 bis 31.12.2026 (2 Monate) · Einzugsbetrag 16,00 €“. Gerechnet wird in ganzen Kalendermonaten ab dem Monat von „Gültig ab“ (der angebrochene Monat zählt voll) bis zum Periodenende: Monatsbeitrag × Anzahl.
- „Zuweisung angelegt.“ und eine neue Zeile in „Zuweisungen“. Zora Zuweisung steht in der Mitgliederliste mit „Überweisung“.

### 5.4 Pflichtregeln: nicht rückwirkend, keine Doppelzuweisung

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne „+ Zuweisung“, wähle Zora Zuweisung, `Testgruppe Probe 2` und trage bei „Gültig ab“ **gestern** ein. Klicke „Anlegen“.
2. Versuche dieselbe Gruppe für Zora mit „Gültig ab“ heute noch einmal.

**Erwartet:**
- Schritt 1: Die App lehnt ab mit der Meldung „Der Beginn einer Zuweisung darf nicht in der Vergangenheit liegen.“ (Nachforderungen laufen über eine Einzelforderung.)
- Schritt 2: Eine zeitlich überlappende zweite Zuweisung zur selben Gruppe wird abgelehnt (Meldungstext nicht belegt).
- Kein Rohtext wie „Request failed“ erscheint.

### 5.5 Untergrenze anheben mit Vorschau

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne bei `Testgruppe Probe 2` das Menü ⋯ und klicke „Untergrenze anheben“. Trage bei „Neue Untergrenze (€/Monat)“ `10` ein und klicke „Vorschau laden“.
2. Lies die Tabelle „Betroffene Zuweisungen“. Klicke „Anheben“.
3. Prüfe in „Zuweisungen“ Zoras Monatsbeitrag.
4. Öffne zum Ansehen bei einer **Seeder-Gruppe** (z. B. Vollmitglied) im Menü ⋯ „Untergrenze anheben“ mit `20`, klicke „Vorschau laden“, lies das Ergebnis und klicke dann „Abbrechen“.

**Erwartet:**
- Dialog „Untergrenze anheben“ mit dem Hinweis „Bereits eingezogene Perioden werden nie neu berechnet – die Änderung wirkt erst ab der nächsten Forderung.“
- Die Vorschau nennt Mitglied, „Bisher“ und „Neu“ (Zora: 8,00 € → 10,00 €). Zuweisungen mit individueller Untergrenze stehen separat: „Unberührt (individuelle Untergrenze): N Zuweisung(en)“.
- Nach „Anheben“ steht bei Zora der neue Monatsbeitrag. Die Tabelle „Zuweisungen“ lädt dabei von selbst neu, und der Standardbeitrag der Gruppe zieht mit auf die neue Untergrenze (hier 8,00 € auf 10,00 €).
- Bei der Gruppe Vollmitglied (Schritt 4) nennt die Vorschau neben den betroffenen Zuweisungen „Unberührt (individuelle Untergrenze): 1 Zuweisung“ (Anna Koch, siehe 5.6). Nichts wird übernommen.

**Beachte:** ⚠ Das „Anheben“ bei `Testgruppe Probe 2` ändert Zoras Zuweisung endgültig. Bei den Seeder-Gruppen nur die Vorschau ansehen und abbrechen.

### 5.6 Individuelle Untergrenze (Anna Koch) – nur Beobachtung

**Rolle:** admin (Verwalter)

**Tun:**
1. Suche in „Zuweisungen“, im Dialog „+ Zuweisung“ und in Anna Kochs Akte nach einer Stelle, an der sich eine **individuelle Untergrenze** einer Zuweisung setzen lässt.
2. Rufe in 5.5 noch einmal die Vorschau „Untergrenze anheben“ bei Vollmitglied auf und achte auf den Hinweis zu „Unberührt (individuelle Untergrenze)“.
3. Optional: Sieh die Untergrenze in „Mein Beitrag“ an, wie in 11.12 beschrieben.

**Erwartet:**
- Anna Koch (Vollmitglied, monatlich) hat laut Seeder eine **individuelle Untergrenze von 10,00 €** (Gruppe: 12,00 €) mit der Begründung „Familienrabatt laut Vorstandsbeschluss“. Sie ersetzt die Gruppen-Untergrenze in beide Richtungen.
- In der Oberfläche gibt es dafür **keine Bedienstelle** (nur die Schnittstelle `POST /api/assignments/{id}/min-amount-override`); sichtbar ist sie nur als „Unberührt (individuelle Untergrenze): 1 Zuweisung“ in der Vorschau und in „Mein Beitrag“ bei einem verknüpften Konto (dort ohne Begründung). Ob das gewollt ist, entscheidest du; die Spec (§3.3) nennt, dass nur der Buchhalter sie setzt.

**Beachte:** Notiere als **Frage**, wenn du eine Oberfläche dafür erwartest.

### 5.7 Zuweisung zurücknehmen und Beitragsgruppe deaktivieren

**Rolle:** admin (Verwalter)

**Tun:**
1. Versuche bei `Testgruppe Probe 2` (mit Zoras Zuweisung) im Menü ⋯ „Löschen“ und bestätige.
2. Öffne bei Zoras Zuweisung (sie beginnt erst in der Zukunft) das Zeilenmenü ⋯, klicke „Zuweisung zurücknehmen“ und bestätige im Dialog mit „Zurücknehmen“.
3. Versuche bei `Testgruppe Probe 2` noch einmal „Löschen“ und bestätige im Dialog „Beitragsgruppe löschen“.
4. Öffne bei `Testgruppe Probe 2` „Bearbeiten“, schalte „Aktiv“ aus und klicke „Speichern“.

**Erwartet:**
- Schritt 1: Dialog „Beitragsgruppe löschen“ mit „„Testgruppe Probe 2“ wirklich löschen? Das geht nur, solange keine Zuweisung mehr daran hängt.“ Danach die Fehlermeldung „Diese Beitragsgruppe hat noch Zuweisungen und kann nicht gelöscht werden. Stattdessen deaktivieren.“; die Gruppe bleibt.
- Schritt 2: Dialog „Zuweisung zurücknehmen“ mit „Die Zuweisung hat noch nicht begonnen. Sie wird zurückgenommen und nie wirksam; es entstehen keine Forderungen daraus.“ Danach steht in der Spalte „Status“ „zurückgenommen“ und das Zeilenmenü fehlt. (Eine Zuweisung, die schon läuft, heißt im Menü „Zuweisung beenden“; der Dialog sagt dann „Die Zuweisung wird zum heutigen Tag beendet. Bereits erzeugte Forderungen bleiben unverändert.“)
- Schritt 3: Auch mit der zurückgenommenen Zuweisung lässt sich die Gruppe nicht löschen: dieselbe Meldung („… hat noch Zuweisungen … Stattdessen deaktivieren.“); die Gruppe bleibt.
- Schritt 4: Die Gruppe steht mit dem Status „inaktiv“ in der Liste.

**Beachte:** Auch die zurückgenommene Zuweisung bleibt als Historie bestehen und hält die Gruppe fest; eine Gruppe mit Zuweisungen wird deaktiviert statt gelöscht. Zora lässt sich wegen der Zuweisung nicht löschen.

### 5.8 Manuelle Einzelforderungen anlegen

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne **Einzug** → „Forderungen“ und klicke auf „+ Einzelforderung“.
2. Wähle bei „Mitglied“ `Eva Schröder` (tippe `Eva`), „Typ“ „Beitrag“, „Betrag (€)“ `15`, „Bezeichnung“ `Test A Stundung`, „Einzugstermin“ ein Datum **in 60 bis 90 Tagen**. Klicke „Anlegen“.
3. Lege auf dieselbe Weise an: `Test B Erlass` und `Test C Storno` (jeweils 15 €, derselbe Termin, Eva Schröder).
4. Lege bei **Jana Hoffmann** eine Forderung mit „Typ“ „Gebühr“, `20` €, „Bezeichnung“ `Test G Gebühr` und demselben Termin an.
5. Lege bei **Theo Testlink** (aktives Mandat aus 4.10) die Forderung `Test D Lauf` über `12,50` € an, mit „Einzugstermin“ `2026-11-01` (dem Termin des nächsten Laufs; liegt heute nach dem 01.11.2026, nimm dessen Termin). Sie landet später im Lauf (7.9) und dient als Drift-Test (7.12); mit ihr stehen im Lauf 11 statt 10 Forderungen (142,50 € statt 130,00 €).
6. Lege bei **Lena Bergmann** keine Forderung an (ihre Forderung für Phase 8 darf nicht mehrdeutig werden).
7. Klicke bei `Test G Gebühr` in der Karte auf „Als bezahlt markieren“ (sie ist dann in 12.2 bereits bezahlt).

**Erwartet:**
- Dialog „Manuelle Einzelforderung“ mit dem Hinweis „Freier Betrag mit eigenem Einzugstermin – auch ohne aktives Mandat anlegbar …“. „Bezeichnung“ ist eine Pflichtangabe.
- Pro Forderung „Einzelforderung angelegt.“ und eine Zeile mit Mitglied, Bezeichnung, Betrag, Termin und Zustand „offen“.
- Nach „Als bezahlt markieren“ bei G ändert sich der Zustand (die Zeile verliert den Knopf). Es entsteht dabei **keine Buchung**.
- Ein Doppelklick auf „Anlegen“ legt nur **eine** Forderung an (Beobachtung, siehe 16.2).

**Beachte:** Die Forderungen A, B und C verwendest du in 9.8 bis 9.10, die Gebühr G in 12.2, die Forderung D in 7.9 bis 7.12 und 9.10. A, B, C und G liegen bewusst weit hinter dem Vorwarnfenster, damit sie nicht in den Lauf vom 01.11. geraten; D ist der Gegenfall.

### 5.9 Beitragsjahr ändern und die Wirkung am Zeitstrahl sehen

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne in den Einstellungen die Karte „Beitragsjahr und Einzugszyklus“, wähle bei „Beitragsjahr beginnt im“ „April“ und klicke „Speichern“.
2. Öffne **Beiträge** → **Einzug** und sieh dir die Überschrift über dem Zeitstrahl an.
3. Stelle in den Einstellungen wieder „Januar“ ein und speichere.
4. Prüfe die Überschrift erneut.

**Erwartet:**
- Mit April heißt das Beitragsjahr z. B. „2026/27“, der Zeitstrahl zeigt andere Perioden; das Beitragsjahr ist unabhängig vom Geschäftsjahr der Buchhaltung.
- Nach dem Zurückstellen steht wieder „Beitragsjahr 2026“.

**Beachte:** ⚠ Führe zwischen Ändern und Zurückstellen **keinen Tageslauf** aus, sonst entstehen Forderungen im falschen Periodenraster. Die Einstellung gilt am besten, bevor die ersten Forderungen entstehen.

## Phase 6 – Aufnahme-Assistent & CSV-Import

**Ziel:** Du nimmst Mitglieder über den Assistenten und per CSV-Liste auf und prüfst, wie die App Mandate und Beiträge dabei anlegt und Fehler meldet.

**Nutzer:** admin (Verwalter). Buchhalter dürfen dasselbe.

**Vorbedingung:** Phase 5. Die Testdateien `mitglieder-import.csv` und `mitglieder-import-fehler.csv` liegen bereit (0.5).

### 6.1 Der Aufnahme-Dialog im Überblick

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke in **Beiträge** → **Mitglieder** auf „Mitglied“.
2. Scrolle den Dialog von oben nach unten, ohne etwas zu speichern. Schließe ihn mit „Abbrechen“.

**Erwartet:**
- Der Dialog „Mitglied aufnehmen“ enthält drei Abschnitte untereinander (die letzten beiden tragen die Überschriften „SEPA-Mandat (optional)“ und „Beitrag (optional)“):
  - **Stammdaten:** „Mitgliedstyp“, „Vorname“ (Platzhalter „optional“), „Nachname“, „E-Mail“ (Platzhalter „Voraussetzung für Lastschrift“), „Telefon“, „Straße“, „PLZ“, „Ort“, „Mitgliedsnummer“, „Beigetreten am“, „Interne Notiz“.
  - **Mandat:** „Art der Unterschrift“, „IBAN“, „BIC“, „Kontoinhaber“ (Platzhalter „sonst Anzeigename des Mitglieds“), „Mandatsreferenz“ (Platzhalter „sonst automatisch vergeben“), „Mandat unterschrieben am“.
  - **Beitrag:** „Beitragsgruppe“ (Vorgabe „– keine Zuweisung –“), „Turnus“, „Monatsbeitrag (€)“, „Zahlungsart“, „Gültig ab“, „Vorschau“.
- Unter „SEPA-Mandat (optional)“ steht der Hinweis „Beides lässt sich auch später in der Akte ergänzen.“

**Beachte:** Der Aufnahme-Dialog ist **ein** scrollbarer Dialog mit drei Abschnitten, ohne „Weiter“-Knöpfe (Hilfetext und Handbuch sprechen von „einem Dialog“). Notiere, ob dir das genügt.

### 6.2 Papier-Mandat mit Datum und Beitrag mit Vorschau

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke „Mitglied“. Trage „Vorname“ `Petra`, „Nachname“ `Aufnahme`, „E-Mail“ `petra.aufnahme@example.org` ein.
2. Trage bei „IBAN“ `DE02 1203 0000 0000 2020 51` ein. „Mandat unterschrieben am“ ist schon mit dem heutigen Datum vorbelegt: Lass es so. „Art der Unterschrift“ bleibt „Papier“.
3. Wähle bei „Beitragsgruppe“ `Vollmitglied` und beobachte die neuen Felder.
4. Klicke „Vorschau“.
5. Klicke „Aufnehmen“.
6. Öffne bei Petra Aufnahme über das Menü (⋯) „Mandat verwalten“.

**Erwartet:**
- Nach der Wahl der Gruppe erscheinen „Turnus“, „Monatsbeitrag (€)“, „Zahlungsart“ (Vorgabe „Lastschrift“) und „Gültig ab“ (mit Vorschau-Knopf).
- Die Vorschau lautet „Erste Periode: {Beginn} bis {Ende} ({N Monate}) · Einzugsbetrag {Betrag} · voraussichtlicher Einzugstermin {Datum}“. Die Werte hängen vom heutigen Tag und vom gewählten Turnus ab.
- „Mitglied aufgenommen.“ Die Zeile zeigt IBAN, Betrag je Periode und Frequenz statt „kein Mandat“.
- Das Mandat ist sofort „Aktiv“ (das vorbelegte Unterschriftsdatum entscheidet), mit automatisch vergebener Referenz.

### 6.3 Papier-Mandat ohne Datum bleibt Entwurf

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke „Mitglied“ und leere als Erstes das Feld „Mandat unterschrieben am“. Es ist mit dem heutigen Datum vorbelegt; nur mit leerem Feld bleibt das Papier-Mandat ein Entwurf.
2. Trage „Vorname“ `Uwe`, „Nachname“ `Entwurf` und „IBAN“ `DE02 1203 0000 0000 2020 51` ein.
3. Klicke „Aufnehmen“.
4. Öffne das Klemmbrett.

**Erwartet:**
- Hinweis unter dem Mandat-Abschnitt: „Ohne Unterschriftsdatum bleibt das Mandat ein Entwurf – erst das Datum aktiviert es sofort.“
- In der Liste trägt Uwe Entwurf bei der IBAN die Marke „Entwurf“.
- Im Klemmbrett steht unter „Handlungsbedarf“: „Uwe Entwurf: Papier-Mandat ist noch ein Entwurf, die Unterschrift fehlt. Bis zur Aktivierung wird nichts eingezogen.“ mit „Zur Akte“.

### 6.4 Ohne E-Mail: Überweisung statt Lastschrift

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke „Mitglied“. Trage „Vorname“ `Otto`, „Nachname“ `OhneMail` ein, lass „E-Mail“ leer.
2. Öffne die Auswahl „Art der Unterschrift“ und sieh dir „Elektronisch (Einmal-Link per Mail)“ an. Tippe testweise eine E-Mail-Adresse ein und lösche sie wieder.
3. Wähle bei „Beitragsgruppe“ `Vollmitglied`.
4. Klicke „Aufnehmen“.
5. Setze in der Liste das Häkchen „nur Auffälligkeiten“.

**Erwartet:**
- Ohne E-Mail ist die Option „Elektronisch (Einmal-Link per Mail)“ nicht wählbar; mit E-Mail wird sie wählbar.
- Nach der Wahl der Gruppe steht dort: „Ohne Mailadresse ist keine Vorabinformation und kein Lastschrifteinzug möglich – Zahlungsart wird auf Überweisung gesetzt.“ Die „Zahlungsart“ ist gesperrt und steht auf „Überweisung“.
- In der Liste steht Otto mit „Überweisung“ und „keine E-Mail – keine Vorankündigung möglich“; „nur Auffälligkeiten“ zeigt ihn.

### 6.5 Organisation als Mitglied

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke „Mitglied“ und wähle bei „Mitgliedstyp“ „Organisation“.
2. Trage bei „Name der Organisation“ `Musikverein Talheim e.V.` ein und klicke „Aufnehmen“.

**Erwartet:**
- Statt „Vorname“ und „Nachname“ erscheint das Feld „Name der Organisation“.
- Die Liste zeigt „Musikverein Talheim e.V.“; die Akte nennt denselben Namen.

### 6.6 CSV-Vorlage und der Weg über „Liste einlesen“

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke oben rechts auf „Liste einlesen“.
2. Lies den Hinweistext im Dialog „Mitgliederliste einlesen“ und klappe „Welche Spalten gibt es?“ auf.
3. Klicke „Vorlage herunterladen“ (der Knopf steht rechtsbündig, abgesetzt von „Datei wählen“ und „Prüfen“) und öffne die Datei `mitglieder-vorlage.csv` in einem Texteditor.
4. Wähle diese **unveränderte** Vorlage über „Datei wählen“ und klicke „Prüfen“.

**Erwartet:**
- Der Hinweis nennt den Zweck (erstmalige Aufnahme vieler Mitglieder aus einer CSV-Datei; vorhandene Mitglieder bleiben unberührt und werden übersprungen; vor dem Anlegen siehst du, was entstehen würde). Unter „Welche Spalten gibt es?“ stehen die Spalten in vier Gruppen (Name, Stammdaten, Lastschrift, Beitrag); Reihenfolge und Schreibweise der Überschriften sind egal, weitere Spalten werden übergangen. „Betrag“ ist der Monatsbeitrag (0 = beitragsfrei), „Frequenz“ sagt, wie oft eingezogen wird.
- Die Vorlage hat die Spalten Vorname, Nachname, Organisation, Mitgliedsnummer, Eintritt, Straße, PLZ, Ort, Telefon, E-Mail, IBAN, BIC, Kontoinhaber, Mandat am, Mandatsreferenz, Beitragsgruppe, Betrag, Frequenz und Start. Drei Beispielzeilen: Anna Beispiel (1001, Mandat, Vollmitglied, monatlich), Musikhaus Beispiel GmbH (Organisation, 1002, nur Stammdaten) und Ben Muster (1003, Ermäßigt, jährlich). Die Beitragsgruppen gibt es in deiner Instanz; „Start“ ist der Erste des Folgemonats.
- Beim Prüfen ohne Anpassung stehen alle drei Zeilen als „übersprungen“ („Diese Mitgliedsnummer existiert bereits – Zeile übersprungen.“), weil die Nummern 1001 bis 1003 schon an Mitglieder des Seeders vergeben sind. Die Zusammenfassung lautet dann „0 von 3 Zeilen sind in Ordnung … 3 bereits vorhandene oder doppelte Zeilen werden übersprungen, 0 sind fehlerhaft.“ (Zählung aus dem Dateiinhalt; weicht die Anzeige ab, notiere es.) Das Prüfen selbst ändert nichts.

**Beachte:** Die Vorlage ist ein Muster für eigene Listen. Wer sie unverändert prüft, sieht die Beispielzeilen nur deshalb als übersprungen, weil die Nummern schon vergeben sind. Notiere, ob dich das stört.

### 6.7 CSV-Prüflauf mit der gültigen Datei

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne „Liste einlesen“, wähle `docs/testprotokoll/testdaten/mitglieder-import.csv` und klicke „Prüfen“.
2. Lies die Zusammenfassung und die Tabelle.
3. Beobachte den Knopf „Zeilen übernehmen“ und das Häkchen darüber.

**Erwartet:**
- Eine Zusammenfassung wie „6 von 6 Zeilen sind in Ordnung: 5 Mandate und 5 Zuweisungen würden angelegt. 0 bereits vorhandene oder doppelte Zeilen werden übersprungen, 0 sind fehlerhaft.“ (Zählung aus dem Dateiinhalt; weicht die Anzeige ab, notiere es).
- Die Datei enthält sechs neue Mitglieder: Greta Hansen (1101, Mandat `ALT-0101`, Vollmitglied 15,00 € monatlich), Ole Petersen (1102, `ALT-0102`, Ermäßigt 7,50 € vierteljährlich), Frieda Lorenz (1103, Kontoinhaber Karl Lorenz, Jugend 5,00 € monatlich), Max Weidner (1104, nur das Mitglied), Imke Sander (1105, ohne E-Mail, Ermäßigt jährlich ab 01.01.2027) und Chorverband (1106, Fördermitglied jährlich ab 01.01.2027). Die Startdaten liegen alle in der Zukunft.
- Die Tabelle hat die Spalten „Zeile“, „Name“, „IBAN“, „Beitragsgruppe“, „Monatsbeitrag“, „Ergebnis“. Beim Prüfen ändert sich nichts in der Mitgliederliste.
- Das Häkchen lautet „Die unterschriebenen Mandate für 5 Zeilen liegen vor – sie werden sofort aktiviert.“ Es steht direkt über dem Knopf „Zeilen übernehmen“ (unter der Tabelle). Solange es nicht gesetzt ist, bleibt „Zeilen übernehmen“ gesperrt.
- Imke Sander hat keine E-Mail-Adresse: Ihre Zuweisung steht auf Überweisung (kein Fehler).

### 6.8 CSV übernehmen

**Rolle:** admin (Verwalter)

**Tun:**
1. Setze das Häkchen und klicke „Zeilen übernehmen“.
2. Bestätige im Dialog „Mitglieder übernehmen“ mit „Übernehmen“.
3. Schließe den Import-Dialog („Schließen“) und sieh dir die Liste an.
4. Öffne bei Greta Hansen und bei Frieda Lorenz „Mandat verwalten“.

**Erwartet:**
- Erst das Häkchen aktiviert „Zeilen übernehmen“; die Rückfrage „Mitglieder übernehmen“ erscheint danach.
- Der Dialog bleibt offen und zeigt je Zeile das Ergebnis, z. B. „Mandat und Zuweisung angelegt“.
- Die Liste hat nun 22 Mitglieder. Die Mandate mit Unterschriftsdatum sind **sofort aktiv** (Papier): Greta mit der Referenz `ALT-0101` aus der Spalte „Mandatsreferenz“, Frieda mit einer automatisch vergebenen Referenz und Karl Lorenz als Kontoinhaber. Max Weidner steht mit „kein Mandat“ da, Imke Sander und das Chorverband haben Mandat bzw. Überweisung wie oben.
- Ein Import legt nur an: Bestehende Mitglieder werden nicht verändert.

### 6.9 Fehlerdatei: Zeile für Zeile benannt

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne „Liste einlesen“, wähle `docs/testprotokoll/testdaten/mitglieder-import-fehler.csv` und klicke „Prüfen“.
2. Lies die Texte in der Spalte „Ergebnis“ je Zeile und die Zusammenfassung.
3. Setze das Häkchen (falls verlangt), klicke „Zeilen übernehmen“ und bestätige.
4. Lies das Ergebnis je Zeile und die Mitgliederliste.

**Erwartet:** Prüflauf, laut Code und Dateiinhalt:
- **Fehlerhaft** (Fehlertext in „Ergebnis“): die Zeile ohne Namen („Weder Name noch Nextcloud-Konto angegeben.“), Emil Gross („Zu einer IBAN gehört das Datum, an dem das Mandat unterschrieben wurde.“), Fiona Haas („Unlesbarer oder negativer Betrag: fünf Euro“), Gero Illner („Keine gültige E-Mail-Adresse: kein-at-zeichen“), Hanna Jung („Zu einem Betrag gehört ein Startdatum (erste Fälligkeit).“) und Kai Lange („Unbekannte Zahlungsfrequenz: zweiwöchentlich“).
- **Übersprungen:** Dora Falk, weil die Mitgliedsnummer 1001 schon Jana Hoffmann gehört („Diese Mitgliedsnummer existiert bereits – Zeile übersprungen.“).
- **Mit Warnung, wird trotzdem angelegt:** Ingo Kern („Unbekannte Beitragsgruppe „Ehrenmitglied“ – der Beitrag wird nicht angelegt.“) und Jana Hoffmann 1209 („Ein Mitglied namens „Jana Hoffmann“ gibt es schon – trotzdem angelegt (Namensgleichheit ist keine Dublette).“).
- **Im Prüflauf ohne Meldung, scheitert aber erst beim Übernehmen:** Carsten Ebert (IBAN `DE12 345` ist formal ungültig), Mia Nolte (Startdatum 01.01.2026 liegt in der Vergangenheit: „Der Beginn einer Zuweisung darf nicht in der Vergangenheit liegen.“) und Nora Otto (die Mitgliedsnummer 1213 kommt in der Datei zweimal vor; Nils Otto wird zuerst angelegt, Nora danach übersprungen).
- Gültig: Birte Voss (Mandat und Zuweisung).
- Summe laut Dateiinhalt: 14 Zeilen, 7 in Ordnung (Birte, Carsten, Ingo, Jana, Mia, Nils, Nora), 1 übersprungen, 6 fehlerhaft; weicht die Anzeige ab, notiere es.
- Ein Fehler in einer Zeile macht die übrigen nicht wertlos: Gültige Zeilen bleiben übernehmbar, fehlerhafte werden übersprungen. Nach dem Übernehmen sind angelegt: Birte Voss, Ingo Kern (ohne Beitrag), Jana Hoffmann (zweite, mit Warnung) und Nils Otto.

**Beachte:** Der Prüflauf meldet inzwischen auch ungültige IBAN-Formate (Carsten Ebert), Startdaten in der Vergangenheit (Mia Nolte) und doppelte Mitgliedsnummern innerhalb derselben Datei (Nora Otto) als Fehler beziehungsweise übersprungene Zeile. Die Zahlen oben ändern sich entsprechend: 14 Zeilen, **4 in Ordnung** (Birte, Ingo mit Warnung, Jana 1209 mit Warnung, Nils), 2 übersprungen (Dora Falk, Nora Otto), 8 fehlerhaft. Ein Betrag von 0 wäre dagegen gültig (die Testdatei enthält keine solche Zeile): Die Zeile ist beitragsfrei und braucht weder IBAN noch Startdatum oder Frequenz.

### 6.10 Derselbe Import noch einmal

**Rolle:** admin (Verwalter)

**Tun:**
1. Wähle noch einmal `mitglieder-import.csv` und klicke „Prüfen“.

**Erwartet:**
- Alle sechs Zeilen stehen als übersprungen („Diese Mitgliedsnummer existiert bereits – Zeile übersprungen.“), kein doppeltes Mitglied, kein zweites Mandat. Die Zusammenfassung nennt „6 bereits vorhandene oder doppelte Zeilen werden übersprungen“; „Zeilen übernehmen“ bleibt gesperrt, weil nichts zu tun ist.
- Eine Zeile mit gleichem Namen, aber ohne Mitgliedsnummer, erzeugt dagegen nur eine Warnung und wird angelegt (Namensgleichheit ist keine Dublette).

## Phase 7 – Einzug

**Ziel:** Du gehst den Einzug von der Vorschau bis zur Einreichung durch: Zeitstrahl, Tageslauf mit Vorabinfo, Freigabe, pain.008-Datei, Terminverschiebung, Verwerfen, Einreichen, Nachzügler und Sperrfenster.

**Nutzer:** admin (Verwalter), jane (für das Sperrfenster); Mailhog.

**Vorbedingung:** Phase 4 bis 6. Wichtig: Lies 0.6 und 7.5, bevor du den Tageslauf startest: Er verschickt die Vorabinfos, und ab dann sind Betrag und Turnus der betroffenen Perioden gesperrt (7.17).

### 7.1 Reiter „Einzug“ und seine Segmente

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne **Beiträge** → **Einzug**.
2. Klicke nacheinander „Zeitstrahl & Läufe“, „Forderungen“ und „Bankabgleich“ und kehre zu „Zeitstrahl & Läufe“ zurück.

**Erwartet:**
- Eine Segmentleiste „Ansicht im Einzug“ mit „Zeitstrahl & Läufe“, „Forderungen“ und „Bankabgleich“.
- Unter „Zeitstrahl & Läufe“: die Überschrift „Beitragsjahr 2026“, ein Zeitstrahl mit der Marke „HEUTE“ und den Einzugsterminen, der Knopf „Terminplan“, darunter die Karte der Vorschau (für den nächsten Termin) und der Abschnitt „Läufe“ mit „1 Lauf“.

### 7.2 Den Zeitstrahl bedienen

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke den Termin-Marker vom 01.11.2026.
2. Lies die Meilensteine und die Legende.
3. Nutze „Früherer Termin“, „Späterer Termin“, „Vorheriges Beitragsjahr“, „Nächstes Beitragsjahr“ und „Zum laufenden Jahr“.
4. Klicke den Marker vom 01.10.2026.

**Erwartet:**
- Der gewählte Marker ist hervorgehoben. „Phasen bis zum Einzug am 01.11.2026“ nennt „Vorwarnung“, „Vorabinfo“, „Freigabe-Vorlauf“ und „Einzug“ mit Daten. Mit den Einstellungen des Seeders (35, 30 und 5 Tage) sind das 27.09., 02.10., 27.10. und 01.11.2026 (mit den Standardwerten 21 und 14 Tage wären es 11.10. und 18.10.).
- Die Legende unterscheidet „Vorschau, noch nicht freigegeben“, „Lauf freigegeben“, „Lauf eingereicht“ und „nichts einzuziehen“.
- Der Marker vom 01.10.2026 ist als „Lauf eingereicht“ gekennzeichnet; der vom 01.11.2026 als Vorschau.
- Die Termine stammen aus dem Terminplan und aus Einzelforderungen mit eigenem Termin (auch Test A bis D aus 5.8 sind dort als Termine zu sehen).

### 7.3 Läufe und Lauf-Details (der Oktoberlauf)

**Rolle:** admin (Verwalter)

**Tun:**
1. Lies im Abschnitt „Läufe“ die Zeile „Einzug am 01.10.2026“.
2. Klicke „Details“.
3. Prüfe die Posten.

**Erwartet:**
- Die Lauf-Zeile nennt Termin, Status „Eingereicht“, **10 Posten** und die Summe **145,00 €** (die Nummer des Laufs hängt von der Instanz ab).
- Das Detail zeigt „Kennung der Datei“ und je Posten Mitglied, Bezeichnung der Forderung, Mandatsreferenz, **maskierte** IBAN (z. B. `DE02••••2051`) und Betrag. Die Posten gehören Jana Hoffmann, Markus Fuchs, Sophie Krüger (anteilig 45,00 €), Mara Lindner, Anna Koch, Bernd Neumann, Clara Vogel, David Wolf, Eva Schröder und Felix Maier. Der Zustand der Forderungen steht in Klartext („eingezogen“: Termin vorbei, keine Rückgabe; die Rückgaben von Fuchs und Krüger kommen erst mit 8.1).
- Der Hinweis „Die Datei ist bei der Bank eingereicht. Einen Storno gibt es nicht mehr: Der Lauf lässt sich weder verwerfen noch verschieben.“ erscheint, dazu der Knopf „XML herunterladen“.

### 7.4 Terminplan: Einzugstag ändern (und zurücksetzen)

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke „Terminplan“. Lies die Tabelle und notiere den **Standard-Einzugstag der Zeile „monatlich“**: ________.
2. Ändere ihn in dieser Zeile auf `14` und klicke „Speichern“ (in der Zeile).
3. Sieh dir den Zeitstrahl an, ohne die Seite neu zu laden.
4. Stelle den notierten Wert wieder ein und speichere.

**Erwartet:**
- Die Karte „Terminplan“ erklärt: „Je Turnus ein Standard-Einzugstag (Tage-Versatz zum Periodenbeginn – 0 = am ersten Tag der Periode, negativ = vorgezogen). Einzelne Perioden lassen sich darunter überschreiben …“. Die Spalte „Überschreibungen (Periodenindex: Versatz)“ und die Eingabe „Periodenindex“ mit „+ Überschreibung“ gehören dazu. Darunter stehen die Fristen nur als Anzeige: „Vorwarnfenster: 35 Tage · Vorabinfo-Vorlauf: 30 Tage · Freigabe-Vorlauf: 5 Tage vor dem Einzug.“ (Einstellungen des Seeders; es gibt dort keine Eingabefelder für sie) und als Verwalter dazu der Link „Fristen in den Einstellungen ändern“ (siehe 2.5).
- Nach dem Speichern rücken die Termine der monatlichen Turnusse auf den 15. des Monats; der Zeitstrahl rechnet ohne Neuladen neu.
- Nach dem Zurücksetzen stehen die Termine wieder an der alten Stelle.

**Beachte:** ⚠ Stelle den Wert wirklich zurück. Er wirkt auf alle künftig erzeugten monatlichen Forderungen (die Forderungen zum 01.11. existieren schon und ändern sich nicht). Als Buchhalter wäre diese Eingabe ebenfalls bedienbar (13.2).

### 7.5 Vorschau vor dem Tageslauf: „Vorabinfo nicht rechtzeitig verschickt“

**Rolle:** admin (Verwalter)

**Tun:**
1. Wähle den Termin 01.11.2026 auf dem Zeitstrahl und lies die Karte „Vorschau“.
2. Öffne das Klemmbrett.
3. Öffne **Einzug** → „Forderungen“ und setze den Filter „Fällig von“ und „Fällig bis“ auf `2026-11-01`.

**Erwartet:**
- Die Karte „Einzug am 01.11.2026“ trägt die Marke „Vorschau“, einen Zeitbezug („in 27 Tagen“ am 05.10.2026) und den Text „Das ist nur eine Vorschau: Vor der Freigabe gibt es keinen Lauf, es wird nichts gespeichert.“
- Kennzahlen „Forderungen“, „Summe“ und „Störfälle“: **10 Forderungen, 130,00 €** (neun Beiträge und Nadine Schusters Einzelforderung über 30,00 €; mit Theos Test D aus 5.8 sind es 11 und 142,50 €). „Forderungen in der Vorschau“ listet Mitglied, Bezeichnung und Betrag nur der **einzugsfähigen** Forderungen (keine Überweiser: Lena, Tobias, Musikhaus).
- Unter „Störfälle zu diesem Termin“ stehen **zehn Einträge** mit „Handlungsbedarf“: „Vorabinfo für {Name} … konnte nicht rechtzeitig verschickt werden.“ und der Eintrag zu Jonas Richter. Der Satz „Störfälle blockieren nichts und müssen nicht quittiert werden – sie verschwinden von selbst, sobald ihre Ursache behoben ist.“ steht dabei.
- Das ist der echte Zustand „Cron noch nicht gelaufen“: Die Vorabinfo (fällig seit 02.10.) wurde noch nicht verschickt. In der Liste „Forderungen“ stehen die zehn Forderungen im Zustand „offen“, mit Marke „Handlungsbedarf“.
- Liegt der Freigabe-Vorlauf (5 Tage) schon hinter dir, steht dort „Freigabe überfällig seit …“ mit dem Zusatz „Das blockiert nichts“.

**Beachte:** Hat der Cron die Tagesjobs inzwischen selbst gestartet (frühestens am 06.10.2026 gegen 20:20 UTC), fehlt dieser Zustand; dann `--wipe` (0.4) oder weiter mit 7.6.

### 7.6 Tageslauf: Vorabinfos und Zahlungsaufforderungen

**Rolle:** admin (Verwalter), Terminal

**Tun:**
1. Starte den Tageslauf:

```bash
/Users/FlorianLudwig/Projekte/nextcloud-apps/vereinsbuchhaltung/tests/dev/run-jobs.sh tageslauf mahnwesen
```

2. Aktualisiere in der App Klemmbrett, Vorschau-Karte und „Forderungen“ („Forderungen aktualisieren“).
3. Öffne Mailhog (7.7).
4. Starte den Lauf ein zweites Mal und prüfe, ob sich etwas ändert.

**Erwartet:**
- Der Lauf legt für die zehn Forderungen **keine neuen** an (der Seeder hat sie schon angelegt), versendet aber die Vorabinfos: **10 Vorabinfo-Mails** (Jana in Du-Form, die übrigen in Sie-Form) und **2 Zahlungsaufforderungen** für überfällige Überweisungsforderungen (Lena auf Englisch, Tobias), also **12 Mails**; mit Theos Test D kommt seine Vorabinfo hinzu (13 Mails). Alle tragen einen GiroCode-Anhang, soweit es eine Zahlungsaufforderung ist.
- Die zehn Aufgaben „Vorabinfo … nicht rechtzeitig verschickt“ sind aus dem Klemmbrett und aus den Störfällen der Vorschau verschwunden; es bleiben Jonas Richter und die Hinweise.
- Für Mitglieder, die du in Phase 5 und 6 neu zugewiesen hast (z. B. Petra Aufnahme), können neue Forderungen mit einem späteren Termin entstehen (Nachzügler, 7.16).
- Der zweite Lauf legt nichts doppelt an und verschickt keine Mail erneut (idempotent).

**Beachte:** Die Forderungen zum 01.11. hat der Seeder selbst angelegt. Hätte sie der Tageslauf erzeugt, wären sie wegen der Nachzügler-Regel auf den 01.12. gerückt: Die Vorabinfo-Frist (30 Tage vor dem 01.11., also ab 02.10.) ist heute schon angebrochen, und eine Forderung fährt am nächsten Termin ihres Turnus, dessen Frist noch nicht begonnen hat. Im Zeitstrahl hieße das: Der Termin 01.11. zeigt solche Forderungen nicht in der Vorschau, sondern der spätere Termin. Beobachte das in 7.16, ohne dir vorab eine Position auf dem Strahl zu merken.

### 7.7 Vorabinfo-Mails in Mailhog

**Rolle:** Du (Mailhog)

**Tun:**
1. Öffne Mailhog (http://mail.local/) und aktualisiere die Ansicht.
2. Öffne die Mail an Jana Hoffmann und die an Markus Fuchs.
3. Prüfe die Empfänger der übrigen Mails; suche Mails an Jonas Richter, Lena Bergmann, Tobias Brandt und das Musikhaus.
4. Öffne die Mail an Theo Testlink (falls vorhanden) und die beiden Zahlungsaufforderungen.

**Erwartet:**
- Je Mitglied mit einzugsfähigem Mandat und E-Mail **eine** gebündelte Vorabinfo: Jana Hoffmann, Markus Fuchs, Mara Lindner, Anna Koch, Bernd Neumann, Clara Vogel, David Wolf, Eva Schröder, Felix Maier und Nadine Schuster (zehn), dazu Theo Testlink. Betreff: „Bevorstehender Lastschrifteinzug von {Vereinsname}“, Überschrift „Bevorstehender Lastschrifteinzug“.
- Inhalt in dieser Reihenfolge: Anrede („Guten Tag Markus Fuchs,“), der Satz „{Verein} wird die folgenden Beträge per Lastschrift von Ihrem Konto einziehen (Mandatsreferenz …):“, je Position eine Zeile („– Bezeichnung (Periodenbeginn – Periodenende): 15,00 €, fällig 01.11.2026“), dann „Frühester Einzug: 01.11.2026“, „Gläubiger-Identifikationsnummer: DE98ZZZ09999999999“, „Betrag und Turnus dieser Positionen stehen ab jetzt fest und lassen sich bis zum Einzug nicht mehr ändern.“ und „Bitte sorgen Sie für ausreichende Deckung Ihres Kontos. Bei Fragen wenden Sie sich an die Kassenführung.“
- **Jana (Konto auf „Deutsch“)** bekommt die **Du-Fassung** („Hallo Jana Hoffmann,“, „… von deinem Konto …“, „Bitte sorge für ausreichende Deckung deines Kontos …“); alle anderen, auch Nadine und Theo ohne Nextcloud-Konto, die Sie-Fassung.
- Jonas Richter, Lena Bergmann, Tobias Brandt und das Musikhaus bekommen **keine Vorabinfo**. Lena (auf Englisch) und Tobias bekommen je eine **Zahlungsaufforderung** („Zahlungsaufforderung von …“) mit GiroCode (siehe 9.7 und 14.7).
- Nadines Mail nennt ihre Einzelforderung; Theos Mail `Test D Lauf` mit „fällig 01.11.2026“.
- Datumsangaben in den Mails stehen im Format TT.MM.JJJJ, Beträge deutsch (`15,00 €`).

### 7.8 Vorschau-Karte nach dem Tageslauf

**Rolle:** admin (Verwalter)

**Tun:**
1. Wähle den Termin 01.11.2026 und lies die Karte „Vorschau“ noch einmal.
2. Prüfe das Klemmbrett.

**Erwartet:**
- Die Forderungen sind unverändert (10 bzw. 11, 130,00 € bzw. 142,50 €). Unter „Störfälle zu diesem Termin“ stehen nur noch Einträge wie der zu Jonas Richter (Mandat im Entwurf), keine Vorabinfo-Störfälle mehr.
- Ab jetzt sind Betrag und Turnus dieser Perioden gesperrt (Sperrfenster, 7.17).

### 7.9 Freigeben und Datei erzeugen (Schritt 1 von 2)

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke auf der Karte „Freigeben & Datei erzeugen“. Lies den Dialog, klicke zunächst „Abbrechen“.
2. Prüfe unter „Läufe“, dass nichts angelegt wurde.
3. Klicke wieder „Freigeben & Datei erzeugen“ und im Dialog „Freigeben & Datei erzeugen“. Klicke die Schaltfläche **nicht doppelt**; teste den Doppelklick erst in 7.13.

**Erwartet:**
- Dialog „Einzug am 01.11.2026 freigeben“ mit „Einzugstermin“, „Forderungen“ (10, mit Theos Test D 11), „Summe“ (130,00 €, mit Test D 142,50 €) und „Störfälle“ sowie dem Kasten „Das lässt sich nicht ungeschehen machen:“ mit drei Punkten (Daten werden eingefroren und die pain.008-Datei erzeugt; jeder Posten bekommt eine eigene EndToEndId, die nie wiederverwendet wird; Freigegeben heißt noch nicht eingereicht).
- Störfälle blockieren die Freigabe nicht; betroffene Forderungen sind nicht im Lauf, sondern bleiben offen.
- „Abbrechen“ legt nichts an. Nach dem Freigeben erscheint ein neuer Lauf in „Läufe“, aufgeklappt, mit Status „freigegeben“ und „Kennung der Datei“; die Vorschau-Karte ist weg, der Marker trägt „Lauf freigegeben“.
- Posten und IBAN im Lauf-Detail sind maskiert.

**Beachte:** Auf der Karte steht auch „Schritt 1 von 2: Die Datei ist danach erzeugt, aber noch nicht bei der Bank eingereicht.“

### 7.10 XML herunterladen und die Datei prüfen

**Rolle:** admin (Verwalter), Texteditor

**Tun:**
1. Klicke im Lauf-Detail auf „XML herunterladen“ (öffnet in neuem Tab bzw. lädt eine Datei).
2. Lade sie ein zweites Mal herunter und vergleiche beide (im Terminal z. B. `shasum datei1.xml datei2.xml`).
3. Öffne die Datei im Texteditor und suche nach `ReqdColltnDt`, `RCUR`, `EndToEndId`, der IBAN eines Mitglieds und `Petra Lindner`.

**Erwartet:**
- Eine pain.008-Datei (SEPA-Lastschrift) mit dem Einzugstermin `<ReqdColltnDt>2026-11-01</ReqdColltnDt>` (bzw. dem Termin des Laufs), Sequenztyp `RCUR` bei allen Posten, je Posten eigener `EndToEndId`, Gläubiger-ID und Kennung (`MsgId`) wie im Lauf-Detail.
- Die IBANs stehen in der Datei im **Klartext**, in der Lauf-Ansicht der App dagegen maskiert.
- Mara Lindners Posten nennt Petra Lindner als Kontoinhaberin (Zahlungspflichtige, siehe 4.13).
- Beide Downloads sind **byte-identisch**.

**Beachte:** Vor dem ersten echten Einzug gehört die Datei ins Prüftool der Hausbank; das ist nicht Teil dieses Tests.

### 7.11 Termin nur nach hinten verschieben

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke im Lauf-Detail „Termin verschieben“.
2. Trage ein früheres Datum und das bisherige Datum ein, beobachte den Knopf. Trage dann ein Datum **einen Tag nach** dem bisherigen ein.
3. Klicke „Termin verschieben“.
4. Lade die XML-Datei erneut herunter und suche `ReqdColltnDt`.

**Erwartet:**
- Dialog „Einzugstermin verschieben“ mit dem Feld „Neuer Einzugstermin“, dessen frühester Wert der Tag nach dem bisherigen Termin ist. Ein früheres oder dasselbe Datum lässt den Knopf „Termin verschieben“ gesperrt, ein Text nennt „nur nach hinten“.
- Der Lauf zeigt den neuen Termin. Die neu geladene Datei trägt den neuen Termin; Kennung der Datei und alle `EndToEndId` bleiben gleich.

### 7.12 Drift-Warnung: Mandatsdaten ändern sich nach der Freigabe

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne in **Mitglieder** die Akte von Theo Testlink, „Mandat verwalten“, und ändere über „Bankverbindung ändern“ die IBAN auf `DE44 5001 0517 5407 3249 31` („IBAN ändern“).
2. Öffne **Einzug** und klappe den freigegebenen Lauf auf.
3. Klicke „Verwerfen und neu freigeben“. Lies die vorbelegte Begründung. Lösche sie und beobachte den Knopf, setze sie wieder ein.
4. Klicke „Lauf verwerfen“ im Dialog.

**Erwartet:**
- Der Lauf warnt: „1 Posten weicht von den aktuellen Mandatsdaten ab …“ und erklärt: „Die Datei enthält noch die Daten vom Tag der Freigabe. Reichen Sie sie nur ein, wenn diese Abweichungen gewollt sind. Sonst verwerfen Sie den Lauf und geben den Termin neu frei …“.
- Der Dialog „Lauf verwerfen und neu freigeben“ schlägt die Begründung „Abweichung von den aktuellen Mandatsdaten“ vor. Ohne Begründung (auch nur aus Leerzeichen) bleibt der Knopf gesperrt.
- Nach dem Verwerfen: Der Lauf steht auf „verworfen“, zeigt die Begründung (ein „&“ bleibt „&“), hat keine Aktionen mehr und bleibt als Historie stehen. Die Forderungen sind wieder frei: Die Vorschau-Karte erscheint für den ursprünglichen Termin erneut.

**Beachte:** Der Knopf „Lauf verwerfen“ (ohne Drift) funktioniert gleich, nur mit leerer Begründungszeile; die Pflicht zur Begründung gilt in beiden Fällen.

### 7.13 Neu freigeben (neue EndToEndIds)

**Rolle:** admin (Verwalter)

**Tun:**
1. Wähle den ursprünglichen Termin (01.11.2026) und klicke „Freigeben & Datei erzeugen“.
2. Klicke im Dialog die Schaltfläche „Freigeben & Datei erzeugen“ **zweimal schnell hintereinander**.
3. Sieh dir „Läufe“ an und lade die neue XML-Datei herunter.

**Erwartet:**
- Es entsteht nur **ein** neuer Lauf (die Schaltfläche zeigt „Wird freigegeben…“ und ist gesperrt).
- In „Läufe“ stehen der verworfene und der neue Lauf zum selben Termin; die `EndToEndId` der neuen Datei sind **andere** als die der verworfenen.
- Die neue Datei trägt die neue IBAN von Theo, nicht die vom Tag der ersten Freigabe.
- Theos Posten trägt im Lauf-Detail die Kennzeichnung „Kontowechsel“ („Der Posten trägt die Kennzeichnung für einen Kontowechsel.“); in der Datei stehen dazu `AmdmntInd` (true) und `SMNDA`.

### 7.14 „Datei ist bei der Bank eingereicht“ (Schritt 2 von 2)

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke im neuen Lauf „Datei ist bei der Bank eingereicht“. Lies den Dialog „Datei als eingereicht bestätigen“ und klicke „Abbrechen“.
2. Klicke erneut den Knopf und im Dialog „Ja, eingereicht“.
3. Prüfe, welche Knöpfe der Lauf jetzt noch hat.
4. Öffne bei Jana Hoffmann und bei Nadine Schuster „Mandat verwalten“. Aktualisiere das Klemmbrett.

**Erwartet:**
- Der Dialog warnt, dass es danach keinen Storno mehr gibt. „Abbrechen“ ändert nichts; nach „Ja, eingereicht“ steht der Lauf auf „eingereicht“.
- Es bleibt nur der Download. Der Text sagt: „Die Datei ist bei der Bank eingereicht. Einen Storno gibt es nicht mehr: Der Lauf lässt sich weder verwerfen noch verschieben.“ Der Marker trägt „Lauf eingereicht“, und es gibt keinen Knopf „Freigeben & Datei erzeugen“ mehr für den Termin.
- In den Akten der beteiligten Mandate steht „Zuletzt eingereicht für: 01.11.2026“, die 36-Monats-Frist läuft neu. Bei **Nadine Schuster** (ihre Einzelforderung über 30,00 € steckt im Lauf) wechselt „Zuletzt eingereicht für“ von 01.12.2023 auf 01.11.2026, „Läuft ab (36-Monats-Regel)“ springt auf den 01.11.2029, und der Verfall-Hinweis aus 4.14 verschwindet nach „Aufgaben aktualisieren“.
- Bei Theo Testlink steht die Änderung der Bankverbindung nun als „an die Bank gemeldet“ (zuvor „offen – noch nicht an die Bank gemeldet“).

**Beachte:** ⚠ Unumkehrbar. Ist die Datei schon bei der Bank, verwirft man den Lauf **nicht**, sondern bestätigt die Einreichung: Sonst könnten die freien Forderungen ein zweites Mal eingezogen werden.

### 7.15 XML-Ablage im Nextcloud-Ordner (falls in 2.7 eingeschaltet)

**Rolle:** admin (Verwalter)

**Tun:**
1. Lies im Lauf-Detail die Zeile zur XML-Ablage.
2. Öffne die Nextcloud-Dateien-App im Home des Ablage-Nutzers und suche den Ordner `SEPA-Einreichungen`.

**Erwartet:**
- Bei eingeschalteter Ablage: „Die XML-Ablage ist eingeschaltet: Eine Kopie der Datei wird im Nextcloud-Ordner abgelegt: SEPA-Einreichungen“. Im Ordner liegt `{Kennung}.xml`.
- Bei ausgeschalteter Ablage fehlt der Hinweis. Ohne Ablage-Nutzer lässt sich die Ablage nicht einschalten (2.7).

**Beachte:** Schritt entfällt, wenn du die Ablage nicht eingeschaltet hast; setze ihn dann auf „übersprungen“.

### 7.16 Nachzügler: eine neue Forderung bekommt keinen vergangenen Termin

**Rolle:** admin (Verwalter), Terminal

**Tun:**
1. Lege unter **Beitragsgruppen** → „+ Zuweisung“ eine Zuweisung an: Mitglied `Theo Testlink`, Beitragsgruppe `Vollmitglied`, „Turnus“ „monatlich“, „Zahlungsart“ „Lastschrift“, „Gültig ab“ heute. Sieh dir die „Vorschau“ an und klicke „Anlegen“.
2. Starte den Tageslauf:

```bash
/Users/FlorianLudwig/Projekte/nextcloud-apps/vereinsbuchhaltung/tests/dev/run-jobs.sh tageslauf mahnwesen
```

3. Suche in **Einzug** → **Forderungen** die neue Forderung von Theo Testlink (Bezeichnung „Vollmitglied“) und lies ihre **Fälligkeit** ab.
4. Wähle auf dem Zeitstrahl **den Marker mit genau dieser Fälligkeit** (nicht den 01.11.) und sieh dir die Vorschau-Karte an.
5. Prüfe, ob die Forderung im Lauf vom 01.11.2026 steht.
6. Prüfe an diesem Termin „Freigeben & Datei erzeugen“ (Dialog ansehen, danach „Abbrechen“) und bei einem freigegebenen Lauf „Termin verschieben“ (wie 7.11).

**Erwartet:**
- Die Vorschau der Zuweisung nennt als erste Periode den laufenden Monat; angebrochene Monate zählen voll (ein Monat × Monatsbeitrag).
- Die Forderung hat **keine** Fälligkeit in der Vergangenheit und steht **nicht** im bereits freigegebenen Lauf. Ihre Fälligkeit ist der **nächste Termin des Turnus, dessen Vorabinfo-Frist (30 Tage vor dem Termin) noch nicht begonnen hat**. Der Termin ergibt sich aus heutigem Datum, Vorabinfo-Vorlauf und Terminplan; lies ihn in der Liste ab, statt ihn aus diesem Protokoll abzuleiten.
- Die Periode (Beginn und Ende) bleibt der laufende Monat; nur die Fälligkeit rückt nach hinten. Auf dem Zeitstrahl erscheint die Forderung unter dem späteren Termin in der Vorschau-Karte und lässt sich dort freigeben.
- Eine Vorabinfo dazu geht erst ab Beginn der Frist raus.

**Beachte:** Der Nachzügler-Mechanismus gilt für Lastschrift-Zuweisungen, nicht für Überweiser. Brichst du den Freigabe-Dialog in Schritt 6 nicht ab, entsteht ein weiterer Lauf; brich ihn ab oder verwirf ihn danach.

### 7.17 Sperrfenster: Betrag ändern wird nach der Vorabinfo abgelehnt

**Rolle:** jane (Mitglied, Fenster D)

**Tun:**
1. Lade in Fenster D (jane) neu und öffne „Mein Beitrag“.
2. Erhöhe in der Karte „Mein Beitrag“ den „Monatsbeitrag (€)“ um 1 € und klicke „Vorschau“.
3. Beobachte den Knopf „Speichern“.
4. Wiederhole mit dem „Turnus“ (Vollmitglied erlaubt monatlich, vierteljährlich, halbjährlich und jährlich).

**Erwartet:**
- Die App weist die Änderung mit einer Erklärung zurück: „Für die laufende Periode wurde bereits eine Vorabinfo verschickt – Betrag und Turnus stehen bis zum Einzug fest. Möglich wäre diese Änderung erst ab {Datum}.“ Es erscheint keine „Wirkt ab …“-Vorschau, und „Speichern“ bleibt gesperrt.
- Das Datum nennt die erste Periode ohne Vorabinfo: Jana hat für Oktober schon eine Vorabinfo (vom Seeder auf den 17.09. vermerkt), also frühestens ab 2026-11-01. Nach dem Tageslauf (7.6) ist auch die Novemberperiode vorabinformiert, dann steht dort 2026-12-01.
- Die IBAN bleibt änderbar (kein Sperrfenster, 11.3).

**Beachte:** Der Erfolgsfall ist bei Jonas Richter (john) zu sehen: Er hat noch keine Forderung, also keine vorabinformierte Periode (11.9). Beide Ausgänge sind richtig, je nach Stand der Vorabinfo.

### 7.18 Vorlaufzeiten auf den Standard zurückstellen (optional)

**Rolle:** admin (Verwalter)

**Tun:**
1. Stelle in den Einstellungen (Karte „Beitragsjahr und Einzugszyklus“) „Vorwarnfenster (Tage vor Einzug)“ auf `21` und „Vorabinfo-Vorlauf (Tage vor Einzug)“ auf `14` und speichere. Das ist nur nötig, wenn du den Standard sehen willst; der Seeder setzt 35 und 30 bei jedem erneuten Anlegen wieder.
2. Öffne **Beiträge** → **Einzug** → „Terminplan“ und lies die Anzeige der Fristen.

**Erwartet:**
- Der Terminplan zeigt „Vorwarnfenster: 21 Tage · Vorabinfo-Vorlauf: 14 Tage · Freigabe-Vorlauf: 5 Tage vor dem Einzug.“
- Die Meilensteine am Zeitstrahl (7.2) rücken entsprechend (Vorabinfo 18.10., Vorwarnung 11.10.).

## Phase 8 – Bankabgleich

**Ziel:** Du importierst einen Kontoauszug und prüfst, dass die App Vorschläge mit Begründung macht, aber nichts ohne dein Urteil bucht: Sammelgutschrift, zwei Rücklastschriften und ein Zahlungseingang per Überweisung.

**Nutzer:** admin (Verwalter). Buchhalter dürfen urteilen und verbuchen, Revisoren nur lesen.

**Vorbedingung:** Der Lauf vom 01.10.2026 ist eingereicht, in 2.9 ist die Weiterbelastung der Rücklastschriftgebühren eingeschaltet (Gebührenkonto 5400 und Erlöskonto 4000 hat der Seeder gesetzt). Die Datei `bank-oktober-2026.camt053.xml` liegt bereit (0.5).

### 8.1 Kontoauszug importieren

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne **Buchungen** und klicke „Umsätze importieren“.
2. Wähle im Dialog „Kontoumsätze importieren“ über „Datei wählen“ die Datei `docs/testprotokoll/testdaten/bank-oktober-2026.camt053.xml` (oder ziehe sie in die Fläche „Kontoauszug der Bank hierher ziehen“).
3. Lies die Vorschau und klicke „{n} Buchungen importieren“.
4. Lies die Meldungen zum Abschluss.
5. Öffne den Import-Dialog noch einmal und wähle dieselbe Datei.

**Erwartet:**
- Das Format wird am Inhalt erkannt (CAMT.053). Die Vorschau zeigt „6 neu“, „0 Dubletten“ und „6 gesamt“: die Sammelgutschrift (85,00 €), die Rückgabe von Markus Fuchs (−18,50 €), Lena Bergmanns Überweisung (22,50 €), eine Spende (50,00 €), die Rückgabe von Sophie Krüger (−49,00 €) und das Kontoführungsentgelt (−7,90 €).
- Nach dem Import: „6 Buchungen importiert“, „6 Buchungen warten auf die Zuordnung zu einem Konto.“ (Auto-Zuordnungsregeln können einzelne zuordnen) und **„2 mögliche SEPA-Rücklastschrift(en) erkannt: bitte im Bankabgleich (Beiträge → Einzug) prüfen und verbuchen.“**, dazu „Schließen“ und „Jetzt zuordnen“.
- Beim zweiten Mal: „Alle Buchungen dieser Datei wurden bereits importiert.“ und der Import-Knopf ist gesperrt (Dublettenprüfung).

**Beachte:** Der Schalter „Auto-Zuordnungsregeln anwenden“ bleibt auf dem Vorgabewert. Hat eine Regel einen Umsatz schon zugeordnet, steht das in „{n} davon wurden automatisch zugeordnet.“

### 8.2 Es wird nichts ohne dein Urteil gebucht

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne **Buchungen** → „Alle Buchungen“ und zähle die Buchungen. Vergleiche mit dem Stand vor dem Import (laut Phase 0: eine Buchung; mit `--with-anonymization-booking` zwei).
2. Öffne „Zuzuordnen“ und sieh dir die importierten Umsätze an.

**Erwartet:**
- Der Import hat **keine** Buchung erzeugt: Die Zahl in „Alle Buchungen“ ist unverändert.
- Die importierten Umsätze stehen unter „Zuzuordnen“, das Badge am Unterreiter zählt sie.

### 8.3 Den Bankabgleich öffnen

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne **Beiträge** → **Einzug** → „Bankabgleich“.
2. Lies Kopfzeile und Hinweis. Klicke die beiden Unterreiter an.

**Erwartet:**
- Die Kopfzeile zeigt die beiden Unterreiter mit Zähler: „Einzüge und Rückgaben (3)“ (Sammelgutschrift und die zwei Rückgaben) und „Zahlungseingänge (1)“ (Lena Bergmanns Überweisung; die Spende hat keinen Vorschlag), rechts davon einen Aktualisieren-Knopf (Symbol, Beschriftung „Bankabgleich aktualisieren“).
- Darunter der Hinweis „Nichts wird automatisch gebucht – erst Ihr Urteil und das Verbuchen lösen eine Buchung aus.“ Der Satz „Der Bankauszug ist die Wahrheit …“ steht nicht mehr da.
- Als Verwalter oder Buchhalter steht dort **nicht** der Satz „Sie sehen den Bankabgleich nur lesend …“.

### 8.4 Sammelgutschrift: Zeilen und Vorschläge prüfen

**Rolle:** admin (Verwalter)

**Tun:**
1. Wähle „Einzüge und Rückgaben“ und suche die Karte mit der Marke „Einzugsgutschrift“.
2. Klicke „Zeilen prüfen“.
3. Lies bei jeder Zeile, was die Bank meldet, und die Vorschläge.
4. Klicke „Eindeutige Vorschläge bestätigen (n)“.
5. Prüfe danach unter **Buchungen** → „Alle Buchungen“, ob eine Buchung entstanden ist.

**Erwartet:**
- Die Karte zeigt 85,00 €, Art „Einzugsgutschrift“, Zustand „wartet auf Urteil“ und den Fortschritt „0 von 8 beurteilt“.
- Acht Zeilen (Jana 15,00 €, Mara 5,00 €, Anna 15,00 €, Bernd 15,00 €, Clara 7,50 €, David 5,00 €, Eva 15,00 €, Felix 7,50 €), je mit End-to-End-ID und Mandatsreferenz der Bank sowie genau einem Vorschlag mit Mitglied, Forderung, Betrag und Einzugstermin. Die **Begründung** steht in Klartext („Gleiche End-to-End-ID“), nie als Prozentzahl. Fuchs und Krüger fehlen hier; ihre Posten kommen zurück (8.7, 8.8).
- „Eindeutige Vorschläge bestätigen (8)“ bestätigt alle acht: „8 Vorschläge bestätigt. Gebucht wurde noch nichts.“ Danach steht „8 von 8 beurteilt“, und bestätigte Zeilen tragen „Zugeordnet:“.
- Das Journal ist **unverändert**.

**Beachte:** Mehrdeutige Fälle gibt es im Seeder-Szenario nicht; sonst stünde dort „Mehrere Posten passen. Es ist keiner vorausgewählt …“. Ein Posten, der schon einer Zeile gehört, würde in anderen Zeilen als „bereits einer anderen Zeile zugeordnet“ ohne Knopf erscheinen.

### 8.5 Urteile: Ablehnen, Nicht zuordenbar, Urteil ändern

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke bei der Zeile von Felix Maier „Urteil ändern“ und dann „Ablehnen“.
2. Klicke bei derselben Zeile „Urteil ändern“, dann „Nicht zuordenbar“, dann wieder „Urteil ändern“ und „Bestätigen“.
3. Lehne die Zeile von Felix noch einmal ab und klicke „Verbuchen…“. Lies den Dialog, klicke „Abbrechen“ (nicht „Jetzt verbuchen“).
4. Stelle Felix' Urteil wieder auf „Bestätigen“.

**Erwartet:**
- Zustände je Zeile: „noch nicht beurteilt“, „zugeordnet“, „abgelehnt“, „nicht zuordenbar“. Ein Urteil lässt sich bis zum Verbuchen ändern.
- Lehnst du eine Zeile ab, ergeben die zugeordneten Posten weniger als der Bankumsatz: Die Vorschau sagt „Die zugeordneten Posten ergeben 77,50 €, der Bankumsatz beträgt 85,00 €“ und „Jetzt verbuchen“ bleibt gesperrt; den Rest buchst du unter Buchungen von Hand.
- Solange Zeilen unbeurteilt sind, ist „Verbuchen…“ gesperrt, mit dem Hinweis „Verbuchen ist erst möglich, wenn alle N Zeilen beurteilt sind – noch k offen.“
- Am Ende steht die Karte auf „bereit zum Verbuchen“.

### 8.6 Sammelgutschrift verbuchen

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke „Verbuchen…“ und lies die Buchungsvorschau.
2. Klicke „Jetzt verbuchen“ **einmal**. Klicke danach nicht noch einmal.
3. Prüfe **Buchungen** → „Alle Buchungen“, **Einzug** → „Forderungen“ (Filter „Zustand: erledigt (bezahlt oder erlassen)“) und das Lauf-Detail vom 01.10.2026.

**Erwartet:**
- Dialog „Einzugsgutschrift verbuchen“ mit „Buchungsvorschau“: „Buchungsdatum: 01.10.2026“ (das Datum des Bankumsatzes, mit diesem Zusatz), die Bank (1200) im **Soll** mit 85,00 €, die Erlöse im **Haben**, **gruppiert nach Erlöskonto** („4000 Mitgliedsbeiträge · 8 Posten“, 85,00 €), und „8 Forderungen werden als bezahlt erledigt“.
- Toast „Verbucht: 8 Forderungen als bezahlt erledigt.“ Die Karte verschwindet.
- In „Alle Buchungen“ entsteht **eine** neue Buchung mit dem Datum 01.10.2026 (Bank im Soll, Erlöse im Haben). Die acht Forderungen stehen auf „erledigt (bezahlt)“ und sind mit der Buchung verknüpft. Jana Hoffmanns Oktober-Beitrag ist nun bezahlt.
- Zurückgebuchte Zeilen (Fuchs, Krüger) bleiben offen: dieses Geld ist nicht gekommen.

**Beachte:** ⚠ Das Verbuchen ist ein bewusster, endgültiger Schritt: Die Oberfläche bietet kein „Rückgängig“. Ein zweites Verbuchen derselben Gutschrift wird abgelehnt: „Dieser Bankumsatz ist bereits gebucht …“. Liegt der Umsatz in einem abgeschlossenen Geschäftsjahr, sagt die Vorschau das, bevor du klickst, und „Jetzt verbuchen“ bleibt gesperrt (hier nicht testbar, ohne ein Geschäftsjahr abzuschließen). Zum Wiederholen: `…/seed-beitraege.php --wipe --wipe-bank` (0.4).

### 8.7 Rücklastschrift „Deckung fehlt“ (Markus Fuchs, AM04)

**Rolle:** admin (Verwalter)

**Tun:**
1. Suche die Karte mit der Marke „Rücklastschrift“ zu Markus Fuchs und klicke „Zeilen prüfen“.
2. Lies Grund, Code, Gebühr und die Folgen. Klicke „Bestätigen“.
3. Klicke „Verbuchen…“ und lies die Vorschau, klicke dann „Jetzt verbuchen“.
4. Prüfe **Einzug** → „Forderungen“ (Fuchs) und **Einzug** → „Läufe“ (Detail 01.10.2026), die Buchungen und Mailhog.

**Erwartet:**
- Die Karte zeigt **−18,50 €** (15,00 € Rückgabe plus 3,50 € Bankgebühr). Der **Grund steht in Klartext**: „Mangels Kontodeckung zurückgegeben“. Zusätzlich für dich: „Rückgabecode (nur für die Buchhaltung sichtbar): AM04“, der Bankfreitext „Insufficient funds“ und „Bankgebühr 3,50 €“.
- Die **Folgen stehen vorab** an der Zeile: „Mit dem Verbuchen dieser Rücklastschrift geschieht automatisch:“ mit „Die Forderung wird wieder offen. Sie wird nicht erneut per Lastschrift eingezogen.“, „Das Mitglied erhält sofort eine Zahlungsaufforderung per E-Mail (sofern eine E-Mail-Adresse hinterlegt ist).“ und, weil die Weiterbelastung an ist, „Die Bankgebühr von 3,50 € wird dem Mitglied als eigene Gebühren-Forderung weiterbelastet.“ Es steht **nicht** „Das Mandat wird gesperrt“.
- Dialog „Rücklastschrift verbuchen“: **zwei Gegenkonto-Zeilen** im Soll („Erlös zurück“ 15,00 € auf das Erlöskonto 4000, „Bankgebühr“ 3,50 € auf das Gebührenkonto 5400), die Bank im Haben (18,50 €), dazu „Das geschieht beim Verbuchen“.
- Toast „Rücklastschrift verbucht: 1 Forderung ist wieder offen.“ In „Forderungen“ steht Fuchs' Oktober-Forderung auf „zurückgegeben“, mit Mahnstand „Zahlungsaufforderung“, und es gibt eine neue **Gebühren-Forderung** (Marke „Gebühr“) über 3,50 €. Das Mandat `M-3` bleibt **aktiv**. Die Buchung hat das Datum 02.10.2026 (Bankumsatz).

### 8.8 Rücklastschrift „Konto nicht nutzbar“ (Sophie Krüger, AC04)

**Rolle:** admin (Verwalter)

**Tun:**
1. Suche die Rücklastschrift-Karte zu Sophie Krüger, „Zeilen prüfen“, lies Grund und Folgen.
2. Klicke „Bestätigen“, „Verbuchen…“ und „Jetzt verbuchen“.
3. Öffne Sophie Krügers Akte („Mandat verwalten“), die Mitgliederliste und das Klemmbrett.
4. Sieh in Mailhog nach den Zahlungsaufforderungen.

**Erwartet:**
- Die Karte zeigt **−49,00 €** (45,00 € plus 4,00 € Gebühr). Grund: „Konto nicht nutzbar (z. B. aufgelöst, gesperrt oder IBAN ungültig)“, Code AC04 („Closed account number“). Folgen: „Das Mandat wird gesperrt: Es wird nicht mehr eingezogen, bis Sie es in der Mitglieder-Akte geklärt und entsperrt haben.“ plus die Zahlungsaufforderung und die Gebühren-Forderung über 4,00 €. Die Vorschau im Dialog nennt „Das Mandat wird gesperrt“.
- Nach dem Verbuchen: Das Mandat `M-4` steht auf „Ausgesetzt“ mit der Marke „Rücklastschrift“; die Akte erklärt: „Das Mandat wurde automatisch nach einer Rücklastschrift ausgesetzt. Klären Sie den Fall mit dem Mitglied und entsperren Sie das Mandat erst danach …“. In der Zeile „Sperre“ steht „gesperrt wegen Rücklastschrift seit …“. In der Liste trägt Sophie die Marke „ausgesetzt“.
- Im Klemmbrett unter „Handlungsbedarf“: „Sophie Krüger: Mandat nach einer Rücklastschrift gesperrt (Konto nicht nutzbar), Klärung offen. Solange wird nichts eingezogen.“
- Mailhog: **zwei Zahlungsaufforderungen**, an Fuchs und an Krüger (zusätzlich zu den zwölf Mails aus 7.6).
- Es gibt keine Auto-Entsperrung; das Mandat endet nie von selbst durch eine Rücklastschrift.

### 8.9 Zahlungseingang per Überweisung (Lena Bergmann)

**Rolle:** admin (Verwalter)

**Tun:**
1. Wähle die Darstellung „Zahlungseingänge“.
2. Lies die Karte zu Lena Bergmann (Betrag, Zahlungstext, Begründung, geplante Buchung).
3. Klicke „Bestätigen und verbuchen“ und im Dialog „Zahlungseingang verbuchen“ auf „Verbuchen“.
4. Prüfe die Forderung unter **Einzug** → „Forderungen“ und die Buchung.

**Erwartet:**
- Die Karte zeigt „Zahlungseingang“ mit 22,50 €, „Bankumsatz vom 02.10.2026 · Lena Bergmann“ und dem Zahlungstext „Mitgliedsbeitrag 4. Quartal 2026 Lena Bergmann“. Die **Begründung** nennt in Klartext, dass der Betrag zu einer offenen Forderung passt und ob der Name im Zahlungstext steht: „Betrag 22,50 € passt zur offenen Forderung von Lena Bergmann, dessen Name auch im Zahlungstext steht.“ Darunter: „Buchung: Bank an 4000 Mitgliedsbeiträge“.
- Der Dialog „Zahlungseingang verbuchen“ sagt: „… wird gebucht und die Forderung als bezahlt erledigt.“ Danach: „Die Gutschrift ist der Forderung zugeordnet und gebucht.“ Lenas Q4-Forderung steht auf „erledigt (bezahlt)“, eine Buchung „Bank an Erlöskonto“ ist entstanden. Die Spende (50,00 €) hat keinen Vorschlag.
- Passen mehrere Forderungen, steht dort „Mehrere Forderungen passen. Es ist keine vorausgewählt: Bitte wählen Sie die richtige.“

**Beachte:** Die Vorschläge berücksichtigen **alle noch nicht erledigten Forderungen mit gleichem Betrag**, auch solche, die schon in einem Lauf stecken. Prüfe deshalb, dass die vorgeschlagene Forderung wirklich Lenas ist.

### 8.10 Ablehnen und übrige Umsätze

**Rolle:** admin (Verwalter)

**Tun:**
1. Lege vorab (optional) in „Beitragsgruppen“ eine Einzelforderung über `50` € für ein Mitglied an (z. B. `Test F Spende` für Eva Schröder, „Einzugstermin“ in 80 Tagen) und aktualisiere den Bankabgleich („Bankabgleich aktualisieren“). Erscheint für die Spende (50,00 €) ein Vorschlag unter „Zahlungseingänge“, klicke dort „Ablehnen“ und im Dialog „Vorschlag ablehnen“ auf „Ablehnen“. Erscheint kein Vorschlag, setze diesen Teil auf „übersprungen“.
2. Lade den Bankabgleich neu.
3. Öffne **Buchungen** → „Zuzuordnen“ und sieh dir die übrigen Umsätze an.

**Erwartet:**
- Der Dialog „Vorschlag ablehnen“ sagt: „… wird dieser Forderung nicht mehr vorgeschlagen“. Danach „Vorschlag abgelehnt.“ und die Karte verschwindet; sie **kommt nicht wieder**, auch nicht nach dem Neuladen.
- Die Spende (50,00 €) und das Kontoführungsentgelt (7,90 €) bleiben unter „Zuzuordnen“ und werden von dir von Hand gebucht; weder die Forderungen noch das Journal ändern sich durch das Ablehnen.
- Gebucht wurde in dieser Phase nur, was du bestätigt hast: Sammelgutschrift, zwei Rücklastschriften und ein Zahlungseingang (**vier Buchungen**).

**Beachte:** Die Forderung `Test F Spende` bleibt offen und stört die folgenden Schritte nicht; entferne sie bei Bedarf in „Forderungen“ mit „Stornieren“.

## Phase 9 – Rücklastschrift & Mahnwesen

**Ziel:** Du prüfst die Folgen einer Rücklastschrift (Klartext, Sperre, Zahlungsaufforderung mit GiroCode, Gebühren-Forderung) und die Instrumente der Kassenführung: Mahnstand, Stundung, Erlass, Storno, als bezahlt vermerken.

**Nutzer:** admin (Verwalter), Mailhog, ein Smartphone mit Banking-App.

**Vorbedingung:** Phase 8 (Rücklastschriften verbucht), Einzelforderungen aus 5.8 (Test A bis D, G).

### 9.1 Rückgabegrund in Klartext an den Forderungen

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne **Einzug** → „Forderungen“. Setze den Filter „Zustand“ auf „zurückgegeben“.
2. Öffne bei Fuchs' Forderung „Details“.

**Erwartet:**
- Zustand „zurückgegeben“, im Detail „Rücklastschrift vom {Datum}.“ mit dem Klartext-Grund und „Rückgabecode (nur für die Buchhaltung sichtbar): AM04“.
- Der Hinweis „Die Forderung wird nicht erneut eingezogen (kein Wiedereinzug). Sie bleibt offen, bis sie bezahlt, erlassen oder gestundet wird; die Zahlungsaufforderung geht je nach Grund automatisch an das Mitglied.“
- Der Mahnstand zeigt „Zahlungsaufforderung“ mit „versandt am …“ und die nächste Stufe („Zahlungserinnerung ab …“).

### 9.2 Mandatssperre nach AC04 verstehen

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne Sophie Krügers Akte, „Mandat verwalten“.
2. Klicke „Entsperren“ und beobachte „Mandat entsperren“, ohne etwas einzutragen. Klicke dann „Abbrechen“ (**nicht** entsperren).
3. Prüfe das Klemmbrett und die Mitgliederliste.

**Erwartet:**
- Das Mandat steht auf „Ausgesetzt“ mit der Marke „Rücklastschrift“, Zeile „Sperre: gesperrt wegen Rücklastschrift seit …“.
- Ohne „Notiz zur Klärung (Pflicht)“ bleibt „Mandat entsperren“ gesperrt; die Sperre lässt sich nur von Hand und mit Notiz aufheben (Spec: keine Auto-Entsperrung).
- Im Klemmbrett steht weiterhin die Aufgabe „Sophie Krüger: Mandat nach einer Rücklastschrift gesperrt …“ unter „Handlungsbedarf“.

**Beachte:** Lass die Sperre stehen. In 17.4 siehst du, dass auch das Zurücksetzen sie nicht aufhebt. Das Entsperren mit Notiz hast du in 4.4 geprüft.

### 9.3 Zahlungsaufforderung nach der Rücklastschrift (Mailhog)

**Rolle:** Du (Mailhog)

**Tun:**
1. Öffne in Mailhog die Mails an Markus Fuchs und Sophie Krüger.
2. Lies Betreff, Text und Positionen.

**Erwartet:**
- Betreff „Zahlungsaufforderung von {Vereinsname}“, Überschrift „Zahlungsaufforderung“.
- Inhalt: Anrede („Guten Tag Markus Fuchs,“), „für die folgende(n) Position(en) bitten wir Sie um Ausgleich per Überweisung:“, je Position eine Zeile („Bezeichnung, Monat Jahr: Betrag, fällig Datum“ (z. B. „Vollmitglied, November 2026: 15,00 €, fällig 01.11.2026“; bei einer Forderung ohne Zeitraum fehlt der Monat)) mit einem eigenen Grundsatz je Position (Fuchs: „Die Lastschrift konnte mangels Kontodeckung nicht eingezogen werden.“; Krüger: „Die Lastschrift konnte nicht eingezogen werden, weil das angegebene Konto nicht erreichbar ist.“). Die Positionen sind nummeriert („Position 1: …“). Die Mail fordert Einzelüberweisungen („Bitte überweisen Sie jede Position einzeln mit dem jeweils genannten Betrag – für jede Position liegt ein GiroCode zum Scannen mit Ihrer Banking-App als Bild bei (die Datei „GiroCode-Position-1.png“ gehört zu Position 1 und so weiter).“), keinen Sammelbetrag, und führt je Position die Zahlungsdaten zum Abschreiben auf („Empfänger“, „IBAN“ in Vierergruppen aus dem Einziehenden Konto, „Betrag“, „Verwendungszweck“ mit Bezeichnung, Zeitraum und Forderungsnummer „F-…“). Es steht kein Rechtsvokabular darin.
- Gebündelt: eine Mail je Mitglied, nicht je Position.
- Nur Mitglieder mit E-Mail-Adresse bekommen die Mail.

### 9.4 GiroCode mit der Banking-App prüfen

**Rolle:** Du (Mailhog, Smartphone)

**Tun:**
1. Öffne in Mailhog die Mail an Markus Fuchs und die Anhänge.
2. Speichere oder öffne einen Anhang `GiroCode-Position-<Nummer>.png` und zeige ihn am Bildschirm. (Mailhog zeigt Anhänge nur im Reiter „MIME“ an; ein normales Mailprogramm zeigt sie unter der Mail.)
3. Scanne ihn mit der Banking-App deiner Wahl (Überweisung per QR-Code) oder einem QR-Scanner.
4. Öffne die Mail an Sophie Krüger und vergleiche.
5. Brich die Überweisung in der Banking-App **ab**.

**Erwartet:**
- **Ein Anhang je Position** (`GiroCode-Position-{Nummer der Position}.png`, passend zu „Position N“ im Text), jeweils ein quadratisches PNG (ca. 150 px oder größer), das sich scannen lässt.
- Die Banking-App füllt die Überweisung vor: Empfänger (Vereinsname), IBAN (das **einziehende Konto** aus den Einstellungen), Betrag in EUR (der Betrag der Position) und Verwendungszweck mit Bezeichnung, Zeitraum und Forderungsnummer („F-…“). Ein reiner QR-Scanner zeigt die EPC-Zeilen: `BCD`, `002`, `1`, `SCT`, Name, IBAN, `EUR…`, Zweck.
- Fehlt PHP die Erweiterung `gd` (0.7) oder dem einziehenden Konto die IBAN, kommt die Mail **ohne** Anhänge und ohne den Satz zum GiroCode; der Fehler steht im Nextcloud-Log.

**Beachte:** ⚠ Führe die Überweisung in der Banking-App **nicht** aus. Die IBAN der Testinstanz ist erfunden. Der GiroCode ist nur dann wirklich geprüft, wenn eine echte Banking-App ihn liest; ein Foto vom Bildschirm genügt.

### 9.5 Gebühren-Forderungen nach Rücklastschrift

**Rolle:** admin (Verwalter)

**Tun:**
1. Filtere unter **Einzug** → „Forderungen“ nach Fuchs („Mitglied“: `Fuchs`) und danach nach Krüger.
2. Öffne die Zeilen mit der Marke „Gebühr“.

**Erwartet:**
- Je eine zusätzliche Forderung vom Typ „Gebühr“ mit exakt der Bankgebühr aus dem Kontoauszug (keine Pauschale): **3,50 €** bei Fuchs, **4,00 €** bei Krüger, Zustand „offen“. Die Weiterbelastung gilt nur bei „Deckung fehlt“ und „Konto nicht nutzbar“.
- Ohne eingeschaltete Weiterbelastung (2.9) oder bei einem Grund wie „Widerspruch“ entsteht keine solche Forderung.

### 9.6 Mahnstand und „Je Mitglied“

**Rolle:** admin (Verwalter)

**Tun:**
1. Lies in „Forderungen“ die Spalte „Mahnstand“ bei Fuchs.
2. Klappe „Details“ auf und lies die Mahnreihe.
3. Klicke „Je Mitglied“ und danach wieder „Je Forderung“.

**Erwartet:**
- „Mahnstand“: erreichte Stufe („Zahlungsaufforderung“) mit „versandt am …“ und „Nächste Stufe: Zahlungserinnerung ab …“ (Abstand: der „Mahnabstand“ der Verwaltung, 14 Tage).
- Das Detail zeigt die Reihe: „Zahlungsaufforderung“ (versandt am …), „Zahlungserinnerung“ („fällig ab …“), „Mahnung“ und „An Vorstand eskaliert“ (je „noch nicht erreicht“) und den Hinweis „Mahnabstand: 14 Tage (Einstellung der Verwaltung).“
- „Je Mitglied“ fasst die Auswahl je Mitglied zusammen (Anzahl, Summe, Mahnstand, Störfall) mit den Knöpfen „Forderungen anzeigen“ und „Akte öffnen“; die Mahnstufen gehen gebündelt je Mitglied raus.

### 9.7 Zahlungsaufforderung für Überweiser

**Rolle:** admin (Verwalter), Mailhog

**Tun:**
1. Öffne in „Forderungen“ die Zeilen von Tobias Brandt (Oktober und November, Überweisung) und von Lena Bergmann (Q4, jetzt bezahlt, Filter „Zustand: erledigt …“ nutzen). Lies die Spalte „Mahnstand“.
2. Sieh in Mailhog die beiden Zahlungsaufforderungen an Tobias und an Lena aus 7.6 an und vergleiche sie mit denen nach der Rücklastschrift (9.3).

**Erwartet:**
- Bei Überweisern entsteht die Zahlungsaufforderung vor der Fälligkeit bzw. bei Überfälligkeit, nicht erst nach einer Rücklastschrift. Die Forderung von Tobias zeigt „Zahlungsaufforderung“ mit „versandt am {Tag von 7.6}“ und die nächste Stufe („Zahlungserinnerung ab …“).
- Die Mails tragen Betreff „Zahlungsaufforderung von {Verein}“, je Position einen Grundsatz („Für diese Position liegt uns bislang kein Zahlungseingang vor.“) und einen GiroCode je Position (Prüfung wie 9.4). Die Mail an Lena ist **englisch** (Sprache ihres Kontos, 14.7).
- Lenas Mail kam vor ihrer Überweisung (8.9); die Forderung ist inzwischen bezahlt und erhält keine Erinnerung mehr.

### 9.8 Stundung setzen und aufheben (Test A)

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne in „Forderungen“ `Test A Stundung` mit „Details“.
2. Klicke „Stunden“. Trage „Gestundet bis“ ein Datum ein und beobachte „Stundung setzen“. Trage dann die „Begründung (Pflicht)“ `Ratenzahlung vereinbart` ein und klicke „Stundung setzen“.
3. Klicke „Stundung aufheben“ und bestätige.

**Erwartet:**
- „Stundung setzen“ bleibt gesperrt, bis Datum **und** Begründung stehen. Danach: „Stundung gesetzt.“ und in der Liste die Marke „gestundet bis {Datum}“; im Detail statt „Stunden“ der Knopf „Stundung aufheben“, die Begründung und „Gestundet bis …: Zahlungserinnerung, Mahnung und Eskalation pausieren bis dahin; danach läuft die Mahnuhr von der zuletzt erreichten Stufe weiter.“
- Es gibt je Forderung höchstens eine laufende Stundung. Nach dem Aufheben (mit Rückfrage): „Stundung aufgehoben.“ und wieder der Knopf „Stunden“.

### 9.9 Erlass (Test B)

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne `Test B Erlass`, klicke „Erlassen“. Lies die Erklärung, trage `Härtefall laut Vorstandsbeschluss` bei „Begründung (Pflicht)“ ein und klicke „Erlass vermerken“.
2. Setze den Filter „Zustand“ auf „erledigt (bezahlt oder erlassen)“ und öffne die Forderung.

**Erwartet:**
- Der Text grenzt vom Storno ab: „Ein Erlass heißt: Die Forderung war berechtigt, wir verzichten aber darauf. Das ist jederzeit möglich, auch nach der Einreichung.“ und „… ist es kein Erlass, sondern ein Storno – das geht nur vor der Einreichung.“
- „Erlass vermerken“ bleibt ohne Begründung gesperrt. Danach: „Erlass vermerkt.“ und „erledigt (erlassen)“; im Detail „Erlassen“ mit der Begründung. Eine Buchung entsteht nicht.

### 9.10 Storno (Test C) und die Sperre nach der Einreichung

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne `Test C Storno`, klicke „Stornieren“. Lies die Erklärung, trage `Doppelt angelegt` bei „Begründung (Pflicht)“ ein und klicke „Stornieren“.
2. Setze den Filter „Zustand“ auf „storniert“ und prüfe die Forderung.
3. Öffne `Test D Lauf` (Theo; steckt im eingereichten Lauf) mit „Details“.

**Erwartet:**
- Der Text sagt: „Ein Storno heißt: Die Forderung hätte nie existieren dürfen (zum Beispiel doppelt oder irrtümlich angelegt). Das geht nur vor der Einreichung.“ Danach „Forderung storniert.“ und der Zustand „storniert“ mit der Begründung.
- Bei `Test D Lauf` fehlt der Knopf „Stornieren“; dort steht „Storno ist nur vor der Einreichung möglich, diese Forderung steckt schon in einem eingereichten Lauf. Ist sie berechtigt und Sie verzichten darauf, vermerken Sie einen Erlass.“ Der Knopf „Erlassen“ bleibt.
- Steckt eine Forderung in einem **freigegebenen**, nicht eingereichten Lauf, verweist die App auf „Lauf verwerfen“ zuerst (Ansicht „Zeitstrahl & Läufe“).

### 9.11 Als bezahlt vermerken (ohne Buchung)

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne bei Eva Schröders Forderung `Test A Stundung` (nach 9.8 wieder ohne Stundung) „Details“ und klicke „Als bezahlt markieren“.
2. Trage die Notiz `Bar am Vereinsabend` ein und klicke „Als bezahlt vermerken“.
3. Prüfe das Detail und unter **Buchungen** das Journal.

**Erwartet:**
- Text: „Vermerkt werden der Zeitpunkt, Ihr Name und die Notiz. Eine Buchung entsteht dadurch nicht – die Zahlung ordnen Sie wie gewohnt dem Bankumsatz zu.“
- „Forderung als bezahlt vermerkt.“ Zustand „erledigt (bezahlt)“; das Detail zeigt „Als bezahlt vermerkt“ mit Zeitstempel, deinem Namen und der Notiz, aber ohne Aktionsknöpfe. Das Journal ist unverändert.

### 9.12 Zahlungserinnerung und Mahnung (mehrtägig, optional)

**Rolle:** admin (Verwalter), Terminal, Mailhog

**Tun:**
1. Stelle in den Einstellungen („Rücklastschriften und Mahnwesen“) „Mahnabstand (Tage)“ auf `1` und speichere.
2. Starte am nächsten Tag den Mahnlauf und sieh in Mailhog und in „Forderungen“ nach:

```bash
/Users/FlorianLudwig/Projekte/nextcloud-apps/vereinsbuchhaltung/tests/dev/run-jobs.sh mahnwesen
```

3. Starte den Lauf am übernächsten und am dritten Tag erneut.
4. Stelle den Mahnabstand danach auf `14` zurück.

**Erwartet:**
- Am Tag nach der Zahlungsaufforderung: Mail „Zahlungserinnerung von {Verein}“ mit „wir möchten Sie an die folgende(n) noch offene(n) Position(en) erinnern:“ und dem Satz „Für diese Position liegt uns weiterhin kein Zahlungseingang vor.“, GiroCode je Position; Mahnstand „Zahlungserinnerung“.
- Am Tag danach: Mail „Mahnung von {Verein}“ mit „… bitten wir Sie dringend um umgehenden Ausgleich:“, „Trotz Erinnerung liegt für diese Position noch kein Zahlungseingang vor …“ und „Sollte der Betrag weiterhin nicht eingehen, legen wir den Vorgang dem Vorstand vor.“
- Eine gestundete Forderung bleibt dabei außen vor, bis die Stundung abläuft; danach läuft die Mahnuhr von der letzten Stufe weiter.
- Der Seeder hat keine Mahnstände rückdatiert: Die Stufen erscheinen erst an den genannten Tagen. Hast du keine Zeit, setze den Schritt auf „übersprungen“.

### 9.13 Eskalation: Aufgabe für den Vorstand

**Rolle:** admin (Verwalter)

**Tun:**
1. Prüfe nach der Mahnung und einem weiteren Mahnabstand (frühestens am vierten Tag aus 9.12) die Forderung und das Klemmbrett.

**Erwartet:**
- Mahnstand „An Vorstand eskaliert“, im Detail „Aufgabe für den Vorstand besteht“. Im Klemmbrett steht „Mahnstufe an Vorstand eskaliert: {Mitglied}, {Betrag} € ({Bezeichnung}).“ Danach läuft keine weitere Automatik: Die Forderung wird bezahlt, erlassen oder gestundet.

**Beachte:** Setze den Schritt auf „übersprungen“, wenn du 9.12 nicht gemacht hast.

## Phase 10 – Forderungen in der Offene-Posten-Sicht

**Ziel:** Du prüfst, dass Forderungen an Mitglieder unter „Buchungen → Offene Posten“ nur lesbar sind und in den Reiter „Einzug“ verweisen, während freie Posten ohne Mitglied wie bisher bearbeitbar bleiben.

**Nutzer:** admin (Verwalter).

**Vorbedingung:** Phase 7 bis 9: Forderungen mit verschiedenen Zuständen existieren.

### 10.1 Offene Posten öffnen

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne **Buchungen** → „Offene Posten“.
2. Lies den Hinweis über der Tabelle und wechsle die Filter-Chips (u. a. „Offen“ und „Alle“).

**Erwartet:**
- Die Ansicht zeigt freie Posten und Forderungen an Mitglieder zusammen. Forderungen tragen eine Marke („Beitrag“ bzw. „Gebühr“).
- Der Hinweis lautet: „Forderungen an Mitglieder (Beitrag oder Gebühr) sehen Sie hier nur. Bearbeitet werden sie im Reiter „Beiträge“ unter Einzug, Segment „Forderungen“: als bezahlt vermerken, stunden, erlassen, stornieren.“

### 10.2 Keine Schreibaktionen an Forderungen

**Rolle:** admin (Verwalter)

**Tun:**
1. Suche in der Tabelle die Zeile einer Forderung (z. B. Fuchs) und eines freien Postens.
2. Wähle den Chip „Alle“ und suche die erlassene Forderung `Test B Erlass`.

**Erwartet:**
- An der Forderung stehen **keine** Knöpfe „Bezahlt“, „Stornieren“ oder „Wieder öffnen“. Stattdessen gibt es „Im Einzug bearbeiten“ (für Revisoren „Im Einzug ansehen“).
- Die erlassene Forderung trägt die Marke „Erlassen“ und ebenfalls kein „Wieder öffnen“.
- Eine bezahlte, erlassene oder stornierte Forderung bleibt, wie sie ist (kein Wiedereröffnen).

### 10.3 Sprung in den Einzug

**Rolle:** admin (Verwalter)

**Tun:**
1. Klicke bei einer Forderung „Im Einzug bearbeiten“.
2. Öffne dort „Details“ und prüfe, ob Aktionen wie „Als bezahlt markieren“ möglich sind.
3. Klicke „Alle Mitglieder“.

**Erwartet:**
- Die App wechselt in **Beiträge** → **Einzug** → „Forderungen“, eingegrenzt auf das Mitglied (Chip „Mitglied: {Name}“ mit dem Knopf „Alle Mitglieder“).
- Das Detail bietet die Aktionen („Als bezahlt markieren“, „Stunden“, „Erlassen“, „Stornieren“). „Alle Mitglieder“ hebt die Eingrenzung auf.

### 10.4 Freie Posten bleiben bedienbar

**Rolle:** admin (Verwalter)

**Tun:**
1. Lege in „Offene Posten“ über „Neuer offener Posten“ einen Posten an: „Debitor“ `Schreinerei Holzwurm`, „Betrag (€)“ `80`, „Fällig am“ ein Datum, „Notiz“ optional. Klicke „Anlegen“.
2. Klicke bei diesem Posten „Bezahlt“.
3. Lege einen zweiten Posten an und klicke „Stornieren“, danach „Wieder öffnen“.

**Erwartet:**
- „Offener Posten angelegt.“ Der freie Posten trägt **keine** Marke „Beitrag“ oder „Gebühr“.
- „Als bezahlt markiert.“, „Storniert.“ und „Wieder geöffnet.“ funktionieren wie bisher.

## Phase 11 – Self-Service „Mein Beitrag“

**Ziel:** Du erlebst den Bereich so, wie ihn Mitglieder sehen: Stammdaten pflegen, Beitrag ändern, Mandat erteilen, ändern und widerrufen, Rücklastschriften im Klartext, Quittungsmails und Aktivität.

**Nutzer:** jane (Fenster D, Du-Form), john (Fenster E, Sie-Form), bob (für den Rücklastschrift-Fall), admin zum Gegenprüfen, Mailhog.

**Vorbedingung:** Der Self-Service-Schalter ist an (2.3) und die Konten sind mit den Mitgliedern verknüpft. Die Rücklastschriften aus Phase 8 sind verbucht.

### 11.1 Überblick und Datenhygiene (jane)

**Rolle:** jane (Mitglied ohne Buchhaltungsrolle)

**Tun:**
1. Lade in Fenster D die App neu und sieh dir die Kopfzeile an.
2. Lies alle Karten von oben nach unten.
3. Suche mit ⌘F nach `Testnotiz`.

**Erwartet:**
- Es gibt nur den Reiter „Mein Beitrag“. Zeitraum, Knopf „Buchung“, Hilfe, Klemmbrett und Geldbestand fehlen (sie gehören zur Buchhaltung).
- Karten: „Meine Stammdaten“ (Name, E-Mail, Telefon, Adresse, Mitgliedsnummer, „Mitglied seit“; Knopf „Bearbeiten“), „Mein Beitrag“ (je Zuweisung: Gruppe, „Untergrenze: X“, „Monatsbeitrag (€)“, „Turnus“, „Vorschau“, „Speichern“), „Mein SEPA-Lastschriftmandat“ (IBAN **maskiert**, z. B. `DE02••••2051`, Kontoinhaber, Status „Aktiv“ und die Knöpfe „Bankverbindung ändern“, „Kontoinhaber wechseln“, „Mandat widerrufen“; darunter „Rücklastschriften“), „Meine Beitragsbestätigung“ und „Meine Daten“.
- Die **interne Notiz** aus 3.4 erscheint nirgends. Du siehst nur deine eigenen Angaben, nie die anderer Mitglieder.
- Die volle IBAN steht nirgends, nur die maskierte.

### 11.2 Stammdaten ändern und E-Mail-Wechsel (jane)

**Rolle:** jane (Mitglied)

**Tun:**
1. Klicke bei „Meine Stammdaten“ auf „Bearbeiten“. Notiere, welche Felder du ändern kannst.
2. Ändere „Telefon“ und klicke „Speichern“.
3. Ändere die „E-Mail“ auf `jana.neu@example.org`, lies den Hinweis darunter und speichere.
4. Öffne Mailhog.
5. Stelle die ursprüngliche E-Mail-Adresse wieder ein und speichere.

**Erwartet:**
- Du kannst Vorname, Nachname, E-Mail, Telefon, Straße, PLZ, Ort und Land ändern; Mitgliedsnummer sowie Eintritts- und Austrittsdatum bleiben unveränderlich (das macht die Kassenführung).
- Der Hinweis bei geänderter E-Mail: „Bei einer Änderung der E-Mail-Adresse erhält die bisherige Adresse zur Sicherheit eine Mail darüber.“
- Toast „Kontaktdaten gespeichert.“
- Mailhog: eine Quittungsmail „Deine Kontaktdaten wurden aktualisiert“ (Du-Fassung) an die **neue** Adresse und eine Warnmail an die **alte** Adresse („E-Mail-Adresse geändert“; die neue Adresse ist darin maskiert, z. B. `j•••@e•••••.org`, ohne Rücknahme-Link, mit dem Hinweis, sich bei Unklarheit an die Kassenführung zu wenden). Die Du- oder Sie-Fassung der Warnmail richtet sich nach dem Konto.

### 11.3 IBAN ändern mit Vorschau (jane)

**Rolle:** jane (Mitglied), danach admin

**Tun:**
1. Klicke bei „Mein SEPA-Lastschriftmandat“ auf „Bankverbindung ändern“.
2. Lies den Dialog. Trage bei „Neue IBAN“ `DE89 3704 0044 0532 0130 00` ein und klicke „IBAN ändern“.
3. Sieh in Mailhog nach der Quittungsmail.
4. Öffne in Fenster A Janas Akte → „Mandat verwalten“ und lies Verlauf und „Änderungen der Bankverbindung“.

**Erwartet:**
- Dialog „Bankverbindung ändern“ mit den zwei Auswahlen „Gleiches Konto, nur die IBAN hat sich geändert“ (vorgewählt) und „Der Kontoinhaber wechselt“. Der Text lautet in Du-Form: „Vorschau: Wirkt ab sofort. Kein Sperrfenster – du kannst die IBAN bis zur Einreichung des nächsten Einzugs jederzeit ändern.“
- Toast „IBAN geändert.“ Die Karte zeigt die neue IBAN maskiert (`DE89••••3000`).
- Quittungsmail „Bankverbindung geändert“ in Du-Form: „Du hast die Bankverbindung deines SEPA-Lastschriftmandats geändert (Referenz …).“, „Wirksam ab sofort, sofern für die laufende Periode noch keine Vorankündigung verschickt wurde.“ und „Diese Mail ist die Bestätigung dieser Änderung – eine Handlung deinerseits ist nicht nötig.“
- In der Akte steht im Verlauf die Änderung mit dem Urheber **„Mitglied (jane)“** (der Kanal entscheidet, nicht die Rolle), und die Bankverbindung hat ein offenes Amendment.
- Die IBAN kennt kein Sperrfenster: Sie lässt sich auch nach der Vorabinfo (7.7) ändern.

### 11.4 Kontoinhaber wechseln: Reibungsdialog (jane)

**Rolle:** jane (Mitglied)

**Tun:**
1. Klicke „Kontoinhaber wechseln“. Lies den Dialog.
2. Klicke den Ausweg „Ich habe nur ein neues Konto → IBAN ändern“.
3. Wähle wieder „Der Kontoinhaber wechselt“ und klicke „Abbrechen“ (nicht wechseln).

**Erwartet:**
- Roter Kasten: „Das bisherige Mandat wird endgültig beendet, ein neues wird sofort elektronisch erteilt. Das lässt sich nicht rückgängig machen.“ (bei offenen Forderungen mit „Noch offen: …“).
- Der Ausweg ist die Primäraktion und schaltet auf die IBAN-Änderung zurück. Darunter stehen „Neues Mandat für den neuen Kontoinhaber“ mit „Neue IBAN“, „Neuer Kontoinhaber“ und der **Mandatstext** (Überschrift „Mandatstext“) sowie der Knopf „Kontoinhaber wechseln“.
- Abbrechen ändert nichts.

### 11.5 Widerruf (jane)

**Rolle:** jane (Mitglied), danach admin

**Tun:**
1. Klicke „Mandat widerrufen“. Lies den Dialog. Klicke zuerst den Ausweg „Ich habe nur ein neues Konto → IBAN ändern“ und danach im IBAN-Dialog „Abbrechen“.
2. Klicke wieder „Mandat widerrufen“ und „Mandat endgültig widerrufen“.
3. Sieh in Mailhog nach den Mails.
4. Öffne in Fenster A das Klemmbrett.

**Erwartet:**
- Dialog „Mandat widerrufen“: „Der Widerruf ist endgültig – ein widerrufenes Mandat lässt sich nicht wieder aktivieren. Für künftige Einzüge brauchst du danach ein neues Mandat.“ und, falls Forderungen offen sind, „Noch offen: … “. Der Ausweg „Ich habe nur ein neues Konto → IBAN ändern“ steht als Primäraktion da; es gibt keine Zweitfaktor-Abfrage.
- Toast „Mandat widerrufen.“ Danach steht in der Karte „Kein Mandat hinterlegt.“ und der Knopf „Mandat jetzt erteilen“.
- Mailhog: Quittungsmail „SEPA-Lastschriftmandat widerrufen“ („Du hast dein SEPA-Lastschriftmandat widerrufen (Referenz …).“, „Endgültig – ein Widerruf lässt sich nicht rückgängig machen.“) und, wenn offene Forderungen bestehen, eine **Zahlungsaufforderung** mit dem Satz „Das SEPA-Mandat wurde widerrufen, ein Einzug per Lastschrift ist für diese Position nicht mehr möglich.“ und GiroCode je Position.
- Eine Nextcloud-Aktivitätsmail zum Widerruf kann zeitverzögert kommen (die Aktivitäten-App verschickt Mails per Cron).
- Im Klemmbrett steht ein Hinweis zu Forderungen nach Widerruf („… nach Widerruf des Mandats weiter offen“, eine Zeile).

**Beachte:** ⚠ Unumkehrbar für dieses Mandat; in 11.6 erteilt Jana ein neues.

### 11.6 Mandat neu erteilen (jane)

**Rolle:** jane (Mitglied)

**Tun:**
1. Klicke „Mandat jetzt erteilen“.
2. Lies den Dialog „Mandat erfassen und erteilen“. Trage bei „IBAN“ `DE02 1203 0000 0000 2020 51` ein, lass „Kontoinhaber“ leer (Vorgabe: dein Name) und klicke „Jetzt erteilen“.
3. Suche mit ⌘F nach der vollen IBAN.
4. Sieh in Mailhog nach der Quittung.

**Erwartet:**
- Der Dialog zeigt oben „Vorschau: Wirkt ab sofort – mit der Bestätigung erteilst du das SEPA-Lastschriftmandat elektronisch.“ und den **Mandatstext** (Pflichtblock und Rahmen, ohne Marker `vbh:rahmen`).
- Toast „Mandat erteilt.“ Die Karte zeigt Status „Aktiv“ und die IBAN **maskiert**; die volle IBAN taucht nirgends auf.
- Quittungsmail „SEPA-Lastschriftmandat erteilt“ in Du-Form („Du hast ein SEPA-Lastschriftmandat elektronisch erteilt (Referenz …).“, „Wirksam ab sofort.“).
- In Fenster A (admin) steht im Verlauf der neuen Referenz „Mitglied (jane)“ und die Zustimmungsdaten (Zeitpunkt, Konto, IP).

### 11.7 Aktivität und Benachrichtigungen (jane)

**Rolle:** jane (Mitglied)

**Tun:**
1. Öffne in Nextcloud die Aktivitäten-App (Beschriftung nicht belegt) und suche die Einträge.
2. Öffne die persönlichen Einstellungen zu Aktivitäten und Benachrichtigungen (Abschnitt und Beschriftung der Nextcloud-Seite nicht belegt) und suche die Einträge der Vereinsbuchhaltung.

**Erwartet:**
- Einträge der Vereinsbuchhaltung in der Aktivität, z. B. „SEPA-Lastschriftmandat elektronisch erteilt“, „Bankverbindung des SEPA-Lastschriftmandats geändert“, „SEPA-Lastschriftmandat widerrufen“ und „Kontaktdaten aktualisiert“.
- In den Einstellungen zwei Typen: „Änderungen an meinem Beitrag (Mandat, Beitrag, Stammdaten)“ (Mail standardmäßig aus) und „Widerruf eines eigenen SEPA-Lastschriftmandats“ (Mail standardmäßig an).

### 11.8 Mandat-Entwurf bestätigen (john, Sie-Form)

**Rolle:** john (Mitglied, förmliches Deutsch)

**Tun:**
1. Lade in Fenster E die App neu, öffne „Mein Beitrag“ und lies die Karte „Mein SEPA-Lastschriftmandat“.
2. Klicke „Jetzt bestätigen“, lies den Dialog „Mandat bestätigen“ und klicke „Ich stimme zu und erteile das Mandat“.
3. Sieh in Mailhog nach der Quittung.
4. Suche mit ⌘F nach `Einmal-Link oder im Self-Service`.

**Erwartet:**
- Jonas Richter hat einen **elektronischen Entwurf** (`M-2`): Status „Entwurf“ und die Knöpfe „Jetzt bestätigen“, „Stattdessen Link per Mail zuschicken“ und „Entwurf verwerfen“. Ein Papier-Entwurf zeigte nur „Entwurf verwerfen“, denn Mitglieder aktivieren keine Papier-Mandate.
- Dialog „Mandat bestätigen“ in **Sie-Form**: „Für Sie liegt ein elektronischer Mandats-Entwurf vor. Mit der Bestätigung erteilen Sie das SEPA-Lastschriftmandat – wirksam ab sofort.“ mit maskierter IBAN (`DE89••••3000`), Kontoinhaber und dem Mandatstext.
- Toast „Mandat erteilt.“, Status „Aktiv“. Mail „SEPA-Lastschriftmandat erteilt“ bzw. „Sie haben Ihr SEPA-Lastschriftmandat elektronisch bestätigt (Referenz M-2).“, „Wirksam ab sofort.“
- Die interne Notiz der Akte („Mandat per Einmal-Link oder im Self-Service bestätigen lassen.“) erscheint nirgends in „Mein Beitrag“.
- „Stattdessen Link per Mail zuschicken“ (nicht klicken, wenn du bestätigt hast) zeigt „Bestätigungslink wurde per Mail verschickt.“ und schickt eine Mail wie in 4.9.
- Im Klemmbrett (Fenster A) verschwindet nach „Aufgaben aktualisieren“ die Aufgabe zu Jonas' Mandat; sein Beitrag erzeugt beim nächsten Tageslauf Forderungen.

### 11.9 Beitrag ändern: Vorschau, Untergrenze, Turnus (john)

**Rolle:** john (Mitglied)

**Tun:**
1. Lies in der Karte „Mein Beitrag“ den Gruppennamen („Vollmitglied“) und die „Untergrenze“.
2. Beobachte „Speichern“ (gesperrt?).
3. Trage bei „Monatsbeitrag (€)“ `11` ein (unter der Untergrenze) und klicke „Vorschau“.
4. Trage `20` ein und klicke „Vorschau“. Ändere danach den Betrag noch einmal auf `21` und beobachte „Speichern“.
5. Setze `20` wieder ein, klicke „Vorschau“ und „Speichern“.
6. Wechsle den „Turnus“ von „vierteljährlich“ auf „halbjährlich“, „Vorschau“, „Speichern“.
7. Sieh in Mailhog nach den Quittungen.
8. Stelle Betrag `15` und Turnus „vierteljährlich“ wieder ein (jeweils mit „Vorschau“ und „Speichern“).

**Erwartet:**
- „Untergrenze: 12,00 €“. „Speichern“ ist gesperrt, bis eine Vorschau für **genau** die eingetragenen Werte geladen ist; nach einer weiteren Änderung ist es wieder gesperrt.
- Bei `11`: die Meldung „Der Monatsbeitrag darf die Untergrenze von 12,00 € nicht unterschreiten.“ Keine Vorschau, nichts wird gespeichert.
- Die Vorschau lautet „Wirkt ab {heute} · erster Einzug am {Datum} · Betrag {Betrag}“: Jonas hat noch keine Forderung, also keine vorabinformierte Periode (sein Mandat war bis eben ein Entwurf). Hoch ist frei, runter nur bis zur Untergrenze. Das Turnus-Feld bietet nur die erlaubten Turnusse der Gruppe Vollmitglied (monatlich, vierteljährlich, halbjährlich, jährlich).
- Toast „Beitrag geändert.“ Quittungsmail „Ihr Beitrag wurde geändert“ mit „Ihr Monatsbeitrag wurde von 15,00 € auf 20,00 € geändert.“, „Wirkt ab: …“, „Voraussichtlich erster betroffener Einzug: …“ und „Diese Mail ist die Bestätigung dieser Änderung – eine Handlung Ihrerseits ist nicht nötig.“ (bei Turnuswechsel „Ihr Zahlungsturnus wurde von alle 3 Monate auf alle 6 Monate geändert.“).
- Wäre für Johns Periode schon eine Vorabinfo raus, käme statt der Vorschau die Ablehnung aus 7.17.

**Beachte:** Ein Mitglied mit **individueller Untergrenze** (Anna Koch, 10,00 €) sieht diesen Wert statt der Gruppen-Untergrenze; ihre Begründung ist nie sichtbar (11.12).

### 11.10 Rücklastschriften im Klartext (Fall ohne und mit)

**Rolle:** jane (Fall ohne), admin und bob (Fall mit)

**Tun:**
1. **Fall ohne:** Lies in Janas Karte „Mein SEPA-Lastschriftmandat“ den Abschnitt „Rücklastschriften“.
2. **Fall mit:** Trage in Fenster A in der Akte von Markus Fuchs bei „E-Mail“ die Adresse von `bob` ein (alte Adresse notieren: ________), speichere und klicke bei „Nextcloud-Konto“ „Vorschläge suchen“ und „Verknüpfen“.
3. Lade in Fenster C (bob) neu und öffne „Mein Beitrag“. Lies den Abschnitt „Rücklastschriften“.
4. Suche mit ⌘F nach `AM04` und `Insufficient`.
5. Räume auf: Klicke in Fuchs' Akte „Verknüpfung lösen“ und stelle die alte E-Mail-Adresse wieder ein.

**Erwartet:**
- Fall ohne: „Bisher keine Rücklastschrift.“ (Jana hat keine).
- Fall mit: bob (Revisor und Mitglied) sieht neben „Beiträge“ den Reiter „Mein Beitrag“. Im Abschnitt „Rücklastschriften“ steht für Fuchs eine Zeile „02.10.2026 · 15,00 €“ mit der Bezeichnung und dem Klartext „Die Lastschrift konnte mangels Kontodeckung nicht eingezogen werden.“ (nur eine Zeile, weil nur die Oktober-Forderung zurückkam; neueste zuerst).
- **Nie** der Bankcode (`AM04`), der Freitext der Bank („Insufficient funds“) oder eine IBAN; nichts von anderen Mitgliedern.
- Schlägt das Laden fehl, steht statt des Leer-Hinweises „Rücklastschriften konnten nicht geladen werden.“ (siehe 16.5).

### 11.11 Beitragsbestätigung und Datenübersicht aus Mitgliedssicht (john)

**Rolle:** john (Mitglied)

**Tun:**
1. Wähle in der Karte „Meine Beitragsbestätigung“ das „Beitragsjahr“ und klicke „Öffnen“.
2. Klicke in „Meine Daten“ auf „Datenübersicht öffnen“.

**Erwartet:**
- Beide Seiten öffnen in einem neuen Tab (Inhalte siehe Phase 12). Die Auswahl „Beitragsjahr“ bietet die Jahre mit mindestens einem bezahlten Beitrag und das laufende.
- Die Hinweise sprechen Johns Form: „Informelle Bestätigung der bezahlten Beiträge eines Beitragsjahres – kein amtlicher Spendennachweis nach § 10b EStG.“ und „… über alle zu Ihrer Mitgliedschaft gespeicherten Daten …“.

### 11.12 Individuelle Untergrenze sehen (Anna Koch, optional)

**Rolle:** admin (Verwalter), bob

**Tun:**
1. Trage in Fenster A in der Akte von Anna Koch bei „E-Mail“ die Adresse von `bob` ein (alte Adresse notieren: ________), speichere und verknüpfe sie wie in 11.10 mit `bob` („Vorschläge suchen“, „Verknüpfen“). Verknüpfe Markus Fuchs vorher wieder (11.10, Schritt 5), da ein Konto nur zu einem Mitglied gehört.
2. Lade in Fenster C (bob) neu, öffne „Mein Beitrag“ und lies die Karte „Mein Beitrag“.
3. Trage bei „Monatsbeitrag (€)“ `11` ein und klicke „Vorschau“.
4. Räume auf: „Verknüpfung lösen“ und die alte E-Mail-Adresse wieder eintragen.

**Erwartet:**
- Die Karte nennt „Untergrenze: 10,00 €“ (die individuelle Untergrenze von Anna Koch), nicht die 12,00 € der Gruppe Vollmitglied. Die Begründung („Familienrabatt laut Vorstandsbeschluss“) ist **nicht** sichtbar.
- Mit `11` € liefert die Vorschau „Wirkt ab …“ bzw. die Ablehnung des Sperrfensters (7.17), aber **nicht** die Untergrenzen-Meldung, weil 11 € über 10 € liegen.
- Speichere nichts.

## Phase 12 – Beitragsbestätigung & Datenschutz

**Ziel:** Du prüfst die Beitragsbestätigung (informell, nur bezahlte Beiträge), die Datenübersicht (Art. 15 DSGVO) und die Anonymisierung.

**Nutzer:** admin (Verwalter), jane/john für die Mitgliedssicht.

**Vorbedingung:** Phase 8 (Zahlungen verbucht), Test G aus 5.8 als bezahlte Gebühr. Für 12.6 bis 12.9 muss Hans Becker anonymisierungsreif sein (12.0).

### 12.0 Vorbereitung: Hans Becker zum Anonymisierungs-Kandidaten machen

**Rolle:** Du (Terminal)

**Tun:**
1. Öffne Hans Beckers Akte (Abschnitt „Anonymisierung (Art. 17 DSGVO)“). Steht dort „Anonymisierungsreif: …“, hast du in 0.4 schon mit `--with-anonymization-booking` geseedet und überspringst den Rest dieses Schritts.
2. Steht dort „Noch keine zugehörige Buchung – die 10-Jahres-Frist läuft noch nicht.“, wähle:
   - **Variante A:** Überspringe die Anonymisierung (12.6 bis 12.9 auf „übersprungen“) und mache mit 13 weiter.
   - **Variante B:** Lege das Szenario jetzt neu an, **mit** der Buchung von 2014. Das löscht alle Modul-Daten deiner bisherigen Durchführung (Wegwerf-Mitglieder, Einzelforderungen, Läufe, Zuordnungen aus dem Bankabgleich); der Stand ist danach wieder der des Seeders „vor dem Tageslauf“, und die Phasen 13 bis 18 laufen gegen dieses frische Szenario (nimm statt der Wegwerf-Mitglieder Seeder-Mitglieder und lass Schritte, die Wegwerf-Daten brauchen, aus). Bereits importierte Bankumsätze bleiben stehen (für einen erneuten Import `--wipe --wipe-bank`, siehe 0.4):

```bash
/Users/FlorianLudwig/Projekte/nextcloud-apps/vereinsbuchhaltung/tests/dev/in-container.sh seed-beitraege.php --wipe --with-anonymization-booking
```

**Erwartet:**
- Hans Becker (1010) ist laut Seeder **ohne** diese Buchung kein Kandidat: Die Frist läuft ab der letzten **verbuchten** Zahlung (Buchung mit Journal), ohne Buchung läuft keine.
- Mit der Option legt der Seeder eine Buchung vom **10.02.2014** über 144,00 € an und verknüpft sie mit Hans' bezahlter Jahresforderung 2013. Dafür materialisiert die App die **Geschäftsjahre 2014 bis 2025** (12 leere Jahre) in der Buchhaltung dieser Instanz. Das siehst du in der Auswahl „Zeitraum“ in der Kopfzeile.
- Danach steht in Hans' Akte „Anonymisierungsreif: die letzte zugehörige Buchung liegt mehr als 10 Jahre zurück – die Bestätigung ist irreversibel.“

**Beachte:** ⚠ Nebenwirkung auf die Buchhaltung dieser Instanz: die zwölf zusätzlichen Geschäftsjahre und die Buchung von 2014. `--purge` räumt sie wieder weg, setzt aber auch Einstellungen, Rollen und Sprachen zurück; danach legst du das Szenario mit dem Seeder neu an (0.4).

### 12.1 Beitragsbestätigung (Kassenwart-Kanal)

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne Jana Hoffmanns Akte und scrolle zu „Beitragsbestätigung“.
2. Wähle bei „Beitragsjahr“ das laufende Jahr und klicke „Öffnen“ (neuer Tab).
3. Drucke die Seite mit Strg+P bzw. ⌘P in die Vorschau (nicht ausgeben).

**Erwartet:**
- Die Akte erklärt: „Informelle Bestätigung der bezahlten Beiträge eines Beitragsjahres – kein amtlicher Spendennachweis nach § 10b EStG. Stellvertretung durch den Kassenwart.“
- Die Seite trägt die Überschrift „Beitragsbestätigung {Beitragsjahr}“, die Zeile „Beitragsjahr {…} ({von}–{bis}) · erstellt am …“, den Hinweis „Zum Drucken oder Als-PDF-Speichern: Strg+P (Mac: ⌘P) im Browser.“, Name, Adresse und Mitgliedsnummer und die Tabelle „Bezahlte Beiträge“ mit „Fälligkeitsperiode“, „Beschreibung“, „Betrag“ und einer Zeile „Summe“. Der Verweis auf § 10b EStG steht unübersehbar da.
- Aufgeführt ist **jede bezahlte Beitragsforderung** einzeln: Janas September-Beitrag (vom Seeder per Erledigungsvermerk bezahlt) und der nach 8.6 bezahlte Oktober-Beitrag, zusammen **30,00 €**.
- Es ist eine Live-Ansicht, kein gespeichertes Dokument: Bei jedem Öffnen entsteht sie neu.

**Beachte:** Eingeordnet wird nach **Fälligkeitsperiode, nicht nach Zahlungsdatum**; ein Beitrag für den Dezember, der erst im Januar bezahlt wird, zählt zum alten Jahr.

### 12.2 Gebühren und Unbezahltes fließen nie ein

**Rolle:** admin (Verwalter)

**Tun:**
1. Prüfe in der Bestätigung aus 12.1, ob `Test G Gebühr` (20 €, bezahlt, Typ „Gebühr“) oder Janas offene Forderungen auftauchen.
2. Wähle bei „Beitragsjahr“ ein Jahr ohne bezahlte Beiträge, falls angeboten, bzw. ein anderes Jahr.

**Erwartet:**
- Die Gebühr erscheint **nirgends**, auch nicht in der Summe; ebenso keine offenen, stornierten oder erlassenen Forderungen.
- Ohne bezahlte Beiträge in einem Jahr steht dort „Keine bezahlten Beitrags-Forderungen in diesem Beitragsjahr.“

### 12.3 Fehlende Adresse: Hinweis nur am Bildschirm

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne in der Akte von **Lena Bergmann** (hat laut Seeder keine Anschrift, sie ist „Studentin“) die Beitragsbestätigung.
2. Drucke in die Vorschau.
3. Ergänze die Adresse in der Akte und öffne die Bestätigung erneut.

**Erwartet:**
- Am Bildschirm erscheint „Adresse jetzt hinterlegen: Für eine Bestätigung mit vollständiger Anschrift fehlen noch Straße, PLZ oder Ort.“; in der Druckvorschau steht der Hinweis **nicht**.
- Nach dem Ergänzen verschwindet der Hinweis. Es gibt keine Admin-Aufgabe dazu.

### 12.4 Beitragsbestätigung aus Mitgliedssicht

**Rolle:** jane (Mitglied)

**Tun:**
1. Öffne in „Mein Beitrag“ unter „Meine Beitragsbestätigung“ das Beitragsjahr und „Öffnen“.

**Erwartet:**
- Dieselbe Seite wie in 12.1, aber nur mit **Janas** Daten. Ein Zugriff auf andere Mitglieder ist nicht vorgesehen (die Mitglieds-ID kommt aus der Kontoverknüpfung, nicht aus der Adresse).
- Es gibt keinen Sammellauf, keinen Versand aus der App und keinen Link ohne Anmeldung.

### 12.5 Datenübersicht (Art. 15 DSGVO)

**Rolle:** admin (Verwalter), danach jane

**Tun:**
1. Öffne Markus Fuchs' Akte und klicke bei „Datenübersicht (Art. 15 DSGVO)“ auf „Datenübersicht öffnen“ (neuer Tab).
2. Lies die Abschnitte.
3. Öffne als jane in „Mein Beitrag“ unter „Meine Daten“ die „Datenübersicht öffnen“.

**Erwartet:**
- Überschrift „Datenübersicht“ mit „Auskunft nach Art. 15 DSGVO (kein strukturierter Export nach Art. 20) · erstellt am …“ und dem Druckhinweis.
- Abschnitte: „Stammdaten“ (Anzeigename, E-Mail, Telefon, Adresse, Mitgliedsnummer, „Mitglied seit“, „Austritt zum“), „SEPA-Lastschriftmandate“ (Referenz, IBAN **maskiert**, Kontoinhaber, Status, „Aktiviert am“, „Beendet am“) mit dem Unterabschnitt „Rücklastschriften“ („Eingegangen am“, „Grund“, „Gebühr“), „Forderungen“ („Zeitraum/Fälligkeit“, Beschreibung, Betrag, Zustand) und „Beitragszuweisungen“ („Beitragsgruppe“, „Turnus“, „Monatsbetrag“, „Zahlungsart“, „Gültig von“, „Gültig bis“). Turnus und Zahlungsart stehen in Worten („monatlich“, „vierteljährlich“, „Lastschrift“ oder „Überweisung“), nicht als „1“ oder „direct_debit“.
- Es gibt keinen Export-Knopf (kein Art. 20). Janas Übersicht zeigt nur ihre eigenen Daten.

### 12.6 Anonymisierungsreife erkennen

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne die Akte von Hans Becker und lies den Abschnitt „Anonymisierung (Art. 17 DSGVO)“.
2. Sieh zum Vergleich in den Akten von Jana Hoffmann und von einem neu angelegten Wegwerf-Mitglied (z. B. Dora Testentwurf) nach.
3. Suche Hans Becker im Klemmbrett.

**Erwartet:**
- Bei Hans Becker (Austritt 31.12.2013, nur mit der Buchung aus 12.0): „Anonymisierungsreif: die letzte zugehörige Buchung liegt mehr als 10 Jahre zurück – die Bestätigung ist irreversibel.“ mit dem Knopf „Jetzt anonymisieren“.
- Bei Jana: „Noch nicht anonymisierungsreif (frühestens ab {Datum} – 10 Jahre nach der letzten zugehörigen Buchung).“ ohne Knopf; bei einem Mitglied ohne Buchung: „Noch keine zugehörige Buchung – die 10-Jahres-Frist läuft noch nicht.“ ohne Knopf. Ein aktives Mitglied oder eines mit lebendem Mandat ist nie reif.
- Im Klemmbrett (unter „Hinweise“): „Hans Becker: anonymisierungsreif, die letzte zugehörige Buchung liegt mehr als 10 Jahre zurück. Die Anonymisierung muss ein Buchhalter bestätigen, sie geschieht nie von selbst.“

### 12.7 Anonymisieren (Hans Becker)

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne vorher Hans Beckers Datenübersicht (12.5) und halte Name, E-Mail und IBAN fest.
2. Klicke in der Akte „Jetzt anonymisieren“. Lies den Dialog „Mitglied anonymisieren“.
3. Klicke zunächst „Abbrechen“, dann wieder „Jetzt anonymisieren“ und bestätige.
4. Öffne die Akte erneut.

**Erwartet:**
- Der Dialog fragt: „Name, Kontaktdaten, Bankverbindungen und personenbezogene Freitexte von „Hans Becker“ unwiderruflich schwärzen? Das lässt sich nicht rückgängig machen.“ „Abbrechen“ ändert nichts.
- Nach dem Bestätigen: „Mitglied anonymisiert.“ Die Akte schließt sich; geschieht nichts automatisch (nur dein Bestätigen löst es aus).
- Beim erneuten Öffnen: der Hinweis „Diese Mitgliedsakte wurde am {Datum} DSGVO-anonymisiert. Name, Kontaktdaten und personenbezogene Freitexte sind unwiderruflich entfernt; Stammdaten können nicht mehr bearbeitet werden.“, die Felder sind gesperrt, und bei „Anonymisierung“ steht „Bereits am {Datum} anonymisiert.“ ohne Knopf. Die Aufgabe im Klemmbrett ist weg.

**Beachte:** ⚠ Unumkehrbar. Der Seeder (`--wipe`, dann neu) stellt Hans Becker wieder her. Ein gelöschtes Nextcloud-Konto löst die Anonymisierung nie aus und umgekehrt.

### 12.8 Was geschwärzt wird und was bleibt

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne die Datenübersicht des anonymisierten Hans Becker erneut und vergleiche mit deinen Notizen aus 12.7.
2. Suche seine Forderungen unter **Einzug** → „Forderungen“ und **Buchungen** → „Offene Posten“, und sieh in den Läufen nach seinen Posten (falls er in einem vorkommt).

**Erwartet:**
- **Geschwärzt:** Name, Kontaktdaten und interne Notiz; IBAN, BIC und Kontoinhaber aller seiner Mandate (auch in den eingefrorenen Posten der Läufe: „IBAN anonymisiert“); IP und Browser der elektronischen Zustimmung; die hochgeladene Nachweisdatei, soweit sie sich entfernen lässt; die Freitexte der Historie (Sperr-, Stundungs-, Erlass- und Storno-Begründungen, Begründung einer individuellen Untergrenze, Vertretungsnotizen, Freitext einer Rücklastschrift). Als Debitor der Forderungen steht „Anonymisiertes Mitglied“.
- **Bleibt:** Beträge, Daten, Zustände, Rückgabecodes und Mandatsreferenzen: das, was die Buchhaltung belegt. Die Akte ist schreibgeschützt.
- Die Zahlen der Buchhaltung ändern sich nicht (Buchungen bleiben).

### 12.9 Bekannte Lücke: Satztexte im Verlauf

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne in der Akte des anonymisierten Mitglieds „Mandat verwalten“ und klappe unter „Frühere Mandate“ den „Verlauf“ auf.
2. Suche Einträge mit Mailadressen oder Namen im Satztext (z. B. „Aktivierungslink versendet an …“, „Kontoinhaber korrigiert …“).

**Erwartet:**
- Strukturierte Freitexte sind geschwärzt. Fragmente, die beim Entstehen eines Ereignisses schon im Satztext standen, **können stehen geblieben sein** (bekannte, bewusst nicht adressierte Lücke).
- Gibt es solche Fragmente nicht (Hans hatte kein Mandat mit Einmal-Link), notiere „nicht prüfbar“ und setze den Schritt auf „übersprungen“.

**Beachte:** Notiere als **Frage**, ob du die Lücke für den Einsatz im Verein akzeptabel findest.

## Phase 13 – Rollen & Rechte

**Ziel:** Du prüfst, wer was darf: Buchhalter (alice), Revisor (bob), Konten ohne Rolle, und dass „Mein Beitrag“ von der Kontoverknüpfung abhängt, nicht von einer Rolle.

**Nutzer:** alice (Fenster B), bob (Fenster C), ein weiteres Konto ohne Rolle, jane.

**Vorbedingung:** Rollen laut Seeder: alice Buchhalter, bob Revisor.

### 13.1 Buchhalter: alles Operative

**Rolle:** alice (Buchhalter)

**Tun:**
1. Lade Fenster B neu. Sieh dir die Reiter und die Kopfzeile an.
2. Öffne **Beiträge** und klicke „Mitglieder“, „Einzug“, „Beitragsgruppen“.
3. Öffne eine Akte, ein Mandat, den Bankabgleich und das Klemmbrett.

**Erwartet:**
- Reiter „Übersicht“, „Buchungen“, „Konten“, „Berichte“, „Beiträge“; der Knopf „Buchung“ und das Klemmbrett sind sichtbar.
- Alle drei Unterreiter stehen offen. Buchhalter dürfen Mitglieder, Mandate (aktivieren, sperren, entsperren, widerrufen), Zuweisungen, Einzelforderungen, Erledigungsvermerke, Stundung, Erlass, Freigabe, Einreichung, Verwerfen, Terminverschiebung, Bankabgleich (urteilen und verbuchen) und Anonymisierung bedienen.
- Das Was-ist-neu-Fenster (1.1) erscheint auch für Buchhalter, falls noch nicht gesehen.

### 13.2 Buchhalter: Fristen nur zur Ansicht, Einzugstage bedienbar

**Rolle:** alice (Buchhalter)

**Tun:**
1. Öffne **Beiträge** → **Einzug** → „Terminplan“.
2. Lies am Ende der Karte die Zeile mit den Fristen und suche dort ein Eingabefeld oder einen Link zum Ändern. Prüfe die Zeilen mit den Einzugstagen.
3. Öffne **Beitragsgruppen**: Dort gibt es keinen Terminplan mehr (er steht nur im Einzug).

**Erwartet:**
- Die Fristen stehen nur als Anzeige da („Vorwarnfenster: … Tage · Vorabinfo-Vorlauf: … Tage · Freigabe-Vorlauf: … Tage vor dem Einzug.“, die Werte stehen also da), ohne Eingabefeld und ohne den Link „Fristen in den Einstellungen ändern“, den nur Verwalter sehen. Geändert werden sie nur in den Einstellungen, die dem Buchhalter nicht offenstehen (13.3).
- Die Einzugstage je Turnus, die Eingabe „Periodenindex“ und „+ Überschreibung“ sind bedienbar.
- In der Karte unter „Beitragsgruppen“ gilt dasselbe.

### 13.3 Buchhalter: keine Einstellungen des Moduls

**Rolle:** alice (Buchhalter)

**Tun:**
1. Öffne die persönlichen Einstellungen von alice in Nextcloud und suche einen Abschnitt „Vereinsbuchhaltung“.
2. Öffne `http://stable34.local/index.php/settings/admin/vereinsbuchhaltung`.

**Erwartet:**
- Es gibt keinen Abschnitt „Vereinsbuchhaltung“ (Einstellungen, Rechtstext, Berechtigungen und Reset sind Verwaltern vorbehalten; die Seite zeigt sich nur Verwaltern bzw. Nextcloud-Administratoren).
- Die Admin-Adresse zeigt eine Meldung, dass der Zugriff nicht erlaubt ist (Wortlaut der Nextcloud-Seite nicht belegt).

### 13.4 Revisor: Einzug lesend, kein Klemmbrett

**Rolle:** bob (Revisor)

**Tun:**
1. Lade Fenster C neu. Lies das Willkommensfenster und klicke „Verstanden“.
2. Sieh dir die Reiter an und öffne **Beiträge**.
3. Öffne im Einzug „Zeitstrahl & Läufe“, einen Lauf mit „Details“ und einen Termin-Marker.
4. Öffne „Forderungen“, bei einer Forderung „Details“, und „Je Mitglied“.
5. Suche nach dem Klemmbrett und dem Knopf „Buchung“.
6. Öffne das Hilfe-Fenster und den Link „Was ist neu in Version 0.35.0?“.

**Erwartet:**
- Das Fenster „Willkommen als Kassenprüfer/in“ mit den Hinweisen zu „Buchungen“, „Konten“, „Berichte“ und „Ändern ist mit dieser Rolle nicht möglich.“ erscheint einmal je Browserprofil.
- Im Reiter „Beiträge“ gibt es **nur** „Einzug“ (keine „Mitglieder“, keine „Beitragsgruppen“). Kein Klemmbrett, kein Knopf „Buchung“.
- Der Zeitstrahl und die Vorschau-Karte sind lesbar, aber ohne Knopf „Freigeben & Datei erzeugen“; es gibt keinen Knopf „Terminplan“. Im Lauf-Detail stehen die IBANs **maskiert**, es gibt weder Knöpfe noch Download-Link.
- „Forderungen“: lesbar, ohne „+ Einzelforderung“; das Detail hat keine Aktionsknöpfe und keinen Sprung in die Akte; „Je Mitglied“ zeigt kein „Akte öffnen“. Der Störfall-Abschnitt ist sichtbar.
- Das Was-ist-neu-Fenster zeigt den 0.35.0-Eintrag für Revisoren nicht an (er gilt für Verwalter und Buchhalter). Notiere als Frage, ob ein Revisor ihn trotzdem sehen sollte, weil er jetzt den Einzug lesen kann.

### 13.5 Revisor: Bankabgleich lesend, kein Rückgabecode

**Rolle:** bob (Revisor)

**Tun:**
1. Öffne **Einzug** → „Bankabgleich“.
2. Suche in den Karten nach dem Rückgabecode (`AM04`, `AC04`), dem Bankfreitext und nach Knöpfen („Bestätigen“, „Zuordnen“, „Ablehnen“, „Verbuchen…“).
3. Öffne **Buchungen** → „Offene Posten“ und die Zeile einer Forderung.

**Erwartet:**
- Der Hinweis „Sie sehen den Bankabgleich nur lesend. Urteile und Verbuchen sind ab der Rolle Buchhalter möglich.“ steht oben.
- Der Grund steht in Klartext („Mangels Kontodeckung zurückgegeben“), die Begründung der Vorschläge ebenfalls („Gleiche End-to-End-ID“). Der **Rückgabecode** („Rückgabecode (nur für die Buchhaltung sichtbar): …“) und der Freitext der Bank fehlen; es gibt keine Aktionsknöpfe.
- In „Offene Posten“ trägt die Forderung „Im Einzug ansehen“ statt „Im Einzug bearbeiten“.

### 13.6 Konto ohne Rolle und ohne Verknüpfung

**Rolle:** ein weiteres Nextcloud-Konto (z. B. `user2`, falls vorhanden)

**Tun:**
1. Melde dich mit einem Konto an, das weder eine App-Rolle hat noch mit einem Mitglied verknüpft ist (existiert keines, überspringe den Schritt oder lege eines an; Beschriftung der Nextcloud-Verwaltung nicht belegt).
2. Öffne die App.

**Erwartet:**
- Es gibt keine Reiter. Das Panel „Kein Zugriff“ steht da: „Sie haben keine Berechtigung für die Vereinsbuchhaltung. Bitte wenden Sie sich an eine Verwalterin oder einen Verwalter.“
- Die Schnittstelle liefert keine Daten (nur für Verwalter nachprüfbar, nicht Teil dieses Tests).

### 13.7 „Mein Beitrag“ folgt Schalter und Verknüpfung, nicht der Rolle

**Rolle:** admin (Verwalter), jane

**Tun:**
1. Schalte in den Einstellungen („Grundeinstellungen“) den Schalter „Self-Service „Mein Beitrag“ für verknüpfte Nextcloud-Konten freischalten“ **aus**.
2. Lade in Fenster D (jane) neu.
3. Schalte den Schalter wieder **ein** und lade in Fenster D erneut.

**Erwartet:**
- Bei ausgeschaltetem Schalter sieht jane **„Kein Zugriff“** und keinen Reiter „Mein Beitrag“ (obwohl ihr Konto verknüpft ist).
- Danach ist „Mein Beitrag“ wieder da. Es braucht in keinem Fall eine Buchhaltungsrolle.

## Phase 14 – Übersetzungen

**Ziel:** Du prüfst Du/Sie/Englisch: „Mein Beitrag“ bei jane (Du), john (Sie) und user1 (Englisch), die Mails und den Einzug-Reiter auf Englisch.

**Nutzer:** jane, john, user1, admin, Mailhog.

**Vorbedingung:** Sprachen laut Seeder: jane „Deutsch“ (informell, `de`), john „Deutsch (förmlich)“ (`de_DE`), user1 „English“. Du hast die Hinweise unter „Bekannte Grenzen“ gelesen: Zahlen und Daten sind in englischen Mails deutsch formatiert, und Mandatstext sowie Zustimmungsseite sind nur deutsch.

### 14.1 „Mein Beitrag“ in Du-Form (jane)

**Rolle:** jane (Mitglied, `de`)

**Tun:**
1. Öffne „Mein Beitrag“. Suche mit ⌘F nach `Sie`, `Ihr` und `Ihre`.
2. Öffne den Dialog „Mandat widerrufen“ (nur ansehen, „Abbrechen“) und „Bankverbindung ändern“.
3. Lies die Karte „Meine Daten“.

**Erwartet:**
- Die Texte sind in Du-Form: z. B. „… über alle zu deiner Mitgliedschaft gespeicherten Daten …“, im Widerrufsdialog „… brauchst du danach ein neues Mandat.“, beim IBAN-Dialog „… du kannst die IBAN bis zur Einreichung des nächsten Einzugs jederzeit ändern.“
- Es bleibt kein „Sie/Ihr/Ihre“ in den Sätzen für Mitglieder stehen (Namen und Fremdtexte ausgenommen). Die Knöpfe sind unverändert („Mandat widerrufen“, „Bankverbindung ändern“).

### 14.2 „Mein Beitrag“ in Sie-Form (john)

**Rolle:** john (Mitglied, `de_DE`)

**Tun:**
1. Öffne „Mein Beitrag“. Suche mit ⌘F nach `du`, `dein` und `deine`.
2. Öffne den Dialog „Mandat widerrufen“ (nur ansehen).

**Erwartet:**
- Sie-Form: „… zu Ihrer Mitgliedschaft gespeicherten Daten …“, „… brauchen Sie danach ein neues Mandat.“
- Keine Du-Formen im Bereich.

### 14.3 Mails: Du gegenüber Sie

**Rolle:** Du (Mailhog)

**Tun:**
1. Vergleiche in Mailhog die Vorabinfo an Jana (Du) mit der an Markus Fuchs (Sie) und die Mails zum Einmal-Link (Theo, Sie) und zum Widerruf (Jana, Du).

**Erwartet:**
- Konten mit informellem Deutsch bekommen „Hallo Jana Hoffmann,“ und „… von deinem Konto …“, ohne Konto bzw. mit förmlichem Deutsch „Guten Tag … “, „… Ihrem Konto …“.
- Die Sprache folgt dem **Empfänger**, nicht dem auslösenden Verwalter. Der Betreff „Bevorstehender Lastschrifteinzug von …“ ist gleich.
- Die Betreffzeile „Bitte bestätige dein SEPA-Lastschriftmandat“ gegenüber „Bitte bestätigen Sie Ihr SEPA-Lastschriftmandat“ zeigt den Unterschied bei einer Einmal-Link-Mail.

### 14.4 „Mein Beitrag“ auf Englisch (user1)

**Rolle:** user1 (Lena Bergmann, Mitglied, English)

**Tun:**
1. Lade Fenster F neu und öffne den Reiter „My contribution“.
2. Lies alle Überschriften und Knöpfe. Suche mit ⌘F nach deutschen Wörtern wie „Mandat“, „Beitrag“, „Rücklastschrift“.

**Erwartet:**
- Reiter „My contribution“. Überschriften „My details“, „My contribution“, „My SEPA direct debit mandate“, „Returned direct debits“, „My contribution confirmation“ und „My data“.
- Leerzustand „No returned direct debit so far.“, Link „Open data overview“; ohne Mandat steht der Hinweis und der Knopf zum Erteilen (Wortlaut nicht belegt); mit Mandat wären es „Change bank details“, „Change account holder“ und „Revoke mandate“.
- Lenas Daten: Gruppe „Ermäßigt“ (22,50 € je Quartal), „Untergrenze“ 5,00 €, ohne Anschrift (keine Adresszeile). Ihre interne Notiz („Studentin, keine Anschrift hinterlegt …“) erscheint nirgends.
- Keine deutschen Reste in den Karten.

### 14.5 Einmal-Link-Mail auf Englisch und Zustimmungsseite auf Deutsch

**Rolle:** admin (Verwalter), danach user1

**Tun:**
1. Lege in Fenster A bei **Lena Bergmann** über „Mandat verwalten“ → „Mandat anlegen“ ein elektronisches Mandat an (IBAN `DE44 5001 0517 5407 3249 31`; Lena braucht eine E-Mail-Adresse, sonst trage eine ein).
2. Öffne in Mailhog die Mail an Lena.
3. Öffne den Link in einem privaten Fenster.

**Erwartet:**
- Die Mail ist **englisch**, obwohl du als Verwalter auf Deutsch arbeitest (Sprache des Empfängers): Betreff „Please confirm your SEPA direct debit mandate“, Text „… asks you to confirm the SEPA direct debit mandate with the reference … electronically.“ und „With a click on the button you see the complete mandate text and can consent.“
- Die Zustimmungsseite ist **deutsch** und in Sie-Form (bekannte Grenze: Mandatstext und Seite nur Deutsch).

**Beachte:** Wenn du zustimmst, hat Lena danach ein aktives Mandat (aus 14.4 kennst du den Zustand ohne Mandat).

### 14.6 Einzug-Reiter auf Englisch (user1 mit Revisor-Rolle)

**Rolle:** admin (Verwalter), danach user1

**Tun:**
1. Öffne in den Einstellungen „Berechtigungen“ → „Neue Berechtigung“: „Typ“ „Nutzer“, Nutzer `user1`, „Rolle“ „Revisor (nur lesen)“, „Hinzufügen“.
2. Lade in Fenster F (user1) neu. Öffne „Contributions“ und den Unterreiter „Collection“.
3. Klicke durch „Timeline & runs“, „Claims“ und „Bank reconciliation“.
4. Entferne die Berechtigung wieder (Papierkorb-Knopf „Berechtigung entfernen“).

**Erwartet:**
- Neben „My contribution“ erscheint der Reiter „Contributions“ mit dem einen Unterreiter „Collection“ (Revisor). Die Segmente heißen „Timeline & runs“, „Claims“, „Bank reconciliation“. Der Zeitstrahl trägt „Contribution year“, „Claims“ erklärt „Claims against members with state, exceptions and dunning status.“, der Bankabgleich beginnt mit „The bank statement is the truth:“.
- Keine deutschen Reste (z. B. „Zeitstrahl“, „Forderungen“, „Läufe“, „Beitragsjahr“, „Rücklastschrift“, „Störfälle“, „Zahlungsaufforderung“, „Mahnstand“).
- Nach dem Entfernen verschwindet „Contributions“ wieder.

### 14.7 Zahlen und Daten in englischen Mails

**Rolle:** Du (Mailhog)

**Tun:**
1. Öffne in Mailhog die **englische Zahlungsaufforderung an Lena Bergmann** aus 7.6 (Betreff auf Englisch, Wortlaut nicht belegt) und, falls vorhanden, die Einmal-Link-Mail aus 14.5.
2. Prüfe das Format von Beträgen und Datumsangaben.

**Erwartet:**
- Der Text ist englisch (Sprache von Lenas Konto), **Beträge und Daten sind deutsch formatiert** (z. B. „22,50 €“, Daten im Format TT.MM.JJJJ). Das ist eine bekannte Grenze, kein Fehler.
- Lenas Zahlungsaufforderung trägt wie die anderen einen GiroCode je Position (9.4).

## Phase 15 – Mobil & Barrierefreiheit (Stichproben)

**Ziel:** Du prüfst stichprobenartig, dass die Oberfläche auf dem Smartphone ohne seitliches Scrollen funktioniert und dass Tastatur, Fokus, Namen und das dunkle Design brauchbar sind.

**Nutzer:** admin (Verwalter), alice (Buchhalter).

**Vorbedingung:** Daten aus den Phasen 3 bis 9 sind vorhanden.

### 15.1 Schmalen Viewport einstellen

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne in Chrome die Entwicklerwerkzeuge (⌥⌘I) und den Gerätemodus (⇧⌘M). Wähle 375 × 812.
2. Lade die App neu (⌘R).
3. Öffne die App in dieser Breite.

**Erwartet:**
- Ab einer Breite von höchstens 640 px wechselt die App in die mobile Ansicht: Die Reiter stehen als Leiste **unten** („Hauptnavigation“), dort auch „Beiträge“, und oben rechts steht ein Plus-Knopf („Neue Buchung anlegen“). Der Geldbestand-Chip entfällt.
- Die Seite hat kein seitliches Scrollen. Prüfe das in der Entwicklerkonsole mit `document.documentElement.scrollWidth - window.innerWidth` (Ergebnis höchstens 1).

### 15.2 Mitglieder als Karten

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne unten **Beiträge** und die Mitgliederliste.
2. Öffne bei einer Karte das Menü „Aktionen“.
3. Prüfe das seitliche Scrollen wie in 15.1.

**Erwartet:**
- Die Liste besteht aus Karten (statt Tabelle) mit Name, Bankverbindung, Betrag, Frequenz und, falls vorhanden, „fällig {Datum}“.
- Der Name auf der Karte öffnet die Akte; das Menü „Aktionen“ der Karte bietet dieselben Einträge wie das Zeilenmenü in der Tabelle (3.1): „Mitglied bearbeiten“, „Mandat verwalten“, „Beitrag ändern“ und „Beitragsgruppe wechseln“ (ohne Zuweisung: „Beitrag zuweisen“). Nichts ragt über den Rand.

### 15.3 Einzug auf dem Handy

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne **Beiträge** → „Einzug“.
2. Tippe auf einen Termin der Liste, auf den Reiter „Forderungen“ und eine Karte mit „Details anzeigen“. Öffne danach „Bankabgleich“.
3. Prüfe jeweils das seitliche Scrollen.

**Erwartet:**
- Die Einzugstermine stehen als **Liste** mit der Marke „HEUTE“ (kein Zeitstrahl).
- Die Vorschau-Karte passt in die Breite. Forderungen und Bankumsätze stehen als Karten; „Details anzeigen“ klappt die Aktionen („Als bezahlt markieren“ usw.) auf.
- Es gibt kein seitliches Scrollen im Abschnitt.

### 15.4 Dialoge und Klemmbrett auf dem Handy

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne das Klemmbrett (Aufgaben).
2. Öffne „Mitglied aufnehmen“ (oben „Mitglied“ in der Mitgliederliste) und ein Verbuchen-Fenster im Bankabgleich (falls vorhanden) oder „Einzug freigeben“.
3. Scrolle in den Dialogen bis zu den Knöpfen.

**Erwartet:**
- Das Klemmbrett-Fenster passt in die Breite (linker und rechter Rand sichtbar) und die Aufgaben springen mit „Zur Akte“ in die Akte.
- Die Dialoge passen auf den Schirm, die Knöpfe sind erreichbar, nichts läuft über den Rand.

### 15.5 Tastatur: Escape, Fokus, Reihenfolge

**Rolle:** admin (Verwalter), Desktop-Breite

**Tun:**
1. Stelle den Gerätemodus wieder aus.
2. Öffne das Klemmbrett mit der Tastatur (Tab bis zum Knopf, Enter) und drücke **Esc**.
3. Öffne „Mitglied aufnehmen“, tippe sofort los, drücke Tab mehrfach und danach **Esc**.
4. Öffne eine Akte und darin „Mandat widerrufen“; schließe beide mit **Esc**.
5. Beobachte, wohin der Fokus nach dem Schließen springt.

**Erwartet:**
- **Esc** schließt das Klemmbrett und jeden Dialog; es schließt auch das Klemmbrett, solange es noch lädt.
- Beim Öffnen von „Mitglied aufnehmen“ steht der Cursor sofort im Feld „Vorname“; Tab bleibt im Dialog.
- Nach dem Schließen kehrt der Fokus zum Auslöser zurück (kein Verlust an den Seitenanfang).
- Die Tab-Reihenfolge folgt der Lesereihenfolge (Kopfzeile, Reiter, Inhalt).

**Beachte:** In einem Browser-Tab im Hintergrund laufen die Übergänge der Dialoge nicht. Führe die Fokus-Prüfungen (Esc, Fokusrückkehr) daher im vorderen Tab aus.

### 15.6 Fokus sichtbar, Namen und Zoom

**Rolle:** admin (Verwalter)

**Tun:**
1. Tabbe durch Kopfzeile und Reiter. Beobachte den Fokusrahmen.
2. Prüfe die Namen der Symbol-Knöpfe, indem du mit der Maus darüber fährst (Tooltip) oder VoiceOver (⌘F5) einschaltest: „Hilfe“, Klemmbrett, „Forderungen aktualisieren“, „Bankabgleich aktualisieren“.
3. Zoome den Browser auf 200 % (⌘+).

**Erwartet:**
- Der Fokus ist an jeder Stelle klar sichtbar.
- Der Klemmbrett-Knopf heißt „Aufgaben – N mit Handlungsbedarf“; Symbol-Knöpfe haben aussagekräftige Namen. Erfolgs- und Fehlermeldungen (Toasts) werden von VoiceOver angesagt („Erfolg: …“).
- Bei 200 % ist nichts abgeschnitten oder überlappt, die Inhalte bleiben bedienbar.

### 15.7 Dunkles Design

**Rolle:** admin (Verwalter)

**Tun:**
1. Schalte in den persönlichen Einstellungen von Nextcloud das dunkle Erscheinungsbild ein (Beschriftung nicht belegt).
2. Sieh dir diese Stellen an: Mandat-Statusmarken („Entwurf“, „Aktiv“, „Ausgesetzt“), Hinweiskästen (Warnung, Info, Fehler), den Zeitstrahl samt Marken, die Marken in „Forderungen“ (Zustände, „Handlungsbedarf“, „Hinweis“) und im Bankabgleich, die Fehlermeldungen in den Einstellungen.
3. Schalte das helle Erscheinungsbild wieder ein.

**Erwartet:**
- Alle Texte, Marken und Hinweise sind lesbar (ausreichender Kontrast, keine hellen Schriften auf hellen Flächen und umgekehrt); Warn- und Fehlerfarben bleiben unterscheidbar.
- Der Zeitstrahl, die Karten und die Tabellen folgen dem dunklen Design.

## Phase 16 – Randfälle & Fehlerfälle

**Ziel:** Du provozierst die Fälle, die im Alltag auftreten: leere Zustände, Doppelklicks, falsche Eingaben, Verbindungsprobleme, zwei Personen zugleich und die Zurück-Taste.

**Nutzer:** admin (Verwalter), alice (zweites Fenster).

**Vorbedingung:** Daten aus den vorigen Phasen. Für 16.5 brauchst du Chrome mit Entwicklerwerkzeugen.

### 16.1 Leere Zustände

**Rolle:** admin (Verwalter)

**Tun:**
1. Tippe in der Mitgliedersuche `zzz`.
2. Wähle in „Forderungen“ Filter, die nichts treffen (z. B. „Fällig von“ in ferner Zukunft).
3. Öffne den Bankabgleich, wenn nichts mehr wartet, und beide Darstellungen.
4. Blättere im Zeitstrahl mit „Nächstes Beitragsjahr“ einige Jahre nach vorn und beobachte, ob er sich füllt.
5. Öffne das Klemmbrett, wenn es keine Aufgaben gibt (sonst „übersprungen“).

**Erwartet:**
- Mitglieder: „Kein Eintrag passt zur Suche.“ (ohne jedes Mitglied: „Noch kein Mitglied aufgenommen.“ mit dem Hinweis, mit „＋ Mitglied“ oder per CSV zu beginnen).
- Forderungen: „Keine Forderung passt zu den gewählten Filtern.“ (ohne jede Forderung: „Es gibt noch keine Forderung an ein Mitglied.“).
- Bankabgleich: „Es warten keine Bankumsätze mit SEPA-Bezug auf ein Urteil. Einzugsgutschriften und Rücklastschriften erscheinen hier nach dem Kontoauszugs-Import (Buchungen → Import).“ bzw. „Es gibt keine Gutschrift, die zu einer offenen Forderung passt. …“.
- Zeitstrahl: Solange offene (laufende) Zuweisungen existieren, bleibt er in **jedem** Beitragsjahr gefüllt, weil der Terminplan jedes Jahr Termine erzeugt. Der Leerhinweis „In diesem Beitragsjahr gibt es noch keine Einzugstermine. Sie entstehen aus dem Terminplan, sobald Mitglieder einer Beitragsgruppe zugewiesen sind, oder durch manuelle Forderungen mit eigenem Termin.“ erscheint nur, wenn keine Zuweisung läuft. Das ist nur auf einer Instanz ohne Zuweisungen prüfbar; sonst setze diesen Punkt auf „übersprungen“.
- Klemmbrett: „Nichts zu tun“ mit „Es gibt gerade keine Aufgaben und Hinweise.“ und kein Badge.

### 16.2 Doppelklick

**Rolle:** admin (Verwalter)

**Tun:**
1. Lege über „+ Einzelforderung“ (Einzug → Forderungen) eine Forderung für Eva Schröder an (`Test E Doppelklick`, 5 €, Termin in 80 Tagen) und klicke „Anlegen“ schnell **zweimal**.
2. Öffne „Mitglied“ und lege `Doppel Klick` mit „Aufnehmen“ an, wieder mit zwei schnellen Klicks.
3. Prüfe die Listen.

**Erwartet:**
- Es entsteht jeweils **genau ein** Datensatz (der Dialog schließt sich nach dem ersten Klick bzw. der Knopf ist während des Speicherns gesperrt). Zwei Zeilen wären eine Abweichung.
- Das Freigeben (7.13) und das Verbuchen (8.6) hast du bereits mit Blick auf Doppelklick geprüft; notiere dort Beobachtungen.

**Beachte:** Die Sperre gegen Mehrfach-Absenden gilt auch für „Beitragsgruppe“ (Dialog „Neue Beitragsgruppe“, „Anlegen“) und „Zuweisung“ (Dialog „+ Zuweisung“, „Anlegen“). Probiere auch dort zwei schnelle Klicks; erwartet wird jeweils genau ein Datensatz.

### 16.3 Ungültige Eingaben und die IBAN-Prüfung

**Rolle:** admin (Verwalter)

**Tun:**
1. Lege bei einem Mitglied ohne lebendes Mandat (z. B. Dora Testentwurf nach 4.12, sonst Tobias Brandt oder Musikhaus Schmidt GmbH) mit „Mandat anlegen“ ein Mandat mit der IBAN `ABC123` an.
2. Lege bei demselben Mitglied ein Mandat mit der IBAN `DE12 5001 0517 0648 4898 99` an (Länderkürzel und Länge stimmen, die Prüfziffer ist falsch).
3. Trage in einer Akte bei „E-Mail“ `abc` ein und speichere.
4. Lege eine Einzelforderung mit Betrag `0` an und eine zweite ohne „Bezeichnung“.
5. Wiederhole aus den Einstellungen: Gläubiger-ID `XYZ` (2.2), Freigabe-Vorlauf `0` (2.5), Mahnabstand `0` (2.9).

**Erwartet:**
- Schritt 1: eine verständliche Meldung in Klartext: „Das sieht nicht nach einer IBAN aus: ABC123 (erwartet wird z. B. DE12 5001 0517 0648 4898 90).“ Nichts wird gespeichert.
- Schritt 2: Die IBAN wird **angenommen** (kein Fehler), weil die App nur das Format prüft, keine Prüfsumme (bewusste Entscheidung).
- Für die übrigen Eingaben Meldungen in Klartext, für die Einstellungen die Texte aus Phase 2. Kein Rohtext wie „Request failed with status code 400“, „Network Error“ oder „[object Object]“. Es wird nichts gespeichert.

**Beachte:** Notiere als **Frage:** Wünschst du dir eine Prüfsummenkontrolle der IBAN (Modulo-97), oder genügt die Formatprüfung? Eine falsche Prüfziffer fällt sonst erst bei der Bank auf (Rückgabe).

### 16.4 Einstellungen: mehrere Fehler und der Server-Check

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne in den Einstellungen die Karte „Rücklastschriften und Mahnwesen“ und klappe die beiden Auswahllisten „Konto für Rücklastschriftgebühren (Aufwand)“ und „Standard-Erlöskonto für Beitragsforderungen (Ertrag)“ auf. Suche einen Eintrag mit „(nicht geeignet)“. Gibt es einen, wähle ihn und speichere.

**Erwartet:**
- Die Listen bieten nur geeignete Konten an: bei den Gebühren Aufwandskonten, beim Erlöskonto Ertragskonten (keine Geldkonten). Ein Eintrag „(nicht geeignet)“ fehlt daher meist; dann setze den Schritt auf „übersprungen“.
- Gibt es einen solchen Eintrag und speicherst du ihn, lehnt die App das Konto mit einer verständlichen Meldung ab (Aufwandskonto bzw. Ertragskonto erwartet, Geldkonto nicht erlaubt); nichts wird gespeichert.
- Der Server lehnt ein falsches Konto auch über die Schnittstelle mit klarer Meldung ab, z. B. „Das Konto für Rücklastschriftgebühren muss ein Aufwandskonto sein. Konto 4000 Mitgliedsbeiträge ist ein Ertragskonto.“

### 16.5 Offline und Serverfehler ohne Rohtext

**Rolle:** admin (Verwalter), Chrome-Entwicklerwerkzeuge

**Tun:**
1. Öffne die Entwicklerwerkzeuge → „Netzwerk“ und stelle die Drosselung auf „Offline“ (Beschriftung nicht belegt).
2. Klicke im Klemmbrett das Aktualisieren-Symbol („Aufgaben aktualisieren“), im Einzug „Forderungen aktualisieren“ und öffne einen Reiter, den du noch nicht geladen hast.
3. Stelle „Online“ wieder ein und klicke „Erneut versuchen“.
4. Sperre eine Adresse (Entwicklerwerkzeuge → Netzwerk → Anfrage blockieren, Beschriftung nicht belegt), z. B. `*/api/claims/overview*`, und lade **Einzug** → „Forderungen“ neu.
5. Sperre als jane `*/api/self/returned-debits*` und öffne „Mein Beitrag“.
6. Hebe die Sperren auf.

**Erwartet:**
- Meldungen in Klartext statt Rohtext: Klemmbrett: „Aufgaben konnten nicht geladen werden“ mit „Bitte prüfen Sie die Verbindung und versuchen Sie es noch einmal.“ und „Erneut versuchen“, oder bei einer Aktualisierung „Die Liste konnte nicht aktualisiert werden. Angezeigt wird der zuletzt geladene Stand.“
- Schritt 4: „Die Forderungen konnten nicht geladen werden.“ mit „Erneut versuchen“. Der Einzug meldet bei Ausfall „Der Einzug konnte nicht geladen werden.“ und der Bankabgleich „Der Bankabgleich konnte nicht geladen werden.“
- Schritt 5: „Rücklastschriften konnten nicht geladen werden.“ und **kein** falscher Leerhinweis „Bisher keine Rücklastschrift.“
- Nach „Erneut versuchen“ (wieder online) lädt die Ansicht. Nirgends erscheint „Request failed“, „Network Error“ oder „[object Object]“.

### 16.6 Zwei Personen zugleich

**Rolle:** admin (Fenster A) und alice (Fenster B)

**Tun:**
1. Öffne in beiden Fenstern **Beiträge** → „Mitglieder“ und bei Theo Testlink „Mandat verwalten“.
2. Sperre das Mandat in Fenster A („Sperren“ mit Notiz).
3. Klicke in Fenster B (veralteter Stand, ohne Neuladen) ebenfalls „Sperren“ und bestätige mit einer Notiz.
4. Wechsle in Fenster B zum Reiter „Einzug“ und zurück.
5. Entsperre das Mandat wieder.

**Erwartet:**
- Das zweite „Sperren“ scheitert mit einer **verständlichen** Meldung (Wortlaut nicht belegt), nicht mit Rohtext; das Mandat bleibt gesperrt, und es entsteht kein zweiter Eintrag.
- Der Einzug lädt beim Zurückkehren ins Fenster neu (der Stand wird aktualisiert, wenn du aus einem anderen Fenster zurückkommst).

### 16.7 Zurück-Taste und Deep-Links

**Rolle:** admin (Verwalter), Revisor bob

**Tun:**
1. Öffne **Beiträge** → „Einzug“, klicke dann „Mitglieder“ und drücke die **Zurück-Taste** des Browsers. Drücke die Vorwärts-Taste.
2. Öffne „Mitglied aufnehmen“ und drücke Zurück. Beobachte, ob der Dialog bleibt und was sich dahinter ändert.
3. Öffne die Adressen `/index.php/apps/vereinsbuchhaltung/contributions/batch`, `/contributions/groups` und `/self` direkt.
4. Öffne als bob `/index.php/apps/vereinsbuchhaltung/contributions`.

**Erwartet:**
- Zurück und Vorwärts wechseln den Reiter bzw. Unterreiter nachvollziehbar; die Adresse in der Zeile folgt dem Reiter. Ob Zurück einen **offenen Dialog** schließt, ist **nicht spezifiziert**: Notiere dein Verhalten und deine Erwartung als Frage. Beobachtet (Schritt 2): „Zurück“ wechselt bei geöffnetem Dialog den Reiter dahinter, lässt den Dialog aber offen; ob das so bleiben soll, ist noch nicht entschieden.
- Die Direktadressen öffnen den richtigen Reiter („Einzug“, „Beitragsgruppen“); `/self` öffnet „Mein Beitrag“ nur, wenn das Konto verknüpft und der Schalter an ist.
- Ein Revisor landet bei `/contributions` auf „Einzug“ statt auf einer leeren Fläche.

## Phase 17 – Zurücksetzen („Alle Daten löschen“)

**Ziel:** Du prüfst, dass „Alle Daten löschen“ jetzt auch den Einzug mitnimmt, die Stammdaten stehen lässt und dass der Tageslauf danach Forderungen nachholt.

**Nutzer:** admin (Verwalter), Terminal, Mailhog.

**Vorbedingung:** Nur ausführen, wenn du das bewusst willst. ⚠ Der Reset löscht die **gesamte Buchhaltung dieser Instanz** (14 Konten, 1 Buchung plus deine Testbuchungen, 4 Belege). Der Sicherungsdump der App-Tabellen vor dem Upgrade liegt beim Koordinator. Wenn du Kontoplan und Buchungen behalten willst, setze die ganze Phase auf „übersprungen“.

### 17.1 Entscheidung und Ausgangslage festhalten

**Rolle:** admin (Verwalter)

**Tun:**
1. Entscheide, ob du den Reset ausführst (sonst alle Schritte der Phase auf „übersprungen“).
2. Notiere dir: Anzahl der Mitglieder ____, der Mandate ____, der Läufe ____, der Forderungen ____, der Buchungen ____.
3. Sophie Krügers Mandat trägt noch die Sperre aus 8.8 (du hast sie in 9.2 nicht aufgehoben); merke dir das.

**Erwartet:**
- Du weißt, was nach dem Reset bleiben soll (Mitglieder, Mandate samt Verlauf, Beitragsgruppen, Zuweisungen, Rechtstext, Einstellungen) und was nicht (Konten, Buchungen, Importe, Belege, offene Posten, Läufe, Posten, Rücklastschriften, Mahnstufen).

### 17.2 „Alle Daten löschen“ ausführen

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne in den Einstellungen den Abschnitt „Daten“ und lies die Karte „Alle Daten löschen“.
2. Klicke den roten Knopf „Alle Daten löschen“ und lies den Dialog. Klicke zunächst „Abbrechen“ (Beschriftung des Knopfs nicht belegt), dann wiederhole und bestätige.

**Erwartet:**
- Die Karte erklärt: „Löscht unwiderruflich alle Konten, Buchungen und Importe sowie den Einzug (Lastschrift-Läufe, Einzugsposten, Rücklastschriften, Mahnstufen). Mitglieder, Mandate, Beitragsgruppen und Zuweisungen bleiben bestehen.“
- Der Dialog „Alle Daten löschen“ fragt: „Wirklich ALLE Konten, Buchungen und Importe sowie den Einzug (Läufe, Rücklastschriften, Mahnstufen) löschen? Mitglieder, Mandate, Beitragsgruppen und Zuweisungen bleiben bestehen.“ Abbrechen ändert nichts.
- Nach dem Bestätigen: „Alle Daten gelöscht.“

**Beachte:** ⚠ Unumkehrbar. Nur Verwalter dürfen das.

### 17.3 Der Einzug nach dem Reset

**Rolle:** admin (Verwalter)

**Tun:**
1. Lade die App neu und öffne **Beiträge** → „Einzug“. Öffne „Zeitstrahl & Läufe“, „Forderungen“ und „Bankabgleich“. Achte auf Fehlermeldungen, auch in der Entwicklerkonsole.
2. Öffne „Mitglieder“ und „Beitragsgruppen“.

**Erwartet:**
- „Läufe“: „Noch kein Lauf. Ein Lauf entsteht erst, wenn ein Einzugstermin freigegeben wird – davor gibt es nur die Vorschau.“ „Forderungen“: „Es gibt noch keine Forderung an ein Mitglied.“ „Bankabgleich“: leer, ohne Fehler.
- Keine Server- oder Skriptfehler; der Einzug-Reiter zeigt keine Verweise auf gelöschte Forderungen.
- Mitglieder, Mandate, Beitragsgruppen und Zuweisungen sind unverändert da.

### 17.4 Was am Mandat bleibt

**Rolle:** admin (Verwalter)

**Tun:**
1. Öffne Sophie Krügers Mandat und das Klemmbrett.
2. Öffne ein Mandat, das schon eingereicht war (z. B. Jana), und lies „Zuletzt eingereicht für“.
3. Öffne Theos Mandat und die „Änderungen der Bankverbindung“.

**Erwartet:**
- Die **Sperre bleibt** (keine Auto-Entsperrung), verliert aber den Verweis auf die gelöschte Rücklastschrift: Die Aufgabe nennt jetzt einen neutralen Text („Sophie Krüger: Mandat nach einer Rücklastschrift gesperrt, Klärung offen. Solange wird nichts eingezogen.“).
- „Zuletzt eingereicht für“ **bleibt** stehen (die 36-Monats-Frist läuft von der tatsächlich letzten Lastschrift an).
- Eine Änderung der Bankverbindung, die ein Einzug schon gemeldet hatte, steht wieder als „offen – noch nicht an die Bank gemeldet“ und läuft beim nächsten Einzug noch einmal mit.

### 17.5 Der Tageslauf holt Forderungen nach (bekannte Entscheidung)

**Rolle:** admin (Verwalter), Terminal, Mailhog

**Tun:**
1. Leere Mailhog.
2. Starte den Tageslauf mit dem Mahnwesen:

```bash
/Users/FlorianLudwig/Projekte/nextcloud-apps/vereinsbuchhaltung/tests/dev/run-jobs.sh tageslauf mahnwesen
```

3. Öffne **Einzug** → „Forderungen“ und lies die Fälligkeiten. Sieh in Mailhog nach.

**Erwartet:**
- Der Tageslauf legt Forderungen **neu an**, auch für längst abgerechnete Zeiträume (die Zuweisungen laufen ab ihrem „Gültig ab“ weiter, im Seeder ab 01.10.2026 bzw. 01.09.2026), und verschickt dazu Vorabinfos bzw. Zahlungsaufforderungen. Das ist die dokumentierte offene Entscheidung (Spec §15.3), kein Fehler.
- Der Lauf bricht dabei nicht ab; es entstehen keine Fehlermeldungen in der Oberfläche.

**Beachte:** Notiere, ob dich dieses Verhalten stört; ein Stichtag für die Nachforderung wäre eine neue Einstellung. Handbuch 12.1 empfiehlt, Zuweisungen mindestens einen Tag vor dem Zurücksetzen zu beenden und danach mit heutigem Beginn neu anzulegen.

### 17.6 XML-Kopien und Ausgangszustand wiederherstellen

**Rolle:** admin (Verwalter), Terminal

**Tun:**
1. Falls die XML-Ablage (2.7) an war: Sieh in der Dateien-App im Ordner `SEPA-Einreichungen` nach.
2. Stelle den Zustand wieder her. Der Seeder braucht die Konten 1200, 4000 und 5400; der Reset hat die Konten gelöscht, du musst sie vorher neu anlegen (Reiter „Konten“ → Kontenrahmen anlegen, 1200 mit IBAN) oder den Sicherungsdump des Koordinators einspielen. Danach:

```bash
# Modul-Daten zurück auf den Szenario-Stand (nach einem Test ohne Bankimport)
/Users/FlorianLudwig/Projekte/nextcloud-apps/vereinsbuchhaltung/tests/dev/in-container.sh seed-beitraege.php --wipe

# wenn du die Bankdatei importiert hattest: zusätzlich deren Umsätze und Buchungen entfernen
/Users/FlorianLudwig/Projekte/nextcloud-apps/vereinsbuchhaltung/tests/dev/in-container.sh seed-beitraege.php --wipe --wipe-bank

# Mailhog leeren
curl -X DELETE -H "Host: mail.local" http://127.0.0.1/api/v1/messages

# Instanz wie vor dem ersten Seeden (Modul-Daten, Bankimport, Einstellungen, Rollen, Sprachen, Anonymisierungs-Buchung)
/Users/FlorianLudwig/Projekte/nextcloud-apps/vereinsbuchhaltung/tests/dev/in-container.sh seed-beitraege.php --purge
```

**Erwartet:**
- Die XML-Dateien liegen **noch** im Ordner (die App löscht dort nichts, weil sie keine Datei-ID kennt und die Ablage der Compliance dient). Du kannst sie von Hand entfernen.
- Der Seeder baut die Modul-Daten neu auf; Mailhog ist leer, der Zustand „Vorabinfo noch nicht versendet“ ist wieder da. Konten, Buchungen und Belege kommen nur über den Sicherungsdump zurück (Koordinator).

**Beachte:** Der echte Cron der Umgebung kann die Tagesjobs nach dem Neuanlegen von selbst starten (frühestens 24 Stunden nach dem letzten Lauf, 0.6).

## Phase 18 – Abschluss

**Ziel:** Du räumst die Einstellungen auf, hältst deinen Gesamteindruck fest, vergibst Prioritäten und exportierst dein Feedback.

**Nutzer:** admin (Verwalter), du.

<!-- matrix -->

### 18.1 Einstellungen aufräumen

**Rolle:** admin (Verwalter)

**Tun:**
1. Prüfe und stelle zurück: XML-Ablage aus (2.7), Vorwarnfenster 21 und Vorabinfo-Vorlauf 14 (7.18), Mahnabstand 14 (9.12), Beitragsjahr beginnt im Januar (5.9), Freigabe-Vorlauf 5 (2.5), Self-Service-Schalter an (13.7), Berechtigung für `user1` entfernt (14.6), Standard-Einzugstag „monatlich“ wie vor 7.4.
2. Lösche Mailhog-Nachrichten, wenn du willst.

**Erwartet:**
- Die Einstellungen stehen wie zu Beginn. Der Rahmentext des Mandatstexts bleibt als Fassung im Verlauf stehen (nicht löschbar); du kannst ihn leeren und als neue Fassung speichern.

### 18.2 Gesamteindruck festhalten

**Rolle:** Du

**Tun:**
1. Schreibe in die Notiz dieses Schritts in drei bis fünf Sätzen: Wie fühlt sich die Journey an? Wo hast du gezögert oder wärst ohne Protokoll nicht weitergekommen? Welche Texte waren unklar? Würdest du dem Geld- und Datenfluss für einen echten Einzug vertrauen?
2. Setze das Ergebnis (OK, Abweichung oder Frage).

**Erwartet:**
- Eine kurze, ehrliche Zusammenfassung. Sie erscheint im Export unter den Notizen.

### 18.3 Prioritäten vergeben

**Rolle:** Du

**Tun:**
1. Wähle oben den Filter „Abweichungen“ und danach „Fragen“.
2. Vergib bei jedem Eintrag eine Priorität: **Blocker** (verhindert den Einsatz, falsches Geld, Datenverlust, Sicherheit), **Wichtig** (stört im Alltag, falscher oder irreführender Text) oder **Schön zu haben**.
3. Sieh dir die Matrix oben in dieser Phase an.

**Erwartet:**
- Die Matrix zeigt je Priorität die Zahl der Abweichungen und Fragen und verlinkt die Schritte.
- Alle Abweichungen tragen eine Priorität (sonst stehen sie unter „ohne Priorität“).

### 18.4 Feedback exportieren

**Rolle:** Du

**Tun:**
1. Klicke oben auf „Feedback exportieren“.
2. Prüfe den angezeigten Markdown-Text und kopiere ihn bei Bedarf aus dem Textfeld.
3. Füge ihn in den Chat ein.

**Erwartet:**
- Der Text enthält Fortschritt und Zählung, alle Abweichungen, Fragen und Notizen mit Schrittnummer, Titel, Priorität, Notiz und der erwarteten Beobachtung.
- Er liegt in der Zwischenablage (oder lässt sich aus dem Textfeld markieren und kopieren).
