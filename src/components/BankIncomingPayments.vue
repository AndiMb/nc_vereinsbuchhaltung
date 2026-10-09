<template>
	<div class="vbh-bank-incoming">
		<NcEmptyContent
			v-if="!incoming.length"
			:name="t('Es gibt keine Gutschrift, die zu einer offenen Forderung passt.')"
			:description="t('Gutschriften mit Vorschlag erscheinen hier nach dem Kontoauszugs-Import.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiCashPlus" />
			</template>
		</NcEmptyContent>

		<article
			v-for="entry in incoming"
			:key="entry.bankTx.id"
			class="vbh-bank-in-tx"
			:aria-label="txLabel(entry.bankTx)">
			<header>
				<p class="vbh-bank-in-top">
					<span class="vbh-bank-in-amount">{{ formatMoney(entry.bankTx.amountCents / 100) }}</span>
					<span class="vbh-typetag">{{ t('Zahlungseingang') }}</span>
				</p>
				<p class="vbh-bank-in-meta">
					{{ t('Bankumsatz vom {datum}', { datum: formatDate(entry.bankTx.bookingDate) }) }}<template v-if="entry.bankTx.counterparty">
						· {{ entry.bankTx.counterparty }}
					</template>
				</p>
				<!-- Zahler und Verwendungszweck stammen aus dem Bankauszug: nie in einer t()-Variable (HTML-Escaping). -->
				<p v-if="entry.bankTx.purpose" class="vbh-bank-in-purpose" :title="entry.bankTx.purpose">
					{{ entry.bankTx.purpose }}
				</p>
			</header>

			<p v-if="entry.suggestions.length > 1" class="vbh-hint vbh-hint--warning">
				{{ t('Mehrere Forderungen passen. Es ist keine vorausgewählt: Bitte wählen Sie die richtige.') }}
			</p>
			<ul class="vbh-bank-in-suggestions" :aria-label="t('Vorschläge')">
				<li v-for="suggestion in entry.suggestions" :key="suggestion.openItemId" class="vbh-bank-in-suggestion">
					<div class="vbh-bank-in-text">
						<p class="vbh-bank-in-claim">
							<strong>{{ suggestion.memberName }}</strong>
							<span v-if="suggestion.description"> · {{ suggestion.description }}</span>
							· {{ formatMoney(suggestion.amountCents / 100) }}
							<span v-if="suggestion.dueDate" class="vbh-hint">· {{ t('fällig am {datum}', { datum: formatDate(suggestion.dueDate) }) }}</span>
						</p>
						<!-- Begründung in Klartext des Servers; sie nennt den Namen des Mitglieds und steht deshalb nicht in einer t()-Variable. -->
						<p class="vbh-bank-in-reason">
							<strong>{{ t('Begründung:') }}</strong> {{ suggestion.reason }}
						</p>
						<p v-if="suggestion.revenueAccount" class="vbh-hint">
							{{ t('Buchung: Bank an') }} {{ accountText(suggestion.revenueAccount) }}
						</p>
						<p v-else class="vbh-hint vbh-hint--warning">
							{{ t('Für diese Forderung ist kein Erlöskonto hinterlegt, und in den Einstellungen ist kein Standard-Erlöskonto für Beiträge gewählt. Bestätigen ist erst möglich, wenn ein Verwalter eines davon einstellt.') }}
						</p>
					</div>
					<div v-if="canWrite" class="vbh-bank-in-actions">
						<NcButton
							variant="primary"
							size="small"
							:disabled="busy || !suggestion.revenueAccount"
							:aria-label="`${t('Bestätigen und verbuchen')}: ${suggestion.memberName}`"
							@click="confirm(entry, suggestion)">
							{{ t('Bestätigen und verbuchen') }}
						</NcButton>
						<NcButton
							variant="secondary"
							size="small"
							:disabled="busy"
							:aria-label="`${t('Ablehnen')}: ${suggestion.memberName}`"
							@click="reject(entry, suggestion)">
							{{ t('Ablehnen') }}
						</NcButton>
					</div>
				</li>
			</ul>
		</article>
	</div>
</template>

<script>
import { mdiCashPlus } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcEmptyContent, NcIconSvgWrapper } from '@nextcloud/vue'
import { useBankReconciliation } from '../composables/useBankReconciliation.js'
import { useConfirm } from '../composables/useConfirm.js'
import { accountLabel, describeSettleError } from '../lib/bankReconciliation.js'
import { formatDate, formatMoney } from '../lib/format.js'

