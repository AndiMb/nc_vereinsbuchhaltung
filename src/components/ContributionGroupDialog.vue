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
			<!-- Betragsangabe (Issue #270): pro Monat (Monatsbeitrag × Turnus) oder pro Jahr (Jahresbeitrag exakt) -->
			<div class="vbh-form">
				<label>{{ t('Beträge gelten') }}
					<select v-model="form.amountBasis" :disabled="basisLocked">
						<option value="month">
							{{ t('pro Monat') }}
						</option>
						<option value="year">
							{{ t('pro Jahr') }}
						</option>
					</select>
				</label>
				<label>{{ annual ? t('Untergrenze ({currency}/Jahr)', { currency: currencySymbol() }) : t('Untergrenze ({currency}/Monat)', { currency: currencySymbol() }) }}
					<AmountInput v-model="form.minAmount" class="vbh-short" :placeholder="annual ? '25,00' : '5,00'" />
				</label>
				<label>{{ annual ? t('Standardbeitrag ({currency}/Jahr)', { currency: currencySymbol() }) : t('Standardbeitrag ({currency}/Monat)', { currency: currencySymbol() }) }}
					<AmountInput v-model="form.defaultAmount" class="vbh-short" :placeholder="annual ? '50,00' : '8,00'" />
				</label>
			</div>
			<p v-if="basisLocked" class="vbh-hint">
				{{ t('Die Betragsangabe lässt sich nicht mehr ändern, weil die Gruppe schon Zuweisungen hat.') }}
			</p>
			<template v-if="annual">
				<p class="vbh-hint">
					{{ t('Ein volles Beitragsjahr kostet genau den Jahresbeitrag. Der Einzug ist dann nur jährlich möglich.') }}
				</p>
				<NcCheckboxRadioSwitch v-model="form.prorate" type="switch">
					{{ t('Angebrochenes Beitragsjahr anteilig berechnen') }}
				</NcCheckboxRadioSwitch>
				<p class="vbh-hint">
					{{ form.prorate
						? t('Bei Ein- oder Austritt im laufenden Jahr: Jahresbeitrag × Monate ÷ 12, auf den Cent gerundet.')
						: t('Bei Ein- oder Austritt im laufenden Jahr wird der volle Jahresbeitrag fällig.') }}
				</p>
			</template>
			<p v-if="groupEditId" class="vbh-hint">
				{{ t('Eine Erhöhung der Untergrenze läuft über die eigene Funktion „Untergrenze anheben" in der Gruppenliste – hier lässt sie sich nur absenken.') }}
			</p>
			<div v-if="!annual" class="vbh-form">
				<fieldset class="vbh-grow vbh-fieldset-reset">
					<legend class="vbh-legend">
						{{ t('Erlaubte Turnusse') }}
					</legend>
					<div class="vbh-checkrow">
						<NcCheckboxRadioSwitch
							v-for="n in intervalOptions"
							:key="n"
							:modelValue="form.allowedIntervals.includes(n)"
							@update:modelValue="setIntervalAllowed(n, $event)">
							{{ intervalLabel(n) }}
						</NcCheckboxRadioSwitch>
					</div>
				</fieldset>
			</div>
			<div class="vbh-form">
				<label v-if="!annual">{{ t('Standard-Turnus') }}
					<select v-model.number="form.defaultInterval">
						<option v-for="n in form.allowedIntervals" :key="n" :value="n">
							{{ intervalLabel(n) }}
						</option>
					</select>
				</label>
				<NcCheckboxRadioSwitch v-model="form.isActive" type="switch">
					{{ tc('Zustand', 'Aktiv') }}
				</NcCheckboxRadioSwitch>
			</div>
			<!-- Vorgabe-Kostenstelle (Issue #333): nur bei eingeschaltetem Modul -->
			<div v-if="costCentersEnabled" class="vbh-form">
				<label class="vbh-grow">{{ t('Kostenstelle') }}
					<select v-model="form.bookingCostCenterId">
						<option :value="null">
							{{ t('– keine –') }}
						</option>
						<option v-for="cc in costCenterOptions" :key="cc.id" :value="cc.id">
							{{ cc.code }} · {{ cc.name }}{{ cc.active ? '' : ' (' + t('inaktiv') + ')' }}
						</option>
					</select>
				</label>
			</div>
			<p v-if="costCentersEnabled" class="vbh-hint">
				{{ t('Beiträge dieser Gruppe werden beim Verbuchen des Einzugs dieser Kostenstelle zugeordnet.') }}
			</p>
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
import { toRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcModal from '@nextcloud/vue/components/NcModal'
import AmountInput from './AmountInput.vue'
import { useBookingCostCenters } from '../composables/useBookingCostCenters.js'
import { currencySymbol } from '../lib/clubCurrency.js'
import { intervalLabel } from '../lib/frequency.js'
import { focusOnOpen } from '../lib/modalFocus.js'

const INTERVAL_OPTIONS = [1, 2, 3, 4, 6, 12]

function emptyForm() {
	// minAmount/defaultAmount stehen in der Betragsangabe der Gruppe: Monats- oder Jahresbeträge (Issue #270).
	return { name: '', amountBasis: 'month', prorate: true, minAmount: '', defaultAmount: '', allowedIntervals: [1, 12], defaultInterval: 12, isActive: true, bookingCostCenterId: null }
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
		/** Die Gruppe hat schon Zuweisungen: ihre Betragsangabe (pro Monat/pro Jahr) steht dann fest. */
		basisLocked: { type: Boolean, default: false },
	},

	emits: ['close', 'save', 'update:show'],

	setup() {
		const costCenters = useBookingCostCenters()
		return {
			costCentersEnabled: toRef(costCenters.state, 'enabled'),
			activeCostCenters: costCenters.activeBookingCostCenters,
			costCentersById: costCenters.bookingCostCentersById,
		}
	},

	data() {
		return { form: emptyForm(), intervalOptions: INTERVAL_OPTIONS }
	},

	computed: {
		/** Beträge pro Jahr (Issue #270): nur der jährliche Turnus, dafür der Schalter „anteilig“. */
		annual() {
			return this.form.amountBasis === 'year'
		},

		/** Aktive Kostenstellen, dazu die an der Gruppe gespeicherte inaktive. */
		costCenterOptions() {
			const list = [...this.activeCostCenters]
			const current = this.costCentersById[this.form.bookingCostCenterId]
			if (current && !list.some((cc) => cc.id === current.id)) { list.push(current) }
			return list
		},
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
		currencySymbol,
		intervalLabel,

		/** Die Häkchen schalten je einen Turnus; die Liste bleibt aufsteigend sortiert. */
		setIntervalAllowed(months, allowed) {
			const without = this.form.allowedIntervals.filter((n) => n !== months)
			this.form.allowedIntervals = allowed ? [...without, months].sort((a, b) => a - b) : without
		},

		save() {
			// Die Vorgabe-Kostenstelle geht nur bei eingeschaltetem Modul mit: fehlt
			// das Feld, lässt der Server den gespeicherten Wert stehen.
			const { bookingCostCenterId, minAmount, defaultAmount, ...fields } = this.form
			// Die Beträge gehen unter dem Namen ihrer Betragsangabe raus; ein Jahresbetrag wird nur jährlich eingezogen.
			const rest = this.annual
				? { ...fields, minAnnualAmount: minAmount, defaultAnnualAmount: defaultAmount, allowedIntervals: [12], defaultInterval: 12 }
				: { ...fields, minMonthlyAmount: minAmount, defaultMonthlyAmount: defaultAmount }
			this.$emit('save', this.costCentersEnabled ? { ...rest, bookingCostCenterId } : rest)
		},
	},
}
</script>
