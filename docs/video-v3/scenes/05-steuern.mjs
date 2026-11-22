// Block 05: Planen und Steuern.
//
// Finanzplan mit Plan, Ist und Vorjahr -> Kostenstellen mit Budget je
// Kostenstelle -> Sphaeren mit dem Freigrenzen-Hinweis (Zoomziel) und dem
// wirtschaftlichen Geschaeftsbetrieb im Detail.

export const needsFreshData = false;

const T = {
	de: {
		berichte: 'Berichte', finanzplan: 'Finanzplan', kostenstellen: 'Kostenstellen', sphaeren: 'Sphären',
		wgb: 'Wirtschaftlicher Geschäftsbetrieb',
		cueBudget: 'Budget', cueKostenstelle: 'Kostenstelle', cueGemein: 'Gemeinnützigkeit', cueApp: 'App', cueFreigrenze: 'Freigrenze',
	},
	en: {
		berichte: 'Reports', finanzplan: 'Budget', kostenstellen: 'Cost centres', sphaeren: 'Spheres',
		wgb: 'Commercial trading activity',
		cueBudget: 'budget', cueKostenstelle: 'cost', cueGemein: 'charitable', cueApp: 'app', cueFreigrenze: 'limit',
	},
};

const UNTERREITER = '.vbh-subtabs button, nav button, [role=tab], button';

export async function prepare(ctx) {
	const t = T[ctx.lang];
	await ctx.click({ text: t.berichte, tag: '.vbh-tabs button', exact: true }, { move: false, settle: 1500 });
	await ctx.click({ text: t.finanzplan, tag: UNTERREITER, exact: true }, { move: false, settle: 2000 });
	await ctx.evaluate('window.scrollTo(0, 0), true');
}

export async function record(ctx) {
	const t = T[ctx.lang];
	ctx.shot('finanzplan');

	// Budget und Ist nebeneinander: die drei Kennzahlen, dann die Plan-Spalte.
	await ctx.until(ctx.cueAt(t.cueBudget) - 0.3);
	await ctx.moveTo('thead th:nth-child(4)');

	// ... auch je Kostenstelle.
	await ctx.until(ctx.cueAt(t.cueKostenstelle) - 0.6);
	await ctx.click({ text: t.kostenstellen, tag: UNTERREITER, exact: true }, { settle: 900 });
	ctx.shot('kostenstellen');

	// Gemeinnuetzigkeit: Sphaeren mit Freigrenzen-Hinweis.
	await ctx.until(ctx.cueAt(t.cueGemein) - 0.4);
	await ctx.click({ text: t.sphaeren, tag: UNTERREITER, exact: true }, { settle: 600 });
	ctx.shot('sphaeren');
	await ctx.waitFor(`__vbhVisible('.vbh-freigrenzemini').length > 0`, 10000);
	await ctx.until(ctx.cueAt(t.cueApp) - 0.2);
	await ctx.click({ text: t.wgb, tag: '.vbh-treenode' }, { settle: 400 });
	await ctx.until(ctx.cueAt(t.cueFreigrenze) - 0.5);
	await ctx.mark('freigrenze', '.vbh-freigrenzemini');
	await ctx.moveTo('.vbh-freigrenzemini');
}
