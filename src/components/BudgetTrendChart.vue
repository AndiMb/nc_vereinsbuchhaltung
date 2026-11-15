<template>
	<div class="vbh-visual-trend" :class="{ 'vbh-visual-trend--mobile': isMobile }">
		<div class="vbh-visual-trend__wrap">
			<canvas ref="canvas" role="img" :aria-label="ariaLabel" />
		</div>
		<!-- Eigene Legende statt der von Chart.js: sie zeigt die Linienarten
		     zuverlässig und nimmt der Zeichenfläche keine Höhe. Das Diagramm
		     beschreibt aria-label, die Legende ist nur fürs Auge. -->
		<ul class="vbh-visual-trend__legend" aria-hidden="true">
			<li>
				<span class="vbh-visual-trend__swatch vbh-visual-trend__swatch--income" />{{ t('Einnahmen') }}
			</li>
			<li>
				<span class="vbh-visual-trend__swatch vbh-visual-trend__swatch--expense" />{{ t('Ausgaben') }}
			</li>
			<li class="vbh-visual-trend__legend-gap">
				<span class="vbh-visual-trend__line" />{{ t('Ist') }}
			</li>
			<li>
				<span class="vbh-visual-trend__line vbh-visual-trend__line--forecast" />{{ t('Hochrechnung') }}
			</li>
			<li>
				<span class="vbh-visual-trend__line vbh-visual-trend__line--plan" />{{ t('Plan') }}
			</li>
		</ul>
		<ul class="vbh-visual-trend__summary">
			<li v-for="line in summaryLines" :key="line">
				{{ line }}
			</li>
		</ul>
	</div>
</template>

<script>
import { getLanguage } from '@nextcloud/l10n'
import { trendDatasets } from '../lib/budgetVisual.js'
import { loadChart } from '../lib/chartLoader.js'
import { chartTheme, onThemeChange } from '../lib/chartTheme.js'
import { formatCurrency, formatCurrencyWhole, formatMoney } from '../lib/format.js'

/**
 * Verlauf der visuellen Plan-Ist-Ansicht: kumuliertes Ist je Monat für
 * Einnahmen und Ausgaben (durchgezogen), die Hochrechnung bis zum
 * Periodenende (Endwert aus den Kennzahlen; gestrichelt) und die Plan-Linie
 * (gepunktet) – dieselben Linienarten wie auf der Druckseite
 * (lib/Service/Export/TrendSvg.php). Rot und Grün sind in dieser Ansicht der
 * Ampel vorbehalten; die Reihen nutzen deshalb die Akzentfarbe (Einnahmen)
 * und die Textfarbe (Ausgaben). Die Zahlen stehen zusätzlich als Text unter
 * dem Diagramm.
 */
