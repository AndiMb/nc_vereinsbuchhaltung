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
			</div>
			<div v-if="canRead" class="vbh-navbar">
				<nav class="vbh-tabs">
					<button v-for="tab in visibleTabs" :key="tab.id" :class="{ active: activeTab === tab.id }" @click="activeTab = tab.id">{{ tab.label }}</button>
				</nav>
				<button v-if="canWrite" class="vbh-databtn" :class="{ active: activeTab === 'import' }" title="Daten importieren, exportieren oder zurücksetzen" @click="activeTab = 'import'">⚙ Daten / Import</button>
			</div>
		</header>

		<div v-if="me && !canRead" class="vbh-noaccess">
			<h3>Kein Zugriff</h3>
			<p>Du hast keine Berechtigung für die Vereinsbuchhaltung. Bitte wende dich an eine Verwalterin oder einen Verwalter.</p>
		</div>

		<main v-show="canRead" class="vbh-main">
			<!-- ============ IMPORT ============ -->
			<section v-show="activeTab === 'import'" class="vbh-section scroll">
				<div class="vbh-sectionhead">
					<h3>Daten / Import</h3>
					<button class="vbh-btnlink" @click="activeTab = 'accounts'">‹ zurück zur Buchhaltung</button>
				</div>

				<div class="vbh-card">
					<h4>Kontoumsätze (CSV-CAMT)</h4>
					<p class="vbh-hint">Bankexport im CSV-CAMT-Format. Nur neue Buchungen werden übernommen (Dublettenprüfung).</p>
					<div class="vbh-uploadrow">
						<label class="vbh-filebtn">Datei wählen<input ref="fileInput" type="file" accept=".csv,text/csv" hidden @change="onFileSelected"></label>
						<span class="vbh-filename">{{ selectedFile ? selectedFile.name : 'keine Datei gewählt' }}</span>
						<label class="vbh-check"><input v-model="applyRules" type="checkbox"> Auto-Zuordnungsregeln anwenden</label>
					</div>
					<div v-if="previewResult" class="vbh-preview">
						<p class="vbh-previewsummary">
							<span class="vbh-badge pos">{{ previewResult.new }} neu</span>
							<span class="vbh-badge muted">{{ previewResult.duplicate }} Dubletten</span>
							<span class="vbh-badge muted">{{ previewResult.total }} gesamt</span>
						</p>
						<button class="primary" :disabled="busy || previewResult.new === 0" @click="commit">{{ previewResult.new }} Buchungen importieren</button>
					</div>
				</div>

				<div class="vbh-card">
					<h4>Aus „zero Buchhaltung" (.xbuc)</h4>
					<p class="vbh-hint">Übernimmt Kontenbaum und alle Buchungen aus einer .xbuc-Datei.</p>
					<div class="vbh-uploadrow">
						<label class="vbh-filebtn">Datei wählen<input ref="xbucInput" type="file" accept=".xbuc,application/xml,text/xml" hidden @change="onXbucSelected"></label>
						<span class="vbh-filename">{{ xbucFile ? xbucFile.name : 'keine Datei gewählt' }}</span>
						<label class="vbh-check"><input v-model="xbucReset" type="checkbox"> Vorher alle Daten löschen (frisch starten)</label>
					</div>
					<div v-if="xbucPreviewResult" class="vbh-preview">
						<p class="vbh-previewsummary">
							<span class="vbh-badge pos">{{ xbucPreviewResult.accounts }} Konten</span>
							<span class="vbh-badge pos">{{ xbucPreviewResult.bookings }} Buchungen</span>
						</p>
						<button class="primary" :disabled="busy" @click="xbucImport">Importieren</button>
						<span v-if="xbucReset" class="vbh-warn-inline">Achtung: bestehende Daten werden gelöscht.</span>
					</div>
				</div>

				<div class="vbh-card">
					<h4>Zurücksetzen</h4>
					<p class="vbh-hint">Löscht alle Konten, Buchungen und Importe dieses Kontos unwiderruflich.</p>
					<button class="danger" :disabled="busy" @click="resetAll">Alle Daten löschen</button>
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
				<p v-else class="vbh-empty">Noch keine CSV-Importe.</p>
			</section>

			<!-- ============ BUCHUNGEN ============ -->
			<section v-show="activeTab === 'transactions'" class="vbh-section scroll">
				<div class="vbh-sectionhead">
					<h3>Bankbuchungen zuordnen</h3>
					<label class="vbh-filter">Filter:
						<select v-model="txFilter" @change="onTxFilterChange">
							<option value="">Alle</option>
							<option value="unassigned">Nur offene</option>
							<option value="assigned">Nur zugeordnete</option>
						</select>
					</label>
				</div>
				<div v-if="sortedTransactions.length" class="vbh-tablecard">
					<div class="vbh-tablecount">{{ sortedTransactions.length }} Buchungen</div>
					<table class="vbh-table">
						<thead>
							<tr>
								<th class="sortable nowrap" @click="toggleSort('transactions','bookingDate')">Datum{{ sortArrow('transactions','bookingDate') }}</th>
								<th class="sortable" @click="toggleSort('transactions','counterparty')">Empfänger/Zahler{{ sortArrow('transactions','counterparty') }}</th>
								<th>Verwendungszweck</th>
								<th class="sortable num" @click="toggleSort('transactions','amount')">Betrag{{ sortArrow('transactions','amount') }}</th>
								<th>Konto / Kategorie</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="tx in sortedTransactions" :key="tx.id" :class="{ assigned: tx.status === 'assigned' }">
								<td class="nowrap">{{ formatDate(tx.bookingDate) }}</td>
								<td>{{ tx.counterparty }}</td>
								<td class="vbh-purpose" :title="tx.purpose">{{ tx.purpose }}</td>
								<td class="num" :class="amountClass(tx.amount)">{{ formatMoney(tx.amount) }}</td>
								<td>
									<select class="vbh-assign" :class="{ unassigned: !tx.contraAccountId }" :value="tx.contraAccountId || ''" :disabled="!canWrite" @change="onAssign(tx, $event.target.value)">
										<option value="">– nicht zugeordnet –</option>
										<optgroup v-for="(group, cat) in accountsByCategory" :key="cat" :label="cat">
											<option v-for="acc in group" :key="acc.id" :value="acc.id">{{ acc.number }} {{ acc.name }}</option>
										</optgroup>
									</select>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p v-else class="vbh-empty">Keine Bankumsätze. Importiere zuerst Kontoumsätze.</p>
			</section>

			<!-- ============ JOURNAL ============ -->
			<section v-show="activeTab === 'journal'" class="vbh-section scroll">
				<div class="vbh-sectionhead">
					<h3>Journal (Buchungssätze)</h3>
					<button v-if="canWrite" class="primary" @click="openNewBooking">＋ Neue Buchung</button>
				</div>

				<div v-if="sortedJournalRows.length" class="vbh-tablecard">
					<div class="vbh-tablecount">{{ sortedJournalRows.length }} Buchungssätze</div>
					<table class="vbh-table">
						<thead>
							<tr>
								<th class="sortable num" @click="toggleSort('journal','entryNo')">Nr.{{ sortArrow('journal','entryNo') }}</th>
								<th class="sortable nowrap" @click="toggleSort('journal','date')">Datum{{ sortArrow('journal','date') }}</th>
								<th class="sortable" @click="toggleSort('journal','description')">Beschreibung{{ sortArrow('journal','description') }}</th>
								<th class="sortable" @click="toggleSort('journal','soll')">Soll{{ sortArrow('journal','soll') }}</th>
								<th class="sortable" @click="toggleSort('journal','haben')">Haben{{ sortArrow('journal','haben') }}</th>
								<th class="sortable num" @click="toggleSort('journal','amount')">Betrag{{ sortArrow('journal','amount') }}</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="r in sortedJournalRows" :key="r.id">
								<td class="num strong">{{ r.entryNo }}</td>
								<td class="nowrap">{{ formatDate(r.date) }}</td>
								<td class="vbh-purpose" :title="r.description">{{ r.description }}</td>
								<td>{{ r.soll }}</td>
								<td>{{ r.haben }}</td>
								<td class="num strong">{{ formatMoney(r.amount) }}</td>
								<td class="nowrap right">
									<button v-if="canWrite" class="vbh-iconbtn edit" title="Bearbeiten" @click="editBooking(r)">✎</button>
									<button v-if="canWrite" class="vbh-iconbtn del" title="Löschen" @click="removeBooking(r)">🗑</button>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p v-else class="vbh-empty">Noch keine Buchungssätze.</p>
			</section>

			<!-- ============ KONTENRAHMEN (Master-Detail) ============ -->
			<section v-show="activeTab === 'accounts'" class="vbh-section split">
				<div class="vbh-tree">
					<div class="vbh-treehead">
						<button v-if="canWrite" class="primary small" @click="openNewAccount">＋ Konto</button>
						<span v-else></span>
						<div class="vbh-treeactions">
							<button class="vbh-btnlink" @click="expandAll">alle auf</button>
							<button class="vbh-btnlink" @click="collapseAll">alle zu</button>
						</div>
					</div>

					<p v-if="accounts.length === 0" class="vbh-hint">
						Noch keine Konten.<br>
						<button v-if="canWrite" class="primary" @click="seedAccounts">Standard-Kontenrahmen anlegen</button>
					</p>

					<div class="vbh-treelist">
						<div v-for="node in visibleTree" :key="node.id"
							class="vbh-treenode" :class="{ selected: node.id === selectedAccountId, group: node.hasChildren }"
							:style="{ paddingLeft: (8 + node.depth * 18) + 'px' }"
							@click="selectAccount(node)">
							<button v-if="node.hasChildren" class="vbh-caret" :class="{ open: expanded[node.id] }" @click.stop="toggleExpand(node.id)">›</button>
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
								<button class="vbh-iconbtn edit" title="Konto bearbeiten" @click="openEditAccount(selectedAccount)">✎</button>
								<button class="vbh-iconbtn del" title="Konto löschen" @click="deleteAccount(selectedAccount)">🗑</button>
							</span>
						</div>

						<div v-if="canWrite && (selectedAccount.isBank || selectedAccount.type === 'asset')" class="vbh-opening">
							<span>Eröffnungssaldo:</span>
							<input v-model.number="openingForm[selectedAccount.id].amount" type="number" step="0.01" class="vbh-num">
							<input v-model="openingForm[selectedAccount.id].date" type="date" class="vbh-date">
							<button class="primary small" @click="saveOpening(selectedAccount)">Speichern</button>
						</div>

						<div v-if="statement" class="vbh-statementbar">
							<label class="vbh-check"><input v-model="statementIncludeChildren" type="checkbox" @change="reloadStatement"> inkl. Unterkonten</label>
							<div class="vbh-previewsummary">
								<span class="vbh-badge muted">{{ statement.totals.count }} Buchungen</span>
								<span class="vbh-badge muted">Soll {{ formatMoney(statement.totals.debit) }}</span>
								<span class="vbh-badge muted">Haben {{ formatMoney(statement.totals.credit) }}</span>
								<span class="vbh-badge pos">Saldo {{ formatMoney(statement.totals.balance) }}</span>
							</div>
						</div>

						<div v-if="statementRows.length" class="vbh-tablecard">
							<table class="vbh-table">
								<thead><tr><th class="num">Nr.</th><th class="nowrap">Datum</th><th>Beschreibung</th><th>Gegenkonto</th><th class="num">Soll</th><th class="num">Haben</th><th class="num">Saldo</th></tr></thead>
								<tbody>
									<tr v-for="(row, i) in statementRows" :key="i">
										<td class="num">{{ row.entryNo }}</td>
										<td class="nowrap">{{ formatDate(row.date) }}</td>
										<td class="vbh-purpose" :title="row.description">{{ row.description }}</td>
										<td>{{ row.contra }}</td>
										<td class="num">{{ row.debit ? formatMoney(row.debit) : '' }}</td>
										<td class="num">{{ row.credit ? formatMoney(row.credit) : '' }}</td>
										<td class="num strong" :class="amountClass(row.saldo)">{{ formatMoney(row.saldo) }}</td>
									</tr>
								</tbody>
							</table>
						</div>
						<p v-else-if="statement" class="vbh-empty">Keine Buchungen auf diesem Konto{{ statementIncludeChildren ? ' (inkl. Unterkonten)' : '' }}.</p>
					</template>
				</div>
			</section>

			<!-- ============ AUSWERTUNG ============ -->
			<section v-show="activeTab === 'report'" class="vbh-section scroll">
				<h3>Auswertung</h3>
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

				<h4>Saldenliste</h4>
				<div v-if="balances" class="vbh-tablecard">
					<table class="vbh-table">
						<thead>
							<tr>
								<th class="sortable nowrap" @click="toggleSort('balances','number')">Nr.{{ sortArrow('balances','number') }}</th>
								<th class="sortable" @click="toggleSort('balances','name')">Konto{{ sortArrow('balances','name') }}</th>
								<th class="sortable" @click="toggleSort('balances','category')">Kategorie{{ sortArrow('balances','category') }}</th>
								<th class="sortable num" @click="toggleSort('balances','debit')">Soll{{ sortArrow('balances','debit') }}</th>
								<th class="sortable num" @click="toggleSort('balances','credit')">Haben{{ sortArrow('balances','credit') }}</th>
								<th class="sortable num" @click="toggleSort('balances','balance')">Saldo{{ sortArrow('balances','balance') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in sortedBalances" :key="row.accountId">
								<td class="nowrap">{{ row.number }}</td>
								<td>{{ row.name }}</td>
								<td>{{ row.category }}</td>
								<td class="num">{{ formatMoney(row.debit) }}</td>
								<td class="num">{{ formatMoney(row.credit) }}</td>
								<td class="num strong">{{ formatMoney(row.balance) }}</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>

			<!-- ============ BERICHTE (Kostenstellen) ============ -->
			<section v-show="activeTab === 'reports'" class="vbh-section split">
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

						<div v-if="canWrite && selectedCC.code" class="vbh-opening">
							<span>Name:</span>
							<input v-model="renameName" class="vbh-rename">
							<button class="primary small" @click="saveRename">Umbenennen</button>
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
									<template v-for="(a, i) in selectedCC.accounts">
										<tr :key="'a' + i" class="vbh-ccrow" @click="toggleCCAccount(a.accountId)">
											<td class="nowrap"><span class="vbh-caret" :class="{ open: ccExpanded[a.accountId] }">›</span> {{ a.number }}</td>
											<td>{{ a.name }}</td>
											<td><span class="vbh-typetag" :class="a.type">{{ typeLabel(a.type) }}</span></td>
											<td class="num" :class="amountClass(a.balance)">{{ formatMoney(a.balance) }}</td>
										</tr>
										<tr v-if="ccExpanded[a.accountId]" :key="'d' + i" class="vbh-ccdetail">
											<td colspan="4">
												<table v-if="ccBookings[a.accountId] && ccBookings[a.accountId].length" class="vbh-table vbh-subtable">
													<thead><tr><th class="num">Nr.</th><th class="nowrap">Datum</th><th>Beschreibung</th><th>Gegenkonto</th><th class="num">Soll</th><th class="num">Haben</th></tr></thead>
													<tbody>
														<tr v-for="(r, j) in ccBookings[a.accountId]" :key="j">
															<td class="num">{{ r.entryNo }}</td>
															<td class="nowrap">{{ formatDate(r.date) }}</td>
															<td class="vbh-purpose" :title="r.description">{{ r.description }}</td>
															<td>{{ r.contra }}</td>
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
			</section>

			<!-- ============ BERECHTIGUNGEN ============ -->
			<section v-show="activeTab === 'permissions'" class="vbh-section scroll">
				<h3>Berechtigungen</h3>
				<p class="vbh-hint">
					Lege fest, welche Nextcloud-Nutzer oder -Gruppen Zugriff haben.
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
						<label v-if="permForm.principalType === 'group'">Gruppe
							<select v-model="permForm.principalId">
								<option value="">– wählen –</option>
								<option v-for="g in groups" :key="g.id" :value="g.id">{{ g.displayName }}</option>
							</select>
						</label>
						<label v-else>Nutzer (Benutzername)
							<input v-model="permForm.principalId" placeholder="z.B. erika">
						</label>
						<label>Rolle
							<select v-model="permForm.role">
								<option value="revisor">Revisor (nur lesen)</option>
								<option value="buchhalter">Buchhalter (lesen+schreiben)</option>
								<option value="verwalter">Verwalter (alles)</option>
							</select>
						</label>
						<button class="primary" @click="savePermission">Hinzufügen</button>
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
								<td class="right"><button class="vbh-iconbtn del" title="Entfernen" @click="removePermission(p)">🗑</button></td>
							</tr>
						</tbody>
					</table>
				</div>
				<p v-else class="vbh-empty">Noch keine Berechtigungen vergeben (außer Nextcloud-Administratoren).</p>
			</section>
		</main>

		<!-- ============ BUCHUNGS-DIALOG ============ -->
		<div v-if="showBooking" class="vbh-modal-overlay" @click.self="closeBooking">
			<div class="vbh-modal">
				<h3>{{ bookingForm.id ? 'Buchung bearbeiten #' + bookingForm.entryNo : 'Neue Buchung' }}</h3>
				<div class="vbh-form">
					<label>Datum<input v-model="bookingForm.date" type="date"></label>
					<label>Beleg-Nr.<input v-model="bookingForm.documentRef" class="vbh-short" placeholder="optional"></label>
					<label>Betrag (€)<input v-model.number="bookingForm.amount" type="number" step="0.01" class="vbh-num"></label>
				</div>
				<div class="vbh-form">
					<label class="vbh-grow">Soll (Aufwand/Aktiv)
						<select v-model.number="bookingForm.debitAccountId">
							<option :value="null">– wählen –</option>
							<option v-for="acc in accountsSorted" :key="acc.id" :value="acc.id">{{ acc.number }} {{ acc.name }}</option>
						</select>
					</label>
					<label class="vbh-grow">Haben (Ertrag/Passiv)
						<select v-model.number="bookingForm.creditAccountId">
							<option :value="null">– wählen –</option>
							<option v-for="acc in accountsSorted" :key="acc.id" :value="acc.id">{{ acc.number }} {{ acc.name }}</option>
						</select>
					</label>
				</div>
				<div class="vbh-form">
					<label class="vbh-grow">Buchungstext<input v-model="bookingForm.description" placeholder="Beschreibung"></label>
				</div>
				<div class="vbh-modal-actions">
					<button class="vbh-btnlink" @click="closeBooking">Abbrechen</button>
					<button class="primary" @click="saveBooking">{{ bookingForm.id ? 'Speichern' : 'Buchen' }}</button>
				</div>
			</div>
		</div>

		<!-- ============ KONTO-DIALOG ============ -->
		<div v-if="showAccount" class="vbh-modal-overlay" @click.self="closeAccount">
			<div class="vbh-modal">
				<h3>{{ accountEditId ? 'Konto bearbeiten' : 'Neues Konto' }}</h3>
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
						<select v-model.number="newAccount.parentId">
							<option :value="null">– kein Überkonto –</option>
							<option v-for="acc in parentOptions" :key="acc.id" :value="acc.id">{{ acc.number }} {{ acc.name }}</option>
						</select>
					</label>
				</div>
				<div class="vbh-form">
					<label>Kategorie<input v-model="newAccount.category" placeholder="optional"></label>
					<label class="vbh-check"><input v-model="newAccount.isBank" type="checkbox"> Bankkonto</label>
				</div>
				<div class="vbh-modal-actions">
					<button class="vbh-btnlink" @click="closeAccount">Abbrechen</button>
					<button class="primary" @click="saveAccount">{{ accountEditId ? 'Speichern' : 'Anlegen' }}</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from './api.js'

export default {
	name: 'App',
	data() {
		return {
			activeTab: 'accounts',
			allTabs: [
				{ id: 'transactions', label: 'Buchungen', need: 'read' },
				{ id: 'journal', label: 'Journal', need: 'read' },
				{ id: 'accounts', label: 'Kontenrahmen', need: 'read' },
				{ id: 'report', label: 'Auswertung', need: 'read' },
				{ id: 'reports', label: 'Berichte', need: 'read' },
				{ id: 'permissions', label: 'Berechtigungen', need: 'admin' },
			],
			me: null,
			permissions: [],
			groups: [],
			permForm: { principalType: 'group', principalId: '', role: 'revisor' },
			busy: false,
			selectedFile: null,
			applyRules: true,
			previewResult: null,
			xbucFile: null,
			xbucReset: true,
			xbucPreviewResult: null,
			imports: [],
			transactions: [],
			txFilter: 'unassigned',
			accounts: [],
			balances: null,
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
			bookingForm: this.emptyBookingForm(),
			sort: {
				transactions: { key: 'bookingDate', dir: 'desc' },
				balances: { key: 'number', dir: 'asc' },
				journal: { key: 'entryNo', dir: 'desc' },
			},
		}
	},
	computed: {
		canRead() { return !!(this.me && this.me.canRead) },
		canWrite() { return !!(this.me && this.me.canWrite) },
		isAdmin() { return !!(this.me && this.me.isAdmin) },
		visibleTabs() {
			return this.allTabs.filter(t => {
				if (t.need === 'admin') return this.isAdmin
				if (t.need === 'write') return this.canWrite
				return this.canRead
			})
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
			// Beim Bearbeiten das Konto selbst nicht als eigenes Überkonto anbieten.
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
					amount: lines.reduce((s, l) => s + (l.debitCents || 0), 0) / 100,
				}
			})
		},
		statementRows() {
			if (!this.statement) return []
			const isCredit = ['income', 'liability', 'equity'].includes(this.statement.account.type)
			let run = 0
			return this.statement.rows.map(r => {
				run += isCredit ? (r.credit - r.debit) : (r.debit - r.credit)
				return { ...r, saldo: run }
			})
		},
		selectedCC() {
			if (this.selectedCCCode === false || !this.reportData) return null
			return this.reportData.costCenters.find(c => c.code === this.selectedCCCode) || null
		},
		sortedTransactions() { return this.applySort(this.transactions, this.sort.transactions) },
		sortedBalances() { return this.applySort(this.balances ? this.balances.accounts : [], this.sort.balances) },
		sortedJournalRows() { return this.applySort(this.journalRows, this.sort.journal) },
	},
	watch: {
		activeTab(tab) {
			if (tab === 'transactions') this.loadTransactions()
			if (tab === 'report') this.loadBalances()
			if (tab === 'accounts') { this.loadAccounts(); this.loadBalances() }
			if (tab === 'journal') this.loadJournal()
			if (tab === 'reports') this.loadReport()
			if (tab === 'permissions') this.loadPermissions()
		},
	},
	async mounted() {
		await this.loadMe()
		if (this.canRead) {
			await this.loadAccounts()
			await this.loadImports()
			await this.loadBalances()
		}
	},
	methods: {
		emptyBookingForm() {
			return { id: null, entryNo: null, date: new Date().toISOString().slice(0, 10), documentRef: '', amount: null, debitAccountId: null, creditAccountId: null, description: '' }
		},
		formatMoney(v) { return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(v || 0) },
		formatDate(s) {
			if (!s) return ''
			const d = String(s).slice(0, 10)
			const m = d.match(/^(\d{4})-(\d{2})-(\d{2})$/)
			return m ? `${m[3]}.${m[2]}.${m[1]}` : d
		},
		formatDateTime(s) { return s ? String(s).replace('T', ' ').slice(0, 16) : '' },
		typeLabel(t) { return { income: 'Ertrag', expense: 'Aufwand', asset: 'Aktiv', liability: 'Passiv', equity: 'Eigenkapital' }[t] || t },
		amountClass(v) { return v < 0 ? 'neg' : '' },
		accountLabel(id) {
			const acc = this.accountsById[id]
			return acc ? `${acc.number} ${acc.name}` : `#${id}`
		},
		balanceFor(accountId) {
			if (!this.balances) return 0
			const row = this.balances.accounts.find(a => a.accountId === accountId)
			return row ? row.balance : 0
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
		applySort(rows, state) {
			if (!state || !state.key) return rows
			const f = state.dir === 'asc' ? 1 : -1
			return rows.slice().sort((a, b) => {
				let x = a[state.key]; let y = b[state.key]
				if (x === null || x === undefined) x = ''
				if (y === null || y === undefined) y = ''
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
			try { const { data } = await api.accountJournal(accountId, this.statementIncludeChildren); this.statement = data } catch (e) { showError(this.errMsg(e, 'Kontoauszug konnte nicht geladen werden')) }
		},

		// --- CSV-Import ---
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
				this.previewResult = null; this.selectedFile = null
				if (this.$refs.fileInput) this.$refs.fileInput.value = ''
				await this.loadImports(); await this.loadBalances()
			} catch (e) { showError(this.errMsg(e, 'Import fehlgeschlagen')) } finally { this.busy = false }
		},
		async loadImports() { try { const { data } = await api.listImports(); this.imports = data } catch (e) { /* still */ } },

		// --- xbuc ---
		onXbucSelected(e) { this.xbucFile = e.target.files[0] || null; this.xbucPreviewResult = null; if (this.xbucFile) this.xbucPreview() },
		async xbucPreview() {
			if (!this.xbucFile) return
			this.busy = true
			try { const fd = new FormData(); fd.append('file', this.xbucFile); const { data } = await api.previewXbuc(fd); this.xbucPreviewResult = data } catch (e) { showError(this.errMsg(e, 'Vorschau fehlgeschlagen')) } finally { this.busy = false }
		},
		async xbucImport() {
			if (!this.xbucFile) return
			if (this.xbucReset && !confirm('Alle vorhandenen Daten werden gelöscht und ersetzt. Fortfahren?')) return
			this.busy = true
			try {
				const fd = new FormData(); fd.append('file', this.xbucFile); fd.append('reset', this.xbucReset ? '1' : '0')
				const { data } = await api.commitXbuc(fd)
				showSuccess(`${data.accounts} Konten und ${data.bookings} Buchungen importiert.`)
				this.xbucPreviewResult = null; this.xbucFile = null
				if (this.$refs.xbucInput) this.$refs.xbucInput.value = ''
				await this.loadAccounts(); await this.loadBalances(); await this.loadImports()
			} catch (e) { showError(this.errMsg(e, 'Import fehlgeschlagen')) } finally { this.busy = false }
		},
		async resetAll() {
			if (!confirm('Wirklich ALLE Konten, Buchungen und Importe löschen?')) return
			this.busy = true
			try {
				await api.reset(); showSuccess('Alle Daten gelöscht.')
				this.selectedAccountId = null; this.statement = null; this.journalData = []; this.transactions = []
				await this.loadAccounts(); await this.loadBalances(); await this.loadImports()
			} catch (e) { showError(this.errMsg(e, 'Zurücksetzen fehlgeschlagen')) } finally { this.busy = false }
		},

		// --- Bankbuchungen ---
		onTxFilterChange() { this.loadTransactions() },
		async loadTransactions() { try { const { data } = await api.listTransactions(this.txFilter); this.transactions = data } catch (e) { showError(this.errMsg(e, 'Buchungen konnten nicht geladen werden')) } },
		async onAssign(tx, value) {
			try {
				if (value === '') await api.unassignTransaction(tx.id)
				else await api.assignTransaction(tx.id, Number(value))
				await this.loadTransactions(); await this.loadBalances()
			} catch (e) { showError(this.errMsg(e, 'Zuordnung fehlgeschlagen')) }
		},

		// --- Journal ---
		async loadJournal() { try { const { data } = await api.journal(); this.journalData = data } catch (e) { showError(this.errMsg(e, 'Journal konnte nicht geladen werden')) } },
		openNewBooking() { this.bookingForm = this.emptyBookingForm(); this.showBooking = true },
		editBooking(r) {
			this.bookingForm = { id: r.id, entryNo: r.entryNo, date: r.date, documentRef: r.documentRef || '', amount: r.amount, debitAccountId: r.debitAccountId, creditAccountId: r.creditAccountId, description: r.description || '' }
			this.showBooking = true
		},
		closeBooking() { this.showBooking = false; this.bookingForm = this.emptyBookingForm() },
		async saveBooking() {
			const f = this.bookingForm
			if (!f.date || !f.debitAccountId || !f.creditAccountId || !f.amount) { showError('Datum, Soll, Haben und Betrag sind Pflicht.'); return }
			if (f.debitAccountId === f.creditAccountId) { showError('Soll- und Habenkonto müssen unterschiedlich sein.'); return }
			const payload = { date: f.date, description: f.description, documentRef: f.documentRef || null, debitAccountId: f.debitAccountId, creditAccountId: f.creditAccountId, amount: Number(f.amount) }
			try {
				if (f.id) await api.updateBooking(f.id, payload)
				else await api.createBooking(payload)
				showSuccess('Buchung gespeichert.')
				this.closeBooking()
				await this.loadJournal(); await this.loadBalances()
			} catch (e) { showError(this.errMsg(e, 'Buchung konnte nicht gespeichert werden')) }
		},
		async removeBooking(r) {
			if (!confirm(`Buchung #${r.entryNo} löschen?`)) return
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
			// Vorbelegung: aktuell gewähltes Konto als Überkonto (praktisch beim Anlegen von Unterkonten).
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
			if (!confirm(`Konto "${acc.number} ${acc.name}" löschen?`)) return
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
		async loadBalances() { try { const { data } = await api.balances(); this.balances = data } catch (e) { showError(this.errMsg(e, 'Auswertung konnte nicht geladen werden')) } },

		// --- Berichte / Kostenstellen ---
		async loadReport() {
			try {
				const { data } = await api.costCenterReport()
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
				try { const { data } = await api.accountJournal(accountId, false); this.$set(this.ccBookings, accountId, data.rows) } catch (e) { showError(this.errMsg(e, 'Buchungen konnten nicht geladen werden')) }
			}
		},
		async saveRename() {
			const cc = this.selectedCC
			if (!cc || !cc.code) return
			try { await api.renameCostCenter(cc.code, this.renameName); await this.loadReport(); showSuccess('Kostenstelle umbenannt.') } catch (e) { showError(this.errMsg(e, 'Umbenennen fehlgeschlagen')) }
		},

		// --- Berechtigungen ---
		async loadMe() {
			try {
				const { data } = await api.me()
				this.me = data
				if (!this.visibleTabs.some(t => t.id === this.activeTab)) {
					this.activeTab = this.visibleTabs.length ? this.visibleTabs[0].id : 'import'
				}
			} catch (e) {
				this.me = { role: 'none', canRead: false, canWrite: false, isAdmin: false }
			}
		},
		async loadPermissions() {
			try {
				const [p, g] = await Promise.all([api.listPermissions(), api.listGroups()])
				this.permissions = p.data
				this.groups = g.data
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
			if (!confirm(`Berechtigung für "${p.principalId}" entfernen?`)) return
			try { await api.deletePermission(p.id); await this.loadPermissions() } catch (e) { showError(this.errMsg(e, 'Entfernen fehlgeschlagen')) }
		},
		roleLabel(r) { return { verwalter: 'Verwalter', buchhalter: 'Buchhalter', revisor: 'Revisor' }[r] || r },

		errMsg(e, fallback) { return (e && e.response && e.response.data && e.response.data.message) || fallback },
	},
}
</script>

<style scoped>
.vbh { width: 100%; flex: 1 1 auto; min-width: 0; height: 100%; display: flex; flex-direction: column; overflow: hidden; background-color: var(--color-main-background); color: var(--color-main-text); }

.vbh-header { flex: 0 0 auto; padding: 12px 24px 0; border-bottom: 1px solid var(--color-border); }
.vbh-noaccess { padding: 48px 24px; text-align: center; color: var(--color-main-text); }
.vbh-noaccess h3 { margin-bottom: 8px; }
.vbh-titlebar { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.vbh-titlebar h2 { margin: 0; }
.vbh-bankchip { display: inline-flex; align-items: baseline; gap: 8px; margin-left: auto; padding: 6px 14px; border-radius: var(--border-radius-large, 12px); color: var(--color-main-text); background-color: rgba(45, 125, 70, 0.16); border: 1px solid rgba(45, 125, 70, 0.55); }
.vbh-bankchip.warn { background-color: rgba(201, 135, 10, 0.18); border-color: rgba(201, 135, 10, 0.65); }
.vbh-bankchip-label { font-size: 0.82em; opacity: 0.9; }
.vbh-bankchip-value { font-size: 1.2em; font-weight: 700; }
.vbh-bankchip-hint { font-size: 0.82em; font-weight: 600; color: #b35900; }

.vbh-navbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.vbh-tabs { display: inline-flex; gap: 4px; margin: 12px 0 -1px; padding: 4px; background-color: var(--color-background-dark); border-radius: 12px; }
.vbh-databtn { margin: 12px 0 -1px; padding: 7px 16px; border: 1px solid var(--color-border); background-color: var(--color-main-background); border-radius: 10px; cursor: pointer; color: var(--color-main-text); font-weight: 600; font-size: 0.9em; }
.vbh-databtn:hover { background-color: var(--color-background-hover); }
.vbh-databtn.active { background-color: var(--color-primary-element); color: var(--color-primary-element-text); border-color: var(--color-primary-element); }
.vbh-tabs button { border: none; background: transparent; padding: 7px 18px; border-radius: 8px; cursor: pointer; color: var(--color-main-text); font-weight: 600; font-size: 0.95em; }
.vbh-tabs button:hover { background-color: var(--color-background-hover); }
.vbh-tabs button.active { background-color: var(--color-primary-element); color: var(--color-primary-element-text); box-shadow: 0 1px 3px rgba(0,0,0,0.2); }

.vbh-main { flex: 1 1 auto; min-height: 0; display: flex; }
.vbh-section { flex: 1 1 auto; min-height: 0; min-width: 0; width: 100%; }
.vbh-section.scroll { overflow-y: auto; padding: 16px 24px 48px; }
.vbh-section.split { overflow: hidden; display: flex; }

.vbh-section h3 { margin-top: 0; }
.vbh-section h4 { margin: 18px 0 6px; }
.vbh-sectionhead { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
.vbh-hint { color: var(--color-main-text); opacity: 0.8; max-width: 80ch; }
.vbh-empty { color: var(--color-main-text); opacity: 0.65; font-style: italic; }
.vbh-warn-inline { color: #b35900; font-weight: 600; margin-left: 10px; }

.vbh-card { border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 12px); padding: 16px; margin: 12px 0; background-color: var(--color-background-hover); }
.vbh-card > h4 { margin-top: 0; }
.vbh-card summary { cursor: pointer; font-weight: bold; }
.vbh-uploadrow { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.vbh-filebtn { display: inline-block; background: var(--color-background-dark); border: 1px solid var(--color-border); border-radius: var(--border-radius-element, 8px); padding: 7px 14px; cursor: pointer; font-weight: 600; }
.vbh-filebtn:hover { background: var(--color-primary-element); color: var(--color-primary-element-text); }
.vbh-filename { opacity: 0.8; font-size: 0.9em; }

.vbh-tablecard { border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 12px); margin: 10px 0; }
.vbh-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 0.92em; }
.vbh-table th, .vbh-table td { text-align: left; padding: 7px 12px; border-bottom: 1px solid var(--color-border); }
.vbh-table tbody tr:last-child td { border-bottom: none; }
.vbh-table thead th { position: sticky; top: 0; z-index: 2; background-color: var(--color-background-dark); color: var(--color-main-text); font-weight: 700; box-shadow: inset 0 -2px 0 var(--color-border); white-space: nowrap; }
.vbh-table thead th.sortable { cursor: pointer; user-select: none; }
.vbh-table thead th.sortable:hover { color: var(--color-primary-element); }
.vbh-table tbody tr:nth-child(even) { background-color: var(--color-background-hover); }
.vbh-table tbody tr:hover { background-color: var(--color-background-dark); }
.vbh-table th.num, .vbh-table td.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.vbh-table .right { text-align: right; }
.vbh-table .nowrap { white-space: nowrap; }
.vbh-table .strong { font-weight: 600; }
.vbh-purpose { max-width: 340px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.num.neg { color: #cc1f1f; font-weight: 700; }
.num.pos { color: var(--color-main-text); }
tr.assigned td { opacity: 0.85; }

.vbh-tablecount { padding: 6px 12px; font-size: 0.82em; opacity: 0.7; }

.vbh-typetag { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 0.82em; background-color: var(--color-background-dark); color: var(--color-main-text); }
.vbh-typetag.income { background-color: rgba(45, 125, 70, 0.25); }
.vbh-typetag.expense { background-color: rgba(199, 60, 60, 0.25); }

.vbh-assign { max-width: 280px; }
.vbh-assign.unassigned { border-color: var(--color-warning, #c7870a); }

/* Master-Detail */
.vbh-tree { flex: 0 0 480px; min-width: 320px; overflow-y: auto; border-right: 1px solid var(--color-border); padding: 12px 10px 24px; }
.vbh-treehead { display: flex; align-items: center; justify-content: space-between; padding: 0 4px 8px; }
.vbh-treeactions { display: flex; gap: 8px; }
.vbh-treelist { display: flex; flex-direction: column; }
.vbh-treenode { display: flex; align-items: center; gap: 6px; padding: 4px 8px; border-radius: 8px; cursor: pointer; }
.vbh-treenode:hover { background-color: var(--color-background-hover); }
.vbh-treenode.group { font-weight: 700; }
.vbh-treenode.selected { background-color: var(--color-primary-element); color: var(--color-primary-element-text); }
.vbh-treenode.selected .vbh-treenum, .vbh-treenode.selected .vbh-treesaldo, .vbh-treenode.selected .num.neg { color: var(--color-primary-element-text); }
.vbh-caret { display: inline-block; flex: 0 0 16px; width: 16px; height: 16px; line-height: 16px; text-align: center; border: none; background: transparent; cursor: pointer; color: inherit; opacity: 0.7; transition: transform 0.12s; font-size: 1em; padding: 0; }
.vbh-caret.open { transform: rotate(90deg); }
.vbh-caret.empty { cursor: default; color: var(--color-border); opacity: 0.6; }
.vbh-treenum { flex: 0 0 auto; min-width: 58px; font-variant-numeric: tabular-nums; opacity: 0.7; font-size: 0.85em; font-weight: 400; }
.vbh-treename { flex: 1 1 auto; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.vbh-treesaldo { flex: 0 0 auto; font-variant-numeric: tabular-nums; font-size: 0.88em; font-weight: 400; }
.vbh-treesaldo.zero { opacity: 0.3; }
.vbh-ccsummary { display: flex; align-items: center; justify-content: space-between; padding: 8px; margin: 0 0 8px; border-radius: 8px; background-color: var(--color-background-hover); }
.vbh-ccsummary span { opacity: 0.8; font-size: 0.85em; }
.vbh-ccsummary strong { font-variant-numeric: tabular-nums; }
.vbh-rename { flex: 1 1 240px; }
.vbh-treesaldo.neg, .vbh-ccsummary strong.neg { color: #cc1f1f; }
.vbh-ccrow { cursor: pointer; }
.vbh-ccdetail > td { padding: 0 8px 8px 28px; background-color: var(--color-main-background); }
.vbh-subtable { width: 100%; margin: 6px 0; font-size: 0.95em; border: 1px solid var(--color-border); border-radius: 8px; }
.vbh-subtable thead th { position: static; box-shadow: inset 0 -1px 0 var(--color-border); background-color: var(--color-background-hover); }

.vbh-detail { flex: 1 1 auto; min-width: 0; overflow-y: auto; padding: 16px 24px 48px; }
.vbh-detailhint { margin-top: 40px; text-align: center; }
.vbh-detailhead { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.vbh-detailhead h3 { margin: 0 0 6px; }
.vbh-cat { margin-left: 8px; opacity: 0.7; font-size: 0.9em; }
.vbh-opening { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 14px 0; padding: 12px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 12px); background-color: var(--color-background-hover); }
.vbh-statementbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-top: 12px; }

.vbh-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; margin-top: 10px; }
.vbh-form.vbh-form-stack { flex-direction: column; align-items: stretch; }
.vbh-form label { display: flex; flex-direction: column; font-size: 0.85em; gap: 3px; }
.vbh-form .vbh-grow { flex: 1 1 220px; }
.vbh-form .vbh-grow input, .vbh-form .vbh-grow select { width: 100%; }
.vbh-check { display: inline-flex; align-items: center; gap: 6px; }
.vbh-filter select { margin-left: 6px; }
.vbh-num { width: 130px; text-align: right; }
.vbh-short { width: 120px; }
.vbh-date { width: 150px; }

button.primary { background: var(--color-primary-element); color: var(--color-primary-element-text); border: none; border-radius: var(--border-radius-element, 8px); padding: 8px 14px; cursor: pointer; font-weight: 600; }
button.primary.small { padding: 5px 10px; }
button.primary:hover { background: var(--color-primary-element-hover); }
button.primary:disabled { opacity: 0.5; cursor: default; }
button.danger { background: #c1121f; color: #fff; border: none; border-radius: var(--border-radius-element, 8px); padding: 8px 14px; cursor: pointer; font-weight: 600; }
button.danger:disabled { opacity: 0.5; cursor: default; }
.vbh-btnlink { background: none; border: none; color: var(--color-primary-element); cursor: pointer; padding: 2px 6px; font-weight: 600; }
.vbh-iconbtn { background: none; border: 1px solid var(--color-border); border-radius: 6px; cursor: pointer; padding: 2px 7px; margin-left: 4px; font-size: 0.95em; color: var(--color-main-text); }
.vbh-iconbtn:hover { background-color: var(--color-background-dark); }
.vbh-iconbtn.del:hover { background-color: #c1121f; color: #fff; border-color: #c1121f; }

.vbh-previewsummary { display: flex; gap: 8px; flex-wrap: wrap; margin: 4px 0 10px; }
.vbh-badge { padding: 3px 10px; border-radius: 10px; font-size: 0.85em; background-color: var(--color-background-dark); color: var(--color-main-text); }
.vbh-badge.pos { background-color: #1f7a3d; color: #fff; }
.vbh-badge.muted { opacity: 0.9; }

.vbh-totals { display: flex; gap: 16px; margin: 12px 0; flex-wrap: wrap; }
.vbh-total { border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 12px); padding: 12px 18px; display: flex; flex-direction: column; min-width: 150px; background-color: var(--color-background-hover); }
.vbh-total span { opacity: 0.8; font-size: 0.85em; }
.vbh-total strong { font-size: 1.4em; }
.vbh-total.pos strong { color: #1f7a3d; }
.vbh-total.neg strong { color: #cc1f1f; }

/* Modal */
.vbh-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 10000; }
.vbh-modal { background: var(--color-main-background); color: var(--color-main-text); border-radius: var(--border-radius-large, 12px); padding: 20px 24px; width: min(640px, 92vw); max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.4); }
.vbh-modal h3 { margin-top: 0; }
.vbh-modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }

@media (prefers-color-scheme: dark) {
	.num.neg { color: #ff7a7a; }
	.vbh-bankchip-hint, .vbh-warn-inline { color: #ffb060; }
	.vbh-total.pos strong { color: #6fcf97; }
	.vbh-total.neg strong { color: #ff7a7a; }
	.vbh-treesaldo.neg, .vbh-ccsummary strong.neg { color: #ff7a7a; }
	.vbh-badge.pos { background-color: #2d7d46; }
}
</style>
