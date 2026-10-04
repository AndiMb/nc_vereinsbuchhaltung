<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-debit-discard"
		size="normal"
		:closeOnClickOutside="!saving"
		:noClose="saving"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-debit-discard" class="vbh-modal-title">
				{{ drift ? t('Lauf verwerfen und neu freigeben') : t('Lauf verwerfen') }}
			</h2>

			<p class="vbh-hint">
				{{ t('Einzug am {datum}: {n} Posten, {summe}.', { datum: formatDate(dueDate), n: itemCount, summe: formatMoney(sumCents / 100) }) }}
			</p>

			<div class="vbh-card vbh-card--danger">
				<p>
					{{ t('Der Lauf ist danach verworfen, das lässt sich nicht zurücknehmen. Die Posten bleiben als Historie stehen, die Forderungen werden wieder frei, und die EndToEndIds werden nie wieder verwendet.') }}
				</p>
				<p>
					<strong>{{ t('Ist die Datei schon bei der Bank?') }}</strong>
					{{ t('Dann verwerfen Sie nicht, sondern bestätigen die Einreichung: Das Verwerfen ändert nichts bei der Bank, und die freien Forderungen könnten sonst ein zweites Mal eingezogen werden.') }}
				</p>
			</div>

			<p v-if="drift" class="vbh-hint vbh-hint--info">
				{{ t('Nach dem Verwerfen geben Sie den Termin neu frei: Die neue Datei enthält die aktuellen Mandatsdaten.') }}
			</p>

			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Begründung (Pflicht)') }}
					<textarea
						ref="reasonInput"
						v-model="reason"
						rows="3"
						:placeholder="t('z. B. falsches Datum gewählt')" />
				</label>
			</div>

			<!-- Die Serverantwort steht nicht in einer t()-Variable (die würde sie HTML-escapen). -->
			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" :disabled="saving" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="error" :disabled="saving || !reason.trim()" @click="$emit('confirm', reason.trim())">
					{{ saving ? t('Wird verworfen…') : t('Lauf verwerfen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'
import { formatDate, formatMoney } from '../lib/format.js'
import { focusOnOpen } from '../lib/modalFocus.js'

/**
 * Verwerfen eines freigegebenen Laufs (Issue #103, Spec §3.5): die
 * Begründung ist Pflicht, sie steht danach im Lauf-Detail und im
 * Änderungsprotokoll. Der Dialog sagt, was bleibt (Historie) und was sich
 * ändert (Forderungen frei, EndToEndIds nie wieder), und warnt vor dem
 * einen gefährlichen Fall: Datei schon bei der Bank, Einreichung nur noch
 * nicht vermerkt.
 *
 * `drift` ist der Weg aus der Drift-Warnung („N Posten weichen von den
 * aktuellen Mandatsdaten ab“): derselbe Dialog, mit der Abweichung als
 * vorgeschlagener Begründung und dem Hinweis, danach neu freizugeben.
 */
export default {
	name: 'DebitDiscardDialog',
	components: { NcButton, NcModal },
	props: {
		show: { type: Boolean, default: false },
		dueDate: { type: String, required: true },
		itemCount: { type: Number, default: 0 },
		sumCents: { type: Number, default: 0 },
		drift: { type: Boolean, default: false },
		saving: { type: Boolean, default: false },
		error: { type: String, default: null },
	},

	emits: ['close', 'confirm', 'update:show'],

	data() {
		return { reason: '' }
	},

	watch: {
		show(open) {
			if (!open) { return }
			// Die Abweichung ist der Anlass – als Vorschlag, die Person darf ihn ändern.
			this.reason = this.drift ? this.t('Abweichung von den aktuellen Mandatsdaten') : ''
			focusOnOpen(this, () => this.$refs.reasonInput)
		},
	},

	methods: { formatDate, formatMoney },
}
</script>
