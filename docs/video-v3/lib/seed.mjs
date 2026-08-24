// Baut den Demodatenbestand fuer die Videoaufnahme in einer laufenden Nextcloud
// auf - Kontenrahmen, drei Geschaeftsjahre, Regeln, Finanzplan, Mitglieder mit
// SEPA-Mandaten, ein abgerechneter Sammeleinzug und faellige Beitraege.
//
//   node lib/seed.mjs --lang de
//   node lib/seed.mjs --lang en --url http://localhost:8081
//
// Warum ueber den Browser und nicht per curl: die App-Endpunkte haengen an einer
// Nextcloud-Sitzung samt CSRF-Token. Aus einer eingeloggten Seite heraus ist
// beides da (OC.requestToken), von aussen muesste man beides nachbauen.

import { createRequire } from 'node:module';
import { spawn, spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync, existsSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { buildDataset, iso } from './dataset.mjs';

const require = createRequire(import.meta.url);
const cdp = require('./cdp.cjs');

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

function arg(name, fallback = null) {
	const i = process.argv.indexOf(`--${name}`);
	return i >= 0 && process.argv[i + 1] && !process.argv[i + 1].startsWith('--') ? process.argv[i + 1] : fallback;
}
const flag = (name) => process.argv.includes(`--${name}`);

const LANG = arg('lang', 'de');
const URL_BASE = arg('url', 'http://localhost:8081').replace(/\/$/, '');
const USER = arg('user', 'andrea');
const PASS = arg('pass', 'VbhDemo2026!');
const PORT = Number(arg('port', '9444'));
const TODAY = arg('today', iso(new Date()));
const CONTAINER = arg('container', 'vbh-demo');

const log = (msg) => console.log(`  ${msg}`);
const step = (msg) => console.log(`\n▶ ${msg}`);

/**
 * Beitragsdaten direkt in der Datenbank leeren.
 *
 * Ueber die API geht das nicht vollstaendig: ein *abgerechneter* Sammeleinzug
 * laesst sich nicht loeschen (SepaBatchService lehnt das bewusst ab), und
 * solange er existiert, bleiben auch seine Mandate stehen. Nach jeder Aufnahme
 * von Szene 04 waeren das zwoelf Mitglieder mehr - nach drei Laeufen zeigte die
 * Liste 111 statt 87 Eintraege, mit Dubletten.
 *
 * Die Aufnahme-Instanz ist eine Wegwerfinstanz, deshalb ist der direkte Griff
 * in die Tabellen hier vertretbar. Schlaegt er fehl (kein Docker, anderer
 * Container), geht es mit der API-Reinigung weiter.
 */
function hartReinigen() {
	const tabellen = ['oc_vbh_sepa_batch_items', 'oc_vbh_sepa_batches', 'oc_vbh_membership_fees', 'oc_vbh_sepa_mandates'];
	const php = `$db = new PDO("sqlite:/var/www/html/data/nextcloud.db");`
		+ tabellen.map((t) => `$db->exec("DELETE FROM ${t}");`).join('')
		+ `echo "ok";`;
	try {
		const res = spawnSync('docker', ['exec', '-u', 'www-data', CONTAINER, 'php', '-r', php], { encoding: 'utf8' });
		if (res.status === 0 && res.stdout.includes('ok')) {
			log('Beitragstabellen direkt geleert (abgerechnete Einzuege lassen sich nicht per API loeschen)');
			return true;
		}
		const meldung = (res.stderr || '').trim().split(/\r?\n/)[0] || `Exit ${res.status}`;
		log(`Warnung: direktes Leeren fehlgeschlagen (${meldung})`);
	} catch (err) {
		log(`Warnung: direktes Leeren nicht moeglich (${err.message})`);
	}
	return false;
}

/** Chromium aus dem Playwright-Cache - kein npm-Paket noetig. */
function findChrome() {
	const cache = join(process.env.LOCALAPPDATA || join(process.env.HOME || '', 'AppData', 'Local'), 'ms-playwright');
	if (!existsSync(cache)) throw new Error(`Kein Chromium gefunden: ${cache} fehlt (npx playwright install chromium)`);
	for (const dir of readdirSync(cache).filter((d) => d.startsWith('chromium-')).sort().reverse()) {
		const exe = join(cache, dir, 'chrome-win64', 'chrome.exe');
		if (existsSync(exe)) return exe;
	}
	throw new Error('Kein chrome.exe im ms-playwright-Cache gefunden');
}

async function chromeAlive(port) {
	try {
		await new Promise((resolve, reject) => {
			require('node:http').get({ host: '127.0.0.1', port, path: '/json/version' }, (res) => {
				res.resume();
				res.statusCode === 200 ? resolve() : reject(new Error(String(res.statusCode)));
			}).on('error', reject);
		});
		return true;
	} catch {
		return false;
	}
}

async function ensureChrome() {
	if (await chromeAlive(PORT)) { log(`Chromium auf Port ${PORT} laeuft bereits`); return; }
	const exe = findChrome();
	// Bewusst ausserhalb des Repos: ein Chrome-Profil im Projektbaum bringt
	// hunderte Dateien mitgelieferter Erweiterungen mit, darunter deren eigene
	// *.test.js - vitest sammelt die ohne Konfiguration einfach mit ein und
	// meldet 22 fehlgeschlagene "Testdateien", die niemandem gehoeren.
	const profile = join(tmpdir(), 'vbh-video-chrome-profile');
	mkdirSync(profile, { recursive: true });
	spawn(exe, [
		'--headless=new', '--disable-gpu', '--no-sandbox', '--hide-scrollbars',
		`--remote-debugging-port=${PORT}`, '--remote-debugging-address=127.0.0.1',
		`--user-data-dir=${profile}`, '--window-size=1600,1000', 'about:blank',
	], { detached: true, stdio: 'ignore' }).unref();

	for (let i = 0; i < 40; i++) {
		if (await chromeAlive(PORT)) { log(`Chromium gestartet (Port ${PORT})`); return; }
		await new Promise((r) => setTimeout(r, 500));
	}
	throw new Error(`Chromium antwortet nicht auf Port ${PORT}`);
}

async function login(tab) {
	await cdp.navigate(tab, `${URL_BASE}/login`);
	const loggedIn = await cdp.evaluate(tab, 'document.querySelector("#user") === null');
	if (loggedIn) { log(`bereits angemeldet`); return; }
	await cdp.typeInto(tab, '#user', USER);
	await cdp.typeInto(tab, '#password', PASS);
	await cdp.click(tab, 'button[type=submit]');
	await cdp.waitFor(tab, 'document.querySelector("#user") === null', 20000);
	log(`angemeldet als ${USER}`);
}

/**
 * Fuehrt eine Liste von API-Aufrufen in der Seite aus. Gebuendelt statt einzeln:
 * ein Runtime.evaluate je Aufruf waere bei ueber 600 Aufrufen die Haelfte der
 * Laufzeit.
 */
async function apiMany(tab, requests, chunkSize = 40) {
	const results = [];
	for (let i = 0; i < requests.length; i += chunkSize) {
		const chunk = requests.slice(i, i + chunkSize);
		const expression = `(async () => {
			const base = OC.generateUrl('/apps/vereinsbuchhaltung/api');
			const out = [];
			for (const r of ${JSON.stringify(chunk)}) {
				const res = await fetch(base + r.path, {
					method: r.method,
					headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
					body: r.body === undefined || r.body === null ? undefined : JSON.stringify(r.body),
				});
				const text = await res.text();
				let data = null;
				try { data = JSON.parse(text); } catch { data = text.slice(0, 200); }
				out.push({ status: res.status, data });
			}
			return out;
		})()`;
		results.push(...await cdp.evaluate(tab, expression));
	}
	return results;
}

const one = async (tab, method, path, body) => (await apiMany(tab, [{ method, path, body }]))[0];

function assertOk(results, what) {
	const bad = results.filter((r) => r.status >= 400);
	if (bad.length) {
		throw new Error(`${what}: ${bad.length} von ${results.length} Aufrufen fehlgeschlagen, erster Fehler: `
			+ `${bad[0].status} ${JSON.stringify(bad[0].data).slice(0, 300)}`);
	}
	return results;
}

async function main() {
	const content = JSON.parse(readFileSync(join(root, 'content', `seed.${LANG}.json`), 'utf8'));
	const data = buildDataset(content, TODAY);
	const outDir = join(root, 'build', LANG);
	mkdirSync(outDir, { recursive: true });

	console.log(`Demodaten "${content.club.name}" (${LANG}) -> ${URL_BASE}`);
	console.log(`Stichtag ${data.today}, Geschaeftsjahre ${data.years.join(', ')}`);

	await ensureChrome();
	const tab = await cdp.openTab(`${URL_BASE}/login`, PORT);

	try {
		await login(tab);
		await cdp.navigate(tab, `${URL_BASE}/apps/vereinsbuchhaltung/`);
		await cdp.waitFor(tab, 'typeof OC !== "undefined" && !!OC.requestToken', 20000);
		await cdp.waitFor(tab, 'document.querySelector(".vbh") !== null', 20000);

		// --- Aufraeumen ---------------------------------------------------
		// /api/reset raeumt Konten, Journal, Bankumsaetze, Regeln, Kostenstellen,
		// Finanzplan und offene Posten ab - Mandate, Beitraege und Einzuege
		// jedoch nicht. Die muessen einzeln weg, sonst wachsen sie mit jedem Lauf.
		step('Bestand zuruecksetzen');
		hartReinigen();
		const batches = (await one(tab, 'GET', '/sepa/export/batches')).data;
		const fees = (await one(tab, 'GET', '/sepa/fees')).data;
		const mandates = (await one(tab, 'GET', '/sepa/mandates')).data;
		const asList = (x) => (Array.isArray(x) ? x : (x && Array.isArray(x.items) ? x.items : []));
		await apiMany(tab, asList(batches).map((b) => ({ method: 'DELETE', path: `/sepa/export/batches/${b.id}` })));
		await apiMany(tab, asList(fees).map((f) => ({ method: 'DELETE', path: `/sepa/fees/${f.id}` })));
		await apiMany(tab, asList(mandates).map((m) => ({ method: 'DELETE', path: `/sepa/mandates/${m.id}` })));
		assertOk([await one(tab, 'POST', '/reset')], 'Reset');
		log(`entfernt: ${asList(batches).length} Einzuege, ${asList(fees).length} Beitraege, ${asList(mandates).length} Mandate, alles Uebrige per /reset`);

		// --- Kontenrahmen ---------------------------------------------------
		step('Kontenrahmen anlegen');
		const accountId = {};
		for (const acc of data.accounts) {
			const res = await one(tab, 'POST', '/accounts', {
				number: acc.number, name: acc.name, type: acc.type, category: acc.category,
				isBank: !!acc.isBank, sphere: acc.sphere ?? null, iban: acc.iban ?? null,
				parentId: acc.parent ? accountId[acc.parent] : null,
			});
			if (res.status >= 400) throw new Error(`Konto ${acc.number}: ${JSON.stringify(res.data)}`);
			accountId[acc.number] = res.data.id;
		}
		log(`${Object.keys(accountId).length} Konten`);

		const openings = data.accounts.filter((a) => a.opening);
		assertOk(await apiMany(tab, openings.map((a) => ({
			method: 'POST', path: `/accounts/${accountId[a.number]}/opening`,
			body: { amount: a.opening, date: `${data.years[0]}-01-01` },
		}))), 'Eroeffnungsbestaende');
		log(`${openings.length} Eroeffnungsbestaende`);

		// --- Kostenstellen ---------------------------------------------------
		step('Kostenstellen, Regeln, Finanzplan');
		assertOk([await one(tab, 'POST', '/settings', { cost_center_mode: 'manual' })], 'Kostenstellen-Modus');
		for (const cc of data.costCenters) {
			const res = await one(tab, 'POST', '/costcenters', { code: cc.code, name: cc.name });
			if (res.status >= 400) throw new Error(`Kostenstelle ${cc.code}: ${JSON.stringify(res.data)}`);
			assertOk([await one(tab, 'POST', '/costcenters/assign', {
				accountIds: cc.accounts.map((n) => accountId[n]), costCenterId: res.data.id,
			})], `Kostenstelle ${cc.code} zuordnen`);
		}
		log(`${data.costCenters.length} Kostenstellen`);

		assertOk(await apiMany(tab, data.rules.map((r) => ({
			method: 'POST', path: '/rules',
			body: { matchField: r.field, matchValue: r.value, contraAccountId: accountId[r.account], priority: r.priority },
		}))), 'Regeln');
		log(`${data.rules.length} Zuordnungsregeln`);

		const currentYear = data.years[data.years.length - 1];
		assertOk(await apiMany(tab, data.budget.map((b) => ({
			method: 'POST', path: '/budget',
			body: { accountId: accountId[b.account], year: currentYear, amount: b.amount, note: b.note },
		}))), 'Finanzplan');
		log(`${data.budget.length} Planwerte fuer ${currentYear}`);

		// --- Buchungen ---------------------------------------------------
		step('Buchungen dreier Geschaeftsjahre');
		assertOk(await apiMany(tab, data.bookings.map((b) => ({
			method: 'POST', path: '/journal',
			body: {
				date: b.date, description: b.description, amount: b.amount,
				debitAccountId: accountId[b.debit], creditAccountId: accountId[b.credit],
			},
		})), 60), 'Buchungen');
		log(`${data.bookings.length} Buchungen`);

		// --- Grundeinstellungen ---------------------------------------------------
		step('Verein und SEPA-Grundeinstellungen');
		assertOk([await one(tab, 'POST', '/settings', {
			club_name: content.club.name,
			brand_color: content.club.brandColor,
			membership_enabled: '1',
			sepa_creditor_id: content.sepa.creditorId,
			sepa_debtor_account_id: accountId[content.sepa.bankAccount],
			default_fee_amount: String(content.sepa.defaultFeeEuro),
			default_fee_frequency: content.sepa.defaultFrequency,
		})], 'Einstellungen');
		log(`${content.club.name}, Glaeubiger-ID ${content.sepa.creditorId}, Standardbeitrag ${content.sepa.defaultFeeEuro} EUR`);

		// --- Mitglieder ---------------------------------------------------
		step('Mitglieder, Mandate und Beitraege');
		const withMandate = data.members.filter((m) => m.iban);
		const mandateResults = assertOk(await apiMany(tab, withMandate.map((m) => ({
			method: 'POST', path: '/sepa/mandates',
			body: {
				memberUid: null, memberLabel: m.label, iban: m.iban, bic: m.bic,
				mandateType: 'RCUR', signedDate: m.signedDate, email: m.email,
			},
		})), 30), 'Mandate');
		const mandateFor = new Map(withMandate.map((m, i) => [m.label, mandateResults[i].data.id]));
		log(`${withMandate.length} SEPA-Mandate`);

		const feeResults = assertOk(await apiMany(tab, data.members.map((m) => ({
			method: 'POST', path: '/sepa/fees',
			body: {
				memberUid: null, memberLabel: m.label, amount: m.amount, frequency: m.frequency,
				startDate: m.startDate, accountId: accountId['4000'], mandateId: mandateFor.get(m.label) ?? null,
			},
		})), 30), 'Beitraege');
		log(`${data.members.length} Beitraege (${data.members.filter((m) => m.group === 'open').length} davon gerade faellig)`);

		assertOk(await apiMany(tab, feeResults.map((r) => ({
			method: 'POST', path: `/sepa/fees/${r.data.id}/catch-up`,
		})), 30), 'Faelligkeiten nachholen');
		log('Faelligkeiten erzeugt');

		// --- Beitraege des Jahresanfangs abhaken ---------------------------------------------------
		// Kein rueckdatierter Sammeleinzug: die App laesst ein Faelligkeitsdatum in
		// der Vergangenheit bewusst nicht zu (SepaBatchService). Die Januar-Beitraege
		// werden deshalb direkt als bezahlt markiert - was sie im echten Verein Ende
		// Januar auch waeren. Offen bleiben nur die frisch faelligen Beitraege, und
		// genau die traegt der Sammeleinzug, den Szene 04 live erzeugt.
		step('Beitraege des Jahresanfangs als bezahlt markieren');
		const openItems = (await one(tab, 'GET', '/open-items')).data;
		const openLabels = new Set(data.members.filter((m) => m.group === 'open').map((m) => m.label));
		const toPay = asList(openItems).filter((i) => i.status === 'open' && !openLabels.has(i.debtor));
		assertOk(await apiMany(tab, toPay.map((i) => ({ method: 'POST', path: `/open-items/${i.id}/pay` })), 30), 'Beitraege abhaken');
		log(`${toPay.length} Beitraege abgehakt, ${asList(openItems).length - toPay.length} bleiben faellig`);

		assertOk(await apiMany(tab, data.openItems.map((i) => ({
			method: 'POST', path: '/open-items',
			body: { debtor: i.debtor, description: i.description, amount: i.amount, dueDate: i.dueDate, accountId: accountId[i.account] },
		}))), 'Offene Posten');
		log(`${data.openItems.length} weitere offene Posten`);

		// --- Rollen ---------------------------------------------------
		// Auch fuer die Optik: ohne vergebene Berechtigung bleibt die
		// "Erste Schritte"-Liste auf dem Dashboard stehen und stoert im Bild.
		step('Rollen vergeben');
		const roleUsers = (content.nextcloudUsers ?? []).filter((u) => u.role);
		const roleResults = await apiMany(tab, roleUsers.map((u) => ({
			method: 'POST', path: '/permissions', body: { principalType: 'user', principalId: u.uid, role: u.role },
		})));
		roleResults.forEach((r, i) => {
			if (r.status >= 400) log(`  Warnung: Rolle fuer ${roleUsers[i].uid} nicht gesetzt (${JSON.stringify(r.data).slice(0, 120)})`);
		});
		log(`${roleResults.filter((r) => r.status < 400).length} von ${roleUsers.length} Rollen vergeben`);

		// Der "Was ist neu"-Splash darf in der Aufnahme nicht aufpoppen. markSeen
		// speichert nur, wenn eine Versionsnummer mitkommt - die aktuelle liefert
		// der Endpunkt selbst, damit hier keine Version doppelt gepflegt wird.
		const whatsNew = (await one(tab, 'GET', '/whatsnew')).data;
		if (whatsNew && whatsNew.currentVersion) {
			await one(tab, 'POST', '/whatsnew/seen', { version: whatsNew.currentVersion });
			log(`"Was ist neu" auf ${whatsNew.currentVersion} quittiert`);
		}

		// --- Vor-Kontoauszug importieren ---------------------------------------------------
		// Damit der Import in Szene 03 Dubletten melden kann, muessen diese drei
		// Umsaetze vorher schon einmal eingelesen worden sein.
		step('Kontoauszug des Vormonats importieren');
		const imported = await one(tab, 'POST', '/import/commit', {
			content: data.csv.statementKnown, filename: 'kontoauszug-vormonat.csv', applyRules: true,
		});
		if (imported.status >= 400) throw new Error(`Import: ${JSON.stringify(imported.data)}`);
		log(`${imported.data.new} neu, ${imported.data.duplicate} Dubletten, ${imported.data.autoAssigned} per Regel zugeordnet`);

		// --- Dateien fuer die Aufnahme ---------------------------------------------------
		step('Dateien fuer die Aufnahme schreiben');
		const files = {
			'kontoauszug.csv': data.csv.statementFull,
			'kontoauszug-vormonat.csv': data.csv.statementKnown,
			'mitglieder-neu.csv': data.csv.membersNew,
		};
		for (const [name, body] of Object.entries(files)) {
			writeFileSync(join(outDir, name), body, 'utf8');
			log(`${join('build', LANG, name)} (${body.split(/\r?\n/).length - 2} Zeilen)`);
		}

		// --- Kontrolle ---------------------------------------------------
		step('Kontrolle');
		const [accounts, journal, openNow, feeList, batchList, txOpen] = await apiMany(tab, [
			{ method: 'GET', path: '/accounts' },
			{ method: 'GET', path: `/journal?year=${currentYear}` },
			{ method: 'GET', path: '/open-items' },
			{ method: 'GET', path: '/sepa/fees' },
			{ method: 'GET', path: '/sepa/export/batches' },
			{ method: 'GET', path: '/transactions?status=open' },
		]);
		const summary = {
			konten: asList(accounts.data).length,
			buchungenLaufendesJahr: asList(journal.data).length,
			offenePosten: asList(openNow.data).filter((i) => i.status === 'open').length,
			beitraege: asList(feeList.data).length,
			einzuege: asList(batchList.data).length,
			offeneBankumsaetze: asList(txOpen.data).length,
		};
		console.log(JSON.stringify(summary, null, 2));

		// Kontrollbild. Bewusst ohne cdp.navigate(): dessen 10-Sekunden-Wartezeit
		// auf readyState reicht der frisch gefuellten App nicht immer, und ein
		// fehlendes Screenshot darf einen fertigen Seed nicht als Fehler enden lassen.
		try {
			await cdp.send(tab, 'Page.navigate', { url: `${URL_BASE}/apps/vereinsbuchhaltung/` });
			await cdp.waitFor(tab, 'document.querySelector(".vbh") !== null', 30000);
			await new Promise((r) => setTimeout(r, 3000));
			const shot = join(outDir, 'seed-dashboard.png');
			await cdp.screenshot(tab, shot);
			log(`Screenshot ${shot}`);
		} catch (err) {
			log(`Screenshot uebersprungen (${err.message})`);
		}
		console.log('\nFertig.');
	} finally {
		if (!flag('keep-tab')) await cdp.closeTab(tab);
	}
}

main().catch((err) => {
	console.error(`\nFEHLER: ${err.message}`);
	process.exit(1);
});
