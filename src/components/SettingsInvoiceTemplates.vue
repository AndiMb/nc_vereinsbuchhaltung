<template>
	<div class="vbh-card">
		<h4>{{ t('Positionsvorlagen') }}</h4>
		<p class="vbh-hint">
			{{ t('Wiederkehrende Leistungen wie Hallenmiete oder Kursgebühr einmal anlegen. Im Rechnungseditor fügt „Aus Vorlage einfügen“ sie als Kopie ein; spätere Änderungen an der Vorlage berühren bestehende Rechnungen nicht.') }}
		</p>
		<NcLoadingIcon v-if="loading" :size="24" :name="t('Wird geladen…')" />
		<p v-else-if="!templates.length && !editing" class="vbh-empty">
			{{ t('Noch keine Vorlagen.') }}
		</p>
		<div v-else-if="templates.length" class="vbh-tablecard vbh-inv-tpltable">
			<table class="vbh-table vbh-stack">
				<thead>
					<tr>
						<th>{{ t('Bezeichnung') }}</th>
						<th class="vbh-inv-r">
							{{ t('Preis') }}
						</th>
						<th>{{ t('Einheit') }}</th>
						<th>{{ t('Erlöskonto') }}</th>
						<th>{{ t('Steuer') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="tpl in templates" :key="tpl.id">
						<td class="vbh-stack-title">
							<strong>{{ tpl.name }}</strong>
							<div v-if="tpl.description" class="vbh-hint">
								{{ tpl.description }}
							</div>
						</td>
						<td class="vbh-inv-r">
							{{ formatMoney(tpl.unitPriceCents / 100) }}
						</td>
						<td>{{ unitLabel(tpl.unitCode) }}</td>
						<td>{{ accountLabel(tpl.accountId) }}</td>
						<td>{{ taxText(tpl) }}</td>
						<td class="vbh-inv-r">
							<div class="vbh-inv-plan-actions">
								<NcButton variant="tertiary" :aria-label="t('Vorlage bearbeiten')" @click="edit(tpl)">
									{{ t('Bearbeiten') }}
								</NcButton>
								<NcButton variant="tertiary" :aria-label="t('Vorlage löschen')" @click="remove(tpl)">
									{{ t('Löschen') }}
								</NcButton>
							</div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div v-if="editing" class="vbh-inv-tplform">
			<h5>{{ editing.id ? t('Vorlage bearbeiten') : t('Neue Vorlage') }}</h5>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Bezeichnung') }}
					<input v-model="editing.name" maxlength="255" :placeholder="t('z. B. Hallenmiete')">
				</label>
				<label>{{ t('Einheit') }}
					<select v-model="editing.unitCode">
						<option v-for="u in UNIT_CODES" :key="u" :value="u">{{ unitLabel(u) }}</option>
					</select>
				</label>
				<label>{{ t('Einzelpreis (€)') }}
					<input
						v-model="editing.price"
						class="vbh-short"
						type="number"
						min="0"
						step="0.01"
						inputmode="decimal">
				</label>
			</div>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Beschreibung (optional)') }}
					<input v-model="editing.description" maxlength="2000">
				</label>
			</div>
			<div class="vbh-form">
				<label class="vbh-grow">{{ t('Erlöskonto') }}
					<select v-model="editing.accountId">
						<option :value="null">{{ t('– kein Konto –') }}</option>
						<option v-for="a in incomeAccounts" :key="a.id" :value="a.id">{{ a.number }} {{ a.name }}</option>
					</select>
				</label>
				<label>{{ t('Steuer (bei Regelbesteuerung)') }}
					<select v-model="editing.taxKey">
						<option v-for="k in taxKeys" :key="k" :value="k">{{ taxKeyLabel(k) }}</option>
					</select>
				</label>
			</div>
			<p v-if="error" class="vbh-hint vbh-hint--error" role="alert">
				{{ error }}
			</p>
			<div class="vbh-form">
				<NcButton variant="primary" :disabled="busy" @click="save">
					{{ t('Speichern') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="busy" @click="cancel">
					{{ t('Abbrechen') }}
				</NcButton>
			</div>
		</div>
		<div v-else class="vbh-form">
			<NcButton variant="secondary" @click="create">
				{{ t('Vorlage hinzufügen') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import api from '../api.js'
import { errMsg, formatMoney } from '../lib/format.js'
import { TAX_KEYS, taxKeyLabel, taxKeyOf, unitLabel } from '../lib/invoices.js'

const UNIT_CODES = ['C62', 'HUR', 'DAY', 'MON', 'ANN', 'LS', 'KMT', 'MTR', 'MTK', 'LTR', 'KGM', 'P1']

/** Positionsvorlagen für Rechnungen pflegen (Epic #168, Stufe 3). */
export default {
	name: 'SettingsInvoiceTemplates',
	components: { NcButton, NcLoadingIcon },
	props: {
		/** Alle Konten; zur Auswahl dienen die aktiven Einnahmenkonten. */
		accounts: { type: Array, default: () => [] },
	},

	data() {
		return { loading: true, busy: false, templates: [], editing: null, error: '' }
	},

	computed: {
		incomeAccounts() { return this.accounts.filter((a) => a.type === 'income' && a.active && !a.isBank) },
		taxKeys() { return Object.keys(TAX_KEYS) },
	},

	created() {
		this.UNIT_CODES = UNIT_CODES
	},

	async mounted() {
		await this.load()
	},

	methods: {
		formatMoney,
		unitLabel,
		taxKeyLabel,

		async load() {
			this.loading = true
			try {
				const { data } = await api.invoiceTemplates()
				this.templates = data
			} catch (e) {
				showError(errMsg(e, this.t('Die Vorlagen konnten nicht geladen werden.')))
			} finally {
				this.loading = false
			}
		},

		accountLabel(id) {
			const a = this.accounts.find((x) => x.id === id)
			return a ? `${a.number} ${a.name}` : '–'
		},

		taxText(tpl) { return taxKeyLabel(taxKeyOf(tpl.taxCategory, tpl.taxRateBp)) },

		create() {
			this.error = ''
			this.editing = { id: null, name: '', description: '', unitCode: 'C62', price: '', accountId: null, taxKey: 'S1900' }
		},

		edit(tpl) {
			this.error = ''
			this.editing = {
				id: tpl.id,
				name: tpl.name,
				description: tpl.description || '',
				unitCode: tpl.unitCode,
				price: tpl.unitPriceCents === 0 ? '' : tpl.unitPriceCents / 100,
				accountId: tpl.accountId,
				taxKey: taxKeyOf(tpl.taxCategory, tpl.taxRateBp),
			}
		},

		cancel() { this.editing = null },

		async save() {
			const e = this.editing
			const price = e.price === '' ? 0 : Math.round(Number(e.price) * 100)
			if (!Number.isFinite(price) || price < 0) {
				this.error = this.t('Bitte einen gültigen Preis angeben.')
				return
			}
			const tax = TAX_KEYS[e.taxKey]
			const body = { name: e.name, description: e.description, unitCode: e.unitCode, unitPriceCents: price, accountId: e.accountId, taxCategory: tax.category, taxRateBp: tax.rateBp }
			this.busy = true
			this.error = ''
			try {
				if (e.id) {
					await api.updateInvoiceTemplate(e.id, body)
				} else {
					await api.createInvoiceTemplate(body)
				}
				this.editing = null
				showSuccess(this.t('Vorlage gespeichert.'))
				await this.load()
			} catch (err) {
				this.error = errMsg(err, this.t('Die Vorlage konnte nicht gespeichert werden.'))
			} finally {
				this.busy = false
			}
		},

		async remove(tpl) {
			try {
				await api.deleteInvoiceTemplate(tpl.id)
				showSuccess(this.t('Vorlage gelöscht.'))
				await this.load()
			} catch (err) {
				showError(errMsg(err, this.t('Die Vorlage konnte nicht gelöscht werden.')))
			}
		},
	},
}
</script>

<style scoped>
.vbh-inv-r { text-align: end; white-space: nowrap; }

.vbh-inv-tplform { margin-top: 12px; }

.vbh-inv-tpltable th:nth-child(1) { width: 28%; }

@container vbhtable (min-width: 641px) {
	.vbh-inv-tpltable > .vbh-table { min-width: 780px; }
}

.vbh-inv-tpltable th:nth-child(2), .vbh-inv-tpltable th:nth-child(3), .vbh-inv-tpltable th:nth-child(5) { width: 12%; }

.vbh-inv-tpltable th:nth-child(4) { width: 18%; }

.vbh-inv-plan-actions { display: flex; flex-wrap: wrap; gap: 2px; justify-content: flex-end; }
</style>
