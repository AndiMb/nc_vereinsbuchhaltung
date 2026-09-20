<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-assignment"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-assignment" class="vbh-modal-title">
				{{ t('Neue Zuweisung') }}
			</h2>

			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Mitglied') }}
					<NcSelect
						ref="memberSelect"
						v-model="memberOption"
						:options="memberOptions"
						label="label"
						:placeholder="t('Mitglied wählen …')" />
				</label>
				<NcButton variant="tertiary" @click="quickAddOpen = !quickAddOpen">
					{{ t('+ neues Mitglied') }}
				</NcButton>
			</div>

			<div v-if="quickAddOpen" class="vbh-card">
				<div class="vbh-form">
					<select v-model="quickMember.memberType">
						<option value="person">
							{{ t('Person') }}
						</option>
						<option value="organisation">
							{{ t('Organisation') }}
						</option>
					</select>
					<template v-if="quickMember.memberType === 'person'">
						<input v-model="quickMember.firstName" :placeholder="t('Vorname')">
						<input v-model="quickMember.lastName" :placeholder="t('Nachname')">
					</template>
					<input v-else v-model="quickMember.organizationName" :placeholder="t('Name der Organisation')">
				</div>
				<NcButton variant="secondary" @click="createQuickMember">
					{{ t('Mitglied anlegen') }}
				</NcButton>
			</div>

			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Beitragsgruppe') }}
					<select v-model.number="form.groupId">
						<option :value="null">
							{{ t('– Gruppe wählen –') }}
						</option>
						<option v-for="g in groups" :key="g.id" :value="g.id">
							{{ g.name }}
						</option>
					</select>
				</label>
			</div>

			<div v-if="selectedGroup" class="vbh-form">
				<label>{{ t('Turnus (Monate)') }}
					<select v-model.number="form.intervalMonths">
						<option v-for="n in selectedGroup.allowedIntervals" :key="n" :value="n">
							{{ n }}
						</option>
					</select>
				</label>
				<label>{{ t('Monatsbeitrag (€)') }}
					<AmountInput v-model="form.monthlyAmount" class="vbh-short" />
				</label>
				<label>{{ t('Zahlungsart') }}
					<select v-model="form.paymentMethod">
						<option value="direct_debit">{{ t('Lastschrift') }}</option>
						<option value="ueberweisung">{{ t('Überweisung') }}</option>
					</select>
				</label>
			</div>

			<div class="vbh-form">
				<label>{{ t('Gültig ab') }}
					<input v-model="form.validFrom" type="date">
				</label>
				<label>{{ t('Gültig bis (optional)') }}
					<input v-model="form.validTo" type="date">
				</label>
				<NcButton variant="secondary" :disabled="!canPreview" @click="loadPreview">
					{{ t('Vorschau') }}
				</NcButton>
			</div>

			<p v-if="preview" class="vbh-hint">
				{{ t('Erste Periode: {from} bis {to} ({months}) · Einzugsbetrag {amount}', { from: preview.periodStart, to: preview.periodEnd, months: n('%n Monat', '%n Monate', preview.months), amount: euro(preview.amountCents) }) }}
			</p>

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSave" @click="save">
					{{ t('Anlegen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcModal, NcSelect } from '@nextcloud/vue'
import { toRefs } from 'vue'
import AmountInput from './AmountInput.vue'
import api from '../api.js'
import { useContributionGroups } from '../composables/useContributionGroups.js'
import { useMembers } from '../composables/useMembers.js'
import { errMsg } from '../lib/format.js'
import { focusOnOpen } from '../lib/modalFocus.js'

function emptyForm() {
	return {
		memberId: null,
		groupId: null,
		intervalMonths: 12,
		monthlyAmount: '',
		paymentMethod: 'direct_debit',
		validFrom: new Date().toISOString().slice(0, 10),
		validTo: '',
	}
}

function emptyQuickMember() {
	return { memberType: 'person', firstName: '', lastName: '', organizationName: '' }
}

/**
 * Zuweisung anlegen (Spec §2.2/§3.3, Issue #68). Der Mitglied-Picker greift
 * auf useMembers() zurück – da Ticket #65 noch keine eigene Mitglieder-UI
 * mitbringt (nur Entity+Mapper), gibt es hier eine schmale
 * „+ neues Mitglied"-Inline-Erfassung statt eines eigenen Dialogs.
 */
export default {
	name: 'AssignmentDialog',
	components: { NcModal, NcButton, NcSelect, AmountInput },
	props: {
		show: { type: Boolean, default: false },
	},

	emits: ['close', 'save', 'update:show'],

	setup() {
		const members = useMembers()
		const groups = useContributionGroups()
		return { ...toRefs(members.state), ...toRefs(groups.state) }
	},

	data() {
		return { form: emptyForm(), quickAddOpen: false, quickMember: emptyQuickMember(), preview: null }
	},

	computed: {
		memberOptions() {
			return this.members.map((m) => ({ id: m.id, label: m.displayName || `#${m.id}` }))
		},

		memberOption: {
			get() { return this.memberOptions.find((o) => o.id === this.form.memberId) ?? null },
			set(v) { this.form.memberId = v ? v.id : null },
		},

		selectedGroup() {
			return this.groups.find((g) => g.id === this.form.groupId) ?? null
		},

		canPreview() {
			return this.form.intervalMonths && this.form.monthlyAmount !== '' && this.form.validFrom
		},

		canSave() {
			return this.form.memberId && this.form.groupId && this.form.intervalMonths && this.form.monthlyAmount !== '' && this.form.validFrom
		},
	},

	watch: {
		show(open) {
			if (open) {
				this.form = emptyForm()
				this.quickAddOpen = false
				this.quickMember = emptyQuickMember()
				this.preview = null
				focusOnOpen(this, () => this.$refs.memberSelect?.$el?.querySelector('input'))
			}
		},

		selectedGroup(group) {
			if (!group) { return }
			if (!group.allowedIntervals.includes(this.form.intervalMonths)) {
				this.form.intervalMonths = group.defaultInterval
			}
			if (this.form.monthlyAmount === '') {
				this.form.monthlyAmount = group.defaultMonthlyAmount
			}
		},
	},

	methods: {
		euro(cents) { return (cents / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }) },

		async createQuickMember() {
			try {
				const { data } = await api.createMember(this.quickMember)
				this.members.push(data)
				this.form.memberId = data.id
				this.quickAddOpen = false
			} catch (e) { showError(errMsg(e, 'Mitglied konnte nicht angelegt werden')) }
		},

		async loadPreview() {
			try {
				const { data } = await api.previewNewAssignment({
					intervalMonths: this.form.intervalMonths,
					monthlyAmount: this.form.monthlyAmount,
					validFrom: this.form.validFrom,
				})
				this.preview = data
			} catch (e) {
				this.preview = null
				showError(errMsg(e, 'Vorschau konnte nicht geladen werden'))
			}
		},

		save() {
			this.$emit('save', { ...this.form, validTo: this.form.validTo || null })
		},
	},
}
</script>
