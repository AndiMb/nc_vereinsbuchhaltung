<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-debit-reschedule"
		size="normal"
		:closeOnClickOutside="!saving"
		:noClose="saving"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-debit-reschedule" class="vbh-modal-title">
				{{ t('Einzugstermin verschieben') }}
			</h2>

			<p class="vbh-hint">
				{{ t('Der Lauf ist für den {datum} vorgesehen.', { datum: formatDate(dueDate) }) }}
			</p>

			<div class="vbh-form">
				<label>{{ t('Neuer Einzugstermin') }}
					<input
						ref="dateInput"
						v-model="newDate"
						type="date"
						:min="minDate"
						:aria-invalid="tooEarly ? 'true' : 'false'"
						aria-describedby="vbh-reschedule-help">
				</label>
			</div>
			<p id="vbh-reschedule-help" class="vbh-hint" :class="{ 'vbh-hint--warning': tooEarly }">
				<template v-if="tooEarly">
					{{ t('Der Termin lässt sich nur nach hinten verschieben: frühestens {datum}.', { datum: formatDate(minDate) }) }}
				</template>
				<template v-else>
					{{ t('Ein Lauf lässt sich nur nach hinten verschieben, frühestens auf den {datum}: Ein früherer Termin würde die Vorlauffristen unterschreiten.', { datum: formatDate(minDate) }) }}
				</template>
			</p>

			<p class="vbh-hint vbh-hint--info">
				{{ t('Die Datei bekommt den neuen Termin. Laden Sie sie danach neu herunter; Kennung der Datei und EndToEndIds bleiben unverändert.') }}
			</p>

			<!-- Die Serverantwort steht nicht in einer t()-Variable (die würde sie HTML-escapen). -->
			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" :disabled="saving" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving || !valid" @click="$emit('confirm', newDate)">
					{{ saving ? t('Wird verschoben…') : t('Termin verschieben') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'
import { earliestRescheduleDate, isLaterDate } from '../lib/debitRunActions.js'
import { formatDate } from '../lib/format.js'
import { focusOnOpen } from '../lib/modalFocus.js'

/**
 * Terminverschiebung eines freigegebenen Laufs (Issue #103, Spec §2.2/§3.5
 * „nur nach hinten verschiebbar, nie nach vorn“). Die Oberfläche lässt frühere
 * Daten gar nicht zu: das Datumsfeld trägt den Tag nach dem bisherigen Termin
 * als `min`, und der Knopf bleibt gesperrt, solange das Datum nicht echt später
 * liegt – auch bei einer von Hand getippten früheren Eingabe, die das Feld
 * selbst nicht verhindert. Der Server lehnt sie zusätzlich ab.
 */
export default {
	name: 'DebitRescheduleDialog',
	components: { NcButton, NcModal },
	props: {
		show: { type: Boolean, default: false },
		// Der bisherige Einzugstermin des Laufs
		dueDate: { type: String, required: true },
		saving: { type: Boolean, default: false },
		error: { type: String, default: null },
	},

	emits: ['close', 'confirm', 'update:show'],

	data() {
		return { newDate: '' }
	},

	computed: {
		minDate() { return earliestRescheduleDate(this.dueDate) },
		valid() { return isLaterDate(this.newDate, this.dueDate) },
		// Erst bei einer vollständigen Eingabe meckern, nicht schon beim Tippen der ersten Ziffern.
		tooEarly() { return /^\d{4}-\d{2}-\d{2}$/.test(this.newDate) && !this.valid },
	},

	watch: {
		show(open) {
			if (!open) { return }
			this.newDate = ''
			focusOnOpen(this, () => this.$refs.dateInput)
		},
	},

	methods: { formatDate },
}
</script>
