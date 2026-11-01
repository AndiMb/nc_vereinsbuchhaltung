<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-invoicebatch"
		size="large"
		:closeOnClickOutside="false"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-invoicebatch" class="vbh-modal-title">
				{{ t('Rechnungen aus Tabelle einlesen') }}
			</h2>
			<p class="vbh-hint">
				{{ t('Für viele Rechnungen auf einmal, etwa an die Teilnehmenden eines Projekts. Jede Zeile der Tabelle ist eine Position; Zeilen desselben Empfängers werden zu einer Rechnung. Es entstehen Entwürfe, die Sie vor dem Festschreiben durchsehen können.') }}
			</p>
			<details class="vbh-importhelp">
				<summary>{{ t('Welche Spalten gibt es?') }}</summary>
				<ul>
					<li><strong>{{ t('Empfänger') }}:</strong> {{ t('Name (Pflicht in der ersten Zeile eines Empfängers), Name 2, Art (Person, Organisation, Behörde), Straße, PLZ, Ort, Land, E-Mail, Kundennummer') }}</li>
					<li><strong>{{ t('Position') }}:</strong> {{ t('Position (Bezeichnung), Beschreibung, Menge (leer = 1), Einheit (Stück, Stunde, Tag, pauschal …), Einzelpreis, Rabatt (%), Steuersatz (%), Erlöskonto (Kontonummer), Kostenstelle, Leistungsdatum') }}</li>
					<li><strong>{{ t('Vorlage') }}:</strong> {{ t('Name einer Positionsvorlage; füllt Bezeichnung, Preis, Steuer und Erlöskonto vor. Werte in der Zeile gehen vor.') }}</li>
					<li><strong>{{ t('Rechnung') }}:</strong> {{ t('optionales Kennzeichen: Zeilen mit gleichem Kennzeichen werden eine Rechnung. Ohne diese Spalte gehören Zeilen mit gleichem Namen, gleicher PLZ und gleichem Ort zusammen, und eine Zeile ohne Namen gehört zur Rechnung darüber.') }}</li>
				</ul>
				<p>{{ t('Gelesen wird das erste Tabellenblatt einer Excel-Datei (.xlsx) oder eine CSV-Datei. Reihenfolge und Schreibweise der Überschriften sind egal, weitere Spalten werden übergangen. Vorhandene Empfänger werden erkannt (Kundennummer, Name mit E-Mail oder PLZ) und nicht doppelt angelegt.') }}</p>
			</details>

			<div class="vbh-form">
				<label>{{ t('Name des Projekts') }}
					<input v-model="name" maxlength="255" :placeholder="t('z. B. Probenwochenende 2026')">
				</label>
			</div>

			<div class="vbh-uploadrow vbh-importpick">
				<NcButton variant="secondary" :disabled="busy" @click="$refs.fileInput.click()">
					<template #icon>
						<NcIconSvgWrapper :path="mdiUpload" :size="20" />
					</template>
					{{ t('Datei wählen') }}
				</NcButton>
				<input
					ref="fileInput"
					type="file"
					accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
					hidden
					@change="onFileChosen">
				<span class="vbh-filename">{{ file ? file.name : t('keine Datei gewählt') }}</span>
				<NcButton :variant="preview ? 'secondary' : 'primary'" :disabled="!file || busy" @click="runPreview">
					{{ t('Prüfen') }}
				</NcButton>
				<NcButton
					class="vbh-importtemplate"
					variant="tertiary"
					:href="templateUrl"
					download="rechnungen-vorlage.csv">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDownload" :size="20" />
					</template>
					{{ t('Vorlage herunterladen') }}
				</NcButton>
			</div>

			<fieldset class="vbh-batch-defaults">
				<legend>{{ t('Vorgaben für alle Rechnungen') }}</legend>
				<div class="vbh-form">
					<label>{{ t('Leistungsdatum') }}
						<input v-model="options.deliveryDate" type="date" :disabled="!!(options.periodStart || options.periodEnd)">
					</label>
					<label>{{ t('oder Zeitraum von') }}
						<input v-model="options.periodStart" type="date" :disabled="!!options.deliveryDate">
					</label>
					<label>{{ t('bis') }}
						<input v-model="options.periodEnd" type="date" :disabled="!!options.deliveryDate">
					</label>
				</div>
				<p class="vbh-hint">
					{{ t('Leer lassen, wenn die Tabelle eine Spalte „Leistungsdatum“ hat.') }}
				</p>
				<div class="vbh-form">
					<label>{{ t('Erlöskonto für Positionen ohne eigenes') }}
						<select v-model="options.accountId">
							<option :value="null">{{ t('– keins –') }}</option>
							<option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.number }} {{ a.name }}</option>
						</select>
					</label>
				</div>
				<div class="vbh-form vbh-batch-texts">
					<label>{{ t('Kopftext') }}
						<textarea v-model="options.noteHead" rows="2" maxlength="2000" />
					</label>
					<label>{{ t('Fußtext') }}
						<textarea v-model="options.noteFoot" rows="2" maxlength="2000" />
					</label>
				</div>
			</fieldset>

			<p v-if="error" class="vbh-hint vbh-hint--warning" role="alert">
				{{ error }}
			</p>

			<template v-if="preview">
				<p class="vbh-hint" :class="preview.summary.failed || preview.errors.length ? 'vbh-hint--warning' : 'vbh-hint--info'">
					{{ summaryText }}
				</p>
				<ul v-if="preview.errors.length" class="vbh-batch-errors">
					<li v-for="(msg, i) in preview.errors" :key="i">
						{{ msg }}
					</li>
				</ul>

				<div class="vbh-tablecard">
					<table class="vbh-table vbh-stack">
						<thead>
							<tr>
								<th class="vbh-batch-lines">
									{{ t('Zeilen') }}
								</th>
								<th>{{ t('Empfänger') }}</th>
								<th class="vbh-batch-mail">
									{{ t('E-Mail') }}
								</th>
								<th class="num vbh-batch-count">
									{{ t('Positionen') }}
								</th>
								<th class="num vbh-batch-amount">
									{{ t('Netto') }}
								</th>
								<th class="vbh-importres">
									{{ t('Ergebnis') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="group in preview.groups" :key="group.key">
								<td>{{ lineLabel(group.lines) }}</td>
								<td>
									{{ group.recipient.name || '–' }}
									<span class="vbh-typetag">{{ group.debtorStatus === 'existing' ? t('vorhanden') : t('neu') }}</span>
								</td>
								<td class="vbh-batch-mailcell">
									{{ group.email || '–' }}
								</td>
								<td class="num">
									{{ group.items.length }}
								</td>
								<td class="num nowrap">
									{{ formatMoney(group.totalCents / 100) }}
								</td>
								<td class="vbh-importres">
									<template v-if="group.errors.length">
										<span class="vbh-importtag vbh-importtag--error">{{ t('Fehler') }}</span>
										<span class="vbh-importmsg">{{ group.errors.join(' ') }}</span>
									</template>
									<template v-else>
										<span class="vbh-typetag">{{ t('Entwurf') }}</span>
										<template v-if="group.warnings.length">
											<span class="vbh-importtag vbh-importtag--warning">{{ t('Hinweis') }}</span>
											<span class="vbh-importmsg">{{ group.warnings.join(' ') }}</span>
										</template>
									</template>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="vbh-modal-actions">
					<NcButton variant="primary" :disabled="busy || !preview.summary.invoices || !name.trim()" @click="runImport">
						{{ n('%n Rechnungsentwurf anlegen', '%n Rechnungsentwürfe anlegen', preview.summary.invoices) }}
					</NcButton>
				</div>
				<p v-if="!name.trim()" class="vbh-hint">
					{{ t('Bitte noch einen Namen für das Projekt angeben.') }}
				</p>
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
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcModal from '@nextcloud/vue/components/NcModal'
import api from '../api.js'
import { useConfirm } from '../composables/useConfirm.js'
import { errMsg, formatMoney } from '../lib/format.js'
import { invoiceBatchTemplateUrl } from '../lib/invoiceBatchTemplate.js'

function emptyOptions(meta) {
	const defaults = (meta && meta.defaults) || {}
	return {
		deliveryDate: '',
		periodStart: '',
		periodEnd: '',
		accountId: null,
		noteHead: defaults.noteHead || '',
		noteFoot: defaults.noteFoot || '',
	}
}

/**
 * Sammelrechnungen aus einer Tabelle (#243): Datei wählen, prüfen, Entwürfe in
 * einem neuen Stapel anlegen. Zweistufig wie der Mitglieder-Import; beide
 * Schritte schicken die Datei, der Server merkt sich zwischen ihnen nichts.
 * Ändert sich eine Vorgabe nach dem Prüfen, verfällt die Vorschau, weil sie
 * Hinweise (Erlöskonto, Leistungsdatum) aus den Vorgaben ableitet.
 */
export default {
	name: 'InvoiceBatchImportDialog',
	components: { NcModal, NcButton, NcIconSvgWrapper },
	props: {
		show: { type: Boolean, default: false },
		/** Aktive Einnahmenkonten für die Vorgabe. */
		accounts: { type: Array, default: () => [] },
		/** Rechnungs-Metadaten (Standardtexte). */
		meta: { type: Object, default: null },
	},

	emits: ['close', 'imported', 'update:show'],

	setup() {
		return { askConfirm: useConfirm().askConfirm, mdiDownload, mdiUpload }
	},

	data() {
		return {
			name: '',
			file: null,
			options: emptyOptions(this.meta),
			preview: null,
			error: '',
			busy: false,
		}
	},

	computed: {
		templateUrl() { return invoiceBatchTemplateUrl() },

		/** Zusammenfassung der Vorschau als ein Absatz aus ganzen Sätzen. */
		summaryText() {
			const s = this.preview.summary
			return [
				this.n('%n Rechnung über {amount} (netto) würde angelegt.', '%n Rechnungen über zusammen {amount} (netto) würden angelegt.', s.invoices, { amount: formatMoney(s.totalCents / 100) }),
				this.t('Empfänger: {neu} neu, {alt} schon vorhanden.', { neu: s.newDebtors, alt: s.existingDebtors }),
				s.failed ? this.n('%n Rechnung ist fehlerhaft und wird übersprungen.', '%n Rechnungen sind fehlerhaft und werden übersprungen.', s.failed) : '',
				s.withoutEmail ? this.n('%n Rechnung hat keine E-Mail-Adresse und muss gedruckt werden.', '%n Rechnungen haben keine E-Mail-Adresse und müssen gedruckt werden.', s.withoutEmail) : '',
			].filter(Boolean).join(' ')
		},
	},

	watch: {
		show(open) {
			if (open) { this.reset() }
		},

		options: {
			deep: true,
			handler() { this.preview = null },
		},
	},

	methods: {
		formatMoney,

		reset() {
			this.name = ''
			this.file = null
			this.options = emptyOptions(this.meta)
			this.preview = null
			this.error = ''
			if (this.$refs.fileInput) { this.$refs.fileInput.value = '' }
		},

		onFileChosen(event) {
			const file = event.target.files && event.target.files[0]
			this.preview = null
			this.error = ''
			this.file = file || null
			if (file && !this.name.trim()) {
				this.name = file.name.replace(/\.[^.]+$/, '')
			}
		},

		/** „2–4“ oder „2, 5“ für die Zeilen einer Rechnung. */
		lineLabel(lines) {
			if (lines.length === 1) { return String(lines[0]) }
			const contiguous = lines.every((l, i) => i === 0 || l === lines[i - 1] + 1)
			return contiguous ? `${lines[0]}–${lines[lines.length - 1]}` : lines.join(', ')
		},

		formData(withName) {
			const data = new FormData()
			data.append('file', this.file)
			if (withName) { data.append('name', this.name.trim()) }
			for (const [key, value] of Object.entries(this.options)) {
				if (value !== null && value !== undefined) { data.append(key, value) }
			}
			return data
		},

		async runPreview() {
			this.busy = true
			this.error = ''
			try {
				const { data } = await api.previewInvoiceBatch(this.formData(false))
				this.preview = data
			} catch (e) {
				this.preview = null
				this.error = errMsg(e, this.t('Prüfen fehlgeschlagen'))
			} finally {
				this.busy = false
			}
		},

		async runImport() {
			if (!await this.askConfirm(
				this.t('Rechnungsentwürfe anlegen'),
				this.tRaw('Im Projekt „{name}“ entstehen {rechnungen}. Fehlerhafte Rechnungen ({fehler}) werden übersprungen. Festgeschrieben und versendet wird noch nichts.', { name: this.name.trim() }, {
					rechnungen: this.n('%n Rechnungsentwurf', '%n Rechnungsentwürfe', this.preview.summary.invoices),
					fehler: this.preview.summary.failed,
				}),
				this.t('Anlegen'),
				'primary',
			)) { return }
			this.busy = true
			try {
				const { data } = await api.createInvoiceBatch(this.formData(true))
				showSuccess(this.n('%n Rechnungsentwurf angelegt.', '%n Rechnungsentwürfe angelegt.', data.batch.invoiceCount))
				this.$emit('imported', data.batch)
				this.$emit('update:show', false)
			} catch (e) {
				showError(errMsg(e, this.t('Anlegen fehlgeschlagen')))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-importtemplate {
	margin-inline-start: auto;
}

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

.vbh-batch-defaults {
	margin: 12px 0;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.vbh-batch-defaults legend {
	padding: 0 4px;
	font-weight: 600;
}

.vbh-batch-texts textarea {
	width: 100%;
	min-width: 240px;
}

.vbh-batch-errors {
	margin: 0 0 10px;
	padding-inline-start: 20px;
	color: var(--color-error-text);
}

.vbh-batch-lines {
	width: 72px;
}

.vbh-batch-mail {
	width: 190px;
}

.vbh-batch-mailcell {
	overflow: hidden;
	text-overflow: ellipsis;
}

.vbh-batch-count {
	width: 96px;
}

.vbh-batch-amount {
	width: 110px;
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
