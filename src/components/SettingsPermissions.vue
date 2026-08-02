<template>
	<div>
		<h3 class="vbh-section-divider">
			{{ t('Berechtigungen') }}
		</h3>
		<p class="vbh-hint">
			<strong>{{ t('Verwalter') }}</strong> {{ t('dürfen alles inkl. Rechtevergabe,') }}
			<strong>{{ t('Buchhalter') }}</strong> {{ t('lesen und schreiben,') }}
			<strong>{{ t('Revisor') }}</strong> {{ t('nur lesen. Nextcloud-Administratoren sind immer Verwalter.') }}
		</p>

		<div class="vbh-card">
			<h4>{{ t('Neue Berechtigung') }}</h4>
			<div class="vbh-form">
				<label>{{ t('Typ') }}
					<select v-model="permForm.principalType">
						<option value="group">{{ t('Gruppe') }}</option>
						<option value="user">{{ t('Nutzer') }}</option>
					</select>
				</label>
				<label class="vbh-grow">{{ permForm.principalType === 'group' ? t('Gruppe') : t('Nutzer') }}
					<NcSelect v-model="permFormPrincipalOption"
						:options="permForm.principalType === 'group' ? groupOptions : userOptions"
						label="label"
						:placeholder="permForm.principalType === 'group' ? t('– Gruppe wählen –') : t('– Nutzer wählen –')" />
				</label>
				<label>{{ t('Rolle') }}
					<select v-model="permForm.role">
						<option value="revisor">{{ t('Revisor (nur lesen)') }}</option>
						<option value="buchhalter">{{ t('Buchhalter (lesen+schreiben)') }}</option>
						<option value="verwalter">{{ t('Verwalter (alles)') }}</option>
					</select>
				</label>
				<NcButton variant="primary" @click="savePermission">
					{{ t('Hinzufügen') }}
				</NcButton>
			</div>
		</div>

		<div v-if="permissions.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead><tr><th>{{ t('Typ') }}</th><th>{{ t('Nutzer / Gruppe') }}</th><th>{{ t('Rolle') }}</th><th /></tr></thead>
				<tbody>
					<tr v-for="p in permissions" :key="p.id">
						<td>{{ p.principalType === 'group' ? t('Gruppe') : t('Nutzer') }}</td>
						<td>{{ p.principalId }}</td>
						<td><span class="vbh-typetag">{{ roleLabel(p.role) }}</span></td>
						<td class="right">
							<NcButton variant="error" :aria-label="t('Berechtigung entfernen')" @click="removePermission(p)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiDelete" :size="20" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<NcEmptyContent v-else :name="t('Keine Berechtigungen')" :description="t('Nextcloud-Administratoren haben immer Zugriff.')">
			<template #action>
				<NcButton variant="tertiary" @click="$emit('help')">
					{{ t('Mehr dazu') }}
				</NcButton>
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { toRefs } from 'vue'
import { NcButton, NcSelect, NcEmptyContent, NcIconSvgWrapper } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { mdiDelete } from '@mdi/js'
import api from '../api.js'
import { roleLabel, errMsg } from '../lib/format.js'
import { usePermissions } from '../composables/usePermissions.js'
import { useConfirm } from '../composables/useConfirm.js'

export default {
	name: 'SettingsPermissions',
	components: { NcButton, NcSelect, NcEmptyContent, NcIconSvgWrapper },
	props: {
		// Bestaetigungsdialog des Elternteils (gibt Promise<boolean> zurueck)
	},
	setup() {
		// permissions/groups/users kommen direkt aus dem usePermissions-Singleton
		// (gleicher geteilter Zustand wie in App.vue, kein Prop-Drilling noetig).
		const permissions = usePermissions()
		return {
			...toRefs(permissions.state),
			loadPermissions: permissions.loadPermissions,
			askConfirm: useConfirm().askConfirm,
		}
	},
	data() {
		return {
			mdiDelete,
			permForm: { principalType: 'group', principalId: '', role: 'revisor' },
		}
	},
	computed: {
		groupOptions() { return this.groups.map(g => ({ id: g.id, label: g.displayName })) },
		userOptions() { return this.users.map(u => ({ id: u.id, label: `${u.displayName} (${u.id})` })) },
		permFormPrincipalOption: {
			get() {
				const list = this.permForm.principalType === 'group' ? this.groupOptions : this.userOptions
				return list.find(o => o.id === this.permForm.principalId) ?? null
			},
			set(v) { this.permForm.principalId = v ? v.id : '' },
		},
	},
	methods: {
		roleLabel,
		errMsg,
		async savePermission() {
			if (!this.permForm.principalId) { showError(this.t('Bitte Nutzer oder Gruppe angeben.')); return }
			try {
				await api.setPermission(this.permForm)
				this.permForm = { principalType: 'group', principalId: '', role: 'revisor' }
				await this.loadPermissions()
				showSuccess(this.t('Berechtigung gespeichert.'))
			} catch (e) { showError(this.errMsg(e, this.t('Speichern fehlgeschlagen'))) }
		},
		async removePermission(p) {
			if (!await this.askConfirm(this.t('Berechtigung entfernen'), this.t('Berechtigung für "{id}" entfernen?', { id: p.principalId }))) return
			try { await api.deletePermission(p.id); await this.loadPermissions() } catch (e) { showError(this.errMsg(e, this.t('Entfernen fehlgeschlagen'))) }
		},
	},
}
</script>
