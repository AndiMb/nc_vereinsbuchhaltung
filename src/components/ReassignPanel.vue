<template>
	<div class="vbh-reassign">
		<div v-if="sides.length > 1" class="vbh-reassign-sides">
			<span class="vbh-reassign-lab">Welche Seite umbuchen?</span>
			<button v-for="s in sides"
				:key="s.accountId"
				type="button"
				class="vbh-reassign-side"
				:class="{ active: s.accountId === sideId }"
				@click="$emit('side', s.accountId)">
				{{ s.label }}
			</button>
		</div>
		<div class="vbh-reassign-row">
			<span class="vbh-reassign-lab">{{ currentLabel }} → </span>
			<NcSelect :model-value="null"
				:options="options"
				:filter-by="accountFilterBy"
				:disabled="busy"
				label="label"
				placeholder="Neues Konto wählen…"
				class="vbh-reassign-select"
				@update:model-value="v => v && v.id && $emit('pick', v.id)" />
			<NcButton variant="tertiary"
				size="small"
				:disabled="busy"
				@click="$emit('cancel')">
				Abbrechen
			</NcButton>
		</div>
		<p class="vbh-hint">
			Es ändert sich nur die Kontozuordnung dieser einen Seite – Betrag, Datum, Beschreibung
			und die Gegenseite bleiben unverändert.
		</p>
	</div>
</template>

<script>
import { NcButton, NcSelect } from '@nextcloud/vue'

/**
 * Umbuchen einer Buchungsseite direkt im Kontoauszug.
 *
 * Eine Buchung hat zwei (bei Splittbuchungen mehr) Seiten; welche davon auf ein
 * anderes Konto soll, entscheidet die Auswahl oben. Voreingestellt ist die
 * Seite des gerade geöffneten Kontos – das ist der übliche Fall: man sieht beim
 * Durchblättern eines Kontos, dass eine Buchung dort nicht hingehört.
 */
export default {
	name: 'ReassignPanel',
	components: { NcButton, NcSelect },
	props: {
		/** Beteiligte Konten der Buchung: [{accountId, label}] */
		sides: { type: Array, required: true },
		/** Aktuell gewählte Seite (Konto-ID) */
		sideId: { type: [Number, String], default: null },
		/** Zielkonten für NcSelect; id === null markiert Gruppen-Überschriften */
		options: { type: Array, required: true },
		busy: { type: Boolean, default: false },
	},
	computed: {
		currentLabel() {
			const s = this.sides.find(x => x.accountId === this.sideId)
			return s ? s.label : ''
		},
	},
	methods: {
		// Ziffern = Präfix der Kontonummer, sonst Textsuche – dieselbe reine
		// Logik wie in SettingsRules.vue/AccountDialog.vue.
		accountFilterBy(option, label, search) {
			const s = String(search || '').trim().toLowerCase()
			if (!s) return true
			if (option && option.$isDisabled) return false
			if (/^[\d\s]+$/.test(s)) {
				const digits = s.replace(/\s+/g, '')
				const num = String((option && option.number) || '').replace(/\s+/g, '').toLowerCase()
				return num.startsWith(digits)
			}
			return String(label || '').toLowerCase().includes(s)
		},
	},
}
</script>
