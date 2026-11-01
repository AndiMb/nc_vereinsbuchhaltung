<template>
	<div class="vbh-card vbh-batch-panel">
		<div class="vbh-batch-head">
			<template v-if="renaming">
				<input
					ref="nameInput"
					v-model="newName"
					class="vbh-batch-nameinput"
					maxlength="255"
					:aria-label="t('Name des Projekts')"
					@keydown.enter="saveName"
					@keydown.esc="renaming = false">
				<NcButton variant="primary" :disabled="busy || !newName.trim()" @click="saveName">
					{{ t('Speichern') }}
				</NcButton>
				<NcButton variant="tertiary" @click="renaming = false">
					{{ t('Abbrechen') }}
				</NcButton>
			</template>
			<template v-else>
				<h3>{{ batch.name }}</h3>
				<span v-if="batch.sourceFilename" class="vbh-batch-source">{{ batch.sourceFilename }}</span>
				<NcButton v-if="canWrite" variant="tertiary" @click="startRename">
					{{ t('Umbenennen') }}
				</NcButton>
				<NcButton
					v-if="canWrite && stats.total === stats.draft"
					variant="tertiary"
					:disabled="busy"
					@click="removeBatch">
					{{ t('Projekt löschen') }}
				</NcButton>
			</template>
		</div>

		<div class="vbh-totals">
			<div class="vbh-total">
				<span>{{ t('Rechnungen') }}</span>
				<strong>{{ stats.total }}</strong>
			</div>
			<div class="vbh-total">
				<span>{{ t('Summe') }}</span>
				<strong>{{ formatMoney(stats.grossCents / 100) }}</strong>
			</div>
			<div class="vbh-total">
				<span>{{ t('Bezahlt') }}</span>
				<strong>{{ formatMoney(stats.settledCents / 100) }}</strong>
			</div>
			<div class="vbh-total" :class="{ 'vbh-total--warn': stats.remainingCents > 0 }">
				<span>{{ t('Offen') }}</span>
				<strong>{{ formatMoney(stats.remainingCents / 100) }}</strong>
			</div>
		</div>
		<p class="vbh-hint">
			{{ t('Entwürfe: {draft} · festgeschrieben, nicht versendet: {issued} · versendet: {sent} · bezahlt: {paid} · storniert: {cancelled}', stats) }}
		</p>

		<div v-if="canWrite && (drafts.length || sendable.length)" class="vbh-batch-actions">
			<NcButton
				v-if="drafts.length"
				variant="primary"
				:disabled="busy || !isAdmin"
				:title="isAdmin ? '' : t('Festschreiben darf nur ein Verwalter.')"
				@click="issueAll">
				{{ n('%n Entwurf festschreiben', 'Alle %n Entwürfe festschreiben', drafts.length) }}
			</NcButton>
			<NcButton
				v-if="sendable.length"
				:variant="drafts.length ? 'secondary' : 'primary'"
				:disabled="busy || !isAdmin"
				:title="isAdmin ? '' : t('Versenden darf nur ein Verwalter.')"
				@click="sendAll">
				<template #icon>
					<NcIconSvgWrapper :path="mdiEmailFastOutline" :size="20" />
				</template>
				{{ n('%n Rechnung per E-Mail senden', 'Alle %n Rechnungen per E-Mail senden', sendable.length) }}
			</NcButton>
		</div>

		<div v-if="progress" class="vbh-batch-progress" role="status">
			<progress :value="progress.done" :max="progress.total" />
			<span>{{ progress.label }} {{ progress.done }} / {{ progress.total }}</span>
		</div>

		<div v-if="failures.length" class="vbh-hint vbh-hint--warning" role="alert">
			<p>{{ n('%n Rechnung hat nicht geklappt:', '%n Rechnungen haben nicht geklappt:', failures.length) }}</p>
			<ul class="vbh-batch-list">
				<li v-for="f in failures" :key="f.item.id">
					<a href="#" @click.prevent="$emit('open', f.item.id)">{{ f.item.number || f.item.debtorName || t('Entwurf') }}</a>: {{ f.message }}
				</li>
			</ul>
		</div>

		<div v-if="printOnly.length" class="vbh-hint vbh-hint--info">
			<p>{{ n('%n festgeschriebene Rechnung hat keine E-Mail-Adresse – bitte das PDF drucken oder auf anderem Weg schicken:', '%n festgeschriebene Rechnungen haben keine E-Mail-Adresse – bitte die PDFs drucken oder auf anderem Weg schicken:', printOnly.length) }}</p>
			<ul class="vbh-batch-list">
				<li v-for="inv in printOnly" :key="inv.id">
					<a :href="pdfUrl(inv.id)" target="_blank" rel="noopener">{{ inv.number }} – {{ inv.debtorName }}</a>
				</li>
			</ul>
		</div>
	</div>
</template>

<script>
import { mdiEmailFastOutline } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import api from '../api.js'
import { useConfirm } from '../composables/useConfirm.js'
import { errMsg, formatMoney } from '../lib/format.js'
import { batchStats, failureMessage, issuableInvoices, printOnlyInvoices, runSequential, sendableInvoices } from '../lib/invoiceBatches.js'
import { eInvoiceDocuments } from '../lib/invoices.js'

/**
 * Kopf eines Rechnungsstapels (#243) über der Rechnungsliste: Stand, Beträge
 * und die Sammelaktionen. Festschreiben und Versenden laufen je Rechnung über
 * die vorhandenen Endpunkte, nacheinander: jede Anfrage bleibt kurz (PDF und
 * E-Rechnung je Rechnung), der Fortschritt ist sichtbar, und eine gescheiterte
 * Rechnung hält die übrigen nicht auf.
 */
