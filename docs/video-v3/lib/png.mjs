// Vereinslogo als PNG ohne Bildbibliothek: Kreis in der Vereinsfarbe mit einer
// weissen Achtelnote. Die App nimmt kein SVG als Logo an.

import { deflateSync } from 'node:zlib';

const CRC = (() => {
	const t = new Uint32Array(256);
	for (let n = 0; n < 256; n++) {
		let c = n;
		for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
		t[n] = c >>> 0;
	}
	return t;
})();

function crc32(buf) {
	let c = 0xFFFFFFFF;
	for (const b of buf) c = CRC[(c ^ b) & 0xFF] ^ (c >>> 8);
	return (c ^ 0xFFFFFFFF) >>> 0;
}

function chunk(type, data) {
	const len = Buffer.alloc(4);
	len.writeUInt32BE(data.length);
	const td = Buffer.concat([Buffer.from(type, 'ascii'), data]);
	const crc = Buffer.alloc(4);
	crc.writeUInt32BE(crc32(td));
	return Buffer.concat([len, td, crc]);
}

/** RGBA-Pixelpuffer (width*height*4) -> PNG-Datei. */
export function encodePng(width, height, rgba) {
	const raw = Buffer.alloc((width * 4 + 1) * height);
	for (let y = 0; y < height; y++) {
		raw[y * (width * 4 + 1)] = 0;
		rgba.copy(raw, y * (width * 4 + 1) + 1, y * width * 4, (y + 1) * width * 4);
	}
	const ihdr = Buffer.alloc(13);
	ihdr.writeUInt32BE(width, 0);
	ihdr.writeUInt32BE(height, 4);
	ihdr[8] = 8; ihdr[9] = 6; ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;
	return Buffer.concat([
		Buffer.from([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]),
		chunk('IHDR', ihdr),
		chunk('IDAT', deflateSync(raw)),
		chunk('IEND', Buffer.alloc(0)),
	]);
}

/** Logo: Kreis in `hex` mit weisser Achtelnote, kantengeglaettet per 4x4-Abtastung. */
export function logoPng(size = 256, hex = '#0082c9') {
	const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
	const px = Buffer.alloc(size * size * 4);
	const s = size;
	const inCircle = (x, y) => (x - s / 2) ** 2 + (y - s / 2) ** 2 <= (s * 0.48) ** 2;
	const inNote = (x, y) => {
		// Notenkopf: gekippte Ellipse
		const hx = s * 0.42, hy = s * 0.68, a = s * 0.12, bb = s * 0.085, rot = -0.45;
		const dx = x - hx, dy = y - hy;
		const u = dx * Math.cos(rot) + dy * Math.sin(rot);
		const v = -dx * Math.sin(rot) + dy * Math.cos(rot);
		if ((u / a) ** 2 + (v / bb) ** 2 <= 1) return true;
		// Hals
		const sx = hx + a * 0.9;
		if (x >= sx - s * 0.035 && x <= sx && y >= s * 0.22 && y <= hy) return true;
		// Faehnchen
		const fy = y - s * 0.22;
		if (fy >= 0 && fy <= s * 0.22) {
			const left = sx - s * 0.01;
			const right = sx + s * 0.16 * Math.sin((fy / (s * 0.22)) * Math.PI * 0.9);
			const thick = s * 0.07 * (1 - fy / (s * 0.3));
			if (x >= left && x <= right && fy <= s * 0.22 && (x - left) <= (s * 0.16) && fy >= (x - left) * 0.6 - thick && fy <= (x - left) * 0.6 + thick + s * 0.04) return true;
		}
		return false;
	};
	for (let y = 0; y < s; y++) {
		for (let x = 0; x < s; x++) {
			let circle = 0, note = 0;
			for (let sy = 0; sy < 4; sy++) {
				for (let sx = 0; sx < 4; sx++) {
					const fx = x + (sx + 0.5) / 4, fy = y + (sy + 0.5) / 4;
					if (inCircle(fx, fy)) { circle++; if (inNote(fx, fy)) note++; }
				}
			}
			const i = (y * s + x) * 4;
			const wn = note / Math.max(circle, 1);
			px[i] = Math.round(r + (255 - r) * wn);
			px[i + 1] = Math.round(g + (255 - g) * wn);
			px[i + 2] = Math.round(b + (255 - b) * wn);
			px[i + 3] = Math.round((circle / 16) * 255);
		}
	}
	return encodePng(s, s, px);
}
