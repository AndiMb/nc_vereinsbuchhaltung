// Zeitleiste des Films: wann jeder Block beginnt, wie lang er ist, wann Sprache,
// Callouts, Zooms und das Mitgliederfenster einsetzen. Reine Rechnung ohne
// Dateizugriff - lib/film.mjs liest Manifeste und Wortzeiten und fuellt damit
// die HyperFrames-Vorlage.
//
// Zeiten in den Bloecken (callouts, zooms, memberWindows) sind relativ zum
// Blockbeginn und liegen auf der Clipachse (inklusive HEAD-Vorlauf);
// start und voStart sind absolut.

import { FILM_DEFAULTS } from './cue.mjs';

const W = 1920;
const H = 1080;
/** Anteil der Bildbreite, den ein Zoomziel fuellen soll. */
const FILL = 0.6;
const ZOOM_HOLD = 3;
const CALLOUT_HOLD = 4;

const r2 = (v) => Math.round(v * 100) / 100;
const r3 = (v) => Math.round(v * 1000) / 1000;

/** Vergroesserung, mit der rect ~60 % der Breite fuellt; begrenzt durch Hoehe und Rohaufloesung. */
export function zoomScale(rect, captureScale = 1) {
	const max = captureScale >= 2 ? 2.5 : 1.5;
	const s = Math.min(FILL * W / rect.w, FILL * H / rect.h, max);
	return r2(Math.max(1, s));
}

/**
 * @param {object} p
 * @param {Array} p.scenes      content.<lang>.json scenes
 * @param {object} p.timing     vo/timing.json ({ scenes: { id: { duration } } })
 * @param {Record<string, object>} p.manifests  Szenen-Manifeste je id (fehlt bei reinen Karten)
 * @param {Record<string, {at:(phrase:string)=>number}>} p.cues  Wortzeiten je id
 */
export function computeTimeline({ scenes, timing, manifests, cues, xfade = FILM_DEFAULTS.xfade, head = FILM_DEFAULTS.head, tail = FILM_DEFAULTS.tail }) {
	const blocks = [];
	let start = 0;
	for (const scene of scenes) {
		const m = manifests[scene.id];
		const vo = timing.scenes?.[scene.id];
		const duration = m?.duration ?? (vo ? head + vo.duration + tail : null);
		if (duration == null) throw new Error(`${scene.id}: weder Manifest noch Sprechdauer`);
		const at = (cue) => {
			const c = cues[scene.id];
			if (!c) throw new Error(`${scene.id}: keine Wortzeiten fuer Anker "${cue}"`);
			return r3(head + c.at(cue));
		};

		const callouts = (scene.callouts ?? []).map((c) => ({ t: at(c.cue), text: c.text, hold: c.hold ?? CALLOUT_HOLD }));

		const zooms = (scene.zooms ?? []).map((z) => {
			const target = m?.targets?.find((x) => x.name === z.target);
			if (!target) throw new Error(`${scene.id}: Zoomziel "${z.target}" fehlt im Manifest`);
			// Nie vor dem Ziel im Bild: Seitenwechsel hinter einem Schnitt
			// koennen das Ziel nach seinem Stichwort erst zeigen.
			const t = r3(Math.max(at(z.cue), target.t));
			return { t, target: z.target, source: target.source ?? 'board', rect: target.rect, scale: zoomScale(target.rect, m.scale) };
		}).sort((a, b) => a.t - b.t);
		zooms.forEach((z, i) => {
			const next = zooms[i + 1];
			z.hold = r3(Math.max(0.5, Math.min(ZOOM_HOLD, (next ? next.t : duration) - z.t - 1.0)));
		});

		const memberWindows = [];
		const shots = [...(m?.shots ?? [])].sort((a, b) => a.t - b.t);
		let open = null;
		for (const s of shots) {
			if (s.source === 'member' && open == null) open = s.t;
			else if (s.source !== 'member' && open != null) { memberWindows.push({ start: open, end: s.t }); open = null; }
		}
		if (open != null) memberWindows.push({ start: open, end: r3(duration) });

		blocks.push({
			id: scene.id,
			start: r3(start),
			duration: r3(duration),
			voStart: r3(start + head),
			lowerThird: scene.lowerThird ?? null,
			clips: m?.clips ?? [],
			callouts,
			zooms,
			memberWindows,
		});
		start += duration - xfade;
	}
	const last = blocks[blocks.length - 1];
	return { total: last ? r3(last.start + last.duration) : 0, xfade, blocks };
}
