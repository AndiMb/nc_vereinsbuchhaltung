<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-mandate-reconfirm"
		size="normal"
		:closeOnClickOutside="!sending"
		:noClose="sending"
		@close="close"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-mandate-reconfirm" class="vbh-modal-title">
				{{ t('Zur erneuten Bestätigung anschreiben') }}
			</h2>

			<template v-if="!result">
				<p>
					{{ t('{count} Mitglieder bekommen eine Mail mit einem Link. Der Link öffnet den aktuellen Mandatstext mit den bisherigen Angaben, ein Klick genügt.', { count: recipientCount }) }}
				</p>
				<NcNoteCard type="info">
					{{ t('Das Mandat bleibt währenddessen unverändert gültig und wird weiter eingezogen, auch wenn jemand nicht reagiert. Der Link gilt {days} Tage.', { days: overview.validityDays }) }}
				</NcNoteCard>

				<div v-if="overview.pendingIds.length > 0" class="vbh-reconfirm-pending">
					<NcCheckboxRadioSwitch v-model="includePending" :disabled="sending">
						{{ t('Auch an die {count} erneut schreiben, deren früherer Link noch gilt', { count: overview.pendingIds.length }) }}
					</NcCheckboxRadioSwitch>
					<p class="vbh-hint">
						{{ t('Der frühere Link wird dabei ungültig.') }}
					</p>
				</div>

				<p v-if="sending" class="vbh-hint" role="status">
					{{ t('Mail {done} von {total} wird verschickt …', { done: done, total: recipientCount }) }}
				</p>
				<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
					{{ error }}
				</p>
			</template>

			<template v-else>
				<NcNoteCard :type="result.failed.length > 0 ? 'warning' : 'success'">
					<p>{{ t('Verschickt: {count}', { count: result.sent }) }}</p>
					<p v-if="result.alreadyCurrent > 0">
						{{ t('Nicht nötig, weil inzwischen bestätigt: {count}', { count: result.alreadyCurrent }) }}
					</p>
					<p v-if="result.skipped > 0">
						{{ t('Übersprungen, weil das Mandat nicht mehr wirksam ist: {count}', { count: result.skipped }) }}
					</p>
				</NcNoteCard>
				<div v-if="result.noEmail.length > 0">
					<p><strong>{{ t('Keine Mailadresse:') }}</strong></p>
					<ul class="vbh-reconfirm-list">
						<li v-for="(name, i) in result.noEmail" :key="`n${i}`">
							{{ name }}
						</li>
					</ul>
				</div>
				<div v-if="result.failed.length > 0">
					<p><strong>{{ t('Mail konnte nicht verschickt werden:') }}</strong></p>
					<ul class="vbh-reconfirm-list">
						<li v-for="(f, i) in result.failed" :key="`f${i}`">
							{{ f.name }}<span v-if="f.reason"> – {{ f.reason }}</span>
						</li>
					</ul>
				</div>
				<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
					{{ error }}
				</p>
			</template>

			<div class="vbh-modal-actions">
				<template v-if="!result">
					<NcButton variant="tertiary" :disabled="sending" @click="close">
						{{ t('Abbrechen') }}
					</NcButton>
					<NcButton variant="primary" :disabled="sending || recipientCount === 0" @click="send">
						{{ t('{count} Mails verschicken', { count: recipientCount }) }}
					</NcButton>
				</template>
				<NcButton v-else variant="primary" @click="close">
					{{ t('Schließen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import api from '../api.js'
import { chunkIds, emptyResult, mergeResult, targetIds } from '../lib/mandateReconfirmation.js'
import { saveErrorMessage } from '../lib/sepaSettings.js'

/**
 * Bestätigungsdialog „Zur erneuten Bestätigung anschreiben“ (Rechtstext-
 * Einstellungen): schickt jedem Mitglied mit wirksamem Mandat, das die aktuelle
 * Fassung noch nicht bestätigt hat, eine Mail mit Link. Die Liste kommt aus
 * `overview` (MandateLegalTextController::reconfirmation()); der Versand läuft in
 * Stücken, bricht ein Aufruf ab, bleibt das bis dahin Verschickte stehen.
 */
export default {
	name: 'MandateReconfirmDialog',
	components: { NcModal, NcButton, NcNoteCard, NcCheckboxRadioSwitch },
	props: {
		show: { type: Boolean, default: false },
		/** Antwort von GET /mandate-legal-text/reconfirmation */
		overview: { type: Object, required: true },
	},

	emits: ['close', 'update:show'],

	data() {
		return {
			includePending: false,
			sending: false,
			done: 0,
			result: null,
			error: '',
		}
	},

	computed: {
		ids() { return targetIds(this.overview, this.includePending) },
		recipientCount() { return this.ids.length },
	},

	watch: {
		show(open) {
			if (!open) { return }
			this.includePending = false
			this.sending = false
			this.done = 0
			this.result = null
			this.error = ''
		},
	},

	methods: {
		async send() {
			if (this.sending || this.ids.length === 0) { return }
			this.sending = true
			this.error = ''
			this.done = 0
			let total = emptyResult()
			try {
				for (const part of chunkIds(this.ids, this.overview.batchSize)) {
					const { data } = await api.sendMandateReconfirmation(part)
					total = mergeResult(total, data)
					this.done += part.length
				}
			} catch (e) {
				this.error = saveErrorMessage(e)
			} finally {
				this.sending = false
				this.result = total
			}
		},

		close() {
			if (this.sending) { return }
			this.$emit('close', { sent: this.result?.sent ?? 0 })
		},
	},
}
</script>

<style scoped>
.vbh-reconfirm-pending {
	margin: 12px 0;
}

.vbh-reconfirm-list {
	margin: 0 0 12px;
	padding-inline-start: 20px;
}
</style>
