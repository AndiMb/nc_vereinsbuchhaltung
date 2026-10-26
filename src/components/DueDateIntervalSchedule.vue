<template>
	<div class="vbh-interval" :data-testid="'schedule-interval-' + intervalMonths">
		<div class="vbh-interval-head">
			<h3>{{ label }}</h3>
			<span class="vbh-hint">{{ n('%n Mitglied', '%n Mitglieder', memberCount) }}</span>
		</div>

		<div class="vbh-interval-rule" role="group" :aria-label="t('Einzugstag für {interval}', { interval: label })">
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
			<table class="vbh-table vbh-stack vbh-schedule-table">
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
							v-for="row in rows"
							:key="row.periodIndex"
							:class="{ 'vbh-schedule-row--conflict': conflicted.has(row.periodIndex) }">
							<td>
								{{ row.name }}
								<span v-if="row.range" class="vbh-schedule-range">{{ row.range }}</span>
							</td>
							<td>
								<template v-if="isEditing(row)">
									<input
										v-model="editing.date"
										type="date"
										:aria-label="t('Einzugstermin für {periodName}', { periodName: row.name })"
										@keydown.enter.prevent="applyEdit"
										@keydown.esc.prevent="editing = null">
									<p v-if="editError" class="vbh-hint vbh-hint--error" role="alert">
										{{ editError }}
									</p>
									<p v-else-if="editLeapNote" class="vbh-hint vbh-schedule-note">
										{{ editLeapNote }}
									</p>
								</template>
								<span v-else class="nowrap">{{ row.dueLabel }}</span>
							</td>
							<td>
								<span v-if="row.overridden" class="vbh-pill">{{ t('Abweichend') }}</span>
								<span v-else class="vbh-pill vbh-pill--quiet">{{ t('Standard') }}</span>
							</td>
							<td class="right">
								<template v-if="isEditing(row)">
									<NcButton size="small" @click="applyEdit">
										{{ t('Übernehmen') }}
									</NcButton>
									<NcButton size="small" variant="tertiary" @click="editing = null">
										{{ t('Abbrechen') }}
									</NcButton>
								</template>
								<template v-else>
									<NcButton size="small" variant="tertiary" @click="startEdit(row)">
										{{ t('Termin ändern') }}
									</NcButton>
									<NcButton
										v-if="row.overridden"
										size="small"
										variant="tertiary"
										@click="setDraftOverride(row.periodIndex, null)">
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

		<!-- Gespeichert wird über die Fußleiste des Dialogs, für alle Turnusse zusammen. -->
		<div v-if="dirty" class="vbh-interval-actions">
			<span class="vbh-hint">{{ t('Änderungen noch nicht gespeichert.') }}</span>
			<NcButton variant="tertiary" @click="reset">
				{{ t('Verwerfen') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { getLanguage } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
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
 * Gespeichert wird der ganze Turnus in einem Schritt, ausgelöst vom Dialog
 * (DueDateScheduleDialog.vue) über save().
 */
export default {
	name: 'DueDateIntervalSchedule',
	components: { NcButton },

	props: {
		intervalMonths: { type: Number, required: true },
		/** gespeicherter Terminplan des Turnus: { defaultOffsetDays, overrides } */
		saved: { type: Object, required: true },
		memberCount: { type: Number, default: 0 },
		/** Anker-Kalenderjahr des gezeigten Beitragsjahrs */
		year: { type: Number, required: true },
		/** Grenzen des Tage-Versatzes: { min, max } */
		offsetRange: { type: Object, required: true },
	},

	// saved: der gespeicherte Terminplan des Turnus aus der Antwort des Servers;
	// state: { dirty, valid } – ob ein ungespeicherter Entwurf besteht und ob er
	// sich so speichern ließe (für die Fußleiste und die Rückfrage beim Schließen).
	emits: ['saved', 'state'],

	expose: ['save'],

	data() {
		return {
			language: getLanguage(),
			draftDays: 0,
			draftDirection: 'after',
			draftOverrides: {},
			preview: null,
			// Die Vorschau gehört noch zu einem älteren Entwurf (Eingabe ruht noch nicht oder Antwort steht aus).
			previewPending: false,
			previewError: '',
			previewSeq: 0,
			previewTimer: null,
			editing: null,
		}
	},

	computed: {
		label() {
			return intervalLabel(this.intervalMonths)
		},

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
			return scheduleChanged(this.saved, this.draftDefault, this.draftOverrides)
		},

		periods() {
			return this.preview?.periods ?? []
		},

		/** Die Perioden mit ihren Texten, einmal je Vorschau statt bei jedem Rendern. */
		rows() {
			return this.periods.map((period) => ({
				...period,
				name: periodName(this.intervalMonths, period.periodIndex, period.start, this.language),
				range: periodRange(period.start, period.end, this.language),
				dueLabel: dueDateLabel(period.dueDate, this.language),
			}))
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

		/** Ob sich der Entwurf so speichern ließe: geprüft, ohne Verstoß, ohne offene Datumseingabe. */
		valid() {
			return !this.inputError && !this.editing && this.preview !== null && !this.previewPending && !this.previewError && this.violations.length === 0
		},

		state() {
			return { dirty: this.dirty, valid: this.valid }
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

		state: {
			immediate: true,
			handler(value) {
				this.$emit('state', value)
			},
		},

		previewKey() {
			this.previewPending = true
			clearTimeout(this.previewTimer)
			this.previewTimer = setTimeout(() => this.requestPreview(), PREVIEW_DELAY_MS)
		},
	},

	mounted() {
		this.requestPreview()
	},

	beforeUnmount() {
		clearTimeout(this.previewTimer)
		// Ein eingeklappter Turnus verwirft seinen Entwurf.
		this.$emit('state', { dirty: false, valid: true })
	},

	methods: {
		reset() {
			const { days, direction } = splitOffset(this.saved.defaultOffsetDays)
			this.draftDays = days
			this.draftDirection = direction
			this.draftOverrides = { ...this.saved.overrides }
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
			} catch (e) {
				if (seq !== this.previewSeq) { return }
				this.previewError = errMsg(e, this.t('Die Termine konnten nicht berechnet werden'))
			}
			this.previewPending = false
		},

		isEditing(period) {
			return this.editing?.periodIndex === period.periodIndex
		},

		startEdit(period) {
			this.editing = { periodIndex: period.periodIndex, date: period.dueDate }
		},

		applyEdit() {
			if (this.editError) { return }
			// Ein Termin, der genau dem Standard entspricht, ist keine Abweichung.
			this.setDraftOverride(this.editing.periodIndex, this.editOffset === this.draftDefault ? null : this.editOffset)
			this.editing = null
		},

		/** Abweichung einer Periode setzen oder mit null entfernen (zurück zum Standard). */
		setDraftOverride(periodIndex, offsetDays) {
			const overrides = { ...this.draftOverrides }
			if (offsetDays === null) {
				delete overrides[periodIndex]
			} else {
				overrides[periodIndex] = offsetDays
			}
			this.draftOverrides = overrides
		},

		/**
		 * Speichert den Entwurf; aufgerufen vom Dialog.
		 *
		 * @return {Promise<boolean>} ob gespeichert wurde
		 */
		async save() {
			if (!this.valid) { return false }
			try {
				const { data } = await api.saveDueDateSchedule(this.intervalMonths, { defaultOffsetDays: this.draftDefault, overrides: this.draftOverrides })
				this.$emit('saved', data)
				return true
			} catch (e) {
				showError(errMsg(e, this.t('Terminplan für {interval} konnte nicht gespeichert werden', { interval: this.label })))
				return false
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

.vbh-interval-head h3 {
	margin: 0;
	font-size: 1em;
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
