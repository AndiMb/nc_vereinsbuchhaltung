import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterAll, describe, expect, it } from 'vitest'
import { CONTEXT_SEPARATOR, extractAll, extractBackend, extractFrontend, printable, readBundle, tokens } from './extract.mjs'

// Der Katalog der Übersetzungen (Issue #106): jeder Text der App, der durch
// t()/n()/tc()/tRaw() bzw. $l->t()/$l->n() läuft, hat einen Eintrag in
// l10n/en.json; l10n/de.json trägt die Du-Fassung der Texte an Mitglieder.
//
// Dieser Test kennt keinen einzelnen Schlüssel: tests/l10n/extract.mjs liest die
// Aufrufe aus den Quelldateien. Ein neuer Text ohne englische Fassung macht ihn
// rot – `npm run l10n:check` listet dann alles Fehlende mit fertigen
// Einfüge-Zeilen.

const { keys } = extractAll()
const en = readBundle('en').translations
const de = readBundle('de').translations
const deFormal = readBundle('de_DE').translations

function where(key) {
	const call = keys.get(key)?.[0]
	return call ? `${call.file}:${call.line}` : 'ohne Fundstelle'
}

function report(list, describe = (key) => `${where(key)}  ${JSON.stringify(printable(key))}`) {
	return list.map(describe).join('\n')
}

describe('en.json', () => {
	it('enthält jeden Text, der im Code übersetzt wird', () => {
		const missing = [...keys.keys()].filter((key) => !(key in en))
		expect(report(missing), `${missing.length} Texte ohne englische Fassung (npm run l10n:check zeigt sie)`).toBe('')
	})

	it('hat zu jedem Eintrag eine nicht leere Übersetzung, bei Pluralformen zwei', () => {
		const broken = Object.entries(en).filter(([key, value]) => {
			if (key.startsWith('_') && key.includes('_::_')) {
				return !(Array.isArray(value) && value.length === 2 && value.every((v) => typeof v === 'string' && v.trim() !== ''))
			}
			return typeof value !== 'string' || value.trim() === ''
		})
		expect(broken.map(([key]) => printable(key))).toEqual([])
	})

	it('behält in jeder Fassung die Platzhalter und Tags des Quelltexts', () => {
		// Platzhalter dürfen nicht dazukommen. Weglassen darf eine Übersetzung nur ein unbenanntes „%s" – etwa
		// das deutsche Pluralsuffix in „Buchungszeile%s", das es im Englischen nicht gibt.
		const unnamed = /^%[sd]$/
		function problem(source, translation) {
			const sourceTokens = tokens(source)
			const translated = tokens(translation)
			const rest = [...sourceTokens]
			for (const token of translated) {
				const index = rest.indexOf(token)
				if (index < 0) { return `„${token}" kommt im Quelltext nicht vor` }
				rest.splice(index, 1)
			}
			const lost = rest.filter((token) => !unnamed.test(token))
			return lost.length > 0 ? `fehlt: ${lost.join(' ')}` : null
		}
		const mismatched = []
		for (const [key, value] of Object.entries(en)) {
			if (!keys.has(key)) { continue }
			const pairs = key.startsWith('_') && key.includes('_::_') && Array.isArray(value)
				? key.slice(1, -1).split('_::_').map((source, index) => [source, value[index]])
				: [[key.split(CONTEXT_SEPARATOR).pop(), value]]
			for (const [source, translation] of pairs) {
				const message = typeof translation === 'string' ? problem(source, translation) : null
				if (message) { mismatched.push([key, `${message} – ${JSON.stringify(translation)}`]) }
			}
		}
		expect(report(mismatched, ([key, message]) => `${where(key)}  ${JSON.stringify(printable(key))}: ${message}`)).toBe('')
	})

	it('lässt in den übersetzten Texten kein Deutsch stehen', () => {
		// Ausnahme: die Vorschau des Mandats-Rechtstexts nennt den deutschen Platzhalter-Ersatz wörtlich.
		const allowed = ['Der Vereinsname ist noch nicht eingetragen']
		const german = /[äöüÄÖÜß]|\b(und|der|die|das|nicht|für|mit|wird|werden|ist|sind|oder|noch|nur|Sie|Ihr|Ihre|bitte)\b/
		const leftovers = []
		for (const [key, value] of Object.entries(en)) {
			if (!keys.has(key) || allowed.some((prefix) => key.startsWith(prefix))) { continue }
			for (const text of Array.isArray(value) ? value : [value]) {
				if (german.test(text)) { leftovers.push([key, text]) }
			}
		}
		expect(report(leftovers, ([key, text]) => `${where(key)}  ${JSON.stringify(text)}`)).toBe('')
	})
})

