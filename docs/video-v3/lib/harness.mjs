// Aufnahme-Kern: alles, was die Szenenskripte brauchen, um die App zu bedienen
// und dabei gefilmt zu werden.
//
// Bedienung und Bild sind getrennt gedacht:
//   - Bedienung laeuft ueber echte .click()-Aufrufe im Seitenkontext (trusted
//     events, loesen Vue-Handler zuverlaessig aus).
//   - Alles Sichtbare, das nicht zur App gehoert - Cursor, Lower Third,
//     Callouts, Titelkarten, Zoom - ist ein Overlay im DOM, das per Inline-Style
//     gesetzt wird. Kein <style>-Element, damit Nextclouds CSP nichts verwirft,
//     und kein ffmpeg-Filter, weil Chromium subpixelgenau interpoliert
//     (ffmpeg-zoompan ruckelt bei langsamen Fahrten).
//
// Zeitachse: jede Szene hat ihre eigene Uhr, die bei 0 startet, wenn die
// Aufnahme beginnt. `await ctx.until(12.4)` wartet, bis die Szenenuhr dort
// steht - und die Zeitpunkte kommen aus den Wortzeiten der Vertonung.

import { createRequire } from 'node:module';
import { mkdirSync, writeFileSync, readFileSync, rmSync, existsSync } from 'node:fs';
import { join } from 'node:path';

const require = createRequire(import.meta.url);
const cdp = require('./cdp.cjs');

// Aufnahme in echten Bildpunkten: das Fenster ist 1920x1080 gross, die Seite
// rechnet in denselben Koordinaten. Kein Emulations- oder Zoomfaktor - beides
// hat sich als Fehlerquelle erwiesen: die Emulation malte die Seite nur in einen
// Teil des Fensters, CSS-Zoom verschiebt die Koordinaten der Einblendungen
// gegenueber dem Bild.
export const VIEWPORT = { width: 1920, height: 1080, scale: 1 };
export const FPS = 30;

/** Nextcloud-Blau und der dunkle Deckblatt-Ton aus dem Konzept. */
export const COLORS = {
	accent: '#0082c9',
	accentDeep: '#00618f',
	dark: '#0a1626',
	darker: '#061020',
	text: '#ffffff',
	muted: '#a8c4e0',
};

const OVERLAY_ID = 'vbh-video-overlay';

/**
 * Baut das Overlay auf: Cursor, Lower Third, Callout, Titelkarte, Blende.
 * Alles per Inline-Style, alles ueber der App (z-index), alles ohne Mausklicks
 * abzufangen (pointer-events: none).
 */
const OVERLAY_SETUP = `(() => {
	const OLD = document.getElementById(${JSON.stringify(OVERLAY_ID)});
	if (OLD) OLD.remove();

	const root = document.createElement('div');
	root.id = ${JSON.stringify(OVERLAY_ID)};
	Object.assign(root.style, {
		position: 'fixed', inset: '0', zIndex: '100000', pointerEvents: 'none',
		fontFamily: 'var(--font-face, "Noto Sans", "Segoe UI", system-ui, sans-serif)',
	});

	// Cursor: Pfeil als SVG plus Klick-Ring.
	const cursor = document.createElement('div');
	Object.assign(cursor.style, {
		position: 'absolute', left: '0', top: '0', width: '34px', height: '34px',
		transform: 'translate(760px, 420px)', opacity: '0',
		transition: 'transform 520ms cubic-bezier(.22,.61,.36,1), opacity 260ms ease-out',
		filter: 'drop-shadow(0 2px 4px rgba(0,0,0,.45))', willChange: 'transform',
	});
	cursor.innerHTML = '<svg viewBox="0 0 28 28" width="34" height="34">'
		+ '<path d="M4 2 L4 22 L9.5 17 L13 25.5 L17 24 L13.5 15.5 L21 15.5 Z" fill="#ffffff" stroke="#1a1a1a" stroke-width="1.4"/>'
		+ '</svg>';

	const ring = document.createElement('div');
	Object.assign(ring.style, {
		position: 'absolute', left: '0', top: '0', width: '58px', height: '58px',
		marginLeft: '-12px', marginTop: '-12px', borderRadius: '50%',
		border: '3px solid ${COLORS.accent}', opacity: '0', transform: 'scale(.4)',
		transition: 'opacity 420ms ease-out, transform 420ms ease-out',
	});
	cursor.appendChild(ring);

	// Lower Third: Akzentbalken plus Text, unten links.
	const lower = document.createElement('div');
	Object.assign(lower.style, {
		position: 'absolute', left: '64px', bottom: '80px', display: 'flex', alignItems: 'stretch',
		opacity: '0', transform: 'translateY(14px)',
		transition: 'opacity 320ms ease-out, transform 320ms ease-out',
	});
	const lowerBar = document.createElement('div');
	Object.assign(lowerBar.style, { width: '10px', background: '${COLORS.accent}', borderRadius: '2px 0 0 2px' });
	const lowerText = document.createElement('div');
	Object.assign(lowerText.style, {
		background: 'rgba(10,22,38,.86)', color: '#fff', fontWeight: '600', fontSize: '42px', lineHeight: '1.25',
		padding: '18px 32px 19px 28px', letterSpacing: '.2px', borderRadius: '0 3px 3px 0',
		backdropFilter: 'blur(2px)',
	});
	lower.append(lowerBar, lowerText);

	// Callout: kurze Beschriftung oben rechts, erklaert was gerade passiert.
	const callout = document.createElement('div');
	Object.assign(callout.style, {
		position: 'absolute', right: '70px', bottom: '96px', maxWidth: '650px',
		background: 'rgba(0,130,201,.94)', color: '#fff', fontWeight: '600', fontSize: '34px', lineHeight: '1.35',
		padding: '18px 28px', borderRadius: '4px', boxShadow: '0 10px 30px rgba(0,0,0,.35)',
		opacity: '0', transform: 'translateY(12px)',
		transition: 'opacity 300ms ease-out, transform 300ms ease-out',
	});

	// Titelkarte: deckt das ganze Bild, fuer Intro und Outro.
	const card = document.createElement('div');
	Object.assign(card.style, {
		position: 'absolute', inset: '0', display: 'flex', flexDirection: 'column',
		alignItems: 'center', justifyContent: 'center', gap: '18px',
		background: 'radial-gradient(120% 120% at 50% 20%, #123457 0%, ${COLORS.dark} 55%, ${COLORS.darker} 100%)',
		opacity: '0', transition: 'opacity 500ms ease-in-out', textAlign: 'center',
	});

	// Blende: Schwarzbild fuer harte Schnitte innerhalb einer Szene.
	const fade = document.createElement('div');
	Object.assign(fade.style, {
		position: 'absolute', inset: '0', background: '#000', opacity: '0',
		transition: 'opacity 300ms linear',
	});

	root.append(card, lower, callout, cursor, fade);
	document.body.appendChild(root);

	window.__vbhOverlay = { root, cursor, ring, lower, lowerText, callout, card, fade };

	// Sichtbarkeitshelfer fuer die Wartebedingungen der Szenen. Verlassene
	// Dialoge bleiben in dieser App im DOM stehen (v-show): ein
	// querySelector('.vbh-modal-title') findet den geschlossenen Import-Dialog
	// und wird nie null. Nur sichtbare Elemente zaehlen.
	window.__vbhVisible = (sel) => Array.from(document.querySelectorAll(sel))
		.filter((e) => e.getClientRects().length > 0);
	window.__vbhVisibleText = (sel) => window.__vbhVisible(sel || 'body')
		.map((e) => e.innerText || '').join(' ');

	return true;
})()`;

