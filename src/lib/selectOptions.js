// Gemeinsame Helfer fuer NcSelect-Optionslisten.

/**
 * NcSelect bzw. vue-select werten das Flag $isDisabled nicht aus: Optionen mit
 * diesem Flag (die Gruppen-Ueberschriften der Konto-Autocompletes) liessen sich
 * anklicken und setzten dabei nur die Auswahl auf null. Als :selectable am
 * NcSelect gebunden, sperrt diese Funktion solche Eintraege wirklich - fuer Klick,
 * Tastatur-Zeiger und die Optik (vs__dropdown-option--disabled).
 *
 * @param {object|null} option Eintrag der Optionsliste
 * @return {boolean}
 */
export function isSelectableOption(option) {
	return !(option && option.$isDisabled)
}
