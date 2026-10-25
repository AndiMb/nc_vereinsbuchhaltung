<template>
	<!-- show bleibt fest auf true und update:show wird abgefangen: sonst blendete sich
	     NcModal beim Kreuz selbst aus, bevor die Rückfrage zu ungespeicherten Änderungen käme. -->
	<NcModal
		:show="true"
		labelId="vbh-modal-title-schedule"
		size="large"
		:closeOnClickOutside="false"
		@update:show="$event || requestClose()">
		<div class="vbh-modal-inner vbh-schedule-dialog">
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
					:ref="(el) => setIntervalRef(interval, el)"
					v-bind="intervalProps(interval)"
					@saved="onSaved(interval, $event)"
					@state="states[interval] = $event" />

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
							:ref="(el) => setIntervalRef(interval, el)"
							v-bind="intervalProps(interval)"
							@saved="onSaved(interval, $event)"
							@state="states[interval] = $event" />
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

			<!-- Speichern/Abbrechen bleiben am Fuß des Dialogs stehen, auch wenn die Turnusse
			     länger sind als der Dialog (wie im Buchungsdialog, lib/stickyShadow.js). -->
			<div v-stickyShadow class="vbh-modal-actions vbh-modal-actions--sticky">
				<span v-if="dirtyIntervals.length > 0 && !allValid" class="vbh-hint vbh-schedule-blocked">
					{{ t('Speichern geht erst, wenn kein Turnus mehr einen Fehler oder eine offene Eingabe hat.') }}
				</span>
				<NcButton @click="requestClose">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSaveAll" @click="saveAndClose">
					{{ t('Speichern') }}
				</NcButton>
			</div>

			<!-- Schließen mit ungespeicherten Änderungen: erst fragen, der Dialog bleibt so lange stehen. -->
			<NcDialog
				v-if="askingClose"
				:name="t('Ungespeicherte Änderungen')"
				size="normal"
				:message="t('Im Terminplan sind ungespeicherte Änderungen. Sollen sie gespeichert oder verworfen werden?')"
				:buttons="closeButtons"
				@update:open="askingClose = false" />
		</div>
	</NcModal>
</template>

<script>
import { mdiChevronDown, mdiChevronLeft, mdiChevronRight } from '@mdi/js'
import { showSuccess } from '@nextcloud/dialogs'
import { getLanguage } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { toRefs } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import DueDateIntervalSchedule from './DueDateIntervalSchedule.vue'
import InfoHint from './InfoHint.vue'
import LeadDaysStrip from './LeadDaysStrip.vue'
import { useAuth } from '../composables/useAuth.js'
import { useDueDateSchedule } from '../composables/useDueDateSchedule.js'
import { dueDateLabel } from '../lib/dueDateSchedule.js'
import { intervalLabel } from '../lib/frequency.js'
import { nextMonthStart } from '../lib/sepaSettings.js'
import { stickyShadow } from '../lib/stickyShadow.js'

/**
 * Der Terminplan als Dialog (Spec §2.2/§3.5, Issue #70): je Turnus ein
 * Standard-Einzugstag + abweichende Termine einzelner Perioden. Jeder Turnus
 * ist ein eigener Entwurf mit Live-Vorschau der Termine
 * (DueDateIntervalSchedule.vue); Turnusse ohne Mitglieder treten zurück,
 * solange es genutzte gibt. Die Vorschau beginnt mit dem Beitragsjahr, das
 * der Zeitstrahl gerade zeigt, und blättert im Dialog weiter; die
 * Fristen-Leiste zeigt den nächsten echten Einzug des Zeitstrahls.
 *
 * Gespeichert wird über die Fußleiste, alle geänderten Turnusse zusammen.
 * Wer mit ungespeicherten Änderungen schließt (Abbrechen, Kreuz, Esc), wird
 * gefragt: speichern, verwerfen oder weiter bearbeiten.
 *
 * Rollen (Spec §3.9, Issue #119): Die Terminverschiebung (Standard-Einzugstag,
 * Überschreibungen) ist `buchhalter`. Vorwarnfenster, Vorabinfo-Vorlauf und
 * Freigabe-Vorlauf sind Einstellungen (nur `verwalter`) und stehen in den
 * Nextcloud-Einstellungen (SettingsSepaCycle.vue); hier zeigt sie die
 * Fristen-Leiste an einem echten Termin, mit Verweis für Verwalter.
 */
export default {
	name: 'DueDateScheduleDialog',
	components: { DueDateIntervalSchedule, InfoHint, LeadDaysStrip, NcButton, NcDialog, NcIconSvgWrapper, NcLoadingIcon, NcModal },
	directives: { stickyShadow },

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
			// Turnusmonate => { dirty, valid } aus DueDateIntervalSchedule
			states: {},
			saving: false,
			askingClose: false,
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

		dirtyIntervals() {
			return Object.keys(this.states).map(Number).filter((interval) => this.states[interval].dirty)
		},

		allValid() {
			return this.dirtyIntervals.every((interval) => this.states[interval].valid)
		},

		canSaveAll() {
			return !this.saving && this.dirtyIntervals.length > 0 && this.allValid
		},

		closeButtons() {
			return [
				{ label: this.t('Weiter bearbeiten'), variant: 'tertiary', callback: () => {} },
				{ label: this.t('Verwerfen'), variant: 'error', callback: () => this.$emit('close') },
				{
					label: this.t('Speichern'),
					variant: 'primary',
					disabled: !this.allValid,
					// false lässt die Rückfrage offen, wenn das Speichern scheitert.
					callback: async () => (await this.saveAll()) ? this.$emit('close') : false,
				},
			]
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

	created() {
		// Die Turnus-Komponenten für saveAll(); bewusst nicht reaktiv.
		this.intervalRefs = {}
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

		setIntervalRef(interval, el) {
			if (el) {
				this.intervalRefs[interval] = el
			} else {
				delete this.intervalRefs[interval]
			}
		},

		// Nur der gespeicherte Turnus bekommt einen neuen Stand: die Entwürfe der anderen bleiben stehen.
		onSaved(interval, saved) {
			this.schedule[interval] = saved
		},

		/**
		 * Speichert alle geänderten Turnusse nacheinander; beim ersten Fehler ist
		 * Schluss (der Turnus meldet ihn selbst).
		 *
		 * @return {Promise<boolean>} ob alles gespeichert ist
		 */
		async saveAll() {
			if (!this.allValid) { return false }
			this.saving = true
			try {
				const pending = [...this.dirtyIntervals]
				for (const interval of pending) {
					if (!(await this.intervalRefs[interval].save())) {
						return false
					}
				}
				if (pending.length > 0) {
					showSuccess(this.t('Terminplan gespeichert.'))
					this.$emit('changed')
				}
				return true
			} finally {
				this.saving = false
			}
		},

		async saveAndClose() {
			if (await this.saveAll()) {
				this.$emit('close')
			}
		},

		/** Schließen (Abbrechen, Kreuz, Esc): mit ungespeicherten Änderungen erst fragen. */
		requestClose() {
			if (this.dirtyIntervals.length > 0) {
				this.askingClose = true
			} else {
				this.$emit('close')
			}
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

.vbh-modal-inner.vbh-schedule-dialog {
	padding-bottom: 0;
}

.vbh-schedule-blocked {
	flex: 1 1 auto;
	align-self: center;
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
