// Block 06: Mitgliederversammlung und Pruefung.
//
// App: Kassenbericht als Rahmen ueber der App (srcdoc, die Druckseite verbietet
// das Einbetten). Dann hinter einem Schnitt in die Verwaltungseinstellungen:
// Berechtigungen mit der Revisorin (Zoomziel), danach das Geschaeftsjahr - der
// Abschluss-Dialog wird geoeffnet, aber nicht bestaetigt.
//
// Das Beleg-ZIP sitzt im Exportmenue der App; ein dritter Seitenwechsel passt
// nicht in den Sprechtext (EN 11 s) - der Sprechtext nennt es, das Bild bleibt
// auf der Rolle.

const SETTINGS = '/settings/admin/vereinsbuchhaltung';

const T = {
	de: {
		berichte: 'Berichte', kassenbericht: 'Kassenbericht', berechtigungen: 'Berechtigungen',
		geschaeftsjahr: 'Geschäftsjahr', abschliessen: 'Abschließen', vorjahr: '2025',
		cueBericht: 'Kassenbericht', cueKnopf: 'Knopfdruck', cuePruefung: 'Kassenprüfung',
		cueZugang: 'Lesezugang', cueDanach: 'Danach', cueAbschluss: 'Jahresabschluss',
	},
	en: {
		berichte: 'Reports', kassenbericht: "Treasurer's report", berechtigungen: 'Permissions',
		geschaeftsjahr: 'Fiscal year', abschliessen: 'Close', vorjahr: '2025',
		cueBericht: "treasurer's", cueKnopf: 'click', cuePruefung: 'auditors',
		cueZugang: 'access', cueDanach: 'Then', cueAbschluss: 'year-end',
	},
};

export async function prepare(ctx) {
	const t = T[ctx.lang];
	await ctx.click({ text: t.berichte, tag: '.vbh-tabs button', exact: true }, { move: false, settle: 1600 });
	await ctx.evaluate('window.scrollTo(0, 0), true');
	ctx.kassenberichtUrl = await ctx.evaluate(`(() => {
		const a = __vbhVisible('a').find(e => /kassenbericht/i.test(e.getAttribute('href') || ''));
		return a ? new URL(a.href).pathname + new URL(a.href).search : null;
	})()`);
	if (!ctx.kassenberichtUrl) throw new Error('Kassenbericht-Link nicht gefunden');
}

export async function record(ctx) {
	const t = T[ctx.lang];
	ctx.shot('bericht');

	// Kassenbericht auf Knopfdruck.
	await ctx.until(0.9);
	await ctx.moveTo({ text: t.kassenbericht, tag: 'a', exact: true });
	await ctx.until(ctx.cueAt(t.cueBericht) - 0.5);
	await ctx.pulse();
	await ctx.showFrame(ctx.kassenberichtUrl, { mode: 'page', inline: true });
	await ctx.scrollFrame(650, { duration: 1500 });

	// Kassenpruefung: Lesezugang in den Berechtigungen.
	// Der Seitenwechsel braucht gut 2 s hinter dem Schnitt: frueh anfangen, aber
	// den Bericht mindestens 2,2 s stehen lassen.
	await ctx.until(Math.max(ctx.cueAt(t.cueBericht) + 2.2, ctx.cueAt(t.cuePruefung) - 2.4));
	await ctx.goto(SETTINGS, 'rechte', {
		ready: `!!Array.from(document.querySelectorAll('h2')).find(h => h.textContent.includes(${JSON.stringify(t.berechtigungen)}))`,
		settle: 300,
	});
	await ctx.scrollTo({ text: t.berechtigungen, tag: 'h2' }, { duration: 1, offset: 70 });
	await ctx.sleep(250);
	await ctx.evaluate(`(() => {
		const tr = __vbhVisible('tr').find(e => e.textContent.includes('karla'));
		tr.id = 'vbh-video-revisor';
		return true;
	})()`);
	await ctx.until(ctx.cueAt(t.cueZugang) - 0.6);
	await ctx.moveTo({ text: 'karla', tag: 'td' });
	await ctx.mark('revisor', '#vbh-video-revisor');

	// Jahresabschluss: Geschaeftsjahr, Abschliessen-Dialog oeffnen.
	await ctx.until(ctx.cueAt(t.cueDanach) - 0.2);
	await ctx.evaluate(`(() => {
		const z = __vbhVisible('tr').find(e => e.textContent.includes(${JSON.stringify(t.vorjahr)}) && Array.from(e.querySelectorAll('button')).some(b => b.textContent.trim() === ${JSON.stringify(t.abschliessen)}));
		if (z) z.id = 'vbh-video-abschluss';
		return !!z;
	})()`);
	await ctx.scrollTo('#vbh-video-abschluss', { duration: 1100, offset: 380 });
	await ctx.until(ctx.cueAt(t.cueAbschluss) - 0.3);
	await ctx.click({ text: t.abschliessen, tag: '#vbh-video-abschluss button', exact: true }, { settle: 300 });
	await ctx.waitFor(`__vbhVisible('.modal-container, .dialog').length > 0`, 5000);
	await ctx.sleep(1200);
}
