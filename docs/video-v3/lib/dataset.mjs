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
	const at = (month, day, description, debit, credit, amount) => {
		const date = `${year}-${pad(month)}-${pad(day)}`;
		if (date > cutoff) return;
		out.push({ date, description, debit, credit, amount: Math.round(amount * 100) / 100 });
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

	return out;
}

/** 75 Mitglieder aus den Namenslisten, mit Beitragsstufe, Mandat und IBAN. */
function buildMembers(content, today) {
	const cfg = content.members;
	const random = rng(20260823);
	const year = Number(today.slice(0, 4));

	// Beitragsstufen mischen statt blockweise vergeben: sonst zahlen ausgerechnet
	// die zuletzt eingetragenen Mitglieder alle denselben Satz, und der
	// Sammeleinzug in Szene 04 bestuende aus sechs gleichen Jugendbeitraegen.
	const tiers = [];
	for (const tier of cfg.tiers) {
		for (let i = 0; i < tier.share; i++) tiers.push(tier);
	}
	const shuffle = rng(4711);
	for (let i = tiers.length - 1; i > 0; i--) {
		const j = Math.floor(shuffle() * (i + 1));
		[tiers[i], tiers[j]] = [tiers[j], tiers[i]];
	}

	const members = [];
	const usedNames = new Set();
	for (let i = 0; i < cfg.seedCount; i++) {
		let first = cfg.first[i % cfg.first.length];
		let last = cfg.last[(i * 7 + Math.floor(i / cfg.last.length)) % cfg.last.length];
		let label = `${first} ${last}`;
		let attempt = 0;
		while (usedNames.has(label)) {
			attempt++;
			last = cfg.last[(i * 7 + attempt) % cfg.last.length];
			label = `${first} ${last}`;
		}
		usedNames.add(label);

		const tier = tiers[i % tiers.length];
		// Die letzten Zeilen ohne Mandat: sie ueberweisen selbst. Die davor
		// liegenden "openNow" sind frisch eingetreten - ihr erster Beitrag ist
		// gerade faellig und traegt damit den Sammeleinzug in Szene 04.
		const noMandate = i >= cfg.seedCount - cfg.withoutMandate;
		const openNow = !noMandate && i >= cfg.seedCount - cfg.withoutMandate - cfg.openNow;

		members.push({
			label,
			email: `${slug(first)}.${slug(last)}@example.org`,
			iban: noMandate ? null : buildIban(TEST_BANK_CODE, 1370000 + i * 37),
			bic: noMandate ? null : TEST_BIC,
			signedDate: openNow ? addDays(today, -(14 + Math.floor(random() * 10))) : `${year - 2}-11-${pad(4 + (i % 20))}`,
			amount: tier.amount,
			frequency: tier.frequency,
			tier: tier.key,
			startDate: openNow ? addDays(today, -(8 + (i % 5))) : `${year}-01-15`,
			group: noMandate ? 'transfer' : (openNow ? 'open' : 'settled'),
		});
	}
	return members;
}

