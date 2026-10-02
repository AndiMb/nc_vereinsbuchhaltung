<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-claim"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-claim" class="vbh-modal-title">
				{{ t('Manuelle Einzelforderung') }}
			</h2>
			<p class="vbh-hint">
				{{ t('Freier Betrag mit eigenem Einzugstermin – auch ohne aktives Mandat anlegbar, z.B. für eine Nachforderung oder Sondergebühr.') }}
			</p>

			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Mitglied') }}
					<NcSelect
						ref="memberSelect"
						v-model="memberOption"
						:options="memberOptions"
						label="label"
						:placeholder="t('Mitglied wählen …')" />
				</label>
			</div>

			<div class="vbh-form">
				<label>{{ t('Typ') }}
					<select v-model="form.type">
						<option value="beitrag">{{ t('Beitrag') }}</option>
						<option value="gebuehr">{{ t('Gebühr') }}</option>
					</select>
				</label>
				<label>{{ t('Betrag (€)') }}
					<AmountInput v-model="form.amount" class="vbh-short" />
				</label>
			</div>

			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Bezeichnung') }}
					<input v-model="form.label" :placeholder="t('z.B. Nachzahlung Sommerfest')">
				</label>
			</div>

			<div class="vbh-form">
				<label>{{ t('Einzugstermin') }}
					<input v-model="form.dueDate" type="date">
				</label>
			</div>

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
import { NcButton, NcModal, NcSelect } from '@nextcloud/vue'
import { toRefs } from 'vue'
import AmountInput from './AmountInput.vue'
import { useMembers } from '../composables/useMembers.js'
import { focusOnOpen } from '../lib/modalFocus.js'

function emptyForm() {
	return { memberId: null, type: 'beitrag', amount: '', label: '', dueDate: new Date().toISOString().slice(0, 10) }
}

/** Manuelle Einzelforderung (Spec §3.3 „schmale Tür", Issue #68). */
export default {
	name: 'ManualClaimDialog',
	components: { NcModal, NcButton, NcSelect, AmountInput },
	props: {
		show: { type: Boolean, default: false },
	},

	emits: ['close', 'save', 'update:show'],

	setup() {
		const members = useMembers()
		return { ...toRefs(members.state) }
	},

	data() {
		return { form: emptyForm() }
	},

	computed: {
		memberOptions() {
			return this.members.map((m) => ({ id: m.id, label: m.displayName || `#${m.id}` }))
		},

		memberOption: {
			get() { return this.memberOptions.find((o) => o.id === this.form.memberId) ?? null },
			set(v) { this.form.memberId = v ? v.id : null },
		},

		canSave() {
			return this.form.memberId && this.form.amount !== '' && this.form.label.trim() !== '' && this.form.dueDate
		},
	},

	watch: {
		show(open) {
			if (open) {
				this.form = emptyForm()
				focusOnOpen(this, () => this.$refs.memberSelect?.$el?.querySelector('input'))
			}
		},
	},

	methods: {
		save() {
			this.$emit('save', { ...this.form })
		},
	},
}
</script>
