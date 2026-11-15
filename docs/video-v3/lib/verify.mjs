// Prueft das fertige Video gegen das Material, aus dem es entstanden ist.
//
//   node lib/verify.mjs --lang de
//
// Anlass: eine falsch platzierte ffmpeg-Option hat einmal die Sprachspur der
// ersten Szene endlos wiederholt. Bild und Clips waren in Ordnung, nur die
// Montage nicht - und beim Durchklicken einzelner Bilder faellt so etwas nicht
// auf. Diese Pruefung vergleicht deshalb den zeitlichen Verlauf der
// Lautstaerke: im Video muss jede Sprachspur dort liegen, wo die Zeitleiste
// (lib/timeline.mjs, blocks[].voStart) sie hinlegt - gemessen je Block.
//
// Zusaetzlich geprueft: Laufzeit (gegen die Zeitleiste und 120-180 s),
// Bildgroesse, Pixelseitenverhaeltnis, Bildrate, Tonkanaele, Lautheit
// (-14 +-1 LUFS), True Peak (<= -1 dBTP) und ob die Untertitel innerhalb der Laufzeit enden.

import { readFileSync, existsSync, mkdtempSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { execFileSync, spawnSync } from 'node:child_process';
import * as ffmpeg from './ffmpeg.mjs';
import { buildTimeline } from './film.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

function arg(name, fallback = null) {
	const i = process.argv.indexOf(`--${name}`);
	return i >= 0 && process.argv[i + 1] && !process.argv[i + 1].startsWith('--') ? process.argv[i + 1] : fallback;
}

const RATE = 8000;      // Abtastrate fuer die Huellkurve, mehr braucht es nicht
const FENSTER = 0.25;   // Sekunden je Messpunkt
/** Der freigegebene Film ist ~2:47 lang; die Spanne faengt grobe Montagefehler. */
const LAENGE = { min: 120, max: 180 };
const LUFS = { ziel: -14, toleranz: 1 };
/** YouTube empfiehlt hoechstens -1 dBTP; darueber verzerrt die Umkodierung. */
const TRUE_PEAK_MAX = -1;
const KORRELATION_MIN = 0.75;

/** Mono-PCM einer Datei als Int16Array. */
function pcm(datei) {
	const ordner = mkdtempSync(join(tmpdir(), 'vbh-verify-'));
	const roh = join(ordner, 'ton.raw');
	try {
		ffmpeg.run(['-i', datei, '-vn', '-ac', '1', '-ar', String(RATE), '-f', 's16le', roh], { label: `PCM ${datei}` });
		const puffer = readFileSync(roh);
		return new Int16Array(puffer.buffer, puffer.byteOffset, Math.floor(puffer.length / 2));
	} finally {
		rmSync(ordner, { recursive: true, force: true });
	}
}

/** Lautstaerke je Fenster - der "Fingerabdruck" einer Sprachspur. */
export function huellkurve(daten) {
	const proFenster = Math.round(RATE * FENSTER);
	const werte = [];
	for (let i = 0; i + proFenster <= daten.length; i += proFenster) {
		let summe = 0;
		for (let k = i; k < i + proFenster; k++) summe += daten[k] * daten[k];
		werte.push(Math.sqrt(summe / proFenster));
	}
	return werte;
}

export function korrelation(a, b) {
	const n = Math.min(a.length, b.length);
	if (n < 10) return 0;
	let sa = 0, sb = 0;
	for (let i = 0; i < n; i++) { sa += a[i]; sb += b[i]; }
	const ma = sa / n, mb = sb / n;
	let zaehler = 0, na = 0, nb = 0;
	for (let i = 0; i < n; i++) {
		const da = a[i] - ma, db = b[i] - mb;
		zaehler += da * db; na += da * da; nb += db * db;
	}
	return na && nb ? zaehler / Math.sqrt(na * nb) : 0;
}

/**
 * Korrelation der Sprachspur mit dem Film an der Stelle voStart. Die Fenster
 * des Films beginnen an der exakten Sample-Position von voStart: auf das
 * 0,25-s-Raster gerundet lagen sie bis zu 0,125 s neben der Sprache, und
 * schnelle Sprache korrelierte dann nur noch mit ~0,6.
 */
export function blockKorrelation(filmPcm, voPcm, voStart) {
	const ref = huellkurve(voPcm);
	const a = Math.round(voStart * RATE);
	const gemessen = huellkurve(filmPcm.subarray(a, a + voPcm.length));
	return korrelation(gemessen, ref);
}

/** Integrierte Lautheit und True Peak (ffmpeg loudnorm, nur messen). */
export function lautheit(datei) {
	// ffmpeg schreibt den Bericht auf stderr.
	const { stderr } = spawnSync(ffmpeg.ffmpegPath().path,
		['-hide_banner', '-nostats', '-i', datei, '-vn', '-af', 'loudnorm=I=-14:TP=-1.5:LRA=11:print_format=json', '-f', 'null', '-'],
		{ encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
	return JSON.parse(stderr.slice(stderr.lastIndexOf('{'), stderr.lastIndexOf('}') + 1));
}

export function verify(lang, { video = join(root, 'build', lang, `vereinsbuchhaltung-${lang}.mp4`) } = {}) {
	if (!existsSync(video)) throw new Error(`Video fehlt: ${video}`);
	const timeline = buildTimeline(lang);

	const probe = ffmpeg.ffprobePath();
	const daten = JSON.parse(execFileSync(probe, ['-v', 'error', '-show_streams', '-show_format', '-of', 'json', video], { encoding: 'utf8' }));
	const bild = daten.streams.find((s) => s.codec_type === 'video');
	const ton = daten.streams.find((s) => s.codec_type === 'audio');
	const dauer = Number(daten.format.duration);

	// Je Block: Huellkurve des Videos im Bereich der Sprachspur gegen die Sprach-MP3.
	const filmPcm = pcm(video);
	const bloecke = timeline.blocks.map((b) => ({
		id: b.id,
		r: blockKorrelation(filmPcm, pcm(join(root, 'build', lang, 'vo', `${b.id}.mp3`)), b.voStart),
	}));

	const srt = join(root, 'build', lang, `vereinsbuchhaltung-${lang}.srt`);
	let letzterUntertitel = null;
	if (existsSync(srt)) {
		const zeiten = [...readFileSync(srt, 'utf8').matchAll(/--> (\d+):(\d+):(\d+),(\d+)/g)];
		const letzte = zeiten.at(-1);
		if (letzte) letzterUntertitel = Number(letzte[1]) * 3600 + Number(letzte[2]) * 60 + Number(letzte[3]) + Number(letzte[4]) / 1000;
	}
	const laut = lautheit(video);

	const befunde = [];
	if (Math.abs(dauer - timeline.total) > 0.5) befunde.push(`Laufzeit ${dauer.toFixed(1)} s statt ${timeline.total.toFixed(1)} s (Zeitleiste)`);
	if (dauer < LAENGE.min || dauer > LAENGE.max) befunde.push(`Laufzeit ${dauer.toFixed(1)} s ausserhalb ${LAENGE.min}-${LAENGE.max} s`);
	if (bild.width !== 1920 || bild.height !== 1080) befunde.push(`Bildgroesse ${bild.width}x${bild.height}`);
	if (bild.r_frame_rate !== '30/1') befunde.push(`Bildrate ${bild.r_frame_rate}`);
	// Quadratische Pixel: sonst zeigt der Player 2,13:1 statt 16:9 und legt
	// oben und unten Balken an - im Einzelbild sieht man davon nichts.
	const sar = bild.sample_aspect_ratio ?? '1:1';
	if (sar !== '1:1' && sar !== 'N/A') befunde.push(`Pixelseitenverhaeltnis ${sar} statt 1:1 (Anzeige ${bild.display_aspect_ratio})`);
	if (ton.channels !== 2) befunde.push(`${ton.channels} Tonkanal/-kanaele statt Stereo`);
	for (const b of bloecke) if (b.r < KORRELATION_MIN) befunde.push(`Sprachspur ${b.id} passt nicht an ihre Stelle (Korrelation ${b.r.toFixed(2)})`);
	const lufs = Number(laut.input_i);
	if (Math.abs(lufs - LUFS.ziel) > LUFS.toleranz) befunde.push(`Lautheit ${lufs.toFixed(1)} LUFS statt ${LUFS.ziel} +-${LUFS.toleranz}`);
	const tp = Number(laut.input_tp);
	if (tp > TRUE_PEAK_MAX) befunde.push(`True Peak ${tp.toFixed(1)} dBTP ueber ${TRUE_PEAK_MAX} dBTP`);
	if (letzterUntertitel !== null && letzterUntertitel > dauer) befunde.push(`Untertitel enden bei ${letzterUntertitel.toFixed(1)} s, Video bei ${dauer.toFixed(1)} s`);

	return { video, dauer, erwartet: timeline.total, bild, ton, bloecke, lufs, truePeak: Number(laut.input_tp), letzterUntertitel, befunde };
}

if (process.argv[1]?.replace(/\\/g, '/').endsWith('lib/verify.mjs')) {
	const lang = arg('lang', 'de');
	const e = verify(lang);
	console.log(`Pruefung ${lang}: ${e.video.split(/[\\/]/).pop()}`);
	console.log(`  Laufzeit          ${e.dauer.toFixed(1)} s (Zeitleiste ${e.erwartet.toFixed(1)} s)`);
	console.log(`  Bild/Ton          ${e.bild.width}x${e.bild.height}, ${e.bild.r_frame_rate} fps, SAR ${e.bild.sample_aspect_ratio ?? '-'}, ${e.ton.channels} Kanaele`);
	console.log(`  Lautheit          ${e.lufs.toFixed(1)} LUFS, True Peak ${e.truePeak.toFixed(1)} dBTP`);
	console.log(`  Sprachspuren      ${e.bloecke.map((b) => `${b.id.slice(0, 2)}:${b.r.toFixed(2)}`).join('  ')}  (>= ${KORRELATION_MIN})`);
	console.log(`  Untertitelende    ${e.letzterUntertitel?.toFixed(1) ?? '-'} s`);
	if (e.befunde.length) {
		console.error('\nBefunde:\n  ' + e.befunde.join('\n  '));
		process.exit(1);
	}
	console.log('\nOhne Befund.');
}
