<template>
	<div class="vbh-budgettree">
		<NcNoteCard v-if="conflictCount" type="warning">
			{{ n('{count} Plankonflikt: In einem Zweig ist auf mehreren Ebenen geplant, die Summen zählen diese Pläne doppelt. Setzen Sie einen der Werte auf 0.', '{count} Plankonflikte: In einem Zweig ist auf mehreren Ebenen geplant, die Summen zählen diese Pläne doppelt. Setzen Sie jeweils einen der Werte auf 0.', conflictCount, { count: conflictCount }) }}
		</NcNoteCard>

		<div class="vbh-budgettree-toolbar">
			<NcButton variant="tertiary" :disabled="onlyAttention" @click="resetExpanded">
				{{ t('Bis zur Planungsebene') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="onlyAttention" @click="expandAll">
				{{ t('Alle aufklappen') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="onlyAttention" @click="collapseAll">
				{{ t('Alle zuklappen') }}
			</NcButton>
			<NcCheckboxRadioSwitch v-model="onlyAttention">
				{{ t('Nur Auffälligkeiten') }}
			</NcCheckboxRadioSwitch>
			<span class="vbh-budgettree-counts">
				{{ tRaw('{overrun} überschritten · {unplanned} ohne Plan', { overrun: String(counts.overrun), unplanned: String(counts.unplanned) }) }}
			</span>
		</div>

		<p v-if="onlyAttention && !shown.length" class="vbh-empty">
			{{ t('Keine Auffälligkeiten: keine Ausgabe über Plan, keine Buchung ohne Plan.') }}
		</p>
		<div v-else class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th class="nowrap vbh-col-hide-sm">
							{{ t('Nr.') }}
						</th>
						<th>{{ t('Konto') }}</th>
						<th class="vbh-col-hide-sm">
							{{ t('Art') }}
						</th>
						<th class="num vbh-col-plan" :title="t('Planwert; bei Konten mit Unterkonten die Summe der Pläne darunter')">
							{{ t('Plan (Soll)') }}
						</th>
						<th class="vbh-col-note" :title="t('Notiz zur Planzahl')" />
						<th class="num" :title="t('Tatsächliche Buchungen inkl. Unterkonten')">
							{{ t('Ist') }}
						</th>
						<th class="num">
							{{ t('Differenz') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<template v-for="row in shown" :key="row.accountId">
						<tr :class="{ 'vbh-parentrow': row.hasChildren, 'vbh-budgettree-planitem': row.isPlanItem }">
							<td class="nowrap vbh-col-hide-sm">
								{{ row.number }}
							</td>
							<td :style="{ paddingInlineStart: (6 + row.depth * 18) + 'px' }">
								<div class="vbh-budgettree-name">
									<button
										v-if="row.hasChildren && !onlyAttention"
										type="button"
										class="vbh-budgettree-toggle"
										:aria-expanded="expanded.has(row.accountId) ? 'true' : 'false'"
										:aria-label="expanded.has(row.accountId) ? tRaw('Unterkonten von {number} {name} einklappen', { number: row.number, name: row.name }) : tRaw('Unterkonten von {number} {name} aufklappen', { number: row.number, name: row.name })"
										@click="toggle(row)">
										<NcIconSvgWrapper :path="expanded.has(row.accountId) ? mdiChevronDown : mdiChevronRight" :size="18" />
									</button>
									<span v-else class="vbh-budgettree-spacer" />
									<span>
										{{ row.name }}
										<span v-if="has(row, 'overrun')" class="vbh-pill vbh-pill--error">{{ t('überschritten') }}</span>
										<span v-if="has(row, 'conflict')" class="vbh-pill vbh-pill--error">{{ t('Plankonflikt') }}</span>
										<span v-if="has(row, 'unplanned')" class="vbh-pill vbh-pill--warning">{{ t('ohne Plan') }}</span>
										<span
											v-if="has(row, 'unplannedBelow') && !expanded.has(row.accountId) && !onlyAttention"
											class="vbh-pill vbh-pill--quiet">{{ t('ungeplant darunter') }}</span>
									</span>
								</div>
							</td>
							<td class="vbh-col-hide-sm">
								<span class="vbh-typetag" :class="row.type">{{ typeLabel(row.type) }}</span>
							</td>
							<td class="num vbh-col-plan">
								<span
									v-if="row.planLockedBy"
									class="vbh-budgettree-locked"
									:title="tRaw('Plan liegt auf {number} {name}', { number: row.planLockedBy.number, name: row.planLockedBy.name })">
									{{ row.planSum ? formatMoney(row.planSum) : '—' }}
									<span class="hidden-visually">{{ tRaw('Plan liegt auf {number} {name}', { number: row.planLockedBy.number, name: row.planLockedBy.name }) }}</span>
								</span>
								<template v-else>
									<AmountInput
										v-if="canWrite"
										v-model="row.plan"
										:emptyValue="0"
										class="vbh-num vbh-planinput"
										:aria-label="tRaw('Planwert für {number} {name}', { number: row.number, name: row.name })"
										@change="$emit('save', row)" />
									<span v-else>{{ formatMoney(row.plan) }}</span>
								</template>
							</td>
							<td class="vbh-col-note">
								<NcButton
									v-if="(canWrite && !row.planLockedBy) || row.note"
									variant="tertiary"
									:aria-label="row.note ? t('Notiz zur Planzahl anzeigen') : t('Notiz zur Planzahl hinzufügen')"
									:title="row.note || t('Notiz hinzufügen')"
									@click="noteOpen[row.accountId] = !noteOpen[row.accountId]">
									<template #icon>
										<NcIconSvgWrapper :path="row.note ? mdiCommentText : mdiCommentPlusOutline" :size="18" :class="{ 'vbh-noteicon--set': row.note }" />
									</template>
								</NcButton>
							</td>
							<td class="num strong" :class="amountClass(row.actualSum)">
								{{ formatMoney(row.actualSum) }}
								<div v-if="row.hasChildren && row.actual && row.actual !== row.actualSum" class="vbh-budgettree-direct">
									{{ tRaw('davon direkt {amount}', { amount: formatMoney(row.actual) }) }}
								</div>
							</td>
							<td class="num strong" :class="budgetDiffClass(row)">
								<span v-if="row.diff === null" class="vbh-budgettree-locked" :title="t('Verglichen wird beim Oberkonto mit dem Plan')">—</span>
								<template v-else>
									{{ formatMoney(row.diff) }}
								</template>
							</td>
						</tr>
						<tr v-if="noteOpen[row.accountId]" class="vbh-note-row">
							<td colspan="7">
								<label class="vbh-note-label">{{ tRaw('Notiz zu {number} {name}', { number: row.number, name: row.name }) }}
									<textarea
										v-if="canWrite"
										v-model="row.note"
										maxlength="1000"
										rows="2"
										class="vbh-note-textarea"
										:placeholder="t('z. B. Herleitung: 40 Mitglieder × 25 € Beitrag')"
										@change="$emit('save', row)" />
									<p v-else class="vbh-note-text">{{ row.note }}</p>
								</label>
							</td>
						</tr>
					</template>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { mdiChevronDown, mdiChevronRight, mdiCommentPlusOutline, mdiCommentText } from '@mdi/js'
import { NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper, NcNoteCard } from '@nextcloud/vue'
import AmountInput from './AmountInput.vue'
import { allExpandable, attentionCounts, initialExpanded, visibleRows } from '../lib/budgetTree.js'
import { amountClass, budgetDiffClass, formatMoney, typeLabel } from '../lib/format.js'

// Der Finanzplan als aufklappbarer Baum (siehe lib/Service/BudgetTree.php).
// Plan steht dort, wo geplant wurde; Ist und Differenz schließen alle
// Unterkonten ein. Konten, deren Zweig schon anderswo geplant ist, zeigen
// statt des Eingabefelds die Plansumme mit einem Hinweis, wo der Plan liegt.
export default {
	name: 'BudgetTreeTable',
	components: { AmountInput, NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper, NcNoteCard },
	props: {
		rows: { type: Array, required: true },
		canWrite: { type: Boolean, default: false },
		conflictCount: { type: Number, default: 0 },
		// Wechselt der Zeitraum, gilt wieder der Startzustand. Ein Neuladen
		// nach dem Speichern eines Planwerts lässt den Klappzustand stehen.
		resetKey: { type: [Number, String], default: null },
	},

	emits: ['save'],

	data() {
		return {
			expanded: initialExpanded(this.rows),
			onlyAttention: false,
			noteOpen: {},
			mdiChevronDown,
			mdiChevronRight,
			mdiCommentPlusOutline,
			mdiCommentText,
		}
	},

	computed: {
		shown() { return visibleRows(this.rows, this.expanded, this.onlyAttention) },
		counts() { return attentionCounts(this.rows) },
	},

	watch: {
		resetKey() { this.resetExpanded() },
	},

	methods: {
		amountClass,
		budgetDiffClass,
		formatMoney,
		typeLabel,
		has(row, flag) { return (row.flags || []).includes(flag) },
		toggle(row) {
			const next = new Set(this.expanded)
			if (next.has(row.accountId)) {
				next.delete(row.accountId)
			} else {
				next.add(row.accountId)
			}
			this.expanded = next
		},

		resetExpanded() { this.expanded = initialExpanded(this.rows) },
		expandAll() { this.expanded = allExpandable(this.rows) },
		collapseAll() { this.expanded = new Set() },
	},
}
</script>
