/**
 * Hat sich gegenueber dem Schnappschuss nur das Geldkonto geaendert, und zwar
 * von leer auf gesetzt? Dann hat es die App nachgetragen (Standard-Geldkonto),
 * nicht der Mensch – und das Formular gilt nicht als "geaendert".
 *
 * Beide Argumente sind Ausgaben von BookingDialog.formState().
 *
 * @param {string} snapshot Schnappschuss beim Oeffnen
 * @param {string} current aktueller Formularstand
 * @return {boolean}
 */
export function onlyDefaultMoneyAccountAdded(snapshot, current) {
	const snap = JSON.parse(snapshot)
	const cur = JSON.parse(current)
	const isEmpty = (v) => v === null || v === undefined
	if (!isEmpty(snap.form.moneyAccountId) || isEmpty(cur.form.moneyAccountId)) {
		return false
	}
	const restored = { ...cur, form: { ...cur.form, moneyAccountId: snap.form.moneyAccountId } }
	return JSON.stringify(restored) === snapshot
}
