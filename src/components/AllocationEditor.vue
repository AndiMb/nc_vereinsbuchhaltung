<template>
	<div class="vbh-alloc">
		<!-- Normalfall: eine Kostenstelle über den ganzen Betrag - oder keine. -->
		<div v-if="!multi" class="vbh-alloc-single">
			<select
				:value="singleValue"
				:disabled="disabled"
				:aria-label="label"
				@change="setSingle($event.target.value)">
				<option value="">
					{{ t('– keine –') }}
				</option>
				<option v-for="cc in optionsFor(0)" :key="cc.id" :value="String(cc.id)">
					{{ optionLabel(cc) }}
				</option>
			</select>
			<NcButton
				v-if="!disabled && options.length > 1"
				variant="tertiary"
				size="small"
				@click="startMulti">
				{{ t('Auf mehrere verteilen…') }}
			</NcButton>
		</div>

		<!-- Aufteilung auf mehrere Kostenstellen, je mit Betrag. -->
		<template v-else>
			<ul class="vbh-alloc-list">
				<li v-for="(row, i) in rows" :key="i" class="vbh-alloc-row">
					<select
						:value="row.costCenterId ? String(row.costCenterId) : ''"
						:disabled="disabled"
						:aria-label="t('Kostenstelle {n}', { n: i + 1 })"
						@change="setRow(i, { costCenterId: $event.target.value ? Number($event.target.value) : null })">
						<option value="">
							{{ t('– Kostenstelle wählen –') }}
						</option>
						<option v-for="cc in optionsFor(i)" :key="cc.id" :value="String(cc.id)">
							{{ optionLabel(cc) }}
						</option>
					</select>
					<AmountInput
						:modelValue="row.amount"
						:emptyValue="null"
						class="vbh-num vbh-alloc-amount"
						:aria-label="t('Betrag für Kostenstelle {n}', { n: i + 1 })"
						:disabled="disabled"
						@update:modelValue="setRow(i, { amount: $event })" />
					<NcButton
						v-if="!disabled"
						variant="tertiary"
						:aria-label="t('Kostenstelle {n} entfernen', { n: i + 1 })"
						@click="removeRow(i)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDelete" :size="14" />
						</template>
					</NcButton>
				</li>
			</ul>
			<div class="vbh-alloc-foot">
				<NcButton
					v-if="!disabled"
					variant="tertiary"
					size="small"
					@click="addRow">
					{{ t('+ Kostenstelle') }}
				</NcButton>
				<NcButton
					v-if="!disabled && rest > 0.0049 && rows.length"
					variant="tertiary"
					size="small"
					:title="t('Den noch offenen Rest in die letzte Zeile schreiben')"
					@click="fillRest">
					{{ t('Rest übernehmen') }}
				</NcButton>
				<span class="vbh-alloc-rest" :class="{ bad: !!error }" role="status">
					{{ statusText }}
				</span>
			</div>
		</template>
		<p v-if="!multi && error" class="vbh-hint vbh-hint--error" role="alert">
			{{ statusText }}
		</p>
		<!-- Pflichtkonto (Issue #334): Erinnerung, Speichern bleibt möglich. -->
		<p v-if="openRequired > 0" class="vbh-hint vbh-hint--warning vbh-alloc-required" role="status">
			{{ t('Für dieses Konto ist eine Kostenstelle vorgesehen; {amount} sind noch nicht zugeordnet.', { amount: formatMoney(openRequired) }) }}
		</p>
	</div>
</template>

<script>
import { mdiDelete } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import AmountInput from './AmountInput.vue'
import { useBookingCostCenters } from '../composables/useBookingCostCenters.js'
import { allocationError, isSingleFull, remainderEuro, requiredRemainder } from '../lib/allocation.js'
import { formatMoney } from '../lib/format.js'

/**
 * Eingabe der Kostenstellen einer Buchungszeile (Issue #7), genutzt vom
 * Buchungsdialog und vom Aufteilen-Dialog.
 *
 * Der Normalfall ist ein einziges Auswahlfeld: keine Kostenstelle (Vorgabe)
 * oder eine über den ganzen Betrag. Erst „Auf mehrere verteilen…" zeigt die
 * Liste mit Betrag je Kostenstelle; was dort nicht verteilt ist, bleibt
 * „ohne Kostenstelle" - das ist erlaubt.
 *
 * Der Wert ist eine Liste [{ costCenterId, amount }] in Euro; geprüft wird
 * mit den Regeln aus lib/allocation.js. Zur Auswahl stehen die aktiven
 * Kostenstellen, dazu inaktive, die an dieser Zeile schon zugeordnet sind.
 */
export default {
	name: 'AllocationEditor',
	components: { AmountInput, NcButton, NcIconSvgWrapper },

	props: {
		modelValue: { type: Array, default: () => [] },
		// Betrag der Buchungszeile in Euro - Obergrenze der Verteilung.
		lineAmount: { type: Number, default: 0 },
		disabled: { type: Boolean, default: false },
		// Beschriftung für Screenreader (das sichtbare Label setzt der Aufrufer).
		label: { type: String, default: '' },
		// Konto der Zeile trägt „Kostenstelle verpflichtend“ (Issue #334): dann
		// erinnert der Editor an den noch nicht zugeordneten Betrag.
		required: { type: Boolean, default: false },
	},

	emits: ['update:modelValue'],

	setup() {
		const costCenters = useBookingCostCenters()
		return {
			costCentersById: costCenters.bookingCostCentersById,
			activeCostCenters: costCenters.activeBookingCostCenters,
		}
	},

	data() {
		return {
			mdiDelete,
			multi: this.needsMulti(this.modelValue),
		}
	},

	computed: {
		rows() { return this.modelValue || [] },

		/** Aktive Kostenstellen plus bereits zugeordnete inaktive. */
		options() {
			const list = [...this.activeCostCenters]
			for (const row of this.rows) {
				const cc = this.costCentersById[row.costCenterId]
				if (cc && !list.some((c) => c.id === cc.id)) { list.push(cc) }
			}
			return list.sort((a, b) => String(a.code).localeCompare(String(b.code), 'de', { numeric: true }))
		},

		singleValue() {
			return this.rows.length && this.rows[0].costCenterId ? String(this.rows[0].costCenterId) : ''
		},

		rest() { return remainderEuro(this.lineAmount, this.rows) },

		error() { return allocationError(this.lineAmount, this.rows) },

		openRequired() {
			return this.error ? 0 : requiredRemainder(this.lineAmount, this.rows, { costCenterRequired: this.required })
		},

		statusText() {
			switch (this.error) {
				case 'missing': return this.t('Einem Betrag fehlt die Kostenstelle.')
				case 'amount': return this.t('Jede Kostenstelle braucht einen Betrag größer als 0.')
				case 'duplicate': return this.t('Jede Kostenstelle nur einmal je Zeile.')
				case 'exceeds': return this.t('Zu viel verteilt: {amount}', { amount: formatMoney(-this.rest) })
				default: return this.rest > 0.0049
					? this.t('Rest ohne Kostenstelle: {amount}', { amount: formatMoney(this.rest) })
					: this.t('✓ vollständig verteilt')
			}
		},
	},

	watch: {
		// Von außen ersetzt (andere Buchung geöffnet, Formular geleert): die
		// Darstellung folgt dem Wert.
		modelValue(value) {
			if (this.needsMulti(value)) { this.multi = true } else if (!(value || []).length) { this.multi = false }
		},
	},

	methods: {
		formatMoney,

		/**
		 * Lässt sich der Wert nicht als "eine Kostenstelle über alles" zeigen?
		 *
		 * @param {Array} value Verteilung
		 * @return {boolean}
		 */
		needsMulti(value) {
			const list = value || []
			return list.length > 1 || (list.length === 1 && !isSingleFull(this.lineAmount, list))
		},

		optionLabel(cc) {
			return `${cc.code} · ${cc.name}` + (cc.active ? '' : ` (${this.t('inaktiv')})`)
		},

		/**
		 * Auswahl einer Zeile: was in anderen Zeilen schon gewählt ist, fehlt.
		 *
		 * @param {number} index Zeile
		 * @return {Array}
		 */
		optionsFor(index) {
			const taken = this.rows.filter((_, i) => i !== index).map((r) => r.costCenterId)
			return this.options.filter((cc) => !taken.includes(cc.id))
		},

		setSingle(value) {
			this.$emit('update:modelValue', value ? [{ costCenterId: Number(value), amount: Number(this.lineAmount || 0) }] : [])
		},

		startMulti() {
			this.multi = true
			const rows = this.rows.length ? [...this.rows] : [{ costCenterId: null, amount: null }]
			this.$emit('update:modelValue', [...rows, { costCenterId: null, amount: null }])
		},

		setRow(index, patch) {
			this.$emit('update:modelValue', this.rows.map((row, i) => (i === index ? { ...row, ...patch } : row)))
		},

		addRow() {
			this.$emit('update:modelValue', [...this.rows, { costCenterId: null, amount: null }])
		},

		removeRow(index) {
			this.$emit('update:modelValue', this.rows.filter((_, i) => i !== index))
		},

		fillRest() {
			const last = this.rows.length - 1
			const amount = Math.round((Number(this.rows[last].amount || 0) + this.rest) * 100) / 100
			this.setRow(last, { amount })
		},
	},
}
</script>

<style scoped>
.vbh-alloc { display: flex; flex-direction: column; gap: 6px; min-width: 0; }

.vbh-alloc-single { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.vbh-alloc-single select { flex: 1 1 200px; min-width: 0; }

.vbh-alloc-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }

.vbh-alloc-row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

.vbh-alloc-row select { flex: 1 1 180px; min-width: 0; }

.vbh-alloc-amount { flex: 0 1 130px; }

.vbh-alloc-foot { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.vbh-alloc-rest { margin-inline-start: auto; font-variant-numeric: tabular-nums; font-size: 0.9em; color: var(--color-text-maxcontrast); }

.vbh-alloc-rest.bad { color: var(--color-error-text); }
</style>
