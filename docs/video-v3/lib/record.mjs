// Nimmt die Szenen auf: Browser steuern, Bilder einsammeln, je Szene einen
// stummen Clip samt Manifest (Einstellungen, Zoomziele) bauen. Ton, Schnitt,
// Einblendungen und Zooms setzt danach HyperFrames (lib/film.mjs).
//
//   node lib/record.mjs --lang de                 # alle Szenen aus content/de.json
//   node lib/record.mjs --lang de --scene 02-geld
//   node lib/record.mjs --lang de --scale 1       # Faktor 1 (Vorgabe 2 -> 3840x2160)
//   node lib/record.mjs --lang de --keep-frames   # Einzelbilder behalten
//
// Bild und Ton treffen sich ueber eine gemeinsame Uhr: startRecording() setzt
// sie auf 0, die Sprachspur beginnt im Film bei HEAD Sekunden, und jede
// Bildaktion haengt an einer Wortzeit aus der Vertonung (lib/cue.mjs).
//
// Szenenmodule (scenes/<id>.mjs) koennen exportieren:
//   prepare(ctx), record(ctx)      Vorbereitung (nicht im Bild) und Aufnahme
//   needsFreshData = true          vorher Demobestand wiederherstellen (snapshot.mjs)
//   needsMember = true             zweiter Browser als Mitglied mia (ctx.member)
//   duration = <s>                 nur fuer Szenen ohne Sprachspur (Probe)

import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { ensureChrome, fitViewport, DEFAULT_PORT } from './chrome.mjs';
import { createContext, makeClock, setScale, VIEWPORT, FPS, resetDir } from './harness.mjs';
import { loadCues } from './cue.mjs';
import { demoPass, login, openApp } from './session.mjs';
import { createManifest, addClip, writeManifest } from './manifest.mjs';
import { createClient } from './ncapi.mjs';
import { restore } from './snapshot.mjs';
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
const URL_BASE = arg('url', 'http://localhost:8082').replace(/\/$/, '');
const USER = arg('user', 'andrea');
const MEMBER_USER = arg('member', 'mia');
const PASS = demoPass(arg('pass'));
const CONTAINER = arg('container', 'vbh-demo');
const SCALE = Number(arg('scale', '2'));
// Je Sprache ein eigener Browser: die Browsersprache steckt im Profil und laesst
// sich nicht im laufenden Betrieb umstellen. Ebenso je Geraetefaktor.
const BROWSER_LANG = { de: 'de-DE', en: 'en-US' }[LANG] ?? 'de-DE';
const LANG_INDEX = LANG === 'de' ? 1 : 2;
const PORT = Number(arg('port', String(DEFAULT_PORT + LANG_INDEX + (SCALE === 1 ? 0 : 4))));
// Mitglied: eigener Chrome-Prozess mit eigenem Profil. Browser-Kontexte im
// selben Prozess teilen Cookies - dann waere die Vorstandssitzung weg.
const MEMBER_PORT = PORT + 10;

/** Bildgroesse einer Datei - fuer die Kontrolle der Aufnahmegroesse. */
function ffmpegBildgroesse(datei) {
	const probe = ffmpeg.ffprobePath();
	const roh = execFileSync(probe, ['-v', 'error', '-select_streams', 'v', '-show_entries', 'stream=width,height', '-of', 'csv=p=0', datei], { encoding: 'utf8' });
	const [width, height] = roh.trim().split(',').map(Number);
	return { width, height };
}

/**
 * Baut aus den Einzelbildern einen stummen Clip mit konstanten 30 Bildern je
 * Sekunde in 1920*scale x 1080*scale.
 *
 * Die Screencast-Bilder kommen unregelmaessig (nur bei Bildaenderung), tragen
 * aber jeweils einen Zeitstempel. Der concat-Demuxer haelt jedes Bild genau so
 * lange, wie es galt; `fps` legt daraus ein gleichmaessiges Raster.
 */
