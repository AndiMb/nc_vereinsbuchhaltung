<template>
	<NcModal
		:show="true"
		labelId="vbh-modal-title-reimb-assign"
		size="normal"
		:closeOnClickOutside="false"
		@close="$emit('close')"
		@update:show="!$event && $emit('close')">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-reimb-assign" class="vbh-modal-title">
				{{ t('Umsatz einer Auslage zuordnen') }}
			</h2>
			<p class="vbh-hint vbh-hint--info" role="note">
				{{ tRaw('{counterparty} · {date} · {amount}', { counterparty: tx.counterparty || '–', date: formatDate(tx.bookingDate), amount: formatMoney(tx.amount) }) }}
				<br>{{ tx.purpose }}
			</p>

			<NcLoadingIcon v-if="loading" :size="32" />
			<template v-else>
				<NcEmptyContent
					v-if="!candidates.length"
					:name="t('Keine Auslage zu bezahlen')"
					:description="t('Eine Auslage lässt sich zuordnen, sobald sie freigegeben ist.')" />
				<template v-else>
					<fieldset class="vbh-reimb-pick">
						<legend>{{ t('Auslage') }}</legend>
						<label
							v-for="c in candidates"
							:key="c.id"
							class="vbh-reimb-pick-row"
							:class="{ disabled: !fits(c) }">
							<input
								v-model="reimbursementId"
								type="radio"
								name="vbh-reimb-pick"
								:value="c.id"
								:disabled="!fits(c)">
							<span>
								<strong>{{ c.number || `#${c.id}` }}</strong> · {{ c.memberName }} · {{ formatMoney(c.amountCents / 100) }}
								<small class="vbh-reimb-pick-purpose">{{ c.purpose }}</small>
								<small v-if="!fits(c)" class="vbh-reimb-pick-purpose">{{ t('Der Betrag des Umsatzes passt nicht.') }}</small>
							</span>
						</label>
					</fieldset>

					<label class="vbh-reimb-account">{{ t('Aufwandskonto') }}
						<NcSelect
							v-model="account"
							:options="accountOptions"
							label="label"
							:placeholder="t('Konto wählen')" />
					</label>
					<p class="vbh-hint vbh-hint--info" role="note">
						{{ t('Der Umsatz wird auf dieses Konto gebucht, die Auslage gilt als ausgezahlt und die Belege hängen an der Buchung.') }}
					</p>
				</template>
			</template>

			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="busy || !reimbursementId || !account"
					@click="assign">
					{{ t('Zuordnen und buchen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import api from '../api.js'
import { useAccounts } from '../composables/useAccounts.js'
import { errMsg, formatDate, formatMoney } from '../lib/format.js'

/**
 * „Umsatz einer Auslage zuordnen“ (docs/reimbursement-design.md §7): ein
 * Kontoumsatz (Abgang) wird gegen das gewählte Aufwandskonto gebucht, die
 * Auslage gilt als ausgezahlt. Der Chip unter „Zuzuordnen“ öffnet den Dialog mit
 * der erkannten Auslage; wer eine Überweisung ohne Nummer im Text zuordnen will,
 * wählt die Auslage selbst.
 */
export default {
	name: 'ReimbursementAssignDialog',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcModal, NcSelect },

	props: {
		/** Der Kontoumsatz (Abgang). */
		tx: { type: Object, required: true },
		/** Vorgewählte Auslage, z. B. die erkannte; sonst wählt man selbst. */
		suggestedId: { type: Number, default: null },
	},

	emits: ['assigned', 'close'],

	setup() {
		const accounts = useAccounts()
		return { accountsSorted: accounts.accountsSorted }
	},

	data() {
		return { loading: true, busy: false, error: '', candidates: [], reimbursementId: this.suggestedId, account: null }
	},

	computed: {
		accountOptions() {
			return this.accountsSorted
				.filter((a) => a.type === 'expense' && !a.isBank && a.active !== false)
				.map((a) => ({ id: a.id, label: `${a.number} ${a.name}` }))
		},
	},

	async mounted() {
		try {
			const { data } = await api.reimbursements()
			this.candidates = data.items.filter((i) => i.canPay)
			// Nur vorwählen, was auch passt; sonst bliebe ein gesperrter Eintrag ausgewählt.
			if (!this.candidates.some((c) => c.id === this.reimbursementId && this.fits(c))) { this.reimbursementId = null }
		} catch (e) {
			this.error = errMsg(e, this.t('Die Auslagen konnten nicht geladen werden.'))
		} finally {
			this.loading = false
		}
	},

	methods: {
		formatDate,
		formatMoney,

		fits(candidate) { return candidate.amountCents === Math.abs(this.tx.amountCents) },

		async assign() {
			this.busy = true
			this.error = ''
			try {
				await api.assignReimbursementTransaction(this.reimbursementId, this.tx.id, this.account.id)
				this.$emit('assigned')
			} catch (e) {
				this.error = errMsg(e, this.t('Die Zuordnung zur Auslage ist fehlgeschlagen.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-reimb-pick {
	display: grid;
	gap: 6px;
	margin: 12px 0;
	padding: 0;
	border: 0;
}

.vbh-reimb-pick-row {
	display: flex;
	gap: 8px;
	align-items: flex-start;
}

.vbh-reimb-pick-row.disabled {
	color: var(--color-text-maxcontrast);
}

.vbh-reimb-pick-purpose {
	display: block;
	color: var(--color-text-maxcontrast);
}

.vbh-reimb-account {
	display: block;
	margin: 12px 0;
}
</style>
