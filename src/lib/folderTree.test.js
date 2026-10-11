import { describe, expect, it } from 'vitest'
import { buildFolderTree, flatFileRows, flattenTree, initialExpanded } from './folderTree.js'

function file(fileId, folder, name, journalIds = [], entryNos = []) {
	return {
		fileId,
		folder,
		name,
		journalIds,
		bookings: journalIds.map((journalId, i) => ({ journalId, entryNo: entryNos[i] ?? null })),
	}
}

const files = [
	file(1, '', 'lose.pdf'),
	file(2, 'Rechnungen', 'r1.pdf', [10], [12]),
	file(3, 'Rechnungen/2026', 'r2.pdf'),
	file(4, 'Rechnungen/2026', 'r3.pdf', [11], [40]),
	file(5, 'Quittungen', 'q1.jpg', [12], [41]),
	file(6, 'Rechnungen/10', 'r10.pdf', [13], [42]),
	file(7, 'Rechnungen/9', 'r9.pdf', [14], [43]),
]

function label(rows) {
	return rows.map((r) => (r.kind === 'folder' ? `${'  '.repeat(r.depth)}[${r.node.name}]` : `${'  '.repeat(r.depth)}${r.file.name}`))
}

describe('buildFolderTree', () => {
	const root = buildFolderTree(files)

	it('zählt offen/zugeordnet einschließlich Unterordner', () => {
		expect([root.open, root.linked]).toEqual([2, 5])
		const rechnungen = root.folders.find((f) => f.name === 'Rechnungen')
		expect([rechnungen.open, rechnungen.linked]).toEqual([1, 4])
		expect(rechnungen.folders.map((f) => f.name)).toEqual(['9', '10', '2026'])
	})

	it('Ordner nach Name, natürliche Zahlenfolge', () => {
		expect(root.folders.map((f) => f.path)).toEqual(['Quittungen', 'Rechnungen'])
	})

	it('aufgeklappt sind nur Ordner mit offenen Belegen', () => {
		expect([...initialExpanded(root)].sort()).toEqual(['Rechnungen', 'Rechnungen/2026'])
	})
})

describe('flattenTree', () => {
	const root = buildFolderTree(files)

	it('zugeklappte Ordner zeigen ihren Inhalt nicht', () => {
		expect(label(flattenTree(root, { expanded: initialExpanded(root) }))).toEqual([
			'[Quittungen]',
			'[Rechnungen]',
			'  [9]',
			'  [10]',
			'  [2026]',
			'    r2.pdf',
			'    r3.pdf',
			'  r1.pdf',
			'lose.pdf',
		])
	})

	it('nur offene: zugeordnete Dateien und erledigte Ordner fallen weg', () => {
		expect(label(flattenTree(root, { expanded: initialExpanded(root), onlyOpen: true }))).toEqual([
			'[Rechnungen]',
			'  [2026]',
			'    r2.pdf',
			'lose.pdf',
		])
	})

	it('Suche klappt die Ordner der Treffer auf, auch zugeklappte', () => {
		expect(label(flattenTree(root, { expanded: new Set(), search: 'Q1' }))).toEqual([
			'[Quittungen]',
			'  q1.jpg',
		])
	})

	it('Suche nach Buchungsnummer', () => {
		expect(label(flattenTree(root, { expanded: new Set(), search: '#40' }))).toEqual([
			'[Rechnungen]',
			'  [2026]',
			'    r3.pdf',
		])
		expect(flattenTree(root, { expanded: new Set(), search: '4' }).filter((r) => r.kind === 'file')).toEqual([])
	})

	it('exclude blendet Dateien aus, Zähler bleiben', () => {
		const rows = flattenTree(root, { expanded: new Set(['Rechnungen', 'Rechnungen/2026']), exclude: new Set([3]) })
		expect(label(rows)).not.toContain('    r2.pdf')
		expect(rows.find((r) => r.kind === 'folder' && r.node.path === 'Rechnungen/2026').node.open).toBe(1)
	})
})

describe('flatFileRows', () => {
	it('liefert nur Dateien in Server-Reihenfolge, ohne Ordnerzeilen', () => {
		expect(label(flatFileRows(files, {}))).toEqual(['lose.pdf', 'r1.pdf', 'r2.pdf', 'r3.pdf', 'q1.jpg', 'r10.pdf', 'r9.pdf'])
	})

	it('filtert wie der Baum nach offen, Suche und Ausschluss', () => {
		expect(label(flatFileRows(files, { onlyOpen: true }))).toEqual(['lose.pdf', 'r2.pdf'])
		expect(label(flatFileRows(files, { search: 'quittungen' }))).toEqual(['q1.jpg'])
		expect(label(flatFileRows(files, { search: '#40' }))).toEqual(['r3.pdf'])
		expect(label(flatFileRows(files, { onlyOpen: true, exclude: new Set([1]) }))).toEqual(['r2.pdf'])
	})
})
