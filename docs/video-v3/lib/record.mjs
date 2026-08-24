// Nimmt die Szenen auf: Browser steuern, Bilder einsammeln, je Szene einen
// Clip mit Sprachspur bauen.
//
//   node lib/record.mjs --lang de                 # alle Szenen
//   node lib/record.mjs --lang de --scene 04-beitraege
//   node lib/record.mjs --lang de --keep-frames   # Einzelbilder behalten
//
// Bild und Ton treffen sich ueber eine gemeinsame Uhr: startRecording() setzt
// sie auf 0, die Sprachspur beginnt bei HEAD Sekunden, und jede Bildaktion
// haengt an einer Wortzeit aus der Vertonung (lib/cue.mjs). Deshalb braucht es
// keine Filmklappe und kein Suchen im Rohvideo - die Zeitstempel der
// Screencast-Bilder sind bekannt.

import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync, existsSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { ensureChrome, fitViewport, DEFAULT_PORT } from './chrome.mjs';
import { createContext, VIEWPORT, FPS, resetDir } from './harness.mjs';
import { loadCues } from './cue.mjs';
import { login, openApp } from './session.mjs';
import * as ffmpeg from './ffmpeg.mjs';

const require = createRequire(import.meta.url);
const cdp = require('./cdp.cjs');

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

/** Vorlauf vor dem ersten Wort und Nachlauf nach dem letzten. */
export const HEAD = 0.55;
export const TAIL = 0.9;

function arg(name, fallback = null) {
	const i = process.argv.indexOf(`--${name}`);
	return i >= 0 && process.argv[i + 1] && !process.argv[i + 1].startsWith('--') ? process.argv[i + 1] : fallback;
}
const flag = (name) => process.argv.includes(`--${name}`);

const LANG = arg('lang', 'de');
const URL_BASE = arg('url', 'http://localhost:8081').replace(/\/$/, '');
const USER = arg('user', 'andrea');
const PASS = arg('pass', 'VbhDemo2026!');
// Eigener Port, damit die Aufnahme nicht das Chrome des Seeds erbt: dort ist
// das Fenster breiter als der Aufnahmebereich, und rechts blitzt ein Streifen
// Seitenhintergrund durch (der Screencast nimmt das Fenster auf, nicht die
// emulierte Viewportbreite).
// Je Sprache ein eigener Browser: die Browsersprache steckt im Profil und laesst
// sich nicht im laufenden Betrieb umstellen.
const BROWSER_LANG = { de: 'de-DE', en: 'en-US' }[LANG] ?? 'de-DE';
const PORT = Number(arg('port', String(DEFAULT_PORT + (LANG === 'de' ? 1 : 2))));

const SCENE_MODULES = [
	'00-intro', '01-nextcloud', '02-buchen', '03-bank',
	'04-beitraege', '05-auswerten', '06-versammlung', '07-mobil', '08-outro',
];

/** Bildgroesse einer Datei - fuer die Kontrolle der Aufnahmegroesse. */
function ffmpegBildgroesse(datei) {
	const probe = ffmpeg.ffprobePath();
	const roh = execFileSync(probe, ['-v', 'error', '-select_streams', 'v', '-show_entries', 'stream=width,height', '-of', 'csv=p=0', datei], { encoding: 'utf8' });
	const [width, height] = roh.trim().split(',').map(Number);
	return { width, height };
}

/**
 * Baut aus den Einzelbildern und der Sprachspur einen Clip mit konstanten
 * 30 Bildern je Sekunde.
 *
 * Die Screencast-Bilder kommen unregelmaessig (nur bei Bildaenderung), tragen
 * aber jeweils einen Zeitstempel. Der concat-Demuxer haelt jedes Bild genau so
 * lange, wie es galt; `fps` legt daraus ein gleichmaessiges Raster.
 */
