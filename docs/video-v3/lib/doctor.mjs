// Prueft, ob alles fuer einen Sprachlauf bereitsteht - bevor eine Aufnahme
// nach Minuten an einer fehlenden Kleinigkeit scheitert.
//
//   node lib/doctor.mjs [--url http://localhost:8082] [--container vbh-demo]
//
// Exit 0 nur, wenn jeder Punkt ok ist.

import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import * as ffmpeg from './ffmpeg.mjs';
import { findChrome } from './chrome.mjs';
import { PASS_FILE } from './session.mjs';

const here = dirname(fileURLToPath(import.meta.url));
export const MUSIC = join(here, '..', '..', '..', 'lvymusic-calm-background-for-video-121519.mp3');
export const DEMO_USERS = ['andrea', 'jens', 'karla', 'mia'];

function arg(name, fallback) {
	const i = process.argv.indexOf(`--${name}`);
	return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}
const URL_BASE = arg('url', 'http://localhost:8082');
const CONTAINER = arg('container', 'vbh-demo');

const run = (cmd, args) => execFileSync(cmd, args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], shell: process.platform === 'win32' && cmd === 'npx' }).trim();
const occ = (...args) => run('docker', ['exec', '-u', 'www-data', CONTAINER, 'php', 'occ', ...args]);

function versionAtLeast(ist, soll) {
	const a = ist.split('.').map(Number);
	const b = soll.split('.').map(Number);
	for (let i = 0; i < b.length; i++) {
		if ((a[i] ?? 0) !== b[i]) return (a[i] ?? 0) > b[i];
	}
	return true;
}

const checks = [
	['edge-tts >= 7.3.1', () => {
		const v = run('python', ['-c', 'import importlib.metadata as m;print(m.version("edge-tts"))']);
		return [versionAtLeast(v, '7.3.1'), v];
	}],
	['ffmpeg >= 7', () => {
		const { version } = ffmpeg.ffmpegPath();
		return [Number(String(version).split('.')[0]) >= 7, version];
	}],
	['Chromium im ms-playwright-Cache', () => [Boolean(findChrome()), findChrome()]],
	[`Nextcloud unter ${URL_BASE}`, async () => {
		const res = await fetch(`${URL_BASE}/status.php`);
		const s = await res.json();
		return [s.installed === true && !s.maintenance && !s.needsDbUpgrade, `Nextcloud ${s.versionstring}`];
	}],
	['App vereinsbuchhaltung aktiv', () => {
		const list = occ('app:list', '--output=json');
		const enabled = JSON.parse(list).enabled ?? {};
		return [Boolean(enabled.vereinsbuchhaltung), enabled.vereinsbuchhaltung ?? 'nicht aktiv'];
	}],
	[`Demokonten ${DEMO_USERS.join(', ')}`, () => {
		const fehlend = DEMO_USERS.filter((u) => {
			try { occ('user:info', u); return false; } catch { return true; }
		});
		return [fehlend.length === 0, fehlend.length ? `fehlen: ${fehlend.join(', ')}` : 'alle da'];
	}],
	['Passwort (VBH_DEMO_PASS oder build/.demo-pass)', () => {
		const ok = Boolean(process.env.VBH_DEMO_PASS) || existsSync(PASS_FILE);
		return [ok, ok ? 'vorhanden' : 'fehlt'];
	}],
	['Musikbett', () => [existsSync(MUSIC), MUSIC]],
	['HyperFrames-Telemetrie aus', () => {
		const out = run('npx', ['--yes', 'hyperframes', 'telemetry', 'status']);
		const status = (out.match(/Status:\s*(\S+)/) ?? [])[1] ?? 'unbekannt';
		return [status === 'disabled', status];
	}],
];

let alleOk = true;
for (const [name, check] of checks) {
	let ok = false;
	let info = '';
	try {
		[ok, info] = await check();
	} catch (err) {
		info = err.message.split(/\r?\n/)[0];
	}
	alleOk &&= ok;
	console.log(`${ok ? 'ok   ' : 'FEHLT'}  ${name}${info ? `  (${info})` : ''}`);
}
process.exit(alleOk ? 0 : 1);
