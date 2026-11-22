// Baut aus content/seed.<lang>.json den konkreten Datenbestand: Buchungen dreier
// Geschaeftsjahre, Mitglieder mit gueltigen Test-IBANs, Kontoauszuege als CSV.
//
// Reine Rechnerei, kein I/O und kein Netz - damit laesst sich der Datenstand
// pruefen (node lib/dataset.mjs --lang de --dump), ohne eine Nextcloud zu haben.
//
// Alles ist deterministisch: derselbe Aufnahmetag ergibt denselben Bestand. Kein
// Math.random(), sondern ein festes Startwert-PRNG - sonst unterscheiden sich
// deutscher und englischer Lauf in Betraegen, die im Bild nebeneinander stehen.

/** Deterministischer Zufall (mulberry32) - gleicher Seed, gleiche Folge. */
function rng(seed) {
	let a = seed >>> 0;
	return () => {
		a = (a + 0x6D2B79F5) >>> 0;
		let t = Math.imul(a ^ (a >>> 15), 1 | a);
		t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
		return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
	};
}

const pad = (n, len = 2) => String(n).padStart(len, '0');

export function iso(date) {
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function parseIso(s) {
	const [y, m, d] = s.split('-').map(Number);
	return new Date(y, m - 1, d);
}

export function addDays(isoDate, days) {
	const d = parseIso(isoDate);
	d.setDate(d.getDate() + days);
	return iso(d);
}

const de = (isoDate) => { const [y, m, d] = isoDate.split('-'); return `${d}.${m}.${y}`; };
const euroDe = (v) => v.toFixed(2).replace('.', ',');

/**
 * Pruefziffern nach ISO 13616. Die App validiert IBANs (IbanValidator), eine
 * ausgedachte Ziffernfolge wuerde beim Anlegen abgelehnt.
 */
export function buildIban(bankCode, accountNumber) {
	const bban = `${bankCode}${String(accountNumber).padStart(10, '0')}`;
	const rearranged = `${bban}DE00`;
	const numeric = rearranged.replace(/[A-Z]/g, (c) => String(c.charCodeAt(0) - 55));
	let rest = 0;
	for (const digit of numeric) {
		rest = (rest * 10 + Number(digit)) % 97;
	}
	return `DE${pad(98 - rest)}${bban}`;
}

const TEST_BANK_CODE = '50010517';
const TEST_BIC = 'SOLADES1WLD';

/** Umlaute und Akzente raus - fuer E-Mail-Adressen aus Namen. */
function slug(text) {
	return text
		.toLowerCase()
		.replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
		.normalize('NFD').replace(/[̀-ͯ]/g, '')
		.replace(/[^a-z0-9]+/g, '.')
		.replace(/^\.|\.$/g, '');
}

function fill(template, values) {
	return template.replace(/\{(\w+)\}/g, (_, key) => (key in values ? String(values[key]) : `{${key}}`));
}

const MONTH_NAMES = {
	de: ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
	en: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
};

/**
 * Buchungen eines Geschaeftsjahres. Wiederkehrendes (Miete, Chorleitung,
 * Kontofuehrung) plus die typischen Ereignisse eines Chorjahres.
 *
 * @param cutoff letzter erlaubter Buchungstag; im laufenden Jahr liegt der
 *        bewusst rund sechs Wochen zurueck, damit die letzten Wochen vom
 *        Kontoauszug in Szene 03 abgedeckt werden und sich nichts doppelt.
 */
function bookingsForYear(content, year, cutoff, feeTotal, random) {
	const t = content.bookingTexts;
	const months = MONTH_NAMES[content.lang] ?? MONTH_NAMES.de;
	const out = [];
	const ccOf = costCenterIndex(content);
	const recurring = new Set([t.rent, t.conductor, t.bankFee].map((x) => x.split(' {')[0]));
	const at = (month, day, description, debit, credit, amount) => {
		const date = `${year}-${pad(month)}-${pad(day)}`;
		if (date > cutoff) return;
		const booking = { date, description, debit, credit, amount: Math.round(amount * 100) / 100 };
		const costCenter = ccOf.get(debit) ?? ccOf.get(credit);
		if (costCenter) booking.costCenter = costCenter;
		// Belege fuer das Pruefer-ZIP: jede Ausgabe ausser den Dauerauftraegen.
		if (debit.startsWith('5') && ![...recurring].some((r) => description.startsWith(r))) {
			booking.receipt = true;
		}
		out.push(booking);
	};

	for (let m = 1; m <= 12; m++) {
		const monat = `${months[m - 1]} ${year}`;
		at(m, 3, fill(t.rent, { monat }), '5000', '1200', 120);
		at(m, 5, fill(t.conductor, { monat }), '5010', '1200', 300);
		at(m, 28, fill(t.bankFee, { monat }), '5400', '1200', 5);
	}

	const jahr = String(year);
	at(1, 27, fill(t.feesCollected, { jahr }), '1200', '4000', feeTotal);
	for (let i = 0; i < 3; i++) {
		at(2, 12 + i, fill(t.feeTransfer, { jahr }), '1200', '4000', 60);
	}
	at(2, 20, fill(t.gema, { jahr }), '5110', '1200', 186.4);
	at(3, 8, fill(t.associationDues, { jahr }), '5100', '1200', 145);
	at(3, 21, t.springIncome, '1200', '4310', 1180 + Math.round(random() * 240));
	at(3, 23, t.springCost, '5210', '1200', 560 + Math.round(random() * 120));
	at(4, 14, fill(t.grantAssociation, { jahr }), '1200', '4200', 450);
	at(5, 6, fill(t.sheetMusic, { titel: content.sheetMusicTitles[year % content.sheetMusicTitles.length] }), '5110', '1200', 128.5);
	at(5, 18, fill(t.donation, { name: content.donorNames[year % content.donorNames.length] }), '1200', '4100', 200);
	at(6, 10, fill(t.grantTown, { jahr }), '1200', '4200', 350);
	at(6, 21, fill(t.tripIncome, { jahr }), '1200', '4400', 1540);
	at(6, 24, fill(t.tripCost, { jahr }), '5500', '1200', 1980);
	at(7, 4, t.office, '5300', '1200', 62.3);
	at(7, 16, t.youthChoir, '5230', '1200', 95);
	at(8, 7, fill(t.insurance, { jahr }), '5100', '1200', 168);
	at(8, 19, fill(t.donation, { name: content.donorNames[(year + 1) % content.donorNames.length] }), '1200', '4100', 150);
	at(9, 12, fill(t.sheetMusic, { titel: content.sheetMusicTitles[(year + 2) % content.sheetMusicTitles.length] }), '5110', '1200', 74.9);
	at(9, 26, t.cashConcert, '1000', '4900', 180);
	at(10, 9, fill(t.donation, { name: content.donorNames[(year + 2) % content.donorNames.length] }), '1200', '4100', 300);
	at(10, 22, t.office, '5300', '1200', 38.6);
	at(11, 5, t.youthChoir, '5230', '1200', 110);
	at(11, 27, fill(t.sheetMusic, { titel: content.sheetMusicTitles[(year + 3) % content.sheetMusicTitles.length] }), '5110', '1200', 96.4);
	at(12, 13, t.christmasIncome, '1200', '4320', 1720 + Math.round(random() * 300));
	at(12, 15, t.christmasCost, '5220', '1200', 690 + Math.round(random() * 120));
	at(12, 19, t.misc, '5900', '1200', 45.8);

	// Sommerfest: der wirtschaftliche Geschaeftsbetrieb, dessen Einnahmen die
	// Freigrenze-Warnung (70 % von 45.000 EUR) ausloesen.
	const fair = content.summerFair;
	fair.cost.forEach((amount, i) => at(6, 26 + i, fill(t.summerFairCost, { jahr, lieferant: fair.suppliers[i] }), '5600', '1200', amount));
	fair.income.forEach((amount, i) => at(7, 6 + i, fill(t.summerFairIncome, { jahr, stand: fair.stands[i] }), '1200', '4500', amount));

	return out;
}

/** Kontonummer -> Kostenstellen-Code (Buchungszeilen tragen ihn als Zuordnung). */
function costCenterIndex(content) {
	const map = new Map();
	for (const cc of content.costCenters) for (const account of cc.accounts) map.set(account, cc.code);
	return map;
}

/**
 * Faelligkeit des kommenden Einzugs: 17 Tage nach dem Stichtag. Das liegt hinter
 * der Vorankuendigungsfrist (14 Tage) und im Freigabefenster - das
 * Aufgaben-Klemmbrett zeigt dann "Freigabe faellig".
 */
export function feeDueDate(today) {
	return addDays(today, 17);
}

/** Beitragsgruppen aus den Stufen: Jahresbeitrag (#270) oder Monatsbetrag. */
function buildGroups(content) {
	return content.members.tiers.map((tier) => ({
		key: tier.key,
		name: tier.label,
		amountBasis: tier.amountBasis,
		annualAmount: tier.amountBasis === 'year' ? tier.amount : null,
		monthlyAmount: tier.amountBasis === 'year' ? null : tier.monthlyAmount,
		claimAmount: tier.amountBasis === 'year' ? tier.amount : tier.monthlyAmount * 12,
	}));
}

/** 75 Mitglieder aus den Namenslisten, dazu das Mitglied mit Nextcloud-Konto (mia). */
function buildMembers(content, today, groups) {
	const cfg = content.members;
	const random = rng(20260823);
	const year = Number(today.slice(0, 4));
	const due = feeDueDate(today);

	// Gruppen mischen statt blockweise vergeben: sonst zahlen ausgerechnet die
	// zuletzt eingetragenen Mitglieder alle denselben Satz.
	const slots = [];
	for (const tier of cfg.tiers) {
		for (let i = 0; i < tier.share; i++) slots.push(tier.key);
	}
	const shuffle = rng(4711);
	for (let i = slots.length - 1; i > 0; i--) {
		const j = Math.floor(shuffle() * (i + 1));
		[slots[i], slots[j]] = [slots[j], slots[i]];
	}

	const members = [];
	const usedNames = new Set();
	for (let i = 0; i < cfg.seedCount; i++) {
		const first = cfg.first[i % cfg.first.length];
		let last = cfg.last[(i * 7 + Math.floor(i / cfg.last.length)) % cfg.last.length];
		let attempt = 0;
		while (usedNames.has(`${first} ${last}`)) {
			attempt++;
			last = cfg.last[(i * 7 + attempt) % cfg.last.length];
		}
		usedNames.add(`${first} ${last}`);

		const group = groups.find((g) => g.key === slots[i % slots.length]);
		// Die letzten Zeilen ohne Mandat: sie ueberweisen selbst.
		const noMandate = i >= cfg.seedCount - cfg.withoutMandate;
		members.push({
			memberNumber: `M-${pad(i + 1, 3)}`,
			firstName: first,
			lastName: last,
			label: `${first} ${last}`,
			email: `${slug(first)}.${slug(last)}@example.org`,
			iban: noMandate ? null : buildIban(TEST_BANK_CODE, 1370000 + i * 37),
			// Unterschrieben im Vorjahr: aeltere, nie genutzte Mandate stuenden sonst
			// als "verfaellt bald" im Aufgaben-Klemmbrett (36-Monats-Frist).
			signedDate: noMandate ? null : `${year - 1}-${pad(1 + (i % 11))}-${pad(4 + (i % 20))}`,
			joinedAt: `${year - 1 - (i % 9)}-${pad(1 + ((i * 5) % 12))}-01`,
			group: group.key,
			validFrom: due,
			paymentMethod: noMandate ? 'ueberweisung' : 'direct_debit',
		});
		random();
	}

	const login = content.memberLogin;
	members.push({
		memberNumber: `M-${pad(cfg.seedCount + 1, 3)}`,
		firstName: login.firstName,
		lastName: login.lastName,
		label: `${login.firstName} ${login.lastName}`,
		email: login.email,
		// Ohne IBAN: mia erteilt ihr Mandat im Video-Bestand selbst online.
		iban: null,
		signedDate: null,
		joinedAt: `${year - 1}-09-01`,
		group: 'regular',
		validFrom: due,
		paymentMethod: 'direct_debit',
		ncUser: login.uid,
	});
	return members;
}

/** Stabile Zahl aus einem Text - gleicher Zahlungspartner, gleiche IBAN. */
function hashCode(text) {
	let h = 0;
	for (let i = 0; i < text.length; i++) {
		h = (Math.imul(31, h) + text.charCodeAt(i)) | 0;
	}
	return Math.abs(h);
}

// Die Gegen-IBAN haengt am Namen des Zahlungspartners, nicht an der Zeilennummer:
// die bereits gebuchten Umsaetze muessen in beiden Dateien Zeichen fuer Zeichen
// gleich sein, sonst erkennt der Import sie nicht als Dublette.
function csvRow(content, row) {
	const date = de(row.date);
	const counterIban = row.iban ?? buildIban(TEST_BANK_CODE, 9100000 + (hashCode(row.party) % 800000));
	return [
		content.statement.accountIban, date, date, row.kind, row.purpose,
		row.creditorId ?? '', row.mandateRef ?? '', row.e2e ?? '', '',
		row.original === undefined ? '' : euroDe(row.original),
		row.fee === undefined ? '' : euroDe(row.fee),
		row.party, counterIban, TEST_BIC,
		euroDe(row.amount), 'EUR', '',
	].join(';');
}

/**
 * Vormonat (beim Seed eingelesen) und aktueller Auszug (im Video eingelesen).
 * Im aktuellen Auszug steckt die Ruecklastschrift eines Einzugs; dessen
 * End-to-End-ID und Mandatsreferenz kennt erst der Seed, darum Platzhalter
 * {E2E} und {MANDATSREF}.
 */
function buildStatements(content, today, returned) {
	const known = content.statement.known.map((r) => ({ ...r, date: addDays(today, -r.daysAgo) }));
	const fresh = content.statement.new.map((r) => ({ ...r, date: addDays(today, -r.daysAgo) }));
	const r = content.statement.returned;
	fresh.push({
		date: addDays(today, -r.daysAgo), kind: r.kind, purpose: r.purpose,
		party: returned.member.label, iban: returned.member.iban,
		creditorId: content.sepa.creditorId, mandateRef: '{MANDATSREF}', e2e: '{E2E}',
		original: returned.amount, fee: r.fee, amount: -(returned.amount + r.fee),
	});
	const all = [...known, ...fresh].sort((a, b) => a.date.localeCompare(b.date));
	const head = content.statement.header;
	return {
		prev: [head, ...known.map((x) => csvRow(content, x))].join('\r\n') + '\r\n',
		full: [head, ...all.map((x) => csvRow(content, x))].join('\r\n') + '\r\n',
		rows: all.length,
	};
}

/**
 * Mitgliederliste fuer den Import: deutsche Tabellen mit 15.01.2026 und 42,50,
 * englische mit 2026-01-15. Betrag bleibt leer - dann gilt der Betrag der
 * Beitragsgruppe.
 */
function buildMemberCsv(content, members, groups) {
	const isoDates = content.dateFormat === 'iso';
	const datum = (value) => (value ? (isoDates ? value : de(value)) : '');
	const header = content.memberCsvHeader.join(';');
	const lines = members.map((m) => [
		m.firstName, m.lastName, m.memberNumber, m.email,
		m.iban ?? '', datum(m.signedDate),
		groups.find((g) => g.key === m.group).name, '',
		content.members.yearly, datum(m.validFrom), datum(m.joinedAt),
	].join(';'));
	return [header, ...lines].join('\r\n') + '\r\n';
}

/** Rechnung im Belegordner; Datum im Text, sonst fehlt dem Belegvorschlag das Datumssignal. */
function watchReceipt(content, today) {
	const r = content.watchReceipt;
	const date = addDays(today, -r.daysAgo);
	const datum = content.dateFormat === 'iso' ? date : de(date);
	return { name: r.file, lines: [r.lines[0], fill(r.dateLine, { datum }), ...r.lines.slice(1)] };
}

export function buildDataset(content, todayIso) {
	const today = todayIso;
	const year = Number(today.slice(0, 4));
	const years = [year - 2, year - 1, year];
	const random = rng(19700101);

	const groups = buildGroups(content);
	const members = buildMembers(content, today, groups);
	const withMandate = members.filter((m) => m.iban);
	const groupOf = (m) => groups.find((g) => g.key === m.group);

	// Januar-Einzug der Vorjahre: Summe der Jahresbeitraege aller Mandatszahler.
	const settledTotal = withMandate.reduce((sum, m) => sum + groupOf(m).claimAmount, 0);

	const bookings = [];
	// Sechs Wochen Luecke am Ende: die deckt der Kontoauszug aus Block 02 ab.
	const cutoffCurrent = addDays(today, -40);
	for (const y of years) {
		const cutoff = y === year ? cutoffCurrent : `${y}-12-31`;
		const total = y === year ? settledTotal : settledTotal - 220 + (y - year + 2) * 110;
		bookings.push(...bookingsForYear(content, y, cutoff, total, random));
	}
	bookings.sort((a, b) => a.date.localeCompare(b.date));

	// Der kommende Jahreseinzug: eine Forderung je Mitglied, faellig am Monatsersten.
	const due = feeDueDate(today);
	const claims = members.map((m) => ({
		memberNumber: m.memberNumber,
		amount: groupOf(m).claimAmount,
		// Beitragsjahr: das Jahr, in das der Einzug hineinfuehrt (Dezember-Einzug -> Folgejahr).
		label: fill(content.members.claimLabel, { jahr: addDays(due, 45).slice(0, 4) }),
		dueDate: due,
	}));

	// Ein schon eingereichter Einzug der Aufnahmegebuehren - einer davon platzt.
	const rb = content.returnBatch;
	const rbMembers = withMandate.slice(-rb.members);
	const returnBatch = {
		dueDate: addDays(today, -rb.daysAgo),
		claims: rbMembers.map((m) => ({ memberNumber: m.memberNumber, amount: rb.amount, label: rb.label, type: 'gebuehr' })),
		returnedMemberNumber: rbMembers[0].memberNumber,
	};
	const statements = buildStatements(content, today, { member: rbMembers[0], amount: rb.amount });

	const resolveInvoice = (inv) => ({
		...inv,
		issueDate: addDays(today, -inv.issueDaysAgo),
		deliveryDate: addDays(today, -inv.issueDaysAgo),
		dueDate: addDays(today, inv.dueInDays),
	});

	return {
		today,
		years,
		accounts: content.accounts,
		costCenters: content.costCenters.map(({ code, name }) => ({ code, name })),
		costCenterBudgets: content.costCenterBudgets,
		bookings,
		rules: content.rules,
		budget: content.budget,
		contributionGroups: groups,
		members,
		mandates: withMandate.map((m) => ({ memberNumber: m.memberNumber, iban: m.iban, signedAt: m.signedDate })),
		assignments: members.map((m) => ({ memberNumber: m.memberNumber, group: m.group, validFrom: m.validFrom, paymentMethod: m.paymentMethod })),
		claims,
		returnBatch,
		invoices: { current: resolveInvoice(content.invoices.current), overdue: resolveInvoice(content.invoices.overdue) },
		reimbursements: [{ ...content.reimbursement, expenseDate: addDays(today, -content.reimbursement.daysAgo) }],
		statementCsv: statements.full,
		statementPrevCsv: statements.prev,
		memberCsv: buildMemberCsv(content, members, groups),
		watchFolderFiles: [watchReceipt(content, today)],
		memberLogin: { ...content.memberLogin, iban: buildIban(TEST_BANK_CODE, 5550001), memberNumber: members[members.length - 1].memberNumber },
		counts: {
			bookings: bookings.length,
			members: members.length,
			mandates: withMandate.length,
			claims: claims.length,
			statementRows: statements.rows,
			statementKnown: content.statement.alreadyBooked,
		},
	};
}

// Direkter Aufruf: Datenbestand rechnen und die Eckwerte zeigen, ohne Nextcloud.
if (import.meta.url === new URL(`file://${process.argv[1]}`).href.replace(/%3A/g, ':')) {
	const { readFileSync } = await import('node:fs');
	const { fileURLToPath } = await import('node:url');
	const { dirname, join } = await import('node:path');
	const here = dirname(fileURLToPath(import.meta.url));
	const lang = process.argv.includes('--lang') ? process.argv[process.argv.indexOf('--lang') + 1] : 'de';
	const todayArg = process.argv.includes('--today') ? process.argv[process.argv.indexOf('--today') + 1] : iso(new Date());
	const content = JSON.parse(readFileSync(join(here, '..', 'content', `seed.${lang}.json`), 'utf8'));
	const data = buildDataset(content, todayArg);
	console.log(JSON.stringify({
		lang, today: data.today, years: data.years, counts: data.counts,
		einzug: data.claims[0].dueDate,
		ruecklastschrift: data.returnBatch,
		beispielBuchungen: data.bookings.slice(0, 3),
		beispielMitglieder: data.members.slice(0, 2),
	}, null, 2));
	if (process.argv.includes('--dump')) {
		console.log('\n--- kontoauszug.csv ---\n' + data.statementCsv);
		console.log('\n--- mitglieder.csv (Ausschnitt) ---\n' + data.memberCsv.split('\r\n').slice(0, 4).join('\n'));
	}
}
