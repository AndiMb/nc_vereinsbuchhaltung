import { beforeEach, describe, expect, it, vi } from 'vitest'

const confirmPassword = vi.fn()

class PasswordConfirmationCancelledError extends Error {}

vi.mock('@nextcloud/password-confirmation', () => ({
	confirmPassword: (...args) => confirmPassword(...args),
	PasswordConfirmationCancelledError,
}))
vi.mock('@nextcloud/password-confirmation/style.css', () => ({}))

const { withPasswordConfirmation } = await import('./passwordGate.js')

describe('withPasswordConfirmation()', () => {
	beforeEach(() => {
		confirmPassword.mockReset()
	})

	it('liefert true nach Bestätigung', async () => {
		confirmPassword.mockResolvedValue()
		expect(await withPasswordConfirmation()).toBe(true)
		expect(confirmPassword).toHaveBeenCalledTimes(1)
	})

	it('liefert false bei Abbruch', async () => {
		confirmPassword.mockRejectedValue(new PasswordConfirmationCancelledError())
		expect(await withPasswordConfirmation()).toBe(false)
	})

	it('wirft andere Fehler weiter', async () => {
		const boom = new Error('kaputt')
		confirmPassword.mockRejectedValue(boom)
		await expect(withPasswordConfirmation()).rejects.toBe(boom)
	})
})
