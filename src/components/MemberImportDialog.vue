<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-memberimport"
		size="large"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-memberimport" class="vbh-modal-title">
				{{ t('Mitgliederliste einlesen') }}
			</h2>
			<p class="vbh-hint">
				{{ t('Für die erstmalige Aufnahme vieler Mitglieder: eine CSV-Datei mit den Spalten Name, E-Mail, IBAN, BIC, Kontoinhaber, Mandat am, Mandatsreferenz, Mitgliedsnummer, Beitragsgruppe, Betrag, Frequenz und Start. Die Reihenfolge und die Schreibweise der Überschriften sind egal, zusätzliche Spalten werden übergangen. Jede Zeile legt nur an – ein bereits bestehendes Mitglied (Mitgliedsnummer/Nextcloud-Konto) wird nie geändert, sondern übersprungen. Vor dem Anlegen sehen Sie zuerst, was entstehen würde.') }}
			</p>
			<p class="vbh-hint vbh-hint--info">
				{{ t('„Betrag" ist der Monatsbeitrag der Zuweisung, unabhängig vom Turnus – „Frequenz" bestimmt nur, wie oft eingezogen wird.') }}
			</p>
			<p v-if="defaultFeeAmount" class="vbh-hint vbh-hint--info">
				{{ t('Zeilen mit Start-Datum, aber ohne eigenen Betrag, bekommen automatisch Ihren Standardbeitrag ({amount}) – die Betrag-Spalte kann bei einheitlichen Sätzen also leer bleiben.', { amount: formatMoney(defaultFeeAmount) }) }}
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
				<a :href="beispielCsv" download="mitglieder-vorlage.csv" class="vbh-export-btn">{{ t('Vorlage herunterladen') }}</a>
			</div>

			<p v-if="importError" class="vbh-hint vbh-hint--warning">
				{{ importError }}
			</p>

			<template v-if="importPreview">
				<p class="vbh-hint" :class="importSummary.failed ? 'vbh-hint--warning' : 'vbh-hint--info'">
					{{ t('{ok} von {total} Zeilen sind in Ordnung: {mandate} Mandate und {beitraege} Zuweisungen würden angelegt. {uebersprungen} bereits bestehende Zeilen werden übersprungen, {fehler} sind fehlerhaft.', {
						ok: importSummary.ok,
						total: importPreview.length,
						mandate: importSummary.mandates,
						beitraege: importSummary.assignments,
						uebersprungen: importSummary.skipped,
						fehler: importSummary.failed,
					}) }}
					<span v-if="importSummary.warnings"> {{ n('%n Zeile mit Warnung (Namensgleichheit/Beitragsgruppe) – wird trotzdem angelegt.', '%n Zeilen mit Warnung (Namensgleichheit/Beitragsgruppe) – werden trotzdem angelegt.', importSummary.warnings) }}</span>
				</p>

				<div v-if="importSummary.mandates > 0" class="vbh-form">
					<label class="vbh-grow">
						<input v-model="mandatesConfirmed" type="checkbox">
						{{ n('Das unterschriebene Mandat liegt vor – es wird sofort aktiviert.', 'Die unterschriebenen Mandate für %n Zeilen liegen vor – sie werden sofort aktiviert.', importSummary.mandates) }}
					</label>
				</div>

				<div class="vbh-tablecard">
					<table class="vbh-table">
						<thead>
							<tr>
								<th>{{ t('Zeile') }}</th>
								<th>{{ t('Zahler') }}</th>
								<th>{{ t('IBAN') }}</th>
								<th>{{ t('Beitragsgruppe') }}</th>
								<th class="num">
									{{ t('Monatsbeitrag') }}
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
								<td>{{ row.groupName || '–' }}</td>
								<td class="num nowrap">
									{{ row.amount === null ? '–' : formatMoney(row.amount) }}
								</td>
								<td>
									<span v-if="row.errors.length" class="vbh-hint vbh-hint--warning">{{ row.errors.join(' ') }}</span>
									<span v-else-if="row.skipped" class="vbh-typetag">{{ row.skipReason }}</span>
									<template v-else>
										<span class="vbh-typetag">{{ row.mandateId || row.assignmentId ? importResultLabel(row) : importLabel(row) }}</span>
										<span v-if="row.warnings.length" class="vbh-hint vbh-hint--warning">{{ row.warnings.join(' ') }}</span>
									</template>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="vbh-modal-actions">
					<NcButton
						variant="primary"
						:disabled="!canRunImport || importing"
						@click="runImport">
						{{ n('%n Zeile übernehmen', '%n Zeilen übernehmen', importSummary.ok) }}
					</NcButton>
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

