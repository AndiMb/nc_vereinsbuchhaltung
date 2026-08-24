// Anmeldung und API-Zugriff aus einer eingeloggten Seite heraus.
//
// Die App-Endpunkte haengen an einer Nextcloud-Sitzung samt CSRF-Token. Aus dem
// Seitenkontext ist beides vorhanden (OC.requestToken); von aussen muesste man
// beides nachbauen. Seed und Aufnahme nutzen denselben Weg.

import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const cdp = require('./cdp.cjs');

export async function login(tab, { url, user, pass }) {
	await cdp.navigate(tab, `${url}/login`);
	if (await cdp.evaluate(tab, 'document.querySelector("#user") === null')) {
		return 'bereits angemeldet';
	}
	await cdp.typeInto(tab, '#user', user);
	await cdp.typeInto(tab, '#password', pass);
	await cdp.click(tab, 'button[type=submit]');
	await cdp.waitFor(tab, 'document.querySelector("#user") === null', 20000);
	return `angemeldet als ${user}`;
}

/** Ruft die App-Oberflaeche auf und wartet, bis Vue steht. */
export async function openApp(tab, url) {
	await cdp.send(tab, 'Page.navigate', { url: `${url}/apps/vereinsbuchhaltung/` });
	await cdp.waitFor(tab, 'typeof OC !== "undefined" && !!OC.requestToken', 25000);
	await cdp.waitFor(tab, 'document.querySelector(".vbh") !== null', 25000);
}

/** Ein API-Aufruf im Seitenkontext. */
export function api(tab, method, path, body) {
	return cdp.evaluate(tab, `(async () => {
		const res = await fetch(OC.generateUrl('/apps/vereinsbuchhaltung/api') + ${JSON.stringify(path)}, {
			method: ${JSON.stringify(method)},
			headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
			body: ${body === undefined ? 'undefined' : JSON.stringify(JSON.stringify(body))},
		});
		const text = await res.text();
		let data = null;
		try { data = JSON.parse(text); } catch { data = text.slice(0, 200); }
		return { status: res.status, data };
	})()`);
}
