<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-member"
		size="large"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-member" class="vbh-modal-title">
				{{ isEdit ? t('Mitglied') + ': ' + member.displayName : t('Mitglied aufnehmen') }}
			</h2>

			<div class="vbh-form">
				<label>{{ t('Mitgliedstyp') }}
					<select v-model="form.memberType">
						<option value="person">{{ t('Person') }}</option>
						<option value="organisation">{{ t('Organisation') }}</option>
					</select>
				</label>
			</div>
			<div v-if="form.memberType === 'person'" class="vbh-form">
				<label>{{ t('Vorname') }}
					<input ref="nameInput" v-model="form.firstName" :placeholder="t('optional')">
				</label>
				<label class="vbh-grow">{{ t('Nachname') }}
					<input v-model="form.lastName">
				</label>
			</div>
			<div v-else class="vbh-form">
				<label class="vbh-grow">{{ t('Name der Organisation') }}
					<input ref="orgInput" v-model="form.organizationName">
				</label>
			</div>

			<div class="vbh-form">
				<label class="vbh-grow">{{ t('E-Mail') }}
					<input v-model="form.email" type="email" :placeholder="t('Voraussetzung für Lastschrift')">
				</label>
				<label>{{ t('Telefon') }}
					<input v-model="form.phone">
				</label>
			</div>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Straße') }}
					<input v-model="form.street">
				</label>
				<label>{{ t('PLZ') }}
					<input v-model="form.postalCode" class="vbh-short">
				</label>
				<label>{{ t('Ort') }}
					<input v-model="form.city">
				</label>
			</div>
			<div class="vbh-form">
				<label>{{ t('Mitgliedsnummer') }}
					<input v-model="form.memberNumber" :placeholder="t('optional')">
				</label>
				<label>{{ t('Beigetreten am') }}
					<input v-model="form.joinedAt" type="date">
				</label>
			</div>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Interne Notiz') }}
					<textarea v-model="form.internalNote" rows="2" :placeholder="t('nicht im Self-Service sichtbar')" />
				</label>
			</div>

			<template v-if="!isEdit">
				<p class="vbh-hint">
					{{ t('Optional gleich ein SEPA-Mandat erfassen und einer Beitragsgruppe zuweisen – beides lässt sich auch später ergänzen (Schritt 2/3 des Aufnahme-Assistenten, überspringbar).') }}
				</p>
				<div class="vbh-form">
					<label>{{ t('Art der Unterschrift') }}
						<select v-model="form.signatureType">
							<option value="papier">
								{{ t('Papier') }}
							</option>
							<option value="elektronisch" :disabled="!hasEmail">
								{{ t('Elektronisch (Einmal-Link per Mail)') }}
							</option>
						</select>
					</label>
					<label class="vbh-grow">{{ t('IBAN') }}
						<input v-model="form.iban" placeholder="DE12 5001 0517 0648 4898 90">
					</label>
					<label>{{ t('BIC') }}
						<input v-model="form.bic" class="vbh-short" :placeholder="t('optional')">
					</label>
				</div>
				<div class="vbh-form">
					<label class="vbh-grow">{{ t('Kontoinhaber') }}
						<input v-model="form.accountHolder" :placeholder="t('sonst Anzeigename des Mitglieds')">
					</label>
					<label>{{ t('Mandatsreferenz') }}
						<input v-model="form.mandateReference" :placeholder="t('sonst automatisch vergeben')">
					</label>
					<label v-if="form.signatureType === 'papier'">{{ t('Mandat unterschrieben am') }}
						<input v-model="form.signedDate" type="date">
					</label>
				</div>
				<p v-if="form.iban.trim() && form.signatureType === 'papier' && !form.signedDate" class="vbh-hint">
					{{ t('Ohne Unterschriftsdatum bleibt das Mandat ein Entwurf – erst das Datum aktiviert es sofort.') }}
				</p>
				<p v-if="form.iban.trim() && form.signatureType === 'elektronisch'" class="vbh-hint">
					{{ t('Nach dem Anlegen geht sofort ein Einmal-Link an die Mailadresse des Mitglieds – die Zustimmung dort aktiviert das Mandat.') }}
				</p>

				<div class="vbh-form">
					<label class="vbh-grow">{{ t('Beitragsgruppe') }}
						<select v-model.number="form.groupId">
							<option :value="null">
								{{ t('– keine Zuweisung –') }}
							</option>
							<option v-for="g in groups" :key="g.id" :value="g.id">
								{{ g.name }}
							</option>
						</select>
					</label>
				</div>
				<div v-if="selectedGroup" class="vbh-form">
					<label>{{ t('Turnus (Monate)') }}
						<select v-model.number="form.intervalMonths">
							<option v-for="n in selectedGroup.allowedIntervals" :key="n" :value="n">
								{{ n }}
							</option>
						</select>
					</label>
					<label>{{ t('Monatsbeitrag (€)') }}
						<AmountInput v-model="form.monthlyAmount" class="vbh-short" />
					</label>
					<label>{{ t('Zahlungsart') }}
						<select v-model="form.paymentMethod" :disabled="!hasEmail">
							<option value="direct_debit">
								{{ t('Lastschrift') }}
							</option>
							<option value="ueberweisung">
								{{ t('Überweisung') }}
							</option>
						</select>
					</label>
				</div>
				<p v-if="selectedGroup && !hasEmail" class="vbh-hint">
					{{ t('Ohne Mailadresse ist keine Vorabinformation und kein Lastschrifteinzug möglich – Zahlungsart wird auf Überweisung gesetzt.') }}
				</p>
				<div v-if="selectedGroup" class="vbh-form">
					<label>{{ t('Gültig ab') }}
						<input v-model="form.validFrom" type="date">
					</label>
					<NcButton variant="secondary" :disabled="!canPreviewAssignment" @click="loadAssignmentPreview">
						{{ t('Vorschau') }}
					</NcButton>
				</div>
				<p v-if="assignmentPreview" class="vbh-hint">
					{{ t('Erste Periode: {from} bis {to} ({months}) · Einzugsbetrag {amount} · voraussichtlicher Einzugstermin {due}', {
						from: assignmentPreview.periodStart,
						to: assignmentPreview.periodEnd,
						months: n('%n Monat', '%n Monate', assignmentPreview.months),
						amount: euro(assignmentPreview.amountCents),
						due: assignmentPreview.dueDate,
					}) }}
				</p>
			</template>

			<template v-else>
				<h3 class="vbh-modal-subtitle">
					{{ t('Nextcloud-Konto') }}
				</h3>
				<div v-if="member.ncUserId" class="vbh-form">
					<span>{{ t('Verknüpft mit „{uid}".', { uid: member.ncUserId }) }}</span>
					<NcButton
						variant="tertiary"
						size="small"
						:disabled="linking"
						@click="doUnlink">
						{{ t('Verknüpfung lösen') }}
					</NcButton>
				</div>
				<div v-else>
					<p v-if="!member.email" class="vbh-hint">
						{{ t('Ohne Mailadresse gibt es keinen Vorschlag – erst speichern, dann verknüpfen.') }}
					</p>
					<template v-else>
						<NcButton
							variant="secondary"
							size="small"
							:disabled="linking"
							@click="loadSuggestions">
							{{ t('Vorschläge suchen') }}
						</NcButton>
						<ul v-if="suggestions.length" class="vbh-linksuggestions">
							<li v-for="s in suggestions" :key="s.uid">
								{{ s.displayName }} <span class="vbh-hint">({{ s.email }})</span>
								<NcButton
									variant="primary"
									size="small"
									:disabled="linking"
									@click="doLink(s.uid)">
									{{ t('Verknüpfen') }}
								</NcButton>
							</li>
						</ul>
						<p v-else-if="suggestionsLoaded" class="vbh-hint">
							{{ t('Kein Nextcloud-Konto mit dieser Mailadresse gefunden.') }}
						</p>
					</template>
				</div>

				<h3 class="vbh-modal-subtitle">
					{{ t('Austritt') }}
				</h3>
				<div v-if="member.leftAt" class="vbh-form">
					<span>{{ t('Austritt zum {datum}.', { datum: member.leftAt }) }}</span>
					<NcButton
						variant="tertiary"
						size="small"
						:disabled="leaving"
						@click="doReactivate">
						{{ t('Austritt zurücknehmen') }}
					</NcButton>
				</div>
				<div v-else class="vbh-form">
					<label>{{ t('Austrittsdatum') }}
						<input v-model="leaveDate" type="date">
					</label>
					<NcButton
						variant="secondary"
						size="small"
						:disabled="leaving || !leaveDate"
						@click="doLeave">
						{{ t('Austritt erklären') }}
					</NcButton>
				</div>

				<h3 class="vbh-modal-subtitle">
					{{ t('Löschen') }}
				</h3>
				<p v-if="member.blockingReasons && member.blockingReasons.length" class="vbh-hint vbh-hint--warning">
					{{ member.blockingReasons.join(' ') }}
				</p>
				<NcButton
					v-else
					variant="error"
					size="small"
					:disabled="deleting"
					@click="doDelete">
					{{ t('Mitglied löschen') }}
				</NcButton>
			</template>

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSave || saving" @click="save">
					{{ isEdit ? t('Speichern') : t('Aufnehmen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcModal } from '@nextcloud/vue'
import { toRefs } from 'vue'
import AmountInput from './AmountInput.vue'
import api from '../api.js'
import { useConfirm } from '../composables/useConfirm.js'
import { useContributionGroups } from '../composables/useContributionGroups.js'
import { errMsg } from '../lib/format.js'
import { focusOnOpen } from '../lib/modalFocus.js'

function emptyForm(member, defaultFeeAmount) {
	return {
		memberType: member?.memberType ?? 'person',
		firstName: member?.firstName ?? '',
		lastName: member?.lastName ?? '',
		organizationName: member?.organizationName ?? '',
		email: member?.email ?? '',
		phone: member?.phone ?? '',
		street: member?.street ?? '',
		postalCode: member?.postalCode ?? '',
		city: member?.city ?? '',
		memberNumber: member?.memberNumber ?? '',
		joinedAt: member?.joinedAt ?? new Date().toISOString().slice(0, 10),
		internalNote: member?.internalNote ?? '',
		// Nur beim Anlegen relevant (siehe Template): das optionale Mandat
		// (Schritt 2) und die optionale Zuweisung (Schritt 3) des
		// Aufnahme-Assistenten gibt es nur, wenn kein Mitglied übergeben wurde
		// (Akte bearbeitet keins).
		...(member
			? {}
			: {
					signatureType: 'papier',
					iban: '',
					bic: '',
					accountHolder: '',
					mandateReference: '',
					signedDate: new Date().toISOString().slice(0, 10),
					groupId: null,
					intervalMonths: 12,
					monthlyAmount: defaultFeeAmount ?? '',
					paymentMethod: 'direct_debit',
					validFrom: new Date().toISOString().slice(0, 10),
				}),
	}
}

/**
 * Mitglieder-Stammdaten anlegen/bearbeiten (Spec §2.2/§3.1). Ohne `member`-Prop
 * ist es die Aufnahme (mit optionalem Mandat+Beitrag in einem Zug, wie schon
 * bisher); mit `member`-Prop ist es die Akte – Stammdaten bearbeiten,
 * NC-Kontoverknüpfung (nur Vorschlag, nie Vollzug), Austritt und die
 * Löschsperre mit erklärender Meldung statt Ausgrauen.
 *
 * Frueher aus MembersList.vue (damals SettingsMembers.vue) herausgeloest,
 * siehe NAVIGATION-KONZEPT.md Abschnitt 4.
 */
export default {
	name: 'MemberDialog',
	components: { NcModal, NcButton, AmountInput },
	props: {
		show: { type: Boolean, default: false },
		saving: { type: Boolean, default: false },
		/** null = Aufnahme, sonst die zu bearbeitende Mitglied-Akte (dekoriert, inkl. blockingReasons). */
		member: { type: Object, default: null },
		// Vorbelegung aus Einstellungen -> Beiträge & SEPA (SettingsSepaBasics.vue),
		// leerer String heisst "kein Standardbeitrag hinterlegt". Nur der Betrag
		// ist hier noch relevant – die Frequenz gibt seit Issue #69 die gewählte
		// Beitragsgruppe vor (allowedIntervals/defaultInterval).
		defaultFeeAmount: { type: [Number, String], default: '' },
	},

	emits: ['close', 'save', 'update:show', 'changed'],

	setup() {
		return { ...toRefs(useContributionGroups().state), askConfirm: useConfirm().askConfirm }
	},

	data() {
		return {
			form: emptyForm(this.member, this.defaultFeeAmount),
			suggestions: [],
			suggestionsLoaded: false,
			linking: false,
			leaving: false,
			deleting: false,
			leaveDate: new Date().toISOString().slice(0, 10),
			assignmentPreview: null,
		}
	},

	computed: {
		isEdit() { return this.member !== null },

		hasEmail() { return this.form.email.trim() !== '' },

		selectedGroup() {
			return this.groups.find((g) => g.id === this.form.groupId) ?? null
		},

		canPreviewAssignment() {
			return this.form.intervalMonths && this.form.monthlyAmount !== '' && this.form.validFrom
		},

		canSave() {
			return this.form.memberType === 'organisation'
				? !!this.form.organizationName.trim()
				: !!this.form.lastName.trim()
		},
	},

	watch: {
		show(open) {
			if (!open) { return }
			this.form = emptyForm(this.member, this.defaultFeeAmount)
			this.suggestions = []
			this.suggestionsLoaded = false
			this.leaveDate = new Date().toISOString().slice(0, 10)
			this.assignmentPreview = null
			focusOnOpen(this, () => this.$refs.nameInput || this.$refs.orgInput)
		},

		// Spec §3.1: "fehlt die Mailadresse, fällt Schritt 3 sichtbar auf
		// ueberweisung zurück, sagt warum" – die Mail-Pflicht greift hier direkt
		// an Ort und Stelle, weil Stammdaten (Schritt 1) und Beitrag (Schritt 3)
		// im selben Formular stehen.
		hasEmail(has) {
			if (!has) { this.form.paymentMethod = 'ueberweisung' }
		},

		selectedGroup(group) {
			if (!group) { return }
			if (!group.allowedIntervals.includes(this.form.intervalMonths)) {
				this.form.intervalMonths = group.defaultInterval
			}
			if (this.form.monthlyAmount === '') {
				this.form.monthlyAmount = group.defaultMonthlyAmount
			}
			this.assignmentPreview = null
		},
	},

	methods: {
		errMsg,
		euro(cents) { return (cents / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }) },

		stammdaten() {
			return {
				memberType: this.form.memberType,
				firstName: this.form.memberType === 'person' ? (this.form.firstName.trim() || null) : null,
				lastName: this.form.memberType === 'person' ? this.form.lastName.trim() : null,
				organizationName: this.form.memberType === 'organisation' ? this.form.organizationName.trim() : null,
				email: this.form.email.trim() || null,
				phone: this.form.phone.trim() || null,
				street: this.form.street.trim() || null,
				postalCode: this.form.postalCode.trim() || null,
				city: this.form.city.trim() || null,
				memberNumber: this.form.memberNumber.trim() || null,
				joinedAt: this.form.joinedAt,
				internalNote: this.form.internalNote.trim() || null,
			}
		},

		save() {
			if (!this.canSave) { return }
			const payload = { stammdaten: this.stammdaten() }
			if (!this.isEdit) {
				payload.mandate = this.form.iban.trim()
					? {
							signatureType: this.form.signatureType,
							iban: this.form.iban.trim(),
							bic: this.form.bic.trim() || null,
							accountHolder: this.form.accountHolder.trim() || null,
							mandateReference: this.form.mandateReference.trim() || null,
							signedAt: this.form.signatureType === 'papier' ? (this.form.signedDate || null) : null,
						}
					: null
				payload.assignment = this.form.groupId
					? {
							groupId: this.form.groupId,
							intervalMonths: this.form.intervalMonths,
							monthlyAmount: Number(this.form.monthlyAmount),
							paymentMethod: this.form.paymentMethod,
							validFrom: this.form.validFrom,
						}
					: null
			}
			this.$emit('save', payload)
		},

		/** Vorschau der ersten (Prorata-)Periode samt vorgeschlagenem Einzugstermin (Spec §3.1 Schritt 3). */
		async loadAssignmentPreview() {
			try {
				const { data } = await api.previewNewAssignment({
					intervalMonths: this.form.intervalMonths,
					monthlyAmount: this.form.monthlyAmount,
					validFrom: this.form.validFrom,
				})
				this.assignmentPreview = data
			} catch (e) {
				this.assignmentPreview = null
				showError(this.errMsg(e, this.t('Vorschau konnte nicht geladen werden')))
			}
		},

		async loadSuggestions() {
			this.linking = true
			try {
				const { data } = await api.memberLinkSuggestions(this.member.id)
				this.suggestions = data
				this.suggestionsLoaded = true
			} catch (e) { showError(this.errMsg(e, this.t('Vorschläge konnten nicht geladen werden'))) } finally { this.linking = false }
		},

		async doLink(ncUserId) {
			this.linking = true
			try {
				await api.linkMember(this.member.id, ncUserId)
				showSuccess(this.t('Verknüpft.'))
				this.$emit('changed')
			} catch (e) { showError(this.errMsg(e, this.t('Verknüpfen fehlgeschlagen'))) } finally { this.linking = false }
		},

		async doUnlink() {
			if (!await this.askConfirm(this.t('Verknüpfung lösen'), this.t('Die Verknüpfung mit dem Nextcloud-Konto lösen? Das Mitglied und seine Historie bleiben bestehen.'), this.t('Lösen'), 'primary')) { return }
			this.linking = true
			try {
				await api.unlinkMember(this.member.id)
				showSuccess(this.t('Verknüpfung gelöst.'))
				this.$emit('changed')
			} catch (e) { showError(this.errMsg(e, this.t('Lösen fehlgeschlagen'))) } finally { this.linking = false }
		},

		async doLeave() {
			this.leaving = true
			try {
				await api.leaveMember(this.member.id, this.leaveDate)
				showSuccess(this.t('Austritt erklärt.'))
				this.$emit('changed')
			} catch (e) { showError(this.errMsg(e, this.t('Speichern fehlgeschlagen'))) } finally { this.leaving = false }
		},

		async doReactivate() {
			this.leaving = true
			try {
				await api.reactivateMember(this.member.id)
				showSuccess(this.t('Austritt zurückgenommen.'))
				this.$emit('changed')
			} catch (e) { showError(this.errMsg(e, this.t('Speichern fehlgeschlagen'))) } finally { this.leaving = false }
		},

		async doDelete() {
			if (!await this.askConfirm(this.t('Mitglied löschen'), this.t('Mitglied „{name}" endgültig löschen?', { name: this.member.displayName }))) { return }
			this.deleting = true
			try {
				await api.deleteMember(this.member.id)
				showSuccess(this.t('Mitglied gelöscht.'))
				this.$emit('changed')
				this.$emit('close')
			} catch (e) { showError(this.errMsg(e, this.t('Löschen fehlgeschlagen'))) } finally { this.deleting = false }
		},
	},
}
</script>
