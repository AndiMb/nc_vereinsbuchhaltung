<template>
	<div class="vbh">
		<header class="vbh-header">
			<div class="vbh-titlebar">
				<h2>Vereinsbuchhaltung</h2>
				<div v-if="primaryBank && !isMobile" class="vbh-bankchip" :class="{ warn: Math.abs(primaryBank.open) > 0.005 }">
					<span class="vbh-bankchip-label">{{ primaryBank.name }}</span>
					<span class="vbh-bankchip-value">{{ formatMoney(primaryBank.balance) }}</span>
					<span v-if="Math.abs(primaryBank.open) > 0.005" class="vbh-bankchip-hint">{{ formatMoney(primaryBank.open) }} offen</span>
				</div>
				<NcLoadingIcon v-if="busy" :size="24" name="Wird geladen…" />
			</div>
			<div v-if="canRead" class="vbh-navbar" :class="{ 'vbh-navbar--mobile': isMobile }">
				<nav v-if="!isMobile" class="vbh-tabs">
					<button v-for="tab in visibleTabs" :key="tab.id" :class="{ active: activeTab === tab.id }" @click="activeTab = tab.id">
						<NcIconSvgWrapper :path="tab.icon" :size="18" inline />
						{{ tab.label }}
						<span v-if="tab.id === 'bookings' && unassignedCount > 0" class="vbh-badge vbh-badge--alert">{{ unassignedCount }}</span>
					</button>
				</nav>
				<div class="vbh-navright">
					<NcButton v-if="canWrite && !isMobile" variant="primary" class="vbh-newbooking-btn" title="Neue Buchung anlegen (von überall)" @click="openNewBooking">
						<template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
						<span class="vbh-newbooking-label">Buchung</span>
					</NcButton>
					<label class="vbh-yearsel" :title="yearClosed ? 'Geschäftsjahr abgeschlossen (festgeschrieben)' : 'Geschäftsjahr (Kalenderjahr)'">
						<span>Jahr</span>
						<select v-model="selectedYear">
							<option :value="null">Alle Jahre</option>
							<option v-for="y in years" :key="y" :value="y">{{ y }}{{ closedYearSet[y] ? ' 🔒' : '' }}</option>
						</select>
					</label>
					<NcButton variant="tertiary" aria-label="Hilfe" title="Hilfe" @click="openHelp()">
						<template #icon><NcIconSvgWrapper :path="mdiHelpCircleOutline" :size="20" /></template>
					</NcButton>
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

		<div v-if="demoActive" class="vbh-demobanner">
			<span><strong>Beispieldaten aktiv.</strong> Das ist ein Beispielverein zum Ausprobieren, keine echten Daten.</span>
			<NcButton variant="secondary" :disabled="busy" @click="resetAll">Zurücksetzen &amp; mit echten Daten starten</NcButton>
		</div>

		<div v-if="showRevisorIntro" class="vbh-revisorintro">
			<h3>Willkommen als Kassenprüfer/in</h3>
			<ul>
				<li>Buchungen einsehen (Tab „Buchungen")</li>
				<li>Kontoauszug und Saldenliste prüfen (Tabs „Konten" und „Berichte")</li>
				<li>Kassenbericht drucken (Tab „Berichte" → Auswertung)</li>
			</ul>
			<p>Ändern ist mit dieser Rolle nicht möglich.</p>
			<div class="vbh-modal-actions">
				<a :href="pruefleitfadenUrl" target="_blank" rel="noopener" class="vbh-export-btn"><NcIconSvgWrapper :path="mdiPrinter" :size="16" inline /> Prüfleitfaden</a>
				<NcButton variant="tertiary" @click="dismissRevisorIntro">Verstanden</NcButton>
			</div>
		</div>

		<main v-show="canRead" class="vbh-main">
			<!-- ============ ÜBERSICHT (DASHBOARD) ============ -->
			<section v-show="activeTab === 'dashboard'" class="vbh-section scroll" :class="{ 'vbh-fadein': sectionFade }">
				<SetupChecklist
					v-if="isAdmin"
					:accounts="accounts"
					:permissions="permissions"
					:journal-count="journalData.length"
					:club-name="clubName"
					@navigate="onSetupNavigate"
					@open-wizard="showSetupWizard = true" />

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

				<div v-if="sphereData && sphereData.freigrenze.incomeCents > 0" class="vbh-freigrenzecard" :class="sphereData.freigrenze.level">
					<div class="vbh-freigrenzecard-text">
						<strong>Wirtschaftlicher Geschäftsbetrieb{{ selectedYear ? ' ' + selectedYear : '' }}:</strong>
						{{ formatMoney(sphereData.freigrenze.income) }} von {{ formatMoney(sphereData.freigrenze.threshold) }} Freigrenze
						({{ Math.round(sphereData.freigrenze.ratio * 100) }} %)
						<span v-if="sphereData.freigrenze.level === 'over'"> – Freigrenze überschritten, bitte mit Steuerberatung klären.</span>
						<span v-else-if="sphereData.freigrenze.level === 'warn'"> – nähert sich der Freigrenze.</span>
					</div>
					<button type="button" class="vbh-sphere-help" title="Was bedeutet das?" @click="openHelp('spheres')">?</button>
				</div>

				<template v-if="balances && balances.bankReconciliation && balances.bankReconciliation.length">
					<h4>Geldkonten</h4>
					<div v-if="!isMobile" class="vbh-tablecard">
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

				<template v-if="recentJournal.length">
					<div class="vbh-sectionhead">
						<h4>Letzte Buchungen</h4>
						<NcButton variant="tertiary" @click="activeTab = 'bookings'">Alle anzeigen</NcButton>
					</div>
					<div v-if="!isMobile" class="vbh-tablecard">
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
					<div v-else class="vbh-cardlist">
						<BookingCard v-for="r in recentJournal"
							:key="'m' + r.id"
							:row="r"
							:attachment-count="attachmentCountMap[r.id] ? attachmentCountMap[r.id].count : 0"
							:flow="rowFlow(r)"
							:tappable="canWrite || !!attachmentCountMap[r.id]"
							@open="openBookingCard(r)"
							@paperclip="clickPaperclip(r)" />
					</div>
				</template>
				<NcEmptyContent v-else-if="!busy" name="Noch keine Buchungen" description="Importiere Kontoumsätze oder lege manuell Buchungssätze an.">
				<template #action>
					<NcButton variant="tertiary" @click="openHelp('bookings')">Mehr dazu</NcButton>
				</template>
			</NcEmptyContent>

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
					<label v-if="bookingView === 'journal'" class="vbh-checkinline" title="Nur Buchungen ohne angehängten Beleg zeigen (z. B. vor der Kassenprüfung)">
						<input v-model="journalOnlyNoAttachment" type="checkbox">
						nur ohne Beleg
					</label>
				</div>

				<div class="vbh-sectionbody">
					<!-- JOURNAL VIEW -->
					<template v-if="bookingView === 'journal'">
						<div v-if="journalNumberIssues" class="vbh-yearwarn">
							<p class="vbh-warn-inline">
								⚠ Buchungsnummern {{ selectedYear }} nicht lückenlos:
								<template v-if="journalNumberIssues.missing.length">fehlend {{ journalNumberIssues.missing.slice(0, 20).join(', ') }}<template v-if="journalNumberIssues.missing.length > 20"> …</template></template>
								<template v-if="journalNumberIssues.missing.length && journalNumberIssues.duplicates.length"> · </template>
								<template v-if="journalNumberIssues.duplicates.length">doppelt {{ journalNumberIssues.duplicates.join(', ') }}</template>
							</p>
						</div>
						<div v-if="filteredJournalRows.length && isMobile" class="vbh-cardlist">
							<div class="vbh-tablecount">{{ filteredJournalRows.length }}<template v-if="filteredJournalRows.length !== sortedJournalRows.length"> von {{ sortedJournalRows.length }}</template> Buchungssätze</div>
							<template v-for="g in journalCardGroups">
								<div :key="g.key" class="vbh-monthdivider">{{ g.label }}</div>
								<BookingCard v-for="r in g.rows"
									:key="g.key + '-' + r.id"
									:row="r"
									:attachment-count="attachmentCountMap[r.id] ? attachmentCountMap[r.id].count : 0"
									:flow="rowFlow(r)"
									:tappable="canWrite || !!attachmentCountMap[r.id]"
									@open="openBookingCard(r)"
									@paperclip="clickPaperclip(r)" />
							</template>
						</div>
						<div v-else-if="filteredJournalRows.length" class="vbh-tablecard">
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
												<NcButton v-if="canWrite && !isYearClosed(r.date)" variant="error" aria-label="Löschen" @click="removeBooking(r)">
													<template #icon><NcIconSvgWrapper :path="mdiDelete" :size="20" /></template>
												</NcButton>
											</div>
										</td>
									</tr>
								</tbody>
							</table>
						</div>
						<NcEmptyContent v-else-if="bookingSearch || bookingFilterAccountId" name="Keine Treffer" description="Suchfilter anpassen oder löschen." />
						<NcEmptyContent v-else name="Noch keine Buchungssätze" description="Lege mit ‛Neue Buchung' einen ersten Buchungssatz an.">
						<template #action>
							<NcButton variant="tertiary" @click="openHelp('bookings')">Mehr dazu</NcButton>
						</template>
					</NcEmptyContent>
					</template>

					<!-- TRANSACTIONS VIEW (unassigned / assigned) -->
					<template v-else>
						<div v-if="bookingView === 'unassigned' && assignProgress.total > 0" class="vbh-progress">
							<span class="vbh-progress-label">{{ assignProgress.done }} von {{ assignProgress.total }} Bankbuchungen zugeordnet</span>
							<div class="vbh-progress-bar"><div class="vbh-progress-fill" :style="{ width: assignProgress.pct + '%' }"></div></div>
						</div>
						<div v-if="currentTransactions.length && isMobile" class="vbh-cardlist">
							<div class="vbh-tablecount">{{ currentTransactions.length }} Buchungen</div>
							<div v-for="tx in currentTransactions" :key="'m' + tx.id" class="vbh-mcard" :class="tx.status === 'assigned' ? '' : 'open'">
								<div class="vbh-mcard-top">
									<span class="vbh-mcard-meta">{{ formatDate(tx.bookingDate) }}</span>
									<span class="vbh-mcard-amount" :class="tx.amount < 0 ? 'neg' : 'pos'">{{ formatMoney(tx.amount) }}</span>
								</div>
								<div class="vbh-mcard-title">{{ tx.counterparty }}</div>
								<div v-if="tx.purpose" class="vbh-mcard-purpose">{{ tx.purpose }}</div>
								<button
									v-if="canWrite && !tx.contraAccountId && suggestionsById[tx.id] && !isYearClosed(tx.bookingDate)"
									type="button"
									class="vbh-suggest-chip vbh-suggest-chip--big"
									@click="applySuggestion(tx)"
								>
									✓ Vorschlag übernehmen: {{ suggestionsById[tx.id].label }}
								</button>
								<button type="button" class="vbh-fieldbtn" :disabled="!canWrite || isYearClosed(tx.bookingDate)" @click="openAccountPicker('assign', tx)">
									<span class="vbh-fieldbtn-text">
										<span class="vbh-fieldbtn-lab">Konto / Kategorie</span>
										<span class="vbh-fieldbtn-val" :class="{ placeholder: !tx.contraAccountId }">{{ tx.contraAccountId ? accountLabel(tx.contraAccountId) : 'Konto wählen…' }}</span>
									</span>
									<span class="vbh-fieldbtn-chev" aria-hidden="true">›</span>
								</button>
							</div>
						</div>
						<div v-else-if="currentTransactions.length" class="vbh-tablecard">
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
													:disabled="!canWrite || isYearClosed(tx.bookingDate)"
													label="label"
													placeholder="– nicht zugeordnet –"
													class="vbh-assign-select"
													@update:model-value="v => onAssign(tx, v ? v.id : '')"
												/>
											</div>
											<button
												v-if="canWrite && !tx.contraAccountId && suggestionsById[tx.id] && !isYearClosed(tx.bookingDate)"
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
			<section v-show="activeTab === 'accounts'" class="vbh-section split" :class="{ 'vbh-fadein': sectionFade, 'vbh-drill': isMobile }">
				<div v-if="!isMobile || !selectedAccountId" class="vbh-tree">
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
					<NcButton variant="tertiary" @click="openHelp('accounts')">Mehr dazu</NcButton>
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

				<div v-if="!isMobile || selectedAccountId" class="vbh-detail">
					<div v-if="isMobile" class="vbh-backbar">
						<button type="button" class="vbh-backbtn" @click="closeAccountDetail">‹ Konten</button>
					</div>
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

						<div v-if="statementRows.length && isMobile" class="vbh-cardlist">
							<div v-if="statement.carry" class="vbh-mcard">
								<div class="vbh-mcard-top">
									<span class="vbh-mcard-title">Saldovortrag aus Vorjahr</span>
									<span class="vbh-mcard-amount" :class="amountClass(statement.carry)">{{ formatMoney(statement.carry) }}</span>
								</div>
							</div>
							<div v-for="(row, i) in statementRows" :key="'m' + i" class="vbh-mcard">
								<div class="vbh-mcard-top">
									<span class="vbh-mcard-meta">#{{ row.entryNo }} · {{ formatDate(row.date) }}</span>
									<span class="vbh-mcard-amount" :class="statementRowNet(row) < 0 ? 'neg' : 'pos'">{{ formatMoney(statementRowNet(row)) }}</span>
								</div>
								<div class="vbh-mcard-title">{{ row.description }}</div>
								<div class="vbh-mcard-bottom">
									<span class="vbh-mcard-accounts">{{ row.contra }}</span>
									<span class="vbh-mcard-meta">Saldo {{ formatMoney(row.saldo) }}</span>
								</div>
							</div>
							<div class="vbh-mcard vbh-mcard--sum">
								<div class="vbh-mcard-top">
									<span class="vbh-mcard-title">Saldo{{ selectedYear ? ' ' + selectedYear : '' }}</span>
									<span class="vbh-mcard-amount" :class="amountClass(statement.totals.balance)">{{ formatMoney(statement.totals.balance) }}</span>
								</div>
								<div class="vbh-mcard-bottom">
									<span class="vbh-mcard-accounts">{{ statement.totals.count }} Buchungen · Soll {{ formatMoney(statement.totals.debit) }} · Haben {{ formatMoney(statement.totals.credit) }}</span>
								</div>
							</div>
						</div>
						<div v-else-if="statementRows.length" class="vbh-tablecard">
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
					<button :class="{ active: reportView === 'spheres' }" @click="reportView = 'spheres'">Sphären</button>
						<button :class="{ active: reportView === 'budget' }" @click="reportView = 'budget'">Finanzplan</button>
						<button :class="{ active: reportView === 'audit' }" @click="reportView = 'audit'">Protokoll</button>
					</div>
					<div class="vbh-sectiontop-actions">
						<a v-if="reportView === 'summary' && selectedYear" :href="kassenberichtUrl" target="_blank" rel="noopener" class="vbh-export-btn" title="Druckfertiger Kassenbericht für die Mitgliederversammlung (öffnet in neuem Tab, dort drucken oder als PDF speichern)"><NcIconSvgWrapper :path="mdiPrinter" :size="16" inline /> Kassenbericht</a>
						<a v-if="reportView === 'summary' && selectedYear" :href="attachmentsZipUrl" download class="vbh-export-btn" title="Alle Belege des Jahres als ZIP herunterladen (für die Kassenprüfung)"><NcIconSvgWrapper :path="mdiPaperclip" :size="16" inline /> Beleg-ZIP</a>
					<a v-if="reportView === 'summary'" :href="pruefleitfadenUrl" target="_blank" rel="noopener" class="vbh-export-btn" title="Druckfertige 1-Seiten-Kurzanleitung für Kassenprüfer/innen (öffnet in neuem Tab)"><NcIconSvgWrapper :path="mdiPrinter" :size="16" inline /> Prüfleitfaden</a>
						<a v-if="reportView === 'summary'" :href="exportBalancesUrl" download class="vbh-export-btn" title="Saldenliste als CSV exportieren"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> Saldenliste</a>
						<a v-if="reportView === 'summary'" :href="exportReportUrl" download class="vbh-export-btn" title="E/A-Übersicht als CSV exportieren"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> E/A-Übersicht</a>
						<a v-if="reportView === 'summary'" :href="exportMultiyearUrl" download class="vbh-export-btn" title="Mehrjahresübersicht (alle Jahre) als CSV exportieren"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> Mehrjahresübersicht</a>
						<a v-if="reportView === 'budget'" :href="exportBudgetUrl" download class="vbh-export-btn" title="Soll-Ist-Vergleich als CSV exportieren"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> Soll-Ist-Vergleich</a>
					</div>
				</div>

				<div class="vbh-sectionbody" :class="{ 'is-split': reportView === 'costcenters' || reportView === 'spheres' }">
					<!-- AUSWERTUNG -->
					<div v-show="reportView === 'summary'">
						<div v-if="balances" class="vbh-totals">
							<div class="vbh-total pos"><span>Einnahmen</span><strong>{{ formatMoney(balances.totals.income) }}</strong></div>
							<div class="vbh-total neg"><span>Ausgaben</span><strong>{{ formatMoney(balances.totals.expense) }}</strong></div>
							<div class="vbh-total" :class="balances.totals.result >= 0 ? 'pos' : 'neg'"><span>Ergebnis</span><strong>{{ formatMoney(balances.totals.result) }}</strong></div>
						</div>

						<template v-if="balances && balances.bankReconciliation && balances.bankReconciliation.length">
							<h4>Geldkonten</h4>
							<div v-if="!isMobile" class="vbh-tablecard">
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
							<NcCheckboxRadioSwitch v-model="balancesIncludeChildren">Werte inkl. Unterkonten</NcCheckboxRadioSwitch>
						</div>
						<div v-if="balances && isMobile" class="vbh-cardlist">
							<div v-for="row in sortedBalances" :key="'m' + row.accountId"
								class="vbh-mcard" :class="{ 'vbh-mcard--parent': row.isParent }"
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

					<!-- KOSTENSTELLEN (split layout; mobil Drilldown) -->
					<div v-show="reportView === 'costcenters'" class="vbh-splitinner" :class="{ 'vbh-drill': isMobile }">
						<div v-if="!isMobile || !selectedCC" class="vbh-tree">
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

						<div v-if="!isMobile || selectedCC" class="vbh-detail">
							<div v-if="isMobile" class="vbh-backbar">
								<button type="button" class="vbh-backbtn" @click="selectedCCCode = false">‹ Kostenstellen</button>
							</div>
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
								<div v-if="selectedCC.accounts.length && isMobile" class="vbh-cardlist">
									<div v-for="(a, i) in selectedCC.accounts" :key="'m' + i"
										class="vbh-mcard tappable" role="button" tabindex="0"
										@click="toggleCCAccount(a.accountId)" @keyup.enter="toggleCCAccount(a.accountId)">
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
											<p v-else class="vbh-empty">Keine Buchungen.</p>
										</div>
									</div>
								</div>
								<div v-else-if="selectedCC.accounts.length" class="vbh-tablecard">
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

					<!-- SPHÄREN -->
					<div v-show="reportView === 'spheres'" class="vbh-splitinner" :class="{ 'vbh-drill': isMobile }">
						<div v-if="!isMobile || !selectedSphere" class="vbh-tree">
							<div class="vbh-treehead">
								<strong>Sphären</strong>
								<button type="button" class="vbh-sphere-help" title="Was bedeutet das?" @click="openHelp('spheres')">?</button>
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
								<div v-for="s in sphereData.spheres" :key="s.code || 'none'"
									class="vbh-treenode" :class="{ selected: isSphereSelected(s) }" @click="selectSphere(s)">
									<span class="vbh-treename">{{ s.name }}</span>
									<span class="vbh-treesaldo" :class="[amountClass(s.result), { zero: !s.result }]">{{ formatMoney(s.result) }}</span>
								</div>
							</div>
							<p v-else class="vbh-hint">Keine Daten. Importiere oder erfasse zuerst Buchungen.</p>
						</div>

						<div v-if="!isMobile || selectedSphere" class="vbh-detail">
							<div v-if="isMobile" class="vbh-backbar">
								<button type="button" class="vbh-backbtn" @click="selectedSphereCode = false">‹ Sphären</button>
							</div>
							<p v-if="!selectedSphere" class="vbh-empty vbh-detailhint">Sphäre links auswählen.</p>
							<template v-else>
								<div class="vbh-detailhead"><div><h3>{{ selectedSphere.name }}</h3></div></div>

								<div class="vbh-totals">
									<div class="vbh-total pos"><span>Einnahmen</span><strong>{{ formatMoney(selectedSphere.income) }}</strong></div>
									<div class="vbh-total neg"><span>Ausgaben</span><strong>{{ formatMoney(selectedSphere.expense) }}</strong></div>
									<div class="vbh-total" :class="selectedSphere.result >= 0 ? 'pos' : 'neg'"><span>Ergebnis</span><strong>{{ formatMoney(selectedSphere.result) }}</strong></div>
								</div>

								<h4>Beteiligte Konten</h4>
								<div v-if="selectedSphere.accounts.length" class="vbh-tablecard">
									<table class="vbh-table">
										<thead><tr><th class="nowrap">Nr.</th><th>Konto</th><th>Art</th><th class="num">Betrag</th></tr></thead>
										<tbody>
											<tr v-for="a in selectedSphere.accounts" :key="a.accountId">
												<td class="nowrap">{{ a.number }}</td>
												<td>{{ a.name }}</td>
												<td><span class="vbh-typetag" :class="a.type">{{ typeLabel(a.type) }}</span></td>
												<td class="num" :class="amountClass(a.balance)">{{ formatMoney(a.balance) }}</td>
											</tr>
										</tbody>
									</table>
								</div>
								<p v-else class="vbh-empty">Keine Buchungen mit Betrag in dieser Sphäre.</p>
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

					<!-- ÄNDERUNGSPROTOKOLL -->
					<div v-show="reportView === 'audit'">
						<p class="vbh-hint">
							Wer hat wann was geändert – z. B. für die Kassenprüfung. Das Protokoll wird
							automatisch geführt und bleibt auch beim Zurücksetzen aller Daten erhalten.
						</p>
						<div v-if="auditEntries.length" class="vbh-tablecard">
							<table class="vbh-table">
								<thead><tr><th class="nowrap">Zeitpunkt</th><th>Wer</th><th>Aktion</th><th>Details</th></tr></thead>
								<tbody>
									<tr v-for="a in auditEntries" :key="a.id">
										<td class="nowrap">{{ formatDateTime(a.ts) }}</td>
										<td class="nowrap">{{ a.userId }}</td>
										<td class="nowrap">{{ a.action }}</td>
										<td class="vbh-purpose"><span class="vbh-clamp">{{ auditDetailText(a) }}</span></td>
									</tr>
								</tbody>
							</table>
						</div>
						<NcEmptyContent v-else-if="!auditLoading" name="Noch keine Protokolleinträge" description="Änderungen ab Version 0.10.41 werden hier aufgezeichnet.">
						<template #action>
							<NcButton variant="tertiary" @click="openHelp('reports')">Mehr dazu</NcButton>
						</template>
					</NcEmptyContent>
						<div v-if="auditEntries.length && !auditEnd" class="vbh-loadmore">
							<NcButton variant="secondary" :disabled="auditLoading" @click="loadAudit(true)">Ältere Einträge laden</NcButton>
						</div>
					</div>
				</div>
			</section>
		</main>

		<MobileNav v-if="canRead && isMobile"
			:tabs="visibleTabs"
			:active-tab="activeTab"
			:unassigned-count="unassignedCount"
			:can-write="canWrite"
			@select="id => { activeTab = id }"
			@new-booking="openNewBooking" />

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

				<SettingsXbucImport
					:imports="imports"
					:busy.sync="busy"
					:ask-confirm="askConfirm"
					@changed="onXbucImported"
					@help="openHelp('bookings')"
				/>

				<SettingsRules
					v-if="canWrite"
					:rules="rules"
					:accounts-by-id="accountsById"
					:account-options-list="accountOptionsList"
					:ask-confirm="askConfirm"
					@changed="loadRules"
				/>

				<SettingsSpheres
					v-if="canWrite"
					:accounts="accounts"
					@changed="onSpheresChanged"
					@help="openHelp('spheres')"
				/>

				<SettingsPermissions
					v-if="isAdmin"
					:ask-confirm="askConfirm"
					@help="openHelp('setup')"
				/>

				<SettingsGeneral
					v-if="isAdmin"
					:club-name.sync="clubName"
					:cost-center-mode.sync="costCenterMode"
					:storage-user.sync="storageUser"
					:storage-path.sync="storagePath"
					:users="users"
					:storage-saving="storageSaving"
					:save-storage-settings="saveStorageSettings"
				/>

				<SettingsYearClose
					v-if="isAdmin"
					:ask-confirm="askConfirm"
					:busy="busy"
					:reset-all="resetAll"
				/>
			</div>
		</NcModal>

		<!-- ============ IMPORT-DIALOG (CSV-CAMT) ============ -->
		<ImportDialog
			:show="showImport"
			:busy.sync="busy"
			@update:show="showImport = $event"
			@close="closeImport"
			@go-assign="goAssignAfterImport"
			@imported="onImported"
		/>

		<!-- ============ BUCHUNGS-DIALOG ============ -->
		<NcModal :show.sync="showBooking" :name="bookingForm.id ? 'Buchung bearbeiten #' + bookingForm.entryNo : 'Neue Buchung'" :size="isMobile ? 'full' : 'normal'" @close="closeBooking">
			<div class="vbh-modal-inner">
				<p v-if="bookingLocked" class="vbh-hint vbh-hint--info">
					🔒 Das Geschäftsjahr {{ String(bookingForm.date).slice(0, 4) }} ist abgeschlossen –
					diese Buchung kann nur noch angesehen werden.
				</p>
				<div v-if="bookingMode === 'simple'" class="vbh-kindtoggle" :class="{ 'vbh-tour-target': bookingTour.active && bookingTour.step === 0 }" role="radiogroup" aria-label="Buchungsart">
					<button type="button" class="vbh-kindbtn income" :class="{ active: bookingForm.kind === 'income' }" :disabled="bookingLocked" @click="setBookingKind('income')">Einnahme</button>
					<button type="button" class="vbh-kindbtn expense" :class="{ active: bookingForm.kind === 'expense' }" :disabled="bookingLocked" @click="setBookingKind('expense')">Ausgabe</button>
				</div>
				<div v-if="bookingTour.active && bookingTour.step === 0" class="vbh-tour-tip">
					<span>Wähle zuerst, ob Geld reinkommt oder rausgeht – Schritt 1 von 3.</span>
					<div class="vbh-tour-actions">
						<button type="button" class="vbh-tour-skip" @click="endTour">Überspringen</button>
						<NcButton variant="primary" @click="nextTourStep">Weiter</NcButton>
					</div>
				</div>

				<!-- Mobil: Betrag zuerst und groß, Kontenwahl über Auswahl-Sheets -->
				<template v-if="isMobile">
					<div class="vbh-bigamount">
						<input v-model.number="bookingForm.amount"
							type="number" step="0.01" min="0.01" inputmode="decimal"
							placeholder="0,00" class="vbh-bigamount-input" aria-label="Betrag in Euro"
							:disabled="bookingLocked">
						<span class="vbh-bigamount-cur">€</span>
					</div>
					<div class="vbh-mfields">
						<template v-if="bookingMode === 'simple'">
							<button type="button" class="vbh-fieldbtn" :disabled="bookingLocked" @click="openAccountPicker('category')">
								<span class="vbh-fieldbtn-text">
									<span class="vbh-fieldbtn-lab">{{ bookingForm.kind === 'income' ? 'Wofür? (Einnahme-Kategorie)' : 'Wofür? (Ausgabe-Kategorie)' }}</span>
									<span class="vbh-fieldbtn-val" :class="{ placeholder: !bookingForm.categoryId }">{{ bookingForm.categoryId ? accountLabel(bookingForm.categoryId) : 'Kategorie wählen…' }}</span>
								</span>
								<span class="vbh-fieldbtn-chev" aria-hidden="true">›</span>
							</button>
							<button type="button" class="vbh-fieldbtn" :disabled="bookingLocked" @click="openAccountPicker('money')">
								<span class="vbh-fieldbtn-text">
									<span class="vbh-fieldbtn-lab">Geldkonto (Bank/Kasse)</span>
									<span class="vbh-fieldbtn-val" :class="{ placeholder: !bookingForm.moneyAccountId }">{{ bookingForm.moneyAccountId ? accountLabel(bookingForm.moneyAccountId) : 'wählen…' }}</span>
								</span>
								<span class="vbh-fieldbtn-chev" aria-hidden="true">›</span>
							</button>
						</template>
						<template v-else>
							<button type="button" class="vbh-fieldbtn" :disabled="bookingLocked" @click="openAccountPicker('debit')">
								<span class="vbh-fieldbtn-text">
									<span class="vbh-fieldbtn-lab">Soll (Aufwand/Aktiv)</span>
									<span class="vbh-fieldbtn-val" :class="{ placeholder: !bookingForm.debitAccountId }">{{ bookingForm.debitAccountId ? accountLabel(bookingForm.debitAccountId) : 'wählen…' }}</span>
								</span>
								<span class="vbh-fieldbtn-chev" aria-hidden="true">›</span>
							</button>
							<button type="button" class="vbh-fieldbtn" :disabled="bookingLocked" @click="openAccountPicker('credit')">
								<span class="vbh-fieldbtn-text">
									<span class="vbh-fieldbtn-lab">Haben (Ertrag/Passiv)</span>
									<span class="vbh-fieldbtn-val" :class="{ placeholder: !bookingForm.creditAccountId }">{{ bookingForm.creditAccountId ? accountLabel(bookingForm.creditAccountId) : 'wählen…' }}</span>
								</span>
								<span class="vbh-fieldbtn-chev" aria-hidden="true">›</span>
							</button>
						</template>
						<label class="vbh-mfield">Datum<input v-model="bookingForm.date" type="date" :disabled="bookingLocked"></label>
						<label class="vbh-mfield">Buchungstext<input v-model="bookingForm.description" placeholder="z. B. Mitgliedsbeitrag Max Mustermann" :disabled="bookingLocked"></label>
						<label class="vbh-mfield">Beleg-Nr.<input v-model="bookingForm.documentRef" placeholder="optional" :disabled="bookingLocked"></label>
						<!-- Beleg schon beim Anlegen: Dateien werden lokal gesammelt und
						     nach dem Speichern an die neue Buchung gehängt. -->
						<div v-if="canWrite && !bookingForm.id" class="vbh-mfield">
							<span>Beleg</span>
							<div class="vbh-pendingbtns">
								<label class="vbh-upload-label">
									<input type="file" accept="image/*" capture="environment" hidden @change="addPendingFiles">
									<span class="vbh-upload-btn"><NcIconSvgWrapper :path="mdiCamera" :size="16" /> Fotografieren</span>
								</label>
								<label class="vbh-upload-label">
									<input type="file" accept="image/*,application/pdf" multiple hidden @change="addPendingFiles">
									<span class="vbh-upload-btn"><NcIconSvgWrapper :path="mdiPaperclip" :size="16" /> Datei…</span>
								</label>
							</div>
							<ul v-if="pendingFiles.length" class="vbh-attachment-list">
								<li v-for="(pf, i) in pendingFiles" :key="i" class="vbh-attachment-item">
									<NcIconSvgWrapper :path="mdiPaperclip" :size="14" class="vbh-attachment-icon" />
									<span class="vbh-attachment-name">{{ pf.name }}</span>
									<span class="vbh-attachment-size">{{ formatFileSize(pf.size) }}</span>
									<NcButton variant="tertiary" aria-label="Beleg entfernen" @click="pendingFiles.splice(i, 1)">
										<template #icon><NcIconSvgWrapper :path="mdiDelete" :size="14" /></template>
									</NcButton>
								</li>
							</ul>
						</div>
					</div>
				</template>

				<!-- Desktop: bisheriges Formular-Layout -->
				<template v-else>
					<div class="vbh-form">
						<label>Datum<input v-model="bookingForm.date" type="date" :disabled="bookingLocked"></label>
						<label>Beleg-Nr.<input v-model="bookingForm.documentRef" class="vbh-short" placeholder="optional" :disabled="bookingLocked"></label>
						<label>Betrag (€)<input v-model.number="bookingForm.amount" type="number" step="0.01" min="0.01" class="vbh-num" :disabled="bookingLocked"></label>
					</div>
					<template v-if="bookingMode === 'simple'">
						<div class="vbh-form" :class="{ 'vbh-tour-target': bookingTour.active && bookingTour.step === 1 }">
							<label class="vbh-grow">{{ bookingForm.kind === 'income' ? 'Wofür? (Einnahme-Kategorie)' : 'Wofür? (Ausgabe-Kategorie)' }}
								<NcSelect
									v-model="bookingFormCategoryOption"
									:options="simpleCategoryOptions"
									:filter-by="accountFilterBy"
									:disabled="bookingLocked"
									label="label"
									placeholder="– Kategorie wählen –"
								/>
							</label>
							<label class="vbh-grow">Geldkonto (Bank/Kasse)
								<NcSelect
									v-model="bookingFormMoneyOption"
									:options="moneyAccountOptions"
									:filter-by="accountFilterBy"
									:disabled="bookingLocked"
									label="label"
									placeholder="– wählen –"
								/>
							</label>
						</div>
						<div v-if="bookingTour.active && bookingTour.step === 1" class="vbh-tour-tip">
							<span>Wähle die Kategorie (z. B. „Mitgliedsbeiträge") und das Geldkonto – die App bucht Soll/Haben automatisch richtig. Schritt 2 von 3.</span>
							<div class="vbh-tour-actions">
								<button type="button" class="vbh-tour-skip" @click="endTour">Überspringen</button>
								<NcButton variant="primary" @click="nextTourStep">Weiter</NcButton>
							</div>
						</div>
					</template>
					<template v-else>
						<div class="vbh-form">
							<label class="vbh-grow">Soll (Aufwand/Aktiv)
								<NcSelect
									v-model="bookingFormDebitOption"
									:options="accountOptionsList"
									:filter-by="accountFilterBy"
									:disabled="bookingLocked"
									label="label"
									placeholder="– wählen –"
								/>
							</label>
							<label class="vbh-grow">Haben (Ertrag/Passiv)
								<NcSelect
									v-model="bookingFormCreditOption"
									:options="accountOptionsList"
									:filter-by="accountFilterBy"
									:disabled="bookingLocked"
									label="label"
									placeholder="– wählen –"
								/>
							</label>
						</div>
					</template>
					<div class="vbh-form" :class="{ 'vbh-tour-target': bookingTour.active && bookingTour.step === 2 }">
						<label class="vbh-grow">Buchungstext<input v-model="bookingForm.description" placeholder="z. B. Mitgliedsbeitrag Max Mustermann" :disabled="bookingLocked"></label>
					</div>
					<div v-if="bookingTour.active && bookingTour.step === 2" class="vbh-tour-tip">
						<span>Ein kurzer Text erklärt später, worum es ging – fertig! Schritt 3 von 3.</span>
						<div class="vbh-tour-actions">
							<NcButton variant="primary" @click="endTour">Verstanden</NcButton>
						</div>
					</div>
				</template>
				<div class="vbh-expertrow">
					<NcCheckboxRadioSwitch v-model="bookingModeExpert" type="switch">
						Experten-Modus (Soll/Haben direkt wählen)
					</NcCheckboxRadioSwitch>
				</div>

				<!-- Belegablage (nur bei bestehenden Buchungen verfügbar) -->
				<div v-if="bookingForm.id" class="vbh-attachments">
					<div class="vbh-attachments-header">
						<span class="vbh-attachments-title">Belege</span>
						<label v-if="canWrite && !bookingLocked" class="vbh-upload-label" :class="{ 'is-uploading': attachmentUploading }">
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
							<NcButton v-if="canWrite && !bookingLocked" variant="tertiary" :aria-label="'Beleg löschen'" @click="deleteAttachment(a.id)">
								<template #icon><NcIconSvgWrapper :path="mdiDelete" :size="14" /></template>
							</NcButton>
						</li>
					</ul>
					<p v-else class="vbh-attachment-empty">Noch kein Beleg angehängt.</p>
				</div>

				<div class="vbh-modal-actions">
					<NcButton v-if="isMobile && bookingForm.id && canWrite && !bookingLocked" variant="error" @click="deleteBookingFromDialog">Löschen</NcButton>
					<NcButton variant="tertiary" @click="closeBooking">{{ bookingLocked ? 'Schließen' : 'Abbrechen' }}</NcButton>
					<NcButton v-if="!bookingLocked" variant="primary" @click="saveBooking">{{ bookingForm.id ? 'Speichern' : 'Buchen' }}</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- ============ KONTO-DIALOG ============ -->
		<AccountDialog
			:show="showAccount"
			:account-edit-id="accountEditId"
			:initial-form="newAccount"
			@update:show="showAccount = $event"
			@close="closeAccount"
			@save="saveAccount"
			@help="openHelp('spheres')"
		/>

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
		<!-- ============ KONTOAUSWAHL-SHEET (mobil) ============ -->
		<AccountPickerSheet
			:open="accountPicker.open"
			:title="accountPicker.title"
			:options="accountPickerOptions"
			:recent="recentAccountOptions"
			:suggestion="accountPickerSuggestion"
			:current-id="accountPickerCurrentId"
			@close="closeAccountPicker"
			@pick="onAccountPicked"
			@suggest="onAccountPickerSuggest" />

		<!-- ============ HILFE ============ -->
		<HelpModal :show="showHelp" :topic="helpTopic" @close="closeHelp" @update:show="showHelp = $event" />

		<!-- ============ SETUP-ASSISTENT (erster Verwalter-Login) ============ -->
		<SetupWizard :show="showSetupWizard" @close="closeSetupWizard" @update:show="showSetupWizard = $event" @choose="onWizardChoice" />

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
import { toRefs } from 'vue'
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
import { mdiCamera, mdiCog, mdiCommentPlusOutline, mdiCommentText, mdiDelete, mdiPaperclip, mdiPencil, mdiPlus, mdiUpload, mdiDownload, mdiFlash, mdiPrinter, mdiViewDashboardOutline, mdiSwapHorizontal, mdiFileTreeOutline, mdiChartBar, mdiHelpCircleOutline } from '@mdi/js'
import api from './api.js'
import { formatMoney, formatDate, formatDateTime, typeLabel, roleLabel, amountClass, budgetDiffClass, errMsg } from './lib/format.js'
import SettingsRules from './components/SettingsRules.vue'
import SettingsSpheres from './components/SettingsSpheres.vue'
import SettingsXbucImport from './components/SettingsXbucImport.vue'
import SettingsPermissions from './components/SettingsPermissions.vue'
import SettingsGeneral from './components/SettingsGeneral.vue'
import SettingsYearClose from './components/SettingsYearClose.vue'
import ImportDialog from './components/ImportDialog.vue'
import AccountDialog from './components/AccountDialog.vue'
import MobileNav from './components/MobileNav.vue'
import BookingCard from './components/BookingCard.vue'
import AccountPickerSheet from './components/AccountPickerSheet.vue'
import HelpModal from './components/HelpModal.vue'
import SetupChecklist from './components/SetupChecklist.vue'
import SetupWizard from './components/SetupWizard.vue'
import { useAuth } from './composables/useAuth.js'
import { useYears } from './composables/useYears.js'
import { useAccounts } from './composables/useAccounts.js'
import { useBalances } from './composables/useBalances.js'
import { useJournal } from './composables/useJournal.js'
import { usePermissions } from './composables/usePermissions.js'
import { useSync } from './composables/useSync.js'
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
		SettingsSpheres,
		SettingsXbucImport,
		SettingsPermissions,
		SettingsGeneral,
		SettingsYearClose,
		ImportDialog,
		AccountDialog,
		MobileNav,
		BookingCard,
		AccountPickerSheet,
		HelpModal,
		SetupChecklist,
		SetupWizard,
	},
	setup() {
		const auth = useAuth()
		const years = useYears()
		const accounts = useAccounts()
		const balances = useBalances()
		const journal = useJournal()
		const permissions = usePermissions()
		const sync = useSync()
		return {
			...toRefs(auth.state),
			canRead: auth.canRead,
			canWrite: auth.canWrite,
			isAdmin: auth.isAdmin,
			// eigener Name, damit App.vue seine eigene loadMe()-Methode mit
			// Zusatzlogik (Tab-Umschalten, Revisor-Hinweis) behalten kann.
			authLoadMe: auth.loadMe,
			...toRefs(years.state),
			closedYearSet: years.closedYearSet,
			yearClosed: years.yearClosed,
			isYearClosed: years.isYearClosed,
			loadYears: years.loadYears,
			loadClosedYears: years.loadClosedYears,
			...toRefs(accounts.state),
			accountsById: accounts.accountsById,
			accountsSorted: accounts.accountsSorted,
			childrenOf: accounts.childrenOf,
			// eigene Namen, da App.vue-eigene loadAccounts()/seedAccounts() noch
			// das lokale openingForm nachziehen bzw. eine Erfolgsmeldung zeigen.
			accountsLoad: accounts.loadAccounts,
			accountsSeedDefaults: accounts.seedDefaults,
			...toRefs(balances.state),
			loadBalances: balances.loadBalances,
			// eigener Name, damit App.vue seine eigene loadSphereReport()-Methode
			// mit Zusatzlogik (selectedSphereCode zurücksetzen) behalten kann.
			balancesLoadSphereReport: balances.loadSphereReport,
			...toRefs(journal.state),
			journalRows: journal.journalRows,
			unassignedCount: journal.unassignedCount,
			// eigener Name, da App.vue seine eigene loadJournal()-Methode mit
			// Zusatzlogik (Beleg-Zähler nachladen) behalten kann.
			journalLoad: journal.loadJournal,
			loadTransactions: journal.loadTransactions,
			...toRefs(permissions.state),
			loadPermissions: permissions.loadPermissions,
			...toRefs(sync.state),
			checkRemoteRevision: sync.checkRemoteRevision,
		}
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
			newBudgetYear: '',
			budgetData: null,
			budgetNoteOpen: {},
			budgetSnapshots: [],
			newSnapshotLabel: '',
			snapshotView: { open: false, data: null },
			busy: false,
			imports: [],
			balancesIncludeChildren: false,
			reportData: null,
			selectedCCCode: false,
			selectedSphereCode: false,
			renameName: '',
			ccExpanded: {},
			ccBookings: {},
			newAccount: { number: '', name: '', type: 'income', category: '', isBank: false, parentId: null, sphere: '' },
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
			rules: [],
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
			mdiDownload,
			mdiFlash,
			mdiPrinter,
			chartInstances: {},
			bookingAttachments: [],
			attachmentUploading: false,
			attachmentCountMap: {},
			// Kollaboration: Poll-Timer (Änderungsstand selbst kommt aus useSync)
			syncTimer: null,
			// Mobil-Layout (≤ 640px): schaltet Bottom-Nav, Kartenlisten etc.
			isMobile: false,
			// Kontoauswahl-Sheet (mobil): target = category|money|debit|credit|assign
			accountPicker: { open: false, target: null, title: '', tx: null },
			// Belege, die beim Anlegen gesammelt und nach dem Speichern hochgeladen werden
			pendingFiles: [],
			// Zuletzt im Auswahl-Sheet gewählte Konten (localStorage, max. 5)
			recentAccountIds: [],
			// Änderungsprotokoll (Berichte → Protokoll)
			auditEntries: [],
			auditLoading: false,
			auditEnd: false,
			// Kassenprüfung: Journal auf Buchungen ohne Beleg einschränken
			journalOnlyNoAttachment: false,
			storageUser: '',
			storagePath: '',
			costCenterMode: 'group',
			clubName: '',
			storageSaving: false,
			// Hilfe-Modal (HelpModal.vue): Kapitel folgt standardmäßig dem aktiven Tab,
			// kann aber gezielt überschrieben werden (z. B. Links aus Leerzuständen).
			showHelp: false,
			helpForcedTopic: null,
			// Einmaliger Willkommenshinweis für die Rolle „Revisor" (localStorage, dauerhaft ausblendbar)
			revisorIntroDismissed: true,
			// Geführter Setup-Assistent (SetupWizard.vue) beim allerersten Verwalter-Login
			showSetupWizard: false,
			// Beispieldaten (DemoDataService) aktiv – Banner mit Zurücksetzen-Hinweis
			demoActive: false,
			// Erste-Buchung-Tour (Feld-Hervorhebung im Einfach-Modus, Desktop, einmalig)
			bookingTour: { active: false, step: 0 },
			mdiHelpCircleOutline,
		}
	},
	computed: {
		// canRead/canWrite/isAdmin/closedYearSet/yearClosed kommen aus setup() (useAuth/useYears).
		// Bearbeiten-Dialog einer Buchung aus einem abgeschlossenen Jahr → nur ansehen
		bookingLocked() { return !!(this.bookingForm.id && this.isYearClosed(this.bookingForm.date)) },
		exportJournalUrl()  { return api.exportJournalUrl(this.selectedYear) },
		exportBalancesUrl() { return api.exportBalancesUrl(this.selectedYear) },
		exportReportUrl()   { return api.exportReportUrl(this.selectedYear) },
		exportBudgetUrl()   { return api.exportBudgetUrl(this.selectedYear) },
		exportMultiyearUrl() { return api.exportMultiyearUrl() },
		kassenberichtUrl() { return api.kassenberichtUrl(this.selectedYear) },
		pruefleitfadenUrl() { return api.pruefleitfadenUrl() },
		attachmentsZipUrl() { return api.exportAttachmentsUrl(this.selectedYear) },
		visibleTabs() {
			return this.allTabs.filter(t => {
				if (t.need === 'admin') return this.isAdmin
				if (t.need === 'write') return this.canWrite
				return this.canRead
			})
		},
		// Hilfe-Kapitel, das zum gerade aktiven Tab passt (HelpModal-Default)
		helpTopic() {
			if (this.helpForcedTopic) return this.helpForcedTopic
			const map = { dashboard: 'setup', bookings: 'bookings', accounts: 'accounts', reports: 'reports' }
			return map[this.activeTab] || 'setup'
		},
		showRevisorIntro() {
			return !!(this.me && this.me.role === 'revisor' && !this.revisorIntroDismissed)
		},
		// unassignedCount kommt aus setup() (useJournal).
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
			if (this.journalOnlyNoAttachment) {
				rows = rows.filter(r => !this.attachmentCountMap[r.id])
			}
			return rows
		},
		// Kassenprüfung: fehlende/doppelte Buchungsnummern im gewählten Jahr
		// (bei „Alle Jahre" nicht sinnvoll, da je Jahr nummeriert wird).
		journalNumberIssues() {
			if (!this.selectedYear) return null
			const nos = this.journalRows
				.map(r => r.entryNo)
				.filter(n => n != null)
				.map(Number)
				.sort((a, b) => a - b)
			if (!nos.length) return null
			const missing = []
			const duplicates = []
			for (let i = 1; i < nos.length; i++) {
				if (nos[i] === nos[i - 1]) { duplicates.push(nos[i]); continue }
				for (let n = nos[i - 1] + 1; n < nos[i] && missing.length <= 20; n++) missing.push(n)
			}
			if (!missing.length && !duplicates.length) return null
			return { missing, duplicates: [...new Set(duplicates)] }
		},
		recentJournal() {
			return this.sortedJournalRows.slice(0, 5)
		},
		// Mobil: Journal fest nach Datum absteigend, gruppiert nach Monat
		// (die Spaltenkopf-Sortierung der Tabelle entfällt auf Karten).
		journalCardGroups() {
			const rows = [...this.filteredJournalRows].sort((a, b) =>
				String(b.date || '').localeCompare(String(a.date || '')) || (b.entryNo || 0) - (a.entryNo || 0))
			const names = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember']
			const groups = []
			let cur = null
			for (const r of rows) {
				const key = String(r.date || '').slice(0, 7)
				if (!cur || cur.key !== key) {
					const m = parseInt(key.slice(5, 7), 10)
					cur = { key, label: (names[m - 1] || '?') + ' ' + key.slice(0, 4), rows: [] }
					groups.push(cur)
				}
				cur.rows.push(r)
			}
			return groups
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
		// accountsById/accountsSorted/childrenOf kommen aus setup() (useAccounts).
		// parentOptions/accountParentOptions/accountParentOption sind jetzt Teil
		// von AccountDialog.vue (eigenes setup() mit useAccounts()).
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
		// Kontoauswahl-Sheet (mobil): Optionen/Vorschlag/Auswahl je nach Ziel
		accountPickerOptions() {
			const t = this.accountPicker.target
			if (t === 'category') return this.simpleCategoryOptions
			if (t === 'money') return this.moneyAccountOptions
			return this.accountOptionsList
		},
		accountPickerSuggestion() {
			const p = this.accountPicker
			return (p.target === 'assign' && p.tx && this.suggestionsById[p.tx.id]) || null
		},
		recentAccountOptions() {
			const out = []
			for (const id of this.recentAccountIds) {
				const a = this.accountsById[id]
				if (a && a.active) out.push({ id: a.id, label: `${a.number} ${a.name}`, number: a.number })
			}
			return out
		},
		accountPickerCurrentId() {
			const t = this.accountPicker.target
			const f = this.bookingForm
			if (t === 'category') return f.categoryId
			if (t === 'money') return f.moneyAccountId
			if (t === 'debit') return f.debitAccountId
			if (t === 'credit') return f.creditAccountId
			if (t === 'assign' && this.accountPicker.tx) return this.accountPicker.tx.contraAccountId
			return null
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
		confirmDialogButtonList() {
			return [
				{ label: 'Abbrechen', type: 'secondary', callback: () => this.closeConfirm(false) },
				{ label: this.confirmDialog.confirmLabel, type: this.confirmDialog.confirmVariant, callback: () => this.closeConfirm(true) },
			]
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
		// journalRows kommt aus setup() (useJournal).
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
			else if (v === 'spheres') this.loadSphereReport()
			else if (v === 'budget') this.loadBudget()
			else if (v === 'audit') this.loadAudit()
		},
		async selectedYear() {
			// Jahresbezogene Caches invalidieren
			this.ccBookings = {}
			this.ccExpanded = {}
			this.busy = true
			try {
				const jobs = [this.loadBalances(), this.loadJournal(), this.loadSphereReport()]
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
		// Mobil-Layout reaktiv am selben Breakpoint wie das CSS (640px)
		this.vbhMql = window.matchMedia('(max-width: 640px)')
		this.isMobile = this.vbhMql.matches
		this.onMqChange = e => { this.isMobile = e.matches }
		if (this.vbhMql.addEventListener) this.vbhMql.addEventListener('change', this.onMqChange)
		else this.vbhMql.addListener(this.onMqChange)
		this.loadRecentAccounts()
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
				this.loadClosedYears(),
				this.loadSphereReport(),
			])
			this.$nextTick(() => setTimeout(() => this.renderDashboardCharts(), 50))
			// storage/demo-Status betrifft alle Leseberechtigten (Demo-Banner); Berechtigungsliste nur Verwalter (Backend-Gate)
			this.loadStorageSettings()
			if (this.isAdmin) {
				this.loadPermissions()
				// Setup-Assistent beim allerersten Login eines Verwalters (leerer Verein, noch nicht gesehen)
				if (this.accounts.length === 0 && !this.setupWizardSeen()) this.showSetupWizard = true
			}
			// Kollaboration: Änderungen anderer Personen per Polling mitbekommen
			this.checkRevision(true)
			this.syncTimer = setInterval(() => this.checkRevision(), 20000)
			window.addEventListener('focus', this.onWindowFocus)
		}
	},
	beforeDestroy() {
		document.removeEventListener('keydown', this.onGlobalKeydown)
		if (this.vbhMql) {
			if (this.vbhMql.removeEventListener) this.vbhMql.removeEventListener('change', this.onMqChange)
			else this.vbhMql.removeListener(this.onMqChange)
		}
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
				else if (this.reportView === 'spheres') jobs.push(this.loadSphereReport())
				else if (this.reportView === 'budget') jobs.push(this.loadBudget())
				else if (this.reportView === 'audit') jobs.push(this.loadAudit())
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
			const result = await this.checkRemoteRevision(init, this.busy)
			if (result !== 'changed') return
			// Nach eigener Schreibaktion still aktualisieren (die Handler haben schon
			// nachgeladen, aber eine zeitgleiche Fremdänderung darf nicht verloren gehen).
			const ownWrite = Date.now() - api.lastWriteAt() < 15000
			await this.refreshAfterRemoteChange()
			if (!ownWrite) showInfo('Die Buchhaltung wurde von einer anderen Person geändert – Ansicht aktualisiert.')
		},
		async refreshAfterRemoteChange() {
			this.ccBookings = {}
			this.ccExpanded = {}
			const jobs = [this.loadYears(), this.loadClosedYears(), this.loadAccounts(), this.loadBalances(), this.loadJournal(), this.loadTransactions(), this.loadSphereReport()]
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
				this.clubName = data.club_name || ''
				this.demoActive = !!data.demo_active
			} catch (e) { /* ignorieren */ }
		},
		async saveStorageSettings() {
			this.storageSaving = true
			try {
				await api.saveSettings({ storage_user: this.storageUser, storage_path: this.storagePath || 'Vereinsbuchhaltung/Belege', cost_center_mode: this.costCenterMode, club_name: this.clubName })
				showSuccess('Einstellungen gespeichert.')
				this.reportData = null
			} catch (e) {
				const msg = (e?.response?.data?.message) || `Speichern fehlgeschlagen (HTTP ${e?.response?.status ?? 'Netzwerkfehler'})`
				showError(msg)
			} finally { this.storageSaving = false }
		},
		// loadYears/loadClosedYears/isYearClosed kommen aus setup() (useYears).
		// closeYear/reopenYear sind jetzt Teil von SettingsYearClose.vue (eigenes
		// setup() mit useYears()).
		// --- Änderungsprotokoll ----------------------------------------------
		async loadAudit(more = false) {
			if (this.auditLoading) return
			this.auditLoading = true
			try {
				const offset = more ? this.auditEntries.length : 0
				const { data } = await api.auditLog(100, offset)
				this.auditEnd = data.length < 100
				this.auditEntries = more ? this.auditEntries.concat(data) : data
			} catch (e) {
				showError(this.errMsg(e, 'Protokoll konnte nicht geladen werden'))
			} finally { this.auditLoading = false }
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

		// --- CSV-Import (ImportDialog.vue) ---
		openImport() { this.showImport = true },
		closeImport() { this.showImport = false },
		goAssignAfterImport() {
			this.closeImport()
			this.activeTab = 'bookings'
			this.bookingView = 'unassigned'
		},
		async onImported() { await this.loadImports(); await this.loadBalances(); await this.loadTransactions() },
		async loadImports() { try { const { data } = await api.listImports(); this.imports = data } catch (e) { /* still */ } },

		// SettingsXbucImport.vue meldet einen erfolgreichen Import; die Nachlade-
		// Orchestrierung über mehrere Composables + lokales imports bleibt hier.
		async onXbucImported() {
			await this.loadYears(); await this.loadAccounts(); await this.loadBalances(); await this.loadImports(); await this.loadJournal(); await this.loadTransactions()
		},
		async resetAll() {
			if (!await this.askConfirm('Alle Daten löschen', 'Wirklich ALLE Konten, Buchungen und Importe löschen?')) return
			this.busy = true
			try {
				await api.reset(); showSuccess('Alle Daten gelöscht.')
				this.selectedAccountId = null; this.statement = null; this.journalData = []; this.transactions = []
				this.selectedYear = null
				this.demoActive = false
				await this.loadYears(); await this.loadAccounts(); await this.loadBalances(); await this.loadImports()
			} catch (e) { showError(this.errMsg(e, 'Zurücksetzen fehlgeschlagen')) } finally { this.busy = false }
		},
		// --- Beispieldaten (Onboarding) ---
		async seedDemoData() {
			this.busy = true
			try {
				await api.seedDemo()
				this.demoActive = true
				await Promise.all([this.loadYears(), this.loadAccounts(), this.loadBalances(), this.loadJournal(), this.loadTransactions()])
				showSuccess('Beispielverein angelegt – schau dich gern um. Zum Starten mit echten Daten: Zurücksetzen.')
			} catch (e) { showError(this.errMsg(e, 'Beispieldaten konnten nicht angelegt werden')) } finally { this.busy = false }
		},
		setupWizardSeen() {
			try { return localStorage.getItem('vbh_setup_wizard_seen') === '1' } catch (e) { return false }
		},
		markSetupWizardSeen() {
			try { localStorage.setItem('vbh_setup_wizard_seen', '1') } catch (e) { /* voll/gesperrt – dann eben ohne */ }
		},
		closeSetupWizard() {
			this.showSetupWizard = false
			this.markSetupWizardSeen()
		},
		onWizardChoice(choice) {
			this.closeSetupWizard()
			if (choice === 'xbuc') this.openSettings()
			else if (choice === 'fresh') this.seedAccounts()
			else if (choice === 'demo') this.seedDemoData()
		},

		// --- Bankbuchungen ---
		// loadTransactions kommt aus setup() (useJournal).
		async loadRules() { try { const { data } = await api.listRules(); this.rules = data } catch (e) { /* Regeln optional */ } },
		async onSpheresChanged() { await this.loadAccounts(); await this.loadSphereReport() },
		async onAssign(tx, value) {
			const prevContra = tx.contraAccountId
			try {
				if (value === '') {
					await api.unassignTransaction(tx.id)
					if (prevContra) {
						showUndo('Zuordnung entfernt', async () => {
							try {
								await api.assignTransaction(tx.id, prevContra)
								await this.loadTransactions(); await this.loadBalances(); await this.loadJournal(); await this.loadSphereReport()
							} catch (e) { showError(this.errMsg(e, 'Wiederherstellen fehlgeschlagen')) }
						})
					}
				} else {
					await api.assignTransaction(tx.id, Number(value))
				}
				await this.loadTransactions(); await this.loadBalances(); await this.loadJournal(); await this.loadSphereReport()
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
		// Eigener Wrapper um useJournal.loadJournal (journalLoad), da hier
		// zusätzlich die Beleg-Zähler nachgeladen werden.
		async loadJournal() {
			await this.journalLoad()
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
			this.pendingFiles = []
			this.bookingForm = this.emptyBookingForm()
			this.bookingForm.moneyAccountId = this.defaultMoneyAccountId
			this.showBooking = true
			this.startBookingTour()
		},
		// --- Erste-Buchung-Tour: einmalige Feld-Hervorhebung im Einfach-Modus (Desktop) ---
		startBookingTour() {
			if (this.isMobile || this.bookingMode !== 'simple') return
			try { if (localStorage.getItem('vbh_booking_tour_seen') === '1') return } catch (e) { return }
			this.bookingTour = { active: true, step: 0 }
			try { localStorage.setItem('vbh_booking_tour_seen', '1') } catch (e) { /* voll/gesperrt – dann eben ohne */ }
		},
		nextTourStep() {
			if (this.bookingTour.step >= 2) { this.endTour(); return }
			this.bookingTour.step++
		},
		endTour() { this.bookingTour = { active: false, step: 0 } },
		// Mobil: Geldfluss-Richtung einer Buchung für die Betrags-Färbung der
		// Karte – nur eindeutige Fälle (genau eine Seite ist ein Geldkonto).
		rowFlow(r) {
			if (r.isSplit) return ''
			const d = this.accountsById[r.debitAccountId]
			const c = this.accountsById[r.creditAccountId]
			const dIn = !!(d && d.isBank)
			const cOut = !!(c && c.isBank)
			if (dIn && !cOut) return 'in'
			if (cOut && !dIn) return 'out'
			return ''
		},
		// --- Mobil: Kontoauswahl-Sheet -------------------------------------
		openAccountPicker(target, tx = null) {
			const titles = {
				category: 'Kategorie wählen',
				money: 'Geldkonto wählen',
				debit: 'Sollkonto wählen',
				credit: 'Habenkonto wählen',
				assign: 'Konto / Kategorie zuordnen',
			}
			this.accountPicker = { open: true, target, title: titles[target] || 'Konto wählen', tx }
		},
		closeAccountPicker() {
			this.accountPicker = { open: false, target: null, title: '', tx: null }
		},
		onAccountPicked(opt) {
			const p = this.accountPicker
			if (p.target === 'category') this.bookingForm.categoryId = opt.id
			else if (p.target === 'money') this.bookingForm.moneyAccountId = opt.id
			else if (p.target === 'debit') this.bookingForm.debitAccountId = opt.id
			else if (p.target === 'credit') this.bookingForm.creditAccountId = opt.id
			else if (p.target === 'assign' && p.tx) this.onAssign(p.tx, opt.id)
			this.pushRecentAccount(opt.id)
			this.closeAccountPicker()
		},
		onAccountPickerSuggest() {
			const p = this.accountPicker
			if (p.target === 'assign' && p.tx) {
				const s = this.suggestionsById[p.tx.id]
				if (s) this.pushRecentAccount(s.id)
				this.applySuggestion(p.tx)
			}
			this.closeAccountPicker()
		},
		loadRecentAccounts() {
			try {
				const list = JSON.parse(localStorage.getItem('vbh_recent_accounts') || '[]')
				this.recentAccountIds = Array.isArray(list) ? list : []
			} catch (e) { this.recentAccountIds = [] }
		},
		pushRecentAccount(id) {
			if (!id) return
			this.recentAccountIds = [id, ...this.recentAccountIds.filter(x => x !== id)].slice(0, 5)
			try { localStorage.setItem('vbh_recent_accounts', JSON.stringify(this.recentAccountIds)) } catch (e) { /* voll/gesperrt – dann eben ohne */ }
		},
		// Kontoauszug mobil: Bewegung der Zeile aus Sicht der Kontonatur
		statementRowNet(row) {
			const isCredit = this.statement && ['income', 'liability', 'equity'].includes(this.statement.account.type)
			return isCredit ? (row.credit - row.debit) : (row.debit - row.credit)
		},
		// Mobil: Konten-Drilldown zurück zur Liste
		closeAccountDetail() {
			this.selectedAccountId = null
			this.statement = null
		},
		// Mobil: Belege beim Anlegen sammeln, Upload folgt nach dem Speichern
		addPendingFiles(event) {
			const files = event.target.files
			if (files && files.length) this.pendingFiles.push(...Array.from(files))
			event.target.value = ''
		},
		async uploadPendingFiles(journalId) {
			const files = this.pendingFiles
			this.pendingFiles = []
			if (!journalId || !files.length) return
			try {
				for (const file of files) {
					const fd = new FormData()
					fd.append('file', file)
					await api.uploadAttachment(journalId, fd)
				}
				this.loadAttachmentCounts()
			} catch (e) { showError(this.errMsg(e, 'Buchung gespeichert, aber der Beleg-Upload ist fehlgeschlagen')) }
		},
		// Mobil: Tippen auf eine Buchungskarte
		openBookingCard(r) {
			if (this.canWrite) {
				this.editBooking(r)
				return
			}
			if (this.attachmentCountMap[r.id]) this.openQuickViewer(r)
		},
		// Mobil: Löschen aus dem Bearbeiten-Dialog (die Karten haben keinen
		// eigenen Löschen-Knopf; am Desktop bleibt der Knopf in der Zeile).
		async deleteBookingFromDialog() {
			const id = this.bookingForm.id
			const entryNo = this.bookingForm.entryNo
			if (!id) return
			if (!await this.askConfirm('Buchung löschen', `Buchung #${entryNo} löschen?`)) return
			try {
				await api.deleteBooking(id)
				this.closeBooking()
				await this.loadJournal(); await this.loadBalances()
			} catch (e) { showError(this.errMsg(e, 'Löschen fehlgeschlagen')) }
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
		closeBooking() { this.showBooking = false; this.bookingForm = this.emptyBookingForm(); this.bookingAttachments = []; this.pendingFiles = []; this.endTour() },
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
				if (f.id) {
					await api.updateBooking(f.id, { ...payload, updatedAt: f.updatedAt || null })
				} else {
					const { data } = await api.createBooking(payload)
					await this.uploadPendingFiles(data && data.id)
				}
				showSuccess('Buchung gespeichert.')
				this.closeBooking()
				await this.loadJournal(); await this.loadBalances(); await this.loadYears(); await this.loadSphereReport()
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
			try { await api.deleteBooking(r.id); await this.loadJournal(); await this.loadBalances(); await this.loadSphereReport() } catch (e) { showError(this.errMsg(e, 'Löschen fehlgeschlagen')) }
		},

		// --- Konten ---
		// Eigene Wrapper um useAccounts (accountsLoad/accountsSeedDefaults), da hier
		// zusätzlich das lokale openingForm nachgezogen bzw. eine Erfolgsmeldung gezeigt wird.
		async loadAccounts() {
			const data = await this.accountsLoad()
			if (!data) return
			const form = {}
			for (const acc of data) form[acc.id] = { amount: acc.openingBalance || 0, date: acc.openingDate || '' }
			this.openingForm = form
		},
		async seedAccounts() {
			try {
				await this.accountsSeedDefaults()
				await this.loadAccounts()
				showSuccess('Standard-Kontenrahmen angelegt.')
			} catch (e) { showError(this.errMsg(e, 'Anlegen fehlgeschlagen')) }
		},
		openNewAccount() {
			this.accountEditId = null
			const parent = this.selectedAccount
			this.newAccount = {
				number: '', name: '',
				type: parent ? parent.type : 'income',
				category: parent ? (parent.category || '') : '',
				isBank: false,
				parentId: this.selectedAccountId || null,
				sphere: parent ? (parent.sphere || '') : '',
			}
			this.showAccount = true
		},
		openEditAccount(acc) {
			this.accountEditId = acc.id
			this.newAccount = {
				number: acc.number, name: acc.name, type: acc.type,
				category: acc.category || '', isBank: !!acc.isBank,
				parentId: acc.parentId || null,
				sphere: acc.sphere || '',
			}
			this.showAccount = true
		},
		closeAccount() { this.showAccount = false; this.accountEditId = null },
		// f kommt jetzt als @save-Payload von AccountDialog.vue (eigene lokale
		// Formularkopie dort, kein direktes Mutieren von this.newAccount mehr).
		async saveAccount(f) {
			if (!f.number || !f.name) { showError('Nummer und Bezeichnung sind Pflicht.'); return }
			try {
				if (this.accountEditId) {
					await api.updateAccount(this.accountEditId, {
						number: f.number, name: f.name, type: f.type,
						category: f.category || null, isBank: f.isBank,
						parentId: f.parentId || 0,
						sphere: f.sphere || '',
					})
				} else {
					await api.createAccount({ ...f, parentId: f.parentId || null, sphere: f.sphere || null })
				}
				this.showAccount = false
				this.accountEditId = null
				this.newAccount = { number: '', name: '', type: 'income', category: '', isBank: false, parentId: null, sphere: '' }
				await this.loadAccounts(); await this.loadBalances(); await this.loadSphereReport()
				showSuccess('Konto gespeichert.')
			} catch (e) { showError(this.errMsg(e, 'Konto konnte nicht gespeichert werden')) }
		},
		async deleteAccount(acc) {
			if (!await this.askConfirm('Konto löschen', `Konto "${acc.number} ${acc.name}" löschen?`)) return
			try {
				await api.deleteAccount(acc.id)
				if (this.selectedAccountId === acc.id) { this.selectedAccountId = null; this.statement = null }
				await this.loadAccounts(); await this.loadBalances(); await this.loadSphereReport()
			} catch (e) { showError(this.errMsg(e, 'Löschen fehlgeschlagen')) }
		},
		async saveOpening(acc) {
			const form = this.openingForm[acc.id] || { amount: 0, date: '' }
			try {
				await api.setOpening(acc.id, Number(form.amount) || 0, form.date || null)
				await this.loadAccounts(); await this.loadBalances(); await this.loadSphereReport()
				if (this.selectedAccountId === acc.id) await this.loadStatement(acc.id)
				showSuccess(`Eröffnungssaldo für ${acc.name} gespeichert.`)
			} catch (e) { showError(this.errMsg(e, 'Eröffnungssaldo konnte nicht gespeichert werden')) }
		},

		// --- Auswertung ---
		// loadBalances kommt aus setup() (useBalances).

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
		// Eigener Wrapper um useBalances.loadSphereReport (balancesLoadSphereReport),
		// da hier zusätzlich die lokale Sphären-Auswahl (Reports-Tab) zurückgesetzt wird.
		async loadSphereReport() {
			const data = await this.balancesLoadSphereReport()
			if (data && this.selectedSphereCode !== false && !data.spheres.some(s => s.code === this.selectedSphereCode)) this.selectedSphereCode = false
		},
		selectSphere(s) { this.selectedSphereCode = s.code },
		isSphereSelected(s) { return this.selectedSphereCode !== false && s.code === this.selectedSphereCode },
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
			const data = await this.authLoadMe()
			if (!this.visibleTabs.some(t => t.id === this.activeTab)) {
				this.activeTab = this.visibleTabs.length ? this.visibleTabs[0].id : 'dashboard'
			}
			if (data.role === 'revisor') {
				try { this.revisorIntroDismissed = localStorage.getItem('vbh_revisor_intro_dismissed') === '1' } catch (e) { this.revisorIntroDismissed = false }
			}
		},
		dismissRevisorIntro() {
			this.revisorIntroDismissed = true
			try { localStorage.setItem('vbh_revisor_intro_dismissed', '1') } catch (e) { /* voll/gesperrt – dann eben ohne */ }
		},
		// --- Hilfe-Modal --------------------------------------------------
		openHelp(topic = null) {
			this.helpForcedTopic = topic
			this.showHelp = true
		},
		closeHelp() {
			this.showHelp = false
			this.helpForcedTopic = null
		},
		// --- Setup-Checkliste: Sprung zur jeweiligen Aktion ----------------
		onSetupNavigate(action) {
			if (action === 'accounts') this.activeTab = 'accounts'
			else if (action === 'settings') this.openSettings()
			else if (action === 'booking') this.openNewBooking()
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
