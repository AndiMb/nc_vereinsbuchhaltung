<template>
	<div>
		<p class="vbh-hint">
			{{ t('Ein Mitglied besteht hier aus zwei Angaben: seiner Bankverbindung (dem SEPA-Mandat) und seinem Beitrag. Eine eigene Mitgliederverwaltung führt die App bewusst nicht – wer keine Beiträge einzieht, braucht diesen Reiter nicht.') }}
		</p>

		<div class="vbh-form">
			<label class="vbh-grow">{{ t('Suchen') }}
				<input v-model="search" type="search" :placeholder="t('Name, IBAN oder E-Mail')">
			</label>
			<label>
				<input v-model="onlyProblems" type="checkbox">
				{{ t('nur Auffälligkeiten') }}
			</label>
		</div>

		<p v-if="rows.length" class="vbh-hint">
			{{ t('{gezeigt} von {gesamt} Einträgen · {mitMandat} mit Mandat · Beitragsaufkommen {summe} im Jahr', {
				gezeigt: filteredRows.length,
				gesamt: rows.length,
				mitMandat: rows.filter(r => r.mandate).length,
				summe: formatMoney(jahresSumme),
			}) }}
		</p>

		<div v-if="filteredRows.length && isMobile" class="vbh-cardlist">
			<MemberCard v-for="row in filteredRows"
				:key="row.key"
				:row="row"
				:editing="editing && row.fee && editing.id === row.fee.id ? editing : null"
				:frequencies="frequencies"
				:saving="saving"
				:is-used="!!(row.mandate && isUsed(row.mandate))"
				@toggle-active="toggleActive(row.fee, $event)"
				@catch-up="catchUp(row.fee)"
				@start-edit="startEdit(row.fee)"
				@save-edit="saveEdit"
				@cancel-edit="editing = null"
				@update-editing="editing = $event"
				@bank-change="openBankChange(row.mandate)"
				@revoke-mandate="revokeMandate(row.mandate)"
				@remove-fee="removeFee(row.fee)"
				@remove-mandate="removeMandate(row.mandate)" />
		</div>
		<div v-else-if="filteredRows.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Zahler') }}</th>
						<th>{{ t('Bankverbindung') }}</th>
						<th class="num">
							{{ t('Betrag') }}
						</th>
						<th>{{ t('Frequenz') }}</th>
						<th>{{ t('Nächste Fälligkeit') }}</th>
						<th>{{ t('Aktiv') }}</th>
						<th class="vbh-col-memberactions" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in filteredRows" :key="row.key">
						<td>
							{{ row.displayName }}
							<span v-if="!row.email" class="vbh-hint">{{ t('keine E-Mail – keine Vorankündigung möglich') }}</span>
						</td>
						<td class="nowrap">
							<template v-if="row.mandate">
								{{ row.mandate.iban }}
								<span v-if="row.mandate.status !== 'active'" class="vbh-typetag">{{ t('widerrufen') }}</span>
							</template>
							<span v-else class="vbh-hint">{{ t('kein Mandat') }}</span>
						</td>

						<template v-if="editing && row.fee && editing.id === row.fee.id">
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
							<td><input v-model="editing.active" type="checkbox"></td>
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
								{{ row.fee ? formatMoney(row.fee.amount) : '–' }}
							</td>
							<td>{{ row.fee ? frequencyLabel(row.fee.frequency) : '–' }}</td>
							<td class="nowrap">
								{{ row.fee ? row.fee.nextDueDate : '–' }}
								<span v-if="row.fee && row.fee.dueCount > 0" class="vbh-hint vbh-hint--warning">
									{{ n('%n Periode im Rückstand', '%n Perioden im Rückstand', row.fee.dueCount) }}
								</span>
							</td>
							<td>
								<input v-if="row.fee"
									type="checkbox"
									:checked="row.fee.active"
									@change="toggleActive(row.fee, $event.target.checked)">
								<span v-else>–</span>
							</td>
							<td class="nowrap right">
								<NcButton v-if="row.fee && row.fee.dueCount > 0"
									variant="secondary"
									size="small"
									@click="catchUp(row.fee)">
									{{ t('Nachholen') }}
								</NcButton>
								<NcButton v-if="row.fee"
									variant="tertiary"
									size="small"
									@click="startEdit(row.fee)">
									{{ t('Bearbeiten') }}
								</NcButton>
								<!-- Seltener genutzte Aktionen im Menue, sonst wird die Zeile
									durch bis zu vier weitere Icon-Buttons zu breit (dasselbe
									Muster wie im Buchungsjournal, siehe BookingsTab.vue). -->
								<NcActions v-if="row.fee || (row.mandate && (row.mandate.status === 'active' || !isUsed(row.mandate)))"
									:force-menu="true">
									<NcActionButton v-if="row.mandate && row.mandate.status === 'active'"
										@click="openBankChange(row.mandate)">
										<template #icon>
											<NcIconSvgWrapper :path="mdiBankTransfer" :size="16" />
										</template>
										{{ t('Bankverbindung wechseln') }}
									</NcActionButton>
									<NcActionButton v-if="row.mandate && row.mandate.status === 'active'"
										@click="revokeMandate(row.mandate)">
										<template #icon>
											<NcIconSvgWrapper :path="mdiCancel" :size="16" />
										</template>
										{{ t('Mandat widerrufen') }}
									</NcActionButton>
									<NcActionButton v-if="row.fee" @click="removeFee(row.fee)">
										<template #icon>
											<NcIconSvgWrapper :path="mdiDelete" :size="16" />
										</template>
										{{ t('Beitrag löschen') }}
									</NcActionButton>
									<NcActionButton v-else-if="row.mandate && !isUsed(row.mandate)" @click="removeMandate(row.mandate)">
										<template #icon>
											<NcIconSvgWrapper :path="mdiDelete" :size="16" />
										</template>
										{{ t('Mandat löschen') }}
									</NcActionButton>
								</NcActions>
							</td>
						</template>
					</tr>
				</tbody>
			</table>
		</div>
		<NcEmptyContent v-if="!filteredRows.length"
			:name="rows.length ? t('Kein Eintrag passt zur Suche.') : t('Noch kein Mitglied aufgenommen.')"
			:description="rows.length ? '' : t('Mit „＋ Mitglied“ oben ein erstes Mitglied anlegen, oder eine Liste als CSV einlesen.')" />

		<MemberDialog :show="memberDialogOpen"
			:saving="saving"
			:default-fee-amount="defaultFeeAmount"
			:default-fee-frequency="defaultFeeFrequency"
			@update:show="memberDialogOpen = $event"
			@close="memberDialogOpen = false"
			@save="createMember" />

		<MemberImportDialog :show="importDialogOpen"
			:default-fee-amount="defaultFeeAmount"
			:default-fee-frequency="defaultFeeFrequency"
			@update:show="importDialogOpen = $event"
			@close="importDialogOpen = false"
			@imported="reload" />

		<BankAccountChangeDialog :show.sync="bankChangeOpen"
			:mandate="bankChangeMandate"
			:saving="bankChangeSaving"
			@close="bankChangeOpen = false"
			@save="saveBankChange" />
	</div>
