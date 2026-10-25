<template>
	<ol class="vbh-leadstrip">
		<li
			v-for="step in steps"
			:key="step.key"
			class="vbh-leadstrip-step"
			:class="{ 'vbh-leadstrip-step--due': step.key === 'collection' }"
			:title="step.explanation">
			<span class="vbh-leadstrip-dot" aria-hidden="true" />
			<span class="vbh-leadstrip-text">
				<strong>{{ formatDate(step.date) }}</strong>
				<span>{{ step.title }}</span>
				<span v-if="step.days" class="vbh-leadstrip-days">{{ n('%n Tag vorher', '%n Tage vorher', step.days) }}</span>
			</span>
		</li>
	</ol>
</template>

<script>
import { milestoneExplanation } from '../lib/debitRun.js'
import { formatDate } from '../lib/format.js'
import { leadDaysExample } from '../lib/sepaSettings.js'

/**
 * Die Fristen vor einem Einzug als kleiner Zeitstrahl an einem echten Termin:
 * „25.12. Forderungen entstehen · 01.01. Vorabinfo an Mitglieder · 10.01. Lauf
 * freigeben bis · 15.01. Einzug“. Die Schritte heißen nach dem, was passiert,
 * statt nach der Einstellung (Vorwarnfenster, Vorabinfo-Vorlauf,
 * Freigabe-Vorlauf); der Tooltip erklärt sie wie der Zeitstrahl im Einzug.
 * Steht im Terminplan und in den Einstellungen (SettingsSepaCycle.vue).
 */
export default {
	name: 'LeadDaysStrip',

	props: {
		/** Einzugstermin „JJJJ-MM-TT“ */
		dueDate: { type: String, required: true },
		/** Tage vor dem Einzug, wie die Einstellungen sie führen: { warningLeadDays, prenotificationLeadDays, releaseLeadDays } */
		leadDays: { type: Object, required: true },
	},

	computed: {
		steps() {
			const { warningLeadDays, prenotificationLeadDays, releaseLeadDays } = this.leadDays
			const dates = leadDaysExample(this.dueDate, this.leadDays)
			// milestoneExplanation() spricht die Schlüssel des Zeitstrahls.
			const byMilestone = { warning: warningLeadDays, prenotification: prenotificationLeadDays, release: releaseLeadDays }
			const steps = [
				{ key: 'warning', date: dates.warning, days: Number(warningLeadDays), title: this.t('Forderungen entstehen') },
				{ key: 'prenotification', date: dates.prenotification, days: Number(prenotificationLeadDays), title: this.t('Vorabinfo an Mitglieder') },
				{ key: 'release', date: dates.release, days: Number(releaseLeadDays), title: this.t('Lauf freigeben bis') },
				{ key: 'collection', date: dates.due, days: 0, title: this.t('Einzug') },
			]
			// Nach Datum, damit auch unübliche Fristen (Einstellungen warnen davor) richtig herum stehen.
			return steps
				.map((step) => ({ ...step, explanation: milestoneExplanation(step.key, byMilestone) }))
				.sort((a, b) => a.date.localeCompare(b.date))
		},
	},

	methods: {
		formatDate,
	},
}
</script>

<style scoped>
.vbh-leadstrip {
	display: flex;
	margin: 8px 0;
	padding: 0;
	list-style: none;
}

.vbh-leadstrip-step {
	position: relative;
	display: flex;
	flex: 1 1 0;
	flex-direction: column;
	align-items: center;
	min-width: 0;
	gap: 4px;
	text-align: center;
}

/* Die Linie zum vorherigen Schritt, auf Höhe der Punkte */
.vbh-leadstrip-step + .vbh-leadstrip-step::before {
	content: '';
	position: absolute;
	top: 5px;
	inset-inline-end: 50%;
	width: 100%;
	height: 2px;
	background-color: var(--color-border-dark);
}

.vbh-leadstrip-dot {
	position: relative;
	z-index: 1;
	width: 12px;
	height: 12px;
	border-radius: 50%;
	background-color: var(--color-text-maxcontrast);
}

.vbh-leadstrip-step--due .vbh-leadstrip-dot {
	background-color: var(--color-primary-element);
}

.vbh-leadstrip-text {
	display: flex;
	flex-direction: column;
	padding: 0 4px;
	overflow-wrap: anywhere;
}

.vbh-leadstrip-days {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

/* Schmal: untereinander, Datum und Schritt nebeneinander */
@media (max-width: 600px) {
	.vbh-leadstrip {
		flex-direction: column;
		gap: 6px;
	}

	.vbh-leadstrip-step {
		flex-direction: row;
		align-items: baseline;
		gap: 8px;
		text-align: start;
	}

	.vbh-leadstrip-step + .vbh-leadstrip-step::before {
		display: none;
	}

	.vbh-leadstrip-text {
		flex-direction: row;
		flex-wrap: wrap;
		gap: 0 8px;
		padding: 0;
	}
}
</style>
