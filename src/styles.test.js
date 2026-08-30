import { readdirSync, readFileSync } from 'fs'
import { join } from 'path'
import { fileURLToPath } from 'url'
import { describe, expect, it } from 'vitest'

// Nextcloud formt in core/css/server.css jeden nackten <button> zum
// Sekundaerknopf und setzt dabei background-color, color, border, width,
// padding und min-height:
//
//   select, button:not(.button-vue, [class^=vs__]), .button, … { … }
//
// Der Selektor liegt bei Spezifitaet (0,1,1) und schlaegt damit jede
// Ein-Klassen-Regel der App (0,1,0) – die Knoepfe erscheinen dann blau
// hinterlegt statt in ihrer eigenen Farbe. Das Gegenmittel ist die doppelte
// Klasse (.vbh-x.vbh-x), siehe Kopfkommentar in styles.css.
//
// Diese Invariante ist statisch pruefbar, und ein Kommentar allein haelt sie
// nicht: beim Umstellen selbst wurde ein Knopf uebersehen. Der Test vergleicht
// deshalb die Klassen an nackten <button> im Markup mit den Regeln, die sie
// stylen, und meldet jede Regel, die den Schutz nicht hat.

const SRC = fileURLToPath(new URL('.', import.meta.url))

// Die Eigenschaften, die Nextclouds Button-Regel beansprucht. Nur Regeln, die
// eine davon setzen, brauchen den Schutz.
const CLAIMED = ['background', 'background-color', 'color', 'border', 'padding', 'min-height', 'width', 'margin', 'font-size']

function vueFiles(dir) {
	const out = []
	for (const entry of readdirSync(dir, { withFileTypes: true })) {
		const full = join(dir, entry.name)
		if (entry.isDirectory()) {
			out.push(...vueFiles(full))
		} else if (entry.name.endsWith('.vue')) {
			out.push(full)
		}
	}
	return out
}

/** Alle Klassen, die im Markup an einem nackten <button> haengen. */
function bareButtonClasses() {
	const classes = new Set()
	for (const file of vueFiles(SRC)) {
		const source = readFileSync(file, 'utf-8')
		for (const tag of source.matchAll(/<button\b([^>]*)>/gs)) {
			for (const attr of tag[1].matchAll(/(?::class|class)="([^"]*)"/g)) {
				for (const token of attr[1].matchAll(/vbh-[a-z0-9-]+/g)) { classes.add(token[0]) }
			}
		}
	}
	return classes
}

/**
 * Regeln aus styles.css, die eine der beanspruchten Eigenschaften setzen,
 * aufgeschluesselt nach der Klasse, die sie adressieren. Kommentare fallen
 * vorher raus, damit Beispielselektoren in Kommentaren nicht mitzaehlen.
 */
function ruleIndex() {
	const css = readFileSync(join(SRC, 'styles.css'), 'utf-8').replace(/\/\*[\s\S]*?\*\//g, '')
	const rules = []
	for (const rule of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
		const selectors = rule[1].trim()
		const body = rule[2]
		if (!CLAIMED.some((prop) => new RegExp(`(^|;|\\s)${prop}\\s*:`).test(body))) { continue }
		rules.push({ selectors, body })
	}
	return rules
}

/**
 * Ist dieser einzelne Selektor gegen Nextclouds Button-Regel geschuetzt?
 * Geschuetzt heisst: er nennt die Klasse mindestens zweimal (0,2,0) oder
 * bringt sonst genug Klassen mit, um (0,1,1) zu ueberbieten.
 */
function isProtected(selector, cls) {
	const escaped = cls.replace(/[-]/g, '\\-')
	const occurrences = (selector.match(new RegExp(`\\.${escaped}(?![\\w-])`, 'g')) || []).length
	if (occurrences >= 2) { return true }
	// Weitere Klassen im selben Selektor zaehlen ebenfalls: .vbh-x.active o. ae.
	const totalClasses = (selector.match(/\.[a-zA-Z_][\w-]*/g) || []).length
	return totalClasses >= 2
}

describe('styles.css: Schutz gegen Nextclouds Button-Grundstil', () => {
	const buttonClasses = bareButtonClasses()

	it('findet ueberhaupt nackte <button> mit App-Klassen', () => {
		// Reisst der Regex ueber dem Markup, wuerde der Test unten stumm gruen.
		expect(buttonClasses.size).toBeGreaterThan(10)
	})

	it('jede Regel, die einen nackten <button> einfaerbt, hat die doppelte Klasse', () => {
		const rules = ruleIndex()
		const unprotected = []
		for (const cls of buttonClasses) {
			const escaped = cls.replace(/[-]/g, '\\-')
			const mentions = new RegExp(`\\.${escaped}(?![\\w-])`)
			for (const rule of rules) {
				// Selektorlisten einzeln pruefen: `.a, .b.b` schuetzt nur .b
				for (const selector of rule.selectors.split(',')) {
					if (!mentions.test(selector)) { continue }
					// Nur Regeln, die den Knopf SELBST treffen. `.vbh-x strong`
					// stylt ein Kind – Nextclouds button-Regel greift dort nicht.
					const target = selector.trim().split(/\s+|>/).filter(Boolean).pop() || ''
					if (!mentions.test(target)) { continue }
					if (isProtected(target, cls)) { continue }
					unprotected.push(`${selector.trim()}  (Klasse ${cls})`)
				}
			}
		}
		expect(unprotected).toEqual([])
	})
})
