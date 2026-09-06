<template>
	<input
		:value="display"
		type="text"
		inputmode="decimal"
		autocomplete="off"
		@focus="onFocus"
		@input="onInput"
		@blur="onBlur"
		@keydown.enter="onEnter">
</template>

<script>
import { amountInputRaw, formatAmountInput, parseAmountInput, roundCents } from '../lib/format.js'

/**
 * Betragsfeld in der Schreibweise der App: unbearbeitet zeigt es
 * "20.000,00 €" wie die Tabellenspalten daneben, beim Fokussieren wird der
 * nackte Wert editierbar, beim Verlassen wieder formatiert (Issue #34).
 *
 * Warum kein <input type="number">: dessen Wert muss laut HTML-Spezifikation
 * eine "valid floating-point number" sein, der Browser verwirft "20.000,00"
 * also stillschweigend. Tausendertrennzeichen sind mit dem Feldtyp schlicht
 * nicht darstellbar - deshalb ein Textfeld mit inputmode="decimal", das die
 * Zifferntastatur auf Mobilgeräten genauso öffnet.
 *
 * Wurzelelement ist das <input> selbst: class, placeholder, aria-label und
 * disabled fallen damit unverändert vom Aufrufer durch, die bestehenden
 * Feld-Stile (.vbh-num, .vbh-short, …) gelten weiter.
 *
 * Beim Tippen meldet das Feld jeden lesbaren Zwischenstand als
 * update:modelValue - Anzeigen, die am Wert hängen (der offene Rest einer
 * Aufteilung), bleiben so live. 'change' kommt dagegen erst beim Verlassen
 * und nur bei echter Änderung, damit ein blosser Tab-Durchlauf nicht speichert.
 * Unlesbare Eingaben setzen den Wert auf den Stand vor dem Fokus zurück: ein
 * Vertipper darf keinen Planwert auf 0 setzen.
 */
export default {
	name: 'AmountInput',

	props: {
		/** Betrag in Euro; '' bzw. null lassen das Feld leer (Platzhalter). */
		modelValue: { type: [Number, String], default: '' },
		/** Kein '€' an der Anzeige – für Felder, neben denen schon eines steht. */
		hideCurrency: { type: Boolean, default: false },
		/** Wert, den ein geleertes Feld meldet (Planwerte wollen hier 0). */
		emptyValue: { type: [Number, String], default: '' },
	},

	emits: ['update:modelValue', 'change'],

	data() {
		return {
			display: '',
			focused: false,
			// Stand beim Fokussieren: Ziel des Rücksprungs bei unlesbarer
			// Eingabe und Vergleichswert für 'change'.
			valueAtFocus: '',
		}
	},

	watch: {
		modelValue: {
			immediate: true,
			handler(v) {
				if (!this.focused) { this.setDisplay(formatAmountInput(v, !this.hideCurrency)) }
			},
		},
	},

	methods: {
		/**
		 * Setzt die Anzeige - und schreibt sie notfalls selbst ins Feld.
		 *
		 * Vue vergleicht beim Rendern gegen den zuletzt *gebundenen* Wert, nicht
		 * gegen den im DOM stehenden. Tippt jemand "abc" ueber "20.000,00 €" und
		 * verlaesst das Feld, springt die Bindung auf denselben Wert zurueck -
		 * Vue sieht keine Aenderung, ueberschreibt das Feld nicht, und "abc"
		 * bliebe sichtbar stehen, obwohl der Wert laengst wieder 20000 ist.
		 *
		 * @param {string} text Anzeigetext
		 */
		setDisplay(text) {
			this.display = text
			this.$nextTick(() => {
				if (this.$el && this.$el.value !== text) { this.$el.value = text }
			})
		},

		onFocus() {
			this.focused = true
			this.valueAtFocus = this.modelValue
			this.setDisplay(amountInputRaw(this.modelValue))
		},

		onInput(event) {
			this.display = event.target.value
			if (event.target.value.trim() === '') {
				this.$emit('update:modelValue', this.emptyValue)
				return
			}
			const n = parseAmountInput(event.target.value)
			if (n !== null) { this.$emit('update:modelValue', n) }
		},

		onBlur(event) {
			this.focused = false
			const raw = event.target.value.trim()
			let value
			if (raw === '') {
				value = this.emptyValue
			} else {
				const n = parseAmountInput(raw)
				value = n === null ? this.valueAtFocus : roundCents(n)
			}
			this.setDisplay(formatAmountInput(value, !this.hideCurrency))
			this.$emit('update:modelValue', value)
			if (value !== this.valueAtFocus) { this.$emit('change', value) }
		},

		/** Enter schließt die Eingabe ab (und speichert damit, wo 'change' hängt). */
		onEnter(event) {
			event.target.blur()
		},
	},
}
</script>
