<template>
	<div class="vbh-selfservice">
		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else-if="member">
			<div class="vbh-card">
				<div class="vbh-selfservice-card-header">
					<h4>{{ t('Meine Stammdaten') }}</h4>
					<NcButton v-if="!editingContact" variant="tertiary" @click="startEditContact">
						{{ t('Bearbeiten') }}
					</NcButton>
				</div>

				<dl v-if="!editingContact" class="vbh-selfservice-fields">
					<dt>{{ t('Name') }}</dt>
					<dd>{{ member.displayName }}</dd>

					<template v-if="member.email">
						<dt>{{ t('E-Mail') }}</dt>
						<dd>{{ member.email }}</dd>
					</template>

					<template v-if="member.phone">
						<dt>{{ t('Telefon') }}</dt>
						<dd>{{ member.phone }}</dd>
					</template>

					<template v-if="addressLine">
						<dt>{{ t('Adresse') }}</dt>
						<dd>{{ addressLine }}</dd>
					</template>

					<template v-if="member.memberNumber">
						<dt>{{ t('Mitgliedsnummer') }}</dt>
						<dd>{{ member.memberNumber }}</dd>
					</template>

					<dt>{{ t('Mitglied seit') }}</dt>
					<dd>{{ formatDate(member.joinedAt) }}</dd>

					<template v-if="member.leftAt">
						<dt>{{ t('Mitglied bis') }}</dt>
						<dd>{{ formatDate(member.leftAt) }}</dd>
					</template>
				</dl>

				<div v-else class="vbh-form-stack">
					<div v-if="member.memberType === 'person'" class="vbh-form">
						<label class="vbh-grow">{{ t('Vorname') }}
							<input v-model="contactForm.firstName">
						</label>
						<label class="vbh-grow">{{ t('Nachname') }}
							<input v-model="contactForm.lastName">
						</label>
					</div>
					<div v-else class="vbh-form">
						<label class="vbh-grow">{{ t('Name') }}
							<input v-model="contactForm.organizationName">
						</label>
					</div>
					<div class="vbh-form">
						<label class="vbh-grow">{{ t('E-Mail') }}
							<input v-model="contactForm.email" type="email">
						</label>
						<label>{{ t('Telefon') }}
							<input v-model="contactForm.phone">
						</label>
					</div>
					<div class="vbh-form">
						<label class="vbh-grow">{{ t('Straße') }}
							<input v-model="contactForm.street">
						</label>
					</div>
					<div class="vbh-form">
						<label>{{ t('PLZ') }}
							<input v-model="contactForm.postalCode" class="vbh-short">
						</label>
						<label class="vbh-grow">{{ t('Ort') }}
							<input v-model="contactForm.city">
						</label>
						<label>{{ t('Land') }}
							<CountrySelect v-model="contactForm.country" />
						</label>
					</div>
					<p v-if="emailChangedInForm" class="vbh-hint">
						{{ t('Bei einer Änderung der E-Mail-Adresse erhält die bisherige Adresse zur Sicherheit eine Mail darüber.') }}
					</p>
					<div class="vbh-modal-actions">
						<NcButton variant="tertiary" @click="cancelEditContact">
							{{ t('Abbrechen') }}
						</NcButton>
						<NcButton variant="primary" :disabled="savingContact" @click="saveContact">
							{{ t('Speichern') }}
						</NcButton>
					</div>
				</div>
			</div>

			<div v-if="assignments.length" class="vbh-card">
				<h4>{{ t('Mein Beitrag') }}</h4>
				<div v-for="a in assignments" :key="a.id" class="vbh-selfservice-assignment">
					<h5>{{ a.groupName || t('Beitrag') }}</h5>
					<p v-if="a.effectiveMinMonthlyAmount !== null" class="vbh-hint">
						{{ t('Untergrenze: {amount}', { amount: formatMoney(a.effectiveMinMonthlyAmount) }) }}
					</p>
					<div class="vbh-form">
						<label>{{ t('Monatsbeitrag (€)') }}
							<AmountInput v-model="a._form.monthlyAmount" class="vbh-short" />
						</label>
						<label>{{ t('Turnus (Monate)') }}
							<select v-model.number="a._form.intervalMonths">
								<option v-for="n in a.allowedIntervals" :key="n" :value="n">
									{{ n }}
								</option>
							</select>
						</label>
						<NcButton variant="secondary" :disabled="a._loadingPreview" @click="loadPreview(a)">
							{{ t('Vorschau') }}
						</NcButton>
					</div>
					<p v-if="a._preview && previewMatchesForm(a)" class="vbh-hint">
						{{ t('Wirkt ab {from} · erster Einzug am {due} · Betrag {amount}', {
							from: formatDate(a._preview.effectiveFrom),
							due: formatDate(a._preview.firstDueDate),
							amount: formatMoney(a._preview.amountCents / 100),
						}) }}
					</p>
					<div class="vbh-modal-actions">
						<NcButton variant="primary" :disabled="!canSave(a)" @click="save(a)">
							{{ t('Speichern') }}
						</NcButton>
					</div>
				</div>
			</div>

			<div class="vbh-card">
				<h4>{{ t('Mein SEPA-Lastschriftmandat') }}</h4>

				<template v-if="mandate">
					<dl class="vbh-selfservice-fields">
						<dt>{{ t('IBAN') }}</dt>
						<dd>{{ mandate.ibanMasked }}</dd>
						<dt>{{ t('Kontoinhaber') }}</dt>
						<dd>{{ mandate.accountHolder }}</dd>
						<dt>{{ t('Status') }}</dt>
						<dd>
							{{ statusLabel(mandate.status) }}
							<span v-if="mandate.storyText" class="vbh-typetag">{{ mandate.storyText }}</span>
						</dd>
					</dl>

					<div class="vbh-mcard-actions">
						<template v-if="isUnconfirmedElectronicDraft">
							<NcButton variant="primary" @click="openGrantDialog('confirm')">
								{{ t('Jetzt bestätigen') }}
							</NcButton>
							<NcButton variant="tertiary" :disabled="requestingLink" @click="requestLink">
								{{ t('Stattdessen Link per Mail zuschicken') }}
							</NcButton>
						</template>
						<template v-else-if="mandate.status === 'aktiv'">
							<NcButton variant="secondary" @click="openAccountDialog('iban')">
								{{ t('Bankverbindung ändern') }}
							</NcButton>
							<NcButton variant="tertiary" @click="openAccountDialog('holder')">
								{{ t('Kontoinhaber wechseln') }}
							</NcButton>
							<NcButton variant="error" @click="revokeOpen = true">
								{{ t('Mandat widerrufen') }}
							</NcButton>
						</template>
						<template v-else-if="mandate.status === 'ausgesetzt'">
							<NcButton variant="error" @click="revokeOpen = true">
								{{ t('Mandat widerrufen') }}
							</NcButton>
						</template>
						<!-- Aus einem Entwurf (elektronisch oder Papier) kommt das Mitglied sonst nicht heraus (Issue #118) -->
						<NcButton v-if="mandate.status === 'entwurf'" variant="error" @click="discardOpen = true">
							{{ t('Entwurf verwerfen') }}
						</NcButton>
					</div>
				</template>
				<template v-else>
					<p class="vbh-hint">
						{{ t('Kein Mandat hinterlegt.') }}
					</p>
					<div class="vbh-mcard-actions">
						<NcButton variant="primary" @click="openGrantDialog('grant')">
							{{ t('Mandat jetzt erteilen') }}
						</NcButton>
					</div>
				</template>

				<h4>{{ t('Rücklastschriften') }}</h4>
				<!-- Der Leer-Hinweis nur, wenn die Liste wirklich geladen und leer ist - nie bei einem Ladefehler (Issue #122) -->
				<ul v-if="returnedDebitRows.length" class="vbh-selfservice-returns">
					<li v-for="(r, index) in returnedDebitRows" :key="index" class="vbh-selfservice-return">
						<strong>{{ r.date }} · {{ r.amount }}</strong>
						<span v-if="r.subject" class="vbh-hint">{{ r.subject }}</span>
						<span>{{ r.reason }}</span>
					</li>
				</ul>
				<p v-else-if="returnedDebitsFailed" class="vbh-hint vbh-hint--warning">
					{{ t('Rücklastschriften konnten nicht geladen werden.') }}
				</p>
				<p v-else-if="returnedDebitsLoaded" class="vbh-hint">
					{{ t('Bisher keine Rücklastschrift.') }}
				</p>
			</div>

			<div class="vbh-card">
				<h4>{{ t('Meine Beitragsbestätigung') }}</h4>
				<p class="vbh-hint">
					{{ t('Informelle Bestätigung der bezahlten Beiträge eines Beitragsjahres – kein amtlicher Spendennachweis nach § 10b EStG.') }}
				</p>
				<div class="vbh-form">
					<label>{{ t('Beitragsjahr') }}
						<select v-model.number="certificateYear">
							<option v-for="y in certificateYears" :key="y" :value="y">
								{{ y }}
							</option>
						</select>
					</label>
					<a
						:href="certificateUrl"
						target="_blank"
						rel="noopener"
						class="vbh-export-btn">{{ t('Öffnen') }}</a>
				</div>
			</div>

			<div class="vbh-card">
				<h4>{{ t('Meine Daten') }}</h4>
				<p class="vbh-hint">
					{{ t('Druckfertige Auskunft nach Art. 15 DSGVO über alle zu Ihrer Mitgliedschaft gespeicherten Daten – kein strukturierter Export nach Art. 20 DSGVO.') }}
				</p>
				<div class="vbh-form">
					<a
						:href="dataOverviewUrl"
						target="_blank"
						rel="noopener"
						class="vbh-export-btn">{{ t('Datenübersicht öffnen') }}</a>
				</div>
			</div>
		</template>
		<p v-else class="vbh-hint vbh-hint--warning">
			{{ t('Ihre Stammdaten konnten nicht geladen werden.') }}
		</p>

		<SelfServiceMandateGrantDialog
			:show="grantDialogOpen"
			:mode="grantMode"
			:member="member"
			:mandate="mandate"
			:saving="saving"
			@close="grantDialogOpen = false"
			@save="saveGrant" />
		<SelfServiceMandateAccountDialog
			:show="accountDialogOpen"
			:initialMode="accountInitialMode"
			:openClaimsTotalCents="openClaimsTotalCents"
			:saving="saving"
			@close="accountDialogOpen = false"
			@saveIban="saveIban"
			@saveHolder="saveHolder" />
		<SelfServiceMandateRevokeDialog
			:show="revokeOpen"
			:openClaimsTotalCents="openClaimsTotalCents"
			:ibanChangeBlocked="ibanChangeBlocked"
			:saving="saving"
			@close="revokeOpen = false"
			@switchToIban="switchToIbanFromRevoke"
			@save="revoke" />
		<MandateDraftDiscardDialog
			:show="discardOpen"
			:saving="saving"
			@close="discardOpen = false"
			@save="discardDraft" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { getLanguage } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import AmountInput from './AmountInput.vue'
import CountrySelect from './CountrySelect.vue'
import MandateDraftDiscardDialog from './MandateDraftDiscardDialog.vue'
import SelfServiceMandateAccountDialog from './SelfServiceMandateAccountDialog.vue'
import SelfServiceMandateGrantDialog from './SelfServiceMandateGrantDialog.vue'
import SelfServiceMandateRevokeDialog from './SelfServiceMandateRevokeDialog.vue'
import api from '../api.js'
import { countryName } from '../lib/countries.js'
import { defaultCountry } from '../lib/countryDefault.js'
import { errMsg, formatDate, formatMoney } from '../lib/format.js'
import { returnedDebitRows } from '../lib/selfReturnedDebits.js'

function emptyContactForm() {
	return { firstName: '', lastName: '', organizationName: '', email: '', phone: '', street: '', postalCode: '', city: '', country: '' }
}

/**
 * Bereich „Mein Beitrag" (Spec §3.4): eigene Stammdaten (#74) plus zwei
 * Aktionskataloge - Mandat (Issue #75: erfassen/elektronisch erteilen,
 * bestehenden Entwurf bestätigen oder verwerfen, IBAN ändern, Kontoinhaber
 * wechseln, widerrufen) und Beitrag/Kontaktdaten (Issue #76: Monatsbeitrag/Turnus
 * ändern, Kontaktstammdaten pflegen). Jede Aktion wirkt sofort (kein
 * Antragsmodell) - die einzige Bremse ist jeweils eine Vorschau vor dem
 * Speichern (Spec Pflicht-UI).
 *
 * Beitrag-Teil (#76) bewusst OHNE Beitragsgruppen-Auswahl (Zuweisung.groupId
 * ist nicht editierbar, siehe SelfController.updateAssignment()) und ohne
 * groupId-Feld überhaupt im Formular. Pflicht-UI „Vorschau vor jedem
 * Speichern": der Speichern-Knopf einer Zuweisung bleibt deaktiviert, bis
 * eine Vorschau für GENAU die aktuell eingetragenen Werte geladen wurde
 * (siehe canSave()/previewMatchesForm()) - jede Änderung an Betrag oder
 * Turnus danach verlangt eine neue Vorschau, weil der Vergleich dann nicht
 * mehr passt.
 *
 * Lädt seine Daten beim eigenen mounted() wie MembersList.vue/EinzugPanel.vue,
 * statt von App.vue vorgeladen zu werden.
 *
 * Dazu die informelle Beitragsbestätigung (Issue #77): eine druckfertige
 * Live-Ansicht je Beitragsjahr, geöffnet als eigene Seite (kein Axios,
 * daher kein Ladezustand/Fehler-Toast außer für die Jahresauswahl selbst).
 *
 * Dazu die eigenen Rücklastschriften (Issue #122, Spec §3.4 Pflicht-UI): als
 * Klartext je Rückgabe-Klasse, neueste zuerst (Anzeigelogik in
 * lib/selfReturnedDebits.js); der Leer-Hinweis steht nur, wenn die Liste
 * geladen und leer ist, nie bei einem Ladefehler.
 *
 * Dazu „Meine Daten" (Issue #78, Spec §3.8): dieselbe Art von druckfertiger
 * Live-Ansicht für die Auskunftspflicht nach Art. 15 DSGVO - kein
 * strukturierter Export nach Art. 20, keine eigene Jahresauswahl (die
 * Datenübersicht deckt immer den vollständigen Datenbestand ab).
 */
export default {
	name: 'SelfServiceTab',
	components: { NcLoadingIcon, NcButton, AmountInput, CountrySelect, MandateDraftDiscardDialog, SelfServiceMandateGrantDialog, SelfServiceMandateAccountDialog, SelfServiceMandateRevokeDialog },

	data() {
		return {
			loading: true,
			member: null,
			assignments: [],
			returnedDebits: [],
			returnedDebitsLoaded: false,
			returnedDebitsFailed: false,
			editingContact: false,
			savingContact: false,
			contactForm: emptyContactForm(),
			grantDialogOpen: false,
			grantMode: 'grant',
			accountDialogOpen: false,
			accountInitialMode: 'iban',
			revokeOpen: false,
			discardOpen: false,
			saving: false,
			requestingLink: false,
			certificateYears: [],
			certificateYear: null,
		}
	},

	computed: {
		addressLine() {
			const m = this.member
			if (!m) { return '' }
			const line1 = m.street || ''
			const line2 = [m.postalCode, m.city].filter(Boolean).join(' ')
			// Das eigene Land bleibt weg, wie auf einem Brief im Inland; ein anderes steht dabei.
			const line3 = m.country && m.country !== defaultCountry() ? countryName(m.country, getLanguage()) : ''
			return [line1, line2, line3].filter(Boolean).join(', ')
		},

		emailChangedInForm() {
			return this.editingContact && this.contactForm.email !== (this.member?.email || '')
		},

		mandate() {
			return this.member && this.member.mandate
		},

		openClaimsTotalCents() {
			return (this.member && this.member.openClaimsTotalCents) || 0
		},

		/**
		 * Die IBAN lässt sich nur bei einem AKTIVEN Mandat ändern (Amendment): bei einem ausgesetzten
		 * Mandat entfällt im Widerrufsdialog der Ausweg „nur ein neues Konto → IBAN ändern“, den es
		 * dort nicht gibt (Issue #118). Der Dialog öffnet nur für aktive/ausgesetzte Mandate.
		 */
		ibanChangeBlocked() {
			return !this.mandate || this.mandate.status !== 'aktiv'
		},

		/** Ein von der Verwaltung angelegter elektronischer Entwurf, der noch auf Zustimmung wartet (Issue #67/#75). */
		isUnconfirmedElectronicDraft() {
			return !!this.mandate && this.mandate.status === 'entwurf' && this.mandate.signatureType === 'elektronisch'
		},

		/** Eigene Rücklastschriften als Anzeigezeilen, neueste zuerst (Issue #122) - Klartext, nie der Bankcode. */
		returnedDebitRows() {
			return returnedDebitRows(this.returnedDebits)
		},

		/** Druckfertige Live-Ansicht der Beitragsbestätigung (Issue #77) - öffnet in neuem Tab. */
		certificateUrl() {
			return api.selfCertificateUrl(this.certificateYear)
		},

		/** Druckfertige "Datenübersicht" (Art. 15 DSGVO, Issue #78) - öffnet in neuem Tab. */
		dataOverviewUrl() {
			return api.selfDataOverviewUrl()
		},
	},

	async mounted() {
		this.loading = true
		await this.reload()
		try {
			const { data } = await api.selfAssignments()
			this.assignments = data.map((a) => this.withEditState(a))
		} catch (e) {
			showError(errMsg(e, this.t('Beitrag konnte nicht geladen werden')))
		} finally {
			this.loading = false
		}
		await this.loadReturnedDebits()
		try {
			const { data } = await api.selfCertificateYears()
			this.certificateYears = data.years
			this.certificateYear = data.years[0] ?? null
		} catch (e) {
			showError(errMsg(e, this.t('Beitragsjahre konnten nicht geladen werden')))
		}
	},

	methods: {
		formatDate,
		formatMoney,

		/**
		 * Eigene Rücklastschriften laden (Issue #122). Ein Ladefehler bleibt als solcher sichtbar
		 * (`returnedDebitsFailed`): der Leer-Hinweis „Bisher keine Rücklastschrift.“ wäre dann falsch.
		 */
		async loadReturnedDebits() {
			try {
				const { data } = await api.selfReturnedDebits()
				this.returnedDebits = data
				this.returnedDebitsLoaded = true
			} catch (e) {
				this.returnedDebitsFailed = true
				showError(errMsg(e, this.t('Rücklastschriften konnten nicht geladen werden.')))
			}
		},

		/** Eigene Stammdaten (inkl. Mandat/offene Forderungssumme) neu laden - auch nach jeder Mandats-Aktion, siehe unten. */
		async reload() {
			try {
				const { data } = await api.selfMe()
				this.member = data
			} catch (e) {
				showError(errMsg(e, this.t('Stammdaten konnten nicht geladen werden')))
			}
		},

		statusLabel(status) {
			return {
				entwurf: this.t('Entwurf'),
				aktiv: this.tc('Zustand', 'Aktiv'),
				ausgesetzt: this.t('Ausgesetzt'),
				erloschen: this.t('Erloschen'),
			}[status] || status
		},

		openGrantDialog(mode) {
			this.grantMode = mode
			this.grantDialogOpen = true
		},

		openAccountDialog(mode) {
			this.accountInitialMode = mode
			this.accountDialogOpen = true
		},

		async saveGrant(payload) {
			this.saving = true
			try {
				if (this.grantMode === 'confirm') {
					await api.selfConfirmMandate()
				} else {
					await api.selfGrantMandate(payload)
				}
				this.grantDialogOpen = false
				await this.reload()
				showSuccess(this.t('Mandat erteilt.'))
			} catch (e) {
				showError(errMsg(e, this.t('Erteilen fehlgeschlagen')))
			} finally {
				this.saving = false
			}
		},

		async requestLink() {
			this.requestingLink = true
			try {
				await api.selfRequestMandateActivationLink()
				showSuccess(this.t('Bestätigungslink wurde per Mail verschickt.'))
			} catch (e) {
				showError(errMsg(e, this.t('Versand fehlgeschlagen')))
			} finally {
				this.requestingLink = false
			}
		},

		async saveIban(payload) {
			this.saving = true
			try {
				await api.selfChangeMandateIban(payload)
				this.accountDialogOpen = false
				await this.reload()
				showSuccess(this.t('IBAN geändert.'))
			} catch (e) {
				showError(errMsg(e, this.t('Ändern fehlgeschlagen')))
			} finally {
				this.saving = false
			}
		},

		async saveHolder(payload) {
			this.saving = true
			try {
				await api.selfReplaceMandate(payload)
				this.accountDialogOpen = false
				await this.reload()
				showSuccess(this.t('Kontoinhaber gewechselt, neues Mandat erteilt.'))
			} catch (e) {
				showError(errMsg(e, this.t('Wechseln fehlgeschlagen')))
			} finally {
				this.saving = false
			}
		},

		async revoke() {
			this.saving = true
			try {
				await api.selfRevokeMandate()
				this.revokeOpen = false
				await this.reload()
				showSuccess(this.t('Mandat widerrufen.'))
			} catch (e) {
				showError(errMsg(e, this.t('Widerrufen fehlgeschlagen')))
			} finally {
				this.saving = false
			}
		},

		switchToIbanFromRevoke() {
			this.revokeOpen = false
			this.openAccountDialog('iban')
		},

		/** Den eigenen Entwurf verwerfen (Issue #118) - danach lässt sich ein neues Mandat erteilen; korrigieren kann nur die Verwaltung. */
		async discardDraft() {
			this.saving = true
			try {
				await api.selfDiscardMandateDraft()
				this.discardOpen = false
				await this.reload()
				showSuccess(this.t('Entwurf verworfen.'))
			} catch (e) {
				showError(errMsg(e, this.t('Verwerfen fehlgeschlagen')))
			} finally {
				this.saving = false
			}
		},

		withEditState(a) {
			return {
				...a,
				_form: { monthlyAmount: a.monthlyAmount, intervalMonths: a.intervalMonths },
				_preview: null,
				_previewedFor: null,
				_loadingPreview: false,
			}
		},

		/** Schlüssel der aktuellen Formularwerte - Grundlage für den Vorschau-Abgleich. */
		formKey(a) {
			return `${a._form.monthlyAmount}|${a._form.intervalMonths}`
		},

		previewMatchesForm(a) {
			return a._previewedFor === this.formKey(a)
		},

		/** Speichern ist erst erlaubt, wenn eine Vorschau GENAU für die aktuellen Werte vorliegt (Pflicht-UI). */
		canSave(a) {
			return a._preview !== null && this.previewMatchesForm(a)
		},

		async loadPreview(a) {
			a._loadingPreview = true
			try {
				const { data } = await api.selfPreviewAssignment(a.id, {
					monthlyAmount: a._form.monthlyAmount,
					intervalMonths: a._form.intervalMonths,
				})
				a._preview = data
				a._previewedFor = this.formKey(a)
			} catch (e) {
				a._preview = null
				a._previewedFor = null
				showError(errMsg(e, this.t('Vorschau konnte nicht geladen werden')))
			} finally {
				a._loadingPreview = false
			}
		},

		async save(a) {
			if (!this.canSave(a)) { return }
			try {
				const { data } = await api.selfUpdateAssignment(a.id, {
					monthlyAmount: a._form.monthlyAmount,
					intervalMonths: a._form.intervalMonths,
				})
				Object.assign(a, this.withEditState(data.assignment))
				showSuccess(this.t('Beitrag geändert.'))
			} catch (e) {
				showError(errMsg(e, this.t('Beitrag konnte nicht geändert werden')))
			}
		},

		startEditContact() {
			const m = this.member
			this.contactForm = {
				firstName: m.firstName || '',
				lastName: m.lastName || '',
				organizationName: m.organizationName || '',
				email: m.email || '',
				phone: m.phone || '',
				street: m.street || '',
				postalCode: m.postalCode || '',
				city: m.city || '',
				// Ohne gespeichertes Land steht das der bedienenden Person vorausgewählt da.
				country: m.country || defaultCountry(),
			}
			this.editingContact = true
		},

		cancelEditContact() {
			this.editingContact = false
		},

		async saveContact() {
			this.savingContact = true
			try {
				const { data } = await api.selfUpdateMe(this.contactForm)
				this.member = data
				this.editingContact = false
				showSuccess(this.t('Kontaktdaten gespeichert.'))
			} catch (e) {
				showError(errMsg(e, this.t('Kontaktdaten konnten nicht gespeichert werden')))
			} finally {
				this.savingContact = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-selfservice-card-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.vbh-selfservice-assignment {
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
	margin-top: 12px;
}

.vbh-selfservice-assignment:first-of-type {
	border-top: none;
	padding-top: 0;
	margin-top: 0;
}

.vbh-form-stack {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.vbh-selfservice-returns {
	list-style: none;
	margin: 0;
	padding: 0;
}

.vbh-selfservice-return {
	display: flex;
	flex-direction: column;
	gap: 2px;
	border-top: 1px solid var(--color-border);
	padding: 8px 0;
}

.vbh-selfservice-return:first-child {
	border-top: none;
	padding-top: 0;
}
</style>
