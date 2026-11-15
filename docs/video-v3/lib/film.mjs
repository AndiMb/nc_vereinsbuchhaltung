// Generator des HyperFrames-Films: fuellt film-template/ mit Zeitleiste,
// Texten, Clips und Ton und schreibt das Ergebnis nach film/<lang>/.
//
//   node lib/film.mjs --lang de            # erzeugen; bricht ab, wenn eine
//                                          # erzeugte Datei von Hand geaendert wurde
//   node lib/film.mjs --lang de --force    # trotzdem; alte Fassung nach film/de.bak-<zeit>/
//
// Jede erzeugte Datei steht mit Pruefsumme in film/<lang>/.generated.json. So
// geht eine Aenderung, die jemand im HyperFrames-Studio gemacht hat, nicht
// still verloren.

import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync, existsSync, mkdirSync, cpSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { computeTimeline } from './timeline.mjs';
import { loadCues } from './cue.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

const sha = (buf) => createHash('sha256').update(buf).digest('hex');
const SUMS = '.generated.json';

/**
 * Schreibt die Dateien nach outDir. files: relativer Pfad -> Inhalt (string,
 * Buffer) oder { copyFrom: absoluter Pfad }.
 */
export function writeGenerated(outDir, files, { force = false } = {}) {
	const sumsFile = join(outDir, SUMS);
	const alt = existsSync(sumsFile) ? JSON.parse(readFileSync(sumsFile, 'utf8')) : {};
	const geaendert = Object.entries(alt)
		.filter(([rel, sum]) => existsSync(join(outDir, rel)) && sha(readFileSync(join(outDir, rel))) !== sum)
		.map(([rel]) => rel);
	if (geaendert.length > 0) {
		if (!force) {
			throw new Error(`Von Hand geaendert (mit --force ueberschreiben, alte Fassung wird gesichert): ${geaendert.join(', ')}`);
		}
		const stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\..*/, '').replace('T', '-');
		cpSync(outDir, `${outDir}.bak-${stamp}`, { recursive: true });
	}
	const sums = {};
	for (const [rel, inhalt] of Object.entries(files)) {
		const ziel = join(outDir, rel);
		mkdirSync(dirname(ziel), { recursive: true });
		const buf = inhalt?.copyFrom ? readFileSync(inhalt.copyFrom) : Buffer.from(inhalt);
		if (!existsSync(ziel) || sha(readFileSync(ziel)) !== sha(buf)) writeFileSync(ziel, buf);
		sums[rel.replace(/\\/g, '/')] = sha(buf);
	}
	mkdirSync(outDir, { recursive: true });
	writeFileSync(sumsFile, JSON.stringify(sums, null, '\t') + '\n');
	return sums;
}

/** Alle Dateien eines Verzeichnisses rekursiv, relativ. */
export function listFiles(dir) {
	const out = [];
	for (const name of readdirSync(dir)) {
		const p = join(dir, name);
		if (statSync(p).isDirectory()) out.push(...listFiles(p).map((r) => join(name, r)));
		else out.push(name);
	}
	return out.map((r) => r.replace(/\\/g, '/'));
}

// ---------------------------------------------------------------------------
// Bildgeometrie

const W = 1920;
const H = 1080;

/** Mitgliederfenster rechts im Bild, 16:9 wie der Clip. */
export const MEMBER_WINDOW = { x: 770, y: 236, w: 1080, h: 608 };

/** Rechteck aus dem Mitgliederclip in Filmkoordinaten. */
export function memberRect(r) {
	const k = MEMBER_WINDOW.w / W;
	return { x: Math.round(MEMBER_WINDOW.x + r.x * k), y: Math.round(MEMBER_WINDOW.y + r.y * k), w: Math.round(r.w * k), h: Math.round(r.h * k) };
}

/**
 * Verschiebung fuer transform-origin 0 0: das Zielrechteck in die Bildmitte,
 * aber nie so weit, dass ein Rand des Bildes frei wird.
 */
export function zoomTransform(rect, scale) {
	const cx = rect.x + rect.w / 2;
	const cy = rect.y + rect.h / 2;
	const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
	const x = clamp(W / 2 - cx * scale, W - W * scale, 0);
	const y = clamp(H / 2 - cy * scale, H - H * scale, 0);
	return { x: Math.round(x) || 0, y: Math.round(y) || 0, scale };
}

