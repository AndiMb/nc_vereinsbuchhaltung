// Render und Endbearbeitung des HyperFrames-Films.
//
//   node lib/finish.mjs --lang de                # rendern + Lautheit
//   node lib/finish.mjs --lang de --render-only  # nur rendern
//   node lib/finish.mjs --lang de --skip-render  # nur Lautheit auf build/<lang>/render.mp4
//
// 1. npx hyperframes render film/<lang> --quality delivery --fps 30
//    -> build/<lang>/render.mp4
// 2. Zwei-Pass-loudnorm auf -14 LUFS (I), True Peak -1,5 dBTP. Das Bild wird
//    nur kopiert (-c:v copy), damit die Lautheitskorrektur keine zweite
//    Videokompression kostet.
// 3. Pixelseitenverhaeltnis pruefen: nicht 1:1 -> Abbruch (sonst zeigt ein
//    Player das Bild verzerrt; im Einzelbild sieht man davon nichts).
//    -> build/<lang>/vereinsbuchhaltung-<lang>.mp4
//
// HyperFrames ruft ffprobe ueber den PATH auf; der Ordner des gefundenen
// ffmpeg wird deshalb vorangestellt.

import { existsSync } from 'node:fs';
import { execFileSync, spawnSync } from 'node:child_process';
import { dirname, join, delimiter } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as ffmpeg from './ffmpeg.mjs';
import { lautheit } from './verify.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

function arg(name, fallback = null) {
	const i = process.argv.indexOf(`--${name}`);
	return i >= 0 && process.argv[i + 1] && !process.argv[i + 1].startsWith('--') ? process.argv[i + 1] : fallback;
}

/** Umgebung fuer HyperFrames: ffmpeg/ffprobe im PATH, keine Telemetrie. */
export function hyperframesEnv() {
	const bin = dirname(ffmpeg.ffmpegPath().path);
	return { ...process.env, PATH: `${bin}${delimiter}${process.env.PATH ?? ''}`, HYPERFRAMES_NO_TELEMETRY: '1' };
}

export function render(lang) {
	const film = join(root, 'film', lang);
	if (!existsSync(join(film, 'index.html'))) throw new Error(`Film fehlt: ${film} (erst node lib/film.mjs --lang ${lang})`);
	const out = join(root, 'build', lang, 'render.mp4');
	// Wenige Worker: jeder ist ein eigener Chrome, der 4K-Clips dekodiert; mit
	// "auto" lief der Rechner einmal voll Speicher.
	const workers = arg('workers', '4');
	const r = spawnSync('npx', ['hyperframes', 'render', film, '--quality', 'delivery', '--fps', '30', '--workers', workers, '--output', out],
		{ env: hyperframesEnv(), stdio: 'inherit', shell: process.platform === 'win32' });
	if (r.status !== 0) throw new Error(`hyperframes render fehlgeschlagen (Exit ${r.status})`);
	return out;
}

/** Zwei-Pass-loudnorm; Video wird kopiert. */
export function loudnorm(input, output, { I = -14, TP = -1.5, LRA = 11 } = {}) {
	const m = lautheit(input);
	const filter = `loudnorm=I=${I}:TP=${TP}:LRA=${LRA}:measured_I=${m.input_i}:measured_TP=${m.input_tp}`
		+ `:measured_LRA=${m.input_lra}:measured_thresh=${m.input_thresh}:offset=${m.target_offset}:linear=true`;
	ffmpeg.run(['-y', '-i', input, '-map', '0:v:0', '-map', '0:a:0', '-c:v', 'copy', '-af', filter,
		'-c:a', 'aac', '-b:a', '192k', '-ar', '48000', '-ac', '2', '-movflags', '+faststart', output], { label: 'loudnorm' });
	return { vorher: Number(m.input_i), output };
}

export function checkSar(file) {
	const daten = JSON.parse(execFileSync(ffmpeg.ffprobePath(), ['-v', 'error', '-select_streams', 'v:0', '-show_streams', '-of', 'json', file], { encoding: 'utf8' }));
	const sar = daten.streams[0]?.sample_aspect_ratio ?? '1:1';
	if (sar !== '1:1' && sar !== 'N/A') throw new Error(`${file}: Pixelseitenverhaeltnis ${sar} statt 1:1`);
	return sar;
}

export function finish(lang, { skipRender = false } = {}) {
	const roh = skipRender ? join(root, 'build', lang, 'render.mp4') : render(lang);
	checkSar(roh);
	const ziel = join(root, 'build', lang, `vereinsbuchhaltung-${lang}.mp4`);
	const { vorher } = loudnorm(roh, ziel);
	const nachher = Number(lautheit(ziel).input_i);
	return { roh, ziel, vorher, nachher };
}

if (process.argv[1]?.replace(/\\/g, '/').endsWith('lib/finish.mjs')) {
	const lang = arg('lang', 'de');
	if (process.argv.includes('--render-only')) {
		console.log(render(lang));
	} else {
		const r = finish(lang, { skipRender: process.argv.includes('--skip-render') });
		console.log(`${r.ziel}\n  Lautheit ${r.vorher.toFixed(1)} -> ${r.nachher.toFixed(1)} LUFS`);
	}
}
