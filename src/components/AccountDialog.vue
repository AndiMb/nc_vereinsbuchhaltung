<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-account"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-account" class="vbh-modal-title">
				{{ accountEditId ? t('Konto bearbeiten') : t('Neues Konto') }}
			</h2>
			<div class="vbh-form">
				<label>{{ t('Nummer') }}<input v-model="form.number" class="vbh-short" :placeholder="t('z.B. 4000')"></label>
				<label class="vbh-grow">{{ t('Bezeichnung') }}<input v-model="form.name" :placeholder="t('Kontoname')"></label>
			</div>
			<div class="vbh-form">
				<label>{{ t('Typ') }}
					<select v-model="form.type">
						<option value="income">{{ t('Ertrag (Einnahme)') }}</option>
						<option value="expense">{{ t('Aufwand (Ausgabe)') }}</option>
						<option value="asset">{{ t('Aktiv (Vermögen)') }}</option>
						<option value="liability">{{ t('Passiv (Verbindlichkeit)') }}</option>
						<option value="equity">{{ t('Eigenkapital') }}</option>
					</select>
				</label>
				<label class="vbh-grow">{{ t('Überkonto') }}
					<NcSelect
						v-model="accountParentOption"
						:options="accountParentOptions"
						:filterBy="accountFilterBy"
						label="label"
						:placeholder="t('– kein Überkonto –')"
						:clearable="true" />
				</label>
			</div>
			<div class="vbh-form">
				<label>{{ t('Kategorie') }}<input v-model="form.category" :placeholder="t('optional')"></label>
				<NcCheckboxRadioSwitch v-model="form.isBank">
					{{ t('Geldkonto (Bank/Kasse) – Bestand geht über Jahresgrenzen') }}
				</NcCheckboxRadioSwitch>
			</div>
			<div v-if="form.isBank" class="vbh-form">
				<label class="vbh-grow">{{ t('IBAN (optional)') }}
					<input
						v-model="form.iban"
						type="text"
						autocapitalize="characters"
						:placeholder="t('z. B. DE12 5001 0517 0648 4898 90')">
				</label>
			</div>
			<p v-if="form.isBank" class="vbh-hint">
				{{ t('Nur nötig, wenn die Buchhaltung mehrere Geldkonten führt: Damit ordnet die App importierte Kontoauszüge dem richtigen Konto zu. Leerzeichen sind egal.') }}
			</p>
			<div v-if="form.type !== 'equity' && !form.isBank" class="vbh-form">
				<label class="vbh-grow">{{ t('Steuerliche Sphäre') }}
					<select v-model="form.sphere">
						<option value="">{{ t('– nicht zugeordnet –') }}</option>
						<option value="ideell">{{ t('Ideeller Bereich') }}</option>
						<option value="vermoegensverwaltung">{{ t('Vermögensverwaltung') }}</option>
						<option value="zweckbetrieb">{{ t('Zweckbetrieb') }}</option>
						<option value="wirtschaftlich">{{ t('Wirtschaftlicher Geschäftsbetrieb') }}</option>
					</select>
				</label>
				<button
					type="button"
					class="vbh-sphere-help"
					:title="t('Was bedeutet das?')"
					@click="$emit('help')">
					?
				</button>
			</div>
			<div v-if="showCostCenter" class="vbh-form">
				<label class="vbh-grow">{{ t('Auswertungsgruppe') }}
					<select v-model="form.costCenterId">
						<option :value="null">{{ t('– keine Auswertungsgruppe –') }}</option>
						<option v-for="cc in costCenters" :key="cc.id" :value="cc.id">{{ cc.code }} · {{ cc.name }}</option>
					</select>
				</label>
			</div>
			<div v-if="form.type === 'equity'" class="vbh-form">
				<label class="vbh-grow">{{ t('Rücklagen-Art') }}
					<select v-model="form.reserveKind">
						<option value="">{{ t('– keine Rücklage –') }}</option>
						<option value="frei">{{ t('Freie Rücklage') }}</option>
						<option value="zweckgebunden">{{ t('Zweckgebundene Rücklage') }}</option>
						<option value="wiederbeschaffung">{{ t('Wiederbeschaffungsrücklage') }}</option>
					</select>
				</label>
			</div>
			<!-- Nur beim Bearbeiten: ein neues Konto ist immer aktiv. Der Weg
			     hierher fuehrt oft ueber den Loeschversuch eines bebuchten
			     Kontos, den AccountService::delete() genau hierauf verweist. -->
			<div v-if="accountEditId" class="vbh-form">
				<NcCheckboxRadioSwitch v-model="form.active" type="switch">
					{{ t('Konto aktiv') }}
				</NcCheckboxRadioSwitch>
			</div>
			<p v-if="accountEditId && !form.active" class="vbh-hint">
				{{ t('Ein inaktives Konto verschwindet aus allen Auswahllisten – bereits gebuchte Beträge, Berichte und die Historie bleiben unverändert. So werden Konten losgeworden, die sich wegen vorhandener Buchungen nicht löschen lassen.') }}
			</p>
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" @click="save">
					{{ accountEditId ? t('Speichern') : t('Anlegen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcModal, NcSelect } from '@nextcloud/vue'
import { toRefs } from 'vue'
import { useAccounts } from '../composables/useAccounts.js'
import { useCostCenters } from '../composables/useCostCenters.js'

/**
 *
 */
function emptyForm() {
	return { number: '', name: '', type: 'income', category: '', isBank: false, parentId: null, sphere: '', reserveKind: '', iban: '', costCenterId: null, active: true }
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
		// Kostenstellen-Modus (group|account|manual): die Zuordnung am Konto
		// wird nur im Modus 'manual' ausgewertet - in den anderen waere das
		// Feld ein Bedienelement ohne Wirkung.
		costCenterMode: { type: String, default: 'group' },
	},

	emits: ['close', 'help', 'save', 'update:show'],

	setup() {
		// accountsSorted kommt direkt aus dem useAccounts-Singleton (fuer die
		// Ueberkonto-Auswahl, gleicher geteilter Zustand wie in App.vue).
		const accounts = useAccounts()
		// Kostenstellen ebenfalls aus dem geteilten Zustand: das Auswahlfeld
		// erscheint nur, wenn welche angelegt sind.
		const costCenters = useCostCenters()
		return { accountsSorted: accounts.accountsSorted, ...toRefs(costCenters.state) }
	},

	data() {
		return {
			// lokale Kopie statt direkter Prop-Mutation (Muster wie ruleForm in
			// SettingsRules.vue) - wird beim Oeffnen aus initialForm geklont.
			form: emptyForm(),
		}
	},

	computed: {
		// Nur wo die Zuordnung auch wirkt und zum Kontotyp passt: Geldkonten und
		// Eigenkapital tauchen in der Kostenstellen-Auswertung nicht auf
		// (Account::isResultRelevant() im Backend).
		showCostCenter() {
			return this.costCenterMode === 'manual'
				&& this.costCenters.length > 0
				&& this.form.type !== 'equity'
				&& !this.form.isBank
		},

		parentOptions() {
			return this.accountsSorted.filter((a) => a.id !== this.accountEditId)
		},

		accountParentOptions() {
			return [
				{ id: null, label: this.t('– kein Überkonto –') },
				...this.parentOptions.map((a) => ({ id: a.id, label: `${a.number} ${a.name}`, number: a.number })),
			]
		},

		accountParentOption: {
			get() { return this.accountParentOptions.find((o) => o.id === this.form.parentId) ?? null },
			set(v) { this.form.parentId = v ? v.id : null },
		},
	},

	watch: {
		show(open) {
			if (open) { this.form = { ...emptyForm(), ...this.initialForm } }
		},
	},

	methods: {
		// Suchfilter fuer das Konto-Dropdown (Ziffern = Praefix der Kontonummer),
		// dieselbe reine Logik wie in SettingsRules.vue.
		accountFilterBy(option, label, search) {
			const s = String(search || '').trim().toLowerCase()
			if (!s) { return true }
			if (option && option.$isDisabled) { return false }
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
