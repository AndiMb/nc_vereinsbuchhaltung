// Manifest einer aufgenommenen Szene: welche Clips es gibt, wo Einstellungen
// beginnen (shots) und wo im Bild die Zoomziele liegen (targets). Der
// HyperFrames-Generator (lib/film.mjs) baut daraus Schnitt und Zooms.
//
// Rechtecke stehen in Ausgabepixeln eines 1920x1080-Bildes. Der Geraetefaktor
// erhoeht nur die Rohaufloesung des Clips (3840x2160 bei Faktor 2), nicht die
// Koordinaten: CSS-Pixel des 1920x1080-Viewports sind Ausgabepixel.

import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const round = (v) => Math.round(v * 1000) / 1000;

export function createManifest({ sceneId, lang, head, duration, scale }) {
	return { sceneId, lang, head, duration, scale, clips: [], shots: [], targets: [] };
}

export function addClip(manifest, { source, file }) {
	manifest.clips.push({ source, file });
}

export function addShot(manifest, { id, t, source = 'board' }) {
	manifest.shots.push({ id, t: round(t), source });
}

/** @param rect DOMRect-artig in CSS-Pixeln ({x,y,width,height} oder {x,y,w,h}) */
export function addTarget(manifest, { name, t, rect, source = 'board' }) {
	if (manifest.targets.some((x) => x.name === name && x.source === source)) {
		throw new Error(`Zielname "${name}" (${source}) in ${manifest.sceneId} doppelt`);
	}
	manifest.targets.push({ name, t: round(t), source, rect: toVideoRect(rect, manifest.scale) });
}

/** CSS-Rechteck -> Ausgaberechteck; unabhaengig vom Geraetefaktor (siehe Kopf). */
export function toVideoRect(rect, _scale = 1) {
	const w = rect.width ?? rect.w;
	const h = rect.height ?? rect.h;
	return { x: Math.round(rect.x), y: Math.round(rect.y), w: Math.round(w), h: Math.round(h) };
}

export function writeManifest(path, manifest) {
	const out = {
		...manifest,
		shots: [...manifest.shots].sort((a, b) => a.t - b.t),
		targets: [...manifest.targets].sort((a, b) => a.t - b.t),
	};
	mkdirSync(dirname(path), { recursive: true });
	writeFileSync(path, JSON.stringify(out, null, '\t') + '\n', 'utf8');
	return out;
}
