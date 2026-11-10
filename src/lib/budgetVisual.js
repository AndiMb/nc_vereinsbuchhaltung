// Darstellungshelfer für die visuelle Soll-Ist-Ansicht des Gliederungsberichts.
// Reine Funktionen auf den Berichtszeilen des Servers (kind, depth, nature,
// plan, actual in Cent, status.{year,time}.level).
import { t } from './l10n.js'

const clamp = (value, min, max) => Math.min(max, Math.max(min, value))

/**
 * Geometrie des Bullet-Balkens: die Spur ist das Soll.
 *
 * @param {number} plan Soll in Cent
 * @param {number} actual Ist in Cent
 * @param {number|null} timeFactor Zeitanteil 0–1 oder null
 * @return {{fill: number, marker: number|null, over: boolean}} Prozent der Spur
 */
export function bulletGeometry(plan, actual, timeFactor) {
	const p = Number.isFinite(plan) ? plan : 0
	const a = Number.isFinite(actual) ? actual : 0
	if (p <= 0) {
		const over = a > 0
		return { fill: over ? 100 : 0, marker: null, over }
	}
	const marker = Number.isFinite(timeFactor) && timeFactor > 0
		? clamp(timeFactor * 100, 0, 100)
		: null
	return { fill: clamp((a / p) * 100, 0, 100), marker, over: a > p }
}

/**
 * Symbol, Text und Ton einer Ampelstufe. Nie nur Farbe: Symbol und Text
 * gehören immer dazu.
 *
 * @param {string} level good|warn|crit|unplanned|none
 * @param {string} nature income|expense|result
 * @return {{icon: string, label: string, tone: string}} Meta
 */
export function statusMeta(level, nature) {
	const expense = nature === 'expense'
	const result = nature === 'result'
	switch (level) {
		case 'good':
			return {
				icon: '✓',
				label: expense ? t('im Rahmen') : t('im Plan'),
				tone: 'good',
			}
		case 'warn':
			return {
				icon: '!',
				label: result ? t('leicht unter Plan') : t('beobachten'),
				tone: 'warn',
			}
		case 'crit':
			return {
				icon: '✕',
				label: expense ? t('überschritten') : (result ? t('deutlich unter Plan') : t('unter Plan')),
				tone: 'crit',
			}
		case 'unplanned':
			return {
				icon: '?',
				label: result ? '–' : t('ungeplant'),
				tone: expense ? 'serious' : 'good',
			}
		default:
			return { icon: '–', label: t('ohne Werte'), tone: 'none' }
	}
}

/**
 * Hat die Zeile untergeordnete Kontozeilen (nächste Zeile ist tiefer)?
 *
 * @param {Array<object>} rows Berichtszeilen
 * @param {number} index Zeilenindex
 * @return {boolean} ja/nein
 */
export function hasChildren(rows, index) {
	const row = rows[index]
	const next = rows[index + 1]
	return !!row && !!next && next.kind === 'account' && next.depth > row.depth
}

/**
 * Steht unter der Überschrift (bis zur nächsten Überschrift gleicher oder
 * höherer Ebene) eine der Zeilen aus `matched`?
 *
 * @param {Array<object>} rows Berichtszeilen
 * @param {number} index Index der Überschrift
 * @param {Set<number>} matched Indizes der Treffer
 * @return {boolean} ja/nein
 */
function headingHasMatch(rows, index, matched) {
	const depth = rows[index].depth
	for (let i = index + 1; i < rows.length; i++) {
		if (rows[i].kind === 'heading' && rows[i].depth <= depth) {
			return false
		}
		if (matched.has(i)) {
			return true
		}
	}
	return false
}

/**
 * Sichtbare Zeilen mit ihrem Originalindex.
 *
 * Ohne Filter blenden zugeklappte Kontozeilen (Index in `collapsed`) ihre
 * tieferen Kontozeilen aus. Mit Filter ({ nature, level }) bleiben nur
 * passende Kontozeilen, jedes Konto (`accountId`) nur beim ersten Auftreten
 * – eine Vorlage darf ein Konto in mehreren Abschnitten führen –, dazu die
 * Überschriften, unter denen (bis zur nächsten Überschrift gleicher oder
 * höherer Ebene) eine davon steht. Zuklappen gilt dann nicht.
 *
 * @param {Array<object>} rows Berichtszeilen
 * @param {Set<number>} collapsed Indizes zugeklappter Kontozeilen
 * @param {{nature: string, level: string}|null} filter Filter oder null
 * @param {string} basis year|time
 * @return {Array<{row: object, index: number}>} Zeilen mit Index
 */
export function visibleRows(rows, collapsed, filter, basis) {
	const out = []
	if (filter) {
		const seen = new Set()
		const matched = new Set()
		rows.forEach((row, index) => {
			if (row.kind !== 'account'
				|| row.nature !== filter.nature
				|| row.status?.[basis]?.level !== filter.level) {
				return
			}
			if (row.accountId !== undefined && row.accountId !== null) {
				if (seen.has(row.accountId)) {
					return
				}
				seen.add(row.accountId)
			}
			matched.add(index)
		})
		rows.forEach((row, index) => {
			if (matched.has(index) || (row.kind === 'heading' && headingHasMatch(rows, index, matched))) {
				out.push({ row, index })
			}
		})
		return out
	}
	let hideBelow = null
	rows.forEach((row, index) => {
		if (hideBelow !== null) {
			if (row.kind === 'account' && row.depth > hideBelow) {
				return
			}
			hideBelow = null
		}
		out.push({ row, index })
		if (row.kind === 'account' && collapsed.has(index)) {
			hideBelow = row.depth
		}
	})
	return out
}

/**
 * Monatsname mit Jahr, z. B. "Oktober 2026".
 *
 * @param {string} ym YYYY-MM
 * @param {string} locale Sprache, z. B. 'de'
 * @return {string} Beschriftung
 */
export function monthLabel(ym, locale) {
	const [year, month] = ym.split('-').map(Number)
	return new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' })
		.format(new Date(Date.UTC(year, month - 1, 1)))
}
