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

				<div v-if="items.length" class="vbh-tablecard">
					<table class="vbh-table vbh-reimb-table">
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
								<td class="nowrap">
									{{ formatDate(item.expenseDate) }}
								</td>
								<td class="vbh-purpose">
									<span class="vbh-clamp">{{ item.purpose }}</span>
								</td>
								<td class="num strong">
									{{ formatMoney(item.amountCents / 100) }}
								</td>
								<td>
									<ReimbursementStatusTag :status="item.status" />
									<span v-if="progressText(item)" class="vbh-reimb-progress">{{ progressText(item) }}</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<NcEmptyContent
					v-else
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
					{{ t('Auslage einreichen') }}
				</h2>
				<p class="vbh-reimb-intro">
					{{ t('Laden Sie den Beleg hoch und nennen Sie den Betrag. Die Verwalter prüfen den Antrag, danach überweist der Verein.') }}
				</p>

				<form class="vbh-reimb-form" novalidate @submit.prevent="submit">
					<div class="vbh-reimb-row2">
						<label class="vbh-reimb-field">
							<span class="vbh-reimb-label">{{ t('Betrag (€)') }}</span>
							<AmountInput v-model="form.amount" class="vbh-reimb-amount" />
						</label>
						<label class="vbh-reimb-field">
							<span class="vbh-reimb-label">{{ t('Ausgabedatum') }}</span>
							<input v-model="form.expenseDate" type="date" :max="today">
						</label>
					</div>

					<label class="vbh-reimb-field">
						<span class="vbh-reimb-label">{{ t('Verwendungszweck') }}</span>
						<input
							ref="purpose"
							v-model="form.purpose"
							maxlength="500"
							:placeholder="t('z. B. Material für die Jugendfreizeit')">
						<span class="vbh-reimb-help">{{ t('Was wurde gekauft, und wofür?') }}</span>
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

					<div class="vbh-reimb-field">
						<span class="vbh-reimb-label">{{ t('Belege') }}</span>
						<ReimbursementReceiptPicker v-model="form.files" />
					</div>
				</form>

				<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
					{{ error }}
				</p>
				<div class="vbh-modal-actions vbh-reimb-actions">
					<span v-if="missing.length" class="vbh-reimb-missing">{{ t('Noch nötig: {fields}', { fields: missing.join(', ') }) }}</span>
					<NcButton variant="tertiary" @click="closeSubmit">
						{{ t('Abbrechen') }}
					</NcButton>
					<NcButton variant="primary" :disabled="busy || missing.length > 0" @click="submit">
						{{ busy ? t('Wird eingereicht …') : t('Einreichen') }}
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
					<dt>{{ t('Betrag') }}</dt>
					<dd>{{ formatMoney(detail.amountCents / 100) }}</dd>
					<dt>{{ t('Verwendungszweck') }}</dt>
					<dd>{{ detail.purpose }}</dd>
					<dt>{{ t('IBAN') }}</dt>
					<dd>{{ detail.iban }}</dd>
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
							v-if="detail.canEditReceipts && detail.files.length > 1"
							variant="tertiary"
							:aria-label="t('Beleg entfernen')"
							:disabled="busy"
							@click="removeReceipt(file)">
							×
						</NcButton>
					</li>
				</ul>
				<div v-if="detail.canEditReceipts" class="vbh-reimb-addreceipt">
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
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import AmountInput from './AmountInput.vue'
import ReimbursementHistory from './ReimbursementHistory.vue'
import ReimbursementReceiptPicker from './ReimbursementReceiptPicker.vue'
import ReimbursementStatusTag from './ReimbursementStatusTag.vue'
import api from '../api.js'
import { errMsg, formatDate, formatFileSize, formatMoney, roundCents } from '../lib/format.js'
import { formatIban, ibanState, normalizeIban } from '../lib/iban.js'
import { focusOnOpen } from '../lib/modalFocus.js'
import { RECEIPT_ACCEPT } from '../lib/reimbursementFiles.js'
import { reimbursementProgressText } from '../lib/reimbursements.js'

/**
 * „Mein Bereich → Auslagen“ (docs/reimbursement-design.md): die eigenen
 * Anträge, ein neuer Antrag mit Beleg, Zurückziehen und Belege nachreichen.
 * Mitglieder laden nur hoch - den Wächter-Ordner sehen sie nie.
 */
export default {
	name: 'SelfReimbursementPanel',
	components: { NcButton, NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon, NcModal, AmountInput, ReimbursementHistory, ReimbursementReceiptPicker, ReimbursementStatusTag },

	data() {
		return {
			loading: true,
			canSubmit: false,
			suggestedIban: null,
			items: [],
			mdiPlus,
			receiptAccept: RECEIPT_ACCEPT,
			submitOpen: false,
			form: this.emptyForm(),
			detail: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		today() { return new Date().toISOString().slice(0, 10) },

		ibanStatus() { return ibanState(this.form.iban) },

		ibanFromMandate() { return !!this.suggestedIban && normalizeIban(this.form.iban) === normalizeIban(this.suggestedIban) },

		/** Was zum Einreichen noch fehlt, als Liste von Feldnamen – steht neben dem Knopf. */
		missing() {
			const f = this.form
			const missing = []
			if (!(Number(f.amount) > 0)) { missing.push(this.t('Betrag')) }
			if (!f.expenseDate) { missing.push(this.t('Ausgabedatum')) }
			if (f.purpose.trim() === '') { missing.push(this.t('Verwendungszweck')) }
			if (normalizeIban(f.iban).length < 15) { missing.push(this.t('IBAN')) }
			if (f.files.length === 0) { missing.push(this.t('Beleg')) }
			return missing
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		formatDate,
		formatFileSize,
		formatIban,
		formatMoney,

		progressText: reimbursementProgressText,

		emptyForm() {
			return { amount: '', expenseDate: new Date().toISOString().slice(0, 10), purpose: '', iban: '', files: [] }
		},

		async load() {
			try {
				const { data } = await api.selfReimbursements()
				this.canSubmit = !!data.canSubmit
				this.suggestedIban = data.suggestedIban || null
				this.items = data.items
			} catch (e) {
				showError(errMsg(e, this.t('Die Auslagen konnten nicht geladen werden.')))
			} finally {
				this.loading = false
			}
		},

		openSubmit() {
			this.form = this.emptyForm()
			if (this.suggestedIban) { this.form.iban = this.suggestedIban }
			this.error = ''
			this.submitOpen = true
			focusOnOpen(this, () => this.$refs.purpose)
		},

		closeSubmit() { this.submitOpen = false },

		async submit() {
			this.busy = true
			this.error = ''
			try {
				const data = new FormData()
				data.append('amount', String(roundCents(this.form.amount)))
				data.append('expenseDate', this.form.expenseDate)
				data.append('purpose', this.form.purpose.trim())
				data.append('iban', this.form.iban.trim())
				for (const file of this.form.files) { data.append('files[]', file) }
				await api.selfSubmitReimbursement(data)
				showSuccess(this.t('Die Auslage wurde eingereicht.'))
				this.submitOpen = false
				await this.load()
			} catch (e) {
				this.error = errMsg(e, this.t('Die Auslage konnte nicht eingereicht werden.'))
			} finally {
				this.busy = false
			}
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

.vbh-reimb-progress {
	margin-inline-start: 8px;
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