// ---------------------------------------------------------------------------
// Vorlage fuellen

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const fill = (tpl, vars) => tpl.replace(/\{\{([A-Z_]+)\}\}/g, (m, k) => {
	if (!(k in vars)) throw new Error(`Platzhalter {{${k}}} ohne Wert`);
	return String(vars[k]);
});
/** Kurzer, CSS-tauglicher Praefix je Block: "01-souveraen" -> "b01". */
const prefix = (id) => `b${id.slice(0, 2)}`;

/**
 * Bildfolge des Auftakts: je Stichwort ein kurzer Ausschnitt aus einer
 * aufgenommenen Szene (content cards.intro), harter Schnitt 0,15 s vor dem
 * Wort. Der erste beginnt bei 0, der letzte laeuft 0,6 s unter die
 * Titelkarte, die zum titleCue erscheint. Zeiten relativ zum Block.
 */
export function introShots({ intro, titleCue, at, manifests, head = 0.55 }) {
	const r3 = (x) => Math.round(x * 1000) / 1000;
	const lead = 0.15;
	const titleAt = r3(at(titleCue) + head - lead);
	const starts = intro.map((s, i) => (i === 0 ? 0 : r3(at(s.cue) + head - lead)));
	const shots = intro.map((s, i) => {
		const shot = manifests[s.scene]?.shots.find((x) => x.id === s.shot);
		if (!shot) throw new Error(`Auftakt: Shot ${s.scene}/${s.shot} fehlt im Manifest`);
		const end = i + 1 < intro.length ? starts[i + 1] : titleAt + 0.6;
		return { scene: s.scene, start: starts[i], duration: r3(end - starts[i]), mediaStart: r3(shot.t + (s.offset ?? 0)) };
	});
	return { titleAt, shots };
}
const r3 = (v) => Math.round(v * 1000) / 1000;

function blockFile({ tplBlock, tplExtra, block, content, cues, isLast }) {
	const P = prefix(block.id);
	const zooms = block.zooms.map((z) => {
		const rect = z.source === 'member' ? memberRect(z.rect) : z.rect;
		const scale = z.source === 'member' ? Math.max(z.scale, zoomScaleFor(rect)) : z.scale;
		return { t: z.t, hold: z.hold, ...zoomTransform(rect, scale) };
	});
	const hasMember = block.clips.some((c) => c.source === 'member');
	const member = hasMember ? [
		`        <div id="${P}-mwin">`,
		`          <video id="${P}-member" class="clip" src="assets/clips/${block.id}.member.mp4" muted playsinline data-start="0" data-duration="${block.duration}" data-track-index="2"></video>`,
		'        </div>',
		`        <div id="${P}-mlabel">${esc(content.cards.memberLabel)}</div>`,
	].join('\n') : '';
	const lowerThird = block.lowerThird
		? `    <div class="${P}-lt clip" id="${P}-lt" data-start="0.6" data-duration="4.4" data-track-index="3"><div class="${P}-lt-in">${esc(block.lowerThird)}</div></div>`
		: '';
	const callouts = block.callouts.map((c, i) =>
		`    <div class="${P}-co clip" id="${P}-co${i}" data-start="${c.t}" data-duration="${c.hold}" data-track-index="4"><div class="${P}-co-in">${esc(c.text)}</div></div>`).join('\n');

	const data = { duration: block.duration, xfade: 0.45, fadeIn: true, fadeOut: !isLast, zooms, memberWindows: block.memberWindows };
	let extraCss = '';
	let extraHtml = '';
	let extraJs = '';
	if (tplExtra) {
		const teil = (name) => tplExtra.split(`<!--${name}-->`)[1].split('<!--')[0];
		const endAt = r3(Math.max(0, cues.at(content.cards.endCardCue) + 0.55 - 0.3));
		const claimAt = r3(cues.at(content.cards.claimCue) + 0.55 - 0.2);
		Object.assign(data, { endAt, claimAt });
		const vars = {
			P, END_AT: endAt, END_DURATION: r3(block.duration - endAt), TITLE: esc(content.cards.title),
			LINES: content.cards.outroLines.map((l) => `        <div class="${P}-end-line">${esc(l)}</div>`).join('\n'),
			URL: esc(content.cards.repoUrl), CLAIM: esc(content.cards.claim),
		};
		extraCss = fill(teil('CSS'), vars);
		extraHtml = fill(teil('HTML'), vars);
		extraJs = teil('JS');
	}
	return fill(tplBlock, {
		P, ID: block.id, DURATION: block.duration, MW_X: MEMBER_WINDOW.x, MW_Y: MEMBER_WINDOW.y, MW_W: MEMBER_WINDOW.w,
		MW_H: MEMBER_WINDOW.h, MW_LABEL_Y: MEMBER_WINDOW.y - 58, MEMBER: member, LOWER_THIRD: lowerThird, CALLOUTS: callouts,
		EXTRA_CSS: extraCss, EXTRA_HTML: extraHtml, EXTRA_JS: extraJs, DATA: JSON.stringify(data),
	});
}

