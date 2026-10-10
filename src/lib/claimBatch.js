/**
 * Legt dieselbe manuelle Einzelforderung für mehrere Mitglieder an.
 *
 * Je Mitglied ein eigener Aufruf der vorhandenen Schnittstelle: ein einzelner
 * Fehler (etwa ein inzwischen gelöschtes Mitglied) verhindert die übrigen
 * nicht, und der Server prüft jede Forderung wie bei einer einzelnen.
 * Nacheinander statt gleichzeitig, damit die Reihenfolge der Auswahl erhalten
 * bleibt und der Server nicht mit einem Schwung Anfragen überrollt wird.
 *
 * @param {(data: object) => Promise<unknown>} createClaim legt eine Forderung an (api.createClaim)
 * @param {{memberIds: number[], type: string, amount: string|number, label: string, dueDate: string}} form
 * @param {(error: unknown) => string} errorText macht aus einem Fehler den Klartext für die Anzeige
 * @return {Promise<{created: number[], failed: {memberId: number, message: string}[]}>}
 */
export async function createClaimsForMembers(createClaim, form, errorText) {
	const created = []
	const failed = []
	for (const memberId of form.memberIds) {
		try {
			await createClaim({
				memberId,
				type: form.type,
				amount: form.amount,
				label: form.label,
				dueDate: form.dueDate,
			})
			created.push(memberId)
		} catch (e) {
			failed.push({ memberId, message: errorText(e) })
		}
	}
	return { created, failed }
}