</template>

<script>
import { toRefs } from 'vue'
import { NcButton, NcActions, NcActionButton, NcEmptyContent, NcIconSvgWrapper } from '@nextcloud/vue'
import { mdiBankTransfer, mdiCancel, mdiDelete } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../api.js'
import { errMsg, formatMoney } from '../lib/format.js'
import BankAccountChangeDialog from './BankAccountChangeDialog.vue'
import MemberDialog from './MemberDialog.vue'
import MemberImportDialog from './MemberImportDialog.vue'
import { useMembershipFees } from '../composables/useMembershipFees.js'
import { useSepaMandates } from '../composables/useSepaMandates.js'
import { useConfirm } from '../composables/useConfirm.js'
import { FREQUENCY_MONTHS, frequencyLabel, frequencyOptions } from '../lib/frequency.js'
import MemberCard from './MemberCard.vue'

/**
 * Mitglieder als eine Liste: Mandat und Beitrag gehören zusammen und werden
 * hier auch zusammen gezeigt. Frueher SettingsMembers.vue im Einstellungen-
 * Modal; jetzt Unterreiter „Mitglieder" von ContributionsTab.vue, siehe
 * NAVIGATION-KONZEPT.md Abschnitt 4. Die beiden Formulare („Mitglied
 * aufnehmen", CSV-Import) leben seither in eigenen Dialogen
 * (MemberDialog.vue, MemberImportDialog.vue), die per $refs von der
 * Kopfzeile in ContributionsTab.vue geoeffnet werden.
 *
 * Erreichbar ab Rolle Buchhalter (siehe SepaMandateController).
 */
