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
