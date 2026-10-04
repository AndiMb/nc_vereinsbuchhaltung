<template>
	<div class="vbh-rundetail">
		<dl class="vbh-rd-facts">
			<div>
				<dt>{{ t('Einzugstermin') }}</dt>
				<dd>{{ formatDate(run.dueDate) }}</dd>
			</div>
			<div>
				<dt>{{ t('Status') }}</dt>
				<dd><DebitStatusTag kind="batch" :value="run.status" /></dd>
			</div>
			<div>
				<dt>{{ t('Posten') }}</dt>
				<dd>{{ run.itemCount }} · {{ formatMoney(run.sumCents / 100) }}</dd>
			</div>
			<div>
				<dt>{{ t('Freigegeben') }}</dt>
				<dd>{{ whoWhen(run.releasedByName || run.releasedBy, run.releasedAt) }}</dd>
			</div>
			<div v-if="run.submittedAt">
				<dt>{{ t('Eingereicht') }}</dt>
				<dd>{{ whoWhen(run.submittedByName || run.submittedBy, run.submittedAt) }}</dd>
			</div>
			<div v-if="run.discardedAt">
				<dt>{{ t('Verworfen') }}</dt>
				<dd>{{ whoWhen(run.discardedByName || run.discardedBy, run.discardedAt) }}</dd>
			</div>
			<div>
				<dt>{{ t('Kennung der Datei') }}</dt>
				<dd class="vbh-rd-msgid">
					{{ run.msgId }}
				</dd>
			</div>
		</dl>

		<!-- Die Begründung ist freier Text der Buchhaltung und steht deshalb nicht in einer t()-Variable
		     (die würde sie HTML-escapen, und Vue zeigte dann „&amp;“). -->
		<p v-if="run.status === 'verworfen'" class="vbh-hint vbh-hint--info">
			{{ t('Dieser Lauf wurde verworfen. Die Posten bleiben als Historie stehen, die Forderungen sind wieder frei und lassen sich in einem neuen Lauf einziehen.') }}
			<br>
			<strong>{{ t('Begründung:') }}</strong> {{ run.discardReason || '–' }}
		</p>
		<p v-else-if="run.status === 'freigegeben'" class="vbh-hint vbh-hint--info">
			{{ t('Freigegeben, aber noch nicht eingereicht: Die Datei ist erzeugt, bei der Bank liegt sie noch nicht.') }}
		</p>
		<p v-if="run.driftWarning" class="vbh-hint vbh-hint--warning" role="status">
			{{ run.driftWarning }}
		</p>

		<!-- Einhängepunkt für Ticket #103: hier kommen die Aktionen eines Laufs hinein – Datei herunterladen,
		     „Datei ist bei der Bank eingereicht“, Verwerfen, Termin nach hinten verschieben (Slot `actions`,
		     Props: run). Bis dahin bleibt der Slot leer und rendert nichts. -->
		<div v-if="$slots.actions" class="vbh-rd-actions">
			<slot name="actions" :run="run" />
		</div>

		<NcLoadingIcon v-if="loading && !run.items" :size="24" :name="t('Wird geladen…')" />
		<div v-else-if="error && !run.items" class="vbh-hint vbh-hint--warning">
			{{ error }}
			<NcButton size="small" @click="$emit('retry')">
				{{ t('Erneut versuchen') }}
			</NcButton>
		</div>

		<template v-else-if="run.items">
			<p v-if="run.items.length === 0" class="vbh-hint">
				{{ t('Dieser Lauf enthält keine Posten.') }}
			</p>
			<div v-else-if="isMobile" class="vbh-cardlist">
				<div v-for="item in run.items" :key="item.id" class="vbh-mcard">
					<div class="vbh-mcard-top">
						<span class="vbh-mcard-title">{{ item.memberDisplayName }}</span>
						<span class="vbh-mcard-amount">{{ formatMoney(item.amount) }}</span>
					</div>
					<div class="vbh-mcard-bottom">
						<span class="vbh-mcard-accounts">{{ item.remittanceInfo }}</span>
					</div>
					<div class="vbh-mcard-bottom">
						<span class="vbh-mcard-accounts">{{ item.iban || t('IBAN anonymisiert') }} · {{ item.mandateReference }}</span>
					</div>
					<div class="vbh-mcard-bottom">
						<DebitStatusTag kind="claim" :value="item.claimState" :settlementType="item.settlementType" />
						<span v-if="item.amendmentIndicator" class="vbh-typetag">{{ t('Kontowechsel') }}</span>
					</div>
				</div>
			</div>
			<div v-else class="vbh-tablecard">
				<table class="vbh-table">
					<thead>
						<tr>
							<th>{{ t('Mitglied') }}</th>
							<th>{{ t('Bezeichnung') }}</th>
							<th class="num">
								{{ t('Betrag') }}
							</th>
							<th>{{ t('IBAN (maskiert)') }}</th>
							<th>{{ t('Mandatsreferenz') }}</th>
							<th>{{ t('Forderung') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="item in run.items" :key="item.id">
							<td>{{ item.memberDisplayName }}</td>
							<td>{{ item.remittanceInfo }}</td>
							<td class="num nowrap">
								{{ formatMoney(item.amount) }}
							</td>
							<td class="nowrap">
								{{ item.iban || t('anonymisiert') }}
							</td>
							<td class="nowrap">
								{{ item.mandateReference }}
							</td>
							<td class="nowrap">
								<DebitStatusTag kind="claim" :value="item.claimState" :settlementType="item.settlementType" />
								<span v-if="item.amendmentIndicator" class="vbh-typetag" :title="t('Der Posten trägt die Kennzeichnung für einen Kontowechsel.')">{{ t('Kontowechsel') }}</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import DebitStatusTag from './DebitStatusTag.vue'
import { formatStamp } from '../lib/debitRun.js'
import { formatDate, formatMoney } from '../lib/format.js'

/**
 * Detail eines Laufs (Issue #102): Termin, Status, Summe, wer/wann sowie die
 * Posten mit maskierter IBAN und dem abgeleiteten Forderungszustand in
 * Klartext (offen, im Einzug, eingezogen, zurückgegeben, storniert). Der
 * Rücklastschrift-Code erscheint hier nie: Codes sind laut Spec §3.6 nur für
 * Buchhalter/Verwalter bestimmt, der Einzug-Unterreiter ist ab `revisor`
 * lesbar.
 *
 * `run` ist der Lauf aus der Liste; sobald `run.items` da ist (Antwort von
 * GET /debit-batches/{id}), erscheinen die Posten. Rein darstellend – die
 * Aktionen (Ticket #103) hängen über den Slot `actions` ein.
 */
export default {
	name: 'DebitRunDetail',
	components: { DebitStatusTag, NcButton, NcLoadingIcon },
	props: {
		run: { type: Object, required: true },
		loading: { type: Boolean, default: false },
		error: { type: String, default: null },
		isMobile: { type: Boolean, default: false },
	},

	emits: ['retry'],

	methods: {
		formatDate,
		formatMoney,

		whoWhen(who, when) {
			return [who, formatStamp(when)].filter(Boolean).join(', ')
		},
	},
}
</script>

<style scoped>
.vbh-rundetail {
	padding: 4px 0;
}

.vbh-rd-facts {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
	gap: 8px 24px;
	margin: 0 0 8px;
}

.vbh-rd-facts > div {
	display: flex;
	flex-direction: column;
}

/* Nextcloud gibt dt/dd Innenabstand und setzt dt rechtsbündig. */
.vbh-rd-facts dt {
	margin: 0;
	padding: 0;
	text-align: start;
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
}

.vbh-rd-facts dd {
	margin: 0;
	padding: 0;
	overflow-wrap: anywhere;
}

.vbh-rd-msgid {
	font-family: var(--font-family-monospace, monospace);
	font-size: 0.85em;
}

.vbh-rd-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 8px 0;
}
</style>
