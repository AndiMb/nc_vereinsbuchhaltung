/**
 * Zwischenspeicher für abgeleitete Tabellenzeilen (Issue #254).
 *
 * Jedes Nachladen liefert neue Objekte vom Server. Ohne Zwischenspeicher
 * entstünden daraus auch lauter neue Zeilenobjekte, und v-memo müsste jede
 * Zeile samt Knöpfen und Aktionsmenü neu patchen. Hier bleibt eine Zeile
 * dasselbe Objekt, solange Kennung, Stand und deps gleich sind.
 *
 * Einträge ohne Stand (version null/undefined) werden immer neu gebaut: ohne
 * Stand lässt sich eine Änderung nicht erkennen.
 *
 * @param {(item: object) => object} build baut die Zeile aus einem Eintrag
 * @param {object} [keys] Zugriffe auf Kennung und Stand eines Eintrags
 * @param {(item: object) => (number|string)} [keys.id] Kennung, Standard item.id
 * @param {(item: object) => (string|null|undefined)} [keys.version] Stand, Standard item.updatedAt
 * @return {(items: Array, deps: string) => Array} liefert die Zeilen in der Reihenfolge der Einträge
 */
export function createRowCache(build, { id = (item) => item.id, version = (item) => item.updatedAt } = {}) {
	let cache = new Map()
	let lastDeps
	return (items, deps) => {
		const reuse = deps === lastDeps
		const next = new Map()
		const rows = items.map((item) => {
			const key = id(item)
			const ver = version(item)
			const hit = reuse && ver !== null && ver !== undefined ? cache.get(key) : undefined
			const row = hit && hit.version === ver ? hit.row : build(item)
			next.set(key, { version: ver, row })
			return row
		})
		cache = next
		lastDeps = deps
		return rows
	}
}
