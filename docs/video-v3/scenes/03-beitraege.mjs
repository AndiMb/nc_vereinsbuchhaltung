// Block 03: Mitglieder und Beitraege.
//
// Mitgliederliste -> Akte von Mia (Mandat elektronisch erteilt, aktiv) ->
// Einzug: Zeitstrahl, Summe des naechsten Laufs, Freigeben & Datei erzeugen ->
// Bankabgleich mit der Ruecklastschrift aus dem Kontoauszug -> Schnitt auf den
// Mitgliederbrowser: "Mein Beitrag".
//
// Der Kontoauszug wird in prepare() ueber die App-API eingespielt (gleicher
// Weg wie der Import-Dialog), damit der Bankabgleich die Ruecklastschrift
// kennt. Freigabe und Import veraendern den Bestand: frische Daten noetig.

import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
const root = join(dirname(fileURLToPath(import.meta.url)), '..');

export const needsFreshData = true;
export const needsMember = true;

const T = {
	de: {
		tab: 'Beiträge', mitglieder: 'Mitglieder', einzug: 'Einzug', abgleich: 'Bankabgleich',
		freigeben: 'Freigeben & Datei erzeugen', mandat: 'SEPA-Mandat', meinBeitrag: 'Mein Beitrag', suche: 'Mia Bran', bestaetigen: 'Bestätigen',
		cueAkte: 'Akte', cueOnline: 'online', cueBeitraege: 'fälligen', cueFrei: 'frei', cueDatei: 'Datei',
		cuePlatzt: 'Platzt', cueMein: 'Mein',
	},
	en: {
		tab: 'Contributions', mitglieder: 'Members', einzug: 'Collection', abgleich: 'Bank reconciliation',
		freigeben: 'Release & create file', mandat: 'SEPA mandate', meinBeitrag: 'My contribution', suche: 'Mia Bran', bestaetigen: 'Confirm',
		cueAkte: 'record', cueOnline: 'online', cueBeitraege: 'knows', cueFrei: 'approve', cueDatei: 'submit',
		cuePlatzt: 'bounces', cueMein: 'My',
	},
};

const visibleButton = (text) => `__vbhVisible('button').some(b => b.textContent.includes(${JSON.stringify(text)}))`;

export async function prepare(ctx) {
	const t = T[ctx.lang];
	const csv = readFileSync(join(root, 'build', ctx.lang, 'kontoauszug.csv'), 'utf8');
	const res = await ctx.evaluate(`(async () => {
		const r = await fetch(OC.generateUrl('/apps/vereinsbuchhaltung/api/import/commit'), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
			body: JSON.stringify({ content: ${JSON.stringify(csv)}, filename: 'kontoauszug.csv', applyRules: true }),
		});
		return r.ok ? (await r.json()).sepaReturnsDetected : -r.status;
	})()`);
	if (res !== 1) throw new Error(`Kontoauszug-Import: ${res} Ruecklastschriften statt 1`);
	await ctx.goto('/apps/vereinsbuchhaltung/', null, { ready: 'typeof OC !== "undefined" && !!document.querySelector(".vbh-tabs")', settle: 800 });
	await ctx.click({ text: t.tab, tag: '.vbh-tabs button', exact: true }, { move: false, settle: 1200 });
	await ctx.click({ text: t.mitglieder, tag: 'button', exact: true }, { move: false, settle: 800 });
	await ctx.evaluate('window.scrollTo(0, 0), true');
	// Mitgliederbrowser: "Mein Bereich" steht schon, nur nach oben.
	await ctx.member.evaluate('window.scrollTo(0, 0), true');
}

