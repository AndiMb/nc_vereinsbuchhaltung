<template>
	<div>
		<p class="vbh-hint">
			{{ t('Ordnet Einnahmen-/Ausgaben-Konten einer Sphäre zu (ideeller Bereich, Vermögensverwaltung, Zweckbetrieb, wirtschaftlicher Geschäftsbetrieb) – wichtig für die Gemeinnützigkeit und die Freigrenze des wirtschaftlichen Geschäftsbetriebs. Ersetzt keine steuerliche Beratung.') }}
			<button
				type="button"
				class="vbh-sphere-help"
				:title="t('Was bedeutet das?')"
				@click="$emit('help')">
				?
			</button>
		</p>

		<div v-if="relevantAccounts.length === 0" class="vbh-hint">
			{{ t('Noch keine Einnahmen-/Ausgaben-Konten vorhanden.') }}
		</div>
		<template v-else>
			<div class="vbh-sphere-bulkbar">
				<label class="vbh-checkinline">
					<input type="checkbox" :checked="allSelected" @change="toggleAll($event.target.checked)">
					{{ t('{selected} von {total} ausgewählt', { selected: selected.length, total: relevantAccounts.length }) }}
				</label>
				<select v-model="bulkSphere">
					<option value="">
						{{ t('– Sphäre wählen –') }}
					</option>
					<option value="ideell">
						{{ t('Ideeller Bereich') }}
					</option>
					<option value="vermoegensverwaltung">
						{{ t('Vermögensverwaltung') }}
					</option>
					<option value="zweckbetrieb">
						{{ t('Zweckbetrieb') }}
					</option>
					<option value="wirtschaftlich">
						{{ t('Wirtschaftlicher Geschäftsbetrieb') }}
					</option>
				</select>
				<NcButton variant="primary" :disabled="!selected.length || !bulkSphere || bulkSaving" @click="applyBulk">
					{{ t('Zuweisen') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="bulkSaving" @click="showSuggestions = !showSuggestions">
					{{ showSuggestions ? t('Vorschläge ausblenden') : t('Vorschläge für unzugeordnete Konten anzeigen') }}
				</NcButton>
			</div>

			<div class="vbh-tablecard">
				<table class="vbh-table">
					<thead>
						<tr>
							<th class="vbh-col-check" /><th class="nowrap">
								{{ t('Nr.') }}
							</th><th>{{ t('Konto') }}</th><th>{{ t('Sphäre') }}</th>
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
								<select :value="a.sphere || ''" @change="saveOne(a, $event.target.value)">
									<option value="">
										{{ t('– nicht zugeordnet –') }}
									</option>
									<option value="ideell">
										{{ t('Ideeller Bereich') }}
									</option>
									<option value="vermoegensverwaltung">
										{{ t('Vermögensverwaltung') }}
									</option>
									<option value="zweckbetrieb">
										{{ t('Zweckbetrieb') }}
									</option>
									<option value="wirtschaftlich">
										{{ t('Wirtschaftlicher Geschäftsbetrieb') }}
									</option>
								</select>
								<span v-if="showSuggestions && !a.sphere && suggestSphere(a.name)" class="vbh-sphere-suggest">
									{{ t('Vorschlag: {sphere}', { sphere: sphereLabel(suggestSphere(a.name)) }) }}
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
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import { toRefs } from 'vue'
import api from '../api.js'
import { useAccounts } from '../composables/useAccounts.js'
import { errMsg } from '../lib/format.js'
import { t } from '../lib/l10n.js'

// Als Funktion statt Modul-Konstante ausgewertet (siehe sphereLabel()), damit
// t() erst beim tatsächlichen Aufruf laeuft und nicht schon beim Import -
// gleiche Begruendung wie bei HelpModal.vue.
function sphereLabels() {
	return {
		ideell: t('Ideeller Bereich'),
		vermoegensverwaltung: t('Vermögensverwaltung'),
		zweckbetrieb: t('Zweckbetrieb'),
		wirtschaftlich: t('Wirtschaftlicher Geschäftsbetrieb'),
	}
}

/**
 * Zuordnung der steuerlichen Sphäre zu Einnahmen-/Ausgaben-Konten. Frueher
 * SettingsSpheres.vue im Einstellungen-Modal; jetzt Teil des Berichts
 * „Sphären" (ReportsTab.vue), siehe NAVIGATION-KONZEPT.md Abschnitt 4 – wo
 * die fehlende Zuordnung sichtbar wird, wird sie auch geschlossen.
 */
export default {
	name: 'SphereAssignPanel',
	components: { NcButton },
	emits: ['changed', 'help'],
	setup() {
		return { ...toRefs(useAccounts().state) }
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
			return this.accounts.filter((a) => a.type !== 'equity' && !a.isBank)
		},

		allSelected() {
			return this.relevantAccounts.length > 0 && this.selected.length === this.relevantAccounts.length
		},
	},

	methods: {
		errMsg,
		sphereLabel(code) { return sphereLabels()[code] || code },
		// Grobe Namensheuristik als Vorschlag – keine Garantie, muss geprüft werden (siehe Hinweistext oben).
		suggestSphere(name) {
			const n = (name || '').toLowerCase()
			if (/mitgliedsbeitr|spende|zuschuss|förder|fördermittel/.test(n)) { return 'ideell' }
			if (/zins|miete|pacht|kapitalertrag|geldanlage/.test(n)) { return 'vermoegensverwaltung' }
			if (/konzert|veranstaltung|kurs|eintritt|sportver/.test(n)) { return 'zweckbetrieb' }
			if (/gaststätte|werbung|sponsoring|verkauf|kiosk|bandenwerbung|stand/.test(n)) { return 'wirtschaftlich' }
			return null
		},

		toggleAll(checked) {
			this.selected = checked ? this.relevantAccounts.map((a) => a.id) : []
		},

		toggleOne(id, checked) {
			if (checked) { if (!this.selected.includes(id)) { this.selected.push(id) } } else { this.selected = this.selected.filter((x) => x !== id) }
		},

		async applyBulk() {
			if (!this.selected.length || !this.bulkSphere) { return }
			this.bulkSaving = true
			try {
				const { data } = await api.bulkSphere(this.selected, this.bulkSphere)
				showSuccess(this.t('{count} Konten der Sphäre „{sphere}" zugeordnet.', { count: data.updated, sphere: this.sphereLabel(this.bulkSphere) }))
				this.selected = []
				this.bulkSphere = ''
				this.$emit('changed')
			} catch (e) { showError(this.errMsg(e, this.t('Zuordnung fehlgeschlagen'))) } finally { this.bulkSaving = false }
		},

		async saveOne(account, sphere) {
			try {
				await api.updateAccount(account.id, {
					number: account.number,
					name: account.name,
					type: account.type,
					category: account.category || null,
					isBank: account.isBank,
					parentId: account.parentId || 0,
					sphere,
				})
				this.$emit('changed')
			} catch (e) { showError(this.errMsg(e, this.t('Sphäre konnte nicht gespeichert werden'))) }
		},
	},
}
</script>
