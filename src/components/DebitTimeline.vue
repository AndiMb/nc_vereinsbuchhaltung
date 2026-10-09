<template>
	<section class="vbh-tl" :aria-label="t('Zeitstrahl der Einzugstermine')">
		<header class="vbh-tl-head">
			<div class="vbh-tl-year">
				<NcButton
					variant="tertiary"
					size="small"
					:aria-label="t('Vorheriges Beitragsjahr')"
					@click="$emit('shift-year', -1)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiChevronLeft" :size="20" />
					</template>
				</NcButton>
				<h4>{{ t('Beitragsjahr {jahr}', { jahr: timeline.year.label }) }}</h4>
				<NcButton
					variant="tertiary"
					size="small"
					:aria-label="t('Nächstes Beitragsjahr')"
					@click="$emit('shift-year', 1)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiChevronRight" :size="20" />
					</template>
				</NcButton>
				<NcButton
					v-if="!timeline.year.isCurrent"
					variant="tertiary"
					size="small"
					@click="$emit('shift-year', null)">
					{{ t('Zum laufenden Jahr') }}
				</NcButton>
			</div>
			<!-- Platz für weitere Werkzeuge der Kopfzeile (heute: Terminplan, siehe EinzugPanel) -->
			<div class="vbh-tl-tools">
				<ul v-if="timeline.dates.length" class="vbh-tl-legend" :aria-label="t('Legende')">
					<li><span class="vbh-tl-marker vbh-tl-marker--eingereicht" aria-hidden="true" /> {{ t('eingereicht') }}</li>
					<li><span class="vbh-tl-marker vbh-tl-marker--freigegeben" aria-hidden="true" /> {{ t('freigegeben') }}</li>
					<li><span class="vbh-tl-marker vbh-tl-marker--offen" aria-hidden="true" /> {{ t('Vorschau') }}</li>
				</ul>
				<slot name="toolbar" />
			</div>
		</header>

		<p v-if="timeline.dates.length === 0" class="vbh-hint">
			{{ t('In diesem Beitragsjahr gibt es noch keine Einzugstermine. Sie entstehen aus dem Terminplan, sobald Mitglieder einer Beitragsgruppe zugewiesen sind, oder durch manuelle Forderungen mit eigenem Termin.') }}
		</p>

		<template v-else>
			<!-- Desktop: Strahl über das Beitragsjahr. Er scrollt seitlich, statt die Termine zusammenzuquetschen. -->
			<div v-if="!isMobile" class="vbh-tl-scroll">
				<ol class="vbh-tl-track">
					<li
						v-for="tick in ticks"
						:key="tick.date"
						class="vbh-tl-tick"
						:class="{ 'is-selected': tick.selected }"
						:style="{ left: tick.position + '%' }"
						aria-hidden="true">
						{{ tick.label }}
					</li>
					<li
						v-if="todayInYear"
						class="vbh-tl-today"
						:style="{ left: todayPosition + '%' }">
						<span>{{ t('HEUTE') }}</span>
					</li>
					<li
						v-for="marker in markers"
						:key="marker.entry.dueDate"
						class="vbh-tl-slot"
						:style="{ left: marker.position + '%' }">
						<button
							type="button"
							class="vbh-tl-marker vbh-tl-marker--button"
							:class="[`vbh-tl-marker--${marker.state}`, { 'is-selected': marker.selected, 'is-next': marker.isNext }]"
							:aria-pressed="marker.selected"
							:aria-label="marker.ariaLabel"
							:title="marker.ariaLabel"
							@click="$emit('select', marker.entry.dueDate)" />
					</li>
				</ol>
			</div>

			<!-- Schmal: dieselben Termine als Liste, das HEUTE steht an seiner Stelle dazwischen. -->
			<ul v-else class="vbh-tl-list">
				<li v-for="row in listRows" :key="row.key">
					<div v-if="row.type === 'today'" class="vbh-tl-list-today">
						<span class="vbh-tl-todaypill">{{ t('HEUTE') }}</span>
						{{ formatDate(timeline.today) }}
					</div>
					<button
						v-else
						type="button"
						class="vbh-tl-row vbh-tl-row--button"
						:class="{ 'is-selected': row.marker.selected }"
						:aria-pressed="row.marker.selected"
						@click="$emit('select', row.marker.entry.dueDate)">
						<span class="vbh-tl-marker" :class="`vbh-tl-marker--${row.marker.state}`" aria-hidden="true" />
						<span class="vbh-tl-row-date">{{ formatDate(row.marker.entry.dueDate) }}</span>
						<span class="vbh-tl-row-info">{{ row.marker.summary }}</span>
					</button>
				</li>
			</ul>

			<!-- Dass die Kreise anklickbar sind, sieht man dem Strahl nicht an. Auf dem Handy sind die Termine ohnehin Zeilen mit Knopf. -->
			<p v-if="!isMobile" class="vbh-tl-hint">
				{{ t('Jeder Kreis ist ein Einzugstermin – anklicken, um darunter seine Phasen und die Vorschau zu sehen.') }}
			</p>

			<div v-if="milestones.length" class="vbh-tl-phases">
				<div class="vbh-tl-phaseshead">
					<h5>{{ t('Phasen bis zum Einzug am {datum}', { datum: formatDate(selectedEntry.dueDate) }) }}</h5>
					<!-- Termine dicht beieinander (Prorata-Erstforderung kurz nach einem Planungstermin) überdecken
					     sich auf dem Strahl; so lässt sich trotzdem jeder per Knopf oder Tastatur ansteuern. -->
					<div class="vbh-tl-stepper">
						<NcButton
							variant="tertiary"
							size="small"
							:disabled="!prevDate"
							:aria-label="t('Früherer Termin')"
							@click="$emit('select', prevDate)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiChevronLeft" :size="20" />
							</template>
						</NcButton>
						<NcButton
							variant="tertiary"
							size="small"
							:disabled="!nextDate"
							:aria-label="t('Späterer Termin')"
							@click="$emit('select', nextDate)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiChevronRight" :size="20" />
							</template>
						</NcButton>
					</div>
				</div>
				<ol class="vbh-tl-steps">
					<li
						v-for="m in milestones"
						:key="m.key"
						class="vbh-tl-milestone-item vbh-tl-step"
						:class="[`is-${m.timing}`, { 'is-next': m.key === nextMilestoneKey }]">
						<div class="vbh-tl-step-title">
							<strong>{{ m.label }}</strong>
							<InfoHint v-if="m.info" :label="t('Was bedeutet „{phase}“?', { phase: m.label })" :title="m.label">
								<p>{{ m.info.what }}</p>
								<p><strong>{{ t('Was zu tun ist:') }}</strong> {{ m.info.todo }}</p>
							</InfoHint>
						</div>
						<span class="vbh-tl-step-date">{{ m.formattedDate }}</span>
						<span class="vbh-tl-step-rel">{{ m.relative }}</span>
					</li>
				</ol>
				<p class="vbh-tl-source">
					{{ dateSourceText(selectedEntry.intervals) }}
				</p>
			</div>
		</template>
	</section>
