<template>
	<div class="vbh-cgroups-panel">
		<section class="vbh-card">
			<div class="vbh-cardhead">
				<h4>{{ t('Beitragsgruppen') }}</h4>
				<NcButton variant="secondary" @click="openNewGroup">
					{{ t('+ Beitragsgruppe') }}
				</NcButton>
			</div>
			<p v-if="groups.length === 0" class="vbh-hint">
				{{ t('Noch keine Beitragsgruppe angelegt.') }}
			</p>
			<div v-else class="vbh-tablecard">
				<table class="vbh-table vbh-stack">
					<thead>
						<tr>
							<th>{{ t('Name') }}</th>
							<th class="num">
								{{ t('Untergrenze') }}
							</th>
							<th class="num">
								{{ t('Standard') }}
							</th>
							<th>{{ t('Turnusse') }}</th>
							<th>{{ t('Status') }}</th>
							<th class="vbh-col-rowactions-text" />
						</tr>
					</thead>
					<tbody>
						<tr v-for="g in groups" :key="g.id">
							<td class="vbh-stack-title">
								{{ g.name }}
								<small v-if="costCentersEnabled && costCentersById[g.bookingCostCenterId]" class="vbh-cgroup-cc">{{ t('Kostenstelle') }}: {{ costCentersById[g.bookingCostCenterId].code }}</small>
							</td>
							<td class="num">
								{{ amountWithUnit(euro(groupMinCents(g)), isAnnualGroup(g)) }}
							</td>
							<td class="num">
								{{ amountWithUnit(euro(Math.round(groupDefaultAmount(g) * 100)), isAnnualGroup(g)) }}
							</td>
							<td>{{ g.allowedIntervals.map(intervalLabel).join(', ') }}</td>
							<td>
								<span class="vbh-status" :class="g.isActive ? 'vbh-status--success' : 'vbh-status--muted'">{{ g.isActive ? t('aktiv') : t('inaktiv') }}</span>
							</td>
							<td class="nowrap right">
								<!-- Die gewöhnliche Aktion steht da, die seltene und die endgültige im Menü. -->
								<div class="vbh-rowactions">
									<NcButton variant="tertiary" size="small" @click="openEditGroup(g)">
										{{ t('Bearbeiten') }}
									</NcButton>
									<NcActions :forceMenu="true">
										<NcActionButton closeAfterClick @click="openMinAmountIncrease(g)">
											<template #icon>
												<NcIconSvgWrapper :path="mdiArrowUpBold" :size="16" />
											</template>
											{{ t('Untergrenze anheben') }}
										</NcActionButton>
										<NcActionButton closeAfterClick @click="deleteGroup(g)">
											<template #icon>
												<NcIconSvgWrapper :path="mdiDelete" :size="16" />
											</template>
											{{ t('Löschen') }}
										</NcActionButton>
									</NcActions>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>

		<section class="vbh-card">
			<div class="vbh-cardhead">
				<h4>{{ t('Zuweisungen') }}</h4>
				<NcButton variant="secondary" :disabled="groups.length === 0" @click="assignmentDialogOpen = true">
					{{ t('+ Zuweisung') }}
				</NcButton>
			</div>
			<p v-if="groups.length === 0" class="vbh-hint">
				{{ t('Erst eine Beitragsgruppe anlegen, dann lassen sich Mitglieder zuweisen.') }}
			</p>
			<p v-else-if="assignments.length === 0" class="vbh-hint">
				{{ t('Noch keine Zuweisung angelegt.') }}
			</p>
			<div v-else class="vbh-tablecard">
				<table class="vbh-table vbh-stack">
					<thead>
						<tr>
							<th>{{ t('Mitglied') }}</th>
							<th>{{ t('Beitragsgruppe') }}</th>
							<th class="num">
								{{ t('Beitrag') }}
							</th>
							<th>{{ t('Turnus') }}</th>
							<th>{{ t('Gültig ab') }}</th>
							<th>{{ t('Gültig bis') }}</th>
							<th>{{ t('Status') }}</th>
							<th class="vbh-col-rowactions" />
						</tr>
					</thead>
					<tbody>
						<tr v-for="a in assignments" :key="a.id">
							<td>{{ memberName(a.memberId) }}</td>
							<td>{{ groupName(a.groupId) }}</td>
							<td class="num">
								{{ assignmentAmountCents(a) === 0 ? t('beitragsfrei') : amountWithUnit(euro(assignmentAmountCents(a)), isAnnual(a)) }}
							</td>
							<td>{{ intervalLabel(a.intervalMonths) }}</td>
							<td>{{ formatDate(a.validFrom) }}</td>
							<td>{{ a.validTo ? formatDate(a.validTo) : '–' }}</td>
							<td>
								<span class="vbh-status" :class="`vbh-status--${statusTone(a)}`">{{ statusLabel(a) }}</span>
							</td>
							<td class="nowrap right">
								<!-- Laufende und künftige Zuweisungen lassen sich ändern und beenden; an beendeten und zurückgenommenen gibt es nichts mehr zu tun, der Status daneben sagt es. -->
								<div v-if="isLive(a)" class="vbh-rowactions">
									<NcActions :forceMenu="true">
										<NcActionButton closeAfterClick @click="openChange(a, 'fee')">
											<template #icon>
												<NcIconSvgWrapper :path="mdiCashEdit" :size="16" />
											</template>
											{{ t('Beitrag ändern') }}
										</NcActionButton>
										<NcActionButton closeAfterClick @click="openChange(a, 'group')">
											<template #icon>
												<NcIconSvgWrapper :path="mdiAccountSwitch" :size="16" />
											</template>
											{{ t('Beitragsgruppe wechseln') }}
										</NcActionButton>
										<NcActionButton closeAfterClick @click="endAssignment(a)">
											<template #icon>
												<NcIconSvgWrapper :path="mdiCalendarRemove" :size="16" />
											</template>
											{{ a.validFrom > today() ? t('Zuweisung zurücknehmen') : t('Zuweisung beenden') }}
										</NcActionButton>
									</NcActions>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>

		<ContributionGroupDialog
			:show="groupDialogOpen"
			:groupEditId="groupEditId"
			:initialForm="groupForm"
			:basisLocked="groupBasisLocked"
			@close="groupDialogOpen = false"
			@update:show="groupDialogOpen = $event"
			@save="saveGroup" />

		<MinAmountIncreaseDialog
			:show="minAmountDialogOpen"
			:groupId="minAmountGroupId"
			:annual="minAmountGroupAnnual"
			@close="minAmountDialogOpen = false"
			@update:show="minAmountDialogOpen = $event"
			@applied="onMinAmountApplied" />

		<AssignmentDialog
			:show="assignmentDialogOpen"
			@close="assignmentDialogOpen = false"
			@update:show="assignmentDialogOpen = $event"
			@save="saveAssignment" />

		<AssignmentChangeDialog
			:show="changeDialogOpen"
			:assignment="changeAssignment"
			:groups="groups"
			:memberName="changeAssignment ? memberName(changeAssignment.memberId) : ''"
			:mode="changeMode"
			@close="changeDialogOpen = false"
			@update:show="changeDialogOpen = $event"
			@saved="onChanged" />
	</div>
