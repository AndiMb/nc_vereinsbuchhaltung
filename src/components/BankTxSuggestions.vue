<template>
	<article class="vbh-bank-tx" :class="{ 'vbh-bank-tx--open': expanded }" :aria-label="txLabel">
		<header class="vbh-bank-tx-head">
			<div class="vbh-bank-tx-top">
				<span class="vbh-bank-tx-amount">{{ formatMoney(tx.amountCents / 100) }}</span>
				<span class="vbh-typetag">{{ kindLabel(entry.kind) }}</span>
				<DebitStatusTag kind="bank-tx" :value="summary.state" />
				<strong class="vbh-bank-tx-progress">{{ progressText(summary) }}</strong>
			</div>
			<!-- Datum und Zahler in einer Zeile, bei Platzmangel gekürzt; der volle Text steht im Tooltip. -->
			<p class="vbh-bank-tx-meta" :title="metaText">
				{{ t('Bankumsatz vom {datum}', { datum: formatDate(tx.bookingDate) }) }}<template v-if="tx.counterparty">
					· {{ tx.counterparty }}
				</template>
			</p>
			<!-- Verwendungszweck und Zahler stammen aus dem Bankauszug: nie in einer t()-Variable (HTML-Escaping).
				Eine Sammelgutschrift trägt hier eine lange Kette von Namen: zwei Zeilen genügen, aufgeklappt steht alles da. -->
			<p
				v-if="tx.purpose"
				class="vbh-bank-tx-purpose"
				:class="{ 'vbh-bank-tx-purpose--full': expanded }"
				:title="tx.purpose">
				{{ tx.purpose }}
			</p>
			<p v-if="summary.state === 'ohne_zuordnung'" class="vbh-hint">
				{{ t('Dieser Umsatz ist ohne Zuordnung beurteilt. Ändern Sie ein Urteil, oder buchen Sie ihn unter Buchungen → Zuzuordnen von Hand.') }}
			</p>
		</header>

		<NcButton
			variant="tertiary"
			size="small"
			class="vbh-bank-tx-toggle"
			:aria-expanded="expanded ? 'true' : 'false'"
			:aria-controls="`vbh-bank-tx-body-${tx.id}`"
			:aria-label="toggleLabel"
			@click="$emit('toggle')">
			{{ expanded ? t('Zeilen ausblenden') : t('Zeilen prüfen') }}
		</NcButton>

		<div v-if="expanded" :id="`vbh-bank-tx-body-${tx.id}`" class="vbh-bank-tx-body">
			<div v-if="canWrite && bulkCount > 0" class="vbh-bank-bulk">
				<NcButton
					variant="secondary"
					size="small"
					:disabled="bulkBusy || busyDetailId !== null"
					@click="$emit('bulk', bulkItems)">
					{{ n('Eindeutigen Vorschlag bestätigen (%n)', 'Eindeutige Vorschläge bestätigen (%n)', bulkCount) }}
				</NcButton>
				<span class="vbh-hint">{{ t('Nur Zeilen mit genau einem Treffer; gebucht wird dabei noch nichts.') }}</span>
			</div>

			<div class="vbh-bank-rows">
				<BankDetailRow
					v-for="(detail, index) in entry.details"
					:key="detail.id"
					:detail="detail"
					:index="index"
					:multiple="entry.details.length > 1"
					:canWrite="canWrite"
					:busy="bulkBusy || busyDetailId === detail.id"
					:takenItemIds="takenFor(detail)"
					@assign="(candidate) => $emit('decide', { detail, action: 'assign', candidate })"
					@reject="$emit('decide', { detail, action: 'reject' })"
					@unmatched="$emit('decide', { detail, action: 'unmatched' })" />
			</div>

			<footer v-if="canWrite" class="vbh-bank-tx-foot">
				<p v-if="hint" class="vbh-hint">
					{{ hint }}
				</p>
				<NcButton
					variant="primary"
					:disabled="summary.state !== 'bereit' || bulkBusy || busyDetailId !== null"
					@click="$emit('settle')">
					{{ t('Verbuchen…') }}
				</NcButton>
			</footer>
		</div>
	</article>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import BankDetailRow from './BankDetailRow.vue'
import DebitStatusTag from './DebitStatusTag.vue'
import { kindLabel, progressText, settleHint, summarize, takenItemIds, unambiguousOpenDetails } from '../lib/bankReconciliation.js'
import { formatDate, formatMoney } from '../lib/format.js'