</template>

<script>
import { mdiChevronLeft, mdiChevronRight } from '@mdi/js'
import { getLanguage } from '@nextcloud/l10n'
import { NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import InfoHint from './InfoHint.vue'
import {
	dateSourceText,
	daysBetween,
	entryState,
	entryStateLabel,
	milestoneInfo,
	milestoneLabel,
	monthTicks,
	relativeDays,
	yearPosition,
} from '../lib/debitRun.js'
import { formatDate, formatMoney } from '../lib/format.js'

/**
 * Zeitstrahl über das Beitragsjahr (Spec §6 Variante A, Issue #102): die
 * Einzugstermine aus dem Terminplan (plus manuelle/verschobene), der
 * HEUTE-Marker und, für den gewählten Termin, seine Phasen (Vorwarnung,
 * Vorabinfo, Freigabe-Vorlauf, Einzug) mit je einem Info-Symbol, das erklärt,
 * was die Phase bedeutet und was dabei zu tun ist.
 *
 * Rein darstellend: Daten kommen als `timeline` (Antwort von
 * GET /debit-batches/timeline), Auswahl und Jahreswechsel gehen als Ereignisse
 * nach oben. Auf schmalen Displays wird aus dem Strahl eine Liste – ein Strahl
 * mit zwölf Terminen auf 360 Pixeln Breite wäre nicht mehr bedienbar.
 *
 * Der Strahl trägt bewusst nur Monatsnamen und Marker: Datum und Zustand eines
 * Termins stehen im Tooltip, in der Beschriftung für Bildschirmleser und – für
 * den gewählten Termin – in den Phasen darunter. Jedes Datum zusätzlich unter
 * jeden Marker zu schreiben, verdoppelte nur die Monatsleiste.
 */
export default {
	name: 'DebitTimeline',
	components: { InfoHint, NcButton, NcIconSvgWrapper },
	props: {
		timeline: { type: Object, required: true },
		selectedDate: { type: String, default: null },
		isMobile: { type: Boolean, default: false },
	},

	emits: ['select', 'shift-year'],

	data() {
		return { mdiChevronLeft, mdiChevronRight }
	},

	computed: {
		// Der Monat des gewählten Termins ist in der Leiste hervorgehoben.
		ticks() {
			const selectedMonth = String(this.selectedDate || '').slice(0, 7)
			return monthTicks(this.timeline.year.start, this.timeline.year.end, getLanguage())
				.map((tick) => ({ ...tick, selected: selectedMonth !== '' && tick.date.slice(0, 7) === selectedMonth }))
		},

		todayInYear() { return this.timeline.today >= this.timeline.year.start && this.timeline.today <= this.timeline.year.end },

		todayPosition() { return yearPosition(this.timeline.today, this.timeline.year.start, this.timeline.year.end) },

		markers() {
			const { year, today, next } = this.timeline
			return this.timeline.dates.map((entry) => {
				const state = entryState(entry)
				return {
					entry,
					state,
					position: yearPosition(entry.dueDate, year.start, year.end),
					selected: entry.dueDate === this.selectedDate,
					isNext: entry.dueDate === next?.dueDate,
					summary: this.summaryOf(entry, state),
					ariaLabel: this.t('Einzugstermin {datum}: {zustand}', {
						datum: formatDate(entry.dueDate),
						zustand: this.summaryOf(entry, state),
					}) + (entry.dueDate === today ? ` (${this.t('heute')})` : ''),
				}
			})
		},

		listRows() {
			const rows = this.markers.map((marker) => ({ type: 'date', key: marker.entry.dueDate, marker }))
			if (this.todayInYear) {
				// Vor den ersten Termin, der heute oder später liegt – sonst ans Ende.
				const at = rows.findIndex((r) => r.marker.entry.dueDate >= this.timeline.today)
				rows.splice(at === -1 ? rows.length : at, 0, { type: 'today', key: 'today' })
			}
			return rows
		},

		// Der gewählte Termin kann im angezeigten Jahr fehlen (der „nächste“ liegt im Folgejahr).
		selectedEntry() {
			return this.timeline.dates.find((d) => d.dueDate === this.selectedDate)
				|| (this.timeline.next?.dueDate === this.selectedDate ? this.timeline.next : null)
		},

		// Nachbartermine des gewählten im angezeigten Jahr (auch wenn der gewählte selbst nicht darin steht).
		prevDate() {
			const earlier = this.timeline.dates.map((d) => d.dueDate).filter((d) => d < this.selectedDate)
			return earlier.length ? earlier[earlier.length - 1] : null
		},

		nextDate() {
			return this.timeline.dates.map((d) => d.dueDate).find((d) => d > this.selectedDate) || null
		},

		milestones() {
			const entry = this.selectedEntry
			if (!entry) { return [] }
			const { today } = this.timeline
			return entry.milestones.map((m) => ({
				key: m.key,
				label: milestoneLabel(m.key),
				info: milestoneInfo(m.key),
				formattedDate: formatDate(m.date),
				relative: relativeDays(m.date, today),
				timing: this.timingOf(m.date),
			}))
		},

		// Die nächste Phase, die noch ansteht (oder heute ist) – sie wird hervorgehoben.
		nextMilestoneKey() {
			return this.milestones.find((m) => m.timing !== 'past')?.key || null
		},
	},

	methods: {
		formatDate,
		dateSourceText,

		timingOf(date) {
			const diff = daysBetween(this.timeline.today, date)
			if (diff < 0) { return 'past' }
			return diff === 0 ? 'today' : 'future'
		},

		summaryOf(entry, state) {
			if (state === 'offen') {
				return this.t('{zustand}: {anzahl} Forderungen, {summe}', {
					zustand: entryStateLabel(state),
					anzahl: entry.preview.count,
					summe: formatMoney(entry.preview.sumCents / 100),
				})
			}
			if (state === 'eingereicht' || state === 'freigegeben') {
				const live = entry.batches.filter((b) => b.status !== 'verworfen')
				const sum = live.reduce((acc, b) => acc + b.sumCents, 0)
				const count = live.reduce((acc, b) => acc + b.itemCount, 0)
				return this.t('{zustand}: {anzahl} Posten, {summe}', {
					zustand: entryStateLabel(state),
					anzahl: count,
					summe: formatMoney(sum / 100),
				})
			}
			return entryStateLabel(state)
		},
	},
}
</script>

<style scoped>
.vbh-tl {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 12px 16px 14px;
	margin: 10px 0;
	background-color: var(--color-main-background);
}

.vbh-tl-head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.vbh-tl-year {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px;
}

.vbh-tl-year h4,
.vbh-tl-phases h5 {
	margin: 0;
}

.vbh-tl-tools {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 16px;
}

/* --- Strahl (Desktop) ------------------------------------------------------ */

.vbh-tl-hint {
	margin: 2px 0 0;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.vbh-tl-scroll {
	overflow-x: auto;
	padding: 2px 0 0;
}

.vbh-tl-track {
	position: relative;
	height: 76px;
	min-width: 640px;
	margin: 0 14px;
	padding: 0;
	list-style: none;
}

/* Die Linie selbst; die Monatsnamen stehen darunter, HEUTE darüber. */
.vbh-tl-track::before {
	content: '';
	position: absolute;
	inset-inline: -14px;
	top: 36px;
	height: 4px;
	border-radius: 2px;
	background-color: var(--color-border);
}

.vbh-tl-tick {
	position: absolute;
	top: 52px;
	padding-inline-start: 4px;
	border-inline-start: 1px solid var(--color-border-dark);
	font-size: 0.75em;
	line-height: 14px;
	color: var(--color-text-maxcontrast);
}

.vbh-tl-tick.is-selected {
	font-weight: 700;
	color: var(--color-main-text);
	border-inline-start-color: var(--color-main-text);
}

.vbh-tl-slot {
	position: absolute;
	top: 27px;
	display: flex;
	align-items: center;
	justify-content: center;
	height: 22px;
	transform: translateX(-50%);
}

/* Dicht beieinander liegende Marker: der berührte/fokussierte liegt obenauf. */
.vbh-tl-slot:hover,
.vbh-tl-slot:focus-within {
	z-index: 2;
}

/* HEUTE: neutral und kontrastreich in beiden Designs (Text- und Hintergrundfarbe vertauscht). */
.vbh-tl-today {
	position: absolute;
	top: 18px;
	height: 40px;
	width: 2px;
	margin-inline-start: -1px;
	background-color: var(--color-main-text);
	pointer-events: none;
}

.vbh-tl-today span,
.vbh-tl-todaypill {
	position: absolute;
	top: -18px;
	inset-inline-start: 50%;
	transform: translateX(-50%);
	padding: 1px 7px;
	border-radius: 8px;
	font-size: 0.7em;
	font-weight: 700;
	letter-spacing: 0.04em;
	line-height: 1.3;
	background-color: var(--color-main-text);
	color: var(--color-main-background);
}

/* --- Marker ---------------------------------------------------------------- */

.vbh-tl-marker {
	display: inline-block;
	flex: 0 0 auto;
	box-sizing: border-box;
	width: 20px;
	height: 20px;
	border-radius: 50%;
	border: 2px solid var(--color-text-maxcontrast);
	background-color: var(--color-main-background);
}

/* Die Zustände nennen die Klasse doppelt: Nextcloud stylt jeden nackten <button> samt :hover mit (0,2,1). */
.vbh-tl-marker.vbh-tl-marker--eingereicht {
	border-color: var(--color-element-success);
	background-color: var(--color-element-success);
}

.vbh-tl-marker.vbh-tl-marker--freigegeben {
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-element);
}

/* Geisterkarte: gestrichelter Ring – „noch nicht da, nur Vorschau“. */
.vbh-tl-marker.vbh-tl-marker--offen {
	border: 2px dashed var(--color-primary-element);
	background-color: var(--color-primary-element-light);
}

.vbh-tl-marker.vbh-tl-marker--verworfen {
	border-color: var(--color-text-maxcontrast);
	background-color: var(--color-background-darker);
}

/* Nichts einzuziehen: nur ein kleiner Punkt, damit zwölf leere Monate den Strahl nicht beherrschen. */
.vbh-tl-marker.vbh-tl-marker--leer {
	width: 10px;
	height: 10px;
	border-width: 1px;
	border-color: var(--color-border-dark);
	background-color: var(--color-main-background);
}

/* Nackter <button>: Breite, Höhe und Außenabstand ausdrücklich zurücksetzen (Nextcloud setzt width/min-height/margin). */
.vbh-tl-marker--button.vbh-tl-marker--button {
	width: 22px;
	min-height: 22px;
	height: 22px;
	margin: 0;
	padding: 0;
	cursor: pointer;
}

.vbh-tl-marker--leer.vbh-tl-marker--button.vbh-tl-marker--button {
	width: 14px;
	min-height: 14px;
	height: 14px;
}

.vbh-tl-marker--button.vbh-tl-marker--button.is-selected {
	box-shadow: 0 0 0 3px var(--color-main-background), 0 0 0 5px var(--color-main-text);
}

.vbh-tl-marker--button.vbh-tl-marker--button.is-next:not(.is-selected) {
	box-shadow: 0 0 0 2px var(--color-main-background), 0 0 0 3px var(--color-primary-element);
}

.vbh-tl-marker--button.vbh-tl-marker--button:hover {
	filter: brightness(0.9);
}

/* --- Liste (schmal) ---------------------------------------------------------- */

.vbh-tl-list {
	margin: 8px 0 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.vbh-tl-row--button.vbh-tl-row--button {
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	min-height: 44px;
	margin: 0;
	padding: 6px 10px;
	text-align: start;
	cursor: pointer;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.vbh-tl-row--button.vbh-tl-row--button.is-selected {
	border-color: var(--color-primary-element);
	box-shadow: inset 3px 0 0 var(--color-primary-element);
}

.vbh-tl-row-date {
	flex: 0 0 auto;
	font-weight: 600;
}

.vbh-tl-row-info {
	flex: 1 1 auto;
	min-width: 0;
	font-size: 0.85em;
	font-weight: 400;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.vbh-tl-list-today {
	position: relative;
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
	font-size: 0.85em;
	border-top: 2px solid var(--color-main-text);
}

.vbh-tl-list-today .vbh-tl-todaypill {
	position: static;
	transform: none;
}

/* --- Legende ------------------------------------------------------------------- */

.vbh-tl-legend {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 14px;
	margin: 0;
	padding: 0;
	list-style: none;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

.vbh-tl-legend li {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

.vbh-tl-legend .vbh-tl-marker {
	width: 12px;
	height: 12px;
}

/* --- Phasen bis zum Einzug ------------------------------------------------------ */

.vbh-tl-phases {
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.vbh-tl-phaseshead {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 4px 12px;
}

.vbh-tl-stepper {
	display: flex;
	gap: 2px;
}

.vbh-tl-steps {
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	margin: 10px 0 0;
	padding: 0;
	list-style: none;
}

/* Eine Phase: Punkt auf der Verbindungslinie, darunter Name, Datum und „in n Tagen“. */
.vbh-tl-step {
	position: relative;
	display: flex;
	flex-direction: column;
	gap: 1px;
	padding: 20px 12px 0 0;
}

.vbh-tl-step::before {
	content: '';
	position: absolute;
	top: 0;
	inset-inline-start: 0;
	z-index: 1;
	box-sizing: border-box;
	width: 14px;
	height: 14px;
	border: 2px solid var(--color-border-dark);
	border-radius: 50%;
	background-color: var(--color-main-background);
}

.vbh-tl-step::after {
	content: '';
	position: absolute;
	top: 6px;
	inset-inline: 18px 4px;
	height: 2px;
	background-color: var(--color-border);
}

.vbh-tl-step:last-child::after {
	display: none;
}

.vbh-tl-step.is-past::before {
	border-color: var(--color-element-success);
	background-color: var(--color-element-success);
}

.vbh-tl-step.is-past::after {
	background-color: var(--color-element-success);
}

.vbh-tl-step.is-today::before,
.vbh-tl-step.is-next::before {
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-element-light);
	box-shadow: 0 0 0 3px var(--color-primary-element-light);
}

.vbh-tl-step.is-today::before {
	background-color: var(--color-primary-element);
}

.vbh-tl-step-title {
	display: flex;
	align-items: center;
	gap: 2px;
	min-height: 24px;
}

.vbh-tl-step-date {
	font-variant-numeric: tabular-nums;
}

.vbh-tl-step-rel {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

/* Vergangenes tritt zurück, die nächste anstehende Phase fällt auf. */
.vbh-tl-step.is-past .vbh-tl-step-title strong,
.vbh-tl-step.is-past .vbh-tl-step-date {
	color: var(--color-text-maxcontrast);
}

.vbh-tl-step.is-next .vbh-tl-step-title strong,
.vbh-tl-step.is-today .vbh-tl-step-title strong {
	color: var(--color-primary-element);
}

.vbh-tl-step.is-next .vbh-tl-step-date,
.vbh-tl-step.is-today .vbh-tl-step-date {
	font-weight: 600;
}

.vbh-tl-source {
	margin: 10px 0 0;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

@media (max-width: 640px) {
	.vbh-tl {
		padding: 10px 12px;
	}

	/* Schmal stehen die Phasen untereinander, die Linie läuft senkrecht. */
	.vbh-tl-steps {
		grid-template-columns: minmax(0, 1fr);
	}

	.vbh-tl-step {
		padding: 0 0 14px 26px;
	}

	.vbh-tl-step::before {
		top: 5px;
	}

	.vbh-tl-step::after {
		top: 19px;
		bottom: 0;
		inset-inline: 6px auto;
		width: 2px;
		height: auto;
	}
}
</style>
