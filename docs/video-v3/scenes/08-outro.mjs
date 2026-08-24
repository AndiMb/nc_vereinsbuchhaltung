// Szene 08: Abspann. Beispielverein-Hinweis und Aufruf.

export async function prepare(ctx) {
	await ctx.evaluate('window.scrollTo(0, 0), true');
}

export async function record(ctx) {
	// Erst noch kurz die App, dann die Karte darueber - so endet das Video nicht
	// mit einem harten Schnitt ins Schwarze.
	await ctx.until(1.8);
	await ctx.showCard({
		title: ctx.content.cards.outroTitle,
		subtitle: null,
		lines: ctx.content.cards.outroLines,
	});
}
