<template>
	<div>
		<p class="vbh-hint">
			{{ t('Ein Mitglied wird unabhängig von einer Bankverbindung geführt – SEPA-Mandat und Beitrag sind optionale Ergänzungen, die sich jederzeit über „Aufnehmen" bzw. die Akte nachtragen lassen.') }}
		</p>

		<div class="vbh-form">
			<label class="vbh-grow">{{ t('Suchen') }}
				<input v-model="search" type="search" :placeholder="t('Name, IBAN, Mitgliedsnummer oder E-Mail')">
			</label>
			<label>
				<input v-model="onlyProblems" type="checkbox">
				{{ t('nur Auffälligkeiten') }}
			</label>
		</div>

		<p v-if="rows.length" class="vbh-hint">
			{{ t('{gezeigt} von {gesamt} Mitgliedern · {mitMandat} mit Mandat · Beitragsaufkommen {summe} im Jahr', {
				gezeigt: filteredRows.length,
				gesamt: rows.length,
				mitMandat: rows.filter(r => r.mandate).length,
				summe: formatMoney(jahresSumme),
			}) }}
		</p>

		<div v-if="filteredRows.length && isMobile" class="vbh-cardlist">
			<MemberCard
				v-for="row in filteredRows"
				:key="row.key"
				:row="row"
				:editing="editing && row.fee && editing.id === row.fee.id ? editing : null"
				:frequencies="frequencies"
				:saving="saving"
				:isUsed="!!(row.mandate && isUsed(row.mandate))"
				@toggleActive="toggleActive(row.fee, $event)"
				@catchUp="catchUp(row.fee)"
				@startEdit="startEdit(row.fee)"
				@saveEdit="saveEdit"
				@cancelEdit="editing = null"
				@updateEditing="editing = $event"
				@bankChange="openBankChange(row.mandate)"
				@revokeMandate="revokeMandate(row.mandate)"
				@removeFee="removeFee(row.fee)"
				@removeMandate="removeMandate(row.mandate)"
				@openMember="openMemberAkte(row.member)" />
		</div>
		<div v-else-if="filteredRows.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>{{ t('Mitglied') }}</th>
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
							<span v-if="row.member.memberNumber" class="vbh-hint">#{{ row.member.memberNumber }}</span>
							<span v-if="!row.member.active" class="vbh-typetag">{{ t('ausgetreten') }}</span>
							<br v-if="!row.email">
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
								<AmountInput
									v-model="editing.amount"
									class="vbh-short" />
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
								<div class="vbh-actions">
									<NcButton
										variant="primary"
										size="small"
										:disabled="saving"
										@click="saveEdit">
										{{ t('Speichern') }}
									</NcButton>
									<NcButton variant="tertiary" size="small" @click="editing = null">
										{{ t('Abbrechen') }}
									</NcButton>
								</div>
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
								<input
									v-if="row.fee"
									type="checkbox"
									:checked="row.fee.active"
									@change="toggleActive(row.fee, $event.target.checked)">
								<span v-else>–</span>
							</td>
							<td class="nowrap right">
								<div class="vbh-actions">
									<NcButton
										v-if="row.fee && row.fee.dueCount > 0"
										variant="secondary"
										size="small"
										@click="catchUp(row.fee)">
										{{ t('Nachholen') }}
									</NcButton>
									<NcButton
										v-if="row.fee"
										variant="tertiary"
										size="small"
										:aria-label="t('Beitrag bearbeiten')"
										:title="t('Bearbeiten')"
										@click="startEdit(row.fee)">
										<template #icon>
											<NcIconSvgWrapper :path="mdiPencil" :size="20" />
										</template>
									</NcButton>
									<!-- Seltener genutzte Aktionen im Menue, sonst wird die Zeile
										durch bis zu vier weitere Icon-Buttons zu breit (dasselbe
										Muster wie im Buchungsjournal, siehe BookingsTab.vue). -->
									<NcActions :forceMenu="true">
										<NcActionButton @click="openMemberAkte(row.member)">
											<template #icon>
												<NcIconSvgWrapper :path="mdiAccountEdit" :size="16" />
											</template>
											{{ t('Akte öffnen') }}
										</NcActionButton>
										<NcActionButton
											v-if="row.mandate && row.mandate.status === 'active'"
											@click="openBankChange(row.mandate)">
											<template #icon>
												<NcIconSvgWrapper :path="mdiBankTransfer" :size="16" />
											</template>
											{{ t('Bankverbindung wechseln') }}
										</NcActionButton>
										<NcActionButton
											v-if="row.mandate && row.mandate.status === 'active'"
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
								</div>
							</td>
						</template>
					</tr>
				</tbody>
			</table>
		</div>
		<NcEmptyContent
			v-if="!filteredRows.length"
			:name="rows.length ? t('Kein Eintrag passt zur Suche.') : t('Noch kein Mitglied aufgenommen.')"
			:description="rows.length ? '' : t('Mit „＋ Mitglied“ oben ein erstes Mitglied anlegen, oder eine Liste als CSV einlesen.')" />

		<MemberDialog
			:show="memberDialogOpen"
			:saving="saving"
			:member="editingMember"
			:defaultFeeAmount="defaultFeeAmount"
			@update:show="memberDialogOpen = $event"
			@close="memberDialogOpen = false"
			@save="saveMember"
			@changed="reload" />

		<MemberImportDialog
			:show="importDialogOpen"
			:defaultFeeAmount="defaultFeeAmount"
			@update:show="importDialogOpen = $event"
			@close="importDialogOpen = false"
			@imported="reload" />

		<BankAccountChangeDialog
			v-model:show="bankChangeOpen"
			:mandate="bankChangeMandate"
			:saving="bankChangeSaving"
			@close="bankChangeOpen = false"
			@save="saveBankChange" />
	</div>
