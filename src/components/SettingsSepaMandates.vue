<template>
	<div>
		<h3 class="vbh-section-divider">
			{{ t('SEPA-Lastschriftmandate') }}
		</h3>
		<p class="vbh-hint">
			{{ t('Rein optionales Zusatzmodul für Vereine, die Mitgliedsbeiträge per Lastschrift einziehen. Ein Mandat gehört entweder zu einem Nextcloud-Konto oder zu einem frei benannten Zahler – letzteres etwa für Verbände, die nur Beitragsanteile von Untergliederungen einziehen und keine individuellen Mitglieder führen. Wer das nicht braucht, kann diesen Abschnitt einfach ignorieren.') }}
		</p>

		<div class="vbh-card">
			<h4>{{ t('Grundeinstellungen') }}</h4>
			<p class="vbh-hint">
				{{ t('Gläubiger-ID und einziehendes Konto werden für den späteren SEPA-XML-Export gebraucht (noch nicht Teil dieser Version).') }}
			</p>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('SEPA-Gläubiger-ID') }}
					<input v-model="sepaCreditorIdModel" placeholder="DE98ZZZ09999999999">
				</label>
				<label class="vbh-grow">{{ t('Einziehendes Konto') }}
					<select v-model="sepaDebtorAccountIdModel">
						<option :value="null">
							{{ t('– Konto wählen –') }}
						</option>
						<option v-for="a in bankAccounts" :key="a.id" :value="a.id">
							{{ a.number }} · {{ a.name }}
						</option>
					</select>
				</label>
				<NcButton variant="primary" :disabled="storageSaving" @click="saveSettings">
					{{ t('Speichern') }}
				</NcButton>
			</div>
		</div>

		<div class="vbh-card">
			<h4>{{ t('Neues Mandat') }}</h4>
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
				<label class="vbh-grow">{{ t('IBAN') }}
					<input v-model="form.iban" placeholder="DE12 5001 0517 0648 4898 90">
				</label>
				<label>{{ t('BIC') }}
					<input v-model="form.bic" class="vbh-short" placeholder="optional">
				</label>
				<label>{{ t('Mandatsart') }}
					<select v-model="form.mandateType">
						<option value="RCUR">{{ t('Wiederkehrend') }}</option>
						<option value="OOFF">{{ t('Einmalig') }}</option>
					</select>
				</label>
				<label>{{ t('Unterschrieben am') }}
					<input v-model="form.signedDate" type="date">
				</label>
				<NcButton variant="primary" :disabled="!canSave || saving" @click="createMandate">
					{{ t('Anlegen') }}
				</NcButton>
			</div>
		</div>

		<div v-if="sepaMandates.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Zahler') }}</th>
						<th>{{ t('IBAN') }}</th>
						<th>{{ t('Mandatsreferenz') }}</th>
						<th>{{ t('Art') }}</th>
						<th>{{ t('Status') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="m in sepaMandates" :key="m.id">
						<td>{{ m.displayName }}</td>
						<td class="nowrap">
							{{ m.iban }}
						</td>
						<td class="nowrap">
							{{ m.mandateReference }}
						</td>
						<td>{{ m.mandateType === 'RCUR' ? t('wiederkehrend') : t('einmalig') }}</td>
						<td>
							<span class="vbh-typetag">{{ m.status === 'active' ? t('aktiv') : t('widerrufen') }}</span>
						</td>
						<td class="nowrap right">
							<NcButton v-if="m.status === 'active'"
								variant="tertiary"
								size="small"
								@click="revoke(m)">
								{{ t('Widerrufen') }}
							</NcButton>
							<NcButton variant="error"
								size="small"
								:aria-label="t('Mandat löschen')"
								@click="remove(m)">
								{{ t('Löschen') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<p v-else class="vbh-hint">
			{{ t('Noch kein Mandat angelegt.') }}
		</p>
	</div>
</template>

<script>
import { toRefs } from 'vue'
import { NcButton, NcSelect } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { useSepaMandates } from '../composables/useSepaMandates.js'
import { usePermissions } from '../composables/usePermissions.js'
import { useAccounts } from '../composables/useAccounts.js'
import { useConfirm } from '../composables/useConfirm.js'

function emptyForm() {
	return {
		memberKind: 'user',
		memberUid: '',
		memberLabel: '',
		iban: '',
		bic: '',
		mandateType: 'RCUR',
		signedDate: new Date().toISOString().slice(0, 10),
	}
}

/**
 * Pflege der SEPA-Lastschriftmandate. Nur für Verwalter erreichbar (siehe
 * SepaMandateController) – dieselbe Einstufung wie die Rechtevergabe, weil
 * ein Mandat ein Nextcloud-Konto mit einer IBAN verknüpft.
 */
export default {
	name: 'SettingsSepaMandates',
	components: { NcButton, NcSelect },
	props: {
		sepaCreditorId: { type: String, default: '' },
		sepaDebtorAccountId: { type: Number, default: null },
		storageSaving: { type: Boolean, default: false },
		// gemeinsame Speichern-Funktion des Elternteils, siehe SettingsGeneral.vue
		saveSettings: { type: Function, required: true },
	},
	setup() {
		const sepaMandates = useSepaMandates()
		const permissions = usePermissions()
		const accounts = useAccounts()
		return {
			...toRefs(sepaMandates.state),
			// nur die Nutzerliste wird gebraucht (fuer den Zahler-Picker); sie
			// wird von SettingsPermissions.vue ohnehin schon fuer Verwalter
			// geladen (App.vue::mounted/openSettings), hier nur mitgelesen.
			...toRefs(permissions.state),
			...toRefs(accounts.state),
			loadSepaMandates: sepaMandates.loadSepaMandates,
			askConfirm: useConfirm().askConfirm,
		}
	},
	data() {
		return {
			form: emptyForm(),
			saving: false,
		}
	},
	computed: {
		userOptions() { return this.users.map(u => ({ id: u.id, label: `${u.displayName} (${u.id})` })) },
		formMemberOption: {
			get() { return this.userOptions.find(o => o.id === this.form.memberUid) ?? null },
			set(v) { this.form.memberUid = v ? v.id : '' },
		},
		canSave() {
			const hasMember = this.form.memberKind === 'user' ? !!this.form.memberUid : !!this.form.memberLabel.trim()
			return hasMember && !!this.form.iban.trim() && !!this.form.signedDate
		},
		bankAccounts() { return this.accounts.filter(a => a.isBank) },
		sepaCreditorIdModel: {
			get() { return this.sepaCreditorId },
			set(v) { this.$emit('update:sepaCreditorId', v) },
		},
		sepaDebtorAccountIdModel: {
			get() { return this.sepaDebtorAccountId },
			set(v) { this.$emit('update:sepaDebtorAccountId', v) },
		},
	},
	mounted() {
		this.loadSepaMandates()
	},
	methods: {
		errMsg,
		async createMandate() {
			this.saving = true
			try {
				await api.createSepaMandate({
					memberUid: this.form.memberKind === 'user' ? this.form.memberUid : null,
					memberLabel: this.form.memberKind === 'label' ? this.form.memberLabel.trim() : null,
					iban: this.form.iban.trim(),
					bic: this.form.bic.trim() || null,
					mandateType: this.form.mandateType,
					signedDate: this.form.signedDate,
				})
				this.form = emptyForm()
				await this.loadSepaMandates()
				showSuccess(this.t('Mandat angelegt.'))
			} catch (e) { showError(this.errMsg(e, this.t('Mandat konnte nicht angelegt werden'))) } finally { this.saving = false }
		},
		async revoke(m) {
			if (!await this.askConfirm(this.t('Mandat widerrufen'), this.t('Mandat für „{name}" widerrufen? Es wird danach nicht mehr für neue Einzüge verwendet.', { name: m.displayName }))) return
			try {
				await api.revokeSepaMandate(m.id)
				await this.loadSepaMandates()
				showSuccess(this.t('Mandat widerrufen.'))
			} catch (e) { showError(this.errMsg(e, this.t('Widerrufen fehlgeschlagen'))) }
		},
		async remove(m) {
			if (!await this.askConfirm(this.t('Mandat löschen'), this.t('Mandat für „{name}" endgültig löschen?', { name: m.displayName }))) return
			try {
				await api.deleteSepaMandate(m.id)
				await this.loadSepaMandates()
				showSuccess(this.t('Mandat gelöscht.'))
			} catch (e) { showError(this.errMsg(e, this.t('Löschen fehlgeschlagen'))) }
		},
	},
}
</script>
