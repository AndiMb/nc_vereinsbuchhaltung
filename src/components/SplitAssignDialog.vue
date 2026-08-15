<template>
	<NcModal
		:show="show"
		:name="isMobile ? t('Umsatz aufteilen') : ''"
		:labelId="isMobile ? undefined : 'vbh-modal-title-split'"
		:size="isMobile ? 'full' : 'normal'"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 v-if="!isMobile" id="vbh-modal-title-split" class="vbh-modal-title">
				{{ t('Umsatz aufteilen') }}
			</h2>
			<p class="vbh-hint vbh-hint--info">
				{{ t('Der Umsatz wird auf mehrere Gegenkonten verteilt – für eine Überweisung, die mehreres zugleich enthält (z. B. Beitrag und Spende). Das Geldkonto bleibt unverändert.') }}
			</p>

			<div v-if="tx" class="vbh-split-tx">
				<div class="vbh-split-tx-line">
					<span>{{ formatDate(tx.bookingDate) }}</span>
					<strong class="vbh-split-tx-amount">{{ formatMoney(totalEuro) }}</strong>
				</div>
				<div v-if="tx.counterparty" class="vbh-split-tx-party">
					{{ tx.counterparty }}
				</div>
				<div v-if="tx.purpose" class="vbh-split-tx-purpose">
					{{ tx.purpose }}
				</div>
			</div>

			<div class="vbh-split">
				<div class="vbh-split-head">
					<span class="vbh-split-title">{{ t('Aufteilung') }}</span>
					<span class="vbh-split-rest" :class="{ ok: restOk, bad: !restOk }">
						{{ restOk ? t('✓ geht auf') : t('Rest: {amount}', { amount: formatMoney(rest) }) }}
					</span>
				</div>
				<ul class="vbh-split-list">
					<li v-for="(part, i) in parts" :key="i" class="vbh-split-row">
						<button
							v-if="isMobile"
							type="button"
							class="vbh-fieldbtn vbh-split-acc"
							@click="openAccountPicker('splitline:' + i)">
							<span class="vbh-fieldbtn-text">
								<span class="vbh-fieldbtn-val" :class="{ placeholder: !part.accountId }">{{ part.accountId ? accountLabel(part.accountId) : t('Konto wählen…') }}</span>
							</span>
							<span class="vbh-fieldbtn-chev" aria-hidden="true">›</span>
						</button>
						<NcSelect
							v-else
							:modelValue="optionFor(i)"
							:options="accountOptions"
							:filterBy="accountFilterBy"
							class="vbh-split-acc"
							label="label"
							:placeholder="t('– Konto wählen –')"
							@update:modelValue="setAccount(i, $event)" />
						<input
							:value="part.amount"
							type="number"
							step="0.01"
							min="0.01"
							inputmode="decimal"
							class="vbh-num vbh-split-amount"
							:aria-label="t('Teilbetrag Zeile {n}', { n: i + 1 })"
							@input="setAmount(i, $event.target.value)">
						<NcButton
							variant="tertiary"
							:aria-label="t('Zeile {n} entfernen', { n: i + 1 })"
							@click="removePart(i)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiDelete" :size="14" />
							</template>
						</NcButton>
					</li>
				</ul>
				<div class="vbh-split-actions">
					<NcButton variant="tertiary" @click="addPart">
						{{ t('+ Zeile hinzufügen') }}
					</NcButton>
					<NcButton
						v-if="rest > 0.0049"
						variant="tertiary"
						:title="t('Den noch offenen Rest in die letzte Zeile schreiben')"
						@click="fillRest">
						{{ t('Rest übernehmen') }}
					</NcButton>
				</div>
			</div>

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" @click="$emit('save')">
					{{ t('Zuordnen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { mdiDelete } from '@mdi/js'
import { NcButton, NcIconSvgWrapper, NcModal, NcSelect } from '@nextcloud/vue'
import { useAccounts } from '../composables/useAccounts.js'
import { formatDate, formatMoney } from '../lib/format.js'
import { splitBalanced, splitRemainder } from '../lib/split.js'

/**
 * Teilt einen Bankumsatz beim Zuordnen auf mehrere Gegenkonten auf.
 *
 * Bewusst ein eigener Dialog und nicht der Buchungsdialog: hier entsteht keine
 * freie Buchung, sondern eine Zuordnung (POST /transactions/{id}/assign) - der
 * Umsatz muss danach als zugeordnet gelten. Gesamtbetrag und Geldkonto stehen
 * dabei fest, aufzuteilen ist allein die Gegenseite.
 *
 * parts gehoert App.vue (dort wird gespeichert). Aenderungen aus diesem Dialog
 * gehen per update:parts zurueck; die mobile Kontoauswahl liegt ebenfalls auf
 * App.vue-Ebene (AccountPickerSheet, geteilt mit dem Buchungsdialog) und
 * schreibt in dieselbe Liste.
 */
export default {
	name: 'SplitAssignDialog',
	components: { NcModal, NcButton, NcSelect, NcIconSvgWrapper },
	props: {
		show: { type: Boolean, default: false },
		tx: { type: Object, default: null },
		parts: { type: Array, required: true },
		isMobile: { type: Boolean, required: true },
		openAccountPicker: { type: Function, required: true },
	},

	emits: ['close', 'save', 'update:parts', 'update:show'],

	setup() {
		const accounts = useAccounts()
		return { accountsSorted: accounts.accountsSorted, accountsById: accounts.accountsById }
	},

	data() {
		return { mdiDelete }
	},

	computed: {
		/** Betrag des Umsatzes ohne Vorzeichen - aufgeteilt wird die Summe. */
		totalEuro() {
			return this.tx ? Math.abs(this.tx.amountCents || 0) / 100 : 0
		},

		rest() {
			return splitRemainder(this.totalEuro, this.parts)
		},

		restOk() {
			return splitBalanced(this.totalEuro, this.parts)
		},

		/** Aktive Konten nach Kategorie gruppiert, ohne die bereits belegten. */
		accountOptions() {
			const used = new Set(this.parts.map((p) => p.accountId).filter(Boolean))
			const groups = {}
			for (const acc of this.accountsSorted) {
				if (!acc.active || used.has(acc.id)) { continue }
				const cat = acc.category || this.t('Sonstige')
				;(groups[cat] = groups[cat] || []).push(acc)
			}
			const opts = []
			for (const [cat, list] of Object.entries(groups)) {
				opts.push({ id: null, label: cat, $isDisabled: true })
				for (const acc of list) { opts.push({ id: acc.id, label: `${acc.number} ${acc.name}`, number: acc.number }) }
			}
			return opts
		},
	},

	methods: {
		formatMoney,
		formatDate,
		accountLabel(id) {
			const acc = this.accountsById[id]
			return acc ? `${acc.number} ${acc.name}` : `#${id}`
		},

		accountFilterBy(option, label, search) {
			const s = String(search || '').trim().toLowerCase()
			if (!s) { return true }
			if (option && option.$isDisabled) { return false }
			if (/^[\d\s]+$/.test(s)) {
				const digits = s.replace(/\s+/g, '')
				const num = String((option && option.number) || '').replace(/\s+/g, '').toLowerCase()
				return num.startsWith(digits)
			}
			return String(label || '').toLowerCase().includes(s)
		},

		optionFor(index) {
			const id = this.parts[index]?.accountId
			if (id === null || id === undefined) { return null }
			const acc = this.accountsById[id]
			return acc ? { id: acc.id, label: `${acc.number} ${acc.name}`, number: acc.number } : null
		},

		setAccount(index, option) {
			this.patch(index, { accountId: option ? option.id : null })
		},

		setAmount(index, value) {
			this.patch(index, { amount: value === '' ? null : Number(value) })
		},

		patch(index, values) {
			this.$emit('update:parts', this.parts.map((p, i) => (i === index ? { ...p, ...values } : p)))
		},

		addPart() {
			this.$emit('update:parts', [...this.parts, { accountId: null, amount: null }])
		},

		removePart(index) {
			this.$emit('update:parts', this.parts.filter((_, i) => i !== index))
		},

		/** Schreibt den offenen Rest in die letzte Zeile. */
		fillRest() {
			if (!this.parts.length) { return }
			const last = this.parts.length - 1
			const value = Math.round((Number(this.parts[last].amount || 0) + this.rest) * 100) / 100
			this.patch(last, { amount: value })
		},
	},
}
</script>
