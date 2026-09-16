# Spec: Beiträge/SEPA-Modul für nc_vereinsbuchhaltung

> **Hinweis zu den Links in diesem Dokument:** Verweise auf `../tickets/*`, `../research/*`,
> `../prototypes/*`, `../map.md` und `../../CONTEXT.md` zeigen auf den internen
> Recherche-/Entscheidungsprozess (Wayfinder), der außerhalb dieses Repos geführt wurde und
> hier nicht mit veröffentlicht ist. Sie dienen der Nachvollziehbarkeit der jeweiligen
> Begründung, sind in diesem Repo aber nicht auflösbar. Bei Rückfragen zur Begründung eines
> Punktes bitte im zugehörigen Scoping-Issue nachfragen.

**Status:** Review-Durchgang mit Florian abgeschlossen (2026-09-16, HITL-Schritt von T18) — freigegeben zur Übergabe
**Stand:** 2026-09-16 · Quelle: [Wayfinder-Map](../map.md), 24 abgeschlossene Entscheidungs-/Research-/Prototyp-Tickets
**Zielrepo bei Übergabe:** `docs/` in [AndiMb/nc_vereinsbuchhaltung](https://github.com/AndiMb/nc_vereinsbuchhaltung)

Dieses Dokument ist die baubereite Spezifikation für das Beiträge/SEPA-Modul, wie es die
Map-Destination fordert. Es fasst *was gebaut wird* normativ zusammen; das *warum* steht
in den verlinkten Tickets und wird hier nicht wiederholt. Jeder Abschnitt verlinkt seine
Quelltickets — bei Rückfragen zur Begründung eines Punktes dort nachsehen.

**Governance (aus [T26](../tickets/T26-recharting-nc-vereinsbuchhaltung.md)):** Freie Bahn,
kein formales Regelwerk. AndiMb (Maintainer von nc_vereinsbuchhaltung) kennt den vollen
Umfang dieser Spec und hat ihn approved. Übergabe erfolgt als ein Scoping-Issue im
Upstream-Repo, das diese Spec vorstellt, danach Einzelissues via `/to-tickets`.

---

## 1. Zielbild & Prämissen

### 1.1 Destination

Ein vollständiges Beiträge/SEPA-Modul **in** nc_vereinsbuchhaltung (kein eigenes Repo, keine
eigene Store-App — [T26](../tickets/T26-recharting-nc-vereinsbuchhaltung.md)): Mitglieder
(auch ohne Nextcloud-Konto), SEPA-Mandate mit vollem Lifecycle, Beitragsgruppen mit Prorata
und Untergrenzen, ein automatisierter Einzugszyklus mit explizitem Freigabe-Gate,
strukturierte Rücklastschrift-Erkennung mit Mahnwesen, Self-Service für Mitglieder,
Beitragsbescheinigungen und ein DSGVO-konformes Löschkonzept.

### 1.2 Umbau-Härte ([T26](../tickets/T26-recharting-nc-vereinsbuchhaltung.md) Pkt. 4)

- Das bestehende Beiträge/SEPA-Modul (`vbh_sepa_mandates`, `vbh_membership_fees`,
  `vbh_open_items`-Teilmenge, `vbh_sepa_batches`/`_items`) hat **keine Produktivnutzer** —
  seine Tabellen dürfen hart ersetzt werden, keine Datenmigrationspflicht.
- Die **Kern-Buchhaltung hat echte Nutzer** (Journal, Konten, Buchungen, `vbh_open_items` als
  geteilte Tabelle) — dort nur additive, saubere Migrationen; der Import-Hash
  (`RowNormalizer::computeHash()`) darf nicht kippen.

### 1.3 Prämissen (Charting 2026-08-09, revidiert durch T26)

- Zielkontext: Modul in nc_vereinsbuchhaltung, keine Vereins-Hardcodes.
- Mitglieder auch ohne Nextcloud-Konto — eigene Member-Entity, NC-Konto optional verknüpft.
- Einzugszyklus: Vollautomatik per Cron + explizites Freigabe-Gate vor Einreichung.
- Rücklastschrift-Signale ausschließlich aus dem hauseigenen Bankimport; Quelle nur noch
  `import | manual`.
- Verbuchung: „Der Bankauszug ist die Wahrheit" — keine Journal-Buchung ohne bestätigten
  Bank-Umsatz, nichts bucht automatisch.
- Einreichung bei der Bank: manuell (XML-Download/Upload ins Banking-Portal).
- Zahler = Mitglied (Familien-/Sammelmandate out of scope, § 11).
- Mahnwesen nach Rücklastschrift und Beitragsbescheinigungen sind in Scope.

### 1.4 Hausregeln — „das Gefäß gewinnt" ([T26](../tickets/T26-recharting-nc-vereinsbuchhaltung.md) Pkt. 5)

- Gespräch/Spec/UI: Deutsch. Code-Kommentare und Commit-Messages: Deutsch (Upstream-Konvention,
  nicht die ursprünglich geplante Englisch-Regel — siehe Konsistenz-Befund
  [§13.1](#131-enum-sprache-vs-contextmd)).
- **Identifier bleiben englisch** (Klassen, Tabellen, Spalten, Methoden).
- UI-Quellsprache Deutsch, eigener l10n-Weg (`register()` + `/api/l10n/{lang}`, Zielbundle
  `en.json`).
- Enum-Werte nach Bestandsmuster: deutsch wo Fachbegriff (`verwalter`, `ideell`), englisch wo
  Technik (`RCUR`, `pending`) — **offener Klärungsbedarf für die vor T26 geprägten Enum-Werte,
  siehe [§13.1](#131-enum-sprache-vs-contextmd)**.
- Kein eigenes Tooling mitbringen (Psalm/Rector entfallen); PHPStan L5, php-cs-fixer, PHPUnit,
  Playwright-E2E, CI, signierte Releases kommen vollständig vom Gefäß.
- Nach jedem Implementierungsschritt läuft der `simplify`-Skill.
- Alle deutschen Mail-/UI-Quelltexte in **Sie-Form** (Fallback für Mitglieder ohne NC-Konto);
  bei verknüpftem, auf `de` (informell) eingestelltem NC-Konto liefert `l10n/de.json` die
  Du-Variante über `IL10NFactory::getUserLanguage($user)`
  ([T31](../tickets/T31-vorabinfo-mail-wortlaut.md)).

### 1.5 Kontext Bestand (Stand v0.34.1, verifiziert gegen `../vereinsbuchhaltung`)

Das Gefäß bringt seit v0.20.0 ein eigenes, flaches Beiträge/SEPA-Modul mit (~1.900 LOC PHP +
~1.300 LOC Vue): `vbh_sepa_mandates`, `vbh_membership_fees`, Beitrags-Teilmenge von
`vbh_open_items`, `vbh_sepa_batches`/`_items`, XSD-validierter `PainXmlBuilder`
(pain.008.001.02), fixer 14-Tage-Vorankündigungs-Job, Sammelbuchung per `settleBatch()`,
Regex-basierte Rücklastschrift-Erkennung (`SepaReturnDetectionService`, Best-Effort). Es fehlt:
Member-Entity (bewusste Upstream-Position „keine Mitgliederverwaltung",
`NAVIGATION-KONZEPT.md` D1 — durch diese Spec offiziell gekippt), Beitragsgruppen/Prorata/
Untergrenzen, Mandats-Lifecycle, Freigabe-Gate, Mahnwesen, Self-Service, strukturierte
R-Erkennung. Importer (`Camt053Parser`, `CamtCsvParser`, `Mt940Parser`) verwerfen
`EndToEndId`, Mandatsreferenz und Rückgabegrund. Rollenmodell `none`/`revisor`/`buchhalter`/
`verwalter` existiert und wird unverändert übernommen (§3.10). Tooling ist vollständig
(PHPStan L5, ~209 PHPUnit-Tests, 17 Playwright-E2E-Specs, CI, Dependabot, signierte Releases).

Referenz-Fachlogik aus dem stillgelegten sepa_manager-Altbestand (nie in Produktion, jede
Regel gegen [T03](#8-compliance-anhang) verifiziert, kein Code-Übernahme): Prorata-Mathematik
(`DebitScheduleService`) und Vorabinfo-Logik (`NotificationService`) —
[T01](../tickets/T01-rewrite-entscheidung.md).

---

## 2. Domänenmodell

Quellen: [T02](../tickets/T02-domaenenmodell-kern.md) (Basis) mit Änderungen aus
[T07](../tickets/T07-beitragsgruppen-detail.md), [T08](../tickets/T08-mandats-lifecycle.md),
[T09](../tickets/T09-self-service-regeln.md), [T10](../tickets/T10-einzugszyklus-choreografie.md),
[T11](../tickets/T11-ruecklastschrift-fachlogik.md), [T17](../tickets/T17-mitgliederverwaltung-light.md),
[T27](../tickets/T27-kontierungs-detail.md), [T30](../tickets/T30-dsgvo-detailkonzept.md),
[T31](../tickets/T31-vorabinfo-mail-wortlaut.md), [T34](../tickets/T34-mandats-rechtstext.md).
Vollständiges Glossar mit Begründungen: [CONTEXT.md](../../CONTEXT.md).

### 2.1 Gesamtbild

```text
Verein (Creditor) ······················ Singleton-App-Konfiguration

Mitglied (Member) ─┬─ 0..1 Kontoverknüpfung → NC-Konto (unique, entkoppelte Lebensdauer)
  member_type: person|organization
                   ├─ 0..n Mandat (Mandate) · höchstens EIN LEBENDES (status ≠ ended) · IBAN + Kontoinhaber AM Mandat
                   │     ├─ 0..n MandateAmendment (offene Meldepflicht ggü. Bank)
                   │     ├─ 0..n MandateEvent (append-only Historie)
                   │     └─ 1 MandateLegalTextVersion-Referenz (fixiert bei Anzeige)
                   └─ 0..n Zuweisung (Assignment) → Beitragsgruppe (Contribution Group)
                         · zeitraumbehaftet, gewählter Monatsbeitrag + Turnus, payment_method
                         ├─ 0..n AssignmentEvent (append-only Historie)
                         └─ 0..n Forderung (Claim) · Typ: contribution | fee
                                   (Forderung hängt IMMER am Mitglied, optional zusätzlich an einer Zuweisung)
                               ├─ 0..1 Einzugsposten (Debit Item) · Snapshot, höchstens einer je Forderung
                               │        └─ 0..1 Rücklastschrift (Returned Debit)
                               ├─ 0..n Mahnversand (Dunning Notice, je Stufe)
                               └─ 0..1 aktive Stundung (Deferral)

Lastschriftlauf (Debit Batch) ─── 1..n Einzugsposten · EIN Einzugstermin · entsteht erst bei Freigabe
```

### 2.2 Entitäten im Detail

#### Verein (Creditor)

Singleton-Konfiguration, ein Verein pro NC-Instanz (kein Multi-Mandanten-Betrieb). Trägt
Name, IBAN, Gläubiger-Identifikationsnummer.

#### Mitglied (Member) — [T02](../tickets/T02-domaenenmodell-kern.md) Pkt. 4, [T17](../tickets/T17-mitgliederverwaltung-light.md)

| Feld | Typ / Pflicht | Hoheit | Bemerkung |
|---|---|---|---|
| `member_type` | `person`\|`organization`, Pflicht, Default `person` | Verein | Diskriminator; Verband/Untergliederung als Zahler |
| `first_name`, `last_name` | Pflicht bei `person` | Mitglied | |
| `organization_name` | Pflicht bei `organization` | Mitglied | |
| `email` | optional, **kein** Unique-Index | Mitglied | Pflichtvoraussetzung für `payment_method: direct_debit` (§3.5); geteilte Familienadressen sind Normalfall |
| `phone` | optional | Mitglied | |
| `street`, `postal_code`, `city`, `country` | optionaler Block | Mitglied | T16 darf ihn für Bescheinigungen zur Pflicht erklären — tut es nicht (§3.7) |
| `member_number` | optional, unique wenn gesetzt | Verein | **nie automatisch vergeben**, kein Vorschlags-Button |
| `joined_at` | Pflicht, Default heute | Verein | Statusquelle für „aktiv" |
| `left_at` | optional, Zukunft erlaubt | Verein | „der Zeitraum ist der Status" — kein Archivfeld |
| `nc_user_id` | optional, unique wenn gesetzt | Verein | Identität, kein Zugriffsrecht; via NC-User-Picker verknüpft, nie automatisch |
| `internal_note` | optional, Freitext | Verein | **im Self-Service nicht sichtbar** |

Bewusst **nicht** im Katalog: `salutation`/Anrede (Mails adressieren „Guten Tag {Name},"),
`birth_date` (kein Verarbeitungszweck in v1).

Kontoverknüpfung: 1:1-Identität „NC-Konto *ist* dieses Mitglied", kein Zugriffsrecht.
NC-Konto-Löschung leert nur `nc_user_id`; Mitglied + Historie bleiben. Ein
`BeforeUserDeletedEvent`-Listener rettet die NC-Mailadresse nach `member.email`, **nur wenn
dieses Feld leer ist**, und erzeugt eine Aufgabe (§3.1, §7).

#### Mandat (Mandate) — [T08](../tickets/T08-mandats-lifecycle.md)

```text
mandate_reference (unique über die gesamte Tabelle, "<Präfix>-<lfd. Nr.">, frei überschreibbar)
member_id
iban (nullable — DSGVO-Löschkonzept, §3.8)   bic   account_holder (Pflicht, vorbefüllt = Anzeigename)
signature_type: paper | electronic | qes     signed_at
status: draft | active | suspended | ended
activated_at   ended_at   end_reason: revoked | replaced | expired | terminated
suspended_at   suspended_by   suspension_origin: manual | returned_debit   suspension_note   returned_debit_id
last_presented_due_date   → expires_at = COALESCE(last_presented_due_date, signed_at) + 36 Monate
document_file_id (NC File-ID, nullable)
mandate_text_version (FK MandateLegalTextVersion)   consent_at   consent_ip   consent_user_agent   consent_actor
[später: redacted_at — via T30-Anonymisierungs-Vorgang]
UNIQUE (member_id) WHERE status <> 'ended'   ← höchstens EIN LEBENDES Mandat je Mitglied
```

**Zustandsmodell** — vier Zustände, nur `active` einzugsfähig:

| Zustand | Bedeutung | Einzugsfähig |
|---|---|---|
| `draft` | Daten liegen vor, Unterschrift/Freigabe fehlt | nein |
| `active` | einzugsfähig | **ja** |
| `suspended` | vorübergehend ausgesetzt | nein |
| `ended` | terminal, mit `end_reason` | nein |

„Verfallen" ist **kein** eigener Zustand, sondern `end_reason: expired` — verhält sich wie
Widerruf (terminal, kein Reaktivieren).

**Aktivierung** — zwei Wege, unterschiedlich streng ([T17](../tickets/T17-mitgliederverwaltung-light.md)
ändert [T08](../tickets/T08-mandats-lifecycle.md)):

- `paper`: manuelles Gate durch `buchhalter`, verlangt `signed_at` (das Unterschriftsdatum
  *ist* das Gate). Kein Nachweis-Zwang — `show_missing_document_warning` (Default an) zeigt
  nur einen abschaltbaren Dauer-Mangel.
- `electronic` (NC-Konto oder bestätigter E-Mail-Einmal-Link): **aktiviert sich bei Zustimmung
  selbst**, kein manuelles Gate mehr (`auto_activate_electronic_mandates` ist ersatzlos
  entfallen). Beweispaket: `mandate_text_version`, `consent_at`, `consent_ip`,
  `consent_user_agent`, `consent_actor`.
- `qes` ist vorgesehen, in v1 **nicht implementiert**.

**Amendment vs. neues Mandat:**

| Fall | Konsequenz |
|---|---|
| IBAN/BIC ändert sich, Kontoinhaber bleibt | dasselbe Mandat, `MandateAmendment(type: account)`, keine neue Unterschrift |
| Kontoinhaber wechselt | **neues Mandat**, altes `ended`/`replaced` — SEPA ließe Amendment zu, das Mandat ist aber die Erlaubnis *des Kontoinhabers* |
| Namensänderung derselben Person (Heirat, Tippfehler) | stille Korrektur, kein Amendment, kein neues Mandat |

`MandateAmendment`: `mandate_id`, `type: account\|reference\|creditor`, alte Werte,
`created_at`, `status: open\|transmitted`, FK auf transportierenden Einzugsposten. Eine
Rücklastschrift eines Postens mit offenem Amendment setzt es zurück auf `open`.

**Sequenztyp:** immer `RCUR`, nie `FRST` (seit 11/2016 rulebook-konform, §8). Kein
Konfigurationsschalter, keine Ableitungslogik.

**36-Monats-Verfall:** `expires_at = COALESCE(last_presented_due_date, signed_at) + 36 Monate`;
automatischer Cron-Übergang zu `ended`/`expired`, 180-Tage-Vorwarnung als Aufgabe.
`last_presented_due_date` wird bei der Einreichung gesetzt, nie zurückgenommen — auch nicht
nach Rücklastschrift.

**Sperre (`suspended`):** einziger Aussetz-Hebel (Rückgabe-Klassen ziehen ihn, §3.6). Zwei
Auslöser (`manual`\|`returned_debit`), nur manuelles Aufheben mit Pflicht-Notiz, keine
Auto-Entsperrung. Beendet nichts — offene Forderungen bleiben offen.

**Widerruf/Austritt:** Widerruf terminal (`ended`/`revoked`), nie reaktivierbar. Austritt
beendet das Mandat automatisch per Cron, **erst wenn keine Forderung mehr offen ist**.

**Historie:** `MandateEvent` (append-only, jeder Zustandswechsel/jede Feldänderung) —
zusätzlich zu, nicht statt `MandateAmendment`. `actor_type: member\|staff\|system`,
`actor_uid`, `on_behalf_note`.

**Mandatsformular-PDF** wird aus demselben versionierten Rechtstext erzeugt wie die
elektronische Zustimmung (§3.11).

#### Beitragsgruppe (Contribution Group) — [T07](../tickets/T07-beitragsgruppen-detail.md)

```text
name   min_monthly_amount   default_monthly_amount
allowed_intervals (Teilmenge von {1,2,3,4,6,12})   default_interval
is_active
```

Trägt das Regelwerk; **keine Historisierung** — was ein Mitglied wann schuldete steht in der
Forderung.

#### Zuweisung (Assignment) — [T07](../tickets/T07-beitragsgruppen-detail.md), [T09](../tickets/T09-self-service-regeln.md), [T10](../tickets/T10-einzugszyklus-choreografie.md)

```text
member_id   group_id
interval_months (aus allowed_intervals)   monthly_amount (2 Nachkommastellen)
min_monthly_amount_override (nullable)   override_reason (nullable, empfohlen)
payment_method: direct_debit | transfer (Default direct_debit)
valid_from (nie in der Vergangenheit)   valid_to (nullable)
```

Zeitraumbehaftete n:m-Kante Mitglied↔Gruppe; der Zeitraum *ist* der Status. Mehrere
Zuweisungen pro Mitglied erlaubt (Basis + Sparte + Förderbeitrag); keine zeitlich
überlappende Doppelzuweisung zur selben Gruppe. Individuelle Untergrenze ersetzt die
Gruppen-Untergrenze **in beide Richtungen**, nur `buchhalter` setzt sie.

`AssignmentEvent` (append-only): `type: amount_changed\|interval_changed\|
min_amount_override_set\|group_changed\|assignment_started\|assignment_ended`,
`actor_type/actor_uid/on_behalf_note` wie bei `MandateEvent`.

#### Forderung (Claim) — [T02](../tickets/T02-domaenenmodell-kern.md) Pkt. 8/9, [T07](../tickets/T07-beitragsgruppen-detail.md), [T10](../tickets/T10-einzugszyklus-choreografie.md), [T11](../tickets/T11-ruecklastschrift-fachlogik.md), [T27](../tickets/T27-kontierungs-detail.md), [T31](../tickets/T31-vorabinfo-mail-wortlaut.md)

Wird auf die bestehende, geteilte `vbh_open_items` abgebildet (§4), **keine eigene Tabelle**.

```text
member_id (Pflicht)   assignment_id (optional — manuelle Einzelforderungen/Gebühren haben keine)
type: contribution | fee
amount   period_start/period_end (nullable bei manuell)   due_date
label / bezeichnung (Pflicht bei manuell, sonst optional — Freitext im Verwendungszweck)
prenotified_at (nullable — Sperrgrenze für Änderungen)
cancelled_at (+ Pflicht-Begründung, nur vor Einreichung)
deferred_until (+ Pflicht-Begründung, Urheber, Zeitpunkt — Stundung, genau eine aktive je Forderung)
settled_at / settled_by / settlement_type: paid|waived / settlement_note
```

**Zustand ist vollständig abgeleitet, kein Statusfeld:**

| Zustand | Ableitung |
|---|---|
| offen | kein Posten in lebendem Lauf, kein Erledigungsvermerk |
| im Einzug | Posten in `released`/`submitted`-Lauf, Termin noch nicht erreicht |
| eingezogen | Posten in `submitted`-Lauf, Termin vorbei, keine Rücklastschrift — *oder* Erledigungsvermerk gesetzt |
| zurückgegeben → wieder offen | Rücklastschrift am Posten |
| storniert | `cancelled_at` gesetzt |

Eine Forderung hat **höchstens einen Einzugsposten** — es gibt keinen Wiedereinzug in v1
(§3.5, §3.6).

#### Einzugsposten (Debit Item) — [T02](../tickets/T02-domaenenmodell-kern.md) Pkt. 10, [T08](../tickets/T08-mandats-lifecycle.md), [T10](../tickets/T10-einzugszyklus-choreografie.md)

Snapshot, entsteht **erst bei der Freigabe** (nicht beim Cron-Vorschlag): Betrag, IBAN, BIC,
Kontoinhaber, Mandatsreferenz, `sequence_type` (konstant `RCUR`), `EndToEndId`,
Verwendungszweck, `amendment_indicator`, `original_debtor_account` (SMNDA). Posten und
XML-Zeile sind dieselbe Wahrheit. Kein eigener Status — ableitbar aus Lauf-Status +
Rücklastschrift.

#### Lastschriftlauf (Debit Batch) — [T10](../tickets/T10-einzugszyklus-choreografie.md) §5

Bündelt **alle an einem Einzugstermin fälligen** Einzugsposten (turnusübergreifend) zu einer
pain.008-Datei. **Existiert nicht vor der Freigabe** — davor ist der Lauf eine Abfrage.

```text
due_date (nur nach hinten verschiebbar)
status: released → submitted | discarded  (beide terminal)
released_by/_at   submitted_by/_at   discarded_by/_at + Begründung
msg_id   creation_date_time  (bei Freigabe eingefroren → byte-identisch nachrenderbar)
```

#### Rücklastschrift (Returned Debit) — [T02](../tickets/T02-domaenenmodell-kern.md) Pkt. 11, [T11](../tickets/T11-ruecklastschrift-fachlogik.md), [T12](../tickets/T12-quellen-abstraktion-fallback.md)

Höchstens eine je Einzugsposten. `reason_code` (+ Freitext bei „unbekannt"), `received_at`,
`source: import\|manual`, optionale Bankgebühr laut Kontoauszug (`charges_cents`).

#### Mahnversand (Dunning Notice) — [T11](../tickets/T11-ruecklastschrift-fachlogik.md) §5

Eine Zeile je (Forderung, Stufe) mit Versandzeitpunkt + Batch-Referenz der Mail. Einzige
Mahnwesen-Persistenz — alles andere ist Ableitung.

#### Zuordnungs-Vorschlag (Settlement Proposal) — [T11](../tickets/T11-ruecklastschrift-fachlogik.md) §9, [T12](../tickets/T12-quellen-abstraktion-fallback.md)

Maschineller Hinweis „importierte Gutschrift passt auf offene Forderung" mit Begründung
statt Konfidenz-Zahl. Bestätigung setzt `settlement_type: paid` mit Buchungsreferenz. Ein
unbeurteilter Vorschlag hält die Mahn-Uhr der Forderung an.

#### MandateLegalTextVersion — [T34](../tickets/T34-mandats-rechtstext.md)

```text
id   created_at   created_by: verwalter | system
body  (Pflichtblock + Rahmen, vollständiger Text inkl. {{creditor_name}}-Platzhalter)
```

Eigene Tabelle (**nicht** geteilt mit Mail-Templates — Korrektur an T08, s. §3.11). Neue
Version admin-getrieben (Rahmen gespeichert) oder system-getrieben (App-Update ändert
Pflichtblock). Keine Rückwirkung auf bestehende Mandate.

#### Terminplan (Due Date Schedule) — [T10](../tickets/T10-einzugszyklus-choreografie.md) §1

**Einstellung, keine Entity, kein Generator-Cron.** Je Turnus ein Standard-Einzugstag +
eine überschreibbare Zeile je Periode (Zeilenidentität = Periodenindex, nicht Datum — gilt
jahresunabhängig).

### 2.3 Begriffssystem (Code-Identifier)

`Member`, `Mandate`, `MandateAmendment`, `MandateEvent`, `MandateLegalTextVersion`,
`ContributionGroup`, `Assignment`, `AssignmentEvent`, `Claim` (auf `vbh_open_items`
abgebildet), `DunningNotice`, `SettlementProposal`, `DebitBatch`, `DebitItem`,
`ReturnedDebit`. Vollständiges Glossar mit Begründung, Herleitung und Avoid-Listen:
[CONTEXT.md](../../CONTEXT.md).

---

## 3. Feature-Spezifikationen

### 3.1 Mitgliederverwaltung — [T17](../tickets/T17-mitgliederverwaltung-light.md)

**Verhalten:**

- **Aufnahme ist ein dreistufiger Assistent** (Stammdaten → Mandat → Beitrag), Schritt 2/3
  überspringbar. Reihenfolge ist Fachentscheidung: Die Mail-Pflicht für Lastschrift muss
  greifen, solange sie an Ort und Stelle behebbar ist; fehlt die Mail, fällt Schritt 3
  sichtbar auf `transfer` zurück und sagt warum. Beim `paper`-Weg entscheidet das
  Mandatsdatum sofort-`active` vs. `draft`; beim `electronic`-Weg geht der Einmal-Link raus.
  Schritt 3 schlägt den Einzugstermin der Prorata-Erstforderung vor (§3.5).
- **CSV-Import legt nur an, gleicht nie ab.** Zeile = ein Mitglied mit zwei optionalen,
  atomaren Blöcken (Mandat, Zuweisung). Dublettenregeln: hart (`member_number`/`nc_user_id`
  vorhanden → übersprungen), weich (Namensgleichheit → Warnung, importiert). Mailadresse
  ist **kein** Dublettenschlüssel. Zeile ohne Mail landet auf `transfer`, kein Fehler.
  Mandatsreferenz ist eine Importspalte (freie Eingabe aus Fremdsystemen). **Der Import
  aktiviert Mandate**: Zeile mit IBAN + Kontoinhaber + Mandatsdatum → `active`; die
  Preview-Bestätigung *ist* die vom Aktivierungs-Gate verlangte Admin-Handlung
  (Bestätigungs-Checkbox „die unterschriebenen Mandate liegen vor").
- **NC-Verknüpfung entsteht nur mit menschlicher Bestätigung.** Mailadresse ist
  Vorschlagsschlüssel, nie Vollzug — bei mehreren Treffern werden alle gezeigt, keiner
  vorausgewählt. Grund: Mailadressen sind in Vereinen nicht eindeutig (Familienadresse),
  und die Verknüpfung ist der Self-Service-Zugang.
- **Kontolöschung rettet die Mailadresse** (`BeforeUserDeletedEvent`, `member.email` wird
  einmalig befüllt, nur wenn leer) und erzeugt eine Aufgabe — nie stillschweigend.
- **Austritt ist ein Vorgang mit Wirkungsvorschau**, nicht ein Datumsfeld: setzt `valid_to`
  aller offenen Zuweisungen, offene Forderungen bleiben offen und werden weiter gemahnt,
  Mandat endet automatisch (§Mandat), sobald nichts mehr offen ist. Zukunftsdatum erlaubt.
  **Kein Archivzustand** — `left_at` in der Vergangenheit *ist* das Archiv.
- **Löschen** nur ohne Forderung und ohne je aktives Mandat (mit erklärender Sperrmeldung
  statt Ausgrauen). Für DSGVO-Fälle: Anonymisieren statt Löschen (§3.8).

**Regeln:** Trennlinie *Kontaktdaten pflegt das Mitglied, Vereinsdaten pflegt der Verein*
(vgl. Feldkatalog §2.2); keine rückwirkenden Zuweisungen (`valid_from` nie in der
Vergangenheit — Nachforderungen laufen über manuelle Einzelforderung); keine Auto-Vergabe
der Mitgliedsnummer, auch kein Vorschlag.

**Störfälle:** „NC-Konto von X wurde gelöscht — Adresse übernommen, bitte prüfen"
(§7 Aufgabenliste).

**Umbaupfad:** `vbh_sepa_mandates`/`vbh_membership_fees` verlieren `member_uid`/
`member_label`, bekommen `member_id`. Migration je distinctem Zahler: `member_uid` →
Mitglied mit NC-Displayname; `member_label` mit Leerzeichen → `person` (Split am ersten
Leerzeichen); ohne Leerzeichen → `organization`. `MemberReferenceValidator` entfällt
ersatzlos. Nach der Migration eine Aufgabe „N Mitglieder übernommen — Namen/Mailadressen
prüfen". Bestehende UI (`MembersList.vue`, `MemberCard.vue`, `MemberDialog.vue`,
`MemberImportDialog.vue`) wird umgebaut, nicht ersetzt; `MemberCsvParser`
(~60 Header-Synonyme, 25 Unit-Tests) wird erweitert, nicht neu gebaut.

### 3.2 Mandats-Lifecycle

Siehe vollständiges Modell in [§2.2 Mandat](#mandat-mandate--t08). Ergänzend:

**Verhalten Freigabe-Kopplung:** Mandatsprüfung erfolgt an zwei Zeitpunkten — bei der
Forderungserzeugung (kein aktives Mandat → kein Forderungseintrag, sondern Störfall) und
bei der Freigabe (Snapshot + pain.008 entstehen gemeinsam, §3.5). Nach der Einreichung
prüft nichts mehr.

**Nachweis-Ablage:** echte Nextcloud-Datei, referenziert per File-ID in
`mandate_document_folder` (Default `/SEPA-Mandate`), kein DB-BLOB, kein `appdata`.

**Störfälle:** `draft` → „Unterschrift fehlt"; `suspended` → „Klärung offen"; `ended` →
„neues Mandat einholen"; Dauer-Hinweis „Mandat ohne Nachweis" (abschaltbar); Vorwarnung
„Mandat läuft in 180 Tagen ab" (§7).

### 3.3 Beitragsgruppen & Zuweisungen — [T07](../tickets/T07-beitragsgruppen-detail.md)

**Leitsatz:** Der Monatsbeitrag ist das Atom — alle Beträge sind Monatsbeträge (2
Nachkommastellen), jeder Einzugsbetrag entsteht per Multiplikation (Monatsbeitrag ×
Turnusmonate); nirgends wird geteilt oder gerundet.

**Verhalten:**

- Erlaubte Turnuswerte = Teiler von 12 (1/2/3/4/6/12 Monate), gespeichert als
  `interval_months` an der Zuweisung, gewählt aus `allowed_intervals` der Gruppe.
- Ein Beitragsjahr pro Verein, Startmonat konfigurierbar. Periode ≠ Einzugstermin (§3.5).
- Prorata monatsgranular: angebrochene Monate zählen an beiden Enden voll. Kein
  Gruppen-Schalter „kein Prorata".
- **Untergrenzen-Erhöhung: Auto-Anhebung mit Vorschau** — betroffene Zuweisungen namentlich
  alt→neu, individuelle Untergrenzen separat als unberührt ausgewiesen.
- **Eine Wirksamkeitsregel für alles** (Gruppenwechsel, Turnuswechsel, Betragsänderung,
  Untergrenzen-Erhöhung): wirkt ab der ersten Periode ohne versandte Vorabinfo (präzisiert
  durch T09 für Self-Service, §3.4). Bereits eingezogene Perioden werden **nie** neu
  berechnet — keine Rückrechnung, kein Guthaben, keine Erstattung.
- **Manuelle Einzelforderung** als schmale Tür: freier Betrag, Pflicht-Bezeichnung, eigener
  Einzugstermin, auch ohne aktives Mandat anlegbar; jede Forderung kann per
  Erledigungsvermerk als `paid` markiert werden.

**Feldkatalog:** siehe [§2.2 ContributionGroup / Assignment](#beitragsgruppe-contribution-group--t07).

**Umbaupfad:** `vbh_membership_fees` (ein Betrag + Frequenz je Mitglied, kein Prorata, keine
Untergrenzen) wird zu Beitragsgruppen + Zuweisungen umgebaut — hart, keine
Migrationspflicht ([T26](../tickets/T26-recharting-nc-vereinsbuchhaltung.md) Pkt. 4).

### 3.4 Self-Service — [T09](../tickets/T09-self-service-regeln.md), Ort/Gate: [T19](../tickets/T19-rollen-rechtemodell.md) §4-5

**Leitsatz:** Vertrauensraum, kein Antragsmodell — Änderungen wirken sofort, Leitplanken
sind Regeln (Untergrenze, `allowed_intervals`, Vorabinfo), nicht Menschen.

**Zugang** (revidiert von [T26](../tickets/T26-recharting-nc-vereinsbuchhaltung.md)/[T19](../tickets/T19-rollen-rechtemodell.md),
**nicht** mehr die ursprünglich in T09 entworfene NC-App-Gruppenbeschränkung): Self-Service
folgt der **Mitglied↔NC-Konto-Verknüpfung**, keine Rolle. `self_service_enabled` ist eine
App-Einstellung (Bool, ab `verwalter` änderbar). Technisch: eigener Controller unter
`/api/self/*`, vierter `instanceof`-Sonderfall in der `PermissionMiddleware` — statt
Rollenprüfung zentral `self_service_enabled && Kontoverknüpfung` prüfen, aufgelöste
`member_id` als Request-Kontext, jede Query filtert darauf. Kein `#[PublicPage]`. UI-Ort:
Bereich **„Mein Beitrag"** in der bestehenden SPA (kein zweiter NC-Navigationseintrag) —
ersetzt das heutige „Kein Zugriff"-Panel für verknüpfte Konten, additiv neben dem
Buchhaltungs-Tab bei Personalunion.

**Aktionskatalog:**

| Darf (sofort wirksam) | Darf nicht |
|---|---|
| Mandat erfassen + elektronisch erteilen | Beitragsgruppe wechseln |
| Mandat widerrufen (terminal, mit Reibung) | Mandat aktivieren/sperren |
| IBAN ändern (gleicher Kontoinhaber) | „Diesen Monat mal nicht abbuchen" |
| Kontoinhaber wechseln (erzwingt neues Mandat) | Austritt erklären |
| Monatsbeitrag: hoch frei, runter bis Untergrenze | Erledigungsvermerk setzen |
| Turnus wechseln (aus `allowed_intervals`) | individuelle Untergrenze ändern |
| Kontaktstammdaten pflegen | |
| eigene Daten einsehen | |

**Sperrfenster:**

- **IBAN:** kein Sperrfenster nötig — Vorabinfo nennt keine IBAN, Snapshot entsteht erst bei
  Freigabe. Änderung bis zur Freigabe möglich, Amendment reitet mit.
- **Betrag/Turnus:** gesperrt sobald für die Periode `prenotified_at` gesetzt ist — präziser
  als „bis zur Freigabe" (~14 Tage früher), verhindert Widerspruch durch abweichenden
  Betrag.
- **Turnuswechsel-Sonderfall:** wirkt ab der ersten Periode des *neuen* Turnus, die
  vollständig hinter der letzten eingezogenen liegt (eigenständige Lesart der
  Wirksamkeitsregel, weil der Turnus das Periodenraster selbst ändert).
- **Freigabe→Einreichung-Fenster:** ein nicht als eingereicht markierter Lauf warnt bei
  Abweichung zu aktuellen Mandatsdaten („3 Posten weichen ab …") und darf verworfen +
  neu freigegeben werden (kein Storno nach Einreichung).

**Pflicht-UI-Elemente:** Vorschau vor jedem Speichern (*„Wirkt ab … · erster Einzug am … ·
Betrag"*) als einzige Bremse des Sofort-wirksam-Modells; IBAN immer maskiert; individuelle
Untergrenze sichtbar, ihre Begründung nicht; Rücklastschriften als Klartext (nie Code);
keine Ereignislisten (Quittungsmail ist die Kopie); niemals andere Mitglieder sichtbar.

**Benachrichtigungen:** Aufgabenliste (nur wo Handlung nötig) + `OCP\Activity`-Feed (jede
Änderung, Mail-Voreinstellung: an bei Widerruf, aus sonst) + **Quittungsmail an das
Mitglied, immer** (was/ab wann/welcher Einzug betroffen; bei E-Mail-Wechsel an alte **und**
neue Adresse).

**Stellvertretung (v1: nur Modell A):** Kassenwart handelt stellvertretend
(`actor_type: staff` + `on_behalf_note`); Token-Portale/Einmal-Links für den allgemeinen
Self-Service bewusst out of scope (§11) — nur der enge T08-Einmal-Link für elektronische
Mandatserteilung existiert.

**Reibungsdialoge** (Widerruf, Kontoinhaberwechsel): Endgültigkeit + offene Summe zeigen,
**Ausweg als Primäraktion** anbieten (*„Ich habe nur ein neues Konto → IBAN ändern"*), keine
Zweitfaktor-Bestätigung (Widerruf ist ein Recht). Exakte Dialogtexte: §3.11.

### 3.5 Einzugszyklus-Choreografie — [T10](../tickets/T10-einzugszyklus-choreografie.md)

**Leitsatz:** Ein Gate, eine Statusmaschine, keine Automatik, die zweimal Geld anfasst.

**Zeitachse** (Cron täglich, idempotent; alle Abstände konfigurierbar):

| Zeitpunkt | Wer | Was |
|---|---|---|
| D − 21 (Vorwarnfenster) | — | Aufgabe: „Nächster Lauf am … — N Forderungen, Summe, M Störfälle" |
| D − 14 (Vorabinfo-Vorlauf) | Cron | Vorabinfo-Mails, setzt `prenotified_at` → Periode ist zu |
| D − 5 (Vorlauf-Puffer) | Kassenwart | Freigabe + Einreichung, ein Vorgang |
| D | Bank | Belastung, frühestens |

- Terminplan ist eine **Einstellung**, kein Generator-Cron: je Turnus ein Standard-Einzugstag
  + eine überschreibbare Zeile je Periode; Guards: kein Überholen, Termin im Beitragsjahr.
- **Die Bank rechnet die Geschäftstagsverschiebung** — kein TARGET-Kalender im Code. Die
  Vorabinfo nennt „frühester Einzug: <Datum>".
- **Ein Lauf = ein Einzugstermin** (nicht ein Turnus) — bündelt Forderungen aller Turnusse,
  deren Periode auf diesen Termin zeigt, plus manuelle Einzelforderungen und
  Prorata-Erstforderungen mit diesem Termin.
- **Vor der Freigabe existiert kein Lauf-Datensatz.** Freigabe = Snapshot **und** pain.008 in
  einem Zug (Schritt 1 „Freigeben & Datei erzeugen"), Einreichung ist Schritt 2 („Datei ist
  bei der Bank eingereicht") — setzt `last_presented_due_date` an allen beteiligten
  Mandaten. Statusmaschine: `released` → `submitted` | `discarded` (beide terminal).
  `discarded` behält die Historie, Forderungen werden wieder frei, EndToEndIds werden nie
  wiederverwendet.
- **XML ist byte-identisch nachrenderbar** (`msg_id`/`creation_date_time` eingefroren).
  Ablage in einen NC-Ordner: optional, Default aus (die Datei enthält alle IBANs im
  Klartext).
- **Nachzügler sind eine Regel, kein Sonderfall:** eine Forderung fährt am nächsten Termin
  ihres Turnus, dessen Vorabinfo-Frist noch nicht angebrochen ist. Die
  Prorata-Erstforderung einer neuen Zuweisung trägt einen eigenen, vorgeschlagenen
  (überschreibbaren) Einzugstermin.
- **Störfälle blockieren nie, niemand quittiert sie** — abgeleitete Abfrage mit zwei
  Schweregraden (Handlungsbedarf/Hinweis, vollständiger Katalog in §7).
- **`payment_method`** (`direct_debit`\|`transfer`, Default `direct_debit`) an der Zuweisung
  verhindert, dass reine Überweiser einen Dauer-Störfall erzeugen. Überweiser bekommen
  Forderungen, aber nie Einzugsposten, Vorabinfo oder Störfall.
- **Kein Wiedereinzug in v1.** Nach Rücklastschrift geht automatisch eine
  Zahlungsaufforderungs-Mail raus (§3.6); die Forderung wird nie wieder in einen Lauf
  gezogen, sondern per Erledigungsvermerk geführt.
- **Gerissene Vorlauffrist:** blockiert nichts, verschiebt nichts automatisch, verfällt
  nicht — nur eine eskalierende Aufgabe. Termin nur nach hinten verschiebbar, nie nach
  vorn.

**Bewusst verworfen:** Jahres-Vorabinfo (bräuchte zweite, entkoppelte Sperrsemantik —
[Out of scope](#11-out-of-scope)); Papier-Vorabinfo; Terminplan-Freigabe-Gate; eigener
TARGET-Kalender.

### 3.6 Rücklastschrift-Fachlogik & Mahnwesen — [T11](../tickets/T11-ruecklastschrift-fachlogik.md), [T12](../tickets/T12-quellen-abstraktion-fallback.md)

**Rückgabe-Klassen** (hartkodiert, deterministisch aus ISO-Reason-Code, nicht gespeichert):

| Klasse | Beispiel-Codes | Mandat-Wirkung | Zahlungsaufforderung | Aufgabe |
|---|---|---|---|---|
| `insufficient_funds` | AM04, MS03 | — | sofort | Hinweis |
| `account_unusable` | AC01, AC04, AC06, AC13, AG01, RC01, BE05 | auto-sperren | sofort | dringend |
| `disputed` | MD01, MD06, MS02, SL01 | auto-sperren (MD06 sofort) | sofort | dringend |
| `deceased` | MD07 | auto-sperren | nein | dringend |
| `technical` | AM05, AG02, FF01, FF05, TM01, DT01, RR01–04, FOCR, CNOR, DNOR | — | nein | dringend, ggf. manuelle Einzelforderung |
| `unknown` | Rest | — | nein | dringend |

Kein Code beendet je automatisch ein Mandat — härteste Auto-Wirkung ist die Sperre.

**Gebühren-Weiterbelastung:** Opt-in-Verwaltungseinstellung (Default aus), Höhe = exakt die
Bankgebühr laut Rücklastschrift (keine Pauschale), automatisch nur bei
`insufficient_funds`/`account_unusable`; Gebühren-Forderung erbt den Lastschriftausschluss
ihres Auslösers.

**Mahnstufen** (eine Familie, gebündelt je Mitglied):

| Stufe | Name | Auslöser | Automatik |
|---|---|---|---|
| 0 | Zahlungsaufforderung | Rücklastschrift (sofort) / Widerruf mit offenen Forderungen (sofort) / `transfer`-Forderung (mit Vorabinfo-Vorlauf) | ja, gatefrei |
| 1 | Zahlungserinnerung | Zahlungsziel + Mahnabstand (Default 14 Tage) verstrichen | ja |
| 2 | Mahnung | + Mahnabstand verstrichen | ja, kündigt Eskalation an |
| — | Eskalation | nach Stufe 2 + Abstand | Aufgabe „Vorstand entscheiden lassen", keine weitere Automatik |

Ableitungsformel für Stufe 1/2 trägt die Zusatzbedingung „**nicht aktuell gestundet**"
([T32](../tickets/T32-mahn-treppe-textbausteine.md) präzisiert T11 §5) — eine gestundete
Forderung fällt komplett aus der Positionsliste, bis die Stundung abläuft; danach läuft die
Uhr von der zuletzt erreichten Stufe weiter (kein Reset).

**Abschluss:** Erledigungsvermerk `paid` (Datum, Urheber, Notiz) oder `waived` (Pflicht-
Begründung) — scharf getrennt vom Storno (**„hätte nie existieren dürfen"**, nur vor
Einreichung) vs. Erlass (**„war berechtigt, wir verzichten"**, jederzeit).

**Zahlungseingangs-Abgleich:** Zuordnungs-Vorschlag statt Auto-Erledigen (§2.2), unbeurteilte
Vorschläge halten die Mahn-Uhr an.

**Import-Härtung** ([T12](../tickets/T12-quellen-abstraktion-fallback.md), siehe auch §5):
neue 1:n-Nebentabelle `vbh_bank_tx_sepa_details` (eine Zeile je `TxDtls`), Matching in drei
Stufen (End-to-End-Id → Mandatsreferenz+Betrag → Betrag+IBAN als Fallback), **alle Stufen
erzeugen Zuordnungs-Vorschläge** mit Begründung statt Konfidenz-Zahl. Posten-Guard: max. 1
Rücklastschrift je Posten, Dubletten verpuffen still (sicher weil kein Wiedereinzug
existiert). Mandats-Rewind (`rewindMandateUsage`) entfällt ersatzlos.

**Mitglieder-Klartexte:** ein wahrheitsfester Text je Klasse (Codes bleiben admin-only),
vollständige Texte in [§3.11](#311-textbausteine--t31-t34).

### 3.7 Beitragsbescheinigungen — [T16](../tickets/T16-beitragsbescheinigungen.md)

**Leitsatz:** Informeller Beleg, keine Steuerformalie — eine Live-Ansicht, kein Dokument.

- Nur eine informelle Beitragsbestätigung, **keine** amtliche Zuwendungsbestätigung nach
  §10b EStG (bleibt Sache des separaten Upstream-Issues
  [#10 „Spendenbescheinigung"](https://github.com/AndiMb/nc_vereinsbuchhaltung/issues/10)).
- Zeitraum: das **Beitragsjahr** (nicht das `Period`-Geschäftsjahr des Gefäßes).
- Inhalt: Auflistung je bezahlter Beitrags-Forderung (Fälligkeitsperiode + Betrag) +
  Summenzeile. Gebühren-Forderungen fließen nie ein.
- Zuordnung nach **Fälligkeitsperiode, nicht Zahlungsdatum** (Nachzügler zählen zum alten
  Jahr).
- **Keine Sammellauf-Funktion, keine PDF-Bibliothek, kein gespeichertes Dokument** — reine
  Live-Ansicht (druckfertiges HTML, Wiederverwendung von `KassenberichtRenderer`/
  `PrintableReportPage`), da keine Zustellpflicht besteht. Kein Zustellungs-Tracking.
- Zugang: Self-Service-Login mit NC-Konto, sonst Stellvertretung durch den Kassenwart
  (kein login-loser Link — T09/T10-Grenze bleibt).
- Fehlende Adresse: Hinweis-Banner „Adresse jetzt hinterlegen" statt Admin-Aufgabe.

### 3.8 DSGVO-Detailkonzept — [T30](../tickets/T30-dsgvo-detailkonzept.md)

- **Frist:** Anonymisierungsreif = 10 Jahre nach Ende des Kalenderjahres der letzten
  zugehörigen Buchung — fest im Code, keine Vereinseinstellung, dominiert immer die
  SEPA-Untergrenze aus §8 (≥14 Monate).
- **Auslöser:** Cron schlägt vor, `buchhalter` bestätigt je Mitglied einzeln (kein
  Vollautomatismus — irreversibel).
- **Mechanismus:** ein Anonymisierungs-Vorgang je Mitglied (nicht je Mandat) — schwärzt
  Namen/Kontakt am Mitglied, Bankdaten an allen Mandaten (nutzt das nullable IBAN-Feld,
  konkretisiert `redacted_at`), sowie alle personenbezogenen Freitextfelder in der gesamten
  verknüpften Historie (Rücklastschrift-Freitext, `suspension_note`, Event-Notizen,
  `on_behalf_note`). Strukturierte Felder (Reason-Code, Beträge, Daten, Status) bleiben
  stehen.
- **Auskunft/Export:** druckfertige Live-Ansicht „Datenübersicht" (Muster
  `KassenberichtRenderer`), erreichbar für `buchhalter` je Mitglied und im Self-Service
  unter „Meine Daten" — deckt Art. 15 DSGVO. **Kein** strukturierter Export (Art. 20) in v1.
- NC-Konto-Löschung bleibt vollständig entkoppelt vom Anonymisierungs-Zeitpunkt (der
  T09-Zielkonflikt ist durch T16s Stellvertretungslösung bereits aufgelöst).

### 3.9 Rollen & Rechte — [T19](../tickets/T19-rollen-rechtemodell.md)

**Leitsatz:** Eine Trennlinie statt Aktions-Mapping — operativ = `buchhalter`, konfigurativ
= `verwalter`; Self-Service ist keine Rolle, sondern eine Middleware-Ausnahme entlang der
Kontoverknüpfung (§3.4). Das bestehende Rollenmodell (`none < revisor < buchhalter <
verwalter`, NC-Admins immer `verwalter`) wird unverändert übernommen.

| Handlung | Rolle |
|---|---|
| Lauf-Freigabe/Einreichung, Mandats-Aktivierung/Sperren/Entsperren, individuelle Untergrenze, Erledigungsvermerk inkl. Erlass, Stundung, Stellvertretung, manuelle Einzelforderung, Import, Terminverschiebung | `buchhalter` |
| Einstellungen (Beitragsjahr, Vorabinfo-Vorlauf, Vorlauf-Puffer, Vorwarnfenster, XML-Ablage, `self_service_enabled`, Rücklastschriftgebühren-Konto), Rechtevergabe | `verwalter` |
| Einzug-Unterreiter lesend (Läufe, Forderungen, Erledigungsvermerke, Störfall-/Rücklastschriftlisten, Offene-Posten-Sicht), IBAN maskiert | `revisor` |
| Mitglieder-Unterreiter (Personenakte, unmaskierte IBAN, Kontaktdaten) | `buchhalter` (nicht `revisor`) |

**Konvention:** jede neue Controller-Methode trägt explizit `#[RequiresRole]` — die
Fail-open-Verb-Heuristik des Gefäßes wird im Modul nicht genutzt.

**Personalunion:** der **Kanal** entscheidet `actor_type`, nicht die Identität — Änderung
über „Mein Beitrag" → `actor_type: member`, über die Admin-Akte (auch an eigener Akte) →
`actor_type: staff`. Die vbh-Rolle wird nicht ins Event geschrieben.

**Kein Nur-Lese-Self-Service in v1** — `self_service_enabled` bleibt einfacher Bool-Schalter,
additiv nachrüstbar.

### 3.10 Kontierung — [T27](../tickets/T27-kontierungs-detail.md)

**Verbuchungsprinzip:** „Der Bankauszug ist die Wahrheit" — keine Journal-Buchung ohne
bestätigten Bank-Umsatz, nichts bucht automatisch.

- **Forderung → `vbh_open_items`**, keine eigene Tabelle (§2.2, §4).
- **Sammelbuchung des Einzugs:** kein neuer SEPA-spezifischer Buchungsmechanismus — der
  bestehende `BookingService::assignParts()`/`doAssign()`-Pfad (mehrteilige Bankumsätze)
  wird wiederverwendet. Split kommt aus dem Lauf-Snapshot, gruppiert nach `account_id`;
  Zuordnung strukturell über `batch_reference` (= `PmtInfId`). Konfirmation und Settlement
  in einem atomaren Schritt (`settleBatch()` erweitert um vorausgehenden
  `assignParts()`-Aufruf).
- **Rücklastschrift-Buchung:** zwei Gegenkonto-Zeilen — OAMT zurück auf das ursprüngliche
  Erlöskonto der Forderung, COAM auf ein konfigurierbares Aufwandskonto „Konto für
  Rücklastschriftgebühren" (`verwalter`-Einstellung). Die opt-in Gebühren-Forderung ans
  Mitglied bleibt getrennt und bucht bei ihrem eigenen Zahlungseingang.
- **Erlass/Storno vor Zahlungseingang:** reiner Statuswechsel, **keine Journal-Buchung** —
  konsistent mit „kein Bankauszug-Eintrag".
- **Jahresgrenze:** kein neuer Mechanismus — bucht immer mit dem Datum des Bank-Umsatzes,
  `PeriodService::assertOpen()` prüft dessen Periode, nicht die des ursprünglichen Einzugs.
- Keine neue Kollaborationsreibung — derselbe `assignParts()`/`doAssign()`-Pfad wie jede
  manuelle Zuordnung, läuft durch `TransactionRunner`, `EntryNumberService`, Audit-Log.
- Rechte-Linie unverändert: Lesen ab `revisor`, Ändern ab `buchhalter`.

### 3.11 Textbausteine — [T31](../tickets/T31-vorabinfo-mail-wortlaut.md), [T32](../tickets/T32-mahn-treppe-textbausteine.md), [T33](../tickets/T33-self-service-quittungen-dialogtexte.md), [T34](../tickets/T34-mandats-rechtstext.md)

Technisches Baumuster für alle Mails: `IMailer::createEMailTemplate()` mit `IL10N::t()`
(Quellsprache Deutsch, Zielbundle `en.json`), wie im bestehenden
`SepaNotificationService.php`.

| Text | Ticket | Kern |
|---|---|---|
| Vorabinfo-Mail | [T31](../tickets/T31-vorabinfo-mail-wortlaut.md) | Ein Rendering-Pfad (auch bei 1 Position), Positionsliste (Bezeichnung + Periode + Betrag), chronologisch sortiert, „Frühester Einzug" als eigene Fakten-Zeile, Sperrgrenzen-Hinweis als eigener Satz, Betreff ohne „SEPA", Anrede „Guten Tag {Name},". Neues optionales Feld `bezeichnung` an der Forderung. |
| Zahlungsaufforderung/-erinnerung/Mahnung | [T32](../tickets/T32-mahn-treppe-textbausteine.md) | Gebündelt je Mitglied (auch Stufe 0), eigener Grund-Satz je Position, Einzelüberweisungen (kein Sammelbetrag), GiroCode (EPC-QR) als Anhang je Position (neue Composer-Abhängigkeit `chillerlan/php-qrcode`), kein Rechtsvokabular, `{organisationsname}` statt `{vereinsname}`. Sechs Klassen-Klartexte (Tabelle §3.6). |
| Self-Service-Quittungsmail | [T33](../tickets/T33-self-service-quittungen-dialogtexte.md) | Ein Vorlagen-Skelett mit vier aus-/einblendbaren Slots (was/ab wann/welcher Einzug/Stellvertretungs-Hinweis) statt sechs Fließtexten. E-Mail-Wechsel: eigener Warn-Text an die **alte** Adresse (neue Adresse maskiert, Verweis auf den Verein, kein Rücknahme-Link). |
| Dialogtexte Widerruf/Kontoinhaberwechsel | [T33](../tickets/T33-self-service-quittungen-dialogtexte.md) | Final, wörtlich in T33/T09/T14 fixiert (Ausweg als Primäraktion, keine Zweitbestätigung). |
| Mandats-Rechtstext | [T34](../tickets/T34-mandats-rechtstext.md) | Geschützter DK-Pflichtblock + freier, admin-editierbarer Rahmen. Eigene Tabelle `MandateLegalTextVersion` (**nicht** geteilt mit Mail-Templates — Mailtexte sind Code-Fixtexte, der Rechtstext ist DB-versioniert). Version fixiert bei Anzeige (nicht bei `signed_at`), keine Rückwirkung. PDF und elektronische Zustimmung teilen denselben Textkörper, nur die Hülle unterscheidet sich. Nur Deutsch. |

Du/Sie-Konvention (§1.4) gilt für alle vier Textfamilien einheitlich.

---

## 4. Datenmodell — Migrationsübersicht

| Bestandstabelle | Änderung |
|---|---|
| `vbh_sepa_mandates` | `member_uid`/`member_label` → `member_id`; neue Felder gemäß §2.2 Mandat (Status, Amendment/Event-Fremdtabellen, `mandate_text_version`, Nachweis-File-ID) |
| `vbh_membership_fees` | wird durch `ContributionGroup` + `Assignment` ersetzt (hart, keine Produktivnutzer) |
| `vbh_open_items` | additiv erweitert: `member_id`, `type` (`contribution`\|`fee`), `assignment_id`, Fälligkeitsperiode-Bezug, `waived` als vierter Status, Stundungsfelder — **keine** Kern-Buchhaltungsdaten verändern sich |
| `vbh_sepa_batches`/`_items` | werden zu `DebitBatch`/`DebitItem` mit der neuen Statusmaschine umgebaut |
| `vbh_bank_tx` | **unverändert** — `RowNormalizer::computeHash()` bleibt stabil |

**Neue Tabellen:** `Member`, `MandateAmendment`, `MandateEvent`, `MandateLegalTextVersion`,
`AssignmentEvent`, `DunningNotice`, `vbh_bank_tx_sepa_details` (1:n zu `vbh_bank_tx`, s. §5).

**Neue Einstellungen** (`verwalter`, in `SettingsSepaBasics.vue`/Sektion „Beiträge & SEPA"):
`self_service_enabled`, `prenotification_lead_days` (ersetzt `SepaNotificationService::
LEAD_DAYS = 14`), `fiscal_year_start_month`, `lead_buffer_days`, `expiry_warning_days`,
`xml_folder_enabled` + `xml_folder_path`, Rücklastschriftgebühren-Konto,
`mandate_reference_prefix`, `show_missing_document_warning`, `mandate_document_folder`.

---

## 5. Integrations- und Quellenschicht — [T04](../tickets/T04-research-nc-app-integration.md), [T05](../tickets/T05-research-r-transaktionen.md), [T12](../tickets/T12-quellen-abstraktion-fallback.md)

Kein App-Grenze mehr, kein zweiter Lieferant (Alt-Idee eines Events aus einer fremden App,
[T04](../tickets/T04-research-nc-app-integration.md), entfällt durch das Aufgehen in
nc_vereinsbuchhaltung). Import-Härtung bleibt jedoch nötig, jetzt als In-App-Arbeit.

**Persistenz:** neue 1:n-Nebentabelle `vbh_bank_tx_sepa_details`, eine Zeile je `TxDtls`
(camt) bzw. je referenztragender MT940-/CSV-Buchung, FK auf `vbh_bank_tx`. Trägt auch die
Gutschrift-Seite des eigenen Sammeleinzugs (GVC 171).

**Feldkatalog:**

| Feld | Quelle je Format | Abnehmer |
|---|---|---|
| `end_to_end_id` | camt `Refs/EndToEndId` / MT940 `EREF+` / Sparkassen-CSV „Kundenreferenz (End-to-End)" | Matching Stufe 1 |
| `mandate_reference` | `Refs/MndtId` / `MREF+` / „Mandatsreferenz" | Matching Stufe 2 |
| `return_reason_code` | `RtrInf/Rsn/Cd` / `?34`→ISO (nur DK-Kernwerte 901–918, **nie raten**) / — | Rückgabe-Klassifikation §3.6 |
| `return_reason_text` | `RtrInf/AddtlInf` / SVWZ-Klartext / VWZ-Auszug | Kassenwart-UI, Audit |
| `original_amount_cents` | `AmtDtls/TxAmt` / `OAMT+`, `:61:/OCMT/` / „Lastschrift Ursprungsbetrag" | Forderung=Original, Kontierung (§3.10) |
| `charges_cents` | `Chrgs` / `COAM+`, `:61:/CHGS/` / „Auslagenersatz Ruecklastschrift" | Gebühr §3.6, Kontierung |
| `gvc` | `BkTxCd/Prtry/Cd` / `:86:`-Anfang / — | strukturelle Erkennung |
| `batch_reference` | `Btch/PmtInfId` / `KREF+` / „Sammlerreferenz" | Lauf-Matching |

Bewusst draußen: Gläubiger-ID (matcht immer, zeigt nichts), ein Sammler-Zähler.

**Erkennung „ist Rückgabe":** camt GVC 109/108 oder `RtrInf` vorhanden; MT940 GVC am
`:86:`-Anfang; Sparkassen-CSV Betrag negativ + (Ursprungsbetrag oder Auslagenersatz gefüllt);
referenzlose Formate (VR & Co.): bestehende Text-Heuristik als dokumentierter Fallback.

**Matching-Stufen** (alle erzeugen Vorschläge, keine wirkt automatisch): (1) `end_to_end_id`
exakt; (2) `mandate_reference` + Betrag; (3) Betrag + Zahler-IBAN unter offenen Posten, nur
bei Rückgabe-Signal. Mehrdeutigkeit → alle Kandidaten zur Auswahl. Kein Treffer → Aufgabe
„nicht zuordenbar".

**Dedup:** bestehende Dreistufen-Dedup bleibt Sache der Kern-Buchhaltung; neuer
**Posten-Guard** (max. 1 Rücklastschrift je Posten) verhindert Dubletten strukturell.

**Sammler:** ein Bestätigungsvorgang je Bankumsatz, Einzelurteil je Detail-Zeile; Verbuchung
setzt voraus, dass alle Detail-Vorschläge beurteilt sind (Schnittstellenregel an §3.10).

**Implementierungs-Hinweise (Fakten, nicht Entscheidung):**

- `FIELD_SYNONYMS` erweitern: `kundenreferenzendtoend`, `mandatsreferenz`,
  `lastschriftursprungsbetrag`, `auslagenersatzruecklastschrift`, `sammlerreferenz`.
- MT940: `?20–29`/`?60–63` konkatenieren, dann an SEPA-Präfixen splitten (Präfixe brechen
  über Subfeldgrenzen um); GVC am `:86:`-Anfang nicht mehr verwerfen.
- Marker-/Synonymlisten vor Scharfstellung gegen echte Vereinskonto-Exporte verifizieren
  (kein eigenes Ticket — hängt keine Entscheidung dran).
- `BankTransactionsImportedEvent` (ursprünglich in T04 skizziert) wird **nicht** gebaut —
  Erkennung bleibt im Commit-Ablauf des `ImportService`.

---

## 6. UX — Prototypen-Verweise

Navigationsentscheidung ([T22](../tickets/T22-ux-mitglieder-mandate.md) Variante C, gilt für
die ganze App): Beiträge-Tab behält zwei Unterreiter **Mitglieder | Einzug**; **Regelwerk**
ist ein Kopfzeilen-Werkzeug-Knopf (kein Unterreiter); **Aufgaben** ein
Querschnitts-Flyout mit Badge; Self-Service = Bereich „Mein Beitrag" in derselben SPA
(§3.4/§3.9). Gegen den echten vbh-Code verifiziert und passend befunden — s.
[T28](../tickets/T28-gesamt-ux.md).

| Bereich | Ticket | Prototyp | Gewählte Variante |
|---|---|---|---|
| Mitglieder & Mandate (+ Navigation) | [T22](../tickets/T22-ux-mitglieder-mandate.md) | [prototypes/mitglieder/](../prototypes/mitglieder/README.md) | C — Akte als Seitenleiste über stehenbleibender Liste |
| Beitragsgruppen & Terminplan | [T23](../tickets/T23-ux-gruppen-terminplan.md) | [prototypes/regelwerk/](../prototypes/regelwerk/README.md) | D — Master-Detail in der App, Einstellungen bei NC-Verwaltung |
| Einzug — Vorschau, Freigabe, Läufe | [T24](../tickets/T24-ux-einzug-freigabe.md) | [prototypes/einzug/](../prototypes/einzug/README.md) | A — Zeitstrahl mit HEUTE-Marker, Geisterkarte für Vorschau |
| Aufgaben & Offene Posten | [T25](../tickets/T25-ux-aufgaben-offene-posten.md) | [prototypes/aufgaben/](../prototypes/aufgaben/README.md) | A — Im Einzug, Segment neben dem Zeitstrahl |
| Member-Self-Service | [T14](../tickets/T14-ux-prototyp-self-service.md) | [prototypes/self-service/](../prototypes/self-service/README.md) | B — Zwei Säulen (Identität links, Geld rechts); mobil Fallback auf Geld-zuerst |
| Gesamtbild in der echten UI | [T28](../tickets/T28-gesamt-ux.md) | [prototypes/gesamt/](../prototypes/gesamt/README.md) | Wegwerf-Branch `prototype/gesamt-ux`, nie gemergt — Befundliste s. Ticket |

**Wiederverwendbare Bestandsmuster** ([T28](../tickets/T28-gesamt-ux.md)): `AmountInput.vue`,
`useConfirm.js`, `showSuccess`/`showError`, `FolderPathField.vue`, `NcModal` (statt
`NcDialog`, das in diesem Setup ohne Rahmen rendert). `MemberDialog.vue` bestätigt den
erwarteten harten Umbau (heute flaches Formular ohne Lifecycle/Gruppen). Rollensicht-
Rückstand notiert: `revisor` sieht die Beiträge-Sektion heute gar nicht — Implementierungslücke
gegenüber der bereits getroffenen T19-Entscheidung, kein neuer Klärungsbedarf.

---

## 7. Automatik/Jobs — Cron-Übersicht

| Job | Takt | Wirkung |
|---|---|---|
| Einzugszyklus-Cron | täglich | Forderungen anlegen, Vorabinfos versenden (D−14, setzt `prenotified_at`), Aufgabenliste neu berechnen — [T10](../tickets/T10-einzugszyklus-choreografie.md) |
| Mandatsverfall (36 Monate) | täglich | automatischer Übergang zu `ended`/`expired`, 180-Tage-Vorwarnung — [T08](../tickets/T08-mandats-lifecycle.md) |
| Austritts-Mandatsende | täglich | Mandat automatisch beenden, sobald `left_at` erreicht **und** keine Forderung mehr offen — [T08](../tickets/T08-mandats-lifecycle.md) |
| Mahnstufen (1/2) | täglich | Zahlungserinnerung/Mahnung nach konfigurierbarem Abstand (Default 14 Tage), Stundung pausiert — [T11](../tickets/T11-ruecklastschrift-fachlogik.md), [T32](../tickets/T32-mahn-treppe-textbausteine.md) |
| Zahlungsaufforderung (Stufe 0) | ereignisgetrieben | sofort bei Rücklastschrift/Widerruf; mit Vorlauf bei `transfer`-Fälligkeit — [T11](../tickets/T11-ruecklastschrift-fachlogik.md) |
| Anonymisierungs-Vorschlag | täglich (oder seltener) | Aufgabe „Mitglied X anonymisierungsreif", **kein** Automatismus — [T30](../tickets/T30-dsgvo-detailkonzept.md) |
| Aufgabenliste | abgeleitete Abfrage, kein Job | keine Entity, kostet nichts — [T09](../tickets/T09-self-service-regeln.md)/[T10](../tickets/T10-einzugszyklus-choreografie.md) |

**Vollständiger Aufgaben-Katalog** (zwei Schweregrade, kein Quittieren — [T25](../tickets/T25-ux-aufgaben-offene-posten.md)):
Kein Mandat + Lastschrift gewollt · Mandat-Entwurf Papier, Unterschrift fehlt ·
Mandat-Entwurf elektronisch, Link ≥14 Tage alt (Hinweis <14 Tage) · Mandat gesperrt · Mandat
beendet, Lastschrift weiter gewollt · Mandat ohne Nachweis (abschaltbar, Hinweis) · Mandat
verfällt in 180 Tagen (Hinweis) · Vorabinfo nicht zustellbar · Ausgetreten mit offenen
Forderungen, Mandat noch aktiv (Hinweis) · Mahnstufe an Vorstand eskaliert · Freigabe fällig
/ Einreichung überfällig · aggregiert: Überweiser-Forderungen überfällig / Rücklastschrift
ohne Wiedereinzug / Forderungen nach Widerruf offen · NC-Konto gelöscht, Mailadresse
übernommen ([T17](../tickets/T17-mitgliederverwaltung-light.md)).

---

## 8. Compliance-Anhang

Quelle: [T03](../tickets/T03-research-sepa-regelwerk.md), Details mit Primärquellen-Zitaten
in [research/T03-sepa-regelwerk.md](../research/T03-sepa-regelwerk.md). Geprüft gegen EPC
2025 SDD Core Rulebook v1.1, C2PSP-IG 2025 (pain.008.001.08), EPC-Clarification-Paper v4.0,
Bundesbank-Verfahrensregeln, DK-Anlage 3 V26.11, DK-Inkassobedingungen.

| Regel | Vorgabe | Umsetzung im Modul |
|---|---|---|
| IBAN-/Kontowechsel | Amendment `AmdmntInd=true` + `OrgnlDbtrAcct=SMNDA`, DK empfiehlt SMNDA für **jeden** Kontowechsel (gleiche wie fremde Bank), `OrgnlDbtrAgt` leer | `MandateAmendment(type: account)`, §2.2 |
| Sequenztyp nach Amendment | keine Einschränkung, Serie einfach mit RCUR fortsetzen | immer `RCUR`, §2.2 |
| FRST/RCUR | FRST seit 11/2016 optional, erste Lastschrift darf RCUR sein, keine AG02-Ablehnung | nie FRST verwendet |
| Vorlagefrist | D-1 bis D-14, einheitlich für alle Sequenztypen | Vorlauf-Puffer konfigurierbar (Default 5 Tage), §3.5 |
| Mandatsverfall | 36 Monate ab Due Date der letzten vorgelegten (auch gescheiterten) Lastschrift, reine Gläubigerpflicht | Cron-Übergang zu `ended`/`expired`, §2.2 |
| Pre-Notification | Default 14 Kalendertage, formfrei (E-Mail zulässig), Pflichtinhalt nur Betrag + Fälligkeitsdatum, vertraglich verkürzbar | Vorabinfo-Mail, 14 Tage Default, konfigurierbar, §3.11 |
| Mandats-Pflichttext | Überschrift „SEPA-Lastschriftmandat", DK-Mustertext (Ermächtigung + Weisung + 8-Wochen-Hinweis), Mandatsreferenz/Name/IBAN/Gläubiger-ID/Zahlungsart/Datum/Unterschrift | `MandateLegalTextVersion` Pflichtblock, §3.11 |
| Aufbewahrung | ≥14 Monate nach Erlöschen (DE-Praxis), solange Mandat besteht ohnehin Pflicht | nie hart löschen, nur `ended`/`redacted_at` — dominiert von der 10-Jahres-DSGVO-Uhr (§3.8) |
| Rückgabefristen | Return D+5, Refund autorisiert 8 Wochen, Refund unautorisiert 13 Monate | „endgültig vereinnahmt" ab ~14 Monaten (konservativ) |
| Elektronisches Mandat ohne QES | technisch verarbeitbar, aber volles Beweisrisiko beim Gläubiger (13-Monats-Fenster) | Beweispaket (Version, Zeitstempel, IP, User-Agent, Identität) an jedem `electronic`-Mandat, §2.2 |

**DSGVO-Löschkonzept:** siehe [§3.8](#38-dsgvo-detailkonzept--t30). Vollständige Herleitung
und Reichweite in [T30](../tickets/T30-dsgvo-detailkonzept.md).

---

## 9. Banking-API-Anhang — [T15](../tickets/T15-research-banking-api-monetarisierung.md)

Sondierung, kein Umsetzungsbeschluss. Details mit Quellen:
[research/T15-banking-api.md](../research/T15-banking-api.md).

- **PSD2/XS2A-Zahlungsauslösung kann keine SEPA-Lastschriften einreichen** — SDD-Einreichung
  läuft nur über EBICS/FinTS/Datei-Upload (Bank-Direktweg) oder ein Zahlungsinstitut als
  Full-Service (GoCardless: ~1 % + 0,20 €/Einzug, ohne Grundgebühr).
- **Self-Hosting-kompatibel** sind nur der Direktweg (FinTS via `phpFinTS`, HKDME-
  Sammellastschrift + Umsatzabruf; EBICS — keine TPP-Lizenz nötig, nur kostenlose
  DK-Produktregistrierung) und GoCardless per Bring-your-own-Account.
- Aggregatoren (finAPI, Enable Banking) setzen den App-Anbieter als zentralen
  Vertragspartner/Proxy voraus — konträr zum Self-Hosting-Versprechen.
- Ein Transaktionsaufschlag trägt bei Vereinsgrößen (4–12 Einzüge/Jahr) wirtschaftlich
  nicht (Überschlag: ~120 €/Verein/Jahr Aufschlagserlös vs. dreistellige monatliche
  Aggregator-Grundgebühren).
- Nextcloud-App-Store kennt keine Bezahl-Apps, nur Spenden-Links; erprobtes Vorbild für
  Lizenzierung ist Sendent (freie App + Jahreslizenz-Key).
- **Empfehlung:** Vertiefung nur als optionales FinTS/EBICS-Komfort-Feature plus GoCardless-
  BYO; Monetarisierung — falls überhaupt — vollständig vom Zahlungsstrom entkoppelt
  (Lizenz-Key nach Sendent-Vorbild, Spenden-Links). **Seit T26 endgültig aus dem Scope
  dieser Spec**, s. §11.

---

## 10. Out-of-scope

Aus der Map, alle Begründungen dort verlinkt:

- **Familien-/Sammelmandate** (ein Zahler für mehrere Mitglieder) — Member-Modell verbaut die
  spätere Öffnung nicht, spezifiziert sie aber nicht.
- **Banking-API-/Monetarisierungs-Umsetzung** — nur Sondierung (§9); Lizenz-Key-Modell in
  einer fremden AGPL-App ist tot.
- **Eigene Store-App sepa_manager** — aufgegeben zugunsten des vollständigen Aufgehens in
  nc_vereinsbuchhaltung ([T26](../tickets/T26-recharting-nc-vereinsbuchhaltung.md)).
- **EBICS/automatische Bank-Einreichung** — Einreichung bleibt in v1 manuell.
- **Self-Service ohne Nextcloud-Konto** (Token-Portal/Einmal-Links) — v1 nutzt Stellvertretung
  durch den Kassenwart; wäre eine unauthentifizierte, öffentlich erreichbare Fläche einer
  Bankdaten-App. Rein additiv nachrüstbar. Der T08-Einmal-Link für elektronische
  Mandatserteilung bleibt unberührt (in Scope).
- **Automatischer Wiedereinzug nach Rücklastschrift** — v1 hat keinen Automatismus, der
  zweimal Geld anfasst; eine Forderung hat höchstens einen Einzugsposten. Nachrüsten wäre
  kein additiver Schritt.
- **Papier-Vorabinfo/manueller Zustellvermerk** — genau ein Zustellweg (Mail); E-Mail ist
  Pflichtvoraussetzung für Lastschrift.
- **Eigener TARGET-Geschäftstagskalender** — die Bank rechnet die Verschiebung.
- **Nur-Lese-Self-Service** — kein artikulierter Bedarf, additiv nachrüstbar
  (`self_service_enabled`: Bool → Enum).
- **Jahres-Vorabinfo** — bräuchte eine zweite, entkoppelte Sperrsemantik.

---

## 11. Glossar-Merge

Vollständiges Fachglossar mit Herleitung, Avoid-Listen und Begründungen:
[CONTEXT.md](../../CONTEXT.md) (26 Begriffe). Unten die Kollisionen mit dem Begriffs-
Inventar von nc_vereinsbuchhaltung — Ergebnis wandert mit dieser Spec upstream.

| Unser Begriff | Upstream-Begriff | Auflösung |
|---|---|---|
| Forderung (`Claim`) | „Offener Posten" (`vbh_open_items`, `OpenItem`) | **Technisch identisch** — die Forderung wird auf `vbh_open_items` abgebildet, keine eigene Tabelle ([T27](../tickets/T27-kontierungs-detail.md)). Fachlich getrennt gehalten: „Forderung" ist der UI-/Spec-Begriff für Beitrags-/Gebühren-Posten mit `member_id`, „Offener Posten" bleibt der generische Oberbegriff (auch Nicht-Mitglieder-Posten wie Handwerkerrechnungen). Zwei gefilterte Einstiege auf denselben Datenbestand ([T28](../tickets/T28-gesamt-ux.md)): Einzug-Unterreiter zeigt nur `member_id`-Zeilen, Buchungen → Offene Posten bleibt die vollständige generische Sicht. |
| Lastschriftlauf (`Debit Batch`) | „Batch" (`vbh_sepa_batches`) | Ersetzt — die bestehende Tabelle wird zur neuen Statusmaschine umgebaut (§2.2, §4). |
| Buchung/Journal | `JournalService`/`JournalLine`/`vbh_journal` | Keine Kollision, vollständig wiederverwendet (§3.10) — das Modul führt kein eigenes Buchungssystem. |
| Mitglied (`Member`) | kein Äquivalent (`member_uid`/`member_label`-Freitext) | Neue Entity, kippt die dokumentierte Upstream-Position „keine Mitgliederverwaltung" (`NAVIGATION-KONZEPT.md` D1) offiziell — von AndiMb approved. |
| Buchhaltungsrolle | `none`/`revisor`/`buchhalter`/`verwalter` | Unverändert übernommen, keine neue Rollenstufe (§3.9). |

**Konvention „das Gefäß gewinnt"** ([T26](../tickets/T26-recharting-nc-vereinsbuchhaltung.md)):
deutsche Kommentare/Commits, deutsche UI-Quellsprache, Enum-Muster wie im Bestand — s. dazu
den offenen Klärungsbedarf in [§13.1](#131-enum-sprache-vs-contextmd).

---

## 12. Umbaupfad — Zusammenfassung

| Bereich | Umbauhärte | Kern des Umbaus |
|---|---|---|
| Mitglieder | hart, keine Produktivdaten | `member_uid`/`member_label` → `Member`-Entity, Split-Migration nach Leerzeichen-Heuristik |
| Mandate | hart | `vbh_sepa_mandates` bekommt vollen Lifecycle, Amendment-/Event-Tabellen |
| Beiträge | hart | `vbh_membership_fees` → `ContributionGroup` + `Assignment` |
| Einzug/Läufe | hart | `vbh_sepa_batches` → neue Statusmaschine mit Freigabe-Gate |
| Offene Posten/Forderungen | **additiv** | `vbh_open_items` bekommt neue Spalten, keine Kern-Buchhaltungsdaten ändern sich |
| Bankimport | **additiv** | neue Nebentabelle `vbh_bank_tx_sepa_details`, `RowNormalizer`-Hash bleibt stabil |
| Kontierung | **additiv** | bestehender `assignParts()`/`doAssign()`-Pfad wiederverwendet, keine neue Buchungslogik |
| Rollen | **unverändert** | bestehendes Modell 1:1 übernommen |

Grund für die Zweiteilung: Das Beiträge/SEPA-Modul hat keine Produktivnutzer (harter Umbau
erlaubt), die Kern-Buchhaltung (Journal, Konten, `vbh_bank_tx`, geteilte Teile von
`vbh_open_items`) hat echte Nutzer (nur additive Migrationen,
[T26](../tickets/T26-recharting-nc-vereinsbuchhaltung.md) Pkt. 4).

---

## 13. Konsistenz-Befunde

Beim Zusammenführen aufgefallene Widersprüche zwischen Ticket-Resolutionen — zur Klärung mit
Florian vorgelegt statt still aufgelöst.

### 13.1 Enum-Sprache vs. CONTEXT.md

**Befund:** [T08](../tickets/T08-mandats-lifecycle.md) hat am 2026-08-10 projektweit
festgelegt: „Im Code ist alles Englisch — inklusive Enum-Werten" (`paper`/`electronic`,
nicht `papier`/`elektronisch`). Dieser Satz steht wortgleich noch im „Conventions"-Abschnitt
von [CONTEXT.md](../../CONTEXT.md). [T26](../tickets/T26-recharting-nc-vereinsbuchhaltung.md)
hat diese Regel am 2026-08-30 explizit **aufgehoben**: „die alte Map-Note ‚im Code alles
Englisch' ist aufgehoben" — Enum-Werte sollen stattdessen dem Bestandsmuster folgen (deutsch
wo Fachbegriff wie `verwalter`/`ideell`, englisch wo Technik wie `RCUR`/`pending`). Die
Map-Notes wurden entsprechend aktualisiert, **CONTEXT.md nicht**.

**Betroffen sind alle vor dem 2026-08-30 geprägten Enum-Werte:**
`member_type: person|organization` ([T17](../tickets/T17-mitgliederverwaltung-light.md),
selbes Datum wie T26 — Reihenfolge unklar), `payment_method: direct_debit|transfer`
([T10](../tickets/T10-einzugszyklus-choreografie.md), 2026-08-12), `signature_type:
paper|electronic|qes`, `status: draft|active|suspended|ended`, `end_reason: revoked|
replaced|expired|terminated`, `suspension_origin: manual|returned_debit` (alle
[T08](../tickets/T08-mandats-lifecycle.md), 2026-08-10), `type: contribution|fee`
([T02](../tickets/T02-domaenenmodell-kern.md)/[T08](../tickets/T08-mandats-lifecycle.md)),
`settlement_type: paid|waived` ([T11](../tickets/T11-ruecklastschrift-fachlogik.md),
2026-08-30, ebenfalls unklare Reihenfolge zu T26).

**Entscheidung (Florian, Review-Durchgang 2026-09-16):** Eindeutschung erfolgt **beim
Schreiben der Einzelissues** (`/to-tickets`), nicht rückwirkend an dieser Spec oder den
bereits geschlossenen Tickets. Diese Spec behält daher bewusst die **englischen Werte aus
den Tickets** (§2, durchgängig) als historischen Stand bei — die Einzelissues germanisieren
nach Bestandsmuster (deutsch wo Fachbegriff, z. B. `person`/`organisation`,
`direct_debit`/`ueberweisung`, `draft`/`entwurf`/`aktiv`/`ausgesetzt`/`erloschen`; englisch
wo Technik). `CONTEXT.md`s „Conventions"-Abschnitt ist bereits entsprechend korrigiert.

### 13.2 Rücklastschrift-Quelle in CONTEXT.md veraltet

**Befund:** [CONTEXT.md](../../CONTEXT.md) beschreibt die Rücklastschrift-Quelle noch als
dreiwertig (`Integration | Import | manuell`) — Stand [T02](../tickets/T02-domaenenmodell-kern.md).
[T12](../tickets/T12-quellen-abstraktion-fallback.md)/[T26](../tickets/T26-recharting-nc-vereinsbuchhaltung.md)
haben das auf zweiwertig geschrumpft (`import | manual`) — „Integration" entfällt
ersatzlos, weil es keine externe Partner-App mehr gibt. Diese Spec (§2.2) verwendet
durchgängig den aktuellen, zweiwertigen Stand. **`CONTEXT.md` ist bereits korrigiert**
(Review-Durchgang 2026-09-16).

### 13.3 Keine inhaltlichen Widersprüche zwischen Fachtickets gefunden

Die übrigen 22 Tickets sind untereinander konsistent — spätere Tickets ändern frühere
explizit und mit Begründung (z. B. T17 ändert T08 „Aktivierungs-Gate", T10 ändert T02/T07
„Statusmaschinen", T19 präzisiert T07/T11 „Admin" → „`buchhalter`"), diese Spec übernimmt
jeweils den letzten, aktuellen Stand. Die einzigen offenen Punkte sind die beiden oben
genannten Sprachfragen — kein Fachwiderspruch im Domänenmodell selbst.

---

## 14. Nächste Schritte

Gemäß [T18](../tickets/T18-spec-assemblieren.md) und
[T26](../tickets/T26-recharting-nc-vereinsbuchhaltung.md):

1. **Review-Durchgang mit Florian** (dieses Dokument) — insbesondere §13 klären.
2. CONTEXT.md gemäß §13 korrigieren, Glossar-Ergebnis (§11) fixieren.
3. **Ein Scoping-Issue** im Upstream-Repo
   [AndiMb/nc_vereinsbuchhaltung](https://github.com/AndiMb/nc_vereinsbuchhaltung), das diese
   Spec vorstellt.
4. `/to-tickets` für die Einzelissues im Upstream-Repo, entlang der Bereiche aus §3/§12.
5. Diese Datei nach `docs/` im Upstream-Repo übergeben.
