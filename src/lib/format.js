// Zustandslose Formatier- und Label-Helfer, aus App.vue ausgelagert (Schritt 2
// der Modularisierung). Reine Funktionen ohne Vue-/DOM-Bezug: sie werden in
// App.vue unveraendert als Komponenten-Methoden eingebunden (das Template ruft
// formatMoney(...) etc. wie bisher auf) und sind hier unabhaengig testbar.

export function formatMoney(v) {
	return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v || 0)
}

export function formatDate(s) {
	if (!s) return ''
	const d = String(s).slice(0, 10)
	const m = d.match(/^(\d{4})-(\d{2})-(\d{2})$/)
	return m ? `${m[3]}.${m[2]}.${m[1]}` : d
}

export function formatDateTime(s) {
	return s ? String(s).replace('T', ' ').slice(0, 16) : ''
}

export function typeLabel(t) {
	return { income: 'Ertrag', expense: 'Aufwand', asset: 'Aktiv', liability: 'Passiv', equity: 'Eigenkapital' }[t] || t
}

export function roleLabel(r) {
	return { verwalter: 'Verwalter', buchhalter: 'Buchhalter', revisor: 'Revisor' }[r] || r
}

export function amountClass(v) {
	return v < 0 ? 'neg' : ''
}

export function budgetDiffClass(row) {
	if (!row.diff) return ''
	const good = row.type === 'income' ? row.diff > 0 : row.diff < 0
	return good ? 'good' : 'bad'
}

export function errMsg(e, fallback) {
	return (e && e.response && e.response.data && e.response.data.message) || fallback
}
