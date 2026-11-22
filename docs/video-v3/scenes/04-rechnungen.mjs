// Block 04: Rechnungen und Auslagen.
//
// Vorstandsbrowser: Rechnungsliste -> Rechnung mit E-Rechnung (ZUGFeRD,
// EN 16931) -> die echte PDF-Seite mit GiroCode -> ueberfaellige Rechnung mit
// Zahlungserinnerung. Mitgliederbrowser: Mia reicht eine Auslage mit Beleg ein.
// Vorstandsbrowser: die Auslage ist da, Freigeben -> Ueberweisung vorbereiten
// (GiroCode) -> Ueberweisung abgeschlossen.
//
// Die PDF-Seite: der Headless-Browser zeigt keine PDFs an. prepare() laedt die
// Rechnung ueber die App und rendert Seite 1 als Bild (lib/pdfpage.mjs); die
// Szene legt das Bild ueber die Oberflaeche. Der GiroCode-Kasten wird im PDF
// gesucht, das Zoomziel liegt genau darauf.
//
// Das Auslagenformular fuellt prepare() vor; im Bild kommen nur Beleg,
// Erklaerung und "Einreichen" - der Sprechtext laesst dafuer gut 5 Sekunden.

import { writeFileSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { renderPdfPage } from '../lib/pdfpage.mjs';
import { textPdf } from '../../../tests/e2e/fixtures/textPdf.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');

export const needsFreshData = true;
export const needsMember = true;
// Mias Einreichen braucht eine Server-Runde und dauert bis etwa 15,4 s; die
// Freigabe des Vorstands (Freigeben -> vorbereiten -> abgeschlossen) laeuft
// deshalb rund 3 s hinter dem Sprechtext und braucht den laengeren Ausklang,
// sonst endet der Block mit dem Zeiger auf "Freigeben".
export const tail = 6.0;

const T = {
	de: {
		rechnungen: 'Rechnungen', buchungen: 'Buchungen', alle: 'Alle Buchungen', auslagen: 'Auslagen', rechnung: 'RE-2026-0001',
		zahlungserinnerung: 'Zahlungserinnerung', girocodeText: 'Überweisen per Code',
		einreichenDialog: 'Auslage einreichen', einreichen: 'Einreichen', freigeben: 'Freigeben',
		vorbereiten: 'Überweisung vorbereiten', abgeschlossen: 'Überweisung abgeschlossen',
		auslage: { betrag: '18,50', nummer: 'MW-55120', laden: 'Musikhaus Weber', zweck: 'Stimmgabel für die Probenarbeit' },
		beleg: ['Musikhaus Weber', 'Rechnung MW-55120', 'Stimmgabel a1 440 Hz', 'Summe 18,50 EUR', 'Bar bezahlt'],
		cueERechnung: 'E-Rechnung', cueGiroCode: 'GiroCode', cueMahnung: 'Mahnung', cueUmgekehrt: 'Umgekehrt',
		belegName: 'Quittung_Musikhaus_Weber.pdf',
		cueBeleg: 'Beleg', cueEin: 'selbst', cueVorstand: 'Vorstand', cueFrei: 'frei', cueErstattet: 'erstattet', cueBuchung: 'Buchung',
	},
	en: {
		rechnungen: 'Invoices', buchungen: 'Entries', alle: 'All entries', auslagen: 'Expenses', rechnung: 'RE-2026-0001',
		zahlungserinnerung: 'Payment reminder', girocodeText: 'Pay by code',
		einreichenDialog: 'Submit expense claim', einreichen: 'Submit', freigeben: 'Approve',
		vorbereiten: 'Prepare transfer', abgeschlossen: 'Transfer completed',
		auslage: { betrag: '18.50', nummer: 'MW-55120', laden: 'Weber Music Shop', zweck: 'Tuning fork for rehearsals' },
		beleg: ['Weber Music Shop', 'Invoice MW-55120', 'Tuning fork A 440 Hz', 'Total 18.50 EUR', 'Paid in cash'],
		cueERechnung: 'e-invoicing', cueGiroCode: 'GiroCode', cueMahnung: 'reminder', cueUmgekehrt: 'other',
		belegName: 'receipt_weber_music.pdf',
		cueBeleg: 'receipt', cueEin: 'themselves', cueVorstand: 'board', cueFrei: 'approves', cueErstattet: 'reimburses', cueBuchung: 'entry',
	},
};

