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
			<div class="vbh-modal-inner">
				<h2 id="vbh-modal-title-reimb-submit" class="vbh-modal-title">
					{{ t('Auslage einreichen') }}
				</h2>
				<div class="vbh-form-stack">
					<div class="vbh-form">
						<label>{{ t('Betrag (€)') }}
							<AmountInput v-model="form.amount" class="vbh-num" />
						</label>
						<label>{{ t('Ausgabedatum') }}
							<input v-model="form.expenseDate" type="date" :max="today">
						</label>
					</div>
					<label>{{ t('Verwendungszweck') }}
						<input
							ref="purpose"
							v-model="form.purpose"
							maxlength="500"
							:placeholder="t('z. B. Material für die Jugendfreizeit')">
					</label>
					<label>{{ t('IBAN für die Erstattung') }}
						<input
							v-model="form.iban"
							autocomplete="off"
							spellcheck="false"
							placeholder="DE00 0000 0000 0000 0000 00">
					</label>
					<label>{{ t('Beleg (PDF oder Foto)') }}
						<input
							type="file"
							multiple
							accept="application/pdf,image/jpeg,image/png,image/gif,image/webp"
							@change="onFiles">
					</label>
					<ul v-if="form.files.length" class="vbh-reimb-files">
						<li v-for="(file, i) in form.files" :key="file.name + i">
							{{ file.name }} <span class="vbh-reimb-progress">{{ formatFileSize(file.size) }}</span>
							<NcButton variant="tertiary" :aria-label="t('Datei entfernen')" @click="form.files.splice(i, 1)">
								×
							</NcButton>
						</li>
					</ul>
				</div>
				<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
					{{ error }}
				</p>
				<div class="vbh-modal-actions">
					<NcButton variant="tertiary" @click="closeSubmit">
						{{ t('Abbrechen') }}
					</NcButton>
					<NcButton variant="primary" :disabled="busy || !submitReady" @click="submit">
						{{ t('Einreichen') }}
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
				<label v-if="detail.canEditReceipts" class="vbh-reimb-addreceipt">{{ t('Weiteren Beleg hochladen') }}
					<input
						type="file"
						accept="application/pdf,image/jpeg,image/png,image/gif,image/webp"
						:disabled="busy"
						@change="addReceipt">
				</label>

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
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import AmountInput from './AmountInput.vue'
import ReimbursementHistory from './ReimbursementHistory.vue'
import ReimbursementStatusTag from './ReimbursementStatusTag.vue'
import api from '../api.js'
import { errMsg, formatDate, formatFileSize, formatMoney, roundCents } from '../lib/format.js'
import { focusOnOpen } from '../lib/modalFocus.js'
import { reimbursementProgressText } from '../lib/reimbursements.js'

/**
 * „Mein Bereich → Auslagen“ (docs/reimbursement-design.md): die eigenen
 * Anträge, ein neuer Antrag mit Beleg, Zurückziehen und Belege nachreichen.
 * Mitglieder laden nur hoch - den Wächter-Ordner sehen sie nie.
 */
export default {
	name: 'SelfReimbursementPanel',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcModal, AmountInput, ReimbursementHistory, ReimbursementStatusTag },

	data() {
		return {
			loading: true,
			canSubmit: false,
			suggestedIban: null,
			items: [],
			submitOpen: false,
			form: this.emptyForm(),
			detail: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		today() { return new Date().toISOString().slice(0, 10) },

		submitReady() {
			const f = this.form
			return Number(f.amount) > 0 && !!f.expenseDate && f.purpose.trim() !== '' && f.iban.trim() !== '' && f.files.length > 0
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		formatDate,
		formatFileSize,
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

		onFiles(event) {
			this.form.files.push(...Array.from(event.target.files || []))
			// Dieselbe Datei soll sich nach dem Entfernen erneut wählen lassen.
			event.target.value = ''
		},

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
.vbh-selfservice-card-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }

.vbh-reimb-row { cursor: pointer; }

.vbh-reimb-progress { margin-inline-start: 8px; color: var(--color-text-maxcontrast); font-size: 0.9em; }

.vbh-reimb-files { list-style: none; margin: 8px 0; padding: 0; display: grid; gap: 4px; }

.vbh-reimb-files li { display: flex; align-items: center; gap: 4px; }

.vbh-reimb-addreceipt { display: block; margin-top: 8px; }
</style>
