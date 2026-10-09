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
				{{ t('Beitrag ändern') }}
			</h2>
			<p class="vbh-hint">
				{{ t('{name} · {group} · Untergrenze {min}', { name: memberName, group: group ? group.name : '', min: euro(effectiveMinCents) }) }}
			</p>

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
				{{ t('Gezahlt werden darf beliebig mehr als die Untergrenze, etwa wenn das Mitglied ausnahmsweise mehr geben möchte.') }}
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
 * „Beitrag ändern“ für die Kassenführung: Monatsbeitrag und Turnus einer bestehenden Zuweisung
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
		/** Ihre Beitragsgruppe: trägt Untergrenze und erlaubte Turnusse. */
		group: { type: Object, default: null },
		memberName: { type: String, default: '' },
	},

	emits: ['close', 'saved', 'update:show'],

	data() {
		return {
			form: { monthlyAmount: '', intervalMonths: 1 },
			preview: null,
			previewedFor: null,
			previewError: '',
			loadingPreview: false,
			saving: false,
			timer: null,
		}
	},

	computed: {
		effectiveMinCents() {
			if (!this.assignment) { return 0 }
			return this.assignment.minMonthlyAmountOverrideCents ?? (this.group ? this.group.minMonthlyAmountCents : 0)
		},

		allowedIntervals() {
			const allowed = this.group ? this.group.allowedIntervals : []
			return allowed.includes(this.form.intervalMonths) ? allowed : [...allowed, this.form.intervalMonths].sort((a, b) => a - b)
		},

		unchanged() {
			return !!this.assignment
				&& Number(this.form.monthlyAmount) === this.assignment.monthlyAmount
				&& this.form.intervalMonths === this.assignment.intervalMonths
		},

		formKey() { return `${this.form.monthlyAmount}|${this.form.intervalMonths}` },

		canSave() {
			return this.form.monthlyAmount !== '' && !this.unchanged && !this.previewError && !!this.preview && this.previewedFor === this.formKey
		},
	},

	watch: {
		show(open) {
			if (!open || !this.assignment) { return }
			this.form = { monthlyAmount: this.assignment.monthlyAmount, intervalMonths: this.assignment.intervalMonths }
			this.preview = null
			this.previewedFor = null
			this.previewError = ''
			focusOnOpen(this, () => this.$refs.amountInput?.$el)
		},

		formKey() { this.schedulePreview() },
	},

	beforeUnmount() { clearTimeout(this.timer) },

	methods: {
		formatDate,
		euro(cents) { return (cents / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }) },

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
