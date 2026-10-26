<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-debtor"
		size="normal"
		:closeOnClickOutside="false"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-debtor" class="vbh-modal-title">
				{{ debtor ? t('Empfänger bearbeiten') : t('Neuer Rechnungsempfänger') }}
			</h2>
			<p class="vbh-hint">
				{{ t('Die Anschrift wird beim Festschreiben in die Rechnung übernommen. Spätere Änderungen hier ändern bereits festgeschriebene Rechnungen nicht.') }}
			</p>

			<div class="vbh-form">
				<label>{{ t('Art') }}
					<select v-model="form.kind">
						<option value="organisation">{{ t('Unternehmen / Verein') }}</option>
						<option value="person">{{ t('Privatperson') }}</option>
						<option value="behoerde">{{ t('Behörde') }}</option>
					</select>
				</label>
				<label class="vbh-grow">{{ t('Name') }}
					<input
						ref="name"
						v-model="form.name"
						maxlength="255"
						:placeholder="form.kind === 'person' ? t('Vor- und Nachname') : t('Name der Organisation')">
				</label>
			</div>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Zusatz (z. B. z. Hd.)') }}
					<input v-model="form.name2" maxlength="255">
				</label>
			</div>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Straße und Hausnummer') }}
					<input v-model="form.street" maxlength="255">
				</label>
			</div>
			<div class="vbh-form">
				<label>{{ t('PLZ') }}
					<input v-model="form.postalCode" class="vbh-short" maxlength="16">
				</label>
				<label class="vbh-grow">{{ t('Ort') }}
					<input v-model="form.city" maxlength="128">
				</label>
				<label>{{ t('Land') }}
					<CountrySelect v-model="form.country" :noClear="true" />
				</label>
			</div>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('E-Mail (für den Rechnungsversand)') }}
					<input v-model="form.email" type="email" maxlength="255">
				</label>
				<label>{{ t('Kundennummer') }}
					<input v-model="form.customerNo" class="vbh-short" maxlength="64">
				</label>
			</div>
			<div class="vbh-form">
				<label>{{ t('USt-IdNr. (optional)') }}
					<input v-model="form.vatId" maxlength="32" placeholder="DE123456789">
				</label>
				<label v-if="form.kind !== 'person'">{{ t('Leitweg-ID (Behörden)') }}
					<input v-model="form.leitwegId" maxlength="64" placeholder="991-01234-44">
				</label>
			</div>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Notiz (intern)') }}
					<textarea
						v-model="form.note"
						class="vbh-note-textarea"
						rows="2"
						maxlength="2000" />
				</label>
			</div>

			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>
			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSave || saving" @click="save">
					{{ debtor ? t('Speichern') : t('Anlegen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import CountrySelect from './CountrySelect.vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { focusOnOpen } from '../lib/modalFocus.js'

function emptyForm() {
	return { kind: 'organisation', name: '', name2: '', street: '', postalCode: '', city: '', country: 'DE', email: '', vatId: '', leitwegId: '', customerNo: '', note: '' }
}

/**
 * Rechnungsempfänger anlegen oder bearbeiten (Epic #168). Speichert selbst und
 * meldet den gespeicherten Datensatz mit `saved`.
 */
export default {
	name: 'DebtorDialog',
	components: { NcModal, NcButton, CountrySelect },
	props: {
		show: { type: Boolean, default: false },
		/** Zu bearbeitender Empfänger; ohne Angabe legt der Dialog einen neuen an. */
		debtor: { type: Object, default: null },
		/** Vorbelegung eines neuen Empfängers (z. B. der Suchtext als Name). */
		initialName: { type: String, default: '' },
	},

	emits: ['close', 'saved', 'update:show'],

	data() {
		return { form: emptyForm(), saving: false, error: '' }
	},

	computed: {
		canSave() { return this.form.name.trim() !== '' },
	},

	watch: {
		show(open) {
			if (!open) { return }
			this.error = ''
			this.form = this.debtor
				? { ...emptyForm(), ...Object.fromEntries(Object.entries(this.debtor).filter(([k]) => k in emptyForm()).map(([k, v]) => [k, v ?? ''])) }
				: { ...emptyForm(), name: this.initialName }
			focusOnOpen(this, () => this.$refs.name)
		},
	},

	methods: {
		async save() {
			this.saving = true
			this.error = ''
			try {
				const { data } = this.debtor
					? await api.updateDebtor(this.debtor.id, this.form)
					: await api.createDebtor(this.form)
				this.$emit('saved', data)
			} catch (e) {
				this.error = errMsg(e, this.t('Der Empfänger konnte nicht gespeichert werden.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>