export async function record(ctx) {
	const t = T[ctx.lang];
	const m = ctx.member;
	ctx.shot('mitglieder');

	// Jedes Mitglied hat seine Akte: Mia suchen und oeffnen.
	await ctx.until(2.4);
	await ctx.type('input[placeholder*="IBAN"]', t.suche);
	await ctx.until(ctx.cueAt(t.cueAkte) - 0.4);
	await ctx.click({ text: t.suche, tag: 'tbody button, tbody a, button' }, { settle: 900 });
	await ctx.waitFor(`__vbhVisible('.modal-container').length > 0`);

	// ... mit SEPA-Mandat, online erteilt.
	await ctx.until(ctx.cueAt(t.cueOnline) - 1.2);
	await ctx.scrollTo({ text: t.mandat, tag: '.modal-container h3, .modal-container h4, .modal-container strong, .modal-container div' }, { duration: 1300, offset: 60 });

	// Die App kennt die faelligen Beitraege: Einzug mit Zeitstrahl.
	await ctx.until(ctx.cueAt(t.cueBeitraege) - 0.8);
	await ctx.behindCut('einzug', async () => {
		await ctx.closeAllModals();
		await ctx.click({ text: t.einzug, tag: 'button', exact: true }, { move: false, settle: 300 });
		await ctx.waitFor(visibleButton(t.freigeben), 15000);
		await ctx.sleep(500);
	});
	// Die Kennzahlenzeile ist bildbreit; Zoomziel sind nur "Forderungen" und
	// "Summe" - dafuer ein unsichtbarer Rahmen ueber den ersten beiden Werten.
	await ctx.evaluate(`(() => {
		const kids = Array.from(document.querySelector('.vbh-ghost-stats').children).slice(0, 2).map(e => e.getBoundingClientRect());
		const box = document.getElementById('vbh-video-ziel') || document.body.appendChild(Object.assign(document.createElement('div'), { id: 'vbh-video-ziel' }));
		const x = Math.min(...kids.map(r => r.left)) - 12, y = Math.min(...kids.map(r => r.top)) - 8;
		const w = Math.max(...kids.map(r => r.right)) + 12 - x, h = Math.max(...kids.map(r => r.bottom)) + 8 - y;
		Object.assign(box.style, { position: 'fixed', left: x + 'px', top: y + 'px', width: w + 'px', height: h + 'px', pointerEvents: 'none' });
		return true;
	})()`);
	await ctx.moveTo('#vbh-video-ziel');
	await ctx.mark('einzugssumme', '#vbh-video-ziel');

	// Ihr gebt den Einzug frei und reicht die Datei bei der Bank ein.
	await ctx.until(ctx.cueAt(t.cueFrei) - 0.5);
	await ctx.click({ text: t.freigeben, tag: 'button' }, { settle: 700 });
	await ctx.waitFor(`__vbhVisible('.modal-container button').some(b => b.textContent.includes(${JSON.stringify(t.freigeben)}))`);
	await ctx.until(ctx.cueAt(t.cueDatei) - 0.6);
	await ctx.click({ text: t.freigeben, tag: '.modal-container button', nth: 0 }, { settle: 400 });
	await ctx.waitFor(`__vbhVisible('.modal-container').length === 0`, 15000);
	await ctx.sleep(600);

	// Platzt eine Lastschrift: Bankabgleich mit der Ruecklastschrift.
	await ctx.until(ctx.cueAt(t.cuePlatzt) - 0.6);
	await ctx.behindCut('abgleich', async () => {
		await ctx.evaluate('window.scrollTo(0, 0), true');
		await ctx.click({ text: t.abgleich, tag: 'button', exact: true }, { move: false, settle: 300 });
		await ctx.waitFor(`/23[.,]00/.test(__vbhVisibleText('body')) && ${visibleButton(t.abgleich)}`, 15000);
		await ctx.sleep(400);
	});
	await ctx.moveTo({ text: t.bestaetigen, tag: 'button', exact: true });

	// Mein Beitrag im Mitgliederbrowser.
	await ctx.until(ctx.cueAt(t.cueMein) - 0.6);
	m.shot('meinbeitrag');
	await m.moveTo({ text: t.meinBeitrag, tag: 'h1, h2, h3, h4, h5, h6', exact: true });
	// Zoomziel ist die ganze Karte um die Ueberschrift, nicht nur die Zeile.
	await m.evaluate(`(() => {
		const h = __vbhVisible('h1, h2, h3, h4, h5, h6').find(e => e.textContent.trim() === ${JSON.stringify(t.meinBeitrag)});
		let card = h;
		while (card && card.parentElement && card.getBoundingClientRect().height < 150) card = card.parentElement;
		if (card) card.id = 'vbh-video-meinbeitrag';
		return !!card;
	})()`);
	await m.mark('meinbeitrag', '#vbh-video-meinbeitrag');
}
