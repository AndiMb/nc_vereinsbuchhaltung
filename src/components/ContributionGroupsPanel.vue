<template>
	<div class="vbh-cgroups-panel">
		<section class="vbh-card">
			<div class="vbh-cardhead">
				<h4>{{ t('Beitragsgruppen') }}</h4>
				<NcButton variant="primary" @click="openNewGroup">
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
							<th>{{ t('Aktiv') }}</th>
							<th class="vbh-col-memberactions" />
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
							<td>{{ g.allowedIntervals.join(', ') }}</td>
							<td>{{ g.isActive ? t('ja') : t('nein') }}</td>
							<td class="nowrap right">
								<div class="vbh-actions">
									<NcButton size="small" @click="openEditGroup(g)">
										{{ t('Bearbeiten') }}
									</NcButton>
									<NcButton size="small" @click="openMinAmountIncrease(g)">
										{{ t('Untergrenze anheben') }}
									</NcButton>
									<NcButton size="small" variant="tertiary" @click="deleteGroup(g)">
										{{ t('Löschen') }}
									</NcButton>
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
				<NcButton variant="primary" :disabled="groups.length === 0" @click="assignmentDialogOpen = true">
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
							<th class="vbh-col-memberactions" />
						</tr>
					</thead>
					<tbody>
						<tr v-for="a in assignments" :key="a.id">
							<td>{{ memberName(a.memberId) }}</td>
							<td>{{ groupName(a.groupId) }}</td>
							<td class="num">
								{{ euro(a.monthlyAmountCents) }}
							</td>
							<td>{{ a.intervalMonths }}</td>
							<td>{{ a.validFrom }}</td>
							<td>{{ a.validTo || '–' }}</td>
							<td class="nowrap right">
								<NcButton
									v-if="a.active"
									size="small"
									variant="tertiary"
									@click="endAssignment(a)">
									{{ t('Beenden') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>

		<section class="vbh-card">
			<div class="vbh-cardhead">
				<h4>{{ t('Einzelforderungen') }}</h4>
				<NcButton variant="primary" @click="claimDialogOpen = true">
					{{ t('+ Einzelforderung') }}
				</NcButton>
			</div>
			<p v-if="claims.length === 0" class="vbh-hint">
				{{ t('Noch keine manuelle Einzelforderung angelegt.') }}
			</p>
			<div v-else class="vbh-tablecard">
				<table class="vbh-table">
					<thead>
						<tr>
							<th>{{ t('Mitglied') }}</th>
							<th>{{ t('Bezeichnung') }}</th>
							<th class="num">
								{{ t('Betrag') }}
							</th>
							<th>{{ t('Termin') }}</th>
							<th>{{ t('Zustand') }}</th>
							<th class="vbh-col-memberactions" />
						</tr>
					</thead>
					<tbody>
						<tr v-for="c in claims" :key="c.id">
							<td>{{ c.memberDisplayName }}</td>
							<td>{{ c.description }}</td>
							<td class="num">
								{{ euro(c.amountCents) }}
							</td>
							<td>{{ c.dueDate }}</td>
							<td>{{ c.state }}</td>
							<td class="nowrap right">
								<NcButton v-if="c.state === 'offen'" size="small" @click="settlePaid(c)">
									{{ t('Als bezahlt markieren') }}
								</NcButton>
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
			@close="assignmentDialogOpen = false"
			@update:show="assignmentDialogOpen = $event"
			@save="saveAssignment" />

		<ManualClaimDialog
			:show="claimDialogOpen"
			@close="claimDialogOpen = false"
			@update:show="claimDialogOpen = $event"
			@save="saveClaim" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import { toRefs } from 'vue'
import AssignmentDialog from './AssignmentDialog.vue'
import ContributionGroupDialog from './ContributionGroupDialog.vue'
import ManualClaimDialog from './ManualClaimDialog.vue'
import MinAmountIncreaseDialog from './MinAmountIncreaseDialog.vue'
import api from '../api.js'
import { useAssignments } from '../composables/useAssignments.js'
import { useClaims } from '../composables/useClaims.js'
import { useConfirm } from '../composables/useConfirm.js'
import { useContributionGroups } from '../composables/useContributionGroups.js'
import { useMembers } from '../composables/useMembers.js'
import { errMsg } from '../lib/format.js'

/**
 * Reiter „Beitragsgruppen" (Issue #68): Gruppen-CRUD, Zuweisungen, manuelle
 * Einzelforderungen. Bewusst eigenständig statt in MembersList.vue/
 * SepaBatchPanel.vue integriert – die arbeiten noch auf dem alten
 * memberUid/memberLabel-Modell (Ticket #65 baut sie erst noch auf die neue
 * Member-Entity um, siehe PR-Beschreibung).
 */
export default {
	name: 'ContributionGroupsPanel',
	components: { NcButton, ContributionGroupDialog, MinAmountIncreaseDialog, AssignmentDialog, ManualClaimDialog },

	setup() {
		const groups = useContributionGroups()
		const assignments = useAssignments()
		const claims = useClaims()
		const members = useMembers()
		const { askConfirm } = useConfirm()
		return {
			...toRefs(groups.state),
			loadContributionGroups: groups.loadContributionGroups,
			...toRefs(assignments.state),
			loadAssignments: assignments.loadAssignments,
			...toRefs(claims.state),
			loadClaims: claims.loadClaims,
			loadMembers: members.loadMembers,
			askConfirm,
		}
	},

	data() {
		return {
			groupDialogOpen: false,
			groupEditId: null,
			groupForm: {},
			minAmountDialogOpen: false,
			minAmountGroupId: null,
			assignmentDialogOpen: false,
			claimDialogOpen: false,
		}
	},

	async mounted() {
		await Promise.all([this.loadMembers(), this.loadContributionGroups(), this.loadAssignments(), this.loadClaims()])
	},

	methods: {
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
			} catch (e) { showError(errMsg(e, 'Beitragsgruppe konnte nicht gespeichert werden')) }
		},

		async deleteGroup(g) {
			const ok = await this.askConfirm(
				this.t('Beitragsgruppe löschen'),
				this.t('„{name}" wirklich löschen? Das geht nur, solange keine Zuweisung mehr daran hängt.', { name: g.name }),
			)
			if (!ok) { return }
			try {
				await api.deleteContributionGroup(g.id)
				await this.loadContributionGroups()
			} catch (e) { showError(errMsg(e, 'Beitragsgruppe konnte nicht gelöscht werden')) }
		},

		async saveAssignment(form) {
			try {
				await api.createAssignment(form)
				this.assignmentDialogOpen = false
				await this.loadAssignments()
				showSuccess(this.t('Zuweisung angelegt.'))
			} catch (e) { showError(errMsg(e, 'Zuweisung konnte nicht angelegt werden')) }
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
			} catch (e) { showError(errMsg(e, 'Zuweisung konnte nicht beendet werden')) }
		},

		async saveClaim(form) {
			try {
				await api.createClaim(form)
				this.claimDialogOpen = false
				await this.loadClaims()
				showSuccess(this.t('Einzelforderung angelegt.'))
			} catch (e) { showError(errMsg(e, 'Einzelforderung konnte nicht angelegt werden')) }
		},

		async settlePaid(c) {
			try {
				await api.settleClaim(c.id, 'paid')
				await this.loadClaims()
			} catch (e) { showError(errMsg(e, 'Forderung konnte nicht als bezahlt markiert werden')) }
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
