// Montage: Clips aneinander blenden, Musik unterlegen, Lautheit normieren.
//
//   node lib/assemble.mjs --lang de
//   node lib/assemble.mjs --lang de --no-music
//   node lib/assemble.mjs --lang de --music pfad/zur/musik.mp3
//
// Blenden laufen ueber xfade (Bild) und acrossfade (Ton) - deshalb braucht die
// Pipeline ffmpeg >= 4.3; das mitgelieferte 4.2.3 aus dem ImageMagick-Bundle
// kann xfade nicht. Die Musik wird geloopt, unter der Sprache abgesenkt
// (sidechaincompress) und am Schluss ausgeblendet.

import { readFileSync, existsSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import * as ffmpeg from './ffmpeg.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');
const repo = join(root, '..', '..');

/** Blendendauer zwischen zwei Szenen. */
export const XFADE = 0.45;

function arg(name, fallback = null) {
	const i = process.argv.indexOf(`--${name}`);
	return i >= 0 && process.argv[i + 1] && !process.argv[i + 1].startsWith('--') ? process.argv[i + 1] : fallback;
}
const flag = (name) => process.argv.includes(`--${name}`);

const LANG = arg('lang', 'de');
const DEFAULT_MUSIC = join(repo, 'lvymusic-calm-background-for-video-121519.mp3');

function hhmmss(seconds) {
	const m = Math.floor(seconds / 60);
	const s = seconds - m * 60;
	return `${m}:${s.toFixed(1).padStart(4, '0')}`;
}

