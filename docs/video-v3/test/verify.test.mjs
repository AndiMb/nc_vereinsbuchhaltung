import { test } from 'node:test';
import assert from 'node:assert/strict';
import { blockKorrelation } from '../lib/verify.mjs';

const RATE = 8000;

// Sprachaehnliches Signal: Silben (0,08-0,2 s Ton) mit Pausen, fest verteilt.
function sprache(sekunden) {
	const x = new Int16Array(Math.round(sekunden * RATE));
	let t = 0.05;
	let i = 0;
	while (t < sekunden - 0.3) {
		const lang = 0.08 + ((i * 37) % 13) / 100;
		const pause = 0.04 + ((i * 53) % 11) / 100;
		const a = Math.round(t * RATE);
		const e = Math.round((t + lang) * RATE);
		for (let k = a; k < e && k < x.length; k++) x[k] = Math.round(8000 * Math.sin(k / 3));
		t += lang + pause;
		i++;
	}
	return x;
}

test('blockKorrelation: Sprache abseits des 0,25-s-Rasters wird erkannt', () => {
	const vo = sprache(12);
	const voStart = 101.131; // liegt 0,13 s neben dem Raster - daran scheiterte die alte Messung
	const film = new Int16Array(Math.round((voStart + 14) * RATE));
	film.set(vo, Math.round(voStart * RATE));
	assert.ok(blockKorrelation(film, vo, voStart) >= 0.95);
});

test('blockKorrelation: an falscher Stelle -> niedrige Korrelation', () => {
	const vo = sprache(12);
	const film = new Int16Array(Math.round(30 * RATE));
	film.set(vo, Math.round(10 * RATE));
	assert.ok(blockKorrelation(film, vo, 4.0) < 0.5);
});
