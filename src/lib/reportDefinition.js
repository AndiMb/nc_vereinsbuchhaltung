// Definition einer Gliederungsbericht-Vorlage im Editor.
//
// Der Server prüft dieselben Regeln (lib/Service/Report/ReportDefinition.php)
// und ist maßgeblich; hier stehen sie, damit Fehler schon beim Bearbeiten
// sichtbar werden und nicht erst nach „Speichern". Ändert sich eine Regel,
// müssen beide Seiten mitziehen – die Tests in reportDefinition.test.js und
// ReportDefinitionTest.php decken dieselben Fälle ab.
//
// Aufbau siehe docs/gliederungsbericht-design.md, Abschnitt 3.2.
import { t, tRaw } from './l10n.js'

export const TYPES = ['income', 'expense']
export const SPHERES = ['ideell', 'vermoegensverwaltung', 'zweckbetrieb', 'wirtschaftlich', 'none']
export const LEVELS = ['type', 'sphere', 'group', 'account']
export const SOURCES = ['plan', 'actual', 'diff']
export const DEPTHS = ['plan', '0', '1', '2', '3', 'all']

export const MAX_SECTION_DEPTH = 4
export const MAX_COLUMNS = 8
export const MIN_PERIOD_OFFSET = -3

/** Präfix der Berichtsgruppen, die die Vorlage selbst festlegt (wie ReportDefinition::GROUP_TEMPLATE). */
export const GROUP_TEMPLATE = 't:'

/** @return {string} Anzeigename einer Kontoart */
export function typeName(type) {
	return type === 'income' ? t('Einnahmen') : t('Ausgaben')
}

/** @return {string} Anzeigename einer Sphäre einschließlich „ohne" */
export function sphereName(sphere) {
	return {
		ideell: t('Ideeller Bereich'),
		vermoegensverwaltung: t('Vermögensverwaltung'),
		zweckbetrieb: t('Zweckbetrieb'),
		wirtschaftlich: t('Wirtschaftlicher Geschäftsbetrieb'),
		none: t('Ohne Sphäre'),
	}[sphere] || sphere
}

/** @return {string} Anzeigename eines Gliederungsmerkmals */
export function levelName(by) {
	return {
		type: t('Kontoart'),
		sphere: t('Sphäre'),
		group: t('Gruppe'),
		account: t('Konto'),
	}[by] || by
}

/** @return {string} Anzeigename einer Spaltenart */
export function sourceName(source) {
	return { plan: t('Plan'), actual: t('Ist'), diff: t('Ist − Plan') }[source] || source
}

/** @return {string} „gewählte Periode", „Vorperiode", „vor 2 Perioden" … */
export function offsetName(offset) {
	if (offset === 0) { return t('Gewählte Periode') }
	if (offset === -1) { return t('Vorperiode') }
	return t('Vor {n} Perioden', { n: -offset })
}

export function newLevel(by = 'account') {
	const level = { by, footer: null, hideEmpty: true }
	if (by === 'account') {
		level.depth = 'plan'
		level.ancestors = false
	}
	return level
}

export function newSection(title = '') {
	return {
		title,
		filter: { types: null, spheres: null, groups: null },
		summary: false,
		hideEmpty: false,
		sections: null,
		levels: [newLevel('account')],
		footer: null,
	}
}

/**
 * Startpunkt für „Neue Vorlage“: alle Konten bis zur Planungsebene mit Plan
 * und Ist des gewählten Zeitraums und dem Saldo darunter. Besteht validate()
 * ohne Fehler – nur der Name fehlt noch.
 *
 * @return {object} Definition
 */
export function starterDefinition() {
	return {
		version: 1,
		columns: [
			{ source: 'plan', periodOffset: 0 },
			{ source: 'actual', periodOffset: 0 },
		],
		showPlanNotes: false,
		groups: [],
		sections: [newSection(t('Konten'))],
		grandTotal: { label: t('Saldo') },
	}
}

/** Eine neue Berichtsgruppe mit einem Schlüssel, den es noch nicht gibt. */
export function newGroup(groups, label = '') {
	const used = new Set(groups.map((g) => g.key))
	let i = groups.length + 1
	while (used.has('g' + i)) { i++ }
	return { key: 'g' + i, label, costCenters: [], accounts: [] }
}

/**
 * Ein Merkmal einer Ebene ändern; Konto-Ebenen bekommen ihre Zusatzfelder,
 * andere verlieren sie.
 */
export function withLevelBy(level, by) {
	const next = { by, footer: level.footer, hideEmpty: level.hideEmpty }
	if (by === 'account') {
		next.depth = level.depth ?? 'plan'
		next.ancestors = level.ancestors ?? false
	}
	return next
}

/** Neue Liste, in der das Element an `index` um `delta` Plätze verschoben ist. */
export function moved(list, index, delta) {
	const target = index + delta
	if (target < 0 || target >= list.length) { return list }
	const copy = list.slice()
	const [item] = copy.splice(index, 1)
	copy.splice(target, 0, item)
	return copy
}

/**
 * Schnittmenge zweier Filterlisten; null heißt „keine Einschränkung".
 *
 * @param {string[]|null} a bisherige Liste
 * @param {string[]|null} b hinzukommende Liste
 * @return {string[]|null}
 */
export function narrow(a, b) {
	if (!b || b.length === 0) { return a }
	if (!a) { return b.slice() }
	return a.filter((x) => b.includes(x))
}

