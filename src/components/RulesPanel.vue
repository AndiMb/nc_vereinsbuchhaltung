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
				<label class="vbh-grow">{{ t('Gegenkonto') }}
					<NcSelect
						v-model="ruleFormContraOption"
						:options="accountOptionsList"
						:filterBy="accountFilterBy"
						label="label"
						:placeholder="t('– Konto wählen –')" />
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
		</div>

		<div v-if="rules.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Feld') }}</th><th>{{ t('Suchtext') }}</th><th>{{ t('Gegenkonto') }}</th><th class="num">
							{{ t('Prio.') }}
						</th><th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="rule in rules" :key="rule.id" :class="{ 'vbh-row-editing': ruleEditId === rule.id }">
						<td>{{ matchFieldLabel(rule.matchField) }}</td>
						<td>{{ rule.matchValue }}</td>
						<td>{{ accountLabel(rule.contraAccountId) }}</td>
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
		<NcEmptyContent v-else :name="t('Keine Regeln')" :description="t('Lege oben eine Regel an – oder erzeuge sie im Journal direkt aus einer Bankbuchung.')" />
	</div>
</template>

<script>
import { mdiDelete, mdiPencil } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcEmptyContent, NcIconSvgWrapper, NcSelect } from '@nextcloud/vue'
import { toRefs } from 'vue'
import api from '../api.js'
import { useAccounts } from '../composables/useAccounts.js'
import { useConfirm } from '../composables/useConfirm.js'
import { useRules } from '../composables/useRules.js'
import { errMsg } from '../lib/format.js'

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
	components: { NcButton, NcSelect, NcEmptyContent, NcIconSvgWrapper },
	setup() {
		const accounts = useAccounts()
		const rulesC = useRules()
		return {
			accountsSorted: accounts.accountsSorted,
			accountsById: accounts.accountsById,
			...toRefs(rulesC.state),
			loadRules: rulesC.loadRules,
			askConfirm: useConfirm().askConfirm,
		}
	},

	data() {
		return {
			mdiPencil,
			mdiDelete,
			ruleForm: { matchField: 'counterparty', matchValue: '', contraAccountId: null, priority: 0 },
			ruleEditId: null,
		}
	},

	computed: {
		// Gleiche Gruppierung wie in BookingsTab.vue (haeufig verwendete Konten
		// zuerst) - eigenstaendig statt geteilt, da beide Stellen geringfuegig
		// unterschiedliche Listen brauchen (siehe AccountDialog.vue fuer das
		// gleiche Muster).
		accountUsageCounts() {
			const counts = {}
			for (const r of this.rules) { counts[r.contraAccountId] = (counts[r.contraAccountId] || 0) + 1 }
			return counts
		},

		frequentAccounts() {
			const counts = this.accountUsageCounts
			return this.accountsSorted
				.filter((a) => a.active && counts[a.id])
				.sort((a, b) => counts[b.id] - counts[a.id])
				.slice(0, 5)
		},

		accountsByCategory() {
			const groups = {}
			for (const acc of this.accountsSorted) {
				if (!acc.active) { continue }
				const cat = acc.category || this.t('Sonstige')
				;(groups[cat] = groups[cat] || []).push(acc)
			}
			return groups
		},

		accountOptionsList() {
			const opts = []
			if (this.frequentAccounts.length >= 2) {
				opts.push({ id: null, label: this.t('★ Häufig verwendet'), $isDisabled: true })
				for (const acc of this.frequentAccounts) {
					opts.push({ id: acc.id, label: `${acc.number} ${acc.name}`, number: acc.number })
				}
			}
			for (const [cat, accounts] of Object.entries(this.accountsByCategory)) {
				opts.push({ id: null, label: cat, $isDisabled: true })
				for (const acc of accounts) {
					opts.push({ id: acc.id, label: `${acc.number} ${acc.name}`, number: acc.number })
				}
			}
			return opts
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
			return acc ? `${acc.number} ${acc.name}` : `#${id}`
		},

		// Suchfilter fuer das Konto-Dropdown (Ziffern = Praefix der Kontonummer)
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

		matchFieldLabel(field) {
			return { counterparty: this.t('Zahlungspartner'), purpose: this.t('Verwendungszweck'), iban: this.t('IBAN') }[field] || field
		},

		resetRuleForm() {
			this.ruleEditId = null
			this.ruleForm = { matchField: 'counterparty', matchValue: '', contraAccountId: null, priority: 0 }
		},

		editRule(rule) {
			this.ruleEditId = rule.id
			this.ruleForm = {
				matchField: rule.matchField,
				matchValue: rule.matchValue,
				contraAccountId: rule.contraAccountId,
				priority: rule.priority || 0,
			}
		},

		async saveRule() {
			const payload = {
				matchField: this.ruleForm.matchField,
				matchValue: (this.ruleForm.matchValue || '').trim(),
				contraAccountId: this.ruleForm.contraAccountId,
				priority: Number(this.ruleForm.priority) || 0,
			}
			if (!payload.matchValue) { showError(this.t('Bitte einen Suchtext eingeben.')); return }
			if (!payload.contraAccountId) { showError(this.t('Bitte ein Gegenkonto wählen.')); return }
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
				this.t('Regel „{field} enthält {value} → {account}" wirklich löschen?', { field: this.matchFieldLabel(rule.matchField), value: rule.matchValue, account: this.accountLabel(rule.contraAccountId) }),
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
