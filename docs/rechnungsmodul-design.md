# Rechnungsmodul: Design

Status: Konzept entschieden, Umsetzung in drei Stufen. Fortschritt im Epic-Issue auf GitHub.

## Ziel

Der Verein stellt jederzeit Rechnungen in seinem Namen aus. Jede Rechnung landet als offene Forderung im System und wird beim Bankimport automatisch zugeordnet. Die Rechnungen erfüllen die Pflichtangaben nach § 14 Abs. 4 UStG, später ZUGFeRD und XRechnung.

## Entscheidungen

| Thema | Entscheidung |
| --- | --- |
| Buchungslogik | Ist-Prinzip. Rechnung = Dokument + offener Posten. Gebucht wird erst beim zugeordneten Bankumsatz. Kein Forderungskonto. |
| Platzierung | Eigener, optional aktivierbarer Reiter „Rechnungen“. |
| Bankabgleich | In „Buchungen → Zuzuordnen“ als stärkste Vorschlagsart, nicht im Einzug-Bankabgleich. |
| PDF | Serverseitig mit TCPDF 6.x (LGPL-3.0-or-later, eigenständige Fassung mit Schriften; die 7er-Linie ist nur noch eine Hülle über `tc-lib-pdf`), PDF/A-3b, GiroCode als Vektorgrafik. Der Renderer steht hinter einer eigenen Klasse und lässt sich austauschen. |
| E-Rechnung | `horstoeko/invoicesuite` (CII und UBL, XRechnung, Peppol). Hinter einem eigenen Interface `EInvoiceWriter` gekapselt. |
| Teilzahlungen | Von Anfang an über `settled_cents`. |
| Nummernkreise | `RE-{JJJJ}-{NNNN}` für Rechnungen, `ST-{JJJJ}-{NNNN}` für Stornorechnungen. |
| Mindestversion | Nextcloud 33 und PHP 8.2 (NC 31/32 sind EOL, TCPDF und invoicesuite verlangen PHP 8.2). |
| Umsatzsteuer | Stufe 1 nur Kleinunternehmer und Befreiungstext. Regelbesteuerung in Stufe 2. Spalten `tax_category` und `tax_rate` gibt es ab Stufe 1. |

## Lizenz von mPDF (entschieden, siehe ADR-0002)

`invoicesuite` verlangt `mpdf/mpdf` (GPL-2.0-only) als Pflichtabhängigkeit, nutzt es aber nur im Visualizer. Die App steht unter AGPL-3.0-or-later, GPL-2.0-only ist damit nicht kombinierbar. Die App hält mPDF per `composer replace` aus dem Paket und lädt den Visualizer nie. Rückfall, falls das nicht tragen sollte: `horstoeko/zugferd` (MIT, nur CII). Begründung, Prüfung und Pflege stehen in [ADR-0002](adr/0002-invoicesuite-fuer-die-e-rechnung.md).

## Rechtliche Leitplanken

- Pflichtangaben nach § 14 Abs. 4 UStG: Name und Anschrift von Verein und Empfänger, Steuernummer oder USt-IdNr., Ausstellungsdatum, fortlaufende Rechnungsnummer, Leistungsbeschreibung, Leistungszeitpunkt oder -zeitraum, Entgelt, Steuersatz und Steuerbetrag oder Befreiungshinweis.
- Kleinunternehmer (§ 19 UStG): kein USt-Ausweis, Pflichthinweis. Grenzen 25.000 € Vorjahr und 100.000 € laufend.
- E-Rechnung: Empfang seit 1.1.2025 Pflicht. Ausstellen B2B ab 2027 (Vorjahresumsatz über 800.000 €) und ab 2028 für alle. Kleinunternehmer und B2C sind ausgenommen (§ 34a UStDV).
- Storno ist eine Stornorechnung (EN16931 Typ 381), nie „Gutschrift“. Verweis auf die Ursprungsrechnung in BT-25.
- Aufbewahrung 8 Jahre. Festgeschriebene Rechnungen sind unveränderlich (GoBD). Bei E-Rechnungen ist das XML das Original.
- Ein echter Mitgliedsbeitrag ist keine Rechnung im Sinne des UStG (nicht steuerbar, UStAE 1.4). Er bleibt im Beitragsmodul.