export function assemble({ lang = LANG, music = null, out = null } = {}) {
	const content = JSON.parse(readFileSync(join(root, 'content', `${lang}.json`), 'utf8'));
	const clips = content.scenes.map((scene) => {
		const file = join(root, 'build', lang, 'clips', `${scene.id}.mp4`);
		if (!existsSync(file)) throw new Error(`Clip fehlt: ${file} - erst aufnehmen (node lib/record.mjs --lang ${lang})`);
		return { id: scene.id, file, duration: ffmpeg.duration(file) };
	});

	const { major, version } = ffmpeg.ffmpegPath();
	if (major < 5) {
		throw new Error(`ffmpeg ${version} kennt kein xfade. Aktuelles Build installieren (winget install Gyan.FFmpeg) oder VBH_FFMPEG setzen.`);
	}

	const gesamt = clips.reduce((sum, c) => sum + c.duration, 0) - XFADE * (clips.length - 1);
	const outFile = out ?? join(root, 'build', lang, `vereinsbuchhaltung-${lang}.mp4`);
	mkdirSync(dirname(outFile), { recursive: true });

	// --- Bild: Kette aus xfade-Uebergaengen ---
	//
	// Zuerst jede Spur auf quadratische Pixel setzen. Die Screencast-Bilder
	// bringen eine Pixeldichte mit, aus der ffmpeg ein Seitenverhaeltnis von
	// 855:713 ableitet; unkorrigiert zeigt jeder Player 2,13:1 statt 16:9 -
	// schwarze Balken oben und unten, Bild vertikal gestaucht. Die Korrektur
	// hier (statt nur im Clipbau) macht auch aeltere Clips ohne Neuaufnahme
	// brauchbar.
	const teile = [];
	for (let i = 0; i < clips.length; i++) {
		teile.push(`[${i}:v]setsar=1[q${i}]`);
	}
	let letzteV = '[q0]';
	let offset = 0;
	for (let i = 1; i < clips.length; i++) {
		offset += clips[i - 1].duration - XFADE;
		const ziel = i === clips.length - 1 ? '[vout]' : `[v${i}]`;
		teile.push(`${letzteV}[q${i}]xfade=transition=fade:duration=${XFADE}:offset=${offset.toFixed(3)}${ziel}`);
		letzteV = ziel;
	}
	if (clips.length === 1) teile.push('[q0]null[vout]');

	// --- Ton: dieselbe Kette mit acrossfade ---
	let letzteA = '[0:a]';
	for (let i = 1; i < clips.length; i++) {
		const ziel = i === clips.length - 1 ? '[sprache]' : `[a${i}]`;
		teile.push(`${letzteA}[${i}:a]acrossfade=d=${XFADE}:c1=tri:c2=tri${ziel}`);
		letzteA = ziel;
	}
	if (clips.length === 1) teile.push('[0:a]anull[sprache]');

	const inputs = clips.flatMap((c) => ['-i', c.file]);

	if (music) {
		// Der Track ist kuerzer als das Video, deshalb -stream_loop. Die Option
		// gilt fuer das *naechste* -i und muss deshalb unmittelbar vor der Musik
		// stehen: am Anfang der Liste wuerde sie den ersten Clip endlos
		// wiederholen - im fertigen Video lief dann die Intro-Sprachspur immer
		// wieder, waehrend die Clips selbst in Ordnung waren.
		inputs.push('-stream_loop', '-1', '-i', music);
		const musikIndex = clips.length;
		teile.push(
			`[sprache]asplit=2[sprache_mix][sprache_key]`,
			`[${musikIndex}:a]volume=0.16,afade=t=in:st=0:d=2.5,afade=t=out:st=${(gesamt - 3.5).toFixed(3)}:d=3.5,`
			+ `atrim=0:${gesamt.toFixed(3)},asetpts=N/SR/TB[musik]`,
			// Ducking: die Musik weicht der Sprache, statt gegen sie anzukommen.
			`[musik][sprache_key]sidechaincompress=threshold=0.03:ratio=12:attack=15:release=350[musik_duck]`,
			`[sprache_mix][musik_duck]amix=inputs=2:duration=first:normalize=0[mix]`,
			// -14 LUFS ist die Zielmarke, auf die YouTube ohnehin normiert.
			`[mix]loudnorm=I=-14:TP=-1.5:LRA=11[aout]`,
		);
	} else {
		teile.push(`[sprache]loudnorm=I=-14:TP=-1.5:LRA=11[aout]`);
	}

	ffmpeg.run([
		...inputs,
		'-filter_complex', teile.join(';'),
		'-map', '[vout]', '-map', '[aout]',
		'-t', gesamt.toFixed(3),
		'-c:v', 'libx264', '-preset', 'slow', '-crf', '19', '-pix_fmt', 'yuv420p',
		// Stereo, obwohl die Sprachspur mono ist: YouTube und die meisten Player
		// erwarten zwei Kanaele, mono kommt dort sonst nur auf einer Seite an.
		'-c:a', 'aac', '-b:a', '192k', '-ar', '48000', '-ac', '2',
		'-movflags', '+faststart',
		outFile,
	], { label: 'Montage' });

	return { outFile, clips, gesamt, music };
}

if (import.meta.url === new URL(`file://${process.argv[1]}`).href.replace(/%3A/g, ':')) {
	const music = flag('no-music') ? null : (arg('music') ?? (existsSync(DEFAULT_MUSIC) ? DEFAULT_MUSIC : null));
	if (!music && !flag('no-music')) {
		console.log(`Hinweis: kein Musikbett gefunden (${DEFAULT_MUSIC}) - es wird ohne montiert.`);
	}
	const res = assemble({ lang: LANG, music });
	console.log(`Montage ${LANG}:`);
	for (const c of res.clips) console.log(`  ${c.id.padEnd(16)} ${c.duration.toFixed(2)} s`);
	console.log(`  ${'Blenden'.padEnd(16)} ${(XFADE * (res.clips.length - 1)).toFixed(2)} s Abzug bei ${res.clips.length - 1} Uebergaengen`);
	console.log(`  ${'Musik'.padEnd(16)} ${res.music ? res.music.split(/[\\/]/).pop() : '(ohne)'}`);
	console.log(`\nFertig: ${res.outFile}`);
	console.log(`Laufzeit ${hhmmss(res.gesamt)} (${res.gesamt.toFixed(1)} s), Ton auf -14 LUFS normiert.`);
}
