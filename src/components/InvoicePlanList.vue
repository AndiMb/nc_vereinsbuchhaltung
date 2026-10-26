<template>
	<div class="vbh-sectionbody">
		<p class="vbh-hint">
			{{ t('Ein Plan legt im Takt neue Rechnungen an, zum Beispiel jeden Monat die Hallenmiete. Platzhalter in Bezeichnungen und Texten: {monat}, {jahr}, {quartal}, {zeitraum}.') }}
		</p>
		<NcLoadingIcon v-if="loading && !plans.length" :size="28" :name="t('Wird geladen…')" />
		<div v-else-if="!plans.length" class="vbh-card vbh-inv-empty">
			<h3>{{ t('Noch keine wiederkehrenden Rechnungen') }}</h3>
			<p>{{ t('Für Leistungen im festen Takt, etwa Hallenmiete, Sponsoring oder eine Jahresgebühr, legt ein Plan die Rechnungen von selbst an.') }}</p>
			<NcButton v-if="isAdmin" variant="primary" @click="$emit('create')">
				{{ t('Ersten Plan anlegen') }}
			</NcButton>
		</div>
		<ul v-else class="vbh-inv-plans">
			<li v-for="p in plans" :key="p.id" class="vbh-inv-plan">
				<div class="vbh-inv-plan-main">
					<strong>{{ p.name }}</strong>
					<span class="vbh-hint">
						{{ debtorName(p) }}<template v-if="p.autoIssue">
							· {{ p.autoSend ? t('festschreiben und senden') : t('festschreiben') }}
						</template>
					</span>
					<span class="vbh-inv-plan-meta">
						{{ planIntervalLabel(p.intervalMonths) }} ·
						<template v-if="!p.active">{{ t('pausiert') }}</template>
						<template v-else-if="p.nextRun">{{ t('Nächste Rechnung') }}: {{ formatDate(p.nextRun) }}</template>
						<template v-else>{{ t('beendet') }}</template>
					</span>
					<span v-if="p.lastRunAt" class="vbh-inv-plan-meta">
						{{ t('Zuletzt') }}: {{ formatDate(p.lastRunAt) }}
						<a v-if="p.lastInvoiceId" href="#" @click.prevent="$emit('open', p.lastInvoiceId)">{{ t('Rechnung öffnen') }}</a>
					</span>
					<span v-if="p.lastError" class="vbh-hint vbh-hint--error" role="alert">{{ p.lastError }}</span>
				</div>
				<div v-if="isAdmin" class="vbh-inv-plan-actions">
					<NcButton variant="tertiary" @click="$emit('edit', p)">
						{{ t('Bearbeiten') }}
					</NcButton>
					<NcButton variant="tertiary" @click="toggle(p)">
						{{ p.active ? t('Pausieren') : t('Fortsetzen') }}
					</NcButton>
					<NcButton variant="tertiary" @click="remove(p)">
						{{ t('Löschen') }}
					</NcButton>
				</div>
			</li>
		</ul>
		<div v-if="isAdmin && plans.length" class="vbh-inv-plan-bar">
			<NcButton variant="secondary" :disabled="busy" @click="runDue">
				{{ t('Fällige jetzt ausführen') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import api from '../api.js'
import { errMsg, formatDate } from '../lib/format.js'
import { planIntervalLabel } from '../lib/invoices.js'

/** Die Pläne für wiederkehrende Rechnungen als Liste im Reiter „Rechnungen" (Epic #168, Stufe 3). */
export default {
	name: 'InvoicePlanList',
	components: { NcButton, NcLoadingIcon },
	props: {
		isAdmin: { type: Boolean, default: false },
		/** Rechnungen der Liste, um den Empfänger eines Plans zu benennen. */
		invoices: { type: Array, default: () => [] },
		/** Zählt hoch, wenn die Liste neu geladen werden soll. */
		reloadKey: { type: Number, default: 0 },
	},

	emits: ['changed', 'create', 'edit', 'open'],

	data() {
		return { loading: false, busy: false, plans: [] }
	},

	watch: {
		reloadKey() { this.load() },
	},

	mounted() {
		this.load()
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
.vbh-inv-plans { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }

.vbh-inv-plan { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 6px 12px; padding: 10px 12px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 8px); }

.vbh-inv-plan-main { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1 1 16rem; overflow-wrap: anywhere; }

.vbh-inv-plan-meta { font-size: 0.9em; color: var(--color-text-maxcontrast); }

.vbh-inv-plan-actions { display: flex; flex-wrap: wrap; gap: 2px; }

.vbh-inv-plan-bar { margin-top: 12px; }

.vbh-inv-empty { max-width: 640px; }

.vbh-inv-empty h3 { margin-top: 0; }
</style>
