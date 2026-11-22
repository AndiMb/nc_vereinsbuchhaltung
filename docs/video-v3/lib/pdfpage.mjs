// PDF-Seite als Bild fuer die Aufnahme: der Headless-Browser zeigt keine PDFs
// an. Gerendert wird die echte Datei der App (z. B. die Rechnung mit GiroCode).
import { execFileSync } from 'node:child_process';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const script = join(dirname(fileURLToPath(import.meta.url)), 'py', 'pdfpage.py');

/** @return {{page:{w:number,h:number}, box:{x:number,y:number,w:number,h:number}|null}} in PDF-Punkten */
export function renderPdfPage(pdfPath, pngPath, { dpi = 220, findText }) {
	const out = execFileSync('python', ['-I', script, pdfPath, pngPath, String(dpi), findText], { encoding: 'utf8' });
	return JSON.parse(out.trim().split('\n').pop());
}
