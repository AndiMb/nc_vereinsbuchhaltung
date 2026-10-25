<template>
	<div class="vbh-inv-editor">
		<div class="vbh-sectiontop">
			<div class="vbh-subtabs">
				<NcButton variant="tertiary" @click="close">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArrowLeft" :size="20" />
					</template>
					{{ t('Zur Liste') }}
				</NcButton>
				<strong class="vbh-inv-editor-title">{{ currentId ? t('Rechnungsentwurf') : t('Neue Rechnung') }}</strong>
			</div>
			<div class="vbh-sectiontop-actions">
				<span class="vbh-inv-saved" role="status">{{ saveStateText }}</span>
				<NcButton variant="tertiary" :disabled="saving" @click="preview">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFilePdfBox" :size="20" />
					</template>
					{{ t('Vorschau') }}
				</NcButton>
				<NcButton variant="secondary" :disabled="saving" @click="saveNow">
					{{ t('Entwurf speichern') }}
				</NcButton>
				<NcButton
					v-if="isAdmin"
					variant="primary"
					:disabled="saving || loading"
					@click="openIssue">
					{{ t('Festschreiben …') }}
				</NcButton>
			</div>
		</div>

		<div v-if="loading" class="vbh-sectionbody">
			<NcLoadingIcon :size="32" :name="t('Wird geladen…')" />
		</div>

		<div v-else class="vbh-sectionbody vbh-inv-body">
			<p v-if="!isAdmin" class="vbh-hint vbh-hint--info">
				{{ t('Festschreiben und Versenden darf nur ein Verwalter. Sie können den Entwurf anlegen und vorbereiten.') }}
			</p>
			<p v-if="meta && meta.missingIssuerFields.length" class="vbh-hint vbh-hint--warning">
				{{ t('In den Einstellungen fehlen noch Angaben des Vereins (Anschrift, Steuernummer). Ohne sie lässt sich keine Rechnung festschreiben.') }}
			</p>
			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>

			<section class="vbh-card vbh-inv-head">
				<div class="vbh-inv-recipient">
					<h3>{{ t('Empfänger') }}</h3>
					<DebtorSelect v-model="form.debtorId" @debtor="debtor = $event" />
				</div>
				<div class="vbh-inv-dates">
					<h3>{{ t('Rechnungsdaten') }}</h3>
					<div class="vbh-inv-grid">
						<label>{{ t('Rechnungsdatum') }}
							<input v-model="form.issueDate" type="date">
						</label>
						<label>{{ t('Zahlbar bis') }}
							<input v-model="form.dueDate" type="date" :min="form.issueDate || undefined">
						</label>
					</div>
					<fieldset class="vbh-fieldset-reset vbh-inv-delivery">
						<legend class="vbh-legend">
							{{ t('Leistungszeitpunkt (Pflichtangabe)') }}
						</legend>
						<label class="vbh-inv-radio">
							<input v-model="leistungMode" type="radio" value="date"> {{ t('Datum') }}
						</label>
						<label class="vbh-inv-radio">
							<input v-model="leistungMode" type="radio" value="period"> {{ t('Zeitraum') }}
						</label>
						<div class="vbh-inv-grid">
							<template v-if="leistungMode === 'date'">
								<label>{{ t('Leistungsdatum') }}
									<input v-model="form.deliveryDate" type="date">
								</label>
							</template>
							<template v-else>
								<label>{{ t('von') }}
									<input v-model="form.periodStart" type="date">
								</label>
								<label>{{ t('bis') }}
									<input v-model="form.periodEnd" type="date" :min="form.periodStart || undefined">
								</label>
							</template>
						</div>
					</fieldset>
				</div>
				<div class="vbh-inv-refs">
					<h3>{{ t('Referenzen (optional)') }}</h3>
					<div class="vbh-inv-grid">
						<label>{{ t('Ihre Referenz / Leitweg-ID') }}
							<input v-model="form.buyerReference" maxlength="255">
						</label>
						<label>{{ t('Bestellnummer') }}
							<input v-model="form.orderReference" maxlength="255">
						</label>
					</div>
				</div>
			</section>

			<section class="vbh-card">
				<h3>{{ t('Positionen') }}</h3>
				<div class="vbh-inv-lines" role="table" :aria-label="t('Positionen')">
					<div class="vbh-inv-line vbh-inv-line--head" :class="{ 'vbh-inv-line--tax': regular }" role="row">
						<span role="columnheader">{{ t('Pos.') }}</span>
						<span role="columnheader">{{ t('Bezeichnung') }}</span>
						<span role="columnheader">{{ t('Menge') }}</span>
						<span role="columnheader">{{ t('Einheit') }}</span>
						<span role="columnheader">{{ t('Einzelpreis') }}</span>
						<span role="columnheader">{{ t('Rabatt %') }}</span>
						<span role="columnheader">{{ t('Erlöskonto') }}</span>
						<span v-if="regular" role="columnheader">{{ t('USt') }}</span>
						<span role="columnheader" class="vbh-inv-num">{{ t('Netto') }}</span>
						<span role="columnheader" />
					</div>
					<div
						v-for="(line, i) in form.lines"
						:key="line.key"
						class="vbh-inv-line"
						:class="{ 'vbh-inv-line--tax': regular }"
						role="row">
						<span class="vbh-inv-pos" role="cell">{{ i + 1 }}</span>
						<div class="vbh-inv-name" role="cell">
							<input
								v-model="line.name"
								:aria-label="t('Bezeichnung')"
								maxlength="255"
								:placeholder="t('Leistung, z. B. Hallenmiete')">
							<textarea
								v-if="line.showDescription || line.description"
								v-model="line.description"
								:aria-label="t('Beschreibung')"
								rows="2"
								maxlength="2000"
								:placeholder="t('Beschreibung (optional)')" />
							<button
								v-else
								type="button"
								class="vbh-inv-linkbtn"
								@click="line.showDescription = true">
								{{ t('+ Beschreibung') }}
							</button>
						</div>
						<input
							v-model="line.quantity"
							class="vbh-inv-qty"
							inputmode="decimal"
							:aria-label="t('Menge')"
							:class="{ 'vbh-inv-invalid': quantityInvalid(line) }">
						<select v-model="line.unitCode" :aria-label="t('Einheit')">
							<option v-for="code in unitCodes" :key="code" :value="code">
								{{ unitLabel(code) }}
							</option>
						</select>
						<AmountInput
							v-model="line.unitPrice"
							class="vbh-inv-price"
							:aria-label="t('Einzelpreis')" />
						<input
							v-model="line.discountPercent"
							class="vbh-inv-discount"
							inputmode="decimal"
							:aria-label="t('Rabatt in Prozent')"
							placeholder="0">
						<select
							v-model="line.accountId"
							:aria-label="t('Erlöskonto')"
							:class="{ 'vbh-inv-invalid': !line.accountId && !isEmptyLine(line) }"
							@change="onAccountChange(line)">
							<option :value="null">
								{{ t('– Konto wählen –') }}
							</option>
							<option v-for="a in accounts" :key="a.id" :value="a.id">
								{{ a.number }} {{ a.name }}
							</option>
						</select>
						<select
							v-if="regular"
							v-model="line.taxKey"
							:aria-label="t('Umsatzsteuer der Position')"
							@change="line.taxTouched = true">
							<option v-for="key in taxKeys" :key="key" :value="key">
								{{ taxKeyLabel(key) }}
							</option>
						</select>
						<span class="vbh-inv-num vbh-inv-net" role="cell">{{ netText(line) }}</span>
						<span class="vbh-inv-actions" role="cell">
							<NcButton
								variant="tertiary"
								:disabled="i === 0"
								:aria-label="t('Position nach oben')"
								:title="t('Nach oben')"
								@click="moveLine(i, -1)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiArrowUp" :size="18" />
								</template>
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="i === form.lines.length - 1"
								:aria-label="t('Position nach unten')"
								:title="t('Nach unten')"
								@click="moveLine(i, 1)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiArrowDown" :size="18" />
								</template>
							</NcButton>
							<NcButton
								variant="tertiary"
								:aria-label="t('Position entfernen')"
								:title="t('Entfernen')"
								@click="removeLine(i)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiDelete" :size="18" />
								</template>
							</NcButton>
						</span>
					</div>
				</div>
				<div class="vbh-inv-linebar">
					<NcButton variant="secondary" @click="addLine">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPlus" :size="20" />
						</template>
						{{ t('Position hinzufügen') }}
					</NcButton>
					<select
						v-if="templates.length"
						v-model="templatePick"
						class="vbh-inv-tplpick"
						:aria-label="t('Position aus Vorlage einfügen')"
						@change="addFromTemplate">
						<option value="">
							{{ t('Aus Vorlage einfügen …') }}
						</option>
						<option v-for="tpl in templates" :key="tpl.id" :value="tpl.id">
							{{ tpl.name }}
						</option>
					</select>
				</div>

				<div class="vbh-inv-totals">
					<template v-if="regular">
						<div class="vbh-inv-totals-row">
							<span>{{ t('Summe netto') }}</span>
							<span>{{ formatMoney(sums.netCents / 100) }}</span>
						</div>
						<div v-for="g in sums.taxBreakdown" :key="g.rateBp" class="vbh-inv-totals-row">
							<span>{{ t('zzgl. {rate} USt', { rate: formatRate(g.rateBp) }) }}</span>
							<span>{{ formatMoney(g.taxCents / 100) }}</span>
						</div>
					</template>
					<div class="vbh-inv-totals-row vbh-inv-totals-row--grand">
						<span>{{ t('Rechnungsbetrag') }}</span>
						<strong>{{ formatMoney(sums.grossCents / 100) }}</strong>
					</div>
					<p v-if="sums.invalid" class="vbh-hint vbh-hint--error">
						{{ t('Mindestens eine Menge oder ein Rabatt ist keine gültige Zahl.') }}
					</p>
				</div>
			</section>

			<section class="vbh-card">
				<h3>{{ t('Steuer und Texte') }}</h3>
				<div class="vbh-inv-grid vbh-inv-grid--wide">
					<label>{{ t('Umsatzsteuer') }}
						<select v-model="form.taxMode">
							<option v-for="mode in taxModes" :key="mode" :value="mode">
								{{ taxModeLabel(mode) }}
							</option>
						</select>
					</label>
				</div>
				<p v-if="form.taxMode === 'kleinunternehmer'" class="vbh-hint">
					{{ t('Ausgewiesen wird keine Umsatzsteuer. Auf der Rechnung steht der Hinweis:') }}
					<em>{{ form.exemptionText || (meta && meta.smallBusinessNotice) }}</em>
				</p>
				<p v-if="regular" class="vbh-hint">
					{{ t('Die Umsatzsteuer wird je Position gewählt (19 %, 7 %, 0 % oder steuerfrei). Beim Festschreiben braucht jeder verwendete Satz ein Umsatzsteuerkonto in den Einstellungen.') }}
				</p>
				<label v-if="form.taxMode === 'kleinunternehmer' || form.taxMode === 'exempt' || needsExemptionText" class="vbh-inv-wide">
					{{ form.taxMode === 'kleinunternehmer' ? t('Eigener Hinweistext (optional, ersetzt den Standardtext)') : t('Hinweis auf die Steuerbefreiung (Pflicht)') }}
					<input
						v-model="form.exemptionText"
						maxlength="500"
						:placeholder="form.taxMode === 'kleinunternehmer' ? '' : t('z. B. Steuerfrei nach § 4 Nr. 22 UStG')">
				</label>
				<label class="vbh-inv-wide">{{ t('Einleitungstext') }}
					<textarea
						v-model="form.noteHead"
						rows="2"
						maxlength="2000"
						:placeholder="t('Steht über den Positionen')" />
				</label>
				<label class="vbh-inv-wide">{{ t('Schlusstext') }}
					<textarea
						v-model="form.noteFoot"
						rows="2"
						maxlength="2000"
						:placeholder="t('Steht unter dem Zahlungsblock')" />
				</label>
				<label class="vbh-inv-wide">{{ t('Zahlungsbedingungen') }}
					<textarea
						v-model="form.paymentTerms"
						rows="2"
						maxlength="1000"
						:placeholder="t('z. B. Zahlbar innerhalb von 14 Tagen ohne Abzug.')" />
				</label>
			</section>

			<section v-if="openChecks.length" class="vbh-card vbh-inv-checks">
				<h3>{{ t('Zum Festschreiben fehlt noch') }}</h3>
				<ul class="vbh-blockers">
					<li v-for="c in openChecks" :key="c.key">
						{{ c.label }}
					</li>
				</ul>
			</section>

			<div v-if="currentId" class="vbh-inv-discard">
				<NcButton variant="tertiary" @click="discardOpen = true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDelete" :size="20" />
					</template>
					{{ t('Entwurf verwerfen') }}
				</NcButton>
			</div>
		</div>

		<InvoiceIssueDialog
			:show="issue.open"
			:checklist="checklist"
			:issueDate="form.issueDate"
			:busy="issue.busy"
			:error="issue.error"
			@close="issue.open = false"
			@update:show="issue.open = $event"
			@confirm="confirmIssue" />

		<NcModal
			v-if="discardOpen"
			:show="discardOpen"
			labelId="vbh-modal-title-inv-discard"
			size="small"
			@close="discardOpen = false"
			@update:show="discardOpen = $event">
			<div class="vbh-modal-inner">
				<h2 id="vbh-modal-title-inv-discard" class="vbh-modal-title">
					{{ t('Entwurf verwerfen?') }}
				</h2>
				<p>{{ t('Der Entwurf wird gelöscht. Es wurde noch keine Rechnungsnummer vergeben.') }}</p>
				<div class="vbh-modal-actions">
					<NcButton variant="tertiary" @click="discardOpen = false">
						{{ t('Abbrechen') }}
					</NcButton>
					<NcButton variant="error" @click="discard">
						{{ t('Verwerfen') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { mdiArrowDown, mdiArrowLeft, mdiArrowUp, mdiDelete, mdiFilePdfBox, mdiPlus } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import AmountInput from './AmountInput.vue'
import DebtorSelect from './DebtorSelect.vue'
import InvoiceIssueDialog from './InvoiceIssueDialog.vue'
import api from '../api.js'
import { errMsg, formatMoney } from '../lib/format.js'
import {
	addDays,
	editorLineNet,
	editorTotals,
	emptyLine,
	isEmptyLine,
	lineFromServer,
	lineFromTemplate,
	linesToPayload,
	quantityToE4,
	suggestTaxKey,
	taxKeyLabel,
	todayIso,
	unitLabel,
} from '../lib/invoices.js'

const AUTOSAVE_MS = 1500

/**
 * Editor für Rechnungsentwürfe (Epic #168): Empfänger, Rechnungsdaten,
 * Positionen mit Erlöskonto, Steuer-Hinweis und Texte. Der Entwurf wird beim
 * Tippen automatisch gespeichert (kurz nach der letzten Änderung), sobald ein
 * Empfänger oder eine Position vorhanden ist. Festschreiben macht daraus eine
 * nummerierte, unveränderliche Rechnung (nur Verwalter).
 */
export default {
	name: 'InvoiceEditor',
	components: { NcButton, NcIconSvgWrapper, NcLoadingIcon, NcModal, AmountInput, DebtorSelect, InvoiceIssueDialog },
	props: {
		/** ID eines vorhandenen Entwurfs; ohne Angabe beginnt eine neue Rechnung. */
		invoiceId: { type: Number, default: null },
		/** Aktive Einnahmenkonten für die Erlöskonto-Auswahl. */
		accounts: { type: Array, default: () => [] },
		/** Antwort von GET /api/invoices/meta. */
		meta: { type: Object, default: null },
		isAdmin: { type: Boolean, default: false },
	},

	emits: ['close', 'changed', 'issued'],

	setup() {
		return { mdiArrowDown, mdiArrowLeft, mdiArrowUp, mdiDelete, mdiFilePdfBox, mdiPlus }
	},

	data() {
		return {
			loading: true,
			saving: false,
			error: '',
			currentId: this.invoiceId,
			debtor: null,
			leistungMode: 'date',
			form: this.blankForm(),
			dirty: false,
			savedAt: null,
			checklist: [],
			autosaveTimer: null,
			saveQueue: Promise.resolve(),
			discardOpen: false,
			issue: { open: false, busy: false, error: '' },
			templates: [],
			templatePick: '',
		}
	},

	computed: {
		unitCodes() { return this.meta?.unitCodes || ['C62', 'HUR', 'DAY', 'MON', 'LS'] },
		taxModes() { return this.meta?.taxModes || ['kleinunternehmer'] },
		regular() { return this.form.taxMode === 'regular' },
		taxKeys() { return ['S1900', 'S700', 'Z', 'E'] },
		rates() { return this.meta?.rates || { zweckbetrieb: 700, wirtschaftlich: 1900 } },
		accountsById() { return Object.fromEntries(this.accounts.map((a) => [a.id, a])) },
		needsExemptionText() { return this.regular && this.form.lines.some((l) => l.taxKey === 'E' && !isEmptyLine(l)) },
		sums() { return editorTotals(this.form.lines, this.regular) },
		openChecks() { return this.checklist.filter((c) => c.required && !c.ok) },
		hasContent() { return this.form.debtorId !== null || this.form.lines.some((l) => !isEmptyLine(l)) },

		saveStateText() {
			if (this.saving) { return this.t('Speichert …') }
			if (this.dirty) { return this.t('Ungespeicherte Änderungen') }
			if (this.savedAt) { return this.t('Gespeichert um {time}', { time: this.savedAt }) }
			return ''
		},
	},

	watch: {
		form: {
			deep: true,
			handler() {
				if (this.loading) { return }
				this.dirty = true
				this.scheduleAutosave()
			},
		},

		'form.taxMode': function(mode) {
			if (this.loading || mode !== 'regular') { return }
			for (const line of this.form.lines) { this.onAccountChange(line) }
		},

		leistungMode(mode) {
			// Je Rechnung gilt entweder ein Datum oder ein Zeitraum.
			if (this.loading) { return }
			if (mode === 'date') {
				this.form.periodStart = ''
				this.form.periodEnd = ''
			} else {
				this.form.deliveryDate = ''
			}
		},

		'form.issueDate': function(date, old) {
			// Das Zahlungsziel folgt dem Rechnungsdatum, solange es nicht von Hand geändert wurde.
			if (this.loading || !date || !old) { return }
			const days = this.meta?.defaultDueDays ?? 14
			if (this.form.dueDate === addDays(old, days)) { this.form.dueDate = addDays(date, days) }
		},
	},

	async mounted() {
		this.loadTemplates()
		await this.load()
	},

	beforeUnmount() {
		clearTimeout(this.autosaveTimer)
	},

	methods: {
		formatMoney,
		isEmptyLine,
		unitLabel,

		blankForm() {
			const today = todayIso()
			const days = this.meta?.defaultDueDays ?? 14
			const d = this.meta?.defaults || {}
			return {
				debtorId: null,
				issueDate: today,
				deliveryDate: today,
				periodStart: '',
				periodEnd: '',
				dueDate: addDays(today, days),
				buyerReference: '',
				orderReference: '',
				taxMode: this.meta?.taxMode || 'kleinunternehmer',
				exemptionText: d.exemptionText || '',
				noteHead: d.noteHead || '',
				noteFoot: d.noteFoot || '',
				paymentTerms: d.paymentTerms || '',
				lines: [emptyLine()],
			}
		},

		taxModeLabel(mode) {
			return {
				exempt: this.t('Steuerbefreit (Hinweis nach § 4 UStG)'),
				regular: this.t('Regelbesteuerung (Umsatzsteuer ausweisen)'),
			}[mode] || this.t('Kleinunternehmer (§ 19 UStG)')
		},

		taxKeyLabel,

		formatRate(rateBp) {
			return new Intl.NumberFormat('de-DE', { maximumFractionDigits: 2 }).format(rateBp / 100) + ' %'
		},

		/** Ein neues Erlöskonto schlägt den Steuersatz vor, solange er nicht von Hand gewählt wurde. */
		onAccountChange(line) {
			if (!this.regular || line.taxTouched) { return }
			line.taxKey = suggestTaxKey(this.accountsById[line.accountId] || null, this.rates)
		},

		async load() {
			this.loading = true
			try {
				if (this.invoiceId) {
					const { data } = await api.invoice(this.invoiceId)
					if (data.status !== 'draft') {
						showError(this.t('Festgeschriebene Rechnungen lassen sich nicht mehr bearbeiten.'))
						this.$emit('close')
						return
					}
					this.applyDetail(data)
				} else {
					this.form = this.blankForm()
				}
			} catch (e) {
				this.error = errMsg(e, this.t('Die Rechnung konnte nicht geladen werden.'))
			} finally {
				this.loading = false
				this.$nextTick(() => { this.dirty = false })
			}
		},

		applyDetail(data) {
			this.currentId = data.id
			this.checklist = data.checklist || []
			this.leistungMode = data.periodStart ? 'period' : 'date'
			this.form = {
				debtorId: data.debtorId,
				issueDate: data.issueDate || '',
				deliveryDate: data.deliveryDate || '',
				periodStart: data.periodStart || '',
				periodEnd: data.periodEnd || '',
				dueDate: data.dueDate || '',
				buyerReference: data.buyerReference || '',
				orderReference: data.orderReference || '',
				taxMode: data.taxMode,
				exemptionText: data.exemptionText || '',
				noteHead: data.noteHead || '',
				noteFoot: data.noteFoot || '',
				paymentTerms: data.paymentTerms || '',
				lines: data.lines.length ? data.lines.map(lineFromServer) : [emptyLine()],
			}
		},

		payload() {
			const f = this.form
			return {
				debtorId: f.debtorId,
				issueDate: f.issueDate || null,
				deliveryDate: this.leistungMode === 'date' ? (f.deliveryDate || null) : null,
				periodStart: this.leistungMode === 'period' ? (f.periodStart || null) : null,
				periodEnd: this.leistungMode === 'period' ? (f.periodEnd || null) : null,
				dueDate: f.dueDate || null,
				buyerReference: f.buyerReference,
				orderReference: f.orderReference,
				taxMode: f.taxMode,
				exemptionText: f.exemptionText,
				noteHead: f.noteHead,
				noteFoot: f.noteFoot,
				paymentTerms: f.paymentTerms,
				lines: linesToPayload(f.lines, this.regular),
			}
		},

		scheduleAutosave() {
			clearTimeout(this.autosaveTimer)
			if (!this.hasContent) { return }
			this.autosaveTimer = setTimeout(() => this.save(true), AUTOSAVE_MS)
		},

		/** Speichert nacheinander: eine zweite Anfrage wartet auf die erste, damit nicht zwei Entwürfe entstehen. */
		save(silent = false) {
			clearTimeout(this.autosaveTimer)
			this.saveQueue = this.saveQueue.then(() => this.doSave(silent))
			return this.saveQueue
		},

		async doSave(silent) {
			if (!this.hasContent && !this.currentId) { return true }
			this.saving = true
			this.error = ''
			const snapshot = JSON.stringify(this.form)
			try {
				const body = this.payload()
				const { data } = this.currentId ? await api.updateInvoice(this.currentId, body) : await api.createInvoice(body)
				this.currentId = data.id
				this.checklist = data.checklist || []
				if (JSON.stringify(this.form) === snapshot) { this.dirty = false }
				this.savedAt = new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
				this.$emit('changed')
				return true
			} catch (e) {
				this.error = errMsg(e, this.t('Der Entwurf konnte nicht gespeichert werden.'))
				if (!silent) { showError(this.error) }
				return false
			} finally {
				this.saving = false
			}
		},

		async saveNow() {
			if (await this.save(false)) { showSuccess(this.t('Entwurf gespeichert.')) }
		},

		async preview() {
			if (!(await this.save(false)) || !this.currentId) { return }
			window.open(api.invoicePdfUrl(this.currentId), '_blank', 'noopener')
		},

		async openIssue() {
			if (!(await this.save(false)) || !this.currentId) { return }
			this.issue = { open: true, busy: false, error: '' }
		},

		async confirmIssue(issueDate) {
			this.issue.busy = true
			this.issue.error = ''
			try {
				const { data } = await api.issueInvoice(this.currentId, issueDate)
				this.issue.open = false
				this.dirty = false
				showSuccess(this.t('Rechnung {number} festgeschrieben.', { number: data.number }))
				if (data.pdfError) { showError(this.t('Das PDF konnte noch nicht erzeugt werden: {message}', { message: data.pdfError })) }
				this.$emit('issued', data)
			} catch (e) {
				const problems = e?.response?.data?.problems
				this.issue.error = problems && problems.length
					? this.t('Es fehlen noch Angaben: {list}', { list: problems.join(', ') })
					: errMsg(e, this.t('Die Rechnung konnte nicht festgeschrieben werden.'))
			} finally {
				this.issue.busy = false
			}
		},

		async discard() {
			this.discardOpen = false
			try {
				if (this.currentId) { await api.deleteInvoice(this.currentId) }
				this.dirty = false
				this.$emit('changed')
				this.$emit('close')
			} catch (e) {
				showError(errMsg(e, this.t('Der Entwurf konnte nicht gelöscht werden.')))
			}
		},

		async close() {
			// Offene Änderungen vor dem Verlassen sichern, damit nichts verloren geht.
			if (this.dirty && this.hasContent) { await this.save(true) }
			this.$emit('close')
		},

		/** Die Vorlagen sind eine Annehmlichkeit: fehlen sie, schreibt man die Position eben von Hand. */
		async loadTemplates() {
			try {
				const { data } = await api.invoiceTemplates()
				this.templates = data
			} catch {
				this.templates = []
			}
		},

		/** Fügt die gewählte Vorlage als Kopie ein; eine leere letzte Zeile wird dafür verwendet. */
		addFromTemplate() {
			const tpl = this.templates.find((x) => x.id === Number(this.templatePick))
			this.templatePick = ''
			if (!tpl) { return }
			const line = lineFromTemplate(tpl)
			const last = this.form.lines[this.form.lines.length - 1]
			if (last && isEmptyLine(last)) {
				this.form.lines.splice(this.form.lines.length - 1, 1, line)
			} else {
				this.form.lines.push(line)
			}
		},

		addLine() {
			const last = this.form.lines[this.form.lines.length - 1]
			this.form.lines.push(emptyLine(last ? last.accountId : null, last ? last.taxKey : 'S1900'))
		},

		removeLine(i) {
			this.form.lines.splice(i, 1)
			if (this.form.lines.length === 0) { this.form.lines.push(emptyLine()) }
		},

		moveLine(i, delta) {
			const j = i + delta
			if (j < 0 || j >= this.form.lines.length) { return }
			const rows = this.form.lines
			;[rows[i], rows[j]] = [rows[j], rows[i]]
		},

		quantityInvalid(line) {
			return line.quantity !== '' && (quantityToE4(line.quantity) === null || quantityToE4(line.quantity) <= 0)
		},

		netText(line) {
			if (isEmptyLine(line)) { return '' }
			const net = editorLineNet(line)
			return net === null ? '–' : formatMoney(net / 100)
		},
	},
}
</script>

<style scoped>
.vbh-inv-editor { display: flex; flex-direction: column; min-height: 0; flex: 1 1 auto; }

.vbh-inv-editor-title { margin-inline-start: 8px; }

.vbh-inv-saved { font-size: 0.85em; opacity: 0.7; white-space: nowrap; }

.vbh-inv-body { display: flex; flex-direction: column; gap: 12px; }

.vbh-inv-head { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); gap: 16px 28px; }

.vbh-inv-refs { grid-column: 1 / -1; }

h3 { margin: 0 0 8px; font-size: 1em; }

.vbh-inv-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }

