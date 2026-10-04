import { beforeEach, describe, expect, it } from 'vitest'
import { useEinzugRequest } from './useEinzugRequest.js'

// Die Bitte an den Einzug-Unterreiter (Issue #121): aus Buchungen → Offene
// Posten ins Segment „Forderungen“, auf das Mitglied der Forderung eingegrenzt.
// Sie gilt genau einmal - wer sie liest, räumt sie ab, sonst öffnete sich das
// Segment später von selbst noch einmal, etwa beim Neuaufbau des Reiters.

const { request, requestClaims, takeSegmentRequest, takeMemberFocus } = useEinzugRequest()

beforeEach(() => {
	request.segment = null
	request.memberId = null
})

describe('requestClaims', () => {
	it('hinterlässt Segment und Mitglied', () => {
		requestClaims(7)
		expect(request.segment).toBe('claims')
		expect(request.memberId).toBe(7)
	})

	it('verlangt ohne Angabe keine Eingrenzung und vergisst eine frühere', () => {
		requestClaims(7)
		requestClaims()
		expect(request.segment).toBe('claims')
		expect(request.memberId).toBeNull()
	})
})

describe('takeSegmentRequest', () => {
	it('liefert das Segment einmal und räumt es ab, das Mitglied bleibt für das Segment stehen', () => {
		requestClaims(7)
		expect(takeSegmentRequest()).toBe('claims')
		expect(takeSegmentRequest()).toBeNull()
		expect(request.memberId).toBe(7)
	})

	it('liefert ohne Anfrage null', () => {
		expect(takeSegmentRequest()).toBeNull()
	})
})

describe('takeMemberFocus', () => {
	it('liefert das Mitglied einmal und räumt es ab', () => {
		requestClaims(7)
		expect(takeMemberFocus()).toBe(7)
		expect(takeMemberFocus()).toBeNull()
		expect(request.segment).toBe('claims')
	})
})
