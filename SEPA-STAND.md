# Arbeitsstand SEPA/Mitgliedsbeiträge (Branch `feature/sepa-lastschrift`)

> Diese Datei ist eine Fortsetzungsnotiz für die Zusammenarbeit mit Claude
> Code auf einem anderen Rechner, auf dem die lokalen Session-Notizen dieses
> Rechners nicht vorliegen. Sie beschreibt den Stand zum Zeitpunkt des
> Commits, der sie einführt – nicht zwangsläufig den aktuellen. Nach dem
> nächsten Merge nach `main` oder wenn sie veraltet ist, darf sie gelöscht
> werden. Sie enthält bewusst **keine** Zugangsdaten oder Serveradressen.

## Wo das steht

Branch `feature/sepa-lastschrift`, noch **nicht getaggt, nicht gemerged**.
Wichtigste Commits in der Reihenfolge, in der sie entstanden sind:

1. `84afb8d` … `9a8a9d3` … `cf85073` – die fünf Phasen der ursprünglichen
   SEPA-Implementierung (Mandate, Beiträge, XML-Export, Vorankündigung,
   Rücklastschrift-Erkennung).
2. `ae1dc25` „Version 0.20.0" – 21 Befunde aus einem Code-Review behoben
   (Details unten unter „Der Review").
3. `721b01e` „Version 0.21.0" – fünf funktionale Lücken geschlossen, die
   der Review offengelassen hatte, plus eine neue gemeinsame
   Mitgliederansicht mit CSV-Massenimport (Details unten).
4. Ein loser Commit danach (siehe `git log` – falls diese Datei noch in
   seinem Diff steht, ist er der aktuellste): behebt, dass ein
   Mandatswechsel (Bankverbindung ändern) offene Posten und Beiträge am
   alten, widerrufenen Mandat hängen ließ.

## Tragende Entscheidungen des Moduls (wichtig, bevor man etwas ändert)

- **Keine eigene Mitgliedertabelle.** Ein Zahler ist entweder ein
  Nextcloud-Konto (`member_uid`) oder ein Freitextname (`member_label`) –
  exklusiv, geprüft in `MemberReferenceValidator` (`lib/Service/`). Gilt für
  Mandate *und* Beiträge gleichermaßen. Das war ein bewusster Architekturschnitt,
  keine Notlösung – siehe Diskussion unten unter „Offene Grundsatzfrage".
- **Die IBAN steht am Mandat** (`vbh_sepa_mandates`), nicht an einer Person.
  Ein Mitglied ohne Mandat hat schlicht keine Bankverbindung in der App.
- **Ein fälliger Beitrag ist ein offener Posten**, keine eigene
  Forderungsverwaltung. `vbh_open_items.mandate_id` ist die einzige
  Ausnahme vom Freitext-Debitor und zugleich die Auswahlmenge für den Export.
- **Mandate werden widerrufen, nie gelöscht**, solange etwas darauf
  verweist – sonst bricht der XML-Download bereits erzeugter Einzüge.
- **Zeilenstatus eines Sammeleinzugs**: `pending` → `settled` → ggf.
  `returned`. `SepaBatchItem::OPEN_STATUSES` (= `pending`+`settled`) ist der
  Schlüssel für die Rücklastschrift-Erkennung: eine Rückgabe trifft
  regelmäßig erst *nach* dem Verbuchen als „ausgeführt" ein.
- **Vorlaufzeit an genau einer Stelle**: `SepaNotificationService::LEAD_DAYS`
  (14 Tage) steuert Ankündigungsfenster *und* Terminvorschlag.
- **`last_used_date` am Mandat zählt nur Einzüge, die Bestand haben**
  (zurückgebuchte nicht) – daraus ergibt sich FRST/RCUR beim nächsten Einzug.
- **`SepaText`** (Zeichensatz/Feldlängen), **`SepaReference`** (Erzeugung
  *und* Wiedererkennung von Mandats-/End-to-End-Referenzen) und
  **`SepaCreditor`** (Wertobjekt statt Entität, damit `PainXmlBuilder`
  ohne Nextcloud testbar ist) sind die drei Klassen, die man bei jeder
  Formatänderung anfassen muss.

## Der Review (→ 0.20.0)

Ein vollständiger Code-Review plus Test auf einer Testinstanz fand 21
Befunde, alle behoben. Die drei schwersten:

- Keine Versionsanhebung in `info.xml` ⇒ Migrationen wären bei bestehenden
  Installationen nie gelaufen.
- Die Vorankündigung verglich `execution_date` exakt mit „heute+14 Tagen"
  statt mit einem Zeitfenster ⇒ ein Einzug mit kürzerem Vorlauf bekam nie
  eine Ankündigung.
- Ein gelöschtes Mandat riss den XML-Download bereits erzeugter Einzüge ab
  ⇒ Mandate werden seither widerrufen statt gelöscht, sobald etwas darauf
  verweist.

## Was 0.21.0 zusätzlich gebracht hat

Nach dem Review blieben fünf funktionale Lücken offen, die kein Bug im
engen Sinn waren, aber die Nutzbarkeit einschränkten:

1. **Kein Abschluss des Einzugs.** Der Ablauf endete beim heruntergeladenen
   XML; jeder offene Posten musste einzeln von Hand geschlossen werden.
   Neu: Status `settled` an `vbh_sepa_batch_items` und `settled_at` an
   `vbh_sepa_batches`, dazu `SepaBatchService::settleBatch()`.
2. **Vorankündigung erreichte nur Zahler mit Nextcloud-Konto und dort
   hinterlegter Adresse** – in den meisten Vereinen also fast niemanden.
   Neu: `email`-Feld direkt am Mandat (`vbh_sepa_mandates.email`), als
   Vorrang vor der Konto-Adresse (siehe
   `SepaNotificationService::resolveRecipient()`).
3. **`PainXmlBuilder` war die formatkritischste, aber einzige ungetestete
   Klasse** – sie arbeitete auf der `SepaBatch`-Entität, die von OCP erbt,
   und der Test-Bootstrap lädt kein OCP. Neu: `SepaCreditor` als reines
   Wertobjekt; `tests/unit/PainXmlBuilderTest.php` validiert das erzeugte
   XML gegen das amtliche Schema der Deutschen Kreditwirtschaft
   (`tests/schema/pain.008.001.02.xsd`, dort auch die Bezugsquelle
   dokumentiert). `tests/` ist vom Release-Tarball ausgeschlossen.
4. **Ein zurückgegebener Ersteinzug galt weiter als „benutzt"** ⇒ der
   nächste Versuch lief als RCUR statt erneut FRST. Behoben in
   `SepaBatchItemMapper::findLastExecutionDateByMandate()`
   (zurückgebuchte Zeilen zählen nicht mehr) und
   `SepaReturnDetectionService::rewindMandateUsage()`.
5. **Kein sichtbarer Beitragsrückstand.** Ein rückwirkend angelegter
   Monatsbeitrag brauchte einen Tageslauf je fehlender Periode, ohne dass
   irgendwo stand, dass noch etwas aussteht. Neu:
   `BillingPeriod::dueCount()`, `MembershipFeeService::catchUp()`,
   Anzeige und Nachholen-Knopf in der Mitgliederliste.

Dazu, weil der Nutzer beim Test auf UI-Ebene nachgefragt hat, ob die
Oberfläche für einen Verein mit **dreistelliger Mitgliederzahl** überhaupt
brauchbar ist: **Nein, war sie vorher nicht.** Mandat und Beitrag standen
in zwei getrennten Formularen mit doppelter Auswahl desselben Zahlers.
Neu:

- `src/components/SettingsMembers.vue` – eine gemeinsame Ansicht (ersetzt
  die früheren `SettingsSepaMandates.vue`/`SettingsMembershipFees.vue`),
  mit Suche, Filter „nur Auffälligkeiten", Beitragsaufkommen aufs Jahr
  hochgerechnet, kombiniertem Anlegen von Mandat+Beitrag in einem Schritt.
- `src/components/SettingsSepaBasics.vue` – nur noch Gläubiger-ID und
  einziehendes Konto (was von den alten Mandats-Einstellungen übrig blieb).
- **CSV-Massenimport**, zweistufig (erst `preview()`, dann `import()`):
  `lib/Service/Sepa/MemberCsvParser.php` (reines PHP, testbar ohne
  Nextcloud – `tests/unit/MemberCsvParserTest.php`) und
  `lib/Service/MemberImportService.php`. Spaltennamen deutsch/englisch,
  Reihenfolge egal, unbekannte Spalten werden übergangen.

## Die noch nicht gepushte Korrektur: Bankwechsel

Im Gespräch danach kam die berechtigte Frage auf, ob die App überhaupt
weiß, von welchem Konto bei welchem Mitglied abgebucht wird (ja, über das
Mandat), und ob man dafür eine „richtige" Mitgliederverwaltung bräuchte.
Beim Durchdenken fiel ein echter Fehler auf:

**Bisheriger Weg bei einem Bankwechsel** war „altes Mandat widerrufen, neues
anlegen" – zwei für sich richtige Schritte mit einer Lücke dazwischen: noch
offene, nicht eingezogene Beiträge und offene Posten zeigten weiter auf das
**widerrufene** Mandat. `SepaBatchService::previewEligible()` verlangt ein
aktives Mandat und ließ sie deshalb kommentarlos aus jeder künftigen
Einzugsvorschau herausfallen – eine Forderung, die nie wieder eingezogen
wird, ohne dass es irgendwo auffällt.

Behoben mit `SepaMandateService::changeBankAccount()`: widerruft das alte
Mandat, legt ein neues für denselben Zahler an und hängt

- alle Beiträge (aktiv oder pausiert), die auf das alte Mandat zeigten, auf
  das neue um,
- alle noch **offenen** (nicht bereits bezahlten/stornierten) offenen Posten
  ebenfalls um. Abgeschlossene Historie bleibt bewusst unangetastet.

Das neue Mandat beginnt als „unbenutzt" (kein `last_used_date`), der
nächste Einzug darüber läuft also zu Recht wieder als **FRST** – eine neue
Bankverbindung ist SEPA-rechtlich eine neue Einzugsermächtigung.

Dazu: Controller-Endpunkt `SepaMandateController::changeBankAccount()`
(`POST /api/sepa/mandates/{id}/change-account`), Frontend-Dialog
`src/components/BankAccountChangeDialog.vue`, eingehängt über einen Knopf
„Bankverbindung wechseln" in `SettingsMembers.vue`.

**Getestet** (funktional, gegen eine echte Instanz – nicht nur PHPUnit):
neues Mandat übernimmt IBAN/E-Mail, altes wird widerrufen, aktiver Beitrag
und offener Posten hängen um, ein bereits bezahlter Posten bleibt beim
alten Mandat, ein zweiter Wechselversuch auf ein bereits widerrufenes
Mandat wird abgelehnt. PHPUnit/PHPStan/ESLint/Vitest/Build unauffällig.

## Offene Grundsatzfrage: Nextcloud-Konten als Mitgliederverwaltung?

Im Gespräch wurde diskutiert, ob man Nextcloud-Konten als
Mitgliederverwaltung nutzen und ggf. erweitern könnte (z. B. über
`IConfig::setUserValue()` für App-eigene Felder wie Mitgliedsnummer,
Beitragsklasse). Ergebnis der Abwägung, **noch keine Entscheidung
getroffen**:

- Konto-Zahler lösen das Identitätsproblem des Freitextfalls bereits heute
  (uid ist stabil, Umbenennung bricht nichts) – wer ohnehin Konten vergibt,
  sollte das nutzen.
- Für einen Verein, dessen Mitglieder nichts weiter in Nextcloud tun
  sollen, ist ein Konto pro Mitglied ein hoher Preis (Login-Verwaltung,
  gegenseitige Sichtbarkeit in der Kontaktauswahl, Nutzeranlage erfordert
  Server-Admin-Rechte, die die App-Rolle „Verwalter" nicht hat).
  Nicht personenbezogene Zahler (Familien, Firmen, Untergliederungen)
  bleiben davon ohnehin unberührt – dafür bleibt der Freitextfall nötig.
- Der jetzt behobene Bankwechsel-Fehler betraf **beide** Welten gleichermaßen
  und war unabhängig von dieser Grundsatzfrage.
- Falls die Entscheidung für „eigene Mitgliedstabelle" fällt: das wäre eine
  Migration, die bestehende Mandate/Beiträge über den bisherigen
  Namensstring zusammenführen muss – nicht trivial, weil Namen nicht
  garantiert übereinstimmen (siehe genau das Problem, das der
  Bankwechsel-Fix für den Mandatswechsel-Fall bereits löst).

## Was als Nächstes ansteht (nichts davon ist bereits entschieden)

1. **Browser-Check.** Die Oberfläche wurde durchgehend nur im Build/über
   Funktionstests gegen die echte Instanz geprüft, nie im Browser gesehen
   (Browser-Werkzeug war in den bisherigen Sessions nicht verfügbar).
   Besonders SettingsMembers.vue, der CSV-Import-Dialog und
   BankAccountChangeDialog.vue verdienen einen Blick.
2. **Tag, Push nach `main`, App-Store-Auslieferung** – bewusst noch nicht
   passiert; der Nutzer wollte das getrennt entscheiden.
3. **Bewusst nicht gebaut**: Rücklastschriftgebühr/Mahnwesen,
   `pain.008.001.08`/`.09` (manche Banken verlangen es statt `.001.02`),
   Verschlüsselung der IBAN-Spalte, FinTS/HBCI (Stufe 2 eines separaten
   Vorhabens).
4. Diese Datei selbst: nach dem Merge nach `main` oder sobald sie veraltet
   ist, einfach löschen – sie ist eine Momentaufnahme, kein Dauerdokument.

## Praktische Hinweise fürs Weiterarbeiten

- **PHPUnit und PHPStan sind keine Projektabhängigkeit** (bewusst, damit
  das Release-Tarball frei von Fremdcode bleibt – siehe `composer.json`).
  Als `.phar` besorgen: `phar.phpunit.de/phpunit-10.5.phar` direkt, für
  PHPStan die **explizite Versions-URL**
  (`github.com/phpstan/phpstan/releases/download/<version>/phpstan.phar`) –
  die `/latest/download/`-Variante lieferte in dieser Session wiederholt
  eine leere Antwort. PHPStan braucht `--memory-limit=1G`, sonst stürzt
  der Worker bei den standardmäßigen 128M ab.
- **Unit-Tests dürfen keine Entität anfassen** (Entitäten erben von OCP,
  der Test-Bootstrap lädt nur `lib/`). Reine Logik gehört in eigene
  Klassen ohne Nextcloud-Bezug: `BillingPeriod`, `MemberCsvParser`,
  `SepaCreditor`, `SepaText`, `SepaReference` sind Beispiele dafür.
- **`tests/` ist vom Release-Tarball ausgeschlossen** (siehe
  `.github/workflows/release.yml`) – deshalb darf dort auch das
  eingebundene amtliche XSD-Schema liegen, ohne im Auslieferungspaket zu
  landen.
- **`l10n/en.json`**: Plural-Einträge haben die Schlüsselform
  `_Singular_::_Plural_` (mit Unterstrichen), Wert ist ein zweielementiges
  Array. Ein kleines Skript, das `t(...)`/`n(...)` aus `src/` und
  `->t(...)` aus `lib/` einsammelt und gegen die Datei hält, findet Lücken
  zuverlässig – lohnt sich nach jeder Änderung an übersetzten Strings.
- **Bei jeder Migrationsprüfung gegen eine Testinstanz**: eine Sicherung
  *vor* der Migration ist zwingend, sonst fehlt nach einem Restore-Schritt
  die Migration wieder und alles, was neue Spalten braucht, bricht mit
  einer SQL-Fehlermeldung, die durch Nextclouds Fehlerbehandlung leicht
  lautlos verschluckt wird (immer mit `-d display_errors=1` und im
  Zweifel `try { … } catch (\Throwable $e) { var_dump($e); }` um den
  verdächtigen Aufruf herum debuggen, statt der stillen Fehlermeldung zu
  vertrauen).
