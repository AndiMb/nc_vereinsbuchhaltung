<template>
	<section class="vbh-bank-detail" :class="`vbh-bank-detail--${detail.status}`" :aria-label="rowLabel">
		<!-- Eine Zeile: Nummer, Betrag und – klein und gedämpft – was die Bank dazu meldet. Der Zustand steht erst,
			wenn die Zeile beurteilt ist; „noch nicht beurteilt“ ist der Normalzustand und nur Rauschen. -->
		<header class="vbh-bank-detail-head">
			<strong v-if="multiple">{{ t('Zeile {n}', { n: index + 1 }) }}</strong>
			<span class="vbh-bank-detail-amount">{{ formatMoney(detail.amountCents / 100) }}</span>
			<DebitStatusTag v-if="detail.status !== DETAIL_OPEN" kind="bank-detail" :value="detail.status" />
			<span v-if="detail.endToEndId" class="vbh-bank-fact">{{ t('End-to-End-ID') }} <span class="vbh-bank-mono">{{ detail.endToEndId }}</span></span>
			<span v-if="detail.mandateReference" class="vbh-bank-fact">{{ t('Mandatsreferenz') }} <span class="vbh-bank-mono">{{ detail.mandateReference }}</span></span>
			<span v-if="detail.isReturn && detail.chargesCents" class="vbh-bank-fact">{{ t('Bankgebühr') }} {{ formatMoney(detail.chargesCents / 100) }}</span>
		</header>

		<p v-if="detail.detectionSource === 'text_heuristik'" class="vbh-hint">
			{{ t('Diese Zeile wurde aus dem Buchungstext erkannt, nicht aus strukturierten Feldern der Bank. Bitte besonders genau prüfen.') }}
		</p>

		<!-- ============ Rücklastschrift: Grund in Klartext, Folgen vor der Bestätigung ============ -->
		<div v-if="detail.return" class="vbh-bank-return">
			<p class="vbh-bank-line">
				<strong>{{ t('Grund:') }}</strong> {{ returnReasonLabel(detail.return.reasonClass) }}
			</p>
			<!-- Den Rückgabecode liefert der Server nur an Buchhalter/Verwalter (Schlüssel fehlt sonst ganz, Spec §3.6). -->
			<p v-if="detail.return.reasonCode !== undefined" class="vbh-bank-line vbh-bank-code">
				{{ t('Rückgabecode (nur für die Buchhaltung sichtbar):') }}
				<code>{{ detail.return.reasonCode || '–' }}</code>
				<!-- Freier Text der Bank: bewusst nicht in einer t()-Variable (die würde ihn HTML-escapen). -->
				<span v-if="detail.return.reasonText">{{ detail.return.reasonText }}</span>
			</p>
			<div v-if="showConsequences" class="vbh-hint vbh-hint--info vbh-bank-consequences">
				<p>{{ t('Mit dem Verbuchen dieser Rücklastschrift geschieht automatisch:') }}</p>
				<ul>
					<li v-for="line in consequences" :key="line">
						{{ line }}
					</li>
				</ul>
			</div>
		</div>

		<!-- ============ Urteil: zugeordnet ============ -->
		<div v-if="detail.status === 'zugeordnet' && detail.assignedItem && !changing" class="vbh-bank-assigned">
			<span class="vbh-bank-line">
				{{ t('Zugeordnet:') }}
				<strong>{{ detail.assignedItem.memberName }}</strong>
				<span v-if="detail.assignedItem.description"> · {{ detail.assignedItem.description }}</span>
				· {{ formatMoney(detail.assignedItem.amountCents / 100) }}
			</span>
			<NcButton
				v-if="canWrite"
				size="small"
				variant="tertiary"
				:disabled="busy"
				@click="changing = true">
				{{ t('Urteil ändern') }}
			</NcButton>
		</div>

		<!-- ============ Vorschläge: alle Kandidaten, keiner vorausgewählt ============ -->
		<template v-if="showCandidates">
			<p v-if="!detail.candidates.length" class="vbh-hint">
				{{ t('Kein Vorschlag gefunden: Zu dieser Zeile passt kein Einzugsposten.') }}
			</p>
			<template v-else>
				<p v-if="detail.candidates.length > 1" class="vbh-hint vbh-hint--warning">
					{{ t('Mehrere Posten passen. Es ist keiner vorausgewählt: Bitte wählen Sie den richtigen.') }}
				</p>
				<ul class="vbh-bank-candidates" :aria-label="t('Vorschläge')">
					<li
						v-for="candidate in detail.candidates"
						:key="candidate.debitItemId"
						class="vbh-bank-candidate"
						:class="{ 'vbh-bank-candidate--chosen': isChosen(candidate) }">
						<div class="vbh-bank-candidate-text">
							<div class="vbh-bank-candidate-main">
								<strong>{{ candidate.memberName }}</strong>
								<span v-if="candidate.description">{{ candidate.description }}</span>
								<span class="vbh-bank-candidate-amount">{{ formatMoney(candidate.amountCents / 100) }}</span>
								<span v-if="candidate.dueDate" class="vbh-hint">{{ t('Einzug am {datum}', { datum: formatDate(candidate.dueDate) }) }}</span>
								<span v-if="candidate.batchStatus === 'verworfen'" class="vbh-typetag">{{ t('aus verworfenem Lauf') }}</span>
							</div>
							<p class="vbh-bank-candidate-reason">
								<strong>{{ t('Begründung:') }}</strong> {{ stageReason(candidate.stage) }}<template v-if="stageIsWeak(candidate.stage)">
									– {{ t('schwächster Treffer, bitte genau prüfen') }}
								</template>
							</p>
						</div>
						<span v-if="isChosen(candidate)" class="vbh-typetag">{{ t('aktuell zugeordnet') }}</span>
						<span v-else-if="isTaken(candidate)" class="vbh-typetag">{{ t('bereits einer anderen Zeile zugeordnet') }}</span>
						<NcButton
							v-else-if="canWrite"
							size="small"
							:variant="detail.candidates.length === 1 ? 'primary' : 'secondary'"
							:aria-label="`${assignLabel}: ${candidate.memberName}`"
							:disabled="busy"
							@click="assign(candidate)">
							{{ assignLabel }}
						</NcButton>
					</li>
				</ul>
			</template>

			<div v-if="canWrite" class="vbh-bank-actions">
				<NcButton
					v-if="detail.candidates.length && detail.status !== 'abgelehnt'"
					size="small"
					variant="tertiary"
					:disabled="busy"
					@click="reject">
					{{ t('Ablehnen') }}
				</NcButton>
				<NcButton
					v-if="detail.status !== 'nicht_zuordenbar'"
					size="small"
					variant="tertiary"
					:disabled="busy"
					@click="unmatched">
					{{ t('Nicht zuordenbar') }}
				</NcButton>
				<NcButton
					v-if="changing"
					size="small"
					variant="tertiary"
					:disabled="busy"
					@click="changing = false">
					{{ t('Abbrechen') }}
				</NcButton>
			</div>
		</template>
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import DebitStatusTag from './DebitStatusTag.vue'
import { DETAIL_OPEN, returnConsequences, returnReasonLabel, stageIsWeak, stageReason } from '../lib/bankReconciliation.js'
import { formatDate, formatMoney } from '../lib/format.js'