</template>

<script>
import { mdiAccountEdit, mdiBankTransfer, mdiCancel, mdiDelete, mdiPencil } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcActionButton, NcActions, NcButton, NcEmptyContent, NcIconSvgWrapper } from '@nextcloud/vue'
import { toRefs } from 'vue'
import AmountInput from './AmountInput.vue'
import BankAccountChangeDialog from './BankAccountChangeDialog.vue'
import MemberCard from './MemberCard.vue'
import MemberDialog from './MemberDialog.vue'
import MemberImportDialog from './MemberImportDialog.vue'
import api from '../api.js'
import { useConfirm } from '../composables/useConfirm.js'
import { useMembers } from '../composables/useMembers.js'
import { useMembershipFees } from '../composables/useMembershipFees.js'
import { useSepaMandates } from '../composables/useSepaMandates.js'
import { errMsg, formatMoney } from '../lib/format.js'
import { FREQUENCY_MONTHS, frequencyLabel, frequencyOptions } from '../lib/frequency.js'

/**
 * Mitgliederliste (Spec §2.2/§3.1, docs/beitraege-sepa-modul-spec.md): jede
 * Zeile ist ein Mitglied, angereichert um sein SEPA-Mandat und seinen
 * Mitgliedsbeitrag, falls vorhanden – beides ist seit der Mitglied-Entity
 * unabhängig vom Mitglied selbst (siehe Migration 000137/000138/000139).
 *
 * Frueher SettingsMembers.vue im Einstellungen-Modal, jetzt Unterreiter
 * „Mitglieder" von ContributionsTab.vue, siehe NAVIGATION-KONZEPT.md
 * Abschnitt 4. Die Formulare leben in eigenen Dialogen (MemberDialog.vue,
 * MemberImportDialog.vue), die per $refs von der Kopfzeile in
 * ContributionsTab.vue geoeffnet werden.
 *
 * Erreichbar ab Rolle Buchhalter (siehe MemberController) – anders als der
 * Einzug-Unterreiter *nicht* ab Revisor, weil hier unmaskierte Kontaktdaten
 * stehen (Spec §3.9).
 */
