<template>
	<div>
		<p class="vbh-hint">
			{{ t('Regeln ordnen offenen Bankbuchungen automatisch ein Gegenkonto zu: Enthält das gewählte Feld (Zahlungspartner, Verwendungszweck oder IBAN) den Suchtext, wird das Gegenkonto vorgeschlagen und beim Import direkt gesetzt. Bei mehreren Treffern gewinnt die höhere Priorität.') }}
		</p>

		<div class="vbh-card">
			<h4>{{ ruleEditId ? t('Regel bearbeiten') : t('Neue Regel') }}</h4>
			<div class="vbh-form">
				<label>{{ t('Feld') }}
					<select v-model="ruleForm.matchField">
						<option value="counterparty">{{ t('Zahlungspartner') }}</option>
						<option value="purpose">{{ t('Verwendungszweck') }}</option>
						<option value="iban">{{ t('IBAN') }}</option>
					</select>
				</label>
				<label class="vbh-grow">{{ t('enthält (Suchtext)') }}
					<input
						v-model="ruleForm.matchValue"
						type="text"
						:placeholder="t('z. B. Stadtwerke')"
						@keyup.enter="saveRule">
				</label>
				<label class="vbh-grow">{{ ruleForm.parts.length ? t('Restkonto') : t('Gegenkonto') }}
					<NcSelect
						v-model="ruleFormContraOption"
						:options="accountOptionsList"
						:filterBy="accountFilterBy"
						:selectable="isSelectableOption"
						label="label"
						:placeholder="t('– Konto wählen –')" />
				</label>
				<!-- Vorgabe-Kostenstelle (Issue #331): nur bei eingeschaltetem Modul -->
				<label v-if="costCentersEnabled">{{ t('Kostenstelle') }}
					<select v-model="ruleForm.costCenterId">
						<option :value="null">
							{{ t('– keine –') }}
						</option>
						<option v-for="cc in costCenterOptions" :key="cc.id" :value="cc.id">
							{{ cc.code }} · {{ cc.name }}{{ cc.active ? '' : ' (' + t('inaktiv') + ')' }}
						</option>
					</select>
				</label>
				<label class="vbh-rule-prio">{{ t('Priorität') }}
					<input v-model.number="ruleForm.priority" type="number" step="1">
				</label>
				<NcButton variant="primary" @click="saveRule">
					{{ ruleEditId ? t('Speichern') : t('Hinzufügen') }}
				</NcButton>
				<NcButton v-if="ruleEditId" variant="tertiary" @click="resetRuleForm">
					{{ t('Abbrechen') }}
				</NcButton>
			</div>

			<!-- Feste Anteile (Issue #163): der Rest geht aufs Restkonto -->
			<div class="vbh-split vbh-rule-parts">
				<div class="vbh-split-head">
					<span class="vbh-split-title">{{ t('Feste Anteile (optional)') }}</span>
				</div>
				<p v-if="ruleForm.parts.length" class="vbh-hint">
					{{ t('Was nach den festen Anteilen übrig bleibt, geht auf das Restkonto. Ist der Umsatz kleiner als die Anteile zusammen, bleibt er unzugeordnet.') }}
				</p>
				<ul v-if="ruleForm.parts.length" class="vbh-split-list">
					<li v-for="(part, i) in ruleForm.parts" :key="part.uid" class="vbh-split-row">
						<NcSelect
							:modelValue="partOption(part)"
							:options="accountOptionsList"
							:filterBy="accountFilterBy"
							:selectable="isSelectableOption"
							class="vbh-split-acc"
							label="label"
							:ariaLabelCombobox="t('Konto Anteil {n}', { n: i + 1 })"
							:placeholder="t('– Konto wählen –')"
							@update:modelValue="part.accountId = $event ? $event.id : null" />
						<AmountInput
							v-model="part.amount"
							:emptyValue="null"
							class="vbh-num vbh-split-amount"
							:aria-label="t('Betrag Anteil {n}', { n: i + 1 })" />
						<select
							v-if="costCentersEnabled"
							v-model="part.costCenterId"
							:aria-label="t('Kostenstelle Anteil {n}', { n: i + 1 })">
							<option :value="null">
								{{ t('– keine –') }}
							</option>
							<option v-for="cc in costCenterOptionsFor(part.costCenterId)" :key="cc.id" :value="cc.id">
								{{ cc.code }} · {{ cc.name }}{{ cc.active ? '' : ' (' + t('inaktiv') + ')' }}
							</option>
						</select>
						<NcButton
							variant="tertiary"
							:aria-label="t('Anteil {n} entfernen', { n: i + 1 })"
							@click="ruleForm.parts.splice(i, 1)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiDelete" :size="14" />
							</template>
						</NcButton>
					</li>
				</ul>
				<div class="vbh-split-actions">
					<NcButton variant="tertiary" @click="addPart">
						{{ t('+ Anteil hinzufügen') }}
					</NcButton>
				</div>
			</div>
		</div>

		<div v-if="rules.length" class="vbh-tablecard">
			<table class="vbh-table vbh-stack">
				<thead>
					<tr>
						<th>{{ t('Feld') }}</th><th>{{ t('Suchtext') }}</th><th>{{ t('Gegenkonto') }}</th><th v-if="costCentersEnabled">
							{{ t('Kostenstelle') }}
						</th><th class="num">
							{{ t('Prio.') }}
						</th><th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="rule in rules" :key="rule.id" :class="{ 'vbh-row-editing': ruleEditId === rule.id }">
						<td>{{ matchFieldLabel(rule.matchField) }}</td>
						<td>{{ rule.matchValue }}</td>
						<td>{{ ruleTarget(rule) }}</td>
						<td v-if="costCentersEnabled">
							{{ costCenterCode(rule.costCenterId) }}
						</td>
						<td class="num">
							{{ rule.priority }}
						</td>
						<td class="right nowrap">
							<div class="vbh-actions">
								<NcButton
									variant="tertiary"
									:aria-label="t('Regel bearbeiten')"
									:title="t('Bearbeiten')"
									@click="editRule(rule)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiPencil" :size="20" />
									</template>
								</NcButton>
								<NcButton
									variant="error"
									:aria-label="t('Regel löschen')"
									:title="t('Löschen')"
									@click="deleteRule(rule)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiDelete" :size="20" />
									</template>
								</NcButton>
							</div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<NcEmptyContent v-else :name="t('Keine Regeln')" :description="t('Legen Sie oben eine Regel an – oder erzeugen Sie sie im Journal direkt aus einer Bankbuchung.')" />
	</div>
</template>

<script>
import { mdiDelete, mdiPencil } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { toRef, toRefs } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import AmountInput from './AmountInput.vue'
import api from '../api.js'
import { buildAccountOptions, useAccounts } from '../composables/useAccounts.js'
import { useBookingCostCenters } from '../composables/useBookingCostCenters.js'
import { useConfirm } from '../composables/useConfirm.js'
import { useRules } from '../composables/useRules.js'
import { accountFilterBy, accountLabel } from '../lib/accountLabel.js'
import { errMsg, formatMoney } from '../lib/format.js'
import { newUid } from '../lib/rowKeys.js'
import { isSelectableOption } from '../lib/selectOptions.js'

/**
 * Auto-Zuordnungsregeln. Frueher SettingsRules.vue im Einstellungen-Modal;
 * jetzt Unterreiter „Regeln" von BookingsTab.vue, siehe
 * NAVIGATION-KONZEPT.md Abschnitt 4 – Regeln entstehen beim Zuordnen (das
 * "Regel anlegen"-Menue steht dort bereits), ihre Pflege gehoert daneben.
 *
 * Sichtbar fuer canWrite (Buchhalter und Verwalter), nicht nur Verwalter.
 */
export default {
	name: 'RulesPanel',
	components: { AmountInput, NcButton, NcSelect, NcEmptyContent, NcIconSvgWrapper },
	setup() {
		const accounts = useAccounts()
		const rulesC = useRules()
		const costCenters = useBookingCostCenters()
		return {
			accountsSorted: accounts.accountsSorted,
			accountsById: accounts.accountsById,
			...toRefs(rulesC.state),
			loadRules: rulesC.loadRules,
			costCentersEnabled: toRef(costCenters.state, 'enabled'),
			activeCostCenters: costCenters.activeBookingCostCenters,
			costCentersById: costCenters.bookingCostCentersById,
			askConfirm: useConfirm().askConfirm,
		}
	},

	data() {
		return {
			mdiPencil,
			mdiDelete,
			ruleForm: { matchField: 'counterparty', matchValue: '', contraAccountId: null, priority: 0, costCenterId: null, parts: [] },
			ruleEditId: null,
		}
	},

	computed: {
		accountUsageCounts() {
			const counts = {}
			for (const r of this.rules) {
				for (const id of [r.contraAccountId, ...(r.parts || []).map((p) => p.accountId)]) {
					counts[id] = (counts[id] || 0) + 1
				}
			}
			return counts
		},

		accountOptionsList() {
			return buildAccountOptions(this.accountsSorted, this.accountUsageCounts, this.t)
		},

		/** Aktive Kostenstellen, dazu die an der bearbeiteten Regel gespeicherte inaktive. */
		costCenterOptions() {
			return this.costCenterOptionsFor(this.ruleForm.costCenterId)
		},

		ruleFormContraOption: {
			get() {
				if (this.ruleForm.contraAccountId === null || this.ruleForm.contraAccountId === undefined) { return null }
				return this.accountOptionsList.find((o) => o.id === this.ruleForm.contraAccountId) ?? null
			},

			set(v) { this.ruleForm.contraAccountId = v ? v.id : null },
		},
	},

	mounted() {
		this.loadRules()
	},

	methods: {
		errMsg,
		accountLabel(id) {
			const acc = this.accountsById[id]
			return acc ? accountLabel(acc) : `#${id}`
		},

		// Suchfilter fuer das Konto-Dropdown (Ziffern = Praefix der Kontonummer)
		isSelectableOption,

		accountFilterBy,

		/** Aktive Kostenstellen, dazu die gespeicherte, falls sie inzwischen inaktiv ist. */
		costCenterOptionsFor(currentId) {
			const list = [...this.activeCostCenters]
			const current = this.costCentersById[currentId]
			if (current && !list.some((cc) => cc.id === current.id)) { list.push(current) }
			return list
		},

		partOption(part) {
			// Ohne Konto keine Auswahl - sonst traefe null die Gruppenueberschrift (id null).
			if (!part.accountId) { return null }
			return this.accountOptionsList.find((o) => o.id === part.accountId) ?? null
		},

		addPart() {
			this.ruleForm.parts.push({ uid: newUid(), accountId: null, amount: null, costCenterId: null })
		},

		/**
		 * Ziel der Regel für die Tabelle: das Gegenkonto, bei festen Anteilen
		 * (Issue #163) die ganze Aufteilung, z. B. „10,00 € → 4711 · Rest → 4000".
		 */
		ruleTarget(rule) {
			if (!rule.parts || !rule.parts.length) { return this.accountLabel(rule.contraAccountId) }
			const number = (id) => this.accountsById[id]?.number ?? `#${id}`
			const parts = rule.parts.map((p) => `${formatMoney(p.amount)} → ${number(p.accountId)}`)
			return [...parts, this.tRaw('Rest → {account}', { account: number(rule.contraAccountId) })].join(' · ')
		},

		costCenterCode(id) {
			if (!id) { return '' }
			return this.costCentersById[id]?.code ?? `#${id}`
		},

		matchFieldLabel(field) {
			return { counterparty: this.t('Zahlungspartner'), purpose: this.t('Verwendungszweck'), iban: this.t('IBAN') }[field] || field
		},

		resetRuleForm() {
			this.ruleEditId = null
			this.ruleForm = { matchField: 'counterparty', matchValue: '', contraAccountId: null, priority: 0, costCenterId: null, parts: [] }
		},

		editRule(rule) {
			this.ruleEditId = rule.id
			this.ruleForm = {
				matchField: rule.matchField,
				matchValue: rule.matchValue,
				contraAccountId: rule.contraAccountId,
				priority: rule.priority || 0,
				costCenterId: rule.costCenterId ?? null,
				parts: (rule.parts || []).map((p) => ({ uid: newUid(), accountId: p.accountId, amount: p.amount, costCenterId: p.costCenterId ?? null })),
			}
		},

		async saveRule() {
			const payload = {
				matchField: this.ruleForm.matchField,
				matchValue: (this.ruleForm.matchValue || '').trim(),
				contraAccountId: this.ruleForm.contraAccountId,
				priority: Number(this.ruleForm.priority) || 0,
				// Nur bei eingeschaltetem Modul mitschicken: fehlt das Feld, laesst der
				// Server eine gespeicherte Vorgabe stehen.
				...(this.costCentersEnabled ? { costCenterId: this.ruleForm.costCenterId ?? null } : {}),
				// Feste Anteile (Issue #163) immer mitschicken: eine leere Liste entfernt sie.
				parts: this.ruleForm.parts.map((p) => ({
					accountId: p.accountId,
					amount: Number(p.amount),
					// Ohne Modul kein Feld (der Server lehnte einen Wert ab); eine
					// gespeicherte Kostenstelle des Anteils entfaellt dann beim Speichern.
					...(this.costCentersEnabled ? { costCenterId: p.costCenterId ?? null } : {}),
				})),
			}
			if (!payload.matchValue) { showError(this.t('Bitte einen Suchtext eingeben.')); return }
			if (!payload.contraAccountId) { showError(this.t('Bitte ein Gegenkonto wählen.')); return }
			if (payload.parts.some((p) => !p.accountId || !(p.amount > 0))) { showError(this.t('Jedem Anteil fehlt noch Konto oder Betrag.')); return }
			try {
				if (this.ruleEditId) {
					await api.updateRule(this.ruleEditId, payload)
					showSuccess(this.t('Regel gespeichert.'))
				} else {
					await api.createRule(payload)
					showSuccess(this.t('Regel angelegt.'))
				}
				await this.loadRules()
				this.resetRuleForm()
			} catch (e) { showError(this.errMsg(e, this.t('Regel konnte nicht gespeichert werden'))) }
		},

		async deleteRule(rule) {
			const ok = await this.askConfirm(
				this.t('Regel löschen'),
				this.tRaw('Regel „{field} enthält {value} → {account}" wirklich löschen?', { field: this.matchFieldLabel(rule.matchField), value: rule.matchValue, account: this.accountLabel(rule.contraAccountId) }),
			)
			if (!ok) { return }
			try {
				await api.deleteRule(rule.id)
				if (this.ruleEditId === rule.id) { this.resetRuleForm() }
				await this.loadRules()
				showSuccess(this.t('Regel gelöscht.'))
			} catch (e) { showError(this.errMsg(e, this.t('Regel konnte nicht gelöscht werden'))) }
		},
	},
}
</script>
