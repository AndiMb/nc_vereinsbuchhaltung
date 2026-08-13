# Prüfschema für den SEPA-Export

`pain.008.001.02.xsd` ist das **Kunde-Bank-Schema der Deutschen
Kreditwirtschaft** für die SEPA-Lastschrift (DFÜ-Abkommen Anlage 3,
Version 3.0, gültig ab November 2016; setzt EPC SDD Core IG 9.0 um).
Namensraum `urn:iso:std:iso:20022:tech:xsd:pain.008.001.02`.

Bezogen aus dem Hibiscus-/HBCI4Java-Projekt von Olaf Willuhn:
<https://github.com/willuhn/hbci4java/blob/master/src/pain.008.001.02.xsd>

Verwendet wird es ausschließlich von `tests/unit/PainXmlBuilderTest.php`, um
die erzeugte Einreichungsdatei gegen dieselbe Vorlage zu prüfen, gegen die
auch die Bank prüft. Das Verzeichnis `tests/` ist im Release-Tarball
ausgeschlossen (siehe `.github/workflows/release.yml`) – die ausgelieferte App
enthält das Schema also nicht.

**Nicht abgedeckt:** Manche Institute erwarten inzwischen `pain.008.001.08`
oder `.09`. Wer darauf umstellt, braucht ein zweites Schema hier und einen
zweiten Erzeuger – siehe Klassenkommentar in `PainXmlBuilder`.
