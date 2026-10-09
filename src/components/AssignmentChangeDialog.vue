<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-assignment-change"
		size="normal"
		:closeOnClickOutside="!saving"
		:noClose="saving"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div v-if="assignment" class="vbh-modal-inner">
			<h2 id="vbh-modal-title-assignment-change" class="vbh-modal-title">
				{{ isGroupMode ? t('Beitragsgruppe wechseln') : t('Beitrag ändern') }}
			</h2>
			<p class="vbh-hint">
				{{ isGroupMode
					? tRaw('{name} · bisher {group}', { name: memberName, group: currentGroupName })
					: tRaw('{name} · {group} · Untergrenze {min}', { name: memberName, group: currentGroupName, min: euro(effectiveMinCents) }) }}
			</p>

			<div v-if="isGroupMode" class="vbh-form">
				<label class="vbh-grow">{{ t('Neue Beitragsgruppe') }}
					<select ref="groupSelect" v-model.number="form.groupId" @change="onGroupChange">
						<option v-for="g in selectableGroups" :key="g.id" :value="g.id">
							{{ g.name }}
						</option>
					</select>
				</label>
			</div>
			<div class="vbh-form">
				<label>{{ t('Monatsbeitrag (€)') }}
					<AmountInput ref="amountInput" v-model="form.monthlyAmount" class="vbh-short" />
				</label>
				<label>{{ t('Turnus (Monate)') }}
					<select v-model.number="form.intervalMonths">
						<option v-for="m in allowedIntervals" :key="m" :value="m">
							{{ m }}
						</option>
					</select>
				</label>
			</div>
			<p class="vbh-hint">
				{{ isGroupMode
					? tRaw('Untergrenze der gewählten Gruppe: {min}. Beim Wechsel ist deren Standardbeitrag eingetragen; Sie können ihn anpassen.', { min: euro(effectiveMinCents) })
					: t('Gezahlt werden darf beliebig mehr als die Untergrenze, etwa wenn das Mitglied ausnahmsweise mehr geben möchte.') }}
			</p>

			<NcNoteCard v-if="previewError" type="error">
				{{ previewError }}
			</NcNoteCard>
			<NcNoteCard v-else-if="preview && preview.amountCents === 0" type="info">
				{{ t('Wirkt ab {from} · beitragsfrei: es wird nichts eingezogen.', { from: formatDate(preview.effectiveFrom) }) }}
			</NcNoteCard>
			<NcNoteCard v-else-if="preview" type="info">
				{{ t('Wirkt ab {from} · erster Einzug am {due} · Betrag {amount}', { from: formatDate(preview.effectiveFrom), due: formatDate(preview.firstDueDate), amount: euro(preview.amountCents) }) }}
			</NcNoteCard>
			<NcLoadingIcon v-else-if="loadingPreview" :size="20" />

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" :disabled="saving" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSave || saving" @click="save">
					{{ t('Speichern') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard } from '@nextcloud/vue'
import AmountInput from './AmountInput.vue'
import api from '../api.js'
import { errMsg, formatDate } from '../lib/format.js'
import { focusOnOpen } from '../lib/modalFocus.js'

/**
 * „Beitrag ändern“ und „Beitragsgruppe wechseln“ für die Kassenführung: Monatsbeitrag und Turnus einer bestehenden Zuweisung beziehungsweise ihre Beitragsgruppe
 * (bisher konnte nur das Mitglied selbst unter „Mein Beitrag“ ändern, die Verwaltung nur beenden und
 * neu anlegen). Der Server prüft Untergrenze und erlaubte Turnusse und nennt in der Vorschau, ab wann
 * die Änderung gilt – bei einem schon angekündigten Einzug erst danach (Sperrfenster). Die Vorschau
 * erscheint von selbst, sobald sich ein Wert ändert; gespeichert wird erst, wenn sie zum Stand der
 * Felder passt.
 */
export default {
	name: 'AssignmentChangeDialog',
	components: { NcModal, NcButton, NcNoteCard, NcLoadingIcon, AmountInput },

	props: {
		show: { type: Boolean, default: false },
		/** Die Zuweisung (Assignment-JSON) oder null. */
		assignment: { type: Object, default: null },
		/** Alle Beitragsgruppen (Untergrenze, erlaubte Turnusse, Standardbeitrag je Gruppe). */
		groups: { type: Array, default: () => [] },
		memberName: { type: String, default: '' },
		/** 'fee' = Betrag und Turnus ändern, 'group' = in eine andere Beitragsgruppe wechseln (mit deren Regeln). */
		mode: { type: String, default: 'fee' },
	},

	emits: ['close', 'saved', 'update:show'],

	data() {
		return {
			form: { monthlyAmount: '', intervalMonths: 1, groupId: null },
			preview: null,
			previewedFor: null,
			previewError: '',
			loadingPreview: false,
			saving: false,
			timer: null,
		}
	},

	computed: {
		isGroupMode() { return this.mode === 'group' },

		currentGroupName() {
			const group = this.assignment ? this.groups.find((g) => g.id === this.assignment.groupId) : null
			return group ? group.name : ''
		},

		/** Die gewählte Gruppe: sie bestimmt Untergrenze und erlaubte Turnusse. */
		selectedGroup() {
			return this.groups.find((g) => g.id === this.form.groupId) ?? null
		},

		/** Wählbar sind die aktiven Gruppen und – damit die Auswahl nie leer aussieht – die bisherige. */
		selectableGroups() {
			return this.groups.filter((g) => g.isActive || (this.assignment && g.id === this.assignment.groupId))
		},

		groupChanged() {
			return !!this.assignment && this.form.groupId !== this.assignment.groupId
		},

		effectiveMinCents() {
			if (!this.assignment) { return 0 }
			return this.assignment.minMonthlyAmountOverrideCents ?? (this.selectedGroup ? this.selectedGroup.minMonthlyAmountCents : 0)
		},

		allowedIntervals() {
			const allowed = this.selectedGroup ? this.selectedGroup.allowedIntervals : []
			return allowed.includes(this.form.intervalMonths) ? allowed : [...allowed, this.form.intervalMonths].sort((a, b) => a - b)
		},

		unchanged() {
			return !!this.assignment
				&& Number(this.form.monthlyAmount) === this.assignment.monthlyAmount
				&& this.form.intervalMonths === this.assignment.intervalMonths
				&& !this.groupChanged
		},

		formKey() { return `${this.form.groupId}|${this.form.monthlyAmount}|${this.form.intervalMonths}` },

		canSave() {
			return this.form.monthlyAmount !== '' && !this.unchanged && !this.previewError && !!this.preview && this.previewedFor === this.formKey
		},
	},

	watch: {
		show(open) {
			if (!open || !this.assignment) { return }
			this.form = { monthlyAmount: this.assignment.monthlyAmount, intervalMonths: this.assignment.intervalMonths, groupId: this.assignment.groupId }
			this.preview = null
			this.previewedFor = null
			this.previewError = ''
			focusOnOpen(this, () => (this.isGroupMode ? this.$refs.groupSelect : this.$refs.amountInput?.$el))
		},

		formKey() { this.schedulePreview() },
	},

	beforeUnmount() { clearTimeout(this.timer) },

	methods: {
		formatDate,
		euro(cents) { return (cents / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }) },

		/**
		 * Wechselt die Gruppe, gelten ihre Regeln: ein Turnus, den sie nicht kennt, wird durch ihren Standard-Turnus
		 * ersetzt, und ihr Standardbeitrag wird eingetragen (änderbar) – so entsteht nie versehentlich ein Betrag
		 * unter der neuen Untergrenze.
		 */
		onGroupChange() {
			const group = this.selectedGroup
			if (!group) { return }
			if (!group.allowedIntervals.includes(this.form.intervalMonths)) { this.form.intervalMonths = group.defaultInterval }
			this.form.monthlyAmount = group.defaultMonthlyAmount
		},

		/** Die Vorschau folgt den Feldern mit kurzer Verzögerung, damit nicht jede Ziffer eine Anfrage auslöst. */
		schedulePreview() {
			clearTimeout(this.timer)
			this.preview = null
			this.previewError = ''
			if (!this.show || !this.assignment || this.form.monthlyAmount === '' || this.unchanged) { return }
			this.timer = setTimeout(() => this.loadPreview(), 350)
		},

		async loadPreview() {
			const key = this.formKey
			this.loadingPreview = true
			try {
				const { data } = await api.previewAssignmentChange(this.assignment.id, {
					monthlyAmount: this.form.monthlyAmount,
					intervalMonths: this.form.intervalMonths,
					groupId: this.form.groupId,
				})
				if (key !== this.formKey) { return }
				this.preview = data
				this.previewedFor = key
			} catch (e) {
				if (key !== this.formKey) { return }
				this.previewError = errMsg(e, this.t('Vorschau konnte nicht geladen werden'))
			} finally {
				this.loadingPreview = false
			}
		},

		async save() {
			if (!this.canSave) { return }
			this.saving = true
			try {
				await api.updateAssignment(this.assignment.id, {
					monthlyAmount: this.form.monthlyAmount,
					intervalMonths: this.form.intervalMonths,
					groupId: this.form.groupId,
				})
				showSuccess(this.t('Beitrag geändert.'))
				this.$emit('saved')
			} catch (e) {
				showError(errMsg(e, this.t('Beitrag konnte nicht geändert werden')))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>
