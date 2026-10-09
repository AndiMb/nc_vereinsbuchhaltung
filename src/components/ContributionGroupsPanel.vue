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
				<table class="vbh-table">
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
							<td>{{ g.name }}</td>
							<td class="num">
								{{ euro(g.minMonthlyAmountCents) }}
							</td>
							<td class="num">
								{{ euro(g.defaultMonthlyAmountCents) }}
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
				<table class="vbh-table">
					<thead>
						<tr>
							<th>{{ t('Mitglied') }}</th>
							<th>{{ t('Beitragsgruppe') }}</th>
							<th class="num">
								{{ t('Monatsbeitrag') }}
							</th>
							<th>{{ t('Turnus') }}</th>
							<th>{{ t('Gültig ab') }}</th>
							<th>{{ t('Gültig bis') }}</th>
							<th class="vbh-col-rowactions" />
						</tr>
					</thead>
					<tbody>
						<tr v-for="a in assignments" :key="a.id">
							<td>{{ memberName(a.memberId) }}</td>
							<td>{{ groupName(a.groupId) }}</td>
							<td class="num">
								{{ a.monthlyAmountCents === 0 ? t('beitragsfrei') : euro(a.monthlyAmountCents) }}
							</td>
							<td>{{ intervalLabel(a.intervalMonths) }}</td>
							<td>{{ formatDate(a.validFrom) }}</td>
							<td>{{ a.validTo ? formatDate(a.validTo) : '–' }}</td>
							<td class="nowrap right">
								<div v-if="a.active" class="vbh-rowactions">
									<NcActions :forceMenu="true">
										<NcActionButton closeAfterClick @click="openChange(a)">
											<template #icon>
												<NcIconSvgWrapper :path="mdiCashEdit" :size="16" />
											</template>
											{{ t('Beitrag ändern') }}
										</NcActionButton>
										<NcActionButton closeAfterClick @click="endAssignment(a)">
											<template #icon>
												<NcIconSvgWrapper :path="mdiCalendarRemove" :size="16" />
											</template>
											{{ t('Zuweisung beenden') }}
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
			@close="groupDialogOpen = false"
			@update:show="groupDialogOpen = $event"
			@save="saveGroup" />

		<MinAmountIncreaseDialog
			:show="minAmountDialogOpen"
			:groupId="minAmountGroupId"
			@close="minAmountDialogOpen = false"
			@update:show="minAmountDialogOpen = $event"
			@applied="loadContributionGroups" />

		<AssignmentDialog
			:show="assignmentDialogOpen"
			:presetMemberId="presetMemberId"
			@close="assignmentDialogOpen = false"
			@update:show="assignmentDialogOpen = $event"
			@save="saveAssignment" />

		<AssignmentChangeDialog
			:show="changeDialogOpen"
			:assignment="changeAssignment"
			:group="changeAssignment ? groups.find((g) => g.id === changeAssignment.groupId) : null"
			:memberName="changeAssignment ? memberName(changeAssignment.memberId) : ''"
			@close="changeDialogOpen = false"
			@update:show="changeDialogOpen = $event"
			@saved="onChanged" />
	</div>
</template>

