import { describe, expect, it } from 'vitest'
import { isSelectableOption } from './selectOptions.js'

describe('isSelectableOption', () => {
	it('sperrt nur Eintraege mit $isDisabled', () => {
		expect(isSelectableOption({ id: null, label: 'Einnahmen', $isDisabled: true })).toBe(false)
		expect(isSelectableOption({ id: 4, label: '4000 Mitgliedsbeiträge' })).toBe(true)
		expect(isSelectableOption({ id: 'category:Einnahmen', isCategory: true })).toBe(true)
		expect(isSelectableOption({ id: null, label: '– kein Überkonto –' })).toBe(true)
		expect(isSelectableOption(null)).toBe(true)
	})
})