/** Wartet, bis die Szenenuhr bei `seconds` steht. */
function makeClock() {
	const t0 = Date.now();
	return {
		now: () => (Date.now() - t0) / 1000,
		async until(seconds) {
			const rest = seconds * 1000 - (Date.now() - t0);
			if (rest > 0) await new Promise((r) => setTimeout(r, rest));
			return this.now();
		},
	};
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Erzeugt den Aufnahmekontext fuer eine Szene.
 *
 * @param {object} tab      offener CDP-Tab
 * @param {object} opts     { lang, sceneId, framesDir }
 */
export async function createContext(tab, { lang, sceneId, framesDir }) {
	await cdp.evaluate(tab, OVERLAY_SETUP);

	const clock = makeClock();
	const frames = [];
	let capturing = false;
	let frameIndex = 0;
	let frameHandler = null;

	const evaluate = (expression) => cdp.evaluate(tab, expression);

	/** Mittelpunkt eines Elements in CSS-Pixeln - dorthin faehrt der Cursor. */
	async function center(selector) {
		const box = await evaluate(`(() => {
			const el = ${selectorExpression(selector)};
			if (!el) return null;
			const r = el.getBoundingClientRect();
			return { x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2) };
		})()`);
		if (!box) throw new Error(`Element nicht gefunden: ${JSON.stringify(selector)}`);
		return box;
	}

	const ctx = {
		lang,
		sceneId,
		tab,
		clock,
		until: (s) => clock.until(s),
		now: () => clock.now(),
		sleep,
		evaluate,

		/** Aufnahme starten. Ab hier laeuft die Szenenuhr. */
		async startRecording() {
			mkdirSync(framesDir, { recursive: true });
			capturing = true;
			// Der Handler wird gemerkt, damit stopRecording ihn wieder abmelden kann.
			// Bricht eine Szene ab und wird wiederholt, schriebe ein liegengebliebener
			// Handler sonst weiter Bilder mit seiner eigenen Zaehlung in denselben
			// Ordner - die Wiederholung waere dahin.
			frameHandler = async (params) => {
				if (!capturing) return;
				const index = frameIndex++;
				const file = join(framesDir, `f${String(index).padStart(5, '0')}.jpg`);
				writeFileSync(file, Buffer.from(params.data, 'base64'));
				frames.push({ file, timestamp: params.metadata.timestamp });
				try {
					await cdp.send(tab, 'Page.screencastFrameAck', { sessionId: params.sessionId });
				} catch { /* Tab schon geschlossen - die letzten Bilder sind ohnehin gesetzt */ }
			};
			cdp.on(tab, 'Page.screencastFrame', frameHandler);
			await cdp.send(tab, 'Page.startScreencast', {
				format: 'jpeg', quality: 92,
				maxWidth: VIEWPORT.width * VIEWPORT.scale,
				maxHeight: VIEWPORT.height * VIEWPORT.scale,
				everyNthFrame: 1,
			});
			clock.now();
		},

		async stopRecording() {
			capturing = false;
			if (frameHandler) { cdp.off(tab, 'Page.screencastFrame', frameHandler); frameHandler = null; }
			await cdp.send(tab, 'Page.stopScreencast').catch(() => {});
			return frames;
		},

		/**
		 * Cursor bewegen. Rein kosmetisch - die eigentliche Bedienung laeuft
		 * ueber .click() im Seitenkontext, weil Koordinatenklicks bei jedem
		 * Layoutwechsel danebengehen.
		 */
		async moveTo(selector, { duration = 520 } = {}) {
			const { x, y } = await center(selector);
			await evaluate(`(() => {
				const o = window.__vbhOverlay;
				o.cursor.style.transition = 'transform ${duration}ms cubic-bezier(.22,.61,.36,1), opacity 260ms ease-out';
				o.cursor.style.opacity = '1';
				o.cursor.style.transform = 'translate(${x}px, ${y}px)';
				return true;
			})()`);
			await sleep(duration + 60);
		},

		/** Klick-Ring zeigen (ohne zu klicken). */
		async pulse() {
			await evaluate(`(() => {
				window.__vbhOverlay.cursor.style.opacity = '1';
				const r = window.__vbhOverlay.ring;
				r.style.transition = 'none'; r.style.opacity = '.9'; r.style.transform = 'scale(.4)';
				requestAnimationFrame(() => {
					r.style.transition = 'opacity 420ms ease-out, transform 420ms ease-out';
					r.style.opacity = '0'; r.style.transform = 'scale(1.15)';
				});
				return true;
			})()`);
			await sleep(180);
		},

		/** Hinfahren, pulsen, klicken - der Standardweg fuer jede Bedienung. */
		async click(selector, { move = true, settle = 420 } = {}) {
			if (move) await ctx.moveTo(selector);
			await ctx.pulse();
			const ok = await evaluate(`(() => {
				const el = ${selectorExpression(selector)};
				if (!el) return false;
				el.click();
				return true;
			})()`);
			if (!ok) throw new Error(`Klick fehlgeschlagen, Element nicht gefunden: ${JSON.stringify(selector)}`);
			await sleep(settle);
		},

		/** Zeichenweise tippen - ein gesetzter Wert sieht im Video falsch aus. */
		async type(selector, text, { delay = 55 } = {}) {
			await ctx.moveTo(selector);
			await ctx.pulse();
			await evaluate(`(() => { const el = ${selectorExpression(selector)}; el.focus(); el.value = ''; return true; })()`);
			for (const char of String(text)) {
				await evaluate(`(() => {
					const el = ${selectorExpression(selector)};
					el.value += ${JSON.stringify(char)};
					el.dispatchEvent(new Event('input', { bubbles: true }));
					return true;
				})()`);
				await sleep(delay);
			}
			await evaluate(`(() => {
				const el = ${selectorExpression(selector)};
				el.dispatchEvent(new Event('change', { bubbles: true }));
				return true;
			})()`);
			await sleep(160);
		},

		/** Auswahlfeld setzen (native <select>). */
		async select(selector, optionText) {
			await ctx.moveTo(selector);
			await ctx.pulse();
			const ok = await evaluate(`(() => {
				const el = ${selectorExpression(selector)};
				const option = Array.from(el.options).find(o => o.textContent.trim().includes(${JSON.stringify(optionText)}));
				if (!option) return false;
				el.value = option.value;
				el.dispatchEvent(new Event('change', { bubbles: true }));
				return true;
			})()`);
			if (!ok) throw new Error(`Option "${optionText}" nicht gefunden in ${JSON.stringify(selector)}`);
			await sleep(380);
		},

		/**
		 * Auswahl in einem NcSelect (vue-select). Kein natives <select>: der
		 * Wert entsteht erst, wenn ein Eintrag aus der aufgeklappten Liste
		 * geklickt wird - genau das macht diese Folge.
		 *
		 * `inputSelector` zeigt auf das Suchfeld des Auswahlfelds. Klassen sind
		 * hier besser als Platzhaltertexte: die sind uebersetzt und wuerden im
		 * englischen Lauf nicht treffen.
		 */
		async pickOption(inputSelector, optionText, { nth = 0 } = {}) {
			const feld = `(() => {
				const alle = Array.from(document.querySelectorAll(${JSON.stringify(inputSelector)}))
					.filter(e => e.getClientRects().length > 0);
				return alle[${nth}] || null;
			})()`;
			const box = await evaluate(`(() => {
				const el = ${feld};
				if (!el) return null;
				const r = el.getBoundingClientRect();
				return { x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2) };
			})()`);
			if (!box) throw new Error(`Auswahlfeld nicht gefunden: ${inputSelector} (nth=${nth})`);
			await evaluate(`(() => {
				window.__vbhOverlay.cursor.style.transform = 'translate(${box.x}px, ${box.y}px)';
				return true;
			})()`);
			await sleep(560);
			await ctx.pulse();
			await evaluate(`(() => {
				const el = ${feld};
				el.focus();
				el.dispatchEvent(new Event('focus', { bubbles: true }));
				return true;
			})()`);
			await sleep(320);
			for (const char of String(optionText)) {
				await evaluate(`(() => {
					const el = ${feld};
					el.value += ${JSON.stringify(char)};
					el.dispatchEvent(new Event('input', { bubbles: true }));
					return true;
				})()`);
				await sleep(60);
			}
			await cdp.waitFor(tab, `Array.from(document.querySelectorAll('.vs__dropdown-option'))
				.some(o => (o.textContent || '').includes(${JSON.stringify(optionText)}))`, 8000);
			await sleep(420);
			const ok = await evaluate(`(() => {
				const treffer = Array.from(document.querySelectorAll('.vs__dropdown-option'))
					.filter(o => (o.textContent || '').includes(${JSON.stringify(optionText)}));
				if (!treffer.length) return false;
				treffer[0].dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
				treffer[0].click();
				return true;
			})()`);
			if (!ok) throw new Error(`Auswahl "${optionText}" nicht in der Liste`);
			await sleep(420);
		},

		/**
		 * Ersten unzugeordneten Bankumsatz auf ein Konto buchen. Das Auswahlfeld
		 * steckt in der Tabellenzeile (.vbh-assign-select), nicht in einem Dialog.
		 */
		async assignFirstTransaction(accountText) {
			await ctx.pickOption('.vbh-assign-select input.vs__search', accountText);
			// Manche Zeilen bestaetigen selbst, andere haben einen Knopf daneben.
			const knopf = await evaluate(`(() => {
				const b = Array.from(document.querySelectorAll('.vbh-assign-btns button'))
					.filter(e => e.getClientRects().length > 0);
				if (!b.length) return false;
				b[0].click();
				return true;
			})()`);
			await sleep(knopf ? 900 : 500);
		},

		/** Alte Fassung mit Platzhaltertext - nur noch fuer Sonderfaelle. */
		async pickOptionByPlaceholder(placeholder, optionText) {
			const feld = `input.vs__search[placeholder*=${JSON.stringify(placeholder)}]`;
			await ctx.moveTo(feld);
			await ctx.pulse();
			await evaluate(`(() => {
				const el = document.querySelector(${JSON.stringify(feld)});
				el.focus();
				el.dispatchEvent(new Event('focus', { bubbles: true }));
				return true;
			})()`);
			await sleep(320);
			for (const char of String(optionText)) {
				await evaluate(`(() => {
					const el = document.querySelector(${JSON.stringify(feld)});
					el.value += ${JSON.stringify(char)};
					el.dispatchEvent(new Event('input', { bubbles: true }));
					return true;
				})()`);
				await sleep(60);
			}
			await cdp.waitFor(tab, `Array.from(document.querySelectorAll('.vs__dropdown-option'))
				.some(o => (o.textContent || '').includes(${JSON.stringify(optionText)}))`, 8000);
			await sleep(420);
			const ok = await evaluate(`(() => {
				const treffer = Array.from(document.querySelectorAll('.vs__dropdown-option'))
					.filter(o => (o.textContent || '').includes(${JSON.stringify(optionText)}));
				if (!treffer.length) return false;
				treffer[0].dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
				treffer[0].click();
				return true;
			})()`);
			if (!ok) throw new Error(`Auswahl "${optionText}" nicht in der Liste`);
			await sleep(420);
		},

		/**
		 * Schalter (NcCheckboxRadioSwitch) ueber seine Beschriftung umlegen.
		 *
		 * Die Beschriftung steckt in dieser @nextcloud/vue-Fassung in einem
		 * `span.checkbox-content`, nicht in einem `<label>` - deshalb wird der
		 * ganze Schalter gesucht und am Ende sein `input` geklickt.
		 */
		async toggleSwitch(labelText) {
			const treffer = `(() => {
				const kandidaten = Array.from(document.querySelectorAll('.checkbox-radio-switch'))
					.filter(e => e.getClientRects().length > 0)
					.filter(e => (e.textContent || '').includes(${JSON.stringify(labelText)}));
				kandidaten.sort((a, b) => (a.textContent || '').length - (b.textContent || '').length);
				return kandidaten[0] || null;
			})()`;
			const da = await evaluate(`${treffer} !== null`);
			if (!da) throw new Error(`Schalter "${labelText}" nicht gefunden`);
			const box = await evaluate(`(() => {
				const el = ${treffer};
				const r = el.getBoundingClientRect();
				return { x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2) };
			})()`);
			await evaluate(`(() => {
				const o = window.__vbhOverlay;
				o.cursor.style.transform = 'translate(${box.x}px, ${box.y}px)';
				return true;
			})()`);
			await sleep(560);
			await ctx.pulse();
			await evaluate(`(() => {
				const schalter = ${treffer};
				const box = schalter.querySelector('input');
				(box || schalter).click();
				return true;
			})()`);
			await sleep(520);
		},

		/**
		 * Ein Element ins Bild holen, egal in welchem scrollbaren Container es
		 * steckt. Zuverlaessiger als das Raten des richtigen Containers: die
		 * Aktionsknoepfe der Einzugstabelle liegen in einer Tabelle, die ueber
		 * ihren Kasten hinausragt, und gescrollt wird der Abschnitt darum herum.
		 */
		async reveal(selector, { duration = 1200 } = {}) {
			const ok = await evaluate(`(() => {
				const el = ${selectorExpression(selector)};
				if (!el) return false;
				el.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'end' });
				return true;
			})()`);
			if (!ok) throw new Error(`Element zum Freilegen nicht gefunden: ${JSON.stringify(selector)}`);
			await sleep(duration);
			return true;
		},

		/**
		 * Einen waagerecht scrollbaren Bereich ans rechte Ende fahren.
		 *
		 * Die Tabelle der Sammeleinzuege ist breiter als ihr Container: die
		 * Aktionsknoepfe ("XML herunterladen", "Als ausgeführt verbuchen") liegen
		 * ausserhalb und waeren im Bild abgeschnitten.
		 */
		async scrollRight(selector, { duration = 1200 } = {}) {
			await evaluate(`(() => {
				const kandidaten = Array.from(document.querySelectorAll(${JSON.stringify(selector)}))
					.filter(e => e.getClientRects().length > 0 && e.scrollWidth > e.clientWidth + 4);
				// Kein Kandidat? Dann ist der Ueberlauf woanders - reveal() nehmen.
				const el = kandidaten[0];
				if (!el) return false;
				const start = el.scrollLeft;
				const ziel = el.scrollWidth - el.clientWidth;
				const t0 = performance.now();
				function schritt(now) {
					const p = Math.min(1, (now - t0) / ${duration});
					const eased = p < .5 ? 2 * p * p : 1 - Math.pow(-2 * p + 2, 2) / 2;
					el.scrollLeft = start + (ziel - start) * eased;
					if (p < 1) requestAnimationFrame(schritt);
				}
				requestAnimationFrame(schritt);
				return true;
			})()`);
			await sleep(duration + 150);
		},

		/** Weiches Scrollen: Dauer statt Sprung, damit das Auge mitkommt. */
		async scrollTo(target, { duration = 1400 } = {}) {
			await evaluate(`(() => {
				const ziel = ${typeof target === 'number' ? String(target) : `(() => {
					const el = ${selectorExpression(target)};
					if (!el) return 0;
					const r = el.getBoundingClientRect();
					return window.scrollY + r.top - 160;
				})()`};
				const start = window.scrollY;
				const distanz = ziel - start;
				const t0 = performance.now();
				const dauer = ${duration};
				function schritt(now) {
					const p = Math.min(1, (now - t0) / dauer);
					const eased = p < .5 ? 2 * p * p : 1 - Math.pow(-2 * p + 2, 2) / 2;
					window.scrollTo(0, start + distanz * eased);
					if (p < 1) requestAnimationFrame(schritt);
				}
				requestAnimationFrame(schritt);
				return true;
			})()`);
			await sleep(duration + 120);
		},

		/**
		 * Zoom als CSS-Transform auf den Seiteninhalt. Nach Ablauf wird die
		 * Transition entfernt: Playwright/CDP halten Elemente mit laufender
		 * Transition sonst fuer instabil, und Klicks verzoegern sich.
		 */
		async zoomTo(selector, { scale = 1.35, duration = 900 } = {}) {
			// transform-origin zaehlt im Koordinatensystem des transformierten
			// Elements, hier also des Dokuments - nicht des Fensters. Ohne den
			// Scroll-Versatz zoomt die Seite an einer ganz anderen Stelle.
			const { x, y } = await evaluate(`(() => {
				const el = ${selectorExpression(selector)};
				if (!el) return null;
				const r = el.getBoundingClientRect();
				return {
					x: Math.round(window.scrollX + r.left + r.width / 2),
					y: Math.round(window.scrollY + r.top + r.height / 2),
				};
			})()`) ?? {};
			if (x === undefined) throw new Error(`Zoomziel nicht gefunden: ${JSON.stringify(selector)}`);
			await evaluate(`(() => {
				const el = document.body;
				el.style.transformOrigin = '${x}px ${y}px';
				el.style.transition = 'transform ${duration}ms cubic-bezier(.4,0,.2,1)';
				el.style.transform = 'scale(${scale})';
				return true;
			})()`);
			await sleep(duration + 80);
			await evaluate(`(() => { document.body.style.transition = 'none'; return true; })()`);
		},

		async zoomOut({ duration = 800 } = {}) {
			await evaluate(`(() => {
				const el = document.body;
				el.style.transition = 'transform ${duration}ms cubic-bezier(.4,0,.2,1)';
				el.style.transform = 'none';
				return true;
			})()`);
			await sleep(duration + 80);
			await evaluate(`(() => {
				const el = document.body;
				el.style.transition = 'none';
				el.style.transformOrigin = '';
				return true;
			})()`);
		},

		/** Lower Third einblenden (und nach `hold` Sekunden wieder ausblenden). */
		async lowerThird(text, { hold = 4.5 } = {}) {
			if (!text) return;
			await evaluate(`(() => {
				const o = window.__vbhOverlay;
				o.lowerText.textContent = ${JSON.stringify(text)};
				o.lower.style.opacity = '1';
				o.lower.style.transform = 'translateY(0)';
				setTimeout(() => {
					o.lower.style.opacity = '0';
					o.lower.style.transform = 'translateY(14px)';
				}, ${Math.round(hold * 1000)});
				return true;
			})()`);
		},

		/** Callout einblenden, hält `hold` Sekunden. */
		async callout(text, { hold = 4 } = {}) {
			if (!text) return;
			await evaluate(`(() => {
				const o = window.__vbhOverlay;
				o.callout.textContent = ${JSON.stringify(text)};
				o.callout.style.opacity = '1';
				o.callout.style.transform = 'translateY(0)';
				setTimeout(() => {
					o.callout.style.opacity = '0';
					o.callout.style.transform = 'translateY(12px)';
				}, ${Math.round(hold * 1000)});
				return true;
			})()`);
		},

		/**
		 * Titelkarte zeigen. `lines` sind kleinere Zeilen unter dem Titel.
		 * Sichtbar bis hideCard().
		 */
		async showCard({ title, subtitle = null, lines = [], logo = true }) {
			await evaluate(`(() => {
				const o = window.__vbhOverlay;
				o.card.innerHTML = '';
				const wrap = document.createElement('div');
				Object.assign(wrap.style, { display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '20px' });

				${logo ? `
				const logo = document.createElement('div');
				Object.assign(logo.style, {
					width: '165px', height: '165px', borderRadius: '38px',
					background: 'linear-gradient(150deg, ${COLORS.accent}, ${COLORS.accentDeep})',
					display: 'flex', alignItems: 'center', justifyContent: 'center',
					boxShadow: '0 24px 60px rgba(0,0,0,.45)', marginBottom: '10px',
				});
				logo.innerHTML = '<svg viewBox="0 0 24 24" width="95" height="95" fill="#fff">'
					+ '<path d="M4 4h11l5 5v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z" opacity=".22"/>'
					+ '<path d="M7 12h3v6H7zm4.5-4h3v10h-3zM16 14h3v4h-3z"/>'
					+ '</svg>';
				wrap.appendChild(logo);
				` : ''}

				const h = document.createElement('div');
				h.textContent = ${JSON.stringify(title)};
				Object.assign(h.style, { fontWeight: '700', fontSize: '105px', lineHeight: '1.1', color: '#fff', letterSpacing: '-1px' });
				wrap.appendChild(h);

				${subtitle ? `
				const sub = document.createElement('div');
				sub.textContent = ${JSON.stringify(subtitle)};
				Object.assign(sub.style, { fontWeight: '400', fontSize: '42px', lineHeight: '1.4', color: '${COLORS.muted}', maxWidth: '1350px' });
				wrap.appendChild(sub);
				` : ''}

				const zeilen = ${JSON.stringify(lines)};
				if (zeilen.length) {
					const list = document.createElement('div');
					Object.assign(list.style, { display: 'flex', flexDirection: 'column', gap: '12px', marginTop: '22px' });
					for (const z of zeilen) {
						const row = document.createElement('div');
						row.textContent = z;
						Object.assign(row.style, { fontWeight: '400', fontSize: '35px', lineHeight: '1.4', color: '#cfe0f0' });
						list.appendChild(row);
					}
					wrap.appendChild(list);
				}

				o.card.appendChild(wrap);
				o.cursor.style.opacity = '0';
				o.card.style.opacity = '1';
				// Leichter Zoom, damit das Standbild nicht steht.
				o.card.style.transition = 'opacity 500ms ease-in-out';
				wrap.style.transition = 'transform 9s linear';
				wrap.style.transform = 'scale(1)';
				requestAnimationFrame(() => { wrap.style.transform = 'scale(1.045)'; });
				return true;
			})()`);
		},

		async hideCard({ duration = 500 } = {}) {
			await evaluate(`(() => {
				const o = window.__vbhOverlay;
				o.card.style.transition = 'opacity ${duration}ms ease-in-out';
				o.card.style.opacity = '0';
				return true;
			})()`);
			await sleep(duration + 60);
		},

		/** Kurz auf Schwarz und zurueck - fuer Ortswechsel innerhalb einer Szene. */
		async blink({ duration = 260 } = {}) {
			await evaluate(`(() => { window.__vbhOverlay.fade.style.opacity = '1'; return true; })()`);
			await sleep(duration);
			await evaluate(`(() => { window.__vbhOverlay.fade.style.opacity = '0'; return true; })()`);
			await sleep(duration);
		},

		/**
		 * Datei in den Datei-Input des *sichtbaren* Dialogs legen.
		 *
		 * Bewusst nicht ueber einen globalen `input[type=file]`-Selektor: in
		 * dieser App haengen mehrere Datei-Inputs gleichzeitig im DOM
		 * (Kontoauszug, Mitgliederliste, Vereinslogo). Ein generischer Selektor
		 * trifft zuverlaessig den falschen - der Dialog zeigt dann weiter
		 * "0 Zeilen" und niemand sieht, warum.
		 */
		async setFile(filePath) {
			// Der Inhalt wandert als Text in die Seite, dort entsteht ein echtes
			// File-Objekt und wird ueber ein DataTransfer in das Feld gelegt.
			//
			// Warum nicht DOM.setFileInputFiles: fuer das Datei-Feld der
			// Mitgliederliste meldet der Aufruf Erfolg, das Feld bleibt aber leer
			// (nachgemessen: derselbe Knoten, noch im DOM, files.length 0). Beim
			// versteckten Feld des Kontoauszug-Imports funktioniert derselbe
			// Aufruf. Der Weg ueber die Seite ist von dieser Eigenheit unabhaengig
			// und loest zusaetzlich das change-Ereignis genauso aus wie eine echte
			// Auswahl.
			const inhalt = readFileSync(filePath, 'utf8');
			const name = filePath.replace(/\\/g, '/').split('/').pop();
			const ok = await evaluate(`(() => {
				const modal = Array.from(document.querySelectorAll('.modal-container'))
					.filter(e => e.getClientRects().length > 0)[0];
				if (!modal) throw new Error('Kein sichtbarer Dialog');
				const input = modal.querySelector('input[type=file]');
				if (!input) throw new Error('Kein Datei-Feld im sichtbaren Dialog');
				const daten = new DataTransfer();
				daten.items.add(new File([${JSON.stringify(inhalt)}], ${JSON.stringify(name)}, { type: 'text/csv' }));
				input.files = daten.files;
				// Erst pruefen, dann melden: manche Handler leeren das Feld beim
				// change-Ereignis wieder (sie merken sich die Datei selbst). Eine
				// Pruefung danach saehe immer nach Misserfolg aus.
				const gesetzt = input.files.length === 1;
				input.dispatchEvent(new Event('input', { bubbles: true }));
				input.dispatchEvent(new Event('change', { bubbles: true }));
				return gesetzt;
			})()`);
			if (!ok) throw new Error(`Datei ${name} liess sich nicht in das Datei-Feld legen`);
			await sleep(700);
			return true;
		},

		/**
		 * Sichtbaren Dialog schliessen. Der Import-Dialog bleibt nach dem
		 * Uebernehmen absichtlich mit seinem Ergebnis stehen - ohne diesen
		 * Schritt wartet die Szene ewig darauf, dass er von selbst verschwindet.
		 */
		async closeModal() {
			const knopf = `(() => {
				const modal = Array.from(document.querySelectorAll('.modal-container'))
					.filter(e => e.getClientRects().length > 0)[0];
				if (!modal) return null;
				const schliessen = Array.from(modal.querySelectorAll('button'))
					.filter(e => e.getClientRects().length > 0)
					.find(e => /Schließen|Close|Abbrechen|Cancel|Fertig|Done/i.test(e.textContent || ''));
				if (schliessen) return schliessen;
				// Das X des Dialogs sitzt ausserhalb von .modal-container, aber im
				// selben .modal-wrapper. Nur sichtbare nehmen: die verlassenen
				// Dialoge dieser App haben ihr eigenes, unsichtbares X.
				const huelle = modal.closest('.modal-wrapper') || document;
				return Array.from(huelle.querySelectorAll('.modal-container__close, .modal-header button'))
					.filter(e => e.getClientRects().length > 0)[0] || null;
			})()`;
			const da = await evaluate(`${knopf} !== null`);
			if (!da) return false;
			const box = await evaluate(`(() => {
				const r = ${knopf}.getBoundingClientRect();
				return { x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2) };
			})()`);
			await evaluate(`(() => {
				const o = window.__vbhOverlay;
				o.cursor.style.opacity = '1';
				o.cursor.style.transform = 'translate(${box.x}px, ${box.y}px)';
				return true;
			})()`);
			await sleep(540);
			await ctx.pulse();
			await evaluate(`(() => { ${knopf}.click(); return true; })()`);
			await sleep(600);
			return true;
		},

		/**
		 * Alle offenen Dialoge schliessen - ohne Cursor, ohne Pause.
		 *
		 * Laeuft vor jeder Szene: Szene 02 endet zum Beispiel absichtlich mit
		 * geoeffnetem Buchungsdialog (der Experten-Modus ist der letzte Blick),
		 * und Szene 03 wartete danach vergeblich darauf, dass "kein Dialog offen"
		 * eintritt. Jede Szene faengt so an, wie sie es erwartet.
		 */
		async closeAllModals(maxVersuche = 4) {
			for (let i = 0; i < maxVersuche; i++) {
				const offen = await evaluate(`__vbhVisible('.modal-container').length > 0`);
				if (!offen) return i;
				await evaluate(`(() => {
					const modal = window.__vbhVisible('.modal-container')[0];
					const abbrechen = Array.from(modal.querySelectorAll('button'))
						.filter(e => e.getClientRects().length > 0)
						.find(e => /Abbrechen|Cancel|Schließen|Close/i.test(e.textContent || ''));
					if (abbrechen) { abbrechen.click(); return 'knopf'; }
					const huelle = modal.closest('.modal-wrapper') || document;
					const x = Array.from(huelle.querySelectorAll('.modal-container__close, .modal-header button'))
						.filter(e => e.getClientRects().length > 0)[0];
					if (x) { x.click(); return 'x'; }
					return 'nichts';
				})()`);
				await sleep(650);
			}
			return maxVersuche;
		},

		/** Overlay neu aufbauen - nach jedem Seitenwechsel noetig. */
		async reinstallOverlay() {
			await cdp.evaluate(tab, OVERLAY_SETUP);
		},

		/**
		 * Zeigt eine App-Seite in einem Rahmen ueber der Oberflaeche - als
		 * "neuer Tab" (mode 'page') oder im Handyrahmen (mode 'phone').
		 *
		 * Warum ein iframe und keine echte Navigation: die Bildschirmaufnahme
		 * laeuft an einem Tab, ein Seitenwechsel mitten in der Szene wuerde sie
		 * unterbrechen und das Overlay verlieren. Der Rahmen ist gleiche
		 * Herkunft, also voll steuerbar (contentDocument).
		 */
		async showFrame(url, { mode = 'page', width = 390, height = 844, inline = false } = {}) {
			// Die Druckseiten (Kassenbericht, Prüfleitfaden) senden
			// "frame-ancestors 'none'" - sie lassen sich also gar nicht einbetten.
			// Dann wird der Inhalt geholt und als srcdoc gesetzt: das ist kein
			// eigener Seitenabruf mehr, die Richtlinie greift nicht, und das
			// Dokument bleibt zugaenglich (gleiche Herkunft).
			const srcdoc = inline
				? await evaluate(`(async () => (await fetch(${JSON.stringify(url)})).text())()`)
				: null;
			await evaluate(`(() => {
				const alt = document.getElementById('vbh-video-frame');
				if (alt) alt.remove();

				const huelle = document.createElement('div');
				huelle.id = 'vbh-video-frame';
				Object.assign(huelle.style, {
					position: 'fixed', inset: '0', zIndex: '99000',
					display: 'flex', alignItems: 'center', justifyContent: 'center',
					background: ${mode === 'phone'
						? `'linear-gradient(140deg, #123457 0%, ${COLORS.dark} 70%)'`
						: `'rgba(6,16,32,.75)'`},
					opacity: '0', transition: 'opacity 420ms ease-out',
				});

				const frame = document.createElement('iframe');
				frame.id = 'vbh-video-iframe';
				${inline ? `frame.srcdoc = ${JSON.stringify(srcdoc)};` : `frame.src = ${JSON.stringify(url)};`}

				${mode === 'phone' ? `
				const geraet = document.createElement('div');
				Object.assign(geraet.style, {
					width: '${width + 24}px', height: '${height + 24}px', borderRadius: '52px',
					background: '#0d1117', padding: '12px',
					boxShadow: '0 40px 90px rgba(0,0,0,.55), inset 0 0 0 2px #2b3a4a',
					transform: 'scale(1)', transformOrigin: 'center',
				});
				Object.assign(frame.style, {
					width: '${width}px', height: '${height}px', border: '0',
					borderRadius: '42px', background: '#fff',
				});
				geraet.appendChild(frame);
				huelle.appendChild(geraet);
				` : `
				Object.assign(frame.style, {
					width: '1700px', height: '950px', border: '0', borderRadius: '7px',
					background: '#fff', boxShadow: '0 30px 80px rgba(0,0,0,.5)',
				});
				huelle.appendChild(frame);
				`}

				document.body.appendChild(huelle);
				requestAnimationFrame(() => { huelle.style.opacity = '1'; });
				return true;
			})()`);
			// Auf den Inhalt warten, nicht auf eine feste Zeit.
			await cdp.waitFor(tab, `(() => {
				const f = document.getElementById('vbh-video-iframe');
				return !!(f && f.contentDocument && f.contentDocument.body && f.contentDocument.body.children.length > 0);
			})()`, 20000);

			// Nach dem Einfuegen nachmessen statt der gerechneten Skalierung zu
			// vertrauen. Gemessen wird die *unskalierte* Hoehe: die Polsterung des
			// Rahmens zaehlt bei content-box mit (868 + 24 = 892), und ein Faktor,
			// der auf die bereits skalierte Hoehe angewandt wird, macht den Rahmen
			// groesser statt kleiner - genau das ist hier passiert.
			if (mode === 'phone') {
				const rand = await evaluate(`(() => {
					const geraet = document.getElementById('vbh-video-frame').firstElementChild;
					geraet.style.transform = 'none';
					const roh = geraet.getBoundingClientRect().height;
					const platz = window.innerHeight - 72;
					const skala = Math.min(1, platz / roh);
					geraet.style.transform = 'scale(' + skala + ')';
					const r = geraet.getBoundingClientRect();
					return { oben: Math.round(r.top), hoehe: Math.round(r.height), roh: Math.round(roh), skala: Number(skala.toFixed(3)) };
				})()`);
				if (rand.oben < 12) {
					throw new Error(`Handyrahmen passt nicht ins Bild (oben ${rand.oben}px, Hoehe ${rand.hoehe}px von ${rand.roh}px)`);
				}
			}
			await sleep(900);
		},

		/** Weiches Scrollen im Rahmen. */
		async scrollFrame(y, { duration = 2200 } = {}) {
			await evaluate(`(() => {
				const doc = document.getElementById('vbh-video-iframe').contentDocument;
				const start = doc.documentElement.scrollTop || doc.body.scrollTop || 0;
				const distanz = ${y} - start;
				const t0 = performance.now();
				function schritt(now) {
					const p = Math.min(1, (now - t0) / ${duration});
					const eased = p < .5 ? 2 * p * p : 1 - Math.pow(-2 * p + 2, 2) / 2;
					doc.documentElement.scrollTop = start + distanz * eased;
					doc.body.scrollTop = start + distanz * eased;
					if (p < 1) requestAnimationFrame(schritt);
				}
				requestAnimationFrame(schritt);
				return true;
			})()`);
			await sleep(duration + 150);
		},

		/** Klick im Rahmen, per Text gesucht. */
		async clickInFrame(text, tag = 'button') {
			const ok = await evaluate(`(() => {
				const doc = document.getElementById('vbh-video-iframe').contentDocument;
				const el = Array.from(doc.querySelectorAll(${JSON.stringify(tag)}))
					.filter(e => e.getClientRects().length > 0)
					.find(e => (e.textContent || '').trim().includes(${JSON.stringify(text)}));
				if (!el) return false;
				el.click();
				return true;
			})()`);
			if (!ok) throw new Error(`Im Rahmen nicht gefunden: "${text}" (${tag})`);
			await sleep(700);
		},

		async hideFrame({ duration = 400 } = {}) {
			await evaluate(`(() => {
				const h = document.getElementById('vbh-video-frame');
				if (!h) return true;
				h.style.opacity = '0';
				setTimeout(() => h.remove(), ${duration + 100});
				return true;
			})()`);
			await sleep(duration + 150);
		},

		/** Wartet auf eine Bedingung im Seitenkontext. */
		waitFor: (expression, timeoutMs = 15000) => cdp.waitFor(tab, expression, timeoutMs),
	};

	return ctx;
}

/**
 * Selektor-Ausdruck: Strings sind CSS-Selektoren, Objekte erlauben
 * Textsuche - `{ text: 'Beiträge', tag: 'button' }`.
 *
 * Wichtig ist der Sichtbarkeitsfilter: verlassene Reiter bleiben per v-show im
 * DOM stehen. `.first()` ohne Filter trifft dann das unsichtbare Element - der
 * Bug-Magnet der Vorgaengerproduktion.
 */
function selectorExpression(selector) {
	if (typeof selector === 'string') {
		return `(() => {
			const alle = Array.from(document.querySelectorAll(${JSON.stringify(selector)}));
			return alle.find(e => e.getClientRects().length > 0) || null;
		})()`;
	}
	const { text, tag = '*', exact = false, nth = 0 } = selector;
	return `(() => {
		const treffer = Array.from(document.querySelectorAll(${JSON.stringify(tag)}))
			.filter(e => e.getClientRects().length > 0)
			.filter(e => {
				const t = (e.textContent || '').trim();
				return ${exact ? `t === ${JSON.stringify(text)}` : `t.includes(${JSON.stringify(text)})`};
			});
		// Innerstes Element bevorzugen: eine Textsuche trifft sonst den
		// Container, und der Cursor faehrt in die Mitte des halben Bildschirms.
		// Kuerzester Textinhalt ist dafuer das robusteste Mass.
		treffer.sort((a, b) => (a.textContent || '').length - (b.textContent || '').length);
		return treffer[${nth}] || null;
	})()`;
}

export { selectorExpression };

/** Loescht ein Verzeichnis, ohne zu meckern, wenn es nicht existiert. */
export function resetDir(dir) {
	if (existsSync(dir)) rmSync(dir, { recursive: true, force: true });
	mkdirSync(dir, { recursive: true });
}
