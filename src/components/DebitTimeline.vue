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
						:style="{ left: tick.position + '%' }"
						aria-hidden="true">
						{{ tick.label }}
					</li>
					<li
						v-for="m in trackMilestones"
						:key="m.key"
						class="vbh-tl-milestone"
						:class="`is-${m.timing}`"
						:style="{ left: m.position + '%' }"
						:title="`${m.label}: ${m.formattedDate}`" />
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
						<span class="vbh-tl-markerbox">
							<button
								type="button"
								class="vbh-tl-marker vbh-tl-marker--button"
								:class="[`vbh-tl-marker--${marker.state}`, { 'is-selected': marker.selected, 'is-next': marker.isNext }]"
								:aria-pressed="marker.selected"
								:aria-label="marker.ariaLabel"
								:title="marker.ariaLabel"
								@click="$emit('select', marker.entry.dueDate)" />
						</span>
						<span v-if="marker.selected || showAllLabels" class="vbh-tl-date" :class="{ 'is-selected': marker.selected }">{{ marker.shortDate }}</span>
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

			<ul class="vbh-tl-legend" :aria-label="t('Legende')">
				<li><span class="vbh-tl-marker vbh-tl-marker--eingereicht" aria-hidden="true" /> {{ t('Lauf eingereicht') }}</li>
				<li><span class="vbh-tl-marker vbh-tl-marker--freigegeben" aria-hidden="true" /> {{ t('Lauf freigegeben') }}</li>
				<li><span class="vbh-tl-marker vbh-tl-marker--offen" aria-hidden="true" /> {{ t('Vorschau, noch nicht freigegeben') }}</li>
				<li><span class="vbh-tl-marker vbh-tl-marker--leer" aria-hidden="true" /> {{ t('nichts einzuziehen') }}</li>
			</ul>

			<div v-if="milestones.length" class="vbh-tl-milestones-wrap">
				<div class="vbh-tl-milestoneshead">
					<h5>{{ t('Meilensteine zum Einzug am {datum}', { datum: formatDate(selectedEntry.dueDate) }) }}</h5>
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
				<p class="vbh-hint vbh-tl-source">
					{{ dateSourceText(selectedEntry.intervals) }}
				</p>
				<ul class="vbh-tl-milestones">
					<li
						v-for="m in milestones"
						:key="m.key"
						class="vbh-tl-milestone-item"
						:class="`is-${m.timing}`"
						:title="m.explanation">
						<strong>{{ m.label }}</strong>
						<span>{{ m.formattedDate }} · {{ m.relative }}</span>
					</li>
				</ul>
			</div>
		</template>
	</section>
</template>

