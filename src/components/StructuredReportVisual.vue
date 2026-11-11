<template>
	<div class="vbh-visual" :class="{ 'vbh-visual--mobile': isMobile }">
		<NcLoadingIcon v-if="loading && !data" :size="32" :name="t('Wird geladen…')" />
		<template v-else-if="data">
			<div class="vbh-visual-controls">
				<div class="vbh-visual-asof">
					<NcSelect
						:modelValue="asOfOption"
						:options="asOfOptions"
						:clearable="false"
						label="label"
						:inputLabel="tc('Auswertung', 'Stand')"
						@update:modelValue="selectAsOf($event && $event.id)" />
					<p class="vbh-hint vbh-visual-asof__meaning">
						{{ asOfMeaning }}
					</p>
				</div>
				<div class="vbh-visual-basis">
					<span id="vbh-visual-basis-label" class="vbh-visual-basis__label">{{ t('Vergleich gegen') }}</span>
					<div class="vbh-segmented" role="group" aria-labelledby="vbh-visual-basis-label">
						<button
							type="button"
							class="vbh-segmented-item"
							:class="{ active: effectiveBasis === 'year' }"
							:aria-pressed="effectiveBasis === 'year' ? 'true' : 'false'"
							@click="setBasis('year')">
							{{ t('Jahresplan') }}
						</button>
						<button
							type="button"
							class="vbh-segmented-item"
							:class="{ active: effectiveBasis === 'time' }"
							:aria-pressed="effectiveBasis === 'time' ? 'true' : 'false'"
							:disabled="!timeAvailable"
							@click="setBasis('time')">
							{{ t('Zeitanteilig') }}
						</button>
					</div>
					<p v-if="!timeAvailable" class="vbh-hint vbh-visual-basis__hint">
						{{ t('Die Periode hat noch nicht begonnen – der zeitanteilige Vergleich und die Hochrechnung entfallen.') }}
					</p>
				</div>
			</div>

			<NcNoteCard v-if="hasNoPlan && !isEmpty" type="info">
				<p>{{ t('Für diese Periode ist noch kein Plan erfasst; alle Konten mit Buchungen gelten als ungeplant. Die Planwerte tragen Sie im Finanzplan ein.') }}</p>
			</NcNoteCard>
			<NcNoteCard v-if="!data.hasSpheres" type="info">
				<p>{{ t('Noch kein Einnahmen- oder Ausgabenkonto hat eine Sphäre. Die Standardvorlagen trennen danach den ideellen Bereich von den Projekten – bis dahin steht alles unter „Ohne Sphäre“.') }}</p>
				<NcButton v-if="canWrite" variant="secondary" @click="$emit('open-spheres')">
					{{ t('Sphären zuordnen') }}
				</NcButton>
			</NcNoteCard>
			<NcNoteCard v-for="w in data.warnings" :key="w.code" type="warning">
				{{ w.message }}
			</NcNoteCard>

			<p v-if="isEmpty" class="vbh-empty">
				{{ t('Für diese Periode gibt es weder Planwerte noch Buchungen. Die Planwerte tragen Sie im Finanzplan ein.') }}
			</p>
			<template v-else>
				<div class="vbh-visual-tiles">
					<BudgetKpiTile
						:title="t('Einnahmen')"
						nature="income"
						:kpi="data.kpis.income"
						:basis="effectiveBasis"
						:timeFactor="timeFactor" />
					<BudgetKpiTile
						:title="t('Ausgaben')"
						nature="expense"
						:kpi="data.kpis.expense"
						:basis="effectiveBasis"
						:timeFactor="timeFactor" />
					<BudgetKpiTile
						:title="t('Ergebnis')"
						nature="result"
						:kpi="data.kpis.result"
						:basis="effectiveBasis"
						:timeFactor="timeFactor" />
				</div>

				<div class="vbh-visual-cards">
					<div class="vbh-visual-card">
						<BudgetStatusDistribution
							:title="t('Ampel der Ausgabenkonten')"
							nature="expense"
							:counts="data.distribution[effectiveBasis].expense"
							:active="activeLevel('expense')"
							@select="selectFilter('expense', $event)" />
					</div>
					<div class="vbh-visual-card">
						<BudgetStatusDistribution
							:title="t('Ampel der Einnahmenkonten')"
							nature="income"
							:counts="data.distribution[effectiveBasis].income"
							:active="activeLevel('income')"
							@select="selectFilter('income', $event)" />
					</div>
				</div>

				<div class="vbh-visual-cards">
					<div class="vbh-visual-card">
						<BudgetDeviationList
							:title="t('Größte Überschreitungen (Ausgaben)')"
							nature="expense"
							:items="data.deviations[effectiveBasis].expense"
							:emptyText="t('Kein Ausgabenkonto liegt über dem Plan.')" />
					</div>
					<div class="vbh-visual-card">
						<BudgetDeviationList
							:title="t('Größte Lücken (Einnahmen)')"
							nature="income"
							:items="data.deviations[effectiveBasis].income"
							:emptyText="t('Kein Einnahmenkonto liegt unter dem Plan.')" />
					</div>
				</div>

				<section class="vbh-visual-card vbh-visual-trendsection">
					<h5 class="vbh-visual-trendsection__title">
						{{ t('Verlauf') }}
					</h5>
					<BudgetTrendChart
						v-if="hasTrend"
						:series="data.series"
						:planIncome="data.kpis.income.plan"
						:planExpense="data.kpis.expense.plan"
						:total="data.asOf.total"
						:monthsOfPeriod="monthsOfPeriod"
						:forecastIncome="data.kpis.income.forecast"
						:forecastExpense="data.kpis.expense.forecast"
						:isMobile="isMobile"
						:isActive="isActive" />
					<p v-else class="vbh-hint vbh-visual-trendsection__hint">
						{{ t('Die Periode hat noch nicht begonnen – der Verlauf erscheint ab ihrem ersten Monat.') }}
					</p>
				</section>

				<section class="vbh-visual-breakdown">
					<h5 class="vbh-visual-breakdown__title">
						{{ t('Aufriss nach Vorlage') }}
					</h5>
					<div v-if="filter" class="vbh-visual-filter">
						<span>{{ t('Gefiltert: {art} – {stufe}', { art: filterNatureLabel, stufe: filterLevelLabel }) }}</span>
						<NcButton variant="tertiary" @click="filter = null">
							{{ t('Filter aufheben') }}
						</NcButton>
					</div>
					<p v-if="!hasValueRows" class="vbh-empty">
						{{ t('Diese Vorlage enthält keine Zeilen mit Werten.') }}
					</p>
					<p v-else-if="filter && !shownRows.some(isAccountEntry)" class="vbh-empty">
						{{ t('Kein Konto der Gliederung hat diese Ampelstufe.') }}
					</p>
					<template v-else>
						<div v-if="!isMobile" class="vbh-visual-row vbh-visual-row--head" aria-hidden="true">
							<span class="vbh-visual-row__label">{{ t('Bezeichnung') }}</span>
							<span class="vbh-visual-row__status">{{ t('Ampel') }}</span>
							<span class="vbh-visual-row__bar">{{ t('Ist zu Plan') }}</span>
							<span class="vbh-visual-row__num vbh-visual-row__actual">{{ t('Ist') }}</span>
							<span class="vbh-visual-row__num vbh-visual-row__plan">{{ t('Plan') }}</span>
						</div>
						<ul class="vbh-visual-tree">
							<li
								v-for="entry in shownRows"
								:key="entry.index"
								class="vbh-visual-tree__item"
								:class="rowClass(entry.row)">
								<span
									v-if="entry.row.kind === 'heading'"
									class="vbh-visual-tree__heading"
									role="heading"
									aria-level="6"
									:style="indent(entry.row)">{{ entry.row.label }}</span>
								<div v-else class="vbh-visual-row">
									<span class="vbh-visual-row__label" :style="indent(entry.row)">
										<button
											v-if="!filter && entry.row.kind === 'account' && hasChildren(data.rows, entry.index)"
											type="button"
											class="vbh-visual-row__toggle"
											:aria-expanded="collapsed.has(entry.index) ? 'false' : 'true'"
											@click="toggle(entry.index)">
											<NcIconSvgWrapper :path="collapsed.has(entry.index) ? mdiChevronRight : mdiChevronDown" :size="18" />
											<span>{{ rowLabel(entry.row) }}</span>
										</button>
										<span
											v-else
											class="vbh-visual-row__text"
											:class="{ 'vbh-visual-row__text--account': entry.row.kind === 'account' }">{{ rowLabel(entry.row) }}</span>
										<span v-if="hasFlag(entry.row, 'inactive')" class="vbh-pill">{{ t('nicht mehr aktiv') }}</span>
										<span v-if="hasFlag(entry.row, 'duplicate')" class="vbh-pill vbh-pill--warning">{{ t('doppelt gezählt') }}</span>
									</span>
									<span class="vbh-visual-row__status">
										<span v-if="hasFlag(entry.row, 'covered')" class="vbh-pill">{{ t('im Oberkonto geplant') }}</span>
										<BudgetStatusPill v-else :level="rowLevel(entry.row)" :nature="entry.row.nature" />
									</span>
									<span class="vbh-visual-row__bar">
										<BudgetBullet
											v-if="entry.row.nature !== 'result'"
											:plan="entry.row.plan"
											:actual="entry.row.actual"
											:timeFactor="timeFactor"
											:tone="rowTone(entry.row)" />
									</span>
									<span class="vbh-visual-row__num vbh-visual-row__actual">
										<span :class="isMobile ? 'vbh-visual-row__numlabel' : 'vbh-visually-hidden'">{{ t('Ist') }}</span>
										{{ money(entry.row.actual) }}
									</span>
									<span class="vbh-visual-row__num vbh-visual-row__plan">
										<span :class="isMobile ? 'vbh-visual-row__numlabel' : 'vbh-visually-hidden'">{{ t('Plan') }}</span>
										{{ money(entry.row.plan) }}
									</span>
								</div>
							</li>
						</ul>
					</template>
				</section>
			</template>
		</template>
	</div>