/**
 * Was eine Summenzeile an dieser Stelle zeigt: eine Summe, wenn nur eine
 * Kontoart erlaubt ist, sonst das Ergebnis Einnahmen − Ausgaben.
 *
 * @param {string[]|null} types wirksame Kontoarten
 * @return {'sum'|'result'}
 */
export function footerKind(types) {
	return types === null || types.filter((x) => TYPES.includes(x)).length > 1 ? 'result' : 'sum'
}

/**
 * Inhaltliche Fehler einer Definition; leer = gültig. Gleiche Regeln wie
 * ReportDefinition::validate().
 *
 * @param {object} def Definition
 * @return {string[]}
 */
export function validate(def) {
	const errors = []
	const columns = def.columns || []
	if (columns.length === 0) { errors.push(t('Die Vorlage braucht mindestens eine Spalte.')) }
	if (columns.length > MAX_COLUMNS) { errors.push(t('Höchstens {n} Spalten sind möglich.', { n: MAX_COLUMNS })) }
	for (const c of columns) {
		if (!SOURCES.includes(c.source)) { errors.push(t('Unbekannte Spaltenart „{source}“.', { source: c.source })) }
		if (c.periodOffset > 0 || c.periodOffset < MIN_PERIOD_OFFSET) {
			errors.push(t('Eine Spalte kann höchstens {n} Perioden zurückliegen.', { n: -MIN_PERIOD_OFFSET }))
		}
	}

	const keys = new Set()
	const costCenters = new Set()
	const accounts = new Set()
	for (const g of def.groups || []) {
		if (!/^[A-Za-z0-9_-]{1,32}$/.test(g.key)) {
			errors.push(t('Ungültiger Schlüssel einer Berichtsgruppe.'))
		} else if (keys.has(g.key)) {
			errors.push(tRaw('Die Berichtsgruppe „{name}“ ist doppelt angelegt.', { name: g.label || g.key }))
		}
		keys.add(g.key)
		if (!String(g.label || '').trim()) { errors.push(t('Jede Berichtsgruppe braucht einen Namen.')) }
		for (const code of g.costCenters || []) {
			if (costCenters.has(code)) { errors.push(tRaw('Die Auswertungsgruppe „{code}“ ist mehreren Berichtsgruppen zugeordnet.', { code })) }
			costCenters.add(code)
		}
		for (const id of g.accounts || []) {
			if (accounts.has(id)) { errors.push(t('Ein Konto ist mehreren Berichtsgruppen zugeordnet.')) }
			accounts.add(id)
		}
	}

	const sections = def.sections || []
	if (sections.length === 0) { errors.push(t('Die Vorlage braucht mindestens einen Abschnitt.')) }
	for (const s of sections) { validateSection(s, 1, errors) }
	return [...new Set(errors)]
}

function validateSection(section, depth, errors) {
	if (depth > MAX_SECTION_DEPTH) {
		errors.push(t('Abschnitte lassen sich höchstens {n} Ebenen tief verschachteln.', { n: MAX_SECTION_DEPTH }))
		return
	}
	const hasSections = section.sections !== null && section.sections !== undefined
	const hasLevels = section.levels !== null && section.levels !== undefined
	if (hasSections === hasLevels) {
		errors.push(t('Ein Abschnitt enthält entweder Unterabschnitte oder Gliederungsebenen.'))
		return
	}
	if (hasSections) {
		if (section.sections.length === 0) { errors.push(t('Ein Abschnitt mit Unterabschnitten braucht mindestens einen davon.')) }
		for (const child of section.sections) { validateSection(child, depth + 1, errors) }
		return
	}
	if (section.levels.length === 0) { errors.push(t('Ein Abschnitt braucht mindestens eine Gliederungsebene.')) }
	const seen = new Set()
	section.levels.forEach((level, i) => {
		if (seen.has(level.by)) { errors.push(t('Jedes Merkmal darf in einem Abschnitt nur eine Gliederungsebene bilden.')) }
		seen.add(level.by)
		if (level.by === 'account' && i !== section.levels.length - 1) {
			errors.push(t('„Konto“ kann nur die letzte Gliederungsebene sein.'))
		}
	})
}

/**
 * Skelett der Gliederung ohne Zahlen, für die Vorschau im Editor:
 * „Einnahmen › Ideeller Bereich › Konten".
 *
 * @param {object} def Definition
 * @return {Array<{depth: number, text: string, kind: string}>}
 */
export function outline(def) {
	const lines = []
	const walk = (sections, depth, types) => {
		for (const s of sections || []) {
			const sectionTypes = narrow(types, s.filter?.types)
			const title = s.title || t('(ohne Überschrift)')
			lines.push({ depth, text: s.summary ? t('{title} (Zusammenfassung)', { title }) : title, kind: 'section' })
			if (s.sections) {
				walk(s.sections, depth + 1, sectionTypes)
			} else {
				let d = depth + 1
				for (const level of s.levels || []) {
					lines.push({ depth: d, text: level.by === 'account' ? t('Konten') : t('je {level}', { level: levelName(level.by) }), kind: 'level' })
					d++
				}
			}
			if (s.footer) {
				lines.push({ depth, text: s.footer.label || t('(Summenzeile)'), kind: footerKind(sectionTypes) })
			}
		}
	}
	walk(def.sections, 0, null)
	if (def.grandTotal) { lines.push({ depth: 0, text: def.grandTotal.label || t('(Gesamtzeile)'), kind: 'result' }) }
	return lines
}
