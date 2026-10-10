<template>
	<form class="vbh-caf" :aria-labelledby="headingId" @submit.prevent="submit">
		<h5 :id="headingId" class="vbh-caf-title">
			{{ title }}
		</h5>

		<!-- Erlass und Storno liegen nah beieinander und werden verwechselt: der Text sagt jeweils, wofür er gilt
		     und wofür nicht (Spec §3.6 „scharf getrennt“). -->
		<p v-if="mode === 'waive'" class="vbh-hint vbh-hint--info">
			{{ t('Ein Erlass heißt: Die Forderung war berechtigt, wir verzichten aber darauf. Das ist jederzeit möglich, auch nach der Einreichung.') }}
			<br>
			{{ t('Hätte die Forderung nie entstehen dürfen (zum Beispiel doppelt angelegt), ist es kein Erlass, sondern ein Storno – das geht nur vor der Einreichung.') }}
		</p>
		<p v-else-if="mode === 'cancel'" class="vbh-hint vbh-hint--info">
			{{ t('Ein Storno heißt: Die Forderung hätte nie existieren dürfen (zum Beispiel doppelt oder irrtümlich angelegt). Das geht nur vor der Einreichung.') }}
			<br>
			{{ t('War die Forderung berechtigt und Sie verzichten nur darauf, vermerken Sie stattdessen einen Erlass.') }}
		</p>
		<p v-else-if="mode === 'defer'" class="vbh-hint vbh-hint--info">
			{{ t('Bis einschließlich zum gewählten Tag pausiert das Mahnwesen: keine Zahlungserinnerung, keine Mahnung, keine Eskalation. Die Forderung bleibt offen; danach läuft die Mahnuhr von der zuletzt erreichten Stufe weiter. Je Forderung gibt es höchstens eine Stundung zugleich.') }}
		</p>
		<p v-else class="vbh-hint vbh-hint--info">
			{{ t('Vermerkt werden der Zeitpunkt, Ihr Name und die Notiz. Eine Buchung entsteht dadurch nicht – die Zahlung ordnen Sie wie gewohnt dem Bankumsatz zu.') }}
		</p>

		<!-- Ein Einzug lässt sich durch Vermerk oder Stundung nicht anhalten: das sagt die Warnung. -->
		<p v-if="warning && mode !== 'cancel'" class="vbh-hint vbh-hint--warning" role="status">
			{{ warning }}
		</p>

		<div class="vbh-form">
			<label v-if="mode === 'defer'">{{ t('Gestundet bis') }}
				<input
					ref="focusTarget"
					v-model="until"
					type="date"
					:min="today"
					required>
			</label>
		</div>

		<label class="vbh-caf-note">{{ noteLabel }}
			<textarea
				ref="noteField"
				v-model="note"
				rows="2"
				:required="noteRequired"
				:placeholder="notePlaceholder" />
		</label>

		<div class="vbh-caf-actions">
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('cancel')">
				{{ t('Abbrechen') }}
			</NcButton>
			<NcButton
				type="submit"
				:variant="mode === 'cancel' ? 'error' : 'primary'"
				:disabled="busy || !valid">
				{{ submitLabel }}
			</NcButton>
		</div>
	</form>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

let formCounter = 0

/**
 * Die vier Aktionen an einer Forderung als eingeklapptes Formular im Detail
 * (Issue #104): als bezahlt vermerken, erlassen, stunden, stornieren. Inline
 * statt als Dialog – wie die Mandat-Verwaltung in der Akte (MandatePanel): es
 * gibt keinen Dialog, der Klicks abfängt, und das Formular bleibt neben der
 * Forderung stehen, auf die es sich bezieht.
 *
 * Rein darstellend: die Komponente fragt ab und meldet `submit` mit Notiz bzw.
 * Begründung (und beim Stunden dem Datum). Den Aufruf macht ClaimDetail.
 *
 * Pflichtangaben laut Spec §3.6: Erlass, Storno und Stundung verlangen eine
 * Begründung, „bezahlt“ nur eine optionale Notiz. Das Backend prüft dasselbe
 * noch einmal.
 */
export default {
	name: 'ClaimActionForm',
	components: { NcButton },
	props: {
		// 'paid' | 'waive' | 'defer' | 'cancel'
		mode: { type: String, required: true, validator: (v) => ['paid', 'waive', 'defer', 'cancel'].includes(v) },
		// Stichtag (JJJJ-MM-TT) vom Server: frühestes Stundungsdatum
		today: { type: String, required: true },
		// Warnung, wenn die Forderung schon in einem Lauf steckt (siehe lib/claims.js::debitWarning)
		warning: { type: String, default: '' },
		busy: { type: Boolean, default: false },
	},

	emits: ['submit', 'cancel'],

	data() {
		formCounter++
		return { note: '', until: '', headingId: `vbh-caf-title-${formCounter}` }
	},

	computed: {
		noteRequired() { return this.mode !== 'paid' },

		valid() {
			if (this.noteRequired && this.note.trim() === '') { return false }
			if (this.mode === 'defer') { return this.until !== '' && this.until >= this.today }
			return true
		},

		title() {
			return {
				paid: this.t('Als bezahlt markieren'),
				waive: this.t('Forderung erlassen'),
				defer: this.t('Forderung stunden'),
				cancel: this.t('Forderung stornieren'),
			}[this.mode]
		},

		noteLabel() {
			return this.noteRequired ? this.t('Begründung (Pflicht)') : this.t('Notiz (optional)')
		},

		notePlaceholder() {
			return {
				paid: this.t('z. B. Zahlungsweg oder Datum des Geldeingangs'),
				waive: this.t('z. B. Härtefall laut Vorstandsbeschluss'),
				defer: this.t('z. B. Ratenzahlung telefonisch vereinbart'),
				cancel: this.t('z. B. doppelt angelegt'),
			}[this.mode]
		},

		submitLabel() {
			return {
				paid: this.t('Als bezahlt vermerken'),
				waive: this.t('Erlass vermerken'),
				defer: this.t('Stundung setzen'),
				cancel: this.t('Stornieren'),
			}[this.mode]
		},
	},

	mounted() {
		this.$nextTick(() => (this.$refs.focusTarget ?? this.$refs.noteField)?.focus())
	},

	methods: {
		submit() {
			if (!this.valid || this.busy) { return }
			this.$emit('submit', { note: this.note.trim(), until: this.until })
		},
	},
}
</script>

<style scoped>
.vbh-caf {
	margin-top: 12px;
	padding: 12px 16px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-main-background);
}

.vbh-caf-title {
	margin: 0 0 8px;
}

.vbh-caf-note {
	display: flex;
	flex-direction: column;
	gap: 3px;
	margin-top: 10px;
	font-size: 0.85em;
}

.vbh-caf-note textarea {
	width: 100%;
}

.vbh-caf-actions {
	display: flex;
	justify-content: flex-end;
	flex-wrap: wrap;
	gap: 12px;
	margin-top: 12px;
}
</style>
