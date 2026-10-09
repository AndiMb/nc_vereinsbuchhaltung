# Testdaten für den manuellen Komplett-Test des Beiträge/SEPA-Moduls

Werkzeuge für eine **Entwicklungsinstanz**: ein Seeder legt ein festes Szenario
an (16 Mitglieder, Mandate, Beitragsgruppen, ein eingereichter Lauf, ein
anstehender Lauf), ein Generator baut dazu den passenden Kontoauszug der Bank,
ein Skript startet die Tagesjobs von Hand. Das Testprotokoll wird gegen dieses
Szenario geschrieben (`docs/testprotokoll/`).

> Nie gegen eine Instanz mit echten Vereinsdaten ausführen: der Seeder setzt
> Einstellungen, vergibt Rollen und ändert Nutzersprachen (alles mit
> `--purge` rückgängig zu machen, siehe unten).

## Voraussetzungen

- Docker-Entwicklungsumgebung ([nextcloud-docker-dev]) mit Nextcloud 34 im Dienst
  `stable34`, Mailhog unter <http://mail.local/>. Der Ordner `nextcloud-apps` ist
  im Container unter `/var/www/html/apps-shared/` eingehängt (nur lesend).
- Vereinsbuchhaltung ab 0.35.0 aktiviert, Kontenrahmen vorhanden: Konto **1200**
  (Bank, mit IBAN), **4000** (Mitgliedsbeiträge), **5400** (Bankgebühren).
- Nextcloud-Nutzer `admin`, `alice`, `bob`, `jane`, `john`, `user1` (fehlt einer,
  überspringt der Seeder den Teil und sagt es).
- Zeitlich ist das Szenario auf **Anfang Oktober 2026** zugeschnitten (heute =
  2026-10-05): Oktoberlauf mit Fälligkeit 01.10., nächster Lauf 01.11.

## Aufrufe

Alle Aufrufe von der Host-Shell; `in-container.sh` führt das PHP-Skript als
`www-data` im Container aus (Pfad wird aus dem Ort des Skripts abgeleitet,
Compose-Verzeichnis `NC_DOCKER_DIR`, Standard `~/Projekte/nextcloud-docker-dev`).

```bash
# Szenario anlegen (bricht ab, wenn schon Modul-Daten existieren)
tests/dev/in-container.sh seed-beitraege.php

# Modul-Daten löschen und neu anlegen – Konten, Buchungen, Belege, Geschäftsjahre bleiben
tests/dev/in-container.sh seed-beitraege.php --wipe

# zusätzlich die aus der Testdatei importierten Bankumsätze samt daraus entstandener Buchungen löschen
tests/dev/in-container.sh seed-beitraege.php --wipe --wipe-bank

# nur ansehen: Stand und Aufgabenliste (wie im Flyout), nichts ändern
tests/dev/in-container.sh seed-beitraege.php --check

# Bankdatei neu erzeugen (docs/testprotokoll/testdaten/bank-oktober-2026.camt053.xml)
tests/dev/make-camt053.sh

# Tagesjobs von Hand: alle, oder einzeln (tageslauf, mahnwesen, verfall, austritt-zuweisungen, austritt-mandate)
tests/dev/run-jobs.sh
tests/dev/run-jobs.sh tageslauf mahnwesen
tests/dev/run-jobs.sh --list
```

Gleichwertig ohne die Hilfsskripte (so läuft es auch von jedem anderen Rechner,
Pfad im Container anpassen):

```bash
cd ~/Projekte/nextcloud-docker-dev
docker compose exec -T -u www-data stable34 php \
  /var/www/html/apps-shared/vereinsbuchhaltung/tests/dev/seed-beitraege.php --wipe
```

Ausgaben des Containers beginnen mit einer Zeile „Profiler output …" – sie gehört
nicht zum Ergebnis (`in-container.sh` filtert sie, bei einer Umleitung in eine
Datei **muss** man sie filtern, sonst ist das XML kaputt).

## Was der Seeder anlegt

