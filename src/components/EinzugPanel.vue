<template>
	<div class="vbh-einzug">
		<!-- Segmentleiste des Einzug-Unterreiters. Heute ein Segment; die Folge-Tickets hängen ihre
		     Segmente über `segments` (Script) und je einen eigenen Block unten ein. -->
		<div class="vbh-segments" role="tablist" :aria-label="t('Ansicht im Einzug')">
			<button
				v-for="seg in segments"
				:id="`vbh-einzug-tab-${seg.id}`"
				:key="seg.id"
				type="button"
				role="tab"
				class="vbh-segments-item"
				:class="{ active: segment === seg.id }"
				:aria-selected="segment === seg.id ? 'true' : 'false'"
				:aria-controls="`vbh-einzug-panel-${seg.id}`"
				@click="segment = seg.id">
				{{ seg.label }}
			</button>
		</div>

		<!-- ============ SEGMENT „ZEITSTRAHL & LÄUFE“ ============ -->
		<div
			v-show="segment === 'timeline'"
			id="vbh-einzug-panel-timeline"
			role="tabpanel"
			aria-labelledby="vbh-einzug-tab-timeline">
			<NcLoadingIcon v-if="!loaded && loading" :size="32" :name="t('Wird geladen…')" />

			<div v-else-if="!timeline" class="vbh-hint vbh-hint--warning">
				{{ error || t('Der Einzug konnte nicht geladen werden.') }}
				<NcButton size="small" @click="reload">
					{{ t('Erneut versuchen') }}
				</NcButton>
			</div>

			<template v-else>
				<!-- Die Serverantwort steht nicht in einer t()-Variable (die würde sie HTML-escapen). -->
				<p v-if="error" class="vbh-hint vbh-hint--warning" role="status">
					{{ t('Die Ansicht konnte nicht aktualisiert werden:') }} {{ error }}
				</p>

				<DebitTimeline
					:timeline="timeline"
					:selectedDate="selectedDate"
					:isMobile="isMobile"
					@select="selectDate"
					@shiftYear="shiftYear">
					<template #toolbar>
						<!-- Der Terminplan ist eine Einstellung der Buchhaltung (Terminverschiebung ab Buchhalter,
						     die Vorlaufzeiten darin nur für Verwalter, Spec §3.9); ein Revisor sieht seine
						     Wirkung am Strahl, nicht die Eingabefelder. -->
						<NcButton
							v-if="canWrite"
							variant="secondary"
							size="small"
							:aria-expanded="showSchedule ? 'true' : 'false'"
							aria-controls="vbh-einzug-schedule"
							@click="showSchedule = !showSchedule">
							<template #icon>
								<NcIconSvgWrapper :path="mdiCalendarEdit" :size="18" />
							</template>
							{{ t('Terminplan') }}
						</NcButton>
					</template>
				</DebitTimeline>

				<div v-if="canWrite && showSchedule" id="vbh-einzug-schedule">
					<DueDateScheduleSettings @changed="reload" />
				</div>

				<DebitGhostCard
					v-if="showGhost"
					:entry="selectedEntry"
					:preview="selectedPreview"
					:loading="previewLoadingFor === selectedDate"
					:error="previewError"
					:today="timeline.today"
					:isMobile="isMobile"
					@retry="loadPreview(selectedDate, { force: true })">
					<!-- Freigabe (Ticket #103): nur ab Buchhalter; für einen Revisor bleibt der Slot ungesetzt, die Karte rein lesend. -->
					<template v-if="canWrite" #actions="{ entry, preview }">
						<DebitReleaseAction :entry="entry" :preview="preview" :today="timeline.today" />
					</template>
				</DebitGhostCard>
				<p v-else-if="pastNote" class="vbh-hint">
					{{ pastNote }}
				</p>

				<section class="vbh-einzug-runs">
					<div class="vbh-einzug-runshead">
						<h4>{{ t('Läufe') }}</h4>
						<span v-if="runs.length" class="vbh-hint">{{ n('%n Lauf', '%n Läufe', runs.length) }}</span>
					</div>
					<DebitRunList
						:runs="runs"
						:expandedId="expandedRunId"
						:isMobile="isMobile"
						@toggle="toggleRun">
						<template #detail="{ run }">
							<DebitRunDetail
								:run="expandedRun && expandedRun.id === run.id ? expandedRun : run"
								:loading="runLoadingId === run.id"
								:error="runError"
								:isMobile="isMobile"
								@retry="loadRun(run.id, { force: true })">
								<!-- Download, Einreichung, Verwerfen, Termin verschieben (Ticket #103): nur ab Buchhalter. -->
								<template v-if="canWrite" #actions="{ run: actionRun }">
									<DebitRunActions :run="actionRun" :today="timeline.today" :releaseLeadDays="timeline.leadDays.release" />
								</template>
							</DebitRunDetail>
						</template>
					</DebitRunList>
				</section>
			</template>
		</div>

		<!-- ============ SEGMENT „FORDERUNGEN“ (Issue #104) ============ -->
		<div
			v-if="segment === 'claims'"
			id="vbh-einzug-panel-claims"
			role="tabpanel"
			aria-labelledby="vbh-einzug-tab-claims">
			<ClaimsSegment :canWrite="canWrite" :isMobile="isMobile" :active="active" />
		</div>

		<!-- ============ SEGMENT „BANKABGLEICH“ (Issue #105) ============ -->
		<div
			v-if="segment === 'bankabgleich'"
			id="vbh-einzug-panel-bankabgleich"
			role="tabpanel"
			aria-labelledby="vbh-einzug-tab-bankabgleich">
			<BankReconciliationSegment :canWrite="canWrite" :active="active" />
		</div>
	</div>
</template>

<script>
import { mdiCalendarEdit } from '@mdi/js'
import { NcButton, NcIconSvgWrapper, NcLoadingIcon } from '@nextcloud/vue'
import { toRefs } from 'vue'
import BankReconciliationSegment from './BankReconciliationSegment.vue'
import ClaimsSegment from './ClaimsSegment.vue'
import DebitGhostCard from './DebitGhostCard.vue'
import DebitReleaseAction from './DebitReleaseAction.vue'
import DebitRunActions from './DebitRunActions.vue'
import DebitRunDetail from './DebitRunDetail.vue'
import DebitRunList from './DebitRunList.vue'
import DebitTimeline from './DebitTimeline.vue'
import DueDateScheduleSettings from './DueDateScheduleSettings.vue'
import { useDebitRuns } from '../composables/useDebitRuns.js'
import { liveBatches } from '../lib/debitRun.js'

/**
 * Einzug-Unterreiter des Beiträge-Reiters (Spec §6 Variante A, Issue #102):
 * Zeitstrahl über das Beitragsjahr mit HEUTE-Marker und Meilensteinen, die
 * „Geisterkarte“ als Vorschau des gewählten Termins und die Läufe samt Detail.
 * Ersetzt das alte Einzug-Panel (SepaBatchPanel.vue, Alt-Modell), das nach dem
 * Cutover (#107) samt Datei entfällt.
 *
 * Aufbau für die Folge-Tickets: eine Segmentleiste („Zeitstrahl & Läufe“,
 * „Forderungen“ (#104), „Bankabgleich“ (#105)), eigene
 * Komponenten für Zeitstrahl, Geisterkarte, Lauf-Liste und Lauf-Detail mit
 * klaren Props/Slots, gemeinsamer Zustand in useDebitRuns(). Die Stellen, an
 * denen #103 Aktionen und #104/#105 Segmente einhängen, sind im Template
 * markiert („Einhängepunkt“).
 *
 * Lesend ab Rolle Revisor (Spec §3.9, IBAN maskiert): `canWrite` blendet nur
 * den Terminplan aus, alle Daten sind für beide Rollen dieselben.
 */
export default {
	name: 'EinzugPanel',
	components: { BankReconciliationSegment, ClaimsSegment, DebitGhostCard, DebitReleaseAction, DebitRunActions, DebitRunDetail, DebitRunList, DebitTimeline, DueDateScheduleSettings, NcButton, NcIconSvgWrapper, NcLoadingIcon },
	props: {
		isMobile: { type: Boolean, default: false },
		canWrite: { type: Boolean, default: false },
		// Ob der Unterreiter gerade angezeigt wird – geladen wird erst dann (und bei jeder Rückkehr frisch).
		active: { type: Boolean, default: false },
	},

	setup() {
		const debitRuns = useDebitRuns()
		return {
			...toRefs(debitRuns.state),
			selectedEntry: debitRuns.selectedEntry,
			selectedPreview: debitRuns.selectedPreview,
			expandedRun: debitRuns.expandedRun,
			reload: debitRuns.reload,
			loadPreview: debitRuns.loadPreview,
			loadRun: debitRuns.loadRun,
			selectDate: debitRuns.selectDate,
			toggleRun: debitRuns.toggleRun,
			shiftYear: debitRuns.shiftYear,
		}
	},

	data() {
		return {
			mdiCalendarEdit,
			segment: 'timeline',
			showSchedule: false,
		}
	},

	computed: {
		segments() {
			return [
				{ id: 'timeline', label: this.t('Zeitstrahl & Läufe') },
				{ id: 'claims', label: this.t('Forderungen') },
				{ id: 'bankabgleich', label: this.t('Bankabgleich') },
			]
		},

		// Die Karte zeigt, was an diesem Termin noch freizugeben ist – nicht, was schon vollständig im Lauf steckt.
		showGhost() {
			const entry = this.selectedEntry
			if (!entry) { return false }
			const hasOpenClaims = entry.preview.count > 0
			if (liveBatches(entry).length > 0 && !hasOpenClaims) { return false }
			return entry.dueDate >= this.timeline.today || hasOpenClaims
		},

		// Ein vergangener Termin ohne Vorschau braucht keine Karte, aber eine Auskunft.
		pastNote() {
			const entry = this.selectedEntry
			if (!entry || liveBatches(entry).length > 0) { return '' }
			return entry.batches.length > 0
				? this.t('Der Lauf zu diesem Termin wurde verworfen und es gibt nichts mehr freizugeben (siehe Läufe).')
				: this.t('An diesem Termin war nichts einzuziehen.')
		},
	},

	watch: {
		active: {
			immediate: true,
			handler(value) { if (value) { this.reload() } },
		},
	},

	mounted() {
		window.addEventListener('focus', this.onWindowFocus)
	},

	beforeUnmount() {
		window.removeEventListener('focus', this.onWindowFocus)
	},

	methods: {
		// Wer aus einem anderen Fenster zurückkommt, soll keinen veralteten Stand sehen
		// (Freigabe/Einreichung kann eine andere Person gemacht haben).
		onWindowFocus() {
			if (this.active && !document.hidden) { this.reload() }
		},
	},
}
</script>

<style scoped>
.vbh-segments {
	display: inline-flex;
	max-width: 100%;
	overflow-x: auto;
	margin-bottom: 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-hover);
}

/* Nackte <button>: doppelte Klasse gegen Nextclouds Grundstil, Breite/Außenabstand ausdrücklich zurückgesetzt. */
.vbh-segments-item.vbh-segments-item {
	flex: 0 0 auto;
	width: auto;
	min-height: 36px;
	margin: 0;
	padding: 4px 16px;
	border: none;
	border-radius: var(--border-radius-large, 12px);
	background: none;
	color: var(--color-main-text);
	font-weight: 600;
	white-space: nowrap;
	cursor: pointer;
}

.vbh-segments-item.vbh-segments-item:hover {
	background-color: var(--color-background-dark);
}

.vbh-segments-item.vbh-segments-item.active {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.vbh-einzug-runs {
	margin: 14px 0;
}

.vbh-einzug-runshead {
	display: flex;
	align-items: baseline;
	gap: 12px;
}

.vbh-einzug-runshead h4 {
	margin: 0;
}
</style>
