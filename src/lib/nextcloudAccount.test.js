import { beforeEach, describe, expect, it, vi } from 'vitest'

const post = vi.fn()
const confirmPassword = vi.fn()

class PasswordConfirmationCancelledError extends Error {}

vi.mock('@nextcloud/axios', () => ({
	default: { post: (...args) => post(...args), interceptors: { response: { use: () => {} } } },
}))
vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path) => `/ocs/v2.php${path}`,
	generateUrl: (path) => path,
}))

vi.mock('@nextcloud/password-confirmation', () => ({
	confirmPassword: (...args) => confirmPassword(...args),
	PasswordConfirmationCancelledError,
}))
vi.mock('@nextcloud/password-confirmation/style.css', () => ({}))

const { confirmAccountCreation, createAndLinkAccount, createNextcloudAccount } = await import('./nextcloudAccount.js')

const ACCOUNT = {
	userId: 'anna.koch',
	password: '',
	displayName: 'Anna Koch',
	email: 'anna@example.org',
	groups: ['chor'],
	quota: '5 GB',
	language: 'de',
}

describe('createNextcloudAccount()', () => {
	beforeEach(() => {
		post.mockReset()
		confirmPassword.mockReset().mockResolvedValue()
	})

	// Issue #150: ohne kürzlich bestätigtes Passwort lehnt Nextcloud mit
	// „Password confirmation is required" ab.
	it('lässt das Passwort bestätigen, bevor es das Konto anlegt', async () => {
		const order = []
		confirmPassword.mockImplementation(async () => { order.push('confirm') })
		post.mockImplementation(async () => { order.push('post'); return { data: { ocs: { data: { id: 'anna.koch' } } } } })

		await createNextcloudAccount(ACCOUNT)

		expect(order).toEqual(['confirm', 'post'])
	})

	it('legt nichts an, wenn die Bestätigung abgebrochen wird', async () => {
		confirmPassword.mockRejectedValue(new PasswordConfirmationCancelledError())

		await expect(createNextcloudAccount(ACCOUNT)).rejects.toBeInstanceOf(PasswordConfirmationCancelledError)
		expect(post).not.toHaveBeenCalled()
	})

	it('ruft die OCS-Schnittstelle von Nextcloud und liefert die Konto-ID', async () => {
		post.mockResolvedValue({ data: { ocs: { data: { id: 'anna.koch' } } } })

		const id = await createNextcloudAccount(ACCOUNT)

		expect(id).toBe('anna.koch')
		expect(post).toHaveBeenCalledWith('/ocs/v2.php/cloud/users', {
			userid: 'anna.koch',
			password: '',
			displayName: 'Anna Koch',
			email: 'anna@example.org',
			groups: ['chor'],
			quota: '5 GB',
			language: 'de',
		})
	})

	it('lässt Fehler von Nextcloud unverändert durch', async () => {
		const failure = { response: { data: { ocs: { meta: { statuscode: 102, message: 'User already exists' } } } } }
		post.mockRejectedValue(failure)

		await expect(createNextcloudAccount(ACCOUNT)).rejects.toBe(failure)
	})
})

describe('createAndLinkAccount()', () => {
	beforeEach(() => {
		post.mockReset()
		confirmPassword.mockReset().mockResolvedValue()
	})

	it('legt das Konto an und verknüpft es mit der Akte', async () => {
		post.mockResolvedValue({ data: { ocs: { data: { id: 'anna.koch' } } } })

		const id = await createAndLinkAccount(7, ACCOUNT)

		expect(id).toBe('anna.koch')
		expect(post).toHaveBeenNthCalledWith(2, '/apps/vereinsbuchhaltung/api/members/7/link', { ncUserId: 'anna.koch' })
	})

	it('verknüpft nichts, wenn das Konto nicht angelegt werden konnte', async () => {
		post.mockRejectedValueOnce(new Error('User already exists'))

		await expect(createAndLinkAccount(7, ACCOUNT)).rejects.toThrow('User already exists')
		expect(post).toHaveBeenCalledTimes(1)
	})

	it('meldet bei gescheiterter Verknüpfung die ID des schon angelegten Kontos', async () => {
		post.mockResolvedValueOnce({ data: { ocs: { data: { id: 'anna.koch' } } } })
		post.mockRejectedValueOnce(Object.assign(new Error('Konto ist bereits verknüpft'), { response: { data: { message: 'bereits verknüpft' } } }))

		await expect(createAndLinkAccount(7, ACCOUNT)).rejects.toMatchObject({ createdUid: 'anna.koch' })
	})
})

describe('confirmAccountCreation()', () => {
	beforeEach(() => {
		confirmPassword.mockReset()
	})

	it('meldet true nach bestätigtem Passwort', async () => {
		confirmPassword.mockResolvedValue()

		await expect(confirmAccountCreation()).resolves.toBe(true)
	})

	it('meldet false, wenn die Person den Dialog abbricht', async () => {
		confirmPassword.mockRejectedValue(new PasswordConfirmationCancelledError())

		await expect(confirmAccountCreation()).resolves.toBe(false)
	})

	it('lässt andere Fehler durch', async () => {
		const failure = new Error('Netzwerk')
		confirmPassword.mockRejectedValue(failure)

		await expect(confirmAccountCreation()).rejects.toBe(failure)
	})
})
