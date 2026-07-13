<template>
	<NcModal :show="show" :name="accountEditId ? 'Konto bearbeiten' : 'Neues Konto'" size="normal" @close="$emit('close')" @update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<div class="vbh-form">
				<label>Nummer<input v-model="form.number" class="vbh-short" placeholder="z.B. 4000"></label>
				<label class="vbh-grow">Bezeichnung<input v-model="form.name" placeholder="Kontoname"></label>
			</div>
			<div class="vbh-form">
				<label>Typ
					<select v-model="form.type">
						<option value="income">Ertrag (Einnahme)</option>
						<option value="expense">Aufwand (Ausgabe)</option>
						<option value="asset">Aktiv (Vermögen)</option>
						<option value="liability">Passiv (Verbindlichkeit)</option>
						<option value="equity">Eigenkapital</option>
					</select>
				</label>
				<label class="vbh-grow">Überkonto
					<NcSelect
						v-model="accountParentOption"
						:options="accountParentOptions"
						:filter-by="accountFilterBy"
						label="label"
						placeholder="– kein Überkonto –"
						:clearable="true"
					/>
				</label>
			</div>
			<div class="vbh-form">
				<label>Kategorie<input v-model="form.category" placeholder="optional"></label>
				<NcCheckboxRadioSwitch v-model="form.isBank">Geldkonto (Bank/Kasse) – Bestand geht über Jahresgrenzen</NcCheckboxRadioSwitch>
			</div>
			<div v-if="form.type !== 'equity' && !form.isBank" class="vbh-form">
				<label class="vbh-grow">Steuerliche Sphäre
					<select v-model="form.sphere">
						<option value="">– nicht zugeordnet –</option>
						<option value="ideell">Ideeller Bereich</option>
						<option value="vermoegensverwaltung">Vermögensverwaltung</option>
						<option value="zweckbetrieb">Zweckbetrieb</option>
						<option value="wirtschaftlich">Wirtschaftlicher Geschäftsbetrieb</option>
					</select>
				</label>
				<button type="button" class="vbh-sphere-help" title="Was bedeutet das?" @click="$emit('help')">?</button>
			</div>
			<div v-if="form.type === 'equity'" class="vbh-form">
				<label class="vbh-grow">Rücklagen-Art
					<select v-model="form.reserveKind">
						<option value="">– keine Rücklage –</option>
						<option value="frei">Freie Rücklage</option>
						<option value="zweckgebunden">Zweckgebundene Rücklage</option>
						<option value="wiederbeschaffung">Wiederbeschaffungsrücklage</option>
					</select>
				</label>
			</div>
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">Abbrechen</NcButton>
				<NcButton variant="primary" @click="save">{{ accountEditId ? 'Speichern' : 'Anlegen' }}</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcSelect, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { useAccounts } from '../composables/useAccounts.js'

function emptyForm() {
	return { number: '', name: '', type: 'income', category: '', isBank: false, parentId: null, sphere: '', reserveKind: '' }
}

export default {
	name: 'AccountDialog',
	components: { NcModal, NcButton, NcSelect, NcCheckboxRadioSwitch },
	props: {
		show: { type: Boolean, default: false },
		// null = neues Konto anlegen, sonst das zu bearbeitende Konto
		accountEditId: { type: [Number, String], default: null },
		// Vorbelegung fuer ein neues Konto (aus dem aktuell gewaehlten Konto im
		// Konten-Tab abgeleitet) bzw. das zu bearbeitende Konto - Elternteil
		// bereitet dies weiterhin vor (openNewAccount/openEditAccount), da es von
		// Accounts-Tab-Kontext (selectedAccount) abhaengt, den der Dialog nicht kennt.
		initialForm: { type: Object, required: true },
	},
	setup() {
		// accountsSorted kommt direkt aus dem useAccounts-Singleton (fuer die
		// Ueberkonto-Auswahl, gleicher geteilter Zustand wie in App.vue).
		const accounts = useAccounts()
		return { accountsSorted: accounts.accountsSorted }
	},
	data() {
		return {
			// lokale Kopie statt direkter Prop-Mutation (Muster wie ruleForm in
			// SettingsRules.vue) - wird beim Oeffnen aus initialForm geklont.
			form: emptyForm(),
		}
	},
	computed: {
		parentOptions() {
			return this.accountsSorted.filter(a => a.id !== this.accountEditId)
		},
		accountParentOptions() {
			return [
				{ id: null, label: '– kein Überkonto –' },
				...this.parentOptions.map(a => ({ id: a.id, label: `${a.number} ${a.name}`, number: a.number })),
			]
		},
		accountParentOption: {
			get() { return this.accountParentOptions.find(o => o.id === this.form.parentId) ?? null },
			set(v) { this.form.parentId = v ? v.id : null },
		},
	},
	watch: {
		show(open) {
			if (open) this.form = { ...emptyForm(), ...this.initialForm }
		},
	},
	methods: {
		// Suchfilter fuer das Konto-Dropdown (Ziffern = Praefix der Kontonummer),
		// dieselbe reine Logik wie in SettingsRules.vue.
		accountFilterBy(option, label, search) {
			const s = String(search || '').trim().toLowerCase()
			if (!s) return true
			if (option && option.$isDisabled) return false
			if (/^[\d\s]+$/.test(s)) {
				const digits = s.replace(/\s+/g, '')
				const num = String((option && option.number) || '').replace(/\s+/g, '').toLowerCase()
				return num.startsWith(digits)
			}
			return String(label || '').toLowerCase().includes(s)
		},
		save() {
			this.$emit('save', { ...this.form })
		},
	},
}
</script>
