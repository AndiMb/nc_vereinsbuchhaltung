<template>
	<div class="vbh-bank">
		<div class="vbh-bank-head">
			<!-- Der Reiter „Bankabgleich" darüber nennt den Bereich schon; die Überschrift bleibt für Screenreader. -->
			<h4 class="vbh-visually-hidden">
				{{ t('Bankabgleich') }}
			</h4>
			<div class="vbh-segmented" role="group" :aria-label="t('Darstellung des Bankabgleichs')">
				<button
					type="button"
					class="vbh-segmented-item"
					:class="{ active: view === 'sepa' }"
					:aria-pressed="view === 'sepa' ? 'true' : 'false'"
					@click="view = 'sepa'">
					{{ t('Einzüge und Rückgaben') }}<template v-if="sepaPending > 0">
						({{ sepaPending }})
					</template>
				</button>
				<button
					type="button"
					class="vbh-segmented-item"
					:class="{ active: view === 'incoming' }"
					:aria-pressed="view === 'incoming' ? 'true' : 'false'"
					@click="view = 'incoming'">
					{{ t('Zahlungseingänge') }}<template v-if="incoming.length > 0">
						({{ incoming.length }})
					</template>
				</button>
			</div>
			<span class="vbh-bank-spacer" />
			<NcButton
				class="vbh-bank-refresh"
				size="small"
				variant="tertiary"
				:aria-label="t('Bankabgleich aktualisieren')"
				:title="t('Bankabgleich aktualisieren')"
				:disabled="loading"
				@click="load">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="18" />
					<NcIconSvgWrapper v-else :path="mdiRefresh" :size="18" />
				</template>
			</NcButton>
		</div>
		<!-- Eine Zeile Erklärung, und nur dort, wo es etwas zu beurteilen gibt; der leere Zustand sagt sich selbst. -->
		<p v-if="loaded && (view === 'sepa' ? items.length : incoming.length)" class="vbh-hint">
			{{ t('Nichts wird automatisch gebucht – erst Ihr Urteil und das Verbuchen lösen eine Buchung aus.') }}
		</p>
		<p v-if="!canWrite" class="vbh-hint">
			{{ t('Sie sehen den Bankabgleich nur lesend. Urteile und Verbuchen sind ab der Rolle Buchhalter möglich.') }}
		</p>

		<NcLoadingIcon v-if="!loaded && loading" :size="32" :name="t('Wird geladen…')" />

		<div v-else-if="!loaded" class="vbh-hint vbh-hint--warning">
			{{ error || t('Der Bankabgleich konnte nicht geladen werden.') }}
			<NcButton size="small" @click="load">
				{{ t('Erneut versuchen') }}
			</NcButton>
		</div>

		<template v-else>
			<!-- Die Serverantwort steht nicht in einer t()-Variable (die würde sie HTML-escapen). -->
			<p v-if="error" class="vbh-hint vbh-hint--warning" role="status">
				{{ t('Die Ansicht konnte nicht aktualisiert werden:') }} {{ error }}
			</p>

			<!-- ============ EINZÜGE UND RÜCKGABEN ============ -->
			<template v-if="view === 'sepa'">
				<NcEmptyContent
					v-if="!items.length"
					:name="t('Es warten keine Bankumsätze mit SEPA-Bezug auf ein Urteil.')"
					:description="t('Einzugsgutschriften und Rücklastschriften erscheinen hier nach dem Kontoauszugs-Import (Buchungen → Import).')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiBankOutline" />
					</template>
				</NcEmptyContent>
				<BankTxSuggestions
					v-for="entry in items"
					:key="entry.bankTx.id"
					:entry="entry"
					:expanded="expandedId === entry.bankTx.id"
					:canWrite="canWrite"
					:busyDetailId="busyDetailId"
					:bulkBusy="bulkBusy"
					@toggle="toggle(entry.bankTx.id)"
					@decide="decide"
					@bulk="confirmUnambiguous"
					@settle="openSettle(entry)" />
			</template>

			<!-- ============ ZAHLUNGSEINGÄNGE ============ -->
			<BankIncomingPayments
				v-else
				:incoming="incoming"
				:canWrite="canWrite"
				@booked="refreshRuns" />
		</template>

		<BankTxSettleDialog
			v-if="canWrite"
			:show="settleTxId !== null"
			:bankTxId="settleTxId"
			:kind="settleKind"
			:saving="settling"
			:error="settleError"
			@close="closeSettle"
			@update:show="(open) => { if (!open) { closeSettle() } }"
			@confirm="confirmSettle" />
	</div>
</template>

<script>
import { mdiBankOutline, mdiRefresh } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon } from '@nextcloud/vue'
import { toRefs } from 'vue'
import BankIncomingPayments from './BankIncomingPayments.vue'
import BankTxSettleDialog from './BankTxSettleDialog.vue'
import BankTxSuggestions from './BankTxSuggestions.vue'
import { useBankReconciliation } from '../composables/useBankReconciliation.js'
import { useDebitRuns } from '../composables/useDebitRuns.js'
import { describeSettleError, pendingCount } from '../lib/bankReconciliation.js'
import { n, t } from '../lib/l10n.js'

/**
 * Segment „Bankabgleich“ im Einzug-Unterreiter (Issue #105, Spec §3.10/§5/§6):
 * „der Bankauszug ist die Wahrheit“ – die Bankumsätze mit SEPA-Bezug, die auf
 * ein Urteil warten, und die Zahlungseingänge mit Zuordnungsvorschlag. Je
 * Detail-Zeile entscheidet ein Mensch (zuordnen/bestätigen, ablehnen, „nicht
 * zuordenbar“); „Verbuchen“ öffnet die Vorschau der Buchung und bucht erst
 * nach der Bestätigung. Nichts bucht automatisch.
 *
 * Lesend ab `revisor`; Urteile, Verbuchen und Zahlungseingang erst ab
 * `buchhalter` – `canWrite` blendet die Knöpfe aus, das Backend prüft noch
 * einmal. Die Daten kommen aus GET /bank-reconciliation (useBankReconciliation).
 * Geladen wird beim Sichtbarwerden und bei jeder Rückkehr ins Fenster.
 */
