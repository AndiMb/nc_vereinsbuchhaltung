import { describe, expect, it } from 'vitest'
import { onlyDefaultMoneyAccountAdded } from './bookingSnapshot.js'

const state = (form, pending = []) => JSON.stringify({ form, pending })
const base = { id: null, date: '2026-10-31', amount: null, description: '', moneyAccountId: null }

describe('onlyDefaultMoneyAccountAdded', () => {
	it('nur das Geldkonto kam nach: true', () => {
		expect(onlyDefaultMoneyAccountAdded(state(base), state({ ...base, moneyAccountId: 7 }))).toBe(true)
	})
	it('zusätzlich etwas getippt: false', () => {
		expect(onlyDefaultMoneyAccountAdded(state(base), state({ ...base, moneyAccountId: 7, description: 'x' }))).toBe(false)
	})
	it('Geldkonto war im Schnappschuss schon gesetzt: false', () => {
		expect(onlyDefaultMoneyAccountAdded(state({ ...base, moneyAccountId: 3 }), state({ ...base, moneyAccountId: 7 }))).toBe(false)
	})
	it('Geldkonto geleert: false', () => {
		expect(onlyDefaultMoneyAccountAdded(state(base), state(base))).toBe(false)
	})
	it('Warteliste geändert: false', () => {
		expect(onlyDefaultMoneyAccountAdded(state(base), state({ ...base, moneyAccountId: 7 }, [['a.pdf', 1, null]]))).toBe(false)
	})
})
