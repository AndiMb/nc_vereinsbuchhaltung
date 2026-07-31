<template>
	<div>
		<h3 class="vbh-section-divider">
			Kostenstellen
		</h3>
		<p class="vbh-hint">
			Kostenstellen bündeln Konten zu Projekten, Abteilungen oder Veranstaltungen – der Bericht
			<em>Berichte → Kostenstellen</em> zeigt dann Einnahmen, Ausgaben und Ergebnis je Kostenstelle.
			Hier angelegte Kostenstellen wertet die App aus, sobald der Modus
			<em>„Frei definierte Kostenstellen"</em> eingestellt ist.
		</p>
		<p v-if="mode !== 'manual'" class="vbh-hint vbh-cc-modewarn">
			Zurzeit ist der Modus <strong>{{ modeLabel }}</strong> eingestellt. Die Zuordnung unten wird dann
			<strong>nicht</strong> ausgewertet; die Namen der Kostenstellen gelten aber weiterhin.
			Umstellen lässt sich das weiter unten unter <em>Allgemein</em> (nur Verwalter).
		</p>

		<div class="vbh-form vbh-cc-newform">
			<label>Kürzel<input v-model="newCode"
				class="vbh-short"
				maxlength="8"
				placeholder="z.B. 51"></label>
			<label class="vbh-grow">Name<input v-model="newName" placeholder="z.B. Sommerfest"></label>
			<NcButton variant="primary" :disabled="!newCode.trim() || !newName.trim() || saving" @click="createCostCenter">
				Anlegen
			</NcButton>
		</div>

		<div v-if="costCenters.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th class="nowrap">
							Kürzel
						</th><th>Name</th><th class="num nowrap">
							Konten
						</th><th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="cc in costCenters" :key="cc.id">
						<td class="nowrap">
							<input :value="cc.code"
								class="vbh-short"
								maxlength="8"
								@change="rename(cc, 'code', $event)">
						</td>
						<td>
							<input :value="cc.name"
								class="vbh-rename"
								@change="rename(cc, 'name', $event)">
						</td>
						<td class="num">
							{{ accountCount(cc.id) }}
						</td>
						<td class="nowrap">
							<NcButton variant="error"
								size="small"
								aria-label="Kostenstelle löschen"
								@click="remove(cc)">
								Löschen
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<p v-else class="vbh-hint">
			Noch keine Kostenstelle angelegt.
		</p>

		<template v-if="costCenters.length && relevantAccounts.length">
			<h4>Konten zuordnen</h4>
			<div class="vbh-sphere-bulkbar">
				<label class="vbh-checkinline">
					<input type="checkbox" :checked="allSelected" @change="toggleAll($event.target.checked)">
					{{ selected.length }} von {{ relevantAccounts.length }} ausgewählt
				</label>
				<select v-model="bulkTarget">
					<option value="">
						– Kostenstelle wählen –
					</option>
					<option v-for="cc in costCenters" :key="cc.id" :value="String(cc.id)">
						{{ cc.code }} · {{ cc.name }}
					</option>
					<option value="0">
						– Zuordnung aufheben –
					</option>
				</select>
				<NcButton variant="primary" :disabled="!selected.length || bulkTarget === '' || saving" @click="applyBulk">
					Zuweisen
				</NcButton>
			</div>

			<div class="vbh-tablecard">
				<table class="vbh-table">
					<thead>
						<tr>
							<th /><th class="nowrap">
								Nr.
							</th><th>Konto</th><th>Kostenstelle</th>
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
										– nicht zugeordnet –
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
import { toRefs } from 'vue'
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { useAccounts } from '../composables/useAccounts.js'
import { useCostCenters } from '../composables/useCostCenters.js'

const MODE_LABELS = {
	group: '2. Zahlengruppe der Kontonummer',
	account: 'jedes Konto eine eigene Kostenstelle',
	manual: 'frei definierte Kostenstellen',
}

