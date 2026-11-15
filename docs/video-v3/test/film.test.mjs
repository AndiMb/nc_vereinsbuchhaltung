import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, writeFileSync, existsSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { writeGenerated } from '../lib/film.mjs';

const files = () => ({ 'index.html': '<html>a</html>', 'compositions/b.html': '<template>b</template>' });

test('writeGenerated: erster Lauf schreibt Dateien und .generated.json', () => {
	const out = join(mkdtempSync(join(tmpdir(), 'vbh-film-')), 'de');
	writeGenerated(out, files());
	assert.equal(readFileSync(join(out, 'index.html'), 'utf8'), '<html>a</html>');
	const sums = JSON.parse(readFileSync(join(out, '.generated.json'), 'utf8'));
	assert.deepEqual(Object.keys(sums).sort(), ['compositions/b.html', 'index.html']);
});

test('writeGenerated: von Hand geaenderte Datei -> zweiter Lauf wirft und nennt sie', () => {
	const out = join(mkdtempSync(join(tmpdir(), 'vbh-film-')), 'de');
	writeGenerated(out, files());
	writeFileSync(join(out, 'compositions/b.html'), '<template>von Hand</template>');
	assert.throws(() => writeGenerated(out, files()), /compositions\/b\.html/);
	assert.equal(readFileSync(join(out, 'compositions/b.html'), 'utf8'), '<template>von Hand</template>');
});

test('writeGenerated: force sichert die alte Fassung und generiert neu', () => {
	const parent = mkdtempSync(join(tmpdir(), 'vbh-film-'));
	const out = join(parent, 'de');
	writeGenerated(out, files());
	writeFileSync(join(out, 'compositions/b.html'), '<template>von Hand</template>');
	writeGenerated(out, files(), { force: true });
	const bak = readdirSync(parent).find((n) => n.startsWith('de.bak-'));
	assert.ok(bak, 'Backup-Ordner fehlt');
	assert.equal(readFileSync(join(parent, bak, 'compositions/b.html'), 'utf8'), '<template>von Hand</template>');
	assert.equal(readFileSync(join(out, 'compositions/b.html'), 'utf8'), '<template>b</template>');
	assert.ok(existsSync(join(out, '.generated.json')));
});

test('zoomTransform: Ziel in die Bildmitte, Bildrand bleibt bedeckt', async () => {
	const { zoomTransform } = await import('../lib/film.mjs');
	// Ziel in der Mitte: reine Vergroesserung um die Mitte.
	assert.deepEqual(zoomTransform({ x: 860, y: 490, w: 200, h: 100 }, 2), { x: -960, y: -540, scale: 2 });
	// Ziel oben links: verschoben wird nur bis zum Rand, nie darueber hinaus.
	assert.deepEqual(zoomTransform({ x: 0, y: 0, w: 100, h: 50 }, 2), { x: 0, y: 0, scale: 2 });
	// Ziel unten rechts.
	assert.deepEqual(zoomTransform({ x: 1820, y: 1030, w: 100, h: 50 }, 2), { x: -1920, y: -1080, scale: 2 });
});

test('memberRect: Rechteck im Mitgliederfenster in Filmkoordinaten', async () => {
	const { memberRect, MEMBER_WINDOW } = await import('../lib/film.mjs');
	const k = MEMBER_WINDOW.w / 1920;
	assert.deepEqual(memberRect({ x: 100, y: 200, w: 400, h: 80 }), {
		x: Math.round(MEMBER_WINDOW.x + 100 * k), y: Math.round(MEMBER_WINDOW.y + 200 * k), w: Math.round(400 * k), h: Math.round(80 * k),
	});
});

import { introShots } from '../lib/film.mjs';

const introInput = () => ({
	intro: [
		{ cue: 'Kasse', scene: 's1', shot: 'liste', offset: 0.5 },
		{ cue: 'Beiträge', scene: 's2', shot: 'a', offset: 0 },
		{ cue: 'Verein', scene: 's1', shot: 'start', offset: 1 },
	],
	titleCue: 'trotzdem',
	at: (w) => ({ Kasse: 0, 'Beiträge': 1.2, Verein: 6.5, trotzdem: 10 })[w],
	manifests: { s1: { shots: [{ id: 'start', t: 0 }, { id: 'liste', t: 20 }] }, s2: { shots: [{ id: 'a', t: 3 }] } },
	head: 0.55,
});

test('introShots: erster Schnitt bei 0, weitere kurz vor ihrem Stichwort, letzter bis unter die Titelkarte', () => {
	const r = introShots(introInput());
	assert.equal(r.titleAt, 10.4);
	assert.deepEqual(r.shots.map((s) => [s.scene, s.start, s.duration, s.mediaStart]), [
		['s1', 0, 1.6, 20.5],
		['s2', 1.6, 5.3, 3],
		['s1', 6.9, 4.1, 1],
	]);
});

test('introShots: unbekannter Shot wirft mit Namen', () => {
	const input = introInput();
	input.intro[1].shot = 'fehlt';
	assert.throws(() => introShots(input), /s2.*fehlt/);
});
