import { describe, expect, it } from 'vitest'
import { createHeaderCollapse } from './headerCollapse.js'

// Eine lange Liste: 3000 px Inhalt in einem 500 px hohen Scrollbereich,
// der Kopf darüber ist 56 px hoch.
function metrics(scrollTop, extra = {}) {
	return { scrollTop, scrollHeight: 3000, clientHeight: 500, headerHeight: 56, ...extra }
}

// Scrollt in kleinen Schritten, wie es Scroll-Ereignisse beim Wischen tun.
function scrollTo(tracker, from, to, extra = {}) {
	const step = from < to ? 4 : -4
	let hidden
	for (let top = from + step; step > 0 ? top <= to : top >= to; top += step) {
		hidden = tracker.update(metrics(top, extra))
	}
	return hidden
}

describe('createHeaderCollapse', () => {
	it('blendet nach genug Scrollweg nach unten aus, auch in kleinen Schritten', () => {
		const tracker = createHeaderCollapse()
		tracker.update(metrics(0))
		expect(scrollTo(tracker, 0, 200)).toBe(true)
	})

	it('blendet nicht aus, solange der Kopf selbst noch nicht weggescrollt wäre', () => {
		const tracker = createHeaderCollapse()
		tracker.update(metrics(0))
		expect(scrollTo(tracker, 0, 40)).toBe(false)
	})

	it('blendet beim Hochscrollen wieder ein', () => {
		const tracker = createHeaderCollapse()
		tracker.update(metrics(0))
		scrollTo(tracker, 0, 800)
		expect(scrollTo(tracker, 800, 760)).toBe(false)
	})

	it('zittert nicht: ein paar Pixel zurück lassen den Kopf ausgeblendet', () => {
		const tracker = createHeaderCollapse()
		tracker.update(metrics(0))
		scrollTo(tracker, 0, 800)
		expect(scrollTo(tracker, 800, 792)).toBe(true)
		expect(scrollTo(tracker, 792, 900)).toBe(true)
	})

	it('zeigt den Kopf ganz oben immer', () => {
		const tracker = createHeaderCollapse()
		tracker.update(metrics(0))
		scrollTo(tracker, 0, 800)
		expect(tracker.update(metrics(0))).toBe(false)
	})

	it('blendet bei knapp überlanger Liste gar nicht erst aus', () => {
		// 580 px Inhalt in 500 px: ohne Kopf passte fast alles, der Browser
		// würde nach oben springen und der Kopf käme sofort zurück.
		const tracker = createHeaderCollapse()
		tracker.update(metrics(0, { scrollHeight: 580 }))
		expect(scrollTo(tracker, 0, 80, { scrollHeight: 580 })).toBe(false)
	})

	it('blendet bei mittellanger Liste aus, wenn danach noch Scrollweg bleibt', () => {
		// 640 px in 500 px: 140 px Scrollweg, ohne Kopf bleiben 84 px.
		const tracker = createHeaderCollapse()
		tracker.update(metrics(0, { scrollHeight: 640 }))
		expect(scrollTo(tracker, 0, 120, { scrollHeight: 640 })).toBe(true)
	})

	it('rechnet bei ausgeblendetem Kopf mit dem gewonnenen Platz', () => {
		// Ausgeblendet ist der Scrollbereich um die Kopfhöhe größer; dieselbe
		// Liste darf deshalb nicht plötzlich als "zu kurz" gelten.
		const tracker = createHeaderCollapse()
		tracker.update(metrics(0))
		scrollTo(tracker, 0, 400)
		expect(tracker.update(metrics(404, { clientHeight: 556 }))).toBe(true)
	})

	it('sync() übernimmt einen Scrollstand, ohne ihn als Scrollweg zu zählen', () => {
		// Während der Kopf weggleitet, kann der Browser den Scrollstand
		// verschieben (am Listenende wird der Bereich größer). Das ist keine
		// Bewegung des Nutzers und darf den Kopf nicht zurückholen.
		const tracker = createHeaderCollapse()
		tracker.update(metrics(0))
		scrollTo(tracker, 0, 800)
		tracker.sync(744)
		expect(tracker.hidden).toBe(true)
		expect(tracker.update(metrics(748))).toBe(true)
	})

	it('fängt nach reset() mit sichtbarem Kopf neu an', () => {
		const tracker = createHeaderCollapse()
		tracker.update(metrics(0))
		scrollTo(tracker, 0, 800)
		tracker.reset()
		expect(tracker.hidden).toBe(false)
		// Ein anderer Scrollbereich beginnt mitten im Inhalt: der erste Wert ist
		// nur der Ausgangspunkt, kein Scrollweg.
		expect(tracker.update(metrics(1500))).toBe(false)
		expect(scrollTo(tracker, 1500, 1560)).toBe(true)
	})
})
