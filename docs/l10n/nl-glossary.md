# Glossary German → English → Dutch (nl) for Vereinsbuchhaltung

`l10n/nl.json` (Issue #279) was created from the German source texts and the
English translation using this glossary. Please keep new translations in line
with it, and change the glossary together with `nl.json` when a term is improved.
`npm run l10n:check` lists missing Dutch entries, with the English text as template.

Context-dependent choices made during the first translation:
- *Freigabe*: "vrijgave" for SEPA collection runs, "goedkeuring" for expense claims.
- *Stornieren*: "annuleren" for claims, "crediteren" / "creditnota" for invoices.
- *Festschreiben* (invoices): "definitief maken".
- *Auswertung* (report tab): "Analyse"; *Auswertungsgruppe*: "rapportagegroep";
  *Plan-Stand*: "begrotingsmoment".
- Column headings and values of the spreadsheet/CSV importers stay German/English,
  because the importers only recognise those.

Use these terms consistently. Where a concept is specific to German law, keep the
German term in brackets the first time it appears in a longer explanatory text
(e.g. "giftbevestiging (Zuwendungsbestätigung)" — but NOT in short labels/buttons).

## Tone
- Address the user formally with **"u" / "uw"** (source German is formal "Sie").
- Buttons and menu items: short infinitive, like Nextcloud's Dutch UI
  ("Opslaan", "Annuleren", "Sluiten", "Verwijderen", "Bewerken", "Terug",
  "Toevoegen", "Uploaden", "Downloaden", "Overnemen", "Toepassen").
- Instructions in sentences: imperative with "u" sense ("Kies een rekening", "Vul ... in").
- Use Dutch number/date wording neutrally; never change placeholders.

## Core accounting
| German | English | Dutch |
|---|---|---|
| Buchung | booking / entry | boeking |
| buchen | book / post | boeken |
| Buchungstext | booking text | boekingsomschrijving |
| Beleg | receipt / voucher | bewijsstuk (plural: bewijsstukken) |
| Belegordner / Wächter-Ordner | receipt folder / watch folder | map met bewijsstukken / bewaakte map |
| Konto (Sachkonto) | account | rekening (grootboekrekening where needed) |
| Geldkonto (Bank/Kasse) | cash/bank account | geldrekening (bank/kas) |
| Kasse | cash box | kas |
| Gegenkonto | contra account | tegenrekening |
| Kontenrahmen / Kontenplan | chart of accounts | rekeningschema |
| Soll / Haben | debit / credit | debet / credit |
| Saldo | balance | saldo |
| Eröffnungssaldo / Anfangsbestand | opening balance | beginsaldo |
| Journal | journal | journaal |
| Geschäftsjahr | financial year | boekjaar |
| Zeitraum / Periode | period | periode |
| abschließen / Abschluss | close / closing | afsluiten / afsluiting |
| Jahresabschluss | year-end closing | jaarafsluiting |
| gesperrt / abgeschlossen | locked / closed | vergrendeld / afgesloten |
| Kassenbericht | cash report | kasverslag |
| Kassenprüfung / Kassenprüfer | cash audit / auditors | kascontrole / kascommissie |
| Einnahmen / Ausgaben | income / expenses | inkomsten / uitgaven |
| Überschuss / Fehlbetrag | surplus / deficit | overschot / tekort |
| Finanzplan / Haushaltsplan | financial plan / budget | begroting |
| Kostenstelle | cost centre | kostenplaats |
| Gliederung (Bericht) | breakdown / structure | indeling |
| Umbuchung | transfer entry | overboeking (intern) / correctieboeking |
| Storno / stornieren | reversal / reverse | tegenboeking / terugdraaien |
| Rücklage | reserve | reserve |
| Umsatzsteuer | VAT | btw |
| Finanzamt | tax office | belastingdienst (Finanzamt) |
| Gemeinnützigkeit / gemeinnützig | non-profit status / non-profit | algemeen nut / algemeen nut beogend |
| ideeller Bereich | non-material sphere | ideële sfeer |
| Vermögensverwaltung | asset management | vermogensbeheer |
| Zweckbetrieb | purpose-related business | doelonderneming (Zweckbetrieb) |
| wirtschaftlicher Geschäftsbetrieb | commercial operations | economische bedrijfsactiviteit |
| Spende | donation | gift |
| Zuwendungsbestätigung | donation receipt | giftbevestiging |
| Auslagenerstattung / Erstattung | reimbursement | onkostendeclaratie / vergoeding |

## Members, contributions, SEPA
| German | English | Dutch |
|---|---|---|
| Verein | club / association | vereniging |
| Mitglied / Mitglieder | member(s) | lid / leden |
| Vorstand | board | bestuur |
| Kassenwart / Schatzmeister | treasurer | penningmeester |
| Buchhalter | bookkeeper | boekhouder |
| Beitrag / Mitgliedsbeitrag | contribution / membership fee | contributie |
| Beitragsgruppe | contribution group | contributiegroep |
| Zuordnung | assignment | toewijzing |
| Fälligkeit / fällig | due date / due | vervaldatum / verschuldigd |
| Forderung | claim / receivable | vordering |
| Lastschrift | direct debit | incasso |
| Einzug / einziehen | collection / collect | incasso / incasseren |
| Einzugslauf | collection run | incassorun |
| Mandat / Mandatsreferenz | mandate / mandate reference | machtiging / machtigingskenmerk |
| Gläubiger-ID | creditor ID | incassant-ID |
| Vorabinformation (Pre-Notification) | pre-notification | vooraankondiging |
| Rücklastschrift | returned direct debit | gestorneerde incasso / storno |
| Freigabe / freigeben | release / approve | vrijgave / vrijgeven |
| Mahnung / Mahnwesen / Mahnstufe | dunning / reminder / dunning level | aanmaning / aanmaningen / aanmaningsniveau |
| Zahlungserinnerung | payment reminder | betalingsherinnering |
| Überweisung | bank transfer | overschrijving |
| Bankumsatz | bank transaction | banktransactie |
| Kontoauszug | bank statement | bankafschrift |
| Abgleich (Bankabgleich) | reconciliation | afstemming (bankafstemming) |
| verbuchen (Bankumsatz) | settle / post | verwerken / boeken |
| Auszahlung | payout | uitbetaling |
| Selbstauskunft / Self-Service | self-service | selfservice |
| GiroCode | GiroCode | GiroCode (EPC-QR) |
| IBAN, BIC, SEPA, XML, CSV, PDF, CAMT, MT940 | — | unchanged |

## Invoices
| German | English | Dutch |
|---|---|---|
| Rechnung | invoice | factuur |
| Rechnungsnummer | invoice number | factuurnummer |
| Position | line item | regel / factuurregel |
| E-Rechnung | e-invoice | e-factuur |
| ZUGFeRD / XRechnung | unchanged | unchanged |
| Empfänger | recipient | ontvanger |
| Gutschrift | credit note | creditnota |
| Kunde / Debitor | customer | klant / debiteur |

## Nextcloud / UI
| English | Dutch |
|---|---|
| Settings | Instellingen |
| Save | Opslaan |
| Cancel | Annuleren |
| Close | Sluiten |
| Delete | Verwijderen |
| Edit | Bewerken |
| Add | Toevoegen |
| Search | Zoeken |
| Preview | Voorbeeld |
| Import / Export | Importeren / Exporteren |
| Dashboard | Dashboard |
| Help | Help |
| Tab (Reiter) | tabblad |
| Use / Apply (Übernehmen) | Overnemen |
| Print | Afdrukken |
| Upload | Uploaden |
| Download | Downloaden |
| Filter | Filter |
| Folder | Map |
| File | Bestand |
