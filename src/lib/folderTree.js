// Der Ordnerbaum des Wächter-Ordners (AttachmentFolderDialog.vue).
//
// Der Server liefert die Dateien flach, jede mit ihrem Ordnerpfad relativ zum
// Wächter-Ordner ('' = oberste Ebene, 'a/b' = Unterordner). Hier wird daraus
// ein Baum mit Zählern je Ordner und eine flache Zeilenliste für eine einzige
// v-for – auch bei einigen tausend Dateien ohne rekursive Komponenten.

/**
 * @typedef {object} TreeFile
 * @property {number} fileId Datei-ID in Nextcloud
 * @property {string} name Dateiname
 * @property {string} folder Ordnerpfad, '' = oberste Ebene
 * @property {number[]} journalIds Buchungen, an denen die Datei hängt
 * @property {Array<{journalId: number, entryNo: number|null}>} [bookings] Buchungen mit Nummer (vom Server)
 */

/**
 * @typedef {object} FolderNode
 * @property {string} path z. B. 'Rechnungen/2026'
 * @property {string} name letzter Pfadteil
 * @property {FolderNode[]} folders Unterordner, nach Name
 * @property {TreeFile[]} files Dateien direkt in diesem Ordner, Reihenfolge wie geliefert
 * @property {number} open Dateien ohne Buchung, einschließlich Unterordner
 * @property {number} linked Dateien mit Buchung, einschließlich Unterordner
 */

const collator = new Intl.Collator('de', { numeric: true, sensitivity: 'base' })

/** @param {TreeFile} f */
export function isLinked(f) {
	return (f.journalIds || []).length > 0
}

/**
 * @param {TreeFile[]} files
 * @return {FolderNode} die Wurzel (path '')
 */
export function buildFolderTree(files) {
	const root = { path: '', name: '', folders: [], files: [], open: 0, linked: 0 }
	const byPath = new Map([['', root]])
	const folderFor = (path) => {
		if (byPath.has(path)) { return byPath.get(path) }
		const cut = path.lastIndexOf('/')
		const parent = folderFor(cut < 0 ? '' : path.slice(0, cut))
		const node = { path, name: cut < 0 ? path : path.slice(cut + 1), folders: [], files: [], open: 0, linked: 0 }
		parent.folders.push(node)
		byPath.set(path, node)
		return node
	}
	for (const f of files) {
		folderFor(f.folder || '').files.push(f)
	}
	const count = (node) => {
		node.folders.sort((a, b) => collator.compare(a.name, b.name))
		node.open = node.files.filter((f) => !isLinked(f)).length
		node.linked = node.files.length - node.open
		for (const child of node.folders) {
			count(child)
			node.open += child.open
			node.linked += child.linked
		}
	}
	count(root)
	return root
}

/**
 * Beim Öffnen aufgeklappt: die Ordner, in denen noch offene Belege liegen.
 *
 * @param {FolderNode} root
 * @return {Set<string>}
 */
export function initialExpanded(root) {
	const out = new Set()
	const walk = (node) => {
		for (const child of node.folders) {
			if (child.open > 0) {
				out.add(child.path)
				walk(child)
			}
		}
	}
	walk(root)
	return out
}

/**
 * Passt eine Datei zur Suche? Dateiname, Ordner und Buchungsnummer.
 *
 * @param {TreeFile} f
 * @param {string} s kleingeschriebene Suche
 */
function fileMatches(f, s) {
	if (f.name.toLowerCase().includes(s) || (f.folder || '').toLowerCase().includes(s)) { return true }
	const no = s.replace(/^#/, '')
	return /^\d+$/.test(no) && (f.bookings || []).some((b) => String(b.entryNo ?? '') === no)
}

/**
 * @param {object} opts
 * @param {boolean} opts.onlyOpen zugeordnete Dateien auslassen
 * @param {string} opts.search kleingeschriebene, getrimmte Suche
 * @param {Set<number>} opts.exclude fileIds, die nicht angeboten werden
 * @return {function(TreeFile): boolean}
 */
function fileFilter({ onlyOpen, search, exclude }) {
	return (f) => !exclude.has(f.fileId) && (!onlyOpen || !isLinked(f)) && (!search || fileMatches(f, search))
}

/**
 * Die Dateien ohne Ordnerzeilen, in der Reihenfolge vom Server – die
 * einfache Liste für alle, die den Baum nicht wollen. Gleiche Filter wie
 * flattenTree(), gleiche Zeilenform (Tiefe 0).
 *
 * @param {TreeFile[]} files
 * @param {object} opts
 * @param {boolean} [opts.onlyOpen] zugeordnete Dateien auslassen
 * @param {string} [opts.search] Suche
 * @param {Set<number>} [opts.exclude] fileIds, die nicht angeboten werden
 * @return {Array<{kind: 'file', file: TreeFile, depth: number}>}
 */
export function flatFileRows(files, { onlyOpen = false, search = '', exclude = new Set() }) {
	const visible = fileFilter({ onlyOpen, search: search.trim().toLowerCase(), exclude })
	return files.filter(visible).map((file) => ({ kind: 'file', file, depth: 0 }))
}

/**
 * Die sichtbaren Zeilen des Baums.
 *
 * Mit Suche erscheinen nur passende Dateien samt ihrer Ordner, und diese
 * Ordner sind aufgeklappt, egal was der Nutzer zugeklappt hat. Die Zähler der
 * Ordner bleiben davon unberührt – sie sind die Statusaussage über alle Dateien.
 *
 * @param {FolderNode} root
 * @param {object} opts
 * @param {Set<string>} opts.expanded aufgeklappte Ordnerpfade
 * @param {boolean} [opts.onlyOpen] zugeordnete Dateien und erledigte Ordner auslassen
 * @param {string} [opts.search] Suche
 * @param {Set<number>} [opts.exclude] fileIds, die nicht angeboten werden
 * @return {Array<{kind: 'folder', node: FolderNode, depth: number, expanded: boolean}|{kind: 'file', file: TreeFile, depth: number}>}
 */
export function flattenTree(root, { expanded, onlyOpen = false, search = '', exclude = new Set() }) {
	const s = search.trim().toLowerCase()
	const fileVisible = fileFilter({ onlyOpen, search: s, exclude })
	const rows = []
	// Liefert, ob im Teilbaum etwas sichtbar ist; die Zeilen des Ordners
	// werden erst danach eingefügt, damit leere Ordner wegfallen können.
	const walk = (node, depth) => {
		const own = []
		for (const child of node.folders) {
			if (onlyOpen && child.open === 0) { continue }
			const childRows = walk(child, depth + 1)
			if (childRows === null) { continue }
			const open = s ? true : expanded.has(child.path)
			own.push({ kind: 'folder', node: child, depth, expanded: open })
			if (open) { own.push(...childRows) }
		}
		for (const f of node.files) {
			if (fileVisible(f)) { own.push({ kind: 'file', file: f, depth }) }
		}
		// Ohne Suche und Filter bleibt ein Ordner sichtbar, auch wenn er (nach
		// exclude) nichts mehr anbietet – sonst springen Ordner beim Wählen weg.
		if (!own.length && (s || onlyOpen) && depth > 0) { return null }
		return own
	}
	rows.push(...(walk(root, 0) || []))
	return rows
}
