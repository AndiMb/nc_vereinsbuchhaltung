import { describe, expect, it } from 'vitest'
import { SAVE_PARTS, settingsPayload } from './settingsSave.js'

/** Formularstand mit lauter geänderten Werten. */
const state = {
	clubName: 'Testverein e. V.',
	clubCurrency: 'CHF',
	savedCurrency: 'EUR',
	brandColor: '#112233',
	amountDisplay: 'signed',
	storageMode: 'user',
	storageUser: 'admin',
	storagePath: '',
	statementWatchUser: 'admin',
	statementWatchPath: 'Auszuege',
	sepaCreditorId: 'DE98ZZZ09999999999',
	sepaDebtorAccountId: null,
	membershipEnabled: true,
	selfServiceEnabled: false,
	selfServiceTransferSwitchEnabled: false,
}

describe('settingsPayload', () => {
	it('schickt je Karte nur deren Felder', () => {
		expect(settingsPayload(['club'], state)).toEqual({ club_name: 'Testverein e. V.' })
		expect(settingsPayload(['display'], state)).toEqual({ amount_display: 'signed' })
		expect(settingsPayload(['storage'], state)).toEqual({ storage_mode: 'user', storage_user: 'admin', storage_path: 'Vereinsbuchhaltung/Belege' })
		expect(settingsPayload(['watch'], state)).toEqual({ statement_watch_user: 'admin', statement_watch_path: 'Auszuege' })
		expect(settingsPayload(['sepa'], state)).toEqual({ sepa_creditor_id: 'DE98ZZZ09999999999', sepa_debtor_account_id: '' })
		expect(settingsPayload(['sepaModule'], state)).toEqual({ membership_enabled: '1', self_service_enabled: '0', self_service_transfer_switch_enabled: '0' })
	})

	it('schickt die Währung nur bei echter Änderung', () => {
		expect(settingsPayload(['currency'], state)).toEqual({ club_currency: 'CHF' })
		expect(settingsPayload(['currency'], { ...state, clubCurrency: 'EUR' })).toEqual({})
	})

	it('ohne Angabe wie früher: der vollständige Satz', () => {
		const all = settingsPayload([], state)
		expect(all).toEqual(Object.assign({}, ...SAVE_PARTS.map((p) => settingsPayload([p], state))))
		expect(Object.keys(all)).toHaveLength(14)
	})
})
