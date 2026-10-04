<template>
	<section class="vbh-ghost" :aria-label="t('Vorschau des Einzugs am {datum}', { datum: formatDate(entry.dueDate) })">
		<header class="vbh-ghost-head">
			<span class="vbh-ghost-badge">{{ t('Vorschau') }}</span>
			<h4>{{ t('Einzug am {datum}', { datum: formatDate(entry.dueDate) }) }}</h4>
			<span class="vbh-ghost-when">{{ relativeDays(entry.dueDate, today) }}</span>
		</header>
		<p class="vbh-ghost-note">
			{{ t('Das ist nur eine Vorschau: Vor der Freigabe gibt es keinen Lauf, es wird nichts gespeichert.') }}
		</p>

		<NcLoadingIcon v-if="loading && !preview" :size="24" :name="t('Wird geladen…')" />
		<div v-else-if="error && !preview" class="vbh-hint vbh-hint--warning">
			{{ error }}
			<NcButton size="small" @click="$emit('retry')">
				{{ t('Erneut versuchen') }}
			</NcButton>
		</div>

		<template v-else>
			<dl class="vbh-ghost-stats">
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

			<!-- Eskalierender Hinweis, kein Sperrgrund: eine gerissene Vorlauffrist blockiert nichts (Spec §3.5). -->
			<p v-if="urgency !== 'none'" class="vbh-hint vbh-hint--warning" role="status">
				{{ urgencyText }}
			</p>
			<p v-else-if="summary.count === 0" class="vbh-hint vbh-hint--info">
				{{ emptyText }}
			</p>

			<div v-if="issues.length" class="vbh-ghost-issues">
				<h5>{{ t('Störfälle zu diesem Termin') }}</h5>
				<ul>
					<li v-for="(issue, index) in sortedIssues" :key="index">
						<DebitStatusTag kind="severity" :value="issue.severity" />
						<span>{{ issue.message }}</span>
					</li>
				</ul>
				<p class="vbh-hint">
					{{ t('Störfälle blockieren nichts und müssen nicht quittiert werden – sie verschwinden von selbst, sobald ihre Ursache behoben ist.') }}
				</p>
			</div>

			<div v-if="claims.length" class="vbh-ghost-claims">
				<h5>{{ t('Forderungen in der Vorschau') }}</h5>
				<div v-if="isMobile" class="vbh-cardlist">
					<div v-for="claim in shownClaims" :key="claim.id" class="vbh-mcard">
						<div class="vbh-mcard-top">
							<span class="vbh-mcard-title">{{ claim.memberDisplayName }}</span>
							<span class="vbh-mcard-amount">{{ formatMoney(claim.amount) }}</span>
						</div>
						<div class="vbh-mcard-bottom">
							<span class="vbh-mcard-accounts">{{ claim.description }}</span>
						</div>
					</div>
				</div>
				<div v-else class="vbh-tablecard">
					<table class="vbh-table">
						<thead>
							<tr>
								<th>{{ t('Mitglied') }}</th>
								<th>{{ t('Bezeichnung') }}</th>
								<th class="num">
									{{ t('Betrag') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="claim in shownClaims" :key="claim.id">
								<td>{{ claim.memberDisplayName }}</td>
								<td>{{ claim.description }}</td>
								<td class="num nowrap">
									{{ formatMoney(claim.amount) }}
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<NcButton
					v-if="claims.length > LIMIT && !showAll"
					variant="tertiary"
					size="small"
					@click="showAll = true">
					{{ t('Alle {n} Forderungen anzeigen', { n: claims.length }) }}
				</NcButton>
			</div>
		</template>

		<!-- Einhängepunkt für Ticket #103: hier kommt die Schaltfläche „Freigeben & Datei erzeugen“ hinein
		     (Slot `actions`, Props: entry, preview). Bis dahin bleibt der Slot leer und rendert nichts. -->
		<div v-if="$slots.actions" class="vbh-ghost-actions">
			<slot name="actions" :entry="entry" :preview="preview" />
		</div>
	</section>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import DebitStatusTag from './DebitStatusTag.vue'
import { issueSummary, milestoneOf, relativeDays, releaseUrgency } from '../lib/debitRun.js'
import { formatDate, formatMoney } from '../lib/format.js'

/** So viele Forderungen zeigt die Vorschau zunächst; der Rest kommt auf Knopfdruck. */
const LIMIT = 8

/**
 * Die „Geisterkarte“ (Spec §6 Variante A, Issue #102): Vorschau des gewählten
 * Termins mit Anzahl Forderungen, Summe und Störfällen in zwei Schweregraden.
 * Datenquelle ist die lesende Lauf-Abfrage (GET /debit-batches/preview) – vor
 * der Freigabe existiert kein Lauf-Datensatz, deshalb ist die Karte bewusst als
 * Vorschau gekennzeichnet (gestrichelter Rand) und zeigt nichts, was sich
 * speichern ließe.
 *
 * Rein darstellend. Die Freigabe selbst (Schaltfläche, Bestätigungsdialog)
 * gehört zu Ticket #103 und hängt über den Slot `actions` ein.
 */
export default {
	name: 'DebitGhostCard',
	components: { DebitStatusTag, NcButton, NcLoadingIcon },
	props: {
		// Eintrag des Termins aus dem Zeitstrahl (Meilensteine, Zusammenfassung)
		entry: { type: Object, required: true },
		// Antwort von GET /debit-batches/preview, solange sie noch nicht da ist: null
		preview: { type: Object, default: null },
		loading: { type: Boolean, default: false },
		error: { type: String, default: null },
		today: { type: String, required: true },
		isMobile: { type: Boolean, default: false },
	},

	emits: ['retry'],

	data() {
		return { showAll: false, LIMIT }
	},

	computed: {
		// Die frische Vorschau gewinnt, bis sie da ist trägt die Zusammenfassung aus dem Zeitstrahl.
		summary() { return this.preview?.summary || this.entry.preview },

		issues() { return this.preview?.issues || [] },

		sortedIssues() {
			const rank = (i) => (i.severity === 'handlungsbedarf' ? 0 : 1)
			return [...this.issues].sort((a, b) => rank(a) - rank(b))
		},

		claims() { return this.preview?.claims || [] },

		shownClaims() { return this.showAll ? this.claims : this.claims.slice(0, LIMIT) },

		urgency() { return releaseUrgency({ ...this.entry, preview: this.summary }, this.today) },

		urgencyText() {
			const release = milestoneOf(this.entry, 'release')
			const date = formatDate(release?.date)
			if (this.urgency === 'due') {
				return this.t('Freigabe fällig: Ab heute ({datum}) sollte der Lauf freigegeben und bei der Bank eingereicht sein.', { datum: date })
			}
			if (this.urgency === 'overdue') {
				return this.t('Freigabe überfällig seit {datum}. Das blockiert nichts: Der Lauf lässt sich weiterhin freigeben.', { datum: date })
			}
			return this.t('Der Einzugstermin ist verstrichen, der Lauf wurde nicht freigegeben. Das blockiert nichts: Er lässt sich weiterhin freigeben.')
		},

		// Ohne Forderungen: entweder entstehen sie erst (Vorwarnfenster noch nicht erreicht) oder es gibt wirklich keine.
		emptyText() {
			const warning = milestoneOf(this.entry, 'warning')
			if (warning && this.today < warning.date) {
				return this.t('Die Forderungen zu diesem Termin entstehen automatisch ab dem {datum} (Vorwarnfenster). Bis dahin ist hier nichts zu sehen.', { datum: formatDate(warning.date) })
			}
			return this.t('Zu diesem Termin ist keine einzugsfähige Forderung offen.')
		},
	},

	watch: {
		// Ein anderer Termin beginnt wieder mit der Kurzliste.
		'entry.dueDate': {
			handler() { this.showAll = false },
		},
	},

	methods: { formatDate, formatMoney, issueSummary, relativeDays },
}
</script>

<style scoped>
/* Gestrichelter Rand und durchscheinender Grund: „noch nicht da“, nur Vorschau. */
.vbh-ghost {
	border: 2px dashed var(--color-primary-element);
	border-radius: var(--border-radius-large, 12px);
	padding: 12px 16px;
	margin: 10px 0;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.vbh-ghost-head {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 4px 10px;
}

.vbh-ghost-head h4 {
	margin: 0;
}

.vbh-ghost-badge {
	padding: 1px 8px;
	border-radius: 10px;
	font-size: 0.78em;
	font-weight: 700;
	letter-spacing: 0.03em;
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.vbh-ghost-when {
	font-size: 0.85em;
	opacity: 0.85;
}

.vbh-ghost-note {
	margin: 4px 0 8px;
	font-size: 0.85em;
}

.vbh-ghost-stats {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 28px;
	margin: 8px 0;
}

.vbh-ghost-stats > div {
	display: flex;
	flex-direction: column;
}

/* Nextcloud gibt dt/dd Innenabstand und setzt dt rechtsbündig. */
.vbh-ghost-stats dt {
	margin: 0;
	padding: 0;
	text-align: start;
	font-size: 0.78em;
	opacity: 0.85;
}

.vbh-ghost-stats dd {
	margin: 0;
	padding: 0;
	font-size: 1.15em;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}

.vbh-ghost-issues h5,
.vbh-ghost-claims h5 {
	margin: 12px 0 4px;
}

.vbh-ghost-issues ul {
	margin: 0;
	padding: 0;
	list-style: none;
}

.vbh-ghost-issues li {
	display: flex;
	align-items: baseline;
	gap: 8px;
	padding: 3px 0;
}

.vbh-ghost-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 12px;
}

/* Hat die Freigabe nichts zu tun (keine Forderung), rendert der Slot nur einen Kommentar: dann auch keinen Außenabstand. */
.vbh-ghost-actions:empty {
	display: none;
}

/* Die Hinweise tragen ihre eigene Fläche – im hellen Grund der Karte gut lesbar, im dunklen ebenso. */
.vbh-ghost .vbh-hint {
	color: inherit;
}

.vbh-ghost .vbh-hint--info,
.vbh-ghost .vbh-hint--warning {
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.vbh-ghost .vbh-tablecard,
.vbh-ghost .vbh-mcard {
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}
</style>
