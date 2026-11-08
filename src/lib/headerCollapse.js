/**
 * Mobil: Soll der App-Kopf gerade ausgeblendet sein?
 *
 * Die Reiter scrollen jeweils in einem eigenen Bereich unter dem Kopf. Beim
 * Herunterscrollen gleitet der Kopf weg und gibt der Liste den Platz, beim
 * Hochscrollen kommt er zurück. Entschieden wird über den Scrollweg seit dem
 * letzten Richtungswechsel, nicht über einzelne Ereignisse: beim Wischen kommen
 * viele Ereignisse mit wenigen Pixeln, und ein kurzes Zurückfedern soll den
 * Kopf nicht flackern lassen.
 *
 * Eine Liste, die nur knapp über den Bildschirm reicht, blendet gar nicht erst
 * aus: ohne Kopf passte sie (fast) hinein, der Browser zöge den Scrollstand
 * zurück, und der Kopf käme sofort wieder – eine Schleife.
 */

/** Scrollweg in Pixeln, ab dem die Richtung zählt. */
const THRESHOLD = 24
/** Ganz oben ist der Kopf immer zu sehen. */
const TOP_ZONE = 8
/** So viel Scrollweg muss über die Kopfhöhe hinaus bleiben, damit sich das Ausblenden lohnt. */
const MIN_SLACK = 40

/**
 * update({ scrollTop, scrollHeight, clientHeight, headerHeight }) liefert den
 * neuen Zustand; clientHeight ist die Höhe des Scrollbereichs, so wie er
 * gerade dasteht (bei ausgeblendetem Kopf also um headerHeight größer).
 * sync(scrollTop) übernimmt einen Scrollstand ohne Bewertung, reset() beginnt
 * mit sichtbarem Kopf neu.
 *
 * @return {{ hidden: boolean, update: (m: { scrollTop: number, scrollHeight: number, clientHeight: number, headerHeight: number }) => boolean, sync: (scrollTop: number) => void, reset: () => void }}
 */
export function createHeaderCollapse() {
	const state = {
		hidden: false,
		lastTop: null,
		anchorTop: 0,
		direction: 0,
	}

	function reset() {
		state.hidden = false
		state.lastTop = null
		state.anchorTop = 0
		state.direction = 0
	}

	/** Scrollstand übernehmen, ohne ihn als Bewegung zu werten. */
	function sync(scrollTop) {
		state.lastTop = scrollTop
		state.anchorTop = scrollTop
		state.direction = 0
	}

	function update({ scrollTop, scrollHeight, clientHeight, headerHeight }) {
		if (state.lastTop === null) {
			state.lastTop = scrollTop
			state.anchorTop = scrollTop
			return state.hidden
		}

		const direction = Math.sign(scrollTop - state.lastTop)
		if (direction !== 0 && direction !== state.direction) {
			state.direction = direction
			state.anchorTop = state.lastTop
		}
		state.lastTop = scrollTop

		// Scrollweg, den die Liste bei sichtbarem Kopf hätte.
		const roomWithHeader = scrollHeight - clientHeight + (state.hidden ? headerHeight : 0)
		if (scrollTop <= TOP_ZONE || roomWithHeader < headerHeight + MIN_SLACK) {
			state.hidden = false
			return state.hidden
		}

		const travelled = Math.abs(scrollTop - state.anchorTop)
		if (state.direction > 0 && travelled >= THRESHOLD && scrollTop > headerHeight) {
			state.hidden = true
		} else if (state.direction < 0 && travelled >= THRESHOLD) {
			state.hidden = false
		}
		return state.hidden
	}

	return {
		get hidden() { return state.hidden },
		update,
		sync,
		reset,
	}
}
