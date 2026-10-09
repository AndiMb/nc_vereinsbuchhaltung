#!/usr/bin/env node
// Baut aus docs/testprotokoll/testprotokoll.md die Einzeldatei testprotokoll.html.
//
//   node docs/testprotokoll/build.mjs              HTML erzeugen (neben der Markdown-Quelle)
//   node docs/testprotokoll/build.mjs --check      nur prüfen (Struktur, Querverweise, Kontraste, Script-Syntax)
//   node docs/testprotokoll/build.mjs --out x.html anderen Ausgabepfad wählen
//
// Keine Abhängigkeiten außer der Node-Standardbibliothek. Die HTML-Datei braucht
// kein Netz (kein CDN, keine Schriften), läuft per file:// und speichert die
// Eingaben des Testers im localStorage des Browsers (mit Rückfall ohne Speicher).
//
// Format der Quelle (Details im Kommentar oben in testprotokoll.md):
//   # Titel
//   Einleitungstext
//   ## Phase N – Titel        **Ziel:** / **Nutzer:** / **Vorbedingung:**
//   ### N.M Schritt-Titel     **Rolle:** / **Tun:** / **Erwartet:** / **Beachte:**
//   ## Anderes Kapitel        reiner Text
//   <!-- matrix -->           Platz der Prioritäten-Matrix

import { createHash } from 'node:crypto'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import vm from 'node:vm'

const HERE = dirname(fileURLToPath(import.meta.url))
const SOURCE = resolve(HERE, 'testprotokoll.md')
const MATRIX_MARK = '@@MATRIX@@'

// ---------------------------------------------------------------------------
// Farben (Hell/Dunkel). Alle Text-/Hintergrundpaare in CONTRAST_PAIRS werden
// bei jedem Lauf nach WCAG 2.1 geprüft (AA: 4,5 : 1, Fokusrahmen 3 : 1).
// ---------------------------------------------------------------------------
const THEME = {
	light: {
		bg: '#f4f6f9', surface: '#ffffff', text: '#1a1f26', muted: '#475262', border: '#c5ccd6',
		accent: '#0b5cad', accentText: '#ffffff', focus: '#0b5cad',
		okFg: '#14532d', okBg: '#dcf5e3', devFg: '#7f1d1d', devBg: '#fde2e2',
		qFg: '#1e3a8a', qBg: '#dde7ff', skipFg: '#3b3f47', skipBg: '#e6e8ee',
		warnFg: '#5c3d00', warnBg: '#fff3d1', warnBorder: '#d9a92b', codeBg: '#eef1f5',
	},
	dark: {
		bg: '#11151b', surface: '#1a2028', text: '#e7ebf0', muted: '#b4bdc9', border: '#3a4453',
		accent: '#8ec1ff', accentText: '#0b1a2b', focus: '#8ec1ff',
		okFg: '#a9f0c0', okBg: '#173a22', devFg: '#ffb8b8', devBg: '#4c1f1f',
		qFg: '#bccfff', qBg: '#1f2f63', skipFg: '#d3d6de', skipBg: '#343743',
		warnFg: '#f5dc9a', warnBg: '#3c3011', warnBorder: '#8d6d1d', codeBg: '#242c37',
	},
}
const CONTRAST_PAIRS = [
	['text', 'bg'], ['text', 'surface'], ['muted', 'bg'], ['muted', 'surface'],
	['accent', 'surface'], ['accent', 'bg'], ['accentText', 'accent'],
	['okFg', 'okBg'], ['devFg', 'devBg'], ['qFg', 'qBg'], ['skipFg', 'skipBg'],
	['warnFg', 'warnBg'], ['text', 'codeBg'],
]
const NON_TEXT_PAIRS = [['focus', 'bg'], ['focus', 'surface']]

// ---------------------------------------------------------------------------
// Hilfen
// ---------------------------------------------------------------------------
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

function slug(s) {
	return s.toLowerCase().replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
		.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
}

function luminance(hex) {
	const c = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
		.map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4))
	return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]
}
function contrast(a, b) {
	const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x)
	return (l1 + 0.05) / (l2 + 0.05)
}

// ---------------------------------------------------------------------------
// Quelle lesen: Kommentare entfernen (außerhalb von Code), Matrix-Marke setzen
// ---------------------------------------------------------------------------
function stripComments(md) {
	const out = []
	let inFence = false
	let comment = null // null oder gesammelter Kommentartext
	for (const line of md.split('\n')) {
		if (comment === null && /^\s*```/.test(line)) {
			inFence = !inFence
			out.push(line)
			continue
		}
		if (inFence) {
			out.push(line)
			continue
		}
		let res = ''
		let i = 0
		while (i < line.length) {
			if (comment !== null) {
				const end = line.indexOf('-->', i)
				if (end === -1) {
					comment += line.slice(i)
					i = line.length
				} else {
					comment += line.slice(i, end)
					i = end + 3
					if (comment.trim() === 'matrix') {
						res += `\n${MATRIX_MARK}\n`
					}
					comment = null
				}
				continue
			}
			const ch = line[i]
			if (ch === '`') {
				let run = 1
				while (line[i + run] === '`') { run++ }
				const ticks = '`'.repeat(run)
				const close = line.indexOf(ticks, i + run)
				if (close === -1) {
					res += ticks
					i += run
				} else {
					res += line.slice(i, close + run)
					i = close + run
				}
				continue
			}
			if (line.startsWith('<!--', i)) {
				comment = ''
				i += 4
				continue
			}
			res += ch
			i++
		}
		if (comment !== null) { comment += '\n' }
		out.push(...res.split('\n'))
	}
	return out.join('\n')
}

// ---------------------------------------------------------------------------
// Aufbau des Dokuments
// ---------------------------------------------------------------------------
const PHASE_LABELS = ['Ziel', 'Nutzer', 'Vorbedingung']
const STEP_LABELS = ['Rolle', 'Tun', 'Erwartet', 'Beachte']

function splitLabeled(lines, allowed, errors, where) {
	const pre = []
	const sections = {}
	let current = null
	let inFence = false
	for (const line of lines) {
		if (/^\s*```/.test(line)) { inFence = !inFence }
		if (!inFence && line.trim() === MATRIX_MARK) {
			pre.push(line)
			current = null
			continue
		}
		const m = !inFence ? /^\*\*([A-Za-zÄÖÜäöüß]+):\*\*\s*(.*)$/.exec(line) : null
		if (m) {
			if (!allowed.includes(m[1])) {
				errors.push(`${where}: unbekanntes Label „${m[1]}:" (erlaubt: ${allowed.join(', ')})`)
				continue
			}
			if (sections[m[1]]) { errors.push(`${where}: Label „${m[1]}:" doppelt`) }
			current = m[1]
			sections[current] = []
			if (m[2].trim()) { sections[current].push(m[2]) }
			continue
		}
		if (current) { sections[current].push(line) } else { pre.push(line) }
	}
	for (const k of Object.keys(sections)) {
		while (sections[k].length && !sections[k][sections[k].length - 1].trim()) { sections[k].pop() }
		while (sections[k].length && !sections[k][0].trim()) { sections[k].shift() }
	}
	return { pre, sections }
}

