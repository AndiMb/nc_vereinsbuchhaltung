// `npm run l10n:check`: zeigt, was in l10n/en.json fehlt und was dort (oder in
// l10n/de.json) übrig geblieben ist. Dieselben Regeln prüft tests/l10n/catalog.test.js
// in `npm test` – hier gibt es dazu die vollständige Liste und fertige
// Platzhalter-Zeilen zum Einfügen.
import { extractAll, printable, readBundle } from './extract.mjs'

const { keys, frontend, backend } = extractAll()
const en = readBundle('en').translations
const de = readBundle('de').translations

const missing = [...keys.keys()].filter((key) => !(key in en))
const orphans = Object.keys(en).filter((key) => !keys.has(key))
const staleDu = Object.keys(de).filter((key) => !keys.has(key))

console.log(`Quelltexte: ${keys.size} (Frontend ${frontend.calls.length} Aufrufe in ${frontend.files} Dateien, Backend ${backend.calls.length} in ${backend.files})`)
console.log(`en.json: ${Object.keys(en).length} Einträge, davon ${orphans.length} ohne Fundstelle im Code (nicht zwingend veraltet: ein Aufruf mit berechnetem Text taucht hier nicht auf)`)
console.log(`de.json (Du-Fassung): ${Object.keys(de).length} Einträge, ${staleDu.length} ohne Fundstelle`)
if (frontend.dynamic.length + backend.dynamic.length > 0) {
	console.log(`Aufrufe mit berechnetem Text (nicht prüfbar): ${frontend.dynamic.length + backend.dynamic.length}`)
	for (const d of [...frontend.dynamic, ...backend.dynamic].filter((x) => !x.code.startsWith('t()'))) { console.log(`  ${d.file}:${d.line}  ${d.code}`) }
}
if (missing.length === 0) {
	console.log('en.json ist vollständig.')
} else {
	console.log(`\nFEHLT in en.json (${missing.length}):`)
	for (const key of missing) {
		const first = keys.get(key)[0]
		console.log(`  ${first.file}:${first.line}  ${JSON.stringify(printable(key))}`)
	}
	console.log('\nZum Einfügen (Übersetzung ergänzen):')
	for (const key of missing) { console.log(`        ${JSON.stringify(key)}: ${key.startsWith('_') && key.includes('_::_') ? '["", ""]' : '""'},`) }
	process.exitCode = 1
}
