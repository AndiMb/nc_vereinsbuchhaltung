// Szene 06: Kassenbericht für die Mitgliederversammlung, Lesezugang für die
// Kassenprüfung, Jahresabschluss.
//
// Der Kassenbericht ist eine eigene Druckseite. Sie laeuft hier in einem Rahmen
// ueber der App statt in einem zweiten Tab: die Bildschirmaufnahme haengt an
// genau einem Tab, ein Wechsel wuerde sie abschneiden.

const T = {
	de: { tab: 'Berichte', jahr: 2026 },
	en: { tab: 'Reports', jahr: 2026 },
};

export async function prepare(ctx) {
	const t = T[ctx.lang];
	await ctx.click({ text: t.tab, tag: '.vbh-tabs button', exact: true }, { move: false, settle: 1600 });
	await ctx.evaluate('window.scrollTo(0, 0), true');
}

export async function record(ctx) {
	await ctx.lowerThird(ctx.scene.lowerThird, { hold: 5 });

	// Kassenbericht aufziehen und langsam durchfahren.
	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Kassenbericht' : 'report'));
	const url = `/apps/vereinsbuchhaltung/api/export/kassenbericht?year=${T[ctx.lang].jahr}`;
	await ctx.showFrame(url, { mode: 'page', inline: true });
	await ctx.scrollFrame(700, { duration: 3200 });

	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Unterschriftszeilen' : 'signature'));
	await ctx.scrollFrame(1900, { duration: 3000 });

	// Kassenprüfung: Rolle und Belege.
	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Lesezugang' : 'read-only') - 0.4);
	await ctx.callout(ctx.scene.callouts[0].text, { hold: 5 });
	await ctx.scrollFrame(3200, { duration: 2600 });

	// Jahresabschluss: der Prüfleitfaden zeigt, was danach festgeschrieben ist.
	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Jahresabschluss' : 'year-end'));
	await ctx.hideFrame({ duration: 350 });
	await ctx.showFrame('/apps/vereinsbuchhaltung/api/help/pruefleitfaden', { mode: 'page', inline: true });
	await ctx.scrollFrame(600, { duration: 2600 });
}
