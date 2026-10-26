<template>
	<div class="vbh-inv-editor">
		<div class="vbh-sectiontop">
			<div class="vbh-subtabs">
				<NcButton variant="tertiary" @click="close">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArrowLeft" :size="20" />
					</template>
					{{ t('Zur Liste') }}
				</NcButton>
				<strong class="vbh-inv-editor-title">{{ title }}</strong>
			</div>
			<div v-if="isPlan" class="vbh-sectiontop-actions">
				<span class="vbh-inv-saved" role="status">{{ saveStateText }}</span>
				<NcButton
					v-if="isAdmin"
					variant="primary"
					:disabled="saving || loading || form.debtorId === null"
					@click="savePlan">
					{{ t('Plan speichern') }}
				</NcButton>
			</div>
			<div v-else class="vbh-sectiontop-actions">
				<span class="vbh-inv-saved" role="status">{{ saveStateText }}</span>
				<NcButton variant="tertiary" :disabled="saving" @click="preview">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFilePdfBox" :size="20" />
					</template>
					{{ t('Vorschau') }}
				</NcButton>
				<NcButton variant="secondary" :disabled="saving" @click="saveNow">
					{{ t('Entwurf speichern') }}
				</NcButton>
				<NcButton
					v-if="isAdmin"
					variant="primary"
					:disabled="saving || loading"
					@click="openIssue">
					{{ t('Festschreiben …') }}
				</NcButton>
			</div>
		</div>

		<div v-if="loading" class="vbh-sectionbody">
			<NcLoadingIcon :size="32" :name="t('Wird geladen…')" />
		</div>

		<div v-else class="vbh-sectionbody vbh-inv-body">
			<p v-if="meta && meta.missingIssuerFields.length" class="vbh-hint vbh-hint--warning">
				{{ t('In den Einstellungen fehlen noch Angaben des Vereins (Anschrift, Steuernummer). Ohne sie lässt sich keine Rechnung festschreiben.') }}
			</p>
			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>

			<div class="vbh-inv-layout">
				<!-- 0 · Art: einmalig oder wiederkehrend -->
				<div v-if="canChooseKind" class="vbh-inv-intro">
					<div class="vbh-inv-seg" role="radiogroup" :aria-label="t('Art')">
						<label :class="{ on: !isPlan }">
							<input v-model="isPlan" type="radio" :value="false"> {{ t('Einmalige Rechnung') }}
						</label>
						<label :class="{ on: isPlan, disabled: !isAdmin }" :title="isAdmin ? '' : t('Wiederkehrende Rechnungen legt nur ein Verwalter an.')">
							<input
								v-model="isPlan"
								type="radio"
								:value="true"
								:disabled="!isAdmin"> {{ t('Wiederkehrende Rechnung') }}
						</label>
					</div>
				</div>
				<p v-if="isPlan && !plan" class="vbh-hint vbh-hint--info vbh-inv-intro">
					{{ t('Ein Plan legt im gewählten Takt neue Rechnungen mit diesen Angaben an. Jetzt entsteht noch keine Rechnung; die erste folgt am gewählten Datum.') }}
				</p>
				<p v-else-if="!isPlan && !isAdmin" class="vbh-hint vbh-hint--info vbh-inv-intro">
					{{ t('Festschreiben und Versenden darf nur ein Verwalter. Sie können den Entwurf anlegen und vorbereiten.') }}
				</p>

				<!-- 1 · Empfänger -->
				<section class="vbh-card vbh-inv-card">
					<h3>{{ t('Empfänger') }}</h3>
					<DebtorSelect v-model="form.debtorId" @debtor="debtor = $event" />
				</section>

				<!-- 2 · Rechnungsdaten bzw. Wiederholung -->
				<section v-if="isPlan" class="vbh-card vbh-inv-card">
					<h3>{{ t('Wiederholung') }}</h3>
					<div class="vbh-inv-grid">
						<label class="vbh-inv-span">{{ t('Name des Plans') }}
							<input v-model="planForm.name" maxlength="255" :placeholder="t('z. B. Hallenmiete Sportstudio')">
						</label>
						<label>{{ t('Takt') }}
							<select v-model.number="planForm.intervalMonths">
								<option v-for="m in planIntervals" :key="m" :value="m">{{ planIntervalLabel(m) }}</option>
							</select>
						</label>
						<label>{{ t('Zahlungsziel (Tage)') }}
							<input
								v-model="planForm.dueDays"
								type="number"
								min="0"
								max="365"
								:placeholder="t('Standard')">
						</label>
						<label>{{ t('Erste Rechnung am') }}
							<input v-model="planForm.startDate" type="date">
						</label>
						<label>{{ t('Ende (optional)') }}
							<input v-model="planForm.endDate" type="date" :min="planForm.startDate || undefined">
						</label>
						<label class="vbh-inv-span">{{ t('Leistungszeitraum') }}
							<select v-model="planForm.periodMode">
								<option value="interval">{{ t('der Zeitraum des Taktes, der mit der Rechnung beginnt') }}</option>
								<option value="issue_date">{{ t('Leistungsdatum = Rechnungsdatum') }}</option>
							</select>
						</label>
					</div>
					<label class="vbh-checkrow">
						<input v-model="planForm.autoIssue" type="checkbox"> {{ t('Rechnung gleich festschreiben') }}
					</label>
					<label class="vbh-checkrow">
						<input v-model="planForm.autoSend" type="checkbox" :disabled="!planForm.autoIssue"> {{ t('und per E-Mail an den Empfänger senden') }}
					</label>
					<p class="vbh-hint">
						{{ t('Ohne Festschreiben bleibt jede Rechnung ein Entwurf, den Sie prüfen und festschreiben. Fehlen beim Festschreiben Angaben, bleibt der Entwurf liegen und der Plan nennt den Grund.') }}
					</p>
					<p class="vbh-hint">
						{{ t('In Bezeichnungen und Texten ersetzt der Lauf {monat}, {jahr}, {quartal} und {zeitraum} durch die Angaben des Termins.') }}
					</p>
				</section>
				<section v-else class="vbh-card vbh-inv-card">
					<h3>{{ t('Rechnungsdaten') }}</h3>
					<div class="vbh-inv-grid">
						<label>{{ t('Rechnungsdatum') }}
							<input v-model="form.issueDate" type="date">
						</label>
						<label>{{ t('Zahlbar bis') }}
							<input v-model="form.dueDate" type="date" :min="form.issueDate || undefined">
						</label>
					</div>
					<fieldset class="vbh-fieldset-reset vbh-inv-delivery">
						<legend class="vbh-inv-legend">
							{{ t('Leistungszeitpunkt (Pflichtangabe)') }}
						</legend>
						<div class="vbh-inv-delivery-row">
							<div class="vbh-inv-seg vbh-inv-seg--small" role="radiogroup" :aria-label="t('Leistungszeitpunkt (Pflichtangabe)')">
								<label :class="{ on: leistungMode === 'date' }">
									<input v-model="leistungMode" type="radio" value="date"> {{ t('Datum') }}
								</label>
								<label :class="{ on: leistungMode === 'period' }">
									<input v-model="leistungMode" type="radio" value="period"> {{ t('Zeitraum') }}
								</label>
							</div>
							<input
								v-if="leistungMode === 'date'"
								v-model="form.deliveryDate"
								type="date"
								:aria-label="t('Leistungsdatum')">
							<template v-else>
								<input v-model="form.periodStart" type="date" :aria-label="t('von')">
								<span class="vbh-inv-dash" aria-hidden="true">–</span>
								<input
									v-model="form.periodEnd"
									type="date"
									:min="form.periodStart || undefined"
									:aria-label="t('bis')">
							</template>
						</div>
					</fieldset>
				</section>

				<!-- 3 · Positionen -->
				<section class="vbh-card vbh-inv-card">
					<h3>{{ t('Positionen') }}</h3>
					<div class="vbh-inv-lines">
						<div class="vbh-inv-l1 vbh-inv-l1--head" aria-hidden="true">
							<span>{{ t('Bezeichnung') }}</span>
							<span class="vbh-inv-r">{{ t('Menge') }}</span>
							<span>{{ t('Einheit') }}</span>
							<span class="vbh-inv-r">{{ t('Einzelpreis') }}</span>
							<span class="vbh-inv-r">{{ t('Netto') }}</span>
							<span />
						</div>
						<div v-for="(line, i) in form.lines" :key="line.key" class="vbh-inv-line">
							<div class="vbh-inv-l1">
								<input
									v-model="line.name"
									class="vbh-inv-name"
									:aria-label="t('Bezeichnung')"
									maxlength="255"
									:placeholder="t('Leistung, z. B. Hallenmiete')">
								<input
									v-model="line.quantity"
									class="vbh-inv-r"
									inputmode="decimal"
									:aria-label="t('Menge')"
									:class="{ 'vbh-inv-invalid': quantityInvalid(line) }">
								<select v-model="line.unitCode" :aria-label="t('Einheit')">
									<option v-for="code in unitCodes" :key="code" :value="code">
										{{ unitLabel(code) }}
									</option>
								</select>
								<AmountInput
									v-model="line.unitPrice"
									class="vbh-inv-price"
									:aria-label="t('Einzelpreis')" />
								<span class="vbh-inv-r vbh-inv-net">{{ netText(line) }}</span>
								<span class="vbh-inv-actions">
									<NcButton
										v-if="form.lines.length > 1"
										variant="tertiary"
										:disabled="i === 0"
										:aria-label="t('Position nach oben')"
										:title="t('Nach oben')"
										@click="moveLine(i, -1)">
										<template #icon>
											<NcIconSvgWrapper :path="mdiArrowUp" :size="18" />
										</template>
									</NcButton>
									<NcButton
										v-if="form.lines.length > 1"
										variant="tertiary"
										:disabled="i === form.lines.length - 1"
										:aria-label="t('Position nach unten')"
										:title="t('Nach unten')"
										@click="moveLine(i, 1)">
										<template #icon>
											<NcIconSvgWrapper :path="mdiArrowDown" :size="18" />
										</template>
									</NcButton>
									<NcButton
										variant="tertiary"
										:aria-label="t('Position entfernen')"
										:title="t('Entfernen')"
										@click="removeLine(i)">
										<template #icon>
											<NcIconSvgWrapper :path="mdiDelete" :size="18" />
										</template>
									</NcButton>
								</span>
							</div>
							<div class="vbh-inv-l2">
								<label>{{ t('Erlöskonto') }}
									<select
										v-model="line.accountId"
										:class="{ 'vbh-inv-invalid': !line.accountId && !isEmptyLine(line) }"
										@change="onAccountChange(line)">
										<option :value="null">
											{{ t('– Konto wählen –') }}
										</option>
										<option v-for="a in accounts" :key="a.id" :value="a.id">
											{{ a.number }} {{ a.name }}
										</option>
									</select>
								</label>
								<label v-if="regular">{{ t('USt') }}
									<select v-model="line.taxKey" :aria-label="t('Umsatzsteuer der Position')" @change="line.taxTouched = true">
										<option v-for="key in taxKeys" :key="key" :value="key">
											{{ taxKeyLabel(key) }}
										</option>
									</select>
								</label>
								<button
									v-if="!detailsOpen(line)"
									type="button"
									class="vbh-inv-linkbtn"
									@click="line.showDetails = true">
									{{ t('Beschreibung, Rabatt …') }}
								</button>
							</div>
							<div v-if="detailsOpen(line)" class="vbh-inv-l3">
								<label class="vbh-inv-l3-desc">{{ t('Beschreibung') }}
									<textarea
										v-model="line.description"
										rows="2"
										maxlength="2000"
										:placeholder="t('Beschreibung (optional)')" />
								</label>
								<label class="vbh-inv-l3-discount">{{ t('Rabatt %') }}
									<input
										v-model="line.discountPercent"
										class="vbh-inv-r"
										inputmode="decimal"
										placeholder="0">
								</label>
							</div>
						</div>
					</div>
					<div class="vbh-inv-linebar">
						<NcButton variant="secondary" @click="addLine">
							<template #icon>
								<NcIconSvgWrapper :path="mdiPlus" :size="20" />
							</template>
							{{ t('Position hinzufügen') }}
						</NcButton>
						<select
							v-if="templates.length"
							v-model="templatePick"
							class="vbh-inv-tplpick"
							:aria-label="t('Position aus Vorlage einfügen')"
							@change="addFromTemplate">
							<option value="">
								{{ t('Aus Vorlage einfügen …') }}
							</option>
							<option v-for="tpl in templates" :key="tpl.id" :value="tpl.id">
								{{ tpl.name }}
							</option>
						</select>
					</div>
					<p v-if="sums.invalid" class="vbh-hint vbh-hint--error">
						{{ t('Mindestens eine Menge oder ein Rabatt ist keine gültige Zahl.') }}
					</p>
				</section>

				<!-- Seitenleiste: Summe und, was zum Festschreiben noch fehlt (mobil unter den Positionen) -->
				<aside class="vbh-inv-side" :aria-label="t('Zusammenfassung')">
					<div class="vbh-card vbh-inv-card vbh-inv-sum">
						<h3>{{ t('Summe') }}</h3>
						<dl class="vbh-inv-totals">
							<template v-if="regular">
								<div>
									<dt>{{ t('Summe netto') }}</dt>
									<dd>{{ formatMoney(sums.netCents / 100) }}</dd>
								</div>
								<div v-for="g in sums.taxBreakdown" :key="g.rateBp">
									<dt>{{ t('zzgl. {rate} USt', { rate: formatRate(g.rateBp) }) }}</dt>
									<dd>{{ formatMoney(g.taxCents / 100) }}</dd>
								</div>
							</template>
							<div class="vbh-inv-totals-grand">
								<dt>{{ t('Rechnungsbetrag') }}</dt>
								<dd>{{ formatMoney(sums.grossCents / 100) }}</dd>
							</div>
						</dl>
						<p class="vbh-hint vbh-inv-taxline">
							{{ taxModeLabel(form.taxMode) }}
						</p>
					</div>

					<div v-if="!isPlan" class="vbh-card vbh-inv-card vbh-inv-checks" role="status">
						<h3>{{ requiredChecks.length === 0 ? t('Zum Festschreiben') : openChecks.length ? t('Zum Festschreiben fehlt noch') : t('Bereit zum Festschreiben') }}</h3>
						<p v-if="requiredChecks.length === 0" class="vbh-hint">
							{{ t('Der Entwurf wird beim Eingeben gespeichert. Danach steht hier, was zum Festschreiben noch fehlt.') }}
						</p>
						<ul v-else class="vbh-inv-checklist">
							<li v-for="c in requiredChecks" :key="c.key" :class="c.ok ? 'ok' : 'open'">
								<NcIconSvgWrapper :path="c.ok ? mdiCheckCircleOutline : mdiCircleOutline" :size="18" />
								<span>{{ c.label }}</span>
							</li>
						</ul>
						<template v-if="hintChecks.length">
							<h4>{{ t('Zur Beachtung') }}</h4>
							<ul class="vbh-inv-checklist vbh-inv-hints">
								<li v-for="c in hintChecks" :key="c.key" class="open">
									<NcIconSvgWrapper :path="mdiAlertOutline" :size="18" />
									<span>{{ c.label }}</span>
								</li>
							</ul>
						</template>
					</div>
					<div v-if="currentId && !isPlan" class="vbh-inv-discard">
						<NcButton variant="tertiary" @click="discardOpen = true">
							<template #icon>
								<NcIconSvgWrapper :path="mdiDelete" :size="20" />
							</template>
							{{ t('Entwurf verwerfen') }}
						</NcButton>
					</div>
				</aside>

				<!-- 4 · Weitere Angaben (meist unverändert) -->
				<details class="vbh-card vbh-inv-card vbh-inv-more" :open="moreOpen" @toggle="moreOpen = $event.target.open">
					<summary>
						<strong>{{ t('Weitere Angaben') }}</strong>
						<span class="vbh-hint">{{ moreHint }}</span>
					</summary>
					<div class="vbh-inv-more-body">
						<div class="vbh-inv-grid">
							<label>{{ t('Ihre Referenz / Leitweg-ID') }}
								<input v-model="form.buyerReference" maxlength="255">
							</label>
							<label>{{ t('Bestellnummer') }}
								<input v-model="form.orderReference" maxlength="255">
							</label>
						</div>
						<template v-if="!isPlan">
							<div class="vbh-inv-grid">
								<label>{{ t('Umsatzsteuer') }}
									<select v-model="form.taxMode">
										<option v-for="mode in taxModes" :key="mode" :value="mode">
											{{ taxModeLabel(mode) }}
										</option>
									</select>
								</label>
							</div>
							<p v-if="form.taxMode === 'kleinunternehmer'" class="vbh-hint">
								{{ t('Ausgewiesen wird keine Umsatzsteuer. Auf der Rechnung steht der Hinweis:') }}
								<em>{{ form.exemptionText || (meta && meta.smallBusinessNotice) }}</em>
							</p>
							<p v-if="regular" class="vbh-hint">
								{{ t('Die Umsatzsteuer wird je Position gewählt (19 %, 7 %, 0 % oder steuerfrei). Beim Festschreiben braucht jeder verwendete Satz ein Umsatzsteuerkonto in den Einstellungen.') }}
							</p>
							<label v-if="form.taxMode === 'kleinunternehmer' || form.taxMode === 'exempt' || needsExemptionText" class="vbh-inv-wide">
								{{ form.taxMode === 'kleinunternehmer' ? t('Eigener Hinweistext (optional, ersetzt den Standardtext)') : t('Hinweis auf die Steuerbefreiung (Pflicht)') }}
								<input
									v-model="form.exemptionText"
									maxlength="500"
									:placeholder="form.taxMode === 'kleinunternehmer' ? '' : t('z. B. Steuerfrei nach § 4 Nr. 22 UStG')">
							</label>
						</template>
						<p v-else class="vbh-hint">
							{{ t('Die Umsatzsteuer gilt so, wie sie in den Einstellungen steht, wenn der Plan die Rechnung anlegt.') }}
						</p>
						<label class="vbh-inv-wide">{{ t('Einleitungstext') }}
							<textarea
								v-model="form.noteHead"
								rows="2"
								maxlength="2000"
								:placeholder="t('Steht über den Positionen')" />
						</label>
						<label class="vbh-inv-wide">{{ t('Schlusstext') }}
							<textarea
								v-model="form.noteFoot"
								rows="2"
								maxlength="2000"
								:placeholder="t('Steht unter dem Zahlungsblock')" />
						</label>
						<label class="vbh-inv-wide">{{ t('Zahlungsbedingungen') }}
							<textarea
								v-model="form.paymentTerms"
								rows="2"
								maxlength="1000"
								:placeholder="t('z. B. Zahlbar innerhalb von 14 Tagen ohne Abzug.')" />
						</label>
					</div>
				</details>
			</div>
		</div>

		<InvoiceIssueDialog
			:show="issue.open"
			:checklist="checklist"
			:issueDate="form.issueDate"
			:busy="issue.busy"
			:error="issue.error"
			@close="issue.open = false"
			@update:show="issue.open = $event"
			@confirm="confirmIssue" />

		<NcModal
			v-if="leaveOpen"
			:show="leaveOpen"
			labelId="vbh-modal-title-inv-leave"
			size="small"
			@close="leaveOpen = false"
			@update:show="leaveOpen = $event">
			<div class="vbh-modal-inner">
				<h2 id="vbh-modal-title-inv-leave" class="vbh-modal-title">
					{{ t('Änderungen verwerfen?') }}
				</h2>
				<p>{{ t('Der Plan wurde noch nicht gespeichert. Die Änderungen gehen verloren.') }}</p>
				<div class="vbh-modal-actions">
					<NcButton variant="tertiary" @click="leaveOpen = false">
						{{ t('Weiter bearbeiten') }}
					</NcButton>
					<NcButton variant="error" @click="leaveNow">
						{{ t('Verwerfen') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<NcModal
			v-if="discardOpen"
			:show="discardOpen"
			labelId="vbh-modal-title-inv-discard"
			size="small"
			@close="discardOpen = false"
			@update:show="discardOpen = $event">
			<div class="vbh-modal-inner">
				<h2 id="vbh-modal-title-inv-discard" class="vbh-modal-title">
					{{ t('Entwurf verwerfen?') }}
				</h2>
				<p>{{ t('Der Entwurf wird gelöscht. Es wurde noch keine Rechnungsnummer vergeben.') }}</p>
				<div class="vbh-modal-actions">
					<NcButton variant="tertiary" @click="discardOpen = false">
						{{ t('Abbrechen') }}
					</NcButton>
					<NcButton variant="error" @click="discard">
						{{ t('Verwerfen') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { mdiAlertOutline, mdiArrowDown, mdiArrowLeft, mdiArrowUp, mdiCheckCircleOutline, mdiCircleOutline, mdiDelete, mdiFilePdfBox, mdiPlus } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import AmountInput from './AmountInput.vue'
import DebtorSelect from './DebtorSelect.vue'
import InvoiceIssueDialog from './InvoiceIssueDialog.vue'
import api from '../api.js'
import { errMsg, formatMoney } from '../lib/format.js'
import {
	addDays,
	defaultPlanName,
	dueDaysOf,
	editorLineNet,
	editorTotals,
	emptyLine,
	isEmptyLine,
	lineFromPlan,
	lineFromServer,
	lineFromTemplate,
	linesToPayload,
	PLAN_INTERVALS,
	planIntervalLabel,
	quantityToE4,
	suggestTaxKey,
	taxKeyLabel,
	todayIso,
	unitLabel,
} from '../lib/invoices.js'

const AUTOSAVE_MS = 1500

/**
 * Editor für Rechnungsentwürfe (Epic #168): Empfänger, Rechnungsdaten,
 * Positionen mit Erlöskonto, Steuer-Hinweis und Texte. Der Entwurf wird beim
 * Tippen automatisch gespeichert (kurz nach der letzten Änderung), sobald ein
 * Empfänger oder eine Position vorhanden ist. Festschreiben macht daraus eine
 * nummerierte, unveränderliche Rechnung (nur Verwalter).
 */
export default {
	name: 'InvoiceEditor',
	components: { NcButton, NcIconSvgWrapper, NcLoadingIcon, NcModal, AmountInput, DebtorSelect, InvoiceIssueDialog },
	props: {
		/** ID eines vorhandenen Entwurfs; ohne Angabe beginnt eine neue Rechnung. */
		invoiceId: { type: Number, default: null },
		/** Aktive Einnahmenkonten für die Erlöskonto-Auswahl. */
		accounts: { type: Array, default: () => [] },
		/** Antwort von GET /api/invoices/meta. */
		meta: { type: Object, default: null },
		isAdmin: { type: Boolean, default: false },
		/** Ein vorhandener Plan für wiederkehrende Rechnungen, der bearbeitet wird. */
		plan: { type: Object, default: null },
		/** Ein neuer Plan beginnt leer. */
		startAsPlan: { type: Boolean, default: false },
		/** Ein neuer Plan, vorbelegt aus dieser (festgeschriebenen) Rechnung. */
		fromInvoice: { type: Object, default: null },
	},

	emits: ['close', 'changed', 'issued', 'planSaved'],

	setup() {
		return { mdiArrowDown, mdiArrowLeft, mdiAlertOutline, mdiArrowUp, mdiCheckCircleOutline, mdiCircleOutline, mdiDelete, mdiFilePdfBox, mdiPlus }
	},

	data() {
		return {
			loading: true,
			saving: false,
			error: '',
			currentId: this.invoiceId,
			debtor: null,
			leistungMode: 'date',
			form: this.blankForm(),
			dirty: false,
			savedAt: null,
			checklist: [],
			autosaveTimer: null,
			saveQueue: Promise.resolve(),
			discardOpen: false,
			issue: { open: false, busy: false, error: '' },
			templates: [],
			templatePick: '',
			isPlan: Boolean(this.plan || this.startAsPlan || this.fromInvoice),
			planForm: this.blankPlanForm(),
			leaveOpen: false,
			moreOpen: false,
		}
	},

	computed: {
		unitCodes() { return this.meta?.unitCodes || ['C62', 'HUR', 'DAY', 'MON', 'LS'] },
		taxModes() { return this.meta?.taxModes || ['kleinunternehmer'] },
		regular() { return this.form.taxMode === 'regular' },
		taxKeys() { return ['S1900', 'S700', 'Z', 'E'] },
		rates() { return this.meta?.rates || { zweckbetrieb: 700, wirtschaftlich: 1900 } },
		accountsById() { return Object.fromEntries(this.accounts.map((a) => [a.id, a])) },
		needsExemptionText() { return this.regular && this.form.lines.some((l) => l.taxKey === 'E' && !isEmptyLine(l)) },
		sums() { return editorTotals(this.form.lines, this.regular) },
		requiredChecks() { return this.checklist.filter((c) => c.required) },
		/** Empfehlungen, die das Festschreiben nicht verhindern (z. B. Spendenkonto als Position). */
		hintChecks() { return this.checklist.filter((c) => !c.required && !c.ok) },
		/** Kurzer Hinweis neben „Weitere Angaben“, damit man nicht aufklappen muss, um zu sehen, was drinsteht. */
		moreHint() {
			const f = this.form
			const parts = []
			if (f.buyerReference) { parts.push(this.tRaw('Referenz: {ref}', { ref: f.buyerReference })) }
			if (f.orderReference) { parts.push(this.tRaw('Bestellnummer: {ref}', { ref: f.orderReference })) }
			if (!this.isPlan && f.taxMode !== (this.meta?.taxMode || 'kleinunternehmer')) { parts.push(this.taxModeLabel(f.taxMode)) }
			if (parts.length === 0) { return this.t('Referenzen, Umsatzsteuer, Einleitungs- und Schlusstext, Zahlungsbedingungen') }
			return parts.join(' · ')
		},

		openChecks() { return this.checklist.filter((c) => c.required && !c.ok) },
		planIntervals() { return PLAN_INTERVALS },
		/** Die Art (einmalig oder wiederkehrend) lässt sich nur beim Anlegen wählen. */
		canChooseKind() { return !this.plan && !this.fromInvoice },
		title() {
			if (this.plan) { return this.t('Plan bearbeiten') }
			if (this.isPlan) { return this.t('Neuer Plan für wiederkehrende Rechnungen') }
			return this.currentId ? this.t('Rechnungsentwurf') : this.t('Neue Rechnung')
		},

		hasContent() { return this.form.debtorId !== null || this.form.lines.some((l) => !isEmptyLine(l)) },

		saveStateText() {
			if (this.saving) { return this.t('Speichert …') }
			if (this.dirty) { return this.t('Ungespeicherte Änderungen') }
			if (this.savedAt) { return this.t('Gespeichert um {time}', { time: this.savedAt }) }
			return ''
		},
	},

	watch: {
		form: {
			deep: true,
			handler() {
				if (this.loading) { return }
				this.dirty = true
				this.scheduleAutosave()
			},
		},

		'form.taxMode': function(mode) {
			if (this.loading || mode !== 'regular') { return }
			for (const line of this.form.lines) { this.onAccountChange(line) }
		},

		planForm: {
			deep: true,
			handler() {
				if (!this.loading) { this.dirty = true }
			},
		},

		isPlan(plan) {
			// Ein Plan speichert sich nicht von selbst; ein schon angelegter Entwurf bleibt, bis der Plan gespeichert ist.
			if (plan) { clearTimeout(this.autosaveTimer) }
		},

		leistungMode(mode) {
			// Je Rechnung gilt entweder ein Datum oder ein Zeitraum.
			if (this.loading) { return }
			if (mode === 'date') {
				this.form.periodStart = ''
				this.form.periodEnd = ''
			} else {
				this.form.deliveryDate = ''
			}
		},

		'form.issueDate': function(date, old) {
			// Das Zahlungsziel folgt dem Rechnungsdatum, solange es nicht von Hand geändert wurde.
			if (this.loading || !date || !old) { return }
			const days = this.meta?.defaultDueDays ?? 14
			if (this.form.dueDate === addDays(old, days)) { this.form.dueDate = addDays(date, days) }
		},
	},

	async mounted() {
		this.loadTemplates()
		await this.load()
	},

	beforeUnmount() {
		clearTimeout(this.autosaveTimer)
	},

	methods: {
		formatMoney,
		isEmptyLine,
		planIntervalLabel,
		unitLabel,

		blankForm() {
			const today = todayIso()
			const days = this.meta?.defaultDueDays ?? 14
			const d = this.meta?.defaults || {}
			return {
				debtorId: null,
				issueDate: today,
				deliveryDate: today,
				periodStart: '',
				periodEnd: '',
				dueDate: addDays(today, days),
				buyerReference: '',
				orderReference: '',
				taxMode: this.meta?.taxMode || 'kleinunternehmer',
				exemptionText: d.exemptionText || '',
				noteHead: d.noteHead || '',
				noteFoot: d.noteFoot || '',
				paymentTerms: d.paymentTerms || '',
				lines: [emptyLine()],
			}
		},

		/** Beschreibung und Rabatt stehen erst offen, wenn sie gebraucht werden oder schon etwas enthalten. */
		detailsOpen(line) {
			return Boolean(line.showDetails || line.description || line.discountPercent)
		},

		taxModeLabel(mode) {
			return {
				exempt: this.t('Steuerbefreit (Hinweis nach § 4 UStG)'),
				regular: this.t('Regelbesteuerung (Umsatzsteuer ausweisen)'),
			}[mode] || this.t('Kleinunternehmer (§ 19 UStG)')
		},

		taxKeyLabel,

		formatRate(rateBp) {
			return new Intl.NumberFormat('de-DE', { maximumFractionDigits: 2 }).format(rateBp / 100) + ' %'
		},

		/** Ein neues Erlöskonto schlägt den Steuersatz vor, solange er nicht von Hand gewählt wurde. */
		onAccountChange(line) {
			if (!this.regular || line.taxTouched) { return }
			line.taxKey = suggestTaxKey(this.accountsById[line.accountId] || null, this.rates)
		},

		blankPlanForm() {
			return { name: '', intervalMonths: 1, startDate: todayIso(), endDate: '', periodMode: 'interval', dueDays: '', autoIssue: false, autoSend: false }
		},

		/** Formular und Planangaben aus einem gespeicherten Plan. */
		applyPlan(plan) {
			this.form = {
				...this.blankForm(),
				debtorId: plan.debtorId,
				buyerReference: plan.buyerReference || '',
				orderReference: plan.orderReference || '',
				noteHead: plan.noteHead || '',
				noteFoot: plan.noteFoot || '',
				paymentTerms: plan.paymentTerms || '',
				lines: plan.lines.length ? plan.lines.map(lineFromPlan) : [emptyLine()],
			}
			this.moreOpen = Boolean(this.form.buyerReference || this.form.orderReference)
			this.planForm = {
				name: plan.name,
				intervalMonths: plan.intervalMonths,
				startDate: plan.startDate,
				endDate: plan.endDate || '',
				periodMode: plan.periodMode,
				dueDays: plan.dueDays ?? '',
				autoIssue: plan.autoIssue,
				autoSend: plan.autoSend,
			}
		},

		async load() {
			this.loading = true
			try {
				if (this.plan) {
					this.applyPlan(this.plan)
				} else if (this.fromInvoice) {
					this.applyDetail(this.fromInvoice, false)
					this.planForm = { ...this.blankPlanForm(), dueDays: dueDaysOf(this.fromInvoice) ?? '' }
				} else if (this.invoiceId) {
					const { data } = await api.invoice(this.invoiceId)
					if (data.status !== 'draft') {
						showError(this.t('Festgeschriebene Rechnungen lassen sich nicht mehr bearbeiten.'))
						this.$emit('close')
						return
					}
					this.applyDetail(data)
				} else {
					this.form = this.blankForm()
				}
			} catch (e) {
				this.error = errMsg(e, this.t('Die Rechnung konnte nicht geladen werden.'))
			} finally {
				this.loading = false
				this.$nextTick(() => { this.dirty = false })
			}
		},

		applyDetail(data, adopt = true) {
			if (adopt) {
				this.currentId = data.id
				this.checklist = data.checklist || []
			}
			this.leistungMode = data.periodStart ? 'period' : 'date'
			this.form = {
				debtorId: data.debtorId,
				issueDate: data.issueDate || '',
				deliveryDate: data.deliveryDate || '',
				periodStart: data.periodStart || '',
				periodEnd: data.periodEnd || '',
				dueDate: data.dueDate || '',
				buyerReference: data.buyerReference || '',
				orderReference: data.orderReference || '',
				taxMode: data.taxMode,
				exemptionText: data.exemptionText || '',
				noteHead: data.noteHead || '',
				noteFoot: data.noteFoot || '',
				paymentTerms: data.paymentTerms || '',
				lines: data.lines.length ? data.lines.map(lineFromServer) : [emptyLine()],
			}
			this.moreOpen = Boolean(this.form.buyerReference || this.form.orderReference)
		},

		payload() {
			const f = this.form
			return {
				debtorId: f.debtorId,
				issueDate: f.issueDate || null,
				deliveryDate: this.leistungMode === 'date' ? (f.deliveryDate || null) : null,
				periodStart: this.leistungMode === 'period' ? (f.periodStart || null) : null,
				periodEnd: this.leistungMode === 'period' ? (f.periodEnd || null) : null,
				dueDate: f.dueDate || null,
				buyerReference: f.buyerReference,
				orderReference: f.orderReference,
				taxMode: f.taxMode,
				exemptionText: f.exemptionText,
				noteHead: f.noteHead,
				noteFoot: f.noteFoot,
				paymentTerms: f.paymentTerms,
				lines: linesToPayload(f.lines, this.regular),
			}
		},

		scheduleAutosave() {
			clearTimeout(this.autosaveTimer)
			if (this.isPlan || !this.hasContent) { return }
			this.autosaveTimer = setTimeout(() => this.save(true), AUTOSAVE_MS)
		},

		/** Speichert nacheinander: eine zweite Anfrage wartet auf die erste, damit nicht zwei Entwürfe entstehen. */
		save(silent = false) {
			clearTimeout(this.autosaveTimer)
			this.saveQueue = this.saveQueue.then(() => this.doSave(silent))
			return this.saveQueue
		},

		async doSave(silent) {
			if (!this.hasContent && !this.currentId) { return true }
			this.saving = true
			this.error = ''
			const snapshot = JSON.stringify(this.form)
			try {
				const body = this.payload()
				const { data } = this.currentId ? await api.updateInvoice(this.currentId, body) : await api.createInvoice(body)
				this.currentId = data.id
				this.checklist = data.checklist || []
				if (JSON.stringify(this.form) === snapshot) { this.dirty = false }
				this.savedAt = new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })
				this.$emit('changed')
				return true
			} catch (e) {
				this.error = errMsg(e, this.t('Der Entwurf konnte nicht gespeichert werden.'))
				if (!silent) { showError(this.error) }
				return false
			} finally {
				this.saving = false
			}
		},

		async saveNow() {
			if (await this.save(false)) { showSuccess(this.t('Entwurf gespeichert.')) }
		},

		async preview() {
			if (!(await this.save(false)) || !this.currentId) { return }
			window.open(api.invoicePdfUrl(this.currentId), '_blank', 'noopener')
		},

		async openIssue() {
			if (!(await this.save(false)) || !this.currentId) { return }
			this.issue = { open: true, busy: false, error: '' }
		},

		async confirmIssue(issueDate) {
			this.issue.busy = true
			this.issue.error = ''
			try {
				const { data } = await api.issueInvoice(this.currentId, issueDate)
				this.issue.open = false
				this.dirty = false
				showSuccess(this.t('Rechnung {number} festgeschrieben.', { number: data.number }))
				if (data.pdfError) { showError(this.t('Das PDF konnte noch nicht erzeugt werden: {message}', { message: data.pdfError })) }
				this.$emit('issued', data)
			} catch (e) {
				const problems = e?.response?.data?.problems
				this.issue.error = problems && problems.length
					? this.t('Es fehlen noch Angaben: {list}', { list: problems.join(', ') })
					: errMsg(e, this.t('Die Rechnung konnte nicht festgeschrieben werden.'))
			} finally {
				this.issue.busy = false
			}
		},

		async discard() {
			this.discardOpen = false
			try {
				if (this.currentId) { await api.deleteInvoice(this.currentId) }
				this.dirty = false
				this.$emit('changed')
				this.$emit('close')
			} catch (e) {
				showError(errMsg(e, this.t('Der Entwurf konnte nicht gelöscht werden.')))
			}
		},

		async close() {
			if (this.isPlan) {
				// Ein Plan ist kein Entwurf: ungespeicherte Angaben gehen verloren, also nachfragen.
				if (this.dirty) { this.leaveOpen = true } else { this.$emit('close') }
				return
			}
			// Offene Änderungen vor dem Verlassen sichern, damit nichts verloren geht.
			if (this.dirty && this.hasContent) { await this.save(true) }
			this.$emit('close')
		},

		leaveNow() {
			this.leaveOpen = false
			this.dirty = false
			this.$emit('close')
		},

		/** Speichert den Plan (neu oder geändert); ein vorher begonnener Entwurf wird dabei überflüssig und gelöscht. */
		async savePlan() {
			const f = this.form
			const p = this.planForm
			const lines = linesToPayload(f.lines, this.regular)
			if (lines.length === 0) {
				this.error = this.t('Mindestens eine Position ist nötig.')
				return
			}
			const debtorName = this.debtor?.name || this.fromInvoice?.debtorName || ''
			const body = {
				name: p.name.trim() || defaultPlanName(debtorName, lines),
				debtorId: f.debtorId,
				intervalMonths: p.intervalMonths,
				startDate: p.startDate,
				endDate: p.endDate || null,
				periodMode: p.periodMode,
				dueDays: p.dueDays === '' || p.dueDays === null ? null : Number(p.dueDays),
				autoIssue: p.autoIssue,
				autoSend: p.autoIssue && p.autoSend,
				lines,
				noteHead: f.noteHead,
				noteFoot: f.noteFoot,
				paymentTerms: f.paymentTerms,
				buyerReference: f.buyerReference,
				orderReference: f.orderReference,
			}
			this.saving = true
			this.error = ''
			try {
				const { data } = this.plan ? await api.updateInvoicePlan(this.plan.id, body) : await api.createInvoicePlan(body)
				if (this.currentId) {
					try { await api.deleteInvoice(this.currentId) } catch { /* der Entwurf bleibt dann als Entwurf in der Liste */ }
					this.currentId = null
				}
				this.dirty = false
				showSuccess(this.t('Plan gespeichert.'))
				this.$emit('planSaved', data)
			} catch (e) {
				this.error = errMsg(e, this.t('Der Plan konnte nicht gespeichert werden.'))
			} finally {
				this.saving = false
			}
		},

		/** Die Vorlagen sind eine Annehmlichkeit: fehlen sie, schreibt man die Position eben von Hand. */
		async loadTemplates() {
			try {
				const { data } = await api.invoiceTemplates()
				this.templates = data
			} catch {
				this.templates = []
			}
		},

		/** Fügt die gewählte Vorlage als Kopie ein; eine leere letzte Zeile wird dafür verwendet. */
		addFromTemplate() {
			const tpl = this.templates.find((x) => x.id === Number(this.templatePick))
			this.templatePick = ''
			if (!tpl) { return }
			const line = lineFromTemplate(tpl)
			const last = this.form.lines[this.form.lines.length - 1]
			if (last && isEmptyLine(last)) {
				this.form.lines.splice(this.form.lines.length - 1, 1, line)
			} else {
				this.form.lines.push(line)
			}
		},

		addLine() {
			const last = this.form.lines[this.form.lines.length - 1]
			this.form.lines.push(emptyLine(last ? last.accountId : null, last ? last.taxKey : 'S1900'))
		},

		removeLine(i) {
			this.form.lines.splice(i, 1)
			if (this.form.lines.length === 0) { this.form.lines.push(emptyLine()) }
		},

		moveLine(i, delta) {
			const j = i + delta
			if (j < 0 || j >= this.form.lines.length) { return }
			const rows = this.form.lines
			;[rows[i], rows[j]] = [rows[j], rows[i]]
		},

		quantityInvalid(line) {
			return line.quantity !== '' && (quantityToE4(line.quantity) === null || quantityToE4(line.quantity) <= 0)
		},

		netText(line) {
			if (isEmptyLine(line)) { return '' }
			const net = editorLineNet(line)
			return net === null ? '–' : formatMoney(net / 100)
		},
	},
}
</script>

<style scoped>
.vbh-inv-editor { display: flex; flex-direction: column; min-height: 0; flex: 1 1 auto; }

.vbh-inv-editor-title { margin-inline-start: 8px; }

.vbh-inv-saved { font-size: 0.85em; opacity: 0.7; white-space: nowrap; }

.vbh-inv-body { container-type: inline-size; }

/* Zwei Spalten: links das Formular in der Reihenfolge, in der man es ausfüllt,
   rechts mitwandernd die Summe und was noch fehlt. Schmal rutscht alles untereinander,
   die Seitenleiste landet dabei direkt unter den Positionen. */
.vbh-inv-layout { display: grid; grid-template-columns: minmax(0, 1fr) 320px; column-gap: 20px; align-items: start; max-width: 1280px; }

.vbh-inv-layout > * { grid-column: 1; }

.vbh-inv-layout > .vbh-inv-side { grid-column: 2; grid-row: 1 / span 8; position: sticky; top: 8px; }

.vbh-inv-intro { margin: 0 0 4px; }

.vbh-inv-card { margin: 0 0 12px; }

h3 { margin: 0 0 10px; font-size: 1.05em; }

/* Umschalter wie ein Reiter: „Einmalig | Wiederkehrend“ */
.vbh-inv-seg { display: inline-flex; padding: 3px; gap: 2px; border-radius: 10px; background: var(--color-background-dark); }

.vbh-inv-seg label { display: inline-flex; align-items: center; padding: 6px 14px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.9em; }

.vbh-inv-seg label.on { background: var(--color-primary-element); color: var(--color-primary-element-text); }

.vbh-inv-seg label.disabled { opacity: 0.5; cursor: not-allowed; }

.vbh-inv-seg input { position: absolute; opacity: 0; width: 1px; height: 1px; }

.vbh-inv-seg label:focus-within { outline: 2px solid var(--color-main-text); outline-offset: 1px; }

.vbh-inv-seg--small label { padding: 4px 10px; font-size: 0.85em; }

.vbh-inv-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 14px; }

.vbh-inv-grid label, .vbh-inv-wide, .vbh-inv-l2 label, .vbh-inv-l3 label { display: flex; flex-direction: column; gap: 3px; font-size: 0.85em; font-weight: 600; }

.vbh-inv-grid input, .vbh-inv-grid select, .vbh-inv-wide input, .vbh-inv-wide textarea { width: 100%; box-sizing: border-box; margin: 0; font-weight: normal; }

.vbh-inv-span { grid-column: 1 / -1; }

.vbh-inv-wide { margin-top: 10px; }

.vbh-inv-legend { padding: 0; margin: 0 0 4px; font-size: 0.85em; font-weight: 600; }

.vbh-inv-delivery { margin: 12px 0 0; }

.vbh-inv-delivery-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }

