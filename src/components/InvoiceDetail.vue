<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-inv-detail"
		size="large"
		:closeOnClickOutside="true"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner vbh-inv-detail">
			<NcLoadingIcon v-if="loading && !invoice" :size="32" :name="t('Wird geladen…')" />
			<p v-else-if="error && !invoice" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>
			<template v-else-if="invoice">
				<header class="vbh-inv-detail-head">
					<div>
						<h2 id="vbh-modal-title-inv-detail" class="vbh-modal-title">
							{{ invoice.kind === 'storno' ? t('Stornorechnung') : t('Rechnung') }} {{ invoice.number || t('(Entwurf)') }}
						</h2>
						<span :class="statusClass(invoice)">{{ displayStatusLabel(invoice) }}</span>
					</div>
					<div class="vbh-inv-detail-amount">
						<strong>{{ formatMoney(invoice.grossCents / 100) }}</strong>
						<small v-if="invoice.kind !== 'storno' && invoice.status !== 'draft' && invoice.status !== 'cancelled'">
							{{ invoice.remainingCents > 0 ? t('noch offen: {amount}', { amount: formatMoney(invoice.remainingCents / 100) }) : t('vollständig bezahlt') }}
						</small>
					</div>
				</header>

				<div class="vbh-inv-actionbar">
					<NcButton
						v-if="invoice.status !== 'draft'"
						variant="secondary"
						:href="pdfUrl"
						target="_blank">
						<template #icon>
							<NcIconSvgWrapper :path="mdiFilePdfBox" :size="20" />
						</template>
						{{ t('PDF öffnen') }}
					</NcButton>
					<NcButton v-if="invoice.status !== 'draft'" variant="tertiary" :href="pdfDownloadUrl">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDownload" :size="20" />
						</template>
						{{ t('Herunterladen') }}
					</NcButton>
					<NcButton v-if="canWrite && invoice.status === 'draft'" variant="primary" @click="$emit('edit', invoice.id)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPencil" :size="20" />
						</template>
						{{ t('Entwurf bearbeiten') }}
					</NcButton>
					<NcButton v-if="canPay" variant="primary" @click="pay.open = true">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCashPlus" :size="20" />
						</template>
						{{ t('Zahlung erfassen') }}
					</NcButton>
					<NcButton v-if="canSend" variant="secondary" @click="mail.open = true">
						<template #icon>
							<NcIconSvgWrapper :path="mdiEmailOutline" :size="20" />
						</template>
						{{ invoice.sentAt ? t('Erneut senden') : t('Per E-Mail senden') }}
					</NcButton>
					<NcButton v-if="canDun" :variant="dunning.due ? 'primary' : 'secondary'" @click="dun.open = true">
						<template #icon>
							<NcIconSvgWrapper :path="mdiBellRing" :size="20" />
						</template>
						{{ t('Mahnen …') }}
					</NcButton>
					<NcButton v-if="canWrite && invoice.status !== 'draft'" variant="tertiary" @click="duplicate">
						<template #icon>
							<NcIconSvgWrapper :path="mdiContentCopy" :size="20" />
						</template>
						{{ t('Duplizieren') }}
					</NcButton>
					<NcButton v-if="canPlan" variant="tertiary" @click="plan.open = true">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCalendarSync" :size="20" />
						</template>
						{{ t('Wiederkehrend …') }}
					</NcButton>
					<NcButton v-if="canCancel" variant="tertiary" @click="cancel.open = true">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCancel" :size="20" />
						</template>
						{{ t('Stornieren …') }}
					</NcButton>
				</div>

				<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
					{{ error }}
				</p>
				<p v-if="invoice.kind === 'storno'" class="vbh-hint vbh-hint--info">
					{{ t('Diese Stornorechnung hebt die Rechnung {number} vom {date} auf.', { number: invoice.precedingNumber, date: formatDate(invoice.precedingIssueDate) }) }}
					<a v-if="invoice.precedingInvoiceId" href="#" @click.prevent="$emit('open', invoice.precedingInvoiceId)">{{ t('Zur Rechnung') }}</a>
					<template v-if="invoice.refundDueCents > 0">
						<br>{{ t('Zurückzuzahlen sind {amount}. Ordnen Sie die Auszahlung in „Buchungen → Zuzuordnen“ dem Erlöskonto zu.', { amount: formatMoney(invoice.refundDueCents / 100) }) }}
					</template>
				</p>
				<p v-if="invoice.status === 'cancelled'" class="vbh-hint vbh-hint--warning">
					{{ tRaw('Storniert am {date}: {reason}', { date: formatDate(invoice.cancelledAt), reason: invoice.cancelReason }) }}
					<a v-if="invoice.creditInvoiceId" href="#" @click.prevent="$emit('open', invoice.creditInvoiceId)">{{ t('Zur Stornorechnung') }}</a>
				</p>

				<div class="vbh-inv-detail-cols">
					<section>
						<h3>{{ t('Empfänger') }}</h3>
						<address v-if="invoice.debtor" class="vbh-inv-address">
							<strong>{{ invoice.debtor.name }}</strong>
							<span v-if="invoice.debtor.name2">{{ invoice.debtor.name2 }}</span>
							<span v-if="invoice.debtor.street">{{ invoice.debtor.street }}</span>
							<span>{{ [invoice.debtor.postalCode, invoice.debtor.city].filter(Boolean).join(' ') }}</span>
							<span v-if="invoice.debtor.email">{{ invoice.debtor.email }}</span>
						</address>
						<p v-else class="vbh-empty">
							{{ t('Kein Empfänger gewählt.') }}
						</p>
					</section>
					<section>
						<h3>{{ t('Daten') }}</h3>
						<dl class="vbh-inv-dl">
							<dt>{{ t('Rechnungsdatum') }}</dt><dd>{{ formatDate(invoice.issueDate) || '–' }}</dd>
							<dt>{{ invoice.periodStart ? t('Leistungszeitraum') : t('Leistungsdatum') }}</dt>
							<dd>{{ invoice.periodStart ? formatDate(invoice.periodStart) + ' – ' + formatDate(invoice.periodEnd) : (formatDate(invoice.deliveryDate) || '–') }}</dd>
							<template v-if="invoice.kind !== 'storno'">
								<dt>{{ t('Zahlbar bis') }}</dt><dd>{{ formatDate(invoice.dueDate) || '–' }}</dd>
							</template>
							<template v-if="invoice.buyerReference">
								<dt>{{ t('Ihre Referenz') }}</dt><dd>{{ invoice.buyerReference }}</dd>
							</template>
							<template v-if="invoice.orderReference">
								<dt>{{ t('Bestellnummer') }}</dt><dd>{{ invoice.orderReference }}</dd>
							</template>
						</dl>
					</section>
				</div>

				<section>
					<h3>{{ t('Positionen') }}</h3>
					<div class="vbh-tablecard">
						<table class="vbh-table vbh-inv-linestable">
							<colgroup>
								<col style="width: 6%">
								<col :style="{ width: regular ? '32%' : '38%' }">
								<col style="width: 14%">
								<col style="width: 15%">
								<col style="width: 10%">
								<col v-if="regular" style="width: 8%">
								<col :style="{ width: regular ? '15%' : '17%' }">
							</colgroup>
							<thead>
								<tr>
									<th class="vbh-inv-c-pos">
										{{ t('Pos.') }}
									</th>
									<th>{{ t('Bezeichnung') }}</th>
									<th class="vbh-inv-r">
										{{ t('Menge') }}
									</th>
									<th class="vbh-inv-r">
										{{ t('Einzelpreis') }}
									</th>
									<th class="vbh-inv-r">
										{{ t('Rabatt') }}
									</th>
									<th v-if="regular" class="vbh-inv-r">
										{{ t('USt') }}
									</th>
									<th class="vbh-inv-r">
										{{ t('Netto') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="line in invoice.lines" :key="line.id">
									<td class="vbh-inv-c-pos">
										{{ line.position }}
									</td>
									<td>
										<strong>{{ line.name }}</strong>
										<small v-if="line.description" class="vbh-inv-desc">{{ line.description }}</small>
										<small v-if="accountLabel(line.accountId)" class="vbh-inv-desc">{{ accountLabel(line.accountId) }}</small>
									</td>
									<td class="vbh-inv-r">
										{{ formatQuantity(line.quantityE4) }} {{ unitShort(line.unitCode) }}
									</td>
									<td class="vbh-inv-r">
										{{ formatMoney(line.unitPriceCents / 100) }}
									</td>
									<td class="vbh-inv-r">
										{{ line.discountBp ? formatDiscount(line.discountBp) + ' %' : '' }}
									</td>
									<td v-if="regular" class="vbh-inv-r">
										{{ taxLabel(line) }}
									</td>
									<td class="vbh-inv-r">
										{{ formatMoney(line.lineNetCents / 100) }}
									</td>
								</tr>
							</tbody>
						</table>
					</div>
					<dl v-if="regular" class="vbh-inv-sums">
						<dt>{{ t('Summe netto') }}</dt><dd>{{ formatMoney(invoice.netCents / 100) }}</dd>
						<template v-for="g in taxGroups" :key="g.rateBp">
							<dt>{{ t('zzgl. {rate} USt', { rate: formatDiscount(g.rateBp) + ' %' }) }}</dt><dd>{{ formatMoney(g.taxCents / 100) }}</dd>
						</template>
						<dt class="vbh-inv-sums-total">
							{{ t('Rechnungsbetrag') }}
						</dt><dd class="vbh-inv-sums-total">
							{{ formatMoney(invoice.grossCents / 100) }}
						</dd>
					</dl>
					<p v-if="invoice.exemptionText" class="vbh-hint">
						{{ invoice.exemptionText }}
					</p>
				</section>

				<div class="vbh-inv-detail-cols">
					<section>
						<h3>{{ t('Zahlungen') }}</h3>
						<p v-if="!invoice.payments.length" class="vbh-empty">
							{{ t('Noch keine Zahlung gebucht.') }}
						</p>
						<ul v-else class="vbh-inv-list">
							<li v-for="p in invoice.payments" :key="p.id">
								<span>{{ formatDate(p.createdAt) }} · {{ formatMoney(p.amountCents / 100) }} <small>{{ p.bankTxId ? t('Bankumsatz') : t('Barzahlung') }}</small></span>
								<NcButton
									v-if="canWrite"
									variant="tertiary"
									:aria-label="t('Zahlung zurücknehmen')"
									:title="t('Zahlung zurücknehmen')"
									@click="revert(p)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiUndo" :size="18" />
									</template>
								</NcButton>
							</li>
						</ul>
					</section>
					<section v-if="showDunning">
						<h3>{{ t('Mahnungen') }}</h3>
						<p v-if="dunning.due" class="vbh-hint vbh-hint--warning" role="status">
							{{ t('Fällig: {stage}.', { stage: dunningLabel(dunning.next) }) }}
						</p>
						<p v-else-if="dunning.next" class="vbh-hint">
							{{ t('Nächste Stufe: {stage} ab {date}.', { stage: dunningLabel(dunning.next), date: formatDate(dunning.dueOn) }) }}
						</p>
						<p v-else-if="dunning.level >= 3" class="vbh-hint">
							{{ t('Die letzte Mahnstufe ist erreicht. Wie es weitergeht, entscheidet der Verein.') }}
						</p>
						<p v-if="!history.length" class="vbh-empty">
							{{ t('Noch nicht gemahnt.') }}
						</p>
						<ul v-else class="vbh-inv-list">
							<li v-for="d in history" :key="d.id">
								<span>{{ dunningLabel(d.level) }} · {{ formatDate(d.sentAt) }} <small>{{ d.auto ? t('automatisch') : '' }} {{ d.sentTo }}</small></span>
								<a
									v-if="d.documentId"
									:href="documentUrl({ id: d.documentId })"
									target="_blank"
									rel="noopener">{{ t('PDF') }}</a>
							</li>
						</ul>
					</section>
					<section>
						<h3>{{ t('Dokumente') }}</h3>
						<p v-if="!invoice.documents.length" class="vbh-empty">
							{{ t('Das PDF entsteht beim Festschreiben.') }}
						</p>
						<ul v-else class="vbh-inv-list">
							<li v-for="d in invoice.documents" :key="d.id">
								<a :href="documentUrl(d)" target="_blank" rel="noopener">{{ d.fileName }}</a>
								<small v-if="d.profile" class="vbh-inv-profile">{{ d.profile }}</small>
								<small :class="'vbh-inv-int vbh-inv-int--' + d.integrity" :title="integrityHint(d)">{{ integrityLabel(d) }}</small>
							</li>
						</ul>
						<div v-if="canWrite && eInvoiceChoices.length" class="vbh-inv-einvoice">
							<div class="vbh-form">
								<label class="vbh-grow">{{ t('E-Rechnung erzeugen') }}
									<select v-model="eFormat" :disabled="eBusy">
										<option v-for="f in eInvoiceChoices" :key="f" :value="f">
											{{ eFormatLabel(f) }}{{ f === suggestedFormat ? ' · ' + t('empfohlen') : '' }}
										</option>
									</select>
								</label>
								<NcButton variant="secondary" :disabled="eBusy || eMissing.length > 0" @click="generateEInvoice">
									{{ t('Erzeugen') }}
								</NcButton>
							</div>
							<div v-if="eMissing.length" class="vbh-hint vbh-hint--error" role="status">
								{{ t('Für dieses Format fehlen noch Angaben:') }}
								<ul>
									<li v-for="m in eMissing" :key="m">
										{{ m }}
									</li>
								</ul>
							</div>
							<div v-if="eError" class="vbh-hint vbh-hint--error" role="alert">
								{{ eError }}
								<ul v-if="eProblems.length">
									<li v-for="m in eProblems" :key="m">
										{{ m }}
									</li>
								</ul>
							</div>
							<p v-if="!eMissing.length && !eError" class="vbh-hint">
								{{ t('Wird unveränderlich abgelegt, wie das PDF. Das ZUGFeRD-PDF enthält die Rechnungsdaten als XML, XRechnung ist eine reine XML-Datei.') }}
							</p>
						</div>
					</section>
				</div>

				<section>
					<h3>{{ t('Verlauf') }}</h3>
					<ol class="vbh-inv-timeline">
						<li v-for="(e, i) in timeline" :key="i">
							<time>{{ formatDateTime(e.at) }}</time> {{ e.text }}
						</li>
					</ol>
				</section>
			</template>

			<InvoicePayDialog
				v-if="invoice"
				:show="pay.open"
				:invoice="invoice"
				:accounts="accounts"
				@close="pay.open = false"
				@update:show="pay.open = $event"
				@paid="onPaid" />
			<InvoiceDunningDialog
				v-if="invoice"
				:show="dun.open"
				:invoice="invoice"
				@close="dun.open = false"
				@update:show="dun.open = $event"
				@sent="onDunned" />
			<InvoicePlanDialog
				v-if="invoice"
				:show="plan.open"
				:invoice="invoice"
				@close="plan.open = false"
				@update:show="plan.open = $event"
				@saved="onPlanSaved" />
			<InvoiceMailDialog
				v-if="invoice"
				:show="mail.open"
				:invoice="invoice"
				@close="mail.open = false"
				@update:show="mail.open = $event"
				@sent="onSent" />
			<InvoiceCancelDialog
				v-if="invoice"
				:show="cancel.open"
				:invoice="invoice"
				@close="cancel.open = false"
				@update:show="cancel.open = $event"
				@cancelled="onCancelled" />
		</div>
	</NcModal>
</template>

<script>
import { mdiBellRing, mdiCalendarSync, mdiCancel, mdiCashPlus, mdiContentCopy, mdiDownload, mdiEmailOutline, mdiFilePdfBox, mdiPencil, mdiUndo } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import InvoiceCancelDialog from './InvoiceCancelDialog.vue'
import InvoiceDunningDialog from './InvoiceDunningDialog.vue'
import InvoiceMailDialog from './InvoiceMailDialog.vue'
import InvoicePayDialog from './InvoicePayDialog.vue'
import InvoicePlanDialog from './InvoicePlanDialog.vue'
import api from '../api.js'
import { errMsg, formatDate, formatDateTime, formatMoney } from '../lib/format.js'
import { displayStatusLabel, dunningLabel, formatDiscount, formatQuantity, missingEInvoiceFormats, statusClass, unitShort } from '../lib/invoices.js'

/**
 * Detailansicht einer Rechnung (Epic #168): Kopf, Positionen, Zahlungen,
 * Dokumente und Verlauf, dazu die Aktionen je nach Status und Rolle.
 */
export default {
	name: 'InvoiceDetail',
	components: { NcModal, NcButton, NcIconSvgWrapper, NcLoadingIcon, InvoicePayDialog, InvoiceMailDialog, InvoiceCancelDialog, InvoiceDunningDialog, InvoicePlanDialog },
	props: {
		show: { type: Boolean, default: false },
		invoiceId: { type: Number, default: null },
		canWrite: { type: Boolean, default: false },
		isAdmin: { type: Boolean, default: false },
		/** Alle Konten, für die Anzeige der Erlöskonten und die Geldkonten der Barzahlung. */
		accounts: { type: Array, default: () => [] },
	},

	emits: ['close', 'changed', 'edit', 'open', 'update:show'],

	setup() {
		return { mdiBellRing, mdiCalendarSync, mdiCancel, mdiCashPlus, mdiContentCopy, mdiDownload, mdiEmailOutline, mdiFilePdfBox, mdiPencil, mdiUndo }
	},

	data() {
		return {
			loading: false,
			error: '',
			invoice: null,
			pay: { open: false },
			mail: { open: false },
			cancel: { open: false },
			dun: { open: false },
			plan: { open: false },
			eFormat: '',
			eBusy: false,
			eError: '',
			eProblems: [],
		}
	},

	computed: {
		/** Formate, die sich noch erzeugen lassen. */
		eInvoiceChoices() {
			return this.invoice && this.invoice.status !== 'draft' ? missingEInvoiceFormats(this.invoice.documents) : []
		},

		suggestedFormat() { return this.invoice?.eInvoice?.suggested || '' },
		/** Was dem gewählten Format noch fehlt (Pflichtangaben von XRechnung und Anschrift). */
		eMissing() { return this.invoice?.eInvoice?.missing?.[this.eFormat] || [] },
		pdfUrl() { return this.invoice ? api.invoicePdfUrl(this.invoice.id) : '' },
		pdfDownloadUrl() { return this.invoice ? api.invoicePdfUrl(this.invoice.id, true) : '' },
		accountsById() { return Object.fromEntries(this.accounts.map((a) => [a.id, a])) },
		regular() { return this.invoice?.taxMode === 'regular' },
		taxGroups() { return (this.invoice?.taxBreakdown || []).filter((g) => g.category === 'S') },

		canPay() {
			const i = this.invoice
			return this.canWrite && !!i && i.kind !== 'storno' && ['issued', 'sent'].includes(i.status) && i.remainingCents > 0
		},

		canSend() {
			const i = this.invoice
			return this.isAdmin && !!i && i.status !== 'draft' && i.status !== 'cancelled'
		},

		/** Mahnstand der Rechnung (Stufe, nächste Stufe, ob sie fällig ist). */
		dunning() { return this.invoice?.dunning?.state || { level: 0, next: null, due: false, blocked: 'draft' } },
		history() { return this.invoice?.dunning?.history || [] },
		showDunning() { return !!this.invoice && this.invoice.status !== 'draft' && this.invoice.kind !== 'storno' && (this.history.length > 0 || this.dunning.blocked === null) },
		canDun() { return this.isAdmin && this.dunning.blocked === null && this.dunning.next !== null },
		canPlan() {
			const i = this.invoice
			return this.isAdmin && !!i && i.kind !== 'storno' && ['issued', 'sent', 'paid'].includes(i.status) && !!i.debtorId
		},

		canCancel() {
			const i = this.invoice
			return this.isAdmin && !!i && i.kind !== 'storno' && ['issued', 'sent', 'paid'].includes(i.status)
		},

		timeline() {
			const i = this.invoice
			if (!i) { return [] }
			const rows = [{ at: i.createdAt, text: this.tRaw('Entwurf angelegt von {user}', { user: i.createdBy }) }]
			if (i.issuedAt) { rows.push({ at: i.issuedAt, text: this.tRaw('Festgeschrieben als {number} von {user}', { number: i.number, user: i.issuedBy }) }) }
			if (i.sentAt) { rows.push({ at: i.sentAt, text: this.t('Per E-Mail versendet') }) }
			for (const d of this.history) {
				rows.push({ at: d.sentAt, text: this.tRaw('{stage} an {to} verschickt', { stage: dunningLabel(d.level), to: d.sentTo }) })
			}
			for (const p of i.payments || []) {
				rows.push({ at: p.createdAt, text: this.tRaw('Zahlung {amount} gebucht von {user}', { amount: formatMoney(p.amountCents / 100), user: p.createdBy }) })
			}
			if (i.cancelledAt) { rows.push({ at: i.cancelledAt, text: this.t('Storniert') }) }
			return rows.sort((a, b) => String(a.at).localeCompare(String(b.at)))
		},
	},

	watch: {
		show(open) {
			if (open) { this.load() }
		},

		invoiceId() {
			if (this.show) { this.load() }
		},

		invoice() {
			this.pickFormat()
			this.eError = ''
			this.eProblems = []
		},
	},

	mounted() {
		if (this.show) { this.load() }
	},

	methods: {
		formatDate,
		formatDateTime,
		formatDiscount,
		formatMoney,
		formatQuantity,
		displayStatusLabel,
		statusClass,
		unitShort,

		async load() {
			if (!this.invoiceId) { return }
			this.loading = true
			this.error = ''
			try {
				const { data } = await api.invoice(this.invoiceId)
				this.invoice = data
			} catch (e) {
				this.error = errMsg(e, this.t('Die Rechnung konnte nicht geladen werden.'))
			} finally {
				this.loading = false
			}
		},

		/** Steuer der Position für die Tabelle: „19 %", „7 %", „0 %" oder „steuerfrei". */
		taxLabel(line) {
			if (line.taxCategory === 'E') { return this.t('steuerfrei') }
			return formatDiscount(line.taxRateBp) + ' %'
		},

		accountLabel(id) {
			const a = this.accountsById[id]
			return a ? `${a.number} ${a.name}` : ''
		},

		eFormatLabel(format) {
			return {
				zugferd: this.t('ZUGFeRD-PDF (EN 16931)'),
				xrechnung_cii: this.t('XRechnung (CII-XML)'),
				xrechnung_ubl: this.t('XRechnung (UBL-XML)'),
			}[format] || format
		},

		/** Wählt das empfohlene Format, solange es sich noch erzeugen lässt. */
		pickFormat() {
			const open = this.eInvoiceChoices
			if (!open.includes(this.eFormat)) {
				this.eFormat = open.includes(this.suggestedFormat) ? this.suggestedFormat : (open[0] || '')
			}
		},

		async generateEInvoice() {
			this.eBusy = true
			this.eError = ''
			this.eProblems = []
			try {
				const { data } = await api.createInvoiceEInvoice(this.invoice.id, this.eFormat)
				this.invoice = data
				showSuccess(this.t('E-Rechnung erzeugt.'))
				this.$emit('changed')
			} catch (e) {
				this.eError = errMsg(e, this.t('Die E-Rechnung konnte nicht erzeugt werden.'))
				this.eProblems = e?.response?.data?.problems || []
			} finally {
				this.eBusy = false
			}
		},

		documentUrl(d) { return api.invoiceDocumentUrl(this.invoice.id, d.id) },

		integrityLabel(d) {
			return { ok: this.t('unverändert'), changed: this.t('verändert'), missing: this.t('Datei fehlt') }[d.integrity] || ''
		},

		integrityHint(d) {
			return { ok: this.t('Die Datei entspricht dem erzeugten Stand (SHA-256 stimmt).'), changed: this.t('Die Datei wurde nach dem Erzeugen verändert.'), missing: this.t('Die Datei liegt nicht mehr am Ablageort.') }[d.integrity] || ''
		},

		async duplicate() {
			try {
				const { data } = await api.duplicateInvoice(this.invoice.id)
				showSuccess(this.t('Als neuer Entwurf kopiert.'))
				this.$emit('changed')
				this.$emit('edit', data.id)
			} catch (e) {
				showError(errMsg(e, this.t('Die Rechnung konnte nicht dupliziert werden.')))
			}
		},

		async revert(payment) {
			try {
				const { data } = await api.revertInvoicePayment(this.invoice.id, payment.id)
				this.invoice = data
				showSuccess(this.t('Zahlung zurückgenommen.'))
				this.$emit('changed', { bookings: true })
			} catch (e) {
				showError(errMsg(e, this.t('Die Zahlung konnte nicht zurückgenommen werden.')))
			}
		},

		onDunned(data) {
			this.dun.open = false
			this.invoice = data
			showSuccess(this.t('Mahnung verschickt.'))
			this.$emit('changed')
		},

		onPlanSaved() {
			this.plan.open = false
			showSuccess(this.t('Plan gespeichert. Die wiederkehrenden Rechnungen finden Sie unter „Wiederkehrend“ in der Rechnungsliste.'))
		},

		dunningLabel,

		onPaid(data) {
			this.pay.open = false
			this.invoice = data
			showSuccess(this.t('Zahlung gebucht.'))
			this.$emit('changed', { bookings: true })
		},

		onSent(data) {
			this.mail.open = false
			this.invoice = data
			showSuccess(this.t('Rechnung versendet.'))
			this.$emit('changed')
		},

		onCancelled(data) {
			this.cancel.open = false
			this.invoice = data.invoice
			showSuccess(this.t('Rechnung storniert. Stornorechnung: {number}', { number: data.credit.number }))
			this.$emit('changed')
		},
	},
}
</script>

<style scoped>
.vbh-inv-detail { display: flex; flex-direction: column; gap: 14px; min-width: 0; }

.vbh-inv-detail h3 { margin: 0 0 6px; font-size: 0.95em; }

.vbh-inv-detail-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }

.vbh-inv-detail-amount { display: flex; flex-direction: column; align-items: flex-end; font-size: 1.4em; }

.vbh-inv-detail-amount small { font-size: 0.6em; opacity: 0.75; }

@media (max-width: 600px) {
	.vbh-inv-detail-amount { align-items: flex-start; }
}

.vbh-inv-actionbar { display: flex; gap: 6px; flex-wrap: wrap; }

.vbh-inv-detail-cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 28px; }