</template>

<script>
import { mdiChevronDown, mdiChevronRight } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { getLanguage } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import BudgetBullet from './BudgetBullet.vue'
import BudgetDeviationList from './BudgetDeviationList.vue'
import BudgetKpiTile from './BudgetKpiTile.vue'
import BudgetStatusDistribution from './BudgetStatusDistribution.vue'
import BudgetStatusPill from './BudgetStatusPill.vue'
import BudgetTrendChart from './BudgetTrendChart.vue'
import api from '../api.js'
import { useAuth } from '../composables/useAuth.js'
import { hasChildren, monthLabel, periodMonths, statusMeta, visibleRows } from '../lib/budgetVisual.js'
import { errMsg, formatDate, formatMoney } from '../lib/format.js'

const BASIS_KEY = 'vbh.structuredReport.basis'
const VALUE_KINDS = ['line', 'account', 'footer', 'total']

function storedBasis() {
	try {
		return localStorage.getItem(BASIS_KEY) === 'time' ? 'time' : 'year'
	} catch {
		return 'year'
	}
}

/**
 * Visuelle Plan-Ist-Ansicht des Gliederungsberichts: Stand und
 * Vergleichsbasis, Kennzahlen, Ampelverteilung, größte Abweichungen, Verlauf
 * und der Aufriss nach der Vorlage. Gerechnet wird ausschließlich auf dem Server; der
 * Wechsel der Vergleichsbasis lädt nicht nach, weil der Server den Status für
 * beide Basen liefert.
 */
