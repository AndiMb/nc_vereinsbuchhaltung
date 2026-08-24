// Prueft das fertige Video gegen die Clips, aus denen es entstanden ist.
//
//   node lib/verify.mjs --lang de
//
// Anlass: eine falsch platzierte ffmpeg-Option (-stream_loop vor dem ersten
// Clip statt vor der Musik) hat die Sprachspur der ersten Szene endlos
// wiederholt. Bild und Clips waren in Ordnung, nur die Montage nicht - und beim
// Durchklicken einzelner Bilder faellt so etwas nicht auf. Diese Pruefung
// vergleicht deshalb den zeitlichen Verlauf der Lautstaerke: die Sprachspur des
// Videos muss demselben Muster folgen wie die Clips hintereinander.
//
// Zusaetzlich geprueft: Laufzeit, Bildgroesse, Bildrate, Tonkanaele und ob die
// Untertitel innerhalb der Laufzeit enden.

import { readFileSync, existsSync, mkdtempSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { execFileSync } from 'node:child_process';
import * as ffmpeg from './ffmpeg.mjs';
import { XFADE } from './assemble.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

function arg(name, fallback = null) {
	const i = process.argv.indexOf(`--${name}`);
	return i >= 0 && process.argv[i + 1] && !process.argv[i + 1].startsWith('--') ? process.argv[i + 1] : fallback;
}

const RATE = 8000;      // Abtastrate fuer die Huellkurve, mehr braucht es nicht
const FENSTER = 0.25;   // Sekunden je Messpunkt

/** Mono-PCM einer Datei als Int16Array - notfalls nur ein Ausschnitt. */
function pcm(datei, extraArgs = []) {
	const ordner = mkdtempSync(join(tmpdir(), 'vbh-verify-'));
	const roh = join(ordner, 'ton.raw');
	try {
		ffmpeg.run([...extraArgs, '-i', datei, '-vn', '-ac', '1', '-ar', String(RATE), '-f', 's16le', roh],
			{ label: `PCM ${datei}` });
		const puffer = readFileSync(roh);
		return new Int16Array(puffer.buffer, puffer.byteOffset, Math.floor(puffer.length / 2));
	} finally {
		rmSync(ordner, { recursive: true, force: true });
	}
}

/** Lautstaerke je Fenster - der "Fingerabdruck" einer Sprachspur. */
function huellkurve(daten) {
	const proFenster = Math.round(RATE * FENSTER);
	const werte = [];
	for (let i = 0; i + proFenster <= daten.length; i += proFenster) {
		let summe = 0;
		for (let k = i; k < i + proFenster; k++) summe += daten[k] * daten[k];
		werte.push(Math.sqrt(summe / proFenster));
	}
	return werte;
}

function korrelation(a, b) {
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

export function verify(lang) {
	const content = JSON.parse(readFileSync(join(root, 'content', `${lang}.json`), 'utf8'));
	const video = join(root, 'build', lang, `vereinsbuchhaltung-${lang}.mp4`);
	if (!existsSync(video)) throw new Error(`Video fehlt: ${video}`);

	const probe = ffmpeg.ffprobePath();
	const daten = JSON.parse(execFileSync(probe, ['-v', 'error', '-show_streams', '-show_format', '-of', 'json', video], { encoding: 'utf8' }));
	const bild = daten.streams.find((s) => s.codec_type === 'video');
	const ton = daten.streams.find((s) => s.codec_type === 'audio');
	const dauer = Number(daten.format.duration);

	// Erwartete Laufzeit aus den Clips.
	const clips = content.scenes.map((s) => ffmpeg.duration(join(root, 'build', lang, 'clips', `${s.id}.mp4`)));
	const erwartet = clips.reduce((a, b) => a + b, 0) - XFADE * (clips.length - 1);

	// Referenz auf der Zeitachse des fertigen Videos: jede Szene beginnt um
	// XFADE frueher als die Summe der Clips davor, weil sich die Blenden
	// ueberlappen. Ein simples Aneinanderhaengen waere am Ende um mehrere
	// Sekunden verschoben - und verschobene Sprachspuren korrelieren nicht,
	// egal wie richtig sie sind.
	const referenz = new Array(Math.ceil(erwartet / FENSTER)).fill(0);
	let versatz = 0;
	for (const [i, scene] of content.scenes.entries()) {
		const kurve = huellkurve(pcm(join(root, 'build', lang, 'clips', `${scene.id}.mp4`)));
		const start = Math.round(versatz / FENSTER);
		for (let k = 0; k < kurve.length; k++) {
			const ziel = start + k;
			if (ziel < referenz.length) referenz[ziel] = Math.max(referenz[ziel], kurve[k]);
		}
		versatz += clips[i] - XFADE;
	}
	const gemessen = huellkurve(pcm(video));
	const r = korrelation(gemessen, referenz);

	// Wiederholt sich die erste Szene? Dann aehnelt der Anfang des Videos auch
	// dem Bereich eine Szenenlaenge spaeter.
	const ersteLaenge = Math.round(clips[0] / FENSTER);
	const anfang = gemessen.slice(0, ersteLaenge);
	const danach = gemessen.slice(ersteLaenge, ersteLaenge * 2);
	const wiederholung = korrelation(anfang, danach);

	const srt = join(root, 'build', lang, `vereinsbuchhaltung-${lang}.srt`);
	let letzterUntertitel = null;
	if (existsSync(srt)) {
		const zeiten = [...readFileSync(srt, 'utf8').matchAll(/--> (\d+):(\d+):(\d+),(\d+)/g)];
		const letzte = zeiten.at(-1);
		if (letzte) letzterUntertitel = Number(letzte[1]) * 3600 + Number(letzte[2]) * 60 + Number(letzte[3]) + Number(letzte[4]) / 1000;
	}

	const befunde = [];
	if (Math.abs(dauer - erwartet) > 0.5) befunde.push(`Laufzeit ${dauer.toFixed(1)} s statt ${erwartet.toFixed(1)} s`);
	if (bild.width !== 1920 || bild.height !== 1080) befunde.push(`Bildgroesse ${bild.width}x${bild.height}`);
	if (bild.r_frame_rate !== '30/1') befunde.push(`Bildrate ${bild.r_frame_rate}`);
	// Quadratische Pixel: sonst zeigt der Player 2,13:1 statt 16:9 und legt
	// oben und unten Balken an - im Einzelbild sieht man davon nichts.
	const sar = bild.sample_aspect_ratio ?? '1:1';
	if (sar !== '1:1' && sar !== 'N/A') befunde.push(`Pixelseitenverhaeltnis ${sar} statt 1:1 (Anzeige ${bild.display_aspect_ratio})`);
	if (ton.channels !== 2) befunde.push(`${ton.channels} Tonkanal/-kanaele statt Stereo`);
	if (r < 0.75) befunde.push(`Sprachspur passt nicht zu den Clips (Korrelation ${r.toFixed(2)})`);
	if (wiederholung > 0.75) befunde.push(`Erste Szene wiederholt sich (Korrelation ${wiederholung.toFixed(2)})`);
	if (letzterUntertitel !== null && letzterUntertitel > dauer) befunde.push(`Untertitel enden bei ${letzterUntertitel.toFixed(1)} s, Video bei ${dauer.toFixed(1)} s`);

	return { video, dauer, erwartet, bild, ton, r, wiederholung, letzterUntertitel, befunde };
}

if (import.meta.url === new URL(`file://${process.argv[1]}`).href.replace(/%3A/g, ':')) {
	const lang = arg('lang', 'de');
	const e = verify(lang);
	console.log(`Pruefung ${lang}: ${e.video.split(/[\\/]/).pop()}`);
	console.log(`  Laufzeit          ${e.dauer.toFixed(1)} s (erwartet ${e.erwartet.toFixed(1)} s)`);
	console.log(`  Bild/Ton          ${e.bild.width}x${e.bild.height}, ${e.bild.r_frame_rate} fps, ${e.ton.channels} Kanaele`);
	console.log(`  Sprachspur        Korrelation ${e.r.toFixed(2)} mit den Clips (>= 0,75 erwartet)`);
	console.log(`  Wiederholung      ${e.wiederholung.toFixed(2)} (< 0,75 erwartet)`);
	console.log(`  Untertitelende    ${e.letzterUntertitel?.toFixed(1) ?? '-'} s`);
	if (e.befunde.length) {
		console.error('\nBefunde:\n  ' + e.befunde.join('\n  '));
		process.exit(1);
	}
	console.log('\nOhne Befund.');
}
