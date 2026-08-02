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
		<div class="vbh-mcard-title">
			{{ row.description || t('(ohne Beschreibung)') }}
		</div>
		<div class="vbh-mcard-bottom">
			<!-- Bei einer Splittbuchung stehen in soll/haben mehrere Konten; die
			     zu zeigen ist nuetzlicher als der blosse Hinweis, dass es eine
			     ist. Der Vermerk davor ordnet sie trotzdem gleich ein. -->
			<span class="vbh-mcard-accounts">
				<template v-if="row.isSplit">{{ t('Splitt: ') }}</template>{{ row.soll }} ← {{ row.haben }}
			</span>
			<button v-if="attachmentCount > 0"
				type="button"
				class="vbh-mcard-clip"
				:aria-label="n('%n Beleg anzeigen', '%n Belege anzeigen', attachmentCount)"
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
