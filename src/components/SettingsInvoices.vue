<template>
	<div v-if="loaded" class="vbh-inv-settings">
		<div class="vbh-card">
			<h4>{{ t('Rechnungsmodul') }}</h4>
			<p class="vbh-hint">
				{{ t('Rechnungen im Namen des Vereins schreiben, als offene Posten führen und beim Kontoauszug-Import ihrer Zahlung zuordnen.') }}
			</p>
			<SettingsToggle v-model="form.invoice_module_active" :label="t('Reiter „Rechnungen“ anzeigen')">
				{{ t('Schaltet das Rechnungsmodul ein. Ist der Schalter aus, bleibt der Reiter ausgeblendet; bestehende Rechnungen bleiben erhalten.') }}
			</SettingsToggle>
			<SettingsToggle v-model="form.invoice_auto_assign" :label="t('Zahlungen beim Import automatisch zuordnen')">
				{{ t('Der Kontoauszug-Import bucht Zahlungen mit passender Rechnungsnummer und genauem Betrag selbst.') }}
			</SettingsToggle>
			<SettingsToggle v-model="form.invoice_rf_reference" :label="t('Strukturierte Zahlungsreferenz verwenden')">
				{{ t('Druckt die Referenz (RF…, ISO 11649) auf die Rechnung und trägt sie in den GiroCode ein.') }}
			</SettingsToggle>
			<SettingsToggle v-model="form.invoice_einvoice_auto" :label="t('E-Rechnung beim Festschreiben erzeugen')">
				{{ t('Erzeugt zugleich ZUGFeRD (für Unternehmen) bzw. XRechnung (für Behörden).') }}
			</SettingsToggle>
			<p v-if="stateError" class="vbh-hint vbh-hint--error" role="alert">
				{{ stateError }}
			</p>
			<div class="vbh-form">
				<NcButton variant="primary" :disabled="saving" @click="saveModule">
					{{ t('Speichern') }}
				</NcButton>
			</div>
		</div>

		<div class="vbh-card">
			<h4>{{ t('Angaben des Vereins auf der Rechnung') }}</h4>
			<p class="vbh-hint">
				{{ t('Pflichtangaben nach § 14 Abs. 4 UStG: Name und Anschrift des Vereins sowie die Steuernummer oder USt-IdNr. Den Vereinsnamen pflegen Sie oben unter „Verein“.') }}
			</p>
			<p v-if="missing.length" class="vbh-hint vbh-hint--warning">
				{{ t('Es fehlen noch Angaben, ohne die sich keine Rechnung festschreiben lässt.') }}
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
			<p v-if="issuerError" class="vbh-hint vbh-hint--error" role="alert">
				{{ issuerError }}
			</p>
			<div class="vbh-form">
				<NcButton variant="primary" :disabled="saving" @click="saveIssuer">
					{{ t('Speichern') }}
				</NcButton>
			</div>
		</div>

		<div class="vbh-card">
			<h4>{{ t('Steuer, Zahlung und Texte') }}</h4>
			<p class="vbh-hint">
				{{ t('Die meisten kleinen Vereine sind Kleinunternehmer nach § 19 UStG und weisen keine Umsatzsteuer aus. Ob das für Ihren Verein gilt, klärt das Finanzamt oder Ihre Steuerberatung.') }}
			</p>
			<div class="vbh-form">
				<label>{{ t('Umsatzsteuer') }}
					<select v-model="form.invoice_tax_mode">
						<option value="kleinunternehmer">{{ t('Kleinunternehmer (§ 19 UStG)') }}</option>
						<option value="exempt">{{ t('Steuerbefreit (Hinweis nach § 4 UStG)') }}</option>
						<option value="regular">{{ t('Regelbesteuerung (Umsatzsteuer ausweisen)') }}</option>
					</select>
				</label>
				<label>{{ t('Zahlungsziel (Tage)') }}
					<input
						v-model.number="form.invoice_default_due_days"
						class="vbh-short"
						type="number"
						min="0"
						max="365">
				</label>
			</div>
			<template v-if="form.invoice_tax_mode === 'regular'">
				<p class="vbh-hint">
					{{ t('Bei Regelbesteuerung wird die Umsatzsteuer je Position ausgewiesen und beim Zahlungseingang auf das Umsatzsteuerkonto gebucht. Legen Sie die Konten im Kontenrahmen an (Passivkonto) und wählen Sie sie hier.') }}
				</p>
				<div class="vbh-form">
					<label>{{ t('Umsatzsteuerkonto 19 %') }}
						<select v-model="form.invoice_vat_account_19">
							<option :value="null">{{ t('– kein Konto –') }}</option>
							<option v-for="a in vatAccounts" :key="a.id" :value="a.id">{{ a.number }} {{ a.name }}</option>
						</select>
					</label>
					<label>{{ t('Umsatzsteuerkonto 7 %') }}
						<select v-model="form.invoice_vat_account_7">
							<option :value="null">{{ t('– kein Konto –') }}</option>
							<option v-for="a in vatAccounts" :key="a.id" :value="a.id">{{ a.number }} {{ a.name }}</option>
						</select>
					</label>
				</div>
				<div class="vbh-form">
					<label>{{ t('Vorgeschlagener Satz im Zweckbetrieb') }}
						<select v-model.number="form.invoice_rate_zweckbetrieb">
							<option :value="700">7 %</option>
							<option :value="1900">19 %</option>
							<option :value="0">{{ t('0 % (Nullsatz)') }}</option>
						</select>
					</label>
					<label>{{ t('Vorgeschlagener Satz im wirtschaftlichen Geschäftsbetrieb') }}
						<select v-model.number="form.invoice_rate_wirtschaftlich">
							<option :value="1900">19 %</option>
							<option :value="700">7 %</option>
							<option :value="0">{{ t('0 % (Nullsatz)') }}</option>
						</select>
					</label>
				</div>
			</template>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Standard-Hinweistext zur Steuer (optional)') }}
					<input v-model="form.invoice_default_exemption_text" maxlength="500" :placeholder="t('leer = Standardtext nach § 19 UStG')">
				</label>
			</div>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Konto für Zahlungsangaben und GiroCode') }}
					<select v-model="form.invoice_account_id">
						<option :value="null">{{ t('– kein Konto –') }}</option>
						<option v-for="a in bankAccounts" :key="a.id" :value="a.id">{{ a.number }} {{ a.name }} ({{ a.iban }})</option>
					</select>
				</label>
				<label>{{ t('BIC (optional)') }}
					<input v-model="form.invoice_bic" class="vbh-short" maxlength="11">
				</label>
			</div>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Zahlungsbedingungen') }}
					<textarea
						v-model="form.invoice_payment_terms"
						class="vbh-note-textarea"
						rows="2"
						maxlength="1000" />
				</label>
			</div>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Einleitungstext') }}
					<textarea
						v-model="form.invoice_note_head"
						class="vbh-note-textarea"
						rows="2"
						maxlength="2000" />
				</label>
				<label class="vbh-grow">{{ t('Schlusstext') }}
					<textarea
						v-model="form.invoice_note_foot"
						class="vbh-note-textarea"
						rows="2"
						maxlength="2000" />
				</label>
			</div>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Ablageordner für Rechnungs-Dateien') }}
					<input v-model="form.invoice_folder" maxlength="200" placeholder="Vereinsbuchhaltung/Rechnungen">
				</label>
			</div>
			<p class="vbh-hint">
				{{ t('Die PDFs liegen als Dateien im Ordner des Nutzers der Belegablage, jeweils in einem Unterordner je Jahr. Ist keiner eingerichtet, speichert die App sie intern.') }}
			</p>
			<p v-if="taxError" class="vbh-hint vbh-hint--error" role="alert">
				{{ taxError }}
			</p>
			<div class="vbh-form">
				<NcButton variant="primary" :disabled="saving" @click="saveTax">
					{{ t('Speichern') }}
				</NcButton>
			</div>
		</div>

		<div class="vbh-card">
			<h4>{{ t('E-Mail-Vorlage für den Versand') }}</h4>
			<p class="vbh-hint">
				{{ t('Platzhalter: {nummer}, {betrag}, {faellig}, {verein}, {empfaenger}. Beim Versand lässt sich der Text noch anpassen. Bleibt ein Feld leer, gilt der grau angezeigte Standardtext; „Standardtext übernehmen“ kopiert ihn zum Anpassen ins Feld.') }}
			</p>
			<div class="vbh-form">
				<SettingsTemplateField
					v-model="form.invoice_mail_subject"
					:label="t('Betreff')"
					:defaultText="defaults.invoice_mail_subject"
					:maxlength="200" />
				<SettingsTemplateField
					v-model="form.invoice_mail_body"
					:label="t('Text')"
					:defaultText="defaults.invoice_mail_body"
					multiline />
			</div>
			<p v-if="mailError" class="vbh-hint vbh-hint--error" role="alert">
				{{ mailError }}
			</p>
			<div class="vbh-form">
				<NcButton variant="primary" :disabled="saving" @click="saveMail">
					{{ t('Speichern') }}
				</NcButton>
			</div>
		</div>

		<SettingsInvoiceTemplates :accounts="accounts" />

		<div class="vbh-card">
			<h4>{{ t('Mahnwesen für Rechnungen') }}</h4>
			<p class="vbh-hint">
				{{ t('Drei Stufen: Zahlungserinnerung, 1. und 2. Mahnung. Das Mahnschreiben (PDF mit GiroCode über den Restbetrag) geht per E-Mail an den Empfänger. Bezahlte und stornierte Rechnungen werden nie gemahnt.') }}
			</p>
			<SettingsToggle v-model="form.invoice_dunning_auto" :label="t('Mahnungen automatisch versenden')">
				{{ t('Der tägliche Hintergrundjob verschickt fällige Stufen selbst per E-Mail. Aus: Mahnen kann der Verwalter in der Rechnung von Hand.') }}
			</SettingsToggle>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('E-Mail-Adresse des Vorstands') }}
					<input
						v-model="form.invoice_board_email"
						type="email"
						maxlength="255"
						autocomplete="off">
				</label>
			</div>
			<p class="vbh-hint">
				{{ t('Für Kopien von Rechnungs- und Mahnmails. Sie gehen als Blindkopie, der Empfänger sieht die Adresse nicht.') }}
			</p>
			<SettingsToggle v-model="form.invoice_dunning_copy_board" :label="t('Mailkopie an Vorstand')" :disabled="!form.invoice_board_email">
				{{ t('Jede Mahnmail geht zusätzlich an die Adresse oben, auch im automatischen Lauf. Beim Versand von Hand lässt sich die Kopie je Mail ändern.') }}
			</SettingsToggle>
			<div class="vbh-form">
				<label>{{ t('Erinnerung nach Fälligkeit (Tage)') }}
					<input
						v-model.number="form.invoice_dunning_days_1"
						class="vbh-short"
						type="number"
						min="0"
						max="365">
				</label>
				<label>{{ t('1. Mahnung nach der Erinnerung (Tage)') }}
					<input
						v-model.number="form.invoice_dunning_days_2"
						class="vbh-short"
						type="number"
						min="0"
						max="365">
				</label>
				<label>{{ t('2. Mahnung nach der 1. Mahnung (Tage)') }}
					<input
						v-model.number="form.invoice_dunning_days_3"
						class="vbh-short"
						type="number"
						min="0"
						max="365">
				</label>
				<label>{{ t('Zahlungsfrist im Schreiben (Tage)') }}
					<input
						v-model.number="form.invoice_dunning_grace_days"
						class="vbh-short"
						type="number"
						min="1"
						max="365">
				</label>
			</div>
			<p class="vbh-hint">
				{{ t('Platzhalter: {stufe}, {nummer}, {rechnungsdatum}, {betrag}, {rest}, {faellig}, {frist}, {verein}, {empfaenger}. Bleibt ein Feld leer, gilt der grau angezeigte Standardtext; „Standardtext übernehmen“ kopiert ihn zum Anpassen ins Feld.') }}
			</p>
			<div class="vbh-form">
				<SettingsTemplateField
					v-model="form.invoice_dunning_subject"
					:label="t('Betreff')"
					:defaultText="defaults.invoice_dunning_subject"
					:maxlength="200" />
				<SettingsTemplateField
					v-for="level in [1, 2, 3]"
					:key="level"
					v-model="form['invoice_dunning_body_' + level]"
					:label="dunningLabel(level)"
					:defaultText="defaults['invoice_dunning_body_' + level]"
					multiline />
			</div>
			<p v-if="dunningError" class="vbh-hint vbh-hint--error" role="alert">
				{{ dunningError }}
			</p>
			<div class="vbh-form">
				<NcButton variant="primary" :disabled="saving" @click="saveDunning">
					{{ t('Speichern') }}
				</NcButton>
			</div>
		</div>

		<div class="vbh-card">
			<h4>{{ t('Rechnungsnummern') }}</h4>
			<p class="vbh-hint">
				{{ t('Rechnungsnummern müssen fortlaufend und einmalig sein. Platzhalter: {JJJJ} (Jahr), {JJ} (zweistelliges Jahr) und {NNNN} (Zähler, die Zahl der N bestimmt die Stellen).') }}
			</p>
			<div v-for="range in ranges" :key="range.kind" class="vbh-inv-range">
				<h5>{{ range.kind === 'storno' ? t('Stornorechnungen') : t('Rechnungen') }}</h5>
				<div class="vbh-form">
					<label>{{ t('Muster') }}
						<input v-model="range.prefixPattern" maxlength="40">
					</label>
					<label>{{ t('Zähler') }}
						<select v-model="range.resetMode">
							<option value="yearly">{{ t('jedes Jahr bei 1 beginnen') }}</option>
							<option value="never">{{ t('fortlaufend, nie zurücksetzen') }}</option>
						</select>
					</label>
					<label>{{ t('Nächste Nummer') }}
						<input
							v-model.number="range.nextNo"
							class="vbh-short"
							type="number"
							min="1">
					</label>
					<NcButton variant="primary" :disabled="saving" @click="saveRange(range)">
						{{ t('Speichern') }}
					</NcButton>
				</div>
				<p class="vbh-hint">
					{{ t('Vorschau: {number}', { number: preview(range) }) }}
				</p>
				<p v-if="rangeErrors[range.kind]" class="vbh-hint vbh-hint--error" role="alert">
					{{ rangeErrors[range.kind] }}
				</p>
			</div>
		</div>
	</div>
	<NcLoadingIcon v-else :size="28" :name="t('Wird geladen…')" />
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import CountrySelect from './CountrySelect.vue'
import SettingsInvoiceTemplates from './SettingsInvoiceTemplates.vue'
import SettingsTemplateField from './SettingsTemplateField.vue'
import SettingsToggle from './SettingsToggle.vue'
import api from '../api.js'
import { errMsg } from '../lib/format.js'
import { dunningLabel, formatNumberPattern } from '../lib/invoices.js'

