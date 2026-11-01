import { describe, expect, it } from 'vitest'
import { addReceipts, isImageReceipt, RECEIPT_MAX_FILES, RECEIPT_MAX_SIZE, receiptType } from './reimbursementFiles.js'

const file = (name, size = 1000, type = 'image/png', lastModified = 1) => ({ name, size, type, lastModified })

describe('reimbursementFiles', () => {
	it('erkennt den Typ auch ohne Angabe des Browsers an der Endung', () => {
		expect(receiptType(file('a.PDF', 10, ''))).toBe('application/pdf')
		expect(receiptType(file('foto.jpeg', 10, ''))).toBe('image/jpeg')
		expect(receiptType(file('notizen.txt', 10, ''))).toBe('')
		expect(isImageReceipt(file('a.png'))).toBe(true)
		expect(isImageReceipt(file('a.pdf', 10, 'application/pdf'))).toBe(false)
	})

	it('nimmt gültige Dateien auf und sammelt sie', () => {
		const first = addReceipts([], [file('a.png')])
		const second = addReceipts(first.files, [file('b.pdf', 10, 'application/pdf')])
		expect(second.files.map((f) => f.name)).toEqual(['a.png', 'b.pdf'])
		expect(second.errors).toEqual([])
	})

	it('übergeht dieselbe Datei beim zweiten Mal', () => {
		const first = addReceipts([], [file('a.png')])
		expect(addReceipts(first.files, [file('a.png')]).files).toHaveLength(1)
	})

	it('weist falsche Typen, zu große und leere Dateien mit je einem Satz ab', () => {
		const result = addReceipts([], [file('x.txt', 10, 'text/plain'), file('gross.png', RECEIPT_MAX_SIZE + 1), file('leer.png', 0), file('ok.png')])
		expect(result.files.map((f) => f.name)).toEqual(['ok.png'])
		expect(result.errors).toEqual([
			'„x.txt“ ist weder PDF noch Bild.',
			'„gross.png“ ist zu groß (höchstens 20 MB).',
			'„leer.png“ ist leer.',
		])
	})

	it('begrenzt die Zahl der Belege', () => {
		const many = Array.from({ length: RECEIPT_MAX_FILES + 2 }, (_, i) => file(`b${i}.png`, 100, 'image/png', i))
		const result = addReceipts([], many)
		expect(result.files).toHaveLength(RECEIPT_MAX_FILES)
		expect(result.errors).toEqual([`Mehr als ${RECEIPT_MAX_FILES} Belege je Antrag sind nicht möglich.`])
	})
})
