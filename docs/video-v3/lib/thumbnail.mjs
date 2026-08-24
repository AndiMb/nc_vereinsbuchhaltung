// Thumbnail (1280x720) und die Texte fuer die Veroeffentlichung.
//
//   node lib/thumbnail.mjs --lang de
//
// Gebaut wird es im Browser auf about:blank - dort gilt keine
// Inhaltssicherheitsrichtlinie, das Standbild der App darf also als data-URI
// hinein. Titel und Beschreibung landen als Textdatei daneben; hochgeladen
// wird von Hand.

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { ensureChrome, DEFAULT_PORT } from './chrome.mjs';
import { COLORS } from './harness.mjs';

const require = createRequire(import.meta.url);
const cdp = require('./cdp.cjs');

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

function arg(name, fallback = null) {
	const i = process.argv.indexOf(`--${name}`);
	return i >= 0 && process.argv[i + 1] && !process.argv[i + 1].startsWith('--') ? process.argv[i + 1] : fallback;
}

const TEXTE = {
	de: {
		zeile1: 'Vereins&shy;buchhaltung',
		zeile2: 'für Nextcloud',
		badge: 'NEU: Beiträge & SEPA',
		fuss: 'Kontoauszug importieren · zuordnen · Kassenbericht drucken',
		titel: 'Vereinsbuchhaltung für Nextcloud – Vereinsfinanzen in der eigenen Cloud',
		beschreibung: [
			'Buchhaltung für Vereine, vollständig in der eigenen Nextcloud: Kontoauszüge importieren, Umsätze zuordnen, Mitgliedsbeiträge per SEPA-Lastschrift einziehen und den Kassenbericht für die Mitgliederversammlung drucken.',
			'',
			'Im Video:',
			'0:00 Worum es geht',
			'0:14 Die Übersicht',
			'0:36 Eine Buchung erfassen',
			'0:56 Kontoauszug importieren, Regeln, Dublettenerkennung',
			'1:28 Neu: Mitgliedsbeiträge und SEPA-Lastschrift (pain.008)',
			'2:06 Berichte, Finanzplan, Sphären',
			'2:31 Kassenbericht, Kassenprüfung, Jahresabschluss',
			'2:58 Unterwegs auf dem Smartphone',
			'3:12 Ausprobieren',
			'',
			'Die App ist kostenlos und quelloffen (AGPL) und liegt im Nextcloud App Store.',
			'Quellcode: https://github.com/AndiMb/nc_vereinsbuchhaltung',
			'',
			'Alle im Video gezeigten Daten sind erfunden: ein Beispielchor, erfundene Namen, IBANs aus dem Testbereich.',
		],
		schlagworte: ['Nextcloud', 'Vereinsbuchhaltung', 'Verein', 'Buchhaltung', 'SEPA', 'Kassenwart', 'Kassenbericht', 'Open Source', 'Selfhosting'],
	},
	en: {
		zeile1: 'Club accounting',
		zeile2: 'inside Nextcloud',
		badge: 'NEW: fees & SEPA',
		fuss: 'Import statements · assign · print the treasurer’s report',
		titel: 'Club accounting in your own Nextcloud – Vereinsbuchhaltung app',
		beschreibung: [
			'Accounting for nonprofit clubs, entirely inside your own Nextcloud: import bank statements, assign transactions, collect membership fees by SEPA direct debit and print the treasurer’s report for the general assembly.',
			'',
			'In this video:',
			'0:00 What it is about',
			'0:13 The overview',
			'0:33 Recording an entry',
			'0:48 Bank statements, rules, duplicate detection',
			'1:16 New: membership fees and SEPA direct debit (pain.008)',
			'1:46 Reports, budget, nonprofit spheres',
			'2:10 Treasurer’s report, audit, year-end closing',
			'2:34 On the go',
			'2:46 Try it out',
			'',
			'The app is free and open source (AGPL) and available in the Nextcloud App Store.',
			'Source code: https://github.com/AndiMb/nc_vereinsbuchhaltung',
			'',
			'All data shown is invented: a sample choir, made-up names, IBANs from the documented test range.',
		],
		schlagworte: ['Nextcloud', 'club accounting', 'nonprofit', 'bookkeeping', 'SEPA', 'treasurer', 'open source', 'selfhosted'],
	},
};

const SEITE = (lang, bildDataUri) => {
	const t = TEXTE[lang];
	return `(() => {
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

	// Standbild der App, angeschnitten und leicht gedreht - es soll klar sein,
	// dass es echte Oberflaeche ist, ohne den Titel zu erdruecken.
	const bild = document.createElement('img');
	bild.src = ${JSON.stringify(bildDataUri)};
	Object.assign(bild.style, {
		position: 'absolute', right: '-90px', bottom: '-40px', width: '760px',
		borderRadius: '10px', transform: 'rotate(-6deg)',
		boxShadow: '0 40px 90px rgba(0,0,0,.6)', border: '1px solid rgba(255,255,255,.12)',
	});
	buehne.appendChild(bild);

	// Abdunkelung links, damit der Text auf jeden Fall traegt.
	const schleier = document.createElement('div');
	Object.assign(schleier.style, {
		position: 'absolute', inset: '0',
		background: 'linear-gradient(100deg, rgba(6,16,32,.96) 0%, rgba(6,16,32,.86) 44%, rgba(6,16,32,0) 72%)',
	});
	buehne.appendChild(schleier);

	const text = document.createElement('div');
	Object.assign(text.style, {
		position: 'absolute', left: '64px', top: '96px', width: '640px',
		display: 'flex', flexDirection: 'column', gap: '18px',
	});

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

	text.append(badge, h1, h2, fuss);
	buehne.appendChild(text);
	document.body.appendChild(buehne);
	return true;
})()`;
};

export async function buildThumbnail(lang) {
	const t = TEXTE[lang];
	const still = join(root, 'build', lang, 'seed-dashboard.png');
	if (!existsSync(still)) throw new Error(`Standbild fehlt: ${still} (erst seed.mjs laufen lassen)`);
	const dataUri = `data:image/png;base64,${readFileSync(still).toString('base64')}`;

	// Eigener Port: 9445/9446 gehoeren den Aufnahme-Browsern (je Sprache einer,
	// mit passender Browsersprache) - ein geteilter Port haette dort die falsche
	// Sprache in Chromes eigenen Bedienelementen hinterlassen.
	await ensureChrome({ port: DEFAULT_PORT + 3, width: 1280, height: 720 });
	const tab = await cdp.openTab('about:blank', DEFAULT_PORT + 3);
	try {
		await cdp.send(tab, 'Emulation.setDeviceMetricsOverride', { width: 1280, height: 720, deviceScaleFactor: 1, mobile: false });
		await cdp.evaluate(tab, SEITE(lang, dataUri));
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
			...t.beschreibung,
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

if (import.meta.url === new URL(`file://${process.argv[1]}`).href.replace(/%3A/g, ':')) {
	const lang = arg('lang', 'de');
	const res = await buildThumbnail(lang);
	console.log(`Thumbnail: ${res.datei}`);
	console.log(`Texte:     ${res.textDatei}`);
}
