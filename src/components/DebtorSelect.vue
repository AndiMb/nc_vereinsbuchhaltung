<template>
	<div class="vbh-debtor-select">
		<div class="vbh-debtor-select-row">
			<NcSelect
				v-model="selected"
				class="vbh-debtor-select-field"
				:options="options"
				:filterable="false"
				:loading="loading"
				:clearable="false"
				:disabled="disabled"
				:inputLabel="t('Empfänger')"
				label="label"
				:placeholder="t('Empfänger oder Mitglied suchen …')"
				@search="onSearch"
				@open="onOpen">
				<template #option="option">
					<span class="vbh-debtor-option">
						<span>{{ option.label }}</span>
						<span v-if="option.sub" class="vbh-debtor-option-sub">{{ option.sub }}</span>
					</span>
				</template>
				<template #no-options>
					{{ t('Kein Treffer. Legen Sie den Empfänger neu an.') }}
				</template>
			</NcSelect>
			<NcButton
				v-if="!disabled"
				variant="secondary"
				:title="t('Neuen Empfänger anlegen')"
				@click="openNew">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" :size="20" />
				</template>
				{{ t('Neu') }}
			</NcButton>
			<NcButton
				v-if="!disabled && debtor"
				variant="tertiary"
				:aria-label="t('Empfänger bearbeiten')"
				:title="t('Empfänger bearbeiten')"
				@click="openEdit">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPencil" :size="20" />
				</template>
			</NcButton>
		</div>
		<address v-if="debtor" class="vbh-debtor-address">
			{{ addressLines.join(' · ') }}
		</address>
		<DebtorDialog
			:show="dialog.open"
			:debtor="dialog.debtor"
			:initialName="dialog.name"
			@close="dialog.open = false"
			@update:show="dialog.open = $event"
			@saved="onSaved" />
	</div>
</template>

<script>
import { mdiPencil, mdiPlus } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { toRefs } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import DebtorDialog from './DebtorDialog.vue'
import api from '../api.js'
import { useMembers } from '../composables/useMembers.js'
import { errMsg } from '../lib/format.js'

/**
 * Auswahl des Rechnungsempfängers: sucht in den angelegten Empfängern und in
 * den Mitgliedern. Wer ein Mitglied wählt, bekommt dafür einen Empfänger mit
 * der Adresse aus der Akte (einmalig angelegt, dann wiederverwendet). `modelValue`
 * ist die Empfänger-ID; der gewählte Datensatz geht zusätzlich als `debtor`-Ereignis
 * hinaus, damit der Editor Adresse und E-Mail zeigen kann.
 */
export default {
	name: 'DebtorSelect',
	components: { NcSelect, NcButton, NcIconSvgWrapper, DebtorDialog },
	props: {
		modelValue: { type: Number, default: null },
		disabled: { type: Boolean, default: false },
	},

	emits: ['update:modelValue', 'debtor'],

	setup() {
		const members = useMembers()
		return { ...toRefs(members.state), loadMembers: members.loadMembers, mdiPlus, mdiPencil }
	},

	data() {
		return {
			debtors: [],
			debtor: null,
			query: '',
			loading: false,
			dialog: { open: false, debtor: null, name: '' },
		}
	},

	computed: {
		options() {
			const q = this.query.trim().toLowerCase()
			const linked = new Set(this.debtors.map((d) => d.memberId).filter(Boolean))
			const rows = this.debtors.map((d) => ({ id: 'd' + d.id, debtorId: d.id, label: d.name, sub: [d.city, d.customerNo].filter(Boolean).join(' · ') }))
			// Mitglieder, für die es noch keinen Empfänger gibt.
			const memberRows = (this.members || [])
				.filter((m) => !linked.has(m.id) && !m.redactedAt && (q === '' || (m.displayName || '').toLowerCase().includes(q)))
				.slice(0, 30)
				.map((m) => ({ id: 'm' + m.id, memberId: m.id, label: m.displayName || `#${m.id}`, sub: this.t('Mitglied') }))
			return [...rows, ...memberRows]
		},

		selected: {
			get() {
				return this.debtor ? { id: 'd' + this.debtor.id, debtorId: this.debtor.id, label: this.debtor.name } : null
			},

			set(option) { this.choose(option) },
		},

		addressLines() {
			const d = this.debtor
			if (!d) { return [] }
			return [d.street, [d.postalCode, d.city].filter(Boolean).join(' ')].filter(Boolean)
		},
	},

	watch: {
		modelValue: { immediate: true, handler: 'syncFromId' },
	},

	mounted() {
		if (!this.members.length) { this.loadMembers() }
	},

	methods: {
		async syncFromId(id) {
			if (id === null || id === undefined) {
				this.debtor = null
				return
			}
			if (this.debtor && this.debtor.id === id) { return }
			try {
				const { data } = await api.debtors('')
				this.debtors = data
				const hit = data.find((d) => d.id === id)
				if (hit) {
					this.debtor = hit
					this.$emit('debtor', hit)
				}
			} catch { /* die Auswahl bleibt leer */ }
		},

		async search() {
			this.loading = true
			try {
				const { data } = await api.debtors(this.query)
				this.debtors = data
			} catch (e) {
				showError(errMsg(e, this.t('Empfänger konnten nicht geladen werden.')))
			} finally {
				this.loading = false
			}
		},

		onOpen() { this.search() },

		onSearch(text) {
			this.query = text || ''
			this.search()
		},

		async choose(option) {
			if (!option) { return }
			let debtor = null
			try {
				if (option.debtorId) {
					debtor = this.debtors.find((d) => d.id === option.debtorId) || null
				} else if (option.memberId) {
					const { data } = await api.debtorFromMember(option.memberId)
					debtor = data
					this.debtors = [...this.debtors.filter((d) => d.id !== data.id), data]
				}
			} catch (e) {
				showError(errMsg(e, this.t('Der Empfänger konnte nicht übernommen werden.')))
				return
			}
			if (debtor) { this.apply(debtor) }
		},

		apply(debtor) {
			this.debtor = debtor
			this.$emit('update:modelValue', debtor.id)
			this.$emit('debtor', debtor)
		},

		openNew() { this.dialog = { open: true, debtor: null, name: this.query.trim() } },
		openEdit() { this.dialog = { open: true, debtor: this.debtor, name: '' } },

		onSaved(debtor) {
			this.dialog.open = false
			this.debtors = [...this.debtors.filter((d) => d.id !== debtor.id), debtor]
			this.apply(debtor)
		},
	},
}
</script>

<style scoped>
.vbh-debtor-select { display: flex; flex-direction: column; gap: 4px; min-width: 0; }

.vbh-debtor-select-row { display: flex; gap: 6px; align-items: center; }

.vbh-debtor-select-field { flex: 1 1 auto; min-width: 0; }

.vbh-debtor-option { display: flex; flex-direction: column; min-width: 0; }

.vbh-debtor-option-sub { font-size: 0.82em; opacity: 0.7; }

.vbh-debtor-address { font-style: normal; font-size: 0.88em; opacity: 0.75; }
</style>
