<template>
	<NcModal
		v-if="show"
		:show="show"
		labelId="vbh-modal-title-inv-plans"
		size="large"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-inv-plans" class="vbh-modal-title">
				{{ t('Wiederkehrende Rechnungen') }}
			</h2>
			<p class="vbh-hint">
				{{ t('Ein Plan legt im Takt neue Rechnungen an, zum Beispiel jeden Monat die Hallenmiete. Einen neuen Plan legen Sie im Detail einer Rechnung an („Wiederkehrend …“); sie liefert Empfänger und Positionen.') }}
			</p>
			<NcLoadingIcon v-if="loading" :size="28" :name="t('Wird geladen…')" />
			<p v-else-if="!plans.length" class="vbh-empty">
				{{ t('Noch keine Pläne.') }}
			</p>
			<table v-else class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Plan') }}</th>
						<th>{{ t('Takt') }}</th>
						<th>{{ t('Nächste Rechnung') }}</th>
						<th>{{ t('Zuletzt') }}</th>
						<th v-if="isAdmin" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="p in plans" :key="p.id">
						<td>
							<strong>{{ p.name }}</strong>
							<div class="vbh-hint">
								{{ debtorName(p) }}<template v-if="p.autoIssue">
									· {{ p.autoSend ? t('festschreiben und senden') : t('festschreiben') }}
								</template>
							</div>
						</td>
						<td>{{ planIntervalLabel(p.intervalMonths) }}</td>
						<td>
							<template v-if="!p.active">
								{{ t('pausiert') }}
							</template>
							<template v-else-if="p.nextRun">
								{{ formatDate(p.nextRun) }}
							</template>
							<template v-else>
								{{ t('beendet') }}
							</template>
						</td>
						<td>
							<template v-if="p.lastRunAt">
								{{ formatDate(p.lastRunAt) }}
								<a v-if="p.lastInvoiceId" href="#" @click.prevent="$emit('open', p.lastInvoiceId)">{{ t('Rechnung öffnen') }}</a>
							</template>
							<span v-if="p.lastError" class="vbh-hint vbh-hint--error" role="alert">{{ p.lastError }}</span>
						</td>
						<td v-if="isAdmin" class="vbh-inv-r">
							<NcButton variant="tertiary" @click="edit(p)">
								{{ t('Bearbeiten') }}
							</NcButton>
							<NcButton variant="tertiary" @click="toggle(p)">
								{{ p.active ? t('Pausieren') : t('Fortsetzen') }}
							</NcButton>
							<NcButton variant="tertiary" @click="remove(p)">
								{{ t('Löschen') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<div class="vbh-modal-actions">
				<NcButton
					v-if="isAdmin && plans.length"
					variant="secondary"
					:disabled="busy"
					@click="runDue">
					{{ t('Fällige jetzt ausführen') }}
				</NcButton>
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Schließen') }}
				</NcButton>
			</div>
		</div>

		<InvoicePlanDialog
			v-if="editing"
			:show="Boolean(editing)"
			:plan="editing"
			@close="editing = null"
			@update:show="editing = $event ? editing : null"
			@saved="onSaved" />
	</NcModal>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import InvoicePlanDialog from './InvoicePlanDialog.vue'
import api from '../api.js'
import { errMsg, formatDate } from '../lib/format.js'
import { planIntervalLabel } from '../lib/invoices.js'

/** Übersicht der Pläne für wiederkehrende Rechnungen (Epic #168, Stufe 3). */
export default {
	name: 'InvoicePlansDialog',
	components: { NcModal, NcButton, NcLoadingIcon, InvoicePlanDialog },
	props: {
		show: { type: Boolean, default: false },
		isAdmin: { type: Boolean, default: false },
		/** Rechnungen der Liste, um den Empfänger eines Plans zu benennen. */
		invoices: { type: Array, default: () => [] },
	},

	emits: ['close', 'changed', 'open', 'update:show'],

	data() {
		return { loading: false, busy: false, plans: [], editing: null }
	},

	watch: {
		show: {
			immediate: true,
			handler(open) {
				if (open) { this.load() }
			},
		},
	},

	methods: {
		formatDate,
		planIntervalLabel,

		debtorName(plan) {
			return this.invoices.find((i) => i.debtorId === plan.debtorId)?.debtorName || ''
		},

		async load() {
			this.loading = true
			try {
				const { data } = await api.invoicePlans()
				this.plans = data
			} catch (e) {
				showError(errMsg(e, this.t('Die Pläne konnten nicht geladen werden.')))
			} finally {
				this.loading = false
			}
		},

		edit(plan) { this.editing = plan },

		async onSaved() {
			this.editing = null
			showSuccess(this.t('Plan gespeichert.'))
			await this.load()
		},

		async toggle(plan) {
			try {
				await api.setInvoicePlanActive(plan.id, !plan.active)
				await this.load()
			} catch (e) {
				showError(errMsg(e, this.t('Der Plan konnte nicht geändert werden.')))
			}
		},

		async remove(plan) {
			try {
				await api.deleteInvoicePlan(plan.id)
				showSuccess(this.t('Plan gelöscht. Bereits angelegte Rechnungen bleiben bestehen.'))
				await this.load()
			} catch (e) {
				showError(errMsg(e, this.t('Der Plan konnte nicht gelöscht werden.')))
			}
		},

		async runDue() {
			this.busy = true
			try {
				const { data } = await api.runDueInvoicePlans()
				showSuccess(data.created > 0
					? this.n('%n Rechnung angelegt.', '%n Rechnungen angelegt.', data.created)
					: this.t('Es ist nichts fällig.'))
				await this.load()
				if (data.created > 0) { this.$emit('changed') }
			} catch (e) {
				showError(errMsg(e, this.t('Die Pläne konnten nicht ausgeführt werden.')))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-inv-r { text-align: end; white-space: nowrap; }
</style>