.vbh-inv-delivery-row input[type="date"] { margin: 0; }

.vbh-inv-dash { opacity: 0.6; }

/* Positionen: je Position ein Block. Oben das Wesentliche, darunter Konto und Steuer. */
.vbh-inv-lines { display: flex; flex-direction: column; gap: 0; }

.vbh-inv-l1 { display: grid; grid-template-columns: minmax(0, 1fr) 76px 112px 124px 104px 96px; gap: 8px; align-items: center; }

.vbh-inv-l1--head { font-size: 0.78em; font-weight: 600; opacity: 0.7; padding-bottom: 4px; }

.vbh-inv-line { padding: 10px 0; border-top: 1px solid var(--color-border); }

.vbh-inv-l1 input, .vbh-inv-l1 select { width: 100%; min-width: 0; box-sizing: border-box; margin: 0; }

.vbh-inv-r { text-align: end; }

.vbh-inv-net { font-weight: 600; font-variant-numeric: tabular-nums; }

.vbh-inv-actions { display: inline-flex; justify-content: flex-end; }

.vbh-inv-l2 { display: flex; flex-wrap: wrap; align-items: end; gap: 6px 14px; margin-top: 6px; }

.vbh-inv-l2 label { flex: 0 1 260px; min-width: 150px; font-weight: 500; opacity: 0.9; }

