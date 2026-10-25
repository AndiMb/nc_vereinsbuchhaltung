<template>
	<NcModal
		:show="true"
		labelId="vbh-modal-title-schedule"
		size="large"
		:closeOnClickOutside="false"
		@close="requestClose">
		<div class="vbh-modal-inner">
			<div class="vbh-schedule-head">
				<h2 id="vbh-modal-title-schedule" class="vbh-modal-title">
					{{ t('Terminplan') }}
				</h2>
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
				{{ t('An welchen Tagen die Beiträge fällig sind und eingezogen werden.') }}
			</p>
			<div class="vbh-schedule-year">
				<NcButton
					variant="tertiary"
					size="small"
					:aria-label="t('Vorheriges Beitragsjahr')"
					@click="year--">
					<template #icon>
						<NcIconSvgWrapper :path="mdiChevronLeft" :size="20" />
					</template>
				</NcButton>
				<span>{{ t('Termine im Beitragsjahr {jahr}', { jahr: yearLabelFor(year) }) }}</span>
				<NcButton
					variant="tertiary"
					size="small"
					:aria-label="t('Nächstes Beitragsjahr')"
					@click="year++">
					<template #icon>
						<NcIconSvgWrapper :path="mdiChevronRight" :size="20" />
					</template>
				</NcButton>
			</div>

			<NcLoadingIcon v-if="!loaded" :size="24" />
			<template v-else>
				<DueDateIntervalSchedule
					v-for="interval in relevantIntervals"
					:key="interval"
					v-bind="intervalProps(interval)"
					@saved="onSaved(interval, $event)"
					@dirty="dirty[interval] = $event" />

				<!-- Die Turnusse ohne Mitglieder klappen unter dem Knopf auf, nicht dazwischen:
				     sonst landeten sie über dem Knopf und damit außer Sicht. -->
				<template v-if="usedIntervals.length > 0 && unusedIntervals.length > 0">
					<NcButton
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
					<template v-if="showUnused">
						<DueDateIntervalSchedule
							v-for="interval in unusedIntervals"
							:key="interval"
							v-bind="intervalProps(interval)"
							@saved="onSaved(interval, $event)"
							@dirty="dirty[interval] = $event" />
					</template>
				</template>
			</template>

			<div class="vbh-schedule-leads" data-testid="lead-days-summary">
				<h3>{{ t('Fristen vor dem Einzug') }}</h3>
				<p class="vbh-hint">
					{{ t('Am Beispiel des Einzugs am {date}. Die Fristen gelten für alle Turnusse.', { date: dueDateLabel(exampleDue, language) }) }}
				</p>
				<LeadDaysStrip :dueDate="exampleDue" :leadDays="{ warningLeadDays, prenotificationLeadDays, releaseLeadDays }" />
				<a v-if="isAdmin" class="vbh-settings-link" :href="settingsUrl">{{ t('Fristen in den Einstellungen ändern') }}</a>
			</div>

			<div class="vbh-modal-actions">
				<NcButton @click="requestClose">
					{{ t('Schließen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { mdiChevronDown, mdiChevronLeft, mdiChevronRight } from '@mdi/js'
import { getLanguage } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcIconSvgWrapper, NcLoadingIcon, NcModal } from '@nextcloud/vue'
import { toRefs } from 'vue'
import DueDateIntervalSchedule from './DueDateIntervalSchedule.vue'
import InfoHint from './InfoHint.vue'
import LeadDaysStrip from './LeadDaysStrip.vue'
import { useAuth } from '../composables/useAuth.js'
import { useConfirm } from '../composables/useConfirm.js'
import { useDueDateSchedule } from '../composables/useDueDateSchedule.js'
import { dueDateLabel } from '../lib/dueDateSchedule.js'
import { intervalLabel } from '../lib/frequency.js'
import { nextMonthStart } from '../lib/sepaSettings.js'

/**
 * Der Terminplan als Dialog (Spec §2.2/§3.5, Issue #70): je Turnus ein
 * Standard-Einzugstag + abweichende Termine einzelner Perioden. Jeder Turnus
 * ist ein eigener Entwurf mit Live-Vorschau der Termine
 * (DueDateIntervalSchedule.vue); Turnusse ohne Mitglieder treten zurück,
 * solange es genutzte gibt. Die Vorschau beginnt mit dem Beitragsjahr, das
 * der Zeitstrahl gerade zeigt, und blättert im Dialog weiter; die
 * Fristen-Leiste zeigt den nächsten echten Einzug des Zeitstrahls.
 *
 * Rollen (Spec §3.9, Issue #119): Die Terminverschiebung (Standard-Einzugstag,
 * Überschreibungen) ist `buchhalter`. Vorwarnfenster, Vorabinfo-Vorlauf und
 * Freigabe-Vorlauf sind Einstellungen (nur `verwalter`) und stehen in den
 * Nextcloud-Einstellungen (SettingsSepaCycle.vue); hier zeigt sie die
 * Fristen-Leiste an einem echten Termin, mit Verweis für Verwalter.
 */
export default {
	name: 'DueDateScheduleDialog',
	components: { DueDateIntervalSchedule, InfoHint, LeadDaysStrip, NcButton, NcIconSvgWrapper, NcLoadingIcon, NcModal },

	props: {
		/** Anker-Kalenderjahr des Beitragsjahrs, das der Zeitstrahl zeigt */
		initialYear: { type: Number, required: true },
		/** dessen Bezeichnung („2026“ oder „2026/27“) */
		initialYearLabel: { type: String, required: true },
		/** Stichtag „JJJJ-MM-TT“ */
		today: { type: String, required: true },
		/** nächster Einzugstermin des Zeitstrahls, null ohne jeden Termin */
		nextDueDate: { type: String, default: null },
	},

	// changed: nach jeder gespeicherten Änderung, der Einzug-Unterreiter rechnet
	// daraufhin den Zeitstrahl neu.
	emits: ['changed', 'close'],

	setup() {
		const schedule = useDueDateSchedule()
		const { state: auth, isAdmin } = useAuth()
		return {
			...toRefs(schedule.state),
			loadDueDateSchedule: schedule.loadDueDateSchedule,
			askConfirm: useConfirm().askConfirm,
			isAdmin,
			auth,
			mdiChevronDown,
			mdiChevronLeft,
			mdiChevronRight,
		}
	},

	data() {
		return {
			intervals: [1, 2, 3, 4, 6, 12],
			language: getLanguage(),
			year: this.initialYear,
			// Erst nach dem Laden zeigen: sonst rechnete jeder Turnus zuerst eine Vorschau mit Versatz 0.
			loaded: false,
			showUnused: false,
			// Turnusmonate => ungespeicherter Entwurf
			dirty: {},
			confirmingClose: false,
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

		intervalProps(interval) {
			return {
				intervalMonths: interval,
				saved: this.schedule[interval],
				memberCount: this.memberCount(interval),
				year: this.year,
				offsetRange: this.offsetRange,
			}
		},

		/**
		 * Bezeichnung eines Beitragsjahrs im Muster, das der Zeitstrahl mitgibt
		 * („2026“ oder „2026/27“, siehe PeriodRule::proposeLabel()).
		 *
		 * @param {number} year Anker-Kalenderjahr
		 * @return {string}
		 */
		yearLabelFor(year) {
			return this.initialYearLabel.includes('/') ? `${year}/${String((year + 1) % 100).padStart(2, '0')}` : String(year)
		},

		// Nur der gespeicherte Turnus bekommt einen neuen Stand: die Entwürfe der anderen bleiben stehen.
		onSaved(interval, saved) {
			this.schedule[interval] = saved
			this.$emit('changed')
		},

		/** Schließen (X, Esc, „Schließen“): mit ungespeicherten Entwürfen erst nachfragen. */
		async requestClose() {
			if (this.confirmingClose) { return }
			if (Object.values(this.dirty).some(Boolean)) {
				this.confirmingClose = true
				try {
					const discard = await this.askConfirm(
						this.t('Änderungen verwerfen?'),
						this.t('Im Terminplan sind ungespeicherte Änderungen. Beim Schließen gehen sie verloren.'),
						this.t('Verwerfen'),
						'error',
					)
					if (!discard) { return }
				} finally { this.confirmingClose = false }
			}
			this.$emit('close')
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

/* Der Titel teilt sich die Zeile mit dem Info-Symbol; den Abstand zum Schließen-X hält der Kopf. */
.vbh-schedule-head {
	display: flex;
	align-items: center;
	gap: 4px;
	margin: 0 44px 8px 0;
}

.vbh-schedule-head .vbh-modal-title {
	margin: 0;
}

.vbh-schedule-intro {
	margin: 0 0 4px;
}

.vbh-schedule-year {
	display: flex;
	align-items: center;
	gap: 4px;
	font-weight: 600;
}

.vbh-schedule-more {
	margin: 4px 0 8px;
}

.vbh-schedule-leads {
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.vbh-schedule-leads h3 {
	margin: 0 0 4px;
	font-size: 1em;
	font-weight: 700;
}
</style>
