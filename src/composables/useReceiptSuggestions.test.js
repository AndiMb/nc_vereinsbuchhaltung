import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

const receiptSuggest = vi.fn()
vi.mock('../api.js', () => ({ default: { receiptSuggest: (...a) => receiptSuggest(...a) } }))

const { createLatestOnly, useReceiptSuggestions } = await import('./useReceiptSuggestions.js')

function deferred() {
	let resolve
	const promise = new Promise((r) => { resolve = r })
	return { promise, resolve }
}

describe('createLatestOnly', () => {
	it('veralteteAntwortWirdVerworfen', async () => {
		const run = createLatestOnly()
		const first = deferred()
		const second = deferred()
		const a = run(() => first.promise)
		const b = run(() => second.promise)
		second.resolve('neu')
		expect(await b).toBe('neu')
		first.resolve('alt')
		expect(await a).toEqual({ stale: true })
	})

	it('liefert eine einzelne Antwort unverändert', async () => {
		const run = createLatestOnly()
		expect(await run(() => Promise.resolve(7))).toBe(7)
	})

	it('verwirft auch den Fehler einer veralteten Anfrage', async () => {
		const run = createLatestOnly()
		const first = deferred()
		const a = run(() => first.promise.then(() => { throw new Error('x') }))
		const b = run(() => Promise.resolve('neu'))
		first.resolve()
		expect(await a).toEqual({ stale: true })
		expect(await b).toBe('neu')
	})
})

describe('useReceiptSuggestions', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		receiptSuggest.mockReset()
		receiptSuggest.mockResolvedValue({ data: { pending: 0, suggestions: [] } })
	})
	afterEach(() => { vi.useRealTimers() })

	const setup = (over = {}) => {
		const form = ref({ date: '2026-10-01', amount: 1, description: 'a', documentRef: '' })
		const enabled = ref(over.enabled ?? true)
		const exclude = ref([])
		const api = useReceiptSuggestions({ form: () => form.value, excludeFileIds: () => exclude.value, enabled: () => enabled.value })
		return { form, enabled, exclude, api }
	}

	it('ruft beim Aktivieren sofort ab und schickt Cent und Ausschlussliste', async () => {
		const { exclude } = setup()
		exclude.value = [5]
		await nextTick()
		await vi.advanceTimersByTimeAsync(700)
		expect(receiptSuggest).toHaveBeenCalled()
		const last = receiptSuggest.mock.calls.at(-1)[0]
		expect(last).toMatchObject({ amountCents: 100, excludeFileIds: [5], description: 'a', date: '2026-10-01', documentRef: '' })
	})

	it('entprellt Änderungen: drei Änderungen in 500 ms, ein Abruf nach 600 ms', async () => {
		const { form } = setup()
		await nextTick()
		await vi.advanceTimersByTimeAsync(0)
		receiptSuggest.mockClear()
		for (const d of ['x', 'xy', 'xyz']) {
			form.value = { ...form.value, description: d }
			await nextTick()
			await vi.advanceTimersByTimeAsync(150)
		}
		expect(receiptSuggest).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(449)
		expect(receiptSuggest).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(2)
		expect(receiptSuggest).toHaveBeenCalledTimes(1)
		expect(receiptSuggest.mock.calls[0][0].description).toBe('xyz')
	})

	it('fragt nicht ab, solange es ausgeschaltet ist, und leert dann die Liste', async () => {
		receiptSuggest.mockResolvedValue({ data: { pending: 0, suggestions: [{ fileId: 1 }] } })
		const { enabled, api } = setup()
		await nextTick()
		await vi.advanceTimersByTimeAsync(0)
		expect(api.suggestions.value).toHaveLength(1)
		receiptSuggest.mockClear()
		enabled.value = false
		await nextTick()
		await vi.advanceTimersByTimeAsync(5000)
		expect(receiptSuggest).not.toHaveBeenCalled()
		expect(api.suggestions.value).toEqual([])
		expect(api.pending.value).toBe(0)
	})

	it('fragt bei offenen Dateien nach, solange es voranging, und hört bei 0 auf', async () => {
		receiptSuggest
			.mockResolvedValueOnce({ data: { pending: 40, suggestions: [] } })
			.mockResolvedValueOnce({ data: { pending: 20, suggestions: [] } })
			.mockResolvedValueOnce({ data: { pending: 0, suggestions: [{ fileId: 9 }] } })
		const { api } = setup()
		await nextTick()
		await vi.advanceTimersByTimeAsync(10000)
		expect(receiptSuggest).toHaveBeenCalledTimes(3)
		expect(api.pending.value).toBe(0)
		expect(api.suggestions.value).toEqual([{ fileId: 9 }])
	})

	it('hört auf nachzufragen, wenn nichts mehr voranging', async () => {
		receiptSuggest.mockResolvedValue({ data: { pending: 5, suggestions: [] } })
		setup()
		await nextTick()
		await vi.advanceTimersByTimeAsync(20000)
		expect(receiptSuggest).toHaveBeenCalledTimes(2)
	})
})