/**
 * Zahlungseingänge im Bankabgleich (Issue #105, Spec §2.2/§3.6 „Zuordnungs-
 * Vorschlag“): eine Gutschrift ohne SEPA-Bezug, deren Betrag auf eine offene
 * Forderung passt – „diese Gutschrift passt auf Forderung X“, mit Begründung
 * in Klartext. Bestätigen bucht den Umsatz auf das Erlöskonto der Forderung
 * und schließt sie als bezahlt ab; Ablehnen merkt sich das Paar, damit es nicht
 * wieder vorgeschlagen wird. Nichts davon geschieht von selbst.
 *
 * Beides fragt vorher nach (das Ablehnen ist nicht umkehrbar, das Bestätigen
 * bucht) und nennt dabei, was geschieht. Ohne `canWrite` (Revisor) lesend.
 */
export default {
	name: 'BankIncomingPayments',
	components: { NcButton, NcEmptyContent, NcIconSvgWrapper },
	props: {
		// GET /bank-reconciliation, `incoming`
		incoming: { type: Array, default: () => [] },
		canWrite: { type: Boolean, default: false },
	},

	emits: ['booked'],

	setup() {
		return { bank: useBankReconciliation(), askConfirm: useConfirm().askConfirm }
	},

	data() {
		return { busy: false, mdiCashPlus }
	},

	methods: {
		formatDate,
		formatMoney,

		accountText: accountLabel,

		txLabel(tx) {
			return `${this.t('Bankumsatz vom {datum}', { datum: formatDate(tx.bookingDate) })}, ${formatMoney(tx.amountCents / 100)}`
		},

		async confirm(entry, suggestion) {
			// Namen und Kontonamen stehen nicht in t()-Variablen (HTML-Escaping): sie hängen hinter dem übersetzten Text.
			const message = this.t('Die Gutschrift vom {datum} über {betrag} wird gebucht und die Forderung als bezahlt erledigt.', { datum: formatDate(entry.bankTx.bookingDate), betrag: formatMoney(entry.bankTx.amountCents / 100) })
				+ ' ' + this.t('Buchung: Bank an') + ' ' + accountLabel(suggestion.revenueAccount)
			if (!await this.askConfirm(this.t('Zahlungseingang verbuchen'), message, this.t('Verbuchen'), 'primary')) { return }
			if (await this.run(
				() => this.bank.confirmIncoming(entry.bankTx.id, suggestion.openItemId),
				this.t('Die Gutschrift ist der Forderung zugeordnet und gebucht.'),
				this.t('Der Zahlungseingang konnte nicht verbucht werden.'),
			)) {
				// Die Forderung ist bezahlt: Zeitstrahl und Läufe sollen es auch zeigen.
				this.$emit('booked')
			}
		},

		async reject(entry, suggestion) {
			const message = this.t('Die Gutschrift vom {datum} über {betrag} wird dieser Forderung nicht mehr vorgeschlagen. Sie können sie weiterhin unter Buchungen → Zuzuordnen von Hand buchen.', { datum: formatDate(entry.bankTx.bookingDate), betrag: formatMoney(entry.bankTx.amountCents / 100) })
			if (!await this.askConfirm(this.t('Vorschlag ablehnen'), message, this.t('Ablehnen'), 'error')) { return }
			await this.run(
				() => this.bank.rejectIncoming(entry.bankTx.id, suggestion.openItemId),
				this.t('Vorschlag abgelehnt.'),
				this.t('Der Vorschlag konnte nicht abgelehnt werden.'),
			)
		},

		/** @return {Promise<boolean>} ob die Aktion gelungen ist */
		async run(action, successText, failureText) {
			this.busy = true
			try {
				await action()
				showSuccess(successText)
				return true
			} catch (e) {
				showError(describeSettleError(e, failureText))
				return false
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-bank-in-tx {
	margin: 10px 0;
	padding: 12px 14px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-hover);
}

.vbh-bank-in-top {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px 12px;
	margin: 0;
}

.vbh-bank-in-amount {
	font-size: 1.15em;
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}

.vbh-bank-in-meta,
.vbh-bank-in-purpose {
	margin: 4px 0 0;
	overflow-wrap: anywhere;
}

/* Der Verwendungszweck ist höchstens zwei Zeilen hoch (eine Sammelgutschrift trägt hier eine lange Kette von Namen); der volle Text steht im Tooltip. */
.vbh-bank-in-purpose {
	display: -webkit-box;
	overflow: hidden;
	opacity: 0.85;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: 2;
	line-clamp: 2;
}

.vbh-bank-in-suggestions {
	display: flex;
	flex-direction: column;
	gap: 0;
	margin: 6px 0 0;
	padding: 0;
	list-style: none;
}

.vbh-bank-in-suggestion {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 14px;
	padding: 8px 0;
}

.vbh-bank-in-suggestion + .vbh-bank-in-suggestion {
	border-top: 1px solid var(--color-border);
}

.vbh-bank-in-text {
	flex: 1 1 280px;
	min-width: 0;
}

.vbh-bank-in-text p {
	margin: 2px 0;
	overflow-wrap: anywhere;
}

.vbh-bank-in-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
