# Auslagenerstattung – Design

Stand: 2026-11-01. Ergebnis der Klärungsrunden (Grilling) vom 2026-10-31/11-01.
Status: **zur Bestätigung**, noch nichts implementiert.

## 1. Ziel

Mitglieder reichen Auslagen (privat verauslagte Vereinsausgaben) mit Beleg ein.
Verwalter geben sie frei – mindestens N verschiedene Verwalter, N einstellbar.
Erst die letzte nötige Freigabe erzeugt einen offenen Posten „zu überweisen an
Mitglied“. Buchhalter/Verwalter überweisen manuell und buchen die Auszahlung.
Jeder Schritt steht revisionssicher in einer Historie am Antrag.

Nicht Teil von v1: SEPA-Überweisungsdatei (pain.001), Verbindlichkeitskonto,
Höchstbetrag, Frist, Rückfrage-/Nachbesserungsschleife, Prüfroutine für die
Hash-Kette, Nextcloud-Benachrichtigungen, Erinnerungsmails.

## 2. Einstellungen (Admin, neben den übrigen Schaltern)

| Schlüssel | Bedeutung |
| --- | --- |
| `reimbursement_enabled` | Funktion aktiv/inaktiv (Standard: inaktiv) |
| `reimbursement_required_approvals` | N, ganze Zahl ≥ 1, Standard 2 |

Validierung beim Speichern: Es müssen mindestens **N+1 Verwalter** existieren
(Rolle `verwalter` per Nutzer oder Gruppe, plus Nextcloud-Admins), weil
Einreicher ihre eigene Auslage nicht freigeben dürfen (§4).

Ausschalten sperrt nur neue Einreichungen. Bestehende Anträge bleiben für
Verwalter, Buchhalter und Revisor sichtbar und bearbeitbar; Mitglieder behalten
Lesezugriff auf ihre eigenen Anträge. Der Mitglieder-Unterreiter „Auslagen“
verschwindet erst, wenn das Mitglied keine Anträge hat.

Ändert sich N später, gilt für laufende Anträge weiter das **bei Einreichung
gespeicherte** N (Snapshot am Antrag).

Hinweis: Schrumpft der Verwalter-Pool unter das nötige Maß, können laufende
Anträge hängen bleiben. Die Verwaltersicht weist darauf hin
(„Antrag nicht mehr freigebbar“).

## 3. Rollen und Rechte

| Aktion | Mitglied (verknüpft) | Revisor | Buchhalter | Verwalter |
| --- | --- | --- | --- | --- |
| Eigene Anträge sehen / einreichen / zurückziehen | ja | – | – | ja (als Mitglied) |
| Alle Anträge + Historie lesen | – | ja | ja | ja |
| Freigeben / Ablehnen | – | – | – | ja (nicht den eigenen Antrag) |
| Auszahlung buchen | – | – | ja | ja |
| IBAN | eigene | maskiert | ja | ja |

Mitgliederzugang wie Self-Service: Kontoverknüpfung nötig, aber **eigener
Schalter**, nicht abhängig von `self_service_enabled`. Alle Mitglieder-
Endpunkte prüfen strikt, dass nur eigene Anträge erreichbar sind (IDOR-Schutz,
Muster `SelfServiceMandateService`). Staff-Endpunkte tragen `#[RequiresRole]`.

## 4. Antrag und Ablauf

Felder: Betrag, Ausgabedatum (nicht in der Zukunft), Verwendungszweck,
mindestens eine Belegdatei (PDF/Bild, Grenzen der bestehenden Anhänge),
Erstattungs-IBAN (Pflicht, IBAN-Prüfung, verschlüsselt gespeichert, vorbelegt
aus den Mitgliedsdaten, je Antrag änderbar). Ein Antrag = ein Betrag. Keine
Kontierung durch das Mitglied. Beliebig viele offene Anträge pro Mitglied.

Status (englisch in DB und Code):

