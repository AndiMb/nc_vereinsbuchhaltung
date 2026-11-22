// Thumbnail (1280x720) und die Texte fuer die Veroeffentlichung.
//
//   node lib/thumbnail.mjs --lang de
//
// Das Bild der App stammt aus dem fertigen Film (build/<lang>/
// vereinsbuchhaltung-<lang>.mp4): ein Einzelbild der Uebersicht in Block 01.
// Gebaut wird das Thumbnail im Browser auf about:blank - dort gilt keine
// Inhaltssicherheitsrichtlinie, das Standbild darf also als data-URI hinein.
//
// Die Kapitelmarken der Beschreibung kommen aus der Zeitleiste
// (blocks[].start); YouTube verlangt Beginn bei 0:00 und Kapitel >= 10 s.
// Alle Texte stehen in content/<lang>.json unter "publish".

import { readFileSync, writeFileSync, existsSync, mkdtempSync, rmSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { ensureChrome, DEFAULT_PORT } from './chrome.mjs';
import { COLORS } from './harness.mjs';
import * as ffmpeg from './ffmpeg.mjs';

const require = createRequire(import.meta.url);
const cdp = require('./cdp.cjs');

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

function arg(name, fallback = null) {
	const i = process.argv.indexOf(`--${name}`);
	return i >= 0 && process.argv[i + 1] && !process.argv[i + 1].startsWith('--') ? process.argv[i + 1] : fallback;
}

const KAPITEL_MIN = 10;
const mmss = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

/**
 * Kapitelzeilen "m:ss Titel" je Block. Die Marke liegt am Ende der Blende
 * (start + xfade), gerundet; das erste Kapitel beginnt immer bei 0:00.
 */
export function chapterLines(timeline, titles) {
	const marken = timeline.blocks.map((b, i) => {
		if (!titles[b.id]) throw new Error(`Kapiteltitel fuer ${b.id} fehlt (content publish.kapitel)`);
		return { id: b.id, t: i === 0 ? 0 : Math.round(b.start + (timeline.xfade ?? 0)), titel: titles[b.id] };
	});
	marken.forEach((m, i) => {
		const ende = i + 1 < marken.length ? marken[i + 1].t : timeline.total;
		if (ende - m.t < KAPITEL_MIN) throw new Error(`Kapitel ${m.id} ist nur ${ende - m.t} s lang (YouTube verlangt >= ${KAPITEL_MIN} s)`);
	});
	return marken.map((m) => `${mmss(m.t)} ${m.titel}`);
}

const SEITE = (t, bildDataUri, logoDataUri) => `(() => {
	document.documentElement.style.margin = '0';
	document.body.style.margin = '0';
	document.body.style.background = '${COLORS.darker}';
	document.body.innerHTML = '';

	const buehne = document.createElement('div');
	Object.assign(buehne.style, {
		position: 'relative', width: '1280px', height: '720px', overflow: 'hidden',
		background: 'radial-gradient(120% 130% at 12% 10%, #17406b 0%, ${COLORS.dark} 55%, ${COLORS.darker} 100%)',
		fontFamily: '"Segoe UI", system-ui, sans-serif',
	});

	// Standbild aus dem Film, angeschnitten und leicht gedreht - echte
	// Oberflaeche, ohne den Titel zu erdruecken.
	const bild = document.createElement('img');
	bild.src = ${JSON.stringify(bildDataUri)};
	Object.assign(bild.style, {
		position: 'absolute', right: '-90px', bottom: '-40px', width: '760px',
		borderRadius: '10px', transform: 'rotate(-6deg)',
		boxShadow: '0 40px 90px rgba(0,0,0,.6)', border: '1px solid rgba(255,255,255,.12)',
	});
	buehne.appendChild(bild);

	const schleier = document.createElement('div');
	Object.assign(schleier.style, {
		position: 'absolute', inset: '0',
		background: 'linear-gradient(100deg, rgba(6,16,32,.96) 0%, rgba(6,16,32,.86) 44%, rgba(6,16,32,0) 72%)',
	});
	buehne.appendChild(schleier);

	const text = document.createElement('div');
	Object.assign(text.style, {
		position: 'absolute', left: '64px', top: '86px', width: '640px',
		display: 'flex', flexDirection: 'column', gap: '18px',
	});

	const logo = document.createElement('img');
	logo.src = ${JSON.stringify(logoDataUri)};
	Object.assign(logo.style, { width: '72px', height: '72px', padding: '12px', borderRadius: '16px', background: '${COLORS.accent}', boxSizing: 'border-box' });

	const badge = document.createElement('div');
	badge.textContent = ${JSON.stringify(t.badge)};
	Object.assign(badge.style, {
		alignSelf: 'flex-start', background: '${COLORS.accent}', color: '#fff',
		fontWeight: '700', fontSize: '24px', letterSpacing: '.6px',
		padding: '8px 16px', borderRadius: '4px',
	});

	const h1 = document.createElement('div');
	h1.innerHTML = ${JSON.stringify(t.zeile1)};
	Object.assign(h1.style, { color: '#fff', fontWeight: '800', fontSize: '82px', lineHeight: '1.02', letterSpacing: '-1.5px' });

	const h2 = document.createElement('div');
	h2.textContent = ${JSON.stringify(t.zeile2)};
	Object.assign(h2.style, { color: '${COLORS.muted}', fontWeight: '600', fontSize: '52px', lineHeight: '1.05' });

	const fuss = document.createElement('div');
	fuss.textContent = ${JSON.stringify(t.fuss)};
	Object.assign(fuss.style, { color: '#9fc0dd', fontSize: '25px', lineHeight: '1.35', marginTop: '14px' });

	text.append(logo, badge, h1, h2, fuss);
	buehne.appendChild(text);
	document.body.appendChild(buehne);
	return true;
})()`;

/** Einzelbild des Films als PNG-data-URI. */
function standbild(video, sekunde) {
	const ordner = mkdtempSync(join(tmpdir(), 'vbh-thumb-'));
	const png = join(ordner, 'still.png');
	try {
		ffmpeg.run(['-y', '-ss', String(sekunde), '-i', video, '-frames:v', '1', '-vf', 'scale=1920:1080', png], { label: 'Standbild' });
		return `data:image/png;base64,${readFileSync(png).toString('base64')}`;
	} finally {
		rmSync(ordner, { recursive: true, force: true });
	}
}

export async function buildThumbnail(lang) {
	const { loadFilmInputs } = await import('./film.mjs');
	const { content, manifests, timeline } = loadFilmInputs(lang);
	const t = content.publish;
	const video = join(root, 'build', lang, `vereinsbuchhaltung-${lang}.mp4`);
	if (!existsSync(video)) throw new Error(`Video fehlt: ${video} (erst finish.mjs)`);

	// Die Uebersicht der App in Block 01: Shot "uebersicht", gut 2 s spaeter.
	const b01 = timeline.blocks.find((b) => b.id === '01-souveraen');
	const shot = manifests['01-souveraen']?.shots.find((s) => s.id === 'uebersicht');
	const sekunde = b01.start + (shot ? shot.t + 2.2 : b01.duration / 3);
	const bild = standbild(video, sekunde);
	const logo = `data:image/svg+xml;base64,${readFileSync(join(root, '..', '..', 'img', 'app.svg')).toString('base64')}`;

	// Eigener Port: die Aufnahme-Browser belegen andere.
	await ensureChrome({ port: DEFAULT_PORT + 3, width: 1280, height: 720 });
	const tab = await cdp.openTab('about:blank', DEFAULT_PORT + 3);
	try {
		await cdp.send(tab, 'Emulation.setDeviceMetricsOverride', { width: 1280, height: 720, deviceScaleFactor: 1, mobile: false });
		await cdp.evaluate(tab, SEITE(t, bild, logo));
		await new Promise((r) => setTimeout(r, 800));
		const datei = join(root, 'build', lang, `thumbnail-${lang}.png`);
		await cdp.screenshot(tab, datei);

		const texte = [
			`# Veroeffentlichung ${lang}`,
			'',
			'## Titel',
			t.titel,
			'',
			'## Beschreibung',
			...t.einleitung,
			...chapterLines(timeline, t.kapitel),
			...t.schluss,
			'',
			'## Schlagworte',
			t.schlagworte.join(', '),
			'',
			'## Dateien',
			`- Video: build/${lang}/vereinsbuchhaltung-${lang}.mp4`,
			`- Untertitel: build/${lang}/vereinsbuchhaltung-${lang}.srt (und .vtt)`,
			`- Thumbnail: build/${lang}/thumbnail-${lang}.png`,
			'',
		].join('\n');
		const textDatei = join(root, 'build', lang, `veroeffentlichung-${lang}.md`);
		writeFileSync(textDatei, texte, 'utf8');
		return { datei, textDatei };
	} finally {
		await cdp.closeTab(tab);
	}
}

if (process.argv[1]?.replace(/\\/g, '/').endsWith('lib/thumbnail.mjs')) {
	const lang = arg('lang', 'de');
	const res = await buildThumbnail(lang);
	console.log(`Thumbnail: ${res.datei}`);
	console.log(`Texte:     ${res.textDatei}`);
}
