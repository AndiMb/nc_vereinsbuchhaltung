<template>
	<div class="vbh">
		<header class="vbh-header">
			<h2>Vereinsbuchhaltung</h2>
			<nav class="vbh-tabs">
				<button v-for="tab in tabs"
					:key="tab.id"
					:class="{ active: activeTab === tab.id }"
					@click="activeTab = tab.id">
					{{ tab.label }}
				</button>
			</nav>
		</header>

		<main class="vbh-content">
			<!-- ============ IMPORT ============ -->
			<section v-show="activeTab === 'import'" class="vbh-section">
				<h3>Kontoumsätze importieren (CSV-CAMT)</h3>
				<p class="vbh-hint">
					Exportiere die Umsätze aus dem Onlinebanking im CSV-CAMT-Format und lade die Datei hier hoch.
					Es werden nur neue Buchungen übernommen – Dubletten werden automatisch erkannt.
				</p>

				<div class="vbh-card">
					<input ref="fileInput" type="file" accept=".csv,text/csv" @change="onFileSelected">
					<label class="vbh-check">
						<input v-model="applyRules" type="checkbox">
						Auto-Zuordnungsregeln anwenden
					</label>

					<div v-if="previewResult" class="vbh-preview">
						<p>
							<strong>{{ previewResult.new }}</strong> neue Buchungen,
							<strong>{{ previewResult.duplicate }}</strong> Dubletten
							(von {{ previewResult.total }} gesamt).
						</p>
						<table v-if="previewResult.sample.length" class="vbh-table">
							<thead>
								<tr><th>Datum</th><th>Empfänger/Zahler</th><th>Verwendungszweck</th><th class="num">Betrag</th></tr>
							</thead>
							<tbody>
								<tr v-for="(r, i) in previewResult.sample" :key="i">
									<td>{{ r.bookingDate }}</td>
									<td>{{ r.counterparty }}</td>
									<td class="vbh-purpose">{{ r.purpose }}</td>
									<td class="num" :class="r.amount < 0 ? 'neg' : 'pos'">{{ formatMoney(r.amount) }}</td>
								</tr>
							</tbody>
						</table>
						<button class="primary" :disabled="busy || previewResult.new === 0" @click="commit">
							{{ previewResult.new }} Buchungen importieren
						</button>
					</div>
				</div>

				<h4>Bisherige Importe</h4>
				<table v-if="imports.length" class="vbh-table">
					<thead><tr><th>Datum</th><th>Datei</th><th class="num">Neu</th><th class="num">Dubletten</th></tr></thead>
					<tbody>
						<tr v-for="imp in imports" :key="imp.id">
							<td>{{ formatDateTime(imp.createdAt) }}</td>
							<td>{{ imp.filename }}</td>
							<td class="num">{{ imp.rowsNew }}</td>
							<td class="num">{{ imp.rowsDuplicate }}</td>
						</tr>
					</tbody>
				</table>
				<p v-else class="vbh-empty">Noch keine Importe.</p>
			</section>

			<!-- ============ BUCHUNGEN ============ -->
			<section v-show="activeTab === 'transactions'" class="vbh-section">
				<h3>Bankbuchungen zuordnen</h3>
				<div class="vbh-filter">
					<label>
						Filter:
						<select v-model="txFilter" @change="loadTransactions">
							<option value="">Alle</option>
							<option value="unassigned">Nur offene</option>
							<option value="assigned">Nur zugeordnete</option>
						</select>
					</label>
				</div>
				<table v-if="transactions.length" class="vbh-table">
					<thead>
						<tr><th>Datum</th><th>Empfänger/Zahler</th><th>Verwendungszweck</th><th class="num">Betrag</th><th>Konto / Kategorie</th></tr>
					</thead>
					<tbody>
						<tr v-for="tx in transactions" :key="tx.id" :class="{ assigned: tx.status === 'assigned' }">
							<td>{{ tx.bookingDate }}</td>
							<td>{{ tx.counterparty }}</td>
							<td class="vbh-purpose">{{ tx.purpose }}</td>
							<td class="num" :class="tx.amount < 0 ? 'neg' : 'pos'">{{ formatMoney(tx.amount) }}</td>
							<td>
								<select :value="tx.contraAccountId || ''" @change="onAssign(tx, $event.target.value)">
									<option value="">– nicht zugeordnet –</option>
									<optgroup v-for="(group, cat) in accountsByCategory" :key="cat" :label="cat">
										<option v-for="acc in group" :key="acc.id" :value="acc.id">
											{{ acc.number }} {{ acc.name }}
										</option>
									</optgroup>
								</select>
							</td>
						</tr>
					</tbody>
				</table>
				<p v-else class="vbh-empty">Keine Buchungen. Importiere zuerst Kontoumsätze.</p>
			</section>

			<!-- ============ KONTENRAHMEN ============ -->
			<section v-show="activeTab === 'accounts'" class="vbh-section">
				<h3>Kontenrahmen</h3>
				<p v-if="accounts.length === 0" class="vbh-hint">
					Noch keine Konten angelegt.
					<button class="primary" @click="seedAccounts">Standard-Kontenrahmen anlegen</button>
				</p>

				<table v-if="accounts.length" class="vbh-table">
					<thead><tr><th>Nr.</th><th>Name</th><th>Typ</th><th>Kategorie</th><th>Bankkonto</th><th></th></tr></thead>
					<tbody>
						<tr v-for="acc in accounts" :key="acc.id">
							<td>{{ acc.number }}</td>
							<td>{{ acc.name }}</td>
							<td>{{ typeLabel(acc.type) }}</td>
							<td>{{ acc.category }}</td>
							<td>{{ acc.isBank ? '✓' : '' }}</td>
							<td><button class="vbh-link" @click="deleteAccount(acc)">Löschen</button></td>
						</tr>
					</tbody>
				</table>

				<details class="vbh-card">
					<summary>Neues Konto anlegen</summary>
					<div class="vbh-form">
						<input v-model="newAccount.number" placeholder="Nummer (z.B. 4000)">
						<input v-model="newAccount.name" placeholder="Bezeichnung">
						<select v-model="newAccount.type">
							<option value="income">Ertrag (Einnahme)</option>
							<option value="expense">Aufwand (Ausgabe)</option>
							<option value="asset">Aktiv (Vermögen)</option>
							<option value="liability">Passiv (Verbindlichkeit)</option>
							<option value="equity">Eigenkapital</option>
						</select>
						<input v-model="newAccount.category" placeholder="Kategorie">
						<label class="vbh-check"><input v-model="newAccount.isBank" type="checkbox"> Bankkonto</label>
						<button class="primary" @click="createAccount">Anlegen</button>
					</div>
				</details>
			</section>

			<!-- ============ AUSWERTUNG ============ -->
			<section v-show="activeTab === 'report'" class="vbh-section">
				<h3>Auswertung</h3>
				<div v-if="balances" class="vbh-totals">
					<div class="vbh-total pos"><span>Einnahmen</span><strong>{{ formatMoney(balances.totals.income) }}</strong></div>
					<div class="vbh-total neg"><span>Ausgaben</span><strong>{{ formatMoney(balances.totals.expense) }}</strong></div>
					<div class="vbh-total" :class="balances.totals.result >= 0 ? 'pos' : 'neg'">
						<span>Ergebnis</span><strong>{{ formatMoney(balances.totals.result) }}</strong>
					</div>
				</div>
				<table v-if="balances" class="vbh-table">
					<thead><tr><th>Nr.</th><th>Konto</th><th>Kategorie</th><th class="num">Soll</th><th class="num">Haben</th><th class="num">Saldo</th></tr></thead>
					<tbody>
						<tr v-for="row in balances.accounts" :key="row.accountId">
							<td>{{ row.number }}</td>
							<td>{{ row.name }}</td>
							<td>{{ row.category }}</td>
							<td class="num">{{ formatMoney(row.debit) }}</td>
							<td class="num">{{ formatMoney(row.credit) }}</td>
							<td class="num">{{ formatMoney(row.balance) }}</td>
						</tr>
					</tbody>
				</table>
			</section>
		</main>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from './api.js'

