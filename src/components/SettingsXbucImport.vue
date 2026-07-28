<template>
	<div>
		<h3>Aus „zero Buchhaltung" (.xbuc)</h3>
		<div class="vbh-card">
			<p class="vbh-hint">
				Übernimmt Kontenbaum und alle Buchungen aus einer .xbuc-Datei.
			</p>
			<div class="vbh-uploadrow">
				<label class="vbh-filebtn">Datei wählen<input ref="xbucInput"
					type="file"
					accept=".xbuc,application/xml,text/xml"
					hidden
					@change="onXbucSelected"></label>
				<span class="vbh-filename">{{ xbucFile ? xbucFile.name : 'keine Datei gewählt' }}</span>
				<NcCheckboxRadioSwitch v-model="xbucReset">
					Vorher alle Daten löschen (frisch starten)
				</NcCheckboxRadioSwitch>
			</div>
			<div v-if="xbucPreviewResult" class="vbh-preview">
				<p class="vbh-previewsummary">
					<span class="vbh-badge pos">{{ xbucPreviewResult.accounts }} Konten</span>
					<span class="vbh-badge pos">{{ xbucPreviewResult.bookings }} Buchungen</span>
					<span v-if="xbucPreviewResult.openBankTx > 0" class="vbh-badge muted">{{ xbucPreviewResult.openBankTx }} ohne Gegenkonto → offen</span>
				</p>
				<p v-if="xbucPreviewResult.openBankTx > 0" class="vbh-hint">
					{{ xbucPreviewResult.openBankTx }} Buchung(en) ohne Gegenkonto werden als offene Bankbuchungen übernommen und erscheinen im Tab „Buchungen → Zuzuordnen".
				</p>
				<div class="vbh-form vbh-yearedit">
					<label>Geschäftsjahr
						<input v-model.number="xbucYear"
							type="number"
							min="2000"
							max="2099"
							placeholder="z. B. 2025"
							class="vbh-addyear-input"
							@change="xbucPreview()">
					</label>
					<span v-if="!xbucPreviewResult.fileYear && !xbucYear" class="vbh-hint">Kein Geschäftsjahr in der Datei hinterlegt – Jahr eintragen, um die Datumsprüfung zu aktivieren.</span>
					<span v-else-if="xbucPreviewResult.fileYear && xbucYear && xbucYear !== xbucPreviewResult.fileYear" class="vbh-warn-inline">Weicht vom Jahr der Datei ab ({{ xbucPreviewResult.fileYear }}).</span>
				</div>
				<div v-if="!xbucReset && xbucPreviewResult.openings && xbucPreviewResult.openings.length" class="vbh-openinfo">
					<p class="vbh-openinfo-title">
						Anfangsbestände in der Datei:
					</p>
					<ul class="vbh-yearwarn-list">
						<li v-for="(o, i) in xbucPreviewResult.openings" :key="i">
							{{ o.account }}: {{ formatMoney(o.amount) }} ({{ formatDate(o.date) }}) –
							<template v-if="o.action === 'import'">
								wird übernommen (keine Vorjahresbuchungen vorhanden)
							</template>
							<template v-else-if="o.matches">
								wird übersprungen, stimmt mit dem Vorjahres-Endstand überein ✓
							</template>
							<template v-else>
								<span class="vbh-warn-inline">wird übersprungen – ⚠ Vorjahres-Endstand ist {{ formatMoney(o.priorBalance) }} (Differenz {{ formatMoney(o.amount - o.priorBalance) }})</span>
							</template>
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
						<li v-if="xbucPreviewResult.outsideYear > xbucPreviewResult.outsideSamples.length">
							…
						</li>
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
							<template v-if="c.matches">
								{{ c.account }}: {{ formatMoney(c.storedOpening) }} stimmt überein ✓
							</template>
							<template v-else>
								<span class="vbh-warn-inline">{{ c.account }}: Endstand {{ formatMoney(c.fileClosing) }} ≠ gespeicherter Anfangsbestand {{ formatMoney(c.storedOpening) }} (Differenz {{ formatMoney(c.fileClosing - c.storedOpening) }})</span>
							</template>
						</li>
					</ul>
					<p v-if="xbucPreviewResult.yearTransition.hasMismatch" class="vbh-warn-inline">
						⛔ Import blockiert, bis die Beträge am Jahresübergang übereinstimmen.
					</p>
					<p v-else class="vbh-hint">
						{{ xbucPreviewResult.yearTransition.removalCount }} überflüssige Eröffnungsbuchung(en) aus {{ xbucPreviewResult.yearTransition.targetYear }} werden beim Import entfernt (der Anfangsbestand kommt dann aus diesem früheren Jahr).
					</p>
				</div>
				<NcButton variant="primary" :disabled="busy || xbucImportBlocked" @click="xbucImport">
					Importieren
				</NcButton>
				<span v-if="xbucReset" class="vbh-warn-inline">Achtung: bestehende Daten werden gelöscht.</span>
			</div>
		</div>

		<h4>Bisherige CSV-Importe</h4>
		<div v-if="imports.length" class="vbh-tablecard">
			<table class="vbh-table">
				<thead>
					<tr>
						<th>Datum</th><th>Datei</th><th class="num">
							Neu
						</th><th class="num">
							Dubletten
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="imp in imports" :key="imp.id">
						<td class="nowrap">
							{{ formatDateTime(imp.createdAt) }}
						</td>
						<td>{{ imp.filename }}</td>
						<td class="num">
							{{ imp.rowsNew }}
						</td>
						<td class="num">
							{{ imp.rowsDuplicate }}
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<NcEmptyContent v-else name="Noch keine CSV-Importe" description="Importiere oben eine CSV-CAMT-Datei.">
			<template #action>
				<NcButton variant="tertiary" @click="$emit('help')">
					Mehr dazu
				</NcButton>
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcEmptyContent } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import api from '../api.js'
import { formatMoney, formatDate, formatDateTime, errMsg } from '../lib/format.js'

