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

/** Trifft die Klasse als ganzes Wort - `.vbh-x` nicht in `.vbh-x-gross`. */
function mentionsClass(cls) {
	return new RegExp(`\\.${cls}(?![\\w-])`, 'g')
}

/**
 * Klassen, die im Markup an einem nackten <button> haengen. Mit nurAktive nur
 * die Knoepfe, die auch .active tragen koennen - nur fuer die greift
 * Nextclouds .active-Regel.
 */
function bareButtonClasses({ nurAktive = false } = {}) {
	const classes = new Set()
	for (const file of vueFiles(SRC)) {
		const source = readFileSync(file, 'utf-8')
		for (const tag of source.matchAll(/<button\b([^>]*)>/gs)) {
			// Nur Klassen-Schluessel namens "active" zaehlen - Ausdruecke wie
			// bookingTour.active im :class-Objekt sind Daten, keine Klasse.
			if (nurAktive && !/[{,]\s*'?active'?\s*:/.test(tag[1]) && !/class="[^"]*\bactive\b[^"]*"/.test(tag[1])) { continue }
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
function parseRules() {
	const css = readFileSync(join(SRC, 'styles.css'), 'utf-8').replace(/\/\*[\s\S]*?\*\//g, '')
	const rules = []
	for (const rule of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
		rules.push({ selectors: rule[1].trim(), body: rule[2] })
	}
	return rules
}

function ruleIndex() {
	return parseRules().filter((r) => CLAIMED.some((prop) => new RegExp(`(^|;|\\s)${prop}\\s*:`).test(r.body)))
}

/**
 * Ist dieser einzelne Selektor gegen Nextclouds Button-Regel geschuetzt?
 * Geschuetzt heisst: er nennt die Klasse mindestens zweimal (0,2,0) oder
 * bringt sonst genug Klassen mit, um (0,1,1) zu ueberbieten.
 */
function isProtected(selector, cls) {
	if ((selector.match(mentionsClass(cls)) || []).length >= 2) { return true }
	// Weitere Klassen im selben Selektor zaehlen ebenfalls: .vbh-x.active o. ae.
	return classCount(selector) >= 2
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
			const mentions = mentionsClass(cls)
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

/**
 * Malt die Regel eine echte Rahmenfarbe? Nextclouds .active-Regel setzt nur
 * border-*-color, also zaehlen auch nur Eigenschaften, die eine Farbe tragen
 * koennen - border-radius/-width/-style bleiben aussen vor. `border: none` und
 * transparente Rahmen ebenfalls, die haben keine Farbe zu verlieren.
 */
function paintsBorder(body) {
	for (const decl of body.split(';')) {
		const prop = (decl.match(/^\s*(border[a-z-]*)\s*:/) || [])[1]
		if (!prop || /radius|width|style/.test(prop)) { continue }
		if (/var\(|#[0-9a-f]{3}|rgb/i.test(decl)) { return true }
	}
	return false
}

function classCount(selector) {
	return (selector.match(/\.[a-zA-Z_][\w-]*/g) || []).length
}

describe('styles.css: Schutz gegen Nextclouds .active-Regel', () => {
	// button:not(.button-vue, [class^=vs__]):not(:disabled, .primary)
	//   :not(.app-navigation-entry-button).active { border-color: var(--color-main-text) }
	// liegt bei (0,4,1) und schlaegt damit auch die doppelte Klasse (0,2,0).
	// Betroffen ist nur die Rahmenfarbe - deshalb pruefen wir genau die.
	const NEEDED = 5

	it('findet ueberhaupt nackte <button> mit .active', () => {
		expect(bareButtonClasses({ nurAktive: true }).size).toBeGreaterThan(2)
	})

	it('jede Rahmenfarbe an einem .active-Knopf bringt fuenf Klassen mit', () => {
		// Nicht ueber ruleIndex(): dessen CLAIMED-Liste kennt nur `border`,
		// womit border-inline-start durchfiele - der gemeldete Fall.
		const rules = parseRules().filter((r) => paintsBorder(r.body))
		const unprotected = []
		for (const cls of bareButtonClasses({ nurAktive: true })) {
			const mentions = mentionsClass(cls)
			const treffer = rules.filter((r) => mentions.test(r.selectors))
			// Definiert eine geschuetzte .active-Regel den aktiven Rahmen, duerfen
			// die uebrigen Regeln schwaecher bleiben - im aktiven Zustand gewinnt
			// ohnehin sie.
			const activeGeschuetzt = treffer.some((r) => /\.active(?![\w-])/.test(r.selectors) && classCount(r.selectors) >= NEEDED)
			for (const rule of treffer) {
				const istActive = /\.active(?![\w-])/.test(rule.selectors)
				if (classCount(rule.selectors) >= NEEDED) { continue }
				if (!istActive && activeGeschuetzt) { continue }
				unprotected.push(`${rule.selectors}  (Klasse ${cls})`)
			}
		}
		expect(unprotected).toEqual([])
	})
})

describe('Toast-Styles aus @nextcloud/dialogs', () => {
	// Seit 7.5.0 tragen Toasts CSS-Modules-Klassen, die allein das
	// Paket-Stylesheet kennt - ohne den Import in jedem Webpack-Einstieg
	// rendern showSuccess/showError als nackter Text.
	it.each(['main.js', 'settings.js'])('%s importiert @nextcloud/dialogs/style.css', (entry) => {
		const source = readFileSync(join(SRC, entry), 'utf-8')
		expect(source).toMatch(/^import '@nextcloud\/dialogs\/style\.css'$/m)
	})

	// 7.5.0 setzt Toasts ausserdem nach unten links, Nextclouds eigene bleiben
	// oben rechts - src/toast-position.css raeumt das auf und gehoert damit in
	// dieselben Einstiege wie das Paket-Stylesheet.
	it.each(['main.js', 'settings.js'])('%s importiert toast-position.css', (entry) => {
		const source = readFileSync(join(SRC, entry), 'utf-8')
		expect(source).toMatch(/^import '\.\/toast-position\.css'$/m)
	})

	// Der Hash im CSS-Modules-Namen wechselt mit jedem Release, deshalb trifft
	// die Regel nur den Teilstring; das vorangestellte `body` liefert die
	// Spezifitaet, die die Regeln des Pakets schlaegt.
	it('toast-position.css trifft den Container hash-unabhaengig und mit genug Spezifitaet', () => {
		const source = readFileSync(join(SRC, 'toast-position.css'), 'utf-8')
		expect(source).toMatch(/body \[class\*="_toastContainer_"\]/)
	})

	// Gegenstueck dazu: die Annahme ueber das Paket selbst pruefen, nicht nur
	// unsere Datei gegen sich. Benennt @nextcloud/dialogs die Klasse um, gibt
	// CSS-Modules auf oder positioniert von sich aus wieder oben rechts, dann
	// ist toast-position.css wirkungslos bzw. ueberfluessig - beides faellt
	// sonst erst auf, wenn Toasts in der falschen Ecke stehen.
	it('@nextcloud/dialogs setzt die Toasts weiterhin nach unten links', () => {
		const paket = readFileSync(join(SRC, '..', 'node_modules', '@nextcloud', 'dialogs', 'dist', 'style.css'), 'utf-8')
		const regeln = [...paket.matchAll(/\.(_toastContainer_[a-z0-9_]+)\s*\{([^}]*)\}/gi)]
		expect(regeln.length).toBeGreaterThan(0)
		// Mindestens eine Regel muss die Ecke setzen, die wir ueberschreiben.
		expect(regeln.some((r) => /(^|;)\s*left\s*:/.test(r[2]) && /(^|;)\s*bottom\s*:/.test(r[2]))).toBe(true)
	})
})