/**
 * Pflege der frei definierbaren Kostenstellen: anlegen, umbenennen, löschen und
 * Konten zuordnen. Die Zuordnung wirkt im Bericht nur im Modus „manual" – der
 * Hinweis oben sagt das, statt die Maske zu verstecken: wer die Zuordnung
 * vorbereiten will, bevor umgestellt wird, soll das können.
 */
export default {
	name: 'SettingsCostCenters',
	components: { NcButton },
	props: {
		// Kostenstellen-Modus aus den Einstellungen (group|account|manual)
		mode: { type: String, default: 'group' },
		askConfirm: { type: Function, required: true },
	},
	setup() {
		const accounts = useAccounts()
		const costCenters = useCostCenters()
		return {
			...toRefs(accounts.state),
			...toRefs(costCenters.state),
			loadCostCenters: costCenters.loadCostCenters,
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
		modeLabel() { return MODE_LABELS[this.mode] || this.mode },
		// Entspricht Account::isResultRelevant() im Backend: nur diese Konten
		// tauchen in der Kostenstellen-Auswertung überhaupt auf.
		relevantAccounts() {
			return this.accounts
				.filter(a => a.type !== 'equity' && !a.isBank)
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
			return this.accounts.filter(a => a.costCenterId === costCenterId).length
		},
		async createCostCenter() {
			this.saving = true
			try {
				await api.createCostCenter(this.newCode.trim(), this.newName.trim())
				this.newCode = ''
				this.newName = ''
				await this.loadCostCenters()
				this.$emit('changed')
				showSuccess('Kostenstelle angelegt.')
			} catch (e) { showError(this.errMsg(e, 'Kostenstelle konnte nicht angelegt werden')) } finally { this.saving = false }
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
			if (next.code === cc.code && next.name === cc.name) return
			try {
				await api.updateCostCenter(cc.id, next.code, next.name)
				await this.loadCostCenters()
				this.$emit('changed')
			} catch (e) {
				showError(this.errMsg(e, 'Kostenstelle konnte nicht gespeichert werden'))
				// Das Feld steht sonst weiter auf dem abgelehnten Wert – Vue
				// gleicht es nicht ab, weil sich der gebundene Wert nicht ändert.
				event.target.value = cc[field]
			}
		},
		async remove(cc) {
			const count = this.accountCount(cc.id)
			const hint = count ? ` Die Zuordnung von ${count} Konto/Konten wird gelöst; Buchungen bleiben unverändert.` : ''
			if (!await this.askConfirm('Kostenstelle löschen', `Kostenstelle „${cc.code} ${cc.name}" löschen?${hint}`)) return
			try {
				await api.deleteCostCenter(cc.id)
				await this.loadCostCenters()
				this.$emit('changed')
				showSuccess('Kostenstelle gelöscht.')
			} catch (e) { showError(this.errMsg(e, 'Löschen fehlgeschlagen')) }
		},
		toggleAll(checked) {
			this.selected = checked ? this.relevantAccounts.map(a => a.id) : []
		},
		toggleOne(id, checked) {
			if (checked) { if (!this.selected.includes(id)) this.selected.push(id) } else { this.selected = this.selected.filter(x => x !== id) }
		},
		async applyBulk() {
			if (!this.selected.length || this.bulkTarget === '') return
			this.saving = true
			try {
				const { data } = await api.assignCostCenter(this.selected, Number(this.bulkTarget))
				this.selected = []
				this.bulkTarget = ''
				this.$emit('changed')
				showSuccess(`${data.updated} Konten zugeordnet.`)
			} catch (e) { showError(this.errMsg(e, 'Zuordnung fehlgeschlagen')) } finally { this.saving = false }
		},
		async assignOne(account, value) {
			try {
				await api.assignCostCenter([account.id], Number(value || 0))
				this.$emit('changed')
			} catch (e) { showError(this.errMsg(e, 'Zuordnung fehlgeschlagen')) }
		},
	},
}
</script>
