<template>
	<NcModal
		:show="show"
		label-id="vbh-modal-title-memberimport"
		size="large"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-memberimport" class="vbh-modal-title">
				{{ t('Mitgliederliste einlesen') }}
			</h2>
			<p class="vbh-hint">
				{{ t('Für die erstmalige Aufnahme vieler Mitglieder: eine CSV-Datei mit den Spalten Name, E-Mail, IBAN, Mandat am, Betrag, Frequenz und Start. Die Reihenfolge und die Schreibweise der Überschriften sind egal, zusätzliche Spalten werden übergangen. Vor dem Anlegen sehen Sie zuerst, was entstehen würde.') }}
			</p>
			<p v-if="defaultFeeAmount" class="vbh-hint vbh-hint--info">
				{{ t('Zeilen mit Start-Datum, aber ohne eigenen Betrag, bekommen automatisch Ihren Standardbeitrag ({amount} {frequency}) – die Betrag-Spalte kann bei einheitlichen Sätzen also leer bleiben.', { amount: formatMoney(defaultFeeAmount), frequency: frequencyLabel(defaultFeeFrequency) }) }}
			</p>
			<div class="vbh-form">
				<input
					ref="csvInput"
					type="file"
					accept=".csv,text/csv"
					@change="onFileChosen">
				<NcButton :disabled="!importCsv || importing" @click="previewImport">
					{{ t('Prüfen') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="!importPreview || importSummary.ok === 0 || importing"
					@click="runImport">
					{{ n('%n Zeile übernehmen', '%n Zeilen übernehmen', importSummary.ok) }}
				</NcButton>
				<a :href="beispielCsv" download="mitglieder-vorlage.csv" class="vbh-export-btn">{{ t('Vorlage herunterladen') }}</a>
			</div>

			<p v-if="importError" class="vbh-hint vbh-hint--warning">
				{{ importError }}
			</p>

			<template v-if="importPreview">
				<p class="vbh-hint" :class="importSummary.failed ? 'vbh-hint--warning' : 'vbh-hint--info'">
					{{ t('{ok} von {total} Zeilen sind in Ordnung: {mandate} Mandate und {beitraege} Beiträge würden angelegt. {fehler} Zeilen werden übersprungen.', {
						ok: importSummary.ok,
						total: importPreview.length,
						mandate: importSummary.mandates,
						beitraege: importSummary.fees,
						fehler: importSummary.failed,
					}) }}
				</p>
				<div class="vbh-tablecard">
					<table class="vbh-table">
						<thead>
							<tr>
								<th>{{ t('Zeile') }}</th>
								<th>{{ t('Zahler') }}</th>
								<th>{{ t('IBAN') }}</th>
								<th class="num">
									{{ t('Betrag') }}
								</th>
								<th>{{ t('Ergebnis') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in importPreview" :key="row.line">
								<td>{{ row.line }}</td>
								<td>{{ row.name || '–' }}</td>
								<td class="nowrap">
									{{ row.iban || '–' }}
								</td>
								<td class="num nowrap">
									{{ row.amount === null ? '–' : formatMoney(row.amount) }}
								</td>
								<td>
									<span v-if="row.errors.length" class="vbh-hint vbh-hint--warning">{{ row.errors.join(' ') }}</span>
									<span v-else-if="row.mandateId || row.feeId" class="vbh-typetag">{{ t('angelegt') }}</span>
									<span v-else class="vbh-typetag">{{ importLabel(row) }}</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</template>

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('update:show', false)">
					{{ t('Schließen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcModal } from '@nextcloud/vue'
import api from '../api.js'
import { useConfirm } from '../composables/useConfirm.js'
import { errMsg, formatMoney } from '../lib/format.js'
import { frequencyLabel } from '../lib/frequency.js'

/**
 * CSV-Massenimport von Mitgliedern – aus MembersList.vue (frueher
 * SettingsMembers.vue) herausgeloest, siehe NAVIGATION-KONZEPT.md Abschnitt 4.
 * Zweistufig: erst previewMemberImport() (reine Pruefung, legt nichts an),
 * dann runMemberImport() nach Bestaetigung.
 */
export default {
	name: 'MemberImportDialog',
	components: { NcModal, NcButton },
	props: {
		show: { type: Boolean, default: false },
		defaultFeeAmount: { type: [Number, String], default: '' },
		defaultFeeFrequency: { type: String, default: 'yearly' },
	},

	emits: ['close', 'imported', 'update:show'],

	setup() {
		return { askConfirm: useConfirm().askConfirm }
	},

	data() {
		return {
			importCsv: '',
			importPreview: null,
			importError: '',
			importing: false,
			importSummary: { ok: 0, failed: 0, mandates: 0, fees: 0 },
		}
	},

	computed: {
		/** Vorlage als Daten-URL: kein zusätzlicher Endpunkt nötig. */
		beispielCsv() {
			const zeilen = [
				'Name;E-Mail;IBAN;BIC;Mandat am;Betrag;Frequenz;Start',
				'Katrin Brunner;k.brunner@example.org;DE02120300000000202051;;15.01.2026;42,50;monatlich;01.02.2026',
				'Hans Mertens;h.mertens@example.org;DE02120300000000202051;;15.01.2026;120,00;jährlich;01.01.2026',
			].join('\r\n')
			return 'data:text/csv;charset=utf-8,' + encodeURIComponent('﻿' + zeilen)
		},
	},

	watch: {
		show(open) {
			if (open) { this.resetImport() }
		},
	},

	methods: {
		errMsg,
		formatMoney,
		frequencyLabel,
		importLabel(row) {
			if (row.willCreateMandate && row.willCreateFee) { return this.t('Mandat und Beitrag') }
			if (row.willCreateMandate) { return this.t('nur Mandat') }
			return this.t('nur Beitrag')
		},

		onFileChosen(event) {
			const datei = event.target.files && event.target.files[0]
			this.resetImport()
			if (!datei) { return }
			const leser = new FileReader()
			leser.onload = () => { this.importCsv = String(leser.result || '') }
			leser.onerror = () => showError(this.t('Die Datei konnte nicht gelesen werden.'))
			// Vereinstabellen kommen oft als Windows-1252 aus Excel; UTF-8 ist
			// der Normalfall, der Rest faellt beim Pruefen als kaputte Umlaute auf.
			leser.readAsText(datei, 'utf-8')
		},

		resetImport() {
			this.importCsv = ''
			this.importPreview = null
			this.importError = ''
			this.importSummary = { ok: 0, failed: 0, mandates: 0, fees: 0 }
			if (this.$refs.csvInput) { this.$refs.csvInput.value = '' }
		},

		async previewImport() {
			this.importing = true
			try {
				const { data } = await api.previewMemberImport(this.importCsv)
				this.importError = data.error || ''
				this.importPreview = data.error ? null : data.rows
				this.importSummary = data.summary
			} catch (e) { showError(this.errMsg(e, this.t('Prüfen fehlgeschlagen'))) } finally { this.importing = false }
		},

		async runImport() {
			if (!await this.askConfirm(
				this.t('Mitglieder übernehmen'),
				this.t('{ok} Zeilen werden jetzt angelegt ({mandate} Mandate, {beitraege} Beiträge). {fehler} fehlerhafte Zeilen werden übersprungen.', {
					ok: this.importSummary.ok,
					mandate: this.importSummary.mandates,
					beitraege: this.importSummary.fees,
					fehler: this.importSummary.failed,
				}),
				this.t('Übernehmen'),
				'primary',
			)) { return }
			this.importing = true
			try {
				const { data } = await api.runMemberImport(this.importCsv)
				this.importPreview = data.rows
				this.importSummary = data.summary
				this.importCsv = ''
				if (this.$refs.csvInput) { this.$refs.csvInput.value = '' }
				this.$emit('imported')
				showSuccess(this.n('%n Zeile übernommen.', '%n Zeilen übernommen.', data.summary.ok))
			} catch (e) { showError(this.errMsg(e, this.t('Import fehlgeschlagen'))) } finally { this.importing = false }
		},
	},
}
</script>