/**
 * Eine Detail-Zeile eines Bankumsatzes im Bankabgleich (Issue #105, Spec §5
 * „Einzelurteil je Detail-Zeile“): was die Bank meldet (Betrag, End-to-End-ID,
 * Mandatsreferenz), bei einer Rückgabe der Grund in Klartext samt den
 * automatischen Folgen, und die Vorschläge – alle Kandidaten mit ihrer
 * Begründung nach Stufe (kein Konfidenz-Wert), keiner vorausgewählt. Das
 * Urteil ist eine eigene, bewusste Handlung: zuordnen bzw. bestätigen,
 * ablehnen oder „nicht zuordenbar“.
 *
 * Die Zeile entscheidet nichts selbst: sie meldet das Urteil über `assign`,
 * `reject` und `unmatched` an den Umsatz. Ein Urteil lässt sich ändern („Urteil
 * ändern“ zeigt die Vorschläge wieder), solange der Umsatz nicht gebucht ist.
 * Ohne `canWrite` (Revisor) ist die Zeile rein lesend.
 */
export default {
	name: 'BankDetailRow',
	components: { DebitStatusTag, NcButton },
	props: {
		detail: { type: Object, required: true },
		index: { type: Number, default: 0 },
		// Ob der Umsatz mehrere Zeilen hat (Sammler): nur dann bekommt eine Zeile eine Nummer.
		multiple: { type: Boolean, default: false },
		canWrite: { type: Boolean, default: false },
		// Ein Urteil über diese Zeile ist unterwegs
		busy: { type: Boolean, default: false },
		// Einzugsposten, die schon eine andere Zeile dieses Umsatzes hat (ein Posten gehört zu höchstens einer Zeile)
		takenItemIds: { type: Array, default: () => [] },
	},

	emits: ['assign', 'reject', 'unmatched'],

	data() {
		return { changing: false, DETAIL_OPEN }
	},

	computed: {
		rowLabel() {
			return this.multiple ? this.t('Zeile {n}', { n: this.index + 1 }) : this.t('Zeile des Bankumsatzes')
		},

		// Eine zugeordnete Zeile zeigt ihr Urteil; erst „Urteil ändern“ bringt die Vorschläge zurück.
		showCandidates() {
			return this.detail.status !== 'zugeordnet' || !this.detail.assignedItem || this.changing
		},

		// Bei genau einem Kandidaten bestätigt man einen Vorschlag, bei mehreren wählt man.
		assignLabel() {
			return this.detail.candidates.length === 1 ? this.t('Bestätigen') : this.t('Zuordnen')
		},

		// Die Folgen gelten für eine Rückgabe, die gebucht werden kann – nicht für eine abgelehnte Zeile.
		showConsequences() {
			return this.detail.status === 'offen' || this.detail.status === 'zugeordnet'
		},

		consequences() {
			return returnConsequences(this.detail.return)
		},
	},

	watch: {
		// Nach einem Urteil ist die Auswahl erledigt.
		'detail.status': {
			handler() { this.changing = false },
		},
	},

	methods: {
		formatDate,
		formatMoney,
		returnReasonLabel,
		stageIsWeak,
		stageReason,

		isChosen(candidate) {
			return this.detail.status === 'zugeordnet' && this.detail.debitItemId === candidate.debitItemId
		},

		isTaken(candidate) {
			return this.takenItemIds.includes(candidate.debitItemId)
		},

		assign(candidate) { this.$emit('assign', candidate) },
		reject() { this.$emit('reject') },
		unmatched() { this.$emit('unmatched') },
	},
}
</script>

