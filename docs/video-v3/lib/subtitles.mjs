// Untertitel aus den Wortzeiten der Vertonung - SRT und WebVTT.
//
//   node lib/subtitles.mjs --lang de
//
// Die Wortzeiten liegen szenenweise vor (build/<lang>/vo/*.words.json), das
// fertige Video hat aber eine durchgehende Zeitachse. Beides zusammen ergibt
// den Versatz: jede Szene beginnt um XFADE frueher als die Summe der Clips,
// weil die Blenden ueberlappen - und in jeder Szene beginnt die Sprache erst
// nach dem Vorlauf HEAD.
//
// Damit sind die Untertitel keine Abschrift, sondern auf dasselbe Material
// bezogen wie der Ton: eine Aenderung am Sprechtext wandert automatisch mit.

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { HEAD } from './record.mjs';
import { XFADE } from './assemble.mjs';
import * as ffmpeg from './ffmpeg.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

function arg(name, fallback = null) {
	const i = process.argv.indexOf(`--${name}`);
	return i >= 0 && process.argv[i + 1] && !process.argv[i + 1].startsWith('--') ? process.argv[i + 1] : fallback;
}

/** Maximale Zeichen je Zeile - darueber wird umgebrochen, zwei Zeilen sind das Limit. */
const ZEILE_MAX = 42;
const CUE_MAX_WOERTER = 14;
const CUE_MIN_DAUER = 1.1;

function zeit(sekunden, komma = ',') {
	const ms = Math.max(0, Math.round(sekunden * 1000));
	const h = Math.floor(ms / 3600000);
	const m = Math.floor((ms % 3600000) / 60000);
	const s = Math.floor((ms % 60000) / 1000);
	const rest = ms % 1000;
	return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}${komma}${String(rest).padStart(3, '0')}`;
}

/** Bricht einen Cue-Text auf hoechstens zwei Zeilen um. */
function umbrechen(text) {
	if (text.length <= ZEILE_MAX) return text;
	const woerter = text.split(' ');
	let erste = '';
	while (woerter.length && (erste + ' ' + woerter[0]).trim().length <= ZEILE_MAX) {
		erste = (erste + ' ' + woerter.shift()).trim();
	}
	return `${erste}\n${woerter.join(' ')}`;
}

/**
 * Ordnet den Wortzeiten die Woerter des Sprechtexts zu - mit Satzzeichen.
 *
 * Die WordBoundary-Ereignisse liefern nur das nackte Wort ("fuehren"), nicht
 * "fuehren,". Ohne diese Zuordnung stuenden die Untertitel ohne ein einziges
 * Komma da. Gezaehlt wird zeichenweise: ein Quelltoken kann auch mehrere
 * Ereignisse umfassen (z. B. "pain-Punkt-null-null-acht").
 */
function mitSatzzeichen(words, voText) {
	const tokens = voText.split(/\s+/).filter(Boolean);
	const kern = (t) => t.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '');
	const zuordnung = new Array(words.length).fill(null);

	let ti = 0;
	let wi = 0;
	while (ti < tokens.length && wi < words.length) {
		const ziel = kern(tokens[ti]);
		let gesammelt = '';
		const erste = wi;
		while (wi < words.length && gesammelt.length < ziel.length) {
			gesammelt += kern(words[wi].text);
			wi++;
		}
		for (let k = erste; k < wi; k++) zuordnung[k] = ti;
		// Passt nichts zusammen, bricht die Zuordnung ab und der Rest bleibt
		// beim nackten Wort - besser als ein verschobener Untertitel.
		if (gesammelt !== ziel && gesammelt.length < ziel.length) break;
		ti++;
	}

	return words.map((w, i) => ({
		...w,
		anzeige: zuordnung[i] === null ? w.text : tokens[zuordnung[i]],
		tokenIndex: zuordnung[i],
	}));
}

/**
 * Baut Cues aus Wortzeiten: neuer Cue am Satzende, bei zu vielen Woertern oder
 * bei einer Sprechpause von mehr als einer halben Sekunde.
 */
function cuesFuerSzene(words, offset) {
	const cues = [];
	let aktuell = [];
	const abschliessen = () => {
		if (!aktuell.length) return;
		const start = offset + aktuell[0].start;
		const ende = Math.max(offset + aktuell[aktuell.length - 1].end, start + CUE_MIN_DAUER);
		const sichtbar = [];
		for (const w of aktuell) {
			const wort = w.anzeige ?? w.text;
			if (w.tokenIndex === undefined || w.tokenIndex === null || sichtbar.at(-1)?.index !== w.tokenIndex) {
				sichtbar.push({ wort, index: w.tokenIndex });
			}
		}
		cues.push({ start, ende, text: umbrechen(sichtbar.map((s) => s.wort).join(' ')) });
		aktuell = [];
	};

	for (let i = 0; i < words.length; i++) {
		aktuell.push(words[i]);
		const wort = words[i].anzeige ?? words[i].text;
		const naechstes = words[i + 1];
		const pause = naechstes ? naechstes.start - words[i].end : 0;
		if (/[.!?:]$/.test(wort) || aktuell.length >= CUE_MAX_WOERTER || pause > 0.5) {
			abschliessen();
		}
	}
	abschliessen();
	return cues;
}

export function buildSubtitles(lang) {
	const content = JSON.parse(readFileSync(join(root, 'content', `${lang}.json`), 'utf8'));
	const clipsDir = join(root, 'build', lang, 'clips');

	let offset = 0;
	const cues = [];
	for (const scene of content.scenes) {
		const rohe = JSON.parse(readFileSync(join(root, 'build', lang, 'vo', `${scene.id}.words.json`), 'utf8'));
		const words = mitSatzzeichen(rohe, scene.vo);
		cues.push(...cuesFuerSzene(words, offset + HEAD));
		const clip = join(clipsDir, `${scene.id}.mp4`);
		if (!existsSync(clip)) throw new Error(`Clip fehlt: ${clip}`);
		offset += ffmpeg.duration(clip) - XFADE;
	}

	const srt = cues.map((c, i) => `${i + 1}\n${zeit(c.start)} --> ${zeit(c.ende)}\n${c.text}\n`).join('\n');
	const vtt = 'WEBVTT\n\n' + cues.map((c) => `${zeit(c.start, '.')} --> ${zeit(c.ende, '.')}\n${c.text}\n`).join('\n');

	const srtFile = join(root, 'build', lang, `vereinsbuchhaltung-${lang}.srt`);
	const vttFile = join(root, 'build', lang, `vereinsbuchhaltung-${lang}.vtt`);
	writeFileSync(srtFile, srt, 'utf8');
	writeFileSync(vttFile, vtt, 'utf8');
	return { cues, srtFile, vttFile };
}

if (import.meta.url === new URL(`file://${process.argv[1]}`).href.replace(/%3A/g, ':')) {
	const lang = arg('lang', 'de');
	const res = buildSubtitles(lang);
	console.log(`${res.cues.length} Untertitel fuer "${lang}"`);
	console.log(`  ${res.srtFile}`);
	console.log(`  ${res.vttFile}`);
	console.log('\nErste drei:');
	for (const c of res.cues.slice(0, 3)) {
		console.log(`  ${zeit(c.start)} → ${zeit(c.ende)}  ${c.text.replace('\n', ' / ')}`);
	}
}
