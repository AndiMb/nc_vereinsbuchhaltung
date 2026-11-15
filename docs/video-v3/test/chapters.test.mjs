import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chapterLines } from '../lib/thumbnail.mjs';

const titles = { a: 'Auftakt', b: 'Geld', c: 'Ende' };

test('chapterLines: beginnt bei 0:00, Marken aus blocks[].start', () => {
	const timeline = { xfade: 0.45, blocks: [{ id: 'a', start: 0 }, { id: 'b', start: 14.7 }, { id: 'c', start: 71.3 }], total: 90 };
	assert.deepEqual(chapterLines(timeline, titles), ['0:00 Auftakt', '0:15 Geld', '1:12 Ende']);
});

test('chapterLines: Kapitel unter 10 s wirft mit Namen', () => {
	const timeline = { xfade: 0.45, blocks: [{ id: 'a', start: 0 }, { id: 'b', start: 14.7 }, { id: 'c', start: 20 }], total: 40 };
	assert.throws(() => chapterLines(timeline, titles), /b/);
});

test('chapterLines: fehlender Kapiteltitel wirft', () => {
	const timeline = { xfade: 0.45, blocks: [{ id: 'a', start: 0 }, { id: 'x', start: 20 }], total: 40 };
	assert.throws(() => chapterLines(timeline, titles), /x/);
});
