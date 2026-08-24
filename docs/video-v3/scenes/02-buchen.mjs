// Szene 02: Eine Buchung im Einfach-Modus erfassen, danach der Blick in den
// Experten-Modus.
//
// Der Betrag wird zeichenweise getippt (ctx.type) - ein gesetzter Wert sieht im
// Video aus wie ein Sprung im Bild.

const T = {
	de: {
		tab: 'Übersicht', neu: 'Buchung', buchen: 'Buchen', journal: 'Letzte Buchungen',
		kategorie: 'Spenden', text: 'Spende Sommerkonzert', schalter: 'Experten',
		// Wortanker im Sprechtext - jeder muss dort genau einmal vorkommen.
		cueArt: 'Einnahme', cueBetrag: 'Betrag', cueKategorie: 'Kategorie', cueFertig: 'fertig',
		cueHintergrund: 'Hintergrund', cueWechsel: 'Wer', cueExperte: 'Experten-Modus',
	},
	en: {
		tab: 'Overview', neu: 'Entry', buchen: 'Book', journal: 'Recent entries',
		kategorie: 'Donations', text: 'Donation, summer concert', schalter: 'Expert',
		cueArt: 'income', cueBetrag: 'amount', cueKategorie: 'category', cueFertig: 'done',
		cueHintergrund: 'background', cueWechsel: 'rather', cueExperte: 'expert mode',
	},
};

export async function prepare(ctx) {
	const t = T[ctx.lang];
	await ctx.click({ text: t.tab, tag: '.vbh-tabs button', exact: true }, { move: false, settle: 800 });
	await ctx.evaluate('window.scrollTo(0, 0), true');
	// Die Kurztour im Buchungsdialog wuerde sich ueber das Formular legen. Sie
	// haengt an einem localStorage-Schluessel, also einmal setzen statt im Bild
	// wegklicken.
	await ctx.evaluate(`(() => { localStorage.setItem('vbh_booking_tour_seen', '1'); return true; })()`);
}

export async function record(ctx) {
	const t = T[ctx.lang];
	const neueBuchung = { text: t.neu, tag: 'button', exact: true };
	await ctx.lowerThird(ctx.scene.lowerThird, { hold: 5 });

	await ctx.until(1.6);
	await ctx.click(neueBuchung);
	await ctx.waitFor('__vbhVisible(".vbh-modal-title").length > 0');

	// Einnahme statt Ausgabe: der Umschalter ist der erste Schritt im Dialog.
	await ctx.until(ctx.cueAt(t.cueArt));
	await ctx.click('.vbh-kindbtn.income');

	await ctx.until(ctx.cueAt(t.cueBetrag));
	await ctx.type('input.vbh-num', '250');

	// Erstes Auswahlfeld im Dialog ist die Kategorie, das zweite das Geldkonto.
	// Ueber die Position statt ueber den Platzhaltertext, der uebersetzt ist.
	await ctx.until(ctx.cueAt(t.cueKategorie));
	await ctx.pickOption('.modal-container input.vs__search', t.kategorie);

	await ctx.until(ctx.cueAt(t.cueFertig) - 1.4);
	// Ueber die Struktur statt ueber den Platzhaltertext: der ist uebersetzt, und
	// im englischen Lauf lief die Suche danach ins Leere. Das Feld traegt kein
	// type-Attribut (input.type liefert trotzdem "text"), deshalb :not([type]) -
	// ein Selektor input[type=text] findet es nicht. Das Belegfeld daneben
	// traegt .vbh-short und faellt damit heraus.
	await ctx.type('.modal-container input:not([type]):not(.vbh-short)', t.text, { delay: 42 });

	await ctx.until(ctx.cueAt(t.cueFertig));
	await ctx.click({ text: t.buchen, tag: 'button', exact: true });
	await ctx.waitFor('__vbhVisible(".vbh-modal-title").length === 0', 10000);

	// Die neue Zeile steht oben im Journal der Uebersicht.
	await ctx.until(ctx.cueAt(t.cueHintergrund));
	await ctx.scrollTo({ text: t.journal, tag: 'h4' }, { duration: 1400 });

	// Experten-Modus zeigen: Dialog erneut oeffnen und umschalten.
	await ctx.until(ctx.cueAt(t.cueWechsel));
	await ctx.evaluate('window.scrollTo(0, 0), true');
	await ctx.click(neueBuchung);
	await ctx.waitFor('__vbhVisible(".vbh-modal-title").length > 0');

	await ctx.until(ctx.cueAt(t.cueExperte) - 0.6);
	await ctx.callout(ctx.scene.callouts[0].text, { hold: 4 });
	await ctx.toggleSwitch(t.schalter);
}
