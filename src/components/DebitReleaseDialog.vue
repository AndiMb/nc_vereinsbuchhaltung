<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-debit-release"
		size="normal"
		:closeOnClickOutside="!saving"
		:noClose="saving"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-debit-release" class="vbh-modal-title">
				{{ t('Einzug am {datum} freigeben', { datum: formatDate(dueDate) }) }}
			</h2>

			<dl class="vbh-release-facts">
				<div>
					<dt>{{ t('Forderungen') }}</dt>
					<dd>{{ summary.count }}</dd>
				</div>
				<div>
					<dt>{{ t('Summe') }}</dt>
					<dd>{{ formatMoney(summary.sumCents / 100) }}</dd>
				</div>
				<div>
					<dt>{{ t('Störfälle') }}</dt>
					<dd>{{ issueSummary(issues) }}</dd>
				</div>
			</dl>

			<!-- Nur ein Hinweis, kein Sperrgrund (Spec §3.5): eine gerissene Vorlauffrist blockiert nichts. -->
			<p v-if="late" class="vbh-hint vbh-hint--warning" role="status">
				{{ t('Der Einzugstermin liegt in der Vergangenheit. Das blockiert nichts, die Freigabe geht trotzdem. Die Bank belastet aber frühestens am Termin: Verschieben Sie ihn nach der Freigabe nach hinten, bevor Sie die Datei einreichen.') }}
			</p>

			<div v-if="issues.length" class="vbh-release-issues">
				<h3 class="vbh-modal-subtitle">
					{{ t('Störfälle zu diesem Termin') }}
				</h3>
				<ul>
					<li v-for="(item, index) in shownIssues" :key="index">
						<template v-if="item.type === 'task'">
							<DebitStatusTag kind="severity" :value="item.task.severity" />
							<span>{{ item.task.message }}</span>
						</template>
						<!-- Gleichartige Störfälle als eine Zeile mit Zähler; die einzelnen stehen in der Vorschau. -->
						<template v-else>
							<DebitStatusTag kind="severity" :value="item.severity" />
							<span><strong>{{ item.title }}</strong> · {{ item.tasks.length }}</span>
						</template>
					</li>
				</ul>
				<p v-if="hiddenIssues > 0" class="vbh-hint">
					{{ n('… und %n weiterer Störfall (siehe Vorschau).', '… und %n weitere Störfälle (siehe Vorschau).', hiddenIssues) }}
				</p>
				<p class="vbh-hint">
					{{ t('Störfälle blockieren die Freigabe nicht. Betroffene Forderungen sind nicht im Lauf, sondern bleiben offen.') }}
				</p>
			</div>

			<!-- Reibung vor einer nicht umkehrbaren Aktion: die EndToEndIds sind der Punkt, der sich nie zurücknehmen lässt. -->
			<div class="vbh-card vbh-card--danger vbh-release-warning">
				<p>
					<strong>{{ t('Das lässt sich nicht ungeschehen machen:') }}</strong>
				</p>
				<ul>
					<li>{{ t('Beträge, IBAN und Kontoinhaber der Posten werden jetzt eingefroren und die SEPA-Datei (pain.008) erzeugt.') }}</li>
					<li>{{ t('Jeder Posten bekommt eine eigene EndToEndId. Diese Kennungen werden nie wiederverwendet, auch nicht, wenn Sie den Lauf später verwerfen und neu freigeben.') }}</li>
					<li>{{ t('Freigegeben heißt noch nicht eingereicht: Danach laden Sie die Datei herunter, reichen sie bei der Bank ein und bestätigen das als zweiten Schritt.') }}</li>
				</ul>
			</div>

			<!-- Die Serverantwort steht nicht in einer t()-Variable (die würde sie HTML-escapen). -->
			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>

			<div class="vbh-modal-actions vbh-release-actions">
				<NcButton
					ref="cancelButton"
					variant="tertiary"
					:disabled="saving"
					@click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving || summary.count === 0" @click="$emit('confirm')">
					{{ saving ? t('Wird freigegeben…') : t('Freigeben & Datei erzeugen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'
import DebitStatusTag from './DebitStatusTag.vue'
import { issueSummary } from '../lib/debitRun.js'
import { formatDate, formatMoney } from '../lib/format.js'
import { groupTasks } from '../lib/tasks.js'

/** So viele Störfälle nennt der Dialog einzeln; der Rest steht in der Vorschau dahinter. */
const ISSUE_LIMIT = 5

/**
 * Bestätigungsdialog für Schritt 1 „Freigeben & Datei erzeugen“ (Issue #103,
 * Spec §3.5/§6): zeigt Termin, Anzahl, Summe und Störfälle der Vorschau und
 * sagt vor dem Klick, was sich danach nicht mehr zurücknehmen lässt. Rein
 * darstellend – die Freigabe selbst löst DebitReleaseAction.vue aus.
 *
 * Der Fokus startet auf „Abbrechen“: ein versehentliches Enter soll nichts
 * freigeben. Während der Freigabe lässt sich der Dialog nicht schließen, damit
 * die Antwort nicht an einen schon weggeklickten Dialog geht.
 */
export default {
	name: 'DebitReleaseDialog',
	components: { DebitStatusTag, NcButton, NcModal },
	props: {
		show: { type: Boolean, default: false },
		dueDate: { type: String, required: true },
		// { count, sumCents } der Vorschau
		summary: { type: Object, required: true },
		// Störfälle der Vorschau, Handlungsbedarf zuerst
		issues: { type: Array, default: () => [] },
		// Der Einzugstermin liegt vor dem heutigen Tag
		late: { type: Boolean, default: false },
		saving: { type: Boolean, default: false },
		error: { type: String, default: null },
	},

	emits: ['close', 'confirm', 'update:show'],

	computed: {
		// Gleichartige Störfälle sind eine Zeile; gezählt wird, was dahinter verschwindet.
		issueEntries() { return groupTasks(this.issues) },
		shownIssues() { return this.issueEntries.slice(0, ISSUE_LIMIT) },
		hiddenIssues() {
			return this.issueEntries.slice(ISSUE_LIMIT).reduce((sum, item) => sum + (item.type === 'group' ? item.tasks.length : 1), 0)
		},
	},

	watch: {
		show(open) {
			// Wie focusOnOpen() (NcModal-Fokus-Race), aber ohne Scrollen: auf dem Handy ist der Dialog höher als
			// der Bildschirm, und ein Fokus auf den Knopf unten rollte den Titel und die Zusammenfassung aus dem Bild.
			if (open) { this.$nextTick(() => this.$refs.cancelButton?.$el?.focus({ preventScroll: true })) }
		},
	},

	methods: { formatDate, formatMoney, issueSummary },
}
</script>

<style scoped>
.vbh-release-facts {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 28px;
	margin: 0 0 12px;
}

.vbh-release-facts > div {
	display: flex;
	flex-direction: column;
}

/* Nextcloud gibt dt/dd Innenabstand und setzt dt rechtsbündig. */
.vbh-release-facts dt {
	margin: 0;
	padding: 0;
	text-align: start;
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
}

.vbh-release-facts dd {
	margin: 0;
	padding: 0;
	font-size: 1.1em;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}

.vbh-release-issues ul {
	margin: 0;
	padding: 0;
	list-style: none;
}

.vbh-release-issues li {
	display: flex;
	align-items: baseline;
	gap: 8px;
	padding: 3px 0;
}

.vbh-release-warning {
	margin: 12px 0;
}

.vbh-release-warning p {
	margin: 0 0 4px;
}

/* Nextcloud setzt ul auf list-style: none zurück. */
.vbh-release-warning ul {
	margin: 0;
	padding-inline-start: 20px;
	list-style: disc;
}

.vbh-release-warning li {
	padding: 3px 0;
}

/* Auf dem Handy passen „Abbrechen“ und die lange Beschriftung nicht nebeneinander: umbrechen statt abschneiden. */
@media (max-width: 640px) {
	.vbh-release-actions {
		flex-wrap: wrap;
	}
}
</style>
