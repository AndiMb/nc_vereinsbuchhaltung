import api from '../api.js'

/**
 * Legt ein SEPA-Mandat für ein Mitglied an (Spec §3.1 Schritt 2 „Mandat“) –
 * gemeinsam genutzt vom Aufnahme-Assistenten (MembersList.vue) und vom
 * Mandat-Bereich der Mitglieder-Akte (MandatePanel.vue, Issue #100), damit
 * beide dieselbe Regel anwenden:
 *
 * - `papier`: das Unterschriftsdatum IST das Aktivierungs-Gate – mit Datum
 *   wird das Mandat sofort aktiv, ohne bleibt es Entwurf („Unterschrift
 *   fehlt“).
 * - `elektronisch`: Entwurf plus sofortiger Einmal-Link an die Mailadresse
 *   des Mitglieds (Issue #67); aktiv wird es erst mit der Zustimmung.
 *
 * Schlägt ein späterer Schritt fehl (Aktivierung, Versand), bleibt der schon
 * angelegte Entwurf stehen – der Aufrufer lädt dann neu und sagt in seiner
 * Fehlermeldung, was entstanden ist.
 *
 * @param {number} memberId
 * @param {{signatureType: string, iban: string, bic: ?string, accountHolder: ?string, mandateReference: ?string, signedAt: ?string}} mandate
 * @return {Promise<object>} das angelegte Mandat (im Zustand direkt nach dem Anlegen)
 */
export async function createMandateForMember(memberId, mandate) {
	if (mandate.signatureType === 'elektronisch') {
		const { data } = await api.createMandateElectronic({
			memberId,
			iban: mandate.iban,
			bic: mandate.bic,
			accountHolder: mandate.accountHolder,
			mandateReference: mandate.mandateReference,
		})
		await api.sendMandateActivationLink(data.id)
		return data
	}
	const { data } = await api.createMandate({
		memberId,
		iban: mandate.iban,
		bic: mandate.bic,
		accountHolder: mandate.accountHolder,
		mandateReference: mandate.mandateReference,
		signedAt: mandate.signedAt,
	})
	if (mandate.signedAt) {
		await api.activateMandate(data.id)
	}
	return data
}
