<template>
	<section class="vbh-card">
		<div class="vbh-cardhead">
			<h4>{{ t('Terminplan') }}</h4>
			<InfoHint :label="t('Wie funktioniert der Terminplan?')">
				<p>
					{{ t('Je Turnus ein Standard-Einzugstag (Tage-Versatz zum Periodenbeginn – 0 = am ersten Tag der Periode, negativ = vorgezogen). Einzelne Perioden lassen sich darunter überschreiben, die Überschreibung gilt jahresunabhängig für denselben Periodenindex.') }}
				</p>
			</InfoHint>
		</div>
		<div class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Turnus') }}</th>
						<th class="num">
							{{ t('Standard-Versatz (Tage)') }}
						</th>
						<th>{{ t('Überschreibungen (Periodenindex: Versatz)') }}</th>
						<th class="vbh-col-memberactions" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="interval in intervals" :key="interval">
						<td>{{ intervalLabel(interval) }}</td>
						<td class="num">
							<input v-model.number="defaultDrafts[interval]" type="number" class="vbh-short">
						</td>
						<td>
							<span v-if="overrideList(interval).length === 0" class="vbh-hint">{{ t('keine') }}</span>
							<span v-for="ov in overrideList(interval)" :key="ov.periodIndex" class="vbh-override-chip">
								{{ ov.periodIndex }}: {{ ov.offsetDays }}
								<button
									type="button"
									class="vbh-override-remove"
									:title="t('Überschreibung entfernen')"
									@click="removeOverride(interval, ov.periodIndex)">
									×
								</button>
							</span>
						</td>
						<td class="nowrap right">
							<NcButton size="small" @click="saveDefault(interval)">
								{{ t('Speichern') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="vbh-form">
			<label>{{ t('Turnus') }}
				<select v-model.number="overrideDraft.intervalMonths">
					<option v-for="interval in intervals" :key="interval" :value="interval">
						{{ intervalLabel(interval) }}
					</option>
				</select>
			</label>
			<label>{{ t('Periodenindex') }}
				<input
					v-model.number="overrideDraft.periodIndex"
					type="number"
					class="vbh-short"
					min="0">
			</label>
			<label>{{ t('Versatz (Tage)') }}
				<input v-model.number="overrideDraft.offsetDays" type="number" class="vbh-short">
			</label>
			<NcButton variant="primary" @click="addOverride">
				{{ t('+ Überschreibung') }}
			</NcButton>
		</div>

		<p class="vbh-hint vbh-hint--info" data-testid="lead-days-summary">
			{{ t('Vorwarnfenster: {warning} Tage · Vorabinfo-Vorlauf: {prenotification} Tage · Freigabe-Vorlauf: {release} Tage vor dem Einzug.', { warning: warningLeadDays, prenotification: prenotificationLeadDays, release: releaseLeadDays }) }}
			<template v-if="isAdmin">
				<br>
				<a class="vbh-settings-link" :href="settingsUrl">{{ t('Fristen in den Einstellungen ändern') }}</a>
			</template>
		</p>
	</section>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import { toRefs } from 'vue'
import InfoHint from './InfoHint.vue'
import api from '../api.js'
import { useAuth } from '../composables/useAuth.js'
import { useDueDateSchedule } from '../composables/useDueDateSchedule.js'
import { errMsg } from '../lib/format.js'
import { intervalLabel } from '../lib/frequency.js'

/**
 * Terminplan-Einstellung (Spec §2.2/§3.5, Issue #70): Standard-Einzugstag je
 * Turnus + Überschreibungen je Periodenindex, plus die beiden
 * Cron-Abstände. Guards (kein Überholen, Termin im Beitragsjahr) prüft der
 * Server (DueDateScheduleService) - diese Ansicht zeigt dessen Fehlermeldung
 * einfach an, statt sie hier zu duplizieren.
 *
 * Rollen (Spec §3.9, Issue #119): Die Terminverschiebung (Standard-Einzugstag,
 * Überschreibungen) ist `buchhalter`. Vorwarnfenster und Vorabinfo-Vorlauf sind
 * Einstellungen (nur `verwalter`) und stehen mit dem Freigabe-Vorlauf in den
 * Nextcloud-Einstellungen (SettingsSepaCycle.vue); hier werden sie nur genannt,
 * mit Verweis für Verwalter.
 */
export default {
	name: 'DueDateScheduleSettings',
	components: { InfoHint, NcButton },

	// Nach jeder gespeicherten Änderung: der Einzug-Unterreiter (EinzugPanel)
	// rechnet daraufhin den Zeitstrahl neu.
	emits: ['changed'],

	setup() {
		const schedule = useDueDateSchedule()
		const { state: auth, isAdmin } = useAuth()
		return { ...toRefs(schedule.state), loadDueDateSchedule: schedule.loadDueDateSchedule, isAdmin, auth }
	},

	data() {
		return {
			intervals: [1, 2, 3, 4, 6, 12],
			defaultDrafts: {},
			overrideDraft: { intervalMonths: 1, periodIndex: 0, offsetDays: 0 },
		}
	},

	computed: {
		// Verwaltung für Nextcloud-Admins, Persönlich für App-Verwalter ohne Nextcloud-Adminrechte
		// (dieselbe Unterscheidung wie Settings\PersonalSettings::getSection()).
		settingsUrl() {
			const area = this.auth.me?.isServerAdmin ? 'admin' : 'user'
			return generateUrl('/settings/' + area + '/vereinsbuchhaltung') + '#settings-section_beitraege-sepa'
		},
	},

	watch: {
		schedule: {
			deep: true,
			handler(value) {
				for (const interval of this.intervals) {
					this.defaultDrafts[interval] = value?.[interval]?.defaultOffsetDays ?? 0
				}
			},
		},
	},

	async mounted() {
		await this.loadDueDateSchedule()
	},

	methods: {
		intervalLabel,

		overrideList(interval) {
			const overrides = this.schedule?.[interval]?.overrides || {}
			return Object.keys(overrides).map((periodIndex) => ({ periodIndex: Number(periodIndex), offsetDays: overrides[periodIndex] }))
		},

		async saveDefault(interval) {
			try {
				await api.setDueDateScheduleDefaultDay(interval, this.defaultDrafts[interval])
				await this.loadDueDateSchedule()
				this.$emit('changed')
				showSuccess(this.t('Standard-Einzugstag gespeichert.'))
			} catch (e) { showError(errMsg(e, this.t('Standard-Einzugstag konnte nicht gespeichert werden'))) }
		},

		async addOverride() {
			try {
				await api.setDueDateScheduleOverride(this.overrideDraft.intervalMonths, this.overrideDraft.periodIndex, this.overrideDraft.offsetDays)
				await this.loadDueDateSchedule()
				this.$emit('changed')
				showSuccess(this.t('Überschreibung gespeichert.'))
			} catch (e) { showError(errMsg(e, this.t('Überschreibung konnte nicht gespeichert werden'))) }
		},

		async removeOverride(interval, periodIndex) {
			try {
				await api.setDueDateScheduleOverride(interval, periodIndex, null)
				await this.loadDueDateSchedule()
				this.$emit('changed')
			} catch (e) { showError(errMsg(e, this.t('Überschreibung konnte nicht entfernt werden'))) }
		},
	},
}
</script>

<style scoped>
/* Hauptschriftfarbe mit Unterstreichung: lesbar in jedem Design, auch im dunklen */
.vbh-settings-link {
	color: var(--color-main-text);
	font-weight: 600;
	text-decoration: underline;
}

.vbh-cardhead {
	display: flex;
	align-items: center;
	justify-content: flex-start;
	gap: 4px;
	margin-bottom: 8px;
}

/* Die Versatz-Felder in der Tabelle brauchen keine Zellenbreite: drei Ziffern und ein Minus. */
.vbh-tablecard td input.vbh-short {
	width: 88px;
}

.vbh-cardhead h4 {
	margin: 0;
}

.vbh-override-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	margin-inline-end: 8px;
	padding: 2px 6px;
	border-radius: 8px;
	background: var(--color-background-hover);
}

.vbh-override-remove {
	border: none;
	background: transparent;
	cursor: pointer;
	font-weight: bold;
}
</style>