**Einstellungen:** Gläubiger-ID `DE98ZZZ09999999999`, einziehendes Konto 1200
(die IBAN steht am Konto), Rücklastschriftgebühren-Konto 5400, Standard-Erlöskonto
4000, Self-Service an, **Vorabinfo-Vorlauf 30 Tage** und Vorwarnfenster 35 Tage
(damit heute die Vorabinfo zum 01.11. fällig ist; Standard wären 14/21).
**Rollen:** admin = Verwalter (Nextcloud-Admin), alice = Buchhalter, bob = Revisor.
**Sprachen:** jane `de` (Du), john `de_DE` (Sie), user1 `en`.

**Beitragsgruppen** (Monatsbeitrag ist das Atom, Spec §3.3): Vollmitglied
15,00 €/Monat (Untergrenze 12,00; Turnus 1/3/6/12), Ermäßigt 7,50 € (ab 5,00; 1/3/12),
Jugend 5,00 € (ab 3,00; 1/12), Fördermitglied 5,00 € (ab 4,00; nur 12) = **60,00 €/Jahr** –
„50,00 €/Jahr" lässt sich in ganzen Cent je Monat nicht ausdrücken.

| Nr. | Mitglied | Gruppe, Turnus | Zahlung | Mandat | Forderungen / Besonderheit |
|---|---|---|---|---|---|
| 1001 | Jana Hoffmann (`jane`) | Voll, monatlich | Lastschrift | **aktiv**, elektronisch, `M-1` | Sept. bezahlt, Okt. im Lauf, Nov. offen |
| 1002 | Jonas Richter (`john`) | Voll, vierteljährlich | Lastschrift | **Entwurf** (elektronisch), `M-2` | keine Forderung (Störfall); im Self-Service „Jetzt bestätigen" |
| 1003 | Lena Bergmann (`user1`) | Ermäßigt, vierteljährlich | Überweisung | keines | Q4 22,50 € offen; ohne Anschrift |
| 1004 | Markus Fuchs | Voll, monatlich | Lastschrift | aktiv, Papier, `M-3` | Okt. im Lauf (**AM04** in der Bankdatei), Nov. offen |
| 1005 | Sophie Krüger | Voll, **jährlich** | Lastschrift | aktiv, Papier, `M-4` | Okt.–Dez. anteilig 45,00 € im Lauf (**AC04** in der Bankdatei) |
| 1006 | Tobias Brandt | Voll, monatlich | Überweisung | **widerrufen** (erloschen), `M-5` | Okt. + Nov. offen (Überweiser) |
| 1007 | Nadine Schuster | – | – | aktiv, Papier, `M-6`, **zuletzt vor 34 Monaten vorgelegt** (01.12.2023) | Verfall-Vorwarnung (01.12.2026); Einzelforderung 30,00 € zum 01.11. – ihr Einreichen setzt die Frist neu |
| 1008 | Mara Lindner | Jugend, monatlich | Lastschrift | aktiv, Papier, `M-7`, **Kontoinhaberin Petra Lindner** | Okt. im Lauf, Nov. offen |
| 1009 | Musikhaus Schmidt GmbH | Förder, jährlich | Überweisung | keines | Organisation; Jahresbeitrag 2026 bezahlt |
| 1010 | Hans Becker | Voll, jährlich bis 2013 | – | beendet (Austritt), `M-8` | **Austritt 31.12.2013**, ohne E-Mail, 2013 bezahlt; Anonymisierung siehe Grenzen |
| 1011 | Anna Koch | Voll, monatlich | Lastschrift | aktiv, Papier, `M-9` | **individuelle Untergrenze 10,00 €**; Okt. im Lauf, Nov. offen |
| 1012 | Bernd Neumann | Voll, monatlich | Lastschrift | aktiv, Papier, `M-10` | Okt. im Lauf, Nov. offen |
| 1013 | Clara Vogel | Ermäßigt, monatlich | Lastschrift | aktiv, elektronisch, `M-11` | Okt. im Lauf, Nov. offen |
| 1014 | David Wolf | Jugend, monatlich | Lastschrift | aktiv, Papier, `M-12` | Okt. im Lauf, Nov. offen |
| 1015 | Eva Schröder | Voll, monatlich | Lastschrift | aktiv, Papier, `M-13` | Okt. im Lauf, Nov. offen |
| 1016 | Felix Maier | Ermäßigt, monatlich | Lastschrift | aktiv, Papier, `M-14` | **Austritt 31.12.2026** (künftig); Okt. im Lauf, Nov. offen |

