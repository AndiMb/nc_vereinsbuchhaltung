// Szene 00: Titelkarte.
//
// Der Zoom laeuft im Browser (CSS), nicht in ffmpeg - langsame Fahrten ruckeln
// dort, weil zoompan auf ganze Pixel rundet.

export async function prepare(ctx) {
	// Karte steht schon, bevor die Aufnahme beginnt: so ist Bild 1 fertig
	// aufgebaut und es blitzt keine halbe App durch.
	await ctx.showCard({
		title: ctx.content.cards.title,
		subtitle: ctx.content.cards.subtitle,
	});
}

export async function record(ctx) {
	// Am Ende auf die App aufblenden - der Schnitt in Szene 01 wird dadurch
	// weich, ohne dass die Montage etwas dazutun muss.
	await ctx.until(ctx.duration - 0.7);
	await ctx.hideCard({ duration: 600 });
}