function buildClip({ frames, clockStart, voFile, outFile, duration, framesDir }) {
	if (frames.length === 0) throw new Error('Keine Bilder aufgenommen');

	// Die Aufnahmegroesse muss 16:9 sein. Ist sie es nicht, schneidet der
	// Screencast einen Teil der Seite ab, und das Skalieren auf 1920x1080 zieht
	// den Rest in die Laenge - beides faellt im Einzelbild nicht auf.
	const massBild = ffmpegBildgroesse(frames[0].file);
	const seitenverhaeltnis = massBild.width / massBild.height;
	if (Math.abs(seitenverhaeltnis - 16 / 9) > 0.02) {
		throw new Error(`Aufnahme ist ${massBild.width}x${massBild.height} (${seitenverhaeltnis.toFixed(2)}:1) statt 16:9 - `
			+ 'das Fenster passt nicht zum Aufnahmebereich.');
	}
	if (massBild.width < 1920) {
		throw new Error(`Aufnahme ist nur ${massBild.width}x${massBild.height} - erwartet werden 1920x1080. `
			+ 'Fenster und Geraeteskalierung passen nicht zusammen.');
	}

	const zeiten = frames.map((f) => Math.max(0, f.timestamp - clockStart));
	const listPath = join(framesDir, 'frames.txt');
	const zeilen = [];

	// Erstes Bild deckt auch die Zeit davor ab - sonst fehlt der Anfang.
	for (let i = 0; i < frames.length; i++) {
		const start = i === 0 ? 0 : zeiten[i];
		const ende = i + 1 < frames.length ? zeiten[i + 1] : duration;
		const dauer = Math.max(1 / FPS, ende - start);
		zeilen.push(`file 'file:${frames[i].file.replace(/\\/g, '/')}'`);
		zeilen.push(`duration ${dauer.toFixed(4)}`);
	}
	// Der concat-Demuxer braucht das letzte Bild ohne Dauer noch einmal.
	zeilen.push(`file 'file:${frames[frames.length - 1].file.replace(/\\/g, '/')}'`);
	writeFileSync(listPath, zeilen.join('\n') + '\n', 'utf8');

	const delay = Math.round(HEAD * 1000);
	ffmpeg.run([
		'-f', 'concat', '-safe', '0', '-i', listPath,
		'-i', voFile,
		'-filter_complex',
		// setsar=1 ist Pflicht: die JPEG-Bilder des Screencasts tragen eine
		// Pixeldichte im Dateikopf, aus der ffmpeg ein Seitenverhaeltnis von
		// 855:713 ableitet. Ohne Korrektur zeigt jeder Player das Video als
		// 2,13:1 - mit schwarzen Balken oben und unten und gestauchtem Bild.
		`[0:v]fps=${FPS},scale=1920:1080:flags=lanczos,setsar=1,format=yuv420p[v];`
		+ `[1:a]adelay=${delay}|${delay},apad,atrim=0:${duration.toFixed(3)},asetpts=N/SR/TB[a]`,
		'-map', '[v]', '-map', '[a]',
		'-t', duration.toFixed(3),
		'-c:v', 'libx264', '-preset', 'medium', '-crf', '18', '-pix_fmt', 'yuv420p',
		'-c:a', 'aac', '-b:a', '192k', '-ar', '48000',
		'-movflags', '+faststart',
		outFile,
	], { label: `Clip ${outFile}` });
}

async function recordScene({ tab, scene, module_, lang, content, clipsDir, keepFrames }) {
	const cues = loadCues(lang, scene.id);
	const duration = HEAD + cues.duration + TAIL;
	const framesDir = join(root, 'build', lang, 'frames', scene.id);
	resetDir(framesDir);

	const ctx = await createContext(tab, { lang, sceneId: scene.id, framesDir });
	ctx.content = content;
	ctx.scene = scene;
	ctx.cues = cues;
	// Bequemer Zugriff aus den Szenenskripten: Zeitpunkte liegen auf der
	// Videoachse, also inklusive Vorlauf.
	ctx.cueAt = (phrase, opts) => HEAD + cues.at(phrase, opts);
	ctx.cueAfter = (phrase, opts) => HEAD + cues.after(phrase, opts);
	ctx.untilCue = (phrase, opts) => ctx.until(HEAD + cues.at(phrase, opts));
	ctx.duration = duration;

	// Aufräumen vor dem Vorbereiten: die vorige Szene kann einen Dialog offen
	// gelassen haben (Szene 02 endet absichtlich so).
	await ctx.closeAllModals();
	await ctx.evaluate('window.scrollTo(0, 0), true');

	if (module_.prepare) await module_.prepare(ctx);

	const clockStart = Date.now() / 1000;
	let frames;
	try {
		await ctx.startRecording();
		await module_.record(ctx);
		// Bis zum Ende der Sprachspur plus Nachlauf weiterlaufen lassen.
		await ctx.until(duration);
		frames = await ctx.stopRecording();
	} catch (err) {
		// Aufnahme sauber beenden, sonst schreibt der Bildhandler dieser Szene
		// waehrend eines zweiten Versuchs weiter in denselben Ordner.
		await ctx.stopRecording().catch(() => {});
		throw err;
	}

	const outFile = join(clipsDir, `${scene.id}.mp4`);
	buildClip({
		frames, clockStart,
		voFile: join(root, 'build', lang, 'vo', `${scene.id}.mp3`),
		outFile, duration, framesDir,
	});
	if (!keepFrames) rmSync(framesDir, { recursive: true, force: true });

	return { outFile, duration, frames: frames.length };
}

