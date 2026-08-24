// Zeitpunkte aus dem Sprechtext: at('Wachordner') liefert die Sekunde, in der
// das Wort gesprochen wird, after('...') die Sekunde danach.
//
// Die Szenenskripte takten damit ihre Bildaktionen. Geschaetzte Sekunden laufen
// bei einer halben Minute Sprechtext um mehrere Sekunden aus dem Ruder - das
// war die teuerste Lehre der Vorgaengerpipeline.
//
// Die zweite Lehre steckt in der Eindeutigkeitspruefung: dort traf
// after('ist') im Satz "Soll und Ist gegenueber" statt an der gemeinten
// Stelle. Hier wirft eine mehrdeutige Phrase einen Fehler, statt still das
// falsche Wort zu nehmen - mit Angabe aller Fundstellen.
//
//   node lib/cue.mjs --lang de           # alle Anker aus content/de.json pruefen

import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

/** Kleinschreibung, Satzzeichen weg - "Zuzuordnen:" und "zuzuordnen" sind dasselbe Wort. */
function normalize(text) {
	return text
		.toLowerCase()
		.replace(/[.,;:!?()„“"'»«…]/g, '')
		.replace(/\s+/g, ' ')
		.trim();
}

/**
 * @param {string} lang
 * @param {string} sceneId  z. B. "03-bank"
 */
export function loadCues(lang, sceneId) {
	const path = join(root, 'build', lang, 'vo', `${sceneId}.words.json`);
	if (!existsSync(path)) {
		throw new Error(`Keine Wortzeiten fuer ${lang}/${sceneId}. Erst vertonen: python lib/tts.py --lang ${lang}`);
	}
	const words = JSON.parse(readFileSync(path, 'utf8'));
	const normalized = words.map((w) => normalize(w.text));

	/** Alle Startindizes, an denen die (ggf. mehrwortige) Phrase steht. */
	function findAll(phrase) {
		const needle = normalize(phrase).split(' ').filter(Boolean);
		if (needle.length === 0) throw new Error('Leere Phrase');
		const hits = [];
		for (let i = 0; i + needle.length <= normalized.length; i++) {
			if (needle.every((part, k) => normalized[i + k] === part)) hits.push(i);
		}
		return hits;
	}

	function resolve(phrase, nth = null) {
		const hits = findAll(phrase);
		if (hits.length === 0) {
			const text = words.map((w) => w.text).join(' ');
			throw new Error(`Phrase "${phrase}" kommt in ${sceneId} (${lang}) nicht vor.\nSprechtext: ${text}`);
		}
		if (hits.length > 1 && nth === null) {
			const stellen = hits.map((i) => `${i} (${words[i].start}s)`).join(', ');
			throw new Error(`Phrase "${phrase}" kommt in ${sceneId} (${lang}) ${hits.length}-mal vor: ${stellen}. `
				+ 'Mehr Kontext waehlen oder nth angeben.');
		}
		const index = hits[nth === null ? 0 : nth];
		if (index === undefined) {
			throw new Error(`Phrase "${phrase}" kommt in ${sceneId} (${lang}) nur ${hits.length}-mal vor, nth=${nth} gibt es nicht.`);
		}
		return { index, length: normalize(phrase).split(' ').filter(Boolean).length };
	}

	return {
		words,
		/** Laenge der Sprachspur dieser Szene in Sekunden. */
		duration: words.length ? words[words.length - 1].end : 0,

		/** Beginn des Wortes (Sekunden ab Szenenanfang), optional um `offset` verschoben. */
		at(phrase, { nth = null, offset = 0 } = {}) {
			const { index } = resolve(phrase, nth);
			return Math.max(0, words[index].start + offset);
		},

		/** Ende der Phrase - fuer "sobald das gesagt ist, passiert im Bild ...". */
		after(phrase, { nth = null, offset = 0 } = {}) {
			const { index, length } = resolve(phrase, nth);
			return Math.max(0, words[index + length - 1].end + offset);
		},

		/** Wie oft die Phrase vorkommt - fuer Pruefungen, ohne zu werfen. */
		count: (phrase) => findAll(phrase).length,
	};
}

/** Prueft alle Callout-Anker einer Sprache gegen die vertonten Wortzeiten. */
export function validateContent(lang) {
	const content = JSON.parse(readFileSync(join(root, 'content', `${lang}.json`), 'utf8'));
	const probleme = [];
	const zeilen = [];

	for (const scene of content.scenes) {
		let cues;
		try {
			cues = loadCues(lang, scene.id);
		} catch (err) {
			probleme.push(`${scene.id}: ${err.message.split('\n')[0]}`);
			continue;
		}
		zeilen.push(`${scene.id.padEnd(16)} ${cues.duration.toFixed(2).padStart(6)} s  ${String(cues.words.length).padStart(3)} Woerter`);
		for (const callout of scene.callouts ?? []) {
			const treffer = cues.count(callout.cue);
			if (treffer !== 1) {
				probleme.push(`${scene.id}: Anker "${callout.cue}" kommt ${treffer}-mal vor (genau einmal erwartet)`);
				continue;
			}
			zeilen.push(`  └ ${String(cues.at(callout.cue).toFixed(2)).padStart(6)} s  "${callout.cue}" → ${callout.text}`);
		}
	}
	return { zeilen, probleme, gesamt: content.scenes.length };
}

if (import.meta.url === new URL(`file://${process.argv[1]}`).href.replace(/%3A/g, ':')) {
	const i = process.argv.indexOf('--lang');
	const lang = i >= 0 ? process.argv[i + 1] : 'de';
	const { zeilen, probleme } = validateContent(lang);
	console.log(zeilen.join('\n'));
	if (probleme.length) {
		console.error('\nProbleme:\n  ' + probleme.join('\n  '));
		process.exit(1);
	}
	console.log('\nAlle Anker eindeutig.');
}
