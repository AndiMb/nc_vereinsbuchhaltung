// Demobestand sichern, leeren und wiederherstellen - ueber die Sicherungsfunktion
// der App selbst (POST /api/backup/...), ohne Griff in die Datenbank.
//
//   node lib/snapshot.mjs --lang de --restore   # build/de/seed.vbhbackup einspielen
//   node lib/snapshot.mjs --lang de --wipe      # Bestand leeren
//
// Die Sicherung ist datumsbezogen (Faelligkeiten, Kontoauszug): sie gilt nur
// am Tag, an dem seed.mjs sie geschrieben hat. snapshot.mjs prueft das.

import { readFileSync, writeFileSync, existsSync, mkdirSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { createClient, runJob } from './ncapi.mjs';
import { demoPass } from './session.mjs';
import { iso } from './dataset.mjs';

const here = dirname(fileURLToPath(import.meta.url));
export const ROOT = join(here, '..');

/** Verschluesselt die Sicherungsdatei; sie liegt nur lokal in build/ (gitignoriert). */
export const BACKUP_PASSWORD = 'werbevideo-demobestand';

export const backupFile = (lang) => join(ROOT, 'build', lang, 'seed.vbhbackup');
export const summaryFile = (lang) => join(ROOT, 'build', lang, 'seed-summary.json');

/** Leert den Bestand. Bestaetigungswort ist der Vereinsname, ohne Namen "LÖSCHEN". */
export async function wipe(client) {
	const status = await client.api('GET', '/backup/status');
	if (status.empty) return false;
	const settings = await client.api('GET', '/settings');
	const confirmation = (settings.club_name ?? '').trim() || 'LÖSCHEN';
	await client.api('POST', '/backup/wipe', { confirmation });
	const after = await client.api('GET', '/backup/status');
	if (!after.empty) throw new Error(`Bestand nach dem Leeren nicht leer: ${JSON.stringify(after.counts)}`);
	return true;
}

export async function exportBackup(client, file) {
	const res = await client.api('POST', '/backup/export', { password: BACKUP_PASSWORD, withFiles: true });
	const bytes = await client.download(`/backup/download/${res.token}`);
	mkdirSync(dirname(file), { recursive: true });
	writeFileSync(file, bytes);
	return { rows: res.rows, files: res.files, missingFiles: res.missingFiles, size: bytes.length };
}

/**
 * Belegordner (Waechter-Modus) im Home von andrea. Beim Wiederherstellen legt
 * die App die Belege der Buchungen in ihre interne Ablage (BackupSchema:
 * file_id -> NULL); die alten Dateien im Ordner wuerden sonst als "nicht
 * zugeordnet" im Posteingang stehen. Darum wird der Ordner geleert und nur mit
 * den Dateien gefuellt, die seed.mjs nach build/<lang>/belegordner/ schreibt.
 */
export const WATCH_PATH = 'Belege/Verein';
export const watchDir = (lang) => join(ROOT, 'build', lang, 'belegordner');

async function resetWatchFolder(client, lang) {
	const del = await client.dav('DELETE', WATCH_PATH).catch((err) => (err.status === 404 ? null : Promise.reject(err)));
	await client.dav('MKCOL', 'Belege');
	await client.dav('MKCOL', WATCH_PATH);
	const dir = watchDir(lang);
	const names = existsSync(dir) ? readdirSync(dir) : [];
	for (const name of names) await client.dav('PUT', `${WATCH_PATH}/${name}`, readFileSync(join(dir, name)), 'application/pdf');
	return { cleared: del !== null, files: names.length };
}

/**
 * Ablageordner der App im Home des Verwalters. "Leeren" laesst sie bewusst
 * stehen, das Einspielen legt Mandatsnachweise und Rechnungs-PDFs neu ab - ohne
 * Aufraeumen stuende nach jeder Wiederherstellung "Mandat-M-001 (2).pdf" daneben.
 */
export const APP_FOLDERS = ['SEPA-Mandate', 'Vereinsbuchhaltung'];

export async function clearAppFolders(client) {
	for (const path of APP_FOLDERS) {
		await client.dav('DELETE', path).catch((err) => (err.status === 404 ? null : Promise.reject(err)));
	}
}

export async function restore(client, lang, { container } = {}) {
	const file = backupFile(lang);
	if (!existsSync(file)) throw new Error(`Keine Sicherung: ${file}. Erst node lib/seed.mjs laufen lassen.`);
	await wipe(client);
	await clearAppFolders(client);
	await resetWatchFolder(client, lang);
	const res = await client.form('/backup/import', { password: BACKUP_PASSWORD }, [
		{ field: 'file', name: 'seed.vbhbackup', buffer: readFileSync(file) },
	]);
	// Der Belegindex gehoert nicht zur Sicherung - frisch aufbauen, sonst fehlt
	// in Block 02 der Belegvorschlag.
	if (container) runJob(container, 'ReceiptIndexJob');
	return res;
}

function arg(name, fallback = null) {
	const i = process.argv.indexOf(`--${name}`);
	return i >= 0 && process.argv[i + 1] && !process.argv[i + 1].startsWith('--') ? process.argv[i + 1] : fallback;
}

if (import.meta.url === new URL(`file://${process.argv[1]}`).href.replace(/%3A/g, ':')) {
	const lang = arg('lang', 'de');
	const client = createClient({ url: arg('url', 'http://localhost:8082'), user: arg('user', 'andrea'), pass: demoPass(arg('pass')) });
	const container = arg('container', 'vbh-demo');
	try {
		if (process.argv.includes('--wipe')) {
			console.log((await wipe(client)) ? 'Bestand geleert.' : 'Bestand war schon leer.');
		} else if (process.argv.includes('--restore')) {
			const summary = existsSync(summaryFile(lang)) ? JSON.parse(readFileSync(summaryFile(lang), 'utf8')) : null;
			if (summary && summary.today !== iso(new Date()) && !process.argv.includes('--force')) {
				throw new Error(`Sicherung vom ${summary.today} - die Daten sind datumsbezogen. Neu seeden oder --force.`);
			}
			const res = await restore(client, lang, { container });
			console.log(`Wiederhergestellt: ${JSON.stringify(res.rows ?? res).slice(0, 200)}`);
		} else {
			console.log('Aufruf: node lib/snapshot.mjs --lang <de|en> (--restore | --wipe)');
			process.exit(2);
		}
	} catch (err) {
		console.error(`FEHLER: ${err.message}`);
		process.exit(1);
	}
}
