import { describe, expect, it } from 'vitest'
import {
	activationLinkSentText,
	activationLinkState,
	actorLabel,
	amendmentStatusLabel,
	canSaveDraftCorrection,
	daysUntil,
	documentRowTone,
	draftCorrectionChanged,
	endReasonLabel,
	formatIban,
	formatStamp,
	mandateAccountHint,
	mandateNotices,
	revokeStaffWarning,
	signatureTooOld,
	signatureTypeLabel,
	statusLabel,
	statusTone,
	suspensionOriginLabel,
} from './mandateView.js'

// Anzeigelogik der Mandat-Verwaltung in der Mitglieder-Akte (Issue #100): die
// Fallunterscheidungen, an denen die Oberfläche hängt – Klartext je Zustand,
// Störfall-Hinweise je Zustand (Spec §3.2/§7), Link-Zustand, 36-Monats-Warnung.

const TODAY = '2026-09-20'

function mandate(over = {}) {
	return {
		id: 7,
		status: 'aktiv',
		signatureType: 'papier',
		signedAt: '2026-01-15',
		storyText: null,
		hasDocument: true,
		showMissingDocumentWarning: false,
		expiresAt: '2029-01-15',
		expiryWarning: false,
		suspensionOrigin: null,
		endReason: null,
		activationLink: null,
		...over,
	}
}

describe('Klartext statt Enum-Rohwerten', () => {
	it('benennt die vier Zustände', () => {
		expect(statusLabel('entwurf')).toBe('Entwurf')
		expect(statusLabel('aktiv')).toBe('Aktiv')
		expect(statusLabel('ausgesetzt')).toBe('Ausgesetzt')
		expect(statusLabel('erloschen')).toBe('Erloschen')
	})

	it('lässt einen unbekannten Zustand unverändert durch, statt zu verschlucken', () => {
		expect(statusLabel('neu')).toBe('neu')
	})

	it('Statusfarben: aktiv grün, ausgesetzt gelb, erloschen gedämpft, Entwurf neutral', () => {
		expect(statusTone('aktiv')).toBe('success')
		expect(statusTone('ausgesetzt')).toBe('warning')
		expect(statusTone('erloschen')).toBe('muted')
		expect(statusTone('entwurf')).toBe('neutral')
	})

	it('Unterschriftsart, Endgrund, Sperr-Ursprung, Akteur, Amendment-Status', () => {
		expect(signatureTypeLabel('papier')).toBe('Papier')
		expect(signatureTypeLabel('elektronisch')).toContain('Elektronisch')
		expect(endReasonLabel('widerrufen')).toBe('Widerrufen')
		expect(endReasonLabel('ersetzt')).toContain('Kontoinhaberwechsel')
		expect(endReasonLabel('verfallen')).toContain('36 Monate')
		expect(endReasonLabel('beendet')).toContain('Austritt')
		expect(endReasonLabel('verworfen')).toBe('Entwurf verworfen')
		expect(endReasonLabel(null)).toBe('')
		expect(suspensionOriginLabel('ruecklastschrift')).toContain('Rücklastschrift')
		expect(suspensionOriginLabel('manuell')).toContain('manuell')
		expect(actorLabel('staff')).toBe('Verein')
		expect(actorLabel('member')).toBe('Mitglied')
		expect(actorLabel('system')).toBe('System')
		expect(amendmentStatusLabel('open')).toContain('noch nicht an die Bank gemeldet')
		expect(amendmentStatusLabel('transmitted')).toContain('gemeldet')
	})
})

describe('Formatierung', () => {
	it('gruppiert die IBAN in Vierer-Blöcke, auch maskiert und mit vorhandenen Leerzeichen', () => {
		expect(formatIban('DE12500105170648489890')).toBe('DE12 5001 0517 0648 4898 90')
		expect(formatIban('DE12 5001 0517 0648 4898 90')).toBe('DE12 5001 0517 0648 4898 90')
		expect(formatIban('DE12••••••••••••••9890')).toBe('DE12 •••• •••• •••• ••98 90')
		expect(formatIban(null)).toBe('')
	})

	it('formatiert Zeitstempel der Historie, mit und ohne Uhrzeit', () => {
		expect(formatStamp('2026-09-20 14:05:33')).toBe('20.09.2026 14:05')
		expect(formatStamp('2026-09-20T14:05:33')).toBe('20.09.2026 14:05')
		expect(formatStamp('2026-09-20')).toBe('20.09.2026')
		expect(formatStamp(null)).toBe('')
	})

	it('zählt ganze Tage bis zu einem Datum, negativ wenn vorbei', () => {
		expect(daysUntil('2026-09-30', TODAY)).toBe(10)
		expect(daysUntil('2026-09-20', TODAY)).toBe(0)
		expect(daysUntil('2026-09-10', TODAY)).toBe(-10)
		expect(daysUntil(null, TODAY)).toBeNull()
	})
})

