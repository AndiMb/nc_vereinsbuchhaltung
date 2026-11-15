// Stellt die Aufnahme-Instanz auf eine Sprache um: Nextcloud-Sprache aller
// Demokonten und die Anzeigenamen, die im Bild auftauchen (Avatar oben rechts,
// Rechteliste in Szene 06).
//
//   node lib/instance.mjs --lang en
//   node lib/instance.mjs --lang de --container vbh-demo --check
//
// Getrennt von seed.mjs, weil das hier occ braucht (Nextcloud-Ebene) und der
// Seed nur die App-API (Datenebene). Beides zusammen ergibt einen Sprachlauf:
//   node lib/instance.mjs --lang en && node lib/seed.mjs --lang en

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { demoPass } from './session.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

function arg(name, fallback = null) {
	const i = process.argv.indexOf(`--${name}`);
	return i >= 0 && process.argv[i + 1] && !process.argv[i + 1].startsWith('--') ? process.argv[i + 1] : fallback;
}

const LANG = arg('lang', 'de');
const CONTAINER = arg('container', 'vbh-demo');
const CHECK = process.argv.includes('--check');
const URL_BASE = arg('url', 'http://localhost:8082');

function occ(...args) {
	return execFileSync('docker', ['exec', '-u', 'www-data', CONTAINER, 'php', 'occ', ...args], { encoding: 'utf8' }).trim();
}

const content = JSON.parse(readFileSync(join(root, 'content', `seed.${LANG}.json`), 'utf8'));
const users = content.nextcloudUsers ?? [];

if (CHECK) {
	console.log(`Container ${CONTAINER}, Sprache laut Konfiguration:`);
	for (const u of users) {
		console.log(`  ${u.uid}: lang=${occ('user:setting', u.uid, 'core', 'lang') || '(nicht gesetzt)'}`);
	}
	console.log(occ('user:list'));
	process.exit(0);
}

async function setDisplayName(uid, name) {
	const res = await fetch(`${URL_BASE}/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}?format=json`, {
		method: 'PUT',
		headers: {
			Authorization: 'Basic ' + Buffer.from(`andrea:${demoPass()}`).toString('base64'),
			'OCS-APIREQUEST': 'true',
			'Content-Type': 'application/x-www-form-urlencoded',
		},
		body: new URLSearchParams({ key: 'displayname', value: name }),
	});
	const body = await res.json().catch(() => null);
	if (!res.ok || body?.ocs?.meta?.statuscode !== 200) {
		throw new Error(`Anzeigename ${uid}: HTTP ${res.status} ${JSON.stringify(body?.ocs?.meta ?? null)}`);
	}
}

console.log(`Instanz ${CONTAINER} auf "${LANG}" umstellen (${content.club.name})`);
for (const u of users) {
	occ('user:setting', u.uid, 'core', 'lang', LANG);
	// Der Anzeigename steht im Avatar und in der Rechteliste - er muss zur
	// Sprachfassung passen, sonst steht im englischen Video ein deutscher Name.
	// Weder "occ user:setting ... displayname" noch "occ user:profile" aendern in
	// Nextcloud 34 den Namen im Benutzer-Backend (occ user:info zeigt weiter den
	// alten). Die Provisioning-API ruft setDisplayName() auf - der wirkt.
	await setDisplayName(u.uid, u.displayName);
	console.log(`  ${u.uid}: lang=${LANG}, Anzeigename "${u.displayName}"`);
}

// Sprache der Serverkonfiguration mitziehen: Login-Seite und Nextcloud-Rahmen
// zeigen sonst die Standardsprache der Instanz, nicht die des Kontos.
occ('config:system:set', 'default_language', '--value', LANG);
occ('config:system:set', 'force_language', '--value', LANG);
console.log(`  Server: default_language=${LANG}, force_language=${LANG}`);
console.log('Fertig. Danach: node lib/seed.mjs --lang ' + LANG);
