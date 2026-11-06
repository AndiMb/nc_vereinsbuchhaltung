// Gemeinsamer Vergleich fuer deutsche Texte (Namen, Kontonummern mit Ziffernanteil).
// Ein String.localeCompare mit Optionen legt je Aufruf einen neuen Collator an;
// beim Sortieren langer Listen ist das der Hauptteil der Zeit.
export const deCollator = new Intl.Collator('de', { sensitivity: 'base', numeric: true })
