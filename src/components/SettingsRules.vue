<template>
	<div>
		<h3 class="vbh-section-divider">
			{{ t('Automatische Zuordnung (Regeln)') }}
		</h3>
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
					<input v-model="ruleForm.matchValue"
						type="text"
						:placeholder="t('z. B. Stadtwerke')"
						@keyup.enter="saveRule">
				</label>
				<label class="vbh-grow">{{ t('Gegenkonto') }}
					<NcSelect v-model="ruleFormContraOption"
						:options="accountOptionsList"
						:filter-by="accountFilterBy"
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
							<NcButton variant="tertiary"
								:aria-label="t('Regel bearbeiten')"
								:title="t('Bearbeiten')"
								@click="editRule(rule)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiPencil" :size="20" />
								</template>
							</NcButton>
							<NcButton variant="error"
								:aria-label="t('Regel löschen')"
								:title="t('Löschen')"
								@click="deleteRule(rule)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiDelete" :size="20" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<NcEmptyContent v-else :name="t('Keine Regeln')" :description="t('Lege oben eine Regel an – oder erzeuge sie im Tab „Buchungen“ direkt aus einer Bankbuchung.')" />
	</div>
</template>

<script>
import { NcButton, NcSelect, NcEmptyContent, NcIconSvgWrapper } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { mdiPencil, mdiDelete } from '@mdi/js'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { useConfirm } from '../composables/useConfirm.js'

export default {
	name: 'SettingsRules',
	components: { NcButton, NcSelect, NcEmptyContent, NcIconSvgWrapper },
	props: {
		// Regel-Liste (im Elternteil geladen, da auch fuer Zuordnungs-Vorschlaege genutzt)
		rules: { type: Array, required: true },
		// Kontostammdaten fuer Anzeige (accountLabel) und Gegenkonto-Auswahl
		accountsById: { type: Object, required: true },
		accountOptionsList: { type: Array, required: true },
	},
	setup() {
		return { askConfirm: useConfirm().askConfirm }
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
		ruleFormContraOption: {
			get() {
				if (this.ruleForm.contraAccountId == null) return null
				return this.accountOptionsList.find(o => o.id === this.ruleForm.contraAccountId) ?? null
			},
			set(v) { this.ruleForm.contraAccountId = v ? v.id : null },
		},
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
			if (!s) return true
			if (option && option.$isDisabled) return false
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
				this.$emit('changed')
				this.resetRuleForm()
			} catch (e) { showError(this.errMsg(e, this.t('Regel konnte nicht gespeichert werden'))) }
		},
		async deleteRule(rule) {
			const ok = await this.askConfirm(
				this.t('Regel löschen'),
				this.t('Regel „{field} enthält {value} → {account}" wirklich löschen?', { field: this.matchFieldLabel(rule.matchField), value: rule.matchValue, account: this.accountLabel(rule.contraAccountId) }),
			)
			if (!ok) return
			try {
				await api.deleteRule(rule.id)
				if (this.ruleEditId === rule.id) this.resetRuleForm()
				this.$emit('changed')
				showSuccess(this.t('Regel gelöscht.'))
			} catch (e) { showError(this.errMsg(e, this.t('Regel konnte nicht gelöscht werden'))) }
		},
	},
}
</script>

<style scoped>
/* NcButton-Icon-Fix wie in App.vue (scoped, damit er nicht in Nextclouds eigene
   .button-vue leckt), damit die Icon-Buttons hier identisch dargestellt werden. */
::v-deep .button-vue { display: inline-flex !important; }
::v-deep .button-vue__icon { display: flex !important; align-items: center; justify-content: center; }
::v-deep .button-vue__icon svg { display: block !important; }
</style>
