// Minimales PDF 1.4 mit einer Helvetica-Seite und echtem Text (nicht als Bild),
// damit der Belegindex etwas zu lesen hat. Pro Zeile ein Tj; die Byte-Offsets
// der xref-Tabelle werden beim Zusammensetzen gezählt.

/** Text für einen PDF-String: Klammern und Backslash maskieren, Latin-1 (WinAnsi) belassen. */
function escapePdf(text) {
	return text.replace(/\\/g, '\\\\').replace(/\(/g, '\\(').replace(/\)/g, '\\)')
}

/**
 * @param {string[]} lines Textzeilen, von oben nach unten
 * @return {Buffer} die PDF-Datei
 */
export function textPdf(lines) {
	const content = [
		'BT',
		'/F1 12 Tf',
		'14 TL',
		'72 760 Td',
		...lines.map((line) => `(${escapePdf(line)}) Tj T*`),
		'ET',
	].join('\n')

	const objects = [
		'<< /Type /Catalog /Pages 2 0 R >>',
		'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
		'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
		`<< /Length ${Buffer.byteLength(content, 'latin1')} >>\nstream\n${content}\nendstream`,
		'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
	]

	let out = '%PDF-1.4\n'
	const offsets = []
	objects.forEach((body, i) => {
		offsets.push(Buffer.byteLength(out, 'latin1'))
		out += `${i + 1} 0 obj\n${body}\nendobj\n`
	})
	const xrefAt = Buffer.byteLength(out, 'latin1')
	out += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`
	for (const offset of offsets) { out += `${String(offset).padStart(10, '0')} 00000 n \n` }
	out += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xrefAt}\n%%EOF\n`
	return Buffer.from(out, 'latin1')
}