describe('Unterschrift zu alt für die Aktivierung', () => {
	it('warnt, wenn die 36 Monate schon vorbei sind', () => {
		expect(signatureTooOld('2023-09-20', TODAY)).toBe(true) // genau 36 Monate: läuft ab heute
		expect(signatureTooOld('2020-01-01', TODAY)).toBe(true)
	})

	it('warnt nicht, solange noch Zeit bleibt oder kein Datum da ist', () => {
		expect(signatureTooOld('2023-09-21', TODAY)).toBe(false)
		expect(signatureTooOld('2026-01-15', TODAY)).toBe(false)
		expect(signatureTooOld('', TODAY)).toBe(false)
		expect(signatureTooOld(null, TODAY)).toBe(false)
	})
})

describe('Entwurf korrigieren (Issue #118)', () => {
	const draft = { iban: 'DE12500105170648489890', bic: 'INGDDEFFXXX', accountHolder: 'Katrin Brunner' }
	const form = (over = {}) => ({ iban: 'DE12 5001 0517 0648 4898 90', bic: 'INGDDEFFXXX', accountHolder: 'Katrin Brunner', ...over })

	it('die vorbelegte Eingabe ist keine Änderung – auch nicht in anderer Schreibweise', () => {
		expect(draftCorrectionChanged(draft, form())).toBe(false)
		expect(draftCorrectionChanged(draft, form({ iban: 'de12500105170648489890', bic: ' ingddeffxxx ', accountHolder: '  Katrin Brunner ' }))).toBe(false)
	})

	it('erkennt jede der drei Änderungen einzeln', () => {
		expect(draftCorrectionChanged(draft, form({ iban: 'DE89 3704 0044 0532 0130 00' }))).toBe(true)
		expect(draftCorrectionChanged(draft, form({ bic: 'COBADEFFXXX' }))).toBe(true)
		expect(draftCorrectionChanged(draft, form({ accountHolder: 'Katrin Meier' }))).toBe(true)
	})

	it('eine geleerte BIC zählt als Änderung, eine fehlende BIC am Entwurf nicht', () => {
		expect(draftCorrectionChanged(draft, form({ bic: '' }))).toBe(true)
		expect(draftCorrectionChanged({ ...draft, bic: null }, form({ bic: '' }))).toBe(false)
	})

	it('ohne gespeicherten Entwurf gibt es nichts zu ändern', () => {
		expect(draftCorrectionChanged(null, form({ iban: 'DE89370400440532013000' }))).toBe(false)
	})

	it('Speichern braucht IBAN und Kontoinhaber und eine Änderung, die BIC ist optional', () => {
		expect(canSaveDraftCorrection(draft, form())).toBe(false)
		expect(canSaveDraftCorrection(draft, form({ iban: 'DE89370400440532013000' }))).toBe(true)
		expect(canSaveDraftCorrection(draft, form({ bic: '' }))).toBe(true)
		expect(canSaveDraftCorrection(draft, form({ iban: '   ' }))).toBe(false)
		expect(canSaveDraftCorrection(draft, form({ accountHolder: '  ' }))).toBe(false)
	})
})

describe('Zustand des Einmal-Links', () => {
	it('unterscheidet noch nicht versendet, ausstehend und abgelaufen', () => {
		expect(activationLinkState(null)).toBe('none')
		expect(activationLinkState({ expired: false })).toBe('pending')
		expect(activationLinkState({ expired: true })).toBe('expired')
	})
})

