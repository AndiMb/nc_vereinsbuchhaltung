import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { buildDataset } from '../lib/dataset.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const content = (lang) => JSON.parse(readFileSync(join(here, '..', 'content', `seed.${lang}.json`), 'utf8'));
const TODAY = '2026-11-15';

function ibanOk(iban) {
	const r = iban.slice(4) + iban.slice(0, 4);
	const num = r.replace(/[A-Z]/g, (c) => String(c.charCodeAt(0) - 55));
	let rest = 0;
	for (const d of num) rest = (rest * 10 + Number(d)) % 97;
	return rest === 1;
}

const rows = (csv) => csv.split('\r\n').slice(1).filter(Boolean);

for (const lang of ['de', 'en']) {
	test(`${lang}: deterministisch`, () => {
		assert.equal(JSON.stringify(buildDataset(content(lang), TODAY)), JSON.stringify(buildDataset(content(lang), TODAY)));
	});

	test(`${lang}: alle IBANs gueltig`, () => {
		const data = buildDataset(content(lang), TODAY);
		assert.ok(data.mandates.length > 50, `nur ${data.mandates.length} Mandate`);
		for (const m of data.mandates) {
			assert.ok(ibanOk(m.iban), `ungueltig: ${m.iban}`);
			assert.ok(m.iban.includes('50010517'), m.iban);
		}
	});

	test(`${lang}: Kontoauszug`, () => {
		const c = content(lang);
		const data = buildDataset(c, TODAY);
		const prev = new Set(rows(data.statementPrevCsv));
		const doppelt = rows(data.statementCsv).filter((r) => prev.has(r)).length;
		assert.equal(doppelt, c.statement.alreadyBooked);
	});

	test(`${lang}: Einzug in der Zukunft`, () => {
		const data = buildDataset(content(lang), TODAY);
		assert.ok(data.claims.length > 0);
		for (const claim of data.claims) assert.ok(claim.dueDate > TODAY, `${claim.memberNumber}: ${claim.dueDate}`);
	});

	test(`${lang}: Ruecklastschrift-Einzug liegt in der Vergangenheit`, () => {
		const data = buildDataset(content(lang), TODAY);
		assert.ok(data.returnBatch.dueDate < TODAY);
		assert.ok(data.returnBatch.claims.length >= 1);
	});

	test(`${lang}: Freigrenze im Warnbereich (70–100 % von 45.000 EUR)`, () => {
		const data = buildDataset(content(lang), TODAY);
		const wirtschaftlich = new Set(data.accounts.filter((a) => a.sphere === 'wirtschaftlich' && a.type === 'income').map((a) => a.number));
		const year = TODAY.slice(0, 4);
		const summe = data.bookings
			.filter((b) => b.date.startsWith(year) && wirtschaftlich.has(b.credit))
			.reduce((s, b) => s + b.amount, 0);
		assert.ok(summe >= 31500 && summe < 45000, String(summe));
	});
}

test('en: englische CSV-Kopfzeile', () => {
	const data = buildDataset(content('en'), TODAY);
	assert.ok(data.memberCsv.startsWith('First name;Last name;Member number;Email;IBAN;Mandate;Group;Amount;Frequency;Start date'),
		data.memberCsv.split('\r\n')[0]);
});
