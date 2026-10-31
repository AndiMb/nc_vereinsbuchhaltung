<template>
	<NcModal
		v-if="show"
		:show="show"
		:name="isMobile ? bookingTitle : ''"
		:labelId="isMobile ? undefined : 'vbh-modal-title-booking'"
		:size="modalSize"
		:closeOnClickOutside="!bookingSaving"
		@close="requestClose"
		@update:show="requestShow">
		<!-- v-if: frische Fokusfalle bei jedem Oeffnen, sonst kommt sie beim
		     ersten Mal nicht nach oben (siehe AttachmentFolderDialog.vue). -->
		<div ref="inner" class="vbh-modal-inner vbh-booking-dialog" :class="{ 'is-split': folderPanelOpen && !isMobile, 'is-split3': isSplit3 }">
			<!-- Mit geoeffneter Belegauswahl zwei Spalten: links das Formular,
			     rechts der Wächter-Ordner - so bleibt beim Aussuchen sichtbar,
			     wofuer der Beleg ist. Mobil reicht der Platz dafuer nicht, dort
			     ersetzt die Auswahl das Formular, bis sie geschlossen wird. -->
			<div class="vbh-booking-layout">
				<div v-show="!(isMobile && folderPanelOpen)" ref="main" class="vbh-booking-main">
					<h2 v-if="!isMobile" id="vbh-modal-title-booking" class="vbh-modal-title">
						{{ bookingTitle }}
					</h2>
					<!-- Ohne bekannten Zeitraum kein Banner: dann liesse sich nicht sagen,
					     welches Geschäftsjahr gemeint ist. -->
					<p v-if="bookingLocked && bookingPeriodLabel" class="vbh-hint vbh-hint--info">
						<!-- Mit Schreibrecht bleibt der Kommentar aenderbar (kein Teil des Buchungssatzes). -->
						{{ canWrite
							? tRaw('🔒 Das Geschäftsjahr {period} ist abgeschlossen – an dieser Buchung lässt sich nur noch der Kommentar ändern.', { period: bookingPeriodLabel })
							: tRaw('🔒 Das Geschäftsjahr {period} ist abgeschlossen – diese Buchung kann nur noch angesehen werden.', { period: bookingPeriodLabel }) }}
					</p>
					<div
						v-if="bookingMode === 'simple'"
						class="vbh-kindtoggle"
						:class="{ 'vbh-tour-target': bookingTour.active && bookingTour.step === 0 }"
						role="radiogroup"
						:aria-label="t('Buchungsart')">
						<button
							type="button"
							class="vbh-kindbtn income"
							:class="{ active: bookingForm.kind === 'income' }"
							:disabled="bookingLocked"
							@click="setBookingKind('income')">
							{{ t('Einnahme') }}
						</button>
						<button
							type="button"
							class="vbh-kindbtn expense"
							:class="{ active: bookingForm.kind === 'expense' }"
							:disabled="bookingLocked"
							@click="setBookingKind('expense')">
							{{ t('Ausgabe') }}
						</button>
					</div>
					<div v-if="bookingTour.active && bookingTour.step === 0" class="vbh-tour-tip">
						<span>{{ t('Wählen Sie zuerst, ob Geld reinkommt oder rausgeht – Schritt 1 von 3.') }}</span>
						<div class="vbh-tour-actions">
							<button type="button" class="vbh-tour-skip" @click="endTour">
								{{ t('Überspringen') }}
							</button>
							<NcButton variant="primary" @click="nextTourStep">
								{{ t('Weiter') }}
							</NcButton>
						</div>
					</div>

					<!-- Mobil: Betrag zuerst und groß, Kontenwahl über Auswahl-Sheets -->
					<template v-if="isMobile">
						<div class="vbh-bigamount">
							<AmountInput
								v-model="formAmount"
								hideCurrency
								placeholder="0,00"
								class="vbh-bigamount-input"
								:aria-label="t('Betrag in Euro')"
								:disabled="bookingLocked" />
							<span class="vbh-bigamount-cur">€</span>
						</div>
						<div class="vbh-mfields">
							<template v-if="bookingMode === 'simple'">
								<button
									v-if="!formSplitMode"
									type="button"
									class="vbh-fieldbtn"
									:disabled="bookingLocked"
									@click="openAccountPicker('category')">
									<span class="vbh-fieldbtn-text">
										<span class="vbh-fieldbtn-lab">{{ bookingForm.kind === 'income' ? t('Wofür? (Einnahme-Kategorie)') : t('Wofür? (Ausgabe-Kategorie)') }}</span>
										<span class="vbh-fieldbtn-val" :class="{ placeholder: !bookingForm.categoryId }">{{ bookingForm.categoryId ? accountLabel(bookingForm.categoryId) : t('Kategorie wählen…') }}</span>
									</span>
									<span class="vbh-fieldbtn-chev" aria-hidden="true">›</span>
								</button>
								<button
									type="button"
									class="vbh-fieldbtn"
									:disabled="bookingLocked"
									@click="openAccountPicker('money')">
									<span class="vbh-fieldbtn-text">
										<span class="vbh-fieldbtn-lab">{{ t('Geldkonto (Bank/Kasse)') }}</span>
										<span class="vbh-fieldbtn-val" :class="{ placeholder: !bookingForm.moneyAccountId }">{{ bookingForm.moneyAccountId ? accountLabel(bookingForm.moneyAccountId) : t('wählen…') }}</span>
									</span>
									<span class="vbh-fieldbtn-chev" aria-hidden="true">›</span>
								</button>
							</template>
							<template v-else>
								<button
									v-if="!formSplitMode || splitSide === 'credit'"
									type="button"
									class="vbh-fieldbtn"
									:disabled="bookingLocked"
									@click="openAccountPicker('debit')">
									<span class="vbh-fieldbtn-text">
										<span class="vbh-fieldbtn-lab">{{ t('Soll (Aufwand/Aktiv)') }}</span>
										<span class="vbh-fieldbtn-val" :class="{ placeholder: !bookingForm.debitAccountId }">{{ bookingForm.debitAccountId ? accountLabel(bookingForm.debitAccountId) : t('wählen…') }}</span>
									</span>
									<span class="vbh-fieldbtn-chev" aria-hidden="true">›</span>
								</button>
								<button
									v-if="!formSplitMode || splitSide === 'debit'"
									type="button"
									class="vbh-fieldbtn"
									:disabled="bookingLocked"
									@click="openAccountPicker('credit')">
									<span class="vbh-fieldbtn-text">
										<span class="vbh-fieldbtn-lab">{{ t('Haben (Ertrag/Passiv)') }}</span>
										<span class="vbh-fieldbtn-val" :class="{ placeholder: !bookingForm.creditAccountId }">{{ bookingForm.creditAccountId ? accountLabel(bookingForm.creditAccountId) : t('wählen…') }}</span>
									</span>
									<span class="vbh-fieldbtn-chev" aria-hidden="true">›</span>
								</button>
								<label v-if="formSplitMode" class="vbh-mfield">{{ t('Aufteilen auf') }}
									<select v-model="formSplitSide" :disabled="bookingLocked">
										<option value="credit">{{ t('die Habenseite') }}</option>
										<option value="debit">{{ t('die Sollseite') }}</option>
									</select>
								</label>
							</template>
							<label class="vbh-mfield">{{ t('Datum') }}<input v-model="formDate" type="date" :disabled="bookingLocked"></label>
							<label class="vbh-mfield">{{ t('Buchungstext') }}<textarea
								v-model="formDescription"
								v-autogrow
								class="vbh-autogrow"
								rows="1"
								:placeholder="t('z. B. Mitgliedsbeitrag Max Mustermann')"
								:disabled="bookingLocked"
								@keydown.enter.prevent /></label>
							<label class="vbh-mfield">{{ t('Beleg-Nr.') }}<input v-model="formDocumentRef" :placeholder="t('optional')" :disabled="bookingLocked"></label>
							<!-- Beleg schon beim Anlegen: Dateien werden lokal gesammelt und nach
							     dem Speichern an die neue Buchung gehängt. Mobil stehen die Knöpfe
							     bewusst hier oben im Formular (Kamera direkt griffbereit) statt in
							     der Belegablage weiter unten wie am Desktop. -->
							<div v-if="canWrite && !bookingForm.id" class="vbh-mfield">
								<span>{{ t('Beleg') }}</span>
								<div class="vbh-pendingbtns">
									<label class="vbh-upload-label">
										<input
											type="file"
											accept="image/*"
											capture="environment"
											class="vbh-upload-input"
											@change="addPendingFiles">
										<span class="vbh-upload-btn"><NcIconSvgWrapper :path="mdiCamera" :size="16" inline /> {{ t('Fotografieren') }}</span>
									</label>
									<label class="vbh-upload-label">
										<input
											type="file"
											accept="image/jpeg,image/png,image/gif,image/webp,application/pdf"
											multiple
											class="vbh-upload-input"
											@change="addPendingFiles">
										<span class="vbh-upload-btn"><NcIconSvgWrapper :path="mdiPaperclip" :size="16" inline /> {{ t('Datei…') }}</span>
									</label>
									<button
										v-if="watchMode"
										type="button"
										class="vbh-upload-trigger"
										:aria-expanded="folderPanelOpen ? 'true' : 'false'"
										@click="toggleFolderPanel">
										<span class="vbh-upload-btn"><NcIconSvgWrapper :path="mdiFolderSearchOutline" :size="16" inline /> {{ t('Aus Ordner…') }}</span>
									</button>
								</div>
								<ul v-if="pendingFiles.length" class="vbh-attachment-list">
									<li v-for="(pf, i) in pendingFiles" :key="i" class="vbh-attachment-item">
										<NcIconSvgWrapper :path="pf.kind === 'link' ? mdiFolderOutline : mdiPaperclip" :size="14" class="vbh-attachment-icon" />
										<span class="vbh-attachment-name">{{ pf.name }}</span>
										<span class="vbh-attachment-size">{{ formatFileSize(pf.size) }}</span>
										<NcButton variant="tertiary" :aria-label="t('Beleg entfernen')" @click="removePendingFile(i)">
											<template #icon>
												<NcIconSvgWrapper :path="mdiDelete" :size="14" />
											</template>
										</NcButton>
									</li>
								</ul>
							</div>
						</div>
					</template>

					<!-- Desktop: bisheriges Formular-Layout -->
					<template v-else>
						<div class="vbh-form">
							<label>{{ t('Datum') }}<input v-model="formDate" type="date" :disabled="bookingLocked"></label>
							<label>{{ t('Beleg-Nr.') }}<input
								v-model="formDocumentRef"
								class="vbh-short"
								:placeholder="t('optional')"
								:disabled="bookingLocked"></label>
							<label>{{ formSplitMode ? t('Gesamtbetrag (€)') : t('Betrag (€)') }}<AmountInput
								v-model="formAmount"
								class="vbh-num"
								:disabled="bookingLocked" /></label>
						</div>
						<template v-if="bookingMode === 'simple'">
							<div class="vbh-form" :class="{ 'vbh-tour-target': bookingTour.active && bookingTour.step === 1 }">
								<label v-if="!formSplitMode" class="vbh-grow">{{ bookingForm.kind === 'income' ? t('Wofür? (Einnahme-Kategorie)') : t('Wofür? (Ausgabe-Kategorie)') }}
									<NcSelect
										v-model="bookingFormCategoryOption"
										:options="simpleCategoryOptions"
										:filterBy="accountFilterBy"
										:selectable="isSelectableOption"
										:disabled="bookingLocked"
										label="label"
										:placeholder="t('– Kategorie wählen –')" />
								</label>
								<label class="vbh-grow">{{ t('Geldkonto (Bank/Kasse)') }}
									<NcSelect
										v-model="bookingFormMoneyOption"
										:options="moneyAccountOptions"
										:filterBy="accountFilterBy"
										:selectable="isSelectableOption"
										:disabled="bookingLocked"
										label="label"
										:placeholder="t('– wählen –')" />
								</label>
							</div>
							<div v-if="bookingTour.active && bookingTour.step === 1" class="vbh-tour-tip">
								<span>{{ t('Wählen Sie die Kategorie (z. B. „Mitgliedsbeiträge") und das Geldkonto – die App bucht Soll/Haben automatisch richtig. Schritt 2 von 3.') }}</span>
								<div class="vbh-tour-actions">
									<button type="button" class="vbh-tour-skip" @click="endTour">
										{{ t('Überspringen') }}
									</button>
									<NcButton variant="primary" @click="nextTourStep">
										{{ t('Weiter') }}
									</NcButton>
								</div>
							</div>
						</template>
						<template v-else>
							<div class="vbh-form">
								<label v-if="!formSplitMode || splitSide === 'credit'" class="vbh-grow">{{ t('Soll (Aufwand/Aktiv)') }}
									<NcSelect
										v-model="bookingFormDebitOption"
										:options="accountOptionsList"
										:filterBy="accountFilterBy"
										:selectable="isSelectableOption"
										:disabled="bookingLocked"
										label="label"
										:placeholder="t('– wählen –')" />
								</label>
								<label v-if="!formSplitMode || splitSide === 'debit'" class="vbh-grow">{{ t('Haben (Ertrag/Passiv)') }}
									<NcSelect
										v-model="bookingFormCreditOption"
										:options="accountOptionsList"
										:filterBy="accountFilterBy"
										:selectable="isSelectableOption"
										:disabled="bookingLocked"
										label="label"
										:placeholder="t('– wählen –')" />
								</label>
								<label v-if="formSplitMode">{{ t('Aufteilen auf') }}
									<select v-model="formSplitSide" :disabled="bookingLocked">
										<option value="credit">{{ t('die Habenseite') }}</option>
										<option value="debit">{{ t('die Sollseite') }}</option>
									</select>
								</label>
							</div>
						</template>
						<div class="vbh-form" :class="{ 'vbh-tour-target': bookingTour.active && bookingTour.step === 2 }">
							<label class="vbh-grow">{{ t('Buchungstext') }}<textarea
								v-model="formDescription"
								v-autogrow
								class="vbh-autogrow"
								rows="1"
								:placeholder="t('z. B. Mitgliedsbeitrag Max Mustermann')"
								:disabled="bookingLocked"
								@keydown.enter.prevent /></label>
						</div>
						<div v-if="bookingTour.active && bookingTour.step === 2" class="vbh-tour-tip">
							<span>{{ t('Ein kurzer Text erklärt später, worum es ging – fertig! Schritt 3 von 3.') }}</span>
							<div class="vbh-tour-actions">
								<NcButton variant="primary" @click="endTour">
									{{ t('Verstanden') }}
								</NcButton>
							</div>
						</div>
					</template>
					<!-- Aufteilung: die feste Seite steht oben, hier folgen die
					     Gegenkonten mit ihren Teilbeträgen (mobil wie am Desktop). -->
					<div v-if="formSplitMode" class="vbh-split">
						<div class="vbh-split-head">
							<span class="vbh-split-title">{{ t('Aufteilung') }}</span>
							<span class="vbh-split-rest" :class="{ ok: splitRestOk, bad: !splitRestOk }">
								{{ splitRestOk ? t('✓ geht auf') : t('Rest: {amount}', { amount: formatMoney(splitRest) }) }}
							</span>
						</div>
						<ul class="vbh-split-list">
							<li v-for="(line, i) in splitLines" :key="i" class="vbh-split-row">
								<button
									v-if="isMobile"
									type="button"
									class="vbh-fieldbtn vbh-split-acc"
									:disabled="bookingLocked"
									@click="openAccountPicker('splitline:' + i)">
									<span class="vbh-fieldbtn-text">
										<span class="vbh-fieldbtn-val" :class="{ placeholder: !line.accountId }">{{ line.accountId ? accountLabel(line.accountId) : t('Konto wählen…') }}</span>
									</span>
									<span class="vbh-fieldbtn-chev" aria-hidden="true">›</span>
								</button>
								<NcSelect
									v-else
									:modelValue="splitLineOption(i)"
									:options="splitAccountOptions"
									:filterBy="accountFilterBy"
									:selectable="isSelectableOption"
									:disabled="bookingLocked"
									class="vbh-split-acc"
									label="label"
									:placeholder="t('– Konto wählen –')"
									@update:modelValue="setSplitLineAccount(i, $event)" />
								<AmountInput
									:modelValue="line.amount"
									:emptyValue="null"
									class="vbh-num vbh-split-amount"
									:aria-label="t('Teilbetrag Zeile {n}', { n: i + 1 })"
									:disabled="bookingLocked"
									@update:modelValue="setSplitLineAmount(i, $event)" />
								<NcButton
									v-if="!bookingLocked"
									variant="tertiary"
									:aria-label="t('Zeile {n} entfernen', { n: i + 1 })"
									@click="removeSplitLine(i)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiDelete" :size="14" />
									</template>
								</NcButton>
							</li>
						</ul>
						<div class="vbh-split-actions">
							<NcButton v-if="!bookingLocked" variant="tertiary" @click="addSplitLine">
								{{ t('+ Zeile hinzufügen') }}
							</NcButton>
							<NcButton
								v-if="!bookingLocked && splitRest > 0.0049"
								variant="tertiary"
								:title="t('Den noch offenen Rest in die letzte Zeile schreiben')"
								@click="fillSplitRest">
								{{ t('Rest übernehmen') }}
							</NcButton>
						</div>
					</div>

					<div class="vbh-expertrow">
						<NcCheckboxRadioSwitch v-model="formSplitMode" :disabled="bookingLocked" type="switch">
							{{ t('Betrag aufteilen (mehrere Gegenkonten)') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="bookingModeExpert" type="switch">
							{{ t('Experten-Modus (Soll/Haben direkt wählen)') }}
						</NcCheckboxRadioSwitch>
					</div>

					<!-- Kommentar: kein Teil des Buchungssatzes und deshalb nicht an
					     bookingLocked gebunden - Hinweise aus der Kassenpruefung entstehen
					     meist erst nach dem Jahresabschluss (gespeichert ueber einen eigenen
					     Endpunkt, siehe App.vue::saveBookingComment). -->
					<div class="vbh-form">
						<label class="vbh-grow">{{ t('Kommentar') }}<textarea
							v-model="formComment"
							v-autogrow
							class="vbh-autogrow"
							rows="2"
							:maxlength="2000"
							:placeholder="t('optional, z. B. Hinweis für die Kassenprüfung')"
							:disabled="!canWrite" /></label>
					</div>

					<!-- Belegablage: bei einer bestehenden Buchung die bereits gespeicherten
					     Belege, beim Anlegen die noch lokal gesammelten Dateien (der Upload
					     folgt direkt nach dem Speichern). Mobil stehen die Sammel-Knoepfe
					     schon oben im Formular, dort entfaellt dieser Zweig. -->
					<div v-if="bookingForm.id || (canWrite && !isMobile)" class="vbh-attachments">
						<div class="vbh-attachments-header">
							<span class="vbh-attachments-title">{{ t('Belege') }}</span>
							<div v-if="canWrite && !bookingLocked" class="vbh-attachments-actions">
								<!-- Der <button> ist nur die Huelle (Fokus, Tastatur), sichtbar
								     ist der <span> darin - genau wie beim "Anhängen"-Label
								     daneben. Nextclouds Grundregeln fuer nackte Buttons
								     (Breite, Mindesthoehe, Innenabstand) treffen so nur die
								     zurueckgesetzte Huelle und verschieben das Icon nicht. -->
								<button
									v-if="watchMode"
									type="button"
									class="vbh-upload-trigger"
									:aria-expanded="folderPanelOpen ? 'true' : 'false'"
									:disabled="bookingSaving"
									@click="toggleFolderPanel">
									<span class="vbh-upload-btn" :class="{ 'is-active': folderPanelOpen }">
										<NcIconSvgWrapper :path="mdiFolderSearchOutline" :size="16" inline />
										{{ t('Aus Ordner wählen') }}
									</span>
								</button>
								<label class="vbh-upload-label" :class="{ 'is-uploading': bookingSaving }">
									<input
										type="file"
										accept="image/jpeg,image/png,image/gif,image/webp,application/pdf"
										multiple
										:disabled="bookingSaving"
										class="vbh-upload-input"
										@change="addPendingFiles">
									<span class="vbh-upload-btn">
										<NcIconSvgWrapper :path="mdiPaperclip" :size="16" inline />
										{{ t('Anhängen') }}
									</span>
								</label>
							</div>
						</div>
						<template v-if="bookingForm.id">
							<ul v-if="bookingAttachments.length" class="vbh-attachment-list">
								<!-- Lösen/Löschen wirkt erst beim Speichern: bis dahin steht der
								     Beleg durchgestrichen da und lässt sich zurückholen. -->
								<li
									v-for="a in bookingAttachments"
									:key="a.id"
									class="vbh-attachment-item"
									:class="{ 'is-removed': isMarkedForRemoval(a) }">
									<NcIconSvgWrapper
										:path="a.missing ? mdiAlertCircleOutline : mdiPaperclip"
										:size="14"
										class="vbh-attachment-icon"
										:class="{ 'vbh-folder-warn': a.missing }" />
									<span v-if="a.missing" class="vbh-attachment-name" :title="t('Die Datei wurde in der Dateien-App gelöscht oder verschoben.')">
										{{ a.fileName }} – {{ t('Datei nicht gefunden') }}
									</span>
									<button
										v-else
										class="vbh-attachment-name"
										:title="tRaw('Anzeigen: {name}', { name: a.fileName })"
										@click="openViewer(a)">
										{{ a.fileName }}
									</button>
									<span v-if="isMarkedForRemoval(a)" class="vbh-attachment-removal">
										{{ a.unlinkOnly ? t('Wird beim Speichern gelöst') : t('Wird beim Speichern gelöscht') }}
									</span>
									<span v-else class="vbh-attachment-size">{{ formatFileSize(a.fileSize) }}</span>
									<a
										v-if="!a.missing && !isMarkedForRemoval(a)"
										:href="attachmentDownloadUrl(a.id)"
										class="vbh-attachment-dl"
										:title="t('Herunterladen')"
										download>↓</a>
									<NcButton
										v-if="canWrite && !bookingLocked && isMarkedForRemoval(a)"
										variant="tertiary"
										:aria-label="tRaw('Rückgängig: {name}', { name: a.fileName })"
										:title="t('Rückgängig')"
										@click="toggleRemoval(a)">
										<template #icon>
											<NcIconSvgWrapper :path="mdiUndo" :size="14" />
										</template>
									</NcButton>
									<NcButton
										v-else-if="canWrite && !bookingLocked"
										variant="tertiary"
										:aria-label="a.unlinkOnly ? t('Verknüpfung lösen') : t('Beleg löschen')"
										:title="a.unlinkOnly ? t('Verknüpfung lösen – die Datei bleibt im Ordner') : t('Beleg löschen')"
										@click="toggleRemoval(a)">
										<template #icon>
											<NcIconSvgWrapper :path="a.unlinkOnly ? mdiLinkVariantOff : mdiDelete" :size="14" />
										</template>
									</NcButton>
								</li>
							</ul>
							<p v-else-if="!pendingFiles.length" class="vbh-attachment-empty">
								{{ t('Noch kein Beleg angehängt.') }}
							</p>
						</template>
						<!-- Wartende Dateien: angehängt oder aus dem Ordner gewählt, aber
						     noch nicht gespeichert - beim Anlegen wie beim Bearbeiten gehen
						     sie erst mit Buchen/Speichern an den Server (App.vue::saveBooking).
						     Was dabei nicht ankommt, bleibt hier stehen; ein weiterer Klick
						     auf Speichern versucht es erneut. -->
						<div v-if="pendingFiles.length" class="vbh-pending">
							<div v-if="bookingForm.id" class="vbh-pending-header">
								<span class="vbh-pending-title">{{ t('Wird beim Speichern angehängt') }}</span>
							</div>
							<ul class="vbh-attachment-list">
								<li v-for="(pf, i) in pendingFiles" :key="i" class="vbh-attachment-item">
									<NcIconSvgWrapper :path="pf.kind === 'link' ? mdiFolderOutline : mdiPaperclip" :size="14" class="vbh-attachment-icon" />
									<span class="vbh-attachment-name">{{ pf.name }}</span>
									<span class="vbh-attachment-size">{{ formatFileSize(pf.size) }}</span>
									<NcButton variant="tertiary" :aria-label="t('Beleg entfernen')" @click="removePendingFile(i)">
										<template #icon>
											<NcIconSvgWrapper :path="mdiDelete" :size="14" />
										</template>
									</NcButton>
								</li>
							</ul>
						</div>
						<p v-else-if="!bookingForm.id" class="vbh-attachment-empty">
							{{ t('Noch kein Beleg gewählt – die Dateien werden nach dem Buchen hochgeladen.') }}
						</p>
					</div>
				</div>

				<aside v-if="folderPanelOpen" class="vbh-booking-folder" aria-labelledby="vbh-booking-folder-title">
					<!-- Kein eigenes X: oben rechts sitzt schon das von NcModal, das
					     den ganzen Dialog schliesst. Zugeklappt wird die Leiste ueber
					     den hervorgehobenen Knopf "Aus Ordner wählen". -->
					<h3 id="vbh-booking-folder-title" class="vbh-booking-folder-title">
						{{ t('Beleg aus Ordner wählen') }}
					</h3>
					<!-- Am Desktop uebernimmt ein Klick die Datei sofort, die
					     Seitenleiste bleibt fuer weitere offen. Mobil, wo die Auswahl
					     das Formular ersetzt, bleibt es bei Mehrfachauswahl mit
					     Abbrechen/Übernehmen als Weg zurueck. -->
					<!-- Schmales Fenster: die Vorschau steht anstelle der Liste. Die
					     Liste bleibt eingehaengt (v-show), Suche, Auswahl und
					     Scrollstand bleiben so erhalten. -->
					<ReceiptPreview
						v-if="previewInFolderColumn"
						ref="fallbackPreview"
						:file="previewFile"
						:picked="previewPicked"
						showNav
						@back="backToList"
						@step="$refs.folderPanel.step($event)" />
					<AttachmentFolderPanel
						v-show="!previewInFolderColumn"
						ref="folderPanel"
						pick
						:instantPick="!isMobile"
						:busy="bookingSaving"
						:canWrite="canWrite"
						:excludeFileIds="folderExcludeIds"
						:noAutofocus="isMobile"
						:previewEnabled="!isMobile"
						:previewFileId="previewFile ? previewFile.fileId : null"
						@close="folderPanelOpen = false"
						@pick="onFolderPick"
						@preview="onPreview" />
				</aside>

				<!-- Breites Fenster: dritte Spalte, Formular und Liste bleiben
				     daneben sichtbar - Rechnung und Buchung lassen sich vergleichen. -->
				<aside v-if="isSplit3" class="vbh-booking-preview" :aria-label="t('Belegvorschau')">
					<ReceiptPreview :file="previewFile" :picked="previewPicked" />
				</aside>
			</div>

			<!-- Bleibt am unteren Rand stehen, auch wenn das Formular laenger ist
			     als der Dialog; der Schatten zeigt, dass darunter noch Inhalt
			     liegt (lib/stickyShadow.js). Mobil bei offener Belegauswahl
			     ausgeblendet, die hat dann ihre eigenen Knoepfe. -->
			<div v-show="!(isMobile && folderPanelOpen)" v-stickyShadow class="vbh-modal-actions vbh-modal-actions--sticky">
				<NcButton v-if="isMobile && bookingForm.id && canWrite && !bookingLocked" variant="error" @click="$emit('delete')">
					{{ t('Löschen') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="bookingSaving" @click="requestClose">
					{{ bookingLocked ? t('Schließen') : t('Abbrechen') }}
				</NcButton>
				<!-- Waehrend des Speicherns gesperrt: daran haengt der Upload der
				     gesammelten Belege, und ein zweiter Klick wuerde in dieser Zeit
				     eine zweite Buchung anlegen (bookingForm.id ist noch leer). -->
				<!-- Im abgeschlossenen Zeitraum bleibt nur der Kommentar aenderbar;
				     der Knopf erscheint dort erst, wenn er geaendert wurde. -->
				<NcButton
					v-if="!bookingLocked || (canWrite && commentChanged)"
					variant="primary"
					:disabled="bookingSaving"
					@click="$emit('save')">
					<template v-if="bookingSaving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ bookingSaving ? t('Wird gespeichert…') : (bookingLocked ? t('Kommentar speichern') : (bookingForm.id ? t('Speichern') : t('Buchen'))) }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { mdiAlertCircleOutline, mdiCamera, mdiDelete, mdiFolderOutline, mdiFolderSearchOutline, mdiLinkVariantOff, mdiPaperclip, mdiUndo } from '@mdi/js'
import { toRefs } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import AmountInput from './AmountInput.vue'
import AttachmentFolderPanel from './AttachmentFolderPanel.vue'
import ReceiptPreview from './ReceiptPreview.vue'
import { buildAccountOptions, useAccounts } from '../composables/useAccounts.js'
import { useConfirm } from '../composables/useConfirm.js'
import { useJournal } from '../composables/useJournal.js'
import { usePeriods } from '../composables/usePeriods.js'
import { useWideLayout } from '../composables/useWideLayout.js'
import { autogrow } from '../lib/autogrow.js'
import { formatFileSize, formatMoney } from '../lib/format.js'
import { isSelectableOption } from '../lib/selectOptions.js'
import { splitBalanced, splitRemainder, splitSideOf } from '../lib/split.js'
import { stickyShadow } from '../lib/stickyShadow.js'

export default {
	name: 'BookingDialog',
	components: { NcModal, NcButton, NcSelect, NcCheckboxRadioSwitch, NcIconSvgWrapper, NcLoadingIcon, AmountInput, AttachmentFolderPanel, ReceiptPreview },
	directives: { autogrow, stickyShadow },
	props: {
		show: { type: Boolean, default: false },
		// bookingForm/bookingMode/pendingFiles bleiben in App.vue und werden per
		// Referenz durchgereicht (nicht .sync/lokal geklont wie bei AccountDialog):
		// die mobile Konto-Auswahl (AccountPickerSheet, App.vue-Ebene, auch von der
		// "Zuordnen"-Ansicht geteilt) schreibt waehrend der Dialog offen ist direkt
		// in bookingForm - eine lokale Kopie wuerde dabei veralten.
		bookingForm: { type: Object, required: true },
		bookingMode: { type: String, required: true },
		bookingLocked: { type: Boolean, required: true },
		bookingSaving: { type: Boolean, required: true },
		bookingTour: { type: Object, required: true },
		isMobile: { type: Boolean, required: true },
		canWrite: { type: Boolean, required: true },
		pendingFiles: { type: Array, required: true },
		bookingAttachments: { type: Array, required: true },
		// Belegablage im Wächter-Ordner: dann gibt es "Aus Ordner wählen".
		watchMode: { type: Boolean, default: false },
		// App.vue-Funktionen (Muster wie askConfirm): bleiben dort, da teils mit
		// anderen Bereichen geteilt (openAccountPicker auch von der "Zuordnen"-
		// Ansicht genutzt) oder mit App-weiten Nebeneffekten (Beleg-Zaehler etc.).
		setBookingKind: { type: Function, required: true },
		setBookingMode: { type: Function, required: true },
		openAccountPicker: { type: Function, required: true },
		addPendingFiles: { type: Function, required: true },
		// Uebernimmt die in der Seitenleiste gewaehlten Ordner-Dateien in die
		// Warteliste; verknuepft wird erst beim Speichern.
		pickFolderFiles: { type: Function, required: true },
		openViewer: { type: Function, required: true },
		attachmentDownloadUrl: { type: Function, required: true },
		nextTourStep: { type: Function, required: true },
		endTour: { type: Function, required: true },
	},

	emits: ['close', 'delete', 'save', 'update:bookingForm', 'update:pendingFiles', 'update:show'],

	setup() {
		// accountsSorted/accountsById fuer Konto-Labels/-Auswahl, journalData fuer
		// die Haeufigkeits-Sortierung, periodForDate fuer den Hinweis auf ein
		// festgeschriebenes Geschaeftsjahr - direkt aus den Singletons (gleicher
		// geteilter Zustand wie in App.vue, keine Prop-Weitergabe noetig).
		const accounts = useAccounts()
		const journal = useJournal()
		const periods = usePeriods()
		return {
			accountsSorted: accounts.accountsSorted,
			accountsById: accounts.accountsById,
			...toRefs(journal.state),
			periodForDate: periods.periodForDate,
			askConfirm: useConfirm().askConfirm,
			// Belegvorschau: drei Spalten nur bei breitem Fenster.
			wide: useWideLayout().wide,
		}
	},

	data() {
		return {
			mdiAlertCircleOutline,
			mdiCamera,
			mdiDelete,
			mdiFolderOutline,
			mdiFolderSearchOutline,
			mdiLinkVariantOff,
			mdiPaperclip,
			mdiUndo,
			folderPanelOpen: false,
			// Belegvorschau (#231): die angeklickte Datei aus dem Wächter-Ordner,
			// ob sie inzwischen übernommen ist, und ob sie im schmalen Fenster
			// gerade anstelle der Liste steht.
			previewFile: null,
			previewPicked: false,
			previewOverList: false,
			// Formularstand beim Oeffnen, Vergleichsbasis fuer isDirty.
			snapshot: null,
			// Laeuft gerade die Rueckfrage "Verwerfen?" - NcModal meldet das
			// Schliessen doppelt (close und update:show).
			confirmingClose: false,
		}
	},

	computed: {
		modalSize() {
			if (this.isMobile) { return 'full' }
			return this.folderPanelOpen ? 'large' : 'normal'
		},

		/** Formular | Liste | Vorschau nebeneinander (breites Fenster, Spec §3.5). */
		isSplit3() {
			return this.folderPanelOpen && !this.isMobile && this.wide && this.previewFile !== null
		},

		/** Schmales Fenster: Vorschau anstelle der Liste. */
		previewInFolderColumn() {
			return this.folderPanelOpen && !this.isMobile && !this.wide && this.previewFile !== null && this.previewOverList
		},

		/** Ungespeicherte Aenderungen seit dem Oeffnen? */
		isDirty() {
			return this.snapshot !== null && this.formState() !== this.snapshot
		},

		// Was schon an der Buchung hängt oder wartet, soll die Belegauswahl
		// nicht noch einmal anbieten.
		folderExcludeIds() {
			return [
				...this.bookingAttachments.map((a) => a.fileId),
				...this.pendingFiles.map((f) => f.fileId),
			].filter(Boolean)
		},

		commentChanged() {
			return (this.bookingForm.comment || '').trim() !== (this.bookingForm.originalComment || '').trim()
		},

		bookingTitle() {
			return this.bookingForm.id
				? this.t('Buchung bearbeiten #{n}', { n: this.bookingForm.entryNo })
				: this.t('Neue Buchung')
		},

		/**
		 * Bezeichnung des Geschäftsjahres, in das das Buchungsdatum fällt - leer,
		 * wenn es dafür keinen Zeitraum gibt (z. B. ein Datum vor dem ersten
		 * angelegten Geschäftsjahr).
		 */
		bookingPeriodLabel() {
			return this.periodForDate(this.bookingForm.date)?.label ?? ''
		},

		// --- Formularfelder -------------------------------------------------
		// Das Formular gehoert dem Elternteil (App.vue braucht es auch beim
		// Speichern und beim Anhaengen der Belege). Frueher hat diese Komponente
		// direkt in das uebergebene Objekt geschrieben - in Vue 2 funktioniert
		// das, weil Objekt-Props Referenzen sind, koppelt Kind und Elternteil
		// aber fest aneinander und faellt bei einer Vue-3-Migration auf die
		// Fuesse. Jetzt meldet jedes Feld seine Aenderung per Event zurueck
		// (:booking-form.sync in App.vue).
		formAmount: {
			get() { return this.bookingForm.amount },
			set(v) { this.updateForm({ amount: v }) },
		},

		formDate: {
			get() { return this.bookingForm.date },
			set(v) { this.updateForm({ date: v }) },
		},

		formDescription: {
			get() { return this.bookingForm.description },
			// Das Feld ist ein <textarea>, damit langer Text umbricht statt
			// abgeschnitten zu werden - der Wert bleibt aber einzeilig wie
			// bisher: Zeilenumbrueche aus eingefuegtem Text werden zu
			// Leerzeichen, die Eingabetaste ist im Markup abgefangen. So
			// aendert sich nichts an Journalanzeige, CSV/PDF-Export und API.
			set(v) { this.updateForm({ description: v.replace(/\s*[\r\n]+\s*/g, ' ') }) },
		},

		formDocumentRef: {
			get() { return this.bookingForm.documentRef },
			set(v) { this.updateForm({ documentRef: v }) },
		},

		formComment: {
			get() { return this.bookingForm.comment },
			set(v) { this.updateForm({ comment: v }) },
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

		accountOptionsList() {
			return buildAccountOptions(this.accountsSorted, this.accountUsageCounts, this.t)
		},

		simpleCategoryOptions() {
			const type = this.bookingForm.kind === 'income' ? 'income' : 'expense'
			const counts = this.accountUsageCounts
			return this.accountsSorted
				.filter((a) => a.active && a.type === type)
				.sort((a, b) => (counts[b.id] || 0) - (counts[a.id] || 0)
					|| String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
				.map((a) => ({ id: a.id, label: `${a.number} ${a.name}`, number: a.number }))
		},

		moneyAccountOptions() {
			return this.accountsSorted
				.filter((a) => a.active && (a.isBank || a.type === 'asset'))
				.sort((a, b) => (b.isBank ? 1 : 0) - (a.isBank ? 1 : 0)
					|| String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
				.map((a) => ({ id: a.id, label: `${a.number} ${a.name}`, number: a.number }))
		},

		bookingFormCategoryOption: {
			get() {
				if (this.bookingForm.categoryId === null || this.bookingForm.categoryId === undefined) { return null }
				return this.simpleCategoryOptions.find((o) => o.id === this.bookingForm.categoryId) ?? null
			},

			set(v) { this.updateForm({ categoryId: v ? v.id : null }) },
		},

		bookingFormMoneyOption: {
			get() {
				if (this.bookingForm.moneyAccountId === null || this.bookingForm.moneyAccountId === undefined) { return null }
				return this.moneyAccountOptions.find((o) => o.id === this.bookingForm.moneyAccountId) ?? null
			},

			set(v) { this.updateForm({ moneyAccountId: v ? v.id : null }) },
		},

		bookingFormDebitOption: {
			get() {
				if (this.bookingForm.debitAccountId === null || this.bookingForm.debitAccountId === undefined) { return null }
				return this.accountOptionsList.find((o) => o.id === this.bookingForm.debitAccountId) ?? null
			},

			set(v) { this.updateForm({ debitAccountId: v ? v.id : null }) },
		},

		bookingFormCreditOption: {
			get() {
				if (this.bookingForm.creditAccountId === null || this.bookingForm.creditAccountId === undefined) { return null }
				return this.accountOptionsList.find((o) => o.id === this.bookingForm.creditAccountId) ?? null
			},

			set(v) { this.updateForm({ creditAccountId: v ? v.id : null }) },
		},

		bookingModeExpert: {
			get() { return this.bookingMode === 'expert' },
			set(v) { this.setBookingMode(v ? 'expert' : 'simple') },
		},

		// --- Splittbuchung ---------------------------------------------------
		formSplitMode: {
			get() { return !!this.bookingForm.splitMode },
			set(v) {
				if (!v) { this.updateForm({ splitMode: false }); return }
				// Beim Einschalten das bereits gewaehlte Gegenkonto als erste
				// Zeile uebernehmen - sonst faengt man bei Null an, obwohl oben
				// schon etwas steht. Der Betrag kommt nur mit, wenn es auch ein
				// Konto dazu gibt; sonst stuende eine Zahl ohne Zuordnung da.
				const f = this.bookingForm
				const first = this.bookingMode === 'simple'
					? f.categoryId
					: (this.splitSide === 'credit' ? f.creditAccountId : f.debitAccountId)
				const lines = this.splitLines.length
					? this.splitLines
					: [
							{ accountId: first || null, amount: first ? (f.amount || null) : null },
							{ accountId: null, amount: null },
						]
				this.updateForm({ splitMode: true, splitLines: lines })
			},
		},

		formSplitSide: {
			get() { return this.bookingForm.splitSide === 'debit' ? 'debit' : 'credit' },
			set(v) { this.updateForm({ splitSide: v }) },
		},

		/** Die tatsaechlich aufgeteilte Seite (im Einfach-Modus aus der Buchungsart). */
		splitSide() {
			return splitSideOf(this.bookingForm, this.bookingMode)
		},

		splitLines() {
			return this.bookingForm.splitLines || []
		},

		splitRest() {
			return splitRemainder(this.bookingForm.amount, this.splitLines)
		},

		splitRestOk() {
			return splitBalanced(this.bookingForm.amount, this.splitLines)
		},

		/**
		 * Konten fuer die Aufteilung: im Einfach-Modus die Kategorien zur
		 * Buchungsart, im Experten-Modus alle. Bereits belegte Konten fallen
		 * heraus - das Backend lehnt Dubletten ohnehin ab.
		 */
		splitAccountOptions() {
			const base = this.bookingMode === 'simple' ? this.simpleCategoryOptions : this.accountOptionsList
			const used = new Set(this.splitLines.map((l) => l.accountId).filter(Boolean))
			const fixed = this.bookingMode === 'simple'
				? this.bookingForm.moneyAccountId
				: (this.splitSide === 'credit' ? this.bookingForm.debitAccountId : this.bookingForm.creditAccountId)
			if (fixed) { used.add(fixed) }
			return base.filter((o) => o.$isDisabled || !used.has(o.id))
		},
	},

	watch: {
		// Vor dem Neuzeichnen der Belegliste (flush: 'pre'): steht der Fokus
		// auf einem Knopf in einer Zeile, die gleich verschwindet (Verknüpfung
		// lösen, Beleg löschen, wartenden Beleg entfernen), wandert er auf den
		// umgebenden Belegbereich. Sonst setzt focus-trap ihn nach dem
		// Entfernen auf das erste Feld im Dialog, und das Formular sprang
		// jedes Mal nach oben.
		bookingAttachments: { handler() { this.keepFocusInAttachments() }, flush: 'pre' },
		pendingFiles: { handler() { this.keepFocusInAttachments() }, flush: 'pre' },

		// Wird das Fenster schmaler, rueckt die Vorschau an die Stelle der Liste.
		wide(isWide) {
			if (!isWide && this.previewFile) { this.previewOverList = true }
		},

		show: {
			handler(open) {
				this.folderPanelOpen = false
				this.resetPreview()
				this.snapshot = null
				// Erst nach dem Tick: App.vue fuellt Formular und Warteliste
				// teils erst nach dem Setzen von show (z. B. "Buchung anlegen"
				// aus dem Eingangskorb haengt die Datei danach an).
				if (open) { this.$nextTick(() => { this.snapshot = this.formState() }) }
			},

			immediate: true,
		},
	},

	methods: {
		formatMoney,
		formatFileSize,
		/** Aktuelle Auswahl einer Aufteilungszeile als NcSelect-Option. */
		splitLineOption(index) {
			const id = this.splitLines[index]?.accountId
			if (id === null || id === undefined) { return null }
			const base = this.bookingMode === 'simple' ? this.simpleCategoryOptions : this.accountOptionsList
			return base.find((o) => o.id === id) ?? null
		},

		setSplitLineAccount(index, option) {
			this.patchSplitLine(index, { accountId: option ? option.id : null })
		},

		setSplitLineAmount(index, value) {
			this.patchSplitLine(index, { amount: (value === '' || value === null) ? null : Number(value) })
		},

		patchSplitLine(index, patch) {
			this.updateForm({
				splitLines: this.splitLines.map((l, i) => (i === index ? { ...l, ...patch } : l)),
			})
		},

		addSplitLine() {
			this.updateForm({ splitLines: [...this.splitLines, { accountId: null, amount: null }] })
		},

		removeSplitLine(index) {
			this.updateForm({ splitLines: this.splitLines.filter((_, i) => i !== index) })
		},

		/** Schreibt den offenen Rest in die letzte Zeile. */
		fillSplitRest() {
			const lines = this.splitLines
			if (!lines.length) { return }
			const last = lines.length - 1
			const value = Math.round((Number(lines[last].amount || 0) + this.splitRest) * 100) / 100
			this.patchSplitLine(last, { amount: value })
		},

		/**
		 * Meldet geaenderte Formularfelder an den Elternteil zurueck. Bewusst
		 * ein neues Objekt statt einer Mutation des uebergebenen - so bleibt
		 * der Datenfluss in eine Richtung.
		 */
		updateForm(patch) {
			this.$emit('update:bookingForm', { ...this.bookingForm, ...patch })
		},

		/**
		 * Schliessen aus dem Dialog heraus (X, Abbrechen, Klick daneben, Esc).
		 * Waehrend gespeichert wird bleibt der Dialog stehen: an dem Vorgang
		 * haengt der Upload der wartenden Belege.
		 */
		async requestClose() {
			if (this.bookingSaving || this.confirmingClose) { return }
			if (this.isDirty) {
				this.confirmingClose = true
				try {
					const discard = await this.askConfirm(
						this.t('Änderungen verwerfen?'),
						this.t('Die Buchung enthält ungespeicherte Änderungen. Beim Schließen gehen sie verloren.'),
						this.t('Verwerfen'),
						'error',
					)
					if (!discard) { return }
				} finally { this.confirmingClose = false }
			}
			this.$emit('close')
		},

		/**
		 * NcModal meldet das Schliessen auch als update:show(false) - das
		 * laeuft ueber dieselbe Rueckfrage wie close.
		 *
		 * @param {boolean} value neuer show-Zustand des NcModal
		 */
		requestShow(value) {
			if (!value) { this.requestClose() }
		},

		/**
		 * Vergleichbarer Stand von Formular und Warteliste. Bereits
		 * hochgeladene oder verknuepfte Belege zaehlen nicht: die sind schon
		 * gespeichert, Schliessen verliert sie nicht.
		 *
		 * @return {string}
		 */
		formState() {
			return JSON.stringify({
				form: this.bookingForm,
				pending: this.pendingFiles.map((f) => [f.name, f.size, f.fileId ?? null]),
			})
		},

		/**
		 * Seitenleiste auf/zu. Mit offener Leiste scrollt am Desktop nicht mehr
		 * der Dialog, sondern jede Spalte fuer sich (styles.css, .is-split) -
		 * die Scrollposition wandert dabei mit, sonst sprang das Formular nach
		 * oben und "Aus Ordner wählen" war aus dem Blick.
		 */
		toggleFolderPanel() {
			const from = this.folderPanelOpen ? this.$refs.main : this.modalScroller()
			const top = from ? from.scrollTop : 0
			this.folderPanelOpen = !this.folderPanelOpen
			if (!this.folderPanelOpen) { this.resetPreview() }
			if (this.isMobile) { return }
			this.$nextTick(() => {
				const to = this.folderPanelOpen ? this.$refs.main : this.modalScroller()
				if (to) { to.scrollTop = top }
			})
		},

		isMarkedForRemoval(a) {
			return (this.bookingForm.removedAttachmentIds || []).includes(a.id)
		},

		/**
		 * Beleg zum Lösen/Löschen markieren oder die Markierung zurücknehmen.
		 * Ausgeführt wird erst beim Speichern (App.vue::removeMarkedAttachments);
		 * die Markierung steckt im Formular und zählt damit für isDirty.
		 *
		 * @param {object} a gespeicherter Beleg
		 */
		toggleRemoval(a) {
			const ids = this.bookingForm.removedAttachmentIds || []
			this.updateForm({
				removedAttachmentIds: ids.includes(a.id) ? ids.filter((id) => id !== a.id) : [...ids, a.id],
			})
		},

		/** Siehe die Watcher auf bookingAttachments/pendingFiles. */
		keepFocusInAttachments() {
			const el = document.activeElement
			const row = el && el.closest ? el.closest('.vbh-attachment-list') : null
			if (!row || !this.$refs.main?.contains(row)) { return }
			const anchor = row.closest('.vbh-attachments, .vbh-mfield')
			if (!anchor) { return }
			anchor.setAttribute('tabindex', '-1')
			anchor.focus({ preventScroll: true })
		},

		/** Der scrollende Inhalt von NcModal (ohne Seitenleiste). */
		modalScroller() {
			return this.$refs.inner?.closest('.modal-container__content') ?? null
		},

		/**
		 * @param {Array<{fileId:number,name:string,size:number}>} files gewählte Dateien
		 */
		async onFolderPick(files) {
			if (this.isMobile) { this.folderPanelOpen = false }
			// Die Vorschau bleibt auf der übernommenen Datei stehen.
			if (this.previewFile && files.some((f) => f.fileId === this.previewFile.fileId)) { this.previewPicked = true }
			await this.pickFolderFiles(files)
		},

		/**
		 * Datei in der Belegvorschau zeigen. Im schmalen Fenster rueckt sie an
		 * die Stelle der Liste und bekommt den Fokus, damit ↑/↓ weiterblaettern.
		 *
		 * @param {object} file Datei aus dem Wächter-Ordner
		 */
		onPreview(file) {
			const wasOverList = this.previewInFolderColumn
			this.previewFile = file
			this.previewPicked = false
			if (this.wide) { return }
			this.previewOverList = true
			if (!wasOverList) { this.$nextTick(() => this.$refs.fallbackPreview?.focus()) }
		},

		/** Zurueck zur Liste; die angesehene Datei ist dort markiert und im Blick. */
		backToList() {
			const id = this.previewFile?.fileId
			this.previewOverList = false
			this.$nextTick(() => { if (id) { this.$refs.folderPanel?.revealFile(id) } })
		},

		resetPreview() {
			this.previewFile = null
			this.previewPicked = false
			this.previewOverList = false
		},

		/** Einen noch nicht hochgeladenen Beleg aus der Warteliste nehmen. */
		removePendingFile(index) {
			this.$emit('update:pendingFiles', this.pendingFiles.filter((_, i) => i !== index))
		},

		accountLabel(id) {
			const acc = this.accountsById[id]
			return acc ? `${acc.number} ${acc.name}` : `#${id}`
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

	},
}
</script>
