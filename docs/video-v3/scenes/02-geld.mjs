// Block 02: Geld im Griff.
//
// Kontoauszug importieren (15 neu, 3 Dubletten - die Zahlen sind echt), danach
// in der Buchungsliste die von einer Regel aufgeteilte Buchung (Zuschuss
// Chorverband: 4200 + 4210, Kostenstelle K30), der Belegvorschlag aus dem
// Waechter-Ordner und zum Schluss eine Kostenstelle an der Buchung.
//
// Der Import veraendert den Bestand, darum vorher frisch wiederherstellen.

import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
const root = join(dirname(fileURLToPath(import.meta.url)), '..');

export const needsFreshData = true;

const T = {
	de: {
		tab: 'Buchungen', alle: 'Alle Buchungen', importieren: 'Umsätze importieren', dubletten: 'Dubletten',
		uebernehmen: 'Buchungen importieren', kostenstelle: 'Kostenstelle', speichern: 'Speichern',
		datei: 'Umsaetze_Girokonto_2026-11.csv',
		cueAuszug: 'Kontoauszug', cueHinein: 'hinein', cueUeberspringt: 'überspringt', cueRegeln: 'Regeln',
		cueMehrere: 'mehrere', cueRechnung: 'Rechnung', cueKostenstelle: 'Kostenstelle',
	},
	en: {
		tab: 'Entries', alle: 'All entries', importieren: 'Import transactions', dubletten: 'duplicates',
		uebernehmen: 'Import', kostenstelle: 'Cost centre', speichern: 'Save',
		datei: 'transactions_checking_2026-11.csv',
		cueAuszug: 'drop', cueHinein: 'statement', cueUeberspringt: 'skips', cueRegeln: 'Rules',
		cueMehrere: 'several', cueRechnung: 'invoice', cueKostenstelle: 'cost',
	},
};

export async function prepare(ctx) {
	const t = T[ctx.lang];
	await ctx.click({ text: t.tab, tag: '.vbh-tabs button', exact: true }, { move: false, settle: 1200 });
	await ctx.evaluate('window.scrollTo(0, 0), true');
}

export async function record(ctx) {
	const t = T[ctx.lang];
	ctx.shot('import');

	// Kontoauszug hineinziehen.
	// Frueh oeffnen: das Ziehen selbst dauert gut drei Sekunden und soll zu
	// "hinein" / "statement" auf der Ablageflaeche landen.
	await ctx.until(ctx.cueAt(t.cueAuszug) - 1.2);
	await ctx.click({ text: t.importieren, tag: 'button' });
	await ctx.waitFor(`__vbhVisible('.modal-container input[type=file]').length > 0 || document.querySelector('.modal-container input[type=file]') !== null`);
	// Sichtbar hineinziehen: Dateikarte vom Bildrand auf die Ablageflaeche.
	await ctx.dragFile(join(root, 'build', ctx.lang, 'kontoauszug.csv'), '.modal-container .vbh-dropzone', { label: t.datei });
	await ctx.waitFor(`__vbhVisibleText('.modal-container').toLowerCase().includes(${JSON.stringify('3 ' + t.dubletten.toLowerCase())})`, 15000);

	// Bereits Gebuchtes ueberspringt sie: die Zaehler "neu / Dubletten / gesamt".
	await ctx.until(ctx.cueAt(t.cueUeberspringt) - 0.3);
	await ctx.moveTo({ text: t.dubletten, tag: '.modal-container span' });
	await ctx.mark('dubletten', { text: t.dubletten, tag: '.modal-container div' });

	// Uebernehmen - das Ergebnis meldet, wie viel die Regeln zugeordnet haben.
	await ctx.until(ctx.cueAt(t.cueRegeln) - 0.4);
	await ctx.click({ text: t.uebernehmen, tag: '.modal-container button' });
	await ctx.waitFor(`/importiert|imported/i.test(__vbhVisibleText('.modal-container'))`, 20000);
	await ctx.sleep(1600);
	await ctx.closeModal();
	await ctx.waitFor(`__vbhVisible('.modal-container').length === 0`, 10000);

	// Aufgeteilt auf mehrere Konten: die Zuschussbuchung mit 4200 und 4210.
	await ctx.until(ctx.cueAt(t.cueMehrere) - 1.0);
	await ctx.moveTo({ text: '4210', tag: 'tbody td' });
	await ctx.mark('aufteilung', { text: '4210', tag: 'tbody td' });

	// Belegvorschlag: das Symbol an der Notenbuchung oeffnet den Dialog mit dem
	// Vorschlag aus dem Waechter-Ordner.
	await ctx.until(ctx.cueAt(t.cueRechnung) - 0.2);
	ctx.shot('beleg');
	await ctx.click('tbody .vbh-suggest--possible', { settle: 300 });
	await ctx.waitFor(`__vbhVisible('section.vbh-folder-suggestions li.vbh-suggest-item').length > 0`, 15000);
	await ctx.sleep(500);
	await ctx.mark('belegvorschlag', 'section.vbh-folder-suggestions');
	await ctx.moveTo('section.vbh-folder-suggestions li.vbh-suggest-item');
	await ctx.until(ctx.cueAt(t.cueRechnung) + 3.4);
	await ctx.click('section.vbh-folder-suggestions li.vbh-suggest-item button', { settle: 600 });

	// Kostenstelle an der Buchung, dann speichern.
	await ctx.until(ctx.cueAt(t.cueKostenstelle) - 0.6);
	await ctx.select(`.modal-container select[aria-label="${t.kostenstelle}"]`, 'K10');
	await ctx.sleep(500);
	await ctx.moveTo({ text: t.speichern, tag: '.modal-container button', exact: true });
}
