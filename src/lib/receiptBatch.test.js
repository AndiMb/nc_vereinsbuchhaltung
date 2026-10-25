import { describe, expect, it, vi } from 'vitest'
import { linkFilesToBookings, rangeIds } from './receiptBatch.js'

describe('linkFilesToBookings', () => {
	it('verknüpft jede Datei mit jeder Buchung, Buchung für Buchung', async () => {
		const link = vi.fn().mockResolvedValue({})
		const result = await linkFilesToBookings(link, [{ fileId: 10, journalIds: [] }, { fileId: 11 }], [1, 2])

		expect(link.mock.calls).toEqual([[1, 10], [1, 11], [2, 10], [2, 11]])
		expect(result).toEqual({ linked: [1, 2], failed: [], error: null })
	})

	it('übergeht, was schon an der Buchung hängt', async () => {
		const link = vi.fn().mockResolvedValue({})
		const result = await linkFilesToBookings(link, [{ fileId: 10, journalIds: [1, 7] }], [1, 2, 3])

		expect(link.mock.calls).toEqual([[2, 10], [3, 10]])
		expect(result.linked).toEqual([2, 3])
	})

	it('meldet nichts Neues, wenn die Datei schon überall hängt', async () => {
		const link = vi.fn()
		const result = await linkFilesToBookings(link, [{ fileId: 10, journalIds: [1, 2] }], [1, 2])

		expect(link).not.toHaveBeenCalled()
		expect(result).toEqual({ linked: [], failed: [], error: null })
	})

	it('macht nach einem Fehler weiter und nennt die Buchung samt letztem Fehler', async () => {
		const boom = new Error('Geschäftsjahr abgeschlossen')
		const link = vi.fn()
			.mockResolvedValueOnce({})
			.mockRejectedValueOnce(boom)
			.mockResolvedValueOnce({})
		const result = await linkFilesToBookings(link, [{ fileId: 10 }], [1, 2, 3])

		expect(link).toHaveBeenCalledTimes(3)
		expect(result).toEqual({ linked: [1, 3], failed: [2], error: boom })
	})

	it('zählt eine Buchung, bei der eine von zwei Dateien scheitert, unter beiden', async () => {
		const link = vi.fn()
			.mockResolvedValueOnce({})
			.mockRejectedValueOnce(new Error('weg'))
		const result = await linkFilesToBookings(link, [{ fileId: 10 }, { fileId: 11 }], [1])

		expect(result.linked).toEqual([1])
		expect(result.failed).toEqual([1])
	})
})

describe('rangeIds', () => {
	const rows = [{ id: 5 }, { id: 3 }, { id: 9 }, { id: 4 }]

	it('nimmt alles zwischen Anker und Klick in Listenreihenfolge', () => {
		expect(rangeIds(rows, 3, 4)).toEqual([3, 9, 4])
	})

	it('geht auch nach oben', () => {
		expect(rangeIds(rows, 4, 5)).toEqual([5, 3, 9, 4])
	})

	it('nur die Zeile selbst, wenn der Anker fehlt oder ausgeblendet ist', () => {
		expect(rangeIds(rows, null, 9)).toEqual([9])
		expect(rangeIds(rows, 42, 9)).toEqual([9])
	})

	it('Anker und Klick auf derselben Zeile', () => {
		expect(rangeIds(rows, 9, 9)).toEqual([9])
	})
})
