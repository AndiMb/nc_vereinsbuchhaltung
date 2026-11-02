import { describe, expect, it, vi } from 'vitest'
import { createSharedLoader } from './sharedLoader.js'

function deferred() {
	let resolve
	let reject
	const promise = new Promise((res, rej) => { resolve = res; reject = rej })
	return { promise, resolve, reject }
}

describe('createSharedLoader', () => {
	it('teilt laufende Anfrage ohne force', async () => {
		const d = deferred()
		const fetchFn = vi.fn(() => d.promise)
		const load = createSharedLoader(fetchFn)
		const a = load()
		const b = load()
		d.resolve('x')
		expect(await a).toBe('x')
		expect(await b).toBe('x')
		expect(fetchFn).toHaveBeenCalledTimes(1)
		await load()
		expect(fetchFn).toHaveBeenCalledTimes(2)
	})

	it('force startet neue Anfrage', async () => {
		const ds = [deferred(), deferred()]
		const fetchFn = vi.fn(() => ds[fetchFn.mock.calls.length - 1].promise)
		const load = createSharedLoader(fetchFn)
		const a = load()
		const b = load({ force: true })
		expect(fetchFn).toHaveBeenCalledTimes(2)
		ds[0].resolve('alt')
		ds[1].resolve('neu')
		expect(await b).toBe('neu')
		expect(await a).toBe('neu')
	})

	it('aeltere Antwort ueberschreibt neuere nicht', async () => {
		const ds = [deferred(), deferred()]
		let state = null
		const calls = []
		const load = createSharedLoader((isCurrent) => {
			const i = calls.length
			calls.push(i)
			return (async () => {
				const data = await ds[i].promise
				if (isCurrent()) { state = data }
				return data
			})()
		})
		const a = load()
		const b = load({ force: true })
		ds[1].resolve('neu')
		await b
		ds[0].resolve('alt')
		await a
		expect(state).toBe('neu')
	})

	it('nach Fehler laedt ein neuer Aufruf erneut', async () => {
		const fetchFn = vi.fn().mockRejectedValueOnce(new Error('x')).mockResolvedValue('ok')
		const load = createSharedLoader(fetchFn)
		await expect(load()).rejects.toThrow('x')
		expect(await load()).toBe('ok')
		expect(fetchFn).toHaveBeenCalledTimes(2)
	})
})
