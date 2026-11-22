import { test } from 'node:test';
import assert from 'node:assert/strict';
import { computeTimeline } from '../lib/timeline.mjs';

// Wortanker-Attrappe: jedes Wort steht bei der angegebenen Sekunde.
const cuesOf = (map) => ({ at: (p) => { if (!(p in map)) throw new Error(`Anker ${p}`); return map[p]; } });

const scene = (id, extra = {}) => ({ id, lowerThird: null, callouts: [], zooms: [], ...extra });
const manifest = (id, extra = {}) => ({ sceneId: id, head: 0.55, duration: 10, scale: 2, clips: [{ source: 'board', file: `${id}.mp4` }], shots: [], targets: [], gaps: [], ...extra });

test('computeTimeline: drei Bloecke a 10 s, Blenden ueberlappen 0,45 s', () => {
	const scenes = [scene('a'), scene('b'), scene('c')];
	const manifests = { a: manifest('a'), b: manifest('b'), c: manifest('c') };
	const tl = computeTimeline({ scenes, timing: { scenes: {} }, manifests, cues: {} });
	assert.deepEqual(tl.blocks.map((b) => b.start), [0, 9.55, 19.1]);
	assert.equal(tl.total, 29.1);
});

test('computeTimeline: Callout-Zeit = HEAD + Wortzeit, relativ zum Block', () => {
	const scenes = [scene('a'), scene('b', { callouts: [{ cue: 'Rolle', text: 'Vorstand' }] })];
	const manifests = { a: manifest('a'), b: manifest('b') };
	const tl = computeTimeline({ scenes, timing: { scenes: {} }, manifests, cues: { b: cuesOf({ Rolle: 2.0 }) } });
	assert.equal(tl.blocks[1].callouts[0].t, 2.55);
	assert.equal(tl.blocks[1].callouts[0].text, 'Vorstand');
});

test('computeTimeline: fehlendes Zoomziel wirft mit Szenen- und Zielname', () => {
	const scenes = [scene('02-geld', { zooms: [{ cue: 'mehrere', target: 'aufteilung' }] })];
	const manifests = { '02-geld': manifest('02-geld') };
	assert.throws(
		() => computeTimeline({ scenes, timing: { scenes: {} }, manifests, cues: { '02-geld': cuesOf({ mehrere: 3 }) } }),
		(e) => /02-geld/.test(e.message) && /aufteilung/.test(e.message),
	);
});

test('computeTimeline: Zoom beginnt nicht vor dem Ziel im Bild', () => {
	const scenes = [scene('06', { zooms: [{ cue: 'Lesezugang', target: 'revisor' }] })];
	const manifests = { '06': manifest('06', { targets: [{ name: 'revisor', t: 9.13, source: 'board', rect: { x: 338, y: 500, w: 898, h: 44 } }] }) };
	const tl = computeTimeline({ scenes, timing: { scenes: {} }, manifests, cues: { '06': cuesOf({ Lesezugang: 8.0 }) } });
	assert.equal(tl.blocks[0].zooms[0].t, 9.13);
	assert.ok(tl.blocks[0].zooms[0].scale > 1 && tl.blocks[0].zooms[0].scale <= 3);
});

test('computeTimeline: Karte ohne Manifest nimmt Sprechdauer + Rand', () => {
	const scenes = [scene('00-auftakt')];
	const tl = computeTimeline({ scenes, timing: { scenes: { '00-auftakt': { duration: 8.55 } } }, manifests: {}, cues: {} });
	assert.equal(tl.blocks[0].duration, 10);
	assert.equal(tl.blocks[0].voStart, 0.55);
});

test('computeTimeline: Mitgliederfenster von member-Shot bis zum naechsten board-Shot', () => {
	const scenes = [scene('04')];
	const manifests = { '04': manifest('04', {
		clips: [{ source: 'board', file: '04.mp4' }, { source: 'member', file: '04.member.mp4' }],
		shots: [{ id: 'x', t: 0, source: 'board' }, { id: 'm', t: 4, source: 'member' }, { id: 'y', t: 7, source: 'board' }],
	}) };
	const tl = computeTimeline({ scenes, timing: { scenes: {} }, manifests, cues: {} });
	assert.deepEqual(tl.blocks[0].memberWindows, [{ start: 4, end: 7 }]);
});