export default {
	name: 'BankReconciliationSegment',
	components: { BankIncomingPayments, BankTxSettleDialog, BankTxSuggestions, NcButton, NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon },
	props: {
		canWrite: { type: Boolean, default: false },
		// Ob das Segment gerade angezeigt wird – geladen wird erst dann (und bei jeder Rückkehr frisch).
		active: { type: Boolean, default: false },
	},

	setup() {
		const bank = useBankReconciliation()
		return {
			...toRefs(bank.state),
			load: bank.load,
			bank,
			debitRuns: useDebitRuns(),
		}
	},

	data() {
		return {
			mdiBankOutline,
			mdiRefresh,
			view: 'sepa',
			// Die Ansicht wird einmal nach dem ersten Laden gewählt (die mit Arbeit), danach entscheiden die Knöpfe.
			viewChosen: false,
			expandedId: null,
			// Die Zeile, über die ein Urteil unterwegs ist
			busyDetailId: null,
			bulkBusy: false,
			// Verbuchen: der Umsatz, dessen Vorschau offen ist
			settleTxId: null,
			settleKind: 'einzug',
			settling: false,
			settleError: null,
		}
	},

	computed: {
		sepaPending() { return pendingCount(this.items) },
	},

	watch: {
		active: {
			immediate: true,
			handler(value) { if (value) { this.load() } },
		},

		// Beim ersten Stand: die Ansicht mit Arbeit zeigen, und einen einzelnen Umsatz gleich aufklappen.
		loaded(value) {
			if (!value || this.viewChosen) { return }
			this.viewChosen = true
			if (this.sepaPending === 0 && this.incoming.length > 0) { this.view = 'incoming' }
			if (this.items.length === 1) { this.expandedId = this.items[0].bankTx.id }
		},
	},

	mounted() {
		window.addEventListener('focus', this.onWindowFocus)
	},

	beforeUnmount() {
		window.removeEventListener('focus', this.onWindowFocus)
	},

	methods: {
		// Wer aus einem anderen Fenster zurückkommt, soll keinen veralteten Stand sehen (eine andere Person kann Urteile gefällt oder gebucht haben).
		// Nicht mitten in einem Urteil oder in der Vorschau: das Neuladen ersetzte die Objekte, an denen der Dialog hängt.
		onWindowFocus() {
			if (this.active && !document.hidden && this.busyDetailId === null && !this.bulkBusy && this.settleTxId === null) { this.load() }
		},

		toggle(id) {
			this.expandedId = this.expandedId === id ? null : id
		},

		/** Urteil über eine Zeile (zuordnen, ablehnen, nicht zuordenbar). Ein Fehler steht als Meldung, die Zeile bleibt wie sie war. */
		async decide({ detail, action, candidate }) {
			this.busyDetailId = detail.id
			try {
				await this.bank.decide(detail, action, candidate ?? null)
			} catch (e) {
				showError(describeSettleError(e, t('Das Urteil konnte nicht gespeichert werden.')))
			} finally {
				this.busyDetailId = null
			}
		},

		/** „Eindeutige Vorschläge bestätigen“: je Zeile ein eigenes Urteil, nacheinander; beim ersten Fehler Schluss. */
		async confirmUnambiguous(items) {
			this.bulkBusy = true
			let done = 0
			try {
				for (const { detail, candidate } of items) {
					await this.bank.decide(detail, 'assign', candidate)
					done++
				}
				showSuccess(n('%n Vorschlag bestätigt. Gebucht wurde noch nichts.', '%n Vorschläge bestätigt. Gebucht wurde noch nichts.', done))
			} catch (e) {
				showError(describeSettleError(e, t('Das Urteil konnte nicht gespeichert werden.')))
			} finally {
				this.bulkBusy = false
			}
		},

		openSettle(entry) {
			this.settleKind = entry.kind
			this.settleError = null
			this.settleTxId = entry.bankTx.id
		},

		closeSettle() {
			if (!this.settling) { this.settleTxId = null }
		},

		async confirmSettle() {
			this.settling = true
			this.settleError = null
			try {
				const result = await this.bank.settle(this.settleTxId)
				this.settleTxId = null
				showSuccess(this.settledText(result))
				this.refreshRuns()
			} catch (e) {
				// Der Dialog bleibt offen: dort steht der Grund (z. B. geschlossenes Geschäftsjahr) neben der Vorschau.
				this.settleError = describeSettleError(e, t('Der Bankumsatz konnte nicht verbucht werden.'))
			} finally {
				this.settling = false
			}
		},

		/** Verbuchen ändert den Zustand von Forderungen, die auch im Zeitstrahl und in den Läufen stehen: dort nicht den alten Stand zeigen. */
		refreshRuns() {
			this.debitRuns.reload()
		},

		settledText(result) {
			if (result.returned > 0) {
				return n('Rücklastschrift verbucht: %n Forderung ist wieder offen.', 'Rücklastschrift verbucht: %n Forderungen sind wieder offen.', result.returned)
			}
			return n('Verbucht: %n Forderung als bezahlt erledigt.', 'Verbucht: %n Forderungen als bezahlt erledigt.', result.settled)
		},
	},
}
</script>

<style scoped>
.vbh-bank-head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px 12px;
}

.vbh-bank-spacer {
	flex: 1 1 auto;
}

</style>
