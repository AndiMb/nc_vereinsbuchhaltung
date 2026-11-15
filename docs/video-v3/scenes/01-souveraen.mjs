// Block 01: Eure Daten, eure Cloud.
//
// Nextcloud-Startseite -> App ueber das App-Menue (sichtbar geoeffnet),
// Uebersicht mit dem Aufgaben-Klemmbrett, dann die Handyansicht im Rahmen:
// darin wird gescrollt und auf "Buchungen" getippt. Zum Schluss zurueck am
// Rechner in die Buchungsliste - dort beginnt Block 02.
//
// Bewusst kein Abstecher in die Admin-Einstellungen: Rollen und Sicherung
// kommen als Einblendung (content callouts); die Einstellungen zeigt nur
// Block 06, einmal gebuendelt.

const T = {
	de: {
		appMenu: 'Apps-Menü öffnen', appName: 'Vereinsbuchhaltung', buchungen: 'Buchungen',
		cueNextcloud: 'Nextcloud', cueDaten: 'Daten', cueTrotzdem: 'Trotzdem', cueHandy: 'Prüfung',
		cueRolle: 'Rolle', cueUmzug: 'umziehen',
	},
	en: {
		appMenu: 'Open apps menu', appName: 'Vereinsbuchhaltung', buchungen: 'Entries',
		cueNextcloud: 'Nextcloud', cueDaten: 'data', cueTrotzdem: 'Still', cueHandy: 'auditors',
		cueRolle: 'role', cueUmzug: 'move',
	},
};

const APP = '/apps/vereinsbuchhaltung/';
const appReady = 'typeof OC !== "undefined" && !!document.querySelector(".vbh-tabs")';

export async function prepare(ctx) {
	// Einmal die App laden, damit sie beim sichtbaren Oeffnen aus dem Cache
	// kommt und kaum Ladebild entsteht.
	await ctx.goto(APP, null, { ready: appReady, settle: 800 });
	await ctx.goto('/apps/dashboard/', null, { ready: 'typeof OC !== "undefined"', settle: 1500 });
}

/** Bildschirmposition eines Elements im Handyrahmen (Rahmen ist skaliert). */
function phoneTarget(ctx, text, tag) {
	return ctx.evaluate(`(() => {
		const f = document.getElementById('vbh-video-iframe');
		const el = Array.from(f.contentDocument.querySelectorAll(${JSON.stringify(tag)}))
			.filter(e => e.getClientRects().length > 0)
			.find(e => (e.textContent || '').trim().includes(${JSON.stringify(text)}));
		if (!el) return null;
		const fr = f.getBoundingClientRect();
		const skala = fr.width / f.offsetWidth;
		const r = el.getBoundingClientRect();
		return { x: Math.round(fr.left + (r.left + r.width / 2) * skala), y: Math.round(fr.top + (r.top + r.height / 2) * skala) };
	})()`);
}

export async function record(ctx) {
	const t = T[ctx.lang];
	ctx.shot('startseite');

	// Die eigene Nextcloud: Startseite, App-Menue, Klick auf die App - sichtbar.
	// Nur das eigentliche Laden faellt hinter einen Schnitt.
	await ctx.until(1.4);
	await ctx.click(`[aria-label^="${t.appMenu}"]`, { settle: 500 });
	await ctx.moveTo({ text: t.appName, tag: 'a.app-item' });
	await ctx.until(ctx.cueAt(t.cueNextcloud) - 0.5);
	// Ohne Schnitt stuende gut eine Sekunde der leere Nextcloud-Hintergrund
	// im Bild (nachgemessen) - das wirkt wie ein Fehler. Der Klick bleibt
	// sichtbar, nur das Laden faellt heraus.
	await ctx.pulse();
	await ctx.sleep(350);
	await ctx.behindCut('uebersicht', async () => {
		await ctx.click({ text: t.appName, tag: 'a.app-item' }, { move: false, settle: 100 });
		await ctx.waitFor(appReady, 30000);
		await ctx.reinstallOverlay();
		await ctx.sleep(300);
	});

	// Die Daten bleiben beim Verein: der Geldbestand oben rechts.
	await ctx.until(ctx.cueAt(t.cueDaten));
	await ctx.moveTo('.vbh-header .vbh-balance, .vbh-header [class*=balance], .vbh-header');

	// Alle auf demselben Stand: das Aufgaben-Klemmbrett.
	await ctx.until(ctx.cueAt(t.cueTrotzdem));
	await ctx.click('.vbh-tasks-trigger', { settle: 900 });
	await ctx.waitFor(`__vbhVisible('.vbh-tasks-panel').length > 0`);
	await ctx.mark('klemmbrett', '.vbh-tasks-panel');

	// Auch am Handy: dieselbe App im Rahmen, gescrollt und bedient.
	await ctx.until(ctx.cueAt(t.cueHandy));
	await ctx.click('.vbh-tasks-trigger', { move: false, settle: 200 });
	ctx.shot('handy');
	await ctx.showFrame(APP, { mode: 'phone', ready: 'doc.querySelector(".vbh-navbar--mobile") && doc.querySelector(".vbh-totals .vbh-total-delta")' });
	await ctx.scrollFrame(520, { duration: 1800 });

	// Jede Rolle sieht, was sie braucht: im Handy auf "Buchungen" tippen.
	await ctx.until(ctx.cueAt(t.cueRolle) - 0.8);
	await ctx.scrollFrame(0, { duration: 700 });
	const ziel = await phoneTarget(ctx, t.buchungen, 'a, button');
	if (!ziel) throw new Error(`Handy: "${t.buchungen}" nicht gefunden`);
	await ctx.moveTo(ziel, { duration: 600 });
	await ctx.pulse();
	await ctx.clickInFrame(t.buchungen, 'a, button');
	await ctx.scrollFrame(380, { duration: 1600 });

	// Umziehen / Sicherung: zurueck am Rechner, in die Buchungsliste.
	await ctx.until(ctx.cueAt(t.cueUmzug) - 0.6);
	await ctx.hideFrame();
	ctx.shot('buchungen');
	await ctx.click({ text: t.buchungen, tag: '.vbh-tabs button', exact: true }, { settle: 900 });
	await ctx.moveTo('tbody tr:nth-child(3) td:nth-child(3)', { duration: 900 });
}
