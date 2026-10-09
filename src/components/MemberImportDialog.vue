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
				{{ t('Für die erstmalige Aufnahme vieler Mitglieder aus einer CSV-Datei. Bereits vorhandene Mitglieder bleiben unberührt und werden übersprungen; vor dem Anlegen sehen Sie zuerst, was entstehen würde.') }}
			</p>
			<details class="vbh-importhelp">
				<summary>{{ t('Welche Spalten gibt es?') }}</summary>
				<ul>
					<li><strong>{{ t('Name') }}:</strong> {{ t('Vorname und Nachname, Organisation oder nur „Name“ (wird am ersten Leerzeichen geteilt; mit einer Rechtsform wie GmbH oder e. V. gilt es als Organisation)') }}</li>
					<li><strong>{{ t('Stammdaten') }}:</strong> {{ t('Mitgliedsnummer, Eintritt (leer = heute), Straße, PLZ, Ort, Telefon, E-Mail') }}</li>
					<li><strong>{{ t('Lastschrift') }}:</strong> {{ t('IBAN, BIC, Kontoinhaber, Mandat am, Mandatsreferenz') }}</li>
					<li><strong>{{ t('Beitrag') }}:</strong> {{ t('Beitragsgruppe, Betrag (Monatsbeitrag, unabhängig vom Turnus; 0 = beitragsfrei), Frequenz (wie oft eingezogen wird), Start (nicht in der Vergangenheit; bei beitragsfreien Zeilen leer = heute)') }}</li>
				</ul>
				<p>{{ t('Reihenfolge und Schreibweise der Überschriften sind egal, weitere Spalten werden übergangen.') }}</p>
			</details>
			<p v-if="defaultFeeAmount" class="vbh-hint vbh-hint--info">
				{{ t('Zeilen mit Start-Datum, aber ohne eigenen Betrag, bekommen automatisch Ihren Standardbeitrag ({amount}) – die Betrag-Spalte kann bei einheitlichen Sätzen also leer bleiben.', { amount: formatMoney(defaultFeeAmount) }) }}
			</p>
			<div class="vbh-uploadrow vbh-importpick">
				<NcButton variant="secondary" :disabled="importing" @click="$refs.csvInput.click()">
					<template #icon>
						<NcIconSvgWrapper :path="mdiUpload" :size="20" />
					</template>
					{{ t('Datei wählen') }}
				</NcButton>
				<input
					ref="csvInput"
					type="file"
					accept=".csv,text/csv"
					hidden
					@change="onFileChosen">
				<span class="vbh-filename">{{ csvFileName || t('keine Datei gewählt') }}</span>
				<NcButton :variant="importPreview ? 'secondary' : 'primary'" :disabled="!importCsv || importing" @click="previewImport">
					{{ t('Prüfen') }}
				</NcButton>
				<NcButton variant="tertiary" :href="beispielCsv" download="mitglieder-vorlage.csv">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDownload" :size="20" />
					</template>
					{{ t('Vorlage herunterladen') }}
				</NcButton>
			</div>

			<p v-if="importError" class="vbh-hint vbh-hint--warning">
				{{ importError }}
			</p>

			<template v-if="importPreview">
				<p v-if="imported" class="vbh-hint vbh-hint--info">
					{{ t('{ok} von {total} Zeilen übernommen: {mandate} und {beitraege} angelegt. {uebersprungen} bereits vorhandene oder doppelte Zeilen wurden übersprungen, {fehler} waren fehlerhaft.', {
						ok: importSummary.ok,
						total: importPreview.length,
						mandate: n('%n Mandat', '%n Mandate', importSummary.mandates),
						beitraege: n('%n Zuweisung', '%n Zuweisungen', importSummary.assignments),
						uebersprungen: importSummary.skipped,
						fehler: importSummary.failed,
					}) }}
				</p>
				<p v-else class="vbh-hint" :class="importSummary.failed ? 'vbh-hint--warning' : 'vbh-hint--info'">
					{{ t('{ok} von {total} Zeilen sind in Ordnung: {mandate} und {beitraege} würden angelegt. {uebersprungen} bereits vorhandene oder doppelte Zeilen werden übersprungen, {fehler} sind fehlerhaft.', {
						ok: importSummary.ok,
						total: importPreview.length,
						mandate: n('%n Mandat', '%n Mandate', importSummary.mandates),
						beitraege: n('%n Zuweisung', '%n Zuweisungen', importSummary.assignments),
						uebersprungen: importSummary.skipped,
						fehler: importSummary.failed,
					}) }}
					<span v-if="importSummary.warnings"> {{ n('%n Zeile mit Warnung – wird trotzdem angelegt.', '%n Zeilen mit Warnung – werden trotzdem angelegt.', importSummary.warnings) }}</span>
				</p>

				<div v-if="importSummary.mandates > 0 && !imported" class="vbh-form">
					<NcCheckboxRadioSwitch v-model="mandatesConfirmed">
						{{ n('Das unterschriebene Mandat liegt vor – es wird sofort aktiviert.', 'Die unterschriebenen Mandate für %n Zeilen liegen vor – sie werden sofort aktiviert.', importSummary.mandates) }}
					</NcCheckboxRadioSwitch>
				</div>

				<div class="vbh-tablecard">
					<table class="vbh-table">
						<thead>
							<tr>
								<th class="vbh-imp-line">
									{{ t('Zeile') }}
								</th>
								<th>{{ t('Name') }}</th>
								<th class="vbh-imp-iban">
									{{ t('IBAN') }}
								</th>
								<th class="vbh-imp-group">
									{{ t('Beitragsgruppe') }}
								</th>
								<th class="num vbh-imp-amount">
									{{ t('Monatsbeitrag') }}
								</th>
								<th class="vbh-importres">
									{{ t('Ergebnis') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in importPreview" :key="row.line">
								<td>{{ row.line }}</td>
								<td>
									{{ row.name || '–' }}
									<span v-if="row.memberType === 'organisation' && !row.errors.length && !row.skipped" class="vbh-typetag">{{ t('Organisation') }}</span>
								</td>
								<td class="nowrap">
									{{ row.iban || '–' }}
								</td>
								<td>{{ row.groupName || '–' }}</td>
								<td class="num nowrap">
									{{ row.amount === null ? '–' : formatMoney(row.amount) }}
								</td>
								<td class="vbh-importres">
									<template v-if="row.errors.length">
										<span class="vbh-importtag vbh-importtag--error">{{ t('Fehler') }}</span>
										<span class="vbh-importmsg">{{ row.errors.join(' ') }}</span>
									</template>
									<template v-else-if="row.skipped">
										<span class="vbh-importtag">{{ t('übersprungen') }}</span>
										<span class="vbh-importmsg">{{ row.skipReason }}</span>
									</template>
									<template v-else>
										<span class="vbh-typetag">{{ row.mandateId || row.assignmentId ? importResultLabel(row) : importLabel(row) }}</span>
										<template v-if="row.warnings.length">
											<span class="vbh-importtag vbh-importtag--warning">{{ t('Hinweis') }}</span>
											<span class="vbh-importmsg">{{ row.warnings.join(' ') }}</span>
										</template>
									</template>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div v-if="!imported" class="vbh-modal-actions">
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
import { mdiDownload, mdiUpload } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper, NcModal } from '@nextcloud/vue'
import api from '../api.js'
import { useConfirm } from '../composables/useConfirm.js'
import { errMsg, formatMoney } from '../lib/format.js'
import { memberImportTemplateUrl } from '../lib/memberImportTemplate.js'

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
	components: { NcModal, NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper },
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
			mdiDownload,
			mdiUpload,
			csvFileName: '',
			importCsv: '',
			importPreview: null,
			importError: '',
			importing: false,
			importSummary: emptySummary(),
			mandatesConfirmed: false,
			// Nach dem Übernehmen zeigt dieselbe Tabelle das Ergebnis; Zusammenfassung dann in der Vergangenheit.
			imported: false,
		}
	},

	computed: {
		/** Vorlage als Daten-URL: kein zusätzlicher Endpunkt nötig. */
		beispielCsv() {
			return memberImportTemplateUrl()
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
			this.csvFileName = datei.name
			const leser = new FileReader()
			leser.onload = () => { this.importCsv = String(leser.result || '') }
			leser.onerror = () => showError(this.t('Die Datei konnte nicht gelesen werden.'))
			// Vereinstabellen kommen oft als Windows-1252 aus Excel; UTF-8 ist
			// der Normalfall, der Rest faellt beim Pruefen als kaputte Umlaute auf.
			leser.readAsText(datei, 'utf-8')
		},

		resetImport() {
			this.csvFileName = ''
			this.importCsv = ''
			this.importPreview = null
			this.importError = ''
			this.importSummary = emptySummary()
			this.mandatesConfirmed = false
			this.imported = false
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
				this.t('Jetzt angelegt werden {zeilen} ({mandate} und {beitraege}). Bereits vorhandene oder doppelte Zeilen ({uebersprungen}) werden übersprungen, fehlerhafte ({fehler}) bleiben unberührt.', {
					zeilen: this.n('%n Zeile', '%n Zeilen', this.importSummary.ok),
					mandate: this.n('%n Mandat', '%n Mandate', this.importSummary.mandates),
					beitraege: this.n('%n Zuweisung', '%n Zuweisungen', this.importSummary.assignments),
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
				this.imported = true
				this.importCsv = ''
				this.csvFileName = ''
				if (this.$refs.csvInput) { this.$refs.csvInput.value = '' }
				this.$emit('imported')
				showSuccess(this.n('%n Zeile übernommen.', '%n Zeilen übernommen.', data.summary.ok))
			} catch (e) { showError(this.errMsg(e, this.t('Import fehlgeschlagen'))) } finally { this.importing = false }
		},
	},
}
</script>