Mandatsreferenzen vergibt die App in der Reihenfolge der Anlage (`M-1` …); alle
Mitglieder außer Hans haben eine E-Mail-Adresse `…@example.org` (landet in Mailhog).

**Läufe:**

- **Oktoberlauf** (Fälligkeit 2026-10-01): **eingereicht**, 10 Posten, 145,00 €. Vorabinfo
  war am 17.09. (vermerkt), Freigabe/Einreichung am 26.09. End-to-End-IDs und Kennung
  sind deterministisch (`E2E-20260926-091000-5EED<Mitgliedsnr.>`), die Bankdatei passt
  deshalb nach jedem erneuten Seeden.
- **Novemberlauf** (Fälligkeit 2026-11-01): **nur Vorschau**, 10 Forderungen, 130,00 €
  (9 Beiträge + Nadines Einzelforderung), noch ohne Vorabinfo, noch nicht freigegeben.
  Heute ist die Vorabinfo fällig (27 Tage vor dem Termin, Vorlauf 30): bis der
  Tageslauf läuft, steht je Mitglied „Vorabinfo nicht rechtzeitig verschickt" als
  Handlungsbedarf in der Aufgabenliste – das ist der echte Zustand „Cron noch nicht gelaufen".

## Ablauf eines Tests

1. **Bankdatei importieren**: Buchungen → Kontoauszug importieren →
   `docs/testprotokoll/testdaten/bank-oktober-2026.camt053.xml`. Sie enthält die
   Sammelgutschrift (8 Posten, 85,00 €), die Rückgaben Fuchs (**AM04**, 15,00 € + 3,50 €
   Gebühr) und Krüger (**AC04**, 45,00 € + 4,00 €), Lena Bergmanns Überweisung (22,50 €),
   eine Spende (50,00 €) und ein Kontoführungsentgelt (7,90 €).
