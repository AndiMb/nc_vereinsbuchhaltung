import { test } from 'node:test';
import assert from 'node:assert/strict';
import { subtitleCues, toSrt } from '../lib/subtitles.mjs';

const scenes = [
	{ id: 'a', vo: 'Erster Satz.' },
	{ id: 'b', vo: 'Zweiter Block beginnt.' },
];
const words = {
	a: [{ text: 'Erster', start: 0, end: 0.4 }, { text: 'Satz', start: 0.45, end: 0.9 }],
	b: [{ text: 'Zweiter', start: 0, end: 0.5 }, { text: 'Block', start: 0.55, end: 0.9 }, { text: 'beginnt', start: 0.95, end: 1.4 }],
};

test('subtitleCues: Zeiten aus der Timeline (voStart je Block)', () => {
	const timeline = { blocks: [{ id: 'a', start: 0, voStart: 0.55 }, { id: 'b', start: 9.55, voStart: 10.1 }] };
	const cues = subtitleCues({ scenes, words, timeline });
	const srt = toSrt(cues);
	assert.match(srt, /00:00:10,100 --> /);
	assert.equal(cues[1].text, 'Zweiter Block beginnt.');
	assert.equal(cues[0].start, 0.55);
});

test('subtitleCues: Block ohne Timeline-Eintrag wirft mit Namen', () => {
	const timeline = { blocks: [{ id: 'a', start: 0, voStart: 0.55 }] };
	assert.throws(() => subtitleCues({ scenes, words, timeline }), /b/);
});
