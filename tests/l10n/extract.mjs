// Liest alle übersetzbaren Quelltexte der App aus den Quelldateien und stellt
// sie dem Übersetzungsbündel gegenüber (Issue #106).
//
// Die Quelltexte sind Deutsch, die Schlüssel in l10n/en.json und l10n/de.json
// sind genau diese Texte. Welche es gibt, steht nirgends als Liste – sie
// stehen als Aufrufe im Code: `t('…')`/`n('…', '…', count)`/`tc('Kontext', '…')`/
// `tRaw('…')` im Frontend (src/), `$l->t('…')`/`$this->l10n->n('…', '…', $n)`
// und der `msg('…')`-Helfer der Parser im Backend (lib/, templates/). Dieses
// Modul liest sie generisch aus – es kennt keinen einzelnen Schlüssel, ein neuer
// Text taucht also von selbst im Abgleich auf (tests/l10n/catalog.test.js, und
// `npm run l10n:check` zeigt, was fehlt).
//
// Bewusst kein Parser, sondern ein Leser für Zeichenkettenliterale hinter dem
// Aufruf: das genügt für die Schreibweise dieses Projekts (ein Literal oder
// mehrere, mit „+“ bzw. „.“ verkettet) und braucht weder Babel noch PHP.
// Aufrufe, deren erstes Argument kein Literal ist, kann der Leser nicht lesen;
// er meldet sie in `dynamic`, statt sie zu übergehen.
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

export const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

/** Trennzeichen zwischen Kontext und Text (src/lib/l10n.js: CONTEXT_SEPARATOR). */
export const CONTEXT_SEPARATOR = '\u0004'

function walk(dir, accept, out = []) {
	if (!existsSync(dir)) { return out }
	for (const name of readdirSync(dir)) {
		if (name === 'node_modules' || name === 'vendor' || name.startsWith('.')) { continue }
		const path = join(dir, name)
		if (statSync(path).isDirectory()) {
			walk(path, accept, out)
		} else if (accept(path)) {
			out.push(path)
		}
	}
	return out
}

/**
 * Ersetzt Kommentare durch Leerzeichen (Zeilenumbrüche bleiben, damit die
 * Zeilennummern stimmen). Ein Beispielaufruf in einem Kommentar – etwa
 * `t('… {name} …')` in der Erklärung zu tRaw() – ist kein Text der App.
 * Bewusst grob: Blockkommentare, HTML-Kommentare und Zeilen, die mit „//" beginnen.
 */