```
submitted ─► partially_approved ─► approved ─► paid
    │               │
    ├─► rejected ◄──┘        (jeder Verwalter, Pflichtbegründung, sofort final)
    └─► withdrawn            (Mitglied, nur solange keine Freigabe vorliegt)
```

- Jeder Verwalter zählt höchstens einmal; der Einreicher ist ausgeschlossen.
- Freigabe/Ablehnung laufen in einer Transaktion mit Zeilensperre auf dem
  Antrag (kein Doppelzählen, kein Überfahren der Schwelle durch Parallelität).
- Bei der Freigabe, die N erreicht: Status `approved` und offener Posten
  entstehen in derselben Transaktion.
- Nach `rejected`/`withdrawn`/`paid` ist der Antrag endgültig; neuer Bedarf =
  neuer Antrag.

## 5. Historie (revisionssicher)

Tabelle `vbh_reimbursement_events`, **nur anfügen** (kein Update/Delete im
Code), Muster `MandateEvent`:

`id, reimbursement_id, seq (unique je Antrag), action, actor_uid, actor_role,
created_at, comment, data (JSON), prev_hash, hash`

`hash = SHA-256(kanonisches JSON aus reimbursement_id, seq, action, actor_uid,
created_at, comment, data, prev_hash)`. Aktionen: `submitted`, `approved`,
`rejected`, `withdrawn`, `paid`, `receipt_added`, `receipt_removed`,
`duplicate_hint`. `approved` hält die Zählung fest („2 von 3“). Zusätzlich
schreibt der bestehende Audit-Log. Eine Prüfroutine gibt es in v1 nicht.

Sichtbarkeit: Mitglieder sehen Status mit Fortschritt („1 von 2 Freigaben“),
Zeitstempel, Ablehnungsbegründung und Auszahlungstermin, **nicht** die Namen
der Freigeber („Freigabe durch einen Verwalter“). Verwalter, Buchhalter und
Revisor sehen die volle Historie mit Namen.

## 6. Offener Posten

`vbh_open_items` bekommt additiv `reimbursement_id` (Verbindlichkeit gegenüber
dem Mitglied; wie bei Rechnungen über `invoice_id` gekennzeichnet, ohne `type`). Der Posten steht in
*Buchungen → Offene Posten*, eigens gekennzeichnet („Auslage, zu zahlen an …“).

- Er zählt nicht als Forderung: nicht bei „überfällige Forderungen“, nicht in
  `openClaimsTotalCents`, nicht im Einzug-Reiter. `memberId` und `type`
  bleiben NULL, damit der Posten nicht als Beitragsforderung gilt.
- „Bezahlt“ heißt hier „überwiesen“ und verlangt ein Datum.
- Eine Auslage lässt sich nur über den Antrag erledigen, nicht über die
  generischen `pay/cancel/reopen/destroy`-Endpunkte (`assertNotClaim`-Muster).

## 7. Auszahlung und Buchung

Gebucht wird **erst bei der Auszahlung**. Buchhalter oder Verwalter öffnen den
normalen Buchungsdialog, vorbelegt mit Betrag, Datum und Zweck. Aufwandskonto,
Bankkonto und Kostenstelle wählt der Buchende; die normalen Perioden-/
Abschlussregeln gelten. Die Buchung wird mit dem offenen Posten verknüpft, der
Antrag wird `paid`, die Belege hängen an der Buchung. Kein Verbindlichkeitskonto.

## 8. Belege / Ablage

Mitglieder laden nur hoch; sie sehen nie den Wächter-Ordner und haben kein
„Aus Ordner auswählen“. Tabelle `vbh_reimbursement_files` (Name, MIME, Größe,
`file_id`, `file_owner`, Inhalts-Hash, Zeitpunkt). Die Ablage folgt der
bestehenden Einstellung:

