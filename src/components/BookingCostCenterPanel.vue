<template>
	<div>
		<p class="vbh-hint">
			{{ t('Kostenstellen ordnen einzelne Beträge zu – einer Abteilung, Mannschaft oder einem Projekt. Auswertungsgruppen fassen dagegen ganze Konten zusammen.') }}
		</p>

		<div v-if="canWrite" class="vbh-form vbh-cc-newform">
			<label>{{ t('Kürzel') }}<input
				v-model="newCode"
				class="vbh-short"
				maxlength="8"
				:placeholder="t('z.B. SEN')"></label>
			<label class="vbh-grow">{{ t('Name') }}<input v-model="newName" :placeholder="t('z.B. Senioren')"></label>
			<NcButton variant="primary" :disabled="!newCode.trim() || !newName.trim() || saving" @click="create">
				{{ t('Anlegen') }}
			</NcButton>
		</div>

		<div v-if="bookingCostCenters.length" class="vbh-tablecard">
			<table class="vbh-table vbh-stack">
				<thead>
					<tr>
						<th class="nowrap">
							{{ t('Kürzel') }}
						</th><th>{{ t('Name') }}</th><th class="nowrap">
							{{ t('Aktiv') }}
						</th><th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="cc in bookingCostCenters" :key="cc.id" :class="{ 'vbh-bcc-inactive': !cc.active }">
						<td class="nowrap">
							<input
								:value="cc.code"
								class="vbh-short"
								maxlength="8"
								:disabled="!canWrite"
								:aria-label="tRaw('Kürzel der Kostenstelle {name}', { name: cc.name })"
								@change="change(cc, { code: $event.target.value.trim() }, $event, 'code')">
						</td>
						<td>
							<input
								:value="cc.name"
								class="vbh-rename"
								:disabled="!canWrite"
								:aria-label="tRaw('Name der Kostenstelle {code}', { code: cc.code })"
								@change="change(cc, { name: $event.target.value.trim() }, $event, 'name')">
						</td>
						<td>
							<input
								type="checkbox"
								:checked="cc.active"
								:disabled="!canWrite"
								:aria-label="tRaw('Kostenstelle {code} aktiv', { code: cc.code })"
								@change="change(cc, { active: $event.target.checked }, $event, 'active')">
						</td>
						<td class="nowrap">
							<NcButton
								v-if="canWrite"
								variant="error"
								size="small"
								:aria-label="tRaw('Kostenstelle {code} löschen', { code: cc.code })"
								@click="remove(cc)">
								{{ t('Löschen') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<p v-else class="vbh-empty">
			{{ t('Noch keine Kostenstelle angelegt.') }}
		</p>
		<p v-if="bookingCostCenters.length" class="vbh-hint">
			{{ t('Eine inaktive Kostenstelle steht beim Buchen nicht mehr zur Auswahl; ihre bisherigen Beträge bleiben im Bericht. Löschen geht nur, solange ihr noch kein Betrag zugeordnet ist.') }}
		</p>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { toRefs } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import api from '../api.js'
import { useAuth } from '../composables/useAuth.js'
import { useBookingCostCenters } from '../composables/useBookingCostCenters.js'
import { useConfirm } from '../composables/useConfirm.js'
import { errMsg } from '../lib/format.js'

/**
 * Pflege der Kostenstellen an der Buchungszeile (Issue #7): anlegen,
 * umbenennen, deaktivieren, löschen. Aufgebaut wie CostCenterPanel.vue
 * (Auswertungsgruppen) – Felder speichern beim Verlassen –, geöffnet aus dem
 * Bericht „Kostenstellen" in ReportsTab.vue.
 */
export default {
	name: 'BookingCostCenterPanel',
	components: { NcButton },

	emits: ['changed'],

	setup() {
		const costCenters = useBookingCostCenters()
		return {
			canWrite: useAuth().canWrite,
			...toRefs(costCenters.state),
			loadBookingCostCenters: costCenters.loadBookingCostCenters,
			askConfirm: useConfirm().askConfirm,
		}
	},

	data() {
		return {
			newCode: '',
			newName: '',
			saving: false,
		}
	},

	mounted() {
		this.loadBookingCostCenters()
	},

	methods: {
		errMsg,

		async create() {
			this.saving = true
			try {
				await api.createBookingCostCenter({ code: this.newCode.trim(), name: this.newName.trim() })
				this.newCode = ''
				this.newName = ''
				await this.loadBookingCostCenters()
				this.$emit('changed')
				showSuccess(this.t('Kostenstelle angelegt.'))
			} catch (e) { showError(this.errMsg(e, this.t('Kostenstelle konnte nicht angelegt werden'))) } finally { this.saving = false }
		},

		/**
		 * Kürzel, Name oder Aktiv-Kennzeichen ändern (beim Verlassen des Feldes).
		 * Scheitert das Speichern, springt das Feld auf den bisherigen Wert zurück.
		 *
		 * @param {object} cc die Kostenstelle
		 * @param {object} patch geänderte Felder
		 * @param {Event} event change-Event des Eingabefelds
		 * @param {string} field 'code', 'name' oder 'active'
		 */
		async change(cc, patch, event, field) {
			const next = { code: cc.code, name: cc.name, active: cc.active, ...patch }
			if (next.code === cc.code && next.name === cc.name && next.active === cc.active) { return }
			try {
				await api.updateBookingCostCenter(cc.id, next)
				await this.loadBookingCostCenters()
				this.$emit('changed')
			} catch (e) {
				showError(this.errMsg(e, this.t('Kostenstelle konnte nicht gespeichert werden')))
				if (field === 'active') { event.target.checked = cc.active } else { event.target.value = cc[field] }
			}
		},

		async remove(cc) {
			if (!await this.askConfirm(this.t('Kostenstelle löschen'), this.tRaw('Kostenstelle „{code} {name}" löschen?', { code: cc.code, name: cc.name }))) { return }
			try {
				await api.deleteBookingCostCenter(cc.id)
				await this.loadBookingCostCenters()
				this.$emit('changed')
				showSuccess(this.t('Kostenstelle gelöscht.'))
			} catch (e) { showError(this.errMsg(e, this.t('Löschen fehlgeschlagen'))) }
		},
	},
}
</script>

<style scoped>
.vbh-bcc-inactive input:not([type="checkbox"]) { color: var(--color-text-maxcontrast); }
</style>