export function buildClip({ frames, clockStart, outFile, duration, framesDir, scale = 1 }) {
	if (frames.length === 0) throw new Error('Keine Bilder aufgenommen');
	const breite = 1920 * scale;
	const hoehe = 1080 * scale;

	// Die Aufnahmegroesse muss 16:9 sein. Ist sie es nicht, schneidet der
	// Screencast einen Teil der Seite ab, und das Skalieren zieht den Rest in
	// die Laenge - beides faellt im Einzelbild nicht auf.
	const massBild = ffmpegBildgroesse(frames[0].file);
	const seitenverhaeltnis = massBild.width / massBild.height;
	if (Math.abs(seitenverhaeltnis - 16 / 9) > 0.02) {
		throw new Error(`Aufnahme ist ${massBild.width}x${massBild.height} (${seitenverhaeltnis.toFixed(2)}:1) statt 16:9 - `
			+ 'das Fenster passt nicht zum Aufnahmebereich.');
	}
	if (massBild.width < breite) {
		throw new Error(`Aufnahme ist nur ${massBild.width}x${massBild.height} - erwartet werden ${breite}x${hoehe}. `
			+ 'Fenster und Geraetefaktor passen nicht zusammen.');
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

	ffmpeg.run([
		'-f', 'concat', '-safe', '0', '-i', listPath,
		// setsar=1 ist Pflicht: die JPEG-Bilder des Screencasts tragen eine
		// Pixeldichte im Dateikopf, aus der ffmpeg ein falsches
		// Seitenverhaeltnis ableitet.
		'-vf', `fps=${FPS},scale=${breite}:${hoehe}:flags=lanczos,setsar=1,format=yuv420p`,
		'-an',
		'-t', duration.toFixed(3),
		'-c:v', 'libx264', '-preset', 'medium', '-crf', '18', '-pix_fmt', 'yuv420p',
		'-movflags', '+faststart',
		outFile,
	], { label: `Clip ${outFile}` });
	return massBild;
}

/** Ein Browser, angemeldet, Fensterflaeche exakt 1920x1080 CSS-Pixel. */
async function openBrowser({ port, user }) {
	await ensureChrome({ port, lang: BROWSER_LANG, width: 1936, height: 1240, scale: SCALE });
	const tab = await cdp.openTab(`${URL_BASE}/login`, port);
	console.log(`  ${await login(tab, { url: URL_BASE, user, pass: PASS })} (Port ${port})`);
	// Keine Emulation: der Screencast nimmt die Fensterflaeche auf, also muss
	// das echte Fenster passen (sonst fehlte frueher unten ein Sechstel).
	await cdp.send(tab, 'Emulation.clearDeviceMetricsOverride').catch(() => {});
	const flaeche = await fitViewport(cdp, tab, { width: VIEWPORT.width, height: VIEWPORT.height });
	console.log(`  Flaeche ${flaeche.w}x${flaeche.h} CSS-Pixel, Faktor ${SCALE}`);
	await openApp(tab, URL_BASE);
	return tab;
}

let memberTab = null;

async function recordScene({ tab, scene, module_, lang, content, clipsDir, keepFrames }) {
	let cues = null;
	let duration;
	if (module_.duration) {
		duration = module_.duration;
	} else {
		cues = loadCues(lang, scene.id);
		duration = HEAD + cues.duration + TAIL;
	}

	if (module_.needsFreshData) {
		const client = createClient({ url: URL_BASE, user: USER, pass: PASS });
		await restore(client, lang, { container: CONTAINER });
		await openApp(tab, URL_BASE);
		if (memberTab) await openApp(memberTab, URL_BASE);
	}

	const manifest = createManifest({ sceneId: scene.id, lang, head: HEAD, duration, scale: SCALE });
	const clock = makeClock();
	const framesDir = join(root, 'build', lang, 'frames', scene.id);
	resetDir(framesDir);
	const ctx = await createContext(tab, { lang, sceneId: scene.id, framesDir, clock, manifest, source: 'board' });

	let member = null;
	if (module_.needsMember) {
		if (!memberTab) memberTab = await openBrowser({ port: MEMBER_PORT, user: MEMBER_USER });
		const memberFrames = join(root, 'build', lang, 'frames', `${scene.id}.member`);
		resetDir(memberFrames);
		member = await createContext(memberTab, { lang, sceneId: scene.id, framesDir: memberFrames, clock, manifest, source: 'member', ownsClock: false });
		member.framesDir = memberFrames;
	}

	for (const c of [ctx, member].filter(Boolean)) {
		c.content = content;
		c.scene = scene;
		c.cues = cues;
		// Zeitpunkte liegen auf der Clipachse, also inklusive Vorlauf.
		c.cueAt = (phrase, opts) => HEAD + cues.at(phrase, opts);
		c.cueAfter = (phrase, opts) => HEAD + cues.after(phrase, opts);
		c.untilCue = (phrase, opts) => c.until(HEAD + cues.at(phrase, opts));
		c.duration = duration;
		// Aufraeumen vor dem Vorbereiten: die vorige Szene kann einen Dialog
		// offen gelassen haben.
		await c.closeAllModals();
		await c.evaluate('window.scrollTo(0, 0), true');
	}
	ctx.member = member;

	if (module_.prepare) await module_.prepare(ctx);

	let frames;
	let memberFrames = [];
	try {
		await ctx.startRecording();
		if (member) await member.startRecording();
		await module_.record(ctx);
		await ctx.until(duration);
		frames = await ctx.stopRecording();
		if (member) memberFrames = await member.stopRecording();
	} catch (err) {
		// Aufnahme sauber beenden, sonst schreibt der Bildhandler dieser Szene
		// waehrend eines zweiten Versuchs weiter in denselben Ordner.
		await ctx.stopRecording().catch(() => {});
		if (member) await member.stopRecording().catch(() => {});
		throw err;
	}

	const clockStart = clock.epoch();
	const outFile = join(clipsDir, `${scene.id}.mp4`);
	const roh = buildClip({ frames, clockStart, outFile, duration, framesDir, scale: SCALE });
	addClip(manifest, { source: 'board', file: `${scene.id}.mp4` });
	if (member) {
		buildClip({ frames: memberFrames, clockStart, outFile: join(clipsDir, `${scene.id}.member.mp4`), duration, framesDir: member.framesDir, scale: SCALE });
		addClip(manifest, { source: 'member', file: `${scene.id}.member.mp4` });
	}
	writeManifest(join(clipsDir, `${scene.id}.json`), manifest);
	if (!keepFrames) {
		rmSync(framesDir, { recursive: true, force: true });
		if (member) rmSync(member.framesDir, { recursive: true, force: true });
	}
	return { outFile, duration, frames: frames.length, roh, shots: manifest.shots.length, targets: manifest.targets.length };
}

async function main() {
	setScale(SCALE);
	const content = JSON.parse(readFileSync(join(root, 'content', `${LANG}.json`), 'utf8'));
	const only = arg('scene');
	// Szenen aus dem Inhalt; eine Probeszene (Name mit _) darf ausserhalb stehen.
	const scenes = only?.startsWith('_') ? [{ id: only }] : content.scenes.filter((s) => !only || s.id === only);
	if (scenes.length === 0) throw new Error(`Szene "${only}" steht nicht in content/${LANG}.json`);

	const clipsDir = join(root, 'build', LANG, 'clips');
	mkdirSync(clipsDir, { recursive: true });
	const { version } = ffmpeg.ffmpegPath();
	console.log(`Aufnahme ${LANG}, ${scenes.length} Szene(n), Faktor ${SCALE}, ffmpeg ${version}`);

	const tab = await openBrowser({ port: PORT, user: USER });
	try {
		for (const scene of scenes) {
			const module_ = await import(`../scenes/${scene.id}.mjs`);
			if (!module_.record) {
				console.log(`  ${scene.id.padEnd(16)} keine Aufnahme (reine Karte)`);
				continue;
			}
			process.stdout.write(`  ${scene.id.padEnd(16)} `);
			const t0 = Date.now();
			// Eine Szene darf einmal schiefgehen. Die Wartebedingungen haengen an
			// echten Netzantworten der App; beim zweiten Versuch wird die Szene
			// komplett neu vorbereitet.
			let res;
			for (let versuch = 1; ; versuch++) {
				try {
					res = await recordScene({ tab, scene, module_, lang: LANG, content, clipsDir, keepFrames: flag('keep-frames') });
					break;
				} catch (err) {
					if (versuch >= 2) throw err;
					process.stdout.write(`(1. Versuch fehlgeschlagen: ${err.message.replace(/\s+/g, ' ').slice(0, 90)} - neuer Versuch) `);
					await openApp(tab, URL_BASE);
					if (memberTab) await openApp(memberTab, URL_BASE);
				}
			}
			console.log(`${res.duration.toFixed(2)} s · ${res.frames} Bilder (${res.roh.width}x${res.roh.height}) · `
				+ `${res.shots} Einstellungen · ${res.targets} Ziele · ${((Date.now() - t0) / 1000).toFixed(0)} s`);
		}
		console.log(`\nClips und Manifeste in build/${LANG}/clips/.`);
	} finally {
		if (!flag('keep-tab')) {
			await cdp.closeTab(tab);
			if (memberTab) await cdp.closeTab(memberTab);
		}
	}
}

// Nur beim direkten Aufruf aufnehmen - ein `import { HEAD }` darf keine
// Aufnahme starten.
if (import.meta.url === new URL(`file://${process.argv[1]}`).href.replace(/%3A/g, ':')) {
	main().catch((err) => {
		console.error(`\nFEHLER: ${err.message}`);
		process.exit(1);
	});
}
