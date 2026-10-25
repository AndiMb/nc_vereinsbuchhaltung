<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-assignment-change"
		size="normal"
		:closeOnClickOutside="!saving"
		:noClose="saving"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div v-if="assignment" class="vbh-modal-inner">
			<h2 id="vbh-modal-title-assignment-change" class="vbh-modal-title">
				{{ isGroupMode ? t('Beitragsgruppe wechseln') : t('Beitrag ändern') }}
			</h2>
			<p class="vbh-hint">
				{{ isGroupMode
					? tRaw('{name} · bisher {group}', { name: memberName, group: currentGroupName })
					: tRaw('{name} · {group} · Untergrenze {min}', { name: memberName, group: currentGroupName, min: euro(effectiveMinCents) }) }}
			</p>

			<div v-if="isGroupMode" class="vbh-form">
				<label class="vbh-grow">{{ t('Neue Beitragsgruppe') }}
					<select ref="groupSelect" v-model.number="form.groupId" @change="onGroupChange">
						<option v-for="g in selectableGroups" :key="g.id" :value="g.id">
							{{ g.name }}
						</option>
					</select>
				</label>
			</div>
			<div class="vbh-form">
				<label>{{ t('Monatsbeitrag (€)') }}
					<AmountInput ref="amountInput" v-model="form.monthlyAmount" class="vbh-short" />
				</label>
				<label>{{ t('Turnus') }}
					<select v-model.number="form.intervalMonths">
						<option v-for="m in allowedIntervals" :key="m" :value="m">
							{{ intervalLabel(m) }}
						</option>
					</select>
				</label>
				<label v-if="!isGroupMode">{{ t('Zahlungsart') }}
					<select v-model="form.paymentMethod">
						<option value="direct_debit">{{ t('Lastschrift') }}</option>
						<option value="ueberweisung">{{ t('Überweisung') }}</option>
					</select>
				</label>
			</div>
			<p v-if="paymentMethodChanged && form.paymentMethod === 'ueberweisung'" class="vbh-hint">
				{{ t('Ab sofort wird nichts mehr per Lastschrift eingezogen, auch ein schon angekündigter Einzug nicht; offene und künftige Forderungen überweist das Mitglied selbst (Zahlungsaufforderung kommt mit dem nächsten Tageslauf). Ein bestehendes Mandat bleibt gültig und wird nur nicht mehr genutzt.') }}
			</p>
			<p v-else-if="paymentMethodChanged" class="vbh-hint">
				{{ t('Lastschrift setzt ein aktives Mandat voraus – fehlt es, erscheint eine Aufgabe und die Forderung wartet, bis ein Mandat vorliegt.') }}
			</p>
			<p class="vbh-hint">
				{{ isGroupMode
					? tRaw('Untergrenze der gewählten Gruppe: {min}. Beim Wechsel ist deren Standardbeitrag eingetragen; Sie können ihn anpassen.', { min: euro(effectiveMinCents) })
					: t('Gezahlt werden darf beliebig mehr als die Untergrenze, etwa wenn das Mitglied ausnahmsweise mehr geben möchte.') }}
			</p>

			<NcNoteCard v-if="previewError" type="error">
				{{ previewError }}
			</NcNoteCard>
			<NcNoteCard v-else-if="preview" type="info">
				{{ previewText }}
			</NcNoteCard>
			<NcLoadingIcon v-else-if="loadingPreview" :size="20" />
			<p class="vbh-hint">
				{{ t('Das Mitglied bekommt die Änderung per E-Mail bestätigt (sofern eine Adresse vorliegt).') }}
			</p>

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" :disabled="saving" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSave || saving" @click="save">
					{{ t('Speichern') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import AmountInput from './AmountInput.vue'
import api from '../api.js'
import { errMsg, formatDate } from '../lib/format.js'
import { intervalLabel } from '../lib/frequency.js'
import { focusOnOpen } from '../lib/modalFocus.js'

/**
 * „Beitrag ändern“ und „Beitragsgruppe wechseln“ für den Vorstand: Monatsbeitrag, Turnus und Zahlungsart einer bestehenden Zuweisung beziehungsweise ihre Beitragsgruppe
 * (bisher konnte nur das Mitglied selbst unter „Mein Beitrag“ ändern, die Verwaltung nur beenden und
 * neu anlegen). Der Server prüft Untergrenze und erlaubte Turnusse und nennt in der Vorschau, ab wann
 * die Änderung gilt – bei einem schon angekündigten Einzug erst danach (Sperrfenster). Die Vorschau
 * erscheint von selbst, sobald sich ein Wert ändert; gespeichert wird erst, wenn sie zum Stand der
 * Felder passt. Die Zahlungsart wirkt sofort und kennt kein Sperrfenster; ein bestehendes Mandat bleibt beim
 * Wechsel auf Überweisung unberührt und wird nur nicht mehr genutzt.
 */
export default {
	name: 'AssignmentChangeDialog',
	components: { NcModal, NcButton, NcNoteCard, NcLoadingIcon, AmountInput },

	props: {
		show: { type: Boolean, default: false },
		/** Die Zuweisung (Assignment-JSON) oder null. */
		assignment: { type: Object, default: null },
		/** Alle Beitragsgruppen (Untergrenze, erlaubte Turnusse, Standardbeitrag je Gruppe). */
		groups: { type: Array, default: () => [] },
		memberName: { type: String, default: '' },
		/** 'fee' = Betrag und Turnus ändern, 'group' = in eine andere Beitragsgruppe wechseln (mit deren Regeln). */
		mode: { type: String, default: 'fee' },
	},

	emits: ['close', 'saved', 'update:show'],

	data() {
		return {
			form: { monthlyAmount: '', intervalMonths: 1, groupId: null, paymentMethod: 'direct_debit' },
			preview: null,
			previewedFor: null,
			previewError: '',
			loadingPreview: false,
			saving: false,
			timer: null,
		}
	},

	computed: {
		isGroupMode() { return this.mode === 'group' },

		currentGroupName() {
			const group = this.assignment ? this.groups.find((g) => g.id === this.assignment.groupId) : null
			return group ? group.name : ''
		},

		/** Die gewählte Gruppe: sie bestimmt Untergrenze und erlaubte Turnusse. */
		selectedGroup() {
			return this.groups.find((g) => g.id === this.form.groupId) ?? null
		},

		/** Wählbar sind die aktiven Gruppen und – damit die Auswahl nie leer aussieht – die bisherige. */
		selectableGroups() {
			return this.groups.filter((g) => g.isActive || (this.assignment && g.id === this.assignment.groupId))
		},

		groupChanged() {
			return !!this.assignment && this.form.groupId !== this.assignment.groupId
		},

		paymentMethodChanged() {
			return !!this.assignment && this.form.paymentMethod !== this.assignment.paymentMethod
		},

		/** Die Vorschau-Zeile: beitragsfrei, Fälligkeit (Überweisung) oder erster Einzug (Lastschrift). */
		previewText() {
			const p = this.preview
			if (!p) { return '' }
			if (p.amountCents === 0) {
				return this.t('Wirkt ab {from} · beitragsfrei: es wird nichts eingezogen.', { from: formatDate(p.effectiveFrom) })
			}
			const params = { from: formatDate(p.effectiveFrom), due: formatDate(p.firstDueDate), amount: this.euro(p.amountCents) }
			return this.form.paymentMethod === 'ueberweisung'
				? this.t('Wirkt ab {from} · fällig am {due} · Betrag {amount}', params)
				: this.t('Wirkt ab {from} · erster Einzug am {due} · Betrag {amount}', params)
		},

		effectiveMinCents() {
			if (!this.assignment) { return 0 }
			return this.assignment.minMonthlyAmountOverrideCents ?? (this.selectedGroup ? this.selectedGroup.minMonthlyAmountCents : 0)
		},

		allowedIntervals() {
			const allowed = this.selectedGroup ? this.selectedGroup.allowedIntervals : []
			return allowed.includes(this.form.intervalMonths) ? allowed : [...allowed, this.form.intervalMonths].sort((a, b) => a - b)
		},

		unchanged() {
			return !!this.assignment
				&& Number(this.form.monthlyAmount) === this.assignment.monthlyAmount
				&& this.form.intervalMonths === this.assignment.intervalMonths
				&& !this.groupChanged
				&& !this.paymentMethodChanged
		},

		formKey() { return `${this.form.groupId}|${this.form.monthlyAmount}|${this.form.intervalMonths}|${this.form.paymentMethod}` },

		canSave() {
			return this.form.monthlyAmount !== '' && !this.unchanged && !this.previewError && !!this.preview && this.previewedFor === this.formKey
		},
	},

	watch: {
		show(open) {
			if (!open || !this.assignment) { return }
			this.form = { monthlyAmount: this.assignment.monthlyAmount, intervalMonths: this.assignment.intervalMonths, groupId: this.assignment.groupId, paymentMethod: this.assignment.paymentMethod }
			this.preview = null
			this.previewedFor = null
			this.previewError = ''
			focusOnOpen(this, () => (this.isGroupMode ? this.$refs.groupSelect : this.$refs.amountInput?.$el))
		},

		formKey() { this.schedulePreview() },
	},

	beforeUnmount() { clearTimeout(this.timer) },

	methods: {
		intervalLabel,
		formatDate,
		euro(cents) { return (cents / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }) },

		/**
		 * Wechselt die Gruppe, gelten ihre Regeln: ein Turnus, den sie nicht kennt, wird durch ihren Standard-Turnus
		 * ersetzt, und ihr Standardbeitrag wird eingetragen (änderbar) – so entsteht nie versehentlich ein Betrag
		 * unter der neuen Untergrenze.
		 */
		onGroupChange() {
			const group = this.selectedGroup
			if (!group) { return }
			if (!group.allowedIntervals.includes(this.form.intervalMonths)) { this.form.intervalMonths = group.defaultInterval }
			this.form.monthlyAmount = group.defaultMonthlyAmount
		},

		/** Die Vorschau folgt den Feldern mit kurzer Verzögerung, damit nicht jede Ziffer eine Anfrage auslöst. */
		schedulePreview() {
			clearTimeout(this.timer)
			this.preview = null
			this.previewError = ''
			if (!this.show || !this.assignment || this.form.monthlyAmount === '' || this.unchanged) { return }
			this.timer = setTimeout(() => this.loadPreview(), 350)
		},

		async loadPreview() {
			const key = this.formKey
			this.loadingPreview = true
			try {
				const { data } = await api.previewAssignmentChange(this.assignment.id, {
					monthlyAmount: this.form.monthlyAmount,
					intervalMonths: this.form.intervalMonths,
					groupId: this.form.groupId,
					paymentMethod: this.form.paymentMethod,
				})
				if (key !== this.formKey) { return }
				this.preview = data
				this.previewedFor = key
			} catch (e) {
				if (key !== this.formKey) { return }
				this.previewError = errMsg(e, this.t('Vorschau konnte nicht geladen werden'))
			} finally {
				this.loadingPreview = false
			}
		},

		/** Sagt dem Vorstand, ob die Quittungsmail an das Mitglied rausging – die Änderung selbst ist in jedem Fall gespeichert. */
		reportReceipt(receipt) {
			if (receipt === 'sent') {
				showSuccess(this.t('Beitrag geändert. Das Mitglied hat eine Bestätigung per E-Mail bekommen.'))
			} else if (receipt === 'no_email') {
				showSuccess(this.t('Beitrag geändert. Das Mitglied hat keine E-Mail-Adresse, daher gibt es keine Bestätigung per E-Mail.'))
			} else if (receipt === 'failed') {
				showWarning(this.t('Beitrag geändert, aber die Bestätigung per E-Mail konnte nicht verschickt werden.'))
			} else {
				showSuccess(this.t('Beitrag geändert.'))
			}
		},

		async save() {
			if (!this.canSave) { return }
			this.saving = true
			try {
				const { data } = await api.updateAssignment(this.assignment.id, {
					monthlyAmount: this.form.monthlyAmount,
					intervalMonths: this.form.intervalMonths,
					groupId: this.form.groupId,
					paymentMethod: this.form.paymentMethod,
				})
				this.reportReceipt(data.receipt)
				this.$emit('saved')
			} catch (e) {
				showError(errMsg(e, this.t('Beitrag konnte nicht geändert werden')))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>