async function main() {
	const content = JSON.parse(readFileSync(join(root, 'content', `${LANG}.json`), 'utf8'));
	const only = arg('scene');
	const scenes = content.scenes.filter((s) => !only || s.id === only);
	if (scenes.length === 0) throw new Error(`Szene "${only}" steht nicht in content/${LANG}.json`);

	const clipsDir = join(root, 'build', LANG, 'clips');
	mkdirSync(clipsDir, { recursive: true });

	const { version } = ffmpeg.ffmpegPath();
	console.log(`Aufnahme ${LANG}, ${scenes.length} Szene(n), ffmpeg ${version}`);

	// Fenster gross genug fuer 1920x1080 echte Bildpunkte; die genaue Groesse
	// misst fitViewport gleich selbst nach.
	await ensureChrome({ port: PORT, lang: BROWSER_LANG, width: 1936, height: 1240 });
	const tab = await cdp.openTab(`${URL_BASE}/login`, PORT);

	try {
		console.log('  ' + await login(tab, { url: URL_BASE, user: USER, pass: PASS }));
		// Zwei Schritte, und beide sind noetig:
		//  1. Der Bildschirmmitschnitt nimmt die *Fensterflaeche* auf. Sie muss
		//     deshalb exakt 1920x1080 echte Bildpunkte gross sein.
		//  2. Die Seite soll darin mit Faktor 1,25 rendern, also mit 1536x864
		//     CSS-Pixeln - so bleibt die Schrift auch auf kleinen Displays lesbar.
		// Fehlte Schritt 1, nahm der Screencast nur 1520x713 auf: die unteren
		// 17 Prozent der Seite fehlten, und das Skalieren auf 16:9 zog das Bild
		// um den Faktor 1,21 in die Laenge.
		// Fensterflaeche und Seitenkoordinaten sind identisch: 1920x1080. Der
		// Bildschirmmitschnitt nimmt die Fensterflaeche auf, deshalb muss sie
		// stimmen - vorher waren es 1520x713, wodurch unten ein Sechstel der Seite
		// fehlte und das Bild beim Skalieren in die Laenge gezogen wurde.
		await cdp.send(tab, 'Emulation.clearDeviceMetricsOverride').catch(() => {});
		const flaeche = await fitViewport(cdp, tab, { width: VIEWPORT.width, height: VIEWPORT.height });
		console.log(`  Aufnahmeflaeche ${flaeche.w}x${flaeche.h} Bildpunkte, ohne Emulation`);
		await openApp(tab, URL_BASE);

		for (const scene of scenes) {
			const module_ = await import(`../scenes/${scene.id}.mjs`);
			process.stdout.write(`  ${scene.id.padEnd(16)} `);
			const t0 = Date.now();
			// Eine Szene darf einmal schiefgehen. Die Wartebedingungen haengen an
			// echten Netzantworten der App; faellt eine davon einmal aus, ist der
			// ganze Sprachlauf hin - bei sechs Minuten Laufzeit aergerlich. Beim
			// zweiten Versuch wird die Szene komplett neu vorbereitet.
			let res;
			for (let versuch = 1; ; versuch++) {
				try {
					res = await recordScene({
						tab, scene, module_, lang: LANG, content, clipsDir,
						keepFrames: flag('keep-frames'),
					});
					break;
				} catch (err) {
					if (versuch >= 2) throw err;
					process.stdout.write(`(1. Versuch fehlgeschlagen: ${err.message.replace(/\s+/g, ' ').slice(0, 90)} - neuer Versuch) `);
					await openApp(tab, URL_BASE);
				}
			}
			console.log(`${res.duration.toFixed(2)} s · ${res.frames} Bilder · ${((Date.now() - t0) / 1000).toFixed(0)} s Aufnahmezeit`);
		}

		const gesamt = scenes.reduce((sum, s) => sum + HEAD + loadCues(LANG, s.id).duration + TAIL, 0);
		console.log(`\nClips in build/${LANG}/clips/ — zusammen ${gesamt.toFixed(1)} s vor den Blenden.`);
	} finally {
		if (!flag('keep-tab')) await cdp.closeTab(tab);
	}
}

// Nur beim direkten Aufruf aufnehmen. Ohne diese Klammer startet schon ein
// `import { HEAD } from './record.mjs'` eine vollstaendige Aufnahme - genau das
// ist beim ersten Bauen der Untertitel passiert.
if (import.meta.url === new URL(`file://${process.argv[1]}`).href.replace(/%3A/g, ':')) {
	main().catch((err) => {
		console.error(`\nFEHLER: ${err.message}`);
		process.exit(1);
	});
}
