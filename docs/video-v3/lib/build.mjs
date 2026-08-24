// Ein kompletter Sprachlauf: Instanz umstellen, Daten setzen, aufnehmen,
// montieren, Untertitel und Thumbnail bauen.
//
//   node lib/build.mjs --lang de
//   node lib/build.mjs --lang en --skip-instance
//   node lib/build.mjs --lang de --only montage
//
// Die einzelnen Schritte laufen als eigene Prozesse - jeder ist auch einzeln
// aufrufbar, und ein Fehler in der Aufnahme laesst die Vertonung in Ruhe.

import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

function arg(name, fallback = null) {
	const i = process.argv.indexOf(`--${name}`);
	return i >= 0 && process.argv[i + 1] && !process.argv[i + 1].startsWith('--') ? process.argv[i + 1] : fallback;
}
const flag = (name) => process.argv.includes(`--${name}`);

const LANG = arg('lang', 'de');
const ONLY = arg('only');

const SCHRITTE = [
	{ name: 'instanz', beschreibung: 'Nextcloud-Sprache und Anzeigenamen', cmd: ['node', 'lib/instance.mjs', '--lang', LANG], wenn: () => !flag('skip-instance') },
	{ name: 'vertonung', beschreibung: 'Sprachspuren und Wortzeiten', cmd: ['python', 'lib/tts.py', '--lang', LANG], wenn: () => !flag('skip-tts') },
	{ name: 'daten', beschreibung: 'Demodatenbestand', cmd: ['node', 'lib/seed.mjs', '--lang', LANG] },
	{ name: 'aufnahme', beschreibung: 'neun Szenen aufnehmen', cmd: ['node', 'lib/record.mjs', '--lang', LANG] },
	{ name: 'montage', beschreibung: 'Blenden, Musik, Lautheit', cmd: ['node', 'lib/assemble.mjs', '--lang', LANG] },
	{ name: 'untertitel', beschreibung: 'SRT und VTT', cmd: ['node', 'lib/subtitles.mjs', '--lang', LANG] },
	{ name: 'thumbnail', beschreibung: 'Vorschaubild und Veroeffentlichungstexte', cmd: ['node', 'lib/thumbnail.mjs', '--lang', LANG] },
	// Zum Schluss gegen die Clips pruefen. Eine falsch platzierte ffmpeg-Option
	// hatte die Sprachspur der ersten Szene endlos wiederholt, ohne dass Bild,
	// Laufzeit oder Clips etwas davon verraten haetten.
	{ name: 'pruefung', beschreibung: 'Endfassung gegen die Clips pruefen', cmd: ['node', 'lib/verify.mjs', '--lang', LANG] },
];

const start = Date.now();
for (const schritt of SCHRITTE) {
	if (ONLY && schritt.name !== ONLY) continue;
	if (schritt.wenn && !schritt.wenn()) {
		console.log(`\n── ${schritt.name}: uebersprungen`);
		continue;
	}
	console.log(`\n── ${schritt.name}: ${schritt.beschreibung}`);
	const t0 = Date.now();
	const res = spawnSync(schritt.cmd[0], schritt.cmd.slice(1), { cwd: root, stdio: 'inherit', shell: process.platform === 'win32' });
	if (res.status !== 0) {
		console.error(`\nAbbruch in Schritt "${schritt.name}" (Exit ${res.status}).`);
		process.exit(res.status ?? 1);
	}
	console.log(`   (${((Date.now() - t0) / 1000).toFixed(0)} s)`);
}
console.log(`\nSprachlauf "${LANG}" fertig in ${((Date.now() - start) / 60000).toFixed(1)} Minuten.`);
console.log(`Ergebnis: build/${LANG}/vereinsbuchhaltung-${LANG}.mp4 (+ srt, vtt, thumbnail, Veroeffentlichungstexte)`);