<style scoped>
.vbh-importhelp {
	margin: 0 0 10px;
	font-size: 0.9em;
}

.vbh-importhelp summary {
	cursor: pointer;
	font-weight: 600;
}

.vbh-importhelp ul {
	margin: 6px 0 0;
	padding-inline-start: 20px;
}

.vbh-importhelp p {
	margin: 6px 0 0;
	color: var(--color-text-maxcontrast);
}

/* Die Ergebnisspalte trägt die Meldungen je Zeile und braucht deshalb den meisten Platz. */
/* table-layout: fixed – die Breiten stehen in der Kopfzeile; der Name teilt sich den Rest. */
.vbh-imp-line {
	width: 66px;
}

.vbh-imp-iban {
	width: 135px;
}

.vbh-imp-group {
	width: 140px;
}

.vbh-imp-amount {
	width: 128px;
}

.vbh-importres {
	width: 265px;
}

.vbh-importmsg {
	display: block;
	margin-top: 2px;
	font-size: 0.9em;
	line-height: 1.35;
}

/* Statusfarben als Paar: Flächenton plus darauf lesbare Schrift, nie die Fläche als Schriftfarbe. */
.vbh-importtag {
	display: inline-block;
	padding: 1px 8px;
	border-radius: 10px;
	font-size: 0.82em;
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
}

.vbh-importtag--error {
	background-color: var(--color-error);
	color: var(--color-error-text);
}

.vbh-importtag--warning {
	background-color: var(--color-warning);
	color: var(--color-warning-text);
}
</style>
