import { describe, expect, it } from 'vitest'
import { BATCH_TEMPLATE_HEADERS, invoiceBatchTemplate, invoiceBatchTemplateUrl } from './invoiceBatchTemplate.js'

describe('invoiceBatchTemplate', () => {
	const text = invoiceBatchTemplate()
	// Ohne das erste Zeichen (BOM), Zeilen mit CRLF.
	const lines = text.slice(1).split(String.fromCharCode(13, 10))

	it('beginnt mit BOM und hat Kopf plus drei Beispiele', () => {
		expect(text.charCodeAt(0)).toBe(0xFEFF)
		expect(lines).toHaveLength(4)
		expect(lines[0].split(';')).toEqual(BATCH_TEMPLATE_HEADERS)
	})

	it('jede Zeile hat so viele Zellen wie der Kopf', () => {
		for (const line of lines) {
			expect(line.split(';')).toHaveLength(BATCH_TEMPLATE_HEADERS.length)
		}
	})

	it('die Fortsetzungszeile lässt den Namen leer', () => {
		const nameCol = BATCH_TEMPLATE_HEADERS.indexOf('Name')
		expect(lines[2].split(';')[nameCol]).toBe('')
	})

	it('als Daten-URL', () => {
		expect(invoiceBatchTemplateUrl()).toMatch(/^data:text\/csv;charset=utf-8,/)
	})
})
