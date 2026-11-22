// App-API und WebDAV von Node aus, mit Basic Auth wie die E2E-Fixtures.
//
// Warum nicht aus der Seite heraus (session.mjs api()): Basic Auth plus
// OCS-APIREQUEST braucht kein CSRF-Token, gilt bei jeder Anfrage als frische
// Anmeldung (Sicherung, Rollen und Reset verlangen eine Passwortbestaetigung)
// und erlaubt Aufrufe als zweites Konto (mia), ohne einen zweiten Browser.

import { execFileSync } from 'node:child_process';

export class ApiError extends Error {
	constructor(what, status, data) {
		super(`${what}: HTTP ${status} ${typeof data === 'string' ? data.slice(0, 300) : JSON.stringify(data).slice(0, 300)}`);
		this.status = status;
		this.data = data;
	}
}

/**
 * @param {{url:string, user:string, pass:string}} opts
 */
export function createClient({ url, user, pass }) {
	const base = url.replace(/\/$/, '');
	const auth = 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64');
	const headers = { Authorization: auth, 'OCS-APIREQUEST': 'true' };

	async function send(method, fullUrl, { json, form, body, contentType } = {}) {
		const init = { method, headers: { ...headers } };
		if (json !== undefined) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(json);
		} else if (form) {
			init.body = form;
		} else if (body !== undefined) {
			init.body = body;
			if (contentType) init.headers['Content-Type'] = contentType;
		}
		const res = await fetch(fullUrl, init);
		const buf = Buffer.from(await res.arrayBuffer());
		const text = buf.toString('utf8');
		let data = text;
		try { data = JSON.parse(text); } catch { /* kein JSON */ }
		return { status: res.status, data, buffer: buf };
	}

	const appUrl = (path) => `${base}/index.php/apps/vereinsbuchhaltung${path}`;

	const client = {
		user,
		/** Antwort ohne Pruefung: {status, data}. */
		raw: (method, path, json) => send(method, appUrl(`/api${path}`), { json }),
		/** Wirft bei Status >= 400. */
		async api(method, path, json) {
			const res = await send(method, appUrl(`/api${path}`), { json });
			if (res.status >= 400) throw new ApiError(`${user} ${method} ${path}`, res.status, res.data);
			return res.data;
		},
		/** Multipart: fields ist ein Objekt, files eine Liste {field, name, buffer, type}. */
		async form(path, fields = {}, files = []) {
			const fd = new FormData();
			for (const [k, v] of Object.entries(fields)) fd.append(k, String(v));
			for (const f of files) fd.append(f.field, new Blob([f.buffer], { type: f.type ?? 'application/octet-stream' }), f.name);
			const res = await send('POST', appUrl(`/api${path}`), { form: fd });
			if (res.status >= 400) throw new ApiError(`${user} POST ${path}`, res.status, res.data);
			return res.data;
		},
		/** Seiten-Route der App ausserhalb von /api (z. B. link-account). */
		page: (method, path) => send(method, appUrl(path)),
		/** Rohdaten herunterladen (Sicherung). */
		async download(path) {
			const res = await send('GET', appUrl(`/api${path}`));
			if (res.status >= 400) throw new ApiError(`${user} GET ${path}`, res.status, res.data);
			return res.buffer;
		},
		/** WebDAV im eigenen Home; MKCOL auf bestehende Ordner (405) ist kein Fehler. */
		async dav(method, path, buffer, contentType) {
			const res = await send(method, `${base}/remote.php/dav/files/${user}/${path}`, { body: buffer, contentType });
			if (res.status >= 400 && !(method === 'MKCOL' && res.status === 405)) {
				throw new ApiError(`${user} ${method} dav/${path}`, res.status, res.data);
			}
			return res;
		},
	};
	return client;
}

/** occ in der Aufnahme-Instanz. */
export function occ(container, ...args) {
	return execFileSync('docker', ['exec', '-u', 'www-data', container, 'php', 'occ', ...args], { encoding: 'utf8' }).trim();
}

/** Fuehrt einen Hintergrundjob der App sofort aus (Belegindex, Beitragszyklus). */
export function runJob(container, shortClass) {
	const cls = `OCA\\Vereinsbuchhaltung\\BackgroundJob\\${shortClass}`;
	const list = JSON.parse(occ(container, 'background-job:list', `--class=${cls}`, '--output=json'));
	if (!list.length) throw new Error(`Hintergrundjob ${shortClass} nicht registriert`);
	for (const job of list) occ(container, 'background-job:execute', String(job.id), '--force-execute');
	return list.length;
}

/**
 * Letzte Mail an eine Adresse aus Mailpit (laeuft im Docker-Netz, Port 8025
 * ist nicht nach aussen freigegeben - darum curl aus dem Nextcloud-Container).
 */
export function mailpitLatest(container, to, { host = 'vbh-demo-mail' } = {}) {
	const curl = (path) => execFileSync('docker', ['exec', container, 'curl', '-s', `http://${host}:8025${path}`], { encoding: 'utf8' });
	const search = JSON.parse(curl(`/api/v1/search?query=${encodeURIComponent(`to:${to}`)}&limit=1`));
	const msg = search.messages?.[0];
	if (!msg) return null;
	return JSON.parse(curl(`/api/v1/message/${msg.ID}`));
}