.vbh-inv-l2 select { width: 100%; min-width: 0; margin: 0; box-sizing: border-box; }

.vbh-inv-l2 label:nth-of-type(2) { flex-basis: 150px; }

.vbh-inv-l3 { display: grid; grid-template-columns: minmax(0, 1fr) 110px; gap: 8px 14px; margin-top: 8px; }

.vbh-inv-l3 textarea, .vbh-inv-l3 input { width: 100%; box-sizing: border-box; margin: 0; }

.vbh-inv-invalid { border-color: var(--color-element-error) !important; }

.vbh-inv-linkbtn { background: none; border: 0; padding: 4px 0; margin: 0; color: var(--color-primary-element); cursor: pointer; font-size: 0.85em; }

.vbh-inv-linebar { margin-top: 6px; padding-top: 12px; border-top: 1px solid var(--color-border); display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }

.vbh-inv-tplpick { max-width: 260px; }

/* Summe */
.vbh-inv-totals { margin: 0; }

.vbh-inv-totals > div { display: flex; justify-content: space-between; gap: 16px; padding: 6px 0; }

.vbh-inv-totals dt, .vbh-inv-totals dd { margin: 0; }

.vbh-inv-totals dd { font-variant-numeric: tabular-nums; }

.vbh-inv-totals-grand { border-top: 1px solid var(--color-border-dark, var(--color-border)); margin-top: 4px; padding-top: 10px !important; font-size: 1.2em; font-weight: 700; }

