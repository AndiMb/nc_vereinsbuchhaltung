<template>
	<div>
		<h3 class="vbh-section-divider">Steuerliche Sphären</h3>
		<p class="vbh-hint">
			Ordnet Einnahmen-/Ausgaben-Konten einer Sphäre zu (ideeller Bereich, Vermögensverwaltung, Zweckbetrieb,
			wirtschaftlicher Geschäftsbetrieb) – wichtig für die Gemeinnützigkeit und die Freigrenze des
			wirtschaftlichen Geschäftsbetriebs. Ersetzt keine steuerliche Beratung.
			<button type="button" class="vbh-sphere-help" title="Was bedeutet das?" @click="$emit('help')">?</button>
		</p>

		<div v-if="relevantAccounts.length === 0" class="vbh-hint">Noch keine Einnahmen-/Ausgaben-Konten vorhanden.</div>
		<template v-else>
			<div class="vbh-sphere-bulkbar">
				<label class="vbh-checkinline">
					<input type="checkbox" :checked="allSelected" @change="toggleAll($event.target.checked)">
					{{ selected.length }} von {{ relevantAccounts.length }} ausgewählt
				</label>
				<select v-model="bulkSphere">
					<option value="">– Sphäre wählen –</option>
					<option value="ideell">Ideeller Bereich</option>
					<option value="vermoegensverwaltung">Vermögensverwaltung</option>
					<option value="zweckbetrieb">Zweckbetrieb</option>
					<option value="wirtschaftlich">Wirtschaftlicher Geschäftsbetrieb</option>
				</select>
				<NcButton variant="primary" :disabled="!selected.length || !bulkSphere || bulkSaving" @click="applyBulk">
					Zuweisen
				</NcButton>
				<NcButton variant="tertiary" :disabled="bulkSaving" @click="showSuggestions = !showSuggestions">
					{{ showSuggestions ? 'Vorschläge ausblenden' : 'Vorschläge für unzugeordnete Konten anzeigen' }}
				</NcButton>
			</div>

			<div class="vbh-tablecard">
				<table class="vbh-table">
					<thead><tr><th></th><th class="nowrap">Nr.</th><th>Konto</th><th>Sphäre</th></tr></thead>
					<tbody>
						<tr v-for="a in relevantAccounts" :key="a.id">
							<td><input type="checkbox" :checked="selected.includes(a.id)" @change="toggleOne(a.id, $event.target.checked)"></td>
							<td class="nowrap">{{ a.number }}</td>
							<td>{{ a.name }}</td>
							<td>
								<select :value="a.sphere || ''" @change="saveOne(a, $event.target.value)">
									<option value="">– nicht zugeordnet –</option>
									<option value="ideell">Ideeller Bereich</option>
									<option value="vermoegensverwaltung">Vermögensverwaltung</option>
									<option value="zweckbetrieb">Zweckbetrieb</option>
									<option value="wirtschaftlich">Wirtschaftlicher Geschäftsbetrieb</option>
								</select>
								<span v-if="showSuggestions && !a.sphere && suggestSphere(a.name)" class="vbh-sphere-suggest">
									Vorschlag: {{ sphereLabel(suggestSphere(a.name)) }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../api.js'
import { errMsg } from '../lib/format.js'

const LABELS = {
	ideell: 'Ideeller Bereich',
	vermoegensverwaltung: 'Vermögensverwaltung',
	zweckbetrieb: 'Zweckbetrieb',
	wirtschaftlich: 'Wirtschaftlicher Geschäftsbetrieb',
}

export default {
	name: 'SettingsSpheres',
	components: { NcButton },
	props: {
		// Alle Konten (Parent lädt sie ohnehin) – hier nur Einnahmen-/Ausgaben-Konten relevant.
		accounts: { type: Array, required: true },
	},
	data() {
		return {
			selected: [],
			bulkSphere: '',
			bulkSaving: false,
			showSuggestions: false,
		}
	},
	computed: {
		// Entspricht Account::isResultRelevant() im Backend: alles außer Geldkonten und Eigenkapital.
		relevantAccounts() {
			return this.accounts.filter(a => a.type !== 'equity' && !a.isBank)
		},
		allSelected() {
			return this.relevantAccounts.length > 0 && this.selected.length === this.relevantAccounts.length
		},
	},
	methods: {
		errMsg,
		sphereLabel(code) { return LABELS[code] || code },
		// Grobe Namensheuristik als Vorschlag – keine Garantie, muss geprüft werden (siehe Hinweistext oben).
		suggestSphere(name) {
			const n = (name || '').toLowerCase()
			if (/mitgliedsbeitr|spende|zuschuss|förder|fördermittel/.test(n)) return 'ideell'
			if (/zins|miete|pacht|kapitalertrag|geldanlage/.test(n)) return 'vermoegensverwaltung'
			if (/konzert|veranstaltung|kurs|eintritt|sportver/.test(n)) return 'zweckbetrieb'
			if (/gaststätte|werbung|sponsoring|verkauf|kiosk|bandenwerbung|stand/.test(n)) return 'wirtschaftlich'
			return null
		},
		toggleAll(checked) {
			this.selected = checked ? this.relevantAccounts.map(a => a.id) : []
		},
		toggleOne(id, checked) {
			if (checked) { if (!this.selected.includes(id)) this.selected.push(id) } else { this.selected = this.selected.filter(x => x !== id) }
		},
		async applyBulk() {
			if (!this.selected.length || !this.bulkSphere) return
			this.bulkSaving = true
			try {
				const { data } = await api.bulkSphere(this.selected, this.bulkSphere)
				showSuccess(`${data.updated} Konten der Sphäre „${this.sphereLabel(this.bulkSphere)}" zugeordnet.`)
				this.selected = []
				this.bulkSphere = ''
				this.$emit('changed')
			} catch (e) { showError(this.errMsg(e, 'Zuordnung fehlgeschlagen')) } finally { this.bulkSaving = false }
		},
		async saveOne(account, sphere) {
			try {
				await api.updateAccount(account.id, {
					number: account.number, name: account.name, type: account.type,
					category: account.category || null, isBank: account.isBank,
					parentId: account.parentId || 0, sphere,
				})
				this.$emit('changed')
			} catch (e) { showError(this.errMsg(e, 'Sphäre konnte nicht gespeichert werden')) }
		},
	},
}
</script>
