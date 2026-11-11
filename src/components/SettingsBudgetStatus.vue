<template>
	<div class="vbh-card">
		<p class="vbh-hint">
			{{ t('Diese Schwellen gelten vereinsweit für die visuelle Ansicht des Gliederungsberichts.') }}
		</p>
		<div class="vbh-budget-status__fields">
			<div v-for="field in fields" :key="field.key" class="vbh-budget-status__field">
				<label class="vbh-budget-status__label">
					<span>{{ field.before }}</span>
					<span class="vbh-budget-status__input">
						<input
							v-model.number="draft[field.key]"
							type="number"
							class="vbh-short"
							:min="min"
							:max="max"
							step="1"
							:aria-describedby="'vbh-budget-status-hint-' + field.key">
						<span>%</span>
						<span v-if="field.after">{{ field.after }}</span>
					</span>
				</label>
				<span :id="'vbh-budget-status-hint-' + field.key" class="vbh-hint">{{ field.hint }}</span>
			</div>
		</div>
		<div class="vbh-form">
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ t('Speichern') }}
			</NcButton>
			<NcButton :disabled="saving" @click="resetDefaults">
				{{ t('Standardwerte') }}
			</NcButton>
		</div>
		<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import api from '../api.js'
import { useSettingsCardSave } from '../composables/useSettingsCardSave.js'
import { THRESHOLD_DEFAULTS, THRESHOLD_MAX, THRESHOLD_MIN, thresholdsError } from '../lib/budgetStatusSettings.js'

/**
 * Ampel-Schwellen der visuellen Gliederungsansicht (Einstellung
 * `budget_status_thresholds`, BudgetStatusSettings). Den gespeicherten Stand
 * reicht SettingsApp.vue herein (er kommt mit dem übrigen Einstellungssatz);
 * gespeichert wird aber nur dieser eine Wert, mit Prüfung und Meldung in der
 * Karte (useSettingsCardSave) – die fünf Zahlen gelten nur gemeinsam, und eine
 * Ablehnung soll weder andere Abschnitte blockieren noch von ihnen blockiert
 * werden.
 */
export default {
	name: 'SettingsBudgetStatus',
	components: { NcButton },

	props: {
		// gespeicherter Stand: { expWarn, expCrit, incGood, incWarn, resTol } in Prozent
		thresholds: { type: Object, required: true },
	},

	emits: ['update:thresholds'],

	setup() {
		return { ...useSettingsCardSave() }
	},

	data() {
		return {
			min: THRESHOLD_MIN,
			max: THRESHOLD_MAX,
			draft: { ...this.thresholds },
		}
	},

	computed: {
		// Der Wert steht zwischen zwei Textteilen, damit kein übersetzter Text ein „%“ enthält.
		fields() {
			return [
				{ key: 'expWarn', before: this.t('Ausgaben: orange ab'), after: '', hint: this.t('darunter grün') },
				{ key: 'expCrit', before: this.t('Ausgaben: rot über'), after: '', hint: this.t('zwischen beiden Werten orange') },
				{ key: 'incGood', before: this.t('Einnahmen: grün ab'), after: '', hint: this.t('Planwert erreicht') },
				{ key: 'incWarn', before: this.t('Einnahmen: rot unter'), after: '', hint: this.t('dazwischen orange') },
				{ key: 'resTol', before: this.t('Ergebnis: rot, wenn mehr als'), after: this.t('unter Plan'), hint: this.t('bis dahin orange; Ergebnis ≥ Plan = grün') },
			]
		},
	},

	watch: {
		thresholds(value) {
			this.draft = { ...value }
		},
	},

	methods: {
		save() {
			return this.run(
				() => thresholdsError(this.draft),
				async () => {
					const { data } = await api.saveSettings({ budget_status_thresholds: this.draft })
					this.$emit('update:thresholds', data.budget_status_thresholds)
				},
			)
		},

		resetDefaults() {
			this.draft = { ...THRESHOLD_DEFAULTS }
			this.error = ''
		},
	},
}
</script>

<style scoped>
.vbh-budget-status__fields {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-top: 10px;
}

.vbh-budget-status__field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.vbh-budget-status__label {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px;
}

.vbh-budget-status__input {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

.vbh-budget-status__field .vbh-hint {
	margin: 0;
}
</style>
