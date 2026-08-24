// Szene 04: Mitgliedsbeiträge und SEPA-Lastschrift - die Neuheit des Videos.
//
// Reihenfolge im Bild: Mitgliederliste, CSV-Import von zwoelf Neuzugaengen,
// dann der Sammeleinzug mit pain.008-Datei und Verbuchen.

import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
const root = join(dirname(fileURLToPath(import.meta.url)), '..');

const T = {
	de: {
		tab: 'Beiträge', mitglieder: 'Mitglieder', einlesen: 'Liste einlesen',
		dialog: 'Mitgliederliste einlesen', pruefen: 'Prüfen', uebernehmen: 'Zeilen übernehmen',
		bestaetigenTitel: 'Mitglieder übernehmen', bestaetigen: 'Übernehmen',
		schliessen: 'Schließen', einzug: 'Einzug', erzeugen: 'Einzug erzeugen',
		xml: 'XML herunterladen', verbuchen: 'Als ausgeführt verbuchen',
		verbuchenBestaetigen: 'Verbuchen',
	},
	en: {
		tab: 'Contributions', mitglieder: 'Members', einlesen: 'Import list',
		dialog: 'Import a member list', pruefen: 'Check', uebernehmen: 'Import',
		bestaetigenTitel: 'Import members', bestaetigen: 'Apply',
		schliessen: 'Close', einzug: 'Collection', erzeugen: 'Create collection',
		xml: 'Download XML', verbuchen: 'Mark as collected',
		verbuchenBestaetigen: 'Post',
	},
};

export async function prepare(ctx) {
	const t = T[ctx.lang];
	await ctx.click({ text: t.tab, tag: '.vbh-tabs button', exact: true }, { move: false, settle: 1500 });
	await ctx.click({ text: t.mitglieder, tag: 'button', exact: true }, { move: false, settle: 1200 });
	await ctx.evaluate('window.scrollTo(0, 0), true');
}

export async function record(ctx) {
	const t = T[ctx.lang];
	await ctx.lowerThird(ctx.scene.lowerThird, { hold: 5.5 });

	// Die Liste steht schon da: 75 Mitglieder, 68 mit Mandat.
	await ctx.until(1.6);
	await ctx.scrollTo(260, { duration: 1600 });

	// CSV-Import der Neuzugaenge.
	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Mitgliederliste' : 'member'));
	await ctx.evaluate('window.scrollTo(0, 0), true');
	await ctx.click({ text: t.einlesen, tag: 'button' });
	await ctx.waitFor(`__vbhVisibleText('.modal-container').includes(${JSON.stringify(t.dialog)})`);
	await ctx.setFile(join(root, 'build', ctx.lang, 'mitglieder-neu.csv'));
	await ctx.sleep(500);
	await ctx.click({ text: t.pruefen, tag: '.modal-container button' });
	await ctx.waitFor(`/\\b12\\b/.test(__vbhVisibleText('.modal-container'))`, 15000);

	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Voreinstellung' : 'default') - 0.4);
	await ctx.callout(ctx.scene.callouts[0].text, { hold: 4.5 });

	// "12 Zeilen übernehmen" fragt noch einmal nach - die Bestaetigung gehoert
	// mit ins Bild, sie zeigt schwarz auf weiss, was gleich entsteht.
	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Fällige' : 'due'));
	await ctx.click({ text: t.uebernehmen, tag: '.modal-container button' });
	await ctx.waitFor(`__vbhVisibleText('.modal-container, .dialog').includes(${JSON.stringify(t.bestaetigenTitel)})`, 10000);
	await ctx.sleep(900);
	await ctx.click({ text: t.bestaetigen, tag: 'button', exact: true });
	await ctx.waitFor(`/${ctx.lang === 'de' ? 'angelegt' : 'created|angelegt'}/i.test(__vbhVisibleText('.modal-container'))`, 20000);
	await ctx.sleep(900);
	await ctx.closeModal();
	await ctx.sleep(500);

	// Sammeleinzug: Vorschau, erzeugen, Datei, verbuchen.
	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Stichtag' : 'collection day'));
	await ctx.click({ text: t.einzug, tag: 'button', exact: true });
	await ctx.sleep(1200);
	await ctx.click({ text: t.erzeugen, tag: 'button, a' });
	await ctx.waitFor(`__vbhVisibleText('main, .vbh').includes(${JSON.stringify(t.xml)})`, 20000);

	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Hausbank' : 'your bank') - 1.2);
	// Die Aktionsspalte der Einzugstabelle ragt ueber den sichtbaren Bereich
	// hinaus (der Verbuchen-Knopf endet bei 1953 von 1920 Bildpunkten) - ohne
	// dieses Freilegen sind XML-Download und Verbuchen-Knopf angeschnitten.
	await ctx.reveal({ text: t.xml, tag: 'button, a' });
	await ctx.callout(ctx.scene.callouts[1].text, { hold: 5 });
	// Auf den Download zeigen, aber nicht klicken: ein echter Dateidownload
	// bringt in der Aufnahme nur ein Systemfenster.
	await ctx.moveTo({ text: t.xml, tag: 'button, a' });
	await ctx.pulse();

	// Verbuchen fragt nach - die Bestaetigung gehoert dazu, sie nennt die Zahl
	// der Posten, die dabei geschlossen werden.
	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Klick' : 'click'));
	await ctx.click({ text: t.verbuchen, tag: 'button, a' });
	await ctx.sleep(900);
	await ctx.click({ text: t.verbuchenBestaetigen, tag: 'button', exact: true });
	await ctx.sleep(1400);
}