export default {
	name: 'MembersList',
	components: { NcButton, NcActions, NcActionButton, NcEmptyContent, NcIconSvgWrapper, AmountInput, BankAccountChangeDialog, MemberDialog, MemberImportDialog, MemberCard },
	props: {
		isMobile: { type: Boolean, default: false },
		defaultFeeAmount: { type: [Number, String], default: '' },
	},

	setup() {
		const membershipFees = useMembershipFees()
		const sepaMandates = useSepaMandates()
		const members = useMembers()
		return {
			...toRefs(membershipFees.state),
			...toRefs(sepaMandates.state),
			...toRefs(members.state),
			loadMembershipFees: membershipFees.loadMembershipFees,
			loadSepaMandates: sepaMandates.loadSepaMandates,
			loadMembers: members.loadMembers,
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
			editingMember: null,
			importDialogOpen: false,
			bankChangeOpen: false,
			bankChangeMandate: null,
			bankChangeSaving: false,
			mdiAccountEdit,
			mdiBankTransfer,
			mdiCancel,
			mdiDelete,
			mdiPencil,
		}
	},

	computed: {
		/** Ein Mitglied ist die Zeile; sein Mandat/Beitrag (falls vorhanden) hängt sich daran. */
		rows() {
			return this.members.map((member) => {
				const memberMandates = this.sepaMandates.filter((m) => m.memberId === member.id)
				// Ein aktives Mandat sticht ein widerrufenes: gezeigt wird das, mit
				// dem tatsaechlich eingezogen wird.
				const mandate = memberMandates.find((m) => m.status === 'active') ?? memberMandates[0] ?? null
				const fee = this.membershipFees.find((f) => f.memberId === member.id) ?? null
				return {
					key: `member-${member.id}`,
					member,
					displayName: member.displayName,
					email: member.email,
					mandate,
					fee,
				}
			}).sort((a, b) => a.displayName.localeCompare(b.displayName, 'de'))
		},

		filteredRows() {
			const suche = this.search.trim().toLowerCase()
			return this.rows.filter((r) => {
				if (this.onlyProblems && !this.hasProblem(r)) { return false }
				if (!suche) { return true }
				return [r.displayName, r.email, r.member.memberNumber, r.mandate?.iban, r.mandate?.mandateReference]
					.filter(Boolean)
					.some((v) => String(v).toLowerCase().includes(suche))
			})
		},

		/** Beitragsaufkommen aufs Jahr hochgerechnet – nur aktive Beiträge. */
		jahresSumme() {
			return this.rows.reduce((summe, r) => {
				if (!r.fee || !r.fee.active) { return summe }
				return summe + r.fee.amount * (12 / (FREQUENCY_MONTHS[r.fee.frequency] || 12))
			}, 0)
		},
	},

	mounted() {
		this.loadMembers()
		this.loadMembershipFees()
		this.loadSepaMandates()
	},

	methods: {
		errMsg,
		formatMoney,
		frequencyLabel,
		/** Von der Kopfzeile in ContributionsTab.vue per $refs aufgerufen. */
		openMemberDialog() { this.editingMember = null; this.memberDialogOpen = true },
		openImportDialog() { this.importDialogOpen = true },
		openMemberAkte(member) { this.editingMember = member; this.memberDialogOpen = true },
		/** Was der Verwalter sehen sollte: fehlende Adresse, Rückstand, kein Mandat. */
		hasProblem(row) {
			if (row.fee && row.fee.dueCount > 0) { return true }
			if (row.fee && row.fee.active && !row.mandate) { return true }
			return !row.email
		},

		isUsed(m) {
			const u = m.usage || {}
			return (u.batchItems || 0) + (u.fees || 0) + (u.openItems || 0) > 0
		},

		async reload() {
			await Promise.all([this.loadMembers(), this.loadMembershipFees(), this.loadSepaMandates()])
			// Die offene Akte zeigt sonst weiter den Stand von vor dem Neuladen -
			// nach @changed (verknuepft/geloest/Austritt) muss sie den frischen
			// Datensatz bekommen, sonst wirkt z.B. "Verknuepfen" folgenlos.
			if (this.editingMember) {
				this.editingMember = this.members.find((m) => m.id === this.editingMember.id) ?? null
			}
		},

		/**
		 * Stammdaten anlegen/ändern, dazu beim Anlegen optional ein SEPA-Mandat
		 * (papier oder elektronisch, Issue #66/#67) und eine Zuweisung zu einer
		 * Beitragsgruppe (Issue #68) in einem Zug – der dreistufige
		 * Aufnahme-Assistent aus Spec §3.1 (MemberDialog.vue: Stammdaten → Mandat
		 * → Beitrag, Schritt 2/3 überspringbar). Schlägt eine spätere Stufe fehl,
		 * bleibt stehen, was schon entstanden ist – die Meldung sagt ausdrücklich,
		 * was das war, statt nur „fehlgeschlagen".
		 */
		async saveMember(payload) {
			this.saving = true
			let stage = 'member'
			try {
				if (this.editingMember) {
					await api.updateMember(this.editingMember.id, payload.stammdaten)
				} else {
					const { data: member } = await api.createMember(payload.stammdaten)
					if (payload.mandate) {
						stage = 'mandate'
						await this.createOnboardingMandate(member.id, payload.mandate)
					}
					if (payload.assignment) {
						stage = 'assignment'
						await api.createAssignment({ memberId: member.id, ...payload.assignment })
					}
				}
				this.memberDialogOpen = false
				await this.reload()
				showSuccess(this.t(this.editingMember ? 'Mitglied gespeichert.' : 'Mitglied aufgenommen.'))
			} catch (e) {
				await this.reload()
				showError(this.errMsg(e, {
					member: this.t('Mitglied konnte nicht gespeichert werden'),
					mandate: this.t('Das Mitglied wurde angelegt, das Mandat nicht'),
					assignment: this.t('Mitglied und Mandat wurden angelegt, der Beitrag nicht'),
				}[stage]))
			} finally { this.saving = false }
		},

		/**
		 * Schritt 2 des Aufnahme-Assistenten (Spec §3.1): papier-Weg entscheidet
		 * per Mandatsdatum sofort-aktiv vs. entwurf, elektronisch-Weg verschickt
		 * sofort den Einmal-Link (Issue #67).
		 */
		async createOnboardingMandate(memberId, mandate) {
			if (mandate.signatureType === 'elektronisch') {
				const { data } = await api.createMandateElectronic({
					memberId,
					iban: mandate.iban,
					bic: mandate.bic,
					accountHolder: mandate.accountHolder,
					mandateReference: mandate.mandateReference,
				})
				await api.sendMandateActivationLink(data.id)
				return data
			}
			const { data } = await api.createMandate({
				memberId,
				iban: mandate.iban,
				bic: mandate.bic,
				accountHolder: mandate.accountHolder,
				mandateReference: mandate.mandateReference,
				signedAt: mandate.signedAt,
			})
			if (mandate.signedAt) {
				await api.activateMandate(data.id)
			}
			return data
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
				this.t('Erzeugen'),
				'primary',
			)) { return }
			try {
				const { data } = await api.catchUpMembershipFee(fee.id)
				await this.loadMembershipFees()
				showSuccess(this.n('%n offener Posten erzeugt.', '%n offene Posten erzeugt.', data.created))
			} catch (e) { showError(this.errMsg(e, this.t('Nachholen fehlgeschlagen'))) }
		},

		async removeFee(fee) {
			if (!await this.askConfirm(this.t('Beitrag löschen'), this.t('Beitrag für „{name}" endgültig löschen? Das Mandat bleibt bestehen.', { name: fee.displayName }))) { return }
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
			if (!await this.askConfirm(this.t('Mandat widerrufen'), this.t('Mandat für „{name}" widerrufen? Es wird danach nicht mehr für neue Einzüge verwendet.', { name: mandate.displayName }))) { return }
			try {
				await api.revokeSepaMandate(mandate.id)
				await this.loadSepaMandates()
				showSuccess(this.t('Mandat widerrufen.'))
			} catch (e) { showError(this.errMsg(e, this.t('Widerrufen fehlgeschlagen'))) }
		},

		async removeMandate(mandate) {
			if (!await this.askConfirm(this.t('Mandat löschen'), this.t('Mandat für „{name}" endgültig löschen?', { name: mandate.displayName }))) { return }
			try {
				await api.deleteSepaMandate(mandate.id)
				await this.loadSepaMandates()
				showSuccess(this.t('Mandat gelöscht.'))
			} catch (e) { showError(this.errMsg(e, this.t('Löschen fehlgeschlagen'))) }
		},
	},
}
</script>
