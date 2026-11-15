// Block 07: Abspann. Leere App, der Einrichtungsassistent bietet den
// Beispielverein an (Zoomziel), ein Klick, die Beispieldaten erscheinen.
// Die Endkarte (Claim, App Store, Repo) legt der Film darueber.
//
// record.mjs leert den Bestand vorher und stellt den Demobestand danach wieder
// her (needsEmptyData).

export const needsEmptyData = true;

const T = {
	de: { demo: 'Erst mit Beispieldaten ausprobieren', aktiv: 'Beispieldaten aktiv.', cueKlick: 'Klick', cueBeispiel: 'Beispielverein', cueAn: 'an' },
	en: { demo: 'Try it out with sample data first', aktiv: 'Sample data active.', cueKlick: 'click', cueBeispiel: 'sample', cueAn: 'club' },
};

export async function prepare(ctx) {
	// Der Assistent erscheint nur einmal je Browser; fuer die Aufnahme zuruecksetzen.
	await ctx.goto('/apps/vereinsbuchhaltung/', null, {
		ready: `(localStorage.removeItem('vbh_setup_wizard_seen'), true)`,
		settle: 100,
	});
	await ctx.goto('/apps/vereinsbuchhaltung/', null, {
		ready: `document.querySelectorAll('.vbh-wizard-choice').length > 1`,
		settle: 900,
	});
}

export async function record(ctx) {
	const t = T[ctx.lang];
	ctx.shot('assistent');

	await ctx.until(ctx.cueAt(t.cueKlick) - 0.5);
	await ctx.moveTo({ text: t.demo, tag: 'button.vbh-wizard-choice' });
	await ctx.until(ctx.cueAt(t.cueBeispiel) - 0.3);
	await ctx.mark('beispielverein', { text: t.demo, tag: 'button.vbh-wizard-choice' });
	await ctx.until(ctx.cueAt(t.cueAn) + 0.2);
	await ctx.click({ text: t.demo, tag: 'button.vbh-wizard-choice' }, { settle: 300 });
	// Auf die Beispieldaten warten, nicht auf eine feste Zeit.
	await ctx.waitFor(`__vbhVisibleText('body').includes(${JSON.stringify(t.aktiv)})`, 30000);
	ctx.shot('beispieldaten');
	await ctx.evaluate(`(() => { const o = window.__vbhOverlay; if (o && o.cursor) o.cursor.style.opacity = '0'; return true; })()`);
}
