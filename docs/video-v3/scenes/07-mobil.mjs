// Szene 07: Die Handy-Ansicht.
//
// Der Rahmen ist ein iframe mit 390 Pixel Breite - die App entscheidet ihre
// mobile Ansicht an der Fensterbreite, und im Rahmen ist die echte Breite 390.
// Die Bildschirmaufnahme bleibt dabei bei 1920x1080, ein Wechsel der
// Geraetemetrik mitten in der Szene wuerde die Bildgroesse aendern.

export async function prepare(ctx) {
	await ctx.evaluate('window.scrollTo(0, 0), true');
}

export async function record(ctx) {
	await ctx.lowerThird(ctx.scene.lowerThird, { hold: 5 });

	await ctx.until(0.9);
	await ctx.showFrame('/apps/vereinsbuchhaltung/', { mode: 'phone' });

	// Durch die Karten scrollen.
	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Beleg' : 'receipt'));
	await ctx.scrollFrame(420, { duration: 2400 });

	// In die Buchungen wechseln - untere Navigationsleiste.
	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Smartphone' : 'phone'));
	try {
		await ctx.clickInFrame(ctx.lang === 'de' ? 'Buchungen' : 'Entries', 'button, a');
		await ctx.scrollFrame(300, { duration: 2000 });
	} catch {
		// Die mobile Navigation kann je nach Rolle anders aussehen; dann bleibt
		// es beim Scrollen - kein Grund, die Aufnahme abzubrechen.
		await ctx.scrollFrame(700, { duration: 2200 });
	}
}
