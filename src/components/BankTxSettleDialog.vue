<template>
	<NcModal
		:show="show"
		labelId="vbh-modal-title-bank-settle"
		size="large"
		:closeOnClickOutside="!saving"
		:noClose="saving"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<h2 id="vbh-modal-title-bank-settle" class="vbh-modal-title">
				{{ title }}
			</h2>

			<NcLoadingIcon v-if="loading" :size="32" :name="t('Die Buchung wird berechnet…')" />

			<div v-else-if="!preview" class="vbh-hint vbh-hint--error" role="alert">
				{{ loadError }}
				<NcButton size="small" @click="load">
					{{ t('Erneut versuchen') }}
				</NcButton>
			</div>

			<template v-else>
				<p class="vbh-hint">
					{{ t('Bankumsatz vom {datum} über {betrag}.', { datum: formatDate(preview.bookingDate), betrag: formatMoney(preview.amountCents / 100) }) }}
				</p>

				<!-- Hindernisse zuerst: wer sie sieht, muss die Buchung gar nicht erst lesen. -->
				<p
					v-for="blocker in preview.blockers"
					:key="blocker.code"
					class="vbh-hint vbh-hint--error"
					role="alert">
					{{ blockerTextOf(blocker) }}
				</p>
				<p v-for="warning in warnings" :key="warning" class="vbh-hint vbh-hint--warning">
					{{ warning }}
				</p>

				<section v-if="rows.length" class="vbh-bank-preview" :aria-label="t('Buchungsvorschau')">
					<h3 class="vbh-modal-subtitle">
						{{ t('Buchungsvorschau') }}
					</h3>
					<p class="vbh-bank-bookingdate">
						<strong>{{ t('Buchungsdatum:') }}</strong> {{ formatDate(preview.bookingDate) }}
						<span class="vbh-hint">({{ t('das Datum des Bankumsatzes') }})</span>
					</p>
					<ul class="vbh-bank-bookingrows">
						<li v-for="(row, index) in rows" :key="index" class="vbh-bank-bookingrow">
							<span class="vbh-bank-side">{{ sideLabel(row.side) }}</span>
							<span class="vbh-bank-account">
								<!-- Kontoname: Nutzerdaten, nicht in einer t()-Variable -->
								{{ row.account }}
								<span v-if="lineRoleLabel(row.role)" class="vbh-typetag">{{ lineRoleLabel(row.role) }}</span>
								<span v-if="row.count" class="vbh-hint">· {{ n('%n Posten', '%n Posten', row.count) }}</span>
							</span>
							<span class="vbh-bank-amount">{{ formatMoney(row.amountCents / 100) }}</span>
						</li>
					</ul>
				</section>

				<!-- Gutschrift: was mit den Forderungen geschieht -->
				<p v-if="preview.direction === 'collection' && preview.rows.length" class="vbh-bank-effect">
					{{ n('%n Forderung wird als bezahlt erledigt und mit dieser Buchung verknüpft.', '%n Forderungen werden als bezahlt erledigt und mit dieser Buchung verknüpft.', preview.rows.length) }}
				</p>

				<!-- Rücklastschrift: je Posten Grund und automatische Folgen, vor der Bestätigung -->
				<section v-if="preview.direction === 'return' && preview.rows.length" :aria-label="t('Rücklastschrift-Posten')">
					<h3 class="vbh-modal-subtitle">
						{{ t('Das geschieht beim Verbuchen') }}
					</h3>
					<ul class="vbh-bank-returns">
						<li v-for="row in preview.rows" :key="row.debitItemId" class="vbh-bank-return">
							<p class="vbh-bank-returnhead">
								<strong>{{ row.memberName }}</strong>
								<span v-if="row.description"> · {{ row.description }}</span>
								· {{ formatMoney(row.amountCents / 100) }}
							</p>
							<p class="vbh-bank-returnreason">
								{{ returnReasonLabel(row.return.reasonClass) }}<template v-if="row.return.reasonCode !== undefined">
									<code>{{ row.return.reasonCode || '–' }}</code>
								</template>
							</p>
							<ul>
								<li v-for="line in consequencesOf(row.return)" :key="line">
									{{ line }}
								</li>
							</ul>
						</li>
					</ul>
				</section>
			</template>

			<!-- Die Serverantwort steht nicht in einer t()-Variable (die würde sie HTML-escapen). -->
			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>

			<div class="vbh-modal-actions">
				<NcButton variant="tertiary" :disabled="saving" @click="$emit('close')">
					{{ t('Abbrechen') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canConfirm" @click="$emit('confirm')">
					{{ saving ? t('Wird verbucht…') : t('Jetzt verbuchen') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcLoadingIcon, NcModal } from '@nextcloud/vue'
import { useBankReconciliation } from '../composables/useBankReconciliation.js'
import { blockerText, bookingRows, lineRoleLabel, returnConsequences, returnReasonLabel, sideLabel } from '../lib/bankReconciliation.js'
import { errMsg, formatDate, formatMoney } from '../lib/format.js'

/**
 * Verbuchen eines Bankumsatzes mit Vorschau der Buchung (Issue #105, Spec
 * §3.10): bei einer Sammelgutschrift die Erlöskonten im Haben gegenüber der
 * Bank im Soll, bei einer Rücklastschrift die zwei Gegenkonto-Zeilen (Erlös
 * zurück, Gebührenkonto); Buchungsdatum ist das Datum des Bankumsatzes. Bei
 * einer Rücklastschrift stehen vor der Bestätigung die automatischen Folgen
 * je Posten (Mandat gesperrt, Zahlungsaufforderung, Gebühren-Forderung).
 *
 * Die Vorschau rechnet der Server mit derselben Rechnung wie das Verbuchen
 * (BankReconciliationService::settlementPreview()); Hindernisse – eine
 * geschlossene Periode, eine nicht aufgehende Aufteilung, ein fehlendes Konto
 * – kommen als Daten und stehen VOR dem Klick im Dialog, der Knopf bleibt
 * dann aus. Das Verbuchen selbst führt der Aufrufer aus (`confirm`) und gibt
 * Fehler über `error` zurück.
 */
export default {
	name: 'BankTxSettleDialog',
	components: { NcButton, NcLoadingIcon, NcModal },
	props: {
		show: { type: Boolean, default: false },
		bankTxId: { type: Number, default: null },
		// Art des Umsatzes laut Liste – für die Überschrift, bis die Vorschau da ist
		kind: { type: String, default: 'collection' },
		saving: { type: Boolean, default: false },
		error: { type: String, default: null },
	},

	emits: ['close', 'confirm', 'update:show'],

	setup() {
		return { bank: useBankReconciliation() }
	},

	data() {
		return { loading: false, preview: null, loadError: null }
	},

	computed: {
		title() {
			const direction = this.preview?.direction ?? (this.kind === 'return' ? 'return' : 'collection')
			return direction === 'return' ? this.t('Rücklastschrift verbuchen') : this.t('Einzugsgutschrift verbuchen')
		},

		rows() { return bookingRows(this.preview) },

		canConfirm() {
			return !this.loading && !this.saving && !!this.preview && this.preview.blockers.length === 0
		},

		warnings() {
			return (this.preview?.warnings || []).map((warning) => this.warningText(warning))
		},
	},

	watch: {
		show(open) {
			if (open) { this.load() }
		},
	},

	methods: {
		formatDate,
		formatMoney,
		lineRoleLabel,
		returnReasonLabel,
		sideLabel,

		consequencesOf(info) { return returnConsequences(info) },

		blockerTextOf(blocker) { return blockerText(blocker, this.preview) },

		warningText(warning) {
			if (warning.code === 'already_returned') {
				return this.n('%n zugeordneter Posten ist schon als Rücklastschrift verbucht und wird übersprungen.', '%n zugeordnete Posten sind schon als Rücklastschrift verbucht und werden übersprungen.', warning.count)
			}
			return ''
		},

		async load() {
			if (this.bankTxId === null) { return }
			this.loading = true
			this.preview = null
			this.loadError = null
			try {
				this.preview = await this.bank.loadPreview(this.bankTxId)
			} catch (e) {
				this.loadError = errMsg(e, this.t('Die Vorschau der Buchung konnte nicht geladen werden.'))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-bank-bookingdate {
	margin: 4px 0 8px;
}

.vbh-bank-bookingrows {
	margin: 0;
	padding: 0;
	list-style: none;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	overflow: hidden;
}

/* Drei Spalten, die auf schmaler Anzeige umbrechen statt eine Tabelle seitlich scrollen zu lassen. */
.vbh-bank-bookingrow {
	display: grid;
	grid-template-columns: 4.5em minmax(0, 1fr) auto;
	gap: 4px 12px;
	align-items: baseline;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.vbh-bank-bookingrow:last-child {
	border-bottom: none;
}

.vbh-bank-bookingrow:nth-child(even) {
	background-color: var(--color-background-hover);
}

.vbh-bank-side {
	font-weight: 600;
}

.vbh-bank-account {
	overflow-wrap: anywhere;
}

.vbh-bank-amount {
	font-weight: 700;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.vbh-bank-effect {
	margin: 10px 0 0;
}

.vbh-bank-returns {
	display: flex;
	flex-direction: column;
	gap: 10px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.vbh-bank-return {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-hover);
}

.vbh-bank-return p {
	margin: 0 0 4px;
	overflow-wrap: anywhere;
}

.vbh-bank-return ul {
	margin: 0;
	padding-inline-start: 20px;
	list-style: disc;
}

.vbh-bank-returnreason code {
	margin-inline-start: 8px;
	font-weight: 700;
}
</style>