<style scoped>
/*
 * Eine Bankzeile ist ein schmaler Streifen: oben links Betrag und Meldungen der Bank, oben rechts die Urteile
 * „Ablehnen“ und „Nicht zuordenbar“ (im DOM hinter den Vorschlägen, damit die Tab-Reihenfolge beim Vorschlag
 * beginnt), darunter der Vorschlag mit „Bestätigen“. Auf dem Handy bricht alles untereinander um.
 */
.vbh-bank-detail {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto;
	grid-template-areas: "head actions";
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 1) calc(var(--default-grid-baseline, 4px) * 3);
	padding: calc(var(--default-grid-baseline, 4px) * 2) calc(var(--default-grid-baseline, 4px) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-main-background);
}

.vbh-bank-detail > * {
	grid-column: 1 / -1;
}

.vbh-bank-detail > .vbh-bank-detail-head {
	grid-area: head;
}

.vbh-bank-detail > .vbh-bank-actions {
	grid-area: actions;
}

/* Der Rand ist die zweite Spur neben dem Zustandstext: zugeordnet = Erfolgsfarbe. */
.vbh-bank-detail--zugeordnet {
	border-color: var(--color-element-success);
}

.vbh-bank-detail-head {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 2px calc(var(--default-grid-baseline, 4px) * 3);
}

.vbh-bank-detail-amount {
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}

/* Was die Bank meldet: klein und gedämpft, in der Zeile des Betrags. */
.vbh-bank-fact {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.vbh-bank-mono {
	font-family: var(--font-family-monospace, monospace);
}

.vbh-bank-line {
	margin: 0;
	overflow-wrap: anywhere;
}

.vbh-bank-code code {
	font-weight: 700;
}

.vbh-bank-code span {
	margin-inline-start: 8px;
}

.vbh-bank-consequences p {
	margin: 0 0 4px;
	font-weight: 600;
}

/* Nextcloud nimmt Listen die Aufzählungszeichen: hier sind sie die Gliederung der Folgen. */
.vbh-bank-consequences ul {
	margin: 0;
	padding-inline-start: 20px;
	list-style: disc;
}

.vbh-bank-assigned {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 2px calc(var(--default-grid-baseline, 4px) * 3);
}

/* Die Vorschläge: keine Kästen im Kasten, nur Trennlinien zwischen mehreren. */
.vbh-bank-candidates {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding: 0;
	list-style: none;
}

.vbh-bank-candidate {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px calc(var(--default-grid-baseline, 4px) * 3);
	padding: calc(var(--default-grid-baseline, 4px) * 1) 0;
}

.vbh-bank-candidate + .vbh-bank-candidate {
	border-top: 1px solid var(--color-border);
}

.vbh-bank-candidate--chosen {
	padding-inline-start: calc(var(--default-grid-baseline, 4px) * 2);
	box-shadow: inset 3px 0 0 var(--color-element-success);
}

.vbh-bank-candidate-text {
	flex: 1 1 260px;
	min-width: 0;
}

.vbh-bank-candidate-main {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 2px 12px;
	overflow-wrap: anywhere;
}

.vbh-bank-candidate-amount {
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}

.vbh-bank-candidate-reason {
	margin: 0;
	font-size: 0.88em;
	color: var(--color-text-maxcontrast);
}

.vbh-bank-actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: 0 calc(var(--default-grid-baseline, 4px) * 1);
}

@media (max-width: 640px) {
	.vbh-bank-detail {
		grid-template-columns: minmax(0, 1fr);
		grid-template-areas: none;
	}

	.vbh-bank-detail > .vbh-bank-detail-head,
	.vbh-bank-detail > .vbh-bank-actions {
		grid-area: auto;
		grid-column: 1 / -1;
	}

	.vbh-bank-actions {
		justify-content: flex-start;
	}
}
</style>