export default {
	name: 'StructuredReportVisual',
	components: { BudgetBullet, BudgetDeviationList, BudgetKpiTile, BudgetStatusDistribution, BudgetStatusPill, BudgetTrendChart, NcButton, NcIconSvgWrapper, NcLoadingIcon, NcNoteCard, NcSelect },
	props: {
		templateId: { type: String, required: true },
		periodId: { type: Number, default: null },
		refreshKey: { type: Number, default: 0 },
		isMobile: { type: Boolean, required: true },
		// Berichte-Tab sichtbar? Er wird per v-show ausgeblendet, der Verlauf
		// (Chart.js) braucht beim Zeichnen ein sichtbares Canvas.
		isActive: { type: Boolean, required: true },
	},

	emits: ['loaded', 'open-spheres'],

	setup() {
		return { canWrite: useAuth().canWrite }
	},

	data() {
		return {
			mdiChevronDown,
			mdiChevronRight,
			data: null,
			loading: false,
			// Nur die zuletzt gestellte Anfrage darf das Ergebnis setzen.
			requestNo: 0,
			// null = Vorgabe des Servers; nach dem Laden der gewählte Stand
			asOf: null,
			basis: storedBasis(),
			// Indizes zugeklappter Kontozeilen; bei Änderung neu zuweisen
			collapsed: new Set(),
			// { nature, level } aus der Ampelverteilung oder null
			filter: null,
		}
	},

	computed: {
		timeAvailable() {
			return !!this.data && this.data.asOf.elapsed > 0
		},

		effectiveBasis() {
			return this.timeAvailable ? this.basis : 'year'
		},

		timeFactor() {
			const { elapsed, total } = this.data.asOf
			return elapsed > 0 && total > 0 ? elapsed / total : null
		},

		asOfOptions() {
			const language = getLanguage()
			const months = [...this.data.asOf.options].reverse()
				.map((ym) => ({ id: ym, label: monthLabel(ym, language) }))
			return [{ id: 'current', label: this.t('Aktuell (alle Buchungen)') }, ...months]
		},

		asOfOption() {
			return this.asOfOptions.find((o) => o.id === this.data.asOf.value) || null
		},

		asOfMeaning() {
			const { value, until, elapsed, total } = this.data.asOf
			const share = this.t('Zeitanteil: {elapsed} von {total} Monaten', { elapsed, total })
			if (value === 'current') {
				return this.t('Alle Buchungen der Periode') + ' · ' + share
			}
			return this.t('Ist bis {datum}', { datum: formatDate(until) }) + ' · ' + share
		},

		hasNoPlan() {
			const { income, expense } = this.data.kpis
			return income.plan === 0 && expense.plan === 0
		},

		isEmpty() {
			const { income, expense } = this.data.kpis
			return this.hasNoPlan && income.actual === 0 && expense.actual === 0
		},

		hasTrend() {
			return this.data.asOf.elapsed > 0 && (this.data.series?.months || []).length > 0
		},

		monthsOfPeriod() {
			return periodMonths(this.data.period.startDate, this.data.asOf.total)
		},

		hasValueRows() {
			return this.data.rows.some((row) => VALUE_KINDS.includes(row.kind))
		},

		shownRows() {
			return visibleRows(this.data.rows, this.collapsed, this.filter, this.effectiveBasis)
		},

		filterNatureLabel() {
			return this.filter.nature === 'income' ? this.t('Einnahmen') : this.t('Ausgaben')
		},

		filterLevelLabel() {
			return statusMeta(this.filter.level, this.filter.nature).label
		},
	},

	watch: {
		templateId() { this.load() },
		periodId() {
			this.asOf = null
			this.load()
		},

		refreshKey() { this.load() },
	},

	mounted() {
		this.load()
	},

	methods: {
		hasChildren,

		isAccountEntry(entry) {
			return entry.row.kind === 'account'
		},

		async load() {
			if (!this.templateId) { return }
			const no = ++this.requestNo
			this.loading = true
			try {
				const { data } = await api.visualReport(this.templateId, this.periodId, this.asOf)
				if (no === this.requestNo) {
					this.data = data
					this.asOf = data.asOf.value
					this.collapsed = new Set()
					this.$emit('loaded', data.period)
				}
			} catch (e) {
				if (no !== this.requestNo) { return }
				// Gibt es den gewählten Stand nicht mehr (z. B. nach dem Wechsel
				// der Periode durch andere), einmal mit der Vorgabe neu laden.
				if (e?.response?.status === 422 && this.asOf !== null) {
					this.asOf = null
					this.load()
					return
				}
				this.data = null
				this.$emit('loaded', null)
				showError(errMsg(e, this.t('Gliederungsbericht konnte nicht geladen werden')))
			} finally {
				if (no === this.requestNo) { this.loading = false }
			}
		},

		selectAsOf(value) {
			if (!value || value === this.asOf) { return }
			this.asOf = value
			this.load()
		},

		setBasis(basis) {
			this.basis = basis
			try { localStorage.setItem(BASIS_KEY, basis) } catch { /* voll/gesperrt – dann eben ohne */ }
		},

		activeLevel(nature) {
			return this.filter && this.filter.nature === nature ? this.filter.level : null
		},

		selectFilter(nature, level) {
			this.filter = level ? { nature, level } : null
		},

		toggle(index) {
			const next = new Set(this.collapsed)
			if (next.has(index)) { next.delete(index) } else { next.add(index) }
			this.collapsed = next
		},

		rowLabel(row) {
			return row.number ? row.number + ' ' + row.label : row.label
		},

		rowLevel(row) {
			return row.status?.[this.effectiveBasis]?.level ?? 'none'
		},

		rowTone(row) {
			// Gedeckte Unterkonten haben keinen eigenen Plan – neutraler Balken.
			if (this.hasFlag(row, 'covered')) { return 'none' }
			return statusMeta(this.rowLevel(row), row.nature).tone
		},

		money(cents) {
			return formatMoney(cents / 100)
		},

		indent(row) {
			return { paddingInlineStart: (row.depth * (this.isMobile ? 12 : 18)) + 'px' }
		},

		hasFlag(row, flag) {
			return (row.flags || []).includes(flag)
		},

		rowClass(row) {
			return {
				'vbh-visual-tree__item--top': row.kind === 'heading' && row.depth === 0,
				'vbh-visual-tree__item--sum': row.kind === 'footer',
				'vbh-visual-tree__item--total': row.kind === 'total',
				'vbh-visual-tree__item--structural': this.hasFlag(row, 'structural'),
			}
		},
	},
}
</script>

