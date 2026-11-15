import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { demoPass } from '../lib/session.mjs';

test('Passwort aus der Datei, wenn weder Argument noch Umgebung es nennen', () => {
	const dir = mkdtempSync(join(tmpdir(), 'vbh-pass-'));
	const datei = join(dir, '.demo-pass');
	writeFileSync(datei, 'Geheim-123\n', 'utf8');
	const vorher = process.env.VBH_DEMO_PASS;
	delete process.env.VBH_DEMO_PASS;
	try {
		assert.equal(demoPass(null, { file: datei }), 'Geheim-123');
	} finally {
		if (vorher !== undefined) process.env.VBH_DEMO_PASS = vorher;
	}
});

test('Argument schlaegt Datei', () => {
	assert.equal(demoPass('vom-Argument', { file: join(tmpdir(), 'gibt-es-nicht') }), 'vom-Argument');
});
