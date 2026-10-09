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
				<label class="vbh-grow">{{ t('Mitglieder') }}
					<NcSelect
						ref="memberSelect"
						v-model="selectedMembers"
						:options="memberOptions"
						:multiple="true"
						:keepOpen="true"
						label="label"
						:placeholder="t('Mitglieder wählen …')" />
				</label>
				<p class="vbh-hint">
					{{ t('Für jedes gewählte Mitglied entsteht eine eigene Forderung mit denselben Angaben.') }}
				</p>
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
				<NcButton variant="primary" :disabled="!canSave || saving" @click="save">
					{{ form.memberIds.length > 1 ? n('Für %n Mitglied anlegen', 'Für %n Mitglieder anlegen', form.memberIds.length) : t('Anlegen') }}
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
	return { memberIds: [], type: 'beitrag', amount: '', label: '', dueDate: new Date().toISOString().slice(0, 10) }
}

/** Manuelle Einzelforderung (Spec §3.3 „schmale Tür", Issue #68). */
export default {
	name: 'ManualClaimDialog',
	components: { NcModal, NcButton, NcSelect, AmountInput },
	props: {
		show: { type: Boolean, default: false },
		/** Solange der Server speichert, ist „Anlegen" gesperrt – ein Doppelklick legt sonst zwei Forderungen an. */
		saving: { type: Boolean, default: false },
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

		selectedMembers: {
			get() { return this.form.memberIds.map((id) => this.memberOptions.find((o) => o.id === id)).filter(Boolean) },
			set(v) { this.form.memberIds = (v ?? []).map((o) => o.id) },
		},

		canSave() {
			return this.form.memberIds.length > 0 && this.form.amount !== '' && this.form.label.trim() !== '' && this.form.dueDate
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
			this.$emit('save', { ...this.form, memberIds: [...this.form.memberIds], members: this.selectedMembers })
		},

		/** Nach einem Teilerfolg bleiben nur die Mitglieder gewählt, für die noch keine Forderung entstanden ist. */
		keepMembers(ids) {
			this.form.memberIds = this.form.memberIds.filter((id) => ids.includes(id))
		},
	},
}
</script>
