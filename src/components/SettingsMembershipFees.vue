<template>
	<div>
		<h3 class="vbh-section-divider">
			{{ t('Mitgliedsbeiträge') }}
		</h3>
		<p class="vbh-hint">
			{{ t('Wiederkehrende Beiträge mit Zahlungsfrequenz. Bei Fälligkeit legt die App automatisch einen offenen Posten an (Berichte → Offene Posten) – unabhängig davon, ob ein SEPA-Mandat verknüpft ist. Ohne Mandat ist der Posten einfach eine Erinnerung, z. B. für eine erwartete Überweisung.') }}
		</p>

		<div class="vbh-card">
			<h4>{{ t('Neuer Beitrag') }}</h4>
			<div class="vbh-form">
				<label>{{ t('Zahler') }}
					<select v-model="form.memberKind">
						<option value="user">{{ t('Nextcloud-Nutzer') }}</option>
						<option value="label">{{ t('Freier Zahlername') }}</option>
					</select>
				</label>
				<label v-if="form.memberKind === 'user'" class="vbh-grow">{{ t('Nutzer') }}
					<NcSelect v-model="formMemberOption"
						:options="userOptions"
						label="label"
						:placeholder="t('– Nutzer wählen –')" />
				</label>
				<label v-else class="vbh-grow">{{ t('Name') }}
					<input v-model="form.memberLabel" :placeholder="t('z. B. Untergliederung Nord')">
				</label>
			</div>
			<div class="vbh-form">
				<label>{{ t('Betrag (€)') }}
					<input v-model="form.amount"
						type="number"
						step="0.01"
						min="0"
						class="vbh-short">
				</label>
				<label>{{ t('Frequenz') }}
					<select v-model="form.frequency">
						<option v-for="f in frequencies" :key="f.value" :value="f.value">
							{{ f.label }}
						</option>
					</select>
				</label>
				<label>{{ t('Start') }}
					<input v-model="form.startDate" type="date">
				</label>
				<label class="vbh-grow">{{ t('Ertragskonto') }}
					<select v-model="form.accountId">
						<option :value="null">
							{{ t('– optional –') }}
						</option>
						<option v-for="a in incomeAccounts" :key="a.id" :value="a.id">
							{{ a.number }} · {{ a.name }}
						</option>
					</select>
				</label>
				<label class="vbh-grow">{{ t('SEPA-Mandat') }}
					<select v-model="form.mandateId">
						<option :value="null">
							{{ t('– keins –') }}
						</option>
						<option v-for="m in matchingMandates" :key="m.id" :value="m.id">
							{{ m.mandateReference }}
						</option>
					</select>
				</label>
				<NcButton variant="primary" :disabled="!canSave || saving" @click="createFee">
					{{ t('Anlegen') }}
				</NcButton>
			</div>
		</div>

		<div v-if="membershipFees.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Zahler') }}</th>
						<th class="num">
							{{ t('Betrag') }}
						</th>
						<th>{{ t('Frequenz') }}</th>
						<th>{{ t('Nächste Fälligkeit') }}</th>
						<th>{{ t('SEPA-Mandat') }}</th>
						<th>{{ t('Aktiv') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="fee in membershipFees" :key="fee.id">
						<td>{{ fee.displayName }}</td>
						<template v-if="editing && editing.id === fee.id">
							<td class="num">
								<input v-model="editing.amount"
									type="number"
									step="0.01"
									min="0"
									class="vbh-short">
							</td>
							<td>
								<select v-model="editing.frequency">
									<option v-for="f in frequencies" :key="f.value" :value="f.value">
										{{ f.label }}
									</option>
								</select>
							</td>
							<td><input v-model="editing.nextDueDate" type="date"></td>
							<td>
								<select v-model="editing.mandateId">
									<option :value="null">
										{{ t('– keins –') }}
									</option>
									<option v-for="m in mandatesFor(fee)" :key="m.id" :value="m.id">
										{{ m.mandateReference }}
									</option>
								</select>
							</td>
							<td>
								<input v-model="editing.active" type="checkbox">
							</td>
							<td class="nowrap right">
								<NcButton variant="primary"
									size="small"
									:disabled="saving"
									@click="saveEdit">
									{{ t('Speichern') }}
								</NcButton>
								<NcButton variant="tertiary" size="small" @click="editing = null">
									{{ t('Abbrechen') }}
								</NcButton>
							</td>
						</template>
						<template v-else>
							<td class="num nowrap">
								{{ formatMoney(fee.amount) }}
							</td>
							<td>{{ frequencyLabel(fee.frequency) }}</td>
							<td class="nowrap">
								{{ fee.nextDueDate }}
							</td>
							<td class="nowrap">
								{{ mandateReference(fee) }}
							</td>
							<td>
								<input type="checkbox" :checked="fee.active" @change="toggleActive(fee, $event.target.checked)">
							</td>
							<td class="nowrap right">
								<NcButton variant="tertiary" size="small" @click="startEdit(fee)">
									{{ t('Bearbeiten') }}
								</NcButton>
								<NcButton variant="error"
									size="small"
									:aria-label="t('Beitrag löschen')"
									@click="remove(fee)">
									{{ t('Löschen') }}
								</NcButton>
							</td>
						</template>
					</tr>
				</tbody>
			</table>
		</div>
		<p v-else class="vbh-hint">
			{{ t('Noch kein Beitrag angelegt.') }}
		</p>
	</div>
</template>

<script>
import { toRefs } from 'vue'
import { NcButton, NcSelect } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../api.js'
import { errMsg, formatMoney } from '../lib/format.js'
import { useMembershipFees } from '../composables/useMembershipFees.js'
import { useSepaMandates } from '../composables/useSepaMandates.js'
import { usePermissions } from '../composables/usePermissions.js'
import { useAccounts } from '../composables/useAccounts.js'
import { useConfirm } from '../composables/useConfirm.js'
import { t } from '../lib/l10n.js'

function emptyForm() {
	return {
		memberKind: 'user',
		memberUid: '',
		memberLabel: '',
		amount: '',
		frequency: 'monthly',
		startDate: new Date().toISOString().slice(0, 10),
		accountId: null,
		mandateId: null,
	}
}

function frequencyLabels() {
	return {
		monthly: t('monatlich'),
		quarterly: t('vierteljährlich'),
		semiannual: t('halbjährlich'),
		yearly: t('jährlich'),
	}
}

/**
 * Pflege der Mitgliedsbeiträge mit Zahlungsfrequenz. Nur für Verwalter
 * erreichbar (siehe MembershipFeeController), dieselbe Einstufung wie die
 * SEPA-Mandate.
 */
export default {
	name: 'SettingsMembershipFees',
	components: { NcButton, NcSelect },
	setup() {
		const membershipFees = useMembershipFees()
		const sepaMandates = useSepaMandates()
		const permissions = usePermissions()
		const accounts = useAccounts()
		return {
			...toRefs(membershipFees.state),
			...toRefs(sepaMandates.state),
			...toRefs(permissions.state),
			...toRefs(accounts.state),
			loadMembershipFees: membershipFees.loadMembershipFees,
			loadSepaMandates: sepaMandates.loadSepaMandates,
			askConfirm: useConfirm().askConfirm,
		}
	},
	data() {
		return {
			form: emptyForm(),
			saving: false,
			// Zeile, die gerade bearbeitet wird (Kopie, damit Abbrechen wirklich
			// abbricht), oder null.
			editing: null,
			frequencies: Object.entries(frequencyLabels()).map(([value, label]) => ({ value, label })),
		}
	},
	computed: {
		userOptions() { return this.users.map(u => ({ id: u.id, label: `${u.displayName} (${u.id})` })) },
		formMemberOption: {
			get() { return this.userOptions.find(o => o.id === this.form.memberUid) ?? null },
			set(v) { this.form.memberUid = v ? v.id : '' },
		},
		incomeAccounts() {
			return this.accounts.filter(a => a.type === 'income' && !a.isBank)
				.slice()
				.sort((a, b) => String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
		},
		matchingMandates() {
			return this.sepaMandates.filter(m => {
				if (m.status !== 'active') return false
				return this.form.memberKind === 'user' ? m.memberUid === this.form.memberUid : m.memberLabel === this.form.memberLabel.trim()
			})
		},
		canSave() {
			const hasMember = this.form.memberKind === 'user' ? !!this.form.memberUid : !!this.form.memberLabel.trim()
			return hasMember && Number(this.form.amount) > 0 && !!this.form.startDate
		},
	},
	mounted() {
		this.loadMembershipFees()
		this.loadSepaMandates()
	},
	methods: {
		errMsg,
		formatMoney,
		frequencyLabel(f) { return frequencyLabels()[f] || f },
		mandateReference(fee) {
			return this.sepaMandates.find(m => m.id === fee.mandateId)?.mandateReference || '–'
		},
		/** Mandate desselben Zahlers – ein fremdes lehnt das Backend ohnehin ab. */
		mandatesFor(fee) {
			return this.sepaMandates.filter(m => (m.status === 'active' || m.id === fee.mandateId)
				&& m.memberUid === fee.memberUid && m.memberLabel === fee.memberLabel)
		},
		startEdit(fee) {
			this.editing = {
				id: fee.id,
				amount: fee.amount,
				frequency: fee.frequency,
				nextDueDate: fee.nextDueDate,
				mandateId: fee.mandateId,
				active: fee.active,
				accountId: fee.accountId,
			}
		},
		async saveEdit() {
			this.saving = true
			try {
				await api.updateMembershipFee(this.editing.id, {
					amount: Number(this.editing.amount),
					frequency: this.editing.frequency,
					accountId: this.editing.accountId,
					mandateId: this.editing.mandateId,
					active: this.editing.active,
					nextDueDate: this.editing.nextDueDate,
				})
				this.editing = null
				await this.loadMembershipFees()
				showSuccess(this.t('Beitrag gespeichert.'))
			} catch (e) { showError(this.errMsg(e, this.t('Speichern fehlgeschlagen'))) } finally { this.saving = false }
		},
		async createFee() {
			this.saving = true
			try {
				await api.createMembershipFee({
					memberUid: this.form.memberKind === 'user' ? this.form.memberUid : null,
					memberLabel: this.form.memberKind === 'label' ? this.form.memberLabel.trim() : null,
					amount: Number(this.form.amount),
					frequency: this.form.frequency,
					startDate: this.form.startDate,
					accountId: this.form.accountId,
					mandateId: this.form.mandateId,
				})
				this.form = emptyForm()
				await this.loadMembershipFees()
				showSuccess(this.t('Beitrag angelegt.'))
			} catch (e) { showError(this.errMsg(e, this.t('Beitrag konnte nicht angelegt werden'))) } finally { this.saving = false }
		},
		async toggleActive(fee, active) {
			try {
				await api.updateMembershipFee(fee.id, {
					amount: fee.amount,
					frequency: fee.frequency,
					accountId: fee.accountId,
					mandateId: fee.mandateId,
					active,
					nextDueDate: fee.nextDueDate,
				})
				await this.loadMembershipFees()
			} catch (e) { showError(this.errMsg(e, this.t('Speichern fehlgeschlagen'))) }
		},
		async remove(fee) {
			if (!await this.askConfirm(this.t('Beitrag löschen'), this.t('Beitrag für „{name}" endgültig löschen?', { name: fee.displayName }))) return
			try {
				await api.deleteMembershipFee(fee.id)
				await this.loadMembershipFees()
				showSuccess(this.t('Beitrag gelöscht.'))
			} catch (e) { showError(this.errMsg(e, this.t('Löschen fehlgeschlagen'))) }
		},
	},
}
</script>
