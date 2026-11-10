import { readdirSync, readFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

// Vereinswährung (Issue #202): Beträge und Beschriftungen nehmen das Zeichen
// aus lib/clubCurrency.js. Ein fest geschriebenes „€“ zeigte einem Verein mit
// CHF wieder Euro an. Dieser Wächter meldet jede neue Stelle.
//
// Ausgenommen sind gesetzliche Euro-Grenzen (Freigrenze, § 19 UStG) und
// Beispieltexte; sie sind Euro, egal welche Vereinswährung gilt.

const SRC = fileURLToPath(new URL('..', import.meta.url))

// Datei → Teiltexte, deren Zeilen ein „€“ enthalten dürfen.
const ALLOWED = {
	'components/HelpModal.vue': ['45.000 €'],
	'components/InvoicesTab.vue': ['25.000 €'],
	'components/BudgetTreeTable.vue': ['× 25 € Beitrag'],
}

function sourceFiles(dir) {
	const out = []
	for (const entry of readdirSync(dir, { withFileTypes: true })) {
		const path = join(dir, entry.name)
		if (entry.isDirectory()) {
			out.push(...sourceFiles(path))
		} else if (/\.(vue|js)$/.test(entry.name) && !entry.name.endsWith('.test.js')) {
			out.push(path)
		}
	}
	return out
}

function isComment(line) {
	const s = line.trim()
	return s.startsWith('//') || s.startsWith('*') || s.startsWith('/*') || s.startsWith('<!--')
}

function offenders(test) {
	const found = []
	for (const file of sourceFiles(SRC)) {
		const rel = relative(SRC, file).replace(/\\/g, '/')
		if (rel === 'lib/clubCurrency.js') { continue }
		readFileSync(file, 'utf8').split(/\r?\n/).forEach((line, i) => {
			if (isComment(line) || !test(line)) { return }
			if ((ALLOWED[rel] || []).some((part) => line.includes(part))) { return }
			found.push(`${rel}:${i + 1}: ${line.trim()}`)
		})
	}
	return found
}

describe('kein fest geschriebenes Euro in der Oberfläche', () => {
	it('kein „€“ außerhalb von Rechtsgrenzen und Beispielen', () => {
		expect(offenders((line) => line.includes('€'))).toEqual([])
	})

	it('kein Intl-Format mit festem currency: \'EUR\'', () => {
		expect(offenders((line) => /currency:\s*'EUR'/.test(line))).toEqual([])
	})
})
