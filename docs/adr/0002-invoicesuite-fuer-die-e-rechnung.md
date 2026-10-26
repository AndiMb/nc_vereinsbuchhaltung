# ADR-0002: `horstoeko/invoicesuite` für die E-Rechnung, mPDF ausgeschlossen

**Status:** angenommen (2026-10-25, Rechnungsmodul Stufe 2, Epic #168)

## Kontext

Das Rechnungsmodul soll ZUGFeRD (EN 16931, Hybrid-PDF) und XRechnung (CII und
UBL) erzeugen. Dafür gibt es in PHP zwei brauchbare Bibliotheken von
horstoeko:

| Bibliothek | Lizenz | Formate | Stand |
|---|---|---|---|
| `horstoeko/zugferd` | MIT | nur CII (ZUGFeRD, XRechnung CII) | ausgereift, 1.x |
| `horstoeko/invoicesuite` | MIT | CII und UBL, XRechnung, Peppol, ZUGFeRD | aktuellere Linie, Version 0.0.x |

`invoicesuite` verlangt `mpdf/mpdf` (GPL-2.0-only) als Pflichtabhängigkeit.
Die App steht unter AGPL-3.0-or-later; GPL-2.0-only ist damit nicht
kombinierbar. mPDF nutzt `invoicesuite` nur im Visualizer (Rendern einer
Rechnung aus XML in ein PDF), den die App nicht verwendet: Das PDF erzeugt
der eigene Renderer mit TCPDF, `invoicesuite` bettet nur das XML ein.

## Entscheidung

1. Die App nutzt **`horstoeko/invoicesuite`**, exakt festgelegt auf eine
   Version (`composer.json`: `0.0.37`, kein Caret). Updates laufen bewusst,
   mit Blick auf die geprüften Beispielrechnungen.
2. **mPDF kommt nicht ins Paket.** `composer.json` führt
   `"replace": {"mpdf/mpdf": "*"}`; Composer installiert es damit nie. Kein
   Codepfad der App lädt den Visualizer. Ein Test (`InvoiceSuiteEInvoiceWriterTest`)
   erzeugt alle Formate, bettet XML in PDF ein und liest es zurück; er läuft
   ohne mPDF und fällt auf, sobald ein genutzter Pfad es braucht.
3. Alles, was die Bibliothek betrifft, steht hinter dem Interface
   `EInvoiceWriter` mit genau einer Implementierung
   (`InvoiceSuiteEInvoiceWriter`). Die Rechnungslogik kennt keine Klasse der
   Bibliothek.
4. Die Plattform wird auf `config.platform.php = 8.2.0` festgelegt, damit
   Composer auch mit einem neueren PHP auf dem Entwicklerrechner Pakete
   auflöst, die auf PHP 8.2 laufen (sonst käme Symfony 8).
5. Ein Upstream-Issue bei horstoeko bittet darum, mPDF von `require` nach
   `suggest` zu verschieben. Bis dahin gilt Punkt 2.

## Prüfung der Ergebnisse

- Unit-Tests prüfen jedes Format gegen das XSD der Bibliothek, und die App
  prüft jedes XML vor dem Speichern genauso; ein ungültiges Dokument wird
  nicht abgelegt.
- Der CI-Job „E-Rechnung (KoSIT-Validator)" erzeugt Beispielrechnungen (Rechnung,
  Kleinunternehmer, Storno in allen drei Formaten) und prüft sie mit dem
  offiziellen KoSIT-Validator samt XRechnung-Konfiguration 3.0.2 gegen die
  Schematron-Regeln von EN 16931 und XRechnung. Das fängt, was das Schema
  nicht fängt (zum Beispiel BR-CO-26, die Verkäuferkennung).

## Konsequenzen

- Die Bibliothek ist 0.0.x; Schnittstellen können sich ändern. Die Version
  bleibt fest, Updates prüft der CI-Job.
- Nextcloud sperrt libxml-Entitäten (`lib/base.php`). Der Writer erlaubt für
  die Dauer eines Bibliotheksaufrufs genau die Dateien unter dem Verzeichnis
  der Bibliothek, damit ihre mehrteiligen Schemas laden, und stellt die
  Sperre danach wieder her.
- Die Bibliothek legt einen Zwischenspeicher im eigenen Verzeichnis an, das in
  einer Nextcloud meist nicht beschreibbar ist. Der Writer verlegt ihn ins
  temporäre Verzeichnis von Nextcloud.
- Rückfall: Scheitert dieser Weg (Lizenz, Pflege), ersetzt eine zweite
  Implementierung des Interfaces auf Basis von `horstoeko/zugferd` die erste.
  UBL entfiele dabei; XRechnung und ZUGFeRD blieben über CII erhalten.
- Das Release-Paket enthält neben TCPDF nun `horstoeko/invoicesuite` mit den
  Abhängigkeiten, ohne mPDF.