<style scoped>
.vbh-visual { display: flex; flex-direction: column; gap: 16px; min-width: 0; }

.vbh-visual-controls { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 8px 24px; }

.vbh-visual-asof { display: flex; flex-direction: column; gap: 2px; min-width: min(280px, 100%); }

.vbh-visual-asof__meaning { margin: 0; }

/* Beschriftung neben dem Umschalter statt darüber: spart auf dem Handy eine Zeile. */
.vbh-visual-basis { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; }

.vbh-visual-basis__label { font-weight: 600; }

.vbh-visual-basis__hint { flex-basis: 100%; margin: 0; max-width: 40em; }

.vbh-visual-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }

.vbh-visual-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; }

.vbh-visual--mobile .vbh-visual-tiles,
.vbh-visual--mobile .vbh-visual-cards { grid-template-columns: minmax(0, 1fr); }

.vbh-visual-card {
	min-width: 0;
	padding: 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
}

/* Container-Abfrage statt eines weiteren JS-Umbruchpunkts: der Aufriss
   richtet sich nach seiner eigenen Breite (isMobile bleibt der einzige). */
.vbh-visual-breakdown { min-width: 0; container-type: inline-size; }

.vbh-visual-breakdown__title,
.vbh-visual-trendsection__title { margin: 0 0 8px; font-weight: 600; }

