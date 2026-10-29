<template>
	<NcModal
		v-if="show"
		:show="show"
		labelId="vbh-modal-title-inv-issue"
		size="normal"
		:closeOnClickOutside="false"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-inv-issue" class="vbh-modal-title">
				{{ t('Rechnung festschreiben') }}
			</h2>
			<p class="vbh-hint vbh-hint--warning">
				{{ t('Beim Festschreiben bekommt die Rechnung ihre Nummer, und die Anschrift des Empfängers wird übernommen. Danach lässt sie sich nicht mehr ändern, nur noch stornieren. Gleichzeitig entsteht der offene Posten.') }}
			</p>

			<ul class="vbh-inv-check" :aria-label="t('Prüfung der Pflichtangaben')">
				<li v-for="c in visibleChecks" :key="c.key" :class="{ ok: c.ok, missing: !c.ok && c.required, advice: !c.ok && !c.required }">
					<NcIconSvgWrapper :path="c.ok ? mdiCheckCircle : (c.required ? mdiCloseCircle : mdiAlertCircle)" :size="20" />
					<span>{{ c.label }}<em v-if="!c.ok && !c.required"> {{ t('(empfohlen)') }}</em></span>
				</li>
			</ul>

			<label class="vbh-inv-issuedate">{{ t('Rechnungsdatum') }}
				<input v-model="date" type="date">
			</label>
			<p class="vbh-hint">
				{{ t('Die Rechnungsnummer folgt aus dem Nummernkreis und dem Jahr dieses Datums.') }}
			</p>

			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="busy || blocked || !date" @click="$emit('confirm', date)">
					{{ t('Jetzt festschreiben') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { mdiAlertCircle, mdiCheckCircle, mdiCloseCircle } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcModal from '@nextcloud/vue/components/NcModal'
import { todayIso } from '../lib/invoices.js'

/**
 * Letzte Prüfung vor dem Festschreiben (Epic #168): zeigt die Pflichtangaben
 * nach § 14 Abs. 4 UStG als Liste und lässt das Rechnungsdatum noch ändern.
 * „Festschreiben" bleibt gesperrt, solange eine Pflichtangabe fehlt.
 */
export default {
	name: 'InvoiceIssueDialog',
	components: { NcModal, NcButton, NcIconSvgWrapper },
	props: {
		show: { type: Boolean, default: false },
		/** Punkte aus der Prüfliste des Servers: { key, label, ok, required }. */
		checklist: { type: Array, default: () => [] },
		issueDate: { type: String, default: '' },
		busy: { type: Boolean, default: false },
		error: { type: String, default: '' },
	},

	emits: ['close', 'confirm', 'update:show'],

	setup() {
		return { mdiAlertCircle, mdiCheckCircle, mdiCloseCircle }
	},

	data() {
		return { date: this.issueDate || todayIso() }
	},

	computed: {
		blocked() { return this.checklist.some((c) => c.required && !c.ok) },
		/**
		 * Pflichtangaben immer, Empfehlungen nur, solange sie offen sind (Issue #214): Ihre Texte
		 * beschreiben das Problem („Der Vereinsname trägt nicht den Zusatz …“) und läsen sich mit
		 * grünem Haken wie ein Widerspruch. So zeigt es auch der Editor unter „Zur Beachtung“.
		 */
		visibleChecks() { return this.checklist.filter((c) => c.required || !c.ok) },
	},

	watch: {
		show(open) {
			if (open) { this.date = this.issueDate || todayIso() }
		},
	},
}
</script>

<style scoped>
.vbh-inv-check { list-style: none; margin: 12px 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }

.vbh-inv-check li { display: flex; align-items: center; gap: 8px; }

.vbh-inv-check .ok { color: var(--color-success-text, var(--color-main-text)); }

.vbh-inv-check .missing { color: var(--color-error-text, var(--color-main-text)); font-weight: 600; }

.vbh-inv-check .advice { color: var(--color-warning-text, var(--color-main-text)); }

.vbh-inv-check em { font-style: normal; opacity: 0.8; }

.vbh-inv-issuedate { display: flex; flex-direction: column; gap: 3px; width: max-content; font-weight: 600; }
</style>
