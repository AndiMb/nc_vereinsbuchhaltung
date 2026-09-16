<template>
	<div class="vbh-selfservice">
		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else-if="member">
			<div class="vbh-card">
				<h4>{{ t('Meine Stammdaten') }}</h4>
				<dl class="vbh-selfservice-fields">
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
				<p class="vbh-hint">
					{{ t('Bisher keine Rücklastschrift.') }}
				</p>
			</div>
		</template>
		<p v-else class="vbh-hint vbh-hint--warning">
			{{ t('Deine Stammdaten konnten nicht geladen werden.') }}
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
			:saving="saving"
			@close="revokeOpen = false"
			@switchToIban="switchToIbanFromRevoke"
			@save="revoke" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import SelfServiceMandateAccountDialog from './SelfServiceMandateAccountDialog.vue'
import SelfServiceMandateGrantDialog from './SelfServiceMandateGrantDialog.vue'
import SelfServiceMandateRevokeDialog from './SelfServiceMandateRevokeDialog.vue'
import api from '../api.js'
import { errMsg, formatDate } from '../lib/format.js'

/**
 * Bereich „Mein Beitrag" (Spec §3.4): eigene Stammdaten (#74) plus, seit
 * Issue #75, der Mandats-Aktionskatalog - erfassen/elektronisch erteilen,
 * bestehenden Entwurf bestätigen, IBAN ändern, Kontoinhaber wechseln,
 * widerrufen. Jede Aktion wirkt sofort (kein Antragsmodell); die einzige
 * Bremse ist die Vorschau im jeweiligen Dialog (Spec Pflicht-UI).
 *
 * Der Beitrags-Teil des Aktionskatalogs (#76) ist NICHT Teil dieser Komponente.
 *
 * Lädt seine Daten beim eigenen mounted() wie MembersList.vue/SepaBatchPanel.vue,
 * statt von App.vue vorgeladen zu werden.
 */
export default {
	name: 'SelfServiceTab',
	components: { NcLoadingIcon, NcButton, SelfServiceMandateGrantDialog, SelfServiceMandateAccountDialog, SelfServiceMandateRevokeDialog },

	data() {
		return {
			loading: true,
			member: null,
			grantDialogOpen: false,
			grantMode: 'grant',
			accountDialogOpen: false,
			accountInitialMode: 'iban',
			revokeOpen: false,
			saving: false,
			requestingLink: false,
		}
	},

	computed: {
		addressLine() {
			const m = this.member
			if (!m) { return '' }
			const line1 = m.street || ''
			const line2 = [m.postalCode, m.city].filter(Boolean).join(' ')
			return [line1, line2].filter(Boolean).join(', ')
		},

		mandate() {
			return this.member && this.member.mandate
		},

		openClaimsTotalCents() {
			return (this.member && this.member.openClaimsTotalCents) || 0
		},

		/** Ein von der Verwaltung angelegter elektronischer Entwurf, der noch auf Zustimmung wartet (Issue #67/#75). */
		isUnconfirmedElectronicDraft() {
			return !!this.mandate && this.mandate.status === 'entwurf' && this.mandate.signatureType === 'elektronisch'
		},
	},

	async mounted() {
		await this.reload()
	},

	methods: {
		formatDate,

		async reload() {
			this.loading = true
			try {
				const { data } = await api.selfMe()
				this.member = data
			} catch (e) {
				showError(errMsg(e, this.t('Stammdaten konnten nicht geladen werden')))
			} finally {
				this.loading = false
			}
		},

		statusLabel(status) {
			return {
				entwurf: this.t('Entwurf'),
				aktiv: this.t('Aktiv'),
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
	},
}
</script>