describe('Störfall-Hinweise je Zustand', () => {
	const keys = (m) => mandateNotices(m, TODAY).map((n) => n.key)

	it('ein aktives Mandat mit Nachweis ist unauffällig', () => {
		expect(mandateNotices(mandate(), TODAY)).toEqual([])
	})

	it('Papier-Entwurf: „Unterschrift fehlt“, mit Hinweis je nach Unterschriftsdatum', () => {
		const ohneDatum = mandateNotices(mandate({ status: 'entwurf', signedAt: null, storyText: 'Unterschrift fehlt', hasDocument: false }), TODAY)[0]
		expect(ohneDatum.title).toBe('Unterschrift fehlt')
		expect(ohneDatum.tone).toBe('warning')
		expect(ohneDatum.text).toContain('Unterschriftsdatum ein')

		const mitDatum = mandateNotices(mandate({ status: 'entwurf', storyText: 'Unterschrift fehlt' }), TODAY)[0]
		expect(mitDatum.text).toContain('aktivieren Sie das Mandat')
	})

	it('elektronischer Entwurf: Text folgt dem Link-Zustand', () => {
		const base = { status: 'entwurf', signatureType: 'elektronisch', signedAt: null, storyText: 'Unterschrift fehlt' }
		const text = (activationLink) => mandateNotices(mandate({ ...base, activationLink }), TODAY)[0].text

		expect(text(null)).toContain('noch kein Einmal-Link')
		expect(text({ expired: false })).toContain('unterwegs')
		expect(text({ expired: true })).toContain('abgelaufen')
	})

	it('ausgesetzt: „Klärung offen“, bei Rücklastschrift mit eigener Erläuterung', () => {
		const manuell = mandateNotices(mandate({ status: 'ausgesetzt', storyText: 'Klärung offen', suspensionOrigin: 'manuell' }), TODAY)[0]
		expect(manuell.title).toBe('Klärung offen')
		expect(manuell.text).not.toContain('Rücklastschrift')

		const ruecklastschrift = mandateNotices(mandate({ status: 'ausgesetzt', storyText: 'Klärung offen', suspensionOrigin: 'ruecklastschrift' }), TODAY)[0]
		expect(ruecklastschrift.text).toContain('Rücklastschrift')
	})

	it('erloschen: „neues Mandat einholen“ mit dem Endgrund', () => {
		const notice = mandateNotices(mandate({ status: 'erloschen', storyText: 'neues Mandat einholen', endReason: 'widerrufen', expiresAt: null }), TODAY)[0]
		expect(notice.key).toBe('ended')
		expect(notice.title).toBe('neues Mandat einholen')
		expect(notice.text).toContain('Widerrufen')
	})

	it('erloschen durch Verwerfen: der Endgrund steht im Hinweis', () => {
		const notice = mandateNotices(mandate({ status: 'erloschen', storyText: 'neues Mandat einholen', endReason: 'verworfen', expiresAt: null }), TODAY)[0]
		expect(notice.text).toContain('Entwurf verworfen')
	})

	it('ein fehlendes Dokument ist kein Hinweiskasten mehr – es steht einmal als Warnmarke in der Zeile „Nachweis“', () => {
		expect(keys(mandate({ hasDocument: false, showMissingDocumentWarning: true }))).toEqual([])
	})

	it('Ablauf-Warnung mit den verbleibenden Tagen, am Stichtag selbst „läuft heute ab“', () => {
		const in15 = mandateNotices(mandate({ expiryWarning: true, expiresAt: '2026-10-05' }), TODAY)[0]
		expect(in15.title).toBe('Mandat läuft in 15 Tagen ab')
		expect(in15.tone).toBe('warning')
		expect(in15.text).toContain('05.10.2026')

		const heute = mandateNotices(mandate({ expiryWarning: true, expiresAt: TODAY }), TODAY)[0]
		expect(heute.title).toBe('Mandat läuft heute ab')
	})

	it('keine Ablauf-Warnung ohne Warnflag des Servers, auch bei nahem Datum', () => {
		expect(keys(mandate({ expiryWarning: false, expiresAt: '2026-10-05' }))).toEqual([])
	})

	it('Hinweise stapeln sich: gesperrt und bald ablaufend', () => {
		const m = mandate({ status: 'ausgesetzt', storyText: 'Klärung offen', expiryWarning: true, expiresAt: '2026-11-01' })
		expect(keys(m)).toEqual(['suspended', 'expiry'])
	})
})

describe('Zeile „Nachweis“ der Mandatsliste', () => {
	it('warnt nur, wenn das Dokument fehlt und der Server den (abschaltbaren) Hinweis meldet', () => {
		expect(documentRowTone({ hasDocument: false, showMissingDocumentWarning: true })).toBe('warning')
		expect(documentRowTone({ hasDocument: false, showMissingDocumentWarning: false })).toBeNull()
		expect(documentRowTone({ hasDocument: false })).toBeNull()
		expect(documentRowTone({ hasDocument: true, showMissingDocumentWarning: true })).toBeNull()
	})
})

// Issue #106: Sätze mit Nutzerdaten. Ein „&" oder „<" im Namen darf weder als
// „&amp;" erscheinen noch verschwinden – die Anzeige geht als Text in die Seite.
describe('Sätze mit Nutzerdaten', () => {
	const NAME = 'Echo & Söhne <b>GmbH</b>'

	it('Bankverbindungs-Kopfzeile: Kontoinhaber und Referenz unverändert', () => {
		const hint = mandateAccountHint({ mandateReference: 'M&1', accountHolder: NAME, iban: 'DE12500105170648489890' })
		expect(hint).toBe(`Mandat M&1 von ${NAME}, bisherige IBAN DE12 5001 0517 0648 4898 90.`)
	})

	it('Einmal-Link: Mailadresse unverändert (ein „&" ist im lokalen Teil erlaubt)', () => {
		expect(activationLinkSentText('a&b@example.org')).toContain('Einmal-Link an a&b@example.org verschickt.')
	})

	it('Widerruf aus Sicht der Verwaltung: Mitgliedsname unverändert', () => {
		expect(revokeStaffWarning(NAME)).toContain(`braucht ${NAME} danach ein neues Mandat`)
	})
})
