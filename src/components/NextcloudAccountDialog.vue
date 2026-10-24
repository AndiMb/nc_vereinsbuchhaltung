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
				{{ tRaw('Legt ein Nextcloud-Konto für {name} an und verknüpft es mit der Akte.', { name: member.displayName }) }}
			</p>
			<NcNoteCard v-if="options && !options.canCreate" type="warning">
				{{ t('Nextcloud-Konten können nur Administratoren und Gruppen-Administratoren anlegen.') }}
			</NcNoteCard>
			<NextcloudAccountFields
				v-else-if="options && account"
				ref="fields"
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
import { focusOnOpen } from '../lib/modalFocus.js'
import { confirmAccountCreation, createAndLinkAccount } from '../lib/nextcloudAccount.js'

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
		canSave() {
			return !!this.options?.canCreate && !!this.account && missingAccountFields(this.account, this.options).length === 0
		},
	},

	watch: {
		// immediate: MemberDialog hängt den Dialog erst beim Öffnen ein (v-if), show ist dann schon true.
		show: {
			immediate: true,
			handler: 'load',
		},
	},

	methods: {
		async load(open) {
			if (!open) { return }
			this.account = emptyAccount(this.member)
			try {
				const { data } = await api.nextcloudAccountOptions()
				this.options = data
				// Die Felder gibt es erst jetzt: der Cursor kommt ins erste (siehe NcModal-Fokus-Race in modalFocus.js).
				focusOnOpen(this, () => this.$refs.fields?.focus())
			} catch (e) {
				this.options = null
				showError(errMsg(e, this.t('Die Angaben für das Konto konnten nicht geladen werden')))
			}
		},

		async save() {
			if (!this.canSave) { return }
			this.saving = true
			try {
				// Abgebrochene Passwortbestätigung: der Dialog bleibt offen, nichts ist angelegt.
				if (!await confirmAccountCreation()) { return }
				await createAndLinkAccount(this.member.id, accountPayload(this.account))
				showSuccess(this.t('Nextcloud-Konto angelegt und verknüpft.'))
				this.$emit('changed')
				this.$emit('close')
			} catch (e) {
				if (e.createdUid) {
					// Das Konto gibt es, nur die Verknüpfung fehlt: in der Akte lässt sie sich über den Vorschlag nachholen.
					showError(this.tRaw('Das Nextcloud-Konto „{uid}" wurde angelegt, die Verknüpfung mit der Akte nicht.', { uid: e.createdUid }))
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
