import { reactive } from 'vue'

/**
 * Bitte an den Einzug-Unterreiter (EinzugPanel.vue), ein bestimmtes Segment zu
 * zeigen - von Stellen außerhalb des Beiträge-Reiters, die selbst nichts von
 * dessen Segmenten wissen: die generische Offene-Posten-Sicht unter Buchungen
 * schickt von einer Beitragsforderung aus ins Segment „Forderungen“, dort
 * eingegrenzt auf das Mitglied der Forderung (Issue #121).
 *
 * Eine Anfrage statt eines Funktionsaufrufs, aus demselben Grund wie bei
 * {@link ./useMemberAkteRequest.js}: der Reiter ist beim Wechsel womöglich noch
 * nicht aufgebaut; wer die Anfrage versteht, liest sie, sobald er kann, und
 * räumt sie dabei ab. So gilt sie genau einmal. Zwei Leser, zwei Felder: das
 * Panel nimmt das Segment, das Segment selbst den Mitglieds-Fokus.
 */
const request = reactive({
	segment: null,
	memberId: null,
})

/**
 * Bittet den Einzug-Unterreiter, das Segment „Forderungen“ zu zeigen.
 *
 * @param {number|null} memberId Mitglied, auf das die Liste einzugrenzen ist (null: keine Eingrenzung)
 */
function requestClaims(memberId = null) {
	request.segment = 'claims'
	request.memberId = memberId
}

/** Liest die Segment-Anfrage und räumt sie ab (null, wenn keine ansteht). */
function takeSegmentRequest() {
	const { segment } = request
	request.segment = null
	return segment
}

/** Liest den Mitglieds-Fokus und räumt ihn ab (null, wenn keiner ansteht). */
function takeMemberFocus() {
	const { memberId } = request
	request.memberId = null
	return memberId
}

export function useEinzugRequest() {
	return { request, requestClaims, takeSegmentRequest, takeMemberFocus }
}
