// Block 01: Eure Daten, eure Cloud.
//
// Nextcloud-Startseite -> App ueber das App-Menue, Uebersicht mit dem
// Aufgaben-Klemmbrett, die Handyansicht im Rahmen, dann die Einstellungen:
// Berechtigungen (Rollen) und "Sicherung und Umzug".
//
// Seitenwechsel laufen hinter einem Schnitt (ctx.goto/behindCut): die Bilder
// des Ladens fallen weg, die Einstellung danach beginnt mit fertiger Seite.
// Bauchbinde und Rollen-Hinweis legt HyperFrames darueber, nicht diese Szene.

const T = {
	de: {
		appMenu: 'Apps-Menü öffnen', appName: 'Vereinsbuchhaltung',
		berechtigungen: 'Berechtigungen', sicherung: 'Sicherung und Umzug',
		cueNextcloud: 'Nextcloud', cueDaten: 'Daten', cueTrotzdem: 'Trotzdem', cueHandy: 'Prüfung',
		cueRolle: 'Rolle', cueUmzug: 'umziehen',
	},
	en: {
		appMenu: 'Open apps menu', appName: 'Vereinsbuchhaltung',
		berechtigungen: 'Permissions', sicherung: 'Backup and migration',
		cueNextcloud: 'Nextcloud', cueDaten: 'data', cueTrotzdem: 'Still', cueHandy: 'auditors',
		cueRolle: 'role', cueUmzug: 'move',
	},
};

const APP = '/apps/vereinsbuchhaltung/';
const SETTINGS = '/settings/admin/vereinsbuchhaltung';
const appReady = 'typeof OC !== "undefined" && !!document.querySelector(".vbh-tabs")';

export async function prepare(ctx) {
	await ctx.goto('/apps/dashboard/', null, { ready: 'typeof OC !== "undefined"', settle: 1500 });
}

export async function record(ctx) {
	const t = T[ctx.lang];
	ctx.shot('startseite');

	// Die eigene Nextcloud: Startseite, dann ueber das App-Menue in die App.
	// Das Laden dauert gut zwei Sekunden; das Bild steht so lange auf dem
	// geoeffneten App-Menue, die App erscheint zu "kein Abo".
	await ctx.until(1.6);
	await ctx.click(`[aria-label^="${t.appMenu}"]`, { settle: 500 });
	await ctx.moveTo({ text: t.appName, tag: 'a.app-item' });
	await ctx.until(ctx.cueAt(t.cueNextcloud));
	await ctx.behindCut('uebersicht', async () => {
		await ctx.click({ text: t.appName, tag: 'a.app-item' }, { move: false, settle: 300 });
		await ctx.waitFor(appReady, 30000);
		await ctx.reinstallOverlay();
		await ctx.sleep(500);
	});

	// Die Daten bleiben beim Verein: der Geldbestand oben rechts.
	await ctx.until(ctx.cueAt(t.cueDaten));
	await ctx.moveTo('.vbh-header .vbh-balance, .vbh-header [class*=balance], .vbh-header');

	// Alle auf demselben Stand: das Aufgaben-Klemmbrett.
	await ctx.until(ctx.cueAt(t.cueTrotzdem));
	await ctx.click('.vbh-tasks-trigger', { settle: 900 });
	await ctx.waitFor(`__vbhVisible('.vbh-tasks-panel').length > 0`);
	await ctx.mark('klemmbrett', '.vbh-tasks-panel');

	// Auch am Handy. Frueh, weil der Wechsel in die Einstellungen danach gut
	// zwei Sekunden laedt - so lange steht das Handybild still.
	await ctx.until(ctx.cueAt(t.cueHandy));
	await ctx.click('.vbh-tasks-trigger', { move: false, settle: 200 });
	ctx.shot('handy');
	await ctx.showFrame(APP, { mode: 'phone' });

	// Jede Rolle sieht, was sie braucht: Berechtigungen.
	await ctx.until(ctx.cueAt(t.cueRolle) - 2.4);
	await ctx.goto(SETTINGS, 'rechte', { ready: `!!Array.from(document.querySelectorAll('h2')).find(h => h.textContent.includes(${JSON.stringify(t.berechtigungen)}))`, settle: 400 });
	await ctx.scrollTo({ text: t.berechtigungen, tag: 'h2' }, { duration: 1, offset: 70 });
	await ctx.sleep(300);
	await ctx.moveTo({ text: 'karla', tag: 'td' });

	// Umzug: Sicherung und Umzug.
	await ctx.until(ctx.cueAt(t.cueUmzug) - 0.4);
	await ctx.scrollTo({ text: t.sicherung, tag: 'h2' }, { duration: 1500, offset: 70 });
	// Zoomziel: der Absatz "enthaelt den gesamten Bestand ... auch auf einer
	// anderen Nextcloud" - der ganze Abschnitt ist hoeher als das Bild.
	await ctx.mark('sicherung', '.vbh-backup > p.vbh-hint');
	await ctx.moveTo('.vbh-backup > .vbh-card button');
}