export default {
	name: 'InvoiceBatchPanel',
	components: { NcButton, NcIconSvgWrapper },
	props: {
		batch: { type: Object, required: true },
		/** Alle Rechnungen der Liste; der Stapel filtert selbst. */
		invoices: { type: Array, default: () => [] },
		canWrite: { type: Boolean, default: false },
		isAdmin: { type: Boolean, default: false },
	},

	emits: ['changed', 'renamed', 'deleted', 'open'],

	setup() {
		return { askConfirm: useConfirm().askConfirm, mdiEmailFastOutline }
	},

	data() {
		return { busy: false, progress: null, failures: [], renaming: false, newName: '' }
	},

	computed: {
		stats() { return batchStats(this.invoices, this.batch.id) },
		drafts() { return issuableInvoices(this.invoices, this.batch.id) },
		sendable() { return sendableInvoices(this.invoices, this.batch.id) },
		printOnly() { return printOnlyInvoices(this.invoices, this.batch.id) },
	},

	watch: {
		'batch.id': function() {
			this.failures = []
			this.progress = null
			this.renaming = false
		},
	},

	methods: {
		formatMoney,

		pdfUrl(id) { return api.invoicePdfUrl(id) },

		async run(items, label, worker) {
			this.busy = true
			this.failures = []
			this.progress = { done: 0, total: items.length, label }
			try {
				const result = await runSequential(items, worker, (done) => { this.progress.done = done }, (e) => failureMessage(e, this.t('Unbekannter Fehler'), this.t('Es fehlt:')))
				this.failures = result.failed
				return result
			} finally {
				this.busy = false
				this.progress = null
				this.$emit('changed')
			}
		},

		async issueAll() {
			const items = [...this.drafts]
			if (!await this.askConfirm(
				this.t('Entwürfe festschreiben'),
				this.n('Die Rechnung wird festgeschrieben: sie bekommt ihre Nummer und lässt sich danach nicht mehr ändern, nur stornieren.', '%n Rechnungen werden festgeschrieben: sie bekommen ihre Nummern und lassen sich danach nicht mehr ändern, nur stornieren.', items.length),
				this.t('Festschreiben'),
				'primary',
			)) { return }
			const result = await this.run(items, this.t('Festschreiben …'), (inv) => api.issueInvoice(inv.id))
			if (result.ok) {
				showSuccess(this.n('%n Rechnung festgeschrieben.', '%n Rechnungen festgeschrieben.', result.ok))
			}
		},

		async sendAll() {
			const items = [...this.sendable]
			if (!await this.askConfirm(
				this.t('Rechnungen versenden'),
				this.n('Die Rechnung geht mit dem Text aus den Einstellungen an die hinterlegte E-Mail-Adresse.', '%n Rechnungen gehen mit dem Text aus den Einstellungen an die jeweils hinterlegte E-Mail-Adresse.', items.length),
				this.t('Senden'),
				'primary',
			)) { return }
			const result = await this.run(items, this.t('Versenden …'), async (inv) => {
				const [defaults, detail] = await Promise.all([api.invoiceMailDefaults(inv.id), api.invoice(inv.id)])
				if (!defaults.data.to) {
					throw new Error(this.t('Keine E-Mail-Adresse'))
				}
				await api.sendInvoice(inv.id, {
					to: defaults.data.to,
					subject: defaults.data.subject,
					body: defaults.data.body,
					copyBoard: false,
					// Was an E-Rechnung schon da ist, geht mit – wie im Versanddialog.
					documentIds: eInvoiceDocuments(detail.data.documents).map((d) => d.id),
				})
			})
			if (result.ok) {
				showSuccess(this.n('%n Rechnung versendet.', '%n Rechnungen versendet.', result.ok))
			}
		},

		startRename() {
			this.newName = this.batch.name
			this.renaming = true
			this.$nextTick(() => this.$refs.nameInput && this.$refs.nameInput.focus())
		},

		async saveName() {
			if (!this.newName.trim()) { return }
			this.busy = true
			try {
				const { data } = await api.renameInvoiceBatch(this.batch.id, this.newName.trim())
				this.renaming = false
				this.$emit('renamed', data)
			} catch (e) {
				showError(errMsg(e, this.t('Umbenennen fehlgeschlagen')))
			} finally {
				this.busy = false
			}
		},

		async removeBatch() {
			if (!await this.askConfirm(
				this.t('Projekt löschen'),
				[
					this.tRaw('Das Projekt „{name}“ wird gelöscht.', { name: this.batch.name }),
					this.n('Sein Entwurf wird mitgelöscht.', 'Seine %n Entwürfe werden mitgelöscht.', this.stats.draft),
					this.t('Neu angelegte Empfänger bleiben erhalten.'),
				].join(' '),
				this.t('Löschen'),
			)) { return }
			this.busy = true
			try {
				await api.deleteInvoiceBatch(this.batch.id)
				this.$emit('deleted', this.batch.id)
			} catch (e) {
				showError(errMsg(e, this.t('Löschen fehlgeschlagen')))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-batch-panel {
	margin-bottom: 12px;
}

.vbh-batch-head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.vbh-batch-head h3 {
	margin: 0;
}

.vbh-batch-source {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.vbh-batch-nameinput {
	min-width: 260px;
}

.vbh-batch-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 8px;
}

.vbh-batch-progress {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 8px;
}

.vbh-batch-list {
	margin: 4px 0 0;
	padding-inline-start: 20px;
}
</style>
