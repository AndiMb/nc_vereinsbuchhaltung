import { describe, expect, it } from 'vitest'
import { createLatestRequest } from './latestRequest.js'

describe('createLatestRequest()', () => {
	it('nur der zuletzt gestartete Ladevorgang ist aktuell', () => {
		const start = createLatestRequest()
		const first = start()
		expect(first()).toBe(true)
		const second = start()
		expect(first()).toBe(false)
		expect(second()).toBe(true)
	})

	it('getrennte Zähler je Lader', () => {
		const a = createLatestRequest()
		const b = createLatestRequest()
		const fromA = a()
		b()
		expect(fromA()).toBe(true)
	})
})
