<template>
	<NcModal
		v-if="show"
		:show="show"
		labelId="vbh-modal-title-inv-plan"
		size="normal"
		:closeOnClickOutside="false"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-inv-plan" class="vbh-modal-title">
				{{ plan ? t('Plan bearbeiten') : t('Wiederkehrende Rechnung anlegen') }}
			</h2>
			<p v-if="!plan" class="vbh-hint">
				{{ t('Empfänger, Positionen und Texte kommen aus dieser Rechnung. Der Plan legt im Takt neue Rechnungen als Entwurf an; vergangene Termine holt er nicht nach.') }}
			</p>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Name des Plans') }}
					<input v-model="form.name" maxlength="255" :placeholder="t('z. B. Hallenmiete Sportstudio')">
				</label>
			</div>
			<div class="vbh-form">
				<label>{{ t('Takt') }}
					<select v-model.number="form.intervalMonths">
						<option v-for="m in intervals" :key="m" :value="m">{{ planIntervalLabel(m) }}</option>
					</select>
				</label>
				<label>{{ t('Erste Rechnung am') }}
					<input v-model="form.startDate" type="date">
				</label>
				<label>{{ t('Ende (optional)') }}
					<input v-model="form.endDate" type="date" :min="form.startDate || undefined">
				</label>
			</div>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Leistungszeitraum') }}
					<select v-model="form.periodMode">
						<option value="interval">{{ t('der Zeitraum des Taktes, der mit der Rechnung beginnt') }}</option>
						<option value="issue_date">{{ t('Leistungsdatum = Rechnungsdatum') }}</option>
					</select>
				</label>
				<label>{{ t('Zahlungsziel (Tage)') }}
					<input
						v-model="form.dueDays"
						class="vbh-short"
						type="number"
						min="0"
						max="365"
						:placeholder="t('Standard')">
				</label>
			</div>
			<p class="vbh-hint">
				{{ t('In Bezeichnungen und Texten ersetzt der Lauf {monat}, {jahr}, {quartal} und {zeitraum} durch die Angaben des Termins.') }}
			</p>
			<label class="vbh-checkrow">
				<input v-model="form.autoIssue" type="checkbox"> {{ t('Rechnung gleich festschreiben') }}
			</label>
			<label class="vbh-checkrow">
				<input v-model="form.autoSend" type="checkbox" :disabled="!form.autoIssue"> {{ t('und per E-Mail an den Empfänger senden') }}
			</label>
			<p class="vbh-hint">
				{{ t('Ohne Festschreiben bleibt jede Rechnung ein Entwurf, den Sie prüfen und festschreiben. Fehlen beim Festschreiben Angaben, bleibt der Entwurf liegen und der Plan nennt den Grund.') }}
			</p>
			<p class="vbh-hint">
				{{ n('%n Position', '%n Positionen', lineCount) }}
			</p>
			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="busy || !form.name.trim() || !form.startDate" @click="save">
					{{ t('Speichern') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { dueDaysOf, PLAN_INTERVALS, planIntervalLabel, planLinesFromInvoice, todayIso } from '../lib/invoices.js'

/**
 * Plan für wiederkehrende Rechnungen anlegen (aus einer vorhandenen Rechnung)
 * oder ändern (Epic #168, Stufe 3). Die Positionen kommen beim Anlegen aus der
 * Rechnung; ein Plan mit anderen Positionen entsteht aus einer anderen Rechnung.
 */
export default {
	name: 'InvoicePlanDialog',
	components: { NcModal, NcButton },
	props: {
		show: { type: Boolean, default: false },
		/** Beim Anlegen: die Rechnung, aus der der Plan entsteht. */
		invoice: { type: Object, default: null },
		/** Beim Ändern: der Plan. */
		plan: { type: Object, default: null },
	},

	emits: ['close', 'saved', 'update:show'],

	data() {
		return { busy: false, error: '', form: this.blank() }
	},

	computed: {
		intervals() { return PLAN_INTERVALS },
		lineCount() { return this.plan ? this.plan.lines.length : planLinesFromInvoice(this.invoice || {}).length },
	},

	watch: {
		show(open) {
			if (open) {
				this.error = ''
				this.form = this.blank()
			}
		},
	},

	methods: {
		planIntervalLabel,

		blank() {
			if (this.plan) {
				const p = this.plan
				return {
					name: p.name,
					intervalMonths: p.intervalMonths,
					startDate: p.startDate,
					endDate: p.endDate || '',
					periodMode: p.periodMode,
					dueDays: p.dueDays ?? '',
					autoIssue: p.autoIssue,
					autoSend: p.autoSend,
				}
			}
			const inv = this.invoice
			const first = inv?.lines?.[0]?.name || ''
			return {
				name: inv ? [inv.debtorName, first].filter(Boolean).join(' – ') : '',
				intervalMonths: 1,
				startDate: todayIso(),
				endDate: '',
				periodMode: 'interval',
				dueDays: inv ? (dueDaysOf(inv) ?? '') : '',
				autoIssue: false,
				autoSend: false,
			}
		},

		async save() {
			const f = this.form
			const body = {
				name: f.name,
				intervalMonths: f.intervalMonths,
				startDate: f.startDate,
				endDate: f.endDate || null,
				periodMode: f.periodMode,
				dueDays: f.dueDays === '' ? null : Number(f.dueDays),
				autoIssue: f.autoIssue,
				autoSend: f.autoIssue && f.autoSend,
			}
			if (!this.plan) {
				const inv = this.invoice
				Object.assign(body, {
					debtorId: inv.debtorId,
					lines: planLinesFromInvoice(inv),
					noteHead: inv.noteHead || '',
					noteFoot: inv.noteFoot || '',
					paymentTerms: inv.paymentTerms || '',
					buyerReference: inv.buyerReference || '',
					orderReference: inv.orderReference || '',
				})
			}
			this.busy = true
			this.error = ''
			try {
				const { data } = this.plan ? await api.updateInvoicePlan(this.plan.id, body) : await api.createInvoicePlan(body)
				this.$emit('saved', data)
			} catch (e) {
				this.error = errMsg(e, this.t('Der Plan konnte nicht gespeichert werden.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>
