<template>
	<section class="vbh-card">
		<div class="vbh-cardhead">
			<h4>{{ t('Terminplan') }}</h4>
			<InfoHint :label="t('Wie funktioniert der Terminplan?')">
				<p>
					{{ t('Je Turnus gilt ein Einzugstag, gezählt in Tagen ab Beginn jeder Periode. Einzelne Perioden können einen abweichenden Termin bekommen; er gilt in jedem Beitragsjahr für dieselbe Periode.') }}
				</p>
				<p>
					{{ t('Tritt ein Mitglied mitten in einer Periode ein, zählen die Tage für seine erste Forderung ab dem Eintrittsdatum.') }}
				</p>
			</InfoHint>
		</div>
		<p class="vbh-hint vbh-schedule-intro">
			{{ t('An welchen Tagen die Beiträge fällig sind und eingezogen werden. Die Termine unten gelten für das Beitragsjahr {year}.', { year: yearLabel }) }}
		</p>

		<NcLoadingIcon v-if="!loaded" :size="24" />
		<DueDateIntervalSchedule
			v-for="interval in (loaded ? shownIntervals : [])"
			:key="interval"
			:intervalMonths="interval"
			:saved="schedule[interval]"
			:memberCount="memberCount(interval)"
			:year="year"
			:offsetRange="offsetRange"
			@saved="onSaved(interval, $event)" />

		<NcButton
			v-if="usedIntervals.length > 0 && unusedIntervals.length > 0"
			variant="tertiary"
			class="vbh-schedule-more"
			:aria-expanded="showUnused ? 'true' : 'false'"
			@click="showUnused = !showUnused">
			<template #icon>
				<NcIconSvgWrapper :path="showUnused ? mdiChevronDown : mdiChevronRight" :size="20" />
			</template>
			{{ showUnused
				? t('Turnusse ohne Mitglieder ausblenden')
				: t('Turnusse ohne Mitglieder: {list}', { list: unusedIntervals.map(intervalLabel).join(', ') }) }}
		</NcButton>

		<div class="vbh-schedule-leads" data-testid="lead-days-summary">
			<h5>{{ t('Fristen vor dem Einzug') }}</h5>
			<p class="vbh-hint">
				{{ t('Am Beispiel des Einzugs am {date}. Die Fristen gelten für alle Turnusse.', { date: dueDateLabel(exampleDue, language) }) }}
			</p>
			<LeadDaysStrip :dueDate="exampleDue" :leadDays="{ warningLeadDays, prenotificationLeadDays, releaseLeadDays }" />
			<a v-if="isAdmin" class="vbh-settings-link" :href="settingsUrl">{{ t('Fristen in den Einstellungen ändern') }}</a>
		</div>
	</section>
</template>

<script>
import { mdiChevronDown, mdiChevronRight } from '@mdi/js'
import { getLanguage } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcIconSvgWrapper, NcLoadingIcon } from '@nextcloud/vue'
import { toRefs } from 'vue'
import DueDateIntervalSchedule from './DueDateIntervalSchedule.vue'
import InfoHint from './InfoHint.vue'
import LeadDaysStrip from './LeadDaysStrip.vue'
import { useAuth } from '../composables/useAuth.js'
import { useDueDateSchedule } from '../composables/useDueDateSchedule.js'
import { dueDateLabel } from '../lib/dueDateSchedule.js'
import { intervalLabel } from '../lib/frequency.js'
import { nextMonthStart } from '../lib/sepaSettings.js'

/**
 * Terminplan-Einstellung (Spec §2.2/§3.5, Issue #70): je Turnus ein
 * Standard-Einzugstag + abweichende Termine einzelner Perioden. Jeder Turnus
 * ist ein eigener Entwurf mit Live-Vorschau der Termine
 * (DueDateIntervalSchedule.vue); Turnusse ohne Mitglieder treten zurück,
 * solange es genutzte gibt. Die Vorschau zeigt das Beitragsjahr, das der
 * Zeitstrahl darüber gerade zeigt, die Fristen-Leiste dessen nächsten
 * Einzug.
 *
 * Rollen (Spec §3.9, Issue #119): Die Terminverschiebung (Standard-Einzugstag,
 * Überschreibungen) ist `buchhalter`. Vorwarnfenster, Vorabinfo-Vorlauf und
 * Freigabe-Vorlauf sind Einstellungen (nur `verwalter`) und stehen in den
 * Nextcloud-Einstellungen (SettingsSepaCycle.vue); hier zeigt sie die
 * Fristen-Leiste an einem echten Termin, mit Verweis für Verwalter.
 */
