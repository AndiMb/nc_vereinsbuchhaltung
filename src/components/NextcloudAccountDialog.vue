<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-nextcloud-account"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-nextcloud-account" class="vbh-modal-title">
				{{ t('Nextcloud-Konto anlegen') }}
			</h2>
			<p class="vbh-hint">
				{{ tRaw('Legt ein Nextcloud-Konto für {name} an und verknüpft es mit der Akte.', { name: memberName }) }}
			</p>
			<NcNoteCard v-if="options && !options.canCreate" type="warning">
				{{ t('Nextcloud-Konten können nur Administratoren und Gruppen-Administratoren anlegen.') }}
			</NcNoteCard>
			<NextcloudAccountFields
				v-else-if="options && account"
				v-model:account="account"
				:options="options" />
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSave || saving" @click="save">
					{{ t('Konto anlegen und verknüpfen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcModal, NcNoteCard } from '@nextcloud/vue'
import NextcloudAccountFields from './NextcloudAccountFields.vue'
import api from '../api.js'
import { accountPayload, emptyAccount, missingAccountFields } from '../lib/accountForm.js'
import { errMsg } from '../lib/format.js'
import { createNextcloudAccount } from '../lib/nextcloudAccount.js'

/**
 * „Nextcloud-Konto anlegen“ für ein schon vorhandenes Mitglied (Akte): dieselben Angaben wie im
 * Aufnahme-Dialog, danach wird das neue Konto mit der Akte verknüpft. Auch der Weg, ein beim
 * Aufnehmen gescheitertes Konto nachzuholen.
 */
export default {
	name: 'NextcloudAccountDialog',
	components: { NcButton, NcModal, NcNoteCard, NextcloudAccountFields },
	props: {
		show: { type: Boolean, default: false },
		/** Das Mitglied der Akte (dekoriert). */
		member: { type: Object, required: true },
	},

	emits: ['close', 'update:show', 'changed'],

	data() {
		return { options: null, account: null, saving: false }
	},

	computed: {
		memberName() { return this.member.displayName },

		canSave() {
			return !!this.options?.canCreate && !!this.account && missingAccountFields(this.account, this.options).length === 0
		},
	},

	watch: {
		async show(open) {
			if (!open) { return }
			this.account = emptyAccount(this.member)
			try {
				const { data } = await api.nextcloudAccountOptions()
				this.options = data
			} catch (e) {
				this.options = null
				showError(errMsg(e, this.t('Die Angaben für das Konto konnten nicht geladen werden')))
			}
		},
	},

	methods: {
		async save() {
			if (!this.canSave) { return }
			this.saving = true
			let created = null
			try {
				created = await createNextcloudAccount(accountPayload(this.account))
				await api.linkMember(this.member.id, created)
				showSuccess(this.t('Nextcloud-Konto angelegt und verknüpft.'))
				this.$emit('changed')
				this.$emit('close')
			} catch (e) {
				if (created !== null) {
					// Das Konto gibt es, nur die Verknüpfung fehlt: in der Akte lässt sie sich über den Vorschlag nachholen.
					showError(errMsg(e, this.tRaw('Das Konto „{uid}" wurde angelegt, die Verknüpfung nicht. Bitte in der Akte verknüpfen.', { uid: created })))
					this.$emit('changed')
				} else {
					showError(errMsg(e, this.t('Das Nextcloud-Konto konnte nicht angelegt werden')))
				}
			} finally {
				this.saving = false
			}
		},
	},
}
</script>
