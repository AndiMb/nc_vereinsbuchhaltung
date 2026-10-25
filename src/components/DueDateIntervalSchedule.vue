<template>
	<div class="vbh-interval" :data-testid="'schedule-interval-' + intervalMonths">
		<div class="vbh-interval-head">
			<h5>{{ intervalLabel(intervalMonths) }}</h5>
			<span class="vbh-hint">{{ n('%n Mitglied', '%n Mitglieder', memberCount) }}</span>
		</div>

		<div class="vbh-interval-rule" role="group" :aria-label="t('Einzugstag für {interval}', { interval: intervalLabel(intervalMonths) })">
			<span>{{ t('Einzug') }}</span>
			<input
				v-model.number="draftDays"
				type="number"
				min="0"
				class="vbh-short"
				:aria-label="t('Tage ab Periodenbeginn')"
				:aria-invalid="inputError ? 'true' : 'false'">
			<span>{{ t('Tage') }}</span>
			<select v-model="draftDirection" :aria-label="t('Nach oder vor Periodenbeginn')">
				<option value="after">
					{{ t('nach') }}
				</option>
				<option value="before">
					{{ t('vor') }}
				</option>
			</select>
			<span>{{ t('Beginn jeder Periode') }}</span>
		</div>
		<p v-if="inputError" class="vbh-hint vbh-hint--error" role="alert">
			{{ inputError }}
		</p>
		<p v-else-if="meaning" class="vbh-hint vbh-interval-meaning" :data-testid="'offset-meaning-' + intervalMonths">
			<span aria-hidden="true">→ </span>{{ meaning }}
		</p>

		<div class="vbh-tablecard">
			<table class="vbh-table vbh-schedule-table">
				<thead>
					<tr>
						<th>{{ t('Periode') }}</th>
						<th>{{ t('Einzug') }}</th>
						<th class="vbh-col-schedule-source">
							{{ t('Termin') }}
						</th>
						<th class="vbh-col-schedule-actions" />
					</tr>
				</thead>
				<tbody>
					<template v-if="preview">
						<tr
							v-for="period in periods"
							:key="period.periodIndex"
							:class="{ 'vbh-schedule-row--conflict': conflicted.has(period.periodIndex) }">
							<td>
								{{ periodName(intervalMonths, period.periodIndex, period.start, language) }}
								<span v-if="periodRange(period.start, period.end, language)" class="vbh-schedule-range">
									{{ periodRange(period.start, period.end, language) }}
								</span>
							</td>
							<td>
								<template v-if="isEditing(period)">
									<input
										v-model="editing.date"
										type="date"
										:aria-label="t('Einzugstermin für {periodName}', { periodName: periodName(intervalMonths, period.periodIndex, period.start, language) })"
										@keydown.enter.prevent="applyEdit(period)"
										@keydown.esc.prevent="editing = null">
									<p v-if="editError" class="vbh-hint vbh-hint--error" role="alert">
										{{ editError }}
									</p>
									<p v-else-if="editLeapNote" class="vbh-hint vbh-schedule-note">
										{{ editLeapNote }}
									</p>
								</template>
								<span v-else class="nowrap">{{ dueDateLabel(period.dueDate, language) }}</span>
							</td>
							<td>
								<span v-if="period.overridden" class="vbh-pill">{{ t('Abweichend') }}</span>
								<span v-else class="vbh-pill vbh-pill--quiet">{{ t('Standard') }}</span>
							</td>
							<td class="right">
								<template v-if="isEditing(period)">
									<NcButton size="small" @click="applyEdit(period)">
										{{ t('Übernehmen') }}
									</NcButton>
									<NcButton size="small" variant="tertiary" @click="editing = null">
										{{ t('Abbrechen') }}
									</NcButton>
								</template>
								<template v-else>
									<NcButton size="small" variant="tertiary" @click="startEdit(period)">
										{{ t('Termin ändern') }}
									</NcButton>
									<NcButton
										v-if="period.overridden"
										size="small"
										variant="tertiary"
										@click="resetOverride(period.periodIndex)">
										{{ t('Zurücksetzen') }}
									</NcButton>
								</template>
							</td>
						</tr>
					</template>
					<tr v-else>
						<td colspan="4" class="vbh-hint">
							{{ previewError || t('Termine werden berechnet …') }}
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<p v-if="preview && previewError" class="vbh-hint vbh-hint--error" role="alert">
			{{ previewError }}
		</p>
		<ul v-if="violationMessages.length > 0" class="vbh-hint vbh-hint--error vbh-schedule-violations" role="alert">
			<li v-for="message in violationMessages" :key="message">
				{{ message }}
			</li>
		</ul>

		<div v-if="dirty" class="vbh-interval-actions">
			<span class="vbh-hint">{{ t('Änderungen noch nicht gespeichert.') }}</span>
			<NcButton variant="tertiary" @click="reset">
				{{ t('Verwerfen') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canSave" @click="save">
				{{ t('Speichern') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { getLanguage } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import api from '../api.js'
import { daysBetween } from '../lib/debitRun.js'
import {
	dueDateLabel,
	joinOffset,
	leapShiftNote,
	offsetError,
	offsetMeaning,
	periodName,
	periodRange,
	scheduleChanged,
	splitOffset,
	violationMessage,
} from '../lib/dueDateSchedule.js'
import { errMsg } from '../lib/format.js'
import { intervalLabel } from '../lib/frequency.js'

/** Wie lange die Eingabe ruhen muss, bevor die Vorschau neu rechnet. */
const PREVIEW_DELAY_MS = 250

/**
 * Der Terminplan eines Turnus als Entwurf mit Live-Vorschau: der
 * Standard-Einzugstag als Satz („Einzug [14] Tage [nach] Beginn jeder
 * Periode“), darunter die Perioden des gezeigten Beitragsjahrs mit ihrem
 * Termin. Ein abweichender Termin wird als Datum gewählt und als Tage-Versatz
 * gespeichert. Termine und Verstöße rechnet der Server
 * (DueDateScheduleService::preview()) mit denselben Guards wie beim
 * Speichern, deshalb steht ein Fehler schon vor dem Speichern an der Periode.
 * Gespeichert wird der ganze Turnus in einem Schritt.
 */
export default {
	name: 'DueDateIntervalSchedule',
	components: { NcButton },

	props: {
		intervalMonths: { type: Number, required: true },
		/** gespeicherter Terminplan des Turnus: { defaultOffsetDays, overrides } */
		saved: { type: Object, default: () => ({ defaultOffsetDays: 0, overrides: {} }) },
		memberCount: { type: Number, default: 0 },
		/** Anker-Kalenderjahr des gezeigten Beitragsjahrs; null = das laufende */
		year: { type: Number, default: null },
		/** Grenzen des Tage-Versatzes: { min, max } */
		offsetRange: { type: Object, required: true },
	},

	emits: ['saved', 'due-dates'],

	data() {
		return {
			language: getLanguage(),
			draftDays: 0,
			draftDirection: 'after',
			draftOverrides: {},
			preview: null,
			previewError: '',
			previewSeq: 0,
			previewTimer: null,
			editing: null,
			saving: false,
		}
	},

	computed: {
		draftDefault() {
			return joinOffset(this.draftDays, this.draftDirection)
		},

		inputError() {
			return offsetError(this.draftDefault, this.offsetRange)
		},

		meaning() {
			return offsetMeaning(this.intervalMonths, this.draftDefault)
		},

		dirty() {
			return this.draftDefault === null || scheduleChanged(this.saved, this.draftDefault, this.draftOverrides)
		},

		periods() {
			return this.preview?.periods ?? []
		},

		violations() {
			return this.preview?.violations ?? []
		},

		violationMessages() {
			return this.violations.map((violation) => violationMessage(violation, this.intervalMonths, this.periods, this.language))
		},

		/** Die Perioden, die an einem Verstoß beteiligt sind – beide Seiten eines Überholens. */
		conflicted() {
			const indices = new Set()
			for (const violation of this.violations) {
				indices.add(violation.periodIndex)
				if (violation.previousPeriodIndex !== undefined) {
					indices.add(violation.previousPeriodIndex)
				}
			}
			return indices
		},

		canSave() {
			return !this.saving && !this.inputError && !this.editing && this.preview !== null && !this.previewError && this.violations.length === 0
		},

		/** Alles, wovon die Vorschau abhängt. */
		previewKey() {
			return JSON.stringify([this.draftDefault, this.draftOverrides, this.year])
		},

		editPeriod() {
			return this.editing ? this.periods.find((p) => p.periodIndex === this.editing.periodIndex) : null
		},

		editOffset() {
			return this.editPeriod && this.editing.date ? daysBetween(this.editPeriod.start, this.editing.date) : null
		},

		editError() {
			if (!this.editing) { return '' }
			if (!this.editing.date) { return this.t('Bitte ein Datum wählen.') }
			return offsetError(this.editOffset, this.offsetRange) ?? ''
		},

		editLeapNote() {
			return this.editPeriod && this.editing.date ? leapShiftNote(this.editPeriod.start, this.editing.date) : ''
		},
	},

	watch: {
		saved: {
			deep: true,
			immediate: true,
			handler() {
				this.reset()
			},
		},

		previewKey() {
			clearTimeout(this.previewTimer)
			this.previewTimer = setTimeout(() => this.requestPreview(), PREVIEW_DELAY_MS)
		},
	},

	mounted() {
		this.requestPreview()
	},

	beforeUnmount() {
		clearTimeout(this.previewTimer)
	},

	methods: {
		dueDateLabel,
		intervalLabel,
		periodName,
		periodRange,

		reset() {
			const { days, direction } = splitOffset(this.saved?.defaultOffsetDays ?? 0)
			this.draftDays = days
			this.draftDirection = direction
			// Ein PHP-Array mit Index 0, 1, … kommt als JSON-Liste – der Spread macht daraus wieder Periodenindex => Versatz.
			this.draftOverrides = { ...(this.saved?.overrides || {}) }
			this.editing = null
		},

		async requestPreview() {
			if (this.inputError) { return }
			const seq = ++this.previewSeq
			try {
				const { data } = await api.previewDueDateSchedule(this.intervalMonths, {
					defaultOffsetDays: this.draftDefault,
					overrides: this.draftOverrides,
					year: this.year,
				})
				if (seq !== this.previewSeq) { return }
				this.preview = data
				this.previewError = ''
				this.$emit('due-dates', data.periods.map((p) => p.dueDate))
			} catch (e) {
				if (seq !== this.previewSeq) { return }
				this.previewError = errMsg(e, this.t('Die Termine konnten nicht berechnet werden'))
			}
		},

		isEditing(period) {
			return this.editing?.periodIndex === period.periodIndex
		},

		startEdit(period) {
			this.editing = { periodIndex: period.periodIndex, date: period.dueDate }
		},

		applyEdit(period) {
			if (this.editError) { return }
			const overrides = { ...this.draftOverrides }
			// Ein Termin, der genau dem Standard entspricht, ist keine Abweichung.
			if (this.editOffset === this.draftDefault) {
				delete overrides[period.periodIndex]
			} else {
				overrides[period.periodIndex] = this.editOffset
			}
			this.draftOverrides = overrides
			this.editing = null
		},

		resetOverride(periodIndex) {
			const overrides = { ...this.draftOverrides }
			delete overrides[periodIndex]
			this.draftOverrides = overrides
		},

		async save() {
			if (!this.canSave) { return }
			this.saving = true
			try {
				await api.saveDueDateSchedule(this.intervalMonths, { defaultOffsetDays: this.draftDefault, overrides: this.draftOverrides })
				showSuccess(this.t('Terminplan für {interval} gespeichert.', { interval: intervalLabel(this.intervalMonths) }))
				this.$emit('saved')
			} catch (e) {
				showError(errMsg(e, this.t('Terminplan konnte nicht gespeichert werden')))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-interval {
	padding: 12px 0;
	border-top: 1px solid var(--color-border);
}

.vbh-interval-head {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	justify-content: space-between;
	gap: 4px 12px;
}

.vbh-interval-head h5 {
	margin: 0;
	font-weight: 700;
}

.vbh-interval-rule {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px 8px;
	margin-top: 8px;
}

.vbh-interval-rule input.vbh-short {
	width: 80px;
}

.vbh-interval-meaning {
	margin: 4px 0 0;
}

.vbh-schedule-range {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.vbh-schedule-table thead th.vbh-col-schedule-source {
	width: 120px;
}

.vbh-schedule-table thead th.vbh-col-schedule-actions {
	width: 230px;
}

.vbh-schedule-table tbody tr.vbh-schedule-row--conflict td {
	background-color: var(--color-error);
	color: var(--color-error-text);
}

.vbh-schedule-table input[type='date'] {
	max-width: 100%;
}

.vbh-schedule-note {
	margin: 4px 0 0;
	font-size: 0.9em;
}

.vbh-schedule-violations {
	margin: 0 0 8px;
	padding-inline-start: 28px;
}

.vbh-interval-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: flex-end;
	gap: 8px;
}
</style>
