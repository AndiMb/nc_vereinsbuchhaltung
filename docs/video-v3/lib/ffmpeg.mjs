// ffmpeg finden und aufrufen.
//
// Das per winget installierte ffmpeg 9 liegt nicht im PATH dieser Shell, und das
// mitgelieferte 4.2.3 aus dem ImageMagick-Bundle kennt kein xfade. Deshalb wird
// hier gezielt gesucht statt auf "ffmpeg" im PATH zu hoffen - und die Version
// geprueft, bevor eine Filterkette benutzt wird, die es dort nicht gibt.

import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

function candidates() {
	const local = process.env.LOCALAPPDATA || '';
	const list = [];
	if (process.env.VBH_FFMPEG) list.push(process.env.VBH_FFMPEG);

	const wingetRoot = join(local, 'Microsoft', 'WinGet', 'Packages');
	if (existsSync(wingetRoot)) {
		for (const dir of readdirSync(wingetRoot).filter((d) => d.startsWith('Gyan.FFmpeg'))) {
			const build = join(wingetRoot, dir);
			for (const sub of readdirSync(build).filter((d) => d.startsWith('ffmpeg-'))) {
				list.push(join(build, sub, 'bin', 'ffmpeg.exe'));
			}
		}
	}
	list.push('C:/Program Files/ImageMagick-7.1.1-Q16-HDRI/ffmpeg.exe', 'ffmpeg');
	return list;
}

let cached = null;

export function ffmpegPath() {
	if (cached) return cached;
	for (const candidate of candidates()) {
		try {
			const out = execFileSync(candidate, ['-version'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
			const version = /ffmpeg version (\S+)/.exec(out)?.[1] ?? '?';
			const major = Number(/^(\d+)/.exec(version)?.[1] ?? 0);
			cached = { path: candidate, version, major };
			return cached;
		} catch { /* naechster Kandidat */ }
	}
	throw new Error('Kein ffmpeg gefunden. Erwartet: winget install Gyan.FFmpeg (oder VBH_FFMPEG setzen)');
}

export function ffprobePath() {
	const { path } = ffmpegPath();
	const probe = path.replace(/ffmpeg(\.exe)?$/i, (m) => (m.toLowerCase().endsWith('.exe') ? 'ffprobe.exe' : 'ffprobe'));
	return existsSync(probe) ? probe : null;
}

/** Laufzeit einer Mediendatei in Sekunden. */
export function duration(file) {
	const probe = ffprobePath();
	if (probe) {
		const out = execFileSync(probe, ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', file], { encoding: 'utf8' });
		return Number(out.trim());
	}
	// Rueckfall ohne ffprobe: Dauer aus der ffmpeg-Ausgabe lesen.
	const res = spawnSync(ffmpegPath().path, ['-i', file], { encoding: 'utf8' });
	const m = /Duration: (\d+):(\d+):([\d.]+)/.exec(res.stderr || '');
	if (!m) throw new Error(`Dauer von ${file} nicht ermittelbar`);
	return Number(m[1]) * 3600 + Number(m[2]) * 60 + Number(m[3]);
}

/**
 * Fuehrt ffmpeg aus und wirft mit den letzten Ausgabezeilen, wenn es scheitert -
 * eine nackte Exitcode-Meldung hilft bei Filterketten nicht weiter.
 */
export function run(args, { label = 'ffmpeg' } = {}) {
	const { path } = ffmpegPath();
	const res = spawnSync(path, ['-hide_banner', '-loglevel', 'error', '-y', ...args], { encoding: 'utf8' });
	if (res.status !== 0) {
		const fehler = (res.stderr || res.stdout || '').trim().split('\n').slice(-12).join('\n');
		throw new Error(`${label} fehlgeschlagen (Exit ${res.status}):\n${fehler}`);
	}
	return res.stderr || '';
}