- **Wächter-Ordner:** `<Ordner>/<Ausgabejahr>/`, wie bei allen Uploads. Im
  Posteingang „Zuzuordnen“ werden diese Dateien ausgeblendet bzw. als „gehört
  zu Antrag #n“ gekennzeichnet und lassen sich nicht manuell verknüpfen.
- **App-Daten / Buchungsordner:** Unterordner `reimbursements/<id>/`.
- **Bei der Auszahlung:** Es entsteht der normale `Attachment`-Eintrag an der
  Buchung. Im Wächter-Modus bleibt die Datei liegen und wird verknüpft; in den
  anderen Modi wird sie in die Struktur der Buchung verschoben.

Mitglieder sehen/löschen nur eigene Dateien und nur bis zur ersten Freigabe;
Löschen entfernt die Datei und steht in der Historie (`receipt_removed`).
Doppelte Belege (bestehender Hash, `ReceiptHashMapper`) erzeugen einen Hinweis
am Antrag und in der Historie (`duplicate_hint`), blockieren aber nichts.

## 9. Mails

Über `IMailer` mit `ClubMailBranding`, Sprache des Empfängers
(`RecipientL10n`), Versand nach dem Commit; ein Mailfehler rollt nichts zurück.

| Anlass | Empfänger |
| --- | --- |
| Neuer Antrag | alle Verwalter außer dem Einreicher |
| Endgültig freigegeben („stattgegeben“) | Mitglied |
| Abgelehnt (mit Begründung) | Mitglied |
| Ausgezahlt | Mitglied |

Teilfreigaben lösen keine Mail aus.

## 10. Oberfläche

- **Mitglieder:** Der Tab `self` heißt künftig **„Mein Bereich“** mit den
  Unterreitern **„Beitrag“** (bisheriger Inhalt, nur mit
  `self_service_enabled`) und **„Auslagen“** (nur mit
  `reimbursement_enabled`). Ist nur einer aktiv, entfällt die Unterreiter-
  Leiste. Routen: `/self` bleibt, neu `/self/reimbursements`. Handbuch, Hilfe,
  Übersetzungen auf den neuen Namen ziehen.
- **Verwaltung:** *Buchungen → Auslagen* (neben Offene Posten/Regeln): alle
  Anträge, Freigabe-Warteschlange, Auszahlung, Historie. Revisoren lesend.
  Eigene Anträge erscheinen mit Hinweis „nicht selbst freigebbar“.
- Einreichen gibt es nur im Mitglieder-Tab.

## 11. Sonstiges

- **Anonymisierung/Löschung:** Ein Mitglied mit nicht abgeschlossenen Anträgen
  (`submitted`, `partially_approved`, `approved`) lässt sich nicht
  anonymisieren (Grund wird angezeigt). Abgeschlossene Anträge bleiben
  (Aufbewahrung), personenbezogene Angaben werden über den bestehenden Prozess
  pseudonymisiert.
- **Benennung:** DB, Code, Status, Ereignisarten englisch (`reimbursement`),
  Oberfläche deutsch.
- **Dokumentation/Qualität:** Handbuch (DE/EN), Changelog, Versionssprung,
  PHPUnit für Statusmaschine/Rechte/Hash-Kette/Parallelität, Vitest für UI,
  Playwright-Pfad Einreichen → 2× Freigabe → Auszahlung.

## 12. Umsetzungsreihenfolge (Vorschlag)

1. Migration + Entities/Mapper + `ReimbursementService` (Statusmaschine,
   Events/Hash-Kette, Rechte) mit Tests.
2. Admin-Einstellungen (Schalter, N, Validierung).
3. Ablage (Upload, Posteingang-Ausblendung, Übernahme bei Auszahlung).
4. Offener Posten (Typ, Abgrenzung zu Forderungen) + Auszahlungsbuchung.
5. Mails.
6. UI Mitglieder („Mein Bereich → Auslagen“) und Verwaltung
   („Buchungen → Auslagen“).
7. Anonymisierung, Handbuch, Changelog, E2E.
