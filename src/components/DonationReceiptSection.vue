<template>
	<section v-if="state && state.enabled" class="vbh-akte-section vbh-donation-receipts">
		<h3 class="vbh-modal-subtitle">
			{{ t('Zuwendungsbestätigung') }}
		</h3>
		<p class="vbh-hint">
			{{ t('Amtliche Sammelbestätigung über die in einem Kalenderjahr gezahlten Mitgliedsbeiträge (§ 10b EStG). Das PDF wird als Doppel abgelegt.') }}
		</p>
		<ul v-if="state.problems.length" class="vbh-hint vbh-hint--warning vbh-donation-problems">
			<li v-for="(p, i) in state.problems" :key="i">
				{{ p }}
			</li>
		</ul>

		<div v-if="openYears.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Jahr') }}</th>
						<th class="num">
							{{ t('Gezahlte Beiträge') }}
						</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="y in openYears" :key="y.year">
						<td>{{ y.year }}</td>
						<td class="num">
							{{ formatCents(y.amountCents) }}
						</td>
						<td class="vbh-actions">
							<NcButton
								variant="secondary"
								:disabled="busy || state.problems.length > 0"
								@click="issue(y.year)">
								{{ t('Ausstellen') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<p v-else-if="!state.receipts.length" class="vbh-hint">
			{{ t('Noch keine abgeschlossenen Kalenderjahre mit gezahlten Beiträgen.') }}
		</p>

		<div v-if="state.receipts.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Nummer') }}</th>
						<th>{{ t('Jahr') }}</th>
						<th class="num">
							{{ t('Betrag') }}
						</th>
						<th>{{ t('Status') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="r in state.receipts" :key="r.id" :class="{ 'vbh-muted': r.voidedAt }">
						<td class="nowrap">
							{{ r.number }}
						</td>
						<td>{{ r.year }}</td>
						<td class="num">
							{{ formatCents(r.amountCents) }}
						</td>
						<td>
							<span v-if="r.voidedAt" :title="r.voidReason">{{ t('storniert am {date}', { date: formatDateTime(r.voidedAt) }) }}</span>
							<span v-else-if="r.outdated" class="vbh-text-warning">{{ t('überholt – eine bestätigte Zahlung hat sich geändert') }}</span>
							<span v-else>{{ t('ausgestellt am {date}', { date: formatDateTime(r.issuedAt) }) }}</span>
							<span v-if="r.integrity && r.integrity !== 'ok'" class="vbh-text-warning">
								· {{ r.integrity === 'missing' ? t('Datei fehlt') : t('Datei verändert') }}
							</span>
						</td>
						<td class="vbh-actions">
							<NcButton variant="tertiary" :href="pdfUrl(r.id)" :download="true">
								<template #icon>
									<NcIconSvgWrapper :path="mdiFilePdfBox" :size="20" />
								</template>
								{{ t('PDF') }}
							</NcButton>
							<NcButton
								v-if="!r.voidedAt && voidingId !== r.id"
								variant="tertiary"
								@click="startVoid(r.id)">
								{{ t('Stornieren …') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div v-if="voidingId !== null" class="vbh-form">
			<label for="vbh-donation-void-reason">{{ t('Grund der Stornierung') }}</label>
			<input
				id="vbh-donation-void-reason"
				v-model="voidReason"
				type="text"
				maxlength="500">
			<NcButton variant="error" :disabled="busy || !voidReason.trim()" @click="confirmVoid">
				{{ t('Stornieren') }}
			</NcButton>
			<NcButton variant="tertiary" @click="voidingId = null">
				{{ t('Abbrechen') }}
			</NcButton>
		</div>
		<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
			{{ error }}
		</p>
	</section>
</template>

<script>
import { mdiFilePdfBox } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import api from '../api.js'
import { errMsg, formatDateTime, formatMoney } from '../lib/format.js'

/**
 * Zuwendungsbestätigungen eines Mitglieds in der Akte (Issue #10): ausstellbare
 * Kalenderjahre, ausgestellte Bestätigungen mit PDF und Stornieren. Meldet über
 * `enabled`, ob die Funktion eingeschaltet ist – dann blendet die Akte die
 * informelle Beitragsbestätigung aus.
 */
export default {
	name: 'DonationReceiptSection',
	components: { NcButton, NcIconSvgWrapper },
	props: {
		memberId: { type: Number, required: true },
	},

	emits: ['enabled'],

	data() {
		return {
			mdiFilePdfBox,
			state: null,
			busy: false,
			error: '',
			voidingId: null,
			voidReason: '',
		}
	},

	computed: {
		openYears() {
			return (this.state?.years ?? []).filter((y) => !y.receiptId)
		},
	},

	watch: {
		memberId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	methods: {
		formatDateTime,
		formatCents(cents) {
			return formatMoney(cents / 100)
		},

		pdfUrl(id) {
			return api.donationReceiptPdfUrl(id)
		},

		async load() {
			this.error = ''
			this.voidingId = null
			try {
				const { data } = await api.memberDonationReceipts(this.memberId)
				this.state = data
			} catch {
				this.state = null
			}
			this.$emit('enabled', !!this.state?.enabled)
		},

		async issue(year) {
			this.busy = true
			this.error = ''
			try {
				const { data } = await api.issueDonationReceipt(this.memberId, year)
				showSuccess(this.tRaw('Zuwendungsbestätigung {number} ausgestellt.', { number: data.number }))
				await this.load()
			} catch (e) {
				this.error = errMsg(e, this.t('Die Zuwendungsbestätigung konnte nicht ausgestellt werden.'))
			} finally {
				this.busy = false
			}
		},

		startVoid(id) {
			this.voidingId = id
			this.voidReason = ''
			this.error = ''
		},

		async confirmVoid() {
			this.busy = true
			this.error = ''
			try {
				await api.voidDonationReceipt(this.voidingId, this.voidReason)
				showSuccess(this.t('Zuwendungsbestätigung storniert.'))
				await this.load()
			} catch (e) {
				this.error = errMsg(e, this.t('Die Zuwendungsbestätigung konnte nicht storniert werden.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-donation-problems {
	margin: 0 0 8px;
	padding-inline-start: 20px;
}

.vbh-donation-receipts .vbh-actions {
	text-align: end;
	white-space: nowrap;
}

.vbh-muted {
	opacity: .6;
}

.vbh-text-warning {
	color: var(--color-warning-text);
}
</style>
