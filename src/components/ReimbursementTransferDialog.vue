<template>
	<NcModal
		:show="true"
		labelId="vbh-modal-title-reimb-transfer"
		size="normal"
		:closeOnClickOutside="false"
		@close="$emit('close')"
		@update:show="!$event && $emit('close')">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-reimb-transfer" class="vbh-modal-title">
				{{ t('Überweisung vorbereiten') }}
			</h2>

			<NcLoadingIcon v-if="loading" :size="32" />
			<p v-else-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>
			<template v-else>
				<p class="vbh-hint vbh-hint--info" role="note">
					{{ t('Mit der Banking-App den Girocode scannen oder die Angaben kopieren. Die Nummer im Verwendungszweck ordnet den Kontoumsatz später der Auslage zu. Danach hier bestätigen.') }}
				</p>

				<div class="vbh-transfer">
					<div class="vbh-transfer-qr">
						<img
							v-if="data.qrPng"
							:src="`data:image/png;base64,${data.qrPng}`"
							:alt="t('Girocode der Überweisung')"
							width="220"
							height="220">
						<p v-else class="vbh-hint vbh-hint--warning" role="note">
							{{ t('Der Girocode ließ sich nicht erzeugen. Bitte die Angaben von Hand übernehmen.') }}
						</p>
					</div>

					<dl class="vbh-transfer-fields">
						<template v-for="field in fields" :key="field.label">
							<dt>{{ field.label }}</dt>
							<dd>
								<span class="vbh-transfer-value">{{ field.display }}</span>
								<NcButton
									variant="tertiary"
									size="small"
									:aria-label="tRaw('{field} kopieren', { field: field.label })"
									:title="tRaw('{field} kopieren', { field: field.label })"
									@click="copy(field)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiContentCopy" :size="16" />
									</template>
								</NcButton>
							</dd>
						</template>
					</dl>
				</div>
			</template>

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="loading || !!error"
					@click="$emit('done')">
					{{ t('Überweisung abgeschlossen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { mdiContentCopy } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import api from '../api.js'
import { copyText } from '../lib/clipboard.js'
import { errMsg, formatMoney } from '../lib/format.js'
import { formatIban } from '../lib/iban.js'

/**
 * „Überweisung vorbereiten“ (docs/reimbursement-design.md §7): Girocode und
 * kopierbare Überweisungsdaten einer freigegebenen Auslage. Der Server baut
 * Empfänger, IBAN, Betrag und Verwendungszweck einmal, damit Code und Anzeige
 * dasselbe sagen. „Überweisung abgeschlossen“ meldet `done`; der Aufrufer vermerkt
 * die Überweisung am Antrag, gebucht wird mit dem Kontoumsatz.
 */
export default {
	name: 'ReimbursementTransferDialog',
	components: { NcButton, NcIconSvgWrapper, NcLoadingIcon, NcModal },

	props: {
		/** Nummer der Auslage; die Daten holt der Dialog selbst. */
		itemId: { type: Number, required: true },
	},

	emits: ['close', 'done'],

	setup() {
		return { mdiContentCopy }
	},

	data() {
		return { loading: true, error: '', data: null }
	},

	computed: {
		fields() {
			const d = this.data
			return [
				{ label: this.t('Empfänger'), display: d.recipient, copy: d.recipient },
				{ label: this.t('IBAN'), display: formatIban(d.iban), copy: d.iban },
				{ label: this.t('Betrag'), display: formatMoney(d.amountCents / 100), copy: (d.amountCents / 100).toFixed(2).replace('.', ',') },
				{ label: this.t('Verwendungszweck'), display: d.remittance, copy: d.remittance },
			]
		},
	},

	async mounted() {
		try {
			const { data } = await api.reimbursementTransfer(this.itemId)
			this.data = data
		} catch (e) {
			this.error = errMsg(e, this.t('Die Überweisungsdaten konnten nicht geladen werden.'))
		} finally {
			this.loading = false
		}
	},

	methods: {
		async copy(field) {
			if (await copyText(field.copy)) {
				showSuccess(this.tRaw('{field} kopiert.', { field: field.label }))
			} else {
				showError(this.t('Kopieren war nicht möglich. Bitte den Text von Hand markieren.'))
			}
		},
	},
}
</script>

<style scoped>
.vbh-transfer {
	display: flex;
	flex-wrap: wrap;
	gap: 16px 24px;
	align-items: flex-start;
	margin: 12px 0;
}

/* Der Code bleibt auf hellem Grund, auch im dunklen Theme: sonst scannt ihn keine App. */
.vbh-transfer-qr img {
	display: block;
	padding: 8px;
	border-radius: var(--border-radius-large);
	background: #fff;
}

.vbh-transfer-fields {
	flex: 1 1 260px;
	display: grid;
	grid-template-columns: minmax(0, 1fr);
	margin: 0;
}

.vbh-transfer-fields dt {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.vbh-transfer-fields dd {
	display: flex;
	gap: 4px;
	align-items: center;
	justify-content: space-between;
	margin: 0;
}

.vbh-transfer-value {
	overflow-wrap: anywhere;
	font-weight: 600;
	user-select: all;
}
</style>
