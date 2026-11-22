import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createManifest, addShot, addTarget, addClip, toVideoRect, writeManifest } from '../lib/manifest.mjs';

test('toVideoRect: gleiches Ausgaberechteck bei Faktor 1 und 2', () => {
	const rect = { x: 100, y: 50, width: 300, height: 40 };
	assert.deepEqual(toVideoRect(rect, 1), { x: 100, y: 50, w: 300, h: 40 });
	assert.deepEqual(toVideoRect(rect, 2), { x: 100, y: 50, w: 300, h: 40 });
});

test('addTarget: doppelter Name je Quelle wirft', () => {
	const m = createManifest({ sceneId: '02-geld', lang: 'de', head: 0.55, duration: 20, scale: 2 });
	addTarget(m, { name: 'dubletten', t: 3, rect: { x: 1, y: 2, width: 3, height: 4 }, source: 'board' });
	assert.throws(() => addTarget(m, { name: 'dubletten', t: 5, rect: { x: 1, y: 2, width: 3, height: 4 }, source: 'board' }), /dubletten/);
	// Gleicher Name im zweiten Browser ist ein anderes Ziel.
	addTarget(m, { name: 'dubletten', t: 5, rect: { x: 1, y: 2, width: 3, height: 4 }, source: 'member' });
});

test('writeManifest: clips und nach t sortierte shots', () => {
	const m = createManifest({ sceneId: '03-beitraege', lang: 'de', head: 0.55, duration: 24, scale: 1 });
	addClip(m, { source: 'board', file: '03-beitraege.mp4' });
	addShot(m, { id: 'b', t: 7.5, source: 'board' });
	addShot(m, { id: 'a', t: 1.25, source: 'board' });
	const file = join(mkdtempSync(join(tmpdir(), 'vbh-man-')), 'm.json');
	writeManifest(file, m);
	const json = JSON.parse(readFileSync(file, 'utf8'));
	assert.deepEqual(json.clips, [{ source: 'board', file: '03-beitraege.mp4' }]);
	assert.deepEqual(json.shots.map((s) => s.id), ['a', 'b']);
	assert.equal(json.sceneId, '03-beitraege');
	assert.equal(json.scale, 1);
});