export default {
	name: 'App',
	data() {
		return {
			activeTab: 'import',
			tabs: [
				{ id: 'import', label: 'Import' },
				{ id: 'transactions', label: 'Buchungen' },
				{ id: 'accounts', label: 'Kontenrahmen' },
				{ id: 'report', label: 'Auswertung' },
			],
			busy: false,
			selectedFile: null,
			applyRules: true,
			previewResult: null,
			imports: [],
			transactions: [],
			txFilter: 'unassigned',
			accounts: [],
			balances: null,
			newAccount: { number: '', name: '', type: 'income', category: '', isBank: false },
		}
	},
	computed: {
		accountsByCategory() {
			const groups = {}
			for (const acc of this.accounts) {
				if (!acc.active) continue
				const cat = acc.category || 'Sonstige'
				if (!groups[cat]) groups[cat] = []
				groups[cat].push(acc)
			}
			return groups
		},
	},
	watch: {
		activeTab(tab) {
			if (tab === 'transactions') this.loadTransactions()
			if (tab === 'report') this.loadBalances()
			if (tab === 'accounts') this.loadAccounts()
		},
	},
	async mounted() {
		await this.loadAccounts()
		await this.loadImports()
	},
	methods: {
		formatMoney(v) {
			return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v || 0)
		},
		formatDateTime(s) {
			return s ? String(s).replace('T', ' ').slice(0, 16) : ''
		},
		typeLabel(t) {
			return { income: 'Ertrag', expense: 'Aufwand', asset: 'Aktiv', liability: 'Passiv', equity: 'Eigenkapital' }[t] || t
		},

		// --- Import ---
		onFileSelected(e) {
			this.selectedFile = e.target.files[0] || null
			this.previewResult = null
			if (this.selectedFile) this.preview()
		},
		async preview() {
			if (!this.selectedFile) return
			this.busy = true
			try {
				const fd = new FormData()
				fd.append('file', this.selectedFile)
				const { data } = await api.previewImport(fd)
				this.previewResult = data
			} catch (e) {
				showError(this.errMsg(e, 'Vorschau fehlgeschlagen'))
			} finally {
				this.busy = false
			}
		},
		async commit() {
			if (!this.selectedFile) return
			this.busy = true
			try {
				const fd = new FormData()
				fd.append('file', this.selectedFile)
				fd.append('applyRules', this.applyRules ? '1' : '0')
				const { data } = await api.commitImport(fd)
				showSuccess(`${data.new} Buchungen importiert (${data.autoAssigned} automatisch zugeordnet).`)
				this.previewResult = null
				this.selectedFile = null
				if (this.$refs.fileInput) this.$refs.fileInput.value = ''
				await this.loadImports()
			} catch (e) {
				showError(this.errMsg(e, 'Import fehlgeschlagen'))
			} finally {
				this.busy = false
			}
		},
		async loadImports() {
			try {
				const { data } = await api.listImports()
				this.imports = data
			} catch (e) { /* still */ }
		},

		// --- Buchungen ---
		async loadTransactions() {
			try {
				const { data } = await api.listTransactions(this.txFilter)
				this.transactions = data
			} catch (e) {
				showError(this.errMsg(e, 'Buchungen konnten nicht geladen werden'))
			}
		},
		async onAssign(tx, value) {
			try {
				if (value === '') {
					await api.unassignTransaction(tx.id)
				} else {
					await api.assignTransaction(tx.id, Number(value))
				}
				await this.loadTransactions()
			} catch (e) {
				showError(this.errMsg(e, 'Zuordnung fehlgeschlagen'))
			}
		},

		// --- Konten ---
		async loadAccounts() {
			try {
				const { data } = await api.listAccounts()
				this.accounts = data
			} catch (e) {
				showError(this.errMsg(e, 'Konten konnten nicht geladen werden'))
			}
		},
		async seedAccounts() {
			try {
				const { data } = await api.seedAccounts()
				this.accounts = data
				showSuccess('Standard-Kontenrahmen angelegt.')
			} catch (e) {
				showError(this.errMsg(e, 'Anlegen fehlgeschlagen'))
			}
		},
		async createAccount() {
			if (!this.newAccount.number || !this.newAccount.name) {
				showError('Nummer und Bezeichnung sind Pflicht.')
				return
			}
			try {
				await api.createAccount(this.newAccount)
				this.newAccount = { number: '', name: '', type: 'income', category: '', isBank: false }
				await this.loadAccounts()
				showSuccess('Konto angelegt.')
			} catch (e) {
				showError(this.errMsg(e, 'Konto konnte nicht angelegt werden'))
			}
		},
		async deleteAccount(acc) {
			if (!confirm(`Konto "${acc.number} ${acc.name}" löschen?`)) return
			try {
				await api.deleteAccount(acc.id)
				await this.loadAccounts()
			} catch (e) {
				showError(this.errMsg(e, 'Löschen fehlgeschlagen'))
			}
		},

		// --- Auswertung ---
		async loadBalances() {
			try {
				const { data } = await api.balances()
				this.balances = data
			} catch (e) {
				showError(this.errMsg(e, 'Auswertung konnte nicht geladen werden'))
			}
		},

		errMsg(e, fallback) {
			return e?.response?.data?.message || fallback
		},
	},
}
</script>