/** Die zwoelf Neuzugaenge, die im Video per CSV eingelesen werden. */
function buildNewMembers(content, today, seeded) {
	const cfg = content.members;
	const taken = new Set(seeded.map((m) => m.label));
	const out = [];
	for (let i = 0; cfg.newInVideo > out.length; i++) {
		const first = cfg.first[(i * 3 + 11) % cfg.first.length];
		const last = cfg.last[(i * 5 + 17) % cfg.last.length];
		const label = `${first} ${last}`;
		if (taken.has(label)) continue;
		taken.add(label);
		// Zehn zahlen den Regelsatz (leeres Betragsfeld -> Standardbeitrag aus
		// den Einstellungen), zwei den Jugendbeitrag. Genau der Fall, den die
		// Voreinstellung im Video sichtbar macht.
		const youth = out.length >= cfg.newInVideo - 2;
		out.push({
			label,
			email: `${slug(first)}.${slug(last)}@example.org`,
			iban: buildIban(TEST_BANK_CODE, 4820000 + out.length * 53),
			bic: TEST_BIC,
			signedDate: addDays(today, -(3 + out.length)),
			amount: youth ? 30 : null,
			frequency: youth ? 'yearly' : null,
			startDate: addDays(today, 7),
		});
	}
	return out;
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
// die drei bereits gebuchten Umsaetze muessen in beiden Dateien Zeichen fuer
// Zeichen gleich sein, sonst erkennt der Import sie nicht als Dublette.
function csvRow(content, row) {
	const date = de(row.date);
	const counterIban = buildIban(TEST_BANK_CODE, 9100000 + (hashCode(row.party) % 800000));
	return [
		content.statement.accountIban, date, date, row.kind, row.purpose,
		'', '', '', '', '', '',
		row.party, counterIban, TEST_BIC,
		euroDe(row.amount), 'EUR', '',
	].join(';');
}

function buildStatements(content, today) {
	const known = content.statement.known.map((r) => ({ ...r, date: addDays(today, -r.daysAgo) }));
	const fresh = content.statement.new.map((r) => ({ ...r, date: addDays(today, -r.daysAgo) }));
	const all = [...known, ...fresh].sort((a, b) => a.date.localeCompare(b.date));

	const head = content.statement.header;
	return {
		known: [head, ...known.map((r) => csvRow(content, r))].join('\r\n') + '\r\n',
		full: [head, ...all.map((r) => csvRow(content, r))].join('\r\n') + '\r\n',
		knownRows: known,
		newRows: fresh,
	};
}

/**
 * Die Mitgliederliste, die im Video eingelesen wird - in der Schreibweise, die
 * ein Verein in dieser Sprache tatsaechlich exportieren wuerde: deutsche
 * Tabellen mit 15.01.2026 und 42,50, englische mit 2026-01-15 und 42.50. Beide
 * Varianten versteht MemberCsvParser seit 0.28.0.
 */
function buildMemberCsv(content, members) {
	const isoDates = content.dateFormat === 'iso';
	const datum = (value) => (isoDates ? value : de(value));
	const betrag = (value) => (isoDates ? value.toFixed(2) : euroDe(value));

	const header = content.memberCsvHeader.join(';');
	const lines = members.map((m) => [
		m.label,
		m.email,
		m.iban ?? '',
		m.bic ?? '',
		m.signedDate ? datum(m.signedDate) : '',
		m.amount === null || m.amount === undefined ? '' : betrag(m.amount),
		m.frequency ? (content.lang === 'de' ? 'jährlich' : 'yearly') : '',
		m.startDate ? datum(m.startDate) : '',
	].join(';'));
	return [header, ...lines].join('\r\n') + '\r\n';
}

export function buildDataset(content, todayIso) {
	const today = todayIso;
	const year = Number(today.slice(0, 4));
	const years = [year - 2, year - 1, year];
	const random = rng(19700101);

	const members = buildMembers(content, today);
	const newMembers = buildNewMembers(content, today, members);

	// Summe des Januar-Sammeleinzugs: genau die Mitglieder, die im Januar
	// faellig waren und ein Mandat haben. Damit stimmt die Buchung im Journal
	// mit dem Einzug ueberein, den die Beitraege-Ansicht zeigt.
	const settledTotal = members
		.filter((m) => m.group === 'settled')
		.reduce((sum, m) => sum + m.amount, 0);

	const bookings = [];
	// Sechs Wochen Luecke am Ende: die deckt der Kontoauszug aus Szene 03 ab.
	const cutoffCurrent = addDays(today, -40);
	for (const y of years) {
		const cutoff = y === year ? cutoffCurrent : `${y}-12-31`;
		const total = y === year ? settledTotal : settledTotal - 220 + (y - year + 2) * 110;
		bookings.push(...bookingsForYear(content, y, cutoff, total, random));
	}
	bookings.sort((a, b) => a.date.localeCompare(b.date));

	const statements = buildStatements(content, today);

	return {
		today,
		years,
		accounts: content.accounts,
		costCenters: content.costCenters,
		rules: content.rules,
		budget: content.budget,
		bookings,
		members,
		newMembers,
		settledTotal,
		batchExecutionDate: `${year}-01-25`,
		openItems: content.openItems.map((item) => ({ ...item, dueDate: addDays(today, -item.dueDaysAgo) })),
		csv: {
			statementKnown: statements.known,
			statementFull: statements.full,
			membersNew: buildMemberCsv(content, newMembers),
		},
		counts: {
			bookings: bookings.length,
			members: members.length,
			newMembers: newMembers.length,
			statementRows: statements.knownRows.length + statements.newRows.length,
			statementKnown: statements.knownRows.length,
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
		settledTotal: data.settledTotal,
		beispielBuchungen: data.bookings.slice(0, 3),
		beispielMitglieder: data.members.slice(0, 2),
		gruppen: data.members.reduce((acc, m) => ({ ...acc, [m.group]: (acc[m.group] ?? 0) + 1 }), {}),
	}, null, 2));
	if (process.argv.includes('--dump')) {
		console.log('\n--- kontoauszug.csv (Ausschnitt) ---\n' + data.csv.statementFull.split('\r\n').slice(0, 4).join('\n'));
		console.log('\n--- mitglieder-neu.csv (Ausschnitt) ---\n' + data.csv.membersNew.split('\r\n').slice(0, 4).join('\n'));
	}
}
