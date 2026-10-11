<template>
	<div class="vbh-account-fields">
		<NcTextField
			:modelValue="account.userId"
			:label="options.generateUserId ? t('Kontoname (Nextcloud vergibt ihn selbst)') : t('Kontoname')"
			:disabled="options.generateUserId"
			@update:modelValue="set('userId', $event)" />
		<NcTextField
			:modelValue="account.displayName"
			:label="t('Anzeigename')"
			@update:modelValue="set('displayName', $event)" />
		<NcTextField
			:modelValue="account.email"
			type="email"
			:label="emailRequired ? t('E-Mail (erforderlich)') : t('E-Mail')"
			@update:modelValue="set('email', $event)" />
		<NcPasswordField
			:modelValue="account.password"
			:label="t('Passwort (optional)')"
			autocomplete="new-password"
			@update:modelValue="set('password', $event)" />
		<p class="vbh-hint">
			{{ passwordHint }}
		</p>
		<NcSelect
			:modelValue="account.groups"
			:options="options.groups"
			:multiple="true"
			:keepOpen="true"
			:reduce="(group) => group.id"
			label="displayName"
			:inputLabel="options.groupRequired ? t('Gruppen (mindestens eine)') : t('Gruppen')"
			:placeholder="t('Gruppen wählen …')"
			@update:modelValue="set('groups', $event)" />
		<NcSelect
			:modelValue="account.quota"
			:options="quotaOptions"
			:clearable="false"
			:reduce="(option) => option.id"
			label="label"
			:inputLabel="t('Speicherplatz')"
			@update:modelValue="set('quota', $event)" />
		<NcSelect
			:modelValue="account.language"
			:options="languageOptions"
			:clearable="false"
			:reduce="(option) => option.id"
			label="label"
			:inputLabel="t('Sprache')"
			@update:modelValue="set('language', $event)" />
	</div>
</template>

<script>
import { NcPasswordField, NcSelect, NcTextField } from '@nextcloud/vue'
import { DEFAULT_CHOICE } from '../lib/accountForm.js'

/**
 * Die Felder von „Nextcloud-Konto anlegen“ – dieselben Angaben, die Nextcloud selbst in der
 * Benutzerverwaltung fragt: Kontoname, Anzeigename, E-Mail, Passwort (oder Einladung per Mail),
 * Gruppen, Speicherplatz und Sprache. Der Zustand gehört dem Aufrufer (`account`, siehe
 * lib/accountForm.js); die Auswahlen und Rechte kommen von `GET /nextcloud-account/options`.
 */
export default {
	name: 'NextcloudAccountFields',
	components: { NcPasswordField, NcSelect, NcTextField },
	props: {
		account: { type: Object, required: true },
		options: { type: Object, required: true },
	},

	emits: ['update:account'],

	computed: {
		emailRequired() {
			return this.options.requireEmail || this.account.password === ''
		},

		passwordHint() {
			if (this.account.password !== '') {
				return this.t('Das Mitglied meldet sich mit diesem Passwort an.')
			}
			if (this.options.sendInvitation) {
				return this.t('Ohne Passwort bekommt das Mitglied von Nextcloud eine Einladung per Mail – mit einem Link, um das Passwort selbst zu setzen.')
			}
			return this.t('Ohne Passwort verschickt Nextcloud keine Einladung (die Instanz ist so eingestellt); das Mitglied kann sich erst nach dem Setzen eines Passworts anmelden.')
		},

		quotaOptions() {
			const standard = this.options.defaultQuota === 'none' ? this.t('unbegrenzt') : this.options.defaultQuota
			return [
				{ id: DEFAULT_CHOICE, label: this.t('Standard ({quota})', { quota: standard }) },
				...this.options.quotaPresets.map((preset) => ({ id: preset, label: preset })),
				{ id: 'none', label: this.t('Unbegrenzt') },
			]
		},

		languageOptions() {
			return [
				{ id: DEFAULT_CHOICE, label: this.t('Standard der Instanz') },
				...this.options.languages.map((language) => ({ id: language.code, label: language.name })),
			]
		},
	},

	methods: {
		set(field, value) {
			this.$emit('update:account', { ...this.account, [field]: value })
		},
	},
}
</script>

<style scoped>
.vbh-account-fields {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
}

.vbh-account-fields .vbh-hint {
	margin: calc(var(--default-grid-baseline, 4px) * -2) 0 0;
}
</style>