<style scoped>
.vbh { padding: 16px 24px; max-width: 1100px; }
.vbh-header h2 { margin: 0 0 8px; }
.vbh-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--color-border); margin-bottom: 16px; }
.vbh-tabs button {
	background: transparent; border: none; border-bottom: 2px solid transparent;
	padding: 8px 14px; cursor: pointer; color: var(--color-text-maxcontrast);
}
.vbh-tabs button.active { color: var(--color-main-text); border-bottom-color: var(--color-primary-element); font-weight: bold; }
.vbh-section h3 { margin-top: 0; }
.vbh-hint { color: var(--color-text-maxcontrast); }
.vbh-card { border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 16px; margin: 12px 0; }
.vbh-table { width: 100%; border-collapse: collapse; margin: 12px 0; }
.vbh-table th, .vbh-table td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--color-border); font-size: 0.9em; }
.vbh-table th.num, .vbh-table td.num { text-align: right; white-space: nowrap; }
.vbh-purpose { max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.num.neg { color: var(--color-error); }
.num.pos { color: var(--color-success); }
tr.assigned { opacity: 0.7; }
.vbh-empty { color: var(--color-text-maxcontrast); font-style: italic; }
.vbh-form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 12px; }
.vbh-check { display: inline-flex; align-items: center; gap: 6px; margin: 8px 0; }
.vbh-filter { margin-bottom: 8px; }
.vbh-link { background: none; border: none; color: var(--color-error); cursor: pointer; padding: 0; }
.vbh-totals { display: flex; gap: 16px; margin: 12px 0; }
.vbh-total { border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 12px 16px; display: flex; flex-direction: column; min-width: 140px; }
.vbh-total span { color: var(--color-text-maxcontrast); font-size: 0.85em; }
.vbh-total strong { font-size: 1.3em; }
.vbh-total.pos strong { color: var(--color-success); }
.vbh-total.neg strong { color: var(--color-error); }
button.primary { background: var(--color-primary-element); color: var(--color-primary-element-text); border: none; border-radius: var(--border-radius-element, 6px); padding: 8px 14px; cursor: pointer; }
button.primary:disabled { opacity: 0.5; cursor: default; }
</style>