function parse(md) {
	const errors = []
	const text = stripComments(md.replace(/\r\n/g, '\n'))
	const lines = text.split('\n')
	const doc = { title: '', intro: [], chapters: [], phases: [] }
	let target = doc.intro
	let current = null
	let inFence = false
	for (const line of lines) {
		if (/^\s*```/.test(line)) { inFence = !inFence }
		if (!inFence) {
			let m = /^# (.+)$/.exec(line)
			if (m && !doc.title) {
				doc.title = m[1].trim()
				continue
			}
			m = /^## Phase (\d+)\s+[–-]\s+(.+)$/.exec(line)
			if (m) {
				current = { kind: 'phase', n: Number(m[1]), title: m[2].trim(), lines: [] }
				doc.phases.push(current)
				target = current.lines
				continue
			}
			m = /^## (.+)$/.exec(line)
			if (m) {
				current = { kind: 'chapter', title: m[1].trim(), lines: [] }
				doc.chapters.push(current)
				target = current.lines
				continue
			}
		}
		target.push(line)
	}
	if (!doc.title) { errors.push('Es fehlt die Überschrift „# …" ganz oben.') }

	// Reihenfolge der Abschnitte im Dokument (Kapitel und Phasen gemischt) neu aufbauen
	doc.order = []
	let phaseIdx = 0
	let chapterIdx = 0
	let fence = false
	for (const line of lines) {
		if (/^\s*```/.test(line)) { fence = !fence }
		if (fence) { continue }
		if (/^## Phase \d+\s+[–-]\s+/.test(line)) { doc.order.push(doc.phases[phaseIdx++]) } else if (/^## (?!Phase \d+\s+[–-])/.test(line)) { doc.order.push(doc.chapters[chapterIdx++]) }
	}

	for (const ph of doc.phases) {
		const startIdx = ph.lines.findIndex((l) => /^### /.test(l))
		const head = startIdx === -1 ? ph.lines : ph.lines.slice(0, startIdx)
		const body = startIdx === -1 ? [] : ph.lines.slice(startIdx)
		const meta = splitLabeled(head, PHASE_LABELS, errors, `Phase ${ph.n}`)
		ph.meta = meta.sections
		ph.pre = meta.pre
		if (!ph.meta.Ziel) { errors.push(`Phase ${ph.n}: es fehlt „**Ziel:**".`) }
		ph.steps = []
		let step = null
		let fenceOpen = false
		for (const line of body) {
			if (/^\s*```/.test(line)) { fenceOpen = !fenceOpen }
			const m = !fenceOpen ? /^### (\d+)\.(\d+)\s+(.+)$/.exec(line) : null
			if (m) {
				step = { id: `${m[1]}.${m[2]}`, phase: Number(m[1]), index: Number(m[2]), title: m[3].trim(), lines: [] }
				ph.steps.push(step)
				continue
			}
			if (!fenceOpen && /^### /.test(line)) {
				errors.push(`Phase ${ph.n}: „${line}" ist keine gültige Schrittüberschrift („### N.M Titel").`)
				continue
			}
			if (step) { step.lines.push(line) }
		}
		for (const st of ph.steps) {
			const r = splitLabeled(st.lines, STEP_LABELS, errors, `Schritt ${st.id}`)
			st.sections = r.sections
			if (r.pre.some((l) => l.trim())) { errors.push(`Schritt ${st.id}: Text vor dem ersten Label wird ignoriert.`) }
			for (const need of ['Rolle', 'Tun', 'Erwartet']) {
				if (!st.sections[need] || !st.sections[need].some((l) => l.trim())) { errors.push(`Schritt ${st.id}: es fehlt „**${need}:**".`) }
			}
		}
	}
	return { doc, errors }
}

function validate(doc, errors) {
	const warnings = []
	const nums = doc.phases.map((p) => p.n)
	nums.forEach((n, i) => { if (n !== i) { errors.push(`Phasennummern nicht lückenlos ab 0: Phase ${n} an Position ${i}.`) } })
	const ids = new Set()
	for (const ph of doc.phases) {
		if (!ph.steps.length) { errors.push(`Phase ${ph.n} hat keine Schritte.`) }
		let last = null
		for (const st of ph.steps) {
			if (st.phase !== ph.n) { errors.push(`Schritt ${st.id} steht in Phase ${ph.n}.`) }
			if (ids.has(st.id)) { errors.push(`Schritt ${st.id} kommt doppelt vor.`) }
			ids.add(st.id)
			if (last === null) {
				if (st.index > 1) { errors.push(`Schritt ${st.id}: Zählung beginnt nicht bei ${ph.n}.0 oder ${ph.n}.1.`) }
			} else if (st.index !== last + 1) {
				errors.push(`Schritt ${st.id}: Zählung nicht lückenlos (vorher ${ph.n}.${last}).`)
			}
			last = st.index
		}
	}
	// Querverweise auf Schritte („siehe 7.17", „(13.4)") müssen existieren
	const all = JSON.stringify(doc)
	const re = /(?:siehe|Schritt|Schritte|Schritten|in|aus|nach|vor|bis|und|wie|Phase|\()\s*(\d{1,2}\.\d{1,2})(?![\d.])/g
	let m
	while ((m = re.exec(all)) !== null) {
		const ref = m[1]
		const [a, b] = ref.split('.').map(Number)
		if (a <= nums.length - 1 && !ids.has(ref) && b !== 0 && !/^\d{2}\.\d{2}$/.test(ref)) { warnings.push(`Querverweis auf nicht vorhandenen Schritt ${ref}`) }
	}
	// Anführungszeichen: öffnende und schließende „ “ müssen ausgeglichen sein
	const opens = (JSON.stringify(doc).match(/„/g) || []).length
	const closes = (JSON.stringify(doc).match(/“/g) || []).length
	if (opens !== closes) { warnings.push(`Typografische Anführungszeichen unausgeglichen (${opens} × „, ${closes} × “).`) }
	return { warnings, stepCount: ids.size }
}

// ---------------------------------------------------------------------------
// Minimaler Markdown-Renderer (Absätze, Listen, Tabellen, Code, Zitate, Überschriften)
// ---------------------------------------------------------------------------
function inline(src) {
	const codes = []
	let s = src.replace(/(`+)([\s\S]*?[^`])\1(?!`)/g, (_m, _t, code) => {
		codes.push(code.trim())
		return `\u0000${codes.length - 1}\u0000`
	})
	s = esc(s)
	s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+|#[^\s)]+)\)/g, (_m, t, u) => (u.startsWith('#') ? `<a href="${u}">${t}</a>` : `<a href="${u}" target="_blank" rel="noopener noreferrer">${t}</a>`))
	s = s.replace(/(^|[\s(])(https?:\/\/[^\s<)“]+?)([.,;:!?]*)(?=$|[\s)<“])/g, (_m, pre, url, tail) => `${pre}<a href="${url}" target="_blank" rel="noopener noreferrer">${url}</a>${tail}`)
	s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
	s = s.replace(/(^|[\s(„“])\*([^*\s][^*]*?)\*(?=$|[\s).,;:!?“])/g, '$1<em>$2</em>')
	return s.replace(/\u0000(\d+)\u0000/g, (_m, i) => `<code>${esc(codes[Number(i)])}</code>`)
}

const indentOf = (l) => /^\s*/.exec(l)[0].length
function listMarker(line) {
	const m = /^(\s*)([-*]|\d+\.)\s+(.*)$/.exec(line)
	return m ? { indent: m[1].length, ordered: /\d/.test(m[2]), num: parseInt(m[2], 10), text: m[3] } : null
}
function dedent(lines) {
	const nonEmpty = lines.filter((l) => l.trim())
	const min = nonEmpty.length ? Math.min(...nonEmpty.map(indentOf)) : 0
	return lines.map((l) => l.slice(Math.min(min, indentOf(l))))
}
const isTableRow = (l) => /^\s*\|/.test(l)
const isTableSep = (l) => /^\s*\|[\s:|-]+\|?\s*$/.test(l) && l.includes('-')
const splitRow = (l) => l.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map((c) => c.trim())

function blocks(lines) {
	const out = []
	let i = 0
	const startsBlock = (l, next) => /^\s*```/.test(l) || /^#{3,4}\s/.test(l) || listMarker(l) || /^\s*>/.test(l) || (isTableRow(l) && next !== undefined && isTableSep(next)) || l.trim() === MATRIX_MARK
	while (i < lines.length) {
		const line = lines[i]
		if (!line.trim()) { i++; continue }
		if (line.trim() === MATRIX_MARK) {
			out.push('<div class="matrix" id="priority-matrix" data-matrix></div>')
			i++
			continue
		}
		let m = /^\s*```(\S*)\s*$/.exec(line)
		if (m) {
			const code = []
			i++
			while (i < lines.length && !/^\s*```\s*$/.test(lines[i])) { code.push(lines[i++]) }
			i++
			out.push(`<pre><code>${esc(code.join('\n'))}</code></pre>`)
			continue
		}
		m = /^(#{3,4})\s+(.+)$/.exec(line)
		if (m) {
			const level = m[1].length + 1
			out.push(`<h${level}>${inline(m[2])}</h${level}>`)
			i++
			continue
		}
		if (isTableRow(line) && i + 1 < lines.length && isTableSep(lines[i + 1])) {
			const head = splitRow(line)
			i += 2
			const rows = []
			while (i < lines.length && isTableRow(lines[i])) { rows.push(splitRow(lines[i++])) }
			out.push(`<div class="table-wrap"><table><thead><tr>${head.map((c) => `<th scope="col">${inline(c)}</th>`).join('')}</tr></thead><tbody>${rows.map((r) => `<tr>${r.map((c) => `<td>${inline(c)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`)
			continue
		}
		if (/^\s*>/.test(line)) {
			const q = []
			while (i < lines.length && /^\s*>/.test(lines[i])) { q.push(lines[i++].replace(/^\s*>\s?/, '')) }
			out.push(`<blockquote>${blocks(q)}</blockquote>`)
			continue
		}
		const mk = listMarker(line)
		if (mk) {
			const base = mk.indent
			const tag = mk.ordered ? 'ol' : 'ul'
			const items = []
			while (i < lines.length) {
				const cur = listMarker(lines[i])
				if (!cur || cur.indent !== base || cur.ordered !== mk.ordered) { break }
				const sub = []
				i++
				while (i < lines.length) {
					const l = lines[i]
					if (!l.trim()) {
						let j = i + 1
						while (j < lines.length && !lines[j].trim()) { j++ }
						if (j < lines.length && indentOf(lines[j]) > base) { sub.push(''); i++; continue }
						break
					}
					if (indentOf(l) > base) { sub.push(l); i++; continue }
					break
				}
				items.push({ first: cur.text, sub })
			}
			const start = mk.ordered && mk.num !== 1 ? ` start="${mk.num}"` : ''
			out.push(`<${tag}${start}>${items.map((it) => `<li>${inline(it.first)}${it.sub.length ? blocks(dedent(it.sub)) : ''}</li>`).join('')}</${tag}>`)
			continue
		}
		const para = [line.trim()]
		i++
		while (i < lines.length && lines[i].trim() && !startsBlock(lines[i], lines[i + 1])) { para.push(lines[i++].trim()) }
		out.push(`<p>${inline(para.join(' '))}</p>`)
	}
	return out.join('\n')
}

function plain(lines) {
	return lines.join('\n')
		.replace(/```[\s\S]*?```/g, ' ')
		.replace(/^\s*(?:[-*]|\d+\.)\s+/gm, '• ')
		.replace(/\*\*(.+?)\*\*/g, '$1')
		.replace(/(^|[\s(])\*([^*\s][^*]*?)\*(?=$|[\s).,;:!?“])/g, '$1$2')
		.replace(/`([^`]+)`/g, '$1')
		.replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
		.replace(/\s*\n\s*/g, ' ')
		.replace(/\s+/g, ' ')
		.trim()
}

// ---------------------------------------------------------------------------
// HTML erzeugen
// ---------------------------------------------------------------------------
const STATUS = [
	['open', 'Offen'], ['ok', 'OK'], ['dev', 'Abweichung'], ['q', 'Frage'], ['skip', 'Übersprungen'],
]

function renderStep(st) {
	const id = st.id
	const radios = STATUS.map(([v, label]) => `<span class="seg-item"><input type="radio" class="sr-only" name="st-${id}" id="st-${id}-${v}" value="${v}"${v === 'open' ? ' checked' : ''}><label for="st-${id}-${v}" class="seg seg-${v}">${label}</label></span>`).join('')
	const section = (key, cls, title) => (st.sections[key] && st.sections[key].some((l) => l.trim())
		? `<section class="blk ${cls}"><h4>${title}</h4>${blocks(st.sections[key])}</section>` : '')
	return `<article class="step" id="s-${id}" data-id="${id}" data-status="open" tabindex="-1" aria-labelledby="h-${id}">
<header class="step-head"><h3 id="h-${id}"><a class="anchor" href="#s-${id}" aria-label="Link zu Schritt ${id}">${id}</a> ${inline(st.title)}</h3><span class="badge" hidden></span></header>
<p class="role"><span class="label">Rolle</span> ${inline(plain(st.sections.Rolle || []))}</p>
${section('Tun', 'blk-do', 'Tun')}
${section('Erwartet', 'blk-expect', 'Erwartet')}
${section('Beachte', 'blk-note', 'Beachte')}
<fieldset class="result"><legend>Ergebnis zu Schritt ${id}</legend>
<div class="seg-group" role="radiogroup" aria-label="Ergebnis">${radios}</div>
<label class="prio" hidden>Priorität <select data-prio><option value="">ohne Priorität</option><option value="blocker">Blocker</option><option value="wichtig">Wichtig</option><option value="nice">Schön zu haben</option></select></label>
<label class="note">Notiz<textarea rows="2" data-note placeholder="Was ist dir aufgefallen? (auch bei OK)"></textarea></label>
</fieldset>
</article>`
}

function renderPhase(ph) {
	const meta = PHASE_LABELS.filter((k) => ph.meta[k] && ph.meta[k].some((l) => l.trim()))
		.map((k) => `<dt>${k}</dt><dd>${blocks(ph.meta[k])}</dd>`).join('')
	const pre = ph.pre.some((l) => l.trim()) ? blocks(ph.pre) : ''
	return `<section class="phase" id="phase-${ph.n}" aria-labelledby="h-phase-${ph.n}" data-phase="${ph.n}">
<header class="phase-head"><h2 id="h-phase-${ph.n}">Phase ${ph.n} – ${inline(ph.title)}</h2>
<div class="phase-progress"><progress max="${ph.steps.length}" value="0" aria-label="Fortschritt Phase ${ph.n}" data-phase-progress="${ph.n}"></progress><span class="phase-count" data-phase-count="${ph.n}">0 / ${ph.steps.length}</span></div></header>
<dl class="phase-meta">${meta}</dl>
${pre}
<p class="empty" hidden>Keine Schritte dieser Phase passen zum gewählten Filter.</p>
${ph.steps.map(renderStep).join('\n')}
</section>`
}

function renderChapter(ch) {
	const id = `kap-${slug(ch.title)}`
	return `<section class="chapter" id="${id}" aria-labelledby="h-${id}"><h2 id="h-${id}">${inline(ch.title)}</h2>
${blocks(ch.lines)}
</section>`
}

function cssVars(t) {
	return Object.entries(t).map(([k, v]) => `--${k.replace(/[A-Z]/g, (c) => `-${c.toLowerCase()}`)}: ${v};`).join(' ')
}

const CSS = String.raw`
:root { ${cssVars(THEME.light)} color-scheme: light; --topbar-h: 150px; --radius: 10px; }
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { ${cssVars(THEME.dark)} color-scheme: dark; } }
:root[data-theme="dark"] { ${cssVars(THEME.dark)} color-scheme: dark; }
*, *::before, *::after { box-sizing: border-box; }
html { scroll-behavior: smooth; -webkit-text-size-adjust: 100%; }
@media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
.sr-only { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
.skip { position: absolute; left: 8px; top: -60px; background: var(--accent); color: var(--accent-text); padding: 8px 12px; border-radius: 6px; z-index: 100; }
.skip:focus { top: 8px; }
a { color: var(--accent); }
:focus-visible { outline: 3px solid var(--focus); outline-offset: 2px; }
h1, h2, h3, h4 { line-height: 1.25; }
code { background: var(--code-bg); padding: 0.1em 0.35em; border-radius: 4px; font: 0.92em ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; overflow-wrap: anywhere; }
pre { background: var(--code-bg); padding: 12px; border-radius: 8px; overflow-x: auto; margin: 0.6em 0; }
pre code { background: none; padding: 0; overflow-wrap: normal; }

.topbar { position: sticky; top: 0; z-index: 30; background: var(--surface); border-bottom: 1px solid var(--border); padding: 8px 16px; }
.topbar-inner { max-width: 1280px; margin: 0 auto; display: grid; gap: 8px; }
.row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
.topbar h1 { font-size: 1.05rem; margin: 0; flex: 1 1 auto; }
.topbar h1 small { font-weight: 400; color: var(--muted); margin-left: 6px; }
.overall { display: flex; align-items: center; gap: 8px; flex: 0 1 auto; }
.overall-text { font-size: 0.9rem; color: var(--muted); white-space: nowrap; }
progress { appearance: none; -webkit-appearance: none; height: 10px; width: 140px; border: 0; border-radius: 6px; background: var(--border); overflow: hidden; }
progress::-webkit-progress-bar { background: var(--border); border-radius: 6px; }
progress::-webkit-progress-value { background: var(--accent); border-radius: 6px; }
progress::-moz-progress-bar { background: var(--accent); border-radius: 6px; }
.counts { display: flex; flex-wrap: wrap; gap: 6px; font-size: 0.85rem; }
.chip { padding: 1px 8px; border-radius: 999px; font-weight: 600; }
.chip-ok { color: var(--ok-fg); background: var(--ok-bg); } .chip-dev { color: var(--dev-fg); background: var(--dev-bg); }
.chip-q { color: var(--q-fg); background: var(--q-bg); } .chip-skip { color: var(--skip-fg); background: var(--skip-bg); }
.chip-open { color: var(--muted); background: var(--code-bg); }
.btn { font: inherit; font-size: 0.92rem; color: var(--text); background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 6px 12px; min-height: 36px; cursor: pointer; }
.btn:hover { border-color: var(--accent); }
.btn[aria-pressed="true"] { background: var(--accent); color: var(--accent-text); border-color: var(--accent); }
.btn.primary { background: var(--accent); color: var(--accent-text); border-color: var(--accent); font-weight: 600; }
.btn.danger { border-color: var(--dev-fg); color: var(--dev-fg); }
.only-mobile { display: none; }
.sm { display: none; }
.filters { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.filters .label { font-size: 0.85rem; color: var(--muted); }
.notice { margin: 0; padding: 6px 10px; border-radius: 8px; background: var(--warn-bg); color: var(--warn-fg); border: 1px solid var(--warn-border); font-size: 0.9rem; }

.layout { max-width: 1280px; margin: 0 auto; padding: 16px; display: grid; grid-template-columns: minmax(220px, 270px) minmax(0, 1fr); gap: 24px; align-items: start; }
.toc { position: sticky; top: calc(var(--topbar-h) + 12px); max-height: calc(100vh - var(--topbar-h) - 24px); overflow: auto; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px; font-size: 0.92rem; }
.toc h2 { font-size: 0.95rem; margin: 0 0 8px; }
.toc ol { list-style: none; margin: 0; padding: 0; }
.toc li + li { margin-top: 2px; }
.toc a { display: grid; grid-template-columns: 1fr auto; gap: 8px; padding: 5px 8px; border-radius: 6px; text-decoration: none; color: var(--text); }
.toc a:hover { background: var(--code-bg); }
.toc a[aria-current="true"] { background: var(--accent); color: var(--accent-text); }
.toc .tc { color: var(--muted); font-variant-numeric: tabular-nums; white-space: nowrap; }
.toc a[aria-current="true"] .tc { color: var(--accent-text); }
.toc .tc.done { font-weight: 700; }

main { min-width: 0; }
.chapter, .phase { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; margin: 0 0 20px; scroll-margin-top: calc(var(--topbar-h) + 12px); }
.chapter h2, .phase h2 { margin-top: 0; font-size: 1.35rem; }
.chapter h3 { font-size: 1.05rem; margin: 1.2em 0 0.4em; }
.phase-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px; }
.phase-head h2 { margin: 0; }
.phase-progress { display: flex; align-items: center; gap: 8px; color: var(--muted); font-size: 0.9rem; }
.phase-meta { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; margin: 14px 0; padding: 12px; background: var(--code-bg); border-radius: 8px; }
.phase-meta dt { font-weight: 700; } .phase-meta dd { margin: 0; } .phase-meta dd > :first-child { margin-top: 0; } .phase-meta dd > :last-child { margin-bottom: 0; }
.empty { color: var(--muted); font-style: italic; }
.step { border: 1px solid var(--border); border-left: 6px solid var(--border); border-radius: 8px; padding: 14px 16px; margin: 16px 0 0; scroll-margin-top: calc(var(--topbar-h) + 12px); background: var(--surface); }
.step[hidden] { display: none; }
.step.stale { opacity: 0.6; }
.step[data-status="ok"] { border-left-color: var(--ok-fg); } .step[data-status="dev"] { border-left-color: var(--dev-fg); }
.step[data-status="q"] { border-left-color: var(--q-fg); } .step[data-status="skip"] { border-left-color: var(--skip-fg); }
.step-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.step-head h3 { margin: 0; font-size: 1.08rem; }
.anchor { text-decoration: none; font-variant-numeric: tabular-nums; background: var(--code-bg); padding: 1px 7px; border-radius: 6px; margin-right: 4px; color: var(--text); }
.badge { font-size: 0.8rem; font-weight: 700; padding: 2px 9px; border-radius: 999px; white-space: nowrap; }
.step[data-status="ok"] .badge { color: var(--ok-fg); background: var(--ok-bg); } .step[data-status="dev"] .badge { color: var(--dev-fg); background: var(--dev-bg); }
.step[data-status="q"] .badge { color: var(--q-fg); background: var(--q-bg); } .step[data-status="skip"] .badge { color: var(--skip-fg); background: var(--skip-bg); }
.role { margin: 6px 0 0; color: var(--muted); font-size: 0.93rem; }
.role .label, .blk h4 { font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; font-size: 0.78rem; color: var(--muted); }
.role .label { margin-right: 6px; }
.blk { margin-top: 10px; }
.blk h4 { margin: 0 0 4px; }
.blk > :not(h4):first-of-type { margin-top: 0; }
.blk p, .blk ul, .blk ol, .chapter p, .chapter ul, .chapter ol { margin: 0.5em 0; }
ul, ol { padding-left: 1.4em; }
li + li { margin-top: 3px; }
li > ul, li > ol { margin: 3px 0; }
.blk-note { background: var(--warn-bg); color: var(--warn-fg); border: 1px solid var(--warn-border); border-radius: 8px; padding: 8px 12px; }
.blk-note h4 { color: var(--warn-fg); }
.blk-note a, .blk-note code { color: inherit; }
.table-wrap { overflow-x: auto; margin: 0.6em 0; }
table { border-collapse: collapse; width: 100%; font-size: 0.93rem; }
th, td { border: 1px solid var(--border); padding: 5px 9px; text-align: left; vertical-align: top; }
th { background: var(--code-bg); }
blockquote { margin: 0.6em 0; padding: 0.2em 1em; border-left: 4px solid var(--border); color: var(--muted); }
.result { margin: 14px 0 0; padding: 10px 0 0; border: 0; border-top: 1px dashed var(--border); display: grid; gap: 10px; }
.result legend { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
.seg-group { display: flex; flex-wrap: wrap; gap: 6px; }
.seg-item { position: relative; }
.seg { display: inline-block; padding: 6px 13px; border: 1px solid var(--border); border-radius: 999px; cursor: pointer; font-size: 0.92rem; background: var(--surface); user-select: none; }
.seg:hover { border-color: var(--accent); }
input:focus-visible + .seg { outline: 3px solid var(--focus); outline-offset: 2px; }
input:checked + .seg-ok { background: var(--ok-bg); color: var(--ok-fg); border-color: var(--ok-fg); font-weight: 700; }
input:checked + .seg-dev { background: var(--dev-bg); color: var(--dev-fg); border-color: var(--dev-fg); font-weight: 700; }
input:checked + .seg-q { background: var(--q-bg); color: var(--q-fg); border-color: var(--q-fg); font-weight: 700; }
input:checked + .seg-skip { background: var(--skip-bg); color: var(--skip-fg); border-color: var(--skip-fg); font-weight: 700; }
input:checked + .seg-open { background: var(--code-bg); font-weight: 700; border-color: var(--muted); }
.prio, .note { display: grid; gap: 4px; font-size: 0.9rem; color: var(--muted); }
.prio[hidden] { display: none; }
select, textarea { font: inherit; font-size: 1rem; color: var(--text); background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 7px 9px; width: 100%; }
select { max-width: 280px; }
textarea { resize: vertical; min-height: 3.2em; }
.matrix table { max-width: 640px; }
.matrix td a { margin-right: 8px; }
dialog { max-width: min(860px, calc(100vw - 24px)); width: 100%; color: var(--text); background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 18px; }
dialog::backdrop { background: rgba(0, 0, 0, 0.55); }
dialog h2 { margin-top: 0; font-size: 1.2rem; }
dialog textarea { min-height: 280px; font: 0.88rem/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.dialog-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; justify-content: flex-end; }
.copy-status { margin: 8px 0 0; font-weight: 600; min-height: 1.5em; }

@media (max-width: 900px) {
	.layout { grid-template-columns: minmax(0, 1fr); padding: 10px; gap: 10px; }
	.only-mobile { display: inline-block; }
	.js .toc { display: none; position: fixed; z-index: 40; left: 8px; right: 8px; top: calc(var(--topbar-h) + 4px); bottom: 8px; max-height: none; box-shadow: 0 8px 28px rgba(0, 0, 0, 0.35); }
	.js .toc.open { display: block; }
	.chapter, .phase { padding: 14px; }
	.phase-meta { grid-template-columns: 1fr; }
	.topbar { padding: 6px 10px; }
	.topbar-inner { gap: 6px; }
	.topbar h1 { font-size: 0.98rem; }
	.topbar h1 small { display: none; }
	.overall { flex: 1 1 100%; }
	.overall progress { flex: 1 1 auto; width: auto; }
	.toolbar .btn { padding: 5px 11px; }
	.lg { display: none; }
	.sm { display: inline; }
	.js .counts { display: none; }
	.js .topbar.more-open .counts { display: flex; }
	.js .more { display: none; }
	.js .more.open { display: flex; }
}
@media print {
	.topbar, .toc, .result, .skip, .btn { display: none !important; }
	.layout { display: block; }
	.chapter, .phase, .step { border-color: #999; break-inside: avoid-page; }
	body { background: #fff; color: #000; }
}
`

const SCRIPT = String.raw`
(function () {
	'use strict';
	var root = document.documentElement;
	root.classList.add('js');

	var data = JSON.parse(document.getElementById('protocol-data').textContent);
	var STATUS = { open: 'Offen', ok: 'OK', dev: 'Abweichung', q: 'Frage', skip: 'Übersprungen' };
	var PRIO = { '': 'ohne Priorität', blocker: 'Blocker', wichtig: 'Wichtig', nice: 'Schön zu haben' };
	var KEY = 'vbh-testprotokoll:' + data.appVersion;

	var storageOk = true;
	var staleBuild = false;
	var state = { build: data.build, steps: {}, ui: { filter: 'all', theme: 'auto' } };

	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function $all(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

	// --- Speicher (localStorage, darf fehlen) -------------------------------
	function loadState() {
		try {
			var raw = window.localStorage.getItem(KEY);
			if (!raw) { return; }
			var s = JSON.parse(raw);
			if (s && typeof s === 'object') {
				if (s.steps && typeof s.steps === 'object') { state.steps = s.steps; }
				if (s.ui && typeof s.ui === 'object') {
					state.ui.filter = s.ui.filter || 'all';
					state.ui.theme = s.ui.theme || 'auto';
				}
				if (s.build) {
					state.build = s.build;
					if (s.build !== data.build) { staleBuild = true; }
				}
			}
		} catch (e) { storageOk = false; }
	}
	var saveTimer = null;
	function scheduleSave() { clearTimeout(saveTimer); saveTimer = setTimeout(saveNow, 200); }
	function saveNow() {
		try {
			window.localStorage.setItem(KEY, JSON.stringify(state));
			storageOk = true;
		} catch (e) { storageOk = false; }
		paintNotices();
	}
	function paintNotices() {
		var n = $('#storage-notice');
		n.hidden = storageOk;
		var b = $('#build-notice');
		b.hidden = !staleBuild;
	}

	// --- Zustand je Schritt --------------------------------------------------
	function stepState(id) {
		var s = state.steps[id];
		return { s: (s && s.s) || 'open', n: (s && s.n) || '', p: (s && s.p) || '' };
	}
	function setStep(id, patch) {
		var cur = stepState(id);
		for (var k in patch) { if (Object.prototype.hasOwnProperty.call(patch, k)) { cur[k] = patch[k]; } }
		if (cur.s !== 'dev' && cur.s !== 'q') { cur.p = cur.p; }
		if (cur.s === 'open' && !cur.n && !cur.p) { delete state.steps[id]; } else { state.steps[id] = cur; }
		scheduleSave();
		var art = byId[id];
		paintStep(art, !('n' in patch));
		if (state.ui.filter !== 'all' && !matches(cur, state.ui.filter)) { art.classList.add('stale'); } else { art.classList.remove('stale'); }
		paintCounts();
	}
	var articles = $all('.step');
	var byId = {};
	articles.forEach(function (a) { byId[a.getAttribute('data-id')] = a; });

	function paintStep(art, withNote) {
		var id = art.getAttribute('data-id');
		var st = stepState(id);
		art.setAttribute('data-status', st.s);
		var radio = $('input[type="radio"][value="' + st.s + '"]', art);
		if (radio) { radio.checked = true; }
		if (withNote) {
			var ta = $('textarea', art);
			if (ta && ta.value !== st.n) { ta.value = st.n; }
		}
		var sel = $('select', art);
		if (sel) { sel.value = st.p; }
		var prio = $('.prio', art);
		prio.hidden = !(st.s === 'dev' || st.s === 'q');
		var badge = $('.badge', art);
		badge.hidden = st.s === 'open';
		badge.textContent = st.s === 'open' ? '' : STATUS[st.s] + (st.p && (st.s === 'dev' || st.s === 'q') ? ' · ' + PRIO[st.p] : '');
	}

	// --- Zählen und Anzeigen -------------------------------------------------
	function emptyCounts() { return { open: 0, ok: 0, dev: 0, q: 0, skip: 0, all: 0 }; }
	function countAll() {
		var total = emptyCounts();
		var phases = {};
		data.phases.forEach(function (ph) {
			var c = emptyCounts();
			ph.steps.forEach(function (st) {
				var s = stepState(st.id).s;
				c[s]++; c.all++; total[s]++; total.all++;
			});
			phases[ph.n] = c;
		});
		return { total: total, phases: phases };
	}
	function paintCounts() {
		var c = countAll();
		var done = c.total.all - c.total.open;
		var pct = c.total.all ? Math.round(done * 100 / c.total.all) : 0;
		var p = $('#overall-progress');
		p.max = c.total.all; p.value = done;
		$('#overall-text').textContent = done + ' von ' + c.total.all + ' bearbeitet (' + pct + ' %)';
		$('#cnt-ok').textContent = 'OK ' + c.total.ok;
		$('#cnt-dev').textContent = 'Abweichung ' + c.total.dev;
		$('#cnt-q').textContent = 'Frage ' + c.total.q;
		$('#cnt-skip').textContent = 'Übersprungen ' + c.total.skip;
		$('#cnt-open').textContent = 'Offen ' + c.total.open;
		data.phases.forEach(function (ph) {
			var pc = c.phases[ph.n];
			var d = pc.all - pc.open;
			var bar = $('[data-phase-progress="' + ph.n + '"]');
			bar.value = d;
			$('[data-phase-count="' + ph.n + '"]').textContent = d + ' / ' + pc.all + (pc.dev || pc.q ? ' · ' + (pc.dev ? pc.dev + ' Abw.' : '') + (pc.dev && pc.q ? ', ' : '') + (pc.q ? pc.q + ' Fr.' : '') : '');
			var t = $('[data-toc-count="' + ph.n + '"]');
			t.textContent = d + '/' + pc.all;
			t.classList.toggle('done', d === pc.all);
		});
		paintMatrix();
	}

	// --- Prioritäten-Matrix --------------------------------------------------
	function paintMatrix() {
		var host = $('[data-matrix]');
		if (!host) { return; }
		var rows = [['blocker', 'Blocker'], ['wichtig', 'Wichtig'], ['nice', 'Schön zu haben'], ['', 'Ohne Priorität']];
		var cols = [['dev', 'Abweichungen'], ['q', 'Fragen']];
		var table = document.createElement('table');
		var head = document.createElement('tr');
		['Priorität'].concat(cols.map(function (c) { return c[1]; })).forEach(function (t) {
			var th = document.createElement('th'); th.scope = 'col'; th.textContent = t; head.appendChild(th);
		});
		var thead = document.createElement('thead'); thead.appendChild(head); table.appendChild(thead);
		var tb = document.createElement('tbody');
		rows.forEach(function (r) {
			var tr = document.createElement('tr');
			var th = document.createElement('th'); th.scope = 'row'; th.textContent = r[1]; tr.appendChild(th);
			cols.forEach(function (c) {
				var td = document.createElement('td');
				var n = 0;
				data.phases.forEach(function (ph) {
					ph.steps.forEach(function (st) {
						var s = stepState(st.id);
						if (s.s === c[0] && s.p === r[0]) {
							var a = document.createElement('a'); a.href = '#s-' + st.id; a.textContent = st.id; td.appendChild(a); n++;
						}
					});
				});
				if (!n) { td.textContent = '–'; }
				tr.appendChild(td);
			});
			tb.appendChild(tr);
		});
		table.appendChild(tb);
		host.textContent = '';
		host.appendChild(table);
	}

	// --- Filter ---------------------------------------------------------------
	function matches(st, f) {
		if (f === 'open') { return st.s === 'open'; }
		if (f === 'dev') { return st.s === 'dev'; }
		if (f === 'q') { return st.s === 'q'; }
		if (f === 'note') { return !!st.n; }
		return true;
	}
	function applyFilter() {
		var f = state.ui.filter;
		articles.forEach(function (a) {
			a.hidden = !matches(stepState(a.getAttribute('data-id')), f);
			a.classList.remove('stale');
		});
		$all('.phase').forEach(function (sec) {
			var any = $('.step:not([hidden])', sec);
			$('.empty', sec).hidden = !!any || f === 'all';
		});
		$all('[data-filter]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-filter') === f ? 'true' : 'false'); });
	}

	// --- Hilfen -----------------------------------------------------------------
	function announce(msg) { var l = $('#live'); l.textContent = ''; setTimeout(function () { l.textContent = msg; }, 30); }
	function openDialog(d) { if (typeof d.showModal === 'function') { d.showModal(); } else { d.setAttribute('open', ''); } }
	function closeDialog(d) { if (typeof d.close === 'function') { d.close(); } else { d.removeAttribute('open'); } }
	function legacyCopy(text) {
		var ta = $('#export-text');
		ta.value = text; ta.focus(); ta.select();
		try { return document.execCommand('copy'); } catch (e) { return false; }
	}
	function copyText(text) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return legacyCopy(text); });
		}
		return Promise.resolve(legacyCopy(text));
	}
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function stamp() { var d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); }
	function short(t, n) { return t.length > n ? t.slice(0, n - 1).replace(/\s+\S*$/, '') + ' …' : t; }
	function quote(t) { return t.split('\n').map(function (l) { return '> ' + l; }).join('\n'); }

	// --- Export ---------------------------------------------------------------------
	function entry(ph, st, s) {
		var lines = ['### ' + st.id + ' ' + st.title + ' (Phase ' + ph.n + ' – ' + ph.title + ')'];
		lines.push('- Ergebnis: ' + STATUS[s.s] + (s.p && (s.s === 'dev' || s.s === 'q') ? ' · Priorität: ' + PRIO[s.p] : ''));
		lines.push('- Rolle: ' + st.role);
		if (s.s === 'dev' || s.s === 'q') { lines.push('- Erwartet laut Protokoll: ' + short(st.expect, 320)); }
		if (s.n) { lines.push('- Notiz:'); lines.push(quote(s.n)); }
		return lines.join('\n');
	}
	function buildExport() {
		var c = countAll();
		var done = c.total.all - c.total.open;
		var out = [];
		out.push('# Testprotokoll-Feedback: Beiträge & SEPA (App ' + data.appVersion + ')');
		out.push('');
		out.push('Stand: ' + stamp() + ' · Protokoll-Build ' + data.build);
		out.push('Fortschritt: ' + done + ' von ' + c.total.all + ' Schritten bearbeitet · OK ' + c.total.ok + ' · Abweichung ' + c.total.dev + ' · Frage ' + c.total.q + ' · übersprungen ' + c.total.skip + ' · offen ' + c.total.open);
		var groups = { dev: [], q: [], other: [] };
		data.phases.forEach(function (ph) {
			ph.steps.forEach(function (st) {
				var s = stepState(st.id);
				if (s.s === 'dev') { groups.dev.push(entry(ph, st, s)); }
				else if (s.s === 'q') { groups.q.push(entry(ph, st, s)); }
				else if (s.n) { groups.other.push(entry(ph, st, s)); }
			});
		});
		function section(title, list) {
			if (!list.length) { return; }
			out.push(''); out.push('## ' + title + ' (' + list.length + ')'); out.push('');
			out.push(list.join('\n\n'));
		}
		section('Abweichungen', groups.dev);
		section('Fragen', groups.q);
		section('Weitere Notizen (zu OK, übersprungenen und offenen Schritten)', groups.other);
		if (!groups.dev.length && !groups.q.length && !groups.other.length) {
			out.push(''); out.push('Keine Abweichungen, Fragen oder Notizen erfasst.');
		}
		var matrix = [];
		[['blocker', 'Blocker'], ['wichtig', 'Wichtig'], ['nice', 'Schön zu haben'], ['', 'Ohne Priorität']].forEach(function (r) {
			var dev = [], q = [];
			data.phases.forEach(function (ph) { ph.steps.forEach(function (st) {
				var s = stepState(st.id);
				if (s.p === r[0] && s.s === 'dev') { dev.push(st.id); }
				if (s.p === r[0] && s.s === 'q') { q.push(st.id); }
			}); });
			if (dev.length || q.length) { matrix.push('| ' + r[1] + ' | ' + (dev.join(', ') || '–') + ' | ' + (q.join(', ') || '–') + ' |'); }
		});
		if (matrix.length) {
			out.push(''); out.push('## Prioritäten-Matrix'); out.push('');
			out.push('| Priorität | Abweichungen | Fragen |'); out.push('|---|---|---|');
			out.push(matrix.join('\n'));
		}
		return out.join('\n') + '\n';
	}
	function doExport() {
		var text = buildExport();
		$('#export-text').value = text;
		$('#copy-status').textContent = '';
		openDialog($('#dlg-export'));
		copyText(text).then(function (ok) {
			$('#copy-status').textContent = ok ? 'In die Zwischenablage kopiert. Du kannst den Text jetzt in den Chat einfügen.' : 'Kopieren war nicht möglich: Markiere den Text unten und kopiere ihn von Hand (⌘A, ⌘C).';
			if (!ok) { var t = $('#export-text'); t.focus(); t.select(); }
		});
	}

	// --- Theme -------------------------------------------------------------------------
	var THEMES = ['auto', 'light', 'dark'];
	var THEME_LABEL = { auto: 'Darstellung: Auto', light: 'Darstellung: Hell', dark: 'Darstellung: Dunkel' };
	function paintTheme() {
		root.setAttribute('data-theme', state.ui.theme);
		$('#btn-theme').textContent = THEME_LABEL[state.ui.theme];
	}

	// --- Weiter zum nächsten offenen Schritt -------------------------------------------------
	function nextOpen() {
		var y = window.scrollY + 140;
		var first = null, target = null;
		for (var i = 0; i < articles.length; i++) {
			var a = articles[i];
			if (a.hidden || stepState(a.getAttribute('data-id')).s !== 'open') { continue; }
			if (!first) { first = a; }
			if (a.getBoundingClientRect().top + window.scrollY > y) { target = a; break; }
		}
		target = target || first;
		if (!target) { announce('Keine offenen Schritte mehr.'); return; }
		target.scrollIntoView({ block: 'start' });
		target.focus({ preventScroll: true });
		announce('Schritt ' + target.getAttribute('data-id'));
	}

	// --- Start ---------------------------------------------------------------------------------
	loadState();
	articles.forEach(function (a) { paintStep(a, true); });
	paintTheme();
	applyFilter();
	paintCounts();
	paintNotices();

	document.addEventListener('change', function (e) {
		var t = e.target;
		var art = t.closest && t.closest('.step');
		if (!art) { return; }
		var id = art.getAttribute('data-id');
		if (t.matches('input[type="radio"]')) { setStep(id, { s: t.value }); }
		else if (t.matches('select[data-prio]')) { setStep(id, { p: t.value }); }
	});
	document.addEventListener('input', function (e) {
		var t = e.target;
		if (t.matches && t.matches('textarea[data-note]')) {
			var art = t.closest('.step');
			setStep(art.getAttribute('data-id'), { n: t.value });
		}
	});
	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('button, [data-filter]');
		if (!b) { return; }
		if (b.hasAttribute('data-filter')) { state.ui.filter = b.getAttribute('data-filter'); scheduleSave(); applyFilter(); return; }
		switch (b.id) {
			case 'btn-export': doExport(); break;
			case 'btn-copy': copyText($('#export-text').value).then(function (ok) { $('#copy-status').textContent = ok ? 'In die Zwischenablage kopiert.' : 'Kopieren war nicht möglich: Text markieren und von Hand kopieren.'; }); break;
			case 'btn-close-export': closeDialog($('#dlg-export')); break;
			case 'btn-next': nextOpen(); break;
			case 'btn-theme': state.ui.theme = THEMES[(THEMES.indexOf(state.ui.theme) + 1) % THEMES.length]; scheduleSave(); paintTheme(); break;
			case 'btn-reset': openDialog($('#dlg-reset')); break;
			case 'btn-reset-cancel': closeDialog($('#dlg-reset')); break;
			case 'btn-reset-confirm':
				state = { build: data.build, steps: {}, ui: { filter: 'all', theme: state.ui.theme } };
				staleBuild = false;
				try { window.localStorage.removeItem(KEY); } catch (err) { /* ohne Speicher egal */ }
				$all('textarea[data-note]').forEach(function (t) { t.value = ''; });
				articles.forEach(function (a) { paintStep(a, true); });
				applyFilter(); paintCounts(); paintNotices();
				closeDialog($('#dlg-reset'));
				announce('Alle Eingaben wurden zurückgesetzt.');
				break;
			case 'btn-build-ok': staleBuild = false; state.build = data.build; saveNow(); break;
			case 'toc-toggle': {
				var toc = $('#toc'); var open = !toc.classList.contains('open');
				toc.classList.toggle('open', open); b.setAttribute('aria-expanded', open ? 'true' : 'false');
				break;
			}
			case 'more-toggle': {
				var more = $('#more'); var o = !more.classList.contains('open');
				more.classList.toggle('open', o); $('.topbar').classList.toggle('more-open', o); b.setAttribute('aria-expanded', o ? 'true' : 'false');
				break;
			}
			default: break;
		}
	});
	$all('dialog').forEach(function (d) { d.addEventListener('click', function (e) { if (e.target === d) { closeDialog(d); } }); });
	$all('#toc a').forEach(function (a) { a.addEventListener('click', function () { var toc = $('#toc'); toc.classList.remove('open'); $('#toc-toggle').setAttribute('aria-expanded', 'false'); }); });

	// Höhe der Kopfleiste für Sprungmarken und die Inhaltsleiste
	function measure() { root.style.setProperty('--topbar-h', $('.topbar').offsetHeight + 'px'); }
	measure();
	window.addEventListener('resize', measure);
	if (window.ResizeObserver) { new ResizeObserver(measure).observe($('.topbar')); }

	// Aktiver Eintrag im Inhaltsverzeichnis
	if ('IntersectionObserver' in window) {
		var sections = $all('.phase, .chapter');
		var visible = {};
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (en) { visible[en.target.id] = en.isIntersecting; });
			var current = null;
			for (var i = 0; i < sections.length; i++) { if (visible[sections[i].id]) { current = sections[i].id; break; } }
			$all('#toc a').forEach(function (a) { if (current && a.getAttribute('href') === '#' + current) { a.setAttribute('aria-current', 'true'); } else { a.removeAttribute('aria-current'); } });
		}, { rootMargin: '-20% 0px -60% 0px' });
		sections.forEach(function (s) { io.observe(s); });
	}
})();
`

function appVersion() {
	const info = resolve(HERE, '..', '..', 'appinfo', 'info.xml')
	if (existsSync(info)) {
		const m = /<version>([^<]+)<\/version>/.exec(readFileSync(info, 'utf8'))
		if (m) { return m[1].trim() }
	}
	return 'unbekannt'
}

function buildHtml(doc, md) {
	const version = appVersion()
	const build = createHash('sha256').update(md).digest('hex').slice(0, 8)
	const dataJson = JSON.stringify({
		appVersion: version,
		build,
		title: doc.title,
		phases: doc.phases.map((p) => ({
			n: p.n,
			title: p.title,
			steps: p.steps.map((s) => ({
				id: s.id, title: s.title, role: plain(s.sections.Rolle || []), expect: plain(s.sections.Erwartet || []),
			})),
		})),
	}).replace(/</g, '\\u003c').replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029')

	const tocItems = []
	tocItems.push('<li><a href="#einleitung"><span>Einleitung</span></a></li>')
	for (const sec of doc.order) {
		if (sec.kind === 'chapter') {
			tocItems.push(`<li><a href="#kap-${slug(sec.title)}"><span>${esc(sec.title)}</span></a></li>`)
		} else {
			tocItems.push(`<li><a href="#phase-${sec.n}"><span>${sec.n}&nbsp;${esc(sec.title)}</span><span class="tc" data-toc-count="${sec.n}">0/${sec.steps.length}</span></a></li>`)
		}
	}
	const filters = [['all', 'Alle'], ['open', 'Offen'], ['dev', 'Abweichung'], ['q', 'Frage'], ['note', 'Mit Notiz']]
		.map(([v, l]) => `<button type="button" class="btn" data-filter="${v}" aria-pressed="${v === 'all'}">${l}</button>`).join('')

	const body = doc.order.map((s) => (s.kind === 'phase' ? renderPhase(s) : renderChapter(s))).join('\n')
	const intro = `<section class="chapter" id="einleitung" aria-labelledby="h-einleitung"><h2 id="h-einleitung" class="sr-only">Einleitung</h2>${blocks(doc.intro)}</section>`

	return `<!doctype html>