<script>
import { mdiArrowUpBold, mdiCalendarRemove, mdiCashEdit, mdiDelete } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcActionButton, NcActions, NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import { toRefs } from 'vue'
import AssignmentChangeDialog from './AssignmentChangeDialog.vue'
import AssignmentDialog from './AssignmentDialog.vue'
import ContributionGroupDialog from './ContributionGroupDialog.vue'
import MinAmountIncreaseDialog from './MinAmountIncreaseDialog.vue'
import api from '../api.js'
import { useAssignments } from '../composables/useAssignments.js'
import { useConfirm } from '../composables/useConfirm.js'
import { useContributionGroups } from '../composables/useContributionGroups.js'
import { useMembers } from '../composables/useMembers.js'
import { errMsg, formatDate } from '../lib/format.js'
import { intervalLabel } from '../lib/frequency.js'

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

	props: {
		/**
		 * Mitglied, dessen Beitrag gezielt geöffnet werden soll (Menü „Beitrag verwalten“ der Mitgliederliste):
		 * hat es eine laufende Zuweisung, öffnet „Beitrag ändern“, sonst „Zuweisung anlegen“ mit dem Mitglied vorbelegt.
		 */
		focusMemberId: { type: Number, default: null },
	},

	emits: ['focus-handled'],

	setup() {
		const groups = useContributionGroups()
		const assignments = useAssignments()
		const members = useMembers()
		const { askConfirm } = useConfirm()
		return {
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
			mdiCalendarRemove,
			mdiCashEdit,
			mdiDelete,
			groupDialogOpen: false,
			groupEditId: null,
			groupForm: {},
			minAmountDialogOpen: false,
			minAmountGroupId: null,
			assignmentDialogOpen: false,
			presetMemberId: null,
			changeDialogOpen: false,
			changeAssignment: null,
		}
	},

	watch: {
		focusMemberId: {
			immediate: true,
			handler(id) { if (id !== null) { this.openForMember(id) } },
		},
	},

	async mounted() {
		await Promise.all([this.loadMembers(), this.loadContributionGroups(), this.loadAssignments()])
	},

	methods: {
		formatDate,
		intervalLabel,

		openChange(assignment) {
			this.changeAssignment = assignment
			this.changeDialogOpen = true
		},

		async onChanged() {
			this.changeDialogOpen = false
			await this.loadAssignments()
		},

		/** Aus der Mitgliederliste: der Beitrag dieses Mitglieds – ändern, wenn er läuft, sonst anlegen. */
		async openForMember(memberId) {
			await this.loadAssignments()
			const running = this.assignments.find((a) => a.memberId === memberId && a.active)
			if (running) {
				this.openChange(running)
			} else {
				this.presetMemberId = memberId
				this.assignmentDialogOpen = true
			}
			this.$emit('focus-handled')
		},

		euro(cents) { return (cents / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }) },

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
				minMonthlyAmount: g.minMonthlyAmountCents / 100,
				defaultMonthlyAmount: g.defaultMonthlyAmountCents / 100,
				allowedIntervals: [...g.allowedIntervals],
				defaultInterval: g.defaultInterval,
				isActive: g.isActive,
			}
			this.groupDialogOpen = true
		},

		openMinAmountIncrease(g) {
			this.minAmountGroupId = g.id
			this.minAmountDialogOpen = true
		},

		async saveGroup(form) {
			try {
				if (this.groupEditId) {
					await api.updateContributionGroup(this.groupEditId, form)
				} else {
					await api.createContributionGroup(form)
				}
				this.groupDialogOpen = false
				await this.loadContributionGroups()
				showSuccess(this.t('Beitragsgruppe gespeichert.'))
			} catch (e) { showError(errMsg(e, this.t('Beitragsgruppe konnte nicht gespeichert werden'))) }
		},

		async deleteGroup(g) {
			const ok = await this.askConfirm(
				this.t('Beitragsgruppe löschen'),
				this.tRaw('„{name}" wirklich löschen? Das geht nur, solange keine Zuweisung mehr daran hängt.', { name: g.name }),
			)
			if (!ok) { return }
			try {
				await api.deleteContributionGroup(g.id)
				await this.loadContributionGroups()
			} catch (e) { showError(errMsg(e, this.t('Beitragsgruppe konnte nicht gelöscht werden'))) }
		},

		async saveAssignment(form) {
			try {
				await api.createAssignment(form)
				this.assignmentDialogOpen = false
				await this.loadAssignments()
				showSuccess(this.t('Zuweisung angelegt.'))
			} catch (e) { showError(errMsg(e, this.t('Zuweisung konnte nicht angelegt werden'))) }
		},

		async endAssignment(a) {
			const ok = await this.askConfirm(
				this.t('Zuweisung beenden'),
				this.t('Die Zuweisung wird zum heutigen Tag beendet. Bereits erzeugte Forderungen bleiben unverändert.'),
				this.t('Beenden'),
				'primary',
			)
			if (!ok) { return }
			try {
				await api.endAssignment(a.id, new Date().toISOString().slice(0, 10))
				await this.loadAssignments()
			} catch (e) { showError(errMsg(e, this.t('Zuweisung konnte nicht beendet werden'))) }
		},
	},
}
</script>

<style scoped>
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