/** Hoehe der PDF-Seite im Bild (CSS-Pixel). */
const SEITE_HOEHE = 1010;

// Im Seitenkontext: Wert setzen wie eine Eingabe (Vue hoert auf input).
const SET_VALUE = `(el, v) => {
	const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
	Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, v);
	el.dispatchEvent(new Event('input', { bubbles: true }));
	el.dispatchEvent(new Event('change', { bubbles: true }));
}`;

export async function prepare(ctx) {
	const t = T[ctx.lang];
	const m = ctx.member;
	const dir = join(root, 'build', ctx.lang, 'szene04');
	mkdirSync(dir, { recursive: true });

	// Rechnungs-PDF ueber die App holen (gleiche Sitzung wie die Oberflaeche).
	const b64 = await ctx.evaluate(`(async () => {
		const base = OC.generateUrl('/apps/vereinsbuchhaltung/api');
		const list = await (await fetch(base + '/invoices', { headers: { requesttoken: OC.requestToken } })).json();
		const arr = Array.isArray(list) ? list : (list.invoices || list.items || []);
		const inv = arr.find((i) => i.number === ${JSON.stringify(t.rechnung)});
		const buf = await (await fetch(base + '/invoices/' + inv.id + '/pdf', { headers: { requesttoken: OC.requestToken } })).arrayBuffer();
		let s = ''; const u = new Uint8Array(buf);
		for (let i = 0; i < u.length; i += 0x8000) s += String.fromCharCode.apply(null, u.subarray(i, i + 0x8000));
		return btoa(s);
	})()`);
	const pdf = join(dir, 'rechnung.pdf');
	const png = join(dir, 'rechnung.png');
	writeFileSync(pdf, Buffer.from(b64, 'base64'));
	const info = renderPdfPage(pdf, png, { dpi: 200, findText: t.girocodeText });
	if (!info.box) throw new Error(`"${t.girocodeText}" nicht im Rechnungs-PDF gefunden`);
	ctx.pdfPage = { png, ...info };

	// Beleg fuer Mias Auslage.
	ctx.belegDatei = join(dir, 'beleg-auslage.pdf');
	writeFileSync(ctx.belegDatei, textPdf(t.beleg));

	// Vorstand: Rechnungsliste.
	await ctx.click({ text: t.rechnungen, tag: '.vbh-tabs button', exact: true }, { move: false, settle: 1200 });
	await ctx.evaluate('window.scrollTo(0, 0), true');

	// Mitglied: Auslagen, Formular offen und vorausgefuellt.
	await m.click({ text: t.auslagen, tag: 'button, [role=tab]', exact: true }, { move: false, settle: 800 });
	await m.click({ text: t.einreichenDialog, tag: 'button' }, { move: false, settle: 900 });
	await m.waitFor(`__vbhVisible('.modal-container input').length > 4`);
	const a = t.auslage;
	await m.evaluate(`(() => {
		const set = ${SET_VALUE};
		const felder = Array.from(document.querySelectorAll('.modal-container input')).filter(e => e.getClientRects().length > 0 && e.type !== 'file' && e.type !== 'checkbox');
		set(felder[0], ${JSON.stringify(a.betrag)});
		set(felder[2], ${JSON.stringify(a.nummer)});
		set(felder[3], ${JSON.stringify(a.laden)});
		set(felder[4], ${JSON.stringify(a.zweck)});
		document.querySelector('.modal-container .modal-container__content, .modal-container')?.scrollTo?.(0, 0);
		return true;
	})()`);
}