function zoomScaleFor(rect) {
	return Math.round(Math.max(1, Math.min(0.6 * W / rect.w, 0.6 * H / rect.h, 2.5)) * 100) / 100;
}

/** Musik: endet mit dem Film (ohne Loop), Ducking unter jeder Sprachspur. */
export function musicPlan({ total, musicSeconds, voices, duck = 0.5, ramp = 0.35 }) {
	const start = r3(Math.max(0, total - musicSeconds));
	const duration = r3(total - start);
	const points = [{ t: 0, v: 1 }];
	for (const v of voices) {
		const a = r3(Math.max(0, v.start - start - ramp));
		const b = r3(Math.max(0, v.start - start));
		const c = r3(v.start + v.duration - start);
		const d = r3(c + ramp * 2);
		if (c <= 0) continue;
		points.push({ t: a, v: 1 }, { t: b, v: duck }, { t: c, v: duck }, { t: d, v: 1 });
	}
	// Ueberlappende Rampen zusammenlegen: Punkte muessen aufsteigend sein.
	const sorted = [];
	for (const p of points) {
		if (sorted.length && p.t <= sorted[sorted.length - 1].t) {
			sorted[sorted.length - 1] = { t: sorted[sorted.length - 1].t, v: Math.min(sorted[sorted.length - 1].v, p.v) };
		} else sorted.push(p);
	}
	return { start, duration, automation: { version: 1, lanes: [{ target: 'volume', points: sorted }] } };
}

/** Zeitleiste einer Sprache aus Inhalt, Vertonung und Manifesten (fuer Film, Untertitel, Pruefung). */
export function loadFilmInputs(lang, { buildDir = join(root, 'build', lang) } = {}) {
	const content = JSON.parse(readFileSync(join(root, 'content', `${lang}.json`), 'utf8'));
	const timing = JSON.parse(readFileSync(join(buildDir, 'vo', 'timing.json'), 'utf8'));
	const manifests = {};
	const cues = {};
	for (const s of content.scenes) {
		const mf = join(buildDir, 'clips', `${s.id}.json`);
		if (existsSync(mf)) manifests[s.id] = JSON.parse(readFileSync(mf, 'utf8'));
		cues[s.id] = loadCues(lang, s.id);
	}
	const timeline = computeTimeline({ scenes: content.scenes, timing, manifests, cues });
	return { content, timing, manifests, cues, timeline };
}

export const buildTimeline = (lang, opts) => loadFilmInputs(lang, opts).timeline;

