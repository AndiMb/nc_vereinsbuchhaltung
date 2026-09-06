<template>
	<NcModal
		:show="show"
		:name="isMobile ? bookingTitle : ''"
		:labelId="isMobile ? undefined : 'vbh-modal-title-booking'"
		:size="isMobile ? 'full' : 'normal'"
		:closeOnClickOutside="!bookingSaving"
		@close="requestClose"
		@update:show="requestShow">
		<div class="vbh-modal-inner">
			<h2 v-if="!isMobile" id="vbh-modal-title-booking" class="vbh-modal-title">
				{{ bookingTitle }}
			</h2>
			<p v-if="bookingLocked" class="vbh-hint vbh-hint--info">
				{{ t('🔒 Das Geschäftsjahr {year} ist abgeschlossen – diese Buchung kann nur noch angesehen werden.', { year: String(bookingForm.date).slice(0, 4) }) }}
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
				<span>{{ t('Wähle zuerst, ob Geld reinkommt oder rausgeht – Schritt 1 von 3.') }}</span>
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
								<span class="vbh-upload-btn"><NcIconSvgWrapper :path="mdiCamera" :size="16" /> {{ t('Fotografieren') }}</span>
							</label>
							<label class="vbh-upload-label">
								<input
									type="file"
									accept="image/jpeg,image/png,image/gif,image/webp,application/pdf"
									multiple
									class="vbh-upload-input"
									@change="addPendingFiles">
								<span class="vbh-upload-btn"><NcIconSvgWrapper :path="mdiPaperclip" :size="16" /> {{ t('Datei…') }}</span>
							</label>
						</div>
						<ul v-if="pendingFiles.length" class="vbh-attachment-list">
							<li v-for="(pf, i) in pendingFiles" :key="i" class="vbh-attachment-item">
								<NcIconSvgWrapper :path="mdiPaperclip" :size="14" class="vbh-attachment-icon" />
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
								:disabled="bookingLocked"
								label="label"
								:placeholder="t('– Kategorie wählen –')" />
						</label>
						<label class="vbh-grow">{{ t('Geldkonto (Bank/Kasse)') }}
							<NcSelect
								v-model="bookingFormMoneyOption"
								:options="moneyAccountOptions"
								:filterBy="accountFilterBy"
								:disabled="bookingLocked"
								label="label"
								:placeholder="t('– wählen –')" />
						</label>
					</div>
					<div v-if="bookingTour.active && bookingTour.step === 1" class="vbh-tour-tip">
						<span>{{ t('Wähle die Kategorie (z. B. „Mitgliedsbeiträge") und das Geldkonto – die App bucht Soll/Haben automatisch richtig. Schritt 2 von 3.') }}</span>
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
								:disabled="bookingLocked"
								label="label"
								:placeholder="t('– wählen –')" />
						</label>
						<label v-if="!formSplitMode || splitSide === 'debit'" class="vbh-grow">{{ t('Haben (Ertrag/Passiv)') }}
							<NcSelect
								v-model="bookingFormCreditOption"
								:options="accountOptionsList"
								:filterBy="accountFilterBy"
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

			<!-- Belegablage: bei einer bestehenden Buchung die bereits gespeicherten
			     Belege, beim Anlegen die noch lokal gesammelten Dateien (der Upload
			     folgt direkt nach dem Speichern). Mobil stehen die Sammel-Knoepfe
			     schon oben im Formular, dort entfaellt dieser Zweig. -->
			<div v-if="bookingForm.id || (canWrite && !isMobile)" class="vbh-attachments">
				<div class="vbh-attachments-header">
					<span class="vbh-attachments-title">{{ t('Belege') }}</span>
					<label v-if="canWrite && !bookingLocked" class="vbh-upload-label" :class="{ 'is-uploading': attachmentUploading }">
						<input
							type="file"
							accept="image/jpeg,image/png,image/gif,image/webp,application/pdf"
							multiple
							:disabled="attachmentUploading"
							class="vbh-upload-input"
							@change="attachOrCollectFiles">
						<span class="vbh-upload-btn">
							<NcIconSvgWrapper :path="mdiPaperclip" :size="16" />
							{{ attachmentUploading ? t('Lädt hoch…') : t('Anhängen') }}
						</span>
					</label>
				</div>
				<template v-if="bookingForm.id">
					<ul v-if="bookingAttachments.length" class="vbh-attachment-list">
						<li v-for="a in bookingAttachments" :key="a.id" class="vbh-attachment-item">
							<NcIconSvgWrapper :path="mdiPaperclip" :size="14" class="vbh-attachment-icon" />
							<button class="vbh-attachment-name" :title="t('Anzeigen: {name}', { name: a.fileName })" @click="openViewer(a)">
								{{ a.fileName }}
							</button>
							<span class="vbh-attachment-size">{{ formatFileSize(a.fileSize) }}</span>
							<a
								:href="attachmentDownloadUrl(a.id)"
								class="vbh-attachment-dl"
								:title="t('Herunterladen')"
								download>↓</a>
							<NcButton
								v-if="canWrite && !bookingLocked"
								variant="tertiary"
								:aria-label="t('Beleg löschen')"
								@click="deleteAttachment(a.id)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiDelete" :size="14" />
								</template>
							</NcButton>
						</li>
					</ul>
					<p v-else-if="!pendingFiles.length" class="vbh-attachment-empty">
						{{ t('Noch kein Beleg angehängt.') }}
					</p>
				</template>
				<!-- Wartende Dateien: beim Anlegen die getroffene Auswahl, nach einem
				     fehlgeschlagenen Upload die Reste samt Knopf fuer den zweiten
				     Anlauf (dann hat die Buchung schon eine ID). -->
				<div v-if="pendingFiles.length" class="vbh-pending">
					<div v-if="bookingForm.id" class="vbh-pending-header">
						<span class="vbh-pending-title">{{ t('Noch nicht hochgeladen') }}</span>
						<NcButton variant="secondary" :disabled="attachmentUploading" @click="retryPendingFiles">
							{{ attachmentUploading ? t('Lädt hoch…') : t('Erneut hochladen') }}
						</NcButton>
					</div>
					<ul class="vbh-attachment-list">
						<li v-for="(pf, i) in pendingFiles" :key="i" class="vbh-attachment-item">
							<NcIconSvgWrapper :path="mdiPaperclip" :size="14" class="vbh-attachment-icon" />
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

			<div class="vbh-modal-actions">
				<NcButton v-if="isMobile && bookingForm.id && canWrite && !bookingLocked" variant="error" @click="$emit('delete')">
					{{ t('Löschen') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="bookingSaving" @click="requestClose">
					{{ bookingLocked ? t('Schließen') : t('Abbrechen') }}
				</NcButton>
				<!-- Waehrend des Speicherns gesperrt: daran haengt der Upload der
				     gesammelten Belege, und ein zweiter Klick wuerde in dieser Zeit
				     eine zweite Buchung anlegen (bookingForm.id ist noch leer). -->
				<NcButton
					v-if="!bookingLocked"
					variant="primary"
					:disabled="bookingSaving"
					@click="$emit('save')">
					<template v-if="bookingSaving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ bookingSaving ? t('Wird gespeichert…') : (bookingForm.id ? t('Speichern') : t('Buchen')) }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { mdiCamera, mdiDelete, mdiPaperclip } from '@mdi/js'
import { NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper, NcLoadingIcon, NcModal, NcSelect } from '@nextcloud/vue'
import { toRefs } from 'vue'
import AmountInput from './AmountInput.vue'
import { useAccounts } from '../composables/useAccounts.js'
import { useJournal } from '../composables/useJournal.js'
import { autogrow } from '../lib/autogrow.js'
import { formatMoney } from '../lib/format.js'
import { splitBalanced, splitRemainder, splitSideOf } from '../lib/split.js'

export default {
	name: 'BookingDialog',
	components: { NcModal, NcButton, NcSelect, NcCheckboxRadioSwitch, NcIconSvgWrapper, NcLoadingIcon, AmountInput },
	directives: { autogrow },
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
		attachmentUploading: { type: Boolean, required: true },
		// App.vue-Funktionen (Muster wie askConfirm): bleiben dort, da teils mit
		// anderen Bereichen geteilt (openAccountPicker auch von der "Zuordnen"-
		// Ansicht genutzt) oder mit App-weiten Nebeneffekten (Beleg-Zaehler etc.).
		setBookingKind: { type: Function, required: true },
		setBookingMode: { type: Function, required: true },
		openAccountPicker: { type: Function, required: true },
		addPendingFiles: { type: Function, required: true },
		retryPendingFiles: { type: Function, required: true },
		uploadAttachment: { type: Function, required: true },
		deleteAttachment: { type: Function, required: true },
		openViewer: { type: Function, required: true },
		attachmentDownloadUrl: { type: Function, required: true },
		nextTourStep: { type: Function, required: true },
		endTour: { type: Function, required: true },
	},

	emits: ['close', 'delete', 'save', 'update:bookingForm', 'update:pendingFiles', 'update:show'],

	setup() {
		// accountsSorted/accountsById fuer Konto-Labels/-Auswahl, journalData fuer
		// die Haeufigkeits-Sortierung - direkt aus den Singletons (gleicher
		// geteilter Zustand wie in App.vue, keine Prop-Weitergabe noetig).
		const accounts = useAccounts()
		const journal = useJournal()
		return {
			accountsSorted: accounts.accountsSorted,
			accountsById: accounts.accountsById,
			...toRefs(journal.state),
		}
	},

	data() {
		return { mdiCamera, mdiPaperclip, mdiDelete }
	},

	computed: {
		bookingTitle() {
			return this.bookingForm.id
				? this.t('Buchung bearbeiten #{n}', { n: this.bookingForm.entryNo })
				: this.t('Neue Buchung')
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
			return this.accountsSorted
				.filter((a) => a.active && counts[a.id])
				.sort((a, b) => counts[b.id] - counts[a.id])
				.slice(0, 5)
		},

		accountsByCategory() {
			const groups = {}
			for (const acc of this.accountsSorted) {
				if (!acc.active) { continue }
				const cat = acc.category || this.t('Sonstige')
				;(groups[cat] = groups[cat] || []).push(acc)
			}
			return groups
		},

		accountOptionsList() {
			const opts = []
			if (this.frequentAccounts.length >= 2) {
				opts.push({ id: null, label: this.t('★ Häufig verwendet'), $isDisabled: true })
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

	methods: {
		formatMoney,
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
		 * Dateiauswahl aus der Belegablage: bei einer bestehenden Buchung geht
		 * die Datei sofort an den Server, beim Anlegen wandert sie erst in die
		 * Warteliste - hochgeladen wird sie, sobald die Buchung eine ID hat.
		 *
		 * @param {Event} event change-Event des Datei-Feldes
		 */
		attachOrCollectFiles(event) {
			if (this.bookingForm.id) {
				this.uploadAttachment(event)
			} else {
				this.addPendingFiles(event)
			}
		},

		/**
		 * Schliessen aus dem Dialog heraus (X, Abbrechen, Klick daneben, Esc).
		 * Waehrend gespeichert wird bleibt der Dialog stehen: an dem Vorgang
		 * haengt der Upload der wartenden Belege.
		 */
		requestClose() {
			if (!this.bookingSaving) { this.$emit('close') }
		},

		/**
		 * @param {boolean} value neuer show-Zustand des NcModal
		 */
		requestShow(value) {
			if (!this.bookingSaving) { this.$emit('update:show', value) }
		},

		/** Einen noch nicht hochgeladenen Beleg aus der Warteliste nehmen. */
		removePendingFile(index) {
			this.$emit('update:pendingFiles', this.pendingFiles.filter((_, i) => i !== index))
		},

		accountLabel(id) {
			const acc = this.accountsById[id]
			return acc ? `${acc.number} ${acc.name}` : `#${id}`
		},

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

		formatFileSize(bytes) {
			if (bytes < 1024) { return bytes + ' B' }
			if (bytes < 1024 * 1024) { return (bytes / 1024).toFixed(1) + ' KB' }
			return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
		},
	},
}
</script>
