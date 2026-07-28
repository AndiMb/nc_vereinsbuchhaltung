<template>
	<div>
		<h3 class="vbh-section-divider">
			Berechtigungen
		</h3>
		<p class="vbh-hint">
			<strong>Verwalter</strong> dürfen alles inkl. Rechtevergabe, <strong>Buchhalter</strong> lesen und schreiben,
			<strong>Revisor</strong> nur lesen. Nextcloud-Administratoren sind immer Verwalter.
		</p>

		<div class="vbh-card">
			<h4>Neue Berechtigung</h4>
			<div class="vbh-form">
				<label>Typ
					<select v-model="permForm.principalType">
						<option value="group">Gruppe</option>
						<option value="user">Nutzer</option>
					</select>
				</label>
				<label class="vbh-grow">{{ permForm.principalType === 'group' ? 'Gruppe' : 'Nutzer' }}
					<NcSelect v-model="permFormPrincipalOption"
						:options="permForm.principalType === 'group' ? groupOptions : userOptions"
						label="label"
						:placeholder="permForm.principalType === 'group' ? '– Gruppe wählen –' : '– Nutzer wählen –'" />
				</label>
				<label>Rolle
					<select v-model="permForm.role">
						<option value="revisor">Revisor (nur lesen)</option>
						<option value="buchhalter">Buchhalter (lesen+schreiben)</option>
						<option value="verwalter">Verwalter (alles)</option>
					</select>
				</label>
				<NcButton variant="primary" @click="savePermission">
					Hinzufügen
				</NcButton>
			</div>
		</div>

		<div v-if="permissions.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead><tr><th>Typ</th><th>Nutzer / Gruppe</th><th>Rolle</th><th /></tr></thead>
				<tbody>
					<tr v-for="p in permissions" :key="p.id">
						<td>{{ p.principalType === 'group' ? 'Gruppe' : 'Nutzer' }}</td>
						<td>{{ p.principalId }}</td>
						<td><span class="vbh-typetag">{{ roleLabel(p.role) }}</span></td>
						<td class="right">
							<NcButton variant="error" aria-label="Berechtigung entfernen" @click="removePermission(p)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiDelete" :size="20" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<NcEmptyContent v-else name="Keine Berechtigungen" description="Nextcloud-Administratoren haben immer Zugriff.">
			<template #action>
				<NcButton variant="tertiary" @click="$emit('help')">
					Mehr dazu
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

export default {
	name: 'SettingsPermissions',
	components: { NcButton, NcSelect, NcEmptyContent, NcIconSvgWrapper },
	props: {
		// Bestaetigungsdialog des Elternteils (gibt Promise<boolean> zurueck)
		askConfirm: { type: Function, required: true },
	},
	setup() {
		// permissions/groups/users kommen direkt aus dem usePermissions-Singleton
		// (gleicher geteilter Zustand wie in App.vue, kein Prop-Drilling noetig).
		const permissions = usePermissions()
		return {
			...toRefs(permissions.state),
			loadPermissions: permissions.loadPermissions,
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
			if (!this.permForm.principalId) { showError('Bitte Nutzer oder Gruppe angeben.'); return }
			try {
				await api.setPermission(this.permForm)
				this.permForm = { principalType: 'group', principalId: '', role: 'revisor' }
				await this.loadPermissions()
				showSuccess('Berechtigung gespeichert.')
			} catch (e) { showError(this.errMsg(e, 'Speichern fehlgeschlagen')) }
		},
		async removePermission(p) {
			if (!await this.askConfirm('Berechtigung entfernen', `Berechtigung für "${p.principalId}" entfernen?`)) return
			try { await api.deletePermission(p.id); await this.loadPermissions() } catch (e) { showError(this.errMsg(e, 'Entfernen fehlgeschlagen')) }
		},
	},
}
</script>
