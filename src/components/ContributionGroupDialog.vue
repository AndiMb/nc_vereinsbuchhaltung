<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-cgroup"
		size="normal"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-cgroup" class="vbh-modal-title">
				{{ groupEditId ? t('Beitragsgruppe bearbeiten') : t('Neue Beitragsgruppe') }}
			</h2>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Name') }}
					<input ref="nameInput" v-model="form.name" :placeholder="t('z.B. Basisbeitrag')">
				</label>
			</div>
			<div class="vbh-form">
				<label>{{ t('Untergrenze (€/Monat)') }}
					<AmountInput v-model="form.minMonthlyAmount" class="vbh-short" placeholder="5,00" />
				</label>
				<label>{{ t('Standardbeitrag (€/Monat)') }}
					<AmountInput v-model="form.defaultMonthlyAmount" class="vbh-short" placeholder="8,00" />
				</label>
			</div>
			<p v-if="groupEditId" class="vbh-hint">
				{{ t('Eine Erhöhung der Untergrenze läuft über die eigene Funktion „Untergrenze anheben" in der Gruppenliste – hier lässt sie sich nur absenken.') }}
			</p>
			<div class="vbh-form">
				<fieldset class="vbh-grow">
					<legend>{{ t('Erlaubte Turnusse (Monate)') }}</legend>
					<label v-for="n in intervalOptions" :key="n" class="vbh-inline-checkbox">
						<input v-model="form.allowedIntervals" type="checkbox" :value="n">
						{{ n }}
					</label>
				</fieldset>
			</div>
			<div class="vbh-form">
				<label>{{ t('Standard-Turnus') }}
					<select v-model.number="form.defaultInterval">
						<option v-for="n in form.allowedIntervals" :key="n" :value="n">
							{{ n }}
						</option>
					</select>
				</label>
				<NcCheckboxRadioSwitch v-model="form.isActive" type="switch">
					{{ t('Aktiv') }}
				</NcCheckboxRadioSwitch>
			</div>
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" @click="save">
					{{ groupEditId ? t('Speichern') : t('Anlegen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcModal } from '@nextcloud/vue'
import AmountInput from './AmountInput.vue'
import { focusOnOpen } from '../lib/modalFocus.js'

const INTERVAL_OPTIONS = [1, 2, 3, 4, 6, 12]

function emptyForm() {
	return { name: '', minMonthlyAmount: '', defaultMonthlyAmount: '', allowedIntervals: [1, 12], defaultInterval: 12, isActive: true }
}

/**
 * Anlegen/Bearbeiten einer Beitragsgruppe (Spec §2.2/§3.3, Issue #68). Die
 * Untergrenze lässt sich hier nur absenken - eine Erhöhung braucht die
 * eigene Vorschau (ContributionGroupsPanel::openMinAmountDialog()), weil sie
 * bestehende Zuweisungen mitzieht.
 */
export default {
	name: 'ContributionGroupDialog',
	components: { NcModal, NcButton, NcCheckboxRadioSwitch, AmountInput },
	props: {
		show: { type: Boolean, default: false },
		groupEditId: { type: [Number, String], default: null },
		initialForm: { type: Object, required: true },
	},

	emits: ['close', 'save', 'update:show'],

	data() {
		return { form: emptyForm(), intervalOptions: INTERVAL_OPTIONS }
	},

	watch: {
		show(open) {
			if (open) {
				this.form = { ...emptyForm(), ...this.initialForm }
				focusOnOpen(this, () => this.$refs.nameInput)
			}
		},

		'form.allowedIntervals': function(intervals) {
			if (!intervals.includes(this.form.defaultInterval) && intervals.length > 0) {
				this.form.defaultInterval = intervals[0]
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
