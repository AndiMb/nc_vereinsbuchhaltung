<template>
	<div style="display: contents;">
		<div class="vbh-sectiontop">
			<div class="vbh-subtabs">
				<button :class="{ active: reportView === 'summary' }" @click="$emit('update:report-view', 'summary')">
					{{ t('Auswertung') }}
				</button>
				<button :class="{ active: reportView === 'costcenters' }" @click="$emit('update:report-view', 'costcenters')">
					{{ t('Auswertungsgruppen') }}
				</button>
				<button :class="{ active: reportView === 'spheres' }" @click="$emit('update:report-view', 'spheres')">
					{{ t('Sphären') }}
				</button>
				<button :class="{ active: reportView === 'reserves' }" @click="$emit('update:report-view', 'reserves')">
					{{ t('Rücklagen') }}
				</button>
				<button :class="{ active: reportView === 'budget' }" @click="$emit('update:report-view', 'budget')">
					{{ t('Finanzplan') }}
				</button>
				<button :class="{ active: reportView === 'audit' }" @click="$emit('update:report-view', 'audit')">
					{{ t('Protokoll') }}
				</button>
			</div>
			<div class="vbh-sectiontop-actions">
				<a
					v-if="reportView === 'summary' && selectedYear"
					:href="kassenberichtUrl"
					target="_blank"
					rel="noopener"
					class="vbh-export-btn"
					:title="t('Druckfertiger Kassenbericht für die Mitgliederversammlung (öffnet in neuem Tab, dort drucken oder als PDF speichern)')"><NcIconSvgWrapper :path="mdiPrinter" :size="16" inline /> {{ t('Kassenbericht') }}</a>
				<span v-if="reportView === 'summary'" class="vbh-kurzbericht-picker">
					<input
						v-model="kurzberichtSince"
						type="date"
						class="vbh-kurzbericht-date"
						:title="t('Kurzbericht: Bewegungen seit diesem Datum')">
					<a
						:href="kurzberichtUrl"
						target="_blank"
						rel="noopener"
						class="vbh-export-btn"
						:title="t('Kurzbericht für die nächste Vorstandssitzung (öffnet in neuem Tab, dort drucken oder als PDF speichern)')"><NcIconSvgWrapper :path="mdiPrinter" :size="16" inline /> {{ t('Kurzbericht') }}</a>
				</span>
				<!-- Seltener genutzte Exporte in einem Menü statt als eigene Buttons,
				     sonst wird die Kopfzeile durch Reiter + bis zu 7 Buttons zwei-
				     bis dreizeilig (gleiches Muster wie die Zeilen-Aktionen in
				     BookingsTab.vue). Kassenbericht und Kurzbericht bleiben sichtbar,
				     das sind laut Handbuch die beiden meistgenutzten Berichte. -->
				<NcActions
					v-if="reportView === 'summary'"
					:menuName="t('Weitere Exporte')"
					size="small"
					:forceMenu="true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDownload" :size="20" />
					</template>
					<NcActionLink
						v-if="selectedYear"
						:href="attachmentsZipUrl"
						download=""
						:title="t('Alle Belege des Jahres als ZIP herunterladen (für die Kassenprüfung)')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPaperclip" :size="16" />
						</template>
						{{ t('Beleg-ZIP') }}
					</NcActionLink>
					<NcActionLink
						:href="pruefleitfadenUrl"
						target="_blank"
						:title="t('Druckfertige 1-Seiten-Kurzanleitung für Kassenprüfer/innen (öffnet in neuem Tab)')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPrinter" :size="16" />
						</template>
						{{ t('Prüfleitfaden') }}
					</NcActionLink>
					<NcActionLink
						:href="exportBalancesUrl"
						download=""
						:title="t('Saldenliste als CSV exportieren')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDownload" :size="16" />
						</template>
						{{ t('Saldenliste') }}
					</NcActionLink>
					<NcActionLink
						:href="exportReportUrl"
						download=""
						:title="t('E/A-Übersicht als CSV exportieren')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDownload" :size="16" />
						</template>
						{{ t('E/A-Übersicht') }}
					</NcActionLink>
					<NcActionLink
						:href="exportMultiyearUrl"
						download=""
						:title="t('Mehrjahresübersicht (alle Jahre) als CSV exportieren')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDownload" :size="16" />
						</template>
						{{ t('Mehrjahresübersicht') }}
					</NcActionLink>
				</NcActions>
				<a
					v-if="reportView === 'budget'"
					:href="exportBudgetUrl"
					download
					class="vbh-export-btn"
					:title="t('Soll-Ist-Vergleich als CSV exportieren')"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> {{ t('Soll-Ist-Vergleich') }}</a>

				<!-- Gruppierung + Pflege-Einstieg: nur in den beiden Split-Ansichten,
				     dort wo bis 0.23.1 das Pflegepanel als zweiter Block unter dem
				     Bericht stand (siehe styles.css .vbh-splitinner). Der Modus
				     entscheidet, was der Kostenstellen-Bericht ueberhaupt gruppiert,
				     gehoert also als Steuerung in die Kopfzeile statt versteckt im
				     Pflege-Modal. -->
				<span v-if="reportView === 'costcenters'" class="vbh-ccmode-picker">
					<label v-if="isAdmin" class="vbh-ccmode-label">{{ t('Gruppierung:') }}
						<select :value="costCenterMode" @change="changeCostCenterMode($event.target.value)">
							<option value="group">{{ t('2. Zahlengruppe') }}</option>
							<option value="account">{{ t('Je Konto') }}</option>
							<option value="manual">{{ t('Frei definiert') }}</option>
						</select>
					</label>
					<span v-else class="vbh-hint">{{ t('Gruppierung:') }} {{ costCenterModeLabel }}</span>
				</span>
				<NcButton
					v-if="canWrite && reportView === 'costcenters'"
					variant="secondary"
					size="small"
					@click="ccPanelOpen = true">
					{{ t('Auswertungsgruppen verwalten') }}
				</NcButton>
				<NcButton
					v-if="canWrite && reportView === 'spheres'"
					variant="secondary"
					size="small"
					@click="spherePanelOpen = true">
					{{ t('Sphären zuordnen') }}
				</NcButton>
			</div>
		</div>

		<div class="vbh-sectionbody" :class="{ 'is-split': reportView === 'costcenters' || reportView === 'spheres' }">
			<!-- AUSWERTUNG -->
			<div v-show="reportView === 'summary'">
				<div v-if="balances" class="vbh-totals">
					<div class="vbh-total pos">
						<span>{{ t('Einnahmen') }}</span><strong>{{ formatMoney(balances.totals.income) }}</strong>
					</div>
					<div class="vbh-total neg">
						<span>{{ t('Ausgaben') }}</span><strong>{{ formatMoney(balances.totals.expense) }}</strong>
					</div>
					<div class="vbh-total" :class="balances.totals.result >= 0 ? 'pos' : 'neg'">
						<span>{{ t('Ergebnis') }}</span><strong>{{ formatMoney(balances.totals.result) }}</strong>
					</div>
				</div>

				<div v-if="trendChartData.labels.length" class="vbh-chart-grid">
					<div class="vbh-chart-card vbh-chart-card--wide">
						<h4>{{ t('Mehrjahres-Trend (Einnahmen, Ausgaben, Ergebnis)') }}</h4>
						<div class="vbh-chart-wrap">
							<canvas ref="trendChart" />
						</div>
					</div>
				</div>

				<template v-if="balances && balances.bankReconciliation && balances.bankReconciliation.length">
					<h4>{{ t('Geldkonten') }}</h4>
					<div v-if="!isMobile" class="vbh-tablecard">
						<table class="vbh-table">
							<thead>
								<tr>
									<th>{{ t('Konto') }}</th><th class="num">
										{{ t('Kontostand') }}
									</th><th class="num">
										{{ t('Offen (nicht zugeordnet)') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="b in balances.bankReconciliation" :key="b.accountId">
									<td>
										{{ b.number }} {{ b.name }}
										<span v-if="b.countInTotal === false" class="vbh-nocount">{{ t('(nicht im Geldbestand)') }}</span>
									</td>
									<td class="num strong">
										{{ formatMoney(b.balance) }}
									</td>
									<td class="num" :class="Math.abs(b.open) > 0.005 ? 'neg' : 'pos'">
										{{ formatMoney(b.open) }}
									</td>
								</tr>
							</tbody>
							<tfoot v-if="balances.bankTotal">
								<tr>
									<td><strong>{{ t('Summe') }}</strong></td>
									<td class="num strong">
										{{ formatMoney(balances.bankTotal.allBalance) }}
									</td>
									<td class="num" :class="Math.abs(balances.bankTotal.open) > 0.005 ? 'neg' : 'pos'">
										{{ formatMoney(balances.bankTotal.open) }}
									</td>
								</tr>
								<!-- Nur wenn beide Zahlen auseinanderlaufen: sonst stünde
								     hier dieselbe Summe ein zweites Mal. -->
								<tr v-if="balances.bankTotal.count < balances.bankTotal.allCount">
									<td>{{ t('davon Geldbestand (Kopfzeile)') }}</td>
									<td class="num">
										{{ formatMoney(balances.bankTotal.balance) }}
									</td>
									<td />
								</tr>
							</tfoot>
						</table>
					</div>
					<div v-else class="vbh-cardlist">
						<div v-for="b in balances.bankReconciliation" :key="'m' + b.accountId" class="vbh-mcard">
							<div class="vbh-mcard-top">
								<span class="vbh-mcard-title">{{ b.number }} {{ b.name }}</span>
								<span class="vbh-mcard-amount">{{ formatMoney(b.balance) }}</span>
							</div>
							<div v-if="b.countInTotal === false || Math.abs(b.open) > 0.005" class="vbh-mcard-bottom">
								<span class="vbh-mcard-accounts">
									<template v-if="b.countInTotal === false">{{ t('(nicht im Geldbestand)') }}</template>
									<template v-if="Math.abs(b.open) > 0.005">{{ t('{amount} nicht zugeordnet', { amount: formatMoney(b.open) }) }}</template>
								</span>
							</div>
						</div>
						<div v-if="balances.bankTotal && balances.bankTotal.allCount > 1" class="vbh-mcard">
							<div class="vbh-mcard-top">
								<span class="vbh-mcard-title"><strong>{{ t('Summe') }}</strong></span>
								<span class="vbh-mcard-amount"><strong>{{ formatMoney(balances.bankTotal.allBalance) }}</strong></span>
							</div>
							<div v-if="balances.bankTotal.count < balances.bankTotal.allCount" class="vbh-mcard-bottom">
								<span class="vbh-mcard-accounts">{{ t('davon Geldbestand (Kopfzeile): {amount}', { amount: formatMoney(balances.bankTotal.balance) }) }}</span>
							</div>
						</div>
					</div>
				</template>

				<div class="vbh-sectionhead">
					<h4>{{ t('Saldenliste') }}</h4>
					<NcCheckboxRadioSwitch v-model="balancesIncludeChildren">
						{{ t('Werte inkl. Unterkonten') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div v-if="balances && isMobile" class="vbh-cardlist">
					<div
						v-for="row in sortedBalances"
						:key="'m' + row.accountId"
						class="vbh-mcard"
						:class="{ 'vbh-mcard--parent': row.isParent }"
						:style="row.depth ? { marginLeft: (Math.min(row.depth, 3) * 14) + 'px' } : null">
						<div class="vbh-mcard-top">
							<span class="vbh-mcard-title">{{ row.number }} {{ row.name }}</span>
							<span class="vbh-mcard-amount" :class="amountClass(row.balance)">{{ formatMoney(row.balance) }}</span>
						</div>
						<div class="vbh-mcard-bottom">
							<span class="vbh-mcard-accounts">{{ t('{category} · Soll {debit} · Haben {credit}', { category: row.category || typeLabel(row.type), debit: formatMoney(row.debit), credit: formatMoney(row.credit) }) }}</span>
						</div>
					</div>
				</div>
				<div v-else-if="balances" class="vbh-tablecard">
					<table class="vbh-table">
						<thead>
							<tr>
								<th class="sortable nowrap vbh-col-hide-sm" @click="toggleSort('balances', 'number')">
									{{ t('Nr.') }}{{ sortArrow('balances', 'number') }}
								</th>
								<th class="sortable" @click="toggleSort('balances', 'name')">
									{{ t('Konto') }}{{ sortArrow('balances', 'name') }}
								</th>
								<th class="sortable vbh-col-hide-sm" @click="toggleSort('balances', 'category')">
									{{ t('Kategorie') }}{{ sortArrow('balances', 'category') }}
								</th>
								<th class="sortable num vbh-col-hide-sm" @click="toggleSort('balances', 'debit')">
									{{ t('Soll') }}{{ sortArrow('balances', 'debit') }}
								</th>
								<th class="sortable num vbh-col-hide-sm" @click="toggleSort('balances', 'credit')">
									{{ t('Haben') }}{{ sortArrow('balances', 'credit') }}
								</th>
								<th class="sortable num" @click="toggleSort('balances', 'balance')">
									{{ t('Saldo') }}{{ sortArrow('balances', 'balance') }}
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
						<strong>{{ t('Auswertungsgruppen') }}</strong>
					</div>
					<div v-if="reportData" class="vbh-ccsummary">
						<span>{{ t('Gesamtergebnis') }}</span>
						<strong :class="amountClass(reportData.totals.result)">{{ formatMoney(reportData.totals.result) }}</strong>
					</div>
					<div v-if="reportData" class="vbh-treelist">
						<div
							v-for="cc in reportData.costCenters"
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
						{{ t('Keine Daten. Importiere oder erfasse zuerst Buchungen.') }}
					</p>
				</div>

				<div v-if="!isMobile || selectedCC" class="vbh-detail">
					<div v-if="isMobile" class="vbh-backbar">
						<button type="button" class="vbh-backbtn" @click="$emit('update:selected-c-c-code', false)">
							{{ t('‹ Auswertungsgruppen') }}
						</button>
					</div>
					<p v-if="!selectedCC" class="vbh-empty vbh-detailhint">
						{{ t('Auswertungsgruppe links auswählen.') }}
					</p>
					<template v-else>
						<div class="vbh-detailhead">
							<div><h3>{{ selectedCC.code ? selectedCC.code + ' · ' : '' }}{{ selectedCC.name }}</h3></div>
						</div>

						<div v-if="canWrite && selectedCC.code && reportData && reportData.mode !== 'account'" class="vbh-opening">
							<span>{{ t('Name:') }}</span>
							<input :value="renameName" class="vbh-rename" @input="$emit('update:rename-name', $event.target.value)">
							<NcButton variant="primary" size="small" @click="saveRename">
								{{ t('Umbenennen') }}
							</NcButton>
						</div>

						<div v-if="canWrite && !selectedCC.code" class="vbh-opening">
							<NcButton variant="secondary" size="small" @click="ccPanelOpen = true">
								{{ t('Konten zuordnen') }}
							</NcButton>
						</div>

						<div class="vbh-totals">
							<div class="vbh-total pos">
								<span>{{ t('Einnahmen') }}</span><strong>{{ formatMoney(selectedCC.income) }}</strong>
							</div>
							<div class="vbh-total neg">
								<span>{{ t('Ausgaben') }}</span><strong>{{ formatMoney(selectedCC.expense) }}</strong>
							</div>
							<div class="vbh-total" :class="selectedCC.result >= 0 ? 'pos' : 'neg'">
								<span>{{ t('Ergebnis') }}</span><strong>{{ formatMoney(selectedCC.result) }}</strong>
							</div>
						</div>

						<h4>{{ t('Beteiligte Konten') }} <span class="vbh-hint">{{ t('(Konto anklicken für Buchungen)') }}</span></h4>
						<div v-if="selectedCC.accounts.length && isMobile" class="vbh-cardlist">
							<div
								v-for="(a, i) in selectedCC.accounts"
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
										{{ t('Keine Buchungen.') }}
									</p>
								</div>
							</div>
						</div>
						<div v-else-if="selectedCC.accounts.length" class="vbh-tablecard">
							<table class="vbh-table">
								<thead>
									<tr>
										<th class="nowrap">
											{{ t('Nr.') }}
										</th><th>{{ t('Konto') }}</th><th>{{ t('Art') }}</th><th class="num">
											{{ t('Betrag') }}
										</th>
									</tr>
								</thead>
								<tbody>
									<!-- :key gehoert unter Vue 3 aufs <template>, nicht mehr auf die
									     einzelnen <tr> (Muster wie beim Finanzplan unten) -->
									<template v-for="(a, i) in selectedCC.accounts" :key="i">
										<tr class="vbh-ccrow" @click="toggleCCAccount(a.accountId)">
											<td class="nowrap">
												<span class="vbh-caret" :class="{ open: ccExpanded[a.accountId] }">›</span> {{ a.number }}
											</td>
											<td>{{ a.name }}</td>
											<td><span class="vbh-typetag" :class="a.type">{{ typeLabel(a.type) }}</span></td>
											<td class="num" :class="amountClass(a.balance)">
												{{ formatMoney(a.balance) }}
											</td>
										</tr>
										<tr v-if="ccExpanded[a.accountId]" class="vbh-ccdetail">
											<td colspan="4">
												<table v-if="ccBookings[a.accountId] && ccBookings[a.accountId].length" class="vbh-table vbh-subtable">
													<thead>
														<tr>
															<th class="num vbh-col-hide-sm">
																{{ t('Nr.') }}
															</th><th class="nowrap">
																{{ t('Datum') }}
															</th><th>{{ t('Beschreibung') }}</th><th class="vbh-col-hide-sm">
																{{ t('Gegenkonto') }}
															</th><th class="num">
																{{ t('Soll') }}
															</th><th class="num">
																{{ t('Haben') }}
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
													{{ t('Keine Buchungen.') }}
												</p>
											</td>
										</tr>
									</template>
								</tbody>
							</table>
						</div>
						<p v-else class="vbh-empty">
							{{ t('Keine Buchungen mit Betrag in dieser Auswertungsgruppe.') }}
						</p>
					</template>
				</div>
			</div>

			<!-- SPHÄREN -->
			<div v-show="reportView === 'spheres'" class="vbh-splitinner" :class="{ 'vbh-drill': isMobile }">
				<div v-if="!isMobile || !selectedSphere" class="vbh-tree">
					<div class="vbh-treehead">
						<strong>{{ t('Sphären') }}</strong>
						<button
							type="button"
							class="vbh-sphere-help"
							:title="t('Was bedeutet das?')"
							@click="$emit('help', 'spheres')">
							?
						</button>
					</div>
					<div v-if="sphereData" class="vbh-ccsummary">
						<span>{{ t('Gesamtergebnis') }}</span>
						<strong :class="amountClass(sphereData.totals.result)">{{ formatMoney(sphereData.totals.result) }}</strong>
					</div>
					<div v-if="sphereData && sphereData.freigrenze.incomeCents > 0" class="vbh-freigrenzemini" :class="sphereData.freigrenze.level">
						{{ t('Wirtschaftlicher Geschäftsbetrieb: {income} von {threshold}', { income: formatMoney(sphereData.freigrenze.income), threshold: formatMoney(sphereData.freigrenze.threshold) }) }}
						({{ Math.round(sphereData.freigrenze.ratio * 100) }} %)
					</div>
					<div v-if="sphereData" class="vbh-treelist">
						<div
							v-for="s in sphereData.spheres"
							:key="s.code || 'none'"
							class="vbh-treenode"
							:class="{ selected: isSphereSelected(s) }"
							@click="selectSphere(s)">
							<span class="vbh-treename">{{ s.name }}</span>
							<span class="vbh-treesaldo" :class="[amountClass(s.result), { zero: !s.result }]">{{ formatMoney(s.result) }}</span>
						</div>
					</div>
					<p v-else class="vbh-hint">
						{{ t('Keine Daten. Importiere oder erfasse zuerst Buchungen.') }}
					</p>
				</div>

				<div v-if="!isMobile || selectedSphere" class="vbh-detail">
					<div v-if="isMobile" class="vbh-backbar">
						<button type="button" class="vbh-backbtn" @click="$emit('update:selected-sphere-code', false)">
							{{ t('‹ Sphären') }}
						</button>
					</div>
					<p v-if="!selectedSphere" class="vbh-empty vbh-detailhint">
						{{ t('Sphäre links auswählen.') }}
					</p>
					<template v-else>
						<div class="vbh-detailhead">
							<div><h3>{{ selectedSphere.name }}</h3></div>
						</div>

						<div v-if="canWrite && !selectedSphere.code" class="vbh-opening">
							<NcButton variant="secondary" size="small" @click="spherePanelOpen = true">
								{{ t('Konten zuordnen') }}
							</NcButton>
						</div>

						<div class="vbh-totals">
							<div class="vbh-total pos">
								<span>{{ t('Einnahmen') }}</span><strong>{{ formatMoney(selectedSphere.income) }}</strong>
							</div>
							<div class="vbh-total neg">
								<span>{{ t('Ausgaben') }}</span><strong>{{ formatMoney(selectedSphere.expense) }}</strong>
							</div>
							<div class="vbh-total" :class="selectedSphere.result >= 0 ? 'pos' : 'neg'">
								<span>{{ t('Ergebnis') }}</span><strong>{{ formatMoney(selectedSphere.result) }}</strong>
							</div>
						</div>

						<h4>{{ t('Beteiligte Konten') }}</h4>
						<div v-if="selectedSphere.accounts.length" class="vbh-tablecard">
							<table class="vbh-table">
								<thead>
									<tr>
										<th class="nowrap">
											{{ t('Nr.') }}
										</th><th>{{ t('Konto') }}</th><th>{{ t('Art') }}</th><th class="num">
											{{ t('Betrag') }}
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
							{{ t('Keine Buchungen mit Betrag in dieser Sphäre.') }}
						</p>
					</template>
				</div>
			</div>

			<!-- RÜCKLAGEN -->
			<div v-show="reportView === 'reserves'">
				<p class="vbh-hint">
					{{ t('Rücklagen sind Eigenkapital-Konten mit festgelegter Rücklagen-Art (§ 62 AO). Zuweisungen erfolgen als normale Buchung (Experten-Modus im Buchungsdialog, Eigenkapital-zu-Eigenkapital-Umbuchung) – hier siehst du nur den aktuellen Stand je Art.') }}
				</p>
				<div v-if="reserveData" class="vbh-totals">
					<div class="vbh-total" :class="reserveData.total >= 0 ? 'pos' : 'neg'">
						<span>{{ t('Rücklagen gesamt') }}</span><strong>{{ formatMoney(reserveData.total) }}</strong>
					</div>
				</div>
				<div v-if="reserveData && reserveData.reserves.some(r => r.accounts.length)" class="vbh-tablecard">
					<table class="vbh-table">
						<thead>
							<tr>
								<th>{{ t('Rücklagen-Art') }}</th><th class="num">
									{{ t('Saldo') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<template v-for="r in reserveData.reserves" :key="r.kind">
								<tr class="vbh-parentrow">
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
					{{ t('Noch keinem Konto eine Rücklagen-Art zugewiesen. Im Konto-Dialog eines Eigenkapital-Kontos festlegen (Tab Konten).') }}
				</p>
			</div>

			<!-- FINANZPLAN -->
			<div v-show="reportView === 'budget'">
				<div class="vbh-sectionhead">
					<h4>{{ t('Finanzplan & Soll-Ist-Vergleich') }}{{ budgetData ? ' ' + budgetData.year : '' }}</h4>
					<form v-if="canWrite" class="vbh-addyear" @submit.prevent="addBudgetYear">
						<input
							v-model="newBudgetYear"
							type="number"
							min="2000"
							max="2099"
							:placeholder="t('Jahr')"
							class="vbh-addyear-input">
						<NcButton type="submit" variant="secondary">
							{{ t('Jahr hinzufügen') }}
						</NcButton>
					</form>
				</div>
				<p class="vbh-hint">
					{{ t('Plane je Konto die erwarteten Einnahmen und Ausgaben (Spalte „Plan"). Die Spalte „Ist" zeigt die tatsächlichen Buchungen des gewählten Geschäftsjahres, „Differenz" den Abstand zum Plan.') }}
				</p>

				<div v-if="budgetData" class="vbh-totals">
					<div class="vbh-total pos">
						<span>{{ t('Einnahmen (Plan / Ist)') }}</span>
						<strong>{{ formatMoney(budgetData.totals.planIncome) }} / {{ formatMoney(budgetData.totals.actualIncome) }}</strong>
					</div>
					<div class="vbh-total neg">
						<span>{{ t('Ausgaben (Plan / Ist)') }}</span>
						<strong>{{ formatMoney(budgetData.totals.planExpense) }} / {{ formatMoney(budgetData.totals.actualExpense) }}</strong>
					</div>
					<div class="vbh-total" :class="budgetData.totals.actualResult >= 0 ? 'pos' : 'neg'">
						<span>{{ t('Ergebnis (Plan / Ist)') }}</span>
						<strong>{{ formatMoney(budgetData.totals.planResult) }} / {{ formatMoney(budgetData.totals.actualResult) }}</strong>
					</div>
				</div>

				<div v-if="budgetData && budgetData.rows.length" class="vbh-tablecard">
					<table class="vbh-table">
						<thead>
							<tr>
								<th class="nowrap vbh-col-hide-sm">
									{{ t('Nr.') }}
								</th>
								<th>{{ t('Konto') }}</th>
								<th class="vbh-col-hide-sm">
									{{ t('Art') }}
								</th>
								<th class="num vbh-col-plan">
									{{ t('Plan (Soll)') }}
								</th>
								<th class="vbh-col-note" :title="t('Notiz zur Planzahl')" />
								<th class="num">
									{{ t('Ist') }}
								</th>
								<th class="num">
									{{ t('Differenz') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<template v-for="row in budgetData.rows" :key="row.accountId">
								<tr>
									<td class="nowrap vbh-col-hide-sm">
										{{ row.number }}
									</td>
									<td>{{ row.name }}</td>
									<td class="vbh-col-hide-sm">
										<span class="vbh-typetag" :class="row.type">{{ typeLabel(row.type) }}</span>
									</td>
									<td class="num vbh-col-plan">
										<input
											v-if="canWrite"
											v-model.number="row.plan"
											type="number"
											step="0.01"
											class="vbh-num vbh-planinput"
											@change="saveBudget(row)">
										<span v-else>{{ formatMoney(row.plan) }}</span>
									</td>
									<td class="vbh-col-note">
										<NcButton
											v-if="canWrite || row.note"
											variant="tertiary"
											:aria-label="row.note ? t('Notiz zur Planzahl anzeigen') : t('Notiz zur Planzahl hinzufügen')"
											:title="row.note || t('Notiz hinzufügen')"
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
								<tr v-if="budgetNoteOpen[row.accountId]" class="vbh-note-row">
									<td colspan="7">
										<label class="vbh-note-label">{{ t('Notiz zu {number} {name}', { number: row.number, name: row.name }) }}
											<textarea
												v-if="canWrite"
												v-model="row.note"
												maxlength="1000"
												rows="2"
												class="vbh-note-textarea"
												:placeholder="t('z. B. Herleitung: 40 Mitglieder × 25 € Beitrag')"
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
					{{ t('Keine Einnahmen-/Ausgabenkonten vorhanden.') }}
				</p>

				<!-- PLAN-STÄNDE (Snapshots) -->
				<div v-if="budgetData" class="vbh-snapblock">
					<div class="vbh-sectionhead">
						<h4>{{ t('Plan-Stände {year}', { year: budgetData.year }) }}</h4>
						<form v-if="canWrite" class="vbh-addyear" @submit.prevent="saveBudgetSnapshot">
							<input
								v-model="newSnapshotLabel"
								type="text"
								maxlength="128"
								:placeholder="t('z.B. Beschluss MV')"
								class="vbh-snaplabel-input">
							<NcButton type="submit" variant="secondary">
								{{ t('Aktuellen Plan speichern') }}
							</NcButton>
						</form>
					</div>
					<p class="vbh-hint">
						{{ t('Friere den aktuellen Finanzplan als benannten, datierten Stand ein (z.B. den in der Mitgliederversammlung beschlossenen Haushalt). Spätere Planänderungen lassen den Stand unberührt.') }}
					</p>
					<div v-if="budgetSnapshots.length" class="vbh-tablecard">
						<table class="vbh-table">
							<thead>
								<tr>
									<th>{{ t('Stand') }}</th>
									<th class="nowrap vbh-col-hide-sm vbh-col-datetime">
										{{ t('Gespeichert') }}
									</th>
									<th class="num vbh-col-hide-sm">
										{{ t('Einnahmen') }}
									</th>
									<th class="num vbh-col-hide-sm">
										{{ t('Ausgaben') }}
									</th>
									<th class="num">
										{{ t('Ergebnis') }}
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
										<div class="vbh-actions">
											<NcButton variant="tertiary" @click="openSnapshot(snap)">
												{{ t('Ansehen') }}
											</NcButton>
											<NcButton
												v-if="canWrite"
												variant="tertiary"
												:title="t('Stand löschen')"
												@click="deleteBudgetSnapshot(snap)">
												<template #icon>
													<NcIconSvgWrapper :path="mdiDelete" :size="18" />
												</template>
											</NcButton>
										</div>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
					<p v-else class="vbh-empty">
						{{ t('Noch keine Stände für dieses Jahr gespeichert.') }}
					</p>
				</div>
			</div>

			<!-- ÄNDERUNGSPROTOKOLL -->
			<div v-show="reportView === 'audit'">
				<p class="vbh-hint">
					{{ t('Wer hat wann was geändert – z. B. für die Kassenprüfung. Das Protokoll wird automatisch geführt und bleibt auch beim Zurücksetzen aller Daten erhalten.') }}
				</p>
				<label v-if="auditEntries.length" class="vbh-checkinline">
					<input v-model="auditOnlyImports" type="checkbox">
					{{ t('nur Importe (CSV, xbuc, Wachordner)') }}
				</label>
				<div v-if="filteredAuditEntries.length" class="vbh-tablecard">
					<table class="vbh-table">
						<thead>
							<tr>
								<th class="nowrap vbh-col-datetime">
									{{ t('Zeitpunkt') }}
								</th><th>{{ t('Wer') }}</th><th>{{ t('Aktion') }}</th><th>{{ t('Details') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="a in filteredAuditEntries" :key="a.id">
								<td class="nowrap">
									{{ formatDateTime(a.ts) }}
								</td>
								<td class="nowrap">
									{{ a.userId }}
								</td>
								<td class="nowrap">
									{{ t(a.action) }}
								</td>
								<td class="vbh-purpose">
									<span class="vbh-clamp">{{ auditDetailText(a) }}</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<NcEmptyContent v-else-if="!auditLoading && auditOnlyImports" :name="t('Keine Importe in den geladenen Einträgen')" :description="t('Ältere Einträge laden oder den Filter ausschalten.')" />
				<NcEmptyContent v-else-if="!auditLoading" :name="t('Noch keine Protokolleinträge')" :description="t('Änderungen ab Version 0.10.41 werden hier aufgezeichnet.')">
					<template #action>
						<NcButton variant="tertiary" @click="$emit('help', 'reports')">
							{{ t('Mehr dazu') }}
						</NcButton>
					</template>
				</NcEmptyContent>
				<div v-if="auditEntries.length && !auditEnd" class="vbh-loadmore">
					<NcButton variant="secondary" :disabled="auditLoading" @click="loadAudit(true)">
						{{ t('Ältere Einträge laden') }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Pflege-Modals: v-if statt nur :show, damit die teils langen
		     Konten-Tabellen erst beim Oeffnen mounten (siehe MembersList.vue
		     fuer dasselbe Muster bei MemberDialog/MemberImportDialog). -->
		<NcModal
			v-if="canWrite && ccPanelOpen"
			labelId="vbh-modal-title-costcenters"
			size="large"
			@close="ccPanelOpen = false">
			<div class="vbh-modal-inner">
				<h2 id="vbh-modal-title-costcenters" class="vbh-modal-title">
					{{ t('Auswertungsgruppen verwalten') }}
				</h2>
				<CostCenterPanel :mode="costCenterMode" @changed="$emit('cost-centers-changed')" />
			</div>
		</NcModal>

		<NcModal
			v-if="canWrite && spherePanelOpen"
			labelId="vbh-modal-title-spheres"
			size="large"
			@close="spherePanelOpen = false">
			<div class="vbh-modal-inner">
				<h2 id="vbh-modal-title-spheres" class="vbh-modal-title">
					{{ t('Sphären zuordnen') }}
				</h2>
				<SphereAssignPanel @changed="$emit('spheres-changed')" @help="$emit('help', 'spheres')" />
			</div>
		</NcModal>
	</div>
</template>

<script>
import { mdiCommentPlusOutline, mdiCommentText, mdiDelete, mdiDownload, mdiPaperclip, mdiPrinter } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcActionLink, NcActions, NcButton, NcCheckboxRadioSwitch, NcEmptyContent, NcIconSvgWrapper, NcModal } from '@nextcloud/vue'
import {
	CategoryScale,
	Chart,
	Legend,
	LinearScale,
	LineController,
	LineElement,
	PointElement,
	Tooltip,
} from 'chart.js'
import { toRefs } from 'vue'
import CostCenterPanel from './CostCenterPanel.vue'
import SphereAssignPanel from './SphereAssignPanel.vue'
import api from '../api.js'
import { useAccounts } from '../composables/useAccounts.js'
import { useAuth } from '../composables/useAuth.js'
import { useBalances } from '../composables/useBalances.js'
import { useConfirm } from '../composables/useConfirm.js'
import { useSort } from '../composables/useSort.js'
import { useYears } from '../composables/useYears.js'
import { amountClass, budgetDiffClass, formatDate, formatDateTime, formatMoney, roleLabel, typeLabel } from '../lib/format.js'

Chart.register(LineController, LineElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend)

export default {
	name: 'ReportsTab',
	components: { NcButton, NcActions, NcActionLink, NcCheckboxRadioSwitch, NcEmptyContent, NcIconSvgWrapper, NcModal, CostCenterPanel, SphereAssignPanel },
	props: {
		// Kostenstellen-Modus (group|account|manual), gesteuert ueber den
		// Gruppierungs-Waehler in der Kopfzeile (nur reportView==='costcenters').
		// saveCostCenterMode schreibt nur dieses eine Feld - seit die
		// Einstellungsseite (SettingsApp.vue) denselben Endpunkt fuer ihre
		// eigenen elf Felder benutzt, wuerde ein vollstaendiger Schreibzugriff
		// hier deren Stand mit einem veralteten Schnappschuss ueberschreiben.
		costCenterMode: { type: String, default: 'group' },
		saveCostCenterMode: { type: Function, required: true },
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
		selectedCCCode: { type: [Boolean, String, Number], default: false },
		selectedSphereCode: { type: [Boolean, String, Number], default: false },
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

	emits: ['budget-changed', 'cost-centers-changed', 'help', 'snapshots-changed', 'spheres-changed', 'update:cost-center-mode', 'update:rename-name', 'update:report-view', 'update:selected-c-c-code', 'update:selected-sphere-code'],

	setup() {
		const auth = useAuth()
		const years = useYears()
		const accounts = useAccounts()
		const balances = useBalances()
		const sorting = useSort()
		return {
			canWrite: auth.canWrite,
			isAdmin: auth.isAdmin,
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
			multiyearTrendData: null,
			reserveData: null,
			kurzberichtSince: this.defaultKurzberichtSince(),
			newBudgetYear: '',
			budgetNoteOpen: {},
			newSnapshotLabel: '',
			// Protokoll: Importe (CSV/xbuc/Wachordner/Beispieldaten) ausblenden, wenn
			// nicht gebraucht - ersetzt die frühere separate Liste unter
			// Einstellungen → Daten, die dieselben Angaben nur ein zweites Mal zeigte.
			auditOnlyImports: false,
			// Pflege-Modals (Kostenstellen/Sphären) - siehe NAVIGATION-KONZEPT.md
			// Abschnitt 4 (D4-Nachtrag): Pflege bleibt bei den Berichten, aber als
			// kontextnahes Modal statt als Block unter der Split-Ansicht.
			ccPanelOpen: false,
			spherePanelOpen: false,
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
		// Import-Aktionen tragen alle objectType 'import' (CSV-/xbuc-/Wachordner-
		// Import, Beispieldaten) - siehe die audit->log()-Aufrufe im Backend.
		filteredAuditEntries() {
			if (!this.auditOnlyImports) { return this.auditEntries }
			return this.auditEntries.filter((a) => a.objectType === 'import')
		},

		// Kurzlabel fuer Buchhalter (nur lesend) - Verwalter sehen stattdessen das
		// <select> aus changeCostCenterMode(). Gleiche drei Modi wie
		// CostCenterPanel.vue::modeLabels(), hier aber als Kurzform fuer die
		// schmale Kopfzeile statt der ausfuehrlichen Options-Beschriftung dort.
		costCenterModeLabel() {
			return {
				group: this.t('2. Zahlengruppe'),
				account: this.t('Je Konto'),
				manual: this.t('Frei definiert'),
			}[this.costCenterMode] || this.costCenterMode
		},

		selectedCC() {
			if (this.selectedCCCode === false || !this.reportData) { return null }
			return this.reportData.costCenters.find((c) => c.code === this.selectedCCCode) || null
		},

		selectedSphere() {
			if (this.selectedSphereCode === false || !this.sphereData) { return null }
			return this.sphereData.spheres.find((s) => s.code === this.selectedSphereCode) || null
		},

		// Hierarchie-Tiefe je Konto (für die Einrückung in der Saldenliste)
		accountDepth() {
			const out = {}
			for (const a of this.accounts) {
				let depth = 0
				let cur = a
				const seen = new Set([a.id])
				while (cur && cur.parentId !== null && cur.parentId !== undefined && this.accountsById[cur.parentId] && !seen.has(cur.parentId) && depth < 8) {
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
			const enrich = (r) => ({
				...r,
				depth: this.accountDepth[r.accountId] || 0,
				isParent: (this.childrenOf[r.accountId] || []).length > 0,
			})
			if (!this.balancesIncludeChildren) { return base.map(enrich) }
			const rowById = {}
			for (const r of base) { rowById[r.accountId] = r }
			const agg = (id) => {
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
			return base.map((r) => {
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
				labels: rows.map((r) => String(r.year)),
				income: rows.map((r) => r.income),
				expense: rows.map((r) => r.expense),
				result: rows.map((r) => r.result),
			}
		},
	},

	watch: {
		trendChartVisible(v) {
			if (v) { this.loadMultiyearTrend() }
		},

		reportView(v) {
			if (v === 'reserves') { this.loadReserveReport() }
		},

		// Komfort: zuletzt gewaehltes "seit"-Datum geraetelokal merken (Muster
		// wie vbh_recent_accounts), reine UI-Vorbelegung, kein Pflichtfeld.
		kurzberichtSince(v) {
			try { localStorage.setItem('vbh_kurzbericht_since', v) } catch { /* voll/gesperrt - dann eben ohne */ }
		},
	},

	// chartInstances liegt bewusst NICHT in data(): Chart.js-Instanzen vertragen
	// sich nicht mit Vue 3s tiefer Proxy-Reaktivitaet, und die Instanzen werden
	// nirgends im Template gelesen, brauchen also keine Reaktivitaet.
	created() {
		this.chartInstances = {}
	},

	mounted() {
		if (this.trendChartVisible) { this.loadMultiyearTrend() }
		if (this.reportView === 'reserves') { this.loadReserveReport() }
	},

	beforeUnmount() {
		Object.values(this.chartInstances).forEach((c) => c && c.destroy())
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
				if (saved) { return saved }
			} catch { /* voll/gesperrt - dann eben Default */ }
			const d = new Date()
			d.setDate(d.getDate() - 30)
			return d.toISOString().slice(0, 10)
		},

		async loadReserveReport() {
			try { const { data } = await api.reserveReport(); this.reserveData = data } catch (e) { showError(this.errMsg(e, this.t('Rücklagen-Bericht konnte nicht geladen werden'))) }
		},

		async loadMultiyearTrend() {
			try {
				const { data } = await api.multiyearTrend()
				this.multiyearTrendData = data
				this.$nextTick(() => setTimeout(() => this.renderTrendChart(), 50))
			} catch { /* Diagramm ist eine Zusatzansicht, kein harter Fehler */ }
		},

		destroyChart(key) {
			if (this.chartInstances[key]) {
				this.chartInstances[key].destroy()
				this.chartInstances[key] = null
			}
		},

		renderTrendChart() {
			const canvas = this.$refs.trendChart
			if (!canvas) { return }
			this.destroyChart('trend')
			const { labels, income, expense, result } = this.trendChartData
			if (!labels.length) { return }
			const isDark = document.documentElement.classList.contains('theme--dark')
			const textColor = isDark ? 'rgba(255,255,255,0.8)' : 'rgba(0,0,0,0.7)'
			const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.08)'
			const eur = (v) => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v)
			this.chartInstances.trend = new Chart(canvas, {
				type: 'line',
				data: {
					labels,
					datasets: [
						{
							label: this.t('Einnahmen'),
							data: income,
							borderColor: 'rgba(45,125,70,0.9)',
							backgroundColor: 'rgba(45,125,70,0.15)',
							tension: 0.2,
						},
						{
							label: this.t('Ausgaben'),
							data: expense,
							borderColor: 'rgba(199,60,60,0.9)',
							backgroundColor: 'rgba(199,60,60,0.15)',
							tension: 0.2,
						},
						{
							label: this.t('Ergebnis'),
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
						tooltip: { callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${eur(ctx.raw)}` } },
					},

					scales: {
						x: { ticks: { color: textColor }, grid: { color: gridColor } },
						y: { ticks: { color: textColor, callback: (v) => eur(v) }, grid: { color: gridColor } },
					},
				},
			})
		},

		auditDetailText(a) {
			if (!a.details) { return '' }
			const d = a.details
			const parts = []
			if (d.entryNo !== null && d.entryNo !== undefined) { parts.push('#' + d.entryNo) }
			if (d.date) { parts.push(this.formatDate(d.date)) }
			if (d.konto) { parts.push(d.konto) }
			if (d.contra) { parts.push(d.contra) }
			if (d.description) { parts.push(d.description) }
			if (d.fileName) { parts.push(d.fileName) }
			if (d.filename) { parts.push(d.filename) }
			if (d.wer) { parts.push((d.typ === 'group' ? this.t('Gruppe') + ' ' : '') + d.wer + (d.rolle ? ' → ' + this.roleLabel(d.rolle) : '')) }
			if (d.amount !== null && d.amount !== undefined) { parts.push(this.formatMoney(d.amount)) }
			if (d.jahr !== null && d.jahr !== undefined) { parts.push(this.t('Jahr {year}', { year: d.jahr })) }
			if (d.buchungen !== null && d.buchungen !== undefined) { parts.push(this.t('{n} Buchungen', { n: d.buchungen })) }
			if (d.neu !== null && d.neu !== undefined) { parts.push(this.t('{n} neu', { n: d.neu })) }
			if (d.duplikate !== null && d.duplikate !== undefined) { parts.push(this.t('{n} Dubletten', { n: d.duplikate })) }
			if (d.reset) { parts.push(this.t('mit Zurücksetzen')) }
			return parts.join(' · ')
		},

		// $emit ruft update:cost-center-mode synchron auf App.vue (costCenterMode
		// = $event), saveCostCenterMode() liest danach den bereits neuen Wert.
		changeCostCenterMode(value) {
			this.$emit('update:cost-center-mode', value)
			this.saveCostCenterMode()
		},

		addBudgetYear() {
			const y = parseInt(this.newBudgetYear, 10)
			if (!y || y < 2000 || y > 2099) { return }
			this.newBudgetYear = ''
			if (!this.years.includes(y)) {
				this.years = [y, ...this.years].sort((a, b) => b - a)
			}
			this.selectedYear = y
		},

		async saveBudget(row) {
			if (!this.budgetData) { return }
			try {
				await api.setBudget(row.accountId, this.budgetData.year, Number(row.plan) || 0, (row.note || '').trim())
				this.$emit('budget-changed')
			} catch (e) { showError(this.errMsg(e, this.t('Planwert konnte nicht gespeichert werden'))) }
		},

		toggleBudgetNote(row) {
			this.budgetNoteOpen[row.accountId] = !this.budgetNoteOpen[row.accountId]
		},

		async saveBudgetSnapshot() {
			if (!this.budgetData) { return }
			const label = this.newSnapshotLabel.trim()
			try {
				await api.createBudgetSnapshot(this.budgetData.year, label)
				this.newSnapshotLabel = ''
				this.$emit('snapshots-changed')
				showSuccess(this.t('Plan-Stand gespeichert.'))
			} catch (e) { showError(this.errMsg(e, this.t('Plan-Stand konnte nicht gespeichert werden'))) }
		},

		async deleteBudgetSnapshot(snap) {
			if (!await this.askConfirm(this.t('Plan-Stand löschen'), this.t('Stand „{label}" wirklich löschen?', { label: snap.label }))) { return }
			try {
				await api.deleteBudgetSnapshot(snap.id)
				this.$emit('snapshots-changed')
				showSuccess(this.t('Plan-Stand gelöscht.'))
			} catch (e) { showError(this.errMsg(e, this.t('Plan-Stand konnte nicht gelöscht werden'))) }
		},
	},
}
</script>