const KEYS = [
	'invoice_module_active',
	'invoice_auto_assign',
	'invoice_einvoice_auto',
	'invoice_rf_reference',
	'invoice_dunning_auto',
	'invoice_dunning_copy_board',
	'invoice_board_email',
	'invoice_dunning_days_1',
	'invoice_dunning_days_2',
	'invoice_dunning_days_3',
	'invoice_dunning_grace_days',
	'invoice_dunning_subject',
	'invoice_dunning_body_1',
	'invoice_dunning_body_2',
	'invoice_dunning_body_3',
	'invoice_tax_mode',
	'invoice_default_exemption_text',
	'invoice_default_due_days',
	'invoice_payment_terms',
	'invoice_note_head',
	'invoice_note_foot',
	'invoice_account_id',
	'invoice_bic',
	'invoice_vat_account_19',
	'invoice_vat_account_7',
	'invoice_rate_zweckbetrieb',
	'invoice_rate_wirtschaftlich',
	'invoice_folder',
	'invoice_mail_subject',
	'invoice_mail_body',
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
 * Einstellungen des Rechnungsmoduls (Epic #168): Modul ein- und ausschalten,
 * Angaben des Vereins für die Pflichtangaben, Steuer-Modus, Zahlungsangaben,
 * Mail-Vorlage und Nummernkreise. Schreiben dürfen nur Verwalter (SettingsController).
 * Jede Karte speichert nur ihre eigenen Felder; Fehler des Servers stehen in der Karte.
 */
export default {
	name: 'SettingsInvoices',
	components: { NcButton, NcLoadingIcon, CountrySelect, SettingsInvoiceTemplates, SettingsTemplateField, SettingsToggle },

	data() {
		return {
			loaded: false,
			saving: false,
			form: {},
			missing: [],
			defaults: {},
			accounts: [],
			ranges: [],
			stateError: '',
			issuerError: '',
			taxError: '',
			mailError: '',
			dunningError: '',
			rangeErrors: {},
		}
	},

	computed: {
		bankAccounts() { return this.accounts.filter((a) => a.isBank && a.iban) },
		vatAccounts() { return this.accounts.filter((a) => !a.isBank && a.active && ['liability', 'income'].includes(a.type)) },
	},

	async mounted() {
		try {
			const [settings, accounts, ranges] = await Promise.all([api.getSettings(), api.listAccounts(), api.invoiceNumberRanges()])
			this.accounts = accounts.data
			this.ranges = ranges.data.map((r) => ({ ...r }))
			this.apply(settings.data)
			this.loaded = true
		} catch (e) {
			showError(errMsg(e, this.t('Die Einstellungen konnten nicht geladen werden.')))
		}
	},

	methods: {
		apply(data) {
			const form = {}
			for (const key of KEYS) { form[key] = data[key] ?? '' }
			form.invoice_account_id = data.invoice_account_id ?? null
			form.invoice_vat_account_19 = data.invoice_vat_account_19 ?? null
			form.invoice_vat_account_7 = data.invoice_vat_account_7 ?? null
			form.invoice_rate_zweckbetrieb = data.invoice_rate_zweckbetrieb ?? 700
			form.invoice_rate_wirtschaftlich = data.invoice_rate_wirtschaftlich ?? 1900
			form.invoice_default_due_days = data.invoice_default_due_days ?? 14
			this.form = form
			this.missing = data.invoice_missing_issuer_fields || []
			this.defaults = data.invoice_default_texts || {}
		},

		preview(range) {
			return formatNumberPattern(range.prefixPattern, new Date(), range.nextNo || 1)
		},

		async store(keys, errorKey) {
			this.saving = true
			this[errorKey] = ''
			try {
				const body = {}
				for (const key of keys) {
					const value = this.form[key]
					body[key] = typeof value === 'boolean' ? (value ? '1' : '0') : value
				}
				const { data } = await api.saveSettings(body)
				this.apply(data)
				showSuccess(this.t('Einstellungen gespeichert.'))
				return true
			} catch (e) {
				this[errorKey] = errMsg(e, this.t('Die Einstellungen konnten nicht gespeichert werden.'))
				return false
			} finally {
				this.saving = false
			}
		},

		saveModule() { return this.store(['invoice_module_active', 'invoice_auto_assign', 'invoice_einvoice_auto', 'invoice_rf_reference'], 'stateError') },

		saveIssuer() {
			return this.store(['club_street', 'club_postal_code', 'club_city', 'club_country', 'club_tax_number', 'club_vat_id', 'club_register_court', 'club_register_no', 'club_representatives', 'club_phone', 'club_email', 'club_nonprofit_note'], 'issuerError')
		},

		saveTax() {
			return this.store(['invoice_tax_mode', 'invoice_vat_account_19', 'invoice_vat_account_7', 'invoice_rate_zweckbetrieb', 'invoice_rate_wirtschaftlich', 'invoice_default_due_days', 'invoice_default_exemption_text', 'invoice_account_id', 'invoice_bic', 'invoice_payment_terms', 'invoice_note_head', 'invoice_note_foot', 'invoice_folder'], 'taxError')
		},

		saveDunning() {
			return this.store(['invoice_dunning_auto', 'invoice_dunning_copy_board', 'invoice_board_email', 'invoice_dunning_days_1', 'invoice_dunning_days_2', 'invoice_dunning_days_3', 'invoice_dunning_grace_days', 'invoice_dunning_subject', 'invoice_dunning_body_1', 'invoice_dunning_body_2', 'invoice_dunning_body_3'], 'dunningError')
		},

		dunningLabel,

		saveMail() { return this.store(['invoice_mail_subject', 'invoice_mail_body'], 'mailError') },

		async saveRange(range) {
			this.saving = true
			this.rangeErrors = { ...this.rangeErrors, [range.kind]: '' }
			try {
				const { data } = await api.saveInvoiceNumberRange(range.kind, {
					prefixPattern: range.prefixPattern,
					resetMode: range.resetMode,
					nextNo: range.nextNo || null,
				})
				Object.assign(range, data)
				showSuccess(this.t('Nummernkreis gespeichert.'))
			} catch (e) {
				this.rangeErrors = { ...this.rangeErrors, [range.kind]: errMsg(e, this.t('Der Nummernkreis konnte nicht gespeichert werden.')) }
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
/* Die Felder füllen ihre Zeile: je Feld mindestens 220 px, kurze Angaben (PLZ, Registernummer, Tage) nur 150 px. */
.vbh-inv-settings .vbh-form label { flex: 1 1 220px; min-width: 0; }

.vbh-inv-settings .vbh-form label:has(> .vbh-short) { flex: 0 1 150px; }

.vbh-inv-settings .vbh-form label > input:not([type="checkbox"]), .vbh-inv-settings .vbh-form label > select, .vbh-inv-settings .vbh-form label > textarea { width: 100%; min-width: 0; box-sizing: border-box; }

.vbh-inv-settings .vbh-form label :deep(.v-select) { width: 100%; min-width: 0; }
</style>
