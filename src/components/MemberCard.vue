<template>
	<div class="vbh-mcard vbh-membercard" :class="{ open: row.fee && row.fee.dueCount > 0 }">
		<template v-if="editing">
			<div class="vbh-mcard-top">
				<span class="vbh-mcard-title">{{ row.displayName }}</span>
			</div>
			<div class="vbh-form">
				<label>{{ t('Betrag (€)') }}
					<AmountInput
						v-model="editAmount"
						class="vbh-short" />
				</label>
				<label>{{ t('Frequenz') }}
					<select v-model="editFrequency">
						<option v-for="f in frequencies" :key="f.value" :value="f.value">
							{{ f.label }}
						</option>
					</select>
				</label>
				<label>{{ t('Nächste Fälligkeit') }}
					<input v-model="editNextDueDate" type="date">
				</label>
				<label>
					<input v-model="editActive" type="checkbox">
					{{ t('Aktiv') }}
				</label>
			</div>
			<div class="vbh-mcard-actions">
				<NcButton
					variant="primary"
					size="small"
					:disabled="saving"
					@click="$emit('save-edit')">
					{{ t('Speichern') }}
				</NcButton>
				<NcButton variant="tertiary" size="small" @click="$emit('cancel-edit')">
					{{ t('Abbrechen') }}
				</NcButton>
			</div>
		</template>

		<template v-else>
			<div class="vbh-mcard-top">
				<span class="vbh-mcard-title">{{ row.displayName }}</span>
				<span v-if="row.fee" class="vbh-mcard-amount">{{ formatMoney(row.fee.amount) }}</span>
			</div>
			<p v-if="!row.email" class="vbh-hint">
				{{ t('keine E-Mail – keine Vorankündigung möglich') }}
			</p>
			<div class="vbh-mcard-bottom">
				<span class="vbh-mcard-accounts">
					<template v-if="row.mandate">
						{{ row.mandate.iban }}
						<span v-if="row.mandate.status !== 'active'" class="vbh-typetag">{{ t('widerrufen') }}</span>
					</template>
					<span v-else>{{ t('kein Mandat') }}</span>
				</span>
			</div>
			<div v-if="row.fee" class="vbh-mcard-bottom">
				<span class="vbh-mcard-accounts">{{ frequencyLabel(row.fee.frequency) }} · {{ t('fällig {date}', { date: row.fee.nextDueDate }) }}</span>
				<label class="vbh-checkinline">
					<input type="checkbox" :checked="row.fee.active" @change="$emit('toggle-active', $event.target.checked)">
					{{ t('aktiv') }}
				</label>
			</div>
			<p v-if="row.fee && row.fee.dueCount > 0" class="vbh-hint vbh-hint--warning">
				{{ n('%n Periode im Rückstand', '%n Perioden im Rückstand', row.fee.dueCount) }}
			</p>
			<div class="vbh-mcard-actions">
				<NcButton
					v-if="row.fee && row.fee.dueCount > 0"
					variant="secondary"
					size="small"
					@click="$emit('catch-up')">
					{{ t('Nachholen') }}
				</NcButton>
				<NcButton
					v-if="row.fee"
					variant="tertiary"
					size="small"
					@click="$emit('start-edit')">
					{{ t('Bearbeiten') }}
				</NcButton>
				<!-- Seltener genutzte Aktionen im Menue, gleiches Muster wie in der
					Desktop-Tabelle (MembersList.vue) und im Buchungsjournal. -->
				<NcActions v-if="row.fee || (row.mandate && (row.mandate.status === 'active' || !isUsed))" :forceMenu="true">
					<NcActionButton v-if="row.mandate && row.mandate.status === 'active'" @click="$emit('bank-change')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiBankTransfer" :size="16" />
						</template>
						{{ t('Bankverbindung wechseln') }}
					</NcActionButton>
					<NcActionButton v-if="row.mandate && row.mandate.status === 'active'" @click="$emit('revoke-mandate')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCancel" :size="16" />
						</template>
						{{ t('Mandat widerrufen') }}
					</NcActionButton>
					<NcActionButton v-if="row.fee" @click="$emit('remove-fee')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDelete" :size="16" />
						</template>
						{{ t('Beitrag löschen') }}
					</NcActionButton>
					<NcActionButton v-else-if="row.mandate && !isUsed" @click="$emit('remove-mandate')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDelete" :size="16" />
						</template>
						{{ t('Mandat löschen') }}
					</NcActionButton>
				</NcActions>
			</div>
		</template>
	</div>
</template>

<script>
import { mdiBankTransfer, mdiCancel, mdiDelete } from '@mdi/js'
import { NcActionButton, NcActions, NcButton, NcIconSvgWrapper } from '@nextcloud/vue'
import AmountInput from './AmountInput.vue'
import { formatMoney } from '../lib/format.js'
import { frequencyLabel } from '../lib/frequency.js'

/**
 * Mobile Kartendarstellung einer Mitgliederzeile (MembersList.vue): dieselben
 * Angaben und Aktionen wie die Desktop-Tabellenzeile, nur gestapelt statt in
 * sieben nebeneinanderliegenden Spalten - eine Tabelle mit so vielen Spalten
 * lief auf schmalen Bildschirmen sonst auf Ein-Zeichen-pro-Zeile-Zeilenumbruch
 * hinaus (table-layout: fixed + zu wenig Platz je Spalte).
 *
 * Editier-Zustand (Betrag/Frequenz/Fälligkeit/Aktiv) bleibt wie bisher in
 * MembersList.vue (das `editing`-Objekt); diese Karte zeigt nur, je nachdem
 * ob `editing` gesetzt ist, Anzeige- oder Eingabefelder.
 */
export default {
	name: 'MemberCard',
	components: { NcButton, NcActions, NcActionButton, NcIconSvgWrapper, AmountInput },
	props: {
		row: { type: Object, required: true },
		/** Das geteilte editing-Objekt aus MembersList.vue, oder null. */
		editing: { type: Object, default: null },
		frequencies: { type: Array, required: true },
		saving: { type: Boolean, default: false },
		isUsed: { type: Boolean, default: false },
	},

	emits: ['bank-change', 'cancel-edit', 'catch-up', 'remove-fee', 'remove-mandate', 'revoke-mandate', 'save-edit', 'start-edit', 'toggle-active', 'update-editing'],

	data() {
		return { mdiBankTransfer, mdiCancel, mdiDelete }
	},

	computed: {
		// Eigene v-model-Ziele statt direkter Prop-Mutation (vue/no-mutating-props):
		// jede Feldaenderung meldet sich per Event zurueck, MembersList.vue haelt
		// den eigentlichen editing-Zustand (gleiches Muster wie updateForm() in
		// BookingDialog.vue).
		editAmount: {
			get() { return this.editing.amount },
			set(v) { this.$emit('update-editing', { ...this.editing, amount: v }) },
		},

		editFrequency: {
			get() { return this.editing.frequency },
			set(v) { this.$emit('update-editing', { ...this.editing, frequency: v }) },
		},

		editNextDueDate: {
			get() { return this.editing.nextDueDate },
			set(v) { this.$emit('update-editing', { ...this.editing, nextDueDate: v }) },
		},

		editActive: {
			get() { return this.editing.active },
			set(v) { this.$emit('update-editing', { ...this.editing, active: v }) },
		},
	},

	methods: { formatMoney, frequencyLabel },
}
</script>