.vbh-inv-address { font-style: normal; display: flex; flex-direction: column; }

.vbh-inv-dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; margin: 0; }

.vbh-inv-dl dt { opacity: 0.7; margin: 0; text-align: start; }

.vbh-inv-dl dd { margin: 0; text-align: start; }

.vbh-inv-linestable td, .vbh-inv-linestable th { vertical-align: top; }

.vbh-inv-r { text-align: end; font-variant-numeric: tabular-nums; white-space: nowrap; }

.vbh-inv-c-pos { width: 48px; }

.vbh-inv-desc { display: block; opacity: 0.7; font-size: 0.85em; white-space: pre-line; }

.vbh-inv-sums { display: grid; grid-template-columns: 1fr max-content; justify-content: end; gap: 3px 28px; margin: 10px 0 0; }

.vbh-inv-sums dt { text-align: end; opacity: 0.8; }

.vbh-inv-sums dd { margin: 0; text-align: end; font-variant-numeric: tabular-nums; }

.vbh-inv-sums-total { font-weight: 700; opacity: 1 !important; }

.vbh-inv-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 2px; }

.vbh-inv-list li { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 2px 10px; }

.vbh-inv-list li > a { overflow-wrap: anywhere; min-width: 0; }

.vbh-inv-int { margin-inline-start: 8px; padding: 1px 8px; border-radius: 10px; background: var(--color-background-dark); }

.vbh-inv-int--ok { color: var(--color-success-text, inherit); }

.vbh-inv-int--changed, .vbh-inv-int--missing { background: var(--color-error); color: var(--color-error-text); }

.vbh-inv-timeline { margin: 0; padding-inline-start: 18px; display: flex; flex-direction: column; gap: 3px; }

.vbh-inv-timeline time { opacity: 0.65; margin-inline-end: 6px; font-variant-numeric: tabular-nums; }
@media (max-width: 800px) {
	.vbh-inv-detail-cols { grid-template-columns: minmax(0, 1fr); }
}
</style>
