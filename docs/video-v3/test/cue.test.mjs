import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { validateContent, estimateFilmSeconds } from '../lib/cue.mjs';

/** Kleines Projektverzeichnis mit content/xx.json und Wortzeiten, eine Szene. */
function projekt(vo, zooms) {
	const dir = mkdtempSync(join(tmpdir(), 'vbh-cue-'));
	mkdirSync(join(dir, 'content'));
	mkdirSync(join(dir, 'build', 'xx', 'vo'), { recursive: true });
	const scene = { id: '03-beitraege', lowerThird: 'x', callouts: [], zooms, vo };
	writeFileSync(join(dir, 'content', 'xx.json'), JSON.stringify({ lang: 'xx', scenes: [scene] }));
	const words = vo.split(' ').map((text, i) => ({ text, start: i * 0.4, end: i * 0.4 + 0.35 }));
	writeFileSync(join(dir, 'build', 'xx', 'vo', '03-beitraege.words.json'), JSON.stringify(words));
	return dir;
}

test('doppelter Zoom-Anker wird gemeldet', () => {
	const dir = projekt('Der Beitrag ist fällig, der Beitrag kommt', [{ cue: 'Beitrag', target: 'einzugssumme' }]);
	const res = validateContent('xx', { dir });
	assert.equal(res.ok, false);
	assert.ok(res.problems.some((p) => p.includes('Beitrag')), res.problems.join('\n'));
});

test('eindeutiger Zoom-Anker ist ok und die Laenge wird gemeldet', () => {
	const dir = projekt('Der Beitrag ist fällig', [{ cue: 'Beitrag', target: 'einzugssumme' }]);
	const res = validateContent('xx', { dir });
	assert.equal(res.ok, true, res.problems.join('\n'));
	// eine Szene: 1,55 s Sprache + 0,55 Vorlauf + 0,9 Nachlauf
	assert.ok(Math.abs(res.totalSeconds - 3.0) < 1e-9, String(res.totalSeconds));
});

test('estimateFilmSeconds: acht Szenen a 15 s', () => {
	const timing = Array.from({ length: 8 }, () => ({ duration: 15 }));
	const t = estimateFilmSeconds(timing, { head: 0.55, tail: 0.9, xfade: 0.45 });
	assert.ok(Math.abs(t - 128.45) < 1e-9, String(t));
});