2. **Beiträge → Einzug → Bankabgleich**: Zeilen beurteilen, verbuchen. Krügers Mandat
   wird dabei gesperrt (Aufgabe „Mandat prüfen"), beide Rückgaben lösen eine
   Zahlungsaufforderung aus (Mailhog), Lenas Zahlung erscheint als Zahlungseingang.
3. **Tageslauf** (`tests/dev/run-jobs.sh tageslauf mahnwesen`): zehn Vorabinfo-Mails
   zum 01.11. (jana in Du-Form, die übrigen in Sie-Form) und zwei Zahlungsaufforderungen
   an Überweiser (Lena auf Englisch, Tobias) = **12 Mails**.
4. **Novemberlauf**: Freigeben & Datei erzeugen, XML herunterladen, „Datei ist bei der
   Bank eingereicht".
5. **Mitglieder importieren**: `mitglieder-import.csv` (6 neue Mitglieder, Prüflauf
   zeigt alles als „entstünde") und `mitglieder-import-fehler.csv` (absichtlich
   fehlerhafte Zeilen). Der Beginn der Zuweisung darf nicht in der Vergangenheit
   liegen: die Startdaten (01.12.2026, 01.01.2027) vor dem Import prüfen.

## Zurück in den Ausgangszustand

| Ziel | Aufruf |
|---|---|
| Szenario frisch, nach einem Test **ohne** Bankimport | `seed-beitraege.php --wipe` |
| Szenario frisch, nach dem Import der Bankdatei | `seed-beitraege.php --wipe --wipe-bank` |
| Mailhog leeren | `curl -X DELETE -H "Host: mail.local" http://127.0.0.1/api/v1/messages` |
| Instanz wie vor dem ersten Seeden | `seed-beitraege.php --purge` |

`--wipe` löscht nur Modul-Daten: Mitglieder, Mandate (mit Ereignissen, Einmal-Links,
Amendments), Beitragsgruppen, Zuweisungen, Läufe samt Posten, Rücklastschriften,
Mahnstufen, Aufgaben, Forderungen (Zeilen der offenen Posten mit Mitglied), und die
Rechtstext-Version, falls der Seeder sie angelegt hat. **Nicht** angefasst werden
Konten, Buchungen, Belege, Geschäftsjahre, freie offene Posten ohne Mitglied und das
Änderungsprotokoll. Bankumsätze aus der Testdatei bleiben mit `--wipe` liegen und
lassen sich dann nicht erneut importieren – dafür `--wipe-bank`.

`--purge` stellt zusätzlich die Einstellungen (Gläubiger-ID, Konten, Self-Service,
Vorlaufzeiten), die Rollen von alice/bob und die Sprachen von jane/john/user1 auf den
Stand vor dem ersten Seeden zurück; die Ausgangswerte merkt sich der Seeder im
App-Wert `dev_seed_state`.

## Grenzen und Fallen

- **Tageslauf und Cron:** `run-jobs.sh` setzt „zuletzt gelaufen" auf jetzt (`--force-execute`).
  Der Cron der Umgebung (alle fünf Minuten) führt einen Tagesjob frühestens 24 Stunden
  danach von selbst aus – dann gehen Vorabinfo-Mails ohne Zutun raus. Wer nach einem
  Tageslauf wieder den Zustand „Vorabinfo noch nicht versendet" braucht, setzt mit `--wipe`
  neu auf (die Forderungen entstehen ohne Vermerk neu).
- **Hans Becker (1010) ist ohne Zusatz kein Anonymisierungs-Kandidat:** die Frist läuft
  ab der letzten *verbuchten* Zahlung (Buchung mit Journal), ohne jede Buchung läuft
  keine. `--with-anonymization-booking` legt eine Buchung vom 10.02.2014 an – das
  materialisiert die Geschäftsjahre **2014–2025** in der Buchhaltung (12 leere Jahre).
  `--purge` entfernt Buchung und Jahre wieder.
- Die Forderungen zum 01.11. legt der Seeder selbst an: der Tageslauf würde sie wegen der
  Nachzügler-Regel (Vorabinfo-Frist schon angebrochen) auf den 01.12. schieben.
- Die App prüft IBANs nur formal (keine Prüfsumme) und der Prüflauf des CSV-Imports
  meldet weder ungültige IBANs noch Startdaten in der Vergangenheit; beides scheitert
  erst beim Übernehmen. Die Test-IBANs des Seeders haben eine gültige Prüfsumme.
- Das Szenario bleibt auf 2026-10-05 zugeschnitten. An einem anderen Tag laufen Fristen
  und Aufgaben anders; der Seeder warnt, wenn heute nicht zwischen 02. und 31.10.2026 liegt.
- Der Seeder schreibt als `admin`, Ereignisse und Änderungsprotokoll nennen ihn; das
  Änderungsprotokoll wird von `--wipe` nicht aufgeräumt.

## Aufbau

| Datei | Zweck |
|---|---|
| `seed-beitraege.php`, `lib/BeitraegeSeeder.php` | Seeder (Szenario, `--wipe`, `--purge`, `--check`) |
| `make-camt053.php`, `make-camt053.sh`, `lib/CamtGenerator.php` | Bankdatei aus dem Lauf in der Datenbank |
| `lib/DevScenario.php` | das Szenario als Daten (Mitglieder, Gruppen, Rückgaben, Bankbewegungen) |
| `lib/IbanFactory.php`, `lib/CamtBuilder.php` | IBAN-Prüfsumme, camt.053-Aufbau (reine Logik, per PHPUnit geprüft) |
| `run-jobs.sh`, `in-container.sh` | Tagesjobs von Hand; PHP-Skripte im Container starten |

Tests: `tests/unit/DevIbanFactoryTest.php`, `DevCamtBuilderTest.php` (gegen den echten
`Camt053Parser`), `DevScenarioTest.php`.

[nextcloud-docker-dev]: https://github.com/juliusknorr/nextcloud-docker-dev