describe('Sie-Form im Quelltext, Du-Fassung in l10n/de.json', () => {
	it('verwendet in keinem Quelltext Du, Dir, Dein oder Euch', () => {
		const du = /\b(du|dich|dir|dein|deine|deinen|deinem|deiner|deines|euch|euer|eure|eurem|euren)\b/i
		const offenders = [...keys.keys()].filter((key) => du.test(key.split(CONTEXT_SEPARATOR).pop()))
		expect(report(offenders)).toBe('')
	})

	it('beginnt keinen Satz mit einer Du-Aufforderung („Wähle …", „Lege …")', () => {
		const imperative = /(^|[.!?:–] )(Wähle|Lege|Importiere|Plane|Friere|Setze|Gib|Trage|Klicke|Prüfe|Beachte|Achte|Stelle|Speichere|Bestätige|Ordne|Buche|Öffne|Füge|Erstelle|Entferne|Ändere|Korrigiere|Verwende|Verwirf|Sende|Schicke|Lösche|Markiere|Vermerke|Erfasse|Erzeuge|Entsperre|Wende|Sorge|Überweise)\b/
		const offenders = [...keys.keys()].filter((key) => imperative.test(key.split(CONTEXT_SEPARATOR).pop()))
		expect(report(offenders)).toBe('')
	})

	it('l10n/de_DE.json ist leer: förmliches Deutsch ist der Quelltext', () => {
		expect(deFormal).toEqual({})
	})

	it('l10n/de.json übersetzt nur Texte, die es im Code gibt', () => {
		const stale = Object.keys(de).filter((key) => !keys.has(key))
		expect(stale.map(printable)).toEqual([])
	})

	it('l10n/de.json liefert echte Du-Fassungen mit denselben Platzhaltern', () => {
		const problems = []
		for (const [key, value] of Object.entries(de)) {
			if (typeof value !== 'string' || value.trim() === '' || value === key) { problems.push(`${printable(key)}: leer oder unverändert`) }
			else if (tokens(key).join() !== tokens(value).join()) { problems.push(`${printable(key)}: Platzhalter weichen ab`) }
			// förmliche Anrede („Ihr …", „Wählen Sie …", „bitten wir Sie") hat in der Du-Fassung nichts verloren
			else if (/\b(Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihres|Ihnen|Ihrerseits)\b|\b\p{L}+(en|t) Sie\b|\bwir Sie\b/u.test(value)) { problems.push(`${printable(key)}: noch förmlich`) }
		}
		expect(problems).toEqual([])
	})

	it('l10n/de.json deckt jeden Mitgliedertext mit förmlicher Anrede ab', () => {
		// Texte an Mitglieder: Mails, Aktivitäten und die Oberfläche von „Mein Beitrag". Die Texte an die
		// Verwaltung (Akte, Einzug, Einstellungen) sprechen die Buchhaltung mit Sie an und bleiben ohne Du-Fassung.
		const memberFacing = [
			/^lib\/Service\/(ContributionPreNotificationService|DunningLadderService|MandateActivationService|SelfContactService|SelfContributionService|SelfServiceMandateService|SelfServiceReceiptMailService|SelfServiceReceiptMailer)\.php$/,
			/^lib\/Activity\//,
			/^lib\/Service\/Sepa\/ReturnReasonClassifier\.php$/,
			/^src\/components\/SelfService[A-Za-z]*\.vue$/,
		]
		const formal = /\b(Sie|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihres|Ihnen|Ihrerseits)\b|^Guten Tag/
		// Der Dialog MandateDraftDiscardDialog.vue dient Verwaltung und Mitglied; seine zwei Mitgliedertexte
		// stehen in de.json, werden hier aber nicht verlangt (die zwei anderen sprechen die Verwaltung an).
		// Ein Satz an die Verwaltung im gemeinsamen Widerrufs-Dialog (die Verwaltung kann entsperren, das Mitglied nicht).
		const staffOnly = ['Nur ein neues Konto? Entsperren Sie das Mandat zuerst']
		const missing = [...keys.entries()]
			.filter(([key]) => !staffOnly.some((prefix) => key.startsWith(prefix)))
			.filter(([key, calls]) => formal.test(key) && !(key in de) && calls.some((call) => memberFacing.some((pattern) => pattern.test(call.file))))
			.map(([key]) => key)
		expect(report(missing)).toBe('')
	})
})

describe('Nutzerdaten in t()', () => {
	it('stehen nie als Variablen in t(), n() oder tc() – dafür gibt es tRaw()', () => {
		// @nextcloud/l10n escaped jede Variable als HTML („Echo & Söhne" wird zu „Echo &amp; Söhne"), unsere
		// Texte gehen aber als Text in die Seite. Namen, Mailadressen, Benutzernamen, Bezeichnungen und
		// Freitext setzt tRaw() deshalb erst nach der Übersetzung ein (src/lib/l10n.js).
		const userData = new Set(['name', 'names', 'inhaber', 'email', 'uid', 'wer', 'who', 'user', 'counterparty', 'referenz', 'label', 'account', 'value', 'code', 'id', 'grund', 'period', 'category'])
		const { calls } = extractFrontend()
		const offenders = calls
			.filter((call) => call.kind !== 'tRaw')
			.filter((call) => [...call.key.matchAll(/{([^{}]*)}/g)].some((match) => userData.has(match[1])))
		expect(offenders.map((call) => `${call.file}:${call.line}  ${JSON.stringify(printable(call.key))}`)).toEqual([])
	})
})