<script>
import { mdiChevronLeft, mdiChevronRight } from '@mdi/js'
import { getLanguage } from '@nextcloud/l10n'
import { NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import {
	dateSourceText,
	daysBetween,
	entryState,
	entryStateLabel,
	formatShortDate,
	milestoneExplanation,
	milestoneLabel,
	monthTicks,
	relativeDays,
	yearPosition,
} from '../lib/debitRun.js'
import { formatDate, formatMoney } from '../lib/format.js'

/**
 * Zeitstrahl über das Beitragsjahr (Spec §6 Variante A, Issue #102): die
 * Einzugstermine aus dem Terminplan (plus manuelle/verschobene), der
 * HEUTE-Marker und, für den gewählten Termin, seine Meilensteine
 * (Vorwarnung, Vorabinfo, Freigabe-Vorlauf, Einzug).
 *
 * Rein darstellend: Daten kommen als `timeline` (Antwort von
 * GET /debit-batches/timeline), Auswahl und Jahreswechsel gehen als Ereignisse
 * nach oben. Auf schmalen Displays wird aus dem Strahl eine Liste – ein Strahl
 * mit zwölf Terminen auf 360 Pixeln Breite wäre nicht mehr bedienbar.
 */
export default {
	name: 'DebitTimeline',
	components: { NcButton, NcIconSvgWrapper },
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
		ticks() { return monthTicks(this.timeline.year.start, this.timeline.year.end, getLanguage()) },

		todayInYear() { return this.timeline.today >= this.timeline.year.start && this.timeline.today <= this.timeline.year.end },

		todayPosition() { return yearPosition(this.timeline.today, this.timeline.year.start, this.timeline.year.end) },

		// Mit mehr Terminen beschriftet nur noch der gewählte seinen Marker –
		// sonst laufen die Datumsangaben ineinander. Der Tooltip nennt immer alles.
		showAllLabels() { return this.timeline.dates.length <= 12 },

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
					shortDate: formatShortDate(entry.dueDate),
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
			const { today, leadDays } = this.timeline
			return entry.milestones.map((m) => ({
				key: m.key,
				label: milestoneLabel(m.key),
				explanation: milestoneExplanation(m.key, leadDays),
				formattedDate: formatDate(m.date),
				relative: relativeDays(m.date, today),
				timing: this.timingOf(m.date),
			}))
		},

		// Auf dem Strahl nur die Vorstufen – der Einzug selbst IST der Marker.
		trackMilestones() {
			const entry = this.selectedEntry
			if (!entry) { return [] }
			const { year } = this.timeline
			return entry.milestones
				.filter((m) => m.key !== 'collection' && m.date >= year.start && m.date <= year.end)
				.map((m) => ({
					key: m.key,
					label: milestoneLabel(m.key),
					formattedDate: formatDate(m.date),
					position: yearPosition(m.date, year.start, year.end),
					timing: this.timingOf(m.date),
				}))
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
	padding: 12px 16px;
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
.vbh-tl-milestones-wrap h5 {
	margin: 0;
}

.vbh-tl-tools {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

/* --- Strahl (Desktop) ------------------------------------------------------ */

.vbh-tl-scroll {
	overflow-x: auto;
	padding: 4px 0;
}

.vbh-tl-track {
	position: relative;
	height: 112px;
	min-width: 640px;
	margin: 0 18px;
	padding: 0;
	list-style: none;
}

/* Die Linie selbst: mittig zwischen Markern und Monatsbeschriftung. */
.vbh-tl-track::before {
	content: '';
	position: absolute;
	inset-inline: 0;
	top: 58px;
	height: 4px;
	border-radius: 2px;
	background-color: var(--color-border-dark);
}

.vbh-tl-tick {
	position: absolute;
	top: 0;
	padding-inline-start: 4px;
	border-inline-start: 1px solid var(--color-border-dark);
	font-size: 0.75em;
	line-height: 14px;
	color: var(--color-text-maxcontrast);
}

.vbh-tl-slot {
	position: absolute;
	top: 49px;
	display: flex;
	flex-direction: column;
	align-items: center;
	transform: translateX(-50%);
}

/* Dicht beieinander liegende Marker: der berührte/fokussierte liegt obenauf. */
.vbh-tl-slot:hover,
.vbh-tl-slot:focus-within {
	z-index: 2;
}

.vbh-tl-date {
	margin-top: 6px;
	padding: 0 2px;
	/* Der HEUTE-Strich läuft durch die Beschriftung – sie bekommt einen Grund, damit sie lesbar bleibt. */
	background-color: var(--color-main-background);
	font-size: 0.78em;
	white-space: nowrap;
	color: var(--color-text-maxcontrast);
}

.vbh-tl-date.is-selected {
	font-weight: 700;
	color: var(--color-main-text);
}

/* HEUTE: neutral und kontrastreich in beiden Designs (Text- und Hintergrundfarbe vertauscht). */
.vbh-tl-today {
	position: absolute;
	top: 24px;
	bottom: 0;
	width: 2px;
	margin-inline-start: -1px;
	background-color: var(--color-main-text);
	pointer-events: none;
}

.vbh-tl-today span,
.vbh-tl-todaypill {
	position: absolute;
	top: -2px;
	inset-inline-start: 50%;
	transform: translateX(-50%);
	padding: 0 6px;
	border-radius: 8px;
	font-size: 0.7em;
	font-weight: 700;
	letter-spacing: 0.04em;
	background-color: var(--color-main-text);
	color: var(--color-main-background);
}

.vbh-tl-milestone {
	position: absolute;
	top: 55px;
	width: 10px;
	height: 10px;
	margin-inline-start: -5px;
	transform: rotate(45deg);
	border: 2px solid var(--color-primary-element);
	background-color: var(--color-main-background);
}

.vbh-tl-milestone.is-past {
	background-color: var(--color-primary-element);
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

.vbh-tl-marker.vbh-tl-marker--leer {
	width: 12px;
	height: 12px;
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
	width: 16px;
	min-height: 16px;
	height: 16px;
}

.vbh-tl-markerbox {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 22px;
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

/* --- Legende und Meilensteine -------------------------------------------------- */

.vbh-tl-legend {
	display: flex;
	flex-wrap: wrap;
	gap: 6px 16px;
	margin: 6px 0 0;
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

.vbh-tl-milestones-wrap {
	margin-top: 12px;
}

.vbh-tl-milestoneshead {
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

.vbh-tl-source {
	margin: 2px 0 0;
	font-size: 0.85em;
}

.vbh-tl-milestones {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 6px 0 0;
	padding: 0;
	list-style: none;
}

.vbh-tl-milestone-item {
	display: flex;
	flex-direction: column;
	min-width: 130px;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 6px);
	background-color: var(--color-background-hover);
	font-size: 0.85em;
}

.vbh-tl-milestone-item span {
	color: var(--color-text-maxcontrast);
}

/* Erledigte Meilensteine treten zurück, der heutige bekommt einen Rand. */
.vbh-tl-milestone-item.is-past {
	opacity: 0.75;
}

.vbh-tl-milestone-item.is-today {
	border-color: var(--color-primary-element);
	box-shadow: inset 3px 0 0 var(--color-primary-element);
}

@media (max-width: 640px) {
	.vbh-tl {
		padding: 10px 12px;
	}

	.vbh-tl-milestone-item {
		flex: 1 1 calc(50% - 8px);
		min-width: 0;
	}
}
</style>
