<template>
	<!-- Anker unverändert: die Prüfliste des Rechnungseditors springt nach
	     #settings-section_rechnungen-angaben (lib/invoices.js::checklistTarget). -->
	<div id="settings-section_rechnungen-angaben" class="vbh-card">
		<h4>{{ t('Angaben des Vereins') }}</h4>
		<NcLoadingIcon v-if="!loaded" :size="24" :name="t('Wird geladen…')" />
		<div v-else-if="loadFailed">
			<!-- Ohne geladenen Stand wären die Felder leer – nicht so tun, als
			     wäre nichts eingetragen. -->
			<p class="vbh-hint vbh-hint--error" role="alert">
				{{ t('Die Einstellungen konnten nicht geladen werden.') }}
			</p>
			<NcButton @click="load">
				{{ t('Erneut versuchen') }}
			</NcButton>
		</div>
		<template v-else>
			<p class="vbh-hint">
				{{ t('Name, Anschrift und Steuernummer des Vereins stehen auf Rechnungen und Zuwendungsbestätigungen. Für Rechnungen sind Name und Anschrift sowie die Steuernummer oder USt-IdNr. Pflicht (§ 14 Abs. 4 UStG). Den Vereinsnamen pflegen Sie oben in der Karte „Vereinsname“.') }}
			</p>
			<!-- Die Warnung richtet sich nach dem Modul, das die Angaben braucht:
			     ein Verein ohne Rechnungen soll nicht von Rechnungen lesen. -->
			<p v-if="invoicesActive && missing.length" class="vbh-hint vbh-hint--warning">
				{{ t('Es fehlen noch Angaben, ohne die sich keine Rechnung festschreiben lässt.') }}
			</p>
			<p v-else-if="donationsActive && addressMissing" class="vbh-hint vbh-hint--warning">
				{{ t('Name und Anschrift des Vereins fehlen noch; ohne sie stellt die App keine Zuwendungsbestätigung aus.') }}
			</p>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Name auf Rechnungen (optional)') }}
					<input v-model="form.club_invoice_name" maxlength="200" :placeholder="clubName">
				</label>
			</div>
			<p class="vbh-hint">
				{{ t('Nur nötig, wenn der Vereinsname auf Rechnungen anders lauten soll, etwa weil er sehr lang ist. Er steht dann im Briefkopf, in der Absenderzeile, in der Fußzeile, in der E-Rechnung und in den Rechnungs-Mails. Leer gilt der Vereinsname.') }}
			</p>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Kontoinhaber (optional)') }}
					<input v-model="form.club_account_holder" maxlength="70" :placeholder="invoiceName.slice(0, 70)">
				</label>
			</div>
			<p v-if="holderTooLong" class="vbh-hint vbh-hint--warning">
				{{ t('Der Name auf Rechnungen hat mehr als 70 Zeichen; im GiroCode würde er abgeschnitten. Tragen Sie den Kontoinhaber so ein, wie ihn die Bank führt.') }}
			</p>
			<p v-else class="vbh-hint">
				{{ t('Steht im Zahlungsblock, im GiroCode (höchstens 70 Zeichen) und in der E-Rechnung. Leer gilt der Name auf Rechnungen.') }}
			</p>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Straße und Hausnummer') }}
					<input v-model="form.club_street" maxlength="255">
				</label>
			</div>
			<div class="vbh-form">
				<label>{{ t('PLZ') }}
					<input v-model="form.club_postal_code" class="vbh-short" maxlength="16">
				</label>
				<label class="vbh-grow">{{ t('Ort') }}
					<input v-model="form.club_city" maxlength="128">
				</label>
				<label>{{ t('Land') }}
					<CountrySelect v-model="form.club_country" :noClear="true" />
				</label>
			</div>
			<div class="vbh-form">
				<label>{{ t('Steuernummer') }}
					<input v-model="form.club_tax_number" maxlength="32" placeholder="12/345/67890">
				</label>
				<label>{{ t('USt-IdNr. (falls vorhanden)') }}
					<input v-model="form.club_vat_id" maxlength="32" placeholder="DE123456789">
				</label>
			</div>
			<div class="vbh-form">
				<label>{{ t('Vereinsregister (Gericht)') }}
					<input v-model="form.club_register_court" maxlength="128" :placeholder="t('z. B. Amtsgericht Musterstadt')">
				</label>
				<label>{{ t('Registernummer') }}
					<input
						v-model="form.club_register_no"
						class="vbh-short"
						maxlength="64"
						placeholder="VR 1234">
				</label>
			</div>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Vertretungsberechtigt (Vorstand)') }}
					<input v-model="form.club_representatives" maxlength="255" :placeholder="t('z. B. Erika Muster, 1. Vorsitzende')">
				</label>
			</div>
			<div class="vbh-form">
				<label>{{ t('Telefon') }}
					<input v-model="form.club_phone" maxlength="64">
				</label>
				<label class="vbh-grow">{{ t('E-Mail') }}
					<input v-model="form.club_email" type="email" maxlength="255">
				</label>
			</div>
			<div class="vbh-form">
				<SettingsTemplateField
					v-model="form.club_nonprofit_note"
					:label="t('Hinweis auf die Gemeinnützigkeit (optional)')"
					:defaultText="t('Der Verein ist vom Finanzamt … als gemeinnützig anerkannt (Freistellungsbescheid vom …).')"
					:maxlength="500"
					multiline
					:rows="2" />
			</div>
			<p class="vbh-hint">
				{{ t('Erscheint unter den Hinweisen auf der Rechnung. Er ist freiwillig und ersetzt keinen Hinweis zur Umsatzsteuer.') }}
			</p>
			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>
			<div class="vbh-form">
				<NcButton variant="primary" :disabled="saving" @click="save">
					{{ t('Speichern') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import CountrySelect from './CountrySelect.vue'
import SettingsTemplateField from './SettingsTemplateField.vue'
import api from '../api.js'
import { useSettingsCardSave } from '../composables/useSettingsCardSave.js'

/** Die Felder dieser Karte – dieselben Schlüssel wie zuvor in SettingsInvoices.vue. */
const ISSUER_KEYS = [
	'club_invoice_name',
	'club_account_holder',
	'club_street',
	'club_postal_code',
	'club_city',
	'club_country',
	'club_tax_number',
	'club_vat_id',
	'club_register_court',
	'club_register_no',
	'club_representatives',
	'club_phone',
	'club_email',
	'club_nonprofit_note',
]

/**
 * Anschrift, Steuernummer, Register und Vorstand des Vereins
 * (InvoiceSettings::issuer()). Lag früher als „Angaben des Vereins auf der
 * Rechnung“ im Rechnungsabschnitt; die Zuwendungsbestätigungen brauchen
 * dieselben Angaben und verweigern ohne sie das Ausstellen
 * (DonationReceiptSettings::problems()). Seit die Rechnungskarten bei
 * ausgeschaltetem Modul verborgen sind, steht die Karte deshalb unter
 * „Verein“ und ist immer sichtbar (Überschrift jetzt „Angaben des Vereins“) – sonst käme ein Verein ohne Rechnungen
 * nicht mehr an seine Anschrift.
 */
export default {
	name: 'SettingsClubIssuer',
	components: { CountrySelect, NcButton, NcLoadingIcon, SettingsTemplateField },
	props: {
		// Vereinsname aus der Karte darüber (auch ungespeichert), als Platzhalter
		clubName: { type: String, default: '' },
		// Welche Module die Angaben gerade brauchen (für die passende Warnung)
		invoicesActive: { type: Boolean, default: false },
		donationsActive: { type: Boolean, default: false },
	},

	setup() {
		return { ...useSettingsCardSave() }
	},

	data() {
		return {
			loaded: false,
			loadFailed: false,
			form: {},
			missing: [],
		}
	},

	computed: {
		invoiceName() { return (this.form.club_invoice_name || '').trim() || this.clubName },
		// Ohne eigenen Kontoinhaber gilt der Name auf Rechnungen – der GiroCode fasst höchstens 70 Zeichen.
		holderTooLong() { return !(this.form.club_account_holder || '').trim() && this.invoiceName.length > 70 },
		// Wie DonationReceiptSettings::problems(): Name, Straße, PLZ und Ort sind Pflicht.
		addressMissing() {
			return [this.invoiceName, this.form.club_street, this.form.club_postal_code, this.form.club_city]
				.some((value) => !(value || '').trim())
		},
	},

	async mounted() {
		await this.load()
		// Sprung aus der Prüfliste: die Karte steht erst jetzt in voller Höhe da.
		await this.$nextTick()
		if (location.hash === '#settings-section_rechnungen-angaben') {
			this.$el.scrollIntoView({ block: 'start' })
		}
	},

	methods: {
		async load() {
			this.loaded = false
			try {
				const { data } = await api.getSettings()
				this.apply(data, ISSUER_KEYS)
				this.loadFailed = false
			} catch {
				this.loadFailed = true
			} finally {
				this.loaded = true
			}
		},

		apply(data, keys) {
			for (const key of keys) { this.form[key] = data[key] ?? '' }
			this.missing = data.invoice_missing_issuer_fields || []
		},

		save() {
			return this.run(() => null, async () => {
				const body = Object.fromEntries(ISSUER_KEYS.map((key) => [key, this.form[key]]))
				const { data } = await api.saveSettings(body)
				this.apply(data, ISSUER_KEYS)
			})
		},
	},
}
</script>

<style scoped>
/* Wie bisher in SettingsInvoices.vue: Felder füllen ihre Zeile – je Feld
   mindestens 220 px, kurze Angaben (PLZ, Registernummer) nur 150 px – und das
   Eingabefeld füllt sein Label, sonst blieben etwa Steuernummer und Telefon
   schmal und Platzhalter abgeschnitten. */
.vbh-form label { flex: 1 1 220px; min-width: 0; }

.vbh-form label:has(> .vbh-short) { flex: 0 1 150px; }

.vbh-form label > input:not([type="checkbox"]), .vbh-form label > select, .vbh-form label > textarea { width: 100%; min-width: 0; box-sizing: border-box; }

.vbh-form label :deep(.v-select) { width: 100%; min-width: 0; }
</style>
