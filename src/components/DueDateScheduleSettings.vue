<template>
	<section class="vbh-card">
		<div class="vbh-cardhead">
			<h4>{{ t('Terminplan') }}</h4>
		</div>
		<p class="vbh-hint">
			{{ t('Je Turnus ein Standard-Einzugstag (Tage-Versatz zum Periodenbeginn – 0 = am ersten Tag der Periode, negativ = vorgezogen). Einzelne Perioden lassen sich darunter überschreiben, die Überschreibung gilt jahresunabhängig für denselben Periodenindex.') }}
		</p>
		<div class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Turnus (Monate)') }}</th>
						<th class="num">
							{{ t('Standard-Versatz (Tage)') }}
						</th>
						<th>{{ t('Überschreibungen (Periodenindex: Versatz)') }}</th>
						<th class="vbh-col-memberactions" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="interval in intervals" :key="interval">
						<td>{{ interval }}</td>
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
						{{ interval }}
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

		<div class="vbh-form">
			<label>{{ t('Vorwarnfenster (Tage vor Einzug)') }}
				<input
					v-model.number="leadDrafts.warningLeadDays"
					type="number"
					class="vbh-short"
					min="1">
			</label>
			<label>{{ t('Vorabinfo-Vorlauf (Tage vor Einzug)') }}
				<input
					v-model.number="leadDrafts.prenotificationLeadDays"
					type="number"
					class="vbh-short"
					min="1">
			</label>
			<NcButton variant="primary" @click="saveLeadDays">
				{{ t('Speichern') }}
			</NcButton>
		</div>
	</section>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import { toRefs } from 'vue'
import api from '../api.js'
import { useDueDateSchedule } from '../composables/useDueDateSchedule.js'
import { errMsg } from '../lib/format.js'

/**
 * Terminplan-Einstellung (Spec §2.2/§3.5, Issue #70): Standard-Einzugstag je
 * Turnus + Überschreibungen je Periodenindex, plus die beiden
 * Cron-Abstände. Guards (kein Überholen, Termin im Beitragsjahr) prüft der
 * Server (DueDateScheduleService) - diese Ansicht zeigt dessen Fehlermeldung
 * einfach an, statt sie hier zu duplizieren.
 */
export default {
	name: 'DueDateScheduleSettings',
	components: { NcButton },

	setup() {
		const schedule = useDueDateSchedule()
		return { ...toRefs(schedule.state), loadDueDateSchedule: schedule.loadDueDateSchedule }
	},

	data() {
		return {
			intervals: [1, 2, 3, 4, 6, 12],
			defaultDrafts: {},
			leadDrafts: { warningLeadDays: 21, prenotificationLeadDays: 14 },
			overrideDraft: { intervalMonths: 1, periodIndex: 0, offsetDays: 0 },
		}
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

		warningLeadDays(value) { this.leadDrafts.warningLeadDays = value },
		prenotificationLeadDays(value) { this.leadDrafts.prenotificationLeadDays = value },
	},

	async mounted() {
		await this.loadDueDateSchedule()
	},

	methods: {
		overrideList(interval) {
			const overrides = this.schedule?.[interval]?.overrides || {}
			return Object.keys(overrides).map((periodIndex) => ({ periodIndex: Number(periodIndex), offsetDays: overrides[periodIndex] }))
		},

		async saveDefault(interval) {
			try {
				await api.setDueDateScheduleDefaultDay(interval, this.defaultDrafts[interval])
				await this.loadDueDateSchedule()
				showSuccess(this.t('Standard-Einzugstag gespeichert.'))
			} catch (e) { showError(errMsg(e, 'Standard-Einzugstag konnte nicht gespeichert werden')) }
		},

		async addOverride() {
			try {
				await api.setDueDateScheduleOverride(this.overrideDraft.intervalMonths, this.overrideDraft.periodIndex, this.overrideDraft.offsetDays)
				await this.loadDueDateSchedule()
				showSuccess(this.t('Überschreibung gespeichert.'))
			} catch (e) { showError(errMsg(e, 'Überschreibung konnte nicht gespeichert werden')) }
		},

		async removeOverride(interval, periodIndex) {
			try {
				await api.setDueDateScheduleOverride(interval, periodIndex, null)
				await this.loadDueDateSchedule()
			} catch (e) { showError(errMsg(e, 'Überschreibung konnte nicht entfernt werden')) }
		},

		async saveLeadDays() {
			try {
				await api.setDueDateScheduleLeadDays(this.leadDrafts)
				await this.loadDueDateSchedule()
				showSuccess(this.t('Einstellungen gespeichert.'))
			} catch (e) { showError(errMsg(e, 'Einstellungen konnten nicht gespeichert werden')) }
		},
	},
}
</script>

<style scoped>
.vbh-cardhead {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 8px;
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
