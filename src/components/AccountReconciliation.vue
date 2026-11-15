<template>
	<div class="vbh-reconcile">
		<div class="vbh-reconcile-head">
			<h4>{{ t('Kontoabstimmung') }}</h4>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('Zurück zum Konto') }}
			</NcButton>
		</div>
		<p class="vbh-hint">
			{{ t('Laden Sie einen Kontoauszug der Bank hoch. Er wird nur verglichen: nichts wird importiert oder gespeichert.') }}
		</p>

		<div class="vbh-reconcile-form">
			<NcButton variant="secondary" @click="$refs.fileInput.click()">
				<template #icon>
					<NcIconSvgWrapper :path="mdiUpload" :size="20" />
				</template>
				{{ t('Datei wählen') }}
			</NcButton>
			<input
				ref="fileInput"
				type="file"
				accept=".csv,.xml,.sta,.txt,text/csv,text/xml,application/xml"
				hidden
				@change="onFileSelected">
			<span v-if="file" class="vbh-filename">{{ file.name }}</span>
			<label class="vbh-reconcile-field">
				<span>{{ t('Von') }}</span>
				<input v-model="from" type="date">
			</label>
			<label class="vbh-reconcile-field">
				<span>{{ t('Bis') }}</span>
				<input v-model="to" type="date">
			</label>
			<label class="vbh-reconcile-field">
				<span>{{ t('Datumstoleranz (Tage)') }}</span>
				<input
					v-model.number="tolerance"
					type="number"
					min="0"
					max="10"
					step="1">
			</label>
			<NcButton variant="primary" :disabled="!file || loading" @click="run">
				{{ t('Abgleichen') }}
			</NcButton>
		</div>
		<p class="vbh-hint">
			{{ t('Ohne Angabe gilt der Zeitraum des Auszugs. Die Datumstoleranz erlaubt, dass Bank und Buchung um einige Tage auseinanderliegen.') }}
		</p>

		<NcLoadingIcon v-if="loading" :name="t('Auszug wird abgeglichen …')" :size="32" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else-if="result">
			<p class="vbh-reconcile-summary">
				{{ t('{from} bis {to}: {rows} Zeilen im Auszug, {matched} abgeglichen.', { from: formatDate(result.period.from), to: formatDate(result.period.to), rows: result.counts.statementRows, matched: result.counts.matched }) }}
			</p>
			<NcNoteCard v-if="result.counts.otherAccountRows" type="info">
				{{ n('%n Zeile des Auszugs gehört zu einem anderen Konto und wurde nicht verglichen.', '%n Zeilen des Auszugs gehören zu einem anderen Konto und wurden nicht verglichen.', result.counts.otherAccountRows) }}
			</NcNoteCard>
			<NcNoteCard v-if="clean" type="success">
				{{ t('Konto und Auszug stimmen überein.') }}
			</NcNoteCard>

			<h4>{{ t('Saldenprüfung') }}</h4>
			<p v-if="!result.balance" class="vbh-empty">
				{{ t('Der Auszug enthält keine Salden. Die Saldenprüfung entfällt.') }}
			</p>
			<template v-else>
				<div class="vbh-tablecard">
					<table class="vbh-table vbh-reconcile-balance">
						<thead>
							<tr>
								<th />
								<th class="num">
									{{ t('Bank') }}
								</th>
								<th class="num">
									{{ t('App') }}
								</th>
								<th class="num">
									{{ t('Differenz') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td>{{ t('Anfang ({date})', { date: formatDate(result.period.from) }) }}</td>
								<td class="num">
									{{ money(result.balance.bankOpeningCents) }}
								</td>
								<td class="num">
									{{ money(result.balance.appOpeningCents) }}
								</td>
								<td class="num" :class="diffClass(result.balance.diffOpeningCents)">
									{{ money(result.balance.diffOpeningCents) }}
								</td>
							</tr>
							<tr>
								<td>{{ t('Ende ({date})', { date: formatDate(result.period.to) }) }}</td>
								<td class="num">
									{{ money(result.balance.bankClosingCents) }}
								</td>
								<td class="num">
									{{ money(result.balance.appClosingCents) }}
								</td>
								<td class="num" :class="diffClass(result.balance.diffClosingCents)">
									{{ money(result.balance.diffClosingCents) }}
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p class="vbh-hint">
					{{ t('„App“ ist der Kontostand laut Buchungen zuzüglich der noch nicht zugeordneten Bankumsätze dieses Kontos. Differenz = App − Bank.') }}
				</p>
				<div v-if="result.balance.diffClosingCents !== 0 || result.balance.diffOpeningCents !== 0" class="vbh-tablecard">
					<table class="vbh-table vbh-reconcile-breakdown">
						<caption>{{ t('So setzt sich die Differenz am Ende zusammen') }}</caption>
						<tbody>
							<tr v-for="row in breakdown" :key="row.key" :class="{ 'vbh-reconcile-total': row.key === 'diffClosing' }">
								<td>{{ breakdownLabels[row.key] }}</td>
								<td class="num">
									{{ money(row.cents) }}
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<NcNoteCard v-if="result.balance.gaps.length" type="warning">
					{{ t('Die Salden des Auszugs gehen nicht lückenlos auf – vermutlich fehlen Zeilen oder der Auszug wurde aus mehreren Zeiträumen zusammengesetzt:') }}
					<ul>
						<li v-for="gap in result.balance.gaps" :key="gap.date + gap.actualCents">
							{{ t('{date}: erwartet {expected}, im Auszug {actual}', { date: formatDate(gap.date), expected: money(gap.expectedCents), actual: money(gap.actualCents) }) }}
						</li>
					</ul>
				</NcNoteCard>
			</template>

			<h4>{{ t('Zu viel in der App') }}</h4>
			<p class="vbh-hint">
				{{ t('Bewegungen auf dem Konto, die der Auszug nicht kennt – etwa doppelt erfasste Buchungen.') }}
			</p>
			<p v-if="!result.surplus.length" class="vbh-empty">
				{{ t('Keine überzähligen Bewegungen.') }}
			</p>
			<div v-else class="vbh-tablecard">
				<table class="vbh-table vbh-reconcile-list" data-test="reconcile-surplus">
					<thead>
						<tr>
							<th class="nowrap">
								{{ t('Datum') }}
							</th>
							<th>{{ t('Text') }}</th>
							<th>{{ t('Art') }}</th>
							<th class="num">
								{{ t('Betrag') }}
							</th>
							<th />
						</tr>
					</thead>
					<tbody>
						<tr v-for="(row, index) in result.surplus" :key="'s' + index">
							<td class="nowrap">
								{{ formatDate(row.date) }}
							</td>
							<td>{{ row.text }}</td>
							<td>{{ kindLabels[row.kind] }}</td>
							<td class="num" :class="amountClass(row.amountCents)">
								{{ money(row.amountCents) }}
							</td>
							<td class="nowrap">
								<router-link :to="bookingLink(account.id, row)">
									{{ row.kind === 'unassigned' ? t('Zum Umsatz') : t('Zur Buchung') }}
								</router-link>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<h4>{{ t('Fehlt in der App') }}</h4>
			<p class="vbh-hint">
				{{ t('Zeilen des Auszugs, zu denen es weder eine Buchung noch einen Bankumsatz gibt.') }}
			</p>
			<p v-if="!result.missing.length" class="vbh-empty">
				{{ t('Im Auszug fehlt nichts.') }}
			</p>
			<div v-else class="vbh-tablecard">
				<table class="vbh-table vbh-reconcile-list" data-test="reconcile-missing">
					<thead>
						<tr>
							<th class="nowrap">
								{{ t('Datum') }}
							</th>
							<th>{{ t('Text') }}</th>
							<th>{{ t('Zahlungsbeteiligter') }}</th>
							<th class="num">
								{{ t('Betrag') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(row, index) in result.missing" :key="'m' + index">
							<td class="nowrap">
								{{ formatDate(row.date) }}
							</td>
							<td>{{ row.text }}</td>
							<td>{{ row.counterparty }}</td>
							<td class="num" :class="amountClass(row.amountCents)">
								{{ money(row.amountCents) }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<template v-if="result.shifted.length">
				<h4>{{ t('Zeitlich verschoben') }}</h4>
				<p class="vbh-hint">
					{{ t('Diese Zeilen des Auszugs haben ein Gegenstück in der App, das aber außerhalb des Zeitraums gebucht ist.') }}
				</p>
				<div class="vbh-tablecard">
					<table class="vbh-table vbh-reconcile-list" data-test="reconcile-shifted">
						<thead>
							<tr>
								<th class="nowrap">
									{{ t('Datum im Auszug') }}
								</th>
								<th>{{ t('Text') }}</th>
								<th class="nowrap">
									{{ t('Datum in der App') }}
								</th>
								<th>{{ t('Art') }}</th>
								<th class="num">
									{{ t('Betrag') }}
								</th>
								<th />
							</tr>
						</thead>
						<tbody>
							<tr v-for="(pair, index) in result.shifted" :key="'v' + index">
								<td class="nowrap">
									{{ formatDate(pair.statement.date) }}
								</td>
								<td>{{ pair.app.text || pair.statement.text }}</td>
								<td class="nowrap">
									{{ formatDate(pair.app.date) }}
								</td>
								<td>{{ kindLabels[pair.app.kind] }}</td>
								<td class="num" :class="amountClass(pair.statement.amountCents)">
									{{ money(pair.statement.amountCents) }}
								</td>
								<td class="nowrap">
									<router-link :to="bookingLink(account.id, pair.app)">
										{{ pair.app.kind === 'unassigned' ? t('Zum Umsatz') : t('Zur Buchung') }}
									</router-link>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</template>

			<p v-if="result.counts.skippedUnassigned" class="vbh-hint">
				{{ n('%n nicht zugeordneter Bankumsatz ließ sich keinem Geldkonto zuordnen und blieb unberücksichtigt.', '%n nicht zugeordnete Bankumsätze ließen sich keinem Geldkonto zuordnen und blieben unberücksichtigt.', result.counts.skippedUnassigned) }}
			</p>

			<div v-if="!clean" class="vbh-reconcile-export">
				<a
					:href="csvHref"
					:download="csvName"
					class="vbh-export-btn"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> {{ t('Als CSV herunterladen') }}</a>
			</div>
		</template>
	</div>
</template>

<script>
import { mdiDownload, mdiUpload } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import api from '../api.js'
import { amountClass, errMsg, formatDate, formatMoney } from '../lib/format.js'
import { bookingLink, breakdownRows, isClean, reconciliationCsv } from '../lib/reconciliation.js'

/**
 * Kontoabstimmung (Issue #159): Kontoauszug hochladen und mit den Bewegungen
 * des Geldkontos vergleichen. Reiner Bericht – zum Bereinigen führen Links in
 * die Buchungsliste bzw. nach „Zuzuordnen“. Die Datei bleibt im Browser, bis
 * die Ansicht verlassen wird, damit Zeitraum und Toleranz ohne erneute Auswahl
 * geändert werden können; der Server speichert nichts.
 */
export default {
	name: 'AccountReconciliation',

	components: { NcButton, NcIconSvgWrapper, NcLoadingIcon, NcNoteCard },

	props: {
		account: { type: Object, required: true },
	},

	emits: ['close'],

	data() {
		return {
			mdiDownload,
			mdiUpload,
			file: null,
			from: '',
			to: '',
			tolerance: 3,
			result: null,
			loading: false,
			error: '',
		}
	},

	computed: {
		clean() {
			return isClean(this.result)
		},

		breakdown() {
			return breakdownRows(this.result && this.result.balance)
		},

		kindLabels() {
			return {
				booking: this.t('Buchung ohne Bankumsatz'),
				assigned: this.t('Zugeordneter Bankumsatz'),
				unassigned: this.t('Nicht zugeordneter Bankumsatz'),
			}
		},

		breakdownLabels() {
			return {
				diffOpening: this.t('Differenz am Anfang'),
				surplus: this.t('Zu viel in der App'),
				missing: this.t('Fehlt in der App'),
				shifted: this.t('Zeitlich verschoben'),
				rest: this.t('Nicht erklärter Rest'),
				diffClosing: this.t('Differenz am Ende'),
			}
		},

		csvHref() {
			if (!this.result) { return '' }
			return 'data:text/csv;charset=utf-8,' + encodeURIComponent(reconciliationCsv(this.result, {
				surplus: this.t('Zu viel in der App'),
				missing: this.t('Fehlt in der App'),
				shifted: this.t('Zeitlich verschoben'),
				balance: this.t('Saldenprüfung'),
				date: this.t('Datum'),
				text: this.t('Text'),
				counterparty: this.t('Zahlungsbeteiligter'),
				amount: this.t('Betrag'),
				kind: this.t('Art'),
				appDate: this.t('Datum in der App'),
				kinds: this.kindLabels,
				breakdown: this.breakdownLabels,
			}))
		},

		csvName() {
			if (!this.result) { return '' }
			return 'kontoabstimmung-' + this.result.account.number + '-' + this.result.period.from + '-' + this.result.period.to + '.csv'
		},
	},

	watch: {
		// Anderes Konto gewählt: Auszug und Ergebnis gehören zum vorigen.
		'account.id': function() {
			this.file = null
			this.from = ''
			this.to = ''
			this.result = null
			this.error = ''
			if (this.$refs.fileInput) { this.$refs.fileInput.value = '' }
		},
	},

	methods: {
		amountClass,
		bookingLink,
		formatDate,

		money(cents) {
			return formatMoney(cents / 100)
		},

		diffClass(cents) {
			return cents === 0 ? '' : 'vbh-reconcile-diff'
		},

		onFileSelected(event) {
			this.file = event.target.files[0] || null
			// Neuer Auszug, neuer Zeitraum: sonst gälte noch der des vorigen.
			this.from = ''
			this.to = ''
			this.result = null
			this.error = ''
			if (this.file) { this.run() }
		},

		async run() {
			if (!this.file || this.loading) { return }
			this.loading = true
			this.error = ''
			try {
				const form = new FormData()
				form.append('file', this.file)
				if (this.from) { form.append('from', this.from) }
				if (this.to) { form.append('to', this.to) }
				form.append('tolerance', String(Number.isFinite(this.tolerance) ? this.tolerance : 3))
				const { data } = await api.reconcileAccount(this.account.id, form)
				this.result = data
				this.from = data.period.from
				this.to = data.period.to
				this.tolerance = data.tolerance
			} catch (e) {
				this.result = null
				this.error = errMsg(e, this.t('Die Kontoabstimmung ist fehlgeschlagen.'))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.vbh-reconcile { margin-top: 12px; }

.vbh-reconcile-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }

.vbh-reconcile h4 { margin: 18px 0 4px; font-size: 1.05em; font-weight: 700; }

.vbh-reconcile-head h4 { margin: 0; }

.vbh-reconcile-form { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; margin: 12px 0 6px; padding: 10px 14px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 12px); background-color: var(--color-background-hover); }

.vbh-reconcile-field { display: flex; flex-direction: column; gap: 2px; font-size: 0.85em; }

.vbh-reconcile-field input[type="number"] { width: 6em; }

.vbh-reconcile-summary { margin: 14px 0 8px; font-weight: 600; }

.vbh-reconcile-balance, .vbh-reconcile-breakdown { width: auto; min-width: min(100%, 420px); }

.vbh-reconcile-balance tbody tr > :first-child { white-space: nowrap; }

.vbh-reconcile-breakdown caption { padding: 8px 12px 4px; text-align: start; font-weight: 600; }

.vbh-reconcile-total td { border-top: 2px solid var(--color-border-dark, #ccc); font-weight: 700; }

.vbh-reconcile-diff { color: var(--color-error-text, #c00); font-weight: 700; }

/* Schmale Fenster: die Listen laufen in der Tabellenkarte quer, statt die Textspalte zu zerdrücken. */
.vbh-reconcile-list { min-width: 560px; }

.vbh-reconcile-export { margin: 16px 0; }
</style>
