import { reactive } from 'vue'

/**
 * Bitte an die Mitgliederliste (MembersList.vue), die Akte eines Mitglieds zu
 * öffnen - von Stellen außerhalb des Beiträge-Reiters, die selbst nichts von
 * der Akte wissen: das Aufgaben-Flyout in der Kopfzeile (TasksFlyout.vue,
 * Issue #99) wechselt den Reiter und hinterlässt hier die Mitglieds-ID.
 *
 * Eine Anfrage statt eines Funktionsaufrufs, weil die Liste beim Wechsel
 * womöglich noch gar nicht (fertig) geladen ist: sie liest die Anfrage,
 * sobald sie kann, und räumt sie dabei ab. So öffnet die Akte genau einmal -
 * nicht später noch einmal von selbst, wenn die Liste neu lädt.
 */
const request = reactive({
	memberId: null,
})

/** Bittet die Mitgliederliste, die Akte dieses Mitglieds zu öffnen. */
function requestMemberAkte(memberId) {
	request.memberId = memberId
}

/** Liest die Anfrage und räumt sie ab (null, wenn keine ansteht). */
function takeMemberAkteRequest() {
	const memberId = request.memberId
	request.memberId = null
	return memberId
}

export function useMemberAkteRequest() {
	return { request, requestMemberAkte, takeMemberAkteRequest }
}
