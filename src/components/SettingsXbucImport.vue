<template>
	<div>
		<h4>{{ t('Aus „zero Buchhaltung" (.xbuc)') }}</h4>
		<div class="vbh-card">
			<p class="vbh-hint">
				{{ t('Übernimmt Kontenbaum und alle Buchungen aus einer .xbuc-Datei.') }}
			</p>
			<div class="vbh-uploadrow">
				<label class="vbh-filebtn">{{ t('Datei wählen') }}<input
					ref="xbucInput"
					type="file"
					accept=".xbuc,application/xml,text/xml"
					hidden
					@change="onXbucSelected"></label>
				<span class="vbh-filename">{{ xbucFile ? xbucFile.name : t('keine Datei gewählt') }}</span>
				<NcCheckboxRadioSwitch v-model="xbucReset">
					{{ t('Vorher alle Daten löschen (frisch starten)') }}
				</NcCheckboxRadioSwitch>
			</div>
			<div v-if="xbucPreviewResult" class="vbh-preview">
				<p class="vbh-previewsummary">
					<span class="vbh-badge pos">{{ t('{n} Konten', { n: xbucPreviewResult.accounts }) }}</span>
					<span class="vbh-badge pos">{{ t('{n} Buchungen', { n: xbucPreviewResult.bookings }) }}</span>
					<span v-if="xbucPreviewResult.openBankTx > 0" class="vbh-badge muted">{{ t('{n} ohne Gegenkonto → offen', { n: xbucPreviewResult.openBankTx }) }}</span>
				</p>
				<p v-if="xbucPreviewResult.openBankTx > 0" class="vbh-hint">
					{{ t('{n} Buchung(en) ohne Gegenkonto werden als offene Bankbuchungen übernommen und erscheinen im Tab „Buchungen → Zuzuordnen".', { n: xbucPreviewResult.openBankTx }) }}
				</p>
				<div class="vbh-form vbh-yearedit">
					<label>{{ t('Geschäftsjahr') }}
						<input
							v-model.number="xbucYear"
							type="number"
							min="2000"
							max="2099"
							:placeholder="t('z. B. 2025')"
							class="vbh-addyear-input"
							@change="xbucPreview()">
					</label>
					<span v-if="!xbucPreviewResult.fileYear && !xbucYear" class="vbh-hint">{{ t('Kein Geschäftsjahr in der Datei hinterlegt – Jahr eintragen, um die Datumsprüfung zu aktivieren.') }}</span>
					<span v-else-if="xbucPreviewResult.fileYear && xbucYear && xbucYear !== xbucPreviewResult.fileYear" class="vbh-warn-inline">{{ t('Weicht vom Jahr der Datei ab ({year}).', { year: xbucPreviewResult.fileYear }) }}</span>
				</div>
				<div v-if="!xbucReset && xbucPreviewResult.openings && xbucPreviewResult.openings.length" class="vbh-openinfo">
					<p class="vbh-openinfo-title">
						{{ t('Anfangsbestände in der Datei:') }}
					</p>
					<ul class="vbh-yearwarn-list">
						<li v-for="(o, i) in xbucPreviewResult.openings" :key="i">
							{{ t('{account}: {amount} ({date}) –', { account: o.account, amount: formatMoney(o.amount), date: formatDate(o.date) }) }}
							<template v-if="o.action === 'import'">
								{{ t('wird übernommen (keine Vorjahresbuchungen vorhanden)') }}
							</template>
							<template v-else-if="o.matches">
								{{ t('wird übersprungen, stimmt mit dem Vorjahres-Endstand überein ✓') }}
							</template>
							<template v-else>
								<span class="vbh-warn-inline">{{ t('wird übersprungen – ⚠ Vorjahres-Endstand ist {prior} (Differenz {diff})', { prior: formatMoney(o.priorBalance), diff: formatMoney(o.amount - o.priorBalance) }) }}</span>
							</template>
						</li>
					</ul>
				</div>
				<div v-if="xbucPreviewResult.outsideYear > 0" class="vbh-yearwarn">
					<p class="vbh-warn-inline">
						{{ t('⚠ {n} Buchung(en) liegen außerhalb des Geschäftsjahres {year} und würden in der App einem anderen Jahr zugeordnet:', { n: xbucPreviewResult.outsideYear, year: xbucPreviewResult.year }) }}
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
						{{ t('Diese Buchungen auf das Geschäftsjahr {year} datieren (01.01. bzw. 31.12.)', { year: xbucPreviewResult.year }) }}
					</NcCheckboxRadioSwitch>
				</div>
				<div v-if="!xbucReset && xbucPreviewResult.yearTransition" class="vbh-yearwarn" :class="{ 'vbh-yearwarn--block': xbucPreviewResult.yearTransition.hasMismatch }">
					<p :class="xbucPreviewResult.yearTransition.hasMismatch ? 'vbh-warn-inline' : 'vbh-openinfo-title'">
						{{ t('Rückwärts-Import (früheres Jahr): Abgleich mit dem Jahresübergang zu {year}.', { year: xbucPreviewResult.yearTransition.targetYear }) }}
					</p>
					<ul class="vbh-yearwarn-list">
						<li v-for="(c, i) in xbucPreviewResult.yearTransition.comparisons" :key="i">
							<template v-if="c.matches">
								{{ t('{account}: {amount} stimmt überein ✓', { account: c.account, amount: formatMoney(c.storedOpening) }) }}
							</template>
							<template v-else>
								<span class="vbh-warn-inline">{{ t('{account}: Endstand {closing} ≠ gespeicherter Anfangsbestand {opening} (Differenz {diff})', { account: c.account, closing: formatMoney(c.fileClosing), opening: formatMoney(c.storedOpening), diff: formatMoney(c.fileClosing - c.storedOpening) }) }}</span>
							</template>
						</li>
					</ul>
					<p v-if="xbucPreviewResult.yearTransition.hasMismatch" class="vbh-warn-inline">
						{{ t('⛔ Import blockiert, bis die Beträge am Jahresübergang übereinstimmen.') }}
					</p>
					<p v-else class="vbh-hint">
						{{ t('{n} überflüssige Eröffnungsbuchung(en) aus {year} werden beim Import entfernt (der Anfangsbestand kommt dann aus diesem früheren Jahr).', { n: xbucPreviewResult.yearTransition.removalCount, year: xbucPreviewResult.yearTransition.targetYear }) }}
					</p>
				</div>
				<NcButton variant="primary" :disabled="busy || xbucImportBlocked" @click="xbucImport">
					{{ t('Importieren') }}
				</NcButton>
				<span v-if="xbucReset" class="vbh-warn-inline">{{ t('Achtung: bestehende Daten werden gelöscht.') }}</span>
			</div>
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import api from '../api.js'
import { useConfirm } from '../composables/useConfirm.js'
import { errMsg, formatDate, formatMoney } from '../lib/format.js'

