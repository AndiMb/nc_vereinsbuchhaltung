// Szene 01: Die Uebersicht - Kennzahlen, Geldkonten, letzte Buchungen, Diagramm.
//
// Bewusst ohne Seitenwechsel: die Aufnahme haengt an einem Tab, eine echte
// Navigation mitten in der Szene wuerde Bildstrom und Overlay verlieren. Dass
// alles in der eigenen Nextcloud liegt, tragen der Kopfbereich und die
// Titelkarte davor.
//
// Und bewusst ohne Zoom: die App rendert bei 1536 CSS-Pixeln ohnehin gross
// genug, ein Ganzseiten-Zoom wuerde nur den Nextcloud-Rahmen aus dem Bild
// schieben. Gezoomt wird spaeter nur da, wo es auf eine einzelne Zahl ankommt.

const T = {
	de: {
		tab: 'Übersicht', ergebnis: 'Ergebnis', geldkonten: 'Geldkonten', journal: 'Letzte Buchungen',
		// Wortanker im Sprechtext - sie muessen dort genau einmal vorkommen.
		cueKonten: 'Geldkonten', cueJournal: 'Buchungen', cueRollen: 'Vorstand',
	},
	en: {
		tab: 'Overview', ergebnis: 'Result', geldkonten: 'Cash/bank accounts', journal: 'Recent entries',
		cueKonten: 'bank accounts', cueJournal: 'latest entries', cueRollen: 'Board',
	},
};

export async function prepare(ctx) {
	const t = T[ctx.lang];
	await ctx.click({ text: t.tab, tag: '.vbh-tabs button', exact: true }, { move: false, settle: 900 });
	await ctx.evaluate('window.scrollTo(0, 0), true');
}

export async function record(ctx) {
	const t = T[ctx.lang];
	await ctx.lowerThird(ctx.scene.lowerThird, { hold: 5 });

	// Kennzahlen stehen oben - der Cursor geht einmal hin, das Bild bleibt ruhig.
	await ctx.until(2.0);
	await ctx.moveTo({ text: t.ergebnis, tag: 'div' });

	// Geldkonten.
	await ctx.until(ctx.cueAt(t.cueKonten));
	await ctx.scrollTo({ text: t.geldkonten, tag: 'h4' }, { duration: 1600 });

	// Journal.
	await ctx.until(ctx.cueAt(t.cueJournal));
	await ctx.scrollTo({ text: t.journal, tag: 'h4' }, { duration: 1700 });

	// Rollen: die Aussage steckt im Sprechtext, im Bild laeuft das Diagramm.
	await ctx.until(ctx.cueAt(t.cueRollen) - 0.3);
	await ctx.callout(ctx.scene.callouts[0].text, { hold: 5 });
	await ctx.scrollTo(980, { duration: 2400 });
}
