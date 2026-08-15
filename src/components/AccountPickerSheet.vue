<template>
	<div
		v-show="open"
		class="vbh-sheetwrap"
		role="dialog"
		aria-modal="true"
		:aria-label="title">
		<div class="vbh-sheet-scrim" @click="$emit('close')" />
		<div class="vbh-sheet" :style="dragY ? { transform: 'translateY(' + dragY + 'px)', transition: 'none' } : null">
			<div
				class="vbh-sheet-dragzone"
				@touchstart.passive="onTouchStart"
				@touchmove.passive="onTouchMove"
				@touchend="onTouchEnd"
				@touchcancel="onTouchEnd">
				<div class="vbh-sheet-grabber" aria-hidden="true" />
				<div class="vbh-sheet-head">
					<span class="vbh-sheet-title">{{ title }}</span>
					<button
						type="button"
						class="vbh-sheet-close"
						:aria-label="t('Schließen')"
						@click="$emit('close')">
						✕
					</button>
				</div>
			</div>
			<button
				v-if="suggestion"
				type="button"
				class="vbh-suggest-chip vbh-suggest-chip--big vbh-sheet-suggest"
				@click="$emit('suggest')">
				{{ t('✓ Vorschlag übernehmen: {label}', { label: suggestion.label }) }}
			</button>
			<input
				v-model="search"
				type="search"
				class="vbh-search vbh-search--full vbh-sheet-search"
				:placeholder="t('Konto suchen (Nummer oder Name)…')">
			<div class="vbh-sheet-list">
				<template v-if="!searching && recent.length">
					<div class="vbh-sheet-group">
						{{ t('Zuletzt verwendet') }}
					</div>
					<button
						v-for="opt in recent"
						:key="'r' + opt.id"
						type="button"
						class="vbh-sheet-item"
						:class="{ current: opt.id === currentId }"
						@click="$emit('pick', opt)">
						{{ opt.label }}
					</button>
				</template>
				<template v-for="(opt, i) in filteredOptions" :key="i">
					<div v-if="opt.id === null" class="vbh-sheet-group">
						{{ opt.label }}
					</div>
					<button
						v-else
						type="button"
						class="vbh-sheet-item"
						:class="{ current: opt.id === currentId }"
						@click="$emit('pick', opt)">
						{{ opt.label }}
					</button>
				</template>
				<p v-if="!filteredOptions.length" class="vbh-sheet-empty">
					{{ t('Kein Konto gefunden.') }}
				</p>
			</div>
		</div>
	</div>
</template>

<script>
/**
 * Bottom-Sheet zur Kontoauswahl auf Mobilgeräten: durchsuchbare Liste mit
 * Kategorie-Gruppen (Optionen mit id === null sind Überschriften), optional
 * mit Zuordnungs-Vorschlag als großer Primärtaste und den zuletzt gewählten
 * Konten als eigener Gruppe. Ein Sheet, mehrere Einsätze: Umsatz zuordnen,
 * Kategorie/Geldkonto bzw. Soll/Haben im Buchungsdialog.
 *
 * Schließen: Scrim-Tipp, ✕ oder Wisch nach unten am Sheet-Kopf (die Liste
 * selbst muss scrollbar bleiben, daher hängt die Geste nur an der Dragzone).
 * Das Suchfeld wird bewusst nicht automatisch fokussiert: die aufspringende
 * Tastatur würde das halbe Sheet verdecken, bevor man die Liste gesehen hat.
 *
 * Manuelles Portal (mounted/beforeUnmount hängen $el an document.body) statt
 * <teleport>: Pflicht, kein Stilmittel. Nextclouds eigenes Core-CSS setzt
 * `#content { position: fixed }`, was einen neuen Stacking-Context aufmacht;
 * ohne Portal haengt das Sheet darin fest und bleibt trotz hohem z-index
 * unsichtbar/unklickbar hinter einem NcModal (z. B. dem Buchungsdialog) -
 * NcModal selbst entkommt dem nur, weil @nextcloud/vue seine Modals ebenfalls
 * an document.body haengt. <teleport> waere die saubere Vue-3-Loesung, wird
 * von der hier verwendeten vue-loader-15-Toolchain (Vue-2.6-Aera-SFC-Pipeline
 * trotz Vue 2.7 im Package) aber nur als wirkungsloses literales DOM-Element
 * durchgereicht - deshalb der manuelle Weg. v-show statt v-if am Wurzelement
 * ist hierfuer noetig: $el muss ueber die gesamte Komponenten-Lebensdauer
 * dasselbe Element bleiben, sonst haette mounted() nichts zum Verschieben.
 */
export default {
	name: 'AccountPickerSheet',
	props: {
		open: { type: Boolean, default: false },
		title: { type: String, default: 'Konto wählen' },
		/** Einträge {id, label}; id === null markiert Gruppen-Überschriften */
		options: { type: Array, default: () => [] },
		/** Zuletzt gewählte Konten {id, label} – eigene Gruppe über der Liste */
		recent: { type: Array, default: () => [] },
		/** {id, label} oder null – erscheint als Primärtaste über der Suche */
		suggestion: { type: Object, default: null },
		currentId: { type: [Number, String], default: null },
	},

	emits: ['close', 'pick', 'suggest'],
	data() {
		return { search: '', dragStartY: null, dragY: 0 }
	},

	computed: {
		searching() {
			return this.search.trim() !== ''
		},

		filteredOptions() {
			const s = this.search.trim().toLowerCase()
			if (!s) { return this.options }
			// Bei aktiver Suche flache Trefferliste ohne Gruppen-Überschriften
			return this.options.filter((o) => o.id !== null && String(o.label).toLowerCase().includes(s))
		},
	},

	watch: {
		open(v) {
			if (v) {
				this.search = ''
				this.dragStartY = null
				this.dragY = 0
			}
		},
	},

	mounted() {
		document.body.appendChild(this.$el)
	},

	beforeUnmount() {
		if (this.$el && this.$el.parentNode) { this.$el.parentNode.removeChild(this.$el) }
	},

	methods: {
		onTouchStart(e) {
			this.dragStartY = e.touches[0].clientY
			this.dragY = 0
		},

		onTouchMove(e) {
			if (this.dragStartY === null) { return }
			this.dragY = Math.max(0, e.touches[0].clientY - this.dragStartY)
		},

		onTouchEnd() {
			if (this.dragY > 70) { this.$emit('close') }
			this.dragStartY = null
			this.dragY = 0
		},
	},
}
</script>