describe('Mandats-Rechtstext', () => {
	it('bleibt ohne Übersetzung: weder Formular noch Zustimmungsseite rufen t() auf', () => {
		// Der Rechtstext gilt nur auf Deutsch (Spec §3.11). Das Formular und die öffentliche Zustimmungsseite
		// stehen deshalb bewusst nicht im Katalog – ein t() dort würde ein Sprachgemisch erzeugen.
		const files = new Set([...extractBackend().calls].map((call) => call.file))
		expect(files.has('lib/Service/MandateFormRenderer.php')).toBe(false)
		expect(files.has('templates/mandateConsent.php')).toBe(false)
		expect(files.has('lib/Service/MandateLegalTextService.php')).toBe(true) // die Fehlermeldungen des Editors an die Verwaltung
	})
})

describe('Extraktor', () => {
	const created = []
	afterAll(() => created.forEach((dir) => rmSync(dir, { recursive: true, force: true })))

	/** Ein frischer Projektordner je Test, damit sich die Beispiele nicht beeinflussen. */
	function project(files) {
		const root = mkdtempSync(join(tmpdir(), 'l10n-extract-'))
		created.push(root)
		for (const [path, content] of Object.entries(files)) {
			mkdirSync(join(root, path, '..'), { recursive: true })
			writeFileSync(join(root, path), content)
		}
		return root
	}

	it('liest t(), this.t(), n(), tc() und tRaw() samt Verkettung und Escapes', () => {
		const root = project({
			'src/a.vue': [
				'<template><p>{{ t(\'Hallo {name}\', { name }) }} {{ tRaw("Echo {name}", { name }) }}</p></template>',
				'<script>',
				'export default { methods: { f() { return this.t(\'Zwei \' +',
				'  \'Teile\') + this.n(\'%n Zeile\', \'%n Zeilen\', 2) + this.tc(\'Zustand\', \'Aktiv\') + t(\'Es gibt \\\'Quotes\\\'\') } } }',
				'</script>',
			].join('\n'),
		})
		const found = extractFrontend(root).calls.map((call) => `${call.kind}|${printable(call.key)}`)
		expect(found).toEqual([
			't|Hallo {name}',
			'tRaw|Echo {name}',
			't|Zwei Teile',
			'n|_%n Zeile_::_%n Zeilen_',
			'tc|Zustand ▸ Aktiv',
			't|Es gibt \'Quotes\'',
		])
	})

	it('überliest Kommentare, aber nicht „image/*" in einem Attribut', () => {
		const root = project({
			'src/b.vue': [
				'<template>',
				'<!-- t(\'im HTML-Kommentar\') -->',
				'<input accept="image/*"><p>{{ t(\'nach image/*\') }}</p>',
				'</template>',
				'<script>',
				'// t(\'im Zeilenkommentar\')',
				'/** t(\'im Blockkommentar\') */',
				'export default {}',
				'</script>',
			].join('\n'),
		})
		expect(extractFrontend(root).calls.map((call) => call.key)).toEqual(['nach image/*'])
	})

	it('meldet Aufrufe mit berechnetem Text, statt sie zu übergehen', () => {
		const root = project({ 'src/c.js': 'export const f = (a) => t(a.action)\n' })
		const { calls, dynamic } = extractFrontend(root)
		expect(calls).toEqual([])
		expect(dynamic.map((d) => d.file)).toContain(join('src', 'c.js'))
	})

	it('liest im Backend t(), n(), msg() und verkettete Literale', () => {
		const root = project({
			'lib/S.php': [
				'<?php',
				'$a = $this->l10n->t(\'Das Konto "%s" ist \'',
				"\t. 'gesperrt.', [\$x]);",
				'$b = $l->n(\'%n Posten\', \'%n Posten\', 2);',
				'$c = $this->msg(\'Die Datei ist leer.\');',
				'$d = $this->l10n->t("Mit \\"Anführung\\"");',
				'$e = $this->l10n->t($dynamic);',
			].join('\n'),
		})
		const { calls } = extractBackend(root)
		expect(calls.map((call) => call.key)).toEqual(['Das Konto "%s" ist gesperrt.', '_%n Posten_::_%n Posten_', 'Die Datei ist leer.', 'Mit "Anführung"'])
		expect(calls.map((call) => call.kind)).toEqual(['t', 'n', 't', 't'])
	})
})
