<template>
	<div class="vbh">
		<header class="vbh-header">
			<div class="vbh-titlebar">
				<h2>Vereinsbuchhaltung</h2>
				<div v-if="primaryBank" class="vbh-bankchip" :class="{ warn: Math.abs(primaryBank.open) > 0.005 }">
					<span class="vbh-bankchip-label">{{ primaryBank.name }}</span>
					<span class="vbh-bankchip-value">{{ formatMoney(primaryBank.balance) }}</span>
					<span v-if="Math.abs(primaryBank.open) > 0.005" class="vbh-bankchip-hint">{{ formatMoney(primaryBank.open) }} offen</span>
				</div>
				<NcLoadingIcon v-if="busy" :size="24" name="Wird geladen…" />
			</div>
			<div v-if="canRead" class="vbh-navbar">
				<nav class="vbh-tabs">
					<button v-for="tab in visibleTabs" :key="tab.id" :class="{ active: activeTab === tab.id }" @click="activeTab = tab.id">
						<NcIconSvgWrapper :path="tab.icon" :size="18" inline />
						{{ tab.label }}
						<span v-if="tab.id === 'bookings' && unassignedCount > 0" class="vbh-badge vbh-badge--alert">{{ unassignedCount }}</span>
					</button>
				</nav>
				<div class="vbh-navright">
					<NcButton v-if="canWrite" variant="primary" class="vbh-newbooking-btn" title="Neue Buchung anlegen (von überall)" @click="openNewBooking">
						<template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
						<span class="vbh-newbooking-label">Buchung</span>
					</NcButton>
					<label class="vbh-yearsel" title="Geschäftsjahr (Kalenderjahr)">
						<span>Jahr</span>
						<select v-model="selectedYear">
							<option :value="null">Alle Jahre</option>
							<option v-for="y in years" :key="y" :value="y">{{ y }}</option>
						</select>
					</label>
					<NcButton v-if="canWrite" variant="tertiary" aria-label="Einstellungen & Import" title="Einstellungen & Import" @click="openSettings">
						<template #icon><NcIconSvgWrapper :path="mdiCog" :size="20" /></template>
					</NcButton>
				</div>
			</div>
		</header>

		<div v-if="me && !canRead" class="vbh-noaccess">
			<h3>Kein Zugriff</h3>
			<p>Du hast keine Berechtigung für die Vereinsbuchhaltung. Bitte wende dich an eine Verwalterin oder einen Verwalter.</p>
		</div>

		<main v-show="canRead" class="vbh-main">
			<!-- ============ ÜBERSICHT (DASHBOARD) ============ -->
			<section v-show="activeTab === 'dashboard'" class="vbh-section scroll" :class="{ 'vbh-fadein': sectionFade }">
				<div v-if="balances" class="vbh-totals">
					<div class="vbh-total pos">
						<span>Einnahmen{{ selectedYear ? ' ' + selectedYear : '' }}</span>
						<strong>{{ formatMoney(balances.totals.income) }}</strong>
						<small v-if="kpiDeltas && kpiDeltas.income" class="vbh-total-delta" :class="kpiDeltas.income.up ? 'good' : 'bad'">{{ kpiDeltas.income.text }}</small>
					</div>
					<div class="vbh-total neg">
						<span>Ausgaben{{ selectedYear ? ' ' + selectedYear : '' }}</span>
						<strong>{{ formatMoney(balances.totals.expense) }}</strong>
						<small v-if="kpiDeltas && kpiDeltas.expense" class="vbh-total-delta" :class="kpiDeltas.expense.up ? 'bad' : 'good'">{{ kpiDeltas.expense.text }}</small>
					</div>
					<div class="vbh-total" :class="balances.totals.result >= 0 ? 'pos' : 'neg'">
						<span>Ergebnis{{ selectedYear ? ' ' + selectedYear : '' }}</span>
						<strong>{{ formatMoney(balances.totals.result) }}</strong>
						<small v-if="kpiDeltas && kpiDeltas.result" class="vbh-total-delta" :class="kpiDeltas.result.up ? 'good' : 'bad'">{{ kpiDeltas.result.text }}</small>
					</div>
					<div v-if="unassignedCount > 0" class="vbh-total vbh-total--warn">
						<span>Nicht zugeordnet</span>
						<strong>{{ unassignedCount }} Buchungen</strong>
						<NcButton variant="primary" size="small" @click="goToUnassigned">Jetzt zuordnen</NcButton>
					</div>
				</div>

				<template v-if="balances && balances.bankReconciliation && balances.bankReconciliation.length">
					<h4>Geldkonten</h4>
					<div class="vbh-tablecard">
						<table class="vbh-table">
							<thead><tr><th>Konto</th><th class="num">Kontostand</th><th class="num">Offen (nicht zugeordnet)</th></tr></thead>
							<tbody>
								<tr v-for="b in balances.bankReconciliation" :key="b.accountId">
									<td>{{ b.number }} {{ b.name }}</td>
									<td class="num strong">{{ formatMoney(b.balance) }}</td>
									<td class="num" :class="Math.abs(b.open) > 0.005 ? 'neg' : ''">{{ formatMoney(b.open) }}</td>
								</tr>
							</tbody>
						</table>
					</div>
				</template>

				<template v-if="recentJournal.length">
					<div class="vbh-sectionhead">
						<h4>Letzte Buchungen</h4>
						<NcButton variant="tertiary" @click="activeTab = 'bookings'">Alle anzeigen</NcButton>
					</div>
					<div class="vbh-tablecard">
						<table class="vbh-table">
							<thead>
								<tr>
									<th class="num vbh-col-hide-sm">Nr.</th>
									<th class="nowrap">Datum</th>
									<th>Beschreibung</th>
									<th class="vbh-col-hide-sm">Soll</th>
									<th class="vbh-col-hide-sm">Haben</th>
									<th class="num">Betrag</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="r in recentJournal" :key="r.id">
									<td class="num vbh-col-hide-sm">{{ r.entryNo }}</td>
									<td class="nowrap">{{ formatDate(r.date) }}</td>
									<td class="vbh-purpose" :title="r.description"><span class="vbh-clamp">{{ r.description }}</span></td>
									<td class="vbh-col-hide-sm">{{ r.soll }}</td>
									<td class="vbh-col-hide-sm">{{ r.haben }}</td>
									<td class="num strong">{{ formatMoney(r.amount) }}</td>
								</tr>
							</tbody>
						</table>
					</div>
				</template>
				<NcEmptyContent v-else-if="!busy" name="Noch keine Buchungen" description="Importiere Kontoumsätze oder lege manuell Buchungssätze an." />

				<div class="vbh-chart-grid">
					<div class="vbh-chart-card vbh-chart-card--wide">
						<h4>Einnahmen &amp; Ausgaben{{ selectedYear ? ' ' + selectedYear : '' }} (monatlich)</h4>
						<div class="vbh-chart-wrap">
							<canvas ref="monthlyChart"></canvas>
						</div>
					</div>
				</div>
			</section>

			<!-- ============ BUCHUNGEN (JOURNAL + TRANSAKTIONEN) ============ -->
			<section v-show="activeTab === 'bookings'" class="vbh-section vbh-flex-col" :class="{ 'vbh-fadein': sectionFade }">
				<div class="vbh-sectiontop">
					<div class="vbh-subtabs">
						<button :class="{ active: bookingView === 'journal' }" @click="bookingView = 'journal'">Alle Buchungen</button>
						<button :class="{ active: bookingView === 'unassigned' }" @click="bookingView = 'unassigned'">
							Zuzuordnen
							<span v-if="unassignedCount > 0" class="vbh-badge vbh-badge--alert">{{ unassignedCount }}</span>
						</button>
					</div>
					<div class="vbh-sectiontop-actions">
						<a v-if="bookingView === 'journal'" :href="exportJournalUrl" download class="vbh-export-btn" title="Journal als CSV exportieren"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> CSV</a>
						<NcButton v-if="canWrite" variant="secondary" @click="openImport">
							<template #icon><NcIconSvgWrapper :path="mdiUpload" :size="18" /></template>
							Umsätze importieren
						</NcButton>
					</div>
				</div>

				<div class="vbh-filterbar">
					<input v-model="bookingSearch" type="search" placeholder="Suche…" class="vbh-search">
					<NcSelect
						v-if="bookingView === 'journal'"
						v-model="bookingFilterAccountOption"
						:options="accountOptionsList"
						:filter-by="accountFilterBy"
						label="label"
						:clearable="true"
						placeholder="Konto filtern"
						class="vbh-filter-select"
					/>
				</div>

				<div class="vbh-sectionbody">
					<!-- JOURNAL VIEW -->
					<template v-if="bookingView === 'journal'">
						<div v-if="filteredJournalRows.length" class="vbh-tablecard">
							<div class="vbh-tablecount">{{ filteredJournalRows.length }}<template v-if="filteredJournalRows.length !== sortedJournalRows.length"> von {{ sortedJournalRows.length }}</template> Buchungssätze</div>
							<table class="vbh-table">
								<thead>
									<tr>
										<th class="sortable num vbh-col-hide-sm" @click="toggleSort('journal','entryNo')">Nr.{{ sortArrow('journal','entryNo') }}</th>
										<th class="sortable nowrap" @click="toggleSort('journal','date')">Datum{{ sortArrow('journal','date') }}</th>
										<th class="sortable" @click="toggleSort('journal','description')">Beschreibung{{ sortArrow('journal','description') }}</th>
										<th class="sortable vbh-col-hide-sm" @click="toggleSort('journal','soll')">Soll{{ sortArrow('journal','soll') }}</th>
										<th class="sortable vbh-col-hide-sm" @click="toggleSort('journal','haben')">Haben{{ sortArrow('journal','haben') }}</th>
										<th class="sortable num" @click="toggleSort('journal','amount')">Betrag{{ sortArrow('journal','amount') }}</th>
										<th></th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="r in filteredJournalRows" :key="r.id">
										<td class="num strong vbh-col-hide-sm">{{ r.entryNo }}</td>
										<td class="nowrap">{{ formatDate(r.date) }}</td>
										<td class="vbh-purpose" :title="r.description"><span class="vbh-clamp">{{ r.description }}</span></td>
										<td class="vbh-col-hide-sm">{{ r.soll }}</td>
										<td class="vbh-col-hide-sm">{{ r.haben }}</td>
										<td class="num strong">{{ formatMoney(r.amount) }}</td>
										<td class="nowrap right">
											<div class="vbh-actions">
												<NcButton v-if="attachmentCountMap[r.id]"
													variant="tertiary"
													:title="attachmentCountMap[r.id].count === 1 ? 'Beleg anzeigen' : attachmentCountMap[r.id].count + ' Belege'"
													:aria-label="attachmentCountMap[r.id].count + ' Beleg(e)'"
													@click="clickPaperclip(r)">
													<template #icon><NcIconSvgWrapper :path="mdiPaperclip" :size="16" /></template>
												</NcButton>
												<NcButton v-if="canWrite" variant="tertiary" aria-label="Bearbeiten" @click="editBooking(r)">
													<template #icon><NcIconSvgWrapper :path="mdiPencil" :size="20" /></template>
												</NcButton>
												<NcButton v-if="canWrite && txByJournalId[r.id]"
													variant="tertiary"
													:title="'Regel anlegen: ' + txByJournalId[r.id].counterparty + ' künftig automatisch zuordnen'"
													aria-label="Zuordnungsregel anlegen"
													@click="createRuleFromTx(txByJournalId[r.id])">
													<template #icon><NcIconSvgWrapper :path="mdiFlash" :size="16" /></template>
												</NcButton>
												<NcButton v-if="canWrite" variant="error" aria-label="Löschen" @click="removeBooking(r)">
													<template #icon><NcIconSvgWrapper :path="mdiDelete" :size="20" /></template>
												</NcButton>
											</div>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
						<NcEmptyContent v-else-if="bookingSearch || bookingFilterAccountId" name="Keine Treffer" description="Suchfilter anpassen oder löschen." />
						<NcEmptyContent v-else name="Noch keine Buchungssätze" description="Lege mit ‛Neue Buchung' einen ersten Buchungssatz an." />
					</template>

					<!-- TRANSACTIONS VIEW (unassigned / assigned) -->
					<template v-else>
						<div v-if="bookingView === 'unassigned' && assignProgress.total > 0" class="vbh-progress">
							<span class="vbh-progress-label">{{ assignProgress.done }} von {{ assignProgress.total }} Bankbuchungen zugeordnet</span>
							<div class="vbh-progress-bar"><div class="vbh-progress-fill" :style="{ width: assignProgress.pct + '%' }"></div></div>
						</div>
						<div v-if="currentTransactions.length" class="vbh-tablecard">
							<div class="vbh-tablecount">{{ currentTransactions.length }} Buchungen</div>
							<table class="vbh-table">
								<thead>
									<tr>
										<th class="sortable nowrap" @click="toggleSort('transactions','bookingDate')">Datum{{ sortArrow('transactions','bookingDate') }}</th>
										<th class="sortable" @click="toggleSort('transactions','counterparty')">Empfänger/Zahler{{ sortArrow('transactions','counterparty') }}</th>
										<th class="vbh-col-hide-sm">Verwendungszweck</th>
										<th class="sortable num" @click="toggleSort('transactions','amount')">Betrag{{ sortArrow('transactions','amount') }}</th>
										<th>Konto / Kategorie</th>
									</tr>
								</thead>
								<transition-group tag="tbody" name="vbh-row">
									<tr v-for="tx in currentTransactions" :key="tx.id" :class="{ assigned: tx.status === 'assigned', open: tx.status !== 'assigned' }">
										<td class="nowrap">{{ formatDate(tx.bookingDate) }}</td>
										<td>{{ tx.counterparty }}</td>
										<td class="vbh-purpose vbh-col-hide-sm" :title="tx.purpose"><span class="vbh-clamp">{{ tx.purpose }}</span></td>
										<td class="num" :class="amountClass(tx.amount)">{{ formatMoney(tx.amount) }}</td>
										<td class="vbh-assign-cell">
											<div class="vbh-assign-inner">
											<div class="vbh-assign-row">
												<NcSelect
													:model-value="accountOptionFor(tx.contraAccountId)"
													:options="accountOptionsList"
													:filter-by="accountFilterBy"
													:clearable="!!tx.contraAccountId"
													:disabled="!canWrite"
													label="label"
													placeholder="– nicht zugeordnet –"
													class="vbh-assign-select"
													@update:model-value="v => onAssign(tx, v ? v.id : '')"
												/>
											</div>
											<button
												v-if="canWrite && !tx.contraAccountId && suggestionsById[tx.id]"
												class="vbh-suggest-chip"
												:title="'Vorschlag übernehmen: ' + suggestionsById[tx.id].label"
												@click="applySuggestion(tx)"
											>
												✓ Vorschlag: {{ suggestionsById[tx.id].label }}
											</button>
											</div>
										</td>
									</tr>
								</transition-group>
							</table>
						</div>
						<NcEmptyContent v-else-if="bookingSearch" name="Keine Treffer" description="Suchfilter anpassen." />
						<NcEmptyContent v-else-if="bookingView === 'unassigned'" name="Alle Buchungen zugeordnet" description="Keine offenen Bankbuchungen – alles erledigt.">
							<template v-if="canWrite" #action>
								<NcButton variant="secondary" @click="openImport">
									<template #icon><NcIconSvgWrapper :path="mdiUpload" :size="18" /></template>
									Neue Umsätze importieren
								</NcButton>
							</template>
						</NcEmptyContent>
					</template>
				</div>
			</section>

			<!-- ============ KONTEN ============ -->
			<section v-show="activeTab === 'accounts'" class="vbh-section split" :class="{ 'vbh-fadein': sectionFade }">
				<div class="vbh-tree">
					<div class="vbh-treehead">
						<NcButton v-if="canWrite" variant="primary" size="small" @click="openNewAccount">
							<template #icon><NcIconSvgWrapper :path="mdiPlus" :size="18" /></template>
							Konto
						</NcButton>
						<span v-else></span>
						<div class="vbh-treeactions">
							<NcButton variant="tertiary" @click="expandAll">alle auf</NcButton>
							<NcButton variant="tertiary" @click="collapseAll">alle zu</NcButton>
						</div>
					</div>

					<div class="vbh-treesearch">
						<input v-model="accountSearch" type="search" placeholder="Konto suchen…" class="vbh-search vbh-search--full">
					</div>

					<p v-if="accounts.length === 0" class="vbh-hint">
						Noch keine Konten.<br>
						<NcButton v-if="canWrite" variant="primary" @click="seedAccounts">Standard-Kontenrahmen anlegen</NcButton>
					</p>

					<div class="vbh-treelist">
						<div v-for="node in currentTree" :key="node.id"
							class="vbh-treenode" :class="{ selected: node.id === selectedAccountId, group: node.hasChildren }"
							:style="{ paddingLeft: (8 + node.depth * 18) + 'px' }"
							@click="selectAccount(node)">
							<button v-if="node.hasChildren && !accountSearch" class="vbh-caret" :class="{ open: expanded[node.id] }" @click.stop="toggleExpand(node.id)">›</button>
							<span v-else class="vbh-caret empty">·</span>
							<span class="vbh-treenum">{{ node.number }}</span>
							<span class="vbh-treename">{{ node.name }}</span>
							<span class="vbh-treesaldo" :class="[amountClass(balanceFor(node.id)), { zero: !balanceFor(node.id) }]">{{ formatMoney(balanceFor(node.id)) }}</span>
						</div>
					</div>
				</div>

				<div class="vbh-detail">
					<p v-if="!selectedAccount" class="vbh-empty vbh-detailhint">Konto links auswählen, um Buchungen anzuzeigen.</p>

					<template v-else>
						<div class="vbh-detailhead">
							<div>
								<h3>{{ selectedAccount.number }} · {{ selectedAccount.name }}</h3>
								<span class="vbh-typetag" :class="selectedAccount.type">{{ typeLabel(selectedAccount.type) }}</span>
								<span v-if="selectedAccount.category" class="vbh-cat">{{ selectedAccount.category }}</span>
							</div>
							<span v-if="canWrite" class="nowrap">
								<NcButton variant="tertiary" aria-label="Konto bearbeiten" @click="openEditAccount(selectedAccount)">
									<template #icon><NcIconSvgWrapper :path="mdiPencil" :size="20" /></template>
								</NcButton>
								<NcButton variant="error" aria-label="Konto löschen" @click="deleteAccount(selectedAccount)">
									<template #icon><NcIconSvgWrapper :path="mdiDelete" :size="20" /></template>
								</NcButton>
							</span>
						</div>

						<!-- Eröffnungssaldo nur für Geldkonten: nur deren Bestand geht über Jahresgrenzen. -->
					<div v-if="canWrite && selectedAccount.isBank" class="vbh-opening">
							<span>Eröffnungssaldo:</span>
							<input v-model.number="openingForm[selectedAccount.id].amount" type="number" step="0.01" class="vbh-num">
							<input v-model="openingForm[selectedAccount.id].date" type="date" class="vbh-date">
							<NcButton variant="primary" size="small" @click="saveOpening(selectedAccount)">Speichern</NcButton>
						</div>

						<div v-if="statement" class="vbh-statementbar">
							<NcCheckboxRadioSwitch v-model="statementIncludeChildren" @update:model-value="reloadStatement">inkl. Unterkonten</NcCheckboxRadioSwitch>
							<div class="vbh-previewsummary">
								<span class="vbh-badge muted">{{ statement.totals.count }} Buchungen</span>
								<span class="vbh-badge muted">Soll {{ formatMoney(statement.totals.debit) }}</span>
								<span class="vbh-badge muted">Haben {{ formatMoney(statement.totals.credit) }}</span>
								<span class="vbh-badge pos">Saldo {{ formatMoney(statement.totals.balance) }}</span>
							</div>
						</div>

						<div v-if="statementRows.length" class="vbh-tablecard">
							<table class="vbh-table">
								<thead><tr><th class="num vbh-col-hide-sm">Nr.</th><th class="nowrap">Datum</th><th>Beschreibung</th><th class="vbh-col-hide-sm">Gegenkonto</th><th class="num vbh-col-hide-sm">Soll</th><th class="num vbh-col-hide-sm">Haben</th><th class="num">Saldo</th></tr></thead>
								<tbody>
									<tr v-if="statement.carry" class="vbh-carryrow">
										<td class="vbh-col-hide-sm"></td>
										<td class="nowrap">{{ formatDate(selectedYear + '-01-01') }}</td>
										<td><em>Saldovortrag aus Vorjahr</em></td>
										<td class="vbh-col-hide-sm"></td>
										<td class="num vbh-col-hide-sm"></td>
										<td class="num vbh-col-hide-sm"></td>
										<td class="num strong" :class="amountClass(statement.carry)">{{ formatMoney(statement.carry) }}</td>
									</tr>
									<tr v-for="(row, i) in statementRows" :key="i">
										<td class="num vbh-col-hide-sm">{{ row.entryNo }}</td>
										<td class="nowrap">{{ formatDate(row.date) }}</td>
										<td class="vbh-purpose" :title="row.description"><span class="vbh-clamp">{{ row.description }}</span></td>
										<td class="vbh-col-hide-sm">{{ row.contra }}</td>
										<td class="num vbh-col-hide-sm">{{ row.debit ? formatMoney(row.debit) : '' }}</td>
										<td class="num vbh-col-hide-sm">{{ row.credit ? formatMoney(row.credit) : '' }}</td>
										<td class="num strong" :class="amountClass(row.saldo)">{{ formatMoney(row.saldo) }}</td>
									</tr>
								</tbody>
							</table>
						</div>
						<p v-else-if="statement" class="vbh-empty">Keine Buchungen auf diesem Konto{{ statementIncludeChildren ? ' (inkl. Unterkonten)' : '' }}.</p>
					</template>
				</div>
			</section>

			<!-- ============ BERICHTE (AUSWERTUNG + KOSTENSTELLEN + FINANZPLAN) ============ -->
			<section v-show="activeTab === 'reports'" class="vbh-section vbh-flex-col" :class="{ 'vbh-fadein': sectionFade }">
				<div class="vbh-sectiontop">
					<div class="vbh-subtabs">
						<button :class="{ active: reportView === 'summary' }" @click="reportView = 'summary'">Auswertung</button>
						<button :class="{ active: reportView === 'costcenters' }" @click="reportView = 'costcenters'">Kostenstellen</button>
						<button :class="{ active: reportView === 'budget' }" @click="reportView = 'budget'">Finanzplan</button>
					</div>
					<div class="vbh-sectiontop-actions">
						<a v-if="reportView === 'summary'" :href="exportBalancesUrl" download class="vbh-export-btn" title="Saldenliste als CSV exportieren"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> Saldenliste</a>
						<a v-if="reportView === 'summary'" :href="exportReportUrl" download class="vbh-export-btn" title="E/A-Übersicht als CSV exportieren"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> E/A-Übersicht</a>
						<a v-if="reportView === 'summary'" :href="exportMultiyearUrl" download class="vbh-export-btn" title="Mehrjahresübersicht (alle Jahre) als CSV exportieren"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> Mehrjahresübersicht</a>
						<a v-if="reportView === 'budget'" :href="exportBudgetUrl" download class="vbh-export-btn" title="Soll-Ist-Vergleich als CSV exportieren"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> Soll-Ist-Vergleich</a>
					</div>
				</div>

				<div class="vbh-sectionbody" :class="{ 'is-split': reportView === 'costcenters' }">
					<!-- AUSWERTUNG -->
					<div v-show="reportView === 'summary'">
						<div v-if="balances" class="vbh-totals">
							<div class="vbh-total pos"><span>Einnahmen</span><strong>{{ formatMoney(balances.totals.income) }}</strong></div>
							<div class="vbh-total neg"><span>Ausgaben</span><strong>{{ formatMoney(balances.totals.expense) }}</strong></div>
							<div class="vbh-total" :class="balances.totals.result >= 0 ? 'pos' : 'neg'"><span>Ergebnis</span><strong>{{ formatMoney(balances.totals.result) }}</strong></div>
						</div>

						<template v-if="balances && balances.bankReconciliation && balances.bankReconciliation.length">
							<h4>Geldkonten</h4>
							<div class="vbh-tablecard">
								<table class="vbh-table">
									<thead><tr><th>Konto</th><th class="num">Kontostand</th><th class="num">Offen (nicht zugeordnet)</th></tr></thead>
									<tbody>
										<tr v-for="b in balances.bankReconciliation" :key="b.accountId">
											<td>{{ b.number }} {{ b.name }}</td>
											<td class="num strong">{{ formatMoney(b.balance) }}</td>
											<td class="num" :class="Math.abs(b.open) > 0.005 ? 'neg' : 'pos'">{{ formatMoney(b.open) }}</td>
										</tr>
									</tbody>
								</table>
							</div>
						</template>

						<div class="vbh-sectionhead">
							<h4>Saldenliste</h4>
							<NcCheckboxRadioSwitch v-model="balancesIncludeChildren">Werte inkl. Unterkonten</NcCheckboxRadioSwitch>
						</div>
						<div v-if="balances" class="vbh-tablecard">
							<table class="vbh-table">
								<thead>
									<tr>
										<th class="sortable nowrap vbh-col-hide-sm" @click="toggleSort('balances','number')">Nr.{{ sortArrow('balances','number') }}</th>
										<th class="sortable" @click="toggleSort('balances','name')">Konto{{ sortArrow('balances','name') }}</th>
										<th class="sortable vbh-col-hide-sm" @click="toggleSort('balances','category')">Kategorie{{ sortArrow('balances','category') }}</th>
										<th class="sortable num vbh-col-hide-sm" @click="toggleSort('balances','debit')">Soll{{ sortArrow('balances','debit') }}</th>
										<th class="sortable num vbh-col-hide-sm" @click="toggleSort('balances','credit')">Haben{{ sortArrow('balances','credit') }}</th>
										<th class="sortable num" @click="toggleSort('balances','balance')">Saldo{{ sortArrow('balances','balance') }}</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="row in sortedBalances" :key="row.accountId" :class="{ 'vbh-parentrow': row.isParent }">
										<td class="nowrap vbh-col-hide-sm">{{ row.number }}</td>
										<td :style="{ paddingLeft: (10 + (row.depth || 0) * 18) + 'px' }">
											<span v-if="row.depth" class="vbh-treeglyph">└</span>{{ row.name }}
										</td>
										<td class="vbh-col-hide-sm">{{ row.category }}</td>
										<td class="num vbh-col-hide-sm">{{ formatMoney(row.debit) }}</td>
										<td class="num vbh-col-hide-sm">{{ formatMoney(row.credit) }}</td>
										<td class="num strong">{{ formatMoney(row.balance) }}</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>

					<!-- KOSTENSTELLEN (split layout) -->
					<div v-show="reportView === 'costcenters'" class="vbh-splitinner">
						<div class="vbh-tree">
							<div class="vbh-treehead"><strong>Kostenstellen</strong></div>
							<div v-if="reportData" class="vbh-ccsummary">
								<span>Gesamtergebnis</span>
								<strong :class="amountClass(reportData.totals.result)">{{ formatMoney(reportData.totals.result) }}</strong>
							</div>
							<div v-if="reportData" class="vbh-treelist">
								<div v-for="cc in reportData.costCenters" :key="cc.code === null ? 'none' : cc.code"
									class="vbh-treenode" :class="{ selected: isCCSelected(cc) }" @click="selectCC(cc)">
									<span class="vbh-treenum">{{ cc.code || '–' }}</span>
									<span class="vbh-treename">{{ cc.name }}</span>
									<span class="vbh-treesaldo" :class="[amountClass(cc.result), { zero: !cc.result }]">{{ formatMoney(cc.result) }}</span>
								</div>
							</div>
							<p v-else class="vbh-hint">Keine Daten. Importiere oder erfasse zuerst Buchungen.</p>
						</div>

						<div class="vbh-detail">
							<p v-if="!selectedCC" class="vbh-empty vbh-detailhint">Kostenstelle links auswählen.</p>
							<template v-else>
								<div class="vbh-detailhead"><div><h3>{{ selectedCC.code ? selectedCC.code + ' · ' : '' }}{{ selectedCC.name }}</h3></div></div>

								<div v-if="canWrite && selectedCC.code && reportData && reportData.mode !== 'account'" class="vbh-opening">
									<span>Name:</span>
									<input v-model="renameName" class="vbh-rename">
									<NcButton variant="primary" size="small" @click="saveRename">Umbenennen</NcButton>
								</div>

								<div class="vbh-totals">
									<div class="vbh-total pos"><span>Einnahmen</span><strong>{{ formatMoney(selectedCC.income) }}</strong></div>
									<div class="vbh-total neg"><span>Ausgaben</span><strong>{{ formatMoney(selectedCC.expense) }}</strong></div>
									<div class="vbh-total" :class="selectedCC.result >= 0 ? 'pos' : 'neg'"><span>Ergebnis</span><strong>{{ formatMoney(selectedCC.result) }}</strong></div>
								</div>

								<h4>Beteiligte Konten <span class="vbh-hint">(Konto anklicken für Buchungen)</span></h4>
								<div v-if="selectedCC.accounts.length" class="vbh-tablecard">
									<table class="vbh-table">
										<thead><tr><th class="nowrap">Nr.</th><th>Konto</th><th>Art</th><th class="num">Betrag</th></tr></thead>
										<tbody>
											<template v-for="(a, i) in selectedCC.accounts" :key="i">
												<tr class="vbh-ccrow" @click="toggleCCAccount(a.accountId)">
													<td class="nowrap"><span class="vbh-caret" :class="{ open: ccExpanded[a.accountId] }">›</span> {{ a.number }}</td>
													<td>{{ a.name }}</td>
													<td><span class="vbh-typetag" :class="a.type">{{ typeLabel(a.type) }}</span></td>
													<td class="num" :class="amountClass(a.balance)">{{ formatMoney(a.balance) }}</td>
												</tr>
												<tr v-if="ccExpanded[a.accountId]" class="vbh-ccdetail">
													<td colspan="4">
														<table v-if="ccBookings[a.accountId] && ccBookings[a.accountId].length" class="vbh-table vbh-subtable">
															<thead><tr><th class="num vbh-col-hide-sm">Nr.</th><th class="nowrap">Datum</th><th>Beschreibung</th><th class="vbh-col-hide-sm">Gegenkonto</th><th class="num">Soll</th><th class="num">Haben</th></tr></thead>
															<tbody>
																<tr v-for="(r, j) in ccBookings[a.accountId]" :key="j">
																	<td class="num vbh-col-hide-sm">{{ r.entryNo }}</td>
																	<td class="nowrap">{{ formatDate(r.date) }}</td>
																	<td class="vbh-purpose" :title="r.description"><span class="vbh-clamp">{{ r.description }}</span></td>
																	<td class="vbh-col-hide-sm">{{ r.contra }}</td>
																	<td class="num">{{ r.debit ? formatMoney(r.debit) : '' }}</td>
																	<td class="num">{{ r.credit ? formatMoney(r.credit) : '' }}</td>
																</tr>
															</tbody>
														</table>
														<p v-else class="vbh-empty">Keine Buchungen.</p>
													</td>
												</tr>
											</template>
										</tbody>
									</table>
								</div>
								<p v-else class="vbh-empty">Keine Buchungen mit Betrag in dieser Kostenstelle.</p>
							</template>
						</div>
					</div>

					<!-- FINANZPLAN -->
					<div v-show="reportView === 'budget'">
						<div class="vbh-sectionhead">
							<h4>Finanzplan &amp; Soll-Ist-Vergleich{{ budgetData ? ' ' + budgetData.year : '' }}</h4>
							<form v-if="canWrite" class="vbh-addyear" @submit.prevent="addBudgetYear">
								<input v-model="newBudgetYear" type="number" min="2000" max="2099" placeholder="Jahr" class="vbh-addyear-input">
								<NcButton type="submit" variant="secondary">Jahr hinzufügen</NcButton>
							</form>
						</div>
						<p class="vbh-hint">
							Plane je Konto die erwarteten Einnahmen und Ausgaben (Spalte „Plan"). Die Spalte „Ist" zeigt
							die tatsächlichen Buchungen des gewählten Geschäftsjahres, „Differenz" den Abstand zum Plan.
						</p>

						<div v-if="budgetData" class="vbh-totals">
							<div class="vbh-total pos">
								<span>Einnahmen (Plan / Ist)</span>
								<strong>{{ formatMoney(budgetData.totals.planIncome) }} / {{ formatMoney(budgetData.totals.actualIncome) }}</strong>
							</div>
							<div class="vbh-total neg">
								<span>Ausgaben (Plan / Ist)</span>
								<strong>{{ formatMoney(budgetData.totals.planExpense) }} / {{ formatMoney(budgetData.totals.actualExpense) }}</strong>
							</div>
							<div class="vbh-total" :class="budgetData.totals.actualResult >= 0 ? 'pos' : 'neg'">
								<span>Ergebnis (Plan / Ist)</span>
								<strong>{{ formatMoney(budgetData.totals.planResult) }} / {{ formatMoney(budgetData.totals.actualResult) }}</strong>
							</div>
						</div>

						<div v-if="budgetData && budgetData.rows.length" class="vbh-tablecard">
							<table class="vbh-table">
								<thead>
									<tr>
										<th class="nowrap vbh-col-hide-sm">Nr.</th>
										<th>Konto</th>
										<th class="vbh-col-hide-sm">Art</th>
										<th class="num vbh-col-plan">Plan (Soll)</th>
										<th class="vbh-col-note" title="Notiz zur Planzahl"></th>
										<th class="num">Ist</th>
										<th class="num">Differenz</th>
									</tr>
								</thead>
								<tbody>
									<template v-for="row in budgetData.rows">
										<tr :key="row.accountId">
											<td class="nowrap vbh-col-hide-sm">{{ row.number }}</td>
											<td>{{ row.name }}</td>
											<td class="vbh-col-hide-sm"><span class="vbh-typetag" :class="row.type">{{ typeLabel(row.type) }}</span></td>
											<td class="num vbh-col-plan">
												<input v-if="canWrite" v-model.number="row.plan" type="number" step="0.01" class="vbh-num vbh-planinput" @change="saveBudget(row)">
												<span v-else>{{ formatMoney(row.plan) }}</span>
											</td>
											<td class="vbh-col-note">
												<NcButton
													v-if="canWrite || row.note"
													variant="tertiary"
													:aria-label="row.note ? 'Notiz zur Planzahl anzeigen' : 'Notiz zur Planzahl hinzufügen'"
													:title="row.note || 'Notiz hinzufügen'"
													@click="toggleBudgetNote(row)">
													<template #icon>
														<NcIconSvgWrapper :path="row.note ? mdiCommentText : mdiCommentPlusOutline" :size="18" :class="{ 'vbh-noteicon--set': row.note }" />
													</template>
												</NcButton>
											</td>
											<td class="num strong" :class="amountClass(row.actual)">{{ formatMoney(row.actual) }}</td>
											<td class="num strong" :class="budgetDiffClass(row)">{{ formatMoney(row.diff) }}</td>
										</tr>
										<tr v-if="budgetNoteOpen[row.accountId]" :key="'note-' + row.accountId" class="vbh-note-row">
											<td colspan="7">
												<label class="vbh-note-label">Notiz zu {{ row.number }} {{ row.name }}
													<textarea
														v-if="canWrite"
														v-model="row.note"
														maxlength="1000"
														rows="2"
														class="vbh-note-textarea"
														placeholder="z. B. Herleitung: 40 Mitglieder × 25 € Beitrag"
														@change="saveBudget(row)"></textarea>
													<p v-else class="vbh-note-text">{{ row.note }}</p>
												</label>
											</td>
										</tr>
									</template>
								</tbody>
							</table>
						</div>
						<p v-else-if="budgetData" class="vbh-empty">Keine Einnahmen-/Ausgabenkonten vorhanden.</p>

						<!-- PLAN-STÄNDE (Snapshots) -->
						<div v-if="budgetData" class="vbh-snapblock">
							<div class="vbh-sectionhead">
								<h4>Plan-Stände {{ budgetData.year }}</h4>
								<form v-if="canWrite" class="vbh-addyear" @submit.prevent="saveBudgetSnapshot">
									<input v-model="newSnapshotLabel" type="text" maxlength="128" placeholder="z.B. Beschluss MV" class="vbh-snaplabel-input">
									<NcButton type="submit" variant="secondary">Aktuellen Plan speichern</NcButton>
								</form>
							</div>
							<p class="vbh-hint">
								Friere den aktuellen Finanzplan als benannten, datierten Stand ein (z.B. den in der
								Mitgliederversammlung beschlossenen Haushalt). Spätere Planänderungen lassen den Stand unberührt.
							</p>
							<div v-if="budgetSnapshots.length" class="vbh-tablecard">
								<table class="vbh-table">
									<thead>
										<tr>
											<th>Stand</th>
											<th class="nowrap vbh-col-hide-sm">Gespeichert</th>
											<th class="num vbh-col-hide-sm">Einnahmen</th>
											<th class="num vbh-col-hide-sm">Ausgaben</th>
											<th class="num">Ergebnis</th>
											<th></th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="snap in budgetSnapshots" :key="snap.id">
											<td><strong>{{ snap.label }}</strong></td>
											<td class="nowrap vbh-col-hide-sm">{{ formatDateTime(snap.createdAt) }}</td>
											<td class="num vbh-col-hide-sm">{{ formatMoney(snap.planIncome) }}</td>
											<td class="num vbh-col-hide-sm">{{ formatMoney(snap.planExpense) }}</td>
											<td class="num strong" :class="snap.planResult >= 0 ? 'good' : 'bad'">{{ formatMoney(snap.planResult) }}</td>
											<td class="right nowrap">
												<NcButton variant="tertiary" @click="openSnapshot(snap)">Ansehen</NcButton>
												<NcButton v-if="canWrite" variant="tertiary" @click="deleteBudgetSnapshot(snap)" title="Stand löschen">
													<template #icon><NcIconSvgWrapper :path="mdiDelete" :size="18" /></template>
												</NcButton>
											</td>
										</tr>
									</tbody>
								</table>
							</div>
							<p v-else class="vbh-empty">Noch keine Stände für dieses Jahr gespeichert.</p>
						</div>
					</div>
				</div>
			</section>
		</main>

		<!-- ============ EINSTELLUNGEN MODAL ============ -->
		<NcModal :show.sync="showSettings" name="Einstellungen & Import" size="large" @close="showSettings = false">
			<div class="vbh-modal-inner">
				<h3>Kontoumsätze importieren (CSV-CAMT)</h3>
				<div class="vbh-card">
					<p class="vbh-hint">Der CSV-Import ist direkt im Tab „Buchungen" erreichbar.</p>
					<NcButton variant="secondary" @click="showSettings = false; openImport()">
						<template #icon><NcIconSvgWrapper :path="mdiUpload" :size="18" /></template>
						Kontoumsätze importieren…
					</NcButton>
				</div>

				<h3>Aus „zero Buchhaltung" (.xbuc)</h3>
				<div class="vbh-card">
					<p class="vbh-hint">Übernimmt Kontenbaum und alle Buchungen aus einer .xbuc-Datei.</p>
					<div class="vbh-uploadrow">
						<label class="vbh-filebtn">Datei wählen<input ref="xbucInput" type="file" accept=".xbuc,application/xml,text/xml" hidden @change="onXbucSelected"></label>
						<span class="vbh-filename">{{ xbucFile ? xbucFile.name : 'keine Datei gewählt' }}</span>
						<NcCheckboxRadioSwitch v-model="xbucReset">Vorher alle Daten löschen (frisch starten)</NcCheckboxRadioSwitch>
					</div>
					<div v-if="xbucPreviewResult" class="vbh-preview">
						<p class="vbh-previewsummary">
							<span class="vbh-badge pos">{{ xbucPreviewResult.accounts }} Konten</span>
							<span class="vbh-badge pos">{{ xbucPreviewResult.bookings }} Buchungen</span>
							<span v-if="xbucPreviewResult.openBankTx > 0" class="vbh-badge muted">{{ xbucPreviewResult.openBankTx }} ohne Gegenkonto → offen</span>
						</p>
						<p v-if="xbucPreviewResult.openBankTx > 0" class="vbh-hint">{{ xbucPreviewResult.openBankTx }} Buchung(en) ohne Gegenkonto werden als offene Bankbuchungen übernommen und erscheinen im Tab „Buchungen → Zuzuordnen".</p>
						<div class="vbh-form vbh-yearedit">
							<label>Geschäftsjahr
								<input v-model.number="xbucYear" type="number" min="2000" max="2099" placeholder="z. B. 2025" class="vbh-addyear-input" @change="xbucPreview()">
							</label>
							<span v-if="!xbucPreviewResult.fileYear && !xbucYear" class="vbh-hint">Kein Geschäftsjahr in der Datei hinterlegt – Jahr eintragen, um die Datumsprüfung zu aktivieren.</span>
							<span v-else-if="xbucPreviewResult.fileYear && xbucYear && xbucYear !== xbucPreviewResult.fileYear" class="vbh-warn-inline">Weicht vom Jahr der Datei ab ({{ xbucPreviewResult.fileYear }}).</span>
						</div>
						<div v-if="!xbucReset && xbucPreviewResult.openings && xbucPreviewResult.openings.length" class="vbh-openinfo">
							<p class="vbh-openinfo-title">Anfangsbestände in der Datei:</p>
							<ul class="vbh-yearwarn-list">
								<li v-for="(o, i) in xbucPreviewResult.openings" :key="i">
									{{ o.account }}: {{ formatMoney(o.amount) }} ({{ formatDate(o.date) }}) –
									<template v-if="o.action === 'import'">wird übernommen (keine Vorjahresbuchungen vorhanden)</template>
									<template v-else-if="o.matches">wird übersprungen, stimmt mit dem Vorjahres-Endstand überein ✓</template>
									<template v-else><span class="vbh-warn-inline">wird übersprungen – ⚠ Vorjahres-Endstand ist {{ formatMoney(o.priorBalance) }} (Differenz {{ formatMoney(o.amount - o.priorBalance) }})</span></template>
								</li>
							</ul>
						</div>
						<div v-if="xbucPreviewResult.outsideYear > 0" class="vbh-yearwarn">
							<p class="vbh-warn-inline">
								⚠ {{ xbucPreviewResult.outsideYear }} Buchung(en) liegen außerhalb des Geschäftsjahres {{ xbucPreviewResult.year }}
								und würden in der App einem anderen Jahr zugeordnet:
							</p>
							<ul class="vbh-yearwarn-list">
								<li v-for="(s, i) in xbucPreviewResult.outsideSamples" :key="i">
									{{ formatDate(s.date) }} · {{ formatMoney(s.amount) }} · {{ s.text }}
								</li>
								<li v-if="xbucPreviewResult.outsideYear > xbucPreviewResult.outsideSamples.length">…</li>
							</ul>
							<NcCheckboxRadioSwitch v-model="xbucClampDates">
								Diese Buchungen auf das Geschäftsjahr {{ xbucPreviewResult.year }} datieren (01.01. bzw. 31.12.)
							</NcCheckboxRadioSwitch>
						</div>
						<div v-if="!xbucReset && xbucPreviewResult.yearTransition" class="vbh-yearwarn" :class="{ 'vbh-yearwarn--block': xbucPreviewResult.yearTransition.hasMismatch }">
							<p :class="xbucPreviewResult.yearTransition.hasMismatch ? 'vbh-warn-inline' : 'vbh-openinfo-title'">
								Rückwärts-Import (früheres Jahr): Abgleich mit dem Jahresübergang zu {{ xbucPreviewResult.yearTransition.targetYear }}.
							</p>
							<ul class="vbh-yearwarn-list">
								<li v-for="(c, i) in xbucPreviewResult.yearTransition.comparisons" :key="i">
									<template v-if="c.matches">{{ c.account }}: {{ formatMoney(c.storedOpening) }} stimmt überein ✓</template>
									<template v-else><span class="vbh-warn-inline">{{ c.account }}: Endstand {{ formatMoney(c.fileClosing) }} ≠ gespeicherter Anfangsbestand {{ formatMoney(c.storedOpening) }} (Differenz {{ formatMoney(c.fileClosing - c.storedOpening) }})</span></template>
								</li>
							</ul>
							<p v-if="xbucPreviewResult.yearTransition.hasMismatch" class="vbh-warn-inline">
								⛔ Import blockiert, bis die Beträge am Jahresübergang übereinstimmen.
							</p>
							<p v-else class="vbh-hint">
								{{ xbucPreviewResult.yearTransition.removalCount }} überflüssige Eröffnungsbuchung(en) aus {{ xbucPreviewResult.yearTransition.targetYear }} werden beim Import entfernt (der Anfangsbestand kommt dann aus diesem früheren Jahr).
							</p>
						</div>
						<NcButton variant="primary" :disabled="busy || xbucImportBlocked" @click="xbucImport">Importieren</NcButton>
						<span v-if="xbucReset" class="vbh-warn-inline">Achtung: bestehende Daten werden gelöscht.</span>
					</div>
				</div>

				<h4>Bisherige CSV-Importe</h4>
				<div v-if="imports.length" class="vbh-tablecard">
					<table class="vbh-table">
						<thead><tr><th>Datum</th><th>Datei</th><th class="num">Neu</th><th class="num">Dubletten</th></tr></thead>
						<tbody>
							<tr v-for="imp in imports" :key="imp.id">
								<td class="nowrap">{{ formatDateTime(imp.createdAt) }}</td>
								<td>{{ imp.filename }}</td>
								<td class="num">{{ imp.rowsNew }}</td>
								<td class="num">{{ imp.rowsDuplicate }}</td>
							</tr>
						</tbody>
					</table>
				</div>
				<NcEmptyContent v-else name="Noch keine CSV-Importe" description="Importiere oben eine CSV-CAMT-Datei." />

				<SettingsRules
					v-if="canWrite"
					:rules="rules"
					:accounts-by-id="accountsById"
					:account-options-list="accountOptionsList"
					:ask-confirm="askConfirm"
					@changed="loadRules"
				/>

				<template v-if="isAdmin">
					<h3 class="vbh-section-divider">Berechtigungen</h3>
					<p class="vbh-hint">
						<strong>Verwalter</strong> dürfen alles inkl. Rechtevergabe, <strong>Buchhalter</strong> lesen und schreiben,
						<strong>Revisor</strong> nur lesen. Nextcloud-Administratoren sind immer Verwalter.
					</p>

					<div class="vbh-card">
						<h4>Neue Berechtigung</h4>
						<div class="vbh-form">
							<label>Typ
								<select v-model="permForm.principalType">
									<option value="group">Gruppe</option>
									<option value="user">Nutzer</option>
								</select>
							</label>
							<label class="vbh-grow">{{ permForm.principalType === 'group' ? 'Gruppe' : 'Nutzer' }}
								<NcSelect
									v-model="permFormPrincipalOption"
									:options="permForm.principalType === 'group' ? groupOptions : userOptions"
									label="label"
									:placeholder="permForm.principalType === 'group' ? '– Gruppe wählen –' : '– Nutzer wählen –'"
								/>
							</label>
							<label>Rolle
								<select v-model="permForm.role">
									<option value="revisor">Revisor (nur lesen)</option>
									<option value="buchhalter">Buchhalter (lesen+schreiben)</option>
									<option value="verwalter">Verwalter (alles)</option>
								</select>
							</label>
							<NcButton variant="primary" @click="savePermission">Hinzufügen</NcButton>
						</div>
					</div>

					<div v-if="permissions.length" class="vbh-tablecard">
						<table class="vbh-table">
							<thead><tr><th>Typ</th><th>Nutzer / Gruppe</th><th>Rolle</th><th></th></tr></thead>
							<tbody>
								<tr v-for="p in permissions" :key="p.id">
									<td>{{ p.principalType === 'group' ? 'Gruppe' : 'Nutzer' }}</td>
									<td>{{ p.principalId }}</td>
									<td><span class="vbh-typetag">{{ roleLabel(p.role) }}</span></td>
									<td class="right">
										<NcButton variant="error" aria-label="Berechtigung entfernen" @click="removePermission(p)">
											<template #icon><NcIconSvgWrapper :path="mdiDelete" :size="20" /></template>
										</NcButton>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
					<NcEmptyContent v-else name="Keine Berechtigungen" description="Nextcloud-Administratoren haben immer Zugriff." />

					<h3 class="vbh-section-divider">Kostenstellen</h3>
					<div class="vbh-card">
						<p class="vbh-hint">
							Bestimmt, wie der Bericht „Kostenstellen" die Konten gruppiert. Der Modus hängt vom
							Kontenrahmen des Vereins ab.
						</p>
						<div class="vbh-form">
							<label class="vbh-grow">Modus
								<select v-model="costCenterMode">
									<option value="group">2. Zahlengruppe der Kontonummer (z. B. „111 51" → Kostenstelle 51)</option>
									<option value="account">Jedes Einnahmen-/Ausgabenkonto ist eine eigene Kostenstelle</option>
								</select>
							</label>
							<NcButton variant="primary" :disabled="storageSaving" @click="saveStorageSettings">Speichern</NcButton>
						</div>
					</div>

					<h3 class="vbh-section-divider">Belegablage</h3>
					<div class="vbh-card">
						<p class="vbh-hint">
							Belege können intern (AppData, nicht in der Nextcloud-Oberfläche sichtbar) oder in einem
							Ordner eines Nextcloud-Nutzers gespeichert werden. Wenn kein Nutzer gewählt ist, wird die interne Ablage verwendet.
						</p>
						<div class="vbh-form">
							<label class="vbh-grow">Nextcloud-Nutzer
								<select v-model="storageUser">
									<option value="">— intern (AppData) —</option>
									<option v-for="u in users" :key="u.id" :value="u.id">{{ u.displayName }} ({{ u.id }})</option>
								</select>
							</label>
							<label class="vbh-grow">Ordnerpfad im Nutzer-Home
								<input v-model="storagePath" type="text" placeholder="Vereinsbuchhaltung/Belege">
							</label>
							<NcButton variant="primary" :disabled="storageSaving" @click="saveStorageSettings">Speichern</NcButton>
						</div>
						<p v-if="storageUser" class="vbh-hint vbh-hint--info">
							Belege werden unter <code>{{ storageUser }}/{{ storagePath || 'Vereinsbuchhaltung/Belege' }}/&lt;BuchungsID&gt;/</code> abgelegt.
						</p>
					</div>

					<div class="vbh-card vbh-card--danger">
						<h4>Alle Daten löschen</h4>
						<p class="vbh-hint">Löscht alle Konten, Buchungen und Importe dieses Kontos unwiderruflich.</p>
						<NcButton variant="error" :disabled="busy" @click="resetAll">Alle Daten löschen</NcButton>
					</div>
				</template>
			</div>
		</NcModal>

		<!-- ============ IMPORT-DIALOG (CSV-CAMT) ============ -->
		<NcModal :show.sync="showImport" name="Kontoumsätze importieren (CSV-CAMT)" size="normal" @close="closeImport">
			<div class="vbh-modal-inner">
				<template v-if="!importDone">
					<div
						class="vbh-dropzone"
						:class="{ dragging: importDragging, 'has-file': !!selectedFile }"
						@dragover.prevent="importDragging = true"
						@dragleave.self="importDragging = false"
						@drop.prevent="onImportDrop"
					>
						<NcIconSvgWrapper :path="mdiUpload" :size="36" class="vbh-dropzone-icon" />
						<p class="vbh-dropzone-text">
							CSV-Datei der Bank hierher ziehen<br>
							<span class="vbh-dropzone-or">oder</span>
						</p>
						<label class="vbh-filebtn">Datei wählen<input ref="fileInput" type="file" accept=".csv,text/csv" hidden @change="onFileSelected"></label>
						<p v-if="selectedFile" class="vbh-filename">{{ selectedFile.name }}</p>
					</div>
					<p class="vbh-hint">Nur neue Buchungen werden übernommen – bereits importierte werden automatisch erkannt (Dublettenprüfung).</p>
					<NcCheckboxRadioSwitch v-model="applyRules">Auto-Zuordnungsregeln anwenden</NcCheckboxRadioSwitch>
					<div v-if="previewResult" class="vbh-preview">
						<p class="vbh-previewsummary">
							<span class="vbh-badge pos">{{ previewResult.new }} neu</span>
							<span class="vbh-badge muted">{{ previewResult.duplicate }} Dubletten</span>
							<span class="vbh-badge muted">{{ previewResult.total }} gesamt</span>
						</p>
						<p v-if="previewResult.existingBookings > 0" class="vbh-hint">Davon {{ previewResult.existingBookings }} bereits als vorhandene Buchung erkannt (z. B. aus einem XBUC-Import) und daher übersprungen.</p>
						<NcButton variant="primary" :disabled="busy || previewResult.new === 0" @click="commit">{{ previewResult.new }} Buchungen importieren</NcButton>
						<p v-if="previewResult.new === 0" class="vbh-hint">Alle Buchungen dieser Datei wurden bereits importiert.</p>
					</div>
				</template>
				<template v-else>
					<div class="vbh-import-done">
						<NcIconSvgWrapper :path="mdiCheckCircle" :size="48" class="vbh-import-done-icon" />
						<h3>{{ importDone.new }} Buchungen importiert</h3>
						<p v-if="importDone.autoAssigned > 0" class="vbh-hint">{{ importDone.autoAssigned }} davon wurden automatisch zugeordnet.</p>
						<p v-if="importDone.new - importDone.autoAssigned > 0" class="vbh-hint">
							{{ importDone.new - importDone.autoAssigned }} Buchungen warten auf die Zuordnung zu einem Konto.
						</p>
						<div class="vbh-modal-actions">
							<NcButton variant="tertiary" @click="closeImport">Schließen</NcButton>
							<NcButton v-if="importDone.new - importDone.autoAssigned > 0" variant="primary" @click="goAssignAfterImport">Jetzt zuordnen</NcButton>
						</div>
					</div>
				</template>
			</div>
		</NcModal>

		<!-- ============ BUCHUNGS-DIALOG ============ -->
		<NcModal :show.sync="showBooking" :name="bookingForm.id ? 'Buchung bearbeiten #' + bookingForm.entryNo : 'Neue Buchung'" size="normal" @close="closeBooking">
			<div class="vbh-modal-inner">
				<div v-if="bookingMode === 'simple'" class="vbh-kindtoggle" role="radiogroup" aria-label="Buchungsart">
					<button type="button" class="vbh-kindbtn income" :class="{ active: bookingForm.kind === 'income' }" @click="setBookingKind('income')">Einnahme</button>
					<button type="button" class="vbh-kindbtn expense" :class="{ active: bookingForm.kind === 'expense' }" @click="setBookingKind('expense')">Ausgabe</button>
				</div>
				<div class="vbh-form">
					<label>Datum<input v-model="bookingForm.date" type="date"></label>
					<label>Beleg-Nr.<input v-model="bookingForm.documentRef" class="vbh-short" placeholder="optional"></label>
					<label>Betrag (€)<input v-model.number="bookingForm.amount" type="number" step="0.01" min="0.01" class="vbh-num"></label>
				</div>
				<template v-if="bookingMode === 'simple'">
					<div class="vbh-form">
						<label class="vbh-grow">{{ bookingForm.kind === 'income' ? 'Wofür? (Einnahme-Kategorie)' : 'Wofür? (Ausgabe-Kategorie)' }}
							<NcSelect
								v-model="bookingFormCategoryOption"
								:options="simpleCategoryOptions"
								:filter-by="accountFilterBy"
								label="label"
								placeholder="– Kategorie wählen –"
							/>
						</label>
						<label class="vbh-grow">Geldkonto (Bank/Kasse)
							<NcSelect
								v-model="bookingFormMoneyOption"
								:options="moneyAccountOptions"
								:filter-by="accountFilterBy"
								label="label"
								placeholder="– wählen –"
							/>
						</label>
					</div>
				</template>
				<template v-else>
					<div class="vbh-form">
						<label class="vbh-grow">Soll (Aufwand/Aktiv)
							<NcSelect
								v-model="bookingFormDebitOption"
								:options="accountOptionsList"
								:filter-by="accountFilterBy"
								label="label"
								placeholder="– wählen –"
							/>
						</label>
						<label class="vbh-grow">Haben (Ertrag/Passiv)
							<NcSelect
								v-model="bookingFormCreditOption"
								:options="accountOptionsList"
								:filter-by="accountFilterBy"
								label="label"
								placeholder="– wählen –"
							/>
						</label>
					</div>
				</template>
				<div class="vbh-form">
					<label class="vbh-grow">Buchungstext<input v-model="bookingForm.description" placeholder="z. B. Mitgliedsbeitrag Max Mustermann"></label>
				</div>
				<div class="vbh-expertrow">
					<NcCheckboxRadioSwitch v-model="bookingModeExpert" type="switch">
						Experten-Modus (Soll/Haben direkt wählen)
					</NcCheckboxRadioSwitch>
				</div>

				<!-- Belegablage (nur bei bestehenden Buchungen verfügbar) -->
				<div v-if="bookingForm.id" class="vbh-attachments">
					<div class="vbh-attachments-header">
						<span class="vbh-attachments-title">Belege</span>
						<label v-if="canWrite" class="vbh-upload-label" :class="{ 'is-uploading': attachmentUploading }">
							<input type="file" accept="image/*,application/pdf" multiple :disabled="attachmentUploading" hidden @change="uploadAttachment">
							<span class="vbh-upload-btn">
								<NcIconSvgWrapper :path="mdiPaperclip" :size="16" />
								{{ attachmentUploading ? 'Lädt hoch…' : 'Anhängen' }}
							</span>
						</label>
					</div>
					<ul v-if="bookingAttachments.length" class="vbh-attachment-list">
						<li v-for="a in bookingAttachments" :key="a.id" class="vbh-attachment-item">
							<NcIconSvgWrapper :path="mdiPaperclip" :size="14" class="vbh-attachment-icon" />
							<button class="vbh-attachment-name" :title="'Anzeigen: ' + a.fileName" @click="openViewer(a)">{{ a.fileName }}</button>
							<span class="vbh-attachment-size">{{ formatFileSize(a.fileSize) }}</span>
							<a :href="attachmentDownloadUrl(a.id)" class="vbh-attachment-dl" title="Herunterladen" download>↓</a>
							<NcButton v-if="canWrite" variant="tertiary" :aria-label="'Beleg löschen'" @click="deleteAttachment(a.id)">
								<template #icon><NcIconSvgWrapper :path="mdiDelete" :size="14" /></template>
							</NcButton>
						</li>
					</ul>
					<p v-else class="vbh-attachment-empty">Noch kein Beleg angehängt.</p>
				</div>

				<div class="vbh-modal-actions">
					<NcButton variant="tertiary" @click="closeBooking">Abbrechen</NcButton>
					<NcButton variant="primary" @click="saveBooking">{{ bookingForm.id ? 'Speichern' : 'Buchen' }}</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- ============ KONTO-DIALOG ============ -->
		<NcModal :show.sync="showAccount" :name="accountEditId ? 'Konto bearbeiten' : 'Neues Konto'" size="normal" @close="closeAccount">
			<div class="vbh-modal-inner">
				<div class="vbh-form">
					<label>Nummer<input v-model="newAccount.number" class="vbh-short" placeholder="z.B. 4000"></label>
					<label class="vbh-grow">Bezeichnung<input v-model="newAccount.name" placeholder="Kontoname"></label>
				</div>
				<div class="vbh-form">
					<label>Typ
						<select v-model="newAccount.type">
							<option value="income">Ertrag (Einnahme)</option>
							<option value="expense">Aufwand (Ausgabe)</option>
							<option value="asset">Aktiv (Vermögen)</option>
							<option value="liability">Passiv (Verbindlichkeit)</option>
							<option value="equity">Eigenkapital</option>
						</select>
					</label>
					<label class="vbh-grow">Überkonto
						<NcSelect
							v-model="accountParentOption"
							:options="accountParentOptions"
							:filter-by="accountFilterBy"
							label="label"
							placeholder="– kein Überkonto –"
							:clearable="true"
						/>
					</label>
				</div>
				<div class="vbh-form">
					<label>Kategorie<input v-model="newAccount.category" placeholder="optional"></label>
					<NcCheckboxRadioSwitch v-model="newAccount.isBank">Geldkonto (Bank/Kasse) – Bestand geht über Jahresgrenzen</NcCheckboxRadioSwitch>
				</div>
				<div class="vbh-modal-actions">
					<NcButton variant="tertiary" @click="closeAccount">Abbrechen</NcButton>
					<NcButton variant="primary" @click="saveAccount">{{ accountEditId ? 'Speichern' : 'Anlegen' }}</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- ============ PLAN-STAND DETAIL ============ -->
		<NcModal v-if="snapshotView.open" :show.sync="snapshotView.open" :name="'Plan-Stand: ' + (snapshotView.data ? snapshotView.data.label : '')" size="normal" @close="closeSnapshot">
			<div v-if="snapshotView.data" class="vbh-modal-inner">
				<p class="vbh-hint">
					Eingefroren am {{ formatDateTime(snapshotView.data.createdAt) }} · Geschäftsjahr {{ snapshotView.data.year }}.
					Die Spalte „Aktuell" zeigt den heutigen Planwert, „Δ" die Abweichung des aktuellen Plans zum Stand.
				</p>
				<div v-if="snapshotView.data.items && snapshotView.data.items.length" class="vbh-tablecard">
					<table class="vbh-table">
						<thead>
							<tr>
								<th class="nowrap vbh-col-hide-sm">Nr.</th>
								<th>Konto</th>
								<th class="num">Stand</th>
								<th class="num vbh-col-hide-sm">Aktuell</th>
								<th class="num">Δ</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="it in snapshotView.data.items" :key="it.id">
								<td class="nowrap vbh-col-hide-sm">{{ it.number }}</td>
								<td>{{ it.name }}</td>
								<td class="num strong">{{ formatMoney(it.amount) }}</td>
								<td class="num vbh-col-hide-sm">{{ formatMoney(currentPlanForAccount(it.accountId)) }}</td>
								<td class="num strong" :class="amountClass(currentPlanForAccount(it.accountId) - it.amount)">{{ formatMoney(currentPlanForAccount(it.accountId) - it.amount) }}</td>
							</tr>
						</tbody>
						<tfoot>
							<tr>
								<td class="vbh-col-hide-sm"></td>
								<td><strong>Ergebnis (Plan)</strong></td>
								<td class="num strong" :class="snapshotView.data.planResult >= 0 ? 'good' : 'bad'">{{ formatMoney(snapshotView.data.planResult) }}</td>
								<td class="vbh-col-hide-sm"></td>
								<td></td>
							</tr>
						</tfoot>
					</table>
				</div>
				<p v-else class="vbh-empty">Dieser Stand enthält keine Planwerte.</p>
				<div class="vbh-modal-actions">
					<NcButton variant="primary" @click="closeSnapshot">Schließen</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- ============ BESTÄTIGUNGS-DIALOG ============ -->
		<NcDialog
			v-if="confirmDialog.open"
			:name="confirmDialog.title"
			:message="confirmDialog.message"
			:no-close="true"
			:buttons="confirmDialogButtonList"
			@update:open="closeConfirm(false)"
		/>
	</div>
</template>

<script>
import { showError, showInfo, showSuccess, showUndo } from '@nextcloud/dialogs'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcEmptyContent,
	NcIconSvgWrapper,
	NcLoadingIcon,
	NcModal,
	NcSelect,
} from '@nextcloud/vue'
import { mdiCog, mdiCommentPlusOutline, mdiCommentText, mdiDelete, mdiPaperclip, mdiPencil, mdiPlus, mdiUpload, mdiCheckCircle, mdiDownload, mdiFlash, mdiViewDashboardOutline, mdiSwapHorizontal, mdiFileTreeOutline, mdiChartBar } from '@mdi/js'
import api from './api.js'
import { formatMoney, formatDate, formatDateTime, typeLabel, roleLabel, amountClass, budgetDiffClass, errMsg } from './lib/format.js'
import SettingsRules from './components/SettingsRules.vue'
import {
	Chart,
	BarController,
	BarElement,
	CategoryScale,
	LinearScale,
	Tooltip,
	Legend,
} from 'chart.js'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip, Legend)

