import { describe, expect, it } from 'vitest'
import { accountPayload, DEFAULT_CHOICE, emptyAccount, missingAccountFields } from './accountForm.js'

const OPTIONS = { generateUserId: false, requireEmail: false, groupRequired: false }

describe('emptyAccount()', () => {
	it('belegt Kontoname, Anzeigename und E-Mail aus den Stammdaten vor', () => {
		const account = emptyAccount({ memberType: 'person', firstName: 'Anna', lastName: 'Müller', email: 'anna@example.org' })

		expect(account).toMatchObject({
			userId: 'anna.mueller',
			displayName: 'Anna Müller',
			email: 'anna@example.org',
			password: '',
			groups: [],
			quota: DEFAULT_CHOICE,
			language: DEFAULT_CHOICE,
		})
	})

	it('nimmt bei einer Organisation deren Namen', () => {
		const account = emptyAccount({ memberType: 'organisation', organizationName: 'Muster GmbH' })

		expect(account.userId).toBe('muster-gmbh')
		expect(account.displayName).toBe('Muster GmbH')
	})

	it('verträgt leere Stammdaten', () => {
		expect(emptyAccount()).toMatchObject({ userId: '', displayName: '', email: '' })
	})
})

describe('missingAccountFields()', () => {
	const filled = () => ({ ...emptyAccount({ firstName: 'Anna', lastName: 'Koch', email: 'anna@example.org' }) })

	it('ist vollständig, wenn Kontoname und E-Mail da sind', () => {
		expect(missingAccountFields(filled(), OPTIONS)).toEqual([])
	})

	it('verlangt den Kontonamen, es sei denn, Nextcloud vergibt ihn', () => {
		const account = { ...filled(), userId: ' ' }

		expect(missingAccountFields(account, OPTIONS)).toEqual(['userId'])
		expect(missingAccountFields(account, { ...OPTIONS, generateUserId: true })).toEqual([])
	})

	it('verlangt ohne Passwort die E-Mail-Adresse für die Einladung', () => {
		const account = { ...filled(), email: '' }

		expect(missingAccountFields(account, OPTIONS)).toEqual(['email'])
		expect(missingAccountFields({ ...account, password: 'geheim-1234' }, OPTIONS)).toEqual([])
	})

	it('verlangt die E-Mail-Adresse immer, wenn die Instanz es so eingestellt hat', () => {
		const account = { ...filled(), email: '', password: 'geheim-1234' }

		expect(missingAccountFields(account, { ...OPTIONS, requireEmail: true })).toEqual(['email'])
	})

	it('verlangt von einem Gruppen-Administrator eine Gruppe', () => {
		expect(missingAccountFields(filled(), { ...OPTIONS, groupRequired: true })).toEqual(['groups'])
		expect(missingAccountFields({ ...filled(), groups: ['chor'] }, { ...OPTIONS, groupRequired: true })).toEqual([])
	})
})

describe('accountPayload()', () => {
	it('macht aus „Standard“ leere Angaben, die Nextcloud selbst auffüllt', () => {
		const payload = accountPayload({ ...emptyAccount({ firstName: 'Anna', lastName: 'Koch' }), quota: DEFAULT_CHOICE, language: DEFAULT_CHOICE })

		expect(payload.quota).toBe('')
		expect(payload.language).toBe('')
	})

	it('reicht gewählte Werte durch und kürzt Leerraum', () => {
		const payload = accountPayload({
			userId: ' anna ',
			password: '',
			displayName: ' Anna Koch ',
			email: ' anna@example.org ',
			groups: ['chor'],
			quota: '5 GB',
			language: 'de',
		})

		expect(payload).toEqual({
			userId: 'anna',
			password: '',
			displayName: 'Anna Koch',
			email: 'anna@example.org',
			groups: ['chor'],
			quota: '5 GB',
			language: 'de',
		})
	})
})