.vbh-inv-taxline { margin: 6px 0 0; }

/* Checkliste */
.vbh-inv-checklist { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }

.vbh-inv-checklist li { display: flex; gap: 8px; align-items: flex-start; font-size: 0.92em; }

.vbh-inv-checklist li.ok { opacity: 0.65; }

.vbh-inv-checks h4 { margin: 14px 0 6px; }

.vbh-inv-hints li { color: var(--color-warning-text, inherit); }

.vbh-inv-checklist li.open { font-weight: 600; }

.vbh-inv-checklist .icon-vue { flex: 0 0 auto; }

.vbh-inv-discard { margin: 0 0 12px; }

/* Weitere Angaben */
.vbh-inv-more > summary { cursor: pointer; display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 12px; }

.vbh-inv-more > summary .vbh-hint { margin: 0; }

.vbh-inv-more-body { margin-top: 12px; display: flex; flex-direction: column; gap: 10px; }

.vbh-inv-more-body .vbh-inv-wide { margin-top: 0; }

/* Schmal: eine Spalte, Seitenleiste unter den Positionen, Positionen einspaltig */
@container (max-width: 1000px) {
	.vbh-inv-layout { grid-template-columns: minmax(0, 1fr); }

	.vbh-inv-layout > .vbh-inv-side { grid-column: 1; grid-row: auto; position: static; }
}

@container (max-width: 720px) {
	.vbh-inv-grid { grid-template-columns: minmax(0, 1fr); }

	.vbh-inv-l1--head { display: none; }

	.vbh-inv-l1 { grid-template-columns: repeat(3, minmax(0, 1fr)); }

	.vbh-inv-l1 .vbh-inv-name { grid-column: 1 / -1; }

	.vbh-inv-l1 .vbh-inv-net { grid-column: 1 / 3; text-align: start; }

	.vbh-inv-l1 .vbh-inv-actions { grid-column: 3; }

	.vbh-inv-l3 { grid-template-columns: minmax(0, 1fr); }
}
</style>
