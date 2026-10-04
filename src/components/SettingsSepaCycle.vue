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
			<label>{{ t('Freigabe-Vorlauf (Tage vor Einzug)') }}
				<input
					v-model.number="draft.releaseLeadDays"
					type="number"
					class="vbh-short"
					:min="daysMin"
					:max="daysMax">
			</label>
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ t('Speichern') }}
			</NcButton>
		</div>
		<p class="vbh-hint">
			{{ t('Der Freigabe-Vorlauf (Standard 5 Tage) ist der Puffer, ab dem ein noch nicht freigegebener oder eingereichter Lauf in der Aufgabenliste als „Freigabe fällig" bzw. „Einreichung überfällig" auftaucht.') }}
		</p>
		<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
			{{ error }}
		</p>
		<p class="vbh-hint vbh-hint--info">
			{{ t('Vorwarnfenster: {warning} Tage vor dem Einzug · Vorabinfo-Vorlauf: {prenotification} Tage vor dem Einzug.', { warning: warningLeadDays, prenotification: prenotificationLeadDays }) }}
			<br>
			<a class="vbh-settings-link" :href="scheduleUrl">{{ t('Terminplan, Vorwarnfenster und Vorabinfo-Vorlauf im Regelwerk ändern') }}</a>
		</p>
	</div>
</template>

<script>
import { getLanguage } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import { toRefs } from 'vue'
import { useDueDateSchedule } from '../composables/useDueDateSchedule.js'
import { useSepaSettings } from '../composables/useSepaSettings.js'
import { useSettingsCardSave } from '../composables/useSettingsCardSave.js'
import { DAYS_MAX, DAYS_MIN, daysError, monthOptions } from '../lib/sepaSettings.js'

/**
 * Beitragsjahr und Einzugszyklus in den Einstellungen (Spec §4, Issue #101):
 * Startmonat des Beitragsjahrs (`fiscal_year_start_month`) und Freigabe-
 * Vorlauf (`lead_buffer_days`, Spec §3.5 „Vorlauf-Puffer").
 *
 * Der Terminplan selbst (Einzugstage je Turnus) sowie Vorwarnfenster und
 * Vorabinfo-Vorlauf gehören zur Terminplan-Einstellung aus #70
 * (DueDateScheduleSettings.vue, im Regelwerk der Beiträge) - sie sind dort
 * schon bedienbar und werden hier bewusst nicht ein zweites Mal gebaut,
 * sondern nur als Übersicht mit Verweis gezeigt.
 */
export default {
	name: 'SettingsSepaCycle',
	components: { NcButton },

	setup() {
		const { state, saveCycleSettings } = useSepaSettings()
		const { state: schedule } = useDueDateSchedule()
		const card = useSettingsCardSave()
		return { settings: state, saveCycleSettings, ...toRefs(schedule), ...card }
	},

	data() {
		return {
			months: monthOptions(getLanguage()),
			daysMin: DAYS_MIN,
			daysMax: DAYS_MAX,
			// Regelwerk der Beiträge (ContributionGroupsPanel.vue), dort liegt der Terminplan
			scheduleUrl: generateUrl('/apps/vereinsbuchhaltung/contributions/groups'),
			draft: {
				fiscalYearStartMonth: this.settings.fiscalYearStartMonth,
				releaseLeadDays: this.settings.releaseLeadDays,
			},
		}
	},

	methods: {
		save() {
			return this.run(
				() => daysError(this.draft.releaseLeadDays, this.t('Freigabe-Vorlauf')),
				() => this.saveCycleSettings(this.draft),
			)
		},
	},
}
</script>

<style scoped>
/* Hauptschriftfarbe mit Unterstreichung: lesbar in jedem Design, auch im dunklen (ein Link in Eigenfarbe ist es dort nicht immer) */
.vbh-settings-link {
	color: var(--color-main-text);
	font-weight: 600;
	text-decoration: underline;
}
</style>