</template>

<script>
import { mdiAccountSwitch, mdiArrowUpBold, mdiCalendarRemove, mdiCashEdit, mdiDelete } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { toRef, toRefs } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import AssignmentChangeDialog from './AssignmentChangeDialog.vue'
import AssignmentDialog from './AssignmentDialog.vue'
import ContributionGroupDialog from './ContributionGroupDialog.vue'
import MinAmountIncreaseDialog from './MinAmountIncreaseDialog.vue'
import api from '../api.js'
import { useAssignments } from '../composables/useAssignments.js'
import { useBookingCostCenters } from '../composables/useBookingCostCenters.js'
import { useConfirm } from '../composables/useConfirm.js'
import { useContributionGroups } from '../composables/useContributionGroups.js'
import { useMembers } from '../composables/useMembers.js'
import { amountWithUnit, assignmentAmountCents, groupDefaultAmount, groupMinCents, isAnnualGroup } from '../lib/amountBasis.js'
import { clubToday } from '../lib/clubTime.js'
import { errMsg, formatCurrency, formatDate } from '../lib/format.js'
import { intervalLabel } from '../lib/frequency.js'
import { assignmentStatusLabel, assignmentStatusTone, isLiveAssignment } from '../lib/memberRow.js'
import { isAnnual } from '../lib/prorata.js'

