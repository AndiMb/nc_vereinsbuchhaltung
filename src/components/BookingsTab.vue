<template>
	<div style="display: contents;">
		<div class="vbh-sectiontop">
			<div class="vbh-subtabs">
				<button :class="{ active: bookingView === 'journal' }" @click="$emit('update:booking-view', 'journal')">
					{{ t('Alle Buchungen') }}
				</button>
				<button :class="{ active: bookingView === 'unassigned' }" @click="$emit('update:booking-view', 'unassigned')">
					{{ t('Zuzuordnen') }}
					<span v-if="unassignedCount > 0" class="vbh-badge vbh-badge--alert">{{ unassignedCount }}</span>
				</button>
				<button :class="{ active: bookingView === 'openitems' }" @click="$emit('update:booking-view', 'openitems')">
					{{ t('Offene Posten') }}
					<span
						v-if="overdueOpenItemsCount > 0"
						class="vbh-badge vbh-badge--alert"
						:title="n('%n überfälliger offener Posten', '%n überfällige offene Posten', overdueOpenItemsCount)">{{ overdueOpenItemsCount }}</span>
				</button>
				<button v-if="canWrite" :class="{ active: bookingView === 'rules' }" @click="$emit('update:booking-view', 'rules')">
					{{ t('Regeln') }}
				</button>
			</div>
			<div class="vbh-sectiontop-actions">
				<a
					v-if="bookingView === 'journal'"
					:href="exportJournalUrl"
					download
					class="vbh-export-btn"
					:title="t('Journal als CSV exportieren')"><NcIconSvgWrapper :path="mdiDownload" :size="16" inline /> CSV</a>
				<NcButton v-if="canWrite" variant="secondary" @click="openImport">
					<template #icon>
						<NcIconSvgWrapper :path="mdiUpload" :size="18" />
					</template>
					{{ t('Umsätze importieren') }}
				</NcButton>
			</div>
		</div>

		<div class="vbh-filterbar">
			<!-- Bewusst ohne v-model: ein reaktiver Wert würde bei jedem Tastendruck
			     die ganze Komponente samt Journal-Tabelle neu rendern. Gefiltert wird
			     erst über den entprellten bookingSearch (onSearchInput). -->
			<input
				ref="searchField"
				type="search"
				:placeholder="t('Suche (Text oder Betrag)…')"
				:title="t('Sucht in Text und Nummer; eine Zahl wie 12,50 findet auch Buchungen über genau diesen Betrag.')"
				class="vbh-search"
				@input="onSearchInput">
			<NcSelect
				v-if="bookingView === 'journal'"
				v-model="bookingFilterAccountOption"
				:options="accountFilterOptions"
				:filterBy="accountFilterBy"
				:selectable="isSelectableOption"
				label="label"
				:clearable="true"
				:placeholder="t('Konto filtern')"
				class="vbh-filter-select"
				@search="bookingFilterSearch = $event">
				<!-- Kategorie-Überschriften sind hier echte Filter („alle Konten der
				     Kategorie“) und werden deshalb als wählbare Gruppenzeile gezeichnet.
				     NcEllipsisedOption ist die Standarddarstellung von NcSelect
				     (Ellipsis + Hervorhebung des Suchbegriffs). -->
				<template #option="opt">
					<span v-if="opt.isCategory" class="vbh-select-group">
						<NcEllipsisedOption :name="opt.label" :search="bookingFilterSearch" />
						<span class="vbh-select-group-hint">{{ t('(alle)') }}</span>
					</span>
					<NcEllipsisedOption v-else :name="opt.label" :search="bookingFilterSearch" />
				</template>
			</NcSelect>
			<label v-if="bookingView === 'journal'" class="vbh-checkinline" :title="t('Nur Buchungen ohne angehängten Beleg zeigen (z. B. vor der Kassenprüfung)')">
				<input v-model="journalOnlyNoAttachment" type="checkbox">
				{{ t('nur ohne Beleg') }}
			</label>
		</div>

		<div class="vbh-sectionbody vbh-sectionbody--contained">
			<!-- JOURNAL VIEW -->
			<template v-if="bookingView === 'journal'">
				<div v-if="journalNumberIssues" class="vbh-yearwarn">
					<p class="vbh-warn-inline">
						{{ tRaw('⚠ Buchungsnummern {period} nicht lückenlos:', { period: selectedPeriod ? selectedPeriod.label : '' }) }}
						<template v-if="journalNumberIssues.missing.length">
							{{ t('fehlend {list}', { list: journalNumberIssues.missing.slice(0, 20).join(', ') }) }}<template v-if="journalNumberIssues.missing.length > 20">
								…
							</template>
						</template>
						<template v-if="journalNumberIssues.missing.length && journalNumberIssues.duplicates.length">
							·
						</template>
						<template v-if="journalNumberIssues.duplicates.length">
							{{ t('doppelt {list}', { list: journalNumberIssues.duplicates.join(', ') }) }}
						</template>
					</p>
				</div>
				<div v-if="filteredJournalRows.length && isMobile" class="vbh-cardlist">
					<div class="vbh-tablecount">
						{{ filteredJournalRows.length !== sortedJournalRows.length ? t('{n} von {total} Buchungssätze', { n: filteredJournalRows.length, total: sortedJournalRows.length }) : t('{n} Buchungssätze', { n: filteredJournalRows.length }) }}
					</div>
					<template v-for="g in journalCardGroups" :key="g.key">
						<div class="vbh-monthdivider">
							{{ g.label }}
						</div>
						<BookingCard
							v-for="r in g.rows"
							:key="g.key + '-' + r.id"
							:row="r"
							:attachmentCount="attachmentCountMap[r.id] ? attachmentCountMap[r.id].count : 0"
							:flow="cardFlow(r)"
							:tappable="canWrite || !!attachmentCountMap[r.id]"
							@open="openBookingCard(r)"
							@paperclip="clickPaperclip(r)" />
					</template>
				</div>
				<!-- Desktop: die Tabelle bleibt gemountet, solange es überhaupt Buchungen
				     gibt, und der Filter blendet Zeilen nur aus (hidden-Attribut, gesetzt
				     in applyJournalVisibility()). So muss beim Tippen und Löschen keine
				     Zeile samt Aktionsmenü neu aufgebaut oder gepatcht werden; v-memo
				     überspringt alle Zeilen, deren Daten gleich bleiben. Strg+F findet
				     weiter alle sichtbaren. -->
				<div v-else-if="!isMobile && sortedJournalRows.length" class="vbh-tablecard">
					<div v-show="filteredJournalRows.length" class="vbh-tablecount">
						{{ filteredJournalRows.length !== sortedJournalRows.length ? t('{n} von {total} Buchungssätze', { n: filteredJournalRows.length, total: sortedJournalRows.length }) : t('{n} Buchungssätze', { n: filteredJournalRows.length }) }}
					</div>
					<NcEmptyContent v-if="!filteredJournalRows.length" :name="t('Keine Treffer')" :description="t('Suchfilter anpassen oder löschen.')" />
					<table v-show="filteredJournalRows.length" class="vbh-table">
						<thead>
							<tr>
								<th v-if="bulkSelectable" class="vbh-col-check">
									<input
										type="checkbox"
										:checked="allVisibleSelected"
										:indeterminate="selectedVisibleIds.length > 0 && !allVisibleSelected"
										:disabled="!selectableVisibleRows.length"
										:aria-label="t('Alle angezeigten Buchungen auswählen')"
										:title="t('Alle angezeigten Buchungen auswählen')"
										@change="selectAllVisible($event.target.checked)">
								</th>
								<th class="sortable num vbh-col-hide-sm" @click="toggleSort('journal', 'entryNo')">
									{{ t('Nr.') }}{{ sortArrow('journal', 'entryNo') }}
								</th>
								<th class="sortable nowrap" @click="toggleSort('journal', 'date')">
									{{ t('Datum') }}{{ sortArrow('journal', 'date') }}
								</th>
								<th class="sortable" @click="toggleSort('journal', 'description')">
									{{ t('Beschreibung') }}{{ sortArrow('journal', 'description') }}
								</th>
								<th class="sortable vbh-col-hide-sm" @click="toggleSort('journal', 'soll')">
									{{ t('Soll') }}{{ sortArrow('journal', 'soll') }}
								</th>
								<th class="sortable vbh-col-hide-sm" @click="toggleSort('journal', 'haben')">
									{{ t('Haben') }}{{ sortArrow('journal', 'haben') }}
								</th>
								<th class="sortable num" @click="toggleSort('journal', 'amount')">
									{{ t('Betrag') }}{{ sortArrow('journal', 'amount') }}
								</th>
								<th class="vbh-col-actions--wide" />
							</tr>
						</thead>
						<tbody ref="journalBody">
							<tr
								v-for="r in sortedJournalRows"
								:key="r.id"
								v-memo="[r, attachmentCountMap[r.id], txByJournalId[r.id], canWrite, amountDisplay, accountsById, isDateClosed(r.date), bulkSelectable, bulkSelectable && selectedJournalIds.has(r.id)]"
								:data-id="r.id">
								<td v-if="bulkSelectable" class="vbh-col-check">
									<input
										type="checkbox"
										:checked="selectedJournalIds.has(r.id)"
										:disabled="isDateClosed(r.date)"
										:aria-label="t('Buchung #{n} auswählen', { n: r.entryNo })"
										:title="isDateClosed(r.date) ? t('Geschäftsjahr abgeschlossen') : t('Mit Umschalt-Klick einen Bereich auswählen')"
										@click="onSelectClick(r.id, $event)">
								</td>
								<td class="num strong vbh-col-hide-sm">
									{{ r.entryNo }}
								</td>
								<td class="nowrap">
									{{ formatDate(r.date) }}
								</td>
								<td class="vbh-purpose" :title="r.description">
									<span class="vbh-clamp">{{ r.description }}</span>
								</td>
								<td class="vbh-col-hide-sm">
									{{ r.soll }}
								</td>
								<td class="vbh-col-hide-sm">
									{{ r.haben }}
								</td>
								<td class="num strong" :class="flowClass(rowFlow(r))" :title="flowLabel(rowFlow(r))">
									{{ formatFlowMoney(r.amount, rowFlow(r)) }}
								</td>
								<td class="nowrap right">
									<div class="vbh-actions">
										<CommentMark v-if="r.comment" :text="r.comment" />
										<NcButton
											v-if="attachmentCountMap[r.id]"
											variant="tertiary"
											:title="attachmentCountMap[r.id].count === 1 ? t('Beleg anzeigen') : t('{n} Belege', { n: attachmentCountMap[r.id].count })"
											:aria-label="t('{n} Beleg(e)', { n: attachmentCountMap[r.id].count })"
											@click="clickPaperclip(r)">
											<template #icon>
												<NcIconSvgWrapper :path="mdiPaperclip" :size="16" />
											</template>
										</NcButton>
										<NcButton
											v-if="canWrite"
											variant="tertiary"
											:aria-label="t('Bearbeiten')"
											@click="editBooking(r)">
											<template #icon>
												<NcIconSvgWrapper :path="mdiPencil" :size="20" />
											</template>
										</NcButton>
										<!-- Seltener genutzte Aktionen in einem Menü statt als eigene
										     Buttons, sonst wird die Zeile durch bis zu 4 Icon-Buttons
										     zweizeilig (siehe .vbh-table thead th:empty in styles.css). -->
										<NcActions v-if="canWrite && (txByJournalId[r.id] || !isDateClosed(r.date))" :forceMenu="true">
											<NcActionButton
												v-if="txByJournalId[r.id]"
												:title="tRaw('Regel anlegen: {counterparty} künftig automatisch zuordnen', { counterparty: txByJournalId[r.id].counterparty })"
												@click="createRuleFromTx(txByJournalId[r.id])">
												<template #icon>
													<NcIconSvgWrapper :path="mdiFlash" :size="16" />
												</template>
												{{ t('Regel anlegen') }}
											</NcActionButton>
											<NcActionButton v-if="!isDateClosed(r.date)" @click="removeBooking(r)">
												<template #icon>
													<NcIconSvgWrapper :path="mdiDelete" :size="16" />
												</template>
												{{ t('Löschen') }}
											</NcActionButton>
										</NcActions>
									</div>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<NcEmptyContent v-else-if="sortedJournalRows.length" :name="t('Keine Treffer')" :description="t('Suchfilter anpassen oder löschen.')" />
				<NcEmptyContent v-else :name="t('Noch keine Buchungssätze')" :description="t('Legen Sie mit ‛Neue Buchung\' einen ersten Buchungssatz an.')">
					<template #action>
						<NcButton variant="tertiary" @click="$emit('help')">
							{{ t('Mehr dazu') }}
						</NcButton>
					</template>
				</NcEmptyContent>
				<!-- Mehrfachauswahl: hinter der Tabelle und unten angeheftet
				     (.vbh-bulkbar), damit ihr Erscheinen keine Zeile unter dem
				     Mauszeiger verschiebt. Zählt und handelt nur auf den gerade
				     sichtbaren markierten Zeilen. -->
				<div v-if="bulkSelectable && selectedVisibleIds.length" class="vbh-bulkbar">
					<span class="vbh-bulkbar-count">{{ n('%n Buchung ausgewählt', '%n Buchungen ausgewählt', selectedVisibleIds.length) }}</span>
					<NcButton variant="primary" :disabled="bulkBusy" @click="attachReceiptToSelected">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPaperclip" :size="18" />
						</template>
						{{ t('Beleg zuordnen…') }}
					</NcButton>
					<NcButton variant="tertiary" :disabled="bulkBusy" @click="clearJournalSelection">
						{{ t('Auswahl aufheben') }}
					</NcButton>
				</div>
			</template>

			<!-- TRANSACTIONS VIEW (unassigned / assigned) -->
			<template v-else-if="bookingView === 'unassigned'">
				<div v-if="assignProgress.total > 0" class="vbh-progress">
					<span class="vbh-progress-label">{{ t('{done} von {total} Bankbuchungen zugeordnet', { done: assignProgress.done, total: assignProgress.total }) }}</span>
					<div class="vbh-progress-bar">
						<div class="vbh-progress-fill" :style="{ width: assignProgress.pct + '%' }" />
					</div>
				</div>
				<div v-if="currentTransactions.length && isMobile" class="vbh-cardlist">
					<div class="vbh-tablecount">
						{{ t('{n} Buchungen', { n: currentTransactions.length }) }}
					</div>
					<div
						v-for="tx in currentTransactions"
						:key="'m' + tx.id"
						class="vbh-mcard"
						:class="tx.status === 'assigned' ? '' : 'open'">
						<div class="vbh-mcard-top">
							<span class="vbh-mcard-meta">{{ formatDate(tx.bookingDate) }}</span>
							<span class="vbh-mcard-amount" :class="txAmountClass(tx, true)" :title="flowLabel(txFlow(tx))">{{ formatFlowMoney(tx.amount, txFlow(tx)) }}</span>
							<NcButton
								v-if="canWrite && tx.status === 'unassigned' && !isDateClosed(tx.bookingDate)"
								variant="tertiary"
								:aria-label="t('Umsatz löschen')"
								:title="t('Umsatz löschen (z. B. Dublette)')"
								@click="removeTransaction(tx)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiDelete" :size="18" />
								</template>
							</NcButton>
						</div>
						<div class="vbh-mcard-title">
							{{ tx.counterparty }}
						</div>
						<div v-if="tx.purpose" class="vbh-mcard-purpose">
							{{ tx.purpose }}
						</div>
						<button
							v-if="canWrite && !isSplitAssigned(tx) && !tx.contraAccountId && suggestionsById[tx.id] && !isDateClosed(tx.bookingDate)"
							type="button"
							class="vbh-suggest-chip vbh-suggest-chip--big"
							@click="applySuggestion(tx)">
							{{ tRaw('✓ Vorschlag übernehmen: {label}', { label: suggestionsById[tx.id].label }) }}
						</button>
						<template v-if="isSplitAssigned(tx)">
							<span class="vbh-split-badge">{{ t('Aufgeteilt auf mehrere Konten') }}</span>
							<button
								v-if="canWrite && !isDateClosed(tx.bookingDate)"
								type="button"
								class="vbh-suggest-chip"
								@click="onAssign(tx, '')">
								{{ t('Zuordnung aufheben') }}
							</button>
						</template>
						<template v-else>
							<button
								type="button"
								class="vbh-fieldbtn"
								:disabled="!canWrite || isDateClosed(tx.bookingDate)"
								@click="openAccountPicker('assign', tx)">
								<span class="vbh-fieldbtn-text">
									<span class="vbh-fieldbtn-lab">{{ t('Konto / Kategorie') }}</span>
									<span class="vbh-fieldbtn-val" :class="{ placeholder: !tx.contraAccountId }">{{ tx.contraAccountId ? accountLabel(tx.contraAccountId) : t('Konto wählen…') }}</span>
								</span>
								<span class="vbh-fieldbtn-chev" aria-hidden="true">›</span>
							</button>
							<button
								v-if="canWrite && !isDateClosed(tx.bookingDate)"
								type="button"
								class="vbh-suggest-chip"
								@click="openSplitAssign(tx)">
								{{ t('Aufteilen…') }}
							</button>
							<button
								v-if="canAttachToTx(tx)"
								type="button"
								class="vbh-suggest-chip"
								@click="openFolderPickerForTx(tx)">
								{{ t('Beleg vormerken…') }}
							</button>
							<ul v-if="pendingFor(tx).length" class="vbh-txfiles">
								<li v-for="f in pendingFor(tx)" :key="f.fileId" class="vbh-txfile">
									<a
										:href="inboxViewUrl(f.fileId)"
										target="_blank"
										rel="noopener"
										class="vbh-txfile-name">{{ f.name }}</a>
									<button
										type="button"
										class="vbh-txfile-remove"
										:aria-label="tRaw('Vormerkung entfernen: {name}', { name: f.name })"
										@click="removeTxPendingFile(tx.id, f.fileId)">
										×
									</button>
								</li>
							</ul>
						</template>
					</div>
				</div>
				<div v-else-if="currentTransactions.length" class="vbh-tablecard">
					<div class="vbh-tablecount">
						{{ t('{n} Buchungen', { n: currentTransactions.length }) }}
					</div>
					<table class="vbh-table">
						<thead>
							<tr>
								<th class="sortable nowrap" @click="toggleSort('transactions', 'bookingDate')">
									{{ t('Datum') }}{{ sortArrow('transactions', 'bookingDate') }}
								</th>
								<th class="sortable" @click="toggleSort('transactions', 'counterparty')">
									{{ t('Empfänger/Zahler') }}{{ sortArrow('transactions', 'counterparty') }}
								</th>
								<th class="vbh-col-hide-sm">
									{{ t('Verwendungszweck') }}
								</th>
								<th class="sortable num" @click="toggleSort('transactions', 'amount')">
									{{ t('Betrag') }}{{ sortArrow('transactions', 'amount') }}
								</th>
								<th>{{ t('Konto / Kategorie') }}</th>
							</tr>
						</thead>
						<transition-group tag="tbody" name="vbh-row">
							<tr v-for="tx in currentTransactions" :key="tx.id" :class="{ assigned: tx.status === 'assigned', open: tx.status !== 'assigned' }">
								<td class="nowrap">
									{{ formatDate(tx.bookingDate) }}
								</td>
								<td>{{ tx.counterparty }}</td>
								<td class="vbh-purpose vbh-col-hide-sm" :title="tx.purpose">
									<span class="vbh-clamp">{{ tx.purpose }}</span>
								</td>
								<td class="num" :class="txAmountClass(tx)" :title="flowLabel(txFlow(tx))">
									{{ formatFlowMoney(tx.amount, txFlow(tx)) }}
								</td>
								<td class="vbh-assign-cell">
									<!-- Aufgeteilter Umsatz: das Auswahlfeld fasst nur ein Konto
									     und stuende hier leer da. Die Konten selbst zeigt der
									     Kontoauszug bzw. das Journal. -->
									<div v-if="isSplitAssigned(tx)" class="vbh-assign-inner">
										<span class="vbh-split-badge">{{ t('Aufgeteilt auf mehrere Konten') }}</span>
										<button
											v-if="canWrite && !isDateClosed(tx.bookingDate)"
											class="vbh-suggest-chip"
											:title="t('Zuordnung aufheben und neu vergeben')"
											@click="onAssign(tx, '')">
											{{ t('Zuordnung aufheben') }}
										</button>
									</div>
									<div v-else class="vbh-assign-inner">
										<div class="vbh-assign-row">
											<NcSelect
												:modelValue="accountOptionFor(tx.contraAccountId)"
												:options="accountOptionsList"
												:filterBy="accountFilterBy"
												:selectable="isSelectableOption"
												:clearable="!!tx.contraAccountId"
												:disabled="!canWrite || isDateClosed(tx.bookingDate)"
												label="label"
												:placeholder="t('– nicht zugeordnet –')"
												class="vbh-assign-select"
												@update:modelValue="v => onAssign(tx, v ? v.id : '')" />
										</div>
										<div class="vbh-assign-btns">
											<button
												v-if="canWrite && !tx.contraAccountId && suggestionsById[tx.id] && !isDateClosed(tx.bookingDate)"
												class="vbh-suggest-chip"
												:title="tRaw('Vorschlag übernehmen: {label}', { label: suggestionsById[tx.id].label })"
												@click="applySuggestion(tx)">
												{{ tRaw('✓ Vorschlag: {label}', { label: suggestionsById[tx.id].label }) }}
											</button>
											<button
												v-if="canWrite && !isDateClosed(tx.bookingDate)"
												class="vbh-suggest-chip"
												:title="t('Den Umsatz auf mehrere Gegenkonten verteilen')"
												@click="openSplitAssign(tx)">
												{{ t('Aufteilen…') }}
											</button>
											<NcButton
												v-if="canAttachToTx(tx)"
												:variant="pendingFor(tx).length ? 'secondary' : 'tertiary'"
												:aria-label="t('Beleg aus dem Wächter-Ordner vormerken')"
												:title="t('Beleg aus dem Wächter-Ordner vormerken – er wird beim Zuordnen mit der Buchung verknüpft')"
												@click="openFolderPickerForTx(tx)">
												<template #icon>
													<NcIconSvgWrapper :path="mdiPaperclip" :size="18" />
												</template>
												<template v-if="pendingFor(tx).length">
													{{ pendingFor(tx).length }}
												</template>
											</NcButton>
											<NcButton
												v-if="canWrite && !isDateClosed(tx.bookingDate)"
												variant="tertiary"
												:aria-label="t('Umsatz löschen')"
												:title="t('Umsatz löschen (z. B. Dublette)')"
												@click="removeTransaction(tx)">
												<template #icon>
													<NcIconSvgWrapper :path="mdiDelete" :size="18" />
												</template>
											</NcButton>
										</div>
										<ul v-if="pendingFor(tx).length" class="vbh-txfiles">
											<li v-for="f in pendingFor(tx)" :key="f.fileId" class="vbh-txfile">
												<a
													:href="inboxViewUrl(f.fileId)"
													target="_blank"
													rel="noopener"
													class="vbh-txfile-name">{{ f.name }}</a>
												<button
													type="button"
													class="vbh-txfile-remove"
													:aria-label="tRaw('Vormerkung entfernen: {name}', { name: f.name })"
													@click="removeTxPendingFile(tx.id, f.fileId)">
													×
												</button>
											</li>
										</ul>
									</div>
								</td>
							</tr>
						</transition-group>
					</table>
				</div>
				<NcEmptyContent v-else-if="bookingSearch" :name="t('Keine Treffer')" :description="t('Suchfilter anpassen.')" />
				<NcEmptyContent v-else :name="t('Alle Buchungen zugeordnet')" :description="t('Keine offenen Bankbuchungen – alles erledigt.')">
					<template v-if="canWrite" #action>
						<NcButton variant="secondary" @click="openImport">
							<template #icon>
								<NcIconSvgWrapper :path="mdiUpload" :size="18" />
							</template>
							{{ t('Neue Umsätze importieren') }}
						</NcButton>
					</template>
				</NcEmptyContent>
			</template>

			<!-- OFFENE POSTEN -->
			<template v-else-if="bookingView === 'openitems'">
				<div v-if="canWrite" class="vbh-card">
					<h4>{{ t('Neuer offener Posten') }}</h4>
					<div class="vbh-form">
						<label class="vbh-grow">{{ t('Debitor') }}<input v-model="openItemForm.debtor" :placeholder="t('z. B. Max Mustermann')"></label>
						<label>{{ t('Betrag (€)') }}<AmountInput
							v-model="openItemForm.amount"
							class="vbh-num" /></label>
						<label>{{ t('Fällig am') }}<input v-model="openItemForm.dueDate" type="date"></label>
					</div>
					<div class="vbh-form">
						<label class="vbh-grow">{{ t('Konto (für die spätere Buchung)') }}
							<NcSelect
								v-model="openItemAccountOption"
								:options="accountOptionsList"
								:filterBy="accountFilterBy"
								:selectable="isSelectableOption"
								label="label"
								:placeholder="t('optional')"
								:clearable="true" />
						</label>
						<label class="vbh-grow">{{ t('Notiz') }}<input v-model="openItemForm.description" :placeholder="t('optional')"></label>
						<NcButton variant="primary" :disabled="!openItemForm.debtor || !openItemForm.amount" @click="createOpenItem">
							{{ t('Anlegen') }}
						</NcButton>
					</div>
				</div>

				<div class="vbh-filterbar">
					<button
						v-for="f in openItemFilterOptions"
						:key="f.key"
						type="button"
						class="vbh-chip"
						:class="{ active: activeOpenItemFilter === f.key }"
						@click="openItemFilter = f.key">
						{{ f.label }}<span class="vbh-chip-count">{{ f.count }}</span>
					</button>
				</div>

				<!-- Forderungen des Beitragsmoduls stehen hier nur zur Ansicht: ihre Regeln (Erledigungsvermerk, Storno nur vor
				     der Einreichung, Erlass statt Löschen) setzt der Einzug-Reiter durch (Issue #121). -->
				<p v-if="hasClaimRows" class="vbh-hint vbh-hint--info" role="note">
					{{ membershipActive
						? t('Forderungen an Mitglieder (Beitrag oder Gebühr) sehen Sie hier nur. Bearbeitet werden sie im Reiter „Beiträge“ unter Einzug, Segment „Forderungen“: als bezahlt vermerken, stunden, erlassen, stornieren.')
						: t('Forderungen an Mitglieder (Beitrag oder Gebühr) sehen Sie hier nur; bearbeitet werden sie im Beitragsmodul.') }}
				</p>

				<div v-if="filteredOpenItems.length" class="vbh-tablecard">
					<table class="vbh-table">
						<thead>
							<tr>
								<th>{{ t('Debitor') }}</th>
								<th class="vbh-col-hide-sm">
									{{ t('Notiz') }}
								</th>
								<th class="nowrap vbh-col-oi-due">
									{{ t('Fällig') }}
								</th>
								<th class="num vbh-col-oi-amount">
									{{ t('Betrag') }}
								</th>
								<th class="vbh-col-hide-sm vbh-col-oi-account">
									{{ t('Konto') }}
								</th>
								<th class="vbh-col-oi-status">
									{{ t('Status') }}
								</th>
								<th class="vbh-col-memberactions" />
							</tr>
						</thead>
						<tbody>
							<tr v-for="o in filteredOpenItems" :key="o.id">
								<td>
									{{ o.debtor }}
									<span v-if="isClaim(o)" class="vbh-typetag">{{ claimTypeLabel(o.type) }}</span>
								</td>
								<td class="vbh-col-hide-sm vbh-purpose">
									<span class="vbh-clamp">{{ o.description }}</span>
								</td>
								<td class="nowrap">
									{{ o.dueDate ? formatDate(o.dueDate) : '–' }}
									<span v-if="o.overdue" class="vbh-warn-inline vbh-warn-inline--block">{{ t('überfällig') }}</span>
								</td>
								<td class="num strong">
									{{ formatMoney(o.amount) }}
								</td>
								<td class="vbh-col-hide-sm">
									{{ o.accountId ? accountLabel(o.accountId) : '' }}
								</td>
								<td><span class="vbh-typetag" :class="o.status">{{ openItemStatusLabel(o.status) }}</span></td>
								<td class="right nowrap">
									<div class="vbh-actions">
										<template v-if="isClaim(o)">
											<NcButton
												v-if="membershipActive"
												variant="tertiary"
												:aria-label="claimLinkLabel(o)"
												@click="$emit('go-claims', o.memberId)">
												{{ canWrite ? t('Im Einzug bearbeiten') : t('Im Einzug ansehen') }}
											</NcButton>
										</template>
										<template v-else-if="canWrite && o.status === 'open'">
											<NcButton variant="tertiary" @click="markOpenItemPaid(o)">
												{{ t('Bezahlt') }}
											</NcButton>
											<NcButton variant="tertiary" @click="cancelOpenItem(o)">
												{{ t('Stornieren') }}
											</NcButton>
										</template>
										<NcButton v-else-if="canWrite" variant="tertiary" @click="reopenOpenItem(o)">
											{{ t('Wieder öffnen') }}
										</NcButton>
									</div>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<NcEmptyContent v-else :name="t('Keine offenen Posten')" :description="t('Legen Sie oben einen neuen offenen Posten an, z. B. einen unbezahlten Mitgliedsbeitrag.')" />
			</template>

			<!-- REGELN -->
			<template v-else-if="bookingView === 'rules' && canWrite">
				<RulesPanel />
			</template>
		</div>
	</div>
</template>

<script>
import { mdiDelete, mdiDownload, mdiFlash, mdiPaperclip, mdiPencil, mdiUpload } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcActionButton, NcActions, NcButton, NcEllipsisedOption, NcEmptyContent, NcIconSvgWrapper, NcSelect } from '@nextcloud/vue'
import { toRefs } from 'vue'
import AmountInput from './AmountInput.vue'
import BookingCard from './BookingCard.vue'
import CommentMark from './CommentMark.vue'
import RulesPanel from './RulesPanel.vue'
import api from '../api.js'
import { buildAccountOptions, useAccounts } from '../composables/useAccounts.js'
import { useAuth } from '../composables/useAuth.js'
import { useJournal } from '../composables/useJournal.js'
import { useOpenItems } from '../composables/useOpenItems.js'
import { usePeriods } from '../composables/usePeriods.js'
import { useSort } from '../composables/useSort.js'
import { journalRowMatchesAccountFilter } from '../lib/accountFilter.js'
import { claimTypeLabel } from '../lib/claims.js'
import { bookingFlow, flowClass, flowLabel, formatFlowMoney, transactionFlow } from '../lib/flow.js'
import { amountClass, errMsg, formatDate, formatMoney } from '../lib/format.js'
import { openItemStatusLabel } from '../lib/openItems.js'
import { rangeIds } from '../lib/receiptBatch.js'
import { buildSearchIndex, journalSearch, matchesQuery, prepareQuery, transactionSearch } from '../lib/search.js'
import { isSelectableOption } from '../lib/selectOptions.js'

