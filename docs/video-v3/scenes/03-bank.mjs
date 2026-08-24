// Szene 03: Kontoauszug importieren, Dubletten sehen, zuordnen, Regeln.
//
// Die Datei stammt aus dem Seed (build/<lang>/kontoauszug.csv): 17 Umsaetze,
// davon drei schon gebucht. Der Import meldet deshalb "14 neu, 3 Dubletten" -
// die Zahl im Bild ist echt, nicht gestellt.

import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
const root = join(dirname(fileURLToPath(import.meta.url)), '..');

const T = {
	de: {
		tab: 'Buchungen', importieren: 'Umsätze importieren', dialogTitel: 'Kontoumsätze importieren',
		zuzuordnen: 'Zuzuordnen', regeln: 'Regeln',
	},
	en: {
		tab: 'Entries', importieren: 'Import transactions', dialogTitel: 'Import bank transactions',
		zuzuordnen: 'To assign', regeln: 'Rules',
	},
};

export async function prepare(ctx) {
	const t = T[ctx.lang];
	await ctx.click({ text: t.tab, tag: '.vbh-tabs button', exact: true }, { move: false, settle: 1200 });
	await ctx.evaluate('window.scrollTo(0, 0), true');
}

export async function record(ctx) {
	const t = T[ctx.lang];
	await ctx.lowerThird(ctx.scene.lowerThird, { hold: 5 });

	// Import oeffnen und die Datei hineingeben.
	await ctx.until(1.5);
	await ctx.click({ text: t.importieren, tag: 'button' });
	await ctx.waitFor(`__vbhVisible('.vbh-modal-title').some(e => e.textContent.includes(${JSON.stringify(t.dialogTitel)}))`);

	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'hineinziehen' : 'drop'));
	await ctx.setFile(join(root, 'build', ctx.lang, 'kontoauszug.csv'));
	// Die Vorschau kommt vom Server; auf sie warten statt auf eine feste Zeit.
	await ctx.waitFor(`/\\b14\\b/.test(__vbhVisibleText('.modal-container'))`, 15000);

	// Dublettenmeldung: das ist der Moment, in dem die Arbeitsersparnis sichtbar wird.
	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'überspringt' : 'skipped') - 0.5);
	await ctx.callout(ctx.scene.callouts[0].text, { hold: 4.5 });

	// Uebernehmen. Der Knopf traegt die Zahl der neuen Umsaetze ("14 Buchungen
	// importieren"), deshalb per Teiltext suchen.
	const uebernehmen = ctx.lang === 'de' ? 'importieren' : 'Import';
	await ctx.click({ text: uebernehmen, tag: '.modal-container button' });
	// Der Dialog bleibt mit seinem Ergebnis stehen und wird von Hand geschlossen.
	await ctx.waitFor(`/importiert|imported/i.test(__vbhVisibleText('.modal-container'))`, 20000);
	await ctx.sleep(700);
	await ctx.closeModal();
	await ctx.waitFor('__vbhVisible(".vbh-modal-title").length === 0', 10000);

	// Zuzuordnen: sieben Umsaetze ohne Gegenkonto, der Rest lief ueber Regeln.
	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Zuzuordnen' : 'assign'));
	await ctx.click({ text: t.zuzuordnen, tag: 'button' });
	await ctx.sleep(900);

	// Einen Umsatz zuordnen.
	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Gegenkonto' : 'counter-account'));
	await ctx.assignFirstTransaction(ctx.lang === 'de' ? 'Spenden' : 'Donations');

	// Regeln zeigen.
	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Regeln' : 'rules'));
	await ctx.click({ text: t.regeln, tag: 'button' });
	await ctx.sleep(1200);

	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Wachordner' : 'watched folder') - 0.4);
	await ctx.callout(ctx.scene.callouts[1].text, { hold: 4.5 });
	await ctx.scrollTo(420, { duration: 1800 });
}