<html lang="de" data-theme="auto">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>${esc(doc.title)}</title>
<style>${CSS}</style>
</head>
<body>
<a class="skip" href="#inhalt">Zum Inhalt springen</a>
<header class="topbar">
<div class="topbar-inner">
<div class="row">
<button type="button" class="btn only-mobile" id="toc-toggle" aria-controls="toc" aria-expanded="false">Inhalt</button>
<h1>Testprotokoll Beiträge &amp; SEPA <small>App ${esc(version)}</small></h1>
<div class="overall"><progress id="overall-progress" max="1" value="0" aria-label="Gesamtfortschritt"></progress><span class="overall-text" id="overall-text">0 von 0 bearbeitet</span></div>
</div>
<div class="counts" aria-label="Zählung nach Ergebnis"><span class="chip chip-ok" id="cnt-ok">OK 0</span><span class="chip chip-dev" id="cnt-dev">Abweichung 0</span><span class="chip chip-q" id="cnt-q">Frage 0</span><span class="chip chip-skip" id="cnt-skip">Übersprungen 0</span><span class="chip chip-open" id="cnt-open">Offen 0</span></div>
<div class="row toolbar" role="toolbar" aria-label="Werkzeuge">
<button type="button" class="btn" id="btn-next"><span class="lg">Nächster offener Schritt</span><span class="sm">Weiter</span></button>
<button type="button" class="btn primary" id="btn-export"><span class="lg">Feedback exportieren</span><span class="sm">Export</span></button>
<button type="button" class="btn only-mobile" id="more-toggle" aria-controls="more" aria-expanded="false">Filter</button>
<div class="row more" id="more">
<div class="filters" role="group" aria-label="Schritte filtern"><span class="label">Anzeigen:</span>${filters}</div>
<button type="button" class="btn" id="btn-theme">Darstellung: Auto</button>
<button type="button" class="btn danger" id="btn-reset">Zurücksetzen</button>
</div>
</div>
<p class="notice" id="storage-notice" role="status" hidden>Der Browser-Speicher ist nicht verfügbar: Deine Eingaben bleiben nur, solange diese Seite offen ist. Exportiere dein Feedback vor dem Schließen.</p>
<p class="notice" id="build-notice" role="status" hidden>Das Protokoll wurde seit deinen letzten Eingaben neu gebaut. Deine Eingaben sind erhalten, die Schrittnummern könnten sich aber verschoben haben. <button type="button" class="btn" id="btn-build-ok">Verstanden</button></p>
</div>
</header>
<div class="layout">
<nav class="toc" id="toc" aria-label="Inhaltsverzeichnis"><h2>Inhalt</h2><ol>${tocItems.join('')}</ol></nav>
<main id="inhalt">
${intro}
${body}
</main>
</div>
<div id="live" class="sr-only" role="status" aria-live="polite"></div>
<dialog id="dlg-export" aria-labelledby="h-export">
<h2 id="h-export">Feedback exportieren</h2>
<p>Dieser Markdown-Text enthält alle Abweichungen, Fragen und Notizen. Er wurde in die Zwischenablage gelegt. Füge ihn in den Chat ein.</p>
<label class="sr-only" for="export-text">Exportierter Feedback-Text</label>
<textarea id="export-text" readonly spellcheck="false"></textarea>
<p class="copy-status" id="copy-status" role="status"></p>
<div class="dialog-actions"><button type="button" class="btn primary" id="btn-copy">Erneut kopieren</button><button type="button" class="btn" id="btn-close-export">Schließen</button></div>
</dialog>
<dialog id="dlg-reset" aria-labelledby="h-reset">
<h2 id="h-reset">Alles zurücksetzen?</h2>
<p>Alle Ergebnisse, Prioritäten und Notizen in diesem Browser werden gelöscht. Das lässt sich nicht rückgängig machen. Exportiere dein Feedback vorher, falls du es noch brauchst.</p>
<div class="dialog-actions"><button type="button" class="btn" id="btn-reset-cancel">Abbrechen</button><button type="button" class="btn danger" id="btn-reset-confirm">Alles löschen</button></div>
</dialog>
<script type="application/json" id="protocol-data">${dataJson}</script>
<script>${SCRIPT}</script>
</body>
</html>
`
}

// ---------------------------------------------------------------------------
// Selbsttests (laufen bei jedem Aufruf)
// ---------------------------------------------------------------------------
const VOID = new Set(['meta', 'br', 'input', 'link', 'img', 'hr'])

function checkHtml(html) {
	const problems = []
	const noScript = html.replace(/<script[\s\S]*?<\/script>/g, '<script></script>').replace(/<style[\s\S]*?<\/style>/g, '<style></style>')
	const stack = []
	const re = /<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(\/?)>/g
	let m
	while ((m = re.exec(noScript)) !== null) {
		const [, closing, name, selfClose] = m
		const tag = name.toLowerCase()
		if (VOID.has(tag) || selfClose) { continue }
		if (closing) {
			const top = stack.pop()
			if (top !== tag) {
				problems.push(`HTML: </${tag}> schließt <${top ?? 'nichts'}>.`)
				if (problems.length > 8) { break }
			}
		} else { stack.push(tag) }
	}
	if (stack.length) { problems.push(`HTML: ungeschlossene Tags: ${stack.slice(-5).join(', ')}`) }
	const ids = [...html.matchAll(/ id="([^"]+)"/g)].map((x) => x[1])
	const dup = ids.filter((id, i) => ids.indexOf(id) !== i)
	if (dup.length) { problems.push(`HTML: doppelte ids: ${[...new Set(dup)].slice(0, 5).join(', ')}`) }
	if (/(?:src|href)="https?:\/\/(?!stable34\.local|mail\.local|github\.com)[^"]*\.(?:js|css|woff2?)/.test(html)) { problems.push('HTML: externe Ressource gefunden (die Seite muss ohne Netz funktionieren).') }
	return problems
}

function checkScript() {
	try {
		new vm.Script(SCRIPT, { filename: 'testprotokoll-script.js' })
		return []
	} catch (e) {
		return [`Script-Syntaxfehler: ${e.message}`]
	}
}

function checkContrast() {
	const problems = []
	for (const [mode, t] of Object.entries(THEME)) {
		for (const [fg, bg] of CONTRAST_PAIRS) {
			const c = contrast(t[fg], t[bg])
			if (c < 4.5) { problems.push(`Kontrast ${mode}: ${fg} auf ${bg} nur ${c.toFixed(2)} : 1 (nötig 4,5)`) }
		}
		for (const [fg, bg] of NON_TEXT_PAIRS) {
			const c = contrast(t[fg], t[bg])
			if (c < 3) { problems.push(`Kontrast ${mode}: ${fg} auf ${bg} nur ${c.toFixed(2)} : 1 (nötig 3)`) }
		}
	}
	return problems
}

// ---------------------------------------------------------------------------
function main() {
	const args = process.argv.slice(2)
	const checkOnly = args.includes('--check')
	const outIdx = args.indexOf('--out')
	const out = outIdx !== -1 ? resolve(process.cwd(), args[outIdx + 1]) : resolve(HERE, 'testprotokoll.html')

	if (!existsSync(SOURCE)) {
		console.error(`Quelle fehlt: ${SOURCE}`)
		process.exit(2)
	}
	const md = readFileSync(SOURCE, 'utf8')
	const { doc, errors } = parse(md)
	const { warnings, stepCount } = validate(doc, errors)
	const html = buildHtml(doc, md)
	const problems = [...errors, ...checkHtml(html), ...checkScript(), ...checkContrast()]

	for (const w of warnings) { console.warn(`Warnung: ${w}`) }
	if (problems.length) {
		for (const p of problems) { console.error(`Fehler: ${p}`) }
		console.error(`\n${problems.length} Fehler – es wurde nichts geschrieben.`)
		process.exit(1)
	}
	const seeds = (md.match(/laut Seeder – ggf\. abweichend/g) || []).length
	const unproven = (md.match(/nicht belegt/g) || []).length
	console.log(`${doc.phases.length} Phasen, ${stepCount} Schritte, ${doc.chapters.length} Textkapitel (${seeds} Seeder-Markierungen, ${unproven} „nicht belegt"-Vermerke).`)
	if (checkOnly) {
		console.log('Prüfung bestanden (Struktur, Querverweise, HTML, Script-Syntax, Kontraste).')
		return
	}
	writeFileSync(out, html, 'utf8')
	console.log(`Geschrieben: ${out} (${Math.round(html.length / 1024)} KB)`)
}

main()