export async function record(ctx) {
	const t = T[ctx.lang];
	const m = ctx.member;
	ctx.shot('rechnungen');

	// Rechnung oeffnen: E-Rechnung (ZUGFeRD) steht unter Dokumente.
	await ctx.until(1.6);
	await ctx.click({ text: t.rechnung, tag: 'td', exact: true }, { settle: 900 });
	await ctx.waitFor(`__vbhVisible('.modal-container').length > 0`);
	await ctx.until(ctx.cueAt(t.cueERechnung) - 0.2);
	await ctx.moveTo({ text: 'EN 16931', tag: '.modal-container *' });

	// GiroCode: die echte PDF-Seite.
	await ctx.until(ctx.cueAt(t.cueGiroCode) - 0.7);
	ctx.shot('pdf');
	const { png, page, box } = ctx.pdfPage;
	const skala = SEITE_HOEHE / page.h;
	const breite = Math.round(page.w * skala);
	const bild = (await import('node:fs')).readFileSync(png).toString('base64');
	await ctx.evaluate(`(() => {
		const huelle = document.createElement('div');
		huelle.id = 'vbh-video-pdf';
		Object.assign(huelle.style, { position: 'fixed', inset: '0', zIndex: '99000', background: 'rgba(6,16,32,.78)', opacity: '0', transition: 'opacity 380ms ease-out' });
		const blatt = document.createElement('div');
		const links = Math.round((innerWidth - ${breite}) / 2), oben = Math.round((innerHeight - ${SEITE_HOEHE}) / 2);
		Object.assign(blatt.style, { position: 'absolute', left: links + 'px', top: oben + 'px', width: '${breite}px', height: '${SEITE_HOEHE}px', boxShadow: '0 30px 80px rgba(0,0,0,.5)', background: '#fff' });
		const img = document.createElement('img');
		img.src = 'data:image/png;base64,${bild}';
		Object.assign(img.style, { width: '100%', height: '100%', display: 'block' });
		const ziel = document.createElement('div');
		ziel.id = 'vbh-video-girocode';
		Object.assign(ziel.style, { position: 'absolute', left: '${Math.round(box.x * skala)}px', top: '${Math.round(box.y * skala)}px', width: '${Math.round(box.w * skala)}px', height: '${Math.round(box.h * skala)}px', pointerEvents: 'none' });
		blatt.append(img, ziel);
		huelle.appendChild(blatt);
		document.body.appendChild(huelle);
		requestAnimationFrame(() => { huelle.style.opacity = '1'; });
		return true;
	})()`);
	await ctx.waitFor(`(() => { const i = document.querySelector('#vbh-video-pdf img'); return i && i.complete && i.naturalWidth > 0; })()`);
	await ctx.sleep(450);
	await ctx.mark('girocode', '#vbh-video-girocode');
	await ctx.moveTo('#vbh-video-girocode');

	// Wer nicht zahlt, bekommt eine Mahnung: die ueberfaellige Rechnung.
	// Der Wechsel laeuft hinter dem stehenden PDF-Bild an (der Dialog schliesst
	// sich mit Cursorweg gut 1,5 s) - die Liste steht dann zur "Mahnung".
	await ctx.until(Math.max(ctx.cueAt(t.cueGiroCode) + 1.4, ctx.cueAt(t.cueMahnung) - 1.9));
	await ctx.behindCut('mahnung', async () => {
		await ctx.evaluate(`(() => { document.getElementById('vbh-video-pdf')?.remove(); return true; })()`);
		await ctx.closeModal();
		await ctx.waitFor(`__vbhVisible('.modal-container').length === 0`, 5000);
		await ctx.sleep(200);
	});
	await ctx.moveTo({ text: t.zahlungserinnerung, tag: 'tbody *' });

	// Umgekehrt: Mia reicht ihre Auslage ein.
	await ctx.until(ctx.cueAt(t.cueUmgekehrt) - 0.3);
	m.shot('auslage');
	// Waehrend Mias Bild laeuft, geht der Vorstandsbrowser schon zu den Auslagen
	// (unsichtbar, eigener Tab) - danach ist nur noch Neuladen noetig.
	const vorstandBereit = (async () => {
		await ctx.click({ text: t.buchungen, tag: '.vbh-tabs button', exact: true }, { move: false, settle: 400 });
		await ctx.click({ text: t.auslagen, tag: 'button', exact: true }, { move: false, settle: 300 });
	})();
	// Mias Schritte laufen gleich zu Beginn ihrer Einstellung: das Einreichen
	// braucht eine Server-Runde, und der Vorstand soll die Auslage zur Zeile
	// "Der Vorstand ..." schon in der Liste haben.
	await m.sleep(250);
	// Den Beleg sichtbar in das Formular ziehen (kuerzer als im Import).
	await m.dragFile(ctx.belegDatei, '.modal-container .vbh-receipts-drop', { label: t.belegName, duration: 900 });
	await m.waitFor(`__vbhVisibleText('.modal-container').includes(${JSON.stringify(t.belegName)})`, 10000);
	await m.evaluate(`(() => { const c = document.querySelector('.modal-container input[type=checkbox]'); c.scrollIntoView({ block: 'center', behavior: 'smooth' }); return true; })()`);
	await m.sleep(500);
	await m.click('.modal-container input[type=checkbox]', { settle: 300 });
	await m.click({ text: t.einreichen, tag: '.modal-container button', exact: true }, { settle: 300 });
	await m.waitFor(`__vbhVisible('.modal-container').length === 0`, 15000);

	// Der Vorstand gibt sie frei - im Vorstandsbrowser ist die Auslage da.
	await ctx.until(ctx.cueAt(t.cueVorstand) - 0.6);
	await vorstandBereit;
	await ctx.behindCut('freigabe', async () => {
		// Hinter dem Schnitt ohne Cursorweg: Reiter kurz verlassen und zurueck
		// (die Auslagenliste laedt beim Oeffnen), dann die neue Auslage oeffnen.
		const klick = (text, tag) => ctx.evaluate(`(() => {
			const el = __vbhVisible(${JSON.stringify(tag)}).filter(e => e.textContent.trim() === ${JSON.stringify(text)})[0];
			if (el) el.click();
			return !!el;
		})()`);
		await klick(t.alle, 'button');
		await ctx.sleep(120);
		await klick(t.auslagen, 'button');
		// Auf das Eintreffen warten, nicht auf eine feste Zeit (bis 20 s).
		await ctx.waitFor(`__vbhVisibleText('body').includes(${JSON.stringify(t.auslage.zweck)})`, 20000);
		await ctx.evaluate(`(() => {
			const z = __vbhVisible('tbody tr').find(e => e.textContent.includes(${JSON.stringify(t.auslage.zweck)}));
			(z.querySelector('td') || z).click();
			return true;
		})()`);
		await ctx.waitFor(`__vbhVisible('.modal-container button').some(b => b.textContent.trim() === ${JSON.stringify(t.freigeben)})`);
		await ctx.sleep(250);
	});
	await ctx.until(ctx.cueAt(t.cueFrei) - 0.4);
	await ctx.click({ text: t.freigeben, tag: '.modal-container button', exact: true }, { settle: 600 });
	await ctx.waitFor(`__vbhVisible('.modal-container button').some(b => b.textContent.includes(${JSON.stringify(t.vorbereiten)}))`, 10000);

	// ... erstattet: Ueberweisung mit GiroCode, dann abgeschlossen.
	await ctx.until(ctx.cueAt(t.cueErstattet) - 0.3);
	await ctx.click({ text: t.vorbereiten, tag: '.modal-container button' }, { settle: 600 });
	await ctx.waitFor(`__vbhVisible('.modal-container button').some(b => b.textContent.includes(${JSON.stringify(t.abgeschlossen)}))`, 10000);
	await ctx.until(ctx.cueAt(t.cueBuchung) - 0.3);
	await ctx.click({ text: t.abgeschlossen, tag: '.modal-container button' }, { settle: 600 });
}
