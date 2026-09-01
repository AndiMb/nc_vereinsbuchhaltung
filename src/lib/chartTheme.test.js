import { describe, expect, it } from 'vitest'
import { buildChartTheme, withAlpha } from './chartTheme.js'

describe('withAlpha', () => {
	it('rechnet Hex in rgba um - so liefert Nextcloud seine Variablen', () => {
		expect(withAlpha('#40A330', 0.72)).toBe('rgba(64, 163, 48, 0.72)')
	})

	it('versteht die Kurzform und Umgebungsleerzeichen', () => {
		expect(withAlpha('  #fff  ', 0.5)).toBe('rgba(255, 255, 255, 0.5)')
	})

	it('ersetzt eine vorhandene Deckkraft, statt sie zu verdoppeln', () => {
		expect(withAlpha('rgba(1, 2, 3, 0.9)', 0.15)).toBe('rgba(1, 2, 3, 0.15)')
		expect(withAlpha('rgb(1, 2, 3)', 0.15)).toBe('rgba(1, 2, 3, 0.15)')
	})

	it('gibt Unlesbares deckend zurueck - lieber sichtbar als unsichtbar', () => {
		// color-mix() ist genau der Fall, den @kurkle/color nicht parst.
		expect(withAlpha('color-mix(in srgb, red 50%, transparent)', 0.5))
			.toBe('color-mix(in srgb, red 50%, transparent)')
		expect(withAlpha('#12345', 0.5)).toBe('#12345')
	})

	it('haelt die Deckkraft im gueltigen Bereich', () => {
		// Voll deckend schreibt der Helfer als rgb() - dieselbe Farbe.
		expect(withAlpha('#000000', 5)).toBe('rgb(0, 0, 0)')
		expect(withAlpha('#000000', -1)).toBe('rgba(0, 0, 0, 0)')
	})
})

describe('buildChartTheme', () => {
	it('nimmt die Werte aus den CSS-Variablen', () => {
		const dark = {
			'--color-main-text': '#EBEBEB',
			'--color-text-maxcontrast': '#999999',
			'--color-border': '#292929',
			'--color-element-success': '#40A330',
			'--color-element-error': '#FF5050',
			'--color-primary-element': '#6EA8FE',
		}
		const theme = buildChartTheme((name) => dark[name])
		expect(theme).toEqual({
			text: '#EBEBEB',
			mutedText: '#999999',
			grid: '#292929',
			success: '#40A330',
			error: '#FF5050',
			accent: '#6EA8FE',
		})
	})

	it('faellt auf die frueheren Festwerte zurueck, wenn eine Variable fehlt', () => {
		// getPropertyValue liefert fuer Unbekanntes einen leeren String, keinen
		// undefined-Wert - beides darf nicht als Farbe durchgereicht werden.
		const theme = buildChartTheme((name) => (name === '--color-border' ? '' : undefined))
		expect(theme.grid).toBe('#ededed')
		expect(theme.text).toBe('#222222')
		expect(theme.success).toBe('#2d7d46')
	})
})
