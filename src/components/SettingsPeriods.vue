<template>
	<div>
		<!-- KARTE 1: die Geschäftsjahr-Regel.
		     Überschrift „Regel", nicht „Geschäftsjahr": so heißt schon der
		     Abschnitt drumherum (NcSettingsSection in SettingsApp.vue), und
		     zweimal dasselbe Wort untereinander liest sich wie ein Fehler.
		     Zusammen mit der zweiten Karte ergibt das Regel → Zeiträume. -->
		<div class="vbh-card">
			<h4>{{ t('Regel') }}</h4>
			<p class="vbh-hint">
				{{ t('Ein Geschäftsjahr muss kein Kalenderjahr sein: Vereine rechnen häufig von Oktober bis September, Kindergärten im Schuljahr, studentische Vereine in Semestern. Die Regel legt fest, wann ein Zeitraum beginnt und wie lang er ist – daraus entsteht die Kette der Zeiträume unten.') }}
			</p>

			<!-- Bewusst ohne .vbh-form: das ist eine Flex-Zeile, und fünf Vorlagen
			     mit ausgeschriebenen Monatsnamen nebeneinander sind unlesbar. Ein
			     schlichtes div lässt die Schalter untereinander stehen; role und
			     aria-label geben der Gruppe den Namen, den sonst ein <fieldset>
			     mit <legend> gäbe (dessen Rahmen hier aber stören würde). -->
			<div role="radiogroup" :aria-label="t('Geschäftsjahr-Regel')">
				<NcCheckboxRadioSwitch
					v-for="opt in presetOptions"
					:key="opt.value"
					v-model="rule.preset"
					:value="opt.value"
					name="vbh-period-preset"
					type="radio"
					@update:modelValue="applyPreset(opt.value)">
					{{ opt.label }}
				</NcCheckboxRadioSwitch>
			</div>

			<!-- Nur bei „Eigene Regel": bei einer Vorlage stünden hier drei Felder,
			     die man nicht anfassen darf, weil sie die Vorlage definieren. -->
			<div v-if="rule.preset === 'custom'" class="vbh-form">
				<label>{{ t('Beginnt am Tag') }}
					<input
						v-model.number="rule.startDay"
						type="number"
						min="1"
						max="31"
						class="vbh-addyear-input">
				</label>
				<label>{{ t('im Monat') }}
					<select v-model.number="rule.startMonth">
						<option v-for="(name, i) in monthNames" :key="i" :value="i + 1">
							{{ name }}
						</option>
					</select>
				</label>
				<label>{{ t('Länge') }}
					<select v-model.number="rule.lengthMonths">
						<option v-for="l in lengths" :key="l" :value="l">
							{{ n('%n Monat', '%n Monate', l) }}
						</option>
					</select>
				</label>
			</div>
			<p v-if="rule.preset === 'custom'" class="vbh-hint">
				{{ t('Wählbar sind nur Längen, die 12 teilen. Eine andere Länge liefe Jahr für Jahr gegen den Kalender – nach einigen Zeiträumen begänne das Geschäftsjahr in einem anderen Monat als am Anfang.') }}
			</p>

			<p v-if="ruleError" class="vbh-warn-inline">
				{{ ruleError }}
			</p>
			<p v-else-if="exampleRange" class="vbh-hint">
				{{ t('Beispiel: {from} – {to}', exampleRange) }}
			</p>

			<NcButton variant="secondary" :disabled="previewLoading || !!ruleError" @click="openPreview">
				{{ t('Vorschau') }}
			</NcButton>
		</div>

		<!-- KARTE 2: die Zeiträume selbst -->
		<div class="vbh-card">
			<div class="vbh-sectionhead">
				<h4>{{ t('Zeiträume') }}</h4>
				<NcButton
					variant="secondary"
					:disabled="creating"
					:title="t('Legt den Zeitraum nach dem bisher letzten an – nach der oben eingestellten Regel')"
					@click="createNext">
					{{ t('Nächsten Zeitraum anlegen') }}
				</NcButton>
			</div>
			<p class="vbh-hint">
				{{ t('Ein abgeschlossener Zeitraum ist') }} <strong>{{ t('festgeschrieben') }}</strong>{{ t(': Buchungen, Belege und Zuordnungen dieses Zeitraums können nicht mehr geändert oder gelöscht werden – z. B. nach der Kassenprüfung und Entlastung. Nur Verwalter können einen Zeitraum abschließen oder wiedereröffnen; beides wird im Protokoll (Berichte → Protokoll) festgehalten.') }}
			</p>
			<div v-if="periods.length" class="vbh-tablecard">
				<table class="vbh-table">
					<thead>
						<tr>
							<th>{{ t('Bezeichnung') }}</th>
							<th>{{ t('Von–Bis') }}</th>
							<th>{{ t('Status') }}</th>
							<th class="right" />
						</tr>
					</thead>
					<tbody>
						<tr v-for="(p, i) in periods" :key="p.id">
							<td class="strong">
								<input
									v-if="editingId === p.id"
									ref="labelInput"
									v-model="editingLabel"
									type="text"
									maxlength="64"
									class="vbh-rename"
									:aria-label="t('Bezeichnung des Zeitraums')"
									@keyup.enter="saveLabel(p)"
									@keyup.esc="editingId = null"
									@blur="saveLabel(p)">
								<!-- Klick zum Umbenennen. Bewusst als role="button" statt als
								     echter <button>: eine Schaltfläche brächte ihre eigene
								     Optik in die Tabellenzelle, hier soll die Bezeichnung wie
								     Text aussehen (Muster wie die tappable-Karten in
								     ReportsTab.vue). -->
								<span
									v-else
									role="button"
									tabindex="0"
									:title="t('Zum Umbenennen anklicken')"
									@click="startEdit(p)"
									@keyup.enter="startEdit(p)">{{ p.label }}</span>
							</td>
							<td class="nowrap">
								{{ formatDate(p.startDate) }} –
								<!-- Das Ende ist die gemeinsame Grenze mit dem folgenden
								     Zeitraum; sie zu verschieben ist der Weg zu einem
								     Rumpfgeschäftsjahr beim Umstieg. -->
								<input
									type="date"
									:value="p.endDate"
									:disabled="!canMoveEnd(i)"
									:title="endTitle(i)"
									:aria-label="t('Ende des Zeitraums {label}', { label: p.label })"
									@change="moveEnd(p, $event)">
							</td>
							<td>
								<template v-if="p.closedAt">
									{{ t('🔒 abgeschlossen am {date} von {who}', { date: formatDate(String(p.closedAt).slice(0, 10)), who: p.closedBy }) }}
								</template>
								<template v-else>
									{{ t('offen') }}
								</template>
							</td>
							<td class="right nowrap">
								<div class="vbh-actions">
									<NcButton
										v-if="canDelete(i)"
										variant="tertiary"
										size="small"
										:title="t('Zeitraum entfernen (er enthält weder Buchungen noch Planwerte)')"
										@click="removePeriod(p)">
										{{ t('Entfernen') }}
									</NcButton>
									<NcButton
										v-if="!p.closedAt"
										variant="primary"
										size="small"
										@click="closePeriod(p)">
										{{ t('Abschließen') }}
									</NcButton>
									<NcButton
										v-else
										variant="tertiary"
										size="small"
										@click="reopenPeriod(p)">
										{{ t('Wiedereröffnen') }}
									</NcButton>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<p v-else class="vbh-hint">
				{{ t('Noch keine Zeiträume vorhanden.') }}
			</p>
		</div>

		<!-- Vorschau der Umstellung. Erst hier steht „Übernehmen": die Umstellung
		     ordnet jede Buchung neu zu und kann Planwerte verwerfen, das mutet man
		     niemandem ungefragt zu. -->
		<NcModal
			v-if="previewOpen"
			labelId="vbh-modal-title-periodrule"
			size="normal"
			@close="previewOpen = false">
			<div class="vbh-modal-inner">
				<h2 id="vbh-modal-title-periodrule" class="vbh-modal-title">
					{{ t('Geschäftsjahr umstellen') }}
				</h2>
				<template v-if="rulePreview">
					<p class="vbh-hint">
						{{ t('So sähe die Kette der Zeiträume nach der Umstellung aus:') }}
					</p>
					<div class="vbh-tablecard">
						<table class="vbh-table">
							<thead>
								<tr>
									<th>{{ t('Bezeichnung') }}</th>
									<th class="nowrap">
										{{ t('Von') }}
									</th>
									<th class="nowrap">
										{{ t('Bis') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="(p, i) in rulePreview.periods" :key="i">
									<td class="strong">
										{{ p.label }}
									</td>
									<td class="nowrap">
										{{ formatDate(p.startDate) }}
									</td>
									<td class="nowrap">
										{{ formatDate(p.endDate) }}
									</td>
								</tr>
							</tbody>
						</table>
					</div>
					<p class="vbh-hint">
						{{ n('%n Buchung wechselt dabei den Zeitraum.', '%n Buchungen wechseln dabei den Zeitraum.', rulePreview.bookingsMoved) }}
					</p>
					<!-- Nur wenn wirklich etwas verloren ginge - dann aber deutlich:
					     ein verworfener Planwert lässt sich nicht wiederherstellen. -->
					<p v-if="rulePreview.budgetsDropped > 0" class="vbh-warn-inline">
						{{ n('⚠ %n Planwert geht dabei verloren und lässt sich nicht wiederherstellen.', '⚠ %n Planwerte gehen dabei verloren und lassen sich nicht wiederherstellen.', rulePreview.budgetsDropped) }}
					</p>
					<div v-if="rulePreview.closed.length" class="vbh-yearwarn vbh-yearwarn--block">
						<p class="vbh-warn-inline">
							{{ t('⛔ Umstellung nicht möglich: diese Zeiträume sind festgeschrieben und müssten zuerst wiedereröffnet werden.') }}
						</p>
						<ul class="vbh-yearwarn-list">
							<li v-for="label in rulePreview.closed" :key="label">
								{{ label }}
							</li>
						</ul>
					</div>
				</template>
				<div class="vbh-modal-actions">
					<NcButton variant="secondary" @click="previewOpen = false">
						{{ t('Abbrechen') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="applying || !rulePreview || rulePreview.closed.length > 0"
						@click="applyRule">
						{{ t('Übernehmen') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcCheckboxRadioSwitch, NcModal } from '@nextcloud/vue'
import { toRefs } from 'vue'
import api from '../api.js'
import { useConfirm } from '../composables/useConfirm.js'
import { usePeriods } from '../composables/usePeriods.js'
import { errMsg, formatDate } from '../lib/format.js'

/**
 * Einstellungen → Geschäftsjahr (Issue #8).
 *
 * Löst SettingsYearClose.vue ab. Dort gab es nur eine Liste von Jahreszahlen
 * mit „Abschließen"/„Wiedereröffnen" – ein Geschäftsjahr war nichts, was man
 * hätte einstellen können. Jetzt sind es zwei Karten: oben die Regel, nach der
 * Zeiträume entstehen, unten die Zeiträume selbst.
 */
export default {
	name: 'SettingsPeriods',
	components: { NcButton, NcCheckboxRadioSwitch, NcModal },
	setup() {
		// periods/loadPeriods kommen direkt aus dem usePeriods-Singleton
		// (gleicher geteilter Zustand wie in App.vue).
		const periods = usePeriods()
		return {
			...toRefs(periods.state),
			loadPeriods: periods.loadPeriods,
			askConfirm: useConfirm().askConfirm,
		}
	},

	data() {
		return {
			// Die geltende Regel; wird beim Laden vom Server überschrieben.
			rule: { preset: 'calendar', startDay: 1, startMonth: 1, lengthMonths: 12 },
			// Vorlagen und erlaubte Längen kommen vom Server, damit Frontend und
			// PeriodRule.php nicht zwei getrennte Wahrheiten pflegen.
			presets: {},
			lengths: [12],
			// Ergebnis des letzten Probelaufs (savePeriodRule mit dryRun): speist
			// sowohl die Beispielzeile als auch den Dialog.
			rulePreview: null,
			ruleError: '',
			previewLoading: false,
			previewOpen: false,
			applying: false,
			creating: false,
			// Inline-Umbenennung: die ID der Zeile, die gerade bearbeitet wird.
			editingId: null,
			editingLabel: '',
		}
	},

	computed: {
		monthNames() {
			return [
				this.t('Januar'),
				this.t('Februar'),
				this.t('März'),
				this.t('April'),
				this.t('Mai'),
				this.t('Juni'),
				this.t('Juli'),
				this.t('August'),
				this.t('September'),
				this.t('Oktober'),
				this.t('November'),
				this.t('Dezember'),
			]
		},

		// Die vier Vorlagen plus „Eigene Regel". Die Reihenfolge ist fest, nicht
		// die des Server-Objekts: sie geht vom Häufigsten zum Seltensten.
		presetOptions() {
			return [
				{ value: 'calendar', label: this.t('Kalenderjahr (1. Januar – 31. Dezember)') },
				{ value: 'oct-sep', label: this.t('Oktober – September') },
				{ value: 'aug-jul', label: this.t('August – Juli (Schuljahr)') },
				{ value: 'semester', label: this.t('Semester (halbjährlich ab Oktober)') },
				{ value: 'custom', label: this.t('Eigene Regel') },
			]
		},

		/**
		 * Die Beispielzeile unter der Regel: der Zeitraum, in dem heute liegt.
		 * Er kommt aus der Vorschau und nicht aus einer eigenen Rechnung – die
		 * Regel gehört dem Server, und zwei Rechenwege wären zwei Wahrheiten.
		 */
		exampleRange() {
			const rows = this.rulePreview?.periods || []
			if (!rows.length) { return null }
			const today = new Date().toISOString().slice(0, 10)
			const row = rows.find((p) => today >= p.startDate && today <= p.endDate) || rows[rows.length - 1]
			return { from: formatDate(row.startDate), to: formatDate(row.endDate) }
		},
	},

	watch: {
		// Jede Änderung an der Regel zieht einen neuen Probelauf nach sich, sonst
		// stünde unter geänderten Feldern ein Beispiel von vorhin. Entprellt,
		// damit das Tippen im Tagesfeld nicht drei Anfragen auslöst.
		rule: {
			deep: true,
			handler() {
				clearTimeout(this.previewTimer)
				this.previewTimer = setTimeout(() => this.refreshPreview(), 400)
			},
		},
	},

	// previewTimer liegt bewusst NICHT in data(): eine Timer-Kennung wird
	// nirgends im Template gelesen und braucht keine Reaktivitaet (gleiches
	// Muster wie chartInstances in ReportsTab.vue).
	created() {
		this.previewTimer = null
	},

	mounted() {
		this.loadRule()
	},

	beforeUnmount() {
		clearTimeout(this.previewTimer)
	},

	methods: {
		formatDate,
		errMsg,

		async loadRule() {
			try {
				const { data } = await api.periodRule()
				this.presets = data.presets || {}
				this.lengths = data.lengths || [12]
				// Setzt den Watcher in Gang und holt damit auch die erste Vorschau.
				this.rule = { ...data.rule }
			} catch (e) { showError(this.errMsg(e, this.t('Geschäftsjahr-Regel konnte nicht geladen werden'))) }
		},

		// Eine Vorlage füllt Tag, Monat und Länge mit; „Eigene Regel" lässt die
		// zuletzt gezeigten Werte stehen, damit man von einer Vorlage aus
		// weiterbauen kann.
		applyPreset(preset) {
			const values = this.presets[preset]
			if (!values) { return }
			this.rule = { preset, ...values }
		},

		async refreshPreview() {
			this.previewLoading = true
			try {
				const { data } = await api.savePeriodRule(this.rule, true)
				this.rulePreview = data
				this.ruleError = ''
			} catch (e) {
				this.rulePreview = null
				this.ruleError = this.errMsg(e, this.t('Diese Regel ergibt keine gültigen Zeiträume.'))
			} finally { this.previewLoading = false }
		},

		async openPreview() {
			// Ein noch laufender Probelauf oder ein leeres Ergebnis (etwa nach
			// einem Fehler) darf keinen leeren Dialog öffnen.
			if (!this.rulePreview) { await this.refreshPreview() }
			if (this.rulePreview) { this.previewOpen = true }
		},

		async applyRule() {
			this.applying = true
			try {
				const { data } = await api.savePeriodRule(this.rule, false)
				this.previewOpen = false
				await this.loadPeriods()
				await this.loadRule()
				showSuccess(this.t('Geschäftsjahr umgestellt: {periods} Zeiträume, {moved} Buchungen neu zugeordnet.', { periods: data.periods, moved: data.bookingsMoved }))
			} catch (e) { showError(this.errMsg(e, this.t('Umstellung fehlgeschlagen'))) } finally { this.applying = false }
		},

		async createNext() {
			this.creating = true
			try {
				const { data } = await api.createPeriod()
				await this.loadPeriods()
				showSuccess(this.t('Zeitraum {label} angelegt.', { label: data.label }))
			} catch (e) { showError(this.errMsg(e, this.t('Zeitraum konnte nicht angelegt werden'))) } finally { this.creating = false }
		},

		startEdit(period) {
			this.editingId = period.id
			this.editingLabel = period.label
			this.$nextTick(() => {
				// Refs in einem v-for sammelt Vue 3 in einem Array – auch wenn hier
				// immer nur eine Zeile im Bearbeitungsmodus ist.
				const el = this.$refs.labelInput
				const input = Array.isArray(el) ? el[0] : el
				input?.focus()
				input?.select()
			})
		},

		/**
		 * Speichert die Bezeichnung. Wird von Enter UND vom anschließenden blur
		 * aufgerufen; editingId wird deshalb zuerst zurückgesetzt, sonst liefe der
		 * zweite Aufruf noch einmal in den Server.
		 */
		async saveLabel(period) {
			if (this.editingId !== period.id) { return }
			const label = this.editingLabel.trim()
			this.editingId = null
			if (!label || label === period.label) { return }
			try {
				await api.updatePeriod(period.id, { label })
				await this.loadPeriods()
				showSuccess(this.t('Zeitraum umbenannt.'))
			} catch (e) { showError(this.errMsg(e, this.t('Umbenennen fehlgeschlagen'))) }
		},

		/**
		 * Der folgende Zeitraum – state.periods ist absteigend sortiert, er steht
		 * also eine Zeile weiter oben.
		 */
		following(index) {
			return index > 0 ? this.periods[index - 1] : null
		},

		// Die gemeinsame Grenze zweier Zeiträume darf sich nur bewegen, solange
		// beide offen sind: sonst verschöbe sich nachträglich, was ein bereits
		// archivierter Kassenbericht ausweist.
		canMoveEnd(index) {
			const period = this.periods[index]
			const next = this.following(index)
			return !period.closedAt && !(next && next.closedAt)
		},

		endTitle(index) {
			const period = this.periods[index]
			const next = this.following(index)
			if (period.closedAt) {
				return this.t('{label} ist abgeschlossen – die Grenze lässt sich nicht mehr verschieben.', { label: period.label })
			}
			if (next && next.closedAt) {
				return this.t('Der folgende Zeitraum {label} ist abgeschlossen – die gemeinsame Grenze lässt sich nicht mehr verschieben.', { label: next.label })
			}
			return this.t('Ende verschieben – der folgende Zeitraum beginnt dann am Tag darauf.')
		},

		/**
		 * Das Ereignis statt nur des Wertes, weil das Feld nach einer Ablehnung
		 * zurückgesetzt werden muss: `:value` bleibt dann unverändert, und Vue
		 * schreibt einen unveränderten Wert nicht ins DOM – im Feld stünde
		 * weiter ein Datum, das der Server gar nicht übernommen hat.
		 *
		 * @param {object} period der Zeitraum dieser Zeile
		 * @param {Event} event das change-Ereignis des Datumsfelds
		 */
		async moveEnd(period, event) {
			const input = event.target
			const endDate = input.value
			if (!endDate || endDate === period.endDate) { return }
			try {
				await api.updatePeriod(period.id, { endDate })
				await this.loadPeriods()
				showSuccess(this.t('Grenze von {label} verschoben.', { label: period.label }))
			} catch (e) {
				input.value = period.endDate
				showError(this.errMsg(e, this.t('Grenze konnte nicht verschoben werden')))
			}
		},

		// Entfernen nur am Rand der Kette und nur, wenn nichts daran hängt: eine
		// Lücke in der Mitte bräche die Zusicherung, dass jedes Datum zu genau
		// einem Zeitraum gehört (dieselbe Prüfung steht im PeriodService).
		canDelete(index) {
			const period = this.periods[index]
			const isEdge = index === 0 || index === this.periods.length - 1
			return isEdge && !period.closedAt && period.bookings === 0 && period.planValues === 0
		},

		async removePeriod(period) {
			if (!await this.askConfirm(
				this.t('Zeitraum {label} entfernen', { label: period.label }),
				this.t('Der Zeitraum {label} enthält weder Buchungen noch Planwerte und wird entfernt.', { label: period.label }),
				this.t('Entfernen'),
				'error',
			)) { return }
			try {
				await api.deletePeriod(period.id)
				await this.loadPeriods()
				showSuccess(this.t('Zeitraum {label} entfernt.', { label: period.label }))
			} catch (e) { showError(this.errMsg(e, this.t('Entfernen fehlgeschlagen'))) }
		},

		async closePeriod(period) {
			if (!await this.askConfirm(
				this.t('Zeitraum {label} abschließen', { label: period.label }),
				this.t('Der Zeitraum {label} ({from} – {to}) wird festgeschrieben: Buchungen, Belege und Zuordnungen dieses Zeitraums können danach nicht mehr geändert werden. Ein Verwalter kann ihn bei Bedarf wiedereröffnen.', { label: period.label, from: formatDate(period.startDate), to: formatDate(period.endDate) }),
				this.t('Abschließen'),
				'primary',
			)) { return }
			try {
				await api.closePeriod(period.id)
				await this.loadPeriods()
				showSuccess(this.t('Zeitraum {label} abgeschlossen.', { label: period.label }))
			} catch (e) { showError(this.errMsg(e, this.t('Abschließen fehlgeschlagen'))) }
		},

		async reopenPeriod(period) {
			if (!await this.askConfirm(
				this.t('Zeitraum {label} wiedereröffnen', { label: period.label }),
				this.t('Der Zeitraum {label} wird wieder änderbar. Das sollte nur in Ausnahmefällen geschehen (z. B. Korrektur vor der Kassenprüfung) und wird protokolliert.', { label: period.label }),
				this.t('Wiedereröffnen'),
				'error',
			)) { return }
			try {
				await api.reopenPeriod(period.id)
				await this.loadPeriods()
				showSuccess(this.t('Zeitraum {label} wiedereröffnet.', { label: period.label }))
			} catch (e) { showError(this.errMsg(e, this.t('Wiedereröffnen fehlgeschlagen'))) }
		},
	},
}
</script>
