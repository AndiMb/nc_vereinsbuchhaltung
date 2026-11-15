// Baut den Demobestand fuer die Videoaufnahme in der laufenden Aufnahme-Instanz
// auf und legt danach eine Sicherung ab, die snapshot.mjs vor jeder Aufnahme
// wieder einspielt.
//
//   node lib/seed.mjs --lang de
//   node lib/seed.mjs --lang de --check   # Bestand gegen seed-summary.json pruefen
//
// Alles laeuft ueber die App-API (ncapi.mjs, Basic Auth wie die E2E-Fixtures),
// nichts ueber die Datenbank. Ausnahme ist occ fuer Hintergrundjobs
// (Belegindex) - die liefen sonst erst nach zehn Minuten von selbst.

import { createRequire } from 'node:module';
import { readFileSync, writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { buildDataset, iso } from './dataset.mjs';
import { demoPass, login } from './session.mjs';
import { createClient, runJob, mailpitLatest } from './ncapi.mjs';
import { wipe, clearAppFolders, exportBackup, backupFile, summaryFile, WATCH_PATH, watchDir } from './snapshot.mjs';
import { logoPng } from './png.mjs';
import { collectionStatement } from './camt.mjs';
import { ensureChrome, DEFAULT_PORT } from './chrome.mjs';
import { textPdf } from '../../../tests/e2e/fixtures/textPdf.mjs';

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
const URL_BASE = arg('url', 'http://localhost:8082').replace(/\/$/, '');
const PASS = demoPass(arg('pass'));
const TODAY = arg('today', iso(new Date()));
const CONTAINER = arg('container', 'vbh-demo');
const outDir = join(root, 'build', LANG);

const admin = createClient({ url: URL_BASE, user: 'andrea', pass: PASS });
const log = (msg) => console.log(`  ${msg}`);
const step = (msg) => console.log(`\n▶ ${msg}`);
const asList = (x) => (Array.isArray(x) ? x : (x && Array.isArray(x.items) ? x.items : (x && Array.isArray(x.periods) ? x.periods : [])));
const idOf = (x) => x?.id ?? x?.journal?.id ?? x?.batch?.id ?? x?.member?.id;
const euroDe = (v) => v.toFixed(2).replace('.', ',');

/** Kennzahlen, die nach jedem Wiederherstellen gleich sein muessen. */
async function counts() {
	const [members, invoices, reimbursements, batches, status] = await Promise.all([
		admin.api('GET', '/members'),
		admin.api('GET', '/invoices'),
		admin.api('GET', '/reimbursements'),
		admin.api('GET', '/debit-batches'),
		admin.api('GET', '/backup/status'),
	]);
	return {
		members: asList(members).length,
		invoices: asList(invoices).length,
		reimbursements: asList(reimbursements).length,
		debitBatches: asList(batches).length,
		rows: status.counts,
	};
}

async function check() {
	const summary = JSON.parse(readFileSync(summaryFile(LANG), 'utf8'));
	const now = await counts();
	const soll = JSON.stringify(summary.counts);
	const ist = JSON.stringify(now);
	if (soll !== ist) {
		console.error(`Bestand weicht ab.\n  Soll: ${soll}\n  Ist:  ${ist}`);
		process.exit(1);
	}
	console.log(`Bestand stimmt mit seed-summary.json ueberein: ${ist}`);
}

async function ensurePeriods(years) {
	let periods = asList(await admin.api('GET', '/periods'));
	const has = (y) => periods.some((p) => p.startDate.startsWith(String(y)));
	for (let guard = 0; !has(years[0]) && guard < 5; guard++) {
		await admin.api('POST', '/periods', { position: 'before' });
		periods = asList(await admin.api('GET', '/periods'));
	}
	const byYear = Object.fromEntries(years.map((y) => [y, periods.find((p) => p.startDate.startsWith(String(y)))?.id]));
	for (const y of years) if (!byYear[y]) throw new Error(`Kein Geschaeftsjahr ${y}: ${JSON.stringify(periods)}`);
	return byYear;
}

async function linkAndConfirm(memberId, uid, email) {
	await admin.api('POST', `/members/${memberId}/link`, { ncUserId: uid });
	let mail = null;
	for (let i = 0; i < 20 && !mail; i++) {
		mail = mailpitLatest(CONTAINER, email);
		if (!mail) await new Promise((r) => setTimeout(r, 500));
	}
	if (!mail) throw new Error(`Keine Verknuepfungsmail an ${email} in Mailpit`);
	const token = (`${mail.Text}\n${mail.HTML}`.match(/link-account\/([A-Za-z0-9]+)/) ?? [])[1];
	if (!token) throw new Error('Kein Bestaetigungslink in der Verknuepfungsmail');
	const user = createClient({ url: URL_BASE, user: uid, pass: PASS });
	await user.page('GET', `/link-account/${token}`);
	const res = await user.page('POST', `/link-account/${token}`);
	if (res.status >= 400) throw new Error(`Bestaetigung als ${uid}: HTTP ${res.status}`);
	return user;
}

async function screenshot() {
	const port = Number(arg('port', String(DEFAULT_PORT)));
	await ensureChrome({ port });
	const tab = await cdp.openTab(`${URL_BASE}/login`, port);
	try {
		await login(tab, { url: URL_BASE, user: 'andrea', pass: PASS });
		await cdp.send(tab, 'Page.navigate', { url: `${URL_BASE}/apps/vereinsbuchhaltung/` });
		await cdp.waitFor(tab, 'document.querySelector(".vbh") !== null', 30000);
		await new Promise((r) => setTimeout(r, 3000));
		const shot = join(outDir, 'seed-dashboard.png');
		await cdp.screenshot(tab, shot);
		log(`Kontrollbild ${shot}`);
	} catch (err) {
		log(`Kontrollbild uebersprungen (${err.message})`);
	} finally {
		await cdp.closeTab(tab);
	}
}

async function main() {
	if (flag('check')) return check();

	const content = JSON.parse(readFileSync(join(root, 'content', `seed.${LANG}.json`), 'utf8'));
	const data = buildDataset(content, TODAY);
	mkdirSync(outDir, { recursive: true });
	console.log(`Demobestand "${content.club.name}" (${LANG}) -> ${URL_BASE}, Stichtag ${TODAY}`);

	step('Bestand leeren');
	log((await wipe(admin)) ? 'geleert' : 'war leer');
	await clearAppFolders(admin);

	step('Verein, Module, Logo, Belegordner');
	await admin.api('POST', '/settings', {
		club_name: content.club.name,
		brand_color: content.club.brandColor,
		club_street: content.club.street,
		club_postal_code: content.club.postalCode,
		club_city: content.club.city,
		club_tax_number: content.club.taxNumber,
		membership_enabled: '1',
		self_service_enabled: '1',
		cost_centers_enabled: '1',
	});
	await admin.form('/settings/logo', {}, [{ field: 'file', name: 'logo.png', buffer: logoPng(256, content.club.brandColor), type: 'image/png' }]);
	await admin.dav('MKCOL', 'Belege');
	await admin.dav('DELETE', WATCH_PATH).catch((err) => { if (err.status !== 404) throw err; });
	await admin.dav('MKCOL', WATCH_PATH);
	await admin.api('POST', '/settings', { storage_mode: 'watch', storage_user: 'andrea', storage_path: WATCH_PATH });
	log('Einstellungen, Logo und Belegordner Belege/Verein');

	step('Geschaeftsjahre und Konten');
	const periodOf = await ensurePeriods(data.years);
	const accountId = {};
	const accountType = {};
	for (const acc of data.accounts) {
		const iban = acc.number === content.sepa.bankAccount ? content.statement.accountIban : (acc.iban ?? null);
		const res = await admin.api('POST', '/accounts', {
			number: acc.number, name: acc.name, type: acc.type, category: acc.category,
			isBank: !!acc.isBank, sphere: acc.sphere ?? null, iban,
			parentId: acc.parent ? accountId[acc.parent] : null,
		});
		accountId[acc.number] = res.id;
		accountType[acc.number] = acc.type;
	}
	for (const acc of data.accounts.filter((a) => a.opening)) {
		await admin.api('POST', `/accounts/${accountId[acc.number]}/opening`, { amount: acc.opening, date: `${data.years[0]}-01-01` });
	}
	log(`${data.accounts.length} Konten, Geschaeftsjahre ${data.years.join(', ')}`);

	step('Kostenstellen, Buchungen, Belege');
	const ccId = {};
	for (const cc of data.costCenters) {
		ccId[cc.code] = (await admin.api('POST', '/booking-cost-centers', cc)).id;
	}
	let belege = 0;
	for (const b of data.bookings) {
		let body;
		if (b.costCenter) {
			const line = (number, debit, credit) => ({
				accountId: accountId[number], debit, credit,
				...(['income', 'expense'].includes(accountType[number]) ? { allocations: [{ costCenterId: ccId[b.costCenter], amount: b.amount }] } : {}),
			});
			body = { date: b.date, description: b.description, lines: [line(b.debit, b.amount, 0), line(b.credit, 0, b.amount)] };
		} else {
			body = { date: b.date, description: b.description, amount: b.amount, debitAccountId: accountId[b.debit], creditAccountId: accountId[b.credit] };
		}
		const res = await admin.api('POST', '/journal', body);
		if (b.receipt) {
			const pdf = textPdf([`${content.receiptWord} ${b.date}`, b.description, `${euroDe(b.amount)} EUR`]);
			await admin.form(`/journal/${idOf(res)}/attachments`, {}, [{ field: 'file', name: `${content.receiptWord}-${b.date}.pdf`, buffer: pdf, type: 'application/pdf' }]);
			belege++;
		}
	}
	log(`${data.costCenters.length} Kostenstellen, ${data.bookings.length} Buchungen, ${belege} Belege`);

	step('Regeln und Finanzplan');
	for (const r of data.rules) {
		await admin.api('POST', '/rules', {
			matchField: r.field, matchValue: r.value, contraAccountId: accountId[r.account], priority: r.priority,
			...(r.costCenter ? { costCenterId: ccId[r.costCenter] } : {}),
			...(r.parts ? { parts: r.parts.map((p) => ({ accountId: accountId[p.account], amount: p.amount })) } : {}),
		});
	}
	const current = periodOf[data.years[data.years.length - 1]];
	for (const b of data.budget) {
		await admin.api('POST', '/budget', { accountId: accountId[b.account], period: current, amount: b.amount, note: b.note });
	}
	for (const b of data.costCenterBudgets) {
		await admin.api('PUT', '/cost-center-budgets', { period: current, costCenterId: ccId[b.code], income: b.income, expense: b.expense });
	}
	log(`${data.rules.length} Regeln (eine mit Aufteilung), ${data.budget.length} Planwerte, ${data.costCenterBudgets.length} Kostenstellen-Plaene`);

	step('SEPA, Beitragsgruppen, Mitglieder');
	await admin.api('POST', '/settings', {
		sepa_creditor_id: content.sepa.creditorId,
		sepa_debtor_account_id: accountId[content.sepa.bankAccount],
	});
	await admin.api('POST', '/sepa-import/settings', {
		returnFeeAccountId: accountId['5400'], returnFeeRechargeEnabled: '1', contributionDefaultAccountId: accountId['4000'],
	});
	for (const g of data.contributionGroups) {
		await admin.api('POST', '/contribution-groups', g.amountBasis === 'year'
			? { name: g.name, allowedIntervals: [12], defaultInterval: 12, amountBasis: 'year', minAnnualAmount: g.annualAmount, defaultAnnualAmount: g.annualAmount, isActive: true }
			: { name: g.name, allowedIntervals: [1, 3, 12], defaultInterval: 12, minMonthlyAmount: g.monthlyAmount, defaultMonthlyAmount: g.monthlyAmount, isActive: true });
	}
	const preview = await admin.api('POST', '/sepa/members/import/preview', { csv: data.memberCsv });
	const imported = await admin.api('POST', '/sepa/members/import', { csv: data.memberCsv, mandatesConfirmed: true });
	const memberId = {};
	for (const m of asList(await admin.api('GET', '/members'))) memberId[m.memberNumber] = m.id;
	const fehlend = data.members.filter((m) => !memberId[m.memberNumber]);
	if (fehlend.length) {
		throw new Error(`${fehlend.length} Mitglieder fehlen nach dem Import (${fehlend[0].memberNumber}). Vorschau: ${JSON.stringify(preview).slice(0, 400)} Import: ${JSON.stringify(imported).slice(0, 400)}`);
	}
	// Papiermandate mit Scan als Nachweis - sonst meldet das Aufgaben-Klemmbrett
	// jedes einzelne als "Mandat ohne Nachweis".
	const memberByNumber = Object.fromEntries(data.members.map((m) => [m.memberNumber, m]));
	const numberById = Object.fromEntries(Object.entries(memberId).map(([nr, id]) => [id, nr]));
	let scans = 0;
	for (const mandate of asList(await admin.api('GET', '/mandates'))) {
		const m = memberByNumber[numberById[mandate.memberId]];
		if (!m || !m.signedDate) continue;
		const pdf = textPdf([content.mandateScan.title, content.club.name, `${m.firstName} ${m.lastName}`, `IBAN ${m.iban.slice(0, 4)} **** **** **** ${m.iban.slice(-4)}`, `${content.mandateScan.signed} ${m.signedDate}`]);
		await admin.form(`/mandates/${mandate.id}/document`, {}, [{ field: 'file', name: `Mandat-${m.memberNumber}.pdf`, buffer: pdf, type: 'application/pdf' }]);
		scans++;
	}
	log(`${data.members.length} Mitglieder in ${data.contributionGroups.length} Gruppen, ${data.mandates.length} Mandate per Import, ${scans} Scans`);

	step(`Mitgliedskonto ${data.memberLogin.uid}: Verknuepfung, Mandat online`);
	const login = data.memberLogin;
	const member = await linkAndConfirm(memberId[login.memberNumber], login.uid, login.email);
	await member.api('POST', '/self/mandate', { iban: login.iban, accountHolder: `${login.firstName} ${login.lastName}` });
	log(`${login.uid} verknuepft und bestaetigt, Mandat elektronisch erteilt`);

	step('Eingereichter Einzug mit spaeterer Ruecklastschrift');
	for (const c of data.returnBatch.claims) {
		await admin.api('POST', '/claims', {
			memberId: memberId[c.memberNumber], type: c.type, amount: c.amount, label: c.label,
			dueDate: data.returnBatch.dueDate, accountId: accountId['4900'],
		});
	}
	const batch = await admin.api('POST', '/debit-batches', { dueDate: data.returnBatch.dueDate });
	const batchId = idOf(batch);
	await admin.api('POST', `/debit-batches/${batchId}/submit`, { acknowledgeNonCollectible: true });
	const items = asList((await admin.api('GET', `/debit-batches/${batchId}`)).items);
	const returnedLabel = data.members.find((m) => m.memberNumber === data.returnBatch.returnedMemberNumber).label;
	const item = items.find((i) => i.memberDisplayName === returnedLabel) ?? items[0];
	const statementCsv = data.statementCsv.replace('{E2E}', item.endToEndId).replace('{MANDATSREF}', item.mandateReference);
	// Sammelgutschrift am Faelligkeitstag: camt.053 mit einer Zeile je Posten,
	// danach im Bankabgleich verbuchen - sonst stehen die Gebuehren als
	// ueberfaellig auf dem Dashboard.
	const camt = collectionStatement({
		iban: content.statement.accountIban, bookingDate: data.returnBatch.dueDate,
		batchReference: batch.msgId ?? `BATCH-${batchId}`, bookingText: content.returnBatch.creditText, items,
	});
	const camtImport = await admin.api('POST', '/import/commit', { content: camt, filename: 'sammelgutschrift.xml', applyRules: true });
	const recon = asList((await admin.api('GET', '/bank-reconciliation')).items);
	const credit = recon.find((r) => r.bankTx?.amountCents === items.reduce((sum, i) => sum + i.amountCents, 0) && r.bankTx.amountCents > 0);
	if (!credit) throw new Error(`Sammelgutschrift nicht im Bankabgleich (Import ${JSON.stringify(camtImport)})`);
	for (const d of credit.details ?? []) {
		if (!d.debitItemId && !d.matchedItemId) {
			const match = items.find((i) => i.endToEndId === d.endToEndId);
			if (match) await admin.api('POST', `/sepa-import/details/${d.id}/assign`, { debitItemId: match.id });
		}
	}
	await admin.api('POST', `/sepa-import/${credit.bankTx.id}/settle`);
	log(`Einzug ${batchId} mit ${items.length} Posten eingereicht und gutgeschrieben; Ruecklastschrift folgt fuer ${item.memberDisplayName}`);

	step('Forderungen fuer den kommenden Einzug');
	for (const c of data.claims) {
		await admin.api('POST', '/claims', {
			memberId: memberId[c.memberNumber], type: 'beitrag', amount: c.amount, label: c.label,
			dueDate: c.dueDate, accountId: accountId['4000'],
		});
	}
	log(`${data.claims.length} Forderungen, faellig ${data.claims[0].dueDate}`);

	step('Rollen');
	for (const u of content.nextcloudUsers.filter((x) => x.role)) {
		await admin.api('POST', '/permissions', { principalType: 'user', principalId: u.uid, role: u.role });
		log(`${u.uid}: ${u.role}`);
	}

	step('Rechnungen');
	await admin.api('POST', '/settings', {
		invoice_module_active: '1', invoice_account_id: accountId[content.sepa.bankAccount], invoice_tax_mode: 'kleinunternehmer',
		// Ansprechpartner fuer die XRechnung - sonst meldet die Rechnung "fehlt noch".
		club_phone: content.club.phone, club_email: content.club.email,
	});
	const invoiceIds = {};
	for (const [key, inv] of Object.entries(data.invoices)) {
		const debtor = await admin.api('POST', '/debtors', inv.debtor);
		const draft = await admin.api('POST', '/invoices', {
			debtorId: idOf(debtor), issueDate: inv.issueDate, deliveryDate: inv.deliveryDate, dueDate: inv.dueDate,
			...(content.invoices[key]?.buyerReference ? { buyerReference: content.invoices[key].buyerReference } : {}),
			lines: [{ name: inv.line.name, quantity: 1, unitCode: 'LS', unitPrice: inv.line.amount, accountId: accountId[inv.line.account] }],
		});
		invoiceIds[key] = idOf(draft);
		await admin.api('POST', `/invoices/${invoiceIds[key]}/issue`, { issueDate: inv.issueDate });
		if (inv.dunning) {
			await admin.api('POST', `/invoices/${invoiceIds[key]}/dunning`, { to: inv.debtor.email, subject: inv.dunning.subject, body: inv.dunning.body, level: 1, copyBoard: false });
		}
	}
	log('eine laufende Rechnung, eine ueberfaellige mit erster Mahnung');

	step('Auslagen');
	await admin.api('POST', '/settings', { reimbursement_enabled: '1', reimbursement_required_approvals: '1' });
	for (const r of data.reimbursements) {
		await member.form('/self/reimbursements', {
			amount: euroDe(r.amount), expenseDate: r.expenseDate, purpose: r.purpose, iban: login.iban,
			declaration: '1', invoiceNumber: r.invoiceNumber, supplier: r.supplier,
		}, [{ field: 'files[]', name: `${r.invoiceNumber}.pdf`, buffer: textPdf(r.receipt), type: 'application/pdf' }]);
	}
	log(`${data.reimbursements.length} Auslage von ${login.uid} eingereicht`);

	step('Kontoauszug des Vormonats');
	const imp = await admin.api('POST', '/import/commit', { content: data.statementPrevCsv, filename: 'kontoauszug-vormonat.csv', applyRules: true });
	log(`${imp.new} neu, ${imp.duplicate} Dubletten, ${imp.autoAssigned} per Regel zugeordnet`);

	step('Beleg im Belegordner, Index');
	rmSync(watchDir(LANG), { recursive: true, force: true });
	mkdirSync(watchDir(LANG), { recursive: true });
	for (const f of data.watchFolderFiles) {
		const pdf = textPdf(f.lines);
		writeFileSync(join(watchDir(LANG), f.name), pdf);
		await admin.dav('PUT', `${WATCH_PATH}/${f.name}`, pdf, 'application/pdf');
	}
	log(`${data.watchFolderFiles.length} Beleg(e) abgelegt, Belegindex: ${runJob(CONTAINER, 'ReceiptIndexJob')} Job(s) ausgefuehrt`);

	step('Abschluss');
	try {
		await admin.api('POST', `/periods/${periodOf[data.years[0]]}/close`);
		log(`Geschaeftsjahr ${data.years[0]} abgeschlossen`);
	} catch (err) {
		log(`Warnung: Jahr ${data.years[0]} nicht abgeschlossen (${err.message.slice(0, 160)})`);
	}
	const whatsNew = await admin.api('GET', '/whatsnew');
	if (whatsNew?.currentVersion) await admin.api('POST', '/whatsnew/seen', { version: whatsNew.currentVersion });

	const files = {
		'kontoauszug.csv': statementCsv,
		'kontoauszug-vormonat.csv': data.statementPrevCsv,
		'mitglieder.csv': data.memberCsv,
		[`${data.reimbursements[0].invoiceNumber}.pdf`]: textPdf(data.reimbursements[0].receipt),
	};
	for (const [name, body] of Object.entries(files)) writeFileSync(join(outDir, name), body);

	step('Sicherung');
	const exp = await exportBackup(admin, backupFile(LANG));
	const summary = { lang: LANG, today: TODAY, counts: await counts() };
	writeFileSync(summaryFile(LANG), JSON.stringify(summary, null, '\t') + '\n', 'utf8');
	log(`${backupFile(LANG)} (${Math.round(exp.size / 1024)} KB, ${exp.files} Dateien${exp.missingFiles?.length ? `, ${exp.missingFiles.length} fehlen` : ""})`);
	console.log(JSON.stringify(summary.counts));

	if (!flag('no-screenshot')) await screenshot();
	console.log('\nFertig.');
}

main().catch((err) => {
	console.error(`\nFEHLER: ${err.message}`);
	process.exit(1);
});
