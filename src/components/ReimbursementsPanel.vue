<template>
	<div class="vbh-reimb-staff">
		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else>
			<input
				v-model="search"
				type="search"
				class="vbh-search vbh-reimb-search"
				:placeholder="t('Suche (Zweck, Name, Betrag)…')">
			<div class="vbh-reimb-chips" role="group" :aria-label="t('Status')">
				<button
					v-for="f in filterOptions"
					:key="f.key"
					type="button"
					class="vbh-chip"
					:class="{ active: statusFilter === f.key }"
					@click="statusFilter = f.key">
					{{ f.label }}<span class="vbh-chip-count">{{ f.count }}</span>
				</button>
			</div>

			<div v-if="filteredItems.length" class="vbh-tablecard">
				<table class="vbh-table vbh-stack">
					<thead>
						<tr>
							<th class="nowrap">
								{{ t('Eingereicht') }}
							</th>
							<th>{{ t('Mitglied') }}</th>
							<th>
								{{ t('Verwendungszweck') }}
							</th>
							<th class="num">
								{{ t('Betrag') }}
							</th>
							<th>{{ t('Status') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="item in filteredItems"
							:key="item.id"
							class="vbh-reimb-row"
							tabindex="0"
							@click="openDetail(item.id)"
							@keydown.enter="openDetail(item.id)">
							<td class="nowrap vbh-stack-eyebrow">
								{{ formatDate(item.submittedAt) }}
							</td>
							<td class="vbh-stack-title">
								{{ item.memberName }}
							</td>
							<td class="vbh-purpose vbh-stack-title">
								<span class="vbh-clamp">{{ item.purpose }}</span>
							</td>
							<td class="num strong">
								{{ formatMoney(item.amountCents / 100) }}
							</td>
							<td>
								<span class="vbh-reimb-status">
									<ReimbursementStatusTag :status="item.status" />
									<span v-if="progressText(item)" class="vbh-reimb-progress">{{ progressText(item) }}</span>
									<span v-if="item.canApprove" class="vbh-reimb-todo">{{ t('Ihre Freigabe') }}</span>
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<NcEmptyContent
				v-else
				:name="t('Keine Auslagen')"
				:description="items.length ? t('Mit diesem Filter gibt es keine Treffer.') : t('Sobald ein Mitglied eine Auslage einreicht, erscheint sie hier.')" />
		</template>

		<!-- Einzelansicht -->
		<NcModal
			v-if="detail"
			:show="!!detail"
			labelId="vbh-modal-title-reimb-staff"
			size="large"
			@close="detail = null"
			@update:show="detail = $event ? detail : null">
			<div class="vbh-modal-inner">
				<h2 id="vbh-modal-title-reimb-staff" class="vbh-modal-title">
					{{ tRaw('Auslage #{id} von {name}', { id: detail.id, name: detail.memberName }) }}
				</h2>
				<dl class="vbh-selfservice-fields">
					<dt>{{ t('Betrag') }}</dt>
					<dd>{{ formatMoney(detail.amountCents / 100) }}</dd>
					<dt>{{ t('Rechnungsdatum') }}</dt>
					<dd>{{ formatDate(detail.expenseDate) }}</dd>
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
					<dd class="vbh-reimb-iban">
						{{ formatIban(detail.iban || detail.ibanMasked) }}
					</dd>
					<dt>{{ t('Status') }}</dt>
					<dd>
						<ReimbursementStatusTag :status="detail.status" />
						<span v-if="progressText(detail)" class="vbh-reimb-progress">{{ progressText(detail) }}</span>
					</dd>
					<template v-if="detail.rejectionReason">
						<dt>{{ t('Begründung') }}</dt>
						<dd>{{ detail.rejectionReason }}</dd>
					</template>
					<template v-if="detail.paidAt">
						<dt>{{ t('Überwiesen am') }}</dt>
						<dd>{{ formatDate(detail.paidAt) }}</dd>
					</template>
				</dl>

				<p v-if="detail.duplicateHint" class="vbh-hint vbh-hint--warning" role="note">
					{{ t('Mindestens ein Beleg dieses Antrags liegt schon in einem anderen Antrag vor. Bitte prüfen, ob die Auslage doppelt eingereicht wurde.') }}
				</p>
				<p v-if="detail.ownRequest && detail.openForDecision" class="vbh-hint vbh-hint--info" role="note">
					{{ t('Das ist Ihr eigener Antrag. Freigeben können ihn nur andere Verwalter.') }}
				</p>
				<p v-else-if="detail.alreadyApproved && detail.openForDecision" class="vbh-hint vbh-hint--info" role="note">
					{{ t('Sie haben diesen Antrag bereits freigegeben.') }}
				</p>

				<h4 class="vbh-selfservice-subhead">
					{{ t('Belege') }}
				</h4>
				<ul class="vbh-reimb-files">
					<li v-for="file in detail.files" :key="file.id">
						<a :href="receiptUrl(file)" target="_blank" rel="noopener">{{ file.fileName }}</a>
						<span class="vbh-reimb-progress">{{ formatFileSize(file.fileSize) }}</span>
					</li>
				</ul>

				<h4 class="vbh-selfservice-subhead">
					{{ t('Verlauf') }}
				</h4>
				<ReimbursementHistory :events="detail.history" :staff="true" />

				<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
					{{ error }}
				</p>
				<div class="vbh-modal-actions">
					<NcButton variant="tertiary" @click="detail = null">
						{{ t('Schließen') }}
					</NcButton>
					<NcButton
						v-if="detail.canReject"
						variant="error"
						:disabled="busy"
						@click="openReject">
						{{ t('Ablehnen') }}
					</NcButton>
					<NcButton
						v-if="detail.canApprove"
						variant="primary"
						:disabled="busy"
						@click="approve">
						{{ t('Freigeben') }}
					</NcButton>
					<NcButton
						v-if="detail.canPay && canWrite"
						variant="primary"
						:disabled="busy"
						@click="requestPay">
						{{ t('Auszahlung buchen') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- Ablehnen -->
		<NcModal
			v-if="rejectOpen"
			:show="rejectOpen"
			labelId="vbh-modal-title-reimb-reject"
			size="normal"
			:closeOnClickOutside="false"
			@close="rejectOpen = false"
			@update:show="rejectOpen = $event">
			<div class="vbh-modal-inner">
				<h2 id="vbh-modal-title-reimb-reject" class="vbh-modal-title">
					{{ t('Auslage ablehnen') }}
				</h2>
				<p class="vbh-hint vbh-hint--warning">
					{{ t('Die Ablehnung gilt sofort und endgültig, eine zweite Freigabe ist nicht nötig. Das Mitglied bekommt die Begründung per Mail.') }}
				</p>
				<label>{{ t('Begründung (Pflicht)') }}
					<textarea
						ref="reason"
						v-model="reason"
						class="vbh-note-textarea"
						rows="3"
						maxlength="500" />
				</label>
				<div class="vbh-modal-actions">
					<NcButton variant="tertiary" @click="rejectOpen = false">
						{{ t('Abbrechen') }}
					</NcButton>
					<NcButton variant="error" :disabled="busy || reason.trim().length < 3" @click="reject">
						{{ t('Ablehnen') }}
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
import ReimbursementHistory from './ReimbursementHistory.vue'
import ReimbursementStatusTag from './ReimbursementStatusTag.vue'
import api from '../api.js'
import { errMsg, formatDate, formatFileSize, formatMoney } from '../lib/format.js'
import { formatIban } from '../lib/iban.js'
import { focusOnOpen } from '../lib/modalFocus.js'
import {
	countByStatus,
	matchesReimbursementSearch,
	REIMBURSEMENT_STATUSES,
	reimbursementProgressText,
	reimbursementStatusLabel,
} from '../lib/reimbursements.js'

/**
 * „Buchungen → Auslagen“ (docs/reimbursement-design.md §10): alle Anträge mit
 * Freigabe-Warteschlange, Auszahlung und Historie. Freigeben und Ablehnen
 * zeigt die Ansicht nur Verwaltern; ob jemand ausgerechnet diesen Antrag
 * freigeben darf (nicht den eigenen, nicht doppelt), sagt der Server mit
 * `canApprove`/`canReject`. Die Auszahlung öffnet den Buchungsdialog (App.vue).
 */
export default {
	name: 'ReimbursementsPanel',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcModal, ReimbursementHistory, ReimbursementStatusTag },

	props: {
		canWrite: { type: Boolean, default: false },
		/** Hochzählen = neu laden (z. B. nach der Auszahlungsbuchung). */
		reloadKey: { type: Number, default: 0 },
	},

	emits: ['pay'],

	data() {
		return {
			loading: true,
			items: [],
			search: '',
			statusFilter: 'todo',
			detail: null,
			busy: false,
			error: '',
			rejectOpen: false,
			reason: '',
		}
	},

	computed: {
		filterOptions() {
			const counts = countByStatus(this.items)
			const todo = this.items.filter((i) => i.canApprove).length
			return [
				{ key: 'todo', label: this.t('Zu prüfen'), count: todo },
				{ key: 'all', label: this.t('Alle'), count: this.items.length },
				...REIMBURSEMENT_STATUSES.map((status) => ({ key: status, label: reimbursementStatusLabel(status), count: counts[status] || 0 })),
			]
		},

		filteredItems() {
			return this.items.filter((item) => {
				if (this.statusFilter === 'todo' && !item.canApprove) { return false }
				if (this.statusFilter !== 'todo' && this.statusFilter !== 'all' && item.status !== this.statusFilter) { return false }
				return matchesReimbursementSearch(item, this.search)
			})
		},
	},

	watch: {
		reloadKey() { this.load() },
	},

	async mounted() {
		await this.load()
		// „Zu prüfen“ ist nur dann der richtige Einstieg, wenn es etwas zu prüfen gibt.
		if (!this.items.some((i) => i.canApprove)) { this.statusFilter = 'all' }
	},

	methods: {
		formatDate,
		formatFileSize,
		formatIban,
		formatMoney,
		progressText: reimbursementProgressText,

		async load() {
			try {
				const { data } = await api.reimbursements()
				this.items = data.items
			} catch (e) {
				showError(errMsg(e, this.t('Die Auslagen konnten nicht geladen werden.')))
			} finally {
				this.loading = false
			}
		},

		async fetchDetail(id) {
			const { data } = await api.reimbursement(id)
			return data
		},

		async openDetail(id) {
			this.error = ''
			try {
				this.detail = await this.fetchDetail(id)
			} catch (e) {
				showError(errMsg(e, this.t('Der Antrag konnte nicht geladen werden.')))
			}
		},

		receiptUrl(file) { return api.reimbursementReceiptUrl(this.detail.id, file.id) },

		/** Führt eine Entscheidung aus und zeigt danach den neuen Stand. */
		async decide(call, success, failure) {
			this.busy = true
			this.error = ''
			try {
				const { data } = await call()
				this.detail = data
				showSuccess(success)
				await this.load()
				return true
			} catch (e) {
				this.error = errMsg(e, failure)
				// Der Stand kann sich inzwischen geändert haben (zweiter Verwalter war schneller).
				await this.load()
				this.detail = await this.fetchDetail(this.detail.id).catch(() => null)
				return false
			} finally {
				this.busy = false
			}
		},

		approve() {
			return this.decide(() => api.approveReimbursement(this.detail.id), this.t('Freigabe erteilt.'), this.t('Die Freigabe war nicht möglich.'))
		},

		openReject() {
			this.reason = ''
			this.rejectOpen = true
			focusOnOpen(this, () => this.$refs.reason)
		},

		async reject() {
			const done = await this.decide(() => api.rejectReimbursement(this.detail.id, this.reason.trim()), this.t('Antrag abgelehnt.'), this.t('Die Ablehnung war nicht möglich.'))
			if (done) { this.rejectOpen = false }
		},

		requestPay() {
			const item = this.detail
			this.detail = null
			this.$emit('pay', item)
		},
	},
}
</script>

<style scoped>
.vbh-reimb-search {
	margin: 12px 0 0;
}

/* Die Filter bleiben eine Zeile, am Handy wischt man seitlich – statt dass jede Gruppe eine eigene Zeile füllt. */
.vbh-reimb-chips {
	display: flex;
	gap: 8px;
	margin: 12px 0;
	padding-bottom: 4px;
	overflow-x: auto;
	scrollbar-width: thin;
}

.vbh-reimb-chips .vbh-chip {
	flex: none;
}

.vbh-reimb-row {
	cursor: pointer;
}

.vbh-reimb-status {
	display: inline-flex;
	flex-wrap: wrap;
	gap: 4px 8px;
	align-items: center;
	justify-content: flex-end;
}

.vbh-reimb-progress {
	margin-inline-start: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

/* „Wartet auf Sie": auffällig genug zum Finden, aber kein Alarm. */
.vbh-reimb-todo {
	display: inline-block;
	padding: 1px 8px;
	border-radius: 10px;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 0.82em;
	font-weight: 600;
	white-space: nowrap;
}

.vbh-reimb-iban {
	font-family: var(--font-face-monospace, monospace);
}

.vbh-reimb-files {
	display: grid;
	gap: 4px;
	margin: 8px 0;
	padding: 0;
	list-style: none;
}
</style>