.vbh-inv-grid--wide { grid-template-columns: minmax(0, 360px); }

.vbh-inv-grid label, .vbh-inv-wide { display: flex; flex-direction: column; gap: 3px; font-size: 0.88em; font-weight: 600; }

.vbh-inv-wide { margin-top: 10px; }

.vbh-inv-wide textarea, .vbh-inv-wide input { width: 100%; box-sizing: border-box; font-weight: normal; }

.vbh-inv-delivery { margin-top: 10px; }

.vbh-inv-radio { display: inline-flex; align-items: center; gap: 4px; margin-inline-end: 14px; font-size: 0.9em; }

.vbh-inv-lines { display: flex; flex-direction: column; gap: 6px; overflow-x: auto; padding-bottom: 4px; }

.vbh-inv-line { display: grid; grid-template-columns: 28px minmax(220px, 3fr) 78px 110px 118px 70px minmax(150px, 1.6fr) 100px 108px; gap: 6px; align-items: start; min-width: 980px; }

.vbh-inv-line--tax { grid-template-columns: 28px minmax(200px, 3fr) 78px 110px 118px 70px minmax(150px, 1.6fr) 96px 100px 108px; min-width: 1100px; }

.vbh-inv-line--head { font-size: 0.8em; font-weight: 600; opacity: 0.75; align-items: end; }

