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
				<!-- Bis 0.32.0 stand hier ein freies Jahreszahl-Feld. Seit Issue #8
				     ist ein Geschäftsjahr ein Zeitraum mit ID, den es geben muss –
				     also wird er ausgewählt, nicht eingetippt. Die Klasse heisst aus
				     Bestandsgruenden weiter vbh-yearedit (siehe styles.css). -->
				<div class="vbh-form vbh-yearedit">
					<label>{{ t('Geschäftsjahr') }}
						<select v-model="xbucPeriodId" @change="xbucPreview()">
							<option :value="null">
								{{ t('automatisch erkennen') }}
							</option>
							<option v-for="p in periods" :key="p.id" :value="p.id">
								{{ p.label }}
							</option>
						</select>
					</label>
					<span v-if="!previewPeriod" class="vbh-warn-inline">{{ t('Die Datei lässt sich keinem einzelnen Geschäftsjahr zuordnen – sie reicht über eine Geschäftsjahresgrenze hinweg oder enthält kein Datum. Bitte oben einen Zeitraum wählen.') }}</span>
					<span v-else-if="previewPeriod.periodId === null" class="vbh-hint">{{ t('Zeitraum {label} ({from} – {to}) – gibt es noch nicht, er wird beim Import angelegt.', { label: previewPeriod.label, from: formatDate(previewPeriod.from), to: formatDate(previewPeriod.to) }) }}</span>
					<span v-else class="vbh-hint">{{ t('Zeitraum {label} ({from} – {to})', { label: previewPeriod.label, from: formatDate(previewPeriod.from), to: formatDate(previewPeriod.to) }) }}</span>
				</div>
				<div v-if="!xbucReset && xbucPreviewResult.openings && xbucPreviewResult.openings.length" class="vbh-openinfo">
					<p class="vbh-openinfo-title">
						{{ t('Anfangsbestände in der Datei:') }}
					</p>
					<ul class="vbh-yearwarn-list">
						<li v-for="(o, i) in xbucPreviewResult.openings" :key="i">
							{{ t('{account}: {amount} ({date}) –', { account: o.account, amount: formatMoney(o.amount), date: formatDate(o.date) }) }}
							<template v-if="o.action === 'import'">
								{{ t('wird übernommen (keine Buchungen im Vorzeitraum vorhanden)') }}
							</template>
							<template v-else-if="o.matches">
								{{ t('wird übersprungen, stimmt mit dem Endstand des Vorzeitraums überein ✓') }}
							</template>
							<template v-else>
								<span class="vbh-warn-inline">{{ t('wird übersprungen – ⚠ Endstand des Vorzeitraums ist {prior} (Differenz {diff})', { prior: formatMoney(o.priorBalance), diff: formatMoney(o.amount - o.priorBalance) }) }}</span>
							</template>
						</li>
					</ul>
				</div>
				<div v-if="previewPeriod && xbucPreviewResult.outsidePeriod > 0" class="vbh-yearwarn">
					<p class="vbh-warn-inline">
						{{ t('⚠ {n} Buchung(en) liegen außerhalb des Geschäftsjahres {label} ({from} – {to}) und würden in der App einem anderen Zeitraum zugeordnet:', { n: xbucPreviewResult.outsidePeriod, label: previewPeriod.label, from: formatDate(previewPeriod.from), to: formatDate(previewPeriod.to) }) }}
					</p>
					<ul class="vbh-yearwarn-list">
						<li v-for="(s, i) in xbucPreviewResult.outsideSamples" :key="i">
							{{ formatDate(s.date) }} · {{ formatMoney(s.amount) }} · {{ s.text }}
						</li>
						<li v-if="xbucPreviewResult.outsidePeriod > xbucPreviewResult.outsideSamples.length">
							…
						</li>
					</ul>
					<!-- Die Grenzen kommen aus dem Zeitraum selbst, nicht mehr aus
					     01.01./31.12. - ein Geschäftsjahr muss seit Issue #8 kein
					     Kalenderjahr mehr sein. -->
					<NcCheckboxRadioSwitch v-model="xbucClampDates">
						{{ t('Diese Buchungen auf den ersten bzw. letzten Tag des Zeitraums datieren ({from} bzw. {to})', { from: formatDate(previewPeriod.from), to: formatDate(previewPeriod.to) }) }}
					</NcCheckboxRadioSwitch>
				</div>
				<div v-if="!xbucReset && xbucPreviewResult.yearTransition" class="vbh-yearwarn" :class="{ 'vbh-yearwarn--block': xbucPreviewResult.yearTransition.hasMismatch }">
					<p :class="xbucPreviewResult.yearTransition.hasMismatch ? 'vbh-warn-inline' : 'vbh-openinfo-title'">
						{{ t('Rückwärts-Import (früherer Zeitraum): Abgleich mit dem Übergang zu {label}.', { label: xbucPreviewResult.yearTransition.targetLabel }) }}
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
						{{ t('⛔ Import blockiert, bis die Beträge am Zeitraumübergang übereinstimmen.') }}
					</p>
					<p v-else class="vbh-hint">
						{{ t('{n} überflüssige Eröffnungsbuchung(en) aus {label} werden beim Import entfernt (der Anfangsbestand kommt dann aus diesem früheren Zeitraum).', { n: xbucPreviewResult.yearTransition.removalCount, label: xbucPreviewResult.yearTransition.targetLabel }) }}
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
import { toRefs } from 'vue'
import api from '../api.js'
import { useConfirm } from '../composables/useConfirm.js'
import { usePeriods } from '../composables/usePeriods.js'
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
		// periods kommt aus dem usePeriods-Singleton; geladen wird es einmal von
		// SettingsApp.vue (mounted), hier nur gelesen.
		return {
			...toRefs(usePeriods().state),
			askConfirm: useConfirm().askConfirm,
		}
	},

	data() {
		return {
			xbucFile: null,
			xbucReset: false,
			xbucClampDates: false,
			// null = „automatisch erkennen"; sonst die ID des gewählten Zeitraums
			xbucPeriodId: null,
			xbucPreviewResult: null,
		}
	},

	computed: {
		xbucImportBlocked() {
			const t = this.xbucPreviewResult && this.xbucPreviewResult.yearTransition
			return !this.xbucReset && !!t && t.hasMismatch
		},

		// Der Zeitraum, dem der Server die Datei zuordnet: {from,to,periodId,label}
		// oder null, wenn die Datei über eine Geschäftsjahresgrenze reicht.
		// periodId ist null, solange es den Zeitraum noch nicht gibt.
		previewPeriod() {
			return (this.xbucPreviewResult && this.xbucPreviewResult.period) || null
		},
	},

	methods: {
		formatMoney,
		formatDate,
		errMsg,
		onXbucSelected(e) { this.xbucFile = e.target.files[0] || null; this.xbucPreviewResult = null; this.xbucPeriodId = null; if (this.xbucFile) { this.xbucPreview() } },
		// Die Perioden-ID geht als `period` mit; fehlt sie, erkennt der Server den
		// Zeitraum aus den Datumsangaben der Datei. Die alte 2000–2099-Pruefung
		// entfaellt: die IDs kommen aus der Liste des Servers, es gibt nichts mehr
		// zu pruefen.
		xbucPeriodParam() {
			const id = Number(this.xbucPeriodId)
			return Number.isInteger(id) && id > 0 ? id : null
		},

		async xbucPreview() {
			if (!this.xbucFile) { return }
			this.$emit('update:busy', true)
			try {
				const fd = new FormData(); fd.append('file', this.xbucFile)
				const period = this.xbucPeriodParam()
				if (period) { fd.append('period', String(period)) }
				const { data } = await api.previewXbuc(fd)
				this.xbucPreviewResult = data
				// Erkannten Zeitraum ins Auswahlfeld übernehmen - aber nur, wenn es
				// ihn schon gibt. Ein erst beim Import entstehender Zeitraum hat
				// keine ID und stünde in der Liste nicht zur Wahl.
				this.xbucPeriodId = data.period?.periodId ?? this.xbucPeriodId
				// Standard: Ausreißer auf den Zeitraum datieren
				this.xbucClampDates = (data.outsidePeriod || 0) > 0
			} catch (e) { showError(this.errMsg(e, this.t('Vorschau fehlgeschlagen'))) } finally { this.$emit('update:busy', false) }
		},

		async xbucImport() {
			if (!this.xbucFile) { return }
			if (this.xbucReset && !await this.askConfirm(this.t('xbuc Import'), this.t('Alle vorhandenen Daten werden gelöscht und ersetzt. Fortfahren?'), this.t('Importieren'), 'primary')) { return }
			this.$emit('update:busy', true)
			try {
				const fd = new FormData(); fd.append('file', this.xbucFile); fd.append('reset', this.xbucReset ? '1' : '0'); fd.append('clampDates', this.xbucClampDates ? '1' : '0')
				const importPeriod = this.xbucPeriodParam()
				if (importPeriod) { fd.append('period', String(importPeriod)) }
				const { data } = await api.commitXbuc(fd)
				const skippedMsg = data.skipped > 0 ? this.t(', {n} übersprungen (bereits vorhanden)', { n: data.skipped }) : ''
				const newAccMsg = data.accountsNew > 0 ? this.t(', {n} neue Konten', { n: data.accountsNew }) : ''
				const clampMsg = data.clamped > 0 ? this.t(', {n} auf den Zeitraum {label} datiert', { n: data.clamped, label: data.period?.label ?? '' }) : ''
				const openMsg = data.openingsSkipped > 0 ? this.t(', {n} Anfangsbestände übersprungen (über die Salden des Vorzeitraums abgedeckt)', { n: data.openingsSkipped }) : ''
				const openTxMsg = data.openBankTx > 0 ? this.t(', {n} ohne Gegenkonto → offen (Tab „Zuzuordnen")', { n: data.openBankTx }) : ''
				const removedMsg = data.openingsRemoved > 0 ? this.t(', {n} überflüssige Eröffnungsbuchung(en) aus {label} entfernt', { n: data.openingsRemoved, label: data.transitionLabel ?? '' }) : ''
				showSuccess(this.t('{n} Buchungen importiert', { n: data.bookings }) + openTxMsg + skippedMsg + newAccMsg + clampMsg + openMsg + removedMsg + '.')
				for (const m of (data.openingMismatches || [])) {
					showError(this.t('Achtung: Anfangsbestand {account} laut Datei {fileAmount}, Endstand des Vorzeitraums in der App {priorBalance} – bitte die Buchungen des Vorzeitraums prüfen.', { account: m.account, fileAmount: this.formatMoney(m.fileAmount), priorBalance: this.formatMoney(m.priorBalance) }), { timeout: -1 })
				}
				this.xbucPreviewResult = null; this.xbucFile = null; this.xbucPeriodId = null
				if (this.$refs.xbucInput) { this.$refs.xbucInput.value = '' }
				this.$emit('changed')
			} catch (e) { showError(this.errMsg(e, this.t('Import fehlgeschlagen'))) } finally { this.$emit('update:busy', false) }
		},
	},
}
</script>