export default {
	name: 'BookingsTab',
	components: { NcButton, NcActions, NcActionButton, NcSelect, NcEllipsisedOption, NcEmptyContent, NcIconSvgWrapper, AmountInput, BookingCard, CommentMark, RulesPanel },
	props: {
		isMobile: { type: Boolean, required: true },
		// 'plain' | 'signed': Vorzeichen und Farbe am Betrag, siehe lib/flow.js
		amountDisplay: { type: String, default: 'plain' },
		bookingView: { type: String, required: true },
		// Ob der Reiter „Beiträge“ da ist - nur dann führt der Hinweis an einer Forderung dorthin (Issue #121).
		membershipActive: { type: Boolean, default: false },
		attachmentCountMap: { type: Object, required: true },
		// suggestionsById bleibt in App.vue berechnet (wird auch vom
		// AccountPickerSheet-Flow dort gebraucht), hier nur als Prop gelesen.
		suggestionsById: { type: Object, required: true },
		openImport: { type: Function, required: true },
		clickPaperclip: { type: Function, required: true },
		openBookingCard: { type: Function, required: true },
		editBooking: { type: Function, required: true },
		createRuleFromTx: { type: Function, required: true },
		removeBooking: { type: Function, required: true },
		removeTransaction: { type: Function, required: true },
		openAccountPicker: { type: Function, required: true },
		onAssign: { type: Function, required: true },
		openSplitAssign: { type: Function, required: true },
		applySuggestion: { type: Function, required: true },
		// Belege direkt in „Zuzuordnen“ vormerken (nur mit Wächter-Ordner), siehe
		// txPendingFiles/linkTxPendingFiles() in App.vue.
		watchMode: { type: Boolean, default: false },
		txPendingFiles: { type: Object, default: () => ({}) },
		openFolderPickerForTx: { type: Function, default: () => {} },
		removeTxPendingFile: { type: Function, default: () => {} },
		// Einen Beleg an alle markierten Buchungen des Journals (ebenfalls nur
		// mit Wächter-Ordner); löst mit true auf, wenn alles verknüpft ist,
		// siehe openFolderPickerForBookings() in App.vue.
		openFolderPickerForBookings: { type: Function, default: async () => false },
	},

	emits: ['go-claims', 'help', 'update:booking-view'],

	setup() {
		const auth = useAuth()
		const periods = usePeriods()
		const accounts = useAccounts()
		const journal = useJournal()
		const openItemsC = useOpenItems()
		return {
			canWrite: auth.canWrite,
			...toRefs(periods.state),
			selectedPeriod: periods.selectedPeriod,
			isDateClosed: periods.isDateClosed,
			accountsSorted: accounts.accountsSorted,
			accountsById: accounts.accountsById,
			journalRows: journal.journalRows,
			...toRefs(journal.state),
			unassignedCount: journal.unassignedCount,
			...toRefs(openItemsC.state),
			overdueOpenItemsCount: openItemsC.overdueCount,
			loadOpenItems: openItemsC.loadOpenItems,
			isClaim: openItemsC.isClaim,
			// Sortierung aus dem gemeinsamen Zustand (dieselbe Einstellung nutzt
			// die Saldenliste im Berichte-Tab), nicht mehr als Prop aus App.vue.
			...useSort(),
		}
	},

	data() {
		return {
			mdiDownload,
			mdiUpload,
			mdiPaperclip,
			mdiPencil,
			mdiFlash,
			mdiDelete,
			// Der entprellte Suchtext, nach dem gefiltert wird (onSearchInput).
			bookingSearch: '',
			// null | { accountId } | { category }: Kontofilter des Journals, siehe
			// journalRowMatchesAccountFilter().
			bookingFilter: null,
			bookingFilterSearch: '',
			journalOnlyNoAttachment: false,
			// Mehrfachauswahl im Journal. Markierte Zeilen, die ein Filter
			// ausblendet, bleiben markiert, zählen aber erst wieder, wenn sie
			// sichtbar sind (selectedVisibleIds).
			selectedJournalIds: new Set(),
			// Anker für Umschalt-Klick (onSelectClick).
			lastSelectedId: null,
			bulkBusy: false,
			openItemForm: { debtor: '', description: '', amount: '', dueDate: '', accountId: null },
			openItemFilter: 'open',
		}
	},

	computed: {
		exportJournalUrl() { return api.exportJournalUrl(this.selectedPeriodId) },
		accountUsageCounts() {
			const counts = {}
			for (const item of this.journalData) {
				for (const l of (item.lines || [])) {
					counts[l.accountId] = (counts[l.accountId] || 0) + 1
				}
			}
			return counts
		},

		accountOptionsList() {
			return buildAccountOptions(this.accountsSorted, this.accountUsageCounts, this.t)
		},

		// Nur fuer den Journal-Filter: dort sind die Kategorie-Ueberschriften
		// waehlbar und meinen alle Konten der Kategorie. In den Konto-Pickern
		// (Zuordnen, Offene Posten) bleiben sie reine Trenner.
		accountFilterOptions() {
			return buildAccountOptions(this.accountsSorted, this.accountUsageCounts, this.t, { selectableCategories: true })
		},

		bookingFilterAccountOption: {
			get() {
				const f = this.bookingFilter
				if (!f) { return null }
				return this.accountFilterOptions.find((o) => (f.accountId
					? o.id === f.accountId
					: o.isCategory && o.category === f.category)) ?? null
			},

			set(v) {
				if (v && v.isCategory) {
					this.bookingFilter = { category: v.category }
				} else if (v && typeof v.id === 'number') {
					this.bookingFilter = { accountId: v.id }
				} else {
					// Leeren oder eine reine Trenner-Option (id null): kein Filter.
					this.bookingFilter = null
				}
			},
		},

		sortedJournalRows() { return this.applySort(this.journalRows, this.sort.journal) },
		// Einmal je Datenstand statt je Tastendruck, siehe lib/search.js.
		journalSearchIndex() { return buildSearchIndex(this.journalRows, journalSearch.fields, journalSearch.cents) },
		searchQuery() { return prepareQuery(this.bookingSearch) },
		filteredJournalRows() {
			let rows = this.sortedJournalRows
			const q = this.searchQuery
			if (q) {
				const index = this.journalSearchIndex
				rows = rows.filter((r) => matchesQuery(index.get(r.id), q))
			}
			if (this.bookingFilter) {
				rows = rows.filter((r) => journalRowMatchesAccountFilter(r, this.bookingFilter, this.accountsById, this.t))
			}
			if (this.journalOnlyNoAttachment) {
				rows = rows.filter((r) => !this.attachmentCountMap[r.id])
			}
			return rows
		},

		visibleJournalIds() { return new Set(this.filteredJournalRows.map((r) => r.id)) },

		// Mehrfachauswahl nur am Desktop und nur, solange es eine Aktion dafür
		// gibt: „Beleg zuordnen“ braucht den Wächter-Ordner.
		bulkSelectable() { return !this.isMobile && this.watchMode && this.canWrite },
		// Buchungen abgeschlossener Geschäftsjahre bekommen keinen Beleg mehr.
		selectableVisibleRows() { return this.filteredJournalRows.filter((r) => !this.isDateClosed(r.date)) },
		selectedVisibleIds() { return this.selectableVisibleRows.filter((r) => this.selectedJournalIds.has(r.id)).map((r) => r.id) },
		allVisibleSelected() { return this.selectableVisibleRows.length > 0 && this.selectedVisibleIds.length === this.selectableVisibleRows.length },

		// Mobil: fest nach Datum absteigend – einmal je Datenstand sortiert,
		// journalCardGroups filtert dann nur noch.
		journalRowsByDateDesc() {
			return [...this.journalRows].sort((a, b) => String(b.date || '').localeCompare(String(a.date || '')) || (b.entryNo || 0) - (a.entryNo || 0))
		},

		// Mobil: Journal fest nach Datum absteigend, gruppiert nach Monat
		// (die Spaltenkopf-Sortierung der Tabelle entfällt auf Karten).
		//
		// Gruppiert wird bewusst nach KALENDERmonat („JJJJ-MM" aus dem Datum),
		// unabhängig davon, wann das Geschäftsjahr beginnt: die Trennlinien
		// dienen dem Blättern durch eine lange Liste, nicht der Abgrenzung des
		// Zeitraums. Das ist kein Rest der alten Jahres-Logik – bitte nicht auf
		// die Geschäftsjahr-Grenzen umstellen.
		journalCardGroups() {
			const visible = this.visibleJournalIds
			const rows = this.journalRowsByDateDesc.filter((r) => visible.has(r.id))
			const names = [this.t('Januar'), this.t('Februar'), this.t('März'), this.t('April'), this.t('Mai'), this.t('Juni'), this.t('Juli'), this.t('August'), this.t('September'), this.t('Oktober'), this.t('November'), this.t('Dezember')]
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

		// Kassenprüfung: fehlende/doppelte Buchungsnummern im gewählten Zeitraum
		// (bei „Alle Zeiträume" nicht sinnvoll, da je Geschäftsjahr nummeriert
		// wird).
		journalNumberIssues() {
			if (!this.selectedPeriodId) { return null }
			const nos = this.journalRows
				.map((r) => r.entryNo)
				.filter((n) => n !== null && n !== undefined)
				.map(Number)
				.sort((a, b) => a - b)
			if (!nos.length) { return null }
			const missing = []
			const duplicates = []
			for (let i = 1; i < nos.length; i++) {
				if (nos[i] === nos[i - 1]) { duplicates.push(nos[i]); continue }
				for (let n = nos[i - 1] + 1; n < nos[i] && missing.length <= 20; n++) { missing.push(n) }
			}
			if (!missing.length && !duplicates.length) { return null }
			return { missing, duplicates: [...new Set(duplicates)] }
		},

		// Verknüpft Journal-Zeilen mit ihrer bankstämmigen Buchung, damit in
		// "Alle Buchungen" direkt eine Zuordnungsregel angelegt werden kann.
		txByJournalId() {
			const map = {}
			for (const t of this.transactions) {
				if (t.journalId && t.status === 'assigned' && t.counterparty && t.contraAccountId) {
					map[t.journalId] = t
				}
			}
			return map
		},

		unassignedTransactions() { return this.transactions.filter((t) => t.status === 'unassigned') },
		transactionSearchIndex() { return buildSearchIndex(this.unassignedTransactions, transactionSearch.fields, transactionSearch.cents) },

		currentTransactions() {
			let txs = this.applySort(this.unassignedTransactions, this.sort.transactions)
			const q = this.searchQuery
			if (q) {
				const index = this.transactionSearchIndex
				txs = txs.filter((t) => matchesQuery(index.get(t.id), q))
			}
			return txs
		},

		assignProgress() {
			const total = this.transactions.length
			const done = this.transactions.filter((t) => t.status === 'assigned').length
			return { total, done, pct: total ? Math.round((done / total) * 100) : 0 }
		},

		openItemAccountOption: {
			get() { return this.accountOptionFor(this.openItemForm.accountId) },
			set(v) { this.openItemForm.accountId = v ? v.id : null },
		},

		// Chips mit Zähler: „Überfällig“ (eine Teilmenge von „Offen“) und „Erlassen“
		// (nur Forderungen) erscheinen erst, wenn es sie gibt. Der Reiter-Badge zählt
		// genau die überfälligen; so lässt sich seine Zahl in der Liste nachvollziehen.
		openItemFilterOptions() {
			const count = (status) => this.openItems.filter((o) => o.status === status).length
			const overdue = this.openItems.filter((o) => o.status === 'open' && o.overdue).length
			return [
				{ key: 'open', label: this.t('Offen'), count: count('open') },
				...(overdue > 0 ? [{ key: 'overdue', label: this.t('Überfällig'), count: overdue }] : []),
				{ key: 'paid', label: this.t('Bezahlt'), count: count('paid') },
				...(count('waived') > 0 ? [{ key: 'waived', label: this.t('Erlassen'), count: count('waived') }] : []),
				{ key: 'cancelled', label: this.t('Storniert'), count: count('cancelled') },
				{ key: 'all', label: this.t('Alle'), count: this.openItems.length },
			]
		},

		// Ein Filter, dessen Chip verschwunden ist (letzter überfälliger Posten
		// bezahlt), fällt auf „Offen“ zurück statt auf eine leere Liste.
		activeOpenItemFilter() {
			return this.openItemFilterOptions.some((f) => f.key === this.openItemFilter) ? this.openItemFilter : 'open'
		},

		filteredOpenItems() {
			const key = this.activeOpenItemFilter
			if (key === 'all') { return this.openItems }
			if (key === 'overdue') { return this.openItems.filter((o) => o.status === 'open' && o.overdue) }
			return this.openItems.filter((o) => o.status === key)
		},

		hasClaimRows() { return this.filteredOpenItems.some((o) => this.isClaim(o)) },
	},

	watch: {
		// Suchfeld beim Wechsel zwischen "Alle Buchungen"/"Zuzuordnen" leeren
		// (Original-Verhalten aus App.vue's bookingView-Watcher).
		bookingView(v) {
			clearTimeout(this.searchTimer)
			if (this.$refs.searchField) { this.$refs.searchField.value = '' }
			this.bookingSearch = ''
			this.clearJournalSelection()
			if (v === 'openitems') { this.loadOpenItems() }
		},

		// Eine Auswahl gilt für die Buchungen, die gerade auf dem Schirm sind:
		// anderer Zeitraum oder Wächter-Ordner aus, dann von vorn.
		selectedPeriodId() { this.clearJournalSelection() },
		bulkSelectable(v) { if (!v) { this.clearJournalSelection() } },
	},

	mounted() {
		if (this.bookingView === 'openitems') { this.loadOpenItems() }
		this.applyJournalVisibility()
	},

	// Nach jedem Rendern: neue oder umsortierte Zeilen bekommen ihre
	// Sichtbarkeit. Jede Filteränderung rendert (Zähler), läuft also hier durch.
	updated() {
		this.applyJournalVisibility()
	},

	beforeUnmount() {
		clearTimeout(this.searchTimer)
	},

	methods: {
		formatMoney,
		formatDate,
		amountClass,
		flowClass,
		flowLabel,
		formatFlowMoney,
		transactionFlow,
		/**
		 * Zugeordnet, aber ohne einzelnes Gegenkonto = der Umsatz wurde auf
		 * mehrere verteilt. contra_account_id bleibt dann leer, siehe
		 * BookingService::doAssign().
		 */
		isSplitAssigned(tx) {
			return tx.status === 'assigned' && !tx.contraAccountId
		},

		// Entprellt: gefiltert wird erst, wenn die Eingabe kurz ruht. Leeren
		// wirkt sofort.
		onSearchInput(event) {
			const v = event.target.value
			clearTimeout(this.searchTimer)
			if (!v.trim()) {
				this.bookingSearch = ''
				return
			}
			this.searchTimer = setTimeout(() => { this.bookingSearch = v }, 150)
		},

		/**
		 * Blendet die Zeilen der Journal-Tabelle direkt am DOM ein und aus.
		 * Über die Vorlage (v-show) müsste Vue jede Zeile, deren Sichtbarkeit
		 * wechselt, samt Knöpfen und Aktionsmenü patchen – bei einigen hundert
		 * Zeilen spürbar. Das hidden-Attribut kennt Vue nicht und lässt es stehen.
		 */
		applyJournalVisibility() {
			const body = this.$refs.journalBody
			if (!body) { return }
			const visible = this.visibleJournalIds
			for (const tr of body.children) {
				const hide = !visible.has(Number(tr.dataset.id))
				if (tr.hidden !== hide) { tr.hidden = hide }
			}
		},

		canAttachToTx(tx) {
			return this.watchMode && this.canWrite && !this.isDateClosed(tx.bookingDate)
		},

		/**
		 * Klick auf das Kästchen einer Zeile. Das Kästchen hat beim click schon
		 * umgeschaltet; mit Umschalttaste bekommen alle sichtbaren Zeilen
		 * zwischen dem letzten Klick und dieser denselben Zustand.
		 *
		 * @param {number} id Buchung
		 * @param {MouseEvent} event der Klick
		 */
		onSelectClick(id, event) {
			const ids = event.shiftKey ? rangeIds(this.selectableVisibleRows, this.lastSelectedId, id) : [id]
			this.setSelected(ids, event.target.checked)
			this.lastSelectedId = id
		},

		selectAllVisible(checked) {
			this.setSelected(this.selectableVisibleRows.map((r) => r.id), checked)
		},

		setSelected(ids, checked) {
			const next = new Set(this.selectedJournalIds)
			for (const id of ids) {
				if (checked) { next.add(id) } else { next.delete(id) }
			}
			this.selectedJournalIds = next
		},

		clearJournalSelection() {
			this.selectedJournalIds = new Set()
			this.lastSelectedId = null
		},

		async attachReceiptToSelected() {
			this.bulkBusy = true
			try {
				if (await this.openFolderPickerForBookings(this.selectedVisibleIds)) { this.clearJournalSelection() }
			} finally { this.bulkBusy = false }
		},

		pendingFor(tx) {
			return this.txPendingFiles[tx.id] || []
		},

		inboxViewUrl(fileId) {
			return api.attachmentInboxViewUrl(fileId)
		},

		openItemStatusLabel,
		claimTypeLabel,

		/** Zugänglicher Name des Sprung-Knopfs. Der Debitor steht nicht in einer t()-Variable (HTML-Escaping). */
		claimLinkLabel(o) {
			return `${this.canWrite ? this.t('Im Einzug bearbeiten') : this.t('Im Einzug ansehen')}: ${o.debtor}`
		},

		async createOpenItem() {
			if (!this.openItemForm.debtor || !this.openItemForm.amount) { return }
			try {
				await api.createOpenItem({
					debtor: this.openItemForm.debtor,
					description: this.openItemForm.description || null,
					amount: Number(this.openItemForm.amount),
					dueDate: this.openItemForm.dueDate || null,
					accountId: this.openItemForm.accountId,
				})
				this.openItemForm = { debtor: '', description: '', amount: '', dueDate: '', accountId: null }
				await this.loadOpenItems()
				showSuccess(this.t('Offener Posten angelegt.'))
			} catch (e) { showError(errMsg(e, this.t('Offener Posten konnte nicht angelegt werden'))) }
		},

		async markOpenItemPaid(o) {
			try { await api.markOpenItemPaid(o.id); await this.loadOpenItems(); showSuccess(this.t('Als bezahlt markiert.')) } catch (e) { showError(errMsg(e, this.t('Konnte nicht als bezahlt markiert werden'))) }
		},

		async cancelOpenItem(o) {
			try { await api.cancelOpenItem(o.id); await this.loadOpenItems(); showSuccess(this.t('Storniert.')) } catch (e) { showError(errMsg(e, this.t('Konnte nicht storniert werden'))) }
		},

		async reopenOpenItem(o) {
			try { await api.reopenOpenItem(o.id); await this.loadOpenItems(); showSuccess(this.t('Wieder geöffnet.')) } catch (e) { showError(errMsg(e, this.t('Konnte nicht wieder geöffnet werden'))) }
		},

		accountLabel(id) {
			const acc = this.accountsById[id]
			return acc ? `${acc.number} ${acc.name}` : `#${id}`
		},

		accountOptionFor(id) {
			return id ? (this.accountOptionsList.find((o) => o.id === id) ?? null) : null
		},

		isSelectableOption,

		accountFilterBy(option, label, search) {
			const s = String(search || '').trim().toLowerCase()
			if (!s) { return true }
			if (option && option.$isDisabled) { return false }
			if (/^[\d\s]+$/.test(s)) {
				const digits = s.replace(/\s+/g, '')
				const num = String((option && option.number) || '').replace(/\s+/g, '').toLowerCase()
				return num.startsWith(digits)
			}
			return String(label || '').toLowerCase().includes(s)
		},

		/** Geldrichtung der Journalzeile für Vorzeichen, Farbe und Tooltip, siehe lib/flow.js. Neutral ('') in der Standarddarstellung. */
		rowFlow(r) {
			return this.amountDisplay === 'signed' ? bookingFlow(r, this.accountsById) : ''
		},

		/**
		 * Richtung für die mobile Buchungskarte. Die Karten zeigten Vorzeichen und
		 * Farbe schon vor der Einstellung und bleiben davon unberührt: in der
		 * Standarddarstellung wie bisher (Splittbuchungen neutral), mit
		 * 'signed' mit der vollen Richtung aus lib/flow.js.
		 */
		cardFlow(r) {
			return this.amountDisplay === 'signed' || !r.isSplit ? bookingFlow(r, this.accountsById) : ''
		},

		/** Richtung eines Bankumsatzes; neutral ('') in der Standarddarstellung. */
		txFlow(tx) {
			return this.amountDisplay === 'signed' ? transactionFlow(tx) : ''
		},

		/**
		 * Farbklasse am Betrag eines Bankumsatzes. In der Standarddarstellung
		 * bleibt es beim Stand vor der Einstellung: der Abgang rot, am Handy
		 * zusätzlich der Eingang grün; das Vorzeichen liefert die Bank selbst.
		 */
		txAmountClass(tx, mobile = false) {
			if (this.amountDisplay === 'signed') { return flowClass(transactionFlow(tx)) }
			if (mobile) { return tx.amount < 0 ? 'neg' : 'pos' }
			return amountClass(tx.amount)
		},
	},
}
</script>