.vbh-inv-line input, .vbh-inv-line select, .vbh-inv-line textarea { width: 100%; min-width: 0; box-sizing: border-box; margin: 0; }

.vbh-inv-name { display: flex; flex-direction: column; gap: 4px; }

.vbh-inv-pos { padding-top: 8px; opacity: 0.6; text-align: end; }

.vbh-inv-qty, .vbh-inv-discount, .vbh-inv-price { text-align: end; }

.vbh-inv-num { text-align: end; font-variant-numeric: tabular-nums; }

.vbh-inv-net { padding-top: 8px; font-weight: 600; }

.vbh-inv-actions { display: inline-flex; }

.vbh-inv-invalid { border-color: var(--color-element-error) !important; }

.vbh-inv-linkbtn { align-self: flex-start; background: none; border: 0; padding: 0; color: var(--color-primary-element); cursor: pointer; font-size: 0.85em; }

.vbh-inv-linebar { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }

.vbh-inv-tplpick { max-width: 260px; }

.vbh-inv-totals { display: flex; flex-direction: column; align-items: flex-end; margin-top: 12px; }

.vbh-inv-totals-row { display: flex; gap: 24px; justify-content: space-between; min-width: 280px; padding: 8px 0; border-top: 1px solid var(--color-border-dark, var(--color-border)); }

.vbh-inv-totals-row--grand { font-size: 1.15em; }

.vbh-inv-discard { margin-top: 4px; }

@media (max-width: 900px) {
	.vbh-inv-head { grid-template-columns: minmax(0, 1fr); }
}
</style>