export default {
	name: 'MembersList',
	components: { NcButton, NcActions, NcActionButton, NcEmptyContent, NcIconSvgWrapper, BankAccountChangeDialog, MemberDialog, MemberImportDialog, MemberCard },
	props: {
		isMobile: { type: Boolean, default: false },
		defaultFeeAmount: { type: [Number, String], default: '' },
		defaultFeeFrequency: { type: String, default: 'yearly' },
	},
	setup() {
		const membershipFees = useMembershipFees()
		const sepaMandates = useSepaMandates()
		return {
			...toRefs(membershipFees.state),
			...toRefs(sepaMandates.state),
			loadMembershipFees: membershipFees.loadMembershipFees,
			loadSepaMandates: sepaMandates.loadSepaMandates,
			askConfirm: useConfirm().askConfirm,
		}
	},
	data() {
		return {
			saving: false,
			editing: null,
			search: '',
			onlyProblems: false,
			frequencies: frequencyOptions(),
			memberDialogOpen: false,
			importDialogOpen: false,
			bankChangeOpen: false,
			bankChangeMandate: null,
			bankChangeSaving: false,
			mdiBankTransfer,
			mdiCancel,
			mdiDelete,
		}
	},
	computed: {
		/**
		 * Mandate und Beiträge zu einer Liste verschmolzen. Schlüssel ist der
		 * Zahler; hat jemand mehrere Beiträge, bekommt er je Beitrag eine Zeile.
		 */
		rows() {
			const key = x => (x.memberUid ? `u:${x.memberUid}` : `l:${x.memberLabel}`)
			const mandateFor = new Map()
			for (const m of this.sepaMandates) {
				// Ein aktives Mandat sticht ein widerrufenes: gezeigt wird das,
				// mit dem tatsaechlich eingezogen wird.
				const vorhanden = mandateFor.get(key(m))
				if (!vorhanden || (vorhanden.status !== 'active' && m.status === 'active')) mandateFor.set(key(m), m)
			}

			const zeilen = []
			const behandelt = new Set()
			for (const fee of this.membershipFees) {
				const k = key(fee)
				behandelt.add(k)
				const mandate = fee.mandateId
					? this.sepaMandates.find(m => m.id === fee.mandateId)
					: mandateFor.get(k)
				zeilen.push({
					key: `fee-${fee.id}`,
					displayName: fee.displayName,
					email: mandate?.email || null,
					mandate: mandate || null,
					fee,
				})
			}
			// Mandate ohne Beitrag duerfen nicht verschwinden - sonst faende
			// niemand mehr das Mandat, das er gerade angelegt hat.
			for (const m of this.sepaMandates) {
				if (behandelt.has(key(m))) continue
				zeilen.push({
					key: `mandate-${m.id}`,
					displayName: m.displayName,
					email: m.email || null,
					mandate: m,
					fee: null,
				})
			}
			return zeilen.sort((a, b) => a.displayName.localeCompare(b.displayName, 'de'))
		},
		filteredRows() {
			const suche = this.search.trim().toLowerCase()
			return this.rows.filter(r => {
				if (this.onlyProblems && !this.hasProblem(r)) return false
				if (!suche) return true
				return [r.displayName, r.email, r.mandate?.iban, r.mandate?.mandateReference]
					.filter(Boolean)
					.some(v => String(v).toLowerCase().includes(suche))
			})
		},
		/** Beitragsaufkommen aufs Jahr hochgerechnet – nur aktive Beiträge. */
		jahresSumme() {
			return this.rows.reduce((summe, r) => {
				if (!r.fee || !r.fee.active) return summe
				return summe + r.fee.amount * (12 / (FREQUENCY_MONTHS[r.fee.frequency] || 12))
			}, 0)
		},
	},
	mounted() {
		this.loadMembershipFees()
		this.loadSepaMandates()
	},
	methods: {
		errMsg,
		formatMoney,
		frequencyLabel,
		/** Von der Kopfzeile in ContributionsTab.vue per $refs aufgerufen. */
		openMemberDialog() { this.memberDialogOpen = true },
		openImportDialog() { this.importDialogOpen = true },
		/** Was der Verwalter sehen sollte: fehlende Adresse, Rückstand, kein Mandat. */
		hasProblem(row) {
			if (row.fee && row.fee.dueCount > 0) return true
			if (row.fee && row.fee.active && !row.mandate) return true
			return !row.email
		},
		isUsed(m) {
			const u = m.usage || {}
			return (u.batchItems || 0) + (u.fees || 0) + (u.openItems || 0) > 0
		},
		async reload() {
			await Promise.all([this.loadMembershipFees(), this.loadSepaMandates()])
		},

		/**
		 * Legt Mandat und Beitrag zusammen an. Schlägt der Beitrag fehl, bleibt
		 * das Mandat bestehen - deshalb sagt die Meldung ausdrücklich, was
		 * entstanden ist, statt nur „fehlgeschlagen".
		 */
		async createMember(form) {
			this.saving = true
			let mandateId = null
			try {
				if (form.iban) {
					const { data } = await api.createSepaMandate({
						memberUid: form.memberUid,
						memberLabel: form.memberLabel,
						iban: form.iban,
						bic: form.bic,
						email: form.email,
						mandateType: 'RCUR',
						signedDate: form.signedDate,
					})
					mandateId = data.id
				}
				if (Number(form.amount) > 0) {
					await api.createMembershipFee({
						memberUid: form.memberUid,
						memberLabel: form.memberLabel,
						amount: Number(form.amount),
						frequency: form.frequency,
						startDate: form.startDate,
						accountId: form.accountId,
						mandateId,
					})
				}
				this.memberDialogOpen = false
				await this.reload()
				showSuccess(this.t('Mitglied aufgenommen.'))
			} catch (e) {
				await this.reload()
				showError(this.errMsg(e, mandateId
					? this.t('Das Mandat wurde angelegt, der Beitrag nicht')
					: this.t('Mitglied konnte nicht aufgenommen werden')))
			} finally { this.saving = false }
		},

		startEdit(fee) {
			this.editing = {
				id: fee.id,
				amount: fee.amount,
				frequency: fee.frequency,
				nextDueDate: fee.nextDueDate,
				active: fee.active,
				accountId: fee.accountId,
				mandateId: fee.mandateId,
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
		async catchUp(fee) {
			if (!await this.askConfirm(
				this.t('Rückstand nachholen'),
				this.n(
					'Für diesen Beitrag fehlt noch %n offener Posten. Soll er jetzt erzeugt werden?',
					'Für diesen Beitrag fehlen noch %n offene Posten. Sollen sie jetzt alle erzeugt werden?',
					fee.dueCount,
				),
				this.t('Erzeugen'), 'primary',
			)) return
			try {
				const { data } = await api.catchUpMembershipFee(fee.id)
				await this.loadMembershipFees()
				showSuccess(this.n('%n offener Posten erzeugt.', '%n offene Posten erzeugt.', data.created))
			} catch (e) { showError(this.errMsg(e, this.t('Nachholen fehlgeschlagen'))) }
		},
		async removeFee(fee) {
			if (!await this.askConfirm(this.t('Beitrag löschen'), this.t('Beitrag für „{name}" endgültig löschen? Das Mandat bleibt bestehen.', { name: fee.displayName }))) return
			try {
				await api.deleteMembershipFee(fee.id)
				await this.loadMembershipFees()
				showSuccess(this.t('Beitrag gelöscht.'))
			} catch (e) { showError(this.errMsg(e, this.t('Löschen fehlgeschlagen'))) }
		},
		openBankChange(mandate) {
			this.bankChangeMandate = mandate
			this.bankChangeOpen = true
		},
		/**
		 * Widerruft das alte Mandat und legt ein neues an; Beiträge und noch
		 * offene Posten hängen dabei serverseitig automatisch um (siehe
		 * SepaMandateService::changeBankAccount()) - ohne das fielen sie beim
		 * nächsten Einzug sonst kommentarlos aus der Vorschau.
		 */
		async saveBankChange(data) {
			this.bankChangeSaving = true
			try {
				await api.changeSepaMandateBankAccount(this.bankChangeMandate.id, data)
				this.bankChangeOpen = false
				await this.reload()
				showSuccess(this.t('Bankverbindung gewechselt.'))
			} catch (e) { showError(this.errMsg(e, this.t('Wechseln fehlgeschlagen'))) } finally { this.bankChangeSaving = false }
		},
		async revokeMandate(mandate) {
			if (!await this.askConfirm(this.t('Mandat widerrufen'), this.t('Mandat für „{name}" widerrufen? Es wird danach nicht mehr für neue Einzüge verwendet.', { name: mandate.displayName }))) return
			try {
				await api.revokeSepaMandate(mandate.id)
				await this.loadSepaMandates()
				showSuccess(this.t('Mandat widerrufen.'))
			} catch (e) { showError(this.errMsg(e, this.t('Widerrufen fehlgeschlagen'))) }
		},
		async removeMandate(mandate) {
			if (!await this.askConfirm(this.t('Mandat löschen'), this.t('Mandat für „{name}" endgültig löschen?', { name: mandate.displayName }))) return
			try {
				await api.deleteSepaMandate(mandate.id)
				await this.loadSepaMandates()
				showSuccess(this.t('Mandat gelöscht.'))
			} catch (e) { showError(this.errMsg(e, this.t('Löschen fehlgeschlagen'))) }
		},
	},
}
</script>
