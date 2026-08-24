// Startet (oder findet) das headless Chromium, in dem Seed und Aufnahme laufen.
//
// Chromium kommt aus dem Playwright-Cache - kein npm-Paket, kein Download. Das
// Profil liegt bewusst ausserhalb des Repos: mitgelieferte Erweiterungen bringen
// eigene *.test.js mit, die vitest sonst als Testdateien einsammelt.

import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import http from 'node:http';

export const DEFAULT_PORT = 9444;

/** Zielgroesse der Aufnahmeflaeche in echten Bildpunkten. */
export const ZIEL_VIEWPORT = { width: 1920, height: 1080 };

export function findChrome() {
	const cache = join(process.env.LOCALAPPDATA || join(process.env.HOME || '', 'AppData', 'Local'), 'ms-playwright');
	if (!existsSync(cache)) {
		throw new Error(`Kein Chromium gefunden: ${cache} fehlt (npx playwright install chromium)`);
	}
	for (const dir of readdirSync(cache).filter((d) => d.startsWith('chromium-')).sort().reverse()) {
		const exe = join(cache, dir, 'chrome-win64', 'chrome.exe');
		if (existsSync(exe)) return exe;
	}
	throw new Error('Kein chrome.exe im ms-playwright-Cache gefunden');
}

export function alive(port = DEFAULT_PORT) {
	return new Promise((resolve) => {
		const req = http.get({ host: '127.0.0.1', port, path: '/json/version', timeout: 1500 }, (res) => {
			res.resume();
			resolve(res.statusCode === 200);
		});
		req.on('error', () => resolve(false));
		req.on('timeout', () => { req.destroy(); resolve(false); });
	});
}

/**
 * @param {object} opts
 * @param {number} opts.port          Debug-Port
 * @param {number} opts.width         Fenstergroesse in CSS-Pixeln
 * @param {number} opts.height
 * @param {string} opts.lang          Browsersprache (z. B. 'de-DE'), siehe unten
 * @param {number} opts.scale         --force-device-scale-factor; 1.25 ergibt aus
 *                                    1536x864 CSS-Pixeln ein 1920x1080-Bild mit
 *                                    Schrift, die auch auf dem Handy lesbar ist.
 */
export async function ensureChrome({ port = DEFAULT_PORT, width = 1536, height = 864, scale = 1, lang = null } = {}) {
	if (await alive(port)) return { started: false, port };

	const profile = join(tmpdir(), `vbh-video-chrome-${port}${lang ? '-' + lang : ''}`);
	mkdirSync(profile, { recursive: true });
	spawn(findChrome(), [
		'--headless=new', '--disable-gpu', '--no-sandbox', '--hide-scrollbars',
		'--disable-features=CalculateNativeWinOcclusion',
		// Ohne festes Zeitzonen- und Sprachverhalten unterscheiden sich Aufnahmen
		// von Lauf zu Lauf in Datums- und Zahlenformaten.
		'--disable-background-timer-throttling', '--disable-renderer-backgrounding',
		`--force-device-scale-factor=${scale}`,
		// Sprache des Browsers, nicht der Seite: Chromes eigene Bedienelemente -
		// allen voran der Knopf am Datei-Feld ("Datei auswaehlen" / "Choose
		// file") - folgen ihr. Ohne das steht im englischen Video ein deutscher
		// Knopf mitten im Dialog.
		...(lang ? [`--lang=${lang}`, `--accept-lang=${lang}`] : []),
		`--remote-debugging-port=${port}`, '--remote-debugging-address=127.0.0.1',
		`--user-data-dir=${profile}`, `--window-size=${width},${height}`, 'about:blank',
	], { detached: true, stdio: 'ignore' }).unref();

	for (let i = 0; i < 60; i++) {
		if (await alive(port)) return { started: true, port };
		await new Promise((r) => setTimeout(r, 500));
	}
	throw new Error(`Chromium antwortet nicht auf Port ${port}`);
}

/**
 * Passt das Fenster so an, dass der sichtbare Bereich exakt der Zielgroesse
 * entspricht.
 *
 * Der Bildschirmmitschnitt nimmt den *Fensterinhalt* auf, nicht den emulierten
 * Viewport. Mit `Emulation.setDeviceMetricsOverride` gab es deshalb einen
 * stillen Beschnitt: die Seite rechnete mit 1536x864 CSS-Pixeln, aufgenommen
 * wurden aber nur 1520x713 - die unteren 17 Prozent fehlten, und das Bild
 * wurde beim Skalieren auf 16:9 um den Faktor 1,21 in die Hoehe gezogen.
 *
 * Deshalb: keine Emulation, sondern das echte Fenster passend machen. Die
 * Fensterdekoration ist nicht vorhersagbar, also wird gemessen und nachjustiert.
 */
export async function fitViewport(cdp, tab, ziel = ZIEL_VIEWPORT, versuche = 6) {
	const { windowId } = await cdp.send(tab, 'Browser.getWindowForTarget');
	for (let i = 0; i < versuche; i++) {
		const innen = await cdp.evaluate(tab, '({ w: window.innerWidth, h: window.innerHeight })');
		if (innen.w === ziel.width && innen.h === ziel.height) return innen;
		const { bounds } = await cdp.send(tab, 'Browser.getWindowBounds', { windowId });
		await cdp.send(tab, 'Browser.setWindowBounds', {
			windowId,
			bounds: {
				width: bounds.width + (ziel.width - innen.w),
				height: bounds.height + (ziel.height - innen.h),
			},
		});
		await new Promise((r) => setTimeout(r, 250));
	}
	const innen = await cdp.evaluate(tab, '({ w: window.innerWidth, h: window.innerHeight })');
	throw new Error(`Sichtbarer Bereich laesst sich nicht auf ${ziel.width}x${ziel.height} bringen (ist ${innen.w}x${innen.h})`);
}
