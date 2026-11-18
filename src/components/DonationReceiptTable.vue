<template>
	<div class="vbh-donation-receipt-table">
		<div class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Nummer') }}</th>
						<th v-if="showDonor">
							{{ t('Zuwendender') }}
						</th>
						<th>{{ t('Zeitraum') }}</th>
						<th class="num">
							{{ t('Betrag') }}
						</th>
						<th>{{ t('Status') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="r in receipts" :key="r.id" :class="{ 'vbh-muted': r.voidedAt }">
						<td class="nowrap">
							{{ r.number }}
						</td>
						<td v-if="showDonor">
							{{ r.donorName }}
						</td>
						<td>
							<template v-if="r.type === 'einzel'">
								{{ t('Spende vom {date}', { date: formatDate(r.periodFrom) }) }}
							</template>
							<template v-else>
								{{ t('Jahr {year}', { year: r.year }) }}
							</template>
						</td>
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
			<label :for="reasonId">{{ t('Grund der Stornierung') }}</label>
			<input
				:id="reasonId"
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
	</div>
</template>

<script>
import { mdiFilePdfBox } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import api from '../api.js'
import { errMsg, formatDate, formatDateTime, formatMoney } from '../lib/format.js'

let uid = 0

/**
 * Ausgestellte Zuwendungsbestätigungen (Issue #10) mit PDF und Stornieren –
 * in der Akte eines Mitglieds und in der Spendenliste. Meldet `changed`, wenn
 * eine Bestätigung storniert wurde.
 */
export default {
	name: 'DonationReceiptTable',
	components: { NcButton, NcIconSvgWrapper },
	props: {
		receipts: { type: Array, required: true },
		showDonor: { type: Boolean, default: false },
	},

	emits: ['changed'],

	data() {
		uid += 1
		return {
			mdiFilePdfBox,
			reasonId: `vbh-donation-void-reason-${uid}`,
			busy: false,
			error: '',
			voidingId: null,
			voidReason: '',
		}
	},

	methods: {
		formatDate,
		formatDateTime,
		formatCents(cents) {
			return formatMoney(cents / 100)
		},

		pdfUrl(id) {
			return api.donationReceiptPdfUrl(id)
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
				this.voidingId = null
				this.$emit('changed')
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
.vbh-donation-receipt-table .vbh-actions {
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