/**
 * Reiter „Beitragsgruppen" (Issue #68): Gruppen-CRUD und Zuweisungen. Die
 * manuellen Einzelforderungen und der Terminplan stehen im Reiter Einzug
 * (Segment „Forderungen“ beziehungsweise Knopf „Terminplan“ am Zeitstrahl),
 * damit es jede Ansicht nur einmal gibt. Bewusst eigenständig statt in MembersList.vue
 * integriert: die Liste führt Mitglieder, mit Beitrag und Mandat nur zur
 * Ansicht, die Verwaltung der Zuweisungen liegt hier.
 */
export default {
	name: 'ContributionGroupsPanel',
	components: { NcButton, NcActions, NcActionButton, NcIconSvgWrapper, ContributionGroupDialog, MinAmountIncreaseDialog, AssignmentDialog, AssignmentChangeDialog },

	setup() {
		const groups = useContributionGroups()
		const assignments = useAssignments()
		const members = useMembers()
		const { askConfirm } = useConfirm()
		const costCenters = useBookingCostCenters()
		return {
			costCentersEnabled: toRef(costCenters.state, 'enabled'),
			costCentersById: costCenters.bookingCostCentersById,
			...toRefs(groups.state),
			loadContributionGroups: groups.loadContributionGroups,
			...toRefs(assignments.state),
			loadAssignments: assignments.loadAssignments,
			// Ohne den State fehlt memberName() `this.members` – die Zuweisungs-Tabelle
			// warf beim ersten Rendern einen Vue-Fehler und der ganze Reiter blieb leer.
			...toRefs(members.state),
			loadMembers: members.loadMembers,
			askConfirm,
		}
	},

	data() {
		return {
			mdiArrowUpBold,
			mdiAccountSwitch,
			mdiCalendarRemove,
			mdiCashEdit,
			mdiDelete,
			groupDialogOpen: false,
			groupSaving: false,
			assignmentSaving: false,
			groupEditId: null,
			groupForm: {},
			minAmountDialogOpen: false,
			minAmountGroupId: null,
			assignmentDialogOpen: false,
			changeDialogOpen: false,
			changeAssignment: null,
			changeMode: 'fee',
		}
	},

	computed: {
		/** Die bearbeitete Gruppe hat schon Zuweisungen: ihre Betragsangabe (pro Monat/pro Jahr) steht fest (Issue #270). */
		groupBasisLocked() {
			return this.groupEditId !== null && this.assignments.some((a) => a.groupId === this.groupEditId)
		},

		/** Gibt die Gruppe im Dialog „Untergrenze anheben“ ihre Beträge pro Jahr an? */
		minAmountGroupAnnual() {
			return isAnnualGroup(this.groups.find((g) => g.id === this.minAmountGroupId))
		},
	},

	async mounted() {
		await Promise.all([this.loadMembers(), this.loadContributionGroups(), this.loadAssignments()])
	},

	methods: {
		/** Eine angehobene Untergrenze zieht Zuweisungen mit (Betrag je Zuweisung): auch die Tabelle „Zuweisungen“ neu laden. */
		async onMinAmountApplied() {
			await Promise.all([this.loadContributionGroups({ force: true }), this.loadAssignments({ force: true })])
		},

		formatDate,
		intervalLabel,
		isLive: (a) => isLiveAssignment(a),
		statusLabel: (a) => assignmentStatusLabel(a),
		statusTone: (a) => assignmentStatusTone(a),
		today: () => clubToday(),

		openChange(assignment, mode = 'fee') {
			this.changeMode = mode
			this.changeAssignment = assignment
			this.changeDialogOpen = true
		},

		async onChanged() {
			this.changeDialogOpen = false
			await this.loadAssignments({ force: true })
		},

		euro(cents) { return formatCurrency(cents / 100) },

		amountWithUnit,
		assignmentAmountCents,
		groupDefaultAmount,
		groupMinCents,
		isAnnualGroup,
		isAnnual,

		memberName(memberId) {
			const m = this.members.find((x) => x.id === memberId)
			return m ? (m.displayName || `#${memberId}`) : `#${memberId}`
		},

		groupName(groupId) {
			const g = this.groups.find((x) => x.id === groupId)
			return g ? g.name : `#${groupId}`
		},

		openNewGroup() {
			this.groupEditId = null
			this.groupForm = {}
			this.groupDialogOpen = true
		},

		openEditGroup(g) {
			this.groupEditId = g.id
			this.groupForm = {
				name: g.name,
				amountBasis: g.amountBasis ?? 'month',
				prorate: g.prorate ?? true,
				minAmount: groupMinCents(g) / 100,
				defaultAmount: groupDefaultAmount(g),
				allowedIntervals: [...g.allowedIntervals],
				defaultInterval: g.defaultInterval,
				isActive: g.isActive,
				bookingCostCenterId: g.bookingCostCenterId ?? null,
			}
			this.groupDialogOpen = true
		},

		openMinAmountIncrease(g) {
			this.minAmountGroupId = g.id
			this.minAmountDialogOpen = true
		},

		async saveGroup(form) {
			// Ein zweiter Klick vor der Antwort des Servers darf keine zweite Gruppe anlegen.
			if (this.groupSaving) { return }
			this.groupSaving = true
			try {
				if (this.groupEditId) {
					await api.updateContributionGroup(this.groupEditId, form)
				} else {
					await api.createContributionGroup(form)
				}
				this.groupDialogOpen = false
				await this.loadContributionGroups({ force: true })
				showSuccess(this.t('Beitragsgruppe gespeichert.'))
			} catch (e) {
				showError(errMsg(e, this.t('Beitragsgruppe konnte nicht gespeichert werden')))
			} finally {
				this.groupSaving = false
			}
		},

		async deleteGroup(g) {
			const ok = await this.askConfirm(
				this.t('Beitragsgruppe löschen'),
				this.tRaw('„{name}" wirklich löschen? Das geht nur, solange keine Zuweisung mehr daran hängt.', { name: g.name }),
			)
			if (!ok) { return }
			try {
				await api.deleteContributionGroup(g.id)
				await this.loadContributionGroups({ force: true })
			} catch (e) { showError(errMsg(e, this.t('Beitragsgruppe konnte nicht gelöscht werden'))) }
		},

		async saveAssignment(form) {
			if (this.assignmentSaving) { return }
			this.assignmentSaving = true
			try {
				await api.createAssignment(form)
				this.assignmentDialogOpen = false
				await this.loadAssignments({ force: true })
				showSuccess(this.t('Zuweisung angelegt.'))
			} catch (e) {
				showError(errMsg(e, this.t('Zuweisung konnte nicht angelegt werden')))
			} finally {
				this.assignmentSaving = false
			}
		},

		async endAssignment(a) {
			// Eine Zuweisung, die erst künftig beginnt, wird zurückgenommen: sie wird nie wirksam.
			const notStarted = a.validFrom > this.today()
			const ok = notStarted
				? await this.askConfirm(
						this.t('Zuweisung zurücknehmen'),
						this.t('Die Zuweisung hat noch nicht begonnen. Sie wird zurückgenommen und nie wirksam; es entstehen keine Forderungen daraus.'),
						this.t('Zurücknehmen'),
						'primary',
					)
				: await this.askConfirm(
						this.t('Zuweisung beenden'),
						this.t('Die Zuweisung wird zum heutigen Tag beendet. Bereits erzeugte Forderungen bleiben unverändert.'),
						this.t('Beenden'),
						'primary',
					)
			if (!ok) { return }
			try {
				await api.endAssignment(a.id, clubToday())
				await this.loadAssignments({ force: true })
			} catch (e) { showError(errMsg(e, this.t('Zuweisung konnte nicht beendet werden'))) }
		},
	},
}
</script>

<style scoped>
.vbh-cgroup-cc {
	display: block;
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

/*
 * Eigene, gescopte Klasse statt einer globalen Ergänzung in styles.css: eine
 * einfache Kopfzeile (Überschrift + Aktions-Button) je Abschnitt, ohne das
 * geteilte Stylesheet für ein einzelnes neues Panel anzufassen.
 */

/* Die Abschnitte sind keine Kästen um die Tabellenkästen: Überschrift, Tabelle, Luft dazwischen. */
.vbh-cgroups-panel .vbh-card {
	margin: calc(var(--default-grid-baseline, 4px) * 6) 0 0;
	padding: 0;
	border: none;
	background: none;
}

.vbh-cgroups-panel .vbh-card:first-child {
	margin-top: 0;
}

.vbh-cardhead {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 8px;
}

.vbh-cardhead h4 {
	margin: 0;
}
</style>
