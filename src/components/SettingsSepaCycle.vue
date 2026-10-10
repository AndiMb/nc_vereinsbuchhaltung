<template>
	<div class="vbh-card">
		<h4>{{ t('Beitragsjahr und Einzugszyklus') }}</h4>
		<p class="vbh-hint">
			{{ t('Das Beitragsjahr ist unabhängig vom Geschäftsjahr der Buchhaltung: es bestimmt, wie die Beitragsperioden der Mitglieder liegen. Beginnt es nicht im Januar, heißt es z. B. „2026/27". Stellen Sie den Beginn am besten ein, bevor die ersten Forderungen entstehen.') }}
		</p>
		<div class="vbh-form">
			<label class="vbh-grow">{{ t('Beitragsjahr beginnt im') }}
				<select v-model.number="draft.fiscalYearStartMonth">
					<option v-for="m in months" :key="m.value" :value="m.value">
						{{ m.label }}
					</option>
				</select>
			</label>
		</div>

		<h5 class="vbh-cycle-subtitle">
			{{ t('Fristen vor dem Einzug') }}
		</h5>
		<div class="vbh-form">
			<label>{{ t('Vorwarnfenster (Tage vor Einzug)') }}
				<input
					v-model.number="draft.warningLeadDays"
					type="number"
					class="vbh-short"
					:min="daysMin"
					:max="daysMax">
			</label>
			<label>{{ t('Vorabinfo-Vorlauf (Tage vor Einzug)') }}
				<input
					v-model.number="draft.prenotificationLeadDays"
					type="number"
					class="vbh-short"
					:min="daysMin"
					:max="daysMax">
			</label>
			<label>{{ t('Freigabe-Vorlauf (Tage vor Einzug)') }}
				<input
					v-model.number="draft.releaseLeadDays"
					type="number"
					class="vbh-short"
					:min="daysMin"
					:max="daysMax">
			</label>
		</div>
		<ul class="vbh-hint vbh-cycle-explain">
			<li>{{ t('Vorwarnfenster: ab dann entstehen die Forderungen (Standard 21 Tage).') }}</li>
			<li>{{ t('Vorabinfo-Vorlauf: ab dann geht die Ankündigung per Mail an die Mitglieder (Standard 14 Tage; SEPA verlangt mindestens 14, sofern im Mandat nichts Kürzeres vereinbart ist).') }}</li>
			<li>{{ t('Freigabe-Vorlauf: ab dann meldet die Aufgabenliste „Freigabe fällig" (Standard 5 Tage).') }}</li>
		</ul>
		<p v-if="example" class="vbh-cycle-example" data-testid="cycle-example">
			{{ t('Beispiel – Einzug am {einzug}: Forderungen ab {warnung}, Vorabinfo am {vorabinfo}, Lauf freigegeben bis {freigabe}.', example) }}
		</p>
		<NcNoteCard v-for="hint in orderHints" :key="hint" type="warning">
			{{ hint }}
		</NcNoteCard>

		<NcButton variant="primary" :disabled="saving" @click="save">
			{{ t('Speichern') }}
		</NcButton>
		<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { getLanguage } from '@nextcloud/l10n'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { useDueDateSchedule } from '../composables/useDueDateSchedule.js'
import { useSepaSettings } from '../composables/useSepaSettings.js'
import { useSettingsCardSave } from '../composables/useSettingsCardSave.js'
import { formatDate } from '../lib/format.js'
import { DAYS_MAX, DAYS_MIN, daysError, leadDaysExample, leadDaysOrderHints, monthOptions, nextMonthStart } from '../lib/sepaSettings.js'

/**
 * Beitragsjahr und Einzugszyklus in den Einstellungen (Spec §4, Issue #101):
 * Startmonat des Beitragsjahrs (`fiscal_year_start_month`) und die drei Fristen
 * vor dem Einzug – Vorwarnfenster, Vorabinfo-Vorlauf und Freigabe-Vorlauf (Spec
 * §3.5 „Vorlauf-Puffer"). Alle drei stehen hier beisammen, damit man sie findet;
 * der Terminplan im Reiter Einzug zeigt sie nur an.
 */
export default {
	name: 'SettingsSepaCycle',
	components: { NcButton, NcNoteCard },

	setup() {
		const { state, saveCycleSettings } = useSepaSettings()
		const { state: schedule } = useDueDateSchedule()
		const card = useSettingsCardSave()
		return { settings: state, saveCycleSettings, schedule, ...card }
	},

	data() {
		return {
			months: monthOptions(getLanguage()),
			daysMin: DAYS_MIN,
			daysMax: DAYS_MAX,
			draft: {
				fiscalYearStartMonth: this.settings.fiscalYearStartMonth,
				releaseLeadDays: this.settings.releaseLeadDays,
				warningLeadDays: this.schedule.warningLeadDays,
				prenotificationLeadDays: this.schedule.prenotificationLeadDays,
			},
		}
	},

	computed: {
		leads() {
			return {
				warningLeadDays: this.draft.warningLeadDays,
				prenotificationLeadDays: this.draft.prenotificationLeadDays,
				releaseLeadDays: this.draft.releaseLeadDays,
			}
		},

		// Beispiel mit den eingetragenen Werten: Einzug am nächsten Monatsersten. Bei einer
		// unfertigen Eingabe (leeres Feld) gibt es keins.
		example() {
			const values = Object.values(this.leads)
			if (values.some((v) => v === '' || v === null || !Number.isInteger(Number(v)))) { return null }
			const dates = leadDaysExample(nextMonthStart(new Date().toISOString().slice(0, 10)), this.leads)
			return {
				einzug: formatDate(dates.due),
				warnung: formatDate(dates.warning),
				vorabinfo: formatDate(dates.prenotification),
				freigabe: formatDate(dates.release),
			}
		},

		orderHints() {
			return this.example ? leadDaysOrderHints(this.leads) : []
		},
	},

	methods: {
		save() {
			return this.run(
				() => daysError(this.draft.warningLeadDays, this.t('Vorwarnfenster'))
					?? daysError(this.draft.prenotificationLeadDays, this.t('Vorabinfo-Vorlauf'))
					?? daysError(this.draft.releaseLeadDays, this.t('Freigabe-Vorlauf')),
				() => this.saveCycleSettings(this.draft),
			)
		},
	},
}
</script>

<style scoped>
.vbh-cycle-subtitle {
	margin: 16px 0 4px;
	font-weight: 700;
}

.vbh-cycle-explain {
	margin: 4px 0 8px;
	padding-inline-start: 20px;
}

.vbh-cycle-example {
	margin: 8px 0 12px;
	font-weight: 600;
}
</style>