## Datenmodell

Alle Tabellen mit Präfix `vbh_`. Abweichend von der ersten Skizze speichert die Umsetzung Mengen, Rabatte und Steuersätze als Ganzzahlen, damit keine Rundungsfehler in die Summen kommen: `quantity_e4` (Zehntausendstel, 10000 = 1), `discount_bp` und `tax_rate_bp` (Basispunkte, 1900 = 19 %). Dazu kommt `vbh_invoice_payments`, die jede Zahlung mit Betrag, Buchungssatz und Aufteilung auf die Erlöskonten festhält (nötig, um Teilzahlungen exakt zurückzunehmen).

- `vbh_debtors`: id, kind (person/organisation/behoerde), name, name2, street, postal_code, city, country, email, vat_id, leitweg_id, customer_no, member_id (nullable), note, created_at, redacted_at.
- `vbh_invoices`: id, kind (rechnung/storno), status (draft/issued/sent/paid/cancelled), number (nullable bis Festschreiben), number_range_id, issue_date, delivery_date, period_start, period_end, due_date, debtor_id, debtor_snapshot (JSON), tax_mode (regular/kleinunternehmer/exempt), exemption_text, buyer_reference, order_reference, payment_terms, note_head, note_foot, net_cents, tax_cents, gross_cents, currency, preceding_invoice_id, open_item_id, created_by, issued_at, issued_by, sent_at, cancelled_at, cancel_reason.
- `vbh_invoice_lines`: id, invoice_id, position, name, description, quantity (4 Dezimalstellen), unit_code (UN/ECE Rec 20), unit_price_cents, discount_percent, tax_category (S/E/AE/Z), tax_rate, tax_exemption_text, line_net_cents, account_id, cost_center_id, delivery_date.
- `vbh_invoice_documents`: id, invoice_id, kind (pdf/zugferd_pdf/xrechnung_cii/xrechnung_ubl), file_id, file_name, sha256, profile, generated_at. Dateien liegen in Nextcloud unter `Vereinsbuchhaltung/Rechnungen/<Jahr>/`.
- `vbh_number_ranges`: id, kind, prefix_pattern, padding, reset (yearly/never), next_no, last_issued_at. Vergabe in einer DB-Transaktion mit Sperre beim Festschreiben.
- `vbh_invoice_payments`: id, invoice_id, open_item_id, bank_tx_id (nullable, leer bei Barzahlung), journal_id, amount_cents, parts (JSON Konto-ID → Cent), created_at, created_by.
- `vbh_open_items` erweitert um invoice_id, reference (Rechnungsnummer, indexiert) und settled_cents.

Nach `issued_at` ist der Inhalt unveränderlich. Statuswechsel gehen über `AuditService`.

## Bankabgleich

Vorschlagsstufen für einen eingehenden Bankumsatz, stärkste zuerst:

1. Rechnungsnummer im Verwendungszweck (Regex aus dem Nummernkreis, normalisiert).
2. Optional ISO-11649-Referenz (Stufe 3).
3. Betrag plus Name des Zahlers passt zu genau einer offenen Rechnung.
4. Betrag passt zu genau einer offenen Rechnung.

Importregeln buchen heute schon beim Import. Die Rechnungsprüfung läuft deshalb davor, die Regel greift nur ohne Rechnungstreffer. Der Import bucht selbst, wenn die Rechnungsnummer im Verwendungszweck steht, genau eine Rechnung passt und der Betrag dem offenen Rest entspricht (abschaltbar mit `invoice_auto_assign`). Alles andere liefert `/api/invoices/suggestions` für „Zuzuordnen".

Eine Teilzahlung erhöht `settled_cents` und verteilt sich im Verhältnis des noch offenen Anteils auf die Erlöskonten der Positionen (größte Reste, kein Cent geht verloren); mit der letzten Zahlung stimmen die Summen je Konto exakt. Das Zahlungsbuch (`InvoicePaymentLedger`) nimmt eine Zahlung zurück, wenn ihr Buchungssatz verschwindet.

