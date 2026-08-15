<template>
	<div>
		<p class="vbh-hint">
			{{ t('Erzeugt eine SEPA-XML-Datei (pain.008) aus allen offenen Posten mit aktivem Mandat, die bis zum Fälligkeitstag fällig sind und noch in keinem laufenden Einzug stecken. Vor dem ersten echten Einzug unbedingt mit dem Prüftool der Hausbank testen – das genaue Format kann je nach Bank leicht abweichen.') }}
		</p>

		<div class="vbh-card">
			<div class="vbh-form">
				<label>{{ t('Fälligkeitsdatum') }}
					<input v-model="executionDate" type="date" :min="today">
				</label>
				<NcButton :disabled="!executionDate || loading" @click="reloadPreview">
					{{ t('Vorschau aktualisieren') }}
				</NcButton>
				<NcButton variant="primary"
					:disabled="!executionDate || !sepaPreview.length || creating"
					@click="createBatch">
					{{ t('Einzug erzeugen') }}
				</NcButton>
			</div>
			<p v-if="leadDays !== null && leadDays < leadRequired" class="vbh-hint vbh-hint--warning">
				{{ t('Bis zu diesem Termin sind es nur noch {n} Tage. Das SEPA-Regelwerk verlangt, dass Sie den Zahler mindestens {required} Tage vorher über Betrag und Termin informieren – die automatische Vorankündigung schafft das dann nicht mehr vollständig.', { n: leadDays, required: leadRequired }) }}
			</p>
			<p v-else class="vbh-hint">
				{{ t('Vorgeschlagen sind {required} Tage Vorlauf: so viel Zeit verlangt das SEPA-Regelwerk für die Vorankündigung an den Zahler.', { required: leadRequired }) }}
			</p>
		</div>

		<div v-if="sepaPreview.length && isMobile" class="vbh-cardlist">
			<div class="vbh-tablecount">
				{{ t('Summe {sum}', { sum: formatMoney(previewTotal) }) }}
			</div>
			<div v-for="row in sepaPreview" :key="row.openItem.id" class="vbh-mcard">
				<div class="vbh-mcard-top">
					<span class="vbh-mcard-title">{{ row.debtorName }}</span>
					<span class="vbh-mcard-amount">{{ formatMoney(row.openItem.amount) }}</span>
				</div>
				<div class="vbh-mcard-bottom">
					<span class="vbh-mcard-accounts">{{ t('fällig {date}', { date: row.openItem.dueDate || '–' }) }} · {{ sequenceLabel(row.sequenceType) }}</span>
				</div>
				<div class="vbh-mcard-bottom">
					<span class="vbh-mcard-accounts">{{ row.mandate.mandateReference }}</span>
				</div>
			</div>
		</div>
		<div v-else-if="sepaPreview.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Zahler') }}</th>
						<th class="num">
							{{ t('Betrag') }}
						</th>
						<th>{{ t('Fällig am') }}</th>
						<th>{{ t('Mandatsreferenz') }}</th>
						<th>{{ t('Art') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in sepaPreview" :key="row.openItem.id">
						<td>{{ row.debtorName }}</td>
						<td class="num nowrap">
							{{ formatMoney(row.openItem.amount) }}
						</td>
						<td class="nowrap">
							{{ row.openItem.dueDate || '–' }}
						</td>
						<td class="nowrap">
							{{ row.mandate.mandateReference }}
						</td>
						<td>{{ sequenceLabel(row.sequenceType) }}</td>
					</tr>
				</tbody>
				<tfoot>
					<tr>
						<td>{{ t('Summe') }}</td>
						<td class="num nowrap">
							{{ formatMoney(previewTotal) }}
						</td>
						<td colspan="3" />
					</tr>
				</tfoot>
			</table>
		</div>
		<p v-else class="vbh-hint">
			{{ t('Bis zum gewählten Termin ist kein offener Posten mit aktivem SEPA-Mandat fällig.') }}
		</p>

		<p v-if="sepaBatches.length" class="vbh-hint vbh-hint--info">
			{{ t('Sobald das Geld auf dem Vereinskonto eingegangen ist, verbuchen Sie den Einzug als ausgeführt: die enthaltenen offenen Posten werden dann in einem Schritt als bezahlt geschlossen. Kommt später eine Rücklastschrift, öffnet der Kontoauszugs-Import den betroffenen Posten von selbst wieder.') }}
		</p>
		<div v-if="sepaBatches.length && isMobile" class="vbh-cardlist">
			<div v-for="b in sepaBatches" :key="b.id" class="vbh-mcard">
				<div class="vbh-mcard-top">
					<span class="vbh-mcard-title">{{ t('Fälligkeit {date}', { date: b.executionDate }) }}</span>
					<span class="vbh-typetag">{{ b.settledAt ? t('ausgeführt') : t('eingereicht') }}</span>
				</div>
				<div class="vbh-mcard-bottom">
					<span class="vbh-mcard-accounts">{{ t('erzeugt {date}', { date: b.createdAt }) }}</span>
				</div>
				<div class="vbh-mcard-actions">
					<NcButton variant="tertiary" size="small" @click="toggleItems(b.id)">
						{{ expanded === b.id ? t('Zeilen ausblenden') : t('Zeilen anzeigen') }}
					</NcButton>
					<a :href="xmlUrl(b.id)"
						target="_blank"
						rel="noopener"
						class="vbh-export-btn">{{ t('XML herunterladen') }}</a>
					<NcButton v-if="!b.settledAt"
						variant="primary"
						size="small"
						:disabled="settling === b.id"
						@click="settle(b)">
						{{ t('Als ausgeführt verbuchen') }}
					</NcButton>
					<NcButton v-if="!b.settledAt"
						variant="error"
						size="small"
						:aria-label="t('Einzug verwerfen')"
						@click="discard(b)">
						{{ t('Verwerfen') }}
					</NcButton>
				</div>
				<div v-if="expanded === b.id" class="vbh-cardlist vbh-mcard-subcards">
					<div v-for="item in (sepaBatchItems[b.id] || [])" :key="item.id" class="vbh-mcard">
						<div class="vbh-mcard-top">
							<span class="vbh-mcard-title">{{ item.debtorName }}</span>
							<span class="vbh-mcard-amount">{{ formatMoney(item.amount) }}</span>
						</div>
						<div class="vbh-mcard-bottom">
							<span class="vbh-mcard-accounts">{{ sequenceLabel(item.sequenceType) }} · {{ notificationLabel(item) }}</span>
						</div>
						<div class="vbh-mcard-bottom">
							<span class="vbh-typetag">{{ itemStatusLabel(item) }}</span>
							<span v-if="item.returnReason" class="vbh-hint">{{ item.returnReason }}</span>
						</div>
						<div v-if="item.status === 'returned'" class="vbh-mcard-actions">
							<NcButton variant="tertiary" size="small" @click="revert(b.id, item)">
								{{ t('Rückbuchung zurücknehmen') }}
							</NcButton>
						</div>
					</div>
					<p v-if="skippedCount(b.id) > 0" class="vbh-hint vbh-hint--warning">
						{{ t('{n} Zahler haben keine E-Mail-Adresse hinterlegt und konnten nicht angekündigt werden – bitte selbst informieren.', { n: skippedCount(b.id) }) }}
					</p>
				</div>
			</div>
		</div>
		<div v-else-if="sepaBatches.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Erzeugt') }}</th>
						<th>{{ t('Fälligkeit') }}</th>
						<th>{{ t('Status') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<template v-for="b in sepaBatches">
						<tr :key="b.id">
							<td class="nowrap">
								{{ b.createdAt }}
							</td>
							<td class="nowrap">
								{{ b.executionDate }}
							</td>
							<td class="nowrap">
								<span class="vbh-typetag">{{ b.settledAt ? t('ausgeführt') : t('eingereicht') }}</span>
							</td>
							<td class="nowrap right">
								<NcButton variant="tertiary" size="small" @click="toggleItems(b.id)">
									{{ expanded === b.id ? t('Zeilen ausblenden') : t('Zeilen anzeigen') }}
								</NcButton>
								<a :href="xmlUrl(b.id)"
									target="_blank"
									rel="noopener"
									class="vbh-export-btn">{{ t('XML herunterladen') }}</a>
								<NcButton v-if="!b.settledAt"
									variant="primary"
									size="small"
									:disabled="settling === b.id"
									@click="settle(b)">
									{{ t('Als ausgeführt verbuchen') }}
								</NcButton>
								<NcButton v-if="!b.settledAt"
									variant="error"
									size="small"
									:aria-label="t('Einzug verwerfen')"
									@click="discard(b)">
									{{ t('Verwerfen') }}
								</NcButton>
							</td>
						</tr>
						<tr v-if="expanded === b.id" :key="`${b.id}-items`">
							<td colspan="4">
								<table class="vbh-table vbh-subtable">
									<thead>
										<tr>
											<th>{{ t('Zahler') }}</th>
											<th class="num">
												{{ t('Betrag') }}
											</th>
											<th>{{ t('Art') }}</th>
											<th>{{ t('Vorankündigung') }}</th>
											<th>{{ t('Status') }}</th>
											<th />
										</tr>
									</thead>
									<tbody>
										<tr v-for="item in (sepaBatchItems[b.id] || [])" :key="item.id">
											<td>{{ item.debtorName }}</td>
											<td class="num nowrap">
												{{ formatMoney(item.amount) }}
											</td>
											<td>{{ sequenceLabel(item.sequenceType) }}</td>
											<td>{{ notificationLabel(item) }}</td>
											<td>
												<span class="vbh-typetag">{{ itemStatusLabel(item) }}</span>
												<span v-if="item.returnReason" class="vbh-hint">{{ item.returnReason }}</span>
											</td>
											<td class="nowrap right">
												<NcButton v-if="item.status === 'returned'"
													variant="tertiary"
													size="small"
													@click="revert(b.id, item)">
													{{ t('Rückbuchung zurücknehmen') }}
												</NcButton>
											</td>
										</tr>
									</tbody>
								</table>
								<p v-if="skippedCount(b.id) > 0" class="vbh-hint vbh-hint--warning">
									{{ t('{n} Zahler haben keine E-Mail-Adresse hinterlegt und konnten nicht angekündigt werden – bitte selbst informieren.', { n: skippedCount(b.id) }) }}
								</p>
							</td>
						</tr>
					</template>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { toRefs } from 'vue'
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../api.js'
import { errMsg, formatMoney } from '../lib/format.js'
import { useSepaBatches } from '../composables/useSepaBatches.js'
import { useConfirm } from '../composables/useConfirm.js'

/** Vorlaufzeit der Vorankündigung, siehe SepaNotificationService::LEAD_DAYS. */
const LEAD_DAYS = 14

/**
 * Erzeugen, Prüfen und Herunterladen von SEPA-Sammeleinzügen. Frueher
 * SettingsSepaExport.vue im Einstellungen-Modal; jetzt Unterreiter „Einzug"
 * von ContributionsTab.vue, siehe NAVIGATION-KONZEPT.md Abschnitt 4.
 *
 * Erreichbar ab Rolle Buchhalter (siehe SepaBatchController).
 */
export default {
	name: 'SepaBatchPanel',
	components: { NcButton },
	props: {
		isMobile: { type: Boolean, default: false },
	},
	setup() {
		const sepaBatches = useSepaBatches()
		return {
			...toRefs(sepaBatches.state),
			loadSepaPreview: sepaBatches.loadSepaPreview,
			loadSepaBatches: sepaBatches.loadSepaBatches,
			loadSepaBatchItems: sepaBatches.loadSepaBatchItems,
			askConfirm: useConfirm().askConfirm,
		}
	},
	data() {
		return {
			executionDate: '',
			creating: false,
			loading: false,
			// id des Einzugs, der gerade verbucht wird
			settling: null,
			leadRequired: LEAD_DAYS,
			// Aufgeklappter Einzug; immer nur einer, die Zeilenliste ist breit.
			expanded: null,
		}
	},
	computed: {
		today() { return new Date().toISOString().slice(0, 10) },
		previewTotal() { return this.sepaPreview.reduce((sum, r) => sum + r.openItem.amount, 0) },
		/** Tage bis zum gewählten Termin, oder null, solange keiner gewählt ist. */
		leadDays() {
			if (!this.executionDate) return null
			const ms = new Date(`${this.executionDate}T00:00:00`) - new Date(`${this.today}T00:00:00`)
			return Math.round(ms / 86400000)
		},
	},
	async mounted() {
		// Ohne Datum antwortet das Backend mit seinem Vorschlagstermin - den
		// uebernehmen wir, damit Vorschau und Eingabefeld dasselbe meinen.
		const data = await this.loadSepaPreview()
		this.executionDate = data?.executionDate || ''
		this.loadSepaBatches()
	},
	methods: {
		errMsg,
		formatMoney,
		xmlUrl(id) { return api.sepaBatchXmlUrl(id) },
		sequenceLabel(type) {
			return { FRST: this.t('Ersteinzug'), RCUR: this.t('Folgeeinzug'), OOFF: this.t('einmalig') }[type] || type
		},
		itemStatusLabel(item) {
			if (item.status === 'returned') return this.t('zurückgebucht')
			if (item.status === 'settled') return this.t('ausgeführt')
			return this.t('eingereicht')
		},
		notificationLabel(item) {
			if (item.notifiedState === 'sent') return this.t('verschickt')
			if (item.notifiedState === 'no_email') return this.t('keine Adresse')
			return this.t('offen')
		},
		skippedCount(batchId) {
			return (this.sepaBatchItems[batchId] || []).filter(i => i.notifiedState === 'no_email').length
		},
		async reloadPreview() {
			this.loading = true
			try { await this.loadSepaPreview(this.executionDate) } finally { this.loading = false }
		},
		async createBatch() {
			this.creating = true
			try {
				await api.createSepaBatch(this.executionDate)
				await Promise.all([this.loadSepaPreview(this.executionDate), this.loadSepaBatches()])
				showSuccess(this.t('SEPA-Einzug erzeugt.'))
			} catch (e) { showError(this.errMsg(e, this.t('Einzug konnte nicht erzeugt werden'))) } finally { this.creating = false }
		},
		async toggleItems(batchId) {
			if (this.expanded === batchId) { this.expanded = null; return }
			this.expanded = batchId
			await this.loadSepaBatchItems(batchId)
		},
		/**
		 * Abschluss des Einzugs: das Geld ist da. Der Dialog nennt ausdrücklich
		 * die Zahl der Posten, die dabei geschlossen werden - das ist der
		 * eigentliche Effekt und der Grund, warum es diesen Knopf gibt.
		 */
		async settle(batch) {
			const offen = (this.sepaBatchItems[batch.id] || []).filter(i => i.status !== 'returned').length
			if (!await this.askConfirm(
				this.t('Einzug als ausgeführt verbuchen'),
				offen
					? this.t('Ist das Geld für den Einzug vom {date} eingegangen? Die {n} zugehörigen offenen Posten werden dann als bezahlt geschlossen. Zurückgebuchte Zeilen bleiben davon unberührt.', { date: batch.executionDate, n: offen })
					: this.t('Ist das Geld für den Einzug vom {date} eingegangen? Die zugehörigen offenen Posten werden dann als bezahlt geschlossen. Zurückgebuchte Zeilen bleiben davon unberührt.', { date: batch.executionDate }),
			)) return
			this.settling = batch.id
			try {
				const { data } = await api.settleSepaBatch(batch.id)
				await Promise.all([this.loadSepaBatches(), this.loadSepaPreview(this.executionDate)])
				if (this.expanded === batch.id) await this.loadSepaBatchItems(batch.id)
				showSuccess(this.n('%n offener Posten geschlossen.', '%n offene Posten geschlossen.', data.settled))
			} catch (e) { showError(this.errMsg(e, this.t('Verbuchen fehlgeschlagen'))) } finally { this.settling = null }
		},
		async discard(batch) {
			if (!await this.askConfirm(
				this.t('Einzug verwerfen'),
				this.t('Einzug mit Fälligkeit {date} verwerfen? Die enthaltenen offenen Posten stehen danach wieder für einen Einzug bereit. Wurde die Datei bereits bei der Bank eingereicht, ändert das Verwerfen daran nichts.', { date: batch.executionDate }),
			)) return
			try {
				await api.deleteSepaBatch(batch.id)
				this.expanded = null
				await Promise.all([this.loadSepaPreview(this.executionDate), this.loadSepaBatches()])
				showSuccess(this.t('Einzug verworfen.'))
			} catch (e) { showError(this.errMsg(e, this.t('Einzug konnte nicht verworfen werden'))) }
		},
		async revert(batchId, item) {
			if (!await this.askConfirm(
				this.t('Rückbuchung zurücknehmen'),
				this.t('Diese Zeile war offenbar keine Rücklastschrift? Sie gilt danach wieder als offen. Den Status des zugehörigen offenen Postens prüfen Sie bitte selbst.'),
			)) return
			try {
				await api.revertSepaReturn(item.id)
				await this.loadSepaBatchItems(batchId)
				showSuccess(this.t('Rückbuchung zurückgenommen.'))
			} catch (e) { showError(this.errMsg(e, this.t('Zurücknehmen fehlgeschlagen'))) }
		},
	},
}
</script>
