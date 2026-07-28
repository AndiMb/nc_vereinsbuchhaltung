<template>
	<NcModal :show="show"
		:name="bookingForm.id ? 'Buchung bearbeiten #' + bookingForm.entryNo : 'Neue Buchung'"
		:size="isMobile ? 'full' : 'normal'"
		@close="$emit('close')"
		@update:show="$emit('update:show', $event)">
		<div class="vbh-modal-inner">
			<p v-if="bookingLocked" class="vbh-hint vbh-hint--info">
				🔒 Das Geschäftsjahr {{ String(bookingForm.date).slice(0, 4) }} ist abgeschlossen –
				diese Buchung kann nur noch angesehen werden.
			</p>
			<div v-if="bookingMode === 'simple'"
				class="vbh-kindtoggle"
				:class="{ 'vbh-tour-target': bookingTour.active && bookingTour.step === 0 }"
				role="radiogroup"
				aria-label="Buchungsart">
				<button type="button"
					class="vbh-kindbtn income"
					:class="{ active: bookingForm.kind === 'income' }"
					:disabled="bookingLocked"
					@click="setBookingKind('income')">
					Einnahme
				</button>
				<button type="button"
					class="vbh-kindbtn expense"
					:class="{ active: bookingForm.kind === 'expense' }"
					:disabled="bookingLocked"
					@click="setBookingKind('expense')">
					Ausgabe
				</button>
			</div>
			<div v-if="bookingTour.active && bookingTour.step === 0" class="vbh-tour-tip">
				<span>Wähle zuerst, ob Geld reinkommt oder rausgeht – Schritt 1 von 3.</span>
				<div class="vbh-tour-actions">
					<button type="button" class="vbh-tour-skip" @click="endTour">
						Überspringen
					</button>
					<NcButton variant="primary" @click="nextTourStep">
						Weiter
					</NcButton>
				</div>
			</div>

			<!-- Mobil: Betrag zuerst und groß, Kontenwahl über Auswahl-Sheets -->
			<template v-if="isMobile">
				<div class="vbh-bigamount">
					<input v-model.number="bookingForm.amount"
						type="number"
						step="0.01"
						min="0.01"
						inputmode="decimal"
						placeholder="0,00"
						class="vbh-bigamount-input"
						aria-label="Betrag in Euro"
						:disabled="bookingLocked">
					<span class="vbh-bigamount-cur">€</span>
				</div>
				<div class="vbh-mfields">
					<template v-if="bookingMode === 'simple'">
						<button type="button"
							class="vbh-fieldbtn"
							:disabled="bookingLocked"
							@click="openAccountPicker('category')">
							<span class="vbh-fieldbtn-text">
								<span class="vbh-fieldbtn-lab">{{ bookingForm.kind === 'income' ? 'Wofür? (Einnahme-Kategorie)' : 'Wofür? (Ausgabe-Kategorie)' }}</span>
								<span class="vbh-fieldbtn-val" :class="{ placeholder: !bookingForm.categoryId }">{{ bookingForm.categoryId ? accountLabel(bookingForm.categoryId) : 'Kategorie wählen…' }}</span>
							</span>
							<span class="vbh-fieldbtn-chev" aria-hidden="true">›</span>
						</button>
						<button type="button"
							class="vbh-fieldbtn"
							:disabled="bookingLocked"
							@click="openAccountPicker('money')">
							<span class="vbh-fieldbtn-text">
								<span class="vbh-fieldbtn-lab">Geldkonto (Bank/Kasse)</span>
								<span class="vbh-fieldbtn-val" :class="{ placeholder: !bookingForm.moneyAccountId }">{{ bookingForm.moneyAccountId ? accountLabel(bookingForm.moneyAccountId) : 'wählen…' }}</span>
							</span>
							<span class="vbh-fieldbtn-chev" aria-hidden="true">›</span>
						</button>
					</template>
					<template v-else>
						<button type="button"
							class="vbh-fieldbtn"
							:disabled="bookingLocked"
							@click="openAccountPicker('debit')">
							<span class="vbh-fieldbtn-text">
								<span class="vbh-fieldbtn-lab">Soll (Aufwand/Aktiv)</span>
								<span class="vbh-fieldbtn-val" :class="{ placeholder: !bookingForm.debitAccountId }">{{ bookingForm.debitAccountId ? accountLabel(bookingForm.debitAccountId) : 'wählen…' }}</span>
							</span>
							<span class="vbh-fieldbtn-chev" aria-hidden="true">›</span>
						</button>
						<button type="button"
							class="vbh-fieldbtn"
							:disabled="bookingLocked"
							@click="openAccountPicker('credit')">
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
								<input type="file"
									accept="image/*"
									capture="environment"
									hidden
									@change="addPendingFiles">
								<span class="vbh-upload-btn"><NcIconSvgWrapper :path="mdiCamera" :size="16" /> Fotografieren</span>
							</label>
							<label class="vbh-upload-label">
								<input type="file"
									accept="image/*,application/pdf"
									multiple
									hidden
									@change="addPendingFiles">
								<span class="vbh-upload-btn"><NcIconSvgWrapper :path="mdiPaperclip" :size="16" /> Datei…</span>
							</label>
						</div>
						<ul v-if="pendingFiles.length" class="vbh-attachment-list">
							<li v-for="(pf, i) in pendingFiles" :key="i" class="vbh-attachment-item">
								<NcIconSvgWrapper :path="mdiPaperclip" :size="14" class="vbh-attachment-icon" />
								<span class="vbh-attachment-name">{{ pf.name }}</span>
								<span class="vbh-attachment-size">{{ formatFileSize(pf.size) }}</span>
								<NcButton variant="tertiary" aria-label="Beleg entfernen" @click="pendingFiles.splice(i, 1)">
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
					<label>Datum<input v-model="bookingForm.date" type="date" :disabled="bookingLocked"></label>
					<label>Beleg-Nr.<input v-model="bookingForm.documentRef"
						class="vbh-short"
						placeholder="optional"
						:disabled="bookingLocked"></label>
					<label>Betrag (€)<input v-model.number="bookingForm.amount"
						type="number"
						step="0.01"
						min="0.01"
						class="vbh-num"
						:disabled="bookingLocked"></label>
				</div>
				<template v-if="bookingMode === 'simple'">
					<div class="vbh-form" :class="{ 'vbh-tour-target': bookingTour.active && bookingTour.step === 1 }">
						<label class="vbh-grow">{{ bookingForm.kind === 'income' ? 'Wofür? (Einnahme-Kategorie)' : 'Wofür? (Ausgabe-Kategorie)' }}
							<NcSelect v-model="bookingFormCategoryOption"
								:options="simpleCategoryOptions"
								:filter-by="accountFilterBy"
								:disabled="bookingLocked"
								label="label"
								placeholder="– Kategorie wählen –" />
						</label>
						<label class="vbh-grow">Geldkonto (Bank/Kasse)
							<NcSelect v-model="bookingFormMoneyOption"
								:options="moneyAccountOptions"
								:filter-by="accountFilterBy"
								:disabled="bookingLocked"
								label="label"
								placeholder="– wählen –" />
						</label>
					</div>
					<div v-if="bookingTour.active && bookingTour.step === 1" class="vbh-tour-tip">
						<span>Wähle die Kategorie (z. B. „Mitgliedsbeiträge") und das Geldkonto – die App bucht Soll/Haben automatisch richtig. Schritt 2 von 3.</span>
						<div class="vbh-tour-actions">
							<button type="button" class="vbh-tour-skip" @click="endTour">
								Überspringen
							</button>
							<NcButton variant="primary" @click="nextTourStep">
								Weiter
							</NcButton>
						</div>
					</div>
				</template>
				<template v-else>
					<div class="vbh-form">
						<label class="vbh-grow">Soll (Aufwand/Aktiv)
							<NcSelect v-model="bookingFormDebitOption"
								:options="accountOptionsList"
								:filter-by="accountFilterBy"
								:disabled="bookingLocked"
								label="label"
								placeholder="– wählen –" />
						</label>
						<label class="vbh-grow">Haben (Ertrag/Passiv)
							<NcSelect v-model="bookingFormCreditOption"
								:options="accountOptionsList"
								:filter-by="accountFilterBy"
								:disabled="bookingLocked"
								label="label"
								placeholder="– wählen –" />
						</label>
					</div>
				</template>
				<div class="vbh-form" :class="{ 'vbh-tour-target': bookingTour.active && bookingTour.step === 2 }">
					<label class="vbh-grow">Buchungstext<input v-model="bookingForm.description" placeholder="z. B. Mitgliedsbeitrag Max Mustermann" :disabled="bookingLocked"></label>
				</div>
				<div v-if="bookingTour.active && bookingTour.step === 2" class="vbh-tour-tip">
					<span>Ein kurzer Text erklärt später, worum es ging – fertig! Schritt 3 von 3.</span>
					<div class="vbh-tour-actions">
						<NcButton variant="primary" @click="endTour">
							Verstanden
						</NcButton>
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
						<input type="file"
							accept="image/*,application/pdf"
							multiple
							:disabled="attachmentUploading"
							hidden
							@change="uploadAttachment">
						<span class="vbh-upload-btn">
							<NcIconSvgWrapper :path="mdiPaperclip" :size="16" />
							{{ attachmentUploading ? 'Lädt hoch…' : 'Anhängen' }}
						</span>
					</label>
				</div>
				<ul v-if="bookingAttachments.length" class="vbh-attachment-list">
					<li v-for="a in bookingAttachments" :key="a.id" class="vbh-attachment-item">
						<NcIconSvgWrapper :path="mdiPaperclip" :size="14" class="vbh-attachment-icon" />
						<button class="vbh-attachment-name" :title="'Anzeigen: ' + a.fileName" @click="openViewer(a)">
							{{ a.fileName }}
						</button>
						<span class="vbh-attachment-size">{{ formatFileSize(a.fileSize) }}</span>
						<a :href="attachmentDownloadUrl(a.id)"
							class="vbh-attachment-dl"
							title="Herunterladen"
							download>↓</a>
						<NcButton v-if="canWrite && !bookingLocked"
							variant="tertiary"
							:aria-label="'Beleg löschen'"
							@click="deleteAttachment(a.id)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiDelete" :size="14" />
							</template>
						</NcButton>
					</li>
				</ul>
				<p v-else class="vbh-attachment-empty">
					Noch kein Beleg angehängt.
				</p>
			</div>

			<div class="vbh-modal-actions">
				<NcButton v-if="isMobile && bookingForm.id && canWrite && !bookingLocked" variant="error" @click="$emit('delete')">
					Löschen
				</NcButton>
				<NcButton variant="tertiary" @click="$emit('close')">
					{{ bookingLocked ? 'Schließen' : 'Abbrechen' }}
				</NcButton>
				<NcButton v-if="!bookingLocked" variant="primary" @click="$emit('save')">
					{{ bookingForm.id ? 'Speichern' : 'Buchen' }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { toRefs } from 'vue'
import { NcModal, NcButton, NcSelect, NcCheckboxRadioSwitch, NcIconSvgWrapper } from '@nextcloud/vue'
import { mdiCamera, mdiPaperclip, mdiDelete } from '@mdi/js'
import { useAccounts } from '../composables/useAccounts.js'
import { useJournal } from '../composables/useJournal.js'

export default {
	name: 'BookingDialog',
	components: { NcModal, NcButton, NcSelect, NcCheckboxRadioSwitch, NcIconSvgWrapper },
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
		uploadAttachment: { type: Function, required: true },
		deleteAttachment: { type: Function, required: true },
		openViewer: { type: Function, required: true },
		attachmentDownloadUrl: { type: Function, required: true },
		nextTourStep: { type: Function, required: true },
		endTour: { type: Function, required: true },
	},
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
				.filter(a => a.active && counts[a.id])
				.sort((a, b) => counts[b.id] - counts[a.id])
				.slice(0, 5)
		},
		accountsByCategory() {
			const groups = {}
			for (const acc of this.accountsSorted) {
				if (!acc.active) continue
				const cat = acc.category || 'Sonstige'
				;(groups[cat] = groups[cat] || []).push(acc)
			}
			return groups
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
		simpleCategoryOptions() {
			const type = this.bookingForm.kind === 'income' ? 'income' : 'expense'
			const counts = this.accountUsageCounts
			return this.accountsSorted
				.filter(a => a.active && a.type === type)
				.sort((a, b) => (counts[b.id] || 0) - (counts[a.id] || 0)
					|| String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
				.map(a => ({ id: a.id, label: `${a.number} ${a.name}`, number: a.number }))
		},
		moneyAccountOptions() {
			return this.accountsSorted
				.filter(a => a.active && (a.isBank || a.type === 'asset'))
				.sort((a, b) => (b.isBank ? 1 : 0) - (a.isBank ? 1 : 0)
					|| String(a.number).localeCompare(String(b.number), 'de', { numeric: true }))
				.map(a => ({ id: a.id, label: `${a.number} ${a.name}`, number: a.number }))
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
		bookingModeExpert: {
			get() { return this.bookingMode === 'expert' },
			set(v) { this.setBookingMode(v ? 'expert' : 'simple') },
		},
	},
	methods: {
		accountLabel(id) {
			const acc = this.accountsById[id]
			return acc ? `${acc.number} ${acc.name}` : `#${id}`
		},
		accountFilterBy(option, label, search) {
			const s = String(search || '').trim().toLowerCase()
			if (!s) return true
			if (option && option.$isDisabled) return false
			if (/^[\d\s]+$/.test(s)) {
				const digits = s.replace(/\s+/g, '')
				const num = String((option && option.number) || '').replace(/\s+/g, '').toLowerCase()
				return num.startsWith(digits)
			}
			return String(label || '').toLowerCase().includes(s)
		},
		formatFileSize(bytes) {
			if (bytes < 1024) return bytes + ' B'
			if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
			return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
		},
	},
}
</script>