**Storno:** Die Stornorechnung trägt negative Mengen und Beträge, die Anschrift des Originals und einen eigenen Nummernkreis. Sie hat keinen offenen Posten. War die Rechnung schon bezahlt, bleibt die Zahlung gebucht; die Rückzahlung wird wie jede Auszahlung dem Erlöskonto zugeordnet, der Betrag steht als `refundDueCents` in der Stornorechnung. Für XRechnung (Stufe 2) kehrt der Writer die Vorzeichen für den Typ 381 wieder um.

## Rollen

- Revisor: lesen.
- Buchhalter: Entwürfe anlegen und bearbeiten.
- Verwalter: festschreiben, versenden, stornieren.

## Stufen

### Stufe 0: Voraussetzung
Mindestversion Nextcloud 33 und PHP 8.2.

### Stufe 1: Rechnung stellen
Debitoren, Editor, Entwurf, Festschreiben, Nummernkreise, Modus Kleinunternehmer mit optionalem Befreiungstext, PDF mit GiroCode, offener Posten mit Referenz und Teilzahlung, Bankabgleich in Zuzuordnen, Mailversand, Stornorechnung, Duplizieren.

### Stufe 2: E-Rechnung
Regelbesteuerung mit Steuersatz je Position und USt-Konto, ZUGFeRD EN 16931 als Hybrid-PDF, XRechnung in CII und UBL mit Leitweg-ID, Formatvorschlag nach Empfängertyp, XSD-Prüfung in der App, Validierung der Beispielrechnungen in der CI.

### Stufe 3: Nachhalten
Zahlungserinnerung und Mahnung für Rechnungen, Positionsvorlagen, wiederkehrende Rechnungen, RF-Referenz, Kassenprüfungs-ZIP mit Rechnungen und XML.

Umsetzung und Abweichungen vom Plan:

- **Mahnwesen** ist ein eigener `InvoiceDunningService` und kein verallgemeinerter `DunningLadderService`. Die Mahntreppe der Beiträge hängt an Mitgliedern, Forderungen, Lastschrift-Stufen und Mitglieder-Sprache; Rechnungen haben Empfänger mit E-Mail, Teilzahlungen und ein PDF. Beide teilen nur den GiroCode-Erzeuger. Stufen: Erinnerung, 1. und 2. Mahnung, Fristen und Texte in den Einstellungen. Der Hintergrundjob mahnt nur auf ausdrücklichen Wunsch. Mahngebühren und Verzugszinsen gibt es nicht.
- **Wiederkehrende Rechnungen:** Ein Plan speichert die Positionen als JSON in der Form des Editors. Angelegt und bearbeitet wird er im Rechnungseditor selbst (Art „wiederkehrend“ statt Rechnungsdaten und Festschreiben, kein Autosave); ein Reiter „Wiederkehrend“ in der Rechnungsliste zeigt die Pläne, das Rechnungsdetail bietet eine Abkürzung mit Vorbelegung. Termine rechnen vom Beginn aus, ein neuer oder fortgesetzter Plan holt nichts nach. Der Stundenjob legt Entwürfe an, auf Wunsch schreibt er fest, erzeugt PDF und E-Rechnung und versendet.
- **RF-Referenz:** aus der Rechnungsnummer abgeleitet (nur ASCII, höchstens 21 Zeichen), im PDF als zweite Zeile unter dem Verwendungszweck und im GiroCode als strukturierte Referenz (ersetzt dort den freien Text). Der Bankabgleich erkennt sie unabhängig von der Einstellung als stärkste Stufe.
- **Kassenprüfung:** Der Ordner `rechnungen/` im Beleg-ZIP, nach Rechnungsdatum gewählt, mit Übersicht (CSV) und Prüfsummenliste samt Befund (ok, changed, missing).
- **Reset:** Vorlagen, Pläne und Mahnungen fallen mit den Konten weg, Empfänger und Nummernkreise bleiben.

### Später
Beitragsrechnung als Dokumenttyp, Soll-Buchung mit Forderungskonto, Angebot und Auftragsbestätigung, Eingangsrechnungen lesen.