export default {
	name: 'BudgetTrendChart',
	props: {
		// { months: ['YYYY-MM', …], income: [Cent kumuliert…], expense: […] }
		series: { type: Object, required: true },
		planIncome: { type: Number, required: true },
		planExpense: { type: Number, required: true },
		// Monate der Periode
		total: { type: Number, required: true },
		// alle Monate der Periode als YYYY-MM (Achsenbeschriftung)
		monthsOfPeriod: { type: Array, required: true },
		// Hochrechnung zum Periodenende in Cent (kpis.*.forecast) oder null
		forecastIncome: { type: Number, default: null },
		forecastExpense: { type: Number, default: null },
		isMobile: { type: Boolean, required: true },
		// Chart.js braucht beim ersten Zeichnen ein sichtbares Canvas; der
		// Berichte-Tab wird per v-show ausgeblendet.
		isActive: { type: Boolean, required: true },
	},

	computed: {
		datasets() {
			return trendDatasets(this.series, this.planIncome, this.planExpense, this.total, this.forecastIncome, this.forecastExpense)
		},

		monthLabels() {
			const format = new Intl.DateTimeFormat(getLanguage(), { month: 'short', year: '2-digit', timeZone: 'UTC' })
			return this.monthsOfPeriod.map((ym) => {
				const [year, month] = ym.split('-').map(Number)
				return format.format(new Date(Date.UTC(year, month - 1, 1)))
			})
		},

		summaryLines() {
			return [
				this.summaryLine(this.t('Einnahmen'), this.series.income, this.forecastIncome),
				this.summaryLine(this.t('Ausgaben'), this.series.expense, this.forecastExpense),
			]
		},

		ariaLabel() {
			return [this.t('Verlauf: kumuliertes Ist je Monat mit Plan-Linie und Hochrechnung.'), ...this.summaryLines].join(' ')
		},
	},

	watch: {
		isActive(v) {
			if (v) { this.$nextTick(() => this.render()) }
		},

		datasets() { this.renderIfActive() },
		monthLabels() { this.renderIfActive() },
		isMobile() { this.renderIfActive() },
	},

	// chart liegt bewusst NICHT in data(): Chart.js-Instanzen vertragen sich
	// nicht mit Vue 3s tiefer Proxy-Reaktivität (Muster wie DashboardTab).
	created() {
		this.chart = null
	},

	mounted() {
		if (this.isActive) { this.$nextTick(() => setTimeout(() => this.render(), 50)) }
		// Chart.js malt die Farben einmal ins Canvas; ohne Neuzeichnen bliebe
		// nach einem Designwechsel das alte Bild stehen.
		this.stopThemeWatch = onThemeChange(() => {
			if (this.isActive) { this.render(true) }
		})
	},

	beforeUnmount() {
		this.stopThemeWatch()
		if (this.chart) {
			this.chart.destroy()
			this.chart = null
		}
	},

	methods: {
		summaryLine(label, values, forecast) {
			const ist = formatMoney((values.length ? values[values.length - 1] : 0) / 100)
			if (forecast === null || forecast === undefined) {
				return this.t('{art}: Ist {betrag} zum Stand', { art: label, betrag: ist })
			}
			return this.t('{art}: Ist {betrag} zum Stand, Hochrechnung {hochrechnung} zum Periodenende', {
				art: label,
				betrag: ist,
				hochrechnung: formatMoney(forecast / 100),
			})
		},

		renderIfActive() {
			if (this.isActive) { this.$nextTick(() => this.render()) }
		},

		/**
		 * @param {boolean} [rebuild] Instanz neu bauen statt aktualisieren (Designwechsel)
		 */
		async render(rebuild = false) {
			if (!this.$refs.canvas) { return }
			const Chart = await loadChart()
			// Während des Nachladens kann die Komponente abgebaut worden sein.
			const canvas = this.$refs.canvas
			if (!canvas) { return }
			const theme = chartTheme()
			const d = this.datasets
			const toUnits = (values) => values.map((v) => (v === null ? null : v / 100))
			const line = (label, data, color, extra) => ({
				label,
				data: toUnits(data),
				borderColor: color,
				backgroundColor: color,
				borderWidth: 2,
				pointRadius: 0,
				pointHitRadius: 8,
				spanGaps: false,
				tension: 0,
				...extra,
			})
			const solid = { pointRadius: 2 }
			const forecast = { borderDash: [6, 4] }
			// Volle Farbe, dünner und gepunktet: blass war die Linie im hellen
			// Design kaum zu sehen.
			const plan = { borderWidth: 1.5, borderDash: [2, 3] }
			const income = theme.accent
			const expense = theme.text
			const labels = this.monthLabels
			const config = {
				type: 'line',
				data: {
					labels,
					datasets: [
						line(this.t('Einnahmen'), d.income, income, solid),
						line(this.t('{art} (Hochrechnung)', { art: this.t('Einnahmen') }), d.incomeForecast, income, forecast),
						line(this.t('{art} (Plan)', { art: this.t('Einnahmen') }), d.incomePlan, income, plan),
						line(this.t('Ausgaben'), d.expense, expense, solid),
						line(this.t('{art} (Hochrechnung)', { art: this.t('Ausgaben') }), d.expenseForecast, expense, forecast),
						line(this.t('{art} (Plan)', { art: this.t('Ausgaben') }), d.expensePlan, expense, plan),
					],
				},

				options: {
					responsive: true,
					maintainAspectRatio: false,
					animation: false,
					interaction: { mode: 'index', intersect: false },
					plugins: {
						// Die Legende steht als HTML unter der Zeichenfläche (siehe Template).
						legend: { display: false },
						tooltip: {
							filter: (item) => item.raw !== null,
							callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${formatCurrency(ctx.raw)}` },
						},
					},

					scales: {
						x: {
							ticks: {
								color: theme.mutedText,
								maxRotation: 0,
								// Mobil höchstens jede dritte Monatsbeschriftung.
								autoSkip: !this.isMobile,
								callback: (value, index) => (!this.isMobile || index % 3 === 0 ? labels[index] : ''),
							},

							grid: { color: theme.grid },
						},

						y: {
							beginAtZero: true,
							ticks: {
								color: theme.mutedText,
								callback: (v) => formatCurrencyWhole(v),
							},

							grid: { color: theme.grid },
						},
					},
				},
			}

			// Vorhandene Instanz nur aktualisieren; neu gebaut wird ohne Instanz,
			// bei einem Designwechsel oder wenn die Zeichenfläche eine andere ist.
			if (this.chart && !rebuild && this.chart.canvas === canvas) {
				this.chart.data = config.data
				this.chart.options = config.options
				this.chart.update('none')
				return
			}
			if (this.chart) { this.chart.destroy() }
			this.chart = new Chart(canvas, config)
		},
	},
}
</script>

<style scoped>
.vbh-visual-trend { display: flex; flex-direction: column; gap: 8px; min-width: 0; }

.vbh-visual-trend__wrap { position: relative; height: 280px; min-width: 0; }

.vbh-visual-trend--mobile .vbh-visual-trend__wrap { height: 220px; }

.vbh-visual-trend__wrap canvas { display: block; }

.vbh-visual-trend__summary { margin: 0; padding: 0; list-style: none; font-variant-numeric: tabular-nums; }

.vbh-visual-trend__legend { display: flex; flex-wrap: wrap; gap: 4px 16px; margin: 0; padding: 0; list-style: none; font-size: 0.9em; }

.vbh-visual-trend__legend li { display: inline-flex; align-items: center; gap: 6px; }

/* Zwischen den Farben der Arten und den Linienarten der Reihen etwas mehr Luft. */
.vbh-visual-trend__legend-gap { margin-inline-start: 8px; }

.vbh-visual-trend__swatch { display: inline-block; width: 14px; height: 14px; border-radius: 3px; }

.vbh-visual-trend__swatch--income { background-color: var(--color-primary-element); }

.vbh-visual-trend__swatch--expense { background-color: var(--color-main-text); }

/* Linienmuster wie im Diagramm: Ist durchgezogen, Hochrechnung gestrichelt, Plan gepunktet. */
.vbh-visual-trend__line { display: inline-block; width: 28px; border-top: 2px solid currentColor; }

.vbh-visual-trend__line--forecast { border-top-style: dashed; }

.vbh-visual-trend__line--plan { border-top-style: dotted; }
</style>
