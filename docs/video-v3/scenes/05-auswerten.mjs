// Szene 05: Berichte, Finanzplan, Sphären.

const T = {
	de: { tab: 'Berichte', auswertung: 'Auswertung', finanzplan: 'Finanzplan', sphaeren: 'Sphären' },
	en: { tab: 'Reports', auswertung: 'Summary', finanzplan: 'Budget', sphaeren: 'Spheres' },
};

export async function prepare(ctx) {
	const t = T[ctx.lang];
	await ctx.click({ text: t.tab, tag: '.vbh-tabs button', exact: true }, { move: false, settle: 1800 });
	// Den Finanzplan schon vor der Aufnahme einmal holen. Die Zahlen kommen erst
	// beim Wechsel auf den Reiter, und in der laufenden Aufnahme blieb die
	// Flaeche dadurch gelegentlich leer. Einmal geladen, bleiben sie im Zustand
	// der App stehen - der Klick in der Aufnahme zeigt sie dann sofort.
	await ctx.click({ text: t.finanzplan, tag: 'button', exact: true }, { move: false, settle: 2500 });
	await ctx.click({ text: t.auswertung, tag: 'button', exact: true }, { move: false, settle: 1200 });
	await ctx.evaluate('window.scrollTo(0, 0), true');
}

export async function record(ctx) {
	const t = T[ctx.lang];
	await ctx.lowerThird(ctx.scene.lowerThird, { hold: 5 });

	// Mehrjahres-Trend und Saldenliste im Durchlauf.
	await ctx.until(1.5);
	await ctx.scrollTo(360, { duration: 2000 });
	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Saldenliste' : 'trial'));
	await ctx.scrollTo(980, { duration: 2400 });

	// Finanzplan: Budget und Ist nebeneinander.
	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Finanzplan' : 'financial plan'));
	await ctx.evaluate('window.scrollTo(0, 0), true');
	await ctx.click({ text: t.finanzplan, tag: 'button', exact: true });
	// Der Finanzplan holt seine Zahlen erst beim Wechsel auf den Reiter. In der
	// Aufnahme kam die Antwort gelegentlich nicht an - im Bild stand dann nur die
	// Ueberschrift ueber einer leeren Flaeche. Deshalb: auf die Ueberschrift mit
	// Jahreszahl warten und den Reiter notfalls noch einmal anklicken.
	// Ohne Rueckwaertsschraegstriche geschrieben: die Bedingung wandert als Text
	// durch mehrere Ebenen, eine Zeichenklasse wie \d ueberlebt das nicht.
	const geladen = `__vbhVisible('h4').some((e) => /(Finanzplan|Budget)/.test(e.textContent)`
		+ ` && /20[0-9][0-9]/.test(e.textContent))`;
	// Ein zweiter Klick auf denselben Reiter loest *kein* neues Laden aus: die App
	// haengt das Laden an der Zustandsaenderung, und der Zustand steht dann schon
	// auf "Finanzplan". Der Umweg ueber "Auswertung" ist deshalb noetig.
	for (let versuch = 0; versuch < 3; versuch++) {
		if (await ctx.evaluate(geladen)) break;
		await ctx.sleep(1500);
		if (await ctx.evaluate(geladen)) break;
		await ctx.click({ text: t.auswertung, tag: 'button', exact: true }, { move: false, settle: 400 });
		await ctx.click({ text: t.finanzplan, tag: 'button', exact: true }, { move: false, settle: 400 });
	}
	await ctx.waitFor(geladen, 12000);
	await ctx.sleep(600);
	await ctx.scrollTo(320, { duration: 2000 });

	// Sphären samt Freigrenzen-Hinweis.
	await ctx.until(ctx.cueAt(ctx.lang === 'de' ? 'Gemeinnützigkeit' : 'nonprofit') - 0.5);
	await ctx.callout(ctx.scene.callouts[0].text, { hold: 5 });
	await ctx.evaluate('window.scrollTo(0, 0), true');
	await ctx.click({ text: t.sphaeren, tag: 'button', exact: true });
	await ctx.sleep(1200);
	// Ohne Klick auf eine Sphaere bleibt die rechte Haelfte leer ("Sphäre links
	// auswählen") - vier Sekunden halbes Bild im Video. Ueber die Klasse statt
	// ueber den Namen: die Sphaerennamen kommen uebersetzt vom Server.
	await ctx.click('.vbh-treelist .vbh-treenode');
	await ctx.sleep(1200);
	await ctx.scrollTo(240, { duration: 1800 });
}