export default {
	name: 'App',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcEmptyContent,
		NcIconSvgWrapper,
		NcLoadingIcon,
		NcModal,
		NcSelect,
		SettingsRules,
	},
	data() {
		return {
			activeTab: 'dashboard',
			allTabs: [
				{ id: 'dashboard', label: 'Übersicht', need: 'read', icon: mdiViewDashboardOutline },
				{ id: 'bookings', label: 'Buchungen', need: 'read', icon: mdiSwapHorizontal },
				{ id: 'accounts', label: 'Konten', need: 'read', icon: mdiFileTreeOutline },
				{ id: 'reports', label: 'Berichte', need: 'read', icon: mdiChartBar },
			],
			bookingView: 'journal',
			reportView: 'summary',
			showSettings: false,
			bookingSearch: '',
			bookingFilterAccountId: null,
			accountSearch: '',
			selectedYear: null,
			years: [],
			newBudgetYear: '',
			budgetData: null,
			budgetNoteOpen: {},
			budgetSnapshots: [],
			newSnapshotLabel: '',
			snapshotView: { open: false, data: null },
			me: null,
			permissions: [],
			groups: [],
			users: [],
			permForm: { principalType: 'group', principalId: '', role: 'revisor' },
			busy: false,
			selectedFile: null,
			applyRules: true,
			previewResult: null,
			xbucFile: null,
			xbucReset: false,
			xbucClampDates: false,
			xbucYear: null,
			xbucPreviewResult: null,
			imports: [],
			transactions: [],
			accounts: [],
			balances: null,
			balancesIncludeChildren: false,
			reportData: null,
			selectedCCCode: false,
			renameName: '',
			ccExpanded: {},
			ccBookings: {},
			journalData: [],
			newAccount: { number: '', name: '', type: 'income', category: '', isBank: false, parentId: null },
			accountEditId: null,
			openingForm: {},
			expanded: {},
			selectedAccountId: null,
			statement: null,
			statementIncludeChildren: true,
			showBooking: false,
			showAccount: false,
			bookingMode: 'simple',
			showImport: false,
			importDragging: false,
			importDone: null,
			rules: [],
			prevBalances: null,
			sectionFade: true,
			bookingForm: this.emptyBookingForm(),
			sort: {
				transactions: { key: 'bookingDate', dir: 'desc' },
				balances: { key: 'number', dir: 'asc' },
				journal: { key: 'entryNo', dir: 'desc' },
			},
			confirmDialog: { open: false, title: '', message: '', confirmLabel: 'Löschen', confirmVariant: 'error', resolve: null },
			mdiCog,
			mdiCommentPlusOutline,
			mdiCommentText,
			mdiDelete,
			mdiPaperclip,
			mdiPencil,
			mdiPlus,
			mdiUpload,
			mdiCheckCircle,
			mdiDownload,
			mdiFlash,
			chartInstances: {},
			bookingAttachments: [],
			attachmentUploading: false,
			attachmentCountMap: {},
			// Kollaboration: zuletzt gesehener Änderungsstand + Poll-Timer
			syncRevision: null,
			syncTimer: null,
			storageUser: '',
			storagePath: '',
			costCenterMode: 'group',
			storageSaving: false,
		}
	},
	computed: {
		canRead() { return !!(this.me && this.me.canRead) },
		canWrite() { return !!(this.me && this.me.canWrite) },
		isAdmin() { return !!(this.me && this.me.isAdmin) },
		exportJournalUrl()  { return api.exportJournalUrl(this.selectedYear) },
		exportBalancesUrl() { return api.exportBalancesUrl(this.selectedYear) },
		exportReportUrl()   { return api.exportReportUrl(this.selectedYear) },
		exportBudgetUrl()   { return api.exportBudgetUrl(this.selectedYear) },
		exportMultiyearUrl() { return api.exportMultiyearUrl() },
		// Rückwärts-Import mit inkonsistentem Jahresübergang → Import gesperrt
		// (außer bei „alle Daten löschen", da entfällt der Abgleich).
		xbucImportBlocked() {
			const t = this.xbucPreviewResult && this.xbucPreviewResult.yearTransition
			return !this.xbucReset && !!t && t.hasMismatch
		},
		visibleTabs() {
			return this.allTabs.filter(t => {
				if (t.need === 'admin') return this.isAdmin
				if (t.need === 'write') return this.canWrite
				return this.canRead
			})
		},
		unassignedCount() {
			return this.transactions.filter(t => t.status === 'unassigned').length
		},
		currentTransactions() {
			const status = 'unassigned' // "Zugeordnet"-Ansicht entfernt; hier nur offene Umsätze
			let txs = this.applySort(
				this.transactions.filter(t => t.status === status),
				this.sort.transactions,
			)
			const s = this.bookingSearch.trim().toLowerCase()
			if (s) {
				txs = txs.filter(t =>
					(t.counterparty || '').toLowerCase().includes(s) ||
					(t.purpose || '').toLowerCase().includes(s) ||
					(t.bookingDate || '').includes(s),
				)
			}
			return txs
		},
		// Verknüpft Journal-Zeilen mit ihrer bankstämmigen Buchung, damit in
		// "Alle Buchungen" direkt eine Zuordnungsregel angelegt werden kann
		// (nur zugeordnete Umsätze mit Zahlungspartner und Zielkonto).
		txByJournalId() {
			const map = {}
			for (const t of this.transactions) {
				if (t.journalId && t.status === 'assigned' && t.counterparty && t.contraAccountId) {
					map[t.journalId] = t
				}
			}
			return map
		},
		filteredJournalRows() {
			let rows = this.sortedJournalRows
			const s = this.bookingSearch.trim().toLowerCase()
			if (s) {
				rows = rows.filter(r =>
					(r.description || '').toLowerCase().includes(s) ||
					String(r.entryNo || '').includes(s) ||
					(r.soll || '').toLowerCase().includes(s) ||
					(r.haben || '').toLowerCase().includes(s),
				)
			}
			if (this.bookingFilterAccountId) {
				rows = rows.filter(r =>
					r.debitAccountId === this.bookingFilterAccountId ||
					r.creditAccountId === this.bookingFilterAccountId,
				)
			}
			return rows
		},
		recentJournal() {
			return this.sortedJournalRows.slice(0, 5)
		},
		monthlyChartData() {
			const labels = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez']
			const income = new Array(12).fill(0)
			const expense = new Array(12).fill(0)
			for (const item of this.journalData) {
				const date = item.journal && item.journal.date
				if (!date) continue
				const m = parseInt(String(date).slice(5, 7), 10) - 1
				if (m < 0 || m > 11) continue
				for (const line of (item.lines || [])) {
					const acc = this.accountsById[line.accountId]
					// Erfolgswirksam wie im Backend (Account::isResultRelevant):
					// alle Nicht-Geldkonten außer Eigenkapital, netto nach Kontonatur.
					if (!acc || acc.isBank || acc.type === 'equity') continue
					if (['income', 'liability'].includes(acc.type)) income[m] += (line.creditCents - line.debitCents) / 100
					else expense[m] += (line.debitCents - line.creditCents) / 100
				}
			}
			return { labels, income, expense }
		},
		currentTree() {
			return this.accountSearch.trim() ? this.filteredVisibleTree : this.visibleTree
		},
		filteredVisibleTree() {
			const s = this.accountSearch.trim().toLowerCase()
			if (!s) return this.visibleTree
			const matchingIds = new Set(
				this.accounts
					.filter(a => a.name.toLowerCase().includes(s) || String(a.number).includes(s))
					.map(a => a.id),
			)
			const addAncestors = (id) => {
				const acc = this.accountsById[id]
				if (acc && acc.parentId && !matchingIds.has(acc.parentId)) {
					matchingIds.add(acc.parentId)
					addAncestors(acc.parentId)
				}
			}
			for (const id of [...matchingIds]) addAncestors(id)
			const byNum = list => list.slice().sort((a, b) => String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
			const out = []
			const walk = (acc, depth) => {
				const kids = (this.childrenOf[acc.id] || []).filter(k => matchingIds.has(k.id))
				out.push({ id: acc.id, number: acc.number, name: acc.name, type: acc.type, depth, hasChildren: kids.length > 0 })
				for (const k of byNum(kids)) walk(k, depth + 1)
			}
			for (const r of byNum(this.accounts.filter(a => !a.parentId && matchingIds.has(a.id)))) walk(r, 0)
			return out
		},
		bookingFilterAccountOption: {
			get() {
				if (!this.bookingFilterAccountId) return null
				return this.accountOptionsList.find(o => o.id === this.bookingFilterAccountId) ?? null
			},
			set(v) { this.bookingFilterAccountId = v ? v.id : null },
		},
		accountsById() {
			const map = {}
			for (const acc of this.accounts) map[acc.id] = acc
			return map
		},
		accountsSorted() {
			return this.accounts.slice().sort((a, b) => String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
		},
		parentOptions() {
			return this.accountsSorted.filter(a => a.id !== this.accountEditId)
		},
		accountsByCategory() {
			const groups = {}
			for (const acc of this.accountsSorted) {
				if (!acc.active) continue
				const cat = acc.category || 'Sonstige'
				if (!groups[cat]) groups[cat] = []
				groups[cat].push(acc)
			}
			return groups
		},
		accountUsageCounts() {
			const counts = {}
			for (const item of this.journalData) {
				for (const l of (item.lines || [])) {
					counts[l.accountId] = (counts[l.accountId] || 0) + 1
				}
			}
			return counts
		},
		frequentAccounts() {
			const counts = this.accountUsageCounts
			return this.accounts
				.filter(a => a.active && counts[a.id])
				.sort((a, b) => counts[b.id] - counts[a.id])
				.slice(0, 5)
		},
		accountOptionsList() {
			const opts = []
			if (this.frequentAccounts.length >= 2) {
				opts.push({ id: null, label: '★ Häufig verwendet', $isDisabled: true })
				for (const acc of this.frequentAccounts) {
					opts.push({ id: acc.id, label: `${acc.number} ${acc.name}`, number: acc.number })
				}
			}
			for (const [cat, accounts] of Object.entries(this.accountsByCategory)) {
				opts.push({ id: null, label: cat, $isDisabled: true })
				for (const acc of accounts) {
					opts.push({ id: acc.id, label: `${acc.number} ${acc.name}`, number: acc.number })
				}
			}
			return opts
		},
		// Optionen für den Einfach-Modus des Buchungsdialogs
		simpleCategoryOptions() {
			const type = this.bookingForm.kind === 'income' ? 'income' : 'expense'
			const counts = this.accountUsageCounts
			return this.accounts
				.filter(a => a.active && a.type === type)
				.sort((a, b) => (counts[b.id] || 0) - (counts[a.id] || 0)
					|| String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
				.map(a => ({ id: a.id, label: `${a.number} ${a.name}`, number: a.number }))
		},
		moneyAccountOptions() {
			return this.accounts
				.filter(a => a.active && (a.isBank || a.type === 'asset'))
				.sort((a, b) => (b.isBank ? 1 : 0) - (a.isBank ? 1 : 0)
					|| String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
				.map(a => ({ id: a.id, label: `${a.number} ${a.name}`, number: a.number }))
		},
		defaultMoneyAccountId() {
			// Nur echte Geldkonten automatisch vorauswählen – sonst könnte z.B. ein
			// Durchlaufkonto unbemerkt zum Standard-Geldkonto werden.
			const bank = this.accounts.find(a => a.active && a.isBank)
			return bank ? bank.id : null
		},
		bookingFormCategoryOption: {
			get() {
				if (this.bookingForm.categoryId == null) return null
				return this.simpleCategoryOptions.find(o => o.id === this.bookingForm.categoryId) ?? null
			},
			set(v) { this.bookingForm.categoryId = v ? v.id : null },
		},
		bookingFormMoneyOption: {
			get() {
				if (this.bookingForm.moneyAccountId == null) return null
				return this.moneyAccountOptions.find(o => o.id === this.bookingForm.moneyAccountId) ?? null
			},
			set(v) { this.bookingForm.moneyAccountId = v ? v.id : null },
		},
		bookingModeExpert: {
			get() { return this.bookingMode === 'expert' },
			set(v) { this.setBookingMode(v ? 'expert' : 'simple') },
		},
		// Frühere Zuordnungen je Zahlungspartner (für Vorschläge)
		assignmentHistory() {
			const map = {}
			for (const tx of this.transactions) {
				if (tx.status === 'assigned' && tx.contraAccountId && tx.counterparty) {
					const key = tx.counterparty.trim().toLowerCase()
					if (!map[key]) map[key] = {}
					map[key][tx.contraAccountId] = (map[key][tx.contraAccountId] || 0) + 1
				}
			}
			return map
		},
		// Zuordnungs-Vorschlag je offener Bankbuchung (Regeln zuerst, dann Historie)
		suggestionsById() {
			const out = {}
			for (const tx of this.transactions) {
				if (tx.status === 'assigned') continue
				const s = this.computeSuggestion(tx)
				if (s) out[tx.id] = s
			}
			return out
		},
		assignProgress() {
			const total = this.transactions.length
			const done = this.transactions.filter(t => t.status === 'assigned').length
			return { total, done, pct: total ? Math.round((done / total) * 100) : 0 }
		},
		// Vorjahresvergleich für die KPI-Kacheln (nur bei gewähltem Jahr)
		kpiDeltas() {
			if (!this.balances || !this.prevBalances || !this.selectedYear) return null
			const mk = key => {
				const cur = this.balances.totals[key]
				const prev = this.prevBalances.totals[key]
				if (!prev || Math.abs(prev) < 0.005) return null
				const pct = Math.round(((cur - prev) / Math.abs(prev)) * 100)
				return { pct, up: pct >= 0, text: (pct >= 0 ? '+' : '') + pct + ' % ggü. ' + (this.selectedYear - 1) }
			}
			return { income: mk('income'), expense: mk('expense'), result: mk('result') }
		},
		bookingFormDebitOption: {
			get() {
				if (this.bookingForm.debitAccountId == null) return null
				return this.accountOptionsList.find(o => o.id === this.bookingForm.debitAccountId) ?? null
			},
			set(v) { this.bookingForm.debitAccountId = v ? v.id : null },
		},
		bookingFormCreditOption: {
			get() {
				if (this.bookingForm.creditAccountId == null) return null
				return this.accountOptionsList.find(o => o.id === this.bookingForm.creditAccountId) ?? null
			},
			set(v) { this.bookingForm.creditAccountId = v ? v.id : null },
		},
		accountParentOptions() {
			return [
				{ id: null, label: '– kein Überkonto –' },
				...this.parentOptions.map(a => ({ id: a.id, label: `${a.number} ${a.name}`, number: a.number })),
			]
		},
		accountParentOption: {
			get() { return this.accountParentOptions.find(o => o.id === this.newAccount.parentId) ?? null },
			set(v) { this.newAccount.parentId = v ? v.id : null },
		},
		groupOptions() { return this.groups.map(g => ({ id: g.id, label: g.displayName })) },
		userOptions() { return this.users.map(u => ({ id: u.id, label: `${u.displayName} (${u.id})` })) },
		permFormPrincipalOption: {
			get() {
				const list = this.permForm.principalType === 'group' ? this.groupOptions : this.userOptions
				return list.find(o => o.id === this.permForm.principalId) ?? null
			},
			set(v) { this.permForm.principalId = v ? v.id : '' },
		},
		confirmDialogButtonList() {
			return [
				{ label: 'Abbrechen', type: 'secondary', callback: () => this.closeConfirm(false) },
				{ label: this.confirmDialog.confirmLabel, type: this.confirmDialog.confirmVariant, callback: () => this.closeConfirm(true) },
			]
		},
		childrenOf() {
			const map = {}
			for (const acc of this.accounts) {
				if (acc.parentId) (map[acc.parentId] = map[acc.parentId] || []).push(acc)
			}
			return map
		},
		visibleTree() {
			const byNum = list => list.slice().sort((a, b) => String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
			const roots = byNum(this.accounts.filter(a => !a.parentId))
			const out = []
			const walk = (acc, depth) => {
				const kids = this.childrenOf[acc.id] || []
				out.push({ id: acc.id, number: acc.number, name: acc.name, type: acc.type, depth, hasChildren: kids.length > 0 })
				if (kids.length && this.expanded[acc.id]) for (const k of byNum(kids)) walk(k, depth + 1)
			}
			for (const r of roots) walk(r, 0)
			return out
		},
		selectedAccount() {
			return this.selectedAccountId ? this.accountsById[this.selectedAccountId] : null
		},
		primaryBank() {
			const list = this.balances && this.balances.bankReconciliation
			return list && list.length ? list[0] : null
		},
		journalRows() {
			return this.journalData.map(item => {
				const j = item.journal
				const lines = item.lines || []
				const dl = lines.filter(l => l.debitCents > 0)
				const cl = lines.filter(l => l.creditCents > 0)
				return {
					id: j.id, entryNo: j.entryNo, date: j.date, description: j.description, documentRef: j.documentRef,
					soll: dl.map(l => this.accountLabel(l.accountId)).join(', '),
					haben: cl.map(l => this.accountLabel(l.accountId)).join(', '),
					debitAccountId: dl.length ? dl[0].accountId : null,
					creditAccountId: cl.length ? cl[0].accountId : null,
					isSplit: dl.length > 1 || cl.length > 1,
					amount: lines.reduce((s, l) => s + (l.debitCents || 0), 0) / 100,
					updatedAt: j.updatedAt || null,
				}
			})
		},
		statementRows() {
			if (!this.statement) return []
			const isCredit = ['income', 'liability', 'equity'].includes(this.statement.account.type)
			let run = this.statement.carry || 0
			return this.statement.rows.map(r => {
				run += isCredit ? (r.credit - r.debit) : (r.debit - r.credit)
				return { ...r, saldo: run }
			})
		},
		selectedCC() {
			if (this.selectedCCCode === false || !this.reportData) return null
			return this.reportData.costCenters.find(c => c.code === this.selectedCCCode) || null
		},
		// Hierarchie-Tiefe je Konto (für die Einrückung in der Saldenliste)
		accountDepth() {
			const out = {}
			for (const a of this.accounts) {
				let depth = 0
				let cur = a
				const seen = new Set([a.id])
				while (cur && cur.parentId != null && this.accountsById[cur.parentId] && !seen.has(cur.parentId) && depth < 8) {
					cur = this.accountsById[cur.parentId]
					seen.add(cur.id)
					depth++
				}
				out[a.id] = depth
			}
			return out
		},
		balanceRows() {
			const base = this.balances ? this.balances.accounts : []
			const enrich = r => ({
				...r,
				depth: this.accountDepth[r.accountId] || 0,
				isParent: (this.childrenOf[r.accountId] || []).length > 0,
			})
			if (!this.balancesIncludeChildren) return base.map(enrich)
			const rowById = {}
			for (const r of base) rowById[r.accountId] = r
			const agg = id => {
				const r = rowById[id]
				let debit = r ? r.debit : 0
				let credit = r ? r.credit : 0
				// Saldo aus den Backend-Werten summieren statt aus der Bewegung
				// nachzurechnen: für Geldkonten ist der Backend-Saldo der kumulierte
				// Kontostand, der bei reiner Soll/Haben-Summe verloren ginge.
				let balance = r ? r.balance : 0
				for (const child of (this.childrenOf[id] || [])) {
					const sub = agg(child.id)
					debit += sub.debit; credit += sub.credit; balance += sub.balance
				}
				return { debit, credit, balance }
			}
			return base.map(r => {
				const a = agg(r.accountId)
				return { ...enrich(r), debit: a.debit, credit: a.credit, balance: a.balance }
			})
		},
		sortedBalances() { return this.applySort(this.balanceRows, this.sort.balances, ['number']) },
		sortedJournalRows() { return this.applySort(this.journalRows, this.sort.journal) },
	},
	watch: {
		activeTab(tab) {
			this.loadTab(tab)
			if (tab === 'dashboard') this.$nextTick(() => this.renderDashboardCharts())
			// Einblend-Animation der Sektion neu starten
			this.sectionFade = false
			this.$nextTick(() => requestAnimationFrame(() => { this.sectionFade = true }))
		},
		journalData() {
			if (this.activeTab === 'dashboard') this.$nextTick(() => this.renderMonthlyChart())
		},
		bookingView(v) {
			this.bookingSearch = ''
			if (v === 'journal') this.loadJournal()
		},
		reportView(v) {
			if (v === 'summary') this.loadBalances()
			else if (v === 'costcenters') this.loadReport()
			else if (v === 'budget') this.loadBudget()
		},
		async selectedYear() {
			// Jahresbezogene Caches invalidieren
			this.ccBookings = {}
			this.ccExpanded = {}
			this.busy = true
			try {
				const jobs = [this.loadBalances(), this.loadJournal()]
				const tab = this.activeTab
				if (tab === 'accounts') {
					jobs.push(this.loadAccounts())
					if (this.selectedAccountId) jobs.push(this.loadStatement(this.selectedAccountId))
				} else if (tab === 'reports') {
					if (this.reportView === 'costcenters') jobs.push(this.loadReport())
					else if (this.reportView === 'budget') jobs.push(this.loadBudget())
				}
				await Promise.all(jobs)
			} finally { this.busy = false }
		},
	},
	async mounted() {
		document.addEventListener('keydown', this.onGlobalKeydown)
		await this.loadMe()
		if (this.canRead) {
			await this.loadYears()
			await Promise.all([
				this.loadAccounts(),
				this.loadImports(),
				this.loadBalances(),
				this.loadJournal(),
				this.loadTransactions(),
				this.loadRules(),
			])
			this.$nextTick(() => setTimeout(() => this.renderDashboardCharts(), 50))
			// Kollaboration: Änderungen anderer Personen per Polling mitbekommen
			this.checkRevision(true)
			this.syncTimer = setInterval(() => this.checkRevision(), 20000)
			window.addEventListener('focus', this.onWindowFocus)
		}
	},
	beforeDestroy() {
		document.removeEventListener('keydown', this.onGlobalKeydown)
		if (this.syncTimer) clearInterval(this.syncTimer)
		window.removeEventListener('focus', this.onWindowFocus)
		Object.values(this.chartInstances).forEach(c => c && c.destroy())
	},
	methods: {
		// --- Tastaturkürzel: N = neue Buchung, / = Suche fokussieren ---
		onGlobalKeydown(e) {
			if (e.ctrlKey || e.metaKey || e.altKey) return
			const tag = (e.target.tagName || '').toLowerCase()
			if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) return
			if (this.showBooking || this.showAccount || this.showImport || this.showSettings || this.confirmDialog.open) return
			if ((e.key === 'n' || e.key === 'N') && this.canWrite) {
				e.preventDefault()
				this.openNewBooking()
			} else if (e.key === '/') {
				e.preventDefault()
				if (this.activeTab === 'accounts') {
					this.$el.querySelector('.vbh-treesearch input')?.focus()
				} else {
					this.activeTab = 'bookings'
					this.$nextTick(() => this.$el.querySelector('.vbh-filterbar input[type=search]')?.focus())
				}
			}
		},
		async loadTab(tab) {
			const jobs = []
			if (tab === 'bookings' && this.bookingView === 'journal') jobs.push(this.loadJournal())
			else if (tab === 'accounts') { jobs.push(this.loadAccounts(), this.loadBalances()) }
			else if (tab === 'reports') {
				if (this.reportView === 'summary') jobs.push(this.loadBalances())
				else if (this.reportView === 'costcenters') jobs.push(this.loadReport())
				else if (this.reportView === 'budget') jobs.push(this.loadBudget())
			}
			if (!jobs.length) return
			this.busy = true
			try { await Promise.all(jobs) } finally { this.busy = false }
		},
		goToUnassigned() {
			this.activeTab = 'bookings'
			this.bookingView = 'unassigned'
		},
		// --- Kollaboration: Änderungen anderer Browser erkennen -------------
		onWindowFocus() { this.checkRevision() },
		async checkRevision(init = false) {
			if (!this.canRead) return
			if (!init && document.hidden) return
			let rev
			try { rev = (await api.revision()).data.revision } catch (e) { return }
			if (init || this.syncRevision === null) { this.syncRevision = rev; return }
			if (rev === this.syncRevision) return
			// Import o. Ä. läuft gerade – Stand nicht übernehmen, nächster Poll holt nach.
			if (this.busy) return
			this.syncRevision = rev
			// Nach eigener Schreibaktion still aktualisieren (die Handler haben schon
			// nachgeladen, aber eine zeitgleiche Fremdänderung darf nicht verloren gehen).
			const ownWrite = Date.now() - api.lastWriteAt() < 15000
			await this.refreshAfterRemoteChange()
			if (!ownWrite) showInfo('Die Buchhaltung wurde von einer anderen Person geändert – Ansicht aktualisiert.')
		},
		async refreshAfterRemoteChange() {
			this.ccBookings = {}
			this.ccExpanded = {}
			const jobs = [this.loadYears(), this.loadAccounts(), this.loadBalances(), this.loadJournal(), this.loadTransactions()]
			if (this.activeTab === 'accounts' && this.selectedAccountId) jobs.push(this.loadStatement(this.selectedAccountId))
			if (this.activeTab === 'reports') {
				if (this.reportView === 'costcenters') jobs.push(this.loadReport())
				else if (this.reportView === 'budget') jobs.push(this.loadBudget())
			}
			try { await Promise.all(jobs) } catch (e) { /* nächster Poll versucht es erneut */ }
		},
		openSettings() {
			this.showSettings = true
			this.loadImports()
			if (this.isAdmin) {
				this.loadPermissions()
				this.loadStorageSettings()
			}
		},
		async loadStorageSettings() {
			try {
				const { data } = await api.getSettings()
				this.storageUser = data.storage_user || ''
				this.storagePath = data.storage_path || 'Vereinsbuchhaltung/Belege'
				this.costCenterMode = data.cost_center_mode || 'group'
			} catch (e) { /* ignorieren */ }
		},
		async saveStorageSettings() {
			this.storageSaving = true
			try {
				await api.saveSettings({ storage_user: this.storageUser, storage_path: this.storagePath || 'Vereinsbuchhaltung/Belege', cost_center_mode: this.costCenterMode })
				showSuccess('Einstellungen gespeichert.')
				this.reportData = null
			} catch (e) {
				const msg = (e?.response?.data?.message) || `Speichern fehlgeschlagen (HTTP ${e?.response?.status ?? 'Netzwerkfehler'})`
				showError(msg)
			} finally { this.storageSaving = false }
		},
		async loadYears() {
			try {
				const { data } = await api.journalYears()
				this.years = data
				if (this.selectedYear === null && data.length) this.selectedYear = data[0]
			} catch (e) { /* Jahre optional */ }
		},
		emptyBookingForm() {
			return { id: null, entryNo: null, date: new Date().toISOString().slice(0, 10), documentRef: '', amount: null, debitAccountId: null, creditAccountId: null, description: '', kind: 'expense', moneyAccountId: null, categoryId: null, updatedAt: null }
		},
		// --- Einfach-Modus: Einnahme/Ausgabe <-> Soll/Haben ---
		deriveSimpleAccounts() {
			const f = this.bookingForm
			if (!f.categoryId || !f.moneyAccountId) return null
			// Einnahme: Soll Geldkonto / Haben Ertragskonto — Ausgabe: Soll Aufwandskonto / Haben Geldkonto
			return f.kind === 'income'
				? { debit: f.moneyAccountId, credit: f.categoryId }
				: { debit: f.categoryId, credit: f.moneyAccountId }
		},
		mapToSimple(debitId, creditId) {
			const d = this.accountsById[debitId]
			const c = this.accountsById[creditId]
			if (!d || !c) return null
			const isMoney = a => a.isBank || a.type === 'asset'
			if (isMoney(d) && c.type === 'income') return { kind: 'income', moneyAccountId: d.id, categoryId: c.id }
			if (d.type === 'expense' && isMoney(c)) return { kind: 'expense', moneyAccountId: c.id, categoryId: d.id }
			return null
		},
		setBookingKind(kind) {
			if (this.bookingForm.kind === kind) return
			this.bookingForm.kind = kind
			this.bookingForm.categoryId = null
		},
		setBookingMode(mode) {
			if (mode === this.bookingMode) return
			if (mode === 'expert') {
				const d = this.deriveSimpleAccounts()
				if (d) { this.bookingForm.debitAccountId = d.debit; this.bookingForm.creditAccountId = d.credit }
			} else {
				const m = this.mapToSimple(this.bookingForm.debitAccountId, this.bookingForm.creditAccountId)
				if (m) Object.assign(this.bookingForm, m)
			}
			this.bookingMode = mode
		},
		// Formatier-/Label-Helfer aus ./lib/format.js (zustandslos, als Methoden
		// eingebunden, damit das Template sie unveraendert aufrufen kann).
		formatMoney,
		formatDate,
		formatDateTime,
		typeLabel,
		amountClass,
		budgetDiffClass,
		accountLabel(id) {
			const acc = this.accountsById[id]
			return acc ? `${acc.number} ${acc.name}` : `#${id}`
		},
		balanceFor(accountId) {
			if (!this.balances) return 0
			const row = this.balances.accounts.find(a => a.accountId === accountId)
			return row ? row.balance : 0
		},
		accountOptionFor(id) {
			return id ? (this.accountOptionsList.find(o => o.id === id) ?? null) : null
		},
		/**
		 * Suchfilter für Konto-Dropdowns: Ziffern-Eingabe filtert als Präfix
		 * der Kontonummer (Leerzeichen werden ignoriert, z.B. "11101" trifft
		 * "111 01"), Text-Eingabe sucht wie gewohnt im gesamten Label.
		 */
		accountFilterBy(option, label, search) {
			const s = String(search || '').trim().toLowerCase()
			if (!s) return true
			// Kategorie-Überschriften während der Suche ausblenden
			if (option && option.$isDisabled) return false
			if (/^[\d\s]+$/.test(s)) {
				const digits = s.replace(/\s+/g, '')
				const num = String((option && option.number) || '').replace(/\s+/g, '').toLowerCase()
				return num.startsWith(digits)
			}
			return String(label || '').toLowerCase().includes(s)
		},

		// --- Confirm-Dialog ---
		askConfirm(title, message, confirmLabel = 'Löschen', confirmVariant = 'error') {
			return new Promise(resolve => {
				this.confirmDialog = { open: true, title, message, confirmLabel, confirmVariant, resolve }
			})
		},
		closeConfirm(result) {
			this.confirmDialog.open = false
			this.confirmDialog.resolve?.(result)
		},

		// --- Sortierung ---
		toggleSort(table, key) {
			const s = this.sort[table]
			if (s.key === key) s.dir = s.dir === 'asc' ? 'desc' : 'asc'
			else { s.key = key; s.dir = 'asc' }
		},
		sortArrow(table, key) {
			const s = this.sort[table]
			return s.key !== key ? '' : (s.dir === 'asc' ? ' ▲' : ' ▼')
		},
		applySort(rows, state, lexKeys = []) {
			if (!state || !state.key) return rows
			const f = state.dir === 'asc' ? 1 : -1
			const lex = lexKeys.includes(state.key)
			return rows.slice().sort((a, b) => {
				let x = a[state.key]; let y = b[state.key]
				if (x === null || x === undefined) x = ''
				if (y === null || y === undefined) y = ''
				if (lex) {
					const sx = String(x); const sy = String(y)
					return (sx < sy ? -1 : sx > sy ? 1 : 0) * f
				}
				if (typeof x === 'number' && typeof y === 'number') return (x - y) * f
				return String(x).localeCompare(String(y), 'de', { numeric: true, sensitivity: 'base' }) * f
			})
		},

		// --- Baum ---
		toggleExpand(id) { this.$set(this.expanded, id, !this.expanded[id]) },
		expandAll() { const e = {}; for (const acc of this.accounts) if ((this.childrenOf[acc.id] || []).length) e[acc.id] = true; this.expanded = e },
		collapseAll() { this.expanded = {} },
		async selectAccount(node) {
			this.selectedAccountId = node.id
			this.statementIncludeChildren = true
			await this.loadStatement(node.id)
		},
		async reloadStatement() { if (this.selectedAccountId) await this.loadStatement(this.selectedAccountId) },
		async loadStatement(accountId) {
			try { const { data } = await api.accountJournal(accountId, this.statementIncludeChildren, this.selectedYear); this.statement = data } catch (e) { showError(this.errMsg(e, 'Kontoauszug konnte nicht geladen werden')) }
		},

		// --- CSV-Import ---
		openImport() {
			this.showImport = true
			this.importDone = null
			this.previewResult = null
			this.selectedFile = null
		},
		closeImport() {
			this.showImport = false
			this.importDragging = false
		},
		onImportDrop(e) {
			this.importDragging = false
			const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]
			if (f) { this.selectedFile = f; this.previewResult = null; this.preview() }
		},
		goAssignAfterImport() {
			this.closeImport()
			this.activeTab = 'bookings'
			this.bookingView = 'unassigned'
		},
		onFileSelected(e) { this.selectedFile = e.target.files[0] || null; this.previewResult = null; if (this.selectedFile) this.preview() },
		async preview() {
			if (!this.selectedFile) return
			this.busy = true
			try { const fd = new FormData(); fd.append('file', this.selectedFile); const { data } = await api.previewImport(fd); this.previewResult = data } catch (e) { showError(this.errMsg(e, 'Vorschau fehlgeschlagen')) } finally { this.busy = false }
		},
		async commit() {
			if (!this.selectedFile) return
			this.busy = true
			try {
				const fd = new FormData(); fd.append('file', this.selectedFile); fd.append('applyRules', this.applyRules ? '1' : '0')
				const { data } = await api.commitImport(fd)
				showSuccess(`${data.new} Buchungen importiert (${data.autoAssigned} automatisch zugeordnet).`)
				this.importDone = data
				this.previewResult = null; this.selectedFile = null
				if (this.$refs.fileInput) this.$refs.fileInput.value = ''
				await this.loadImports(); await this.loadBalances(); await this.loadTransactions()
			} catch (e) { showError(this.errMsg(e, 'Import fehlgeschlagen')) } finally { this.busy = false }
		},
		async loadImports() { try { const { data } = await api.listImports(); this.imports = data } catch (e) { /* still */ } },

		// --- xbuc ---
		onXbucSelected(e) { this.xbucFile = e.target.files[0] || null; this.xbucPreviewResult = null; this.xbucYear = null; if (this.xbucFile) this.xbucPreview() },
		xbucYearParam() {
			const y = Number(this.xbucYear)
			return Number.isInteger(y) && y >= 2000 && y <= 2099 ? y : null
		},
		async xbucPreview() {
			if (!this.xbucFile) return
			this.busy = true
			try {
				const fd = new FormData(); fd.append('file', this.xbucFile)
				const year = this.xbucYearParam()
				if (year) fd.append('year', String(year))
				const { data } = await api.previewXbuc(fd)
				this.xbucPreviewResult = data
				// Effektives Geschäftsjahr ins Eingabefeld übernehmen
				this.xbucYear = data.year || this.xbucYear
				// Standard: Ausreißer auf das Geschäftsjahr datieren
				this.xbucClampDates = (data.outsideYear || 0) > 0
			} catch (e) { showError(this.errMsg(e, 'Vorschau fehlgeschlagen')) } finally { this.busy = false }
		},
		async xbucImport() {
			if (!this.xbucFile) return
			if (this.xbucReset && !await this.askConfirm('xbuc Import', 'Alle vorhandenen Daten werden gelöscht und ersetzt. Fortfahren?', 'Importieren', 'primary')) return
			this.busy = true
			try {
				const fd = new FormData(); fd.append('file', this.xbucFile); fd.append('reset', this.xbucReset ? '1' : '0'); fd.append('clampDates', this.xbucClampDates ? '1' : '0')
				const importYear = this.xbucYearParam()
				if (importYear) fd.append('year', String(importYear))
				const { data } = await api.commitXbuc(fd)
				const skippedMsg = data.skipped > 0 ? `, ${data.skipped} übersprungen (bereits vorhanden)` : ''
				const newAccMsg = data.accountsNew > 0 ? `, ${data.accountsNew} neue Konten` : ''
				const clampMsg = data.clamped > 0 ? `, ${data.clamped} auf das Geschäftsjahr ${data.year} datiert` : ''
				const openMsg = data.openingsSkipped > 0 ? `, ${data.openingsSkipped} Anfangsbestände übersprungen (über Vorjahressalden abgedeckt)` : ''
				const openTxMsg = data.openBankTx > 0 ? `, ${data.openBankTx} ohne Gegenkonto → offen (Tab „Zuzuordnen")` : ''
				const removedMsg = data.openingsRemoved > 0 ? `, ${data.openingsRemoved} überflüssige Eröffnungsbuchung(en) aus ${data.transitionYear} entfernt` : ''
				showSuccess(`${data.bookings} Buchungen importiert${openTxMsg}${skippedMsg}${newAccMsg}${clampMsg}${openMsg}${removedMsg}.`)
				for (const m of (data.openingMismatches || [])) {
					showError(`Achtung: Anfangsbestand ${m.account} laut Datei ${this.formatMoney(m.fileAmount)}, Vorjahres-Endstand in der App ${this.formatMoney(m.priorBalance)} – bitte Vorjahresbuchungen prüfen.`, { timeout: -1 })
				}
				this.xbucPreviewResult = null; this.xbucFile = null
				if (this.$refs.xbucInput) this.$refs.xbucInput.value = ''
				await this.loadYears(); await this.loadAccounts(); await this.loadBalances(); await this.loadImports(); await this.loadJournal(); await this.loadTransactions()
			} catch (e) { showError(this.errMsg(e, 'Import fehlgeschlagen')) } finally { this.busy = false }
		},
		async resetAll() {
			if (!await this.askConfirm('Alle Daten löschen', 'Wirklich ALLE Konten, Buchungen und Importe löschen?')) return
			this.busy = true
			try {
				await api.reset(); showSuccess('Alle Daten gelöscht.')
				this.selectedAccountId = null; this.statement = null; this.journalData = []; this.transactions = []
				this.selectedYear = null
				await this.loadYears(); await this.loadAccounts(); await this.loadBalances(); await this.loadImports()
			} catch (e) { showError(this.errMsg(e, 'Zurücksetzen fehlgeschlagen')) } finally { this.busy = false }
		},

		// --- Bankbuchungen ---
		async loadTransactions() { try { const { data } = await api.listTransactions(''); this.transactions = data } catch (e) { showError(this.errMsg(e, 'Buchungen konnten nicht geladen werden')) } },
		async loadRules() { try { const { data } = await api.listRules(); this.rules = data } catch (e) { /* Regeln optional */ } },
		async onAssign(tx, value) {
			const prevContra = tx.contraAccountId
			try {
				if (value === '') {
					await api.unassignTransaction(tx.id)
					if (prevContra) {
						showUndo('Zuordnung entfernt', async () => {
							try {
								await api.assignTransaction(tx.id, prevContra)
								await this.loadTransactions(); await this.loadBalances(); await this.loadJournal()
							} catch (e) { showError(this.errMsg(e, 'Wiederherstellen fehlgeschlagen')) }
						})
					}
				} else {
					await api.assignTransaction(tx.id, Number(value))
				}
				await this.loadTransactions(); await this.loadBalances(); await this.loadJournal()
			} catch (e) { showError(this.errMsg(e, 'Zuordnung fehlgeschlagen')) }
		},
		// Vorschlag: passende Regel, sonst häufigste frühere Zuordnung desselben Zahlungspartners
		computeSuggestion(tx) {
			for (const rule of this.rules) {
				const haystack = { counterparty: tx.counterparty, purpose: tx.purpose, iban: tx.iban }[rule.matchField]
				if (haystack && rule.matchValue && haystack.toLowerCase().includes(rule.matchValue.toLowerCase())) {
					const acc = this.accountsById[rule.contraAccountId]
					if (acc && acc.active) return { accountId: acc.id, label: `${acc.number} ${acc.name}` }
				}
			}
			if (tx.counterparty) {
				const hist = this.assignmentHistory[tx.counterparty.trim().toLowerCase()]
				if (hist) {
					const best = Object.entries(hist).sort((a, b) => b[1] - a[1])[0]
					const acc = this.accountsById[Number(best[0])]
					if (acc && acc.active) return { accountId: acc.id, label: `${acc.number} ${acc.name}` }
				}
			}
			return null
		},
		applySuggestion(tx) {
			const s = this.suggestionsById[tx.id]
			if (s) this.onAssign(tx, s.accountId)
		},
		async createRuleFromTx(tx) {
			if (!tx.counterparty || !tx.contraAccountId) return
			const value = tx.counterparty.trim()
			const exists = this.rules.some(r => r.matchField === 'counterparty' && r.matchValue.toLowerCase() === value.toLowerCase())
			if (exists) { showSuccess('Für diesen Zahlungspartner existiert bereits eine Regel.'); return }
			try {
				await api.createRule({ matchField: 'counterparty', matchValue: value, contraAccountId: tx.contraAccountId })
				await this.loadRules()
				showSuccess(`Regel angelegt: „${value}" wird künftig automatisch ${this.accountLabel(tx.contraAccountId)} zugeordnet.`)
			} catch (e) { showError(this.errMsg(e, 'Regel konnte nicht angelegt werden')) }
		},

		// --- Journal ---
		async loadJournal() {
			try { const { data } = await api.journal(this.selectedYear); this.journalData = data } catch (e) { showError(this.errMsg(e, 'Journal konnte nicht geladen werden')) }
			this.loadAttachmentCounts()
		},
		async loadAttachmentCounts() {
			try { const { data } = await api.attachmentCounts(); this.attachmentCountMap = data } catch (e) { /* ignorieren */ }
		},
		async loadAttachments(journalId) {
			if (!journalId) { this.bookingAttachments = []; return }
			try { const { data } = await api.listAttachments(journalId); this.bookingAttachments = data } catch (e) { this.bookingAttachments = [] }
		},
		async uploadAttachment(event) {
			const files = event.target.files
			if (!files || !files.length || !this.bookingForm.id) return
			this.attachmentUploading = true
			try {
				for (const file of Array.from(files)) {
					const fd = new FormData()
					fd.append('file', file)
					await api.uploadAttachment(this.bookingForm.id, fd)
				}
				await this.loadAttachments(this.bookingForm.id)
				this.loadAttachmentCounts()
			} catch (e) { showError(this.errMsg(e, 'Upload fehlgeschlagen')) }
			finally { this.attachmentUploading = false; event.target.value = '' }
		},
		async deleteAttachment(id) {
			if (!await this.askConfirm('Beleg löschen', 'Diesen Beleg wirklich unwiderruflich löschen?')) return
			try {
				await api.deleteAttachment(id)
				await this.loadAttachments(this.bookingForm.id)
				this.loadAttachmentCounts()
			} catch (e) { showError(this.errMsg(e, 'Beleg konnte nicht gelöscht werden')) }
		},
		attachmentDownloadUrl(id) { return api.attachmentDownloadUrl(id) },
		openViewer(attachment) {
			if (attachment.ncPath && window.OCA?.Viewer) {
				OCA.Viewer.open({ path: attachment.ncPath })
			} else {
				window.open(api.attachmentViewUrl(attachment.id), '_blank')
			}
		},
		clickPaperclip(r) {
			// Splittbuchungen haben kein Bearbeiten-Modal → Beleg direkt öffnen
			if (r.isSplit || this.attachmentCountMap[r.id]?.count === 1) this.openQuickViewer(r)
			else this.editBooking(r)
		},
		async openQuickViewer(r) {
			try {
				const { data } = await api.listAttachments(r.id)
				if (data.length) this.openViewer(data[0])
			} catch (e) { this.editBooking(r) }
		},
		formatFileSize(bytes) {
			if (bytes < 1024) return bytes + ' B'
			if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
			return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
		},
		openNewBooking() {
			this.bookingAttachments = []
			this.bookingForm = this.emptyBookingForm()
			this.bookingForm.moneyAccountId = this.defaultMoneyAccountId
			this.showBooking = true
		},
		editBooking(r) {
			if (r.isSplit) {
				showError('Splittbuchung (mehrere Soll-/Haben-Zeilen) – Bearbeitung würde Zeilen verwerfen und wird daher nicht unterstützt.')
				return
			}
			this.bookingForm = { ...this.emptyBookingForm(), id: r.id, entryNo: r.entryNo, date: r.date, documentRef: r.documentRef || '', amount: r.amount, debitAccountId: r.debitAccountId, creditAccountId: r.creditAccountId, description: r.description || '', updatedAt: r.updatedAt || null }
			const m = this.mapToSimple(r.debitAccountId, r.creditAccountId)
			if (m) {
				Object.assign(this.bookingForm, m)
				this.bookingMode = 'simple'
			} else {
				this.bookingMode = 'expert'
			}
			this.loadAttachments(r.id)
			this.showBooking = true
		},
		closeBooking() { this.showBooking = false; this.bookingForm = this.emptyBookingForm(); this.bookingAttachments = [] },
		async saveBooking() {
			const f = this.bookingForm
			if (this.bookingMode === 'simple') {
				if (!f.date || !f.amount || !f.categoryId || !f.moneyAccountId) { showError('Datum, Betrag, Kategorie und Geldkonto sind Pflicht.'); return }
				if (f.categoryId === f.moneyAccountId) { showError('Kategorie und Geldkonto müssen unterschiedlich sein.'); return }
				const d = this.deriveSimpleAccounts()
				f.debitAccountId = d.debit
				f.creditAccountId = d.credit
			}
			if (!f.date || !f.debitAccountId || !f.creditAccountId || !f.amount) { showError('Datum, Soll, Haben und Betrag sind Pflicht.'); return }
			if (f.debitAccountId === f.creditAccountId) { showError('Soll- und Habenkonto müssen unterschiedlich sein.'); return }
			const payload = { date: f.date, description: f.description, documentRef: f.documentRef || null, debitAccountId: f.debitAccountId, creditAccountId: f.creditAccountId, amount: Number(f.amount) }
			try {
				if (f.id) await api.updateBooking(f.id, { ...payload, updatedAt: f.updatedAt || null })
				else await api.createBooking(payload)
				showSuccess('Buchung gespeichert.')
				this.closeBooking()
				await this.loadJournal(); await this.loadBalances(); await this.loadYears()
			} catch (e) {
				if (e?.response?.status === 409) {
					showError('Diese Buchung wurde zwischenzeitlich von einer anderen Person geändert. Die Ansicht wurde aktualisiert – bitte erneut bearbeiten.')
					this.closeBooking()
					await this.loadJournal(); await this.loadBalances()
					return
				}
				showError(this.errMsg(e, 'Buchung konnte nicht gespeichert werden'))
			}
		},
		async removeBooking(r) {
			if (!await this.askConfirm('Buchung löschen', `Buchung #${r.entryNo} löschen?`)) return
			try { await api.deleteBooking(r.id); await this.loadJournal(); await this.loadBalances() } catch (e) { showError(this.errMsg(e, 'Löschen fehlgeschlagen')) }
		},

		// --- Konten ---
		async loadAccounts() {
			try {
				const { data } = await api.listAccounts()
				this.accounts = data
				const form = {}
				for (const acc of data) form[acc.id] = { amount: acc.openingBalance || 0, date: acc.openingDate || '' }
				this.openingForm = form
			} catch (e) { showError(this.errMsg(e, 'Konten konnten nicht geladen werden')) }
		},
		async seedAccounts() { try { await api.seedAccounts(); await this.loadAccounts(); showSuccess('Standard-Kontenrahmen angelegt.') } catch (e) { showError(this.errMsg(e, 'Anlegen fehlgeschlagen')) } },
		openNewAccount() {
			this.accountEditId = null
			const parent = this.selectedAccount
			this.newAccount = {
				number: '', name: '',
				type: parent ? parent.type : 'income',
				category: parent ? (parent.category || '') : '',
				isBank: false,
				parentId: this.selectedAccountId || null,
			}
			this.showAccount = true
		},
		openEditAccount(acc) {
			this.accountEditId = acc.id
			this.newAccount = {
				number: acc.number, name: acc.name, type: acc.type,
				category: acc.category || '', isBank: !!acc.isBank,
				parentId: acc.parentId || null,
			}
			this.showAccount = true
		},
		closeAccount() { this.showAccount = false; this.accountEditId = null },
		async saveAccount() {
			if (!this.newAccount.number || !this.newAccount.name) { showError('Nummer und Bezeichnung sind Pflicht.'); return }
			const f = this.newAccount
			try {
				if (this.accountEditId) {
					await api.updateAccount(this.accountEditId, {
						number: f.number, name: f.name, type: f.type,
						category: f.category || null, isBank: f.isBank,
						parentId: f.parentId || 0,
					})
				} else {
					await api.createAccount({ ...f, parentId: f.parentId || null })
				}
				this.showAccount = false
				this.accountEditId = null
				this.newAccount = { number: '', name: '', type: 'income', category: '', isBank: false, parentId: null }
				await this.loadAccounts(); await this.loadBalances()
				showSuccess('Konto gespeichert.')
			} catch (e) { showError(this.errMsg(e, 'Konto konnte nicht gespeichert werden')) }
		},
		async deleteAccount(acc) {
			if (!await this.askConfirm('Konto löschen', `Konto "${acc.number} ${acc.name}" löschen?`)) return
			try {
				await api.deleteAccount(acc.id)
				if (this.selectedAccountId === acc.id) { this.selectedAccountId = null; this.statement = null }
				await this.loadAccounts(); await this.loadBalances()
			} catch (e) { showError(this.errMsg(e, 'Löschen fehlgeschlagen')) }
		},
		async saveOpening(acc) {
			const form = this.openingForm[acc.id] || { amount: 0, date: '' }
			try {
				await api.setOpening(acc.id, Number(form.amount) || 0, form.date || null)
				await this.loadAccounts(); await this.loadBalances()
				if (this.selectedAccountId === acc.id) await this.loadStatement(acc.id)
				showSuccess(`Eröffnungssaldo für ${acc.name} gespeichert.`)
			} catch (e) { showError(this.errMsg(e, 'Eröffnungssaldo konnte nicht gespeichert werden')) }
		},

		// --- Auswertung ---
		async loadBalances() {
			try { const { data } = await api.balances(this.selectedYear); this.balances = data } catch (e) { showError(this.errMsg(e, 'Auswertung konnte nicht geladen werden')) }
			// Vorjahr für den KPI-Vergleich (still im Hintergrund, Fehler ignorieren)
			if (this.selectedYear) {
				try { const { data } = await api.balances(this.selectedYear - 1); this.prevBalances = data } catch (e) { this.prevBalances = null }
			} else {
				this.prevBalances = null
			}
		},

		// --- Berichte / Kostenstellen ---
		async loadReport() {
			try {
				const { data } = await api.costCenterReport(this.selectedYear)
				this.reportData = data
				if (this.selectedCCCode !== false && !data.costCenters.some(c => c.code === this.selectedCCCode)) this.selectedCCCode = false
			} catch (e) { showError(this.errMsg(e, 'Bericht konnte nicht geladen werden')) }
		},
		selectCC(cc) { this.selectedCCCode = cc.code; this.renameName = cc.name; this.ccExpanded = {} },
		isCCSelected(cc) { return this.selectedCCCode !== false && cc.code === this.selectedCCCode },
		async toggleCCAccount(accountId) {
			if (!accountId) return
			const open = !this.ccExpanded[accountId]
			this.$set(this.ccExpanded, accountId, open)
			if (open && !this.ccBookings[accountId]) {
				try { const { data } = await api.accountJournal(accountId, false, this.selectedYear); this.$set(this.ccBookings, accountId, data.rows) } catch (e) { showError(this.errMsg(e, 'Buchungen konnten nicht geladen werden')) }
			}
		},
		async saveRename() {
			const cc = this.selectedCC
			if (!cc || !cc.code) return
			try { await api.renameCostCenter(cc.code, this.renameName); await this.loadReport(); showSuccess('Kostenstelle umbenannt.') } catch (e) { showError(this.errMsg(e, 'Umbenennen fehlgeschlagen')) }
		},

		// --- Finanzplan / Budget ---
		async loadBudget() {
			try {
				const { data } = await api.budget(this.selectedYear)
				this.budgetData = data
				await this.loadBudgetSnapshots()
			} catch (e) { showError(this.errMsg(e, 'Finanzplan konnte nicht geladen werden')) }
		},
		async saveBudget(row) {
			if (!this.budgetData) return
			try {
				await api.setBudget(row.accountId, this.budgetData.year, Number(row.plan) || 0, (row.note || '').trim())
				await this.loadBudget()
			} catch (e) { showError(this.errMsg(e, 'Planwert konnte nicht gespeichert werden')) }
		},
		toggleBudgetNote(row) {
			this.$set(this.budgetNoteOpen, row.accountId, !this.budgetNoteOpen[row.accountId])
		},

		// --- Finanzplan-Stände (Snapshots) ---
		async loadBudgetSnapshots() {
			try {
				const { data } = await api.budgetSnapshots(this.selectedYear)
				this.budgetSnapshots = data
			} catch (e) { showError(this.errMsg(e, 'Plan-Stände konnten nicht geladen werden')) }
		},
		async saveBudgetSnapshot() {
			if (!this.budgetData) return
			const label = this.newSnapshotLabel.trim()
			try {
				await api.createBudgetSnapshot(this.budgetData.year, label)
				this.newSnapshotLabel = ''
				await this.loadBudgetSnapshots()
				showSuccess('Plan-Stand gespeichert.')
			} catch (e) { showError(this.errMsg(e, 'Plan-Stand konnte nicht gespeichert werden')) }
		},
		async openSnapshot(snap) {
			try {
				const { data } = await api.budgetSnapshot(snap.id)
				this.snapshotView = { open: true, data }
			} catch (e) { showError(this.errMsg(e, 'Plan-Stand konnte nicht geladen werden')) }
		},
		closeSnapshot() { this.snapshotView = { open: false, data: null } },
		async deleteBudgetSnapshot(snap) {
			if (!await this.askConfirm('Plan-Stand löschen', `Stand „${snap.label}" wirklich löschen?`)) return
			try {
				await api.deleteBudgetSnapshot(snap.id)
				await this.loadBudgetSnapshots()
				showSuccess('Plan-Stand gelöscht.')
			} catch (e) { showError(this.errMsg(e, 'Plan-Stand konnte nicht gelöscht werden')) }
		},
		/** Planwert eines Kontos im aktuellen Plan (für Vergleich im Stand-Detail). */
		currentPlanForAccount(accountId) {
			const row = this.budgetData && this.budgetData.rows.find(r => r.accountId === accountId)
			return row ? row.plan : 0
		},
		addBudgetYear() {
			const y = parseInt(this.newBudgetYear, 10)
			if (!y || y < 2000 || y > 2099) return
			this.newBudgetYear = ''
			if (!this.years.includes(y)) {
				this.years = [y, ...this.years].sort((a, b) => b - a)
			}
			this.selectedYear = y
		},

		// --- Berechtigungen ---
		async loadMe() {
			try {
				const { data } = await api.me()
				this.me = data
				if (!this.visibleTabs.some(t => t.id === this.activeTab)) {
					this.activeTab = this.visibleTabs.length ? this.visibleTabs[0].id : 'dashboard'
				}
			} catch (e) {
				this.me = { role: 'none', canRead: false, canWrite: false, isAdmin: false }
			}
		},
		async loadPermissions() {
			try {
				const [p, g, u] = await Promise.all([api.listPermissions(), api.listGroups(), api.listUsers()])
				this.permissions = p.data
				this.groups = g.data
				this.users = u.data
			} catch (e) { showError(this.errMsg(e, 'Berechtigungen konnten nicht geladen werden')) }
		},
		async savePermission() {
			if (!this.permForm.principalId) { showError('Bitte Nutzer oder Gruppe angeben.'); return }
			try {
				await api.setPermission(this.permForm)
				this.permForm = { principalType: 'group', principalId: '', role: 'revisor' }
				await this.loadPermissions()
				showSuccess('Berechtigung gespeichert.')
			} catch (e) { showError(this.errMsg(e, 'Speichern fehlgeschlagen')) }
		},
		async removePermission(p) {
			if (!await this.askConfirm('Berechtigung entfernen', `Berechtigung für "${p.principalId}" entfernen?`)) return
			try { await api.deletePermission(p.id); await this.loadPermissions() } catch (e) { showError(this.errMsg(e, 'Entfernen fehlgeschlagen')) }
		},
		roleLabel,

		errMsg,

		// --- Charts ---
		destroyChart(key) {
			if (this.chartInstances[key]) {
				this.chartInstances[key].destroy()
				this.$set(this.chartInstances, key, null)
			}
		},
		renderDashboardCharts() {
			this.renderMonthlyChart()
		},
		renderMonthlyChart() {
			const canvas = this.$refs.monthlyChart
			if (!canvas) return
			this.destroyChart('monthly')
			const { labels, income, expense } = this.monthlyChartData
			const isDark = document.documentElement.classList.contains('theme--dark')
			const textColor = isDark ? 'rgba(255,255,255,0.8)' : 'rgba(0,0,0,0.7)'
			const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.08)'
			this.$set(this.chartInstances, 'monthly', new Chart(canvas, {
				type: 'bar',
				data: {
					labels,
					datasets: [
						{
							label: 'Einnahmen',
							data: income,
							backgroundColor: 'rgba(45,125,70,0.72)',
							borderColor: 'rgba(45,125,70,0.9)',
							borderWidth: 1,
							borderRadius: 4,
						},
						{
							label: 'Ausgaben',
							data: expense,
							backgroundColor: 'rgba(199,60,60,0.72)',
							borderColor: 'rgba(199,60,60,0.9)',
							borderWidth: 1,
							borderRadius: 4,
						},
					],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: { labels: { color: textColor, font: { size: 12 } } },
						tooltip: {
							callbacks: {
								label: ctx => ` ${ctx.dataset.label}: ${new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(ctx.raw)}`,
							},
						},
					},
					scales: {
						x: {
							ticks: { color: textColor },
							grid: { color: gridColor },
						},
						y: {
							ticks: {
								color: textColor,
								callback: v => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(v),
							},
							grid: { color: gridColor },
						},
					},
				},
			}))
		},
	},
}
</script>

<style scoped>
/* Nur noch Regeln, die per ::v-deep in NcButton-Internas eingreifen und daher
   scoped bleiben MUESSEN, damit sie nicht in Nextclouds eigene .button-vue
   (Header/Sidebar) lecken. Alle .vbh-*-Utilities liegen global in styles.css. */
::v-deep .button-vue { display: inline-flex !important; }
::v-deep .button-vue__icon { display: flex !important; align-items: center; justify-content: center; }
::v-deep .button-vue__icon svg { display: block !important; }
</style>
