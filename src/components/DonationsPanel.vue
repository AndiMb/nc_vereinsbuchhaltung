<template>
	<div class="vbh-donations">
		<div class="vbh-form vbh-form--center">
			<label>{{ t('Jahr') }}
				<select v-model.number="year" @change="load">
					<option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
				</select>
			</label>
			<NcButton variant="secondary" @click="newDonor = true">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" :size="20" />
				</template>
				{{ t('Neuer externer Spender') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading && !state" :size="32" :name="t('Wird geladen…')" />
		<template v-else-if="state">
			<p v-if="!state.accountsConfigured" class="vbh-hint vbh-hint--warning">
				{{ t('Noch keine Spendenkonten festgelegt: Nextcloud-Einstellungen → Vereinsbuchhaltung → Zuwendungsbestätigungen.') }}
			</p>
			<ul v-if="state.problems.length" class="vbh-hint vbh-hint--warning vbh-donation-problems">
				<li v-for="(p, i) in state.problems" :key="i">
					{{ p }}
				</li>
			</ul>

			<h3 class="vbh-donations-heading">
				{{ t('Spenden {year}', { year: state.year }) }}
			</h3>
			<div v-if="state.donations.length" class="vbh-tablecard">
				<table class="vbh-table">
					<thead>
						<tr>
							<th>{{ t('Datum') }}</th>
							<th>{{ t('Buchung') }}</th>
							<th class="num">
								{{ t('Betrag') }}
							</th>
							<th>{{ t('Spender') }}</th>
							<th>{{ t('Verzicht auf Erstattung von Aufwendungen') }}</th>
							<th>{{ t('Bestätigung') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="d in state.donations" :key="d.journalId" :data-id="d.journalId">
							<td class="nowrap">
								{{ formatDate(d.date) }}
							</td>
							<td>{{ d.description }}</td>
							<td class="num">
								{{ formatCents(d.amountCents) }}
							</td>
							<td class="vbh-donor-cell">
								<NcSelect
									:modelValue="donorOption(d.donor)"
									:options="donorOptions"
									:disabled="!canWrite || !!d.receipt || busyId === d.journalId"
									:inputLabel="t('Spender')"
									:labelOutside="true"
									label="label"
									:placeholder="t('– Spender wählen –')"
									@update:modelValue="assign(d, $event, d.waiver)" />
							</td>
							<td>
								<NcCheckboxRadioSwitch
									:modelValue="d.waiver"
									:disabled="!canWrite || !d.donor || !!d.receipt || busyId === d.journalId"
									@update:modelValue="assign(d, donorOption(d.donor), $event)">
									{{ t('ja') }}
								</NcCheckboxRadioSwitch>
							</td>
							<td class="nowrap">
								<span v-if="d.receipt">{{ d.receipt.number }}</span>
								<NcButton
									v-else-if="d.donor && canWrite"
									variant="secondary"
									:disabled="busyId !== null || state.problems.length > 0"
									@click="issueSingle(d)">
									{{ t('Einzeln bestätigen') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<p v-else class="vbh-hint">
				{{ t('In diesem Jahr gibt es keine Buchungen auf den Spendenkonten.') }}
			</p>

			<template v-if="state.donors.length">
				<h3 class="vbh-donations-heading">
					{{ t('Sammelbestätigungen {year}', { year: state.year }) }}
				</h3>
				<p class="vbh-hint">
					{{ state.feesIncluded
						? t('Je Spender alle Zuwendungen des Jahres, die noch nicht einzeln bestätigt sind – bei Mitgliedern auch die gezahlten Beiträge.')
						: t('Je Spender alle Spenden des Jahres, die noch nicht einzeln bestätigt sind.') }}
				</p>
				<div class="vbh-tablecard">
					<table class="vbh-table">
						<thead>
							<tr>
								<th>{{ t('Spender') }}</th>
								<th class="num">
									{{ t('Zuwendungen') }}
								</th>
								<th class="num">
									{{ t('Betrag') }}
								</th>
								<th />
							</tr>
						</thead>
						<tbody>
							<tr v-for="donor in state.donors" :key="donor.kind + donor.id">
								<td>{{ donor.name }}</td>
								<td class="num">
									{{ donor.count }}
								</td>
								<td class="num">
									{{ formatCents(donor.amountCents) }}
								</td>
								<td class="vbh-actions">
									<span v-if="donor.receiptId">{{ t('ausgestellt') }}</span>
									<NcButton
										v-else-if="canWrite"
										variant="secondary"
										:disabled="busyId !== null || state.problems.length > 0"
										@click="issueCollective(donor)">
										{{ t('Ausstellen') }}
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</template>

			<template v-if="state.receipts.length">
				<h3 class="vbh-donations-heading">
					{{ t('Ausgestellte Bestätigungen {year}', { year: state.year }) }}
				</h3>
				<DonationReceiptTable :receipts="state.receipts" :showDonor="true" @changed="load" />
			</template>
		</template>
		<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
			{{ error }}
		</p>

		<DebtorDialog
			v-if="newDonor"
			:show="newDonor"
			@close="newDonor = false"
			@update:show="newDonor = $event"
			@saved="onDonorCreated" />
	</div>
</template>

<script>
import { mdiPlus } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import DebtorDialog from './DebtorDialog.vue'
import DonationReceiptTable from './DonationReceiptTable.vue'
import api from '../api.js'
import { errMsg, formatDate, formatMoney } from '../lib/format.js'

/**
 * Geldspenden (Issue #10, Teil B): Buchungen auf den Spendenkonten eines
 * Jahres, ihre Spender (Mitglied oder Debitor als externer Spender), die
 * Einzelbestätigung einer Spende und die Sammelbestätigungen je Spender.
 */
export default {
	name: 'DonationsPanel',
	components: { DebtorDialog, DonationReceiptTable, NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper, NcLoadingIcon, NcSelect },
	props: {
		canWrite: { type: Boolean, default: false },
	},

	data() {
		const current = new Date().getFullYear()
		return {
			mdiPlus,
			year: current,
			yearOptions: Array.from({ length: 6 }, (_, i) => current - i),
			state: null,
			loading: false,
			error: '',
			busyId: null,
			members: [],
			debtors: [],
			newDonor: false,
		}
	},

	computed: {
		donorOptions() {
			const memberOptions = this.members
				.filter((m) => !m.redactedAt)
				.map((m) => ({ kind: 'member', id: m.id, label: this.tRaw('{name} (Mitglied)', { name: m.displayName }) }))
			const debtorOptions = this.debtors
				.filter((d) => !d.redactedAt)
				.map((d) => ({ kind: 'debtor', id: d.id, label: d.name }))
			return [...memberOptions, ...debtorOptions].sort((a, b) => a.label.localeCompare(b.label))
		},
	},

	async mounted() {
		await Promise.all([this.load(), this.loadDonors()])
	},

	methods: {
		formatDate,
		formatCents(cents) {
			return formatMoney(cents / 100)
		},

		donorOption(donor) {
			if (!donor) {
				return null
			}
			return this.donorOptions.find((o) => o.kind === donor.kind && o.id === donor.id)
				|| { kind: donor.kind, id: donor.id, label: donor.name }
		},

		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await api.donations(this.year)
				this.state = data
			} catch (e) {
				this.error = errMsg(e, this.t('Die Spenden konnten nicht geladen werden.'))
			} finally {
				this.loading = false
			}
		},

		async loadDonors() {
			// Ohne Beitragsmodul gibt es keine Mitgliederliste – dann nur Debitoren.
			const [members, debtors] = await Promise.allSettled([api.listMembers(), api.debtors('')])
			this.members = members.status === 'fulfilled' ? members.value.data : []
			this.debtors = debtors.status === 'fulfilled' ? debtors.value.data : []
		},

		async assign(donation, option, waiver) {
			this.busyId = donation.journalId
			this.error = ''
			try {
				if (option) {
					await api.assignDonation(donation.journalId, {
						memberId: option.kind === 'member' ? option.id : 0,
						debtorId: option.kind === 'debtor' ? option.id : 0,
						waiver: !!waiver,
					})
				} else {
					await api.unassignDonation(donation.journalId)
				}
				await this.load()
			} catch (e) {
				this.error = errMsg(e, this.t('Der Spender konnte nicht gespeichert werden.'))
			} finally {
				this.busyId = null
			}
		},

		async issueSingle(donation) {
			this.busyId = donation.journalId
			this.error = ''
			try {
				const { data } = await api.issueSingleDonationReceipt(donation.journalId)
				showSuccess(this.tRaw('Zuwendungsbestätigung {number} ausgestellt.', { number: data.number }))
				await this.load()
			} catch (e) {
				this.error = errMsg(e, this.t('Die Zuwendungsbestätigung konnte nicht ausgestellt werden.'))
			} finally {
				this.busyId = null
			}
		},

		async issueCollective(donor) {
			this.busyId = -1
			this.error = ''
			try {
				const { data } = donor.kind === 'member'
					? await api.issueDonationReceipt(donor.id, this.state.year)
					: await api.issueDebtorDonationReceipt(donor.id, this.state.year)
				showSuccess(this.tRaw('Zuwendungsbestätigung {number} ausgestellt.', { number: data.number }))
				await this.load()
			} catch (e) {
				this.error = errMsg(e, this.t('Die Zuwendungsbestätigung konnte nicht ausgestellt werden.'))
			} finally {
				this.busyId = null
			}
		},

		async onDonorCreated() {
			this.newDonor = false
			await this.loadDonors()
		},
	},
}
</script>

<style scoped>
.vbh-donations-heading {
	margin: 20px 0 8px;
	font-size: 1.1em;
}

.vbh-donation-problems {
	margin: 8px 0;
	padding-inline-start: 20px;
}

.vbh-donor-cell {
	min-width: 220px;
}

.vbh-donations .vbh-actions {
	text-align: end;
	white-space: nowrap;
}
</style>