/**
 * Ein Bankumsatz der Arbeitsliste im Bankabgleich (Issue #105, Spec §5
 * „Sammler: ein Bestätigungsvorgang je Bankumsatz“): Kopf mit Betrag, Art,
 * Zustand und Fortschritt („4 von 12 beurteilt“), aufklappbar auf seine
 * Detail-Zeilen mit je eigenem Urteil. „Verbuchen…“ ist erst bereit, wenn alle
 * Zeilen beurteilt sind und mindestens eine zugeordnet ist – bis dahin steht
 * neben dem Knopf, was noch fehlt.
 *
 * Der Umsatz entscheidet nichts selbst: Urteile, die Sammel-Bestätigung und
 * das Verbuchen meldet er an den Bankabgleich (`decide`, `bulk`, `settle`).
 * Fortschritt und Zustand rechnet er aus den Zeilen (summarize()), damit sie
 * sich nach jedem Urteil ohne Neuladen ändern.
 */
export default {
	name: 'BankTxSuggestions',
	components: { BankDetailRow, DebitStatusTag, NcButton },
	props: {
		// Ein Umsatz der Arbeitsliste (GET /bank-reconciliation, `items`)
		entry: { type: Object, required: true },
		expanded: { type: Boolean, default: false },
		canWrite: { type: Boolean, default: false },
		// Die Zeile, über die gerade ein Urteil unterwegs ist (höchstens eine zugleich)
		busyDetailId: { type: Number, default: null },
		// Die Sammel-Bestätigung läuft
		bulkBusy: { type: Boolean, default: false },
	},

	emits: ['toggle', 'decide', 'bulk', 'settle'],

	computed: {
		tx() { return this.entry.bankTx },
		summary() { return summarize(this.entry) },
		hint() { return settleHint(this.summary) },
		bulkItems() { return unambiguousOpenDetails(this.entry) },
		bulkCount() { return this.bulkItems.length },

		/** Datum und Zahler als Tooltip der gekürzten Zeile; der Zahler steht nicht in einer t()-Variable (HTML-Escaping). */
		metaText() {
			const date = this.t('Bankumsatz vom {datum}', { datum: formatDate(this.tx.bookingDate) })
			return this.tx.counterparty ? `${date} · ${this.tx.counterparty}` : date
		},

		/** Name der Gruppe für Bildschirmleser. Der Zahler steht nicht in einer t()-Variable (HTML-Escaping). */
		txLabel() {
			return `${this.t('Bankumsatz vom {datum}', { datum: formatDate(this.tx.bookingDate) })}, ${formatMoney(this.tx.amountCents / 100)}`
		},

		/** Zugänglicher Name des Aufklapp-Knopfs: ohne Datum und Betrag hießen alle Knöpfe der Liste gleich. */
		toggleLabel() {
			return `${this.expanded ? this.t('Zeilen ausblenden') : this.t('Zeilen prüfen')}: ${this.txLabel}`
		},
	},

	methods: {
		formatDate,
		formatMoney,
		kindLabel,
		progressText,

		takenFor(detail) { return takenItemIds(this.entry, detail) },
	},
}
</script>

<style scoped>
.vbh-bank-tx {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto;
	gap: 8px 12px;
	align-items: start;
	margin: 10px 0;
	padding: 10px 14px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-hover);
}

.vbh-bank-tx--open {
	border-color: var(--color-primary-element);
}

.vbh-bank-tx-head {
	min-width: 0;
}

.vbh-bank-tx-top {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 12px;
}

.vbh-bank-tx-amount {
	font-size: 1.15em;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}

.vbh-bank-tx-progress {
	font-size: 0.9em;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.vbh-bank-tx-meta,
.vbh-bank-tx-purpose {
	margin: 2px 0 0;
	overflow-wrap: anywhere;
}

/* Datum und Zahler: eine Zeile, gekürzt. */
.vbh-bank-tx-meta {
	overflow: hidden;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Der Verwendungszweck ist höchstens zwei Zeilen hoch; die ungekürzte Fassung zeigt der aufgeklappte Umsatz. */
.vbh-bank-tx-purpose {
	display: -webkit-box;
	overflow: hidden;
	font-size: 0.9em;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: 2;
	line-clamp: 2;
}

.vbh-bank-tx-purpose--full {
	display: block;
	-webkit-line-clamp: unset;
	line-clamp: unset;
}

.vbh-bank-tx-body {
	grid-column: 1 / -1;
}

.vbh-bank-bulk {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px 12px;
	margin-bottom: 8px;
}

.vbh-bank-rows {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.vbh-bank-tx-foot {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: flex-end;
	gap: 8px 16px;
	margin-top: 10px;
}

.vbh-bank-tx-foot .vbh-hint {
	flex: 1 1 260px;
	margin: 0;
}

/* Schmale Anzeige: der Aufklapp-Knopf rutscht unter den Kopf statt daneben zu quetschen. */
@media (max-width: 600px) {
	.vbh-bank-tx {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>