function emptySummary() {
	return { ok: 0, skipped: 0, failed: 0, mandates: 0, assignments: 0, warnings: 0 }
}

/**
 * CSV-Massenimport von Mitgliedern (Spec §3.1, Issue #69) – aus
 * MembersList.vue (frueher SettingsMembers.vue) herausgeloest, siehe
 * NAVIGATION-KONZEPT.md Abschnitt 4. Zweistufig: erst previewMemberImport()
 * (reine Pruefung, legt nichts an), dann runMemberImport() nach Bestaetigung.
 *
 * Die Checkbox „die unterschriebenen Mandate liegen vor" *ist* die vom
 * Mandats-Aktivierungs-Gate verlangte Admin-Handlung (Spec §2.2/§3.1) – ohne
 * sie lehnt MemberImportService::import() serverseitig jede Datei mit
 * mindestens einer Mandatszeile komplett ab, nicht nur dieser Client hier.
 */
export default {
	name: 'MemberImportDialog',
	components: { NcModal, NcButton },
	props: {
		show: { type: Boolean, default: false },
		defaultFeeAmount: { type: [Number, String], default: '' },
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
			importSummary: emptySummary(),
			mandatesConfirmed: false,
		}
	},

	computed: {
		/** Vorlage als Daten-URL: kein zusätzlicher Endpunkt nötig. */
		beispielCsv() {
			const zeilen = [
				'Name;E-Mail;IBAN;BIC;Mandat am;Mandatsreferenz;Mitgliedsnummer;Beitragsgruppe;Betrag;Frequenz;Start',
				'Katrin Brunner;k.brunner@example.org;DE02120300000000202051;;15.01.2026;ALT-0001;0815;Chormitglieder;8,00;monatlich;01.02.2026',
				'Hans Mertens;h.mertens@example.org;DE02120300000000202051;;15.01.2026;;0816;Chormitglieder;10,00;jährlich;01.01.2026',
			].join('\r\n')
			return 'data:text/csv;charset=utf-8,' + encodeURIComponent('﻿' + zeilen)
		},

		canRunImport() {
			if (!this.importPreview || this.importSummary.ok === 0) { return false }
			return this.importSummary.mandates === 0 || this.mandatesConfirmed
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
		importLabel(row) {
			if (row.willCreateMandate && row.willCreateAssignment) { return this.t('Mandat und Zuweisung') }
			if (row.willCreateMandate) { return this.t('nur Mandat') }
			if (row.willCreateAssignment) { return this.t('nur Zuweisung') }
			return this.t('nur Stammdaten')
		},

		importResultLabel(row) {
			if (row.mandateId && row.assignmentId) { return this.t('Mandat und Zuweisung angelegt') }
			if (row.mandateId) { return this.t('Mandat angelegt') }
			return this.t('Zuweisung angelegt')
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
			this.importSummary = emptySummary()
			this.mandatesConfirmed = false
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
				this.t('{ok} Zeilen werden jetzt angelegt ({mandate} Mandate, {beitraege} Zuweisungen). {uebersprungen} bereits bestehende Zeilen werden übersprungen, {fehler} fehlerhafte Zeilen bleiben unberührt.', {
					ok: this.importSummary.ok,
					mandate: this.importSummary.mandates,
					beitraege: this.importSummary.assignments,
					uebersprungen: this.importSummary.skipped,
					fehler: this.importSummary.failed,
				}),
				this.t('Übernehmen'),
				'primary',
			)) { return }
			this.importing = true
			try {
				const { data } = await api.runMemberImport(this.importCsv, this.mandatesConfirmed)
				if (data.error) {
					showError(data.error)
					return
				}
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
