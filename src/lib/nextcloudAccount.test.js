import { beforeEach, describe, expect, it, vi } from 'vitest'

const post = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { post: (...args) => post(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (path) => `/ocs/v2.php${path}` }))

const { createNextcloudAccount } = await import('./nextcloudAccount.js')

describe('createNextcloudAccount()', () => {
	beforeEach(() => {
		post.mockReset()
	})

	it('ruft die OCS-Schnittstelle von Nextcloud und liefert die Konto-ID', async () => {
		post.mockResolvedValue({ data: { ocs: { data: { id: 'anna.koch' } } } })

		const id = await createNextcloudAccount({
			userId: 'anna.koch',
			password: '',
			displayName: 'Anna Koch',
			email: 'anna@example.org',
			groups: ['chor'],
			quota: '5 GB',
			language: 'de',
		})

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

	it('gibt den Text von Nextcloud als Fehlermeldung weiter', async () => {
		post.mockRejectedValue({ response: { data: { ocs: { meta: { statuscode: 102, message: 'User already exists' } } } } })

		await expect(createNextcloudAccount({ userId: 'anna' })).rejects.toMatchObject({
			message: 'User already exists',
			response: { data: { message: 'User already exists' } },
		})
	})

	it('reicht andere Fehler unverändert weiter', async () => {
		const failure = new Error('Network Error')
		post.mockRejectedValue(failure)

		await expect(createNextcloudAccount({ userId: 'anna' })).rejects.toBe(failure)
	})
})