.vbh-visual-trendsection__hint { margin: 0; }

.vbh-visual-filter { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; margin-bottom: 8px; font-weight: 600; }

.vbh-visual-tree { margin: 0; padding: 0; list-style: none; }

.vbh-visual-tree__item { border-bottom: 1px solid var(--color-border); }

.vbh-visual-tree__heading { display: block; padding: 10px 0 4px; font-weight: 600; }

.vbh-visual-tree__item--top .vbh-visual-tree__heading { font-size: 1.05em; border-bottom: 2px solid var(--color-border-dark); }

.vbh-visual-tree__item--sum { font-weight: 600; border-top: 1px solid var(--color-border-dark); }

.vbh-visual-tree__item--total { font-weight: 700; border-top: 2px solid var(--color-border-dark); border-bottom: 2px solid var(--color-border-dark); }

.vbh-visual-tree__item--structural .vbh-visual-row__label { color: var(--color-text-maxcontrast); }

.vbh-visual-row {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 10.5em minmax(60px, 10em) 7em 7em;
	grid-template-areas: "label status bar actual plan";
	align-items: center;
	gap: 4px 12px;
	padding: 6px 0;
}

.vbh-visual-row--head { padding: 4px 0; color: var(--color-text-maxcontrast); font-size: 0.9em; border-bottom: 1px solid var(--color-border-dark); }

