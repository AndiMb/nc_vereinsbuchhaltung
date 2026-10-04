<template>
	<section class="vbh-mandate-panel" aria-labelledby="vbh-mandate-heading">
		<h3 id="vbh-mandate-heading" class="vbh-modal-subtitle">
			{{ t('SEPA-Mandat') }}
		</h3>

		<NcLoadingIcon v-if="loading" :size="28" />
		<div v-else-if="loadError" class="vbh-hint vbh-hint--warning">
			{{ loadError }}
			<NcButton variant="tertiary" size="small" @click="load">
				{{ t('Erneut laden') }}
			</NcButton>
		</div>

		<template v-else>
			<!-- Das lebende Mandat (höchstens eines je Mitglied, Spec §2.2) -->
			<div v-if="current" class="vbh-card vbh-mandate-card">
				<div class="vbh-mandate-head">
					<span class="vbh-mandate-status" :class="'vbh-mandate-status--' + tone(current.status)">{{ statusLabel(current.status) }}</span>
					<span class="vbh-hint">{{ current.mandateReference }}</span>
					<!-- Sperre aus einer Rücklastschrift (setzt die App selbst, Spec §2.2) – auf einen Blick von der manuellen zu unterscheiden -->
					<span v-if="current.status === 'ausgesetzt' && current.suspensionOrigin === 'ruecklastschrift'" class="vbh-typetag">{{ t('Rücklastschrift') }}</span>
				</div>

				<p
					v-for="notice in notices"
					:key="notice.key"
					class="vbh-hint"
					:class="notice.tone === 'warning' ? 'vbh-hint--warning' : 'vbh-hint--info'">
					<strong>{{ notice.title }}</strong> – {{ notice.text }}
				</p>

				<dl class="vbh-mandate-fields">
					<template v-for="row in rows(current)" :key="row.key">
						<dt>{{ row.label }}</dt>
						<dd>{{ row.value }}</dd>
					</template>
				</dl>

				<!-- Änderungen der Bankverbindung (Spec §2.2 MandateAmendment) -->
				<template v-if="amendments.length">
					<h4 class="vbh-mandate-h4">
						{{ t('Änderungen der Bankverbindung') }}
					</h4>
					<ul class="vbh-mandate-amendments">
						<li v-for="a in amendments" :key="a.id">
							<span>
								{{ t('Bisherige IBAN {iban}', { iban: formatIban(a.oldIban) }) }}
								<span class="vbh-hint">· {{ formatStamp(a.createdAt) }}</span>
							</span>
							<span class="vbh-typetag" :class="{ 'vbh-typetag--open': a.status === 'open' }">{{ amendmentStatusLabel(a.status) }}</span>
							<NcButton
								v-if="mayWrite && a.status === 'transmitted'"
								variant="tertiary"
								size="small"
								:disabled="busy"
								@click="doReopenAmendment(a)">
								{{ t('Als nicht gemeldet zurücksetzen') }}
							</NcButton>
						</li>
					</ul>
				</template>

				<!-- Einmal-Link-Adresse zum Weitergeben, solange die Mail nicht ankommt (siehe MandateController::sendActivationLink()) -->
				<div v-if="sentLink" class="vbh-hint vbh-hint--info vbh-mandate-link">
					<span>{{ activationLinkSentText(sentLink.email) }}</span>
					<input
						:value="sentLink.url"
						readonly
						:aria-label="t('Einmal-Link')"
						@focus="$event.target.select()">
				</div>

				<div v-if="mayWrite" class="vbh-mcard-actions vbh-mandate-actions">
					<template v-if="current.status === 'entwurf'">
						<NcButton
							v-if="current.signatureType === 'papier'"
							variant="primary"
							:disabled="busy"
							@click="openAction('activate')">
							{{ t('Aktivieren') }}
						</NcButton>
						<NcButton
							v-else-if="current.signatureType === 'elektronisch'"
							variant="primary"
							:disabled="busy || !hasEmail"
							@click="doSendLink">
							{{ linkState === 'none' ? t('Einmal-Link senden') : t('Einmal-Link erneut senden') }}
						</NcButton>
						<!-- Ausweg aus einem Entwurf (Issue #118): Widerruf/Amendment gibt es erst für ein aktives Mandat -->
						<NcButton variant="secondary" :disabled="busy" @click="correctOpen = true">
							{{ t('Entwurf korrigieren') }}
						</NcButton>
						<NcButton variant="error" :disabled="busy" @click="discardOpen = true">
							{{ t('Entwurf verwerfen') }}
						</NcButton>
					</template>
					<template v-else-if="current.status === 'aktiv'">
						<NcButton variant="secondary" :disabled="busy" @click="openAccountDialog('iban')">
							{{ t('Bankverbindung ändern') }}
						</NcButton>
						<NcButton variant="secondary" :disabled="busy" @click="openAction('suspend')">
							{{ t('Sperren') }}
						</NcButton>
						<NcButton variant="error" :disabled="busy" @click="revokeOpen = true">
							{{ t('Mandat widerrufen') }}
						</NcButton>
					</template>
					<template v-else-if="current.status === 'ausgesetzt'">
						<NcButton variant="primary" :disabled="busy" @click="openAction('resume')">
							{{ t('Entsperren') }}
						</NcButton>
						<NcButton variant="error" :disabled="busy" @click="revokeOpen = true">
							{{ t('Mandat widerrufen') }}
						</NcButton>
					</template>
				</div>
				<p v-if="mayWrite && current.status === 'entwurf' && current.signatureType === 'elektronisch' && !hasEmail" class="vbh-hint">
					{{ t('Ohne Mailadresse beim Mitglied lässt sich kein Einmal-Link verschicken.') }}
				</p>

				<!-- Inline-Formulare der Schreibaktionen: je eine Aktion zur Zeit -->
				<div v-if="action === 'activate'" class="vbh-mandate-form">
					<div class="vbh-form">
						<label>{{ t('Mandat unterschrieben am') }}
							<input ref="focusTarget" v-model="activateDate" type="date">
						</label>
						<NcButton variant="primary" :disabled="busy || !activateDate" @click="doActivate">
							{{ t('Mandat aktivieren') }}
						</NcButton>
						<NcButton variant="tertiary" @click="closeAction">
							{{ t('Abbrechen') }}
						</NcButton>
					</div>
					<p class="vbh-hint">
						{{ t('Das Unterschriftsdatum ist Pflicht – es ist das Gate: erst damit wird das Mandat einzugsfähig.') }}
					</p>
					<p v-if="activateTooOld" class="vbh-hint vbh-hint--warning">
						{{ t('Das Unterschriftsdatum liegt mehr als 36 Monate zurück – das Mandat würde nach der Aktivierung sofort verfallen. Bitte prüfen Sie das Datum.') }}
					</p>
				</div>

				<div v-if="action === 'suspend'" class="vbh-mandate-form">
					<label class="vbh-mandate-note">{{ t('Grund der Sperre (Pflicht)') }}
						<textarea
							ref="focusTarget"
							v-model="note"
							rows="2"
							:placeholder="t('z. B. Rückfrage beim Mitglied wegen Kontowechsel')" />
					</label>
					<p class="vbh-hint">
						{{ t('Ein gesperrtes Mandat ist nicht einzugsfähig, beendet aber nichts – offene Forderungen bleiben offen.') }}
					</p>
					<div class="vbh-modal-actions">
						<NcButton variant="tertiary" @click="closeAction">
							{{ t('Abbrechen') }}
						</NcButton>
						<NcButton variant="primary" :disabled="busy || !note.trim()" @click="doSuspend">
							{{ t('Mandat sperren') }}
						</NcButton>
					</div>
				</div>

				<div v-if="action === 'resume'" class="vbh-mandate-form">
					<label class="vbh-mandate-note">{{ t('Notiz zur Klärung (Pflicht)') }}
						<textarea
							ref="focusTarget"
							v-model="note"
							rows="2"
							:placeholder="t('z. B. Kontoverbindung telefonisch bestätigt')" />
					</label>
					<p class="vbh-hint">
						{{ t('Es gibt keine Auto-Entsperrung: Mit dem Entsperren sind Sie sicher, dass der Grund der Sperre ausgeräumt ist. Die Notiz steht im Verlauf.') }}
					</p>
					<div class="vbh-modal-actions">
						<NcButton variant="tertiary" @click="closeAction">
							{{ t('Abbrechen') }}
						</NcButton>
						<NcButton variant="primary" :disabled="busy || !note.trim()" @click="doResume">
							{{ t('Mandat entsperren') }}
						</NcButton>
					</div>
				</div>

				<!-- Nachweis und Formular -->
				<h4 class="vbh-mandate-h4">
					{{ t('Nachweis und Formular') }}
				</h4>
				<div class="vbh-uploadrow">
					<template v-if="mayWrite">
						<label class="vbh-filebtn">{{ current.hasDocument ? t('Nachweis ersetzen') : t('Nachweis hochladen') }}<input
							type="file"
							accept="application/pdf,image/png,image/jpeg,image/webp"
							hidden
							:disabled="uploading"
							@change="onDocumentSelected"></label>
						<a
							v-if="current.hasDocument"
							:href="documentUrl(current.id)"
							class="vbh-export-btn">{{ t('Nachweis herunterladen') }}</a>
						<a
							:href="formUrl(current.id)"
							target="_blank"
							rel="noopener"
							class="vbh-export-btn">{{ t('Mandatsformular öffnen') }}</a>
					</template>
					<span v-if="!current.hasDocument" class="vbh-hint">{{ t('Noch kein Nachweis hinterlegt.') }}</span>
				</div>

				<!-- Verlauf: wer, wann, was -->
				<details class="vbh-mandate-history">
					<summary>{{ n('Verlauf (%n Eintrag)', 'Verlauf (%n Einträge)', current.history.length) }}</summary>
					<MandateEventList :events="current.history" />
				</details>
			</div>

			<!-- Kein lebendes Mandat -->
			<div v-else class="vbh-card vbh-mandate-card">
				<p v-if="endedNotice" class="vbh-hint vbh-hint--warning">
					<strong>{{ endedNotice.title }}</strong> – {{ endedNotice.text }}
				</p>
				<p v-else class="vbh-hint">
					{{ t('Noch kein Mandat hinterlegt. Ohne aktives Mandat ist kein Lastschrifteinzug möglich.') }}
				</p>

				<div v-if="mayWrite && action !== 'create'" class="vbh-mcard-actions vbh-mandate-actions">
					<NcButton variant="primary" :disabled="busy" @click="openAction('create')">
						{{ t('Mandat anlegen') }}
					</NcButton>
				</div>

				<div v-if="action === 'create'" class="vbh-mandate-form">
					<div class="vbh-form">
						<label>{{ t('Art der Unterschrift') }}
							<select ref="focusTarget" v-model="createForm.signatureType">
								<option value="papier">
									{{ t('Papier') }}
								</option>
								<option value="elektronisch" :disabled="!hasEmail">
									{{ t('Elektronisch (Einmal-Link per Mail)') }}
								</option>
							</select>
						</label>
						<label class="vbh-grow">{{ t('IBAN') }}
							<input v-model="createForm.iban" placeholder="DE12 5001 0517 0648 4898 90">
						</label>
						<label>{{ t('BIC') }}
							<input v-model="createForm.bic" class="vbh-short" :placeholder="t('optional')">
						</label>
					</div>
					<div class="vbh-form">
						<label class="vbh-grow">{{ t('Kontoinhaber') }}
							<input v-model="createForm.accountHolder" :placeholder="member.displayName || t('sonst Anzeigename des Mitglieds')">
						</label>
						<label>{{ t('Mandatsreferenz') }}
							<input v-model="createForm.mandateReference" :placeholder="t('sonst automatisch vergeben')">
						</label>
						<label v-if="createForm.signatureType === 'papier'">{{ t('Mandat unterschrieben am') }}
							<input v-model="createForm.signedAt" type="date">
						</label>
					</div>
					<p v-if="createForm.signatureType === 'papier'" class="vbh-hint">
						{{ t('Ohne Unterschriftsdatum bleibt das Mandat ein Entwurf („Unterschrift fehlt“) – erst das Datum aktiviert es sofort.') }}
					</p>
					<p v-else class="vbh-hint">
						{{ t('Nach dem Anlegen geht sofort ein Einmal-Link an die Mailadresse des Mitglieds – die Zustimmung dort aktiviert das Mandat.') }}
					</p>
					<div class="vbh-modal-actions">
						<NcButton variant="tertiary" @click="closeAction">
							{{ t('Abbrechen') }}
						</NcButton>
						<NcButton variant="primary" :disabled="busy || !createForm.iban.trim()" @click="doCreate">
							{{ t('Mandat anlegen') }}
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Beendete Mandate -->
			<template v-if="past.length">
				<h4 class="vbh-mandate-h4">
					{{ t('Frühere Mandate') }}
				</h4>
				<details
					v-for="m in past"
					:key="m.id"
					class="vbh-mandate-past"
					@toggle="onPastToggle(m, $event)">
					<summary>
						{{ pastSummary(m) }}
					</summary>
					<dl class="vbh-mandate-fields">
						<template v-for="row in rows(m)" :key="row.key">
							<dt>{{ row.label }}</dt>
							<dd>{{ row.value }}</dd>
						</template>
					</dl>
					<MandateEventList v-if="pastHistory[m.id]" :events="pastHistory[m.id]" />
					<NcLoadingIcon v-else :size="20" />
				</details>
			</template>
		</template>

		<MandateAccountDialog
			:show="accountDialogOpen"
			:mandate="current"
			:initialMode="accountInitialMode"
			:openClaimsTotalCents="openClaimsTotalCents"
			:saving="busy"
			@close="accountDialogOpen = false"
			@saveIban="doAmend"
			@saveName="doCorrectName"
			@saveHolder="doReplace" />
		<MandateDraftCorrectDialog
			:show="correctOpen"
			:mandate="current"
			:saving="busy"
			@close="correctOpen = false"
			@save="doCorrectDraft" />
		<MandateDraftDiscardDialog
			:show="discardOpen"
			:staff="true"
			:electronic="!!current && current.signatureType === 'elektronisch'"
			:saving="busy"
			@close="discardOpen = false"
			@switchToCorrect="switchToCorrectFromDiscard"
			@save="doDiscardDraft" />
		<SelfServiceMandateRevokeDialog
			:show="revokeOpen"
			:staff="true"
			:memberName="member.displayName"
			:openClaimsTotalCents="openClaimsTotalCents"
			:ibanChangeBlocked="!current || current.status !== 'aktiv'"
			:saving="busy"
			@close="revokeOpen = false"
			@switchToIban="switchToIbanFromRevoke"
			@save="doRevoke" />
	</section>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import MandateAccountDialog from './MandateAccountDialog.vue'
import MandateDraftCorrectDialog from './MandateDraftCorrectDialog.vue'
import MandateDraftDiscardDialog from './MandateDraftDiscardDialog.vue'
import MandateEventList from './MandateEventList.vue'
import SelfServiceMandateRevokeDialog from './SelfServiceMandateRevokeDialog.vue'
import api from '../api.js'
import { useAuth } from '../composables/useAuth.js'
import { errMsg, formatDate } from '../lib/format.js'
import { createMandateForMember } from '../lib/mandateCreate.js'
import {
	activationLinkSentText,
	activationLinkState,
	amendmentStatusLabel,
	daysUntil,
	endReasonLabel,
	formatIban,
	formatStamp,
	mandateNotices,
	signatureTooOld,
	signatureTypeLabel,
	statusLabel,
	statusTone,
	suspensionOriginLabel,
} from '../lib/mandateView.js'

const emptyCreateForm = () => ({ signatureType: 'papier', iban: '', bic: '', accountHolder: '', mandateReference: '', signedAt: '' })

/**
 * Mandat-Verwaltung in der Mitglieder-Akte (Issue #100, Spec §2.2/§3.2): der
 * ganze Lebenszyklus eines Mandats ohne API – Anzeige samt Störfall-Hinweisen,
 * Papier-Mandat aktivieren, sperren/entsperren (je mit Pflicht-Notiz),
 * widerrufen (Reibungsdialog), IBAN ändern / Kontoinhaber wechseln / Namen
 * korrigieren, Einmal-Link senden, Nachweis und druckfertiges Formular, dazu
 * die beendeten Mandate und die Ereignishistorie.
 *
 * Eigene Komponente statt weiterer Abschnitte in MemberDialog.vue, das nur
 * `<MandatePanel>` einbindet und `changed` nach oben reicht (MembersList.vue
 * lädt dann die Liste neu). Lädt seine Daten selbst (`mandatesByMember` +
 * Einzelansicht des lebenden Mandats) und lädt nach jeder Aktion neu.
 *
 * Ein Entwurf lässt sich korrigieren (IBAN/BIC/Kontoinhaber ohne Amendment) oder
 * verwerfen (Pflicht-Notiz, Issue #118) – sonst hätte er keinen Ausweg, denn
 * Widerruf und Amendment gibt es erst für ein aktives Mandat.
 *
 * Rollen (Spec §3.9): Schreibaktionen nur ab `buchhalter` (Backend:
 * RequiresRole(ROLE_WRITE), hier zusätzlich ausgeblendet); die Akte selbst
 * erreicht `revisor` gar nicht – die IBAN steht deshalb unmaskiert da.
 */
export default {
	name: 'MandatePanel',
	components: { NcButton, NcLoadingIcon, MandateAccountDialog, MandateDraftCorrectDialog, MandateDraftDiscardDialog, MandateEventList, SelfServiceMandateRevokeDialog },
	props: {
		/** Die Akte (dekoriertes Mitglied): id, displayName, email. */
		member: { type: Object, required: true },
		/** Nach einer DSGVO-Anonymisierung nur noch lesen. */
		readonly: { type: Boolean, default: false },
	},

	emits: ['changed'],

	setup() {
		return { canWrite: useAuth().canWrite }
	},

	data() {
		return {
			loading: true,
			/** Erst nach dem ersten Laden; danach tauscht load() die Anzeige leise aus. */
			loaded: false,
			loadError: '',
			/** Alle Mandate des Mitglieds, neueste zuerst (Liste ohne Historie). */
			mandates: [],
			/** Einzelansicht des lebenden Mandats (inkl. history/amendments/activationLink/openClaimsTotalCents) oder null. */
			current: null,
			/** Historien beendeter Mandate, beim ersten Aufklappen nachgeladen: { [mandateId]: events[] }. */
			pastHistory: {},
			/** Inline-Formular: null | 'create' | 'activate' | 'suspend' | 'resume'. */
			action: null,
			createForm: emptyCreateForm(),
			activateDate: '',
			note: '',
			busy: false,
			uploading: false,
			accountDialogOpen: false,
			accountInitialMode: 'iban',
			revokeOpen: false,
			/** Entwurf korrigieren/verwerfen (Issue #118), nur im Zustand `entwurf` erreichbar. */
			correctOpen: false,
			discardOpen: false,
			/** Adresse des zuletzt verschickten Einmal-Links (nur diese Sitzung): { url, email }. */
			sentLink: null,
		}
	},

	computed: {
		mayWrite() { return this.canWrite && !this.readonly },

		hasEmail() { return !!(this.member.email && this.member.email.trim()) },

		past() { return this.mandates.filter((m) => m.status === 'erloschen') },

		/** Ohne lebendes Mandat gilt der Störfall „neues Mandat einholen“ des zuletzt erloschenen. */
		endedNotice() {
			if (this.current || !this.past.length) { return null }
			return mandateNotices(this.past[0]).find((n) => n.key === 'ended') ?? null
		},

		notices() { return this.current ? mandateNotices(this.current) : [] },

		amendments() { return this.current?.amendments ?? [] },

		linkState() { return activationLinkState(this.current?.activationLink) },

		openClaimsTotalCents() { return this.current?.openClaimsTotalCents ?? 0 },

		activateTooOld() { return signatureTooOld(this.activateDate) },
	},

	mounted() {
		this.load()
	},

	methods: {
		formatDate,
		formatStamp,
		formatIban,
		activationLinkSentText,
		statusLabel,
		endReasonLabel,
		amendmentStatusLabel,
		tone: statusTone,
		documentUrl: (id) => api.mandateDocumentUrl(id),
		formUrl: (id) => api.mandateFormUrl(id),

		async load() {
			// Nur das erste Laden zeigt den Ladekreis – ein Neuladen nach einer Aktion
			// tauscht die Anzeige leise aus, statt den Bereich kurz zu leeren.
			if (!this.loaded) { this.loading = true }
			try {
				const { data } = await api.mandatesByMember(this.member.id)
				this.mandates = data
				const live = data.find((m) => m.status !== 'erloschen')
				this.current = live ? (await api.getMandate(live.id)).data : null
				this.pastHistory = {}
				this.loadError = ''
			} catch (e) {
				this.loadError = errMsg(e, this.t('Das Mandat konnte nicht geladen werden.'))
			} finally {
				this.loading = false
				this.loaded = true
			}
		},

		/** Nach jeder erfolgreichen Schreibaktion: Liste oben informieren, eigene Anzeige neu laden. */
		async afterChange() {
			this.$emit('changed')
			await this.load()
		},

		/** Führt eine Schreibaktion aus; `busy` sperrt die Knöpfe, Fehlermeldungen kommen vom Backend. */
		async run(fn, failure) {
			this.busy = true
			try {
				await fn()
				return true
			} catch (e) {
				showError(errMsg(e, failure))
				return false
			} finally {
				this.busy = false
			}
		},

		/** Kopfzeile eines beendeten Mandats: Referenz, Endgrund, Enddatum. */
		pastSummary(m) {
			return [m.mandateReference, endReasonLabel(m.endReason), m.endedAt ? formatDate(m.endedAt) : null].filter(Boolean).join(' · ')
		},

		async onPastToggle(mandate, event) {
			if (!event.target.open || this.pastHistory[mandate.id]) { return }
			try {
				const { data } = await api.getMandate(mandate.id)
				this.pastHistory = { ...this.pastHistory, [mandate.id]: data.history }
			} catch (e) {
				showError(errMsg(e, this.t('Der Verlauf konnte nicht geladen werden')))
			}
		},

		/** Zeilen der Definitionsliste – für das lebende wie für beendete Mandate. */
		rows(m) {
			const rows = [
				{ key: 'ref', label: this.t('Mandatsreferenz'), value: m.mandateReference },
				{ key: 'holder', label: this.t('Kontoinhaber'), value: m.accountHolder },
				{ key: 'iban', label: this.t('IBAN'), value: formatIban(m.iban) || '–' },
				{ key: 'bic', label: this.t('BIC'), value: m.bic || '–' },
				{
					key: 'signature',
					label: this.t('Unterschrift'),
					value: signatureTypeLabel(m.signatureType) + (m.signedAt ? ` · ${formatDate(m.signedAt)}` : ''),
				},
			]
			if (m.consentAt) {
				rows.push({
					key: 'consent',
					label: this.t('Zustimmung'),
					value: this.t('{zeit} durch {wer} (IP {ip})', { zeit: formatStamp(m.consentAt), wer: m.consentActor || '–', ip: m.consentIp || '–' }),
				})
			}
			if (m.status === 'aktiv' || m.status === 'ausgesetzt') {
				const days = daysUntil(m.expiresAt)
				rows.push({
					key: 'expires',
					label: this.t('Läuft ab (36-Monats-Regel)'),
					value: m.expiresAt
						? formatDate(m.expiresAt) + (days !== null && days >= 0 ? ' · ' + this.n('in %n Tag', 'in %n Tagen', days) : '')
						: '–',
				})
			} else if (m.status === 'entwurf') {
				rows.push({ key: 'expires', label: this.t('Läuft ab (36-Monats-Regel)'), value: this.t('beginnt mit der Aktivierung') })
			}
			if (m.lastPresentedDueDate) {
				rows.push({ key: 'presented', label: this.t('Zuletzt eingereicht für'), value: formatDate(m.lastPresentedDueDate) })
			}
			rows.push({ key: 'document', label: this.t('Nachweis'), value: m.hasDocument ? this.t('vorhanden') : this.t('fehlt') })
			if (m.activatedAt) {
				rows.push({ key: 'activated', label: this.t('Aktiviert'), value: formatStamp(m.activatedAt) })
			}
			if (m.status === 'ausgesetzt') {
				rows.push({
					key: 'suspension',
					label: this.t('Sperre'),
					value: this.t('{art} seit {zeit}{von}', {
						art: suspensionOriginLabel(m.suspensionOrigin),
						zeit: formatStamp(m.suspendedAt),
						von: m.suspendedBy ? this.t(' (von {uid})', { uid: m.suspendedBy }) : '',
					}),
				})
				if (m.suspensionNote) {
					rows.push({ key: 'suspension-note', label: this.t('Notiz zur Sperre'), value: m.suspensionNote })
				}
			}
			if (m.status === 'erloschen') {
				rows.push({ key: 'ended', label: this.t('Beendet'), value: `${formatStamp(m.endedAt)} · ${endReasonLabel(m.endReason)}` })
			}
			if (m.status === 'entwurf' && m.signatureType === 'elektronisch') {
				rows.push({ key: 'link', label: this.t('Einmal-Link'), value: this.linkText(m.activationLink) })
			}
			return rows
		},

		/** Status des Einmal-Links in Klartext (gesendet am, abgelaufen). */
		linkText(link) {
			if (!link) { return this.t('noch nicht versendet') }
			const sent = this.t('gesendet am {zeit} an {email}', { zeit: formatStamp(link.sentAt), email: link.email })
			const state = link.expired
				? this.t('abgelaufen am {zeit}', { zeit: formatStamp(link.expiresAt) })
				: this.t('gültig bis {zeit}', { zeit: formatStamp(link.expiresAt) })
			const viewed = link.firstViewedAt ? this.t('vom Mitglied geöffnet am {zeit}', { zeit: formatStamp(link.firstViewedAt) }) : null
			return [sent, state, viewed].filter(Boolean).join(' · ')
		},

		// --- Inline-Formulare ----------------------------------------------------

		openAction(name) {
			this.action = name
			this.note = ''
			this.createForm = emptyCreateForm()
			// Das Unterschriftsdatum eines Entwurfs ist vorbelegt, wenn es schon beim Anlegen
			// eingetragen wurde; sonst bleibt das Feld bewusst leer – das Datum steht auf dem
			// Papier, ein Vorschlagswert wäre eine falsche Auskunft.
			this.activateDate = this.current?.signedAt ?? ''
			this.$nextTick(() => this.$refs.focusTarget?.focus())
		},

		closeAction() { this.action = null },

		// --- Aktionen --------------------------------------------------------------

		async doCreate() {
			const form = this.createForm
			const ok = await this.run(async () => {
				const created = await createMandateForMember(this.member.id, {
					signatureType: form.signatureType === 'elektronisch' ? 'elektronisch' : 'papier',
					iban: form.iban.trim(),
					bic: form.bic.trim() || null,
					accountHolder: form.accountHolder.trim() || null,
					mandateReference: form.mandateReference.trim() || null,
					signedAt: form.signatureType === 'papier' ? (form.signedAt || null) : null,
				})
				this.sentLink = null
				showSuccess(form.signatureType === 'elektronisch'
					? this.t('Entwurf angelegt, Einmal-Link an {email} verschickt.', { email: this.member.email })
					: (form.signedAt ? this.t('Mandat angelegt und aktiviert.') : this.t('Mandat als Entwurf angelegt – die Unterschrift fehlt noch.')))
				return created
			}, this.t('Das Mandat konnte nicht angelegt werden'))
			// Auch bei einem Fehler neu laden: ein schon angelegter Entwurf (z. B. wenn nur der
			// Versand scheiterte) steht dann in der Anzeige, statt unsichtbar zu bleiben.
			if (ok) { this.closeAction() }
			await this.afterChange()
		},

		async doActivate() {
			const ok = await this.run(async () => {
				await api.activateMandate(this.current.id, this.activateDate)
				showSuccess(this.t('Mandat aktiviert.'))
			}, this.t('Aktivieren fehlgeschlagen'))
			if (ok) {
				this.closeAction()
				await this.afterChange()
			}
		},

		async doSuspend() {
			const ok = await this.run(async () => {
				await api.suspendMandate(this.current.id, this.note.trim())
				showSuccess(this.t('Mandat gesperrt.'))
			}, this.t('Sperren fehlgeschlagen'))
			if (ok) {
				this.closeAction()
				await this.afterChange()
			}
		},

		async doResume() {
			const ok = await this.run(async () => {
				await api.resumeMandate(this.current.id, this.note.trim())
				showSuccess(this.t('Mandat entsperrt.'))
			}, this.t('Entsperren fehlgeschlagen'))
			if (ok) {
				this.closeAction()
				await this.afterChange()
			}
		},

		async doRevoke() {
			const ok = await this.run(async () => {
				await api.revokeMandate(this.current.id)
				showSuccess(this.t('Mandat widerrufen.'))
			}, this.t('Widerrufen fehlgeschlagen'))
			if (ok) {
				this.revokeOpen = false
				this.closeAction()
				await this.afterChange()
			}
		},

		switchToIbanFromRevoke() {
			this.revokeOpen = false
			this.openAccountDialog('iban')
		},

		/** Entwurf direkt korrigieren, ohne Amendment (Issue #118); ein ausgesendeter Einmal-Link wird dabei ungültig. */
		async doCorrectDraft({ iban, bic, accountHolder }) {
			const linkWasSent = this.current.signatureType === 'elektronisch' && this.linkState !== 'none'
			const ok = await this.run(async () => {
				await api.correctMandateDraft(this.current.id, { iban, bic, accountHolder })
				// Die zuletzt gezeigte Link-Adresse gilt nicht mehr.
				this.sentLink = null
				showSuccess(linkWasSent
					? this.t('Entwurf korrigiert. Der bisherige Einmal-Link ist ungültig – senden Sie dem Mitglied einen neuen.')
					: this.t('Entwurf korrigiert.'))
			}, this.t('Korrigieren fehlgeschlagen'))
			if (ok) {
				this.correctOpen = false
				await this.afterChange()
			}
		},

		/** Entwurf verwerfen (Issue #118): endet mit Pflicht-Notiz, danach lässt sich ein neues Mandat anlegen. */
		async doDiscardDraft(note) {
			const ok = await this.run(async () => {
				await api.discardMandateDraft(this.current.id, note)
				this.sentLink = null
				showSuccess(this.t('Entwurf verworfen.'))
			}, this.t('Verwerfen fehlgeschlagen'))
			if (ok) {
				this.discardOpen = false
				this.closeAction()
				await this.afterChange()
			}
		},

		/** Der Ausweg im Verwerfen-Dialog („nur ein Tippfehler“): zur Korrektur wechseln. */
		switchToCorrectFromDiscard() {
			this.discardOpen = false
			this.correctOpen = true
		},

		openAccountDialog(mode) {
			this.accountInitialMode = mode
			this.accountDialogOpen = true
		},

		async doAmend({ iban, bic }) {
			const ok = await this.run(async () => {
				await api.amendMandateBankDetails(this.current.id, iban, bic)
				showSuccess(this.t('Bankverbindung geändert – die Bank erfährt es mit dem nächsten Einzug.'))
			}, this.t('Ändern fehlgeschlagen'))
			if (ok) {
				this.accountDialogOpen = false
				await this.afterChange()
			}
		},

		async doCorrectName({ accountHolder }) {
			const ok = await this.run(async () => {
				await api.correctMandateAccountHolder(this.current.id, accountHolder)
				showSuccess(this.t('Kontoinhaber korrigiert.'))
			}, this.t('Korrigieren fehlgeschlagen'))
			if (ok) {
				this.accountDialogOpen = false
				await this.afterChange()
			}
		},

		/** Kontoinhaberwechsel: neues Mandat (Papier-Entwurf), das alte endet als ersetzt; mit Unterschriftsdatum gleich aktiv (Spec §3.1: das Datum ist das Gate). */
		async doReplace({ iban, bic, accountHolder, signedAt }) {
			const ok = await this.run(async () => {
				const { data } = await api.replaceMandate(this.current.id, { iban, bic, accountHolder, signedAt })
				if (signedAt) { await api.activateMandate(data.id) }
				showSuccess(signedAt
					? this.t('Kontoinhaber gewechselt, neues Mandat aktiviert.')
					: this.t('Kontoinhaber gewechselt – das neue Mandat ist ein Entwurf, bis die Unterschrift vorliegt.'))
			}, this.t('Wechseln fehlgeschlagen'))
			if (ok) { this.accountDialogOpen = false }
			// Wie beim Anlegen auch im Fehlerfall neu laden: das alte Mandat kann schon ersetzt sein.
			await this.afterChange()
		},

		async doSendLink() {
			const ok = await this.run(async () => {
				const { data } = await api.sendMandateActivationLink(this.current.id)
				this.sentLink = { url: data.activationUrl, email: data.sentTo }
				showSuccess(this.t('Einmal-Link an {email} verschickt.', { email: data.sentTo }))
			}, this.t('Versand fehlgeschlagen'))
			if (ok) { await this.afterChange() }
		},

		async doReopenAmendment(amendment) {
			const ok = await this.run(async () => {
				await api.reopenMandateAmendment(amendment.id)
				showSuccess(this.t('Änderung wieder auf „offen“ gesetzt.'))
			}, this.t('Zurücksetzen fehlgeschlagen'))
			if (ok) { await this.afterChange() }
		},

		async onDocumentSelected(event) {
			const file = event.target.files?.[0]
			event.target.value = ''
			if (!file) { return }
			this.uploading = true
			try {
				const fd = new FormData()
				fd.append('file', file)
				await api.uploadMandateDocument(this.current.id, fd)
				showSuccess(this.t('Nachweis hinterlegt.'))
				await this.afterChange()
			} catch (e) {
				showError(errMsg(e, this.t('Der Nachweis konnte nicht hochgeladen werden')))
			} finally {
				this.uploading = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-mandate-head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 12px;
	margin-bottom: 8px;
}

/*
 * Statusmarke: Flächen- und Schriftfarbe immer als Paar (--color-success +
 * --color-success-text usw.), sonst ist sie im hellen ODER dunklen Design
 * unlesbar (siehe Kopfkommentar in styles.css).
 */
.vbh-mandate-status {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 10px;
	font-weight: 700;
	font-size: 0.9em;
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
	box-shadow: inset 0 0 0 1px var(--color-border);
}

.vbh-mandate-status--success {
	background-color: var(--color-success);
	color: var(--color-success-text);
	box-shadow: inset 0 0 0 1px var(--color-element-success);
}

.vbh-mandate-status--warning {
	background-color: var(--color-warning);
	color: var(--color-warning-text);
	box-shadow: inset 0 0 0 1px var(--color-element-warning);
}

.vbh-mandate-status--muted {
	opacity: 0.85;
}

.vbh-mandate-fields {
	display: grid;
	grid-template-columns: max-content 1fr;
	column-gap: 16px;
	row-gap: 6px;
	margin: 8px 0 0;
}

.vbh-mandate-fields dt {
	color: var(--color-text-maxcontrast);
}

.vbh-mandate-fields dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.vbh-mandate-h4 {
	margin: 18px 0 6px;
	font-size: 0.95em;
}

.vbh-mandate-amendments {
	list-style: none;
	margin: 0;
	padding: 0;
	display: grid;
	gap: 6px;
}

.vbh-mandate-amendments li {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px 12px;
}

/* Noch nicht gemeldete Änderung: Warnpaar statt einfarbigem Rand, damit sie in beiden Designs lesbar bleibt. */
.vbh-typetag--open {
	background-color: var(--color-warning);
	color: var(--color-warning-text);
}

.vbh-mandate-link {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.vbh-mandate-link input {
	width: 100%;
}

.vbh-mandate-actions {
	margin-top: 12px;
	gap: 8px;
}

.vbh-mandate-form {
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.vbh-mandate-note {
	display: flex;
	flex-direction: column;
	gap: 3px;
	font-size: 0.85em;
}

.vbh-mandate-note textarea {
	width: 100%;
}

.vbh-mandate-history,
.vbh-mandate-past {
	margin-top: 12px;
}

.vbh-mandate-history summary,
.vbh-mandate-past summary {
	cursor: pointer;
	font-weight: 600;
}

.vbh-mandate-past {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}

/* Schmale Displays: Bezeichnung über Wert statt nebeneinander (lange IBANs und Meldungen sprengen sonst die Breite). */
@media (max-width: 640px) {
	.vbh-mandate-fields {
		grid-template-columns: 1fr;
		row-gap: 0;
	}

	.vbh-mandate-fields dd {
		margin-bottom: 8px;
	}
}
</style>
