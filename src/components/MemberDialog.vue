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
					{{ t('Optional gleich SEPA-Mandat und Beitrag miterfassen – beides lässt sich auch später ergänzen.') }}
				</p>
				<div class="vbh-form">
					<label class="vbh-grow">{{ t('IBAN') }}
						<input v-model="form.iban" placeholder="DE12 5001 0517 0648 4898 90">
					</label>
					<label>{{ t('BIC') }}
						<input v-model="form.bic" class="vbh-short" :placeholder="t('optional')">
					</label>
					<label>{{ t('Mandat unterschrieben am') }}
						<input v-model="form.signedDate" type="date">
					</label>
				</div>
				<div class="vbh-form">
					<label>{{ t('Betrag (€)') }}
						<AmountInput v-model="form.amount" class="vbh-short" />
					</label>
					<label>{{ t('Frequenz') }}
						<select v-model="form.frequency">
							<option v-for="f in frequencies" :key="f.value" :value="f.value">
								{{ f.label }}
							</option>
						</select>
					</label>
					<label>{{ t('Erste Fälligkeit') }}
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
				</div>
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
import { useAccounts } from '../composables/useAccounts.js'
import { useConfirm } from '../composables/useConfirm.js'
import { errMsg } from '../lib/format.js'
import { frequencyOptions } from '../lib/frequency.js'
import { focusOnOpen } from '../lib/modalFocus.js'

function emptyForm(member, defaultFeeAmount, defaultFeeFrequency) {
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
		// Nur beim Anlegen relevant (siehe Template) – optionales Mandat/Beitrag.
		iban: '',
		bic: '',
		signedDate: new Date().toISOString().slice(0, 10),
		amount: defaultFeeAmount ?? '',
		frequency: defaultFeeFrequency ?? 'yearly',
		startDate: new Date().toISOString().slice(0, 10),
		accountId: null,
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
		// leerer String heisst "kein Standardbeitrag hinterlegt".
		defaultFeeAmount: { type: [Number, String], default: '' },
		defaultFeeFrequency: { type: String, default: 'yearly' },
	},

	emits: ['close', 'save', 'update:show', 'changed'],

	setup() {
		return { ...toRefs(useAccounts().state), askConfirm: useConfirm().askConfirm }
	},

	data() {
		return {
			form: emptyForm(this.member, this.defaultFeeAmount, this.defaultFeeFrequency),
			frequencies: frequencyOptions(),
			suggestions: [],
			suggestionsLoaded: false,
			linking: false,
			leaving: false,
			deleting: false,
			leaveDate: new Date().toISOString().slice(0, 10),
		}
	},

	computed: {
		isEdit() { return this.member !== null },

		incomeAccounts() {
			return this.accounts.filter((a) => a.type === 'income' && !a.isBank)
				.slice()
				.sort((a, b) => String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
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
			this.form = emptyForm(this.member, this.defaultFeeAmount, this.defaultFeeFrequency)
			this.suggestions = []
			this.suggestionsLoaded = false
			this.leaveDate = new Date().toISOString().slice(0, 10)
			focusOnOpen(this, () => this.$refs.nameInput || this.$refs.orgInput)
		},
	},

	methods: {
		errMsg,

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
					? { iban: this.form.iban.trim(), bic: this.form.bic.trim() || null, signedDate: this.form.signedDate }
					: null
				payload.fee = Number(this.form.amount) > 0
					? { amount: Number(this.form.amount), frequency: this.form.frequency, startDate: this.form.startDate, accountId: this.form.accountId }
					: null
			}
			this.$emit('save', payload)
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