export default {
	name: 'DueDateScheduleSettings',
	components: { DueDateIntervalSchedule, InfoHint, LeadDaysStrip, NcButton, NcIconSvgWrapper, NcLoadingIcon },

	props: {
		/** Anker-Kalenderjahr des Beitragsjahrs, das der Zeitstrahl zeigt */
		year: { type: Number, required: true },
		/** dessen Bezeichnung („2026“ oder „2026/27“) */
		yearLabel: { type: String, required: true },
		/** Stichtag „JJJJ-MM-TT“ */
		today: { type: String, required: true },
		/** nächster Einzugstermin des Zeitstrahls, null ohne jeden Termin */
		nextDueDate: { type: String, default: null },
	},

	// Nach jeder gespeicherten Änderung: der Einzug-Unterreiter (EinzugPanel)
	// rechnet daraufhin den Zeitstrahl neu.
	emits: ['changed'],

	setup() {
		const schedule = useDueDateSchedule()
		const { state: auth, isAdmin } = useAuth()
		return { ...toRefs(schedule.state), loadDueDateSchedule: schedule.loadDueDateSchedule, isAdmin, auth, mdiChevronDown, mdiChevronRight }
	},

	data() {
		return {
			intervals: [1, 2, 3, 4, 6, 12],
			language: getLanguage(),
			// Erst nach dem Laden zeigen: sonst rechnete jeder Turnus zuerst eine Vorschau mit Versatz 0.
			loaded: false,
			showUnused: false,
		}
	},

	computed: {
		usedIntervals() {
			return this.intervals.filter((interval) => this.memberCount(interval) > 0)
		},

		unusedIntervals() {
			return this.intervals.filter((interval) => this.memberCount(interval) === 0)
		},

		// Ohne ein einziges zugewiesenes Mitglied gibt es nichts zurückzustellen: dann alle.
		relevantIntervals() {
			return this.usedIntervals.length > 0 ? this.usedIntervals : this.intervals
		},

		shownIntervals() {
			return this.showUnused ? this.intervals : this.relevantIntervals
		},

		// Ohne jeden Termin (noch keine Zuweisung) wie in den Einstellungen: der nächste Monatserste.
		exampleDue() {
			return this.nextDueDate ?? nextMonthStart(this.today)
		},

		// Verwaltung für Nextcloud-Admins, Persönlich für App-Verwalter ohne Nextcloud-Adminrechte
		// (dieselbe Unterscheidung wie Settings\PersonalSettings::getSection()).
		settingsUrl() {
			const area = this.auth.me?.isServerAdmin ? 'admin' : 'user'
			return generateUrl('/settings/' + area + '/vereinsbuchhaltung') + '#settings-section_beitraege-sepa'
		},
	},

	async mounted() {
		await this.loadDueDateSchedule()
		this.loaded = true
	},

	methods: {
		dueDateLabel,
		intervalLabel,

		memberCount(interval) {
			return this.memberCounts?.[interval] ?? 0
		},

		// Nur der gespeicherte Turnus bekommt einen neuen Stand: die Entwürfe der anderen bleiben stehen.
		onSaved(interval, saved) {
			this.schedule[interval] = saved
			this.$emit('changed')
		},
	},
}
</script>

<style scoped>
/* Hauptschriftfarbe mit Unterstreichung: lesbar in jedem Design, auch im dunklen */
.vbh-settings-link {
	color: var(--color-main-text);
	font-weight: 600;
	text-decoration: underline;
}

.vbh-cardhead {
	display: flex;
	align-items: center;
	justify-content: flex-start;
	gap: 4px;
	margin-bottom: 8px;
}

.vbh-cardhead h4 {
	margin: 0;
}

.vbh-schedule-intro {
	margin: 0 0 4px;
}

.vbh-schedule-more {
	margin: 4px 0 8px;
}

.vbh-schedule-leads {
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.vbh-schedule-leads h5 {
	margin: 0 0 4px;
	font-weight: 700;
}
</style>
