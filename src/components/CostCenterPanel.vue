<template>
	<div>
		<p class="vbh-hint">
			{{ t('Kostenstellen bündeln Konten zu Projekten, Abteilungen oder Veranstaltungen – der Bericht zeigt dann Einnahmen, Ausgaben und Ergebnis je Kostenstelle. Hier angelegte Kostenstellen wertet die App aus, sobald der Modus') }}
			<em>{{ t('„Frei definierte Kostenstellen"') }}</em> {{ t('eingestellt ist.') }}
		</p>

		<p v-if="mode !== 'manual'" class="vbh-hint vbh-cc-modewarn">
			{{ t('Zurzeit ist der Modus') }} <strong>{{ modeLabel }}</strong> {{ t('eingestellt. Die Zuordnung unten wird dann') }}
			<strong>{{ t('nicht') }}</strong> {{ t('ausgewertet; die Namen der Kostenstellen gelten aber weiterhin.') }}
			{{ isAdmin ? t('Umstellen geht oben in der Berichtszeile (Gruppierung).') : t('Nur ein Verwalter kann das umstellen.') }}
		</p>

		<div class="vbh-form vbh-cc-newform">
			<label>{{ t('Kürzel') }}<input
				v-model="newCode"
				class="vbh-short"
				maxlength="8"
				:placeholder="t('z.B. 51')"></label>
			<label class="vbh-grow">{{ t('Name') }}<input v-model="newName" :placeholder="t('z.B. Sommerfest')"></label>
			<NcButton variant="primary" :disabled="!newCode.trim() || !newName.trim() || saving" @click="createCostCenter">
				{{ t('Anlegen') }}
			</NcButton>
		</div>

		<div v-if="costCenters.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th class="nowrap">
							{{ t('Kürzel') }}
						</th><th>{{ t('Name') }}</th><th class="num nowrap">
							{{ t('Konten') }}
						</th><th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="cc in costCenters" :key="cc.id">
						<td class="nowrap">
							<input
								:value="cc.code"
								class="vbh-short"
								maxlength="8"
								@change="rename(cc, 'code', $event)">
						</td>
						<td>
							<input
								:value="cc.name"
								class="vbh-rename"
								@change="rename(cc, 'name', $event)">
						</td>
						<td class="num">
							{{ accountCount(cc.id) }}
						</td>
						<td class="nowrap">
							<NcButton
								variant="error"
								size="small"
								:aria-label="t('Kostenstelle löschen')"
								@click="remove(cc)">
								{{ t('Löschen') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<p v-else class="vbh-hint">
			{{ t('Noch keine Kostenstelle angelegt.') }}
		</p>

		<template v-if="costCenters.length && relevantAccounts.length">
			<h4>{{ t('Konten zuordnen') }}</h4>
			<div class="vbh-sphere-bulkbar">
				<label class="vbh-checkinline">
					<input type="checkbox" :checked="allSelected" @change="toggleAll($event.target.checked)">
					{{ t('{selected} von {total} ausgewählt', { selected: selected.length, total: relevantAccounts.length }) }}
				</label>
				<select v-model="bulkTarget">
					<option value="">
						{{ t('– Kostenstelle wählen –') }}
					</option>
					<option v-for="cc in costCenters" :key="cc.id" :value="String(cc.id)">
						{{ cc.code }} · {{ cc.name }}
					</option>
					<option value="0">
						{{ t('– Zuordnung aufheben –') }}
					</option>
				</select>
				<NcButton variant="primary" :disabled="!selected.length || bulkTarget === '' || saving" @click="applyBulk">
					{{ t('Zuweisen') }}
				</NcButton>
			</div>

			<div class="vbh-tablecard">
				<table class="vbh-table">
					<thead>
						<tr>
							<th /><th class="nowrap">
								{{ t('Nr.') }}
							</th><th>{{ t('Konto') }}</th><th>{{ t('Kostenstelle') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="a in relevantAccounts" :key="a.id">
							<td><input type="checkbox" :checked="selected.includes(a.id)" @change="toggleOne(a.id, $event.target.checked)"></td>
							<td class="nowrap">
								{{ a.number }}
							</td>
							<td>{{ a.name }}</td>
							<td>
								<select :value="a.costCenterId ? String(a.costCenterId) : ''" @change="assignOne(a, $event.target.value)">
									<option value="">
										{{ t('– nicht zugeordnet –') }}
									</option>
									<option v-for="cc in costCenters" :key="cc.id" :value="String(cc.id)">
										{{ cc.code }} · {{ cc.name }}
									</option>
								</select>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import { toRefs } from 'vue'
import api from '../api.js'
import { useAccounts } from '../composables/useAccounts.js'
import { useAuth } from '../composables/useAuth.js'
import { useConfirm } from '../composables/useConfirm.js'
import { useCostCenters } from '../composables/useCostCenters.js'
import { errMsg } from '../lib/format.js'
import { t } from '../lib/l10n.js'

// Als Funktion statt Modul-Konstante (siehe HelpModal.vue/SphereAssignPanel.vue
// fuer die Begruendung: t() darf erst beim Aufruf laufen, nicht beim Import).
function modeLabels() {
	return {
		group: t('2. Zahlengruppe der Kontonummer'),
		account: t('jedes Konto eine eigene Kostenstelle'),
		manual: t('frei definierte Kostenstellen'),
	}
}

/**
 * Pflege der frei definierbaren Kostenstellen: anlegen, umbenennen, löschen
 * und Konten zuordnen. Frueher SettingsCostCenters.vue im Einstellungen-
 * Modal; jetzt ein Modal, das aus dem Bericht „Kostenstellen" (ReportsTab.vue)
 * heraus geoeffnet wird, siehe NAVIGATION-KONZEPT.md Abschnitt 4 und 5 – wo
 * die fehlende Zuordnung sichtbar wird, wird sie auch geschlossen. Der
 * Kostenstellen-Modus (group|account|manual) ist kein Stammdatum dieses
 * Panels mehr, sondern eine Bericht-Steuerung in der Kopfzeile von
 * ReportsTab.vue (Gruppierungs-Waehler) - er entscheidet, was der Bericht
 * ueberhaupt zeigt, und musste deshalb sichtbar bleiben, waehrend die Pflege
 * hier ins Modal wandern konnte.
 *
 * Die Zuordnung wirkt im Bericht nur im Modus „manual" – der Hinweis oben
 * sagt das, statt die Maske zu verstecken: wer die Zuordnung vorbereiten
 * will, bevor umgestellt wird, soll das können. Modus-Wechsel bleibt
 * Verwalter-only (jetzt in ReportsTab.vue), Anlegen/Zuordnen bleibt fuer
 * canWrite (Buchhalter und Verwalter).
 */
export default {
	name: 'CostCenterPanel',
	components: { NcButton },
	props: {
		// Kostenstellen-Modus aus den Einstellungen (group|account|manual) - nur
		// fuer den Hinweistext oben, die Auswahl steht in ReportsTab.vue.
		mode: { type: String, default: 'group' },
	},

	emits: ['changed'],

	setup() {
		const auth = useAuth()
		const accounts = useAccounts()
		const costCenters = useCostCenters()
		return {
			isAdmin: auth.isAdmin,
			...toRefs(accounts.state),
			...toRefs(costCenters.state),
			loadCostCenters: costCenters.loadCostCenters,
			askConfirm: useConfirm().askConfirm,
		}
	},

	data() {
		return {
			newCode: '',
			newName: '',
			selected: [],
			bulkTarget: '',
			saving: false,
		}
	},

	computed: {
		modeLabel() { return modeLabels()[this.mode] || this.mode },

		// Entspricht Account::isResultRelevant() im Backend: nur diese Konten
		// tauchen in der Kostenstellen-Auswertung überhaupt auf.
		relevantAccounts() {
			return this.accounts
				.filter((a) => a.type !== 'equity' && !a.isBank)
				.slice()
				.sort((a, b) => String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
		},

		allSelected() {
			return this.relevantAccounts.length > 0 && this.selected.length === this.relevantAccounts.length
		},
	},

	methods: {
		errMsg,
		accountCount(costCenterId) {
			return this.accounts.filter((a) => a.costCenterId === costCenterId).length
		},

		async createCostCenter() {
			this.saving = true
			try {
				await api.createCostCenter(this.newCode.trim(), this.newName.trim())
				this.newCode = ''
				this.newName = ''
				await this.loadCostCenters()
				this.$emit('changed')
				showSuccess(this.t('Kostenstelle angelegt.'))
			} catch (e) { showError(this.errMsg(e, this.t('Kostenstelle konnte nicht angelegt werden'))) } finally { this.saving = false }
		},

		/**
		 * Kürzel oder Name einer Kostenstelle ändern (beim Verlassen des Feldes).
		 *
		 * @param {object} cc die Kostenstelle
		 * @param {string} field 'code' oder 'name'
		 * @param {Event} event change-Event des Eingabefelds
		 */
		async rename(cc, field, event) {
			const next = { code: cc.code, name: cc.name, [field]: String(event.target.value).trim() }
			if (next.code === cc.code && next.name === cc.name) { return }
			try {
				await api.updateCostCenter(cc.id, next.code, next.name)
				await this.loadCostCenters()
				this.$emit('changed')
			} catch (e) {
				showError(this.errMsg(e, this.t('Kostenstelle konnte nicht gespeichert werden')))
				// Das Feld steht sonst weiter auf dem abgelehnten Wert – Vue
				// gleicht es nicht ab, weil sich der gebundene Wert nicht ändert.
				event.target.value = cc[field]
			}
		},

		async remove(cc) {
			const count = this.accountCount(cc.id)
			const hint = count ? ' ' + this.t('Die Zuordnung von {count} Konto/Konten wird gelöst; Buchungen bleiben unverändert.', { count }) : ''
			if (!await this.askConfirm(this.t('Kostenstelle löschen'), this.t('Kostenstelle „{code} {name}" löschen?', { code: cc.code, name: cc.name }) + hint)) { return }
			try {
				await api.deleteCostCenter(cc.id)
				await this.loadCostCenters()
				this.$emit('changed')
				showSuccess(this.t('Kostenstelle gelöscht.'))
			} catch (e) { showError(this.errMsg(e, this.t('Löschen fehlgeschlagen'))) }
		},

		toggleAll(checked) {
			this.selected = checked ? this.relevantAccounts.map((a) => a.id) : []
		},

		toggleOne(id, checked) {
			if (checked) { if (!this.selected.includes(id)) { this.selected.push(id) } } else { this.selected = this.selected.filter((x) => x !== id) }
		},

		async applyBulk() {
			if (!this.selected.length || this.bulkTarget === '') { return }
			this.saving = true
			try {
				const { data } = await api.assignCostCenter(this.selected, Number(this.bulkTarget))
				this.selected = []
				this.bulkTarget = ''
				this.$emit('changed')
				showSuccess(this.t('{count} Konten zugeordnet.', { count: data.updated }))
			} catch (e) { showError(this.errMsg(e, this.t('Zuordnung fehlgeschlagen'))) } finally { this.saving = false }
		},

		async assignOne(account, value) {
			try {
				await api.assignCostCenter([account.id], Number(value || 0))
				this.$emit('changed')
			} catch (e) { showError(this.errMsg(e, this.t('Zuordnung fehlgeschlagen'))) }
		},
	},
}
</script>
