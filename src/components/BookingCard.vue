<template>
	<div class="vbh-mcard"
		:class="{ tappable }"
		:role="tappable ? 'button' : null"
		:tabindex="tappable ? 0 : null"
		@click="tappable && $emit('open')"
		@keyup.enter="tappable && $emit('open')">
		<div class="vbh-mcard-top">
			<span class="vbh-mcard-meta">#{{ row.entryNo }} · {{ formatDate(row.date) }}</span>
			<span class="vbh-mcard-amount" :class="flowClass">{{ amountLabel }}</span>
		</div>
		<div class="vbh-mcard-title">{{ row.description || '(ohne Beschreibung)' }}</div>
		<div class="vbh-mcard-bottom">
			<span class="vbh-mcard-accounts">
				<template v-if="row.isSplit">Splittbuchung (mehrere Zeilen)</template>
				<template v-else>{{ row.soll }} ← {{ row.haben }}</template>
			</span>
			<button v-if="attachmentCount > 0"
				type="button"
				class="vbh-mcard-clip"
				:aria-label="attachmentCount + ' Beleg(e) anzeigen'"
				@click.stop="$emit('paperclip')">
				<NcIconSvgWrapper :path="mdiPaperclip" :size="14" inline /> {{ attachmentCount }}
			</button>
		</div>
	</div>
</template>

<script>
import { NcIconSvgWrapper } from '@nextcloud/vue'
import { mdiPaperclip } from '@mdi/js'
import { formatMoney, formatDate } from '../lib/format.js'

/**
 * Mobile Kartendarstellung eines Buchungssatzes (journalRows-Zeile):
 * Nr./Datum + Betrag, volle Beschreibung, Soll ← Haben klein, Beleg-Zähler.
 * Die ganze Karte ist das Touchziel (open); die Büroklammer ist ein eigenes
 * Ziel (paperclip), damit Belege ohne Umweg über den Dialog erreichbar sind.
 */
export default {
	name: 'BookingCard',
	components: { NcIconSvgWrapper },
	props: {
		row: { type: Object, required: true },
		attachmentCount: { type: Number, default: 0 },
		/** 'in' (Geldzufluss), 'out' (Geldabfluss) oder '' (neutral, z. B. Umbuchung) */
		flow: { type: String, default: '' },
		tappable: { type: Boolean, default: false },
	},
	data() {
		return { mdiPaperclip }
	},
	computed: {
		flowClass() {
			return this.flow === 'in' ? 'pos' : this.flow === 'out' ? 'neg' : ''
		},
		amountLabel() {
			if (this.flow === 'in') return '+' + formatMoney(this.row.amount)
			if (this.flow === 'out') return formatMoney(-this.row.amount)
			return formatMoney(this.row.amount)
		},
	},
	methods: { formatDate },
}
</script>
