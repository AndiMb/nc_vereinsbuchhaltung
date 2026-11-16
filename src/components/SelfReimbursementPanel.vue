<template>
	<div class="vbh-selfservice vbh-reimb">
		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else>
			<div class="vbh-card">
				<div class="vbh-selfservice-card-header">
					<h4>{{ t('Meine Auslagen') }}</h4>
					<NcButton v-if="canSubmit" variant="primary" @click="openSubmit">
						{{ t('Auslage einreichen') }}
					</NcButton>
				</div>
				<p class="vbh-hint vbh-hint--info" role="note">
					{{ t('Haben Sie etwas für den Verein verauslagt? Reichen Sie hier den Beleg ein. Sobald genügend Verwalter zugestimmt haben, überweist der Verein den Betrag auf Ihr Konto.') }}
				</p>
				<p v-if="!canSubmit" class="vbh-hint vbh-hint--warning" role="note">
					{{ t('Neue Auslagen lassen sich derzeit nicht einreichen. Ihre bisherigen Anträge sehen Sie weiterhin.') }}
				</p>

				<section v-if="drafts.length" class="vbh-reimb-drafts" :aria-label="t('Entwürfe')">
					<h5 class="vbh-reimb-drafts-title">
						{{ t('Entwürfe') }}
					</h5>
					<p class="vbh-reimb-help">
						{{ t('Nur Sie sehen Ihre Entwürfe. Erst mit „Einreichen“ geht der Antrag an die Verwalter.') }}
					</p>
					<ul class="vbh-reimb-draft-list">
						<li v-for="draft in drafts" :key="draft.id">
							<button type="button" class="vbh-reimb-draft" @click="openDraft(draft)">
								<span class="vbh-reimb-draft-purpose">{{ draft.purpose || t('Ohne Zweck') }}</span>
								<span class="vbh-reimb-draft-meta">{{ draftMeta(draft) }}</span>
							</button>
						</li>
					</ul>
				</section>

				<div v-if="items.length" class="vbh-tablecard">
					<table class="vbh-table vbh-stack vbh-reimb-table">
						<thead>
							<tr>
								<th class="nowrap">
									{{ t('Datum') }}
								</th>
								<th>{{ t('Verwendungszweck') }}</th>
								<th class="num">
									{{ t('Betrag') }}
								</th>
								<th>{{ t('Status') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="item in items"
								:key="item.id"
								class="vbh-reimb-row"
								tabindex="0"
								@click="openDetail(item.id)"
								@keydown.enter="openDetail(item.id)">
								<td class="nowrap vbh-stack-eyebrow">
									{{ formatDate(item.expenseDate) }}
								</td>
								<td class="vbh-purpose vbh-stack-title">
									<span class="vbh-clamp"><span v-if="item.number" class="vbh-reimb-number">{{ item.number }}</span> {{ item.purpose }}</span>
								</td>
								<td class="num strong">
									{{ formatMoney(item.amountCents / 100) }}
								</td>
								<td>
									<span class="vbh-reimb-status">
										<ReimbursementStatusTag :status="item.status" />
										<span v-if="progressText(item)" class="vbh-reimb-progress">{{ progressText(item) }}</span>
									</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<NcEmptyContent
					v-else-if="!drafts.length"
					:name="t('Noch keine Auslagen')"
					:description="canSubmit ? t('Mit „Auslage einreichen“ legen Sie den ersten Antrag an.') : ''" />
			</div>
		</template>

		<!-- Einreichen -->
		<NcModal
			v-if="submitOpen"
			:show="submitOpen"
			labelId="vbh-modal-title-reimb-submit"
			size="normal"
			:closeOnClickOutside="false"
			@close="closeSubmit"
			@update:show="submitOpen = $event">
			<div class="vbh-modal-inner vbh-reimb-dialog">
				<h2 id="vbh-modal-title-reimb-submit" class="vbh-modal-title">
					{{ draftId ? t('Entwurf bearbeiten') : t('Auslage einreichen') }}
				</h2>
				<p class="vbh-reimb-intro">
					{{ t('Laden Sie den Beleg hoch und nennen Sie den Betrag. Die Verwalter prüfen den Antrag, danach überweist der Verein.') }}
				</p>
				<p class="vbh-reimb-help vbh-reimb-intro-more">
					{{ t('Mehrere Rechnungen? Reichen Sie jede als eigenen Antrag ein. Mit „Einreichen und weitere erfassen“ bleiben Kontoverbindung und Erklärung ausgefüllt.') }}
				</p>
				<p class="vbh-reimb-help vbh-reimb-intro-more">
					{{ t('Noch nicht alles beisammen? Mit „Als Entwurf speichern“ sichern Sie den Stand und reichen später ein. Den Entwurf sehen nur Sie.') }}
				</p>

				<form class="vbh-reimb-form" novalidate @submit.prevent="submit">
					<div class="vbh-reimb-row2">
						<label class="vbh-reimb-field">
							<span class="vbh-reimb-label">{{ t('Betrag ({currency})', { currency: currencySymbol() }) }}</span>
							<AmountInput v-model="form.amount" class="vbh-reimb-amount" />
						</label>
						<label class="vbh-reimb-field">
							<span class="vbh-reimb-label">{{ t('Rechnungsdatum') }}</span>
							<input v-model="form.expenseDate" type="date" :max="today">
						</label>
					</div>

					<div class="vbh-reimb-row2">
						<label class="vbh-reimb-field">
							<span class="vbh-reimb-label">{{ t('Rechnungsnummer') }}</span>
							<input v-model="form.invoiceNumber" maxlength="100" :placeholder="t('optional')">
						</label>
						<label class="vbh-reimb-field">
							<span class="vbh-reimb-label">{{ t('Lieferant / Shop') }}</span>
							<input v-model="form.supplier" maxlength="255" :placeholder="t('optional')">
						</label>
					</div>

					<label class="vbh-reimb-field">
						<span class="vbh-reimb-label">{{ t('Zweck / Vereinsbezug') }}</span>
						<input
							ref="purpose"
							v-model="form.purpose"
							maxlength="500"
							:placeholder="t('z. B. Material für die Jugendfreizeit')">
						<span class="vbh-reimb-help">{{ t('Was wurde gekauft, und wofür?') }}</span>
					</label>

					<div class="vbh-reimb-field">
						<span class="vbh-reimb-label">{{ t('Belege') }}</span>
						<ul v-if="form.draftFiles.length" class="vbh-reimb-draft-files">
							<li v-for="file in form.draftFiles" :key="file.id">
								<a :href="draftFileUrl(file)" target="_blank" rel="noopener noreferrer">{{ file.fileName }}</a>
								<span class="vbh-reimb-help">{{ formatFileSize(file.fileSize) }}</span>
								<NcButton
									variant="tertiary"
									:aria-label="tRaw('Beleg „{name}“ entfernen', { name: file.fileName })"
									@click="removeDraftFile(file)">
									{{ t('Entfernen') }}
								</NcButton>
							</li>
						</ul>
						<ReimbursementReceiptPicker v-model="form.files" />
					</div>

					<fieldset class="vbh-fieldset-reset vbh-reimb-section">
						<legend class="vbh-reimb-legend">
							{{ t('Kontoverbindung') }}
						</legend>
						<label class="vbh-reimb-field">
							<span class="vbh-reimb-label">{{ t('Kontoinhaber') }}</span>
							<input v-model="form.accountHolder" maxlength="255" autocomplete="off">
							<span class="vbh-reimb-help">{{ t('Der Verein überweist an diesen Kontoinhaber. Soll das Geld auf das Konto einer anderen Person gehen, tragen Sie deren Namen ein.') }}</span>
						</label>

						<label class="vbh-reimb-field">
							<span class="vbh-reimb-label">{{ t('IBAN für die Erstattung') }}</span>
							<input
								v-model="form.iban"
								class="vbh-reimb-iban"
								:class="{ 'is-invalid': ibanStatus === 'invalid' }"
								autocomplete="off"
								spellcheck="false"
								inputmode="text"
								placeholder="DE00 0000 0000 0000 0000 00"
								@blur="form.iban = formatIban(form.iban)">
							<span v-if="ibanStatus === 'invalid'" class="vbh-reimb-help vbh-reimb-help--warn" role="alert">
								{{ t('Die Prüfziffer dieser IBAN stimmt nicht – bitte noch einmal prüfen.') }}
							</span>
							<span v-else-if="ibanStatus === 'valid'" class="vbh-reimb-help vbh-reimb-help--ok">
								{{ ibanFromMandate ? t('Aus Ihrem SEPA-Mandat übernommen – bei Bedarf ändern Sie sie hier.') : t('Die IBAN sieht gut aus.') }}
							</span>
							<span v-else class="vbh-reimb-help">{{ t('Auf dieses Konto wird die Auslage überwiesen.') }}</span>
						</label>
					</fieldset>

					<fieldset class="vbh-fieldset-reset vbh-reimb-section">
						<legend class="vbh-reimb-legend">
							{{ t('Erklärung') }}
						</legend>
						<NcCheckboxRadioSwitch v-model="form.declaration" type="checkbox">
							{{ declarationText }}
						</NcCheckboxRadioSwitch>
					</fieldset>
				</form>

				<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
					{{ error }}
				</p>
				<div class="vbh-modal-actions vbh-reimb-actions">
					<span v-if="missing.length" class="vbh-reimb-missing">{{ t('Noch nötig: {fields}', { fields: missing.join(', ') }) }}</span>
					<NcButton
						v-if="draftId"
						variant="tertiary"
						class="vbh-reimb-delete-draft"
						:disabled="busy"
						@click="deleteDraft">
						{{ confirmDelete ? t('Entwurf wirklich löschen') : t('Entwurf löschen') }}
					</NcButton>
					<NcButton variant="tertiary" @click="closeSubmit">
						{{ t('Abbrechen') }}
					</NcButton>
					<NcButton variant="secondary" :disabled="busy || !hasContent" @click="saveDraft">
						{{ t('Als Entwurf speichern') }}
					</NcButton>
					<NcButton
						v-if="!draftId"
						variant="secondary"
						:disabled="busy || missing.length > 0"
						@click="submitAndAddAnother">
						{{ t('Einreichen und weitere erfassen') }}
					</NcButton>
					<NcButton variant="primary" :disabled="busy || missing.length > 0" @click="submit">
						{{ busy ? t('Wird eingereicht') : t('Einreichen') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- Einzelansicht -->
		<NcModal
			v-if="detail"
			:show="!!detail"
			labelId="vbh-modal-title-reimb-detail"
			size="normal"
			@close="detail = null"
			@update:show="detail = $event ? detail : null">
			<div class="vbh-modal-inner">
				<h2 id="vbh-modal-title-reimb-detail" class="vbh-modal-title">
					{{ t('Auslage vom {date}', { date: formatDate(detail.expenseDate) }) }}
				</h2>
				<dl class="vbh-selfservice-fields">
					<template v-if="detail.number">
						<dt>{{ t('Auslagennummer') }}</dt>
						<dd>{{ detail.number }}</dd>
					</template>
					<dt>{{ t('Betrag') }}</dt>
					<dd>{{ formatMoney(detail.amountCents / 100) }}</dd>
					<template v-if="detail.invoiceNumber">
						<dt>{{ t('Rechnungsnummer') }}</dt>
						<dd>{{ detail.invoiceNumber }}</dd>
					</template>
					<template v-if="detail.supplier">
						<dt>{{ t('Lieferant / Shop') }}</dt>
						<dd>{{ detail.supplier }}</dd>
					</template>
					<dt>{{ t('Verwendungszweck') }}</dt>
					<dd>{{ detail.purpose }}</dd>
					<template v-if="detail.accountHolder">
						<dt>{{ t('Kontoinhaber') }}</dt>
						<dd>{{ detail.accountHolder }}</dd>
					</template>
					<dt>{{ t('IBAN') }}</dt>
					<dd class="vbh-reimb-iban-text">
						{{ formatIban(detail.iban) }}
					</dd>
					<dt>{{ t('Status') }}</dt>
					<dd>
						<ReimbursementStatusTag :status="detail.status" />
						<span v-if="progressText(detail)" class="vbh-reimb-progress">{{ progressText(detail) }}</span>
					</dd>
					<template v-if="detail.status === 'rejected' && detail.rejectionReason">
						<dt>{{ t('Begründung') }}</dt>
						<dd>{{ detail.rejectionReason }}</dd>
					</template>
					<template v-if="detail.paidAt">
						<dt>{{ t('Überwiesen am') }}</dt>
						<dd>{{ formatDate(detail.paidAt) }}</dd>
					</template>
				</dl>

				<h4 class="vbh-selfservice-subhead">
					{{ t('Belege') }}
				</h4>
				<ul class="vbh-reimb-files">
					<li v-for="file in detail.files" :key="file.id">
						<a :href="receiptUrl(file)" target="_blank" rel="noopener">{{ file.fileName }}</a>
						<span class="vbh-reimb-progress">{{ formatFileSize(file.fileSize) }}</span>
						<NcButton
							v-if="detail.canWithdraw && detail.files.length > 1"
							variant="tertiary"
							:aria-label="t('Beleg entfernen')"
							:disabled="busy"
							@click="removeReceipt(file)">
							×
						</NcButton>
					</li>
				</ul>
				<div v-if="detail.canWithdraw" class="vbh-reimb-addreceipt">
					<NcButton variant="secondary" :disabled="busy" @click="$refs.addReceiptInput.click()">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPlus" :size="18" />
						</template>
						{{ t('Beleg hinzufügen') }}
					</NcButton>
					<input
						ref="addReceiptInput"
						type="file"
						hidden
						:accept="receiptAccept"
						@change="addReceipt">
				</div>

				<h4 class="vbh-selfservice-subhead">
					{{ t('Verlauf') }}
				</h4>
				<ReimbursementHistory :events="detail.history" />

				<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
					{{ error }}
				</p>
				<div class="vbh-modal-actions">
					<NcButton
						v-if="detail.canWithdraw"
						variant="error"
						:disabled="busy"
						@click="withdraw">
						{{ t('Antrag zurückziehen') }}
					</NcButton>
					<NcButton variant="tertiary" @click="detail = null">
						{{ t('Schließen') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { mdiPlus } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import AmountInput from './AmountInput.vue'
import ReimbursementHistory from './ReimbursementHistory.vue'
import ReimbursementReceiptPicker from './ReimbursementReceiptPicker.vue'
import ReimbursementStatusTag from './ReimbursementStatusTag.vue'
import api from '../api.js'
import { currencySymbol } from '../lib/clubCurrency.js'
import { errMsg, formatDate, formatFileSize, formatMoney, roundCents } from '../lib/format.js'
import { formatIban, ibanState, normalizeIban } from '../lib/iban.js'
import { todayIso } from '../lib/invoices.js'
import { focusOnOpen } from '../lib/modalFocus.js'
import { RECEIPT_ACCEPT } from '../lib/reimbursementFiles.js'
import { emptyReimbursementForm, emptyReimbursementInvoice, missingReimbursementFields, reimbursementDraftForm, reimbursementFormHasContent, reimbursementProgressText } from '../lib/reimbursements.js'

/**
 * „Mein Bereich → Auslagen“ (docs/reimbursement-design.md): die eigenen
 * Anträge, ein neuer Antrag mit Beleg, Zurückziehen und Belege nachreichen.
 * Mitglieder laden nur hoch - den Wächter-Ordner sehen sie nie.
 */
export default {
	name: 'SelfReimbursementPanel',
	components: { NcButton, NcCheckboxRadioSwitch, NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon, NcModal, AmountInput, ReimbursementHistory, ReimbursementReceiptPicker, ReimbursementStatusTag },

	data() {
		return {
			loading: true,
			canSubmit: false,
			suggestedIban: null,
			suggestedAccountHolder: '',
			declarationText: '',
			items: [],
			drafts: [],
			draftId: null,
			confirmDelete: false,
			mdiPlus,
			receiptAccept: RECEIPT_ACCEPT,
			submitOpen: false,
			form: emptyReimbursementForm(todayIso()),
			detail: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		today() { return todayIso() },

		ibanStatus() { return ibanState(this.form.iban) },

		ibanFromMandate() { return !!this.suggestedIban && normalizeIban(this.form.iban) === normalizeIban(this.suggestedIban) },

		/** Was zum Einreichen noch fehlt, als Liste von Feldnamen – steht neben dem Knopf. */
		missing() { return missingReimbursementFields(this.form) },

		/** Ein ganz leeres Formular lohnt keinen Entwurf. */
		hasContent() { return reimbursementFormHasContent(this.form) },
	},

	async mounted() {
		await this.load()
	},

	methods: {
		currencySymbol,
		formatDate,
		formatFileSize,
		formatIban,
		formatMoney,

		progressText: reimbursementProgressText,

		/** Ein leerer Antrag; die Kontoverbindung ist aus dem Mandat bzw. dem Mitgliedsnamen vorbelegt. */
		emptyForm() {
			return emptyReimbursementForm(this.today, { accountHolder: this.suggestedAccountHolder, iban: this.suggestedIban })
		},

		async load() {
			try {
				const { data } = await api.selfReimbursements()
				this.canSubmit = !!data.canSubmit
				this.suggestedIban = data.suggestedIban || null
				this.suggestedAccountHolder = data.suggestedAccountHolder || ''
				this.declarationText = data.declarationText || ''
				this.items = data.items
				this.drafts = data.drafts || []
			} catch (e) {
				showError(errMsg(e, this.t('Die Auslagen konnten nicht geladen werden.')))
			} finally {
				this.loading = false
			}
		},

		openSubmit() {
			this.form = this.emptyForm()
			this.draftId = null
			this.confirmDelete = false
			this.error = ''
			this.submitOpen = true
			focusOnOpen(this, () => this.$refs.purpose)
		},

		/** Öffnet einen gespeicherten Entwurf im selben Formular. */
		openDraft(draft) {
			const form = reimbursementDraftForm(draft, { accountHolder: this.suggestedAccountHolder, iban: this.suggestedIban })
			form.iban = formatIban(form.iban)
			this.form = form
			this.draftId = draft.id
			this.confirmDelete = false
			this.error = ''
			this.submitOpen = true
			focusOnOpen(this, () => this.$refs.purpose)
		},

		closeSubmit() { this.submitOpen = false },

		/** Die Formularfelder; ohne Betrag bleibt `amount` leer (für Entwürfe). */
		formData() {
			const data = new FormData()
			data.append('amount', Number(this.form.amount) > 0 ? String(roundCents(this.form.amount)) : '')
			data.append('expenseDate', this.form.expenseDate || '')
			data.append('purpose', this.form.purpose.trim())
			data.append('invoiceNumber', this.form.invoiceNumber.trim())
			data.append('supplier', this.form.supplier.trim())
			data.append('iban', this.form.iban.trim())
			data.append('accountHolder', this.form.accountHolder.trim())
			for (const file of this.form.files) { data.append('files[]', file) }
			for (const file of this.form.draftFiles) { data.append('keepFileIds[]', String(file.id)) }
			return data
		},

		/** Sendet den Antrag; `true`, wenn er eingereicht wurde. */
		async send() {
			if (this.busy || this.missing.length > 0) { return false }
			this.busy = true
			this.error = ''
			try {
				const data = this.formData()
				data.append('declaration', this.form.declaration ? '1' : '0')
				if (this.draftId) {
					await api.selfSubmitReimbursementDraft(this.draftId, data)
					this.draftId = null
				} else {
					await api.selfSubmitReimbursement(data)
				}
				showSuccess(this.t('Die Auslage wurde eingereicht.'))
				return true
			} catch (e) {
				this.error = errMsg(e, this.t('Die Auslage konnte nicht eingereicht werden.'))
				return false
			} finally {
				this.busy = false
			}
		},

		async submit() {
			if (await this.send()) {
				this.submitOpen = false
				await this.load()
			}
		},

		/**
		 * Jede Rechnung ist ein eigener Antrag: Kontoverbindung und Erklärung bleiben stehen, nur die
		 * Rechnung ist wieder leer. Die Liste lädt im Hintergrund nach, der Dialog bleibt bedienbar.
		 */
		async submitAndAddAnother() {
			if (await this.send()) {
				Object.assign(this.form, emptyReimbursementInvoice(this.today))
				focusOnOpen(this, () => this.$refs.purpose)
				this.load()
			}
		},

		/** Speichert den Stand als Entwurf; Pflichtangaben und Erklärung braucht es dafür nicht. */
		async saveDraft() {
			if (this.busy || !this.hasContent) { return }
			this.busy = true
			this.error = ''
			try {
				const data = this.formData()
				if (this.draftId) {
					await api.selfUpdateReimbursementDraft(this.draftId, data)
				} else {
					await api.selfCreateReimbursementDraft(data)
				}
				showSuccess(this.t('Der Entwurf wurde gespeichert. Nur Sie sehen ihn.'))
				this.submitOpen = false
				await this.load()
			} catch (e) {
				this.error = errMsg(e, this.t('Der Entwurf konnte nicht gespeichert werden.'))
			} finally {
				this.busy = false
			}
		},

		/** Erst der zweite Klick löscht – ein Versehen kostet sonst die Vorarbeit. */
		async deleteDraft() {
			if (!this.confirmDelete) {
				this.confirmDelete = true
				return
			}
			this.busy = true
			this.error = ''
			try {
				await api.selfDeleteReimbursementDraft(this.draftId)
				showSuccess(this.t('Der Entwurf wurde gelöscht.'))
				this.submitOpen = false
				this.draftId = null
				await this.load()
			} catch (e) {
				this.error = errMsg(e, this.t('Der Entwurf konnte nicht gelöscht werden.'))
			} finally {
				this.busy = false
			}
		},

		removeDraftFile(file) {
			this.form.draftFiles = this.form.draftFiles.filter((f) => f.id !== file.id)
		},

		draftFileUrl(file) { return api.selfReimbursementDraftFileUrl(this.draftId, file.id) },

		/** Kurzinfo in der Entwurfsliste: Betrag, Belege, zuletzt gespeichert. */
		draftMeta(draft) {
			const parts = [draft.amountCents !== null ? formatMoney(draft.amountCents / 100) : this.t('ohne Betrag')]
			parts.push(this.n('%n Beleg', '%n Belege', draft.files.length))
			parts.push(this.t('gespeichert am {date}', { date: formatDate(draft.updatedAt.slice(0, 10)) }))
			return parts.join(' · ')
		},

		async openDetail(id) {
			this.error = ''
			try {
				const { data } = await api.selfReimbursement(id)
				this.detail = data
			} catch (e) {
				showError(errMsg(e, this.t('Der Antrag konnte nicht geladen werden.')))
			}
		},

		receiptUrl(file) { return api.selfReimbursementReceiptUrl(this.detail.id, file.id) },

		async withdraw() {
			this.busy = true
			this.error = ''
			try {
				await api.selfWithdrawReimbursement(this.detail.id)
				showSuccess(this.t('Der Antrag wurde zurückgezogen.'))
				this.detail = null
				await this.load()
			} catch (e) {
				this.error = errMsg(e, this.t('Der Antrag konnte nicht zurückgezogen werden.'))
			} finally {
				this.busy = false
			}
		},

		async addReceipt(event) {
			const file = event.target.files && event.target.files[0]
			event.target.value = ''
			if (!file) { return }
			this.busy = true
			this.error = ''
			try {
				const data = new FormData()
				data.append('file', file)
				await api.selfAddReimbursementReceipt(this.detail.id, data)
				await this.openDetail(this.detail.id)
			} catch (e) {
				this.error = errMsg(e, this.t('Der Beleg konnte nicht hochgeladen werden.'))
			} finally {
				this.busy = false
			}
		},

		async removeReceipt(file) {
			this.busy = true
			this.error = ''
			try {
				await api.selfRemoveReimbursementReceipt(this.detail.id, file.id)
				await this.openDetail(this.detail.id)
			} catch (e) {
				this.error = errMsg(e, this.t('Der Beleg konnte nicht entfernt werden.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-selfservice-card-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.vbh-reimb-row {
	cursor: pointer;
}

.vbh-reimb-drafts {
	margin-block: 12px 16px;
}

.vbh-reimb-drafts-title {
	margin: 0 0 4px;
	font-weight: bold;
}

.vbh-reimb-draft-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 8px;
}

.vbh-reimb-draft {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 2px;
	width: 100%;
	min-height: 44px;
	margin: 0;
	padding: 8px 12px;
	border: 1px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-weight: normal;
	text-align: start;
	cursor: pointer;
}

.vbh-reimb-draft:hover,
.vbh-reimb-draft:focus-visible {
	background: var(--color-background-hover);
}

.vbh-reimb-draft-purpose {
	font-weight: bold;
}

.vbh-reimb-draft-meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.vbh-reimb-draft-files {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin-bottom: 8px;
}

.vbh-reimb-draft-files li {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 12px;
}

.vbh-reimb-draft-files a {
	overflow-wrap: anywhere;
	text-decoration: underline;
}

.vbh-reimb-status {
	display: inline-flex;
	flex-wrap: wrap;
	gap: 4px 8px;
	align-items: center;
	justify-content: flex-end;
}

.vbh-reimb-number {
	font-weight: 600;
	white-space: nowrap;
}

.vbh-reimb-progress {
	margin-inline-start: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.vbh-reimb-files {
	display: grid;
	gap: 4px;
	margin: 8px 0;
	padding: 0;
	list-style: none;
}

.vbh-reimb-files li {
	display: flex;
	align-items: center;
	gap: 4px;
}

.vbh-reimb-addreceipt {
	margin-top: 8px;
}

/* Einreichen-Dialog: Beschriftung über dem Feld, jedes Feld volle Breite, am Handy eine Spalte. */
.vbh-reimb-dialog {
	min-width: min(560px, 100%);
}

.vbh-reimb-intro {
	margin: 0 0 18px;
	color: var(--color-text-maxcontrast);
}

.vbh-reimb-form {
	display: grid;
	gap: 18px;
}

.vbh-reimb-section {
	display: grid;
	gap: 18px;
}

.vbh-reimb-legend {
	margin-bottom: 10px;
	font-size: 1.05em;
	font-weight: 700;
}

.vbh-reimb-intro-more {
	margin: -12px 0 18px;
}

.vbh-reimb-row2 {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
	gap: 16px;
}

.vbh-reimb-field {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 0;
}

.vbh-reimb-label {
	font-weight: 600;
}

.vbh-reimb-field input:not([type="file"]) {
	box-sizing: border-box;
	width: 100%;
	min-height: 44px;
	margin: 0;
}

.vbh-reimb-amount :deep(input),
.vbh-reimb-field :deep(.vbh-num) {
	font-size: 1.15em;
	font-weight: 600;
	text-align: end;
}

.vbh-reimb-iban {
	font-family: var(--font-face-monospace, monospace);
	letter-spacing: 0.04em;
}

.vbh-reimb-iban.is-invalid {
	border-color: var(--color-error);
}

.vbh-reimb-iban-text {
	font-family: var(--font-face-monospace, monospace);
}

.vbh-reimb-help {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.vbh-reimb-help--ok {
	color: var(--color-success-text);
}

.vbh-reimb-help--warn {
	color: var(--color-error-text);
}

.vbh-reimb-actions {
	flex-wrap: wrap;
	align-items: center;
}

/* Was noch fehlt, steht in einer eigenen Zeile über den Knöpfen, damit diese nie gekürzt werden. */
.vbh-reimb-missing {
	flex: 1 0 100%;
	margin-bottom: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

@media (max-width: 520px) {
	.vbh-reimb-row2 {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>