export default {
	name: 'SettingsXbucImport',
	components: { NcButton, NcCheckboxRadioSwitch, NcEmptyContent },
	props: {
		// Verlauf aller Importe (CSV + xbuc), wird im Elternteil geladen (auch von
		// resetAll()/loadImports() weiterhin genutzt)
		imports: { type: Array, required: true },
		// gemeinsames App-weites Ladeflag (blockiert z. B. auch andere Import-/Reset-
		// Buttons und das Kollaborations-Polling), .sync-Prop wie NcModal:show.sync
		busy: { type: Boolean, required: true },
		askConfirm: { type: Function, required: true },
	},
	data() {
		return {
			xbucFile: null,
			xbucReset: false,
			xbucClampDates: false,
			xbucYear: null,
			xbucPreviewResult: null,
		}
	},
	computed: {
		xbucImportBlocked() {
			const t = this.xbucPreviewResult && this.xbucPreviewResult.yearTransition
			return !this.xbucReset && !!t && t.hasMismatch
		},
	},
	methods: {
		formatMoney,
		formatDate,
		formatDateTime,
		errMsg,
		onXbucSelected(e) { this.xbucFile = e.target.files[0] || null; this.xbucPreviewResult = null; this.xbucYear = null; if (this.xbucFile) this.xbucPreview() },
		xbucYearParam() {
			const y = Number(this.xbucYear)
			return Number.isInteger(y) && y >= 2000 && y <= 2099 ? y : null
		},
		async xbucPreview() {
			if (!this.xbucFile) return
			this.$emit('update:busy', true)
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
			} catch (e) { showError(this.errMsg(e, 'Vorschau fehlgeschlagen')) } finally { this.$emit('update:busy', false) }
		},
		async xbucImport() {
			if (!this.xbucFile) return
			if (this.xbucReset && !await this.askConfirm('xbuc Import', 'Alle vorhandenen Daten werden gelöscht und ersetzt. Fortfahren?', 'Importieren', 'primary')) return
			this.$emit('update:busy', true)
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
				this.$emit('changed')
			} catch (e) { showError(this.errMsg(e, 'Import fehlgeschlagen')) } finally { this.$emit('update:busy', false) }
		},
	},
}
</script>