/** Alle Dateien des Films als Map fuer writeGenerated. */
export async function buildFilm(lang, { templateDir = join(root, 'film-template'), buildDir = join(root, 'build', lang), musicFile = join(root, '..', '..', 'lvymusic-calm-background-for-video-121519.mp3') } = {}) {
	const { duration: probeDuration } = await import('./ffmpeg.mjs');
	const { content, timing, manifests, cues, timeline: tl } = loadFilmInputs(lang, { buildDir });

	const files = {};
	const tplBlock = readFileSync(join(templateDir, 'compositions', 'block.html'), 'utf8');
	const tplCard = readFileSync(join(templateDir, 'compositions', '00-auftakt.html'), 'utf8');
	const tplOutro = readFileSync(join(templateDir, 'compositions', '07-abspann.html'), 'utf8');

	const hosts = [];
	const voices = [];
	tl.blocks.forEach((b, i) => {
		const isCard = !manifests[b.id];
		const P = isCard ? 'a00' : prefix(b.id);
		if (isCard) {
			const intro = introShots({
				intro: content.cards.intro, titleCue: content.cards.titleCue,
				at: (w) => cues[b.id].at(w), manifests,
			});
			const shotTags = intro.shots.map((s, k) => [
				`    <div id="a00-shot${k}" class="a00-shot">`,
				`      <video id="a00-v${k}" class="clip" src="assets/clips/${s.scene}.mp4" muted playsinline`,
				`        data-start="${s.start}" data-duration="${s.duration}" data-media-start="${s.mediaStart}" data-track-index="${k + 1}"></video>`,
				'    </div>',
			].join('\n')).join('\n');
			files[`compositions/${b.id}.html`] = fill(tplCard, {
				DURATION: b.duration, TITLE: esc(content.cards.title), SUBTITLE: esc(content.cards.subtitle), SHOTS: shotTags,
				DATA: JSON.stringify({ duration: b.duration, xfade: tl.xfade, titleAt: intro.titleAt, shots: intro.shots }),
			});
		} else {
			files[`compositions/${b.id}.html`] = blockFile({
				tplBlock, tplExtra: i === tl.blocks.length - 1 ? tplOutro : null, block: b, content, cues: cues[b.id],
				isLast: i === tl.blocks.length - 1,
			});
			for (const c of b.clips) files[`assets/clips/${c.file}`] = { copyFrom: join(buildDir, 'clips', c.file) };
		}
		hosts.push([
			`      <div id="${P}" data-composition-id="${P}" data-composition-src="compositions/${b.id}.html"`,
			`        data-start="${b.start}" data-duration="${b.duration}" data-track-index="${i + 1}" data-track-kind="graphics"`,
			`        data-width="1920" data-height="1080" style="z-index: ${i + 1}"></div>`,
		].join('\n'));
		const vo = join(buildDir, 'vo', `${b.id}.mp3`);
		files[`assets/vo/${b.id}.mp3`] = { copyFrom: vo };
		const voDur = timing.scenes[b.id].duration;
		voices.push({ id: b.id, start: b.voStart, duration: voDur });
	});
	const voiceTags = voices.map((v) =>
		`      <audio id="vo-${prefix(v.id)}" class="clip" src="assets/vo/${v.id}.mp3" data-start="${v.start}" data-duration="${v.duration}" data-track-index="${10 + Number(v.id.slice(0, 2))}" data-volume="1"></audio>`).join('\n');

	const musicSeconds = probeDuration(musicFile);
	const music = musicPlan({ total: tl.total, musicSeconds, voices });
	files['assets/music.mp3'] = { copyFrom: musicFile };
	files['index.html'] = fill(readFileSync(join(templateDir, 'index.html'), 'utf8'), {
		LANG: lang, TOTAL: tl.total, HOSTS: hosts.join('\n'), VOICES: voiceTags,
		MUSIC_START: music.start, MUSIC_DURATION: music.duration, MUSIC_AUTOMATION: JSON.stringify(music.automation),
	});

	// Uebrige Projektdateien der Vorlage unveraendert (Schriften, Logo, Konfiguration).
	for (const rel of listFiles(templateDir)) {
		if (rel === 'index.html' || rel.startsWith('compositions/') || rel.startsWith('node_modules/')) continue;
		files[rel] = { copyFrom: join(templateDir, rel) };
	}
	return { files, timeline: tl, music };
}

function arg(name, fallback = null) {
	const i = process.argv.indexOf(`--${name}`);
	return i >= 0 ? process.argv[i + 1] : fallback;
}

if (process.argv[1]?.replace(/\\/g, '/').endsWith('lib/film.mjs')) {
	const lang = arg('lang', 'de');
	const out = join(root, 'film', lang);
	const { files, timeline, music } = await buildFilm(lang);
	writeGenerated(out, files, { force: process.argv.includes('--force') });
	console.log(`film/${lang}: ${Object.keys(files).length} Dateien, Laenge ${timeline.total} s, Musik ab ${music.start} s`);
	for (const b of timeline.blocks) console.log(`  ${b.id.padEnd(16)} ${String(b.start).padStart(7)} s  +${b.duration} s  ${b.zooms.length} Zoom(s)  ${b.memberWindows.length ? 'Mitglied' : ''}`);
}

export { root as videoRoot, relative };
