<template>
	<div v-if="open" class="vbh-sheetwrap" role="dialog" aria-modal="true" :aria-label="title">
		<div class="vbh-sheet-scrim" @click="$emit('close')"></div>
		<div class="vbh-sheet">
			<div class="vbh-sheet-grabber" aria-hidden="true"></div>
			<div class="vbh-sheet-head">
				<span class="vbh-sheet-title">{{ title }}</span>
				<button type="button" class="vbh-sheet-close" aria-label="Schließen" @click="$emit('close')">✕</button>
			</div>
			<button v-if="suggestion"
				type="button"
				class="vbh-suggest-chip vbh-suggest-chip--big vbh-sheet-suggest"
				@click="$emit('suggest')">
				✓ Vorschlag übernehmen: {{ suggestion.label }}
			</button>
			<input v-model="search"
				type="search"
				class="vbh-search vbh-search--full vbh-sheet-search"
				placeholder="Konto suchen (Nummer oder Name)…">
			<div class="vbh-sheet-list">
				<template v-for="(opt, i) in filteredOptions">
					<div v-if="opt.id === null" :key="'h' + i" class="vbh-sheet-group">{{ opt.label }}</div>
					<button v-else
						:key="'o' + i"
						type="button"
						class="vbh-sheet-item"
						:class="{ current: opt.id === currentId }"
						@click="$emit('pick', opt)">
						{{ opt.label }}
					</button>
				</template>
				<p v-if="!filteredOptions.length" class="vbh-sheet-empty">Kein Konto gefunden.</p>
			</div>
		</div>
	</div>
</template>

<script>
/**
 * Bottom-Sheet zur Kontoauswahl auf Mobilgeräten: durchsuchbare Liste mit
 * Kategorie-Gruppen (Optionen mit id === null sind Überschriften), optional
 * mit Zuordnungs-Vorschlag als großer Primärtaste. Ein Sheet, mehrere
 * Einsätze: Umsatz zuordnen, Kategorie/Geldkonto bzw. Soll/Haben im
 * Buchungsdialog.
 *
 * Das Suchfeld wird bewusst nicht automatisch fokussiert: die aufspringende
 * Tastatur würde das halbe Sheet verdecken, bevor man die Liste gesehen hat.
 */
export default {
	name: 'AccountPickerSheet',
	props: {
		open: { type: Boolean, default: false },
		title: { type: String, default: 'Konto wählen' },
		/** Einträge {id, label}; id === null markiert Gruppen-Überschriften */
		options: { type: Array, default: () => [] },
		/** {id, label} oder null – erscheint als Primärtaste über der Suche */
		suggestion: { type: Object, default: null },
		currentId: { type: [Number, String], default: null },
	},
	data() {
		return { search: '' }
	},
	computed: {
		filteredOptions() {
			const s = this.search.trim().toLowerCase()
			if (!s) return this.options
			// Bei aktiver Suche flache Trefferliste ohne Gruppen-Überschriften
			return this.options.filter(o => o.id !== null && String(o.label).toLowerCase().includes(s))
		},
	},
	watch: {
		open(v) {
			if (v) this.search = ''
		},
	},
}
</script>
