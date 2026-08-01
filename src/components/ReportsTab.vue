<template>
	<div style="display: contents;">
		<div class="vbh-sectiontop">
			<div class="vbh-subtabs">
				<button :class="{ active: reportView === 'summary' }" @click="$emit('update:report-view', 'summary')">
					Auswertung
				</button>
				<button :class="{ active: reportView === 'costcenters' }" @click="$emit('update:report-view', 'costcenters')">
					Kostenstellen
				</button>
				<button :class="{ active: reportView === 'spheres' }" @click="$emit('update:report-view', 'spheres')">
					Sphären
				</button>
				<button :class="{ active: reportView === 'reserves' }" @click="$emit('update:report-view', 'reserves')">
					Rücklagen
				</button>
				<button :class="{ active: reportView === 'budget' }" @click="$emit('update:report-view', 'budget')">
					Finanzplan
				</button>
				<button :class="{ active: reportView === 'audit' }" @click="$emit('update:report-view', 'audit')">
					Protokoll
				</button>
			</div>
			<div class="vbh-sectiontop-actions">
				<a v-if="reportView === 'summary' && selectedYear"
					:href="kassenberichtUrl"
					target="_blank"
					rel="noopener"
					class="vbh-export-btn"
					title="Druckfertiger Kassenbericht für die Mitgliederversammlung (öffnet in neuem Tab, dort drucken oder als PDF speichern)"><NcIconSvgWrapper :path="mdiPrinter" :size="16" inline /> Kassenbericht</a>
				<span v-if="reportView === 'summary'" class="vbh-kurzbericht-picker">
					<input v-model="kurzberichtSince"
						type="date"
						class="vbh-kurzbericht-date"
						title="Kurzbericht: Bewegungen seit diesem Datum">
					<a :href="kurzberichtUrl"
						target="_blank"
						rel="noopener"
						class="vbh-export-btn"
						title="Kurzbericht für die nächste Vorstandssitzung (öffnet in neuem Tab, dort drucken oder als PDF speichern)"><NcIconSvgWrapper :path="mdiPrinter" :size="16" inline /> Kurzbericht</a>
				</span>
				<a v-if="reportView === 'summary' && selectedYear"
					:href="attachmentsZipUrl"
					download
					class="vbh-export-btn"
					title="Alle Belege des Jahres als ZIP herunterladen (für die Kassenprüfung)"><NcIconSvgWrapper :path="mdiPaperclip" :size="16" inline /> Beleg-ZIP</a>
				<a v-if="reportView === 'summary'"
					:href="pruefleitfadenUrl"
					target="_blank"
					rel="noopener"
					class="vbh-export-btn"
					title="Druckfertige 1-Seiten-Kurzanleitung für Kassenprüfer/innen (öffnet in neuem Tab)"><NcIconSvgWrapper :path="mdiPrinter" :size="16" inline /> Prüfleitfaden</a>
				<a v-if="reportView === 'summary'"
					:href="exportBalancesUrl"
					download
					class="vbh-export-btn"
					title="Saldenliste als CSV exportieren"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> Saldenliste</a>
				<a v-if="reportView === 'summary'"
					:href="exportReportUrl"
					download
					class="vbh-export-btn"
					title="E/A-Übersicht als CSV exportieren"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> E/A-Übersicht</a>
				<a v-if="reportView === 'summary'"
					:href="exportMultiyearUrl"
					download
					class="vbh-export-btn"
					title="Mehrjahresübersicht (alle Jahre) als CSV exportieren"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> Mehrjahresübersicht</a>
				<a v-if="reportView === 'budget'"
					:href="exportBudgetUrl"
					download
					class="vbh-export-btn"
					title="Soll-Ist-Vergleich als CSV exportieren"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> Soll-Ist-Vergleich</a>
			</div>
		</div>

		<div class="vbh-sectionbody" :class="{ 'is-split': reportView === 'costcenters' || reportView === 'spheres' }">
			<!-- AUSWERTUNG -->
			<div v-show="reportView === 'summary'">
				<div v-if="balances" class="vbh-totals">
					<div class="vbh-total pos">
						<span>Einnahmen</span><strong>{{ formatMoney(balances.totals.income) }}</strong>
					</div>
					<div class="vbh-total neg">
						<span>Ausgaben</span><strong>{{ formatMoney(balances.totals.expense) }}</strong>
					</div>
					<div class="vbh-total" :class="balances.totals.result >= 0 ? 'pos' : 'neg'">
						<span>Ergebnis</span><strong>{{ formatMoney(balances.totals.result) }}</strong>
					</div>
				</div>

				<div v-if="trendChartData.labels.length" class="vbh-chart-grid">
					<div class="vbh-chart-card vbh-chart-card--wide">
						<h4>Mehrjahres-Trend (Einnahmen, Ausgaben, Ergebnis)</h4>
						<div class="vbh-chart-wrap">
							<canvas ref="trendChart" />
						</div>
					</div>
				</div>

				<template v-if="balances && balances.bankReconciliation && balances.bankReconciliation.length">
					<h4>Geldkonten</h4>
					<div v-if="!isMobile" class="vbh-tablecard">
						<table class="vbh-table">
							<thead>
								<tr>
									<th>Konto</th><th class="num">
										Kontostand
									</th><th class="num">
										Offen (nicht zugeordnet)
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="b in balances.bankReconciliation" :key="b.accountId">
									<td>{{ b.number }} {{ b.name }}</td>
									<td class="num strong">
										{{ formatMoney(b.balance) }}
									</td>
									<td class="num" :class="Math.abs(b.open) > 0.005 ? 'neg' : 'pos'">
										{{ formatMoney(b.open) }}
									</td>
								</tr>
							</tbody>
						</table>
					</div>
					<div v-else class="vbh-cardlist">
						<div v-for="b in balances.bankReconciliation" :key="'m' + b.accountId" class="vbh-mcard">
							<div class="vbh-mcard-top">
								<span class="vbh-mcard-title">{{ b.number }} {{ b.name }}</span>
								<span class="vbh-mcard-amount">{{ formatMoney(b.balance) }}</span>
							</div>
							<div v-if="Math.abs(b.open) > 0.005" class="vbh-mcard-bottom">
								<span class="vbh-mcard-accounts">{{ formatMoney(b.open) }} nicht zugeordnet</span>
							</div>
						</div>
					</div>
				</template>

				<div class="vbh-sectionhead">
					<h4>Saldenliste</h4>
					<NcCheckboxRadioSwitch v-model="balancesIncludeChildren">
						Werte inkl. Unterkonten
					</NcCheckboxRadioSwitch>
				</div>
				<div v-if="balances && isMobile" class="vbh-cardlist">
					<div v-for="row in sortedBalances"
						:key="'m' + row.accountId"
						class="vbh-mcard"
						:class="{ 'vbh-mcard--parent': row.isParent }"
						:style="row.depth ? { marginLeft: (Math.min(row.depth, 3) * 14) + 'px' } : null">
						<div class="vbh-mcard-top">
							<span class="vbh-mcard-title">{{ row.number }} {{ row.name }}</span>
							<span class="vbh-mcard-amount" :class="amountClass(row.balance)">{{ formatMoney(row.balance) }}</span>
						</div>
						<div class="vbh-mcard-bottom">
							<span class="vbh-mcard-accounts">{{ row.category || typeLabel(row.type) }} · Soll {{ formatMoney(row.debit) }} · Haben {{ formatMoney(row.credit) }}</span>
						</div>
					</div>
				</div>
				<div v-else-if="balances" class="vbh-tablecard">
					<table class="vbh-table">
						<thead>
							<tr>
								<th class="sortable nowrap vbh-col-hide-sm" @click="toggleSort('balances','number')">
									Nr.{{ sortArrow('balances','number') }}
								</th>
								<th class="sortable" @click="toggleSort('balances','name')">
									Konto{{ sortArrow('balances','name') }}
								</th>
								<th class="sortable vbh-col-hide-sm" @click="toggleSort('balances','category')">
									Kategorie{{ sortArrow('balances','category') }}
								</th>
								<th class="sortable num vbh-col-hide-sm" @click="toggleSort('balances','debit')">
									Soll{{ sortArrow('balances','debit') }}
								</th>
								<th class="sortable num vbh-col-hide-sm" @click="toggleSort('balances','credit')">
									Haben{{ sortArrow('balances','credit') }}
								</th>
								<th class="sortable num" @click="toggleSort('balances','balance')">
									Saldo{{ sortArrow('balances','balance') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in sortedBalances" :key="row.accountId" :class="{ 'vbh-parentrow': row.isParent }">
								<td class="nowrap vbh-col-hide-sm">
									{{ row.number }}
								</td>
								<td :style="{ paddingLeft: (10 + (row.depth || 0) * 18) + 'px' }">
									<span v-if="row.depth" class="vbh-treeglyph">└</span>{{ row.name }}
								</td>
								<td class="vbh-col-hide-sm">
									{{ row.category }}
								</td>
								<td class="num vbh-col-hide-sm">
									{{ formatMoney(row.debit) }}
								</td>
								<td class="num vbh-col-hide-sm">
									{{ formatMoney(row.credit) }}
								</td>
								<td class="num strong">
									{{ formatMoney(row.balance) }}
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- KOSTENSTELLEN (split layout; mobil Drilldown) -->
			<div v-show="reportView === 'costcenters'" class="vbh-splitinner" :class="{ 'vbh-drill': isMobile }">
				<div v-if="!isMobile || !selectedCC" class="vbh-tree">
					<div class="vbh-treehead">
						<strong>Kostenstellen</strong>
					</div>
					<div v-if="reportData" class="vbh-ccsummary">
						<span>Gesamtergebnis</span>
						<strong :class="amountClass(reportData.totals.result)">{{ formatMoney(reportData.totals.result) }}</strong>
					</div>
					<div v-if="reportData" class="vbh-treelist">
						<div v-for="cc in reportData.costCenters"
							:key="cc.code === null ? 'none' : cc.code"
							class="vbh-treenode"
							:class="{ selected: isCCSelected(cc) }"
							@click="selectCC(cc)">
							<span class="vbh-treenum">{{ cc.code || '–' }}</span>
							<span class="vbh-treename">{{ cc.name }}</span>
							<span class="vbh-treesaldo" :class="[amountClass(cc.result), { zero: !cc.result }]">{{ formatMoney(cc.result) }}</span>
						</div>
					</div>
					<p v-else class="vbh-hint">
						Keine Daten. Importiere oder erfasse zuerst Buchungen.
					</p>
				</div>

				<div v-if="!isMobile || selectedCC" class="vbh-detail">
					<div v-if="isMobile" class="vbh-backbar">
						<button type="button" class="vbh-backbtn" @click="$emit('update:selected-c-c-code', false)">
							‹ Kostenstellen
						</button>
					</div>
					<p v-if="!selectedCC" class="vbh-empty vbh-detailhint">
						Kostenstelle links auswählen.
					</p>
					<template v-else>
						<div class="vbh-detailhead">
							<div><h3>{{ selectedCC.code ? selectedCC.code + ' · ' : '' }}{{ selectedCC.name }}</h3></div>
						</div>

						<div v-if="canWrite && selectedCC.code && reportData && reportData.mode !== 'account'" class="vbh-opening">
							<span>Name:</span>
							<input :value="renameName" class="vbh-rename" @input="$emit('update:rename-name', $event.target.value)">
							<NcButton variant="primary" size="small" @click="saveRename">
								Umbenennen
							</NcButton>
						</div>

						<div class="vbh-totals">
							<div class="vbh-total pos">
								<span>Einnahmen</span><strong>{{ formatMoney(selectedCC.income) }}</strong>
							</div>
							<div class="vbh-total neg">
								<span>Ausgaben</span><strong>{{ formatMoney(selectedCC.expense) }}</strong>
							</div>
							<div class="vbh-total" :class="selectedCC.result >= 0 ? 'pos' : 'neg'">
								<span>Ergebnis</span><strong>{{ formatMoney(selectedCC.result) }}</strong>
							</div>
						</div>

						<h4>Beteiligte Konten <span class="vbh-hint">(Konto anklicken für Buchungen)</span></h4>
						<div v-if="selectedCC.accounts.length && isMobile" class="vbh-cardlist">
							<div v-for="(a, i) in selectedCC.accounts"
								:key="'m' + i"
								class="vbh-mcard tappable"
								role="button"
								tabindex="0"
								@click="toggleCCAccount(a.accountId)"
								@keyup.enter="toggleCCAccount(a.accountId)">
								<div class="vbh-mcard-top">
									<span class="vbh-mcard-title"><span class="vbh-caret" :class="{ open: ccExpanded[a.accountId] }">›</span> {{ a.number }} {{ a.name }}</span>
									<span class="vbh-mcard-amount" :class="amountClass(a.balance)">{{ formatMoney(a.balance) }}</span>
								</div>
								<div v-if="ccExpanded[a.accountId]" class="vbh-cclist" @click.stop>
									<template v-if="ccBookings[a.accountId] && ccBookings[a.accountId].length">
										<div v-for="(r, j) in ccBookings[a.accountId]" :key="j" class="vbh-ccbooking">
											<span class="vbh-mcard-meta">{{ formatDate(r.date) }}</span>
											<span class="vbh-ccbooking-desc">{{ r.description }}</span>
											<span class="vbh-ccbooking-amount">{{ r.debit ? formatMoney(r.debit) : formatMoney(r.credit) }}</span>
										</div>
									</template>
									<p v-else class="vbh-empty">
										Keine Buchungen.
									</p>
								</div>
							</div>
						</div>
						<div v-else-if="selectedCC.accounts.length" class="vbh-tablecard">
							<table class="vbh-table">
								<thead>
									<tr>
										<th class="nowrap">
											Nr.
										</th><th>Konto</th><th>Art</th><th class="num">
											Betrag
										</th>
									</tr>
								</thead>
								<tbody>
									<!-- :key gehoert auf die echten <tr>, nicht aufs <template>
									     (Vue 2 ignoriert Template-Keys stillschweigend, Muster wie beim Finanzplan unten) -->
									<template v-for="(a, i) in selectedCC.accounts">
										<tr :key="'cc-' + i" class="vbh-ccrow" @click="toggleCCAccount(a.accountId)">
											<td class="nowrap">
												<span class="vbh-caret" :class="{ open: ccExpanded[a.accountId] }">›</span> {{ a.number }}
											</td>
											<td>{{ a.name }}</td>
											<td><span class="vbh-typetag" :class="a.type">{{ typeLabel(a.type) }}</span></td>
											<td class="num" :class="amountClass(a.balance)">
												{{ formatMoney(a.balance) }}
											</td>
										</tr>
										<tr v-if="ccExpanded[a.accountId]" :key="'ccd-' + i" class="vbh-ccdetail">
											<td colspan="4">
												<table v-if="ccBookings[a.accountId] && ccBookings[a.accountId].length" class="vbh-table vbh-subtable">
													<thead>
														<tr>
															<th class="num vbh-col-hide-sm">
																Nr.
															</th><th class="nowrap">
																Datum
															</th><th>Beschreibung</th><th class="vbh-col-hide-sm">
																Gegenkonto
															</th><th class="num">
																Soll
															</th><th class="num">
																Haben
															</th>
														</tr>
													</thead>
													<tbody>
														<tr v-for="(r, j) in ccBookings[a.accountId]" :key="j">
															<td class="num vbh-col-hide-sm">
																{{ r.entryNo }}
															</td>
															<td class="nowrap">
																{{ formatDate(r.date) }}
															</td>
															<td class="vbh-purpose" :title="r.description">
																<span class="vbh-clamp">{{ r.description }}</span>
															</td>
															<td class="vbh-col-hide-sm">
																{{ r.contra }}
															</td>
															<td class="num">
																{{ r.debit ? formatMoney(r.debit) : '' }}
															</td>
															<td class="num">
																{{ r.credit ? formatMoney(r.credit) : '' }}
															</td>
														</tr>
													</tbody>
												</table>
												<p v-else class="vbh-empty">
													Keine Buchungen.
												</p>
											</td>
										</tr>
									</template>
								</tbody>
							</table>
						</div>
						<p v-else class="vbh-empty">
							Keine Buchungen mit Betrag in dieser Kostenstelle.
						</p>
					</template>
				</div>
			</div>

			<!-- SPHÄREN -->
			<div v-show="reportView === 'spheres'" class="vbh-splitinner" :class="{ 'vbh-drill': isMobile }">
				<div v-if="!isMobile || !selectedSphere" class="vbh-tree">
					<div class="vbh-treehead">
						<strong>Sphären</strong>
						<button type="button"
							class="vbh-sphere-help"
							title="Was bedeutet das?"
							@click="$emit('help', 'spheres')">
							?
						</button>
					</div>
					<div v-if="sphereData" class="vbh-ccsummary">
						<span>Gesamtergebnis</span>
						<strong :class="amountClass(sphereData.totals.result)">{{ formatMoney(sphereData.totals.result) }}</strong>
					</div>
					<div v-if="sphereData && sphereData.freigrenze.incomeCents > 0" class="vbh-freigrenzemini" :class="sphereData.freigrenze.level">
						Wirtschaftlicher Geschäftsbetrieb: {{ formatMoney(sphereData.freigrenze.income) }} von {{ formatMoney(sphereData.freigrenze.threshold) }}
						({{ Math.round(sphereData.freigrenze.ratio * 100) }} %)
					</div>
					<div v-if="sphereData" class="vbh-treelist">
						<div v-for="s in sphereData.spheres"
							:key="s.code || 'none'"
							class="vbh-treenode"
							:class="{ selected: isSphereSelected(s) }"
							@click="selectSphere(s)">
							<span class="vbh-treename">{{ s.name }}</span>
							<span class="vbh-treesaldo" :class="[amountClass(s.result), { zero: !s.result }]">{{ formatMoney(s.result) }}</span>
						</div>
					</div>
					<p v-else class="vbh-hint">
						Keine Daten. Importiere oder erfasse zuerst Buchungen.
					</p>
				</div>

				<div v-if="!isMobile || selectedSphere" class="vbh-detail">
					<div v-if="isMobile" class="vbh-backbar">
						<button type="button" class="vbh-backbtn" @click="$emit('update:selected-sphere-code', false)">
							‹ Sphären
						</button>
					</div>
					<p v-if="!selectedSphere" class="vbh-empty vbh-detailhint">
						Sphäre links auswählen.
					</p>
					<template v-else>
						<div class="vbh-detailhead">
							<div><h3>{{ selectedSphere.name }}</h3></div>
						</div>

						<div class="vbh-totals">
							<div class="vbh-total pos">
								<span>Einnahmen</span><strong>{{ formatMoney(selectedSphere.income) }}</strong>
							</div>
							<div class="vbh-total neg">
								<span>Ausgaben</span><strong>{{ formatMoney(selectedSphere.expense) }}</strong>
							</div>
							<div class="vbh-total" :class="selectedSphere.result >= 0 ? 'pos' : 'neg'">
								<span>Ergebnis</span><strong>{{ formatMoney(selectedSphere.result) }}</strong>
							</div>
						</div>

						<h4>Beteiligte Konten</h4>
						<div v-if="selectedSphere.accounts.length" class="vbh-tablecard">
							<table class="vbh-table">
								<thead>
									<tr>
										<th class="nowrap">
											Nr.
										</th><th>Konto</th><th>Art</th><th class="num">
											Betrag
										</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="a in selectedSphere.accounts" :key="a.accountId">
										<td class="nowrap">
											{{ a.number }}
										</td>
										<td>{{ a.name }}</td>
										<td><span class="vbh-typetag" :class="a.type">{{ typeLabel(a.type) }}</span></td>
										<td class="num" :class="amountClass(a.balance)">
											{{ formatMoney(a.balance) }}
										</td>
									</tr>
								</tbody>
							</table>
						</div>
						<p v-else class="vbh-empty">
							Keine Buchungen mit Betrag in dieser Sphäre.
						</p>
					</template>
				</div>
			</div>

			<!-- RÜCKLAGEN -->
			<div v-show="reportView === 'reserves'">
				<p class="vbh-hint">
					Rücklagen sind Eigenkapital-Konten mit festgelegter Rücklagen-Art (§ 62 AO). Zuweisungen
					erfolgen als normale Buchung (Experten-Modus im Buchungsdialog, Eigenkapital-zu-Eigenkapital-
					Umbuchung) – hier siehst du nur den aktuellen Stand je Art.
				</p>
				<div v-if="reserveData" class="vbh-totals">
					<div class="vbh-total" :class="reserveData.total >= 0 ? 'pos' : 'neg'">
						<span>Rücklagen gesamt</span><strong>{{ formatMoney(reserveData.total) }}</strong>
					</div>
				</div>
				<div v-if="reserveData && reserveData.reserves.some(r => r.accounts.length)" class="vbh-tablecard">
					<table class="vbh-table">
						<thead>
							<tr>
								<th>Rücklagen-Art</th><th class="num">
									Saldo
								</th>
							</tr>
						</thead>
						<tbody>
							<template v-for="r in reserveData.reserves">
								<tr :key="r.kind" class="vbh-parentrow">
									<td><strong>{{ r.name }}</strong></td>
									<td class="num strong" :class="amountClass(r.balance)">
										{{ formatMoney(r.balance) }}
									</td>
								</tr>
								<tr v-for="a in r.accounts" :key="r.kind + '-' + a.accountId">
									<td class="nowrap" style="padding-left: 24px;">
										{{ a.number }} {{ a.name }}
									</td>
									<td class="num">
										{{ formatMoney(a.balance) }}
									</td>
								</tr>
							</template>
						</tbody>
					</table>
				</div>
				<p v-else class="vbh-empty">
					Noch keinem Konto eine Rücklagen-Art zugewiesen. Im Konto-Dialog eines Eigenkapital-Kontos festlegen (Tab Konten).
				</p>
			</div>

			<!-- FINANZPLAN -->
			<div v-show="reportView === 'budget'">
				<div class="vbh-sectionhead">
					<h4>Finanzplan &amp; Soll-Ist-Vergleich{{ budgetData ? ' ' + budgetData.year : '' }}</h4>
					<form v-if="canWrite" class="vbh-addyear" @submit.prevent="addBudgetYear">
						<input v-model="newBudgetYear"
							type="number"
							min="2000"
							max="2099"
							placeholder="Jahr"
							class="vbh-addyear-input">
						<NcButton type="submit" variant="secondary">
							Jahr hinzufügen
						</NcButton>
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
								<th class="nowrap vbh-col-hide-sm">
									Nr.
								</th>
								<th>Konto</th>
								<th class="vbh-col-hide-sm">
									Art
								</th>
								<th class="num vbh-col-plan">
									Plan (Soll)
								</th>
								<th class="vbh-col-note" title="Notiz zur Planzahl" />
								<th class="num">
									Ist
								</th>
								<th class="num">
									Differenz
								</th>
							</tr>
						</thead>
						<tbody>
							<template v-for="row in budgetData.rows">
								<tr :key="row.accountId">
									<td class="nowrap vbh-col-hide-sm">
										{{ row.number }}
									</td>
									<td>{{ row.name }}</td>
									<td class="vbh-col-hide-sm">
										<span class="vbh-typetag" :class="row.type">{{ typeLabel(row.type) }}</span>
									</td>
									<td class="num vbh-col-plan">
										<input v-if="canWrite"
											v-model.number="row.plan"
											type="number"
											step="0.01"
											class="vbh-num vbh-planinput"
											@change="saveBudget(row)">
										<span v-else>{{ formatMoney(row.plan) }}</span>
									</td>
									<td class="vbh-col-note">
										<NcButton v-if="canWrite || row.note"
											variant="tertiary"
											:aria-label="row.note ? 'Notiz zur Planzahl anzeigen' : 'Notiz zur Planzahl hinzufügen'"
											:title="row.note || 'Notiz hinzufügen'"
											@click="toggleBudgetNote(row)">
											<template #icon>
												<NcIconSvgWrapper :path="row.note ? mdiCommentText : mdiCommentPlusOutline" :size="18" :class="{ 'vbh-noteicon--set': row.note }" />
											</template>
										</NcButton>
									</td>
									<td class="num strong" :class="amountClass(row.actual)">
										{{ formatMoney(row.actual) }}
									</td>
									<td class="num strong" :class="budgetDiffClass(row)">
										{{ formatMoney(row.diff) }}
									</td>
								</tr>
								<tr v-if="budgetNoteOpen[row.accountId]" :key="'note-' + row.accountId" class="vbh-note-row">
									<td colspan="7">
										<label class="vbh-note-label">Notiz zu {{ row.number }} {{ row.name }}
											<textarea v-if="canWrite"
												v-model="row.note"
												maxlength="1000"
												rows="2"
												class="vbh-note-textarea"
												placeholder="z. B. Herleitung: 40 Mitglieder × 25 € Beitrag"
												@change="saveBudget(row)" />
											<p v-else class="vbh-note-text">{{ row.note }}</p>
										</label>
									</td>
								</tr>
							</template>
						</tbody>
					</table>
				</div>
				<p v-else-if="budgetData" class="vbh-empty">
					Keine Einnahmen-/Ausgabenkonten vorhanden.
				</p>

				<!-- PLAN-STÄNDE (Snapshots) -->
				<div v-if="budgetData" class="vbh-snapblock">
					<div class="vbh-sectionhead">
						<h4>Plan-Stände {{ budgetData.year }}</h4>
						<form v-if="canWrite" class="vbh-addyear" @submit.prevent="saveBudgetSnapshot">
							<input v-model="newSnapshotLabel"
								type="text"
								maxlength="128"
								placeholder="z.B. Beschluss MV"
								class="vbh-snaplabel-input">
							<NcButton type="submit" variant="secondary">
								Aktuellen Plan speichern
							</NcButton>
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
									<th class="nowrap vbh-col-hide-sm">
										Gespeichert
									</th>
									<th class="num vbh-col-hide-sm">
										Einnahmen
									</th>
									<th class="num vbh-col-hide-sm">
										Ausgaben
									</th>
									<th class="num">
										Ergebnis
									</th>
									<th />
								</tr>
							</thead>
							<tbody>
								<tr v-for="snap in budgetSnapshots" :key="snap.id">
									<td><strong>{{ snap.label }}</strong></td>
									<td class="nowrap vbh-col-hide-sm">
										{{ formatDateTime(snap.createdAt) }}
									</td>
									<td class="num vbh-col-hide-sm">
										{{ formatMoney(snap.planIncome) }}
									</td>
									<td class="num vbh-col-hide-sm">
										{{ formatMoney(snap.planExpense) }}
									</td>
									<td class="num strong" :class="snap.planResult >= 0 ? 'good' : 'bad'">
										{{ formatMoney(snap.planResult) }}
									</td>
									<td class="right nowrap">
										<NcButton variant="tertiary" @click="openSnapshot(snap)">
											Ansehen
										</NcButton>
										<NcButton v-if="canWrite"
											variant="tertiary"
											title="Stand löschen"
											@click="deleteBudgetSnapshot(snap)">
											<template #icon>
												<NcIconSvgWrapper :path="mdiDelete" :size="18" />
											</template>
										</NcButton>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
					<p v-else class="vbh-empty">
						Noch keine Stände für dieses Jahr gespeichert.
					</p>
				</div>
			</div>

			<!-- ÄNDERUNGSPROTOKOLL -->
			<div v-show="reportView === 'audit'">
				<p class="vbh-hint">
					Wer hat wann was geändert – z. B. für die Kassenprüfung. Das Protokoll wird
					automatisch geführt und bleibt auch beim Zurücksetzen aller Daten erhalten.
				</p>
				<div v-if="auditEntries.length" class="vbh-tablecard">
					<table class="vbh-table">
						<thead>
							<tr>
								<th class="nowrap">
									Zeitpunkt
								</th><th>Wer</th><th>Aktion</th><th>Details</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="a in auditEntries" :key="a.id">
								<td class="nowrap">
									{{ formatDateTime(a.ts) }}
								</td>
								<td class="nowrap">
									{{ a.userId }}
								</td>
								<td class="nowrap">
									{{ a.action }}
								</td>
								<td class="vbh-purpose">
									<span class="vbh-clamp">{{ auditDetailText(a) }}</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<NcEmptyContent v-else-if="!auditLoading" name="Noch keine Protokolleinträge" description="Änderungen ab Version 0.10.41 werden hier aufgezeichnet.">
					<template #action>
						<NcButton variant="tertiary" @click="$emit('help', 'reports')">
							Mehr dazu
						</NcButton>
					</template>
				</NcEmptyContent>
				<div v-if="auditEntries.length && !auditEnd" class="vbh-loadmore">
					<NcButton variant="secondary" :disabled="auditLoading" @click="loadAudit(true)">
						Ältere Einträge laden
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { toRefs } from 'vue'
import {
	Chart,
	LineController,
	LineElement,
	PointElement,
	CategoryScale,
	LinearScale,
	Tooltip,
	Legend,
} from 'chart.js'
import { NcButton, NcCheckboxRadioSwitch, NcEmptyContent, NcIconSvgWrapper } from '@nextcloud/vue'
import { mdiPrinter, mdiPaperclip, mdiDownload, mdiDelete, mdiCommentText, mdiCommentPlusOutline } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../api.js'
import { formatMoney, formatDate, formatDateTime, typeLabel, amountClass, budgetDiffClass, roleLabel } from '../lib/format.js'
import { useAuth } from '../composables/useAuth.js'
import { useYears } from '../composables/useYears.js'
import { useAccounts } from '../composables/useAccounts.js'
import { useBalances } from '../composables/useBalances.js'
import { useConfirm } from '../composables/useConfirm.js'
import { useSort } from '../composables/useSort.js'

Chart.register(LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend)

export default {
	name: 'ReportsTab',
	components: { NcButton, NcCheckboxRadioSwitch, NcEmptyContent, NcIconSvgWrapper },
	props: {
		// steuert (zusammen mit reportView==='summary') den Chart-Redraw des
		// Mehrjahres-Trend-Diagramms, gleiches Muster wie DashboardTab.vue.
		isActive: { type: Boolean, required: true },
		isMobile: { type: Boolean, required: true },
		reportView: { type: String, required: true },
		// reportData/budgetData/budgetSnapshots/auditEntries/auditLoading/auditEnd
		// bleiben in App.vue (per reportView-/selectedYear-Watcher und
		// refreshAfterRemoteChange() geladen) - hier nur als Props gelesen.
		reportData: { type: Object, default: null },
		budgetData: { type: Object, default: null },
		budgetSnapshots: { type: Array, required: true },
		auditEntries: { type: Array, required: true },
		auditLoading: { type: Boolean, required: true },
		auditEnd: { type: Boolean, required: true },
		// selectedCCCode/selectedSphereCode/ccExpanded/ccBookings/renameName
		// bleiben in App.vue (Jahres-/Reload-Watcher setzen sie zurueck).
		selectedCCCode: { type: [String, Number, Boolean], default: false },
		selectedSphereCode: { type: [String, Number, Boolean], default: false },
		ccExpanded: { type: Object, required: true },
		ccBookings: { type: Object, required: true },
		renameName: { type: String, required: true },
		// sort wird per Referenz durchgereicht (auch vom Buchungen-Tab genutzt).
		selectCC: { type: Function, required: true },
		isCCSelected: { type: Function, required: true },
		toggleCCAccount: { type: Function, required: true },
		saveRename: { type: Function, required: true },
		selectSphere: { type: Function, required: true },
		isSphereSelected: { type: Function, required: true },
		loadAudit: { type: Function, required: true },
		// oeffnet ein Top-Level-Modal ausserhalb dieser Komponente (App.vue).
		openSnapshot: { type: Function, required: true },
	},
	setup() {
		const auth = useAuth()
		const years = useYears()
		const accounts = useAccounts()
		const balances = useBalances()
		const sorting = useSort()
		return {
			canWrite: auth.canWrite,
			...toRefs(years.state),
			...toRefs(accounts.state),
			accountsById: accounts.accountsById,
			childrenOf: accounts.childrenOf,
			...toRefs(balances.state),
			// Sortierung und Rueckfrage kommen aus dem gemeinsamen Zustand,
			// nicht mehr als Funktions-Props aus App.vue.
			sort: sorting.sort,
			toggleSort: sorting.toggleSort,
			sortArrow: sorting.sortArrow,
			applySort: sorting.applySort,
			askConfirm: useConfirm().askConfirm,
		}
	},
	data() {
		return {
			mdiPrinter,
			mdiPaperclip,
			mdiDownload,
			mdiDelete,
			mdiCommentText,
			mdiCommentPlusOutline,
			balancesIncludeChildren: false,
			chartInstances: {},
			multiyearTrendData: null,
			reserveData: null,
			kurzberichtSince: this.defaultKurzberichtSince(),
			newBudgetYear: '',
			budgetNoteOpen: {},
			newSnapshotLabel: '',
		}
	},
	computed: {
		kassenberichtUrl() { return api.kassenberichtUrl(this.selectedYear) },
		pruefleitfadenUrl() { return api.pruefleitfadenUrl() },
		attachmentsZipUrl() { return api.exportAttachmentsUrl(this.selectedYear) },
		exportBalancesUrl() { return api.exportBalancesUrl(this.selectedYear) },
		exportReportUrl() { return api.exportReportUrl(this.selectedYear) },
		exportMultiyearUrl() { return api.exportMultiyearUrl() },
		exportBudgetUrl() { return api.exportBudgetUrl(this.selectedYear) },
		selectedCC() {
			if (this.selectedCCCode === false || !this.reportData) return null
			return this.reportData.costCenters.find(c => c.code === this.selectedCCCode) || null
		},
		selectedSphere() {
			if (this.selectedSphereCode === false || !this.sphereData) return null
			return this.sphereData.spheres.find(s => s.code === this.selectedSphereCode) || null
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
		kurzberichtUrl() { return api.kurzberichtUrl(this.kurzberichtSince) },
		// steuert Laden+Redraw des Mehrjahres-Trend-Diagramms (nur in der
		// Auswertung sichtbar, und nur wenn der Berichte-Tab selbst aktiv ist).
		trendChartVisible() { return this.isActive && this.reportView === 'summary' },
		trendChartData() {
			const rows = (this.multiyearTrendData && this.multiyearTrendData.years) || []
			return {
				labels: rows.map(r => String(r.year)),
				income: rows.map(r => r.income),
				expense: rows.map(r => r.expense),
				result: rows.map(r => r.result),
			}
		},
	},
	watch: {
		trendChartVisible(v) {
			if (v) this.loadMultiyearTrend()
		},
		reportView(v) {
			if (v === 'reserves') this.loadReserveReport()
		},
		// Komfort: zuletzt gewaehltes "seit"-Datum geraetelokal merken (Muster
		// wie vbh_recent_accounts), reine UI-Vorbelegung, kein Pflichtfeld.
		kurzberichtSince(v) {
			try { localStorage.setItem('vbh_kurzbericht_since', v) } catch (e) { /* voll/gesperrt - dann eben ohne */ }
		},
	},
	mounted() {
		if (this.trendChartVisible) this.loadMultiyearTrend()
		if (this.reportView === 'reserves') this.loadReserveReport()
	},
	beforeDestroy() {
		Object.values(this.chartInstances).forEach(c => c && c.destroy())
	},
	methods: {
		formatMoney,
		formatDate,
		formatDateTime,
		typeLabel,
		amountClass,
		budgetDiffClass,
		roleLabel,
		errMsg(e, fallback) {
			return (e?.response?.data?.message) || fallback
		},
		// Vorbelegung fuer das Kurzbericht-"seit"-Feld: letztes gemerktes Datum,
		// sonst 30 Tage vor heute (typischer Sitzungsabstand).
		defaultKurzberichtSince() {
			try {
				const saved = localStorage.getItem('vbh_kurzbericht_since')
				if (saved) return saved
			} catch (e) { /* voll/gesperrt - dann eben Default */ }
			const d = new Date()
			d.setDate(d.getDate() - 30)
			return d.toISOString().slice(0, 10)
		},
		async loadReserveReport() {
			try { const { data } = await api.reserveReport(); this.reserveData = data } catch (e) { showError(this.errMsg(e, 'Rücklagen-Bericht konnte nicht geladen werden')) }
		},
		async loadMultiyearTrend() {
			try {
				const { data } = await api.multiyearTrend()
				this.multiyearTrendData = data
				this.$nextTick(() => setTimeout(() => this.renderTrendChart(), 50))
			} catch (e) { /* Diagramm ist eine Zusatzansicht, kein harter Fehler */ }
		},
		destroyChart(key) {
			if (this.chartInstances[key]) {
				this.chartInstances[key].destroy()
				this.$set(this.chartInstances, key, null)
			}
		},
		renderTrendChart() {
			const canvas = this.$refs.trendChart
			if (!canvas) return
			this.destroyChart('trend')
			const { labels, income, expense, result } = this.trendChartData
			if (!labels.length) return
			const isDark = document.documentElement.classList.contains('theme--dark')
			const textColor = isDark ? 'rgba(255,255,255,0.8)' : 'rgba(0,0,0,0.7)'
			const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.08)'
			const eur = v => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v)
			this.$set(this.chartInstances, 'trend', new Chart(canvas, {
				type: 'line',
				data: {
					labels,
					datasets: [
						{
							label: 'Einnahmen',
							data: income,
							borderColor: 'rgba(45,125,70,0.9)',
							backgroundColor: 'rgba(45,125,70,0.15)',
							tension: 0.2,
						},
						{
							label: 'Ausgaben',
							data: expense,
							borderColor: 'rgba(199,60,60,0.9)',
							backgroundColor: 'rgba(199,60,60,0.15)',
							tension: 0.2,
						},
						{
							label: 'Ergebnis',
							data: result,
							borderColor: 'rgba(70,100,199,0.9)',
							backgroundColor: 'rgba(70,100,199,0.15)',
							borderDash: [5, 4],
							tension: 0.2,
						},
					],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: { labels: { color: textColor, font: { size: 12 } } },
						tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: ${eur(ctx.raw)}` } },
					},
					scales: {
						x: { ticks: { color: textColor }, grid: { color: gridColor } },
						y: { ticks: { color: textColor, callback: v => eur(v) }, grid: { color: gridColor } },
					},
				},
			}))
		},
		auditDetailText(a) {
			if (!a.details) return ''
			const d = a.details
			const parts = []
			if (d.entryNo != null) parts.push('#' + d.entryNo)
			if (d.date) parts.push(this.formatDate(d.date))
			if (d.konto) parts.push(d.konto)
			if (d.contra) parts.push(d.contra)
			if (d.description) parts.push(d.description)
			if (d.fileName) parts.push(d.fileName)
			if (d.filename) parts.push(d.filename)
			if (d.wer) parts.push((d.typ === 'group' ? 'Gruppe ' : '') + d.wer + (d.rolle ? ' → ' + this.roleLabel(d.rolle) : ''))
			if (d.amount != null) parts.push(this.formatMoney(d.amount))
			if (d.jahr != null) parts.push('Jahr ' + d.jahr)
			if (d.buchungen != null) parts.push(d.buchungen + ' Buchungen')
			if (d.neu != null) parts.push(d.neu + ' neu')
			if (d.duplikate != null) parts.push(d.duplikate + ' Dubletten')
			if (d.reset) parts.push('mit Zurücksetzen')
			return parts.join(' · ')
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
		async saveBudget(row) {
			if (!this.budgetData) return
			try {
				await api.setBudget(row.accountId, this.budgetData.year, Number(row.plan) || 0, (row.note || '').trim())
				this.$emit('budget-changed')
			} catch (e) { showError(this.errMsg(e, 'Planwert konnte nicht gespeichert werden')) }
		},
		toggleBudgetNote(row) {
			this.$set(this.budgetNoteOpen, row.accountId, !this.budgetNoteOpen[row.accountId])
		},
		async saveBudgetSnapshot() {
			if (!this.budgetData) return
			const label = this.newSnapshotLabel.trim()
			try {
				await api.createBudgetSnapshot(this.budgetData.year, label)
				this.newSnapshotLabel = ''
				this.$emit('snapshots-changed')
				showSuccess('Plan-Stand gespeichert.')
			} catch (e) { showError(this.errMsg(e, 'Plan-Stand konnte nicht gespeichert werden')) }
		},
		async deleteBudgetSnapshot(snap) {
			if (!await this.askConfirm('Plan-Stand löschen', `Stand „${snap.label}" wirklich löschen?`)) return
			try {
				await api.deleteBudgetSnapshot(snap.id)
				this.$emit('snapshots-changed')
				showSuccess('Plan-Stand gelöscht.')
			} catch (e) { showError(this.errMsg(e, 'Plan-Stand konnte nicht gelöscht werden')) }
		},
	},
}
</script>
