import { test } from 'node:test';
import assert from 'node:assert/strict';
import { keepOutsideGaps } from '../lib/record.mjs';

const frames = [0, 0.5, 1.0, 1.5, 2.0, 2.5].map((t) => ({ timestamp: 100 + t }));

test('keepOutsideGaps: Bilder im Seitenwechsel fallen weg, Grenzen halboffen', () => {
	const kept = keepOutsideGaps(frames, 100, [{ start: 1.0, end: 2.0 }]);
	assert.deepEqual(kept.map((f) => f.timestamp - 100), [0, 0.5, 2.0, 2.5]);
});

test('keepOutsideGaps: ohne Luecken unveraendert, alles weg -> erstes Bild bleibt', () => {
	assert.equal(keepOutsideGaps(frames, 100, []), frames);
	assert.deepEqual(keepOutsideGaps(frames, 100, [{ start: -1, end: 9 }]), [frames[0]]);
});