/* Zu schmal für fünf Spalten: der Balken weicht in eine eigene Zeile aus,
   die Bezeichnung behält ihren Platz. */
@container (max-width: 760px) {
	.vbh-visual-row {
		grid-template-columns: minmax(0, 1fr) auto 7em 7em;
		grid-template-areas:
			"label status actual plan"
			"bar bar bar bar";
	}

	/* Die Kopfzeile hat keinen Balken – ohne eigene Fläche bliebe eine leere zweite Zeile. */
	.vbh-visual-row--head { grid-template-areas: "label status actual plan"; }

	.vbh-visual-row--head .vbh-visual-row__bar { display: none; }
}

.vbh-visual--mobile .vbh-visual-row {
	grid-template-columns: minmax(0, 1fr) auto;
	grid-template-areas:
		"label status"
		"bar bar"
		"actual plan";
}

.vbh-visual-row__label { grid-area: label; }

.vbh-visual-row__status { grid-area: status; }

.vbh-visual-row__bar { grid-area: bar; }

.vbh-visual-row__actual { grid-area: actual; }

.vbh-visual-row__plan { grid-area: plan; }

.vbh-visual--mobile .vbh-visual-row__actual { text-align: start; }

.vbh-visual-row__label { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; min-width: 0; overflow-wrap: break-word; hyphens: auto; }

/* Doppelte Klasse gegen Nextclouds Grundstil nackter Knöpfe, auch bei
   :hover/:focus/:active (siehe Kopf von src/styles.css). */
.vbh-visual-row__toggle.vbh-visual-row__toggle,
.vbh-visual-row__toggle.vbh-visual-row__toggle:hover,
.vbh-visual-row__toggle.vbh-visual-row__toggle:focus,
.vbh-visual-row__toggle.vbh-visual-row__toggle:active {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	min-height: 0;
	margin: 0;
	padding: 0;
	border: 0;
	background: none;
	color: inherit;
	font: inherit;
	text-align: start;
	cursor: pointer;
}

.vbh-visual-row__toggle.vbh-visual-row__toggle:focus-visible {
	border-radius: var(--border-radius);
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.vbh-visual-row__text--account { padding-inline-start: 22px; }

.vbh-visual-row__status,
.vbh-visual-row__bar { min-width: 0; }

.vbh-visual-row__num { text-align: end; font-variant-numeric: tabular-nums; white-space: nowrap; }

.vbh-visual-row__numlabel { margin-inline-end: 4px; color: var(--color-text-maxcontrast); font-weight: normal; }
</style>