export default {
	name: 'SettingsXbucImport',
	components: { NcButton, NcCheckboxRadioSwitch },
	props: {
		// gemeinsames App-weites Ladeflag (blockiert z. B. auch andere Import-/Reset-
		// Buttons und das Kollaborations-Polling), .sync-Prop wie NcModal:show.sync
		busy: { type: Boolean, required: true },
	},

	emits: ['changed', 'update:busy'],

	setup() {
		return { askConfirm: useConfirm().askConfirm }
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
		errMsg,
		onXbucSelected(e) { this.xbucFile = e.target.files[0] || null; this.xbucPreviewResult = null; this.xbucYear = null; if (this.xbucFile) { this.xbucPreview() } },
		xbucYearParam() {
			const y = Number(this.xbucYear)
			return Number.isInteger(y) && y >= 2000 && y <= 2099 ? y : null
		},

		async xbucPreview() {
			if (!this.xbucFile) { return }
			this.$emit('update:busy', true)
			try {
				const fd = new FormData(); fd.append('file', this.xbucFile)
				const year = this.xbucYearParam()
				if (year) { fd.append('year', String(year)) }
				const { data } = await api.previewXbuc(fd)
				this.xbucPreviewResult = data
				// Effektives Geschäftsjahr ins Eingabefeld übernehmen
				this.xbucYear = data.year || this.xbucYear
				// Standard: Ausreißer auf das Geschäftsjahr datieren
				this.xbucClampDates = (data.outsideYear || 0) > 0
			} catch (e) { showError(this.errMsg(e, this.t('Vorschau fehlgeschlagen'))) } finally { this.$emit('update:busy', false) }
		},

		async xbucImport() {
			if (!this.xbucFile) { return }
			if (this.xbucReset && !await this.askConfirm(this.t('xbuc Import'), this.t('Alle vorhandenen Daten werden gelöscht und ersetzt. Fortfahren?'), this.t('Importieren'), 'primary')) { return }
			this.$emit('update:busy', true)
			try {
				const fd = new FormData(); fd.append('file', this.xbucFile); fd.append('reset', this.xbucReset ? '1' : '0'); fd.append('clampDates', this.xbucClampDates ? '1' : '0')
				const importYear = this.xbucYearParam()
				if (importYear) { fd.append('year', String(importYear)) }
				const { data } = await api.commitXbuc(fd)
				const skippedMsg = data.skipped > 0 ? this.t(', {n} übersprungen (bereits vorhanden)', { n: data.skipped }) : ''
				const newAccMsg = data.accountsNew > 0 ? this.t(', {n} neue Konten', { n: data.accountsNew }) : ''
				const clampMsg = data.clamped > 0 ? this.t(', {n} auf das Geschäftsjahr {year} datiert', { n: data.clamped, year: data.year }) : ''
				const openMsg = data.openingsSkipped > 0 ? this.t(', {n} Anfangsbestände übersprungen (über Vorjahressalden abgedeckt)', { n: data.openingsSkipped }) : ''
				const openTxMsg = data.openBankTx > 0 ? this.t(', {n} ohne Gegenkonto → offen (Tab „Zuzuordnen")', { n: data.openBankTx }) : ''
				const removedMsg = data.openingsRemoved > 0 ? this.t(', {n} überflüssige Eröffnungsbuchung(en) aus {year} entfernt', { n: data.openingsRemoved, year: data.transitionYear }) : ''
				showSuccess(this.t('{n} Buchungen importiert', { n: data.bookings }) + openTxMsg + skippedMsg + newAccMsg + clampMsg + openMsg + removedMsg + '.')
				for (const m of (data.openingMismatches || [])) {
					showError(this.t('Achtung: Anfangsbestand {account} laut Datei {fileAmount}, Vorjahres-Endstand in der App {priorBalance} – bitte Vorjahresbuchungen prüfen.', { account: m.account, fileAmount: this.formatMoney(m.fileAmount), priorBalance: this.formatMoney(m.priorBalance) }), { timeout: -1 })
				}
				this.xbucPreviewResult = null; this.xbucFile = null
				if (this.$refs.xbucInput) { this.$refs.xbucInput.value = '' }
				this.$emit('changed')
			} catch (e) { showError(this.errMsg(e, this.t('Import fehlgeschlagen'))) } finally { this.$emit('update:busy', false) }
		},
	},
}
</script>