function stripComments(source) {
	const blank = (match) => match.replace(/[^\n]/g, ' ')
	// „/*" nur nach Zeilenanfang, Leerraum oder Satzzeichen: in `accept="image/*"` beginnt kein Kommentar.
	return source
		.replace(/(?<=^|[\s;{}(),])\/\*[\s\S]*?\*\//gm, blank)
		.replace(/<!--[\s\S]*?-->/g, blank)
		.replace(/^[ \t]*\/\/.*$/gm, blank)
}

function lineOf(source, index) {
	let line = 1
	for (let i = 0; i < index; i++) { if (source.charCodeAt(i) === 10) { line++ } }
	return line
}

// --- Zeichenkettenliterale ---------------------------------------------------

const ESCAPES_JS = { n: '\n', t: '\t', r: '\r' }

/** Liest ein JS-Literal ab `source[start]` (Anführungszeichen). Gibt [Wert, Ende] oder null (Vorlage mit ${…}). */
function readJsLiteral(source, start) {
	const quote = source[start]
	let value = ''
	let i = start + 1
	while (i < source.length && source[i] !== quote) {
		if (source[i] === '\\') {
			value += ESCAPES_JS[source[i + 1]] ?? source[i + 1]
			i += 2
		} else {
			if (quote === '`' && source[i] === '$' && source[i + 1] === '{') { return null }
			value += source[i]
			i++
		}
	}
	return [value, i + 1]
}

/** Liest ein PHP-Literal ab `source[start]`; einfache Literale kennen nur \\ und \', doppelte die üblichen Escapes. */
function readPhpLiteral(source, start) {
	const quote = source[start]
	let value = ''
	let i = start + 1
	while (i < source.length && source[i] !== quote) {
		if (source[i] === '\\') {
			const next = source[i + 1]
			if (quote === "'") {
				value += next === "'" || next === '\\' ? next : `\\${next}`
			} else {
				value += { n: '\n', t: '\t', r: '\r', '"': '"', '\\': '\\', $: '$' }[next] ?? `\\${next}`
			}
			i += 2
		} else {
			if (quote === '"' && source[i] === '$' && /[A-Za-z_{]/.test(source[i + 1] ?? '')) { return null }
			value += source[i]
			i++
		}
	}
	return [value, i + 1]
}

/** Ein oder mehrere verkettete Literale ab `start`. Gibt [Text, Ende] oder null. */
function readConcatenated(source, start, readLiteral, joiner) {
	let text = ''
	let pos = start
	for (;;) {
		if (!'\'"`'.includes(source[pos] ?? '')) { return null }
		const literal = readLiteral(source, pos)
		if (literal === null) { return null }
		text += literal[0]
		pos = literal[1]
		const next = new RegExp(`^\\s*\\${joiner}\\s*(?=['"\`])`).exec(source.slice(pos, pos + 40))
		if (!next) { return [text, pos] }
		pos += next[0].length
	}
}

function skipComma(source, pos) {
	const match = /^\s*,\s*/.exec(source.slice(pos, pos + 40))
	return match ? pos + match[0].length : -1
}

// --- Aufrufe -----------------------------------------------------------------

/**
 * @typedef {{ key: string, kind: string, file: string, line: number }} Call
 */

/**
 * @param {string} source Dateiinhalt
 * @param {string} file Pfad für die Fundstelle
 * @param {RegExp} callPattern Treffer = Anfang eines Aufrufs; Gruppe 1 = Name; endet direkt vor dem ersten Argument
 * @param {(source: string, start: number) => ([string, number] | null)} readLiteral
 * @param {string} joiner Verkettungszeichen
 */
function scan(source, file, callPattern, readLiteral, joiner) {
	/** @type {Call[]} */
	const calls = []
	const dynamic = []
	for (const match of source.matchAll(callPattern)) {
		const name = match[1]
		const start = match.index + match[0].length
		const line = lineOf(source, match.index)
		const first = readConcatenated(source, start, readLiteral, joiner)
		if (first === null) {
			dynamic.push({ kind: name, file, line, code: source.slice(match.index, match.index + 70).replace(/\s+/g, ' ') })
			continue
		}
		let key = first[0]
		if (name === 'n') {
			const comma = skipComma(source, first[1])
			const second = comma < 0 ? null : readConcatenated(source, comma, readLiteral, joiner)
			if (second === null) {
				dynamic.push({ kind: name, file, line, code: source.slice(match.index, match.index + 70).replace(/\s+/g, ' ') })
				continue
			}
			key = `_${first[0]}_::_${second[0]}_`
		} else if (name === 'tc') {
			const comma = skipComma(source, first[1])
			const second = comma < 0 ? null : readConcatenated(source, comma, readLiteral, joiner)
			if (second === null) {
				dynamic.push({ kind: name, file, line, code: source.slice(match.index, match.index + 70).replace(/\s+/g, ' ') })
				continue
			}
			key = `${first[0]}${CONTEXT_SEPARATOR}${second[0]}`
		}
		calls.push({ key, kind: name, file, line })
	}
	return { calls, dynamic }
}

/**
 * Alle Aufrufe im Frontend: `t('…')`, `this.t('…')`, `n(…)`, `tc(…)`, `tRaw(…)`.
 * Testdateien zählen nicht – sie rufen t() mit Beispieltexten auf.
 */
export function extractFrontend(root = ROOT) {
	// src/lib/l10n.js definiert t()/n()/tc()/tRaw() selbst, es ruft sie nicht mit Texten auf.
	const files = walk(join(root, 'src'), (p) => /\.(vue|js)$/.test(p) && !p.endsWith('.test.js') && !p.endsWith(join('src', 'lib', 'l10n.js')))
	const pattern = /(?<![\w$.])(?:this\.)?(t|n|tc|tRaw)\(\s*(?=['"`])/g
	const dynamicPattern = /(?<![\w$.])(?<!function\s+)(?:this\.)?(t|n|tc|tRaw)\(\s*(?=[^'"`\s)])/g
	const calls = []
	const dynamic = []
	for (const path of files) {
		const source = stripComments(readFileSync(path, 'utf8'))
		const file = relative(root, path)
		const result = scan(source, file, pattern, readJsLiteral, '+')
		calls.push(...result.calls)
		dynamic.push(...result.dynamic)
		// Aufrufe mit nicht-literalem erstes Argument, z. B. t(a.action)
		for (const match of source.matchAll(dynamicPattern)) {
			dynamic.push({ kind: match[1], file, line: lineOf(source, match.index), code: source.slice(match.index, match.index + 70).replace(/\s+/g, ' ') })
		}
	}
	return { calls, dynamic, files: files.length }
}

/**
 * Alle Aufrufe im Backend: `->t('…')`, `->n('…', '…', $n)` und der
 * `->msg('…')`-Helfer der Klassen mit optionalem IL10N (OptionalL10n, Parser).
 */
export function extractBackend(root = ROOT) {
	const files = [
		...walk(join(root, 'lib'), (p) => p.endsWith('.php')),
		...walk(join(root, 'templates'), (p) => p.endsWith('.php')),
	]
	const pattern = /->(t|n|msg)\(\s*(?=['"])/g
	const calls = []
	const dynamic = []
	for (const path of files) {
		const source = stripComments(readFileSync(path, 'utf8'))
		const result = scan(source, relative(root, path), pattern, readPhpLiteral, '.')
		calls.push(...result.calls)
		dynamic.push(...result.dynamic)
	}
	// `msg` ist nur ein anderer Name für t()
	for (const call of calls) { if (call.kind === 'msg') { call.kind = 't' } }
	return { calls, dynamic, files: files.length }
}

/** Alle Texte der App, je Schlüssel mit den Fundstellen. @return {Map<string, Call[]>} */
export function extractAll(root = ROOT) {
	const frontend = extractFrontend(root)
	const backend = extractBackend(root)
	const keys = new Map()
	for (const call of [...frontend.calls, ...backend.calls]) {
		if (!keys.has(call.key)) { keys.set(call.key, []) }
		keys.get(call.key).push(call)
	}
	return { keys, frontend, backend }
}

// --- Bündel ------------------------------------------------------------------

export function readBundle(name, root = ROOT) {
	return JSON.parse(readFileSync(join(root, 'l10n', `${name}.json`), 'utf8'))
}

/** Platzhalter und HTML-Tags eines Textes – die müssen in jeder Fassung gleich vorkommen. */
export function tokens(text) {
	return [...String(text).matchAll(/%\d*\$?[sd]|%n|{[^{}]+}|<\/?[a-z][^>]*>/g)].map((m) => m[0]).sort()
}

/** Der Schlüssel in lesbarer Form für Meldungen („Aktiv" statt „Zustand␄Aktiv"). */
export function printable(key) {
	return key.replace(CONTEXT_SEPARATOR, ' ▸ ')
}
